<?php

namespace Tests\Feature;

use App\Models\Promotion;
use App\Models\PromotionTarget;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Services\PromotionService;
use App\Services\SaleService;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\Concerns\SellsServicesAtPos;
use Tests\TestCase;

/**
 * Los precios de un servicio: promociones de precio si, de cantidad no; el cupon solo toca lo
 * que se cobra; redondeo y cobro combinado como siempre.
 */
class PosServicePricingTest extends TestCase
{
    use CreatesCompanyEnvironment, RefreshDatabase, SellsServicesAtPos;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEnvironment();
        $this->seedServiceScenario();
    }

    public function test_un_articulo_detallado_toma_la_promocion_de_precio(): void
    {
        $this->promotion(Promotion::TYPE_SPECIAL_PRICE, $this->fresa->id, ['value' => 2500]);

        $this->sell([['service_id' => $this->arreglo->id, 'quantity' => 1]])->assertSessionHasNoErrors();

        // 5.000 + 2 x 2.500 + 3 x 1.000.
        $this->assertSame(13000, $this->lastSale()->total);
    }

    public function test_un_articulo_detallado_no_entra_al_tres_por_dos(): void
    {
        $this->promotion(Promotion::TYPE_N_FOR_M, $this->banano->id, [], ['buy' => 3, 'pay' => 2]);

        $this->sell([['service_id' => $this->arreglo->id, 'quantity' => 1]])->assertSessionHasNoErrors();

        // Los tres bananos del arreglo se cobran los tres.
        $this->assertSame(14000, $this->lastSale()->total);

        // Sueltos si arman el tres por dos.
        $this->sell([['product_id' => $this->banano->id, 'quantity' => 3]])->assertSessionHasNoErrors();
        $this->assertSame(2000, $this->lastSale()->total);
    }

    public function test_el_cupon_no_toca_los_articulos_de_un_servicio_cerrado(): void
    {
        $sale = app(SaleService::class)->create([
            'items' => [
                ['service_id' => $this->ancheta->id, 'quantity' => 1],
                ['product_id' => $this->galletas->id, 'quantity' => 2],
                ['description' => 'Domicilio', 'quantity' => 1, 'unit_price' => 5000, 'tax_rate' => 0],
            ],
            'coupon_discount' => 7000,
            'channel' => Sale::CHANNEL_ONLINE,
            'payments' => [['method' => SalePayment::METHOD_TRANSFER, 'amount' => 60000 + 10000 + 5000 - 7000]],
        ], $this->cashier, $this->store)->load('items');

        $this->assertSame(68000, $sale->total);

        $service = $sale->items->firstWhere('service_id', $this->ancheta->id);
        $loose = $sale->items->whereNull('parent_sale_item_id')->firstWhere('product_id', $this->galletas->id);
        $delivery = $sale->items->firstWhere('description', 'Domicilio');

        // 7.000 a prorrata entre 60.000 y 10.000: 6.000 y 1.000.
        $this->assertSame(6000, $service->discount);
        $this->assertSame(1000, $loose->discount);
        $this->assertSame(0, $delivery->discount);
        $this->assertTrue($sale->items->where('parent_sale_item_id', $service->id)->every(fn ($item) => $item->discount === 0 && $item->line_total === 0));
    }

    public function test_el_cupon_si_llega_a_los_articulos_detallados(): void
    {
        $sale = app(SaleService::class)->create([
            'items' => [['service_id' => $this->arreglo->id, 'quantity' => 1]],
            'coupon_discount' => 1400,
            'channel' => Sale::CHANNEL_ONLINE,
            'payments' => [['method' => SalePayment::METHOD_TRANSFER, 'amount' => 12600]],
        ], $this->cashier, $this->store)->load('items');

        $this->assertSame(12600, $sale->total);
        $this->assertSame(1400, (int) $sale->items->sum('discount'));
        $this->assertGreaterThan(0, $sale->items->firstWhere('product_id', $this->fresa->id)->discount);
    }

    public function test_redondeo_solo_en_caja_y_todo_efectivo(): void
    {
        app(SettingsService::class)->set($this->company->id, 'pos.cash_rounding', 100);
        $recarga = $this->makeService('Recarga', 0, attributes: ['open_price' => true]);

        $this->sell([['service_id' => $recarga->id, 'quantity' => 1, 'unit_price' => 10050]], [['method' => SalePayment::METHOD_CASH, 'amount' => 10100]])->assertSessionHasNoErrors();
        $this->assertSame(10100, $this->lastSale()->total);

        $this->sell([['service_id' => $recarga->id, 'quantity' => 1, 'unit_price' => 10050]], [['method' => SalePayment::METHOD_CARD, 'amount' => 10050]])->assertSessionHasNoErrors();
        $this->assertSame(10050, $this->lastSale()->total);
    }

    public function test_cobro_combinado_cuadra_al_peso(): void
    {
        $this->sell(
            [['service_id' => $this->arreglo->id, 'quantity' => 1], ['service_id' => $this->ancheta->id, 'quantity' => 1]],
            [['method' => SalePayment::METHOD_CARD, 'amount' => 50000], ['method' => SalePayment::METHOD_CASH, 'amount' => 30000, 'received_amount' => 30000]],
        )->assertSessionHasNoErrors();

        $sale = $this->lastSale()->load('payments');
        $this->assertSame(74000, $sale->total);
        $this->assertSame(74000, (int) $sale->payments->sum(fn (SalePayment $payment) => $payment->netAmount()));
        $this->assertSame($sale->subtotal + $sale->tax_total, $sale->total);
    }

    public function test_venta_a_credito_con_servicio(): void
    {
        $customer = $this->makeCustomer(['credit_enabled' => true, 'credit_limit' => 500000, 'credit_term_days' => 15]);
        $this->cashier->givePermissionTo('pos.sale.credit');

        $this->sell(
            [['service_id' => $this->ancheta->id, 'quantity' => 1]],
            [['method' => SalePayment::METHOD_CREDIT, 'amount' => 60000]],
            $this->cashier->fresh(),
            ['customer_id' => $customer->id],
        )->assertSessionHasNoErrors();

        $sale = $this->lastSale();
        $this->assertSame(Sale::STATUS_CREDIT, $sale->status);
        $this->assertSame(60000, $sale->due_total);
    }

    /**
     * @param  array<string, mixed>  $target
     * @param  array<string, mixed>  $config
     */
    protected function promotion(string $type, int $productId, array $target = [], array $config = []): void
    {
        $promotion = Promotion::query()->withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'name' => 'Promocion',
            'type' => $type,
            'applies_online' => true,
            'applies_pos' => true,
            'is_active' => true,
            'config' => $config,
        ]);

        $promotion->targets()->create(array_merge(['target_type' => PromotionTarget::TYPE_PRODUCT, 'target_id' => $productId], $target));
        app(PromotionService::class)->forget();
    }
}
