<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Register;
use App\Models\RegisterSession;
use App\Models\Sale;
use App\Models\User;
use App\Services\RegisterSessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\TestCase;

/**
 * Quien abre la caja y quien vende no son la misma persona.
 *
 * En el mostrador la caja la abre uno en la manana y durante el dia venden varios. El efectivo
 * responde al turno —y a quien lo abrio— pero la venta cuenta para quien la hizo. Aqui se fija
 * que las dos cosas viajen separadas y que ninguna se lleve por delante a la otra.
 */
class PosSellerTest extends TestCase
{
    use CreatesCompanyEnvironment, RefreshDatabase;

    protected User $marcela;

    protected Employee $diana;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEnvironment();

        // Marcela abre la caja; Diana es la asesora que vende.
        $marcelaEmployee = $this->makeEmployee(['first_name' => 'Marcela', 'last_name' => 'Ospina']);
        $this->marcela = $this->makeUser('admin', ['employee_id' => $marcelaEmployee->id]);

        $this->diana = $this->makeEmployee(['first_name' => 'Diana', 'last_name' => 'Pulgarin']);
    }

    public function test_por_defecto_la_venta_es_de_quien_abrio_la_caja(): void
    {
        $this->openRegister($this->marcela);
        $product = $this->makeProduct(['price' => 5000, 'tax_rate' => 0]);

        $this->as($this->marcela)->post(route('pos.sales.store'), [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'payments' => [['method' => 'cash', 'amount' => 5000]],
        ])->assertSessionHasNoErrors();

        $sale = Sale::query()->withoutGlobalScopes()->firstOrFail();

        $this->assertSame($this->marcela->employee_id, $sale->seller_employee_id);
    }

    public function test_la_venta_se_puede_asignar_a_otro_empleado(): void
    {
        $this->openRegister($this->marcela);
        $product = $this->makeProduct(['price' => 5000, 'tax_rate' => 0]);

        $this->as($this->marcela)->post(route('pos.sales.store'), [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'payments' => [['method' => 'cash', 'amount' => 5000]],
            'seller_employee_id' => $this->diana->id,
        ])->assertSessionHasNoErrors();

        $sale = Sale::query()->withoutGlobalScopes()->firstOrFail();

        // La venta es de Diana; la caja sigue siendo de Marcela.
        $this->assertSame($this->diana->id, $sale->seller_employee_id);
        $this->assertSame($this->marcela->id, $sale->user_id);
        $this->assertSame('Diana Pulgarin', $sale->seller->full_name);
    }

    public function test_un_empleado_de_otra_empresa_no_puede_quedar_como_vendedor(): void
    {
        $this->openRegister($this->marcela);
        $product = $this->makeProduct(['price' => 5000, 'tax_rate' => 0]);

        $otherCompany = \App\Models\Company::create([
            'name' => 'Otra empresa',
            'legal_name' => 'Otra S.A.S.',
            'tax_id' => '901222333-4',
            'currency' => 'COP',
            'is_active' => true,
        ]);

        $otherStore = \App\Models\Store::query()->withoutGlobalScopes()->create([
            'company_id' => $otherCompany->id,
            'name' => 'Ajena',
            'code' => 'AJE',
            'opens_at' => '07:00:00',
            'closes_at' => '21:00:00',
            'suggested_cash_base' => 0,
            'is_active' => true,
        ]);

        $foreign = Employee::query()->withoutGlobalScopes()->create([
            'company_id' => $otherCompany->id,
            'store_id' => $otherStore->id,
            'first_name' => 'Ajena',
            'last_name' => 'Persona',
            'document_type' => 'CC',
            'document_number' => (string) random_int(10000000, 99999999),
            'position' => 'Asesora',
            'contract_type' => 'indefinido',
            'hired_at' => now()->subYear()->toDateString(),
            'status' => Employee::STATUS_ACTIVE,
        ]);

        $this->as($this->marcela)->from(route('pos.index'))->post(route('pos.sales.store'), [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'payments' => [['method' => 'cash', 'amount' => 5000]],
            'seller_employee_id' => $foreign->id,
        ])->assertSessionHasErrors('seller_employee_id');

        $this->assertSame(0, Sale::query()->withoutGlobalScopes()->count());
    }

    public function test_un_empleado_retirado_no_puede_quedar_como_vendedor(): void
    {
        $this->openRegister($this->marcela);
        $product = $this->makeProduct(['price' => 5000, 'tax_rate' => 0]);

        $this->diana->update(['status' => 'terminated']);

        $this->as($this->marcela)->from(route('pos.index'))->post(route('pos.sales.store'), [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'payments' => [['method' => 'cash', 'amount' => 5000]],
            'seller_employee_id' => $this->diana->id,
        ])->assertSessionHasErrors('seller_employee_id');
    }

    public function test_otro_asesor_puede_vender_en_la_caja_que_abrio_marcela(): void
    {
        $session = $this->openRegister($this->marcela);

        // Andres no abrio ninguna caja: entra a la de Marcela.
        $andres = $this->makeUser('cajero', ['employee_id' => $this->diana->id]);
        $andres->stores()->sync([$this->store->id]);

        $found = app(RegisterSessionService::class)->openSessionFor($andres, $this->store->id);

        $this->assertSame($session->id, $found?->id);

        $product = $this->makeProduct(['price' => 5000, 'tax_rate' => 0]);

        $this->as($andres)->post(route('pos.sales.store'), [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'payments' => [['method' => 'cash', 'amount' => 5000]],
        ])->assertSessionHasNoErrors();

        $sale = Sale::query()->withoutGlobalScopes()->firstOrFail();

        // La plata entra al turno de Marcela, que es quien responde por el cajon.
        $this->assertSame($session->id, $sale->register_session_id);
        $this->assertSame($andres->id, $sale->user_id);
        // Y el vendedor por defecto es el dueno del turno, salvo que se diga otra cosa.
        $this->assertSame($this->marcela->employee_id, $sale->seller_employee_id);
    }

    public function test_el_punto_de_venta_dice_quien_abrio_la_caja(): void
    {
        $this->openRegister($this->marcela);

        $andres = $this->makeUser('cajero');
        $andres->stores()->sync([$this->store->id]);

        $this->as($andres)->get(route('pos.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('session.cashier', $this->marcela->full_name)
                // No es su turno: la pantalla lo dice en vez de hacerle creer que si.
                ->where('session.is_own', false)
                ->where('defaultSeller', $this->marcela->employee_id)
                ->has('sellers')
            );
    }

    public function test_la_venta_entra_a_la_caja_que_se_indico(): void
    {
        $first = $this->openRegister($this->marcela);

        $julian = $this->makeUser('supervisor');
        $julian->stores()->sync([$this->store->id]);
        $second = $this->openRegister($julian, 'C2');

        $product = $this->makeProduct(['price' => 5000, 'tax_rate' => 0]);

        // Marcela tiene su propio turno, pero esta cobrando en la caja de Julian.
        $this->as($this->marcela)->post(route('pos.sales.store'), [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'payments' => [['method' => 'cash', 'amount' => 5000]],
            'register_session_id' => $second->id,
        ])->assertSessionHasNoErrors();

        $sale = Sale::query()->withoutGlobalScopes()->firstOrFail();

        // La plata tiene que entrar al cajon en el que se cobro, no al suyo.
        $this->assertSame($second->id, $sale->register_session_id);
        $this->assertNotSame($first->id, $sale->register_session_id);
    }

    public function test_la_devolucion_se_le_resta_a_quien_vendio(): void
    {
        $this->openRegister($this->marcela);
        $product = $this->makeProduct(['price' => 5000, 'tax_rate' => 0], 50);

        $this->as($this->marcela)->post(route('pos.sales.store'), [
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
            'payments' => [['method' => 'cash', 'amount' => 10000]],
            'seller_employee_id' => $this->diana->id,
        ])->assertSessionHasNoErrors();

        $sale = Sale::query()->withoutGlobalScopes()->firstOrFail();

        $this->as($this->marcela)->post(route('pos.sales.refund', $sale), [
            'items' => [['sale_item_id' => $sale->items->first()->id, 'quantity' => 1]],
            'method' => 'cash',
            'reason' => 'El cliente se arrepintio',
        ])->assertSessionHasNoErrors();

        $refund = Sale::query()->withoutGlobalScopes()->where('status', Sale::STATUS_REFUNDED)->firstOrFail();

        // El negativo va contra Diana, no contra quien tramito la devolucion.
        $this->assertSame($this->diana->id, $refund->seller_employee_id);
    }

    protected function openRegister(User $user, string $code = 'C1'): RegisterSession
    {
        $register = $code === 'C1'
            ? $this->register
            : Register::query()->withoutGlobalScopes()->create([
                'company_id' => $this->company->id,
                'store_id' => $this->store->id,
                'name' => 'Caja 2',
                'code' => $code,
                'is_active' => true,
            ]);

        return RegisterSession::query()->withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'store_id' => $this->store->id,
            'register_id' => $register->id,
            'user_id' => $user->id,
            'opened_at' => now(),
            'opening_amount' => 100000,
            'opening_breakdown' => [],
            'status' => RegisterSession::STATUS_OPEN,
        ]);
    }

    protected function as(User $user): static
    {
        return $this->actingAs($user)->withSession($this->withActiveStore());
    }
}
