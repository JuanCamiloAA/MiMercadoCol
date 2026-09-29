<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\RegisterSession;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\User;
use App\Services\RegisterSessionService;
use App\Services\SaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\TestCase;

/**
 * Cobro repartido entre varios medios de pago.
 *
 * La invariante que sostiene todo lo demas: para una venta no anulada,
 * `sum(amount) - change_amount(efectivo) == sales.total`. Si esa resta deja de cuadrar, el
 * arqueo de caja y los informes por medio de pago mienten.
 */
class SplitPaymentTest extends TestCase
{
    use CreatesCompanyEnvironment, RefreshDatabase;

    protected User $cashier;

    protected Product $product;

    protected RegisterSession $session;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEnvironment();

        $this->cashier = $this->makeUser('admin');
        // 2 x 6.100 = 12.200: un total que no se altera con el redondeo a la centena.
        $this->product = $this->makeProduct(['price' => 6100, 'tax_rate' => 0], 80);
        $this->session = app(RegisterSessionService::class)->open($this->register, $this->cashier, 100000);
    }

    public function test_registra_una_venta_con_efectivo_y_tarjeta_en_dos_lineas(): void
    {
        $sale = $this->sell([
            ['method' => SalePayment::METHOD_CASH, 'amount' => 8000],
            ['method' => SalePayment::METHOD_CARD, 'amount' => 4200, 'reference' => '4821'],
        ]);

        $this->assertSame(12200, $sale->total);
        $this->assertSame(Sale::STATUS_PAID, $sale->status);
        $this->assertSame(12200, $sale->paid_total);
        $this->assertSame(0, $sale->due_total);
        $this->assertCount(2, $sale->payments);

        $card = $sale->payments->firstWhere('method', SalePayment::METHOD_CARD);
        $this->assertSame(4200, $card->amount);
        $this->assertSame('4821', $card->reference);

        // La secuencia deja el orden en que el cajero los fue aplicando.
        $this->assertSame([1, 2], $sale->payments->sortBy('sequence')->pluck('sequence')->all());
    }

    public function test_la_suma_de_los_pagos_menos_el_cambio_es_el_total(): void
    {
        $sale = $this->sell([
            ['method' => SalePayment::METHOD_CARD, 'amount' => 2200],
            ['method' => SalePayment::METHOD_CASH, 'amount' => 20000],
        ]);

        $paid = (int) $sale->payments->sum('amount');
        $change = (int) $sale->payments->sum('change_amount');

        $this->assertSame($sale->total, $paid - $change);
        $this->assertSame(10000, $change);
    }

    public function test_rechaza_cuando_la_suma_de_los_pagos_no_alcanza(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Faltan');

        $this->sell([
            ['method' => SalePayment::METHOD_CASH, 'amount' => 5000],
            ['method' => SalePayment::METHOD_CARD, 'amount' => 2000],
        ]);
    }

    public function test_rechaza_cuando_la_tarjeta_excede_el_total(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Solo el efectivo puede exceder el total.');

        $this->sell([
            ['method' => SalePayment::METHOD_CARD, 'amount' => 15000],
        ]);
    }

    public function test_el_cambio_se_guarda_solo_en_la_linea_de_efectivo(): void
    {
        $sale = $this->sell([
            ['method' => SalePayment::METHOD_CASH, 'amount' => 20000],
            ['method' => SalePayment::METHOD_TRANSFER, 'amount' => 2200],
        ]);

        $cash = $sale->payments->firstWhere('method', SalePayment::METHOD_CASH);
        $transfer = $sale->payments->firstWhere('method', SalePayment::METHOD_TRANSFER);

        $this->assertSame(10000, $cash->change_amount);
        $this->assertNull($transfer->change_amount);
        $this->assertNull($transfer->received_amount);
    }

    public function test_rechaza_dos_pagos_del_mismo_medio(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('mismo medio');

        $this->sell([
            ['method' => SalePayment::METHOD_CASH, 'amount' => 6000],
            ['method' => SalePayment::METHOD_CASH, 'amount' => 6200],
        ]);
    }

    public function test_el_endpoint_rechaza_el_cobro_incompleto(): void
    {
        $response = $this->actingAs($this->cashier)
            ->withSession($this->withActiveStore())
            ->post(route('pos.sales.store'), [
                'items' => [['product_id' => $this->product->id, 'quantity' => 2]],
                'payments' => [['method' => SalePayment::METHOD_CASH, 'amount' => 5000]],
            ]);

        $response->assertSessionHasErrors('payments');
        $this->assertDatabaseCount('sales', 0);
    }

    public function test_el_endpoint_registra_el_cobro_repartido(): void
    {
        $this->actingAs($this->cashier)
            ->withSession($this->withActiveStore())
            ->post(route('pos.sales.store'), [
                'items' => [['product_id' => $this->product->id, 'quantity' => 2]],
                'payments' => [
                    ['method' => SalePayment::METHOD_CASH, 'amount' => 8000, 'received_amount' => 8000],
                    ['method' => SalePayment::METHOD_CARD, 'amount' => 4200, 'reference' => '4821'],
                ],
            ])
            ->assertRedirect(route('pos.index'));

        $sale = Sale::query()->withoutGlobalScopes()->firstOrFail();

        $this->assertSame(12200, $sale->total);
        $this->assertCount(2, $sale->payments);
    }

    /**
     * @param  list<array<string, mixed>>  $payments
     */
    protected function sell(array $payments): Sale
    {
        return app(SaleService::class)->create([
            'items' => [['product_id' => $this->product->id, 'quantity' => 2]],
            'payments' => $payments,
        ], $this->cashier, $this->store, $this->session);
    }
}
