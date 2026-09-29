<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Company;
use App\Models\Product;
use App\Models\Service;
use App\Services\ServiceManager;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Servicios de demostracion. Los componentes se buscan por SKU de `CatalogSeeder` (y de
 * `NeighborCompanySeeder` para la segunda empresa) y se guardan por `ServiceManager`, el mismo
 * camino de la pantalla.
 */
class ServiceSeeder extends Seeder
{
    /**
     * Codigo, datos del maestro y componentes: [SKU, cantidad, vuelve al inventario]. Con
     * `familia:` delante, el componente es el articulo completo y el SKU es su presentacion por
     * defecto: en la caja y en la tienda se cambia por otra del mismo articulo.
     */
    protected const CANASTA = [
        'SRV-ANCHETA' => [
            'name' => 'Armado de ancheta',
            'description' => 'Canasta de regalo armada y envuelta con café, chocolate, galletas, ponqué y arequipe.',
            'category' => 'despensa',
            'pricing_mode' => Service::PRICING_BUNDLED,
            'price' => 85000,
            'cost' => 6000,
            'tax_rate' => 19,
            'is_online_visible' => true,
            'is_featured' => true,
            'components' => [
                ['DES-042', 1, true],
                ['DES-043', 1, true],
                ['PAN-032', 1, true],
                ['PAN-033', 1, true],
                ['LAC-027', 1, true],
            ],
        ],
        'SRV-VACIO' => [
            'name' => 'Porcionado y empaque al vacío',
            'description' => 'Porcionamos la carne a su gusto y la empacamos al vacío.',
            'category' => 'carnes-y-pescados',
            'pricing_mode' => Service::PRICING_BUNDLED,
            'price' => 3000,
            'cost' => 800,
            'tax_rate' => 19,
            'components' => [],
        ],
        'SRV-RECARGA' => [
            'name' => 'Recarga de celular',
            'description' => 'Recarga a cualquier operador. El valor se digita en la caja.',
            'category' => null,
            'pricing_mode' => Service::PRICING_BUNDLED,
            'open_price' => true,
            'price' => 0,
            'cost' => 0,
            'tax_rate' => 0,
            'components' => [],
        ],
        'SRV-DESAYUNO' => [
            'name' => 'Desayuno armado',
            'description' => 'Leche en la presentación que prefieras, pan tajado, huevos y arepas, empacados para llevar.',
            'category' => 'panaderia',
            'pricing_mode' => Service::PRICING_ITEMIZED,
            'price' => 3000,
            'cost' => 600,
            'tax_rate' => 19,
            'is_online_visible' => true,
            'components' => [
                ['familia:LAC-019', 1, true],
                ['PAN-029', 1, true],
                ['LAC-022', 1, true],
                ['PAN-030', 1, true],
            ],
        ],
        'SRV-ARREGLO' => [
            'name' => 'Arreglo de frutas',
            'description' => 'Frutas de temporada lavadas, cortadas y armadas en bandeja.',
            'category' => 'frutas-y-verduras',
            'pricing_mode' => Service::PRICING_ITEMIZED,
            'price' => 5000,
            'cost' => 1500,
            'tax_rate' => 19,
            'is_online_visible' => true,
            'components' => [
                ['FRU-005', 1, false],
                ['FRU-006', 1, false],
                ['FRU-007', 0.5, false],
            ],
        ],
    ];

    /** La segunda empresa tiene el suyo, para comprobar el aislamiento. */
    protected const VECINO = [
        'SRV-CAFETERA' => [
            'name' => 'Canasta cafetera',
            'description' => 'Café molido y panela empacados para regalo.',
            'category' => 'despensa',
            'pricing_mode' => Service::PRICING_BUNDLED,
            'price' => 16000,
            'cost' => 1000,
            'tax_rate' => 5,
            'is_online_visible' => true,
            'components' => [
                ['VEC-006', 1, true],
                ['VEC-004', 1, true],
            ],
        ],
    ];

    public function __construct(protected ServiceManager $services) {}

    public function run(): void
    {
        $this->seedFor(Company::query()->orderBy('id')->firstOrFail(), self::CANASTA);

        $neighbor = Company::query()->where('tax_id', NeighborCompanySeeder::TAX_ID)->first();

        if ($neighbor) {
            $this->seedFor($neighbor, self::VECINO);
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  $definitions
     */
    protected function seedFor(Company $company, array $definitions): void
    {
        foreach ($definitions as $code => $definition) {
            $exists = Service::query()->withoutGlobalScopes()->where('company_id', $company->id)->where('code', $code)->exists();

            if ($exists) {
                continue;
            }

            $components = [];

            foreach ($definition['components'] as [$sku, $quantity, $returnable]) {
                $family = str_starts_with($sku, 'familia:');
                $sku = $family ? substr($sku, strlen('familia:')) : $sku;
                $productId = Product::query()->withoutGlobalScopes()->where('company_id', $company->id)->where('sku', $sku)->value('id');

                if (! $productId) {
                    throw new RuntimeException("El servicio {$code} usa el SKU {$sku}, que no existe en {$company->name}.");
                }

                $components[] = ['product_id' => $productId, 'family' => $family, 'quantity' => $quantity, 'returnable_by_default' => $returnable];
            }

            $categoryId = $definition['category']
                ? Category::query()->withoutGlobalScopes()->where('company_id', $company->id)->where('slug', $definition['category'])->value('id')
                : null;

            $this->services->save(null, [
                'code' => $code,
                'name' => $definition['name'],
                'description' => $definition['description'],
                'category_id' => $categoryId,
                'pricing_mode' => $definition['pricing_mode'],
                'price' => $definition['price'],
                'online_price' => null,
                'cost' => $definition['cost'],
                'tax_rate' => $definition['tax_rate'],
                'open_price' => $definition['open_price'] ?? false,
                'is_active' => true,
                'is_online_visible' => $definition['is_online_visible'] ?? false,
                'is_featured' => $definition['is_featured'] ?? false,
                'components' => $components,
            ], $company->id);
        }
    }
}
