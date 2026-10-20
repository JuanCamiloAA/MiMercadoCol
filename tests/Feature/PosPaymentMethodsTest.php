<?php

namespace Tests\Feature;

use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\RegisterSession;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\User;
use App\Services\RegisterSessionService;
use App\Services\ReportService;
use App\Services\SettingsService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\Concerns\CreatesPaymentGateways;
use Tests\TestCase;

/**
 * La caja cobra con los medios que configuro la empresa: dos transferencias distintas (Nequi y
 * Daviplata) caben en la misma venta y cada una cuadra por separado en el cierre y los informes.
 */
class PosPaymentMethodsTest extends TestCase
{
    use CreatesCompanyEnvironment, CreatesPaymentGateways, RefreshDatabase;

    protected User $cashier;

    protected Product $product;

    protected RegisterSession $session;

    protected PaymentMethod $nequi;

    protected PaymentMethod $daviplata;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEnvironment();

        $this->cashier = $this->makeUser('admin');
        $this->product = $this->makeProduct(['price' => 6150, 'tax_rate' => 0], 80);
        $this->session = app(RegisterSessionService::class)->open($this->register, $this->cashier, 100000);
        $this->nequi = $this->manualMethod('Nequi', PaymentMethod::KIND_TRANSFER, [
            'icon' => 'device-mobile',
            'availability' => ['channels' => ['pos']],
            'fee_percent' => 1.5,
        ]);
        $this->daviplata = $this->manualMethod('Daviplata', PaymentMethod::KIND_TRANSFER, [
            'availability' => ['channels' => ['pos']],
            'requires_reference' => true,
            'reference_label' => 'Número de la transacción',
        ]);
    }

    protected function sell(array $payments, int $quantity = 2)
    {
        return $this->actingAs($this->cashier)->withSession($this->withActiveStore())->from(route('pos.index'))->post(route('pos.sales.store'), [
            'items' => [['product_id' => $this->product->id, 'quantity' => $quantity]],
            'payments' => $payments,
            'register_session_id' => $this->session->id,
        ]);
    }

    public function test_nequi_daviplata_y_efectivo_en_la_misma_venta(): void
    {
        // 2 x 6.150 = 12.300.
        $this->sell([
            ['payment_method_id' => $this->nequi->id, 'amount' => 5000],
            ['payment_method_id' => $this->daviplata->id, 'amount' => 4000, 'reference' => 'D-778'],
            ['payment_method_id' => $this->systemMethod('cash')->id, 'amount' => 5000, 'received_amount' => 5000],
        ])->assertSessionHasNoErrors();

        $sale = Sale::query()->withoutGlobalScopes()->with('payments')->firstOrFail();

        $this->assertSame(12300, $sale->total);
        $this->assertCount(3, $sale->payments);
        $this->assertSame(1700, (int) $sale->payments->firstWhere('method', SalePayment::METHOD_CASH)->change_amount);

        // Las dos son transferencias, cada una con su medio.
        $transfers = $sale->payments->where('method', SalePayment::METHOD_TRANSFER);
        $this->assertEqualsCanonicalizing([$this->nequi->id, $this->daviplata->id], $transfers->pluck('payment_method_id')->all());
        $this->assertSame('Nequi', $sale->payments->firstWhere('payment_method_id', $this->nequi->id)->methodLabel());

        // La suma de los pagos menos el cambio es el total.
        $this->assertSame($sale->total, (int) $sale->payments->sum('amount') - (int) $sale->payments->sum('change_amount'));
    }

    public function test_el_redondeo_solo_cuando_todo_es_efectivo(): void
    {
        app(SettingsService::class)->set($this->company->id, 'pos.cash_rounding', 100);

        // Todo en efectivo: 12.300 queda igual (ya es centena); con 6.130 x 2 = 12.260 se redondea a 12.300.
        $this->product->update(['price' => 6130]);
        $this->sell([['payment_method_id' => $this->systemMethod('cash')->id, 'amount' => 12300, 'received_amount' => 12300]])->assertSessionHasNoErrors();
        $cashOnly = Sale::query()->withoutGlobalScopes()->latest('id')->firstOrFail();
        $this->assertSame(40, (int) $cashOnly->rounding_total);

        // Con Nequi de por medio no se redondea: la suma tiene que ser el total exacto.
        $this->sell([
            ['payment_method_id' => $this->nequi->id, 'amount' => 6260],
            ['payment_method_id' => $this->systemMethod('cash')->id, 'amount' => 6000, 'received_amount' => 6000],
        ])->assertSessionHasNoErrors();
        $mixed = Sale::query()->withoutGlobalScopes()->latest('id')->firstOrFail();
        $this->assertSame(0, (int) $mixed->rounding_total);
        $this->assertSame(12260, $mixed->total);
    }

    public function test_un_medio_apagado_o_fuera_de_horario_se_rechaza(): void
    {
        $this->nequi->update(['is_active' => false]);

        $this->sell([['payment_method_id' => $this->nequi->id, 'amount' => 12300]])->assertSessionHasErrors('payments');

        $this->nequi->update([
            'is_active' => true,
            'availability' => ['channels' => ['pos'], 'schedule' => [['days' => [1, 2, 3, 4, 5], 'from' => '07:00', 'to' => '21:00']]],
        ]);

        // Un sabado a las 10 de la mañana.
        $this->travelTo(CarbonImmutable::parse('2026-10-10 10:00:00', 'America/Bogota'));
        $this->sell([['payment_method_id' => $this->nequi->id, 'amount' => 12300]])->assertSessionHasErrors('payments');

        $this->assertSame(0, Sale::query()->withoutGlobalScopes()->count());
    }

    public function test_la_referencia_obligatoria_se_exige_en_el_servidor(): void
    {
        $this->sell([['payment_method_id' => $this->daviplata->id, 'amount' => 12300]])->assertSessionHasErrors('payments');

        $this->assertSame(0, Sale::query()->withoutGlobalScopes()->count());
    }

    public function test_la_caja_recibe_sus_medios_y_no_inventa_los_suyos(): void
    {
        $this->actingAs($this->cashier)->withSession($this->withActiveStore())->get(route('pos.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Pos/Sale')
                ->where('paymentMethods', fn ($methods) => collect($methods)->pluck('name')->contains('Nequi')
                    && collect($methods)->pluck('name')->contains('Daviplata')
                    // La tienda en linea y el cambio por devolucion no se ofrecen en la caja.
                    && ! collect($methods)->pluck('kind')->contains('exchange')
                    && ! collect($methods)->pluck('name')->contains('Efectivo al recibir'))
            );
    }

    public function test_sin_permiso_de_pasarela_la_caja_no_ofrece_el_qr(): void
    {
        $this->gatewayMethod(PaymentMethod::KIND_GATEWAY_LINK, $this->wompiAccount(), ['name' => 'QR Wompi']);
        $cashier = $this->makeUser('cajero');
        $cashier->revokePermissionTo('pos.sale.gateway');
        app(RegisterSessionService::class)->open(\App\Models\Register::query()->withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'store_id' => $this->store->id, 'name' => 'Caja 2', 'code' => 'C2', 'is_active' => true,
        ]), $cashier, 50000);

        $names = fn (User $user) => $this->actingAs($user)->withSession($this->withActiveStore())->get(route('pos.index'))->viewData('page')['props']['paymentMethods'];

        $this->assertContains('QR Wompi', collect($names($this->cashier))->pluck('name')->all());
        $this->assertNotContains('QR Wompi', collect($names($cashier->fresh()))->pluck('name')->all());
    }

    public function test_el_cierre_y_el_informe_separan_nequi_de_daviplata(): void
    {
        $this->sell([
            ['payment_method_id' => $this->nequi->id, 'amount' => 10000],
            ['payment_method_id' => $this->daviplata->id, 'amount' => 2300, 'reference' => 'D-1'],
        ])->assertSessionHasNoErrors();

        $summary = collect(app(RegisterSessionService::class)->summary($this->session->fresh())['payments_by_method']);
        $this->assertSame(10000, $summary->firstWhere('label', 'Nequi')['total']);
        $this->assertSame(2300, $summary->firstWhere('label', 'Daviplata')['total']);

        $report = app(ReportService::class)->sales($this->company->id, CarbonImmutable::today()->subDay(), CarbonImmutable::today()->addDay(), null, null);
        $byMethod = collect($report['by_payment_method']);
        $nequi = $byMethod->firstWhere('payment_method_id', $this->nequi->id);

        $this->assertSame(10000, $nequi['total']);
        // 1,5 % de 10.000.
        $this->assertSame(150, $nequi['fee']);
        $this->assertSame(9850, $nequi['net']);
        // El subtotal por tipo sigue en `by_method`.
        $this->assertSame(12300, collect($report['by_method'])->firstWhere('method', SalePayment::METHOD_TRANSFER)['total']);
    }
}
