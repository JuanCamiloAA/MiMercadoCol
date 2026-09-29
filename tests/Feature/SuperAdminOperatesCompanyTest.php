<?php

namespace Tests\Feature;

use App\Models\HeldSale;
use App\Models\RegisterSession;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\User;
use App\Support\StoreContext;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\TestCase;

/**
 * El super usuario opera una empresa como su administrador: sin ficha de empleado, sin sedes
 * asignadas y sin PIN. Abre caja, vende, suspende, devuelve y cierra.
 */
class SuperAdminOperatesCompanyTest extends TestCase
{
    use CreatesCompanyEnvironment, RefreshDatabase;

    protected User $super;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEnvironment();
        $this->super = $this->makeSuperAdmin();
        $this->makeOtherCompany();
    }

    /** La sesion del super usuario con la empresa de pruebas elegida arriba. */
    protected function operating(): static
    {
        return $this->actingAs($this->super)->withSession([
            TenantContext::SESSION_KEY => $this->company->id,
            StoreContext::SESSION_KEY => $this->store->id,
        ]);
    }

    protected function openRegister(): RegisterSession
    {
        $this->operating()
            ->post(route('registers.opening.store'), [
                'register_id' => $this->register->id,
                'user_id' => $this->super->id,
                'breakdown' => ['50000' => 4],
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('pos.index'));

        return RegisterSession::query()->withoutGlobalScopes()->where('user_id', $this->super->id)->firstOrFail();
    }

    public function test_la_apertura_lo_ofrece_como_cajero_sin_pin(): void
    {
        $this->operating()
            ->get(route('registers.opening.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Pos/Opening')
                ->where('cashiers.0.id', $this->super->id)
                ->where('cashiers.0.uses_session', true));
    }

    public function test_abre_caja_a_su_nombre_sin_pin(): void
    {
        $session = $this->openRegister();

        $this->assertSame($this->company->id, $session->company_id);
        $this->assertSame($this->store->id, $session->store_id);
        $this->assertSame(200000, $session->opening_amount);
    }

    public function test_a_nombre_de_otro_cajero_si_pide_el_pin(): void
    {
        $cashier = $this->makeUser('cajero');

        $this->operating()
            ->post(route('registers.opening.store'), [
                'register_id' => $this->register->id,
                'user_id' => $cashier->id,
                'breakdown' => ['50000' => 4],
            ])
            ->assertSessionHasErrors('pin');

        $this->assertSame(0, RegisterSession::query()->withoutGlobalScopes()->count());
    }

    public function test_un_administrador_no_puede_abrir_a_nombre_del_super_usuario(): void
    {
        $admin = $this->makeUser('admin');

        $this->actingAs($admin)
            ->withSession($this->withActiveStore())
            ->post(route('registers.opening.store'), [
                'register_id' => $this->register->id,
                'user_id' => $this->super->id,
                'pin' => '1234',
                'breakdown' => ['50000' => 4],
            ])
            ->assertSessionHasErrors('user_id');

        $this->assertSame(0, RegisterSession::query()->withoutGlobalScopes()->count());
    }

    public function test_vende_con_efectivo_repartido_y_a_credito(): void
    {
        $session = $this->openRegister();
        $product = $this->makeProduct(['price' => 10000]);
        $customer = $this->makeCustomer(['credit_enabled' => true, 'credit_limit' => 500000, 'credit_term_days' => 30]);

        $this->operating()->get(route('pos.index'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('Pos/Sale'));

        $this->operating()->post(route('pos.sales.store'), [
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
            'payments' => [['method' => SalePayment::METHOD_CASH, 'amount' => 20000, 'received_amount' => 50000]],
        ])->assertSessionHasNoErrors();

        $this->operating()->post(route('pos.sales.store'), [
            'items' => [['product_id' => $product->id, 'quantity' => 3]],
            'payments' => [
                ['method' => SalePayment::METHOD_CASH, 'amount' => 10000],
                ['method' => SalePayment::METHOD_CARD, 'amount' => 20000],
            ],
        ])->assertSessionHasNoErrors();

        $this->operating()->post(route('pos.sales.store'), [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'payments' => [['method' => SalePayment::METHOD_CREDIT, 'amount' => 10000]],
            'customer_id' => $customer->id,
        ])->assertSessionHasNoErrors();

        $sales = Sale::query()->withoutGlobalScopes()->orderBy('id')->get();

        $this->assertCount(3, $sales);
        $this->assertTrue($sales->every(fn (Sale $sale) => $sale->company_id === $this->company->id
            && $sale->store_id === $this->store->id
            && $sale->user_id === $this->super->id
            && $sale->register_session_id === $session->id));
        $this->assertNull($sales[0]->seller_employee_id);
        $this->assertSame(Sale::STATUS_CREDIT, $sales[2]->status);
        $this->assertSame(94.0, (float) $this->stockOf($product));
    }

    public function test_suspende_y_retoma_un_tiquete(): void
    {
        $this->openRegister();
        $product = $this->makeProduct();

        $this->operating()->post(route('pos.hold.store'), [
            'label' => 'Señora del domicilio',
            'total' => 5100,
            'payload' => ['items' => [['product_id' => $product->id, 'quantity' => 1]]],
        ])->assertSessionHasNoErrors();

        $held = HeldSale::query()->withoutGlobalScopes()->firstOrFail();
        $this->assertSame($this->company->id, $held->company_id);

        $this->operating()->getJson(route('pos.hold.index'))->assertOk();
        $this->operating()->delete(route('pos.hold.destroy', $held))->assertSessionHasNoErrors();
    }

    public function test_devuelve_y_cierra_el_turno(): void
    {
        $session = $this->openRegister();
        $product = $this->makeProduct(['price' => 10000]);

        $this->operating()->post(route('pos.sales.store'), [
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
            'payments' => [['method' => SalePayment::METHOD_CASH, 'amount' => 20000]],
        ])->assertSessionHasNoErrors();

        $sale = Sale::query()->withoutGlobalScopes()->firstOrFail();

        $this->operating()->post(route('pos.sales.refund', $sale), [
            'items' => [['sale_item_id' => $sale->items->first()->id, 'quantity' => 1]],
            'method' => 'cash',
            'reason' => 'Empaque roto',
        ])->assertSessionHasNoErrors();

        $this->assertSame(99.0, (float) $this->stockOf($product));

        $this->operating()->get(route('registers.closing.create'))->assertOk();

        // Base 200.000 + venta 20.000 - devolucion 10.000.
        $this->operating()->post(route('registers.closing.store'), [
            'register_session_id' => $session->id,
            'breakdown' => ['50000' => 4, '10000' => 1],
            'closing_note' => 'Cierre del soporte',
        ])->assertSessionHasNoErrors();

        $session->refresh();
        $this->assertSame(RegisterSession::STATUS_CLOSED, $session->status);
        $this->assertSame(0, $session->difference);

        $this->operating()->get(route('registers.closing.report', $session))->assertOk();
    }

    public function test_no_toca_la_caja_de_otra_empresa(): void
    {
        $session = $this->openRegister();

        // Cambia de empresa: el turno abierto de La Canasta no se ve ni se cierra desde la otra.
        $other = \App\Models\Company::query()->where('id', '!=', $this->company->id)->firstOrFail();

        $this->actingAs($this->super)
            ->withSession([TenantContext::SESSION_KEY => $other->id])
            ->post(route('registers.closing.store'), [
                'register_session_id' => $session->id,
                'breakdown' => ['50000' => 4],
            ])
            ->assertNotFound();

        $this->assertSame(RegisterSession::STATUS_OPEN, $session->fresh()->status);
    }

    public function test_lo_que_crea_queda_en_la_empresa_elegida_y_el_tiquete_sale_con_sus_datos(): void
    {
        $this->openRegister();

        $this->operating()->post(route('categories.store'), ['name' => 'Aseo del soporte', 'is_active' => true])->assertSessionHasNoErrors();
        $this->assertSame($this->company->id, \App\Models\Category::query()->withoutGlobalScopes()->where('name', 'Aseo del soporte')->value('company_id'));

        $this->operating()->put(route('settings.credit.update'), ['credit' => ['block_on_overdue_days' => 12]])->assertSessionHasNoErrors();
        $this->assertSame(12, (int) app(\App\Services\SettingsService::class)->get($this->company->id, 'credit.block_on_overdue_days'));

        $product = $this->makeProduct(['price' => 8000]);
        $this->operating()->post(route('pos.sales.store'), [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'payments' => [['method' => SalePayment::METHOD_CARD, 'amount' => 8000]],
        ])->assertSessionHasNoErrors();

        $sale = Sale::query()->withoutGlobalScopes()->sole();

        $this->operating()
            ->get(route('pos.sales.print', $sale))
            ->assertOk()
            ->assertSee('900111222-3')
            ->assertDontSee('900333444-5')
            ->assertDontSee('Mercado El Vecino');
    }

    public function test_las_pantallas_del_panel_abren_con_la_empresa_elegida(): void
    {
        foreach (['dashboard', 'products.index', 'inventory.index', 'sales.index', 'reports.index', 'settings.index', 'users.index', 'stores.index'] as $name) {
            if (! \Illuminate\Support\Facades\Route::has($name)) {
                continue;
            }

            $this->operating()->get(route($name))->assertOk();
        }
    }

    protected function stockOf($product): float
    {
        return (float) \App\Models\ProductStock::query()->withoutGlobalScopes()
            ->where('product_id', $product->id)
            ->sum('quantity');
    }
}
