<?php

namespace Tests\Feature;

use App\Models\CashMovement;
use App\Models\RegisterSession;
use App\Models\SalePayment;
use App\Services\RegisterSessionService;
use App\Services\SaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\TestCase;

class RegisterSessionTest extends TestCase
{
    use CreatesCompanyEnvironment, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEnvironment();
    }

    public function test_no_permite_dos_turnos_abiertos_en_la_misma_caja(): void
    {
        $first = $this->makeUser('cajero');
        $second = $this->makeUser('cajero');

        app(RegisterSessionService::class)->open($this->register, $first, 200000);

        try {
            app(RegisterSessionService::class)->open($this->register, $second, 150000);
            $this->fail('Debio rechazar la segunda apertura.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('turno abierto', $exception->errors()['register_id'][0]);
        }

        $this->assertSame(1, RegisterSession::query()->withoutGlobalScopes()->where('status', 'open')->count());
    }

    public function test_el_esperado_y_la_diferencia_cuadran(): void
    {
        $cashier = $this->makeUser('cajero');
        $product = $this->makeProduct(['price' => 10000, 'tax_rate' => 0], 100);

        $sessions = app(RegisterSessionService::class);
        $session = $sessions->open($this->register, $cashier, 200000);

        app(SaleService::class)->create([
            'items' => [['product_id' => $product->id, 'quantity' => 5]],
            'payments' => [['method' => SalePayment::METHOD_CASH, 'amount' => 50000, 'received_amount' => 50000]],
        ], $cashier, $this->store, $session);

        app(SaleService::class)->create([
            'items' => [['product_id' => $product->id, 'quantity' => 3]],
            'payments' => [['method' => SalePayment::METHOD_CARD, 'amount' => 30000]],
        ], $cashier, $this->store, $session);

        $sessions->registerCashMovement($session, CashMovement::TYPE_OUT, 80000, 'Traslado a la caja fuerte', $cashier);
        $sessions->registerCashMovement($session, CashMovement::TYPE_IN, 20000, 'Cambio adicional', $cashier);

        // 200.000 base + 50.000 efectivo − 80.000 sangria + 20.000 ingreso = 190.000
        $this->assertSame(190000, $sessions->expectedAmount($session));

        $closed = $sessions->close($session, 188000, ['50000' => 3, '20000' => 1, '10000' => 1], 'Faltaron vueltas');

        $this->assertSame(190000, $closed->expected_amount);
        $this->assertSame(188000, $closed->counted_amount);
        $this->assertSame(-2000, $closed->difference);
        $this->assertSame(RegisterSession::STATUS_CLOSED, $closed->status);
    }

    public function test_el_esperado_solo_cuenta_las_lineas_de_efectivo(): void
    {
        $cashier = $this->makeUser('admin');
        $product = $this->makeProduct(['price' => 6100, 'tax_rate' => 0], 80);
        $session = app(RegisterSessionService::class)->open($this->register, $cashier, 100000);

        // 12.200 repartidos: 8.000 en efectivo y 4.200 con tarjeta, mas 10.000 de vuelto.
        app(SaleService::class)->create([
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
            'payments' => [
                ['method' => SalePayment::METHOD_CASH, 'amount' => 18000, 'received_amount' => 18000],
                ['method' => SalePayment::METHOD_CARD, 'amount' => 4200],
            ],
        ], $cashier, $this->store, $session);

        // En la gaveta quedan 18.000 - 10.000 de cambio: la tarjeta no entra.
        $this->assertSame(108000, app(RegisterSessionService::class)->expectedAmount($session->fresh()));

        $summary = app(RegisterSessionService::class)->summary($session->fresh());
        $byMethod = collect($summary['payments_by_method'])->keyBy('method');

        $this->assertSame(8000, $byMethod['cash']['total']);
        $this->assertSame(4200, $byMethod['card']['total']);
    }

    public function test_una_diferencia_sin_explicacion_no_se_puede_cerrar(): void
    {
        $cashier = $this->makeUser('cajero');
        $session = app(RegisterSessionService::class)->open($this->register, $cashier, 200000);

        $this->expectException(ValidationException::class);

        app(RegisterSessionService::class)->close($session, 150000, [], null);
    }

    public function test_no_se_puede_sacar_mas_efectivo_del_que_hay(): void
    {
        $cashier = $this->makeUser('cajero');
        $session = app(RegisterSessionService::class)->open($this->register, $cashier, 100000);

        $this->expectException(ValidationException::class);

        app(RegisterSessionService::class)->registerCashMovement($session, CashMovement::TYPE_OUT, 150000, 'Sangria', $cashier);
    }

    public function test_el_pos_redirige_a_la_apertura_cuando_no_hay_turno(): void
    {
        $cashier = $this->makeUser('cajero');

        $this->actingAs($cashier)
            ->withSession($this->withActiveStore())
            ->get(route('pos.index'))
            ->assertRedirect(route('registers.opening.create'));
    }
}
