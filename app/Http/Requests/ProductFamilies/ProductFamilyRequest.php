<?php

namespace App\Http\Requests\ProductFamilies;

use App\Models\Product;
use App\Models\ProductAttributeValue;
use App\Models\ProductFamilyImage;
use App\Services\Catalog\CatalogImages;
use App\Support\ScanCodeGuard;
use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Lo comun de crear y editar un articulo con subarticulos: los datos del principal y la matriz.
 *
 * Los errores de la matriz dicen la fila por su nombre («Fila Talla 12 · Azul: ese codigo de
 * barras ya es de Jean clasico · Talla 12»): con treinta filas, «variants.17.barcode» no le sirve
 * a nadie.
 */
abstract class ProductFamilyRequest extends FormRequest
{
    /** La familia que se edita; nula al crear. */
    abstract protected function familyId(): ?int;

    public function rules(): array
    {
        $companyId = (int) TenantContext::activeCompanyId();
        $inCompany = fn (string $table) => Rule::exists($table, 'id')->where(fn ($query) => $query->where('company_id', $companyId));

        return [
            'name' => ['required', 'string', 'max:160'],
            'code' => ['nullable', 'string', 'max:40', ScanCodeGuard::familyRule($companyId, $this->familyId())],
            'category_id' => ['required', 'integer', $inCompany('categories')],
            'brand' => ['nullable', 'string', 'max:60'],
            'supplier_id' => ['nullable', 'integer', $inCompany('suppliers')],
            'tax_rate' => ['required', 'numeric', Rule::in(Product::TAX_RATES)],
            'description' => ['nullable', 'string', 'max:2000'],
            'attribute_1_id' => ['required', 'integer', $inCompany('product_attributes')],
            'attribute_2_id' => ['nullable', 'integer', 'different:attribute_1_id', $inCompany('product_attributes')],
            'is_active' => ['boolean'],
            'is_online_visible' => ['boolean'],
            'is_featured' => ['boolean'],
            'default_index' => ['nullable', 'integer', 'min:0'],
            // Cuantas, lo decide la empresa (Ajustes > Catalogo): se mide en `after` con las que ya tiene.
            'images' => ['nullable', 'array'],
            'images.*' => CatalogImages::FILE_RULES,
            'image_values' => ['nullable', 'array'],
            'image_values.*' => ['nullable', 'integer'],
            'variants' => ['required', 'array', 'min:1', 'max:200'],
            'variants.*.id' => ['nullable', 'integer'],
            'variants.*.value_1_id' => ['required', 'integer'],
            'variants.*.value_2_id' => ['nullable', 'integer'],
            'variants.*.sku' => ['required', 'string', 'max:40'],
            'variants.*.barcode' => ['nullable', 'string', 'max:60'],
            'variants.*.price' => ['required', 'integer', 'min:0'],
            'variants.*.online_price' => ['nullable', 'integer', 'min:0'],
            'variants.*.wholesale_price' => ['nullable', 'integer', 'min:0'],
            'variants.*.cost' => ['nullable', 'integer', 'min:0'],
            'variants.*.content_amount' => ['nullable', 'numeric', 'min:0', 'max:99999'],
            'variants.*.content_unit' => ['nullable', Rule::in(['ml', 'l', 'g', 'kg', 'un'])],
            'variants.*.min_stock' => ['nullable', 'integer', 'min:0'],
            'variants.*.is_active' => ['boolean'],
            'variants.*.is_online_visible' => ['boolean'],
            'variants.*.unit' => ['nullable', Rule::in(Product::UNITS)],
            'variants.*.sold_by_weight' => ['boolean'],
            'variants.*.track_lots' => ['boolean'],
            'variants.*.allow_negative_stock' => ['boolean'],
            'variants.*.purchase_pack_size' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'variants.*.description' => ['nullable', 'string', 'max:600'],
            'variants.*.initial_stocks' => ['nullable', 'array'],
            'variants.*.initial_stocks.*.store_id' => ['required', 'integer', $inCompany('stores')],
            'variants.*.initial_stocks.*.quantity' => ['required', 'numeric', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'El nombre es obligatorio.',
            'category_id.required' => 'Elige la categoría.',
            'tax_rate.in' => 'El IVA solo puede ser 0, 5 o 19.',
            'attribute_1_id.required' => 'Elige al menos una característica: volumen, peso, sabor, talla…',
            'attribute_2_id.different' => 'Las dos características tienen que ser distintas.',
            'variants.required' => 'Marca al menos un valor para armar los subartículos.',
            'variants.min' => 'Marca al menos un valor para armar los subartículos.',
            'variants.max' => 'Máximo 200 subartículos por artículo.',
            'variants.*.sku.required' => 'Cada subartículo necesita su SKU.',
            'variants.*.price.required' => 'Cada subartículo necesita su precio.',
            ...CatalogImages::fileMessages(),
        ];
    }

    public function after(): array
    {
        return [
            fn (Validator $validator) => $this->checkMatrix($validator),
            fn (Validator $validator) => app(CatalogImages::class)->check(
                $validator,
                (int) TenantContext::activeCompanyId(),
                $this->familyId() ? ProductFamilyImage::query()->where('product_family_id', $this->familyId())->count() : 0,
                count((array) $this->file('images', [])),
            ),
        ];
    }

    /**
     * La matriz entera: combinaciones sin repetir, codigos unicos (tambien dentro de la matriz y
     * contra servicios y familias), al menos uno activo y ninguno activo en linea a precio cero.
     */
    protected function checkMatrix(Validator $validator): void
    {
        if ($validator->errors()->isNotEmpty()) {
            return;
        }

        $companyId = (int) TenantContext::activeCompanyId();
        $rows = collect($this->input('variants', []));
        $attributes = array_filter([(int) $this->input('attribute_1_id'), (int) $this->input('attribute_2_id')]);
        $values = ProductAttributeValue::query()->whereIn('product_attribute_id', $attributes)->get()->keyBy('id');
        $own = $this->ownVariantIds();

        $seen = ['key' => [], 'sku' => [], 'barcode' => []];

        foreach ($rows as $index => $row) {
            $v1 = $values->get((int) ($row['value_1_id'] ?? 0));
            $v2 = ! empty($row['value_2_id']) ? $values->get((int) $row['value_2_id']) : null;
            $label = 'Fila '.$this->rowLabel($v1, $v2);

            if (! $v1 || (int) $v1->product_attribute_id !== (int) $this->input('attribute_1_id')) {
                $validator->errors()->add("variants.{$index}.value_1_id", "{$label}: el valor no es de la primera característica.");

                continue;
            }

            if ($this->filled('attribute_2_id') && (! $v2 || (int) $v2->product_attribute_id !== (int) $this->input('attribute_2_id'))) {
                $validator->errors()->add("variants.{$index}.value_2_id", "{$label}: le falta el valor de la segunda característica.");

                continue;
            }

            $key = $v1->id.'-'.($v2?->id ?? 0);

            if (isset($seen['key'][$key])) {
                $validator->errors()->add("variants.{$index}.value_1_id", "{$label}: esa combinación está repetida.");
            }

            $seen['key'][$key] = true;

            if (isset($row['id']) && ! in_array((int) $row['id'], $own, true)) {
                $validator->errors()->add("variants.{$index}.id", "{$label}: ese subartículo no es de este artículo.");
            }

            $ignore = isset($row['id']) ? (int) $row['id'] : null;

            foreach (['sku' => 'SKU', 'barcode' => 'código de barras'] as $field => $name) {
                $code = trim((string) ($row[$field] ?? ''));

                if ($code === '') {
                    continue;
                }

                if (isset($seen[$field][mb_strtolower($code)])) {
                    $validator->errors()->add("variants.{$index}.{$field}", "{$label}: ese {$name} ya está en otra fila de la matriz.");

                    continue;
                }

                $seen[$field][mb_strtolower($code)] = true;

                $taken = Product::query()->withoutGlobalScopes()
                    ->where('company_id', $companyId)
                    ->whereNull('deleted_at')
                    ->when($ignore, fn ($query, $id) => $query->whereKeyNot($id))
                    ->where(fn ($query) => $query->where('sku', $code)->orWhere('barcode', $code))
                    ->first(['id', 'name']);

                $message = $taken
                    ? "ese {$name} ya es de {$taken->name}."
                    : ScanCodeGuard::forProduct($companyId, $code);

                if ($message !== null) {
                    $validator->errors()->add("variants.{$index}.{$field}", "{$label}: ".lcfirst($message));
                }
            }

            $active = (bool) ($row['is_active'] ?? true);
            $online = (bool) ($row['is_online_visible'] ?? true);
            $storePrice = (int) ($row['online_price'] ?? 0) > 0 ? (int) $row['online_price'] : (int) ($row['price'] ?? 0);

            if ($active && (int) ($row['price'] ?? 0) === 0) {
                $validator->errors()->add("variants.{$index}.price", "{$label}: un artículo activo con precio cero es un artículo que la caja regala.");
            } elseif ($active && $online && $storePrice === 0) {
                $validator->errors()->add("variants.{$index}.online_price", "{$label}: visible en línea con precio cero se regalaría en la tienda.");
            }
        }

        if (! $rows->contains(fn ($row) => (bool) ($row['is_active'] ?? true))) {
            $validator->errors()->add('variants', 'Deja al menos un subartículo activo.');
        }
    }

    /**
     * Los articulos que pueden venir con `id` en la matriz: los subarticulos de la familia que se
     * edita; al convertir o agrupar, los articulos individuales que se juntan.
     *
     * @return list<int>
     */
    protected function ownVariantIds(): array
    {
        return $this->familyId() !== null
            ? Product::query()->withoutGlobalScopes()->where('product_family_id', $this->familyId())->pluck('id')->map(fn ($id) => (int) $id)->all()
            : [];
    }

    protected function rowLabel(?ProductAttributeValue $v1, ?ProductAttributeValue $v2): string
    {
        $parts = array_filter([$v1?->value, $v2?->value]);

        return $parts === [] ? 'sin valor' : implode(' · ', $parts);
    }

    /**
     * Lo validado listo para `ProductFamilyService`, sin lo que el usuario no puede tocar.
     *
     * @return array<string, mixed>
     */
    public function familyData(): array
    {
        $data = $this->safe()->except(['images', 'image_values']);
        $canCost = (bool) $this->user()?->can('products.show.view_cost');
        $canPrice = (bool) $this->user()?->can('products.show.edit_price');

        foreach ($data['variants'] as $index => $row) {
            $existing = isset($row['id']);

            // Sin permiso de ver el costo no se puede escribir: el de un subarticulo que ya
            // existe se queda como esta.
            if (! $canCost && $existing) {
                unset($data['variants'][$index]['cost']);
            }

            // Cambiar precios pide su permiso; los de un subarticulo nuevo si se ponen al crearlo.
            if (! $canPrice && $existing) {
                unset($data['variants'][$index]['price'], $data['variants'][$index]['online_price'], $data['variants'][$index]['wholesale_price']);
            }

            foreach (['barcode', 'description'] as $nullable) {
                if (array_key_exists($nullable, $row) && trim((string) $row[$nullable]) === '') {
                    $data['variants'][$index][$nullable] = null;
                }
            }
        }

        return $data;
    }

    /** @return Collection<int, int|null> Posicion de cada foto => el valor que muestra. */
    public function imageValues(): Collection
    {
        return collect($this->input('image_values', []))->map(fn ($value) => $value ? (int) $value : null);
    }
}
