<?php

namespace Database\Seeders;

use App\Models\BillingCycle;
use App\Models\Category;
use App\Models\Company;
use App\Models\MembershipPlan;
use App\Models\Product;
use App\Models\Register;
use App\Models\StockMovement;
use App\Models\Store;
use App\Models\User;
use App\Services\Platform\CompanyProvisioner;
use App\Services\StockService;
use App\Services\WarehouseService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Segunda empresa de demostracion: «Mercado El Vecino», en Envigado y Bello.
 *
 * Nace por `CompanyProvisioner`, como cualquier empresa, y se completa con una segunda sede,
 * un catalogo corto con precios y existencias. La tienda la publica `StorefrontSeeder`, que
 * corre despues. Sirve para probar la landing con dos empresas y la tienda multiempresa.
 */
class NeighborCompanySeeder extends Seeder
{
    public const TAX_ID = '901555444-2';

    /** Nombre, categoria, costo, precio, IVA. */
    protected const PRODUCTS = [
        ['Arroz Vecino 1 kg', 'Granos', 3500, 4900, 0],
        ['Fríjol cargamanto 500 g', 'Granos', 4600, 6400, 0],
        ['Lenteja 500 g', 'Granos', 2700, 3800, 0],
        ['Panela redonda 500 g', 'Despensa', 2200, 3100, 0],
        ['Aceite de girasol 900 ml', 'Despensa', 8200, 11500, 19],
        ['Café molido 250 g', 'Despensa', 7300, 10200, 5],
        ['Leche entera 1 L', 'Lácteos', 3000, 4200, 0],
        ['Queso campesino 250 g', 'Lácteos', 5400, 7600, 0],
        ['Huevos AA x 12', 'Lácteos', 7800, 10900, 0],
        ['Jabón de loza 500 g', 'Aseo', 3300, 4800, 19],
        ['Papel higiénico x 4', 'Aseo', 5100, 7200, 19],
        ['Detergente en polvo 1 kg', 'Aseo', 8900, 12400, 19],
    ];

    /** El icono de cada categoria en la tienda en linea. */
    protected const ICONS = ['Granos' => 'Grains', 'Despensa' => 'Basket', 'Lácteos' => 'Cheese', 'Aseo' => 'Broom'];

    public function __construct(
        protected CompanyProvisioner $provisioner,
        protected StockService $stock,
        protected WarehouseService $warehouses,
    ) {}

    public function run(): void
    {
        if (Company::query()->where('tax_id', self::TAX_ID)->exists()) {
            return;
        }

        $plan = MembershipPlan::query()->where('slug', 'pro')->first();
        $cycle = BillingCycle::query()->where('code', 'monthly')->first();

        $result = $this->provisioner->provision([
            'company' => [
                'name' => 'Mercado El Vecino',
                'legal_name' => 'Mercado El Vecino S.A.S.',
                'tax_id' => self::TAX_ID,
                'email' => 'hola@elvecino.co',
                'phone' => '6043331122',
                'address' => 'Carrera 43A # 38 Sur-15',
                'city' => 'Envigado',
            ],
            'store' => [
                'name' => 'Envigado Centro',
                'code' => 'ENV',
                'address' => 'Carrera 43A # 38 Sur-15',
                'city' => 'Envigado',
                'phone' => '6043331122',
                'latitude' => 6.1698,
                'longitude' => -75.5863,
            ],
            'admin' => [
                'name' => 'Rubén',
                'last_name' => 'Arango',
                'email' => 'admin@elvecino.co',
                'password_hash' => Hash::make(UserSeeder::PASSWORD),
            ],
            'plan_id' => $plan?->id,
            'cycle_id' => $cycle?->id,
            'paid' => true,
        ]);

        $company = $result->company;

        $bello = Store::query()->withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'name' => 'Bello Niquía',
            'code' => 'BEL',
            'address' => 'Calle 56 # 47-20, Niquía',
            'city' => 'Bello',
            'phone' => '6044556677',
            'latitude' => 6.3373,
            'longitude' => -75.5448,
            'opens_at' => '07:00:00',
            'closes_at' => '21:00:00',
            'suggested_cash_base' => 150000,
            'is_active' => true,
        ]);

        Register::query()->withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'store_id' => $bello->id,
            'name' => 'Caja 1',
            'code' => 'C1',
            'is_active' => true,
        ]);

        /** @var User $admin */
        $admin = $result->admin;
        $admin->stores()->sync([$result->store->id, $bello->id]);

        $this->catalog($company, [$result->store, $bello], $admin);
    }

    /**
     * @param  list<Store>  $stores
     */
    protected function catalog(Company $company, array $stores, User $admin): void
    {
        $categories = [];

        foreach (array_unique(array_column(self::PRODUCTS, 1)) as $order => $name) {
            $categories[$name] = Category::query()->withoutGlobalScopes()->create([
                'company_id' => $company->id,
                'name' => $name,
                'slug' => Str::slug($name),
                'icon' => self::ICONS[$name] ?? null,
                'sort_order' => $order,
                'is_active' => true,
            ]);
        }

        foreach (self::PRODUCTS as $index => [$name, $category, $cost, $price, $tax]) {
            $product = Product::query()->withoutGlobalScopes()->create([
                'company_id' => $company->id,
                'category_id' => $categories[$category]->id,
                'name' => $name,
                'sku' => sprintf('VEC-%03d', $index + 1),
                'barcode' => '771'.str_pad((string) (200000 + $index), 10, '0', STR_PAD_LEFT),
                'unit' => 'unit',
                'cost' => $cost,
                'price' => $price,
                'online_price' => $price,
                'tax_rate' => $tax,
                'min_stock' => 10,
                'is_active' => true,
                'is_online_visible' => true,
                'is_featured' => $index % 4 === 0,
                // La presentacion sale del nombre: con ella la tienda calcula el precio por unidad.
                'content_amount' => preg_match('/(\d+(?:[.,]\d+)?)\s*(kg|g|l|ml)\b/i', $name, $match) ? (float) str_replace(',', '.', $match[1]) : null,
                'content_unit' => isset($match[2]) ? strtolower($match[2]) : null,
            ]);

            // Existencias por el unico camino: `StockService`, con su movimiento.
            foreach ($stores as $position => $store) {
                $this->stock->move(
                    $product,
                    $this->warehouses->posSourceFor($store->id),
                    StockMovement::TYPE_ADJUSTMENT,
                    $position === 0 ? 60 : 35,
                    $admin->id,
                    null,
                    'Inventario inicial de demostración',
                );
            }
        }
    }
}
