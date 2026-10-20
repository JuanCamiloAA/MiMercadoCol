<?php

namespace App\Services\Storefront;

use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductAttributeValue;
use App\Models\ProductFamily;
use App\Models\ProductImage;
use App\Models\Service;
use App\Models\ServiceImage;
use App\Models\Store;
use App\Services\PromotionService;
use App\Services\WarehouseService;
use App\Support\Uploads;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Los articulos de la tienda tal como los pinta la tarjeta de producto.
 *
 * Es el unico que arma esa ficha: portada, estantes, categoria, busqueda, relacionados, venta
 * cruzada y vista previa pasan por aqui. El precio sale de `PromotionService` (o de
 * `ServicePricing`, para los servicios); la imagen llega resuelta (`image_path` →
 * `thumb_path` → `image_url`); el precio por unidad de medida se calcula aqui y en ningun otro
 * lado; y la existencia se dice segun la regla de la tarjeta, sin soltar la cifra exacta si
 * la sede no quiere.
 */
class StorefrontProducts
{
    public function __construct(
        protected PromotionService $promotions,
        protected WarehouseService $warehouses,
        protected StorefrontServiceCatalog $services,
    ) {}

    /**
     * Lo disponible de un articulo en la bodega que respalda la tienda: existencia menos lo
     * apartado, con `product_stocks` ya unida a esa bodega. El panel (`StorefrontAssortmentService`)
     * usa la misma expresion para decir que esta oculto: las dos tienen que decir lo mismo.
     */
    public const AVAILABLE_SQL = 'COALESCE(product_stocks.quantity, 0) - COALESCE(product_stocks.reserved_quantity, 0)';

    /**
     * Lo que la tienda puede ofrecer: activo, publicado en linea, con precio y con disponible en
     * la bodega que respalda la tienda (o que se vende aunque no haya, `allow_negative_stock`).
     *
     * @param  array<string, mixed>  $catalog  El ajuste `catalog` publicado. `show_out_of_stock` ya no cuenta.
     * @param  bool  $onlyAvailable  Solo para el aviso de la vista previa, que pregunta si el estante
     *                               quedo vacio por las existencias. El cliente siempre va con `true`.
     */
    public function query(Store $store, array $catalog = [], bool $onlyAvailable = true): Builder
    {
        // La tienda solo muestra lo que hay en la bodega que la respalda. Sumar el deposito
        // ofreceria mercancia que el alistador no va a encontrar en el estante.
        $warehouseId = $this->warehouses->onlineSourceFor($store->id)->id;

        return Product::query()
            ->withoutGlobalScopes()
            ->where('products.company_id', $store->company_id)
            ->whereNull('products.deleted_at')
            ->where('products.is_active', true)
            ->where('products.is_online_visible', true)
            // Un articulo en cero es un articulo que la tienda regala: nunca se pinta.
            ->whereRaw('(CASE WHEN products.online_price > 0 THEN products.online_price ELSE products.price END) > 0')
            ->leftJoin('product_stocks', function ($join) use ($warehouseId) {
                $join->on('product_stocks.product_id', '=', 'products.id')
                    ->where('product_stocks.warehouse_id', '=', $warehouseId);
            })
            // La tienda no ofrece lo que no puede entregar: publicado sin disponible queda oculto,
            // sin despublicarse, y vuelve solo cuando entra mercancia.
            ->when($onlyAvailable, fn (Builder $query) => self::whereAvailable($query))
            ->select(self::COLUMNS);
    }

    /** Con disponible en la bodega unida, o vendible aunque no haya. */
    public static function whereAvailable(Builder $query): Builder
    {
        return $query->where(fn (Builder $inner) => $inner
            ->where('products.allow_negative_stock', true)
            ->orWhereRaw(self::AVAILABLE_SQL.' > 0'));
    }

    /** Las columnas que necesita la ficha. */
    public const COLUMNS = [
        'products.id', 'products.name', 'products.brand', 'products.unit', 'products.sold_by_weight',
        'products.price', 'products.online_price', 'products.image_path', 'products.thumb_path',
        'products.image_url', 'products.category_id', 'products.is_featured', 'products.content_amount',
        'products.content_unit', 'products.allow_negative_stock', 'products.created_at', 'products.product_family_id',
        'products.variant_label', 'products.variant_value_1_id', 'products.variant_value_2_id', 'products.variant_sort',
        'product_stocks.quantity as stock_quantity', 'product_stocks.reserved_quantity',
    ];

    /**
     * La ficha de cada articulo, en el orden en que llegan.
     *
     * @param  iterable<object>  $rows
     * @param  array<string, mixed>  $card  El ajuste `card` publicado.
     * @param  array<int, array<string, mixed>>|null  $promos  Precios de promocion ya calculados.
     * @return list<array<string, mixed>>
     */
    public function present(iterable $rows, Store $store, array $card = [], bool $perUnit = true, ?array $promos = null): array
    {
        $rows = collect($rows);

        if ($rows->isEmpty()) {
            return [];
        }

        $promos ??= $this->promotions->unitPrices($rows, $store->company_id, PromotionService::CHANNEL_ONLINE);
        $icons = $this->categoryIcons($store, $rows->pluck('category_id')->filter()->unique()->all());
        $card = array_merge(StorefrontStructure::DEFAULTS['card'], $card);

        // Un articulo con subarticulos sale una sola vez: su ficha va en el lugar de la primera
        // fila suya que llegue, y las demas se descartan. Asi un estante no repite tallas.
        $families = $this->familyCards($rows->pluck('product_family_id')->filter()->unique()->values()->all(), $store, $card);
        $extras = $this->cardExtras($rows, $card);
        $emitted = [];

        return $rows->map(function ($row) use ($promos, $icons, $card, $perUnit, $families, $extras, &$emitted) {
            if (! empty($row->product_family_id)) {
                $familyId = (int) $row->product_family_id;

                if (isset($emitted[$familyId]) || ! isset($families[$familyId])) {
                    return null;
                }

                $emitted[$familyId] = true;

                return $families[$familyId];
            }

            $product = $this->productCard($row, $promos[(int) $row->id] ?? null, $icons, $card, $perUnit);

            // Solo si la sede los pidió en la tarjeta: la línea de descripción y la foto al pasar el mouse.
            foreach (['description', 'image_hover'] as $key) {
                if (isset($extras[$key][(int) $row->id])) {
                    $product[$key] = $extras[$key][(int) $row->id];
                }
            }

            return $product;
        })->filter()->values()->all();
    }

    /**
     * Lo que la tarjeta muestra solo si la sede lo pidió: una línea de la descripción de la ficha
     * y la segunda foto de la galería (la primera que no es la portada). Va en dos consultas
     * aparte para no cargar la consulta de siempre con texto que casi nunca se pinta.
     *
     * @param  Collection<int, object>  $rows
     * @param  array<string, mixed>  $card
     * @return array{description: array<int, string>, image_hover: array<int, string>}
     */
    protected function cardExtras(Collection $rows, array $card): array
    {
        $extras = ['description' => [], 'image_hover' => []];
        $singles = $rows->filter(fn ($row) => empty($row->product_family_id));
        $ids = $singles->pluck('id')->map(fn ($id) => (int) $id)->unique()->values()->all();

        if ($ids === []) {
            return $extras;
        }

        if ($card['show_description'] ?? false) {
            $extras['description'] = Product::query()->withoutGlobalScopes()->whereIn('id', $ids)->whereNotNull('description')->pluck('description', 'id')
                ->map(fn ($text) => Str::limit(trim(preg_replace('/\s+/', ' ', strip_tags((string) $text)) ?? ''), 90))
                ->filter(fn (string $text) => $text !== '')
                ->all();
        }

        if (($card['image_hover'] ?? 'none') === 'second') {
            $covers = $singles->mapWithKeys(fn ($row) => [(int) $row->id => (string) $row->image_path])->all();

            ProductImage::query()->whereIn('product_id', $ids)->orderBy('sort_order')->orderBy('id')->get(['product_id', 'path'])
                ->groupBy('product_id')
                ->each(function (Collection $images, int|string $productId) use ($covers, &$extras) {
                    $second = $images->first(fn (ProductImage $image) => $image->path !== ($covers[(int) $productId] ?? null));

                    if ($second && ($url = Uploads::url($second->path))) {
                        $extras['image_hover'][(int) $productId] = $url;
                    }
                });
        }

        return $extras;
    }

    /**
     * La ficha de un articulo suelto (o de la unica presentacion de uno con subarticulos).
     *
     * @param  array<string, mixed>|null  $promo
     * @param  array<int, string>  $icons
     * @param  array<string, mixed>  $card
     * @return array<string, mixed>
     */
    protected function productCard(object $row, ?array $promo, array $icons, array $card, bool $perUnit): array
    {
        $price = (int) ($promo['price'] ?? ((int) $row->online_price > 0 ? $row->online_price : $row->price));
        $compare = $promo && (int) $promo['compare_at'] > $price ? (int) $promo['compare_at'] : null;
        $available = round((float) ($row->stock_quantity ?? 0) - (float) ($row->reserved_quantity ?? 0), 3);
        $image = self::image($row->thumb_path ?: $row->image_path, $row->image_url);

        return [
            'kind' => 'product',
            'id' => (int) $row->id,
            'key' => 'p:'.$row->id,
            'name' => $row->name,
            'brand' => $row->brand ?: null,
            'content' => self::content($row->content_amount, $row->content_unit),
            'unit' => $row->unit,
            'sold_by_weight' => (bool) $row->sold_by_weight,
            'step' => $row->sold_by_weight ? 0.5 : 1,
            'price' => $price,
            'compare_at' => $compare,
            'deal' => $compare ? [
                'percent' => (int) round(($compare - $price) / $compare * 100),
                'savings' => $compare - $price,
            ] : null,
            'unit_price' => $perUnit && ($card['show_unit_price'] ?? true) ? self::pricePerUnit($price, $row->content_amount, $row->content_unit) : null,
            'image' => $image['url'],
            'image_external' => $image['external'],
            'category_icon' => $icons[(int) $row->category_id] ?? 'Basket',
            'category_id' => $row->category_id ? (int) $row->category_id : null,
            'href' => '/tienda/producto/'.$row->id,
            'stock' => self::stock($available, (bool) $row->allow_negative_stock, (bool) $row->sold_by_weight, $card),
        ];
    }

    /**
     * La ficha de cada articulo con subarticulos: el nombre del principal, «Desde» con el menor
     * precio vigente entre los vendibles, la etiqueta de la mejor oferta, el resumen de opciones y
     * «Agotado» solo si todos lo estan. El precio por unidad no va: el de 500 ml y el de 2 L no
     * tienen el mismo.
     *
     * @param  list<int>  $familyIds
     * @param  array<string, mixed>  $card
     * @return array<int, array<string, mixed>>
     */
    public function familyCards(array $familyIds, Store $store, array $card = []): array
    {
        if ($familyIds === []) {
            return [];
        }

        $card = array_merge(StorefrontStructure::DEFAULTS['card'], $card);
        $families = ProductFamily::query()->withoutGlobalScopes()
            ->where('company_id', $store->company_id)
            ->whereIn('id', $familyIds)
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->where('is_online_visible', true)
            ->with(['attribute1', 'attribute2', 'images'])
            ->get()
            ->keyBy('id');

        $variants = $this->sellableVariants($families->keys()->all(), $store);
        $promos = $this->promotions->unitPrices($variants->flatten(1), $store->company_id, PromotionService::CHANNEL_ONLINE);
        $values = ProductAttributeValue::query()->with('media')
            ->whereIn('id', $variants->flatten(1)->pluck('variant_value_1_id')->merge($variants->flatten(1)->pluck('variant_value_2_id'))->filter()->unique())
            ->get()
            ->keyBy('id');
        $icons = $this->categoryIcons($store, $families->pluck('category_id')->filter()->unique()->all());
        $cards = [];

        foreach ($families as $family) {
            $rows = $variants->get($family->id, collect());

            if ($rows->isEmpty()) {
                continue;
            }

            $priced = $rows->map(function ($row) use ($promos) {
                $promo = $promos[(int) $row->id] ?? null;
                $price = (int) ($promo['price'] ?? ((int) $row->online_price > 0 ? $row->online_price : $row->price));
                $compare = $promo && (int) $promo['compare_at'] > $price ? (int) $promo['compare_at'] : null;
                $available = round((float) ($row->stock_quantity ?? 0) - (float) ($row->reserved_quantity ?? 0), 3);

                return [
                    'row' => $row,
                    'price' => $price,
                    'compare' => $compare,
                    'percent' => $compare ? (int) round(($compare - $price) / $compare * 100) : 0,
                    'available' => $available,
                ];
            });

            // `sellableVariants()` solo trae presentaciones con disponible: una familia sin
            // ninguna ya salio arriba (`$rows->isEmpty()`) y no tiene tarjeta.
            $representative = $priced->sortBy([['price', 'asc'], [fn ($entry) => $entry['row']->variant_sort, 'asc']])->first();
            $best = $priced->sortByDesc('percent')->first();
            $common = $family->images->firstWhere('attribute_value_id', null) ?? $family->images->first();
            $image = $common
                ? ['url' => Uploads::url($common->thumb()), 'external' => false]
                : self::image($representative['row']->thumb_path ?: $representative['row']->image_path, $representative['row']->image_url);
            $maxAvailable = (float) $priced->max('available');
            $anyNegative = $priced->contains(fn ($entry) => (bool) $entry['row']->allow_negative_stock);

            $cards[$family->id] = [
                'kind' => 'family',
                'id' => $family->id,
                'key' => 'f:'.$family->id,
                'name' => $family->name,
                'brand' => $family->brand ?: null,
                'content' => null,
                'unit' => 'unit',
                'sold_by_weight' => false,
                'step' => 1,
                'price' => $representative['price'],
                // «Desde»: los precios de sus presentaciones no son todos iguales.
                'price_from' => $priced->pluck('price')->unique()->count() > 1,
                'compare_at' => null,
                'deal' => $best['percent'] > 0 ? [
                    'percent' => $best['percent'],
                    'savings' => (int) $best['compare'] - (int) $best['price'],
                    // «Hasta -20%»: no todas las presentaciones tienen la misma rebaja.
                    'upto' => $priced->pluck('percent')->unique()->count() > 1,
                ] : null,
                'unit_price' => null,
                'image' => $image['url'],
                'image_external' => $image['external'],
                'category_icon' => $icons[(int) $family->category_id] ?? 'Basket',
                'category_id' => $family->category_id ? (int) $family->category_id : null,
                'href' => '/tienda/articulo/'.$family->slug,
                'slug' => $family->slug,
                'stock' => self::stock($anyNegative ? 1.0 : $maxAvailable, $anyNegative, false, $card),
                'options' => $this->optionsSummary($family, $rows, $values),
                'choose_label' => $this->chooseLabel($family),
                // Con una sola presentacion a la venta se agrega directo, sin escoger.
                'single' => $rows->count() === 1 ? $this->productCard($rows->first(), $promos[(int) $rows->first()->id] ?? null, $icons, $card, true) : null,
            ];
        }

        return $cards;
    }

    /**
     * Todo lo que la pagina del articulo necesita para escoger, calculado aqui: las
     * caracteristicas con sus valores en orden, y por cada presentacion vendible su precio vigente
     * (tachado si hay promocion), su precio por unidad, su existencia segun la regla de la tarjeta
     * y sus fotos. El navegador solo cruza los valores elegidos; no calcula precios.
     *
     * @param  array<string, mixed>  $settings  Los ajustes publicados de la sede.
     * @return array<string, mixed>
     */
    public function familyDetail(ProductFamily $family, Store $store, array $settings): array
    {
        $family->loadMissing(['attribute1.values', 'attribute2.values', 'images']);
        $card = array_merge(StorefrontStructure::DEFAULTS['card'], $settings['card'] ?? []);
        $perUnit = (bool) ($settings['product_page']['show_unit_price'] ?? true) && (bool) ($settings['catalog']['price_per_unit'] ?? true);
        $rows = $this->sellableVariants([$family->id], $store)->get($family->id, collect());
        $promos = $this->promotions->unitPrices($rows, $store->company_id, PromotionService::CHANNEL_ONLINE);
        $icons = $this->categoryIcons($store, array_filter([(int) $family->category_id]));
        $own = ProductImage::query()->whereIn('product_id', $rows->pluck('id'))->orderBy('sort_order')->get()->groupBy('product_id');
        $descriptions = Product::query()->withoutGlobalScopes()->whereIn('id', $rows->pluck('id'))->pluck('description', 'id');
        $used = $rows->pluck('variant_value_1_id')->merge($rows->pluck('variant_value_2_id'))->filter()->map(fn ($id) => (int) $id)->unique();

        $attributes = [];

        foreach ([1 => $family->attribute1, 2 => $family->attribute2] as $position => $attribute) {
            if (! $attribute) {
                continue;
            }

            $attributes[] = [
                'id' => $attribute->id,
                'position' => $position,
                'name' => $attribute->name,
                'display' => $attribute->display,
                'values' => $attribute->values
                    ->filter(fn (ProductAttributeValue $value) => $used->contains($value->id))
                    ->map(fn (ProductAttributeValue $value) => [
                        'id' => $value->id,
                        'value' => $value->value,
                        'color_hex' => $value->color_hex,
                        'image' => $value->media ? Uploads::url((string) ($value->media->variants['square'] ?? $value->media->path)) : null,
                    ])->values()->all(),
            ];
        }

        $variants = $rows->map(function ($row) use ($promos, $icons, $card, $perUnit, $own, $descriptions, $family) {
            $images = $own->get($row->id, collect())->map(fn (ProductImage $image) => Uploads::url($image->path))->values()->all();

            return array_merge($this->productCard($row, $promos[(int) $row->id] ?? null, $icons, $card, $perUnit), [
                'label' => $row->variant_label,
                'value_1_id' => $row->variant_value_1_id ? (int) $row->variant_value_1_id : null,
                'value_2_id' => $row->variant_value_2_id ? (int) $row->variant_value_2_id : null,
                // Las fotos propias; si no tiene, la galeria usa las comunes (o las de su color).
                'images' => $images !== [] ? $images : ($row->image_path ? [Uploads::url($row->image_path)] : []),
                'description' => $descriptions->get($row->id) ?: $family->description,
            ]);
        })->values()->all();

        // Todas las que llegan tienen disponible: si la de por defecto no esta, va la primera.
        $default = collect($variants)->firstWhere('id', $family->default_variant_id) ?? ($variants[0] ?? null);

        return [
            'family' => [
                'id' => $family->id,
                'name' => $family->name,
                'slug' => $family->slug,
                'brand' => $family->brand,
                'description' => $family->description,
                'category_id' => $family->category_id,
                'tax_rate' => (float) $family->tax_rate,
                'href' => '/tienda/articulo/'.$family->slug,
            ],
            'attributes' => $attributes,
            'variants' => $variants,
            'images' => $family->images->map(fn ($image) => ['url' => Uploads::url($image->path), 'value_id' => $image->attribute_value_id])->values()->all(),
            'default_id' => $default['id'] ?? null,
        ];
    }

    /**
     * Los subarticulos que se pueden vender en linea, con su existencia en la bodega de la tienda.
     *
     * @param  list<int>  $familyIds
     * @return Collection<int, Collection<int, Product>>
     */
    public function sellableVariants(array $familyIds, Store $store): Collection
    {
        if ($familyIds === []) {
            return collect();
        }

        $warehouseId = $this->warehouses->onlineSourceFor($store->id)->id;

        return Product::query()
            ->withoutGlobalScopes()
            ->where('products.company_id', $store->company_id)
            ->whereIn('products.product_family_id', $familyIds)
            ->whereNull('products.deleted_at')
            ->where('products.is_active', true)
            ->where('products.is_online_visible', true)
            ->whereRaw('(CASE WHEN products.online_price > 0 THEN products.online_price ELSE products.price END) > 0')
            ->leftJoin('product_stocks', function ($join) use ($warehouseId) {
                $join->on('product_stocks.product_id', '=', 'products.id')
                    ->where('product_stocks.warehouse_id', '=', $warehouseId);
            })
            // La misma regla que `query()`: una presentacion sin disponible sale del selector.
            ->where(fn (Builder $query) => self::whereAvailable($query))
            ->select(self::COLUMNS)
            ->orderBy('products.variant_sort')
            ->get()
            ->groupBy('product_family_id');
    }

    /**
     * «3 presentaciones», «Tallas S–XL · 2 colores»; y las muestras si una caracteristica se
     * escoge por color.
     *
     * @param  Collection<int, Product>  $rows
     * @param  Collection<int, ProductAttributeValue>  $values
     * @return array{summary: string, swatches: list<array{value: string, color_hex: string|null, image: string|null}>}
     */
    protected function optionsSummary(ProductFamily $family, Collection $rows, Collection $values): array
    {
        $parts = [];
        $swatches = [];

        foreach ([[$family->attribute1, 'variant_value_1_id'], [$family->attribute2, 'variant_value_2_id']] as [$attribute, $column]) {
            if (! $attribute) {
                continue;
            }

            $used = $rows->pluck($column)->filter()->unique()->map(fn ($id) => $values->get((int) $id))->filter()->sortBy('sort_order')->values();

            if ($used->isEmpty()) {
                continue;
            }

            $parts[] = match (true) {
                $attribute->isMeasure() => $used->count() === 1 ? $used->first()->value : $used->count().' presentaciones',
                $attribute->name_in_label && $used->count() > 1 => self::plural($attribute->name).' '.$used->first()->value.'–'.$used->last()->value,
                $used->count() === 1 => $used->first()->value,
                default => $used->count().' '.mb_strtolower(self::plural($attribute->name)),
            };

            if ($attribute->display === ProductAttribute::DISPLAY_SWATCHES) {
                foreach ($used as $value) {
                    $swatches[] = [
                        'value' => $value->value,
                        'color_hex' => $value->color_hex,
                        'image' => $value->media ? Uploads::url((string) ($value->media->variants['square'] ?? $value->media->path)) : null,
                    ];
                }
            }
        }

        return ['summary' => implode(' · ', $parts), 'swatches' => array_slice($swatches, 0, 8)];
    }

    /** «Elegir talla», «Elegir presentación»; con dos caracteristicas, «Elegir». */
    protected function chooseLabel(ProductFamily $family): string
    {
        if ($family->attribute2 || ! $family->attribute1) {
            return 'Elegir';
        }

        return 'Elegir '.($family->attribute1->isMeasure() ? 'presentación' : mb_strtolower($family->attribute1->name));
    }

    /** «Talla» → «Tallas», «Color» → «Colores», «Tamaño» → «Tamaños». */
    public static function plural(string $word): string
    {
        return preg_match('/[aeiouáéó]$/iu', $word) === 1 ? $word.'s' : $word.'es';
    }

    /**
     * Los servicios con la misma ficha que los articulos, y su distintivo.
     *
     * @param  Collection<int, Service>  $services
     * @param  array<string, mixed>  $card
     * @return list<array<string, mixed>>
     */
    public function presentServices(Collection $services, Store $store, array $card = []): array
    {
        $card = array_merge(StorefrontStructure::DEFAULTS['card'], $card);

        // Un servicio que no alcanza para una unidad no se muestra, como un articulo sin disponible.
        return collect($this->services->present($this->services->onlyAvailable($services, $store), $store))->map(function (array $service) use ($card) {
            $image = self::image($service['thumb_path'] ?: $service['image_path'], null);

            return [
                'kind' => 'service',
                'id' => (int) $service['id'],
                'key' => 's:'.$service['id'],
                'name' => $service['name'],
                'brand' => null,
                'content' => null,
                'unit' => 'unit',
                'sold_by_weight' => false,
                'step' => 1,
                'price' => (int) $service['price'],
                'compare_at' => null,
                'deal' => null,
                'unit_price' => null,
                'image' => $image['url'],
                'image_external' => false,
                'category_icon' => 'Package',
                'category_id' => $service['category_id'] ? (int) $service['category_id'] : null,
                'href' => '/tienda/servicio/'.$service['id'],
                'stock' => self::stock((float) $service['available'], false, false, $card),
                'includes' => $service['includes'],
            ];
        })->values()->all();
    }

    /**
     * La foto de un articulo ya cargado. Un subarticulo sin foto propia muestra la de su valor (la
     * del color, por ejemplo) o la comun del articulo: la misma que vio el cliente al elegirlo.
     * Espera `family.images` cargado para no consultar por renglon.
     *
     * @return array{url: string|null, external: bool}
     */
    public static function productImage(Product $product): array
    {
        $own = self::image($product->thumb_path ?: $product->image_path, $product->image_url);

        if ($own['url'] !== null || ! $product->product_family_id || ! $product->family) {
            return $own;
        }

        $images = $product->family->images;
        $values = array_filter([$product->variant_value_1_id, $product->variant_value_2_id]);
        $pick = $images->first(fn ($image) => $image->attribute_value_id !== null && in_array((int) $image->attribute_value_id, $values, true))
            ?? $images->firstWhere('attribute_value_id', null)
            ?? $images->first();

        return $pick ? ['url' => Uploads::url($pick->thumb()), 'external' => false] : $own;
    }

    /**
     * Las fotos de la ficha de un articulo, en tamaño completo y con la portada primero: lo que
     * recorre el carrusel. Sin fotos propias, la portada suelta o la del catalogo externo. Muestra
     * todas las que tiene aunque la empresa haya bajado despues el tope: el tope es para montar.
     *
     * @return list<string>
     */
    public function gallery(Product $product): array
    {
        $own = ProductImage::query()->where('product_id', $product->id)->orderBy('sort_order')->orderBy('id')->get()
            ->sortBy(fn (ProductImage $image) => $image->path === $product->image_path ? 0 : 1)
            ->map(fn (ProductImage $image) => Uploads::url($image->path))
            ->filter()
            ->values()
            ->all();

        if ($own !== []) {
            return $own;
        }

        $cover = self::image($product->image_path, $product->image_url)['url'];

        return $cover ? [$cover] : [];
    }

    /**
     * Las fotos de la ficha de un servicio, con la portada primero.
     *
     * @return list<string>
     */
    public function serviceGallery(Service $service): array
    {
        $images = $service->images()->get()
            ->sortBy(fn (ServiceImage $image) => $image->path === $service->image_path ? 0 : 1)
            ->map(fn (ServiceImage $image) => Uploads::url($image->path))
            ->filter()
            ->values()
            ->all();

        if ($images === [] && $service->image_path) {
            return array_values(array_filter([Uploads::url($service->image_path)]));
        }

        return $images;
    }

    /** El enlace de un articulo: un subarticulo abre su articulo con la presentacion elegida. */
    public static function productHref(Product $product): string
    {
        return $product->product_family_id && $product->family
            ? '/tienda/articulo/'.$product->family->slug.'?variante='.$product->id
            : '/tienda/producto/'.$product->id;
    }

    /**
     * La imagen resuelta: la propia (ya en el disco publico) o la del catalogo externo.
     *
     * @return array{url: string|null, external: bool}
     */
    public static function image(?string $path, ?string $url): array
    {
        if ($path) {
            return ['url' => Uploads::url($path), 'external' => false];
        }

        if ($url && preg_match('#^https?://#i', $url) === 1) {
            return ['url' => $url, 'external' => true];
        }

        return ['url' => null, 'external' => false];
    }

    /** «900 ml», «1 kg», «6 un». */
    public static function content(mixed $amount, ?string $unit): ?string
    {
        $amount = (float) $amount;

        if ($amount <= 0 || ! $unit) {
            return null;
        }

        $labels = ['kg' => 'kg', 'g' => 'g', 'l' => 'L', 'ml' => 'ml', 'un' => 'un'];

        return rtrim(rtrim(number_format($amount, 2, ',', '.'), '0'), ',').' '.($labels[$unit] ?? $unit);
    }

    /**
     * «$ 12.778 x L», para comparar presentaciones distintas del mismo producto.
     *
     * Gramos y mililitros se llevan a kilo y litro. Una presentacion de un kilo o un litro no
     * lo muestra: seria repetir el precio.
     */
    public static function pricePerUnit(int $price, mixed $amount, ?string $unit): ?string
    {
        $amount = (float) $amount;

        if ($amount <= 0 || ! $unit || $price <= 0) {
            return null;
        }

        [$base, $label] = match ($unit) {
            'g' => [$amount / 1000, 'kg'],
            'ml' => [$amount / 1000, 'L'],
            'kg' => [$amount, 'kg'],
            'l' => [$amount, 'L'],
            'un' => [$amount, 'unidad'],
            default => [0, ''],
        };

        if ($base <= 0 || abs($base - 1.0) < 0.0001) {
            return null;
        }

        return StorefrontStructure::money((int) round($price / $base)).' x '.$label;
    }

    /**
     * El aviso de existencias segun la regla de la tarjeta.
     *
     * Por defecto no se dice la cifra: «Últimas 3 unidades» solo bajo el umbral. La cifra exacta
     * solo si la sede lo pidio. «Agotado» ya no existe: lo que no tiene disponible no llega a
     * la tienda (`query()`), asi que aqui siempre hay algo que vender.
     *
     * @param  array<string, mixed>  $card
     * @return array{level: string, note: string|null}
     */
    public static function stock(float $available, bool $allowNegative, bool $byWeight, array $card): array
    {
        if ($allowNegative || $available <= 0) {
            return ['level' => 'ok', 'note' => null];
        }

        $display = $card['stock_display'] ?? 'low_only';
        $threshold = max(1, (int) ($card['low_stock_threshold'] ?? 5));
        $low = $available <= $threshold;
        $amount = rtrim(rtrim(number_format($available, 2, ',', '.'), '0'), ',');

        $note = match (true) {
            $display === 'exact' => $byWeight ? $amount.' kg disponibles' : $amount.' disponibles',
            $display === 'low_only' && $low => $byWeight ? 'Quedan '.$amount.' kg' : ($available <= 1 ? 'Última unidad' : 'Últimas '.$amount.' unidades'),
            default => null,
        };

        return ['level' => $low ? 'low' : 'ok', 'note' => $note];
    }

    /**
     * Los iconos de las categorias, para la tarjeta sin foto.
     *
     * @param  list<int>  $ids
     * @return array<int, string>
     */
    protected function categoryIcons(Store $store, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return DB::table('categories')
            ->where('company_id', $store->company_id)
            ->whereIn('id', $ids)
            ->pluck('icon', 'id')
            ->map(fn ($icon) => StorefrontStructure::icon($icon))
            ->all();
    }
}
