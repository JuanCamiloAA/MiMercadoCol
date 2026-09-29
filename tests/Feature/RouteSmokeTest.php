<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerReturn;
use App\Models\Employee;
use App\Models\MembershipPlan;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductFamily;
use App\Models\PurchaseOrder;
use App\Models\Register;
use App\Models\RegisterSession;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\Service;
use App\Models\StockTransfer;
use App\Models\StockTransferRequest;
use App\Models\Store;
use App\Models\StorefrontTemplate;
use App\Models\Supplier;
use App\Models\SupplierReturn;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\CustomerReturnService;
use App\Services\OrderService;
use App\Services\RegisterSessionService;
use App\Services\SaleService;
use App\Services\Storefront\StorefrontTemplateService;
use App\Services\SupplierReturnService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\BuildsVariants;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\Concerns\SellsServices;
use Tests\TestCase;

/**
 * Barrido de todas las pantallas del panel.
 *
 * Abre cada ruta GET con un administrador y exige que ninguna reviente. Es la red que atrapa
 * la consulta rota o la propiedad que se dejo de enviar cuando se toca un controlador.
 */
class RouteSmokeTest extends TestCase
{
    use BuildsVariants, CreatesCompanyEnvironment, RefreshDatabase, SellsServices;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEnvironment();
        $this->admin = $this->makeUser('admin');
        $this->seedSomethingToLookAt();
    }

    public function test_ninguna_pantalla_del_panel_revienta(): void
    {
        $failures = [];
        $visited = 0;

        foreach ($this->panelRoutes() as $uri) {
            $response = $this->actingAs($this->admin)
                ->withSession($this->withActiveStore())
                ->get("/{$uri}");

            $visited++;

            if ($response->getStatusCode() >= 500) {
                $failures[] = "/{$uri} -> {$response->getStatusCode()}";
            }
        }

        $this->assertGreaterThan(30, $visited, 'El barrido deberia cubrir todo el panel.');
        $this->assertSame([], $failures, 'Pantallas con error: '.implode(', ', $failures));
    }

    public function test_el_super_usuario_con_empresa_elegida_abre_todo(): void
    {
        $super = $this->makeSuperAdmin();
        $failures = [];

        foreach ($this->panelRoutes() as $uri) {
            $status = $this->actingAs($super)
                ->withSession([TenantContext::SESSION_KEY => $this->company->id])
                ->get("/{$uri}")
                ->getStatusCode();

            // El super usuario tiene todos los permisos: nada deberia negarse ni reventar.
            if ($status >= 500 || ($status === 403 && ! $this->deniedForEveryone($uri))) {
                $failures[] = "/{$uri} -> {$status}";
            }
        }

        $this->assertSame([], $failures, 'Pantallas con error: '.implode(', ', $failures));
    }

    public function test_el_super_usuario_en_consolidado_no_revienta_nada(): void
    {
        $super = $this->makeSuperAdmin();
        $failures = [];

        foreach ($this->panelRoutes() as $uri) {
            $status = $this->actingAs($super)->get("/{$uri}")->getStatusCode();

            // Sin empresa elegida una pantalla de empresa pide elegir; nunca un error.
            if ($status >= 500 || ($status === 403 && ! $this->deniedForEveryone($uri))) {
                $failures[] = "/{$uri} -> {$status}";
            }
        }

        $this->assertSame([], $failures, 'Pantallas con error: '.implode(', ', $failures));
    }

    /**
     * Rutas que niegan a cualquiera por GET sin mas: la vista previa pide enlace firmado y la
     * autorizacion de canales no es una pantalla.
     */
    protected function deniedForEveryone(string $uri): bool
    {
        return str_starts_with($uri, 'tienda/preview') || $uri === 'broadcasting/auth';
    }

    public function test_la_tienda_en_linea_responde_sin_sesion(): void
    {
        // Sin sede elegida, la tienda devuelve al buscador de la landing.
        $this->get('/tienda')->assertRedirect('/#tiendas');
        $this->get('/')->assertOk();

        $this->shopAt();

        foreach (['tienda', 'tienda/carrito', 'tienda/ingresar', 'tienda/registro', 'contratar', 'terminos', 'privacidad'] as $uri) {
            $this->get("/{$uri}")->assertOk();
        }
    }

    /**
     * Rutas GET del panel con sus parametros ya resueltos.
     *
     * @return list<string>
     */
    protected function panelRoutes(): array
    {
        $models = [
            'product' => Product::class,
            'employee' => Employee::class,
            'user' => User::class,
            'role' => Role::class,
            'customer' => Customer::class,
            'order' => Order::class,
            'sale' => Sale::class,
            'session' => RegisterSession::class,
            'store' => Store::class,
            'register' => Register::class,
            'supplier' => Supplier::class,
            'category' => Category::class,
            'transfer' => StockTransfer::class,
            'transferRequest' => StockTransferRequest::class,
            'warehouse' => Warehouse::class,
            'company' => Company::class,
            'plan' => MembershipPlan::class,
            'supplierReturn' => SupplierReturn::class,
            'service' => Service::class,
            'customerReturn' => CustomerReturn::class,
            'template' => StorefrontTemplate::class,
            'family' => ProductFamily::class,
            'attribute' => ProductAttribute::class,
        ];

        // Parametros que van por slug y no por id.
        $slugs = ['familySlug' => ProductFamily::class];

        $uris = [];

        foreach (Route::getRoutes() as $route) {
            if (! in_array('GET', $route->methods(), true)) {
                continue;
            }

            $uri = $route->uri();

            if (str_starts_with($uri, '_') || in_array($uri, ['up', '/'], true)) {
                continue;
            }

            // Rutas que necesitan estado que este barrido no monta.
            if (str_contains($uri, '{token}') || str_contains($uri, '{item}') || str_contains($uri, '{held}') || str_contains($uri, '{image}')) {
                continue;
            }

            if (str_contains($uri, '{')) {
                preg_match_all('/\{(\w+)\??\}/', $uri, $matches);
                $resolved = true;

                foreach ($matches[1] as $param) {
                    $id = match (true) {
                        isset($models[$param]) => $models[$param]::query()->withoutGlobalScopes()->value('id'),
                        isset($slugs[$param]) => $slugs[$param]::query()->withoutGlobalScopes()->value('slug'),
                        default => null,
                    };

                    if (! $id) {
                        $resolved = false;
                        break;
                    }

                    $uri = str_replace(['{'.$param.'}', '{'.$param.'?}'], (string) $id, $uri);
                }

                if (! $resolved) {
                    continue;
                }
            }

            $uris[] = $uri;
        }

        return array_values(array_unique($uris));
    }

    /**
     * Datos minimos para que cada pantalla tenga algo real que pintar.
     */
    protected function seedSomethingToLookAt(): void
    {
        // Un articulo con subarticulos: su ficha, su edicion y su matriz de compra.
        $this->seedAttributes();
        $this->makeFamily('Camiseta básica', 'Talla', 'Color', [['S', 'Azul', 24900], ['M', 'Negro', 24900]], [], $this->admin);

        $supplier = Supplier::query()->withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'name' => 'Distribuidora El Trigal',
            'lead_time_days' => 2,
            'is_active' => true,
        ]);

        $product = $this->makeProduct([
            'supplier_id' => $supplier->id,
            'price' => 10000,
            'tax_rate' => 0,
            'is_online_visible' => true,
            'track_lots' => true,
        ], 80);

        $customer = $this->makeCustomer([
            'credit_enabled' => true,
            'credit_limit' => 400000,
            'credit_term_days' => 15,
            'email' => 'cliente@correo.co',
        ]);

        // Un servicio con un articulo adentro: la venta y el pedido de abajo lo llevan, para
        // que su ficha, el tiquete y el detalle del pedido pinten lineas hijas.
        $service = $this->makeService('Arreglo de prueba', 5000, [[$product, 1, false]], Service::PRICING_ITEMIZED, [
            'is_online_visible' => true,
        ]);

        $this->makeEmployee();

        // Una plantilla de tienda: su editor y su vista previa entran al barrido.
        app(StorefrontTemplateService::class)->create('Supermercado', 'Para el barrido');
        $this->makePlan(['name' => 'Pro'], ['online_store', 'promotions']);

        $session = app(RegisterSessionService::class)->open($this->register, $this->admin, 200000);

        $sale = app(SaleService::class)->create([
            'items' => [['product_id' => $product->id, 'quantity' => 4], ['service_id' => $service->id, 'quantity' => 1]],
            'payments' => [['method' => SalePayment::METHOD_CASH, 'amount' => 55000, 'received_amount' => 60000]],
            'customer_id' => $customer->id,
        ], $this->admin, $this->store, $session);

        // Una devolucion de caja resuelta como saldo a favor: su ficha, su documento y el saldo del cliente.
        app(CustomerReturnService::class)->registerAtPos($sale, [
            'items' => [['sale_item_id' => $sale->items->firstWhere('product_id', $product->id)->id, 'quantity' => 1, 'reason' => 'damaged']],
            'resolution' => 'store_credit',
            'reason' => 'Llegó dañado',
        ], $this->admin, $this->store, $session);

        app(OrderService::class)->create([
            'customer_id' => $customer->id,
            'items' => [['product_id' => $product->id, 'quantity' => 2], ['service_id' => $service->id, 'quantity' => 1]],
            'delivery_address' => 'Calle 30 # 65-12',
            'delivery_fee' => 5000,
            'payment_method' => Order::PAYMENT_CASH_ON_DELIVERY,
        ], $this->store, $this->admin);

        PurchaseOrder::create([
            'company_id' => $this->company->id,
            'store_id' => $this->store->id,
            'supplier_id' => $supplier->id,
            'code' => 'EM-0001',
            'status' => PurchaseOrder::STATUS_SENT,
            'subtotal' => 100000,
            'tax_total' => 0,
            'total' => 100000,
            'user_id' => $this->admin->id,
        ]);

        // Una devolucion suelta en borrador, para abrir su ficha, su edicion y su documento.
        app(SupplierReturnService::class)->create([
            'company_id' => $this->company->id,
            'store_id' => $this->store->id,
            'supplier_id' => $supplier->id,
            'warehouse_id' => $this->warehouse()->id,
            'items' => [['product_id' => $product->id, 'quantity' => 2, 'reason' => 'damaged']],
        ], $this->admin);
    }
}
