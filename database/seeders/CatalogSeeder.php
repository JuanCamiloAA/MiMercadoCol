<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\ProductSupplier;
use App\Models\Store;
use App\Models\Supplier;
use App\Services\WarehouseService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class CatalogSeeder extends Seeder
{
    /** @var list<array{name: string, icon: string}> */
    protected const CATEGORIES = [
        ['name' => 'Frutas y verduras', 'icon' => 'Carrot'],
        ['name' => 'Carnes y pescados', 'icon' => 'Fish'],
        ['name' => 'Lacteos y huevos', 'icon' => 'Egg'],
        ['name' => 'Panaderia', 'icon' => 'Bread'],
        ['name' => 'Despensa', 'icon' => 'Basket'],
        ['name' => 'Bebidas', 'icon' => 'BeerBottle'],
        ['name' => 'Aseo del hogar', 'icon' => 'Broom'],
        ['name' => 'Cuidado personal', 'icon' => 'HandSoap'],
    ];

    /** @var list<array{name: string, tax_id: string, contact: string, lead: int, days: list<string>}> */
    protected const SUPPLIERS = [
        ['name' => 'Distribuidora El Trigal', 'tax_id' => '890234567-1', 'contact' => 'Alberto Mesa', 'lead' => 2, 'days' => ['lun', 'jue']],
        ['name' => 'Lacteos del Oriente', 'tax_id' => '811345678-2', 'contact' => 'Claudia Rios', 'lead' => 1, 'days' => ['mar', 'vie']],
        ['name' => 'Central de Abastos SAS', 'tax_id' => '900456789-3', 'contact' => 'Jorge Naranjo', 'lead' => 1, 'days' => ['lun', 'mie', 'vie']],
        ['name' => 'Carnes La Sabana', 'tax_id' => '830567890-4', 'contact' => 'Marta Villa', 'lead' => 2, 'days' => ['mie', 'sab']],
        ['name' => 'Bebidas Nacionales', 'tax_id' => '860678901-5', 'contact' => 'Pedro Lemus', 'lead' => 3, 'days' => ['jue']],
        ['name' => 'Aseo Total Distribuciones', 'tax_id' => '901789012-6', 'contact' => 'Sandra Correa', 'lead' => 4, 'days' => ['mar']],
    ];

    /**
     * Articulos reales de supermercado: nombre, categoria, proveedor, unidad, costo, precio, IVA, minimo.
     *
     * @var list<array{0: string, 1: int, 2: int, 3: string, 4: int, 5: int, 6: float, 7: int, 8: string}>
     */
    protected const PRODUCTS = [
        // Frutas y verduras (0)
        ['Papa pastusa', 0, 2, 'kg', 2200, 3200, 0, 40, 'Del campo'],
        ['Tomate chonto', 0, 2, 'kg', 3100, 4600, 0, 30, 'Del campo'],
        ['Cebolla cabezona blanca', 0, 2, 'kg', 2800, 4200, 0, 25, 'Del campo'],
        ['Zanahoria', 0, 2, 'kg', 2300, 3500, 0, 25, 'Del campo'],
        ['Banano criollo', 0, 2, 'kg', 2600, 3900, 0, 30, 'Del campo'],
        ['Naranja valencia', 0, 2, 'kg', 2400, 3700, 0, 30, 'Del campo'],
        ['Aguacate hass', 0, 2, 'kg', 6800, 9900, 0, 15, 'Del campo'],
        ['Limon tahiti', 0, 2, 'kg', 2900, 4500, 0, 20, 'Del campo'],
        ['Lechuga batavia', 0, 2, 'unit', 1800, 2800, 0, 20, 'Del campo'],
        ['Platano harton verde', 0, 2, 'kg', 2500, 3800, 0, 30, 'Del campo'],

        // Carnes y pescados (1)
        ['Pechuga de pollo', 1, 3, 'kg', 12500, 17900, 0, 20, 'Pollo Fino'],
        ['Muslo de pollo', 1, 3, 'kg', 8900, 12900, 0, 20, 'Pollo Fino'],
        ['Carne molida de res', 1, 3, 'kg', 17800, 24900, 0, 15, 'La Sabana'],
        ['Costilla de cerdo', 1, 3, 'kg', 14200, 19900, 0, 12, 'La Sabana'],
        ['Filete de tilapia', 1, 3, 'kg', 19500, 27900, 0, 10, 'Mar Abierto'],
        ['Chorizo santarrosano x5', 1, 3, 'pack', 7800, 11500, 19, 15, 'La Sabana'],
        ['Jamon de cerdo tajado 200 g', 1, 3, 'pack', 6200, 9200, 19, 18, 'Zenu'],
        ['Salchicha ranchera 500 g', 1, 3, 'pack', 9400, 13500, 19, 15, 'Zenu'],

        // Lacteos y huevos (2)
        ['Leche entera 1 L', 2, 1, 'l', 3100, 4200, 0, 60, 'Colanta'],
        ['Leche deslactosada 1 L', 2, 1, 'l', 3400, 4700, 0, 40, 'Alqueria'],
        ['Huevos AA x30', 2, 1, 'pack', 15200, 20500, 0, 30, 'Santa Reyes'],
        ['Huevos A x12', 2, 1, 'pack', 6200, 8600, 0, 40, 'Santa Reyes'],
        ['Queso campesino 500 g', 2, 1, 'pack', 9800, 13900, 0, 20, 'Colanta'],
        ['Yogur griego 150 g', 2, 1, 'unit', 2400, 3500, 5, 45, 'Alpina'],
        ['Mantequilla 250 g', 2, 1, 'pack', 7600, 10900, 5, 20, 'Colanta'],
        ['Kumis 1 L', 2, 1, 'l', 5100, 7200, 5, 20, 'Alpina'],
        ['Arequipe 250 g', 2, 1, 'unit', 5200, 7500, 19, 15, 'Alpina'],

        // Panaderia (3)
        ['Pan tajado integral 500 g', 3, 0, 'pack', 4900, 7200, 19, 25, 'Bimbo'],
        ['Pan blanco tajado 450 g', 3, 0, 'pack', 4200, 6300, 19, 25, 'Bimbo'],
        ['Arepa de maiz blanco x5', 3, 0, 'pack', 3200, 4800, 0, 30, 'Doria'],
        ['Tortillas de trigo x8', 3, 0, 'pack', 5400, 7900, 19, 15, 'Bimbo'],
        ['Galletas saltinas x3 tacos', 3, 0, 'pack', 4600, 6800, 19, 25, 'Noel'],
        ['Ponque casero 350 g', 3, 0, 'unit', 6100, 8900, 19, 12, 'Ramo'],

        // Despensa (4)
        ['Arroz blanco 1 kg', 4, 0, 'kg', 3600, 5100, 0, 80, 'Diana'],
        ['Arroz blanco 5 kg', 4, 0, 'pack', 16800, 23500, 0, 30, 'Diana'],
        ['Frijol cargamanto 500 g', 4, 0, 'pack', 6200, 8900, 0, 30, 'La Huerta'],
        ['Lenteja 500 g', 4, 0, 'pack', 3400, 4900, 0, 30, 'La Huerta'],
        ['Aceite de girasol 1 L', 4, 0, 'l', 8900, 12500, 19, 40, 'Premier'],
        ['Panela redonda 500 g', 4, 0, 'unit', 2600, 3900, 0, 40, 'Trapiche'],
        ['Azucar blanca 1 kg', 4, 0, 'kg', 4200, 5900, 0, 50, 'Incauca'],
        ['Sal refinada 1 kg', 4, 0, 'kg', 1800, 2700, 0, 40, 'Refisal'],
        ['Cafe molido 500 g', 4, 0, 'pack', 16500, 22900, 5, 25, 'Sello Rojo'],
        ['Chocolate de mesa 500 g', 4, 0, 'pack', 9200, 13200, 5, 20, 'Corona'],
        ['Pasta espagueti 500 g', 4, 0, 'pack', 3100, 4600, 19, 35, 'Doria'],
        ['Atun en agua 160 g', 4, 0, 'unit', 4300, 6200, 19, 40, 'Van Camps'],
        ['Salsa de tomate 400 g', 4, 0, 'unit', 5100, 7400, 19, 25, 'Fruco'],
        ['Mayonesa 380 g', 4, 0, 'unit', 7200, 10400, 19, 20, 'Fruco'],
        ['Harina de maiz precocida 1 kg', 4, 0, 'kg', 4100, 5900, 0, 35, 'Promasa'],
        ['Avena en hojuelas 500 g', 4, 0, 'pack', 4700, 6800, 5, 20, 'Quaker'],

        // Bebidas (5)
        ['Gaseosa cola 1.5 L', 5, 4, 'l', 4600, 6800, 19, 40, 'Postobon'],
        ['Agua sin gas 600 ml', 5, 4, 'unit', 1400, 2300, 19, 60, 'Cristal'],
        ['Jugo de naranja 1 L', 5, 4, 'l', 4900, 7200, 19, 25, 'Hit'],
        ['Cerveza lata 330 ml', 5, 4, 'unit', 3100, 4600, 19, 60, 'Aguila'],
        ['Malta 350 ml', 5, 4, 'unit', 2400, 3600, 19, 30, 'Pony'],
        ['Te frio limon 400 ml', 5, 4, 'unit', 2600, 3900, 19, 30, 'Mr Tea'],

        // Aseo del hogar (6)
        ['Papel higienico x12 rollos', 6, 5, 'pack', 18500, 26500, 19, 25, 'Familia'],
        ['Detergente en polvo 2 kg', 6, 5, 'pack', 16200, 22900, 19, 20, 'Fab'],
        ['Jabon liquido loza 500 ml', 6, 5, 'unit', 5600, 8200, 19, 25, 'Axion'],
        ['Blanqueador 1 L', 6, 5, 'l', 3900, 5800, 19, 30, 'Clorox'],
        ['Limpiador multiusos 1 L', 6, 5, 'l', 6100, 8900, 19, 20, 'Fabuloso'],
        ['Bolsas para basura x20', 6, 5, 'pack', 7300, 10500, 19, 20, 'Vanyplas'],

        // Cuidado personal (7)
        ['Shampoo 400 ml', 7, 5, 'unit', 12400, 17900, 19, 18, 'Savital'],
        ['Jabon de tocador x3', 7, 5, 'pack', 6800, 9800, 19, 25, 'Protex'],
        ['Crema dental 150 g', 7, 5, 'unit', 7200, 10400, 19, 25, 'Colgate'],
        ['Desodorante roll-on 50 ml', 7, 5, 'unit', 9100, 13200, 19, 18, 'Rexona'],
    ];

    /**
     * Subcategorias de demostracion: la tienda las muestra en el menu grande y como filtro
     * («Aseo del hogar › Detergentes»). Cada articulo nombrado pasa a su subcategoria.
     *
     * @var array<int, array<string, array{icon: string, products: list<string>}>>
     */
    protected const SUBCATEGORIES = [
        4 => [
            'Granos y arroz' => ['icon' => 'Grains', 'products' => ['Arroz blanco 1 kg', 'Arroz blanco 5 kg', 'Frijol cargamanto 500 g', 'Lenteja 500 g', 'Avena en hojuelas 500 g']],
            'Aceites y salsas' => ['icon' => 'Drop', 'products' => ['Aceite de girasol 1 L', 'Salsa de tomate 400 g', 'Mayonesa 380 g']],
            'Café y chocolate' => ['icon' => 'Coffee', 'products' => ['Cafe molido 500 g', 'Chocolate de mesa 500 g']],
            'Enlatados y pastas' => ['icon' => 'CookingPot', 'products' => ['Atun en agua 160 g', 'Pasta espagueti 500 g']],
        ],
        6 => [
            'Detergentes' => ['icon' => 'Drop', 'products' => ['Detergente en polvo 2 kg', 'Jabon liquido loza 500 ml', 'Blanqueador 1 L']],
            'Limpieza de superficies' => ['icon' => 'Sparkle', 'products' => ['Limpiador multiusos 1 L']],
            'Papel y bolsas' => ['icon' => 'ToiletPaper', 'products' => ['Papel higienico x12 rollos', 'Bolsas para basura x20']],
        ],
    ];

    /**
     * Fotos del catalogo externo (Open Food Facts, ODbL) para algunos articulos: la tienda las
     * muestra con su atribucion en el pie.
     *
     * @var array<string, string>
     */
    protected const IMAGE_URLS = [
        'Arroz blanco 1 kg' => 'https://images.openfoodfacts.org/images/products/770/251/100/0014/front_en.30.400.jpg',
        'Queso campesino 500 g' => 'https://images.openfoodfacts.org/images/products/770/212/901/5523/front_es.3.400.jpg',
        'Atun en agua 160 g' => 'https://images.openfoodfacts.org/images/products/770/236/700/3917/front_es.99.400.jpg',
        'Avena en hojuelas 500 g' => 'https://images.openfoodfacts.org/images/products/770/219/310/1283/front_fr.14.400.jpg',
        'Kumis 1 L' => 'https://images.openfoodfacts.org/images/products/770/200/104/8076/front_es.15.400.jpg',
        'Cafe molido 500 g' => 'https://images.openfoodfacts.org/images/products/770/203/211/0988/front_es.4.400.jpg',
        'Mayonesa 380 g' => 'https://images.openfoodfacts.org/images/products/770/204/703/8772/front_es.7.400.jpg',
        'Aceite de girasol 1 L' => 'https://images.openfoodfacts.org/images/products/770/101/800/7151/front_es.3.400.jpg',
    ];

    public function run(): void
    {
        $company = Company::query()->firstOrFail();
        $stores = Store::query()->withoutGlobalScopes()->where('company_id', $company->id)->orderBy('id')->get();

        $categories = [];

        foreach (self::CATEGORIES as $index => $data) {
            $categories[] = Category::query()->withoutGlobalScopes()->firstOrCreate(
                ['company_id' => $company->id, 'slug' => Str::slug($data['name'])],
                [
                    'name' => $data['name'],
                    'icon' => $data['icon'],
                    'sort_order' => $index + 1,
                    'is_active' => true,
                ]
            );
        }

        // Las subcategorias cuelgan de su categoria madre.
        $children = [];

        foreach (self::SUBCATEGORIES as $parentIndex => $subcategories) {
            foreach (array_keys($subcategories) as $position => $name) {
                $children[$name] = Category::query()->withoutGlobalScopes()->firstOrCreate(
                    ['company_id' => $company->id, 'slug' => Str::slug($name)],
                    ['name' => $name, 'icon' => $subcategories[$name]['icon'], 'parent_id' => $categories[$parentIndex]->id, 'sort_order' => $position + 1, 'is_active' => true]
                );
            }
        }

        $suppliers = [];

        foreach (self::SUPPLIERS as $data) {
            $suppliers[] = Supplier::query()->withoutGlobalScopes()->firstOrCreate(
                ['company_id' => $company->id, 'tax_id' => $data['tax_id']],
                [
                    'name' => $data['name'],
                    'contact_name' => $data['contact'],
                    'phone' => '604 '.random_int(200, 899).' '.random_int(1000, 9999),
                    'email' => Str::slug($data['name'], '.').'@proveedores.co',
                    'address' => 'Medellin, Antioquia',
                    'lead_time_days' => $data['lead'],
                    'delivery_days' => $data['days'],
                    'is_active' => true,
                ]
            );
        }

        foreach (self::PRODUCTS as $index => $row) {
            [$name, $categoryIndex, $supplierIndex, $unit, $cost, $price, $taxRate, $minStock, $brand] = $row;

            $sku = sprintf('%s-%03d', strtoupper(substr($categories[$categoryIndex]->slug, 0, 3)), $index + 1);
            $category = $categories[$categoryIndex];

            foreach (self::SUBCATEGORIES[$categoryIndex] ?? [] as $childName => $child) {
                if (in_array($name, $child['products'], true)) {
                    $category = $children[$childName];
                }
            }

            // La presentacion sale del nombre («Aceite 1 L», «Atun 160 g»): con ella la tienda
            // calcula el precio por unidad de medida.
            [$contentAmount, $contentUnit] = preg_match('/(\d+(?:[.,]\d+)?)\s*(kg|g|l|ml)\b/i', $name, $match)
                ? [(float) str_replace(',', '.', $match[1]), strtolower($match[2])]
                : [null, null];

            $product = Product::query()->withoutGlobalScopes()->firstOrCreate(
                ['company_id' => $company->id, 'sku' => $sku],
                [
                    'category_id' => $category->id,
                    'supplier_id' => $suppliers[$supplierIndex]->id,
                    'name' => $name,
                    'barcode' => '770'.str_pad((string) (100000 + $index), 10, '0', STR_PAD_LEFT),
                    'brand' => $brand,
                    'unit' => $unit,
                    'sold_by_weight' => $unit === 'kg',
                    'cost' => $cost,
                    'price' => $price,
                    'wholesale_price' => (int) round($price * 0.93),
                    'online_price' => (int) round($price * 1.04),
                    'tax_rate' => $taxRate,
                    'min_stock' => $minStock,
                    'track_lots' => in_array($categoryIndex, [1, 2], true),
                    'allow_negative_stock' => false,
                    'is_active' => true,
                    'is_online_visible' => $index % 7 !== 0,
                    'is_featured' => $index % 11 === 0,
                    'description' => "{$name} de {$brand}, disponible en nuestras sedes.",
                    'content_amount' => $contentAmount,
                    'content_unit' => $contentUnit,
                    'image_url' => self::IMAGE_URLS[$name] ?? null,
                ]
            );

            // El proveedor principal tambien vive en `product_suppliers`, sincronizado con
            // `is_primary`: compras y devoluciones resaltan por ahi, no por `products.supplier_id`.
            ProductSupplier::query()->withoutGlobalScopes()->firstOrCreate(
                ['product_id' => $product->id, 'supplier_id' => $product->supplier_id],
                ['company_id' => $company->id, 'last_cost' => $cost, 'is_primary' => true],
            );

            foreach ($stores as $storeIndex => $store) {
                // La sede principal carga mas inventario; unos pocos articulos quedan bajo el minimo a proposito.
                $factor = $storeIndex === 0 ? 1.0 : 0.6;
                $quantity = $index % 9 === 0
                    ? max(0, $minStock - random_int(1, 5))
                    : (int) round($minStock * random_int(15, 45) / 10 * $factor);

                $warehouse = app(WarehouseService::class)->receivingDefaultFor($store->id);

                ProductStock::query()->withoutGlobalScopes()->firstOrCreate(
                    ['product_id' => $product->id, 'warehouse_id' => $warehouse->id],
                    [
                        'company_id' => $company->id,
                        'store_id' => $store->id,
                        'quantity' => $quantity,
                        'reserved_quantity' => 0,
                        'location' => 'P'.random_int(1, 12).'-'.chr(random_int(65, 70)),
                    ]
                );
            }
        }
    }
}
