<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductAttributeValue;
use App\Models\ProductFamily;
use App\Models\ProductFamilyImage;
use App\Models\Store;
use App\Models\User;
use App\Services\ImageService;
use App\Services\ProductAttributeCatalog;
use App\Services\ProductFamilyService;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Articulos con subarticulos de demostracion.
 *
 * Primero la canasta familiar: en La Canasta, el aceite de 1 L que ya existia pasa a ser el
 * primer subarticulo de «Aceite de girasol» (500 ml, 1 L, 2 L) y la leche se vende por
 * presentacion (bolsa de 1 L, de 1,1 L y la paca de seis). En El Vecino, una gaseosa por sabor y
 * volumen y una camiseta por talla y color, con una foto por color y alguna combinacion agotada.
 *
 * Todo por `ProductFamilyService`, y las existencias iniciales por `StockService` desde ahi: nada
 * se escribe a mano.
 */
class ProductFamilySeeder extends Seeder
{
    public function __construct(
        protected ProductFamilyService $families,
        protected ProductAttributeCatalog $catalog,
        protected ImageService $images,
    ) {}

    public function run(): void
    {
        // La Canasta no nace por el aprovisionador: su catalogo de caracteristicas se siembra aqui.
        foreach (Company::query()->pluck('id') as $companyId) {
            $this->catalog->seedDefaults((int) $companyId);
        }

        if ($canasta = Company::query()->orderBy('id')->first()) {
            $this->canasta($canasta);
        }

        if ($neighbor = Company::query()->where('tax_id', NeighborCompanySeeder::TAX_ID)->first()) {
            $this->neighbor($neighbor);
        }
    }

    protected function canasta(Company $company): void
    {
        $admin = User::query()->where('email', 'admin@lacanasta.co')->first();
        $stores = $this->stores($company);

        if (! $admin || $stores->isEmpty()) {
            return;
        }

        $volume = $this->attribute($company, 'volumen');
        $oil = $this->product($company, 'DES-038');

        if ($oil && ! $oil->product_family_id) {
            $family = $this->families->convertToFamily($oil, [
                'name' => 'Aceite de girasol',
                'code' => 'ACE-GIR',
                'description' => 'Aceite de girasol en tres presentaciones. Ideal para freír y para ensaladas.',
                'category_id' => $oil->category_id,
                'brand' => $oil->brand,
                'supplier_id' => $oil->supplier_id,
                'tax_rate' => $oil->tax_rate,
                'attribute_1_id' => $volume->id,
                'default_index' => 1,
                'variants' => [
                    $this->row([$this->value($volume, '500 ml')], 'DES-038-500', '7700000500001', 4900, 7300, 25, $stores, [36, 20]),
                    ['id' => $oil->id, 'value_1_id' => $this->value($volume, '1 L')->id, 'is_active' => true, 'is_online_visible' => true],
                    $this->row([$this->value($volume, '2 L')], 'DES-038-2L', '7700000500002', 16900, 23900, 12, $stores, [18, 8]),
                ],
            ], $admin);

            // La misma foto de la marca para las presentaciones nuevas.
            $this->shareImage($family, $oil);
        }

        $milk = $this->product($company, 'LAC-019');

        if ($milk && ! $milk->product_family_id) {
            // «Bolsa x6» no es un volumen: la presentacion es texto y el contenido va en cada fila,
            // para que la tienda siga calculando el precio por litro.
            $presentation = $this->catalog->attributeNamed($company->id, 'Presentación');

            $family = $this->families->convertToFamily($milk, [
                'name' => 'Leche entera',
                'code' => 'LEC-ENT',
                'description' => 'Leche entera pasteurizada, en bolsa de 1 L, de 1,1 L o la paca de seis.',
                'category_id' => $milk->category_id,
                'brand' => $milk->brand,
                'supplier_id' => $milk->supplier_id,
                'tax_rate' => $milk->tax_rate,
                'attribute_1_id' => $presentation->id,
                'default_index' => 0,
                'variants' => [
                    ['id' => $milk->id, 'value_1_id' => $this->value($presentation, 'Bolsa 1 L')->id, 'content_amount' => 1, 'content_unit' => 'l', 'is_active' => true, 'is_online_visible' => true],
                    $this->row([$this->value($presentation, 'Bolsa 1,1 L')], 'LAC-019-11', '7700000500003', 3400, 4600, 30, $stores, [48, 24], ['content_amount' => 1.1, 'content_unit' => 'l']),
                    $this->row([$this->value($presentation, 'Paca x6 bolsas de 1,1 L')], 'LAC-019-X6', '7700000500004', 19800, 26900, 8, $stores, [14, 6], ['content_amount' => 6.6, 'content_unit' => 'l', 'unit' => Product::UNIT_PACK]),
                ],
            ], $admin);

            $this->shareImage($family, $milk);
        }
    }

    protected function neighbor(Company $company): void
    {
        $admin = User::query()->where('email', 'admin@elvecino.co')->first();
        $stores = $this->stores($company);

        if (! $admin || $stores->isEmpty()) {
            return;
        }

        if (! $this->familyExists($company, 'GAS-VEC')) {
            $flavor = $this->attribute($company, 'sabor');
            $volume = $this->attribute($company, 'volumen');
            $drinks = $this->category($company, 'Bebidas', 'BeerBottle');
            $rows = [];
            $counter = 10;

            foreach (['Cola', 'Naranja', 'Uva'] as $name) {
                foreach ([['400 ml', 1700, 2500, [40, 24]], ['1,5 L', 4300, 6200, [30, 15]]] as [$size, $cost, $price, $stock]) {
                    // La de uva grande quedo agotada: la tienda la muestra tachada.
                    $stock = $name === 'Uva' && $size === '1,5 L' ? [0, 0] : $stock;
                    $sku = 'VEC-GAS-'.strtoupper(substr(Str::ascii($name), 0, 3)).'-'.($size === '400 ml' ? '400' : '1500');
                    $rows[] = $this->row([$this->value($flavor, $name), $this->value($volume, $size)], $sku, '77100003'.str_pad((string) $counter++, 5, '0', STR_PAD_LEFT), $cost, $price, 10, $stores, $stock);
                }
            }

            $this->families->create([
                'name' => 'Gaseosa',
                'code' => 'GAS-VEC',
                'description' => 'Gaseosa bien fría en tres sabores, personal o familiar.',
                'category_id' => $drinks->id,
                'brand' => 'Burbuja',
                'tax_rate' => 19,
                'attribute_1_id' => $flavor->id,
                'attribute_2_id' => $volume->id,
                'default_index' => 1,
                'variants' => $rows,
            ], $admin, $company->id);
        }

        if (! $this->familyExists($company, 'CAM-BAS')) {
            $size = $this->attribute($company, 'talla');
            $color = $this->attribute($company, 'color');
            $clothes = $this->category($company, 'Ropa básica', 'TShirt');
            $rows = [];
            $counter = 40;

            foreach (['S', 'M', 'L', 'XL'] as $talla) {
                foreach (['Blanco', 'Negro', 'Azul'] as $tone) {
                    // La XL azul se agoto y la S negra solo queda en Envigado.
                    $stock = match (true) {
                        $talla === 'XL' && $tone === 'Azul' => [0, 0],
                        $talla === 'S' && $tone === 'Negro' => [4, 0],
                        default => [8, 5],
                    };

                    $sku = 'VEC-CAM-'.$talla.'-'.strtoupper(substr($tone, 0, 3));
                    $price = $talla === 'XL' ? 26900 : 24900;
                    $rows[] = $this->row([$this->value($size, $talla), $this->value($color, $tone)], $sku, '77100004'.str_pad((string) $counter++, 5, '0', STR_PAD_LEFT), 12000, $price, 3, $stores, $stock);
                }
            }

            $family = $this->families->create([
                'name' => 'Camiseta básica de algodón',
                'code' => 'CAM-BAS',
                'description' => 'Camiseta cuello redondo, 100 % algodón. Tallas S a XL.',
                'category_id' => $clothes->id,
                'tax_rate' => 19,
                'attribute_1_id' => $size->id,
                'attribute_2_id' => $color->id,
                'default_index' => 4,
                'variants' => $rows,
            ], $admin, $company->id);

            // Una foto por color: al elegir el color, la galeria cambia.
            foreach (['Blanco', 'Negro', 'Azul'] as $position => $tone) {
                $value = $this->value($color, $tone);
                $stored = $this->images->storeProductImage($this->shirtPhoto((string) ($value->color_hex ?? '#888888')), $company->id);

                ProductFamilyImage::query()->create([
                    'product_family_id' => $family->id,
                    'path' => $stored['path'],
                    'thumb_path' => $stored['thumb_path'],
                    'attribute_value_id' => $value->id,
                    'sort_order' => $position + 1,
                ]);
            }
        }
    }

    /**
     * Una fila de la matriz con su existencia inicial por sede.
     *
     * @param  list<ProductAttributeValue>  $values
     * @param  Collection<int, Store>  $stores
     * @param  list<int>  $stock  Cantidad por sede, en el orden de `$stores`.
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    protected function row(array $values, string $sku, string $barcode, int $cost, int $price, int $minStock, Collection $stores, array $stock, array $extra = []): array
    {
        return array_merge([
            'value_1_id' => $values[0]->id,
            'value_2_id' => $values[1]->id ?? null,
            'sku' => $sku,
            'barcode' => $barcode,
            'cost' => $cost,
            'price' => $price,
            'online_price' => $price,
            'wholesale_price' => (int) round($price * 0.93),
            'min_stock' => $minStock,
            'unit' => Product::UNIT_UNIT,
            'is_active' => true,
            'is_online_visible' => true,
            'initial_stocks' => $stores->values()->map(fn (Store $store, int $index) => ['store_id' => $store->id, 'quantity' => $stock[$index] ?? 0])->all(),
        ], $extra);
    }

    /** Las presentaciones nuevas se quedan con la foto del articulo que ya existia. */
    protected function shareImage(ProductFamily $family, Product $original): void
    {
        $original->refresh();

        if (! $original->image_url && ! $original->image_path) {
            return;
        }

        foreach ($family->variants()->where('id', '!=', $original->id)->get() as $variant) {
            if (! $variant->image_url && ! $variant->image_path) {
                $variant->forceFill(['image_url' => $original->image_url, 'image_path' => $original->image_path, 'thumb_path' => $original->thumb_path])->save();
            }
        }
    }

    /**
     * Una camiseta plana del color pedido: basta para ver que la galeria cambia con el color.
     */
    protected function shirtPhoto(string $hex): UploadedFile
    {
        $image = imagecreatetruecolor(900, 900);
        imagefill($image, 0, 0, imagecolorallocate($image, 243, 241, 236));

        [$red, $green, $blue] = sscanf($hex, '#%02x%02x%02x') ?: [136, 136, 136];
        $fill = imagecolorallocate($image, (int) $red, (int) $green, (int) $blue);
        $line = imagecolorallocate($image, max(0, (int) $red - 70), max(0, (int) $green - 70), max(0, (int) $blue - 70));

        $outline = [320, 140, 200, 180, 80, 330, 170, 400, 235, 345, 235, 700, 565, 700, 565, 345, 630, 400, 720, 330, 600, 180, 480, 140, 455, 180, 400, 198, 345, 180];
        $points = array_map(fn (int $point) => (int) round($point * 1.125), $outline);

        imagefilledpolygon($image, $points, $fill);
        imagesetthickness($image, 6);
        imagepolygon($image, $points, $line);

        $path = tempnam(sys_get_temp_dir(), 'cam');
        imagepng($image, $path);
        imagedestroy($image);

        return new UploadedFile($path, 'camiseta.png', 'image/png', null, true);
    }

    protected function value(ProductAttribute $attribute, string $text): ProductAttributeValue
    {
        return $this->catalog->valueFromText($attribute, $text);
    }

    protected function attribute(Company $company, string $slug): ProductAttribute
    {
        return ProductAttribute::query()->withoutGlobalScopes()->where('company_id', $company->id)->where('slug', $slug)->firstOrFail();
    }

    protected function product(Company $company, string $sku): ?Product
    {
        return Product::query()->withoutGlobalScopes()->where('company_id', $company->id)->where('sku', $sku)->first();
    }

    protected function familyExists(Company $company, string $code): bool
    {
        return ProductFamily::query()->withoutGlobalScopes()->where('company_id', $company->id)->where('code', $code)->exists();
    }

    protected function category(Company $company, string $name, string $icon): Category
    {
        return Category::query()->withoutGlobalScopes()->firstOrCreate(
            ['company_id' => $company->id, 'slug' => Str::slug($name)],
            [
                'name' => $name,
                'icon' => $icon,
                'sort_order' => (int) Category::query()->withoutGlobalScopes()->where('company_id', $company->id)->max('sort_order') + 1,
                'is_active' => true,
            ],
        );
    }

    /** @return Collection<int, Store> */
    protected function stores(Company $company): Collection
    {
        return Store::query()->withoutGlobalScopes()->where('company_id', $company->id)->where('is_active', true)->orderBy('id')->get();
    }
}
