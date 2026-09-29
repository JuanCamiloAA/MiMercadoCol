<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductAttributeValue;
use App\Models\ProductFamily;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * El unico camino de los articulos con subarticulos.
 *
 * El principal vive en `product_families` y no se vende; cada subarticulo es una fila normal de
 * `products`. Lo comun (categoria, marca, proveedor principal, IVA y el nombre compuesto) se copia
 * a cada subarticulo porque medio sistema lo lee de `products`: esas copias las escribe solo este
 * servicio. Un subarticulo con movimientos nunca se borra: se desactiva.
 */
class ProductFamilyService
{
    /** Lo que vive en la familia y se copia a cada subarticulo. */
    public const COMMON = ['category_id', 'brand', 'supplier_id', 'tax_rate'];

    /** Lo propio de cada subarticulo que llega de la matriz. */
    protected const VARIANT_FIELDS = [
        'sku', 'barcode', 'price', 'online_price', 'wholesale_price', 'cost', 'content_amount', 'content_unit',
        'min_stock', 'is_active', 'is_online_visible', 'unit', 'sold_by_weight', 'track_lots', 'allow_negative_stock',
        'purchase_pack_size', 'description',
    ];

    /** Tablas donde un articulo deja rastro: con alguna de estas, no se borra. */
    protected const TRACES = [
        'stock_movements', 'sale_items', 'purchase_order_items', 'order_items', 'service_components', 'cart_items',
        'stock_transfer_items', 'stock_transfer_request_items', 'supplier_return_items', 'customer_return_items', 'product_lots',
    ];

    public function __construct(
        protected StockService $stock,
        protected WarehouseService $warehouses,
    ) {}

    /**
     * Publica, oculta o destaca articulos con subarticulos en la tienda. Es de la familia, no se
     * copia: ocultar una presentacion se hace en el subarticulo y la tarjeta sigue en pie.
     *
     * @param  list<int>  $ids
     */
    public function setFlag(int $companyId, array $ids, string $field, bool $value): int
    {
        if (! in_array($field, ['is_online_visible', 'is_featured'], true)) {
            throw new \InvalidArgumentException("No se cambia {$field} desde aqui.");
        }

        return ProductFamily::query()->withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereIn('id', $ids)
            ->whereNull('deleted_at')
            ->update([$field => $value]);
    }

    /**
     * Crea la familia y sus subarticulos desde la matriz, con su existencia inicial por sede.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, User $user, int $companyId): ProductFamily
    {
        return DB::transaction(function () use ($data, $user, $companyId) {
            $family = ProductFamily::query()->withoutGlobalScopes()->create(array_merge($this->commonData($data), [
                'company_id' => $companyId,
                'slug' => ProductFamily::uniqueSlug($companyId, (string) $data['name']),
                'attribute_1_id' => (int) $data['attribute_1_id'],
                'attribute_2_id' => ! empty($data['attribute_2_id']) ? (int) $data['attribute_2_id'] : null,
            ]));

            $values = $this->values($family);
            $created = [];

            foreach ($data['variants'] as $index => $row) {
                $variant = $this->createVariant($family, $values, $row);
                $created[$index] = $variant;
                $this->initialStock($variant, $row, $user);
            }

            $default = $created[(int) ($data['default_index'] ?? -1)] ?? null;
            $this->settle($family, $default?->id);

            return $family->refresh();
        });
    }

    /**
     * Actualiza lo comun y lo propaga; agrega subarticulos nuevos; los quitados se desactivan si
     * tienen movimientos y se eliminan si no.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(ProductFamily $family, array $data, User $user): ProductFamily
    {
        return DB::transaction(function () use ($family, $data, $user) {
            $family->fill($this->commonData($data));

            if ($family->isDirty('name')) {
                $family->slug = ProductFamily::uniqueSlug((int) $family->company_id, (string) $family->name, $family->id);
            }

            if (array_key_exists('attribute_2_id', $data) && $family->attribute_2_id === null && ! empty($data['attribute_2_id'])) {
                // Una segunda caracteristica se puede sumar despues; quitarla no, sin rehacer la matriz.
                $family->attribute_2_id = (int) $data['attribute_2_id'];
            }

            $family->save();

            $values = $this->values($family);
            $existing = $family->variants()->get()->keyBy('id');
            $kept = [];
            $default = null;

            foreach ($data['variants'] as $index => $row) {
                $id = isset($row['id']) ? (int) $row['id'] : null;

                if ($id !== null && $existing->has($id)) {
                    $variant = $existing->get($id);
                    $this->fillVariant($variant, $family, $values, $row);
                    $variant->save();
                } else {
                    $variant = $this->createVariant($family, $values, $row);
                    $this->initialStock($variant, $row, $user);
                }

                $kept[] = $variant->id;

                if ((int) ($data['default_index'] ?? -1) === $index) {
                    $default = $variant->id;
                }
            }

            foreach ($existing->except($kept) as $removed) {
                $this->remove($removed);
            }

            $this->syncCommon($family);
            $this->settle($family, $default);

            return $family->refresh();
        });
    }

    /**
     * Un articulo individual pasa a ser el primer subarticulo de una familia nueva. Conserva su
     * id, su existencia, su kardex y sus ventas; la matriz puede traer subarticulos nuevos.
     *
     * @param  array<string, mixed>  $data  Lo comun y la matriz: la fila con `id` es el articulo.
     */
    public function convertToFamily(Product $product, array $data, User $user): ProductFamily
    {
        if (! collect($data['variants'] ?? [])->contains(fn ($row) => (int) ($row['id'] ?? 0) === $product->id)) {
            throw ValidationException::withMessages(['variants' => "{$product->name} tiene que quedar como uno de los subartículos."]);
        }

        return $this->group($data, $user, (int) $product->company_id);
    }

    /**
     * Agrupa articulos individuales que ya existen (el aceite de 500 ml y el de 1 L creados antes)
     * y, si la matriz trae filas sin `id`, crea esos subarticulos nuevos.
     *
     * Cada articulo conserva su id, su kardex y sus ventas; toma lo comun de la familia (categoria,
     * marca, proveedor, IVA) y el nombre compuesto.
     *
     * @param  array<string, mixed>  $data  Lo comun y la matriz: las filas con `id` son los articulos que se agrupan.
     */
    public function group(array $data, User $user, int $companyId): ProductFamily
    {
        return DB::transaction(function () use ($data, $user, $companyId) {
            $ids = collect($data['variants'])->pluck('id')->filter()->map(fn ($id) => (int) $id)->unique()->values();

            $products = Product::query()->withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->whereNull('deleted_at')
                ->whereIn('id', $ids)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($products->count() !== $ids->count()) {
                throw ValidationException::withMessages(['variants' => 'Alguno de los artículos no existe o es de otra empresa.']);
            }

            if ($taken = $products->first(fn (Product $product) => $product->product_family_id !== null)) {
                throw ValidationException::withMessages(['variants' => "{$taken->name} ya es parte de otro artículo con subartículos."]);
            }

            $family = ProductFamily::query()->withoutGlobalScopes()->create(array_merge($this->commonData($data), [
                'company_id' => $companyId,
                'slug' => ProductFamily::uniqueSlug($companyId, (string) $data['name']),
                'attribute_1_id' => (int) $data['attribute_1_id'],
                'attribute_2_id' => ! empty($data['attribute_2_id']) ? (int) $data['attribute_2_id'] : null,
            ]));

            $values = $this->values($family);
            $default = null;

            foreach ($data['variants'] as $index => $row) {
                if (! empty($row['id'])) {
                    $variant = $products->get((int) $row['id']);
                    $this->fillVariant($variant, $family, $values, $row);
                    $variant->save();
                } else {
                    $variant = $this->createVariant($family, $values, $row);
                    $this->initialStock($variant, $row, $user);
                }

                if ((int) ($data['default_index'] ?? -1) === $index) {
                    $default = $variant->id;
                }
            }

            $this->syncCommon($family);
            $this->settle($family, $default);

            return $family->refresh();
        });
    }

    /**
     * La importacion de articulos: pone el articulo de la fila en su familia, creandola con lo
     * comun de la primera fila si no existe. Caracteristicas y valores que falten se crean en el
     * catalogo. Una fila que contradiga la familia falla con su motivo, sin tocar nada.
     *
     * @param  array{family: string, attribute_1: string, value_1: string, attribute_2: ?string, value_2: ?string}  $spec
     */
    public function placeImported(Product $product, array $spec, int $companyId): ProductFamily
    {
        return DB::transaction(function () use ($product, $spec, $companyId) {
            $catalog = app(ProductAttributeCatalog::class);

            $attribute1 = $catalog->attributeNamed($companyId, $spec['attribute_1']);
            $attribute2 = filled($spec['attribute_2']) ? $catalog->attributeNamed($companyId, (string) $spec['attribute_2']) : null;

            if ($attribute2 && $attribute2->id === $attribute1->id) {
                throw ValidationException::withMessages(['variants' => 'atributo_1 y atributo_2 no pueden ser la misma característica']);
            }

            $v1 = $catalog->valueFromText($attribute1, $spec['value_1']);
            $v2 = $attribute2 ? $catalog->valueFromText($attribute2, (string) $spec['value_2']) : null;

            $family = self::findFamily($companyId, $spec['family']);

            if ($product->product_family_id && (int) $product->product_family_id !== (int) $family?->id) {
                $current = ProductFamily::query()->withoutGlobalScopes()->whereKey($product->product_family_id)->value('name');

                throw ValidationException::withMessages(['variants' => "el artículo ya es subartículo de «{$current}»; sepáralo primero desde su ficha"]);
            }

            if (! $family) {
                $family = ProductFamily::query()->withoutGlobalScopes()->create([
                    'company_id' => $companyId,
                    'name' => trim($spec['family']),
                    'slug' => ProductFamily::uniqueSlug($companyId, trim($spec['family'])),
                    'category_id' => $product->category_id,
                    'brand' => $product->brand,
                    'supplier_id' => $product->supplier_id,
                    'tax_rate' => $product->tax_rate,
                    'attribute_1_id' => $attribute1->id,
                    'attribute_2_id' => $attribute2?->id,
                ]);
            } else {
                $this->assertMatches($family, $product, $attribute1, $attribute2);
            }

            $family->load(['attribute1', 'attribute2']);
            $key = $v1->id.'-'.($v2?->id ?? 0);

            $taken = Product::query()->withoutGlobalScopes()
                ->where('product_family_id', $family->id)
                ->where('variant_key', $key)
                ->where('id', '!=', $product->id)
                ->first(['id', 'sku']);

            if ($taken) {
                throw ValidationException::withMessages(['variants' => "«{$family->name}» ya tiene el subartículo ".$this->label($family, $v1, $v2)." (SKU {$taken->sku})"]);
            }

            // Una medida trae su contenido, igual que en el formulario.
            foreach ([$v1, $v2] as $value) {
                if ($value && $value->amount !== null && $value->unit !== null && empty($product->content_amount)) {
                    $product->content_amount = $value->amount;
                    $product->content_unit = $value->unit;
                }
            }

            $this->assignVariant($product, $family, $v1, $v2);
            $product->save();
            $this->settle($family, null);

            return $family;
        });
    }

    /** La familia por su codigo o por su nombre exacto, como la escribe el archivo. */
    public static function findFamily(int $companyId, string $reference): ?ProductFamily
    {
        $reference = trim($reference);

        return ProductFamily::query()->withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->where(fn ($query) => $query->where('code', $reference)->orWhere('name', $reference))
            ->orderByRaw('code = ? desc', [$reference])
            ->first();
    }

    /** Lo que una fila no puede cambiarle a una familia que ya existe. */
    protected function assertMatches(ProductFamily $family, Product $product, ProductAttribute $attribute1, ?ProductAttribute $attribute2): void
    {
        $family->loadMissing(['attribute1', 'attribute2', 'category']);
        $name = $family->name;

        if ((int) $family->attribute_1_id !== (int) $attribute1->id || (int) $family->attribute_2_id !== (int) $attribute2?->id) {
            $expected = implode(' y ', array_filter([$family->attribute1?->name, $family->attribute2?->name]));

            throw ValidationException::withMessages(['variants' => "«{$name}» se arma con {$expected}, no con ".implode(' y ', array_filter([$attribute1->name, $attribute2?->name]))]);
        }

        if ((int) $product->category_id !== (int) $family->category_id) {
            throw ValidationException::withMessages(['variants' => "la categoría no coincide con la de «{$name}» ({$family->category?->name})"]);
        }

        if (filled($product->brand) && filled($family->brand) && mb_strtolower((string) $product->brand) !== mb_strtolower((string) $family->brand)) {
            throw ValidationException::withMessages(['variants' => "la marca no coincide con la de «{$name}» ({$family->brand})"]);
        }

        if ((float) $product->tax_rate !== (float) $family->tax_rate) {
            throw ValidationException::withMessages(['variants' => "el IVA no coincide con el de «{$name}» (".(float) $family->tax_rate.'%)']);
        }
    }

    /**
     * Quitar un subarticulo desde su ficha: con movimientos se desactiva, sin nada se elimina.
     *
     * @return bool Si se elimino (false: quedo desactivado).
     */
    public function removeVariant(Product $variant): bool
    {
        return DB::transaction(function () use ($variant) {
            $family = $variant->family;
            $deleted = ! $this->hasMovements($variant);
            $this->remove($variant);

            if ($family) {
                $this->afterLeaving($family);
            }

            return $deleted;
        });
    }

    /** El subarticulo vuelve a ser individual, con el nombre compuesto que tenia. */
    public function detach(Product $variant): Product
    {
        return DB::transaction(function () use ($variant) {
            $family = $variant->family;

            $variant->forceFill([
                'product_family_id' => null,
                'variant_value_1_id' => null,
                'variant_value_2_id' => null,
                'variant_key' => null,
                'variant_label' => null,
                'variant_sort' => 0,
            ])->save();

            if ($family) {
                $this->afterLeaving($family);
            }

            return $variant->refresh();
        });
    }

    /** Deshace la agrupacion: todos vuelven a ser individuales y la familia se elimina. */
    public function ungroup(ProductFamily $family): void
    {
        DB::transaction(function () use ($family) {
            foreach ($family->variants()->withTrashed()->get() as $variant) {
                $variant->forceFill([
                    'product_family_id' => null,
                    'variant_value_1_id' => null,
                    'variant_value_2_id' => null,
                    'variant_key' => null,
                    'variant_label' => null,
                    'variant_sort' => 0,
                ])->save();
            }

            $family->forceFill(['default_variant_id' => null, 'is_active' => false])->save();
            $family->delete();
        });
    }

    /**
     * Copia lo comun y el nombre compuesto a cada subarticulo. Solo toca lo que cambio: una venta
     * de ayer conserva su IVA porque `sale_items.tax_rate` ya guardo el del momento.
     */
    public function syncCommon(ProductFamily $family): void
    {
        $values = $this->values($family);

        foreach ($family->variants()->get() as $variant) {
            $v1 = $values->get((int) $variant->variant_value_1_id);
            $v2 = $variant->variant_value_2_id ? $values->get((int) $variant->variant_value_2_id) : null;
            $this->assignVariant($variant, $family, $v1, $v2);

            if ($variant->isDirty()) {
                $variant->save();
            }
        }
    }

    /** «Aceite de girasol · 900 ml», «Camiseta · Talla M · Azul». Cabe en `products.name`. */
    public function composeName(ProductFamily $family, ?ProductAttributeValue $v1, ?ProductAttributeValue $v2 = null): string
    {
        $label = $this->label($family, $v1, $v2);

        return mb_substr($label === '' ? (string) $family->name : $family->name.' · '.$label, 0, 255);
    }

    /** «900 ml», «Talla M · Azul»: el nombre de la caracteristica solo donde ayuda. */
    public function label(ProductFamily $family, ?ProductAttributeValue $v1, ?ProductAttributeValue $v2 = null): string
    {
        $parts = [];

        foreach ([[$family->attribute1, $v1], [$family->attribute2, $v2]] as [$attribute, $value]) {
            if (! $value) {
                continue;
            }

            $parts[] = $attribute && $attribute->name_in_label ? $attribute->name.' '.$value->value : $value->value;
        }

        return mb_substr(implode(' · ', $parts), 0, 80);
    }

    /** Si el articulo ya dejo rastro en algun lado: ventas, kardex, compras, pedidos, servicios… */
    public function hasMovements(Product $product): bool
    {
        foreach (self::TRACES as $table) {
            if (DB::table($table)->where('product_id', $product->id)->exists()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function commonData(array $data): array
    {
        $common = [
            'name' => trim((string) $data['name']),
            'code' => isset($data['code']) && trim((string) $data['code']) !== '' ? trim((string) $data['code']) : null,
            'description' => $data['description'] ?? null,
            'category_id' => (int) $data['category_id'],
            'brand' => isset($data['brand']) && trim((string) $data['brand']) !== '' ? trim((string) $data['brand']) : null,
            'supplier_id' => ! empty($data['supplier_id']) ? (int) $data['supplier_id'] : null,
            'tax_rate' => (float) ($data['tax_rate'] ?? 0),
        ];

        foreach (['is_active', 'is_online_visible', 'is_featured'] as $flag) {
            if (array_key_exists($flag, $data)) {
                $common[$flag] = (bool) $data[$flag];
            }
        }

        return $common;
    }

    /** @return Collection<int, ProductAttributeValue> Los valores de las dos caracteristicas, por id. */
    protected function values(ProductFamily $family): Collection
    {
        $family->loadMissing(['attribute1', 'attribute2']);

        return ProductAttributeValue::query()
            ->whereIn('product_attribute_id', array_filter([$family->attribute_1_id, $family->attribute_2_id]))
            ->get()
            ->keyBy('id');
    }

    /**
     * Los dos valores de una fila, comprobados contra las caracteristicas de la familia.
     *
     * @param  Collection<int, ProductAttributeValue>  $values
     * @param  array<string, mixed>  $row
     * @return array{0: ProductAttributeValue, 1: ProductAttributeValue|null}
     */
    protected function pick(ProductFamily $family, Collection $values, array $row): array
    {
        $v1 = $values->get((int) ($row['value_1_id'] ?? 0));
        $v2 = ! empty($row['value_2_id']) ? $values->get((int) $row['value_2_id']) : null;

        if (! $v1 || (int) $v1->product_attribute_id !== (int) $family->attribute_1_id) {
            throw ValidationException::withMessages(['variants' => 'Cada subartículo necesita un valor de '.($family->attribute1?->name ?? 'la primera característica').'.']);
        }

        if ($family->attribute_2_id !== null && (! $v2 || (int) $v2->product_attribute_id !== (int) $family->attribute_2_id)) {
            throw ValidationException::withMessages(['variants' => 'Cada subartículo necesita un valor de '.($family->attribute2?->name ?? 'la segunda característica').'.']);
        }

        return [$v1, $family->attribute_2_id !== null ? $v2 : null];
    }

    /**
     * @param  Collection<int, ProductAttributeValue>  $values
     * @param  array<string, mixed>  $row
     */
    protected function createVariant(ProductFamily $family, Collection $values, array $row): Product
    {
        $variant = new Product(['company_id' => $family->company_id]);
        $this->fillVariant($variant, $family, $values, $row);
        $variant->save();

        return $variant;
    }

    /**
     * @param  Collection<int, ProductAttributeValue>  $values
     * @param  array<string, mixed>  $row
     */
    protected function fillVariant(Product $variant, ProductFamily $family, Collection $values, array $row): void
    {
        [$v1, $v2] = $this->pick($family, $values, $row);

        foreach (self::VARIANT_FIELDS as $field) {
            if (array_key_exists($field, $row)) {
                $variant->{$field} = $row[$field];
            }
        }

        $variant->unit ??= Product::UNIT_UNIT;
        $variant->min_stock ??= 0;
        $variant->cost ??= 0;
        $variant->price ??= 0;

        // Una medida trae su contenido: «900 ml» ya dice cuanto tiene el subarticulo.
        foreach ([$v1, $v2] as $value) {
            if ($value && $value->amount !== null && $value->unit !== null && empty($row['content_amount'])) {
                $variant->content_amount = $value->amount;
                $variant->content_unit = $value->unit;
            }
        }

        $this->assignVariant($variant, $family, $v1, $v2);
    }

    /** Lo comun, la familia, los valores, la llave, la etiqueta, el orden y el nombre. */
    protected function assignVariant(Product $variant, ProductFamily $family, ?ProductAttributeValue $v1, ?ProductAttributeValue $v2): void
    {
        foreach (self::COMMON as $field) {
            $variant->{$field} = $family->{$field};
        }

        $variant->product_family_id = $family->id;
        $variant->variant_value_1_id = $v1?->id;
        $variant->variant_value_2_id = $v2?->id;
        $variant->variant_key = ($v1?->id ?? 0).'-'.($v2?->id ?? 0);
        $variant->variant_label = $this->label($family, $v1, $v2) ?: null;
        $variant->variant_sort = min(65535, (($v1?->sort_order ?? 0) + 1) * 100 + ($v2?->sort_order ?? 0));
        $variant->name = $this->composeName($family, $v1, $v2);
    }

    /**
     * La existencia inicial por sede de un subarticulo nuevo, como un ajuste: igual que al crear
     * un articulo individual.
     *
     * @param  array<string, mixed>  $row
     */
    protected function initialStock(Product $variant, array $row, User $user): void
    {
        foreach ($row['initial_stocks'] ?? [] as $entry) {
            $quantity = (float) ($entry['quantity'] ?? 0);

            if ($quantity <= 0) {
                continue;
            }

            $this->stock->move(
                product: $variant,
                warehouse: $this->warehouses->receivingDefaultFor((int) $entry['store_id']),
                type: StockMovement::TYPE_ADJUSTMENT,
                quantity: $quantity,
                userId: $user->id,
                note: 'Existencia inicial del articulo',
                unitCost: $variant->cost,
            );
        }
    }

    /** Quitar un subarticulo: con movimientos se desactiva, sin nada se elimina. */
    protected function remove(Product $variant): void
    {
        if ($this->hasMovements($variant)) {
            $variant->forceFill(['is_active' => false, 'is_online_visible' => false])->save();

            return;
        }

        // Libera su combinacion: la misma talla se puede volver a crear.
        $variant->forceFill(['variant_key' => null])->save();
        $variant->delete();
    }

    /**
     * El subarticulo por defecto y el estado de la familia: sin subarticulos activos, la familia
     * se apaga y la tienda deja de mostrarla.
     */
    protected function settle(ProductFamily $family, ?int $default): void
    {
        $variants = $family->variants()->get(['id', 'is_active']);
        $active = $variants->where('is_active', true);

        if ($default === null || ! $active->contains('id', $default)) {
            $default = $active->contains('id', $family->default_variant_id) ? $family->default_variant_id : $active->first()?->id;
        }

        $family->forceFill([
            'default_variant_id' => $default,
            'is_active' => $active->isNotEmpty() && $family->is_active,
        ])->save();
    }

    protected function afterLeaving(ProductFamily $family): void
    {
        if (! $family->variants()->exists()) {
            $family->forceFill(['default_variant_id' => null, 'is_active' => false])->save();
            $family->delete();

            return;
        }

        $this->settle($family, null);
    }
}
