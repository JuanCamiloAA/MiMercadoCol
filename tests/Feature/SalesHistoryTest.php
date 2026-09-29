<?php

namespace Tests\Feature;

use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\User;
use App\Services\RegisterSessionService;
use App\Services\SaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\TestCase;

/**
 * Historial de ventas del panel.
 *
 * Lo que se cobra en la caja tiene que poder consultarse desde el back office, sin abrir
 * un turno y sin confundirlo con Pedidos, que son los domicilios.
 */
class SalesHistoryTest extends TestCase
{
    use CreatesCompanyEnvironment, RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEnvironment();
        $this->admin = $this->makeUser('admin');
    }

    public function test_la_venta_del_punto_de_venta_aparece_en_el_historial(): void
    {
        $sale = $this->sell();

        $this->actingAs($this->admin)
            ->withSession($this->withActiveStore())
            ->get(route('sales.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Sales/Index')
                ->where('sales.data.0.number', $sale->number)
                ->where('sales.data.0.channel_label', 'Mostrador')
                ->where('metrics.count', 1)
                ->where('metrics.total', $sale->total));
    }

    public function test_el_rango_de_fechas_deja_por_fuera_lo_viejo(): void
    {
        $sale = $this->sell();
        $sale->forceFill(['sold_at' => now()->subMonths(2)])->save();

        $this->actingAs($this->admin)
            ->withSession($this->withActiveStore())
            ->get(route('sales.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('metrics.count', 0));

        $this->actingAs($this->admin)
            ->withSession($this->withActiveStore())
            ->get(route('sales.index', ['from' => now()->subMonths(3)->toDateString(), 'to' => now()->toDateString()]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('metrics.count', 1));
    }

    public function test_el_detalle_trae_articulos_cobro_y_quien_vendio(): void
    {
        $sale = $this->sell();

        $this->actingAs($this->admin)
            ->withSession($this->withActiveStore())
            ->get(route('sales.show', $sale))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Sales/Show')
                ->where('sale.number', $sale->number)
                ->has('sale.items', 1)
                ->has('sale.payments', 1)
                ->where('sale.cashier', $this->admin->full_name)
                ->where('sale.register', $this->register->name)
                ->where('canPrint', true));
    }

    public function test_la_devolucion_queda_colgada_de_la_venta_original(): void
    {
        $sale = $this->sell();
        $session = $sale->session;

        app(SaleService::class)->refund(
            $sale,
            [['sale_item_id' => $sale->items->first()->id, 'quantity' => 1]],
            $this->admin,
            $this->store,
            $session,
            SalePayment::METHOD_CASH,
            'Venia partido',
        );

        $this->actingAs($this->admin)
            ->withSession($this->withActiveStore())
            ->get(route('sales.show', $sale))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('refunds', 1)
                ->where('refunds.0.reason', 'Venia partido'));
    }

    public function test_el_tiquete_se_puede_reimprimir_desde_el_panel(): void
    {
        $sale = $this->sell();

        $response = $this->actingAs($this->admin)
            ->withSession($this->withActiveStore())
            ->get(route('sales.print', $sale));

        $response->assertOk();
        $this->assertStringContainsString($sale->number, $response->getContent());
    }

    public function test_un_domiciliario_no_entra_al_historial(): void
    {
        $courier = $this->makeUser('domiciliario');

        $this->actingAs($courier)
            ->withSession($this->withActiveStore())
            ->get(route('sales.index'))
            ->assertStatus(403);
    }

    protected function sell(): Sale
    {
        $product = $this->makeProduct(['price' => 12000, 'tax_rate' => 0], 50);
        $session = app(RegisterSessionService::class)->open($this->register, $this->admin, 100000);

        return app(SaleService::class)->create([
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
            'payments' => [['method' => SalePayment::METHOD_CASH, 'amount' => 24000, 'received_amount' => 30000]],
        ], $this->admin, $this->store, $session)->load('items', 'session');
    }
}
