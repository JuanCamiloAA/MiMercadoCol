<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Promotion;
use App\Models\PromotionTarget;
use App\Models\RegisterSession;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\User;
use App\Services\PromotionService;
use App\Services\RegisterSessionService;
use App\Services\SaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\TestCase;

/**
 * Promociones vistas desde la caja.
 *
 * El cajero tiene que enterarse de que el articulo trae promocion —incluso de las que no se
 * notan en el precio, como el 3x2— y poder decidir que no se la aplica. Lo que decida es lo
 * que se cobra: el servidor no la aplica por su cuenta.
 */
class PosPromotionTest extends TestCase
{
    use CreatesCompanyEnvironment, RefreshDatabase;

    protected User $cashier;

    protected RegisterSession $session;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEnvironment();
        $this->cashier = $this->makeUser('admin');
        $this->session = app(RegisterSessionService::class)->open($this->register, $this->cashier, 100000);
    }

    public function test_el_catalogo_anuncia_la_promocion_de_precio(): void
    {
        $product = $this->makeProduct(['price' => 5100]);
        $this->promotion(Promotion::TYPE_SPECIAL_PRICE, [$product], ['value' => 4200]);

        $products = app(PromotionService::class)->descriptors([$product], $this->company->id, PromotionService::CHANNEL_POS);

        $this->assertSame(4200, $products[$product->id]['price']);
        $this->assertSame(5100, $products[$product->id]['compare_at']);
        $this->assertFalse($products[$product->id]['depends_on_quantity']);
    }

    public function test_el_catalogo_tambien_anuncia_el_tres_por_dos(): void
    {
        $product = $this->makeProduct(['price' => 4000]);
        $this->promotion(Promotion::TYPE_N_FOR_M, [$product], [], ['buy' => 3, 'pay' => 2]);

        $descriptors = app(PromotionService::class)->descriptors([$product], $this->company->id, PromotionService::CHANNEL_POS);

        // No baja el precio unitario, pero el cajero tiene que verla igual.
        $this->assertNull($descriptors[$product->id]['price']);
        $this->assertTrue($descriptors[$product->id]['depends_on_quantity']);
        $this->assertSame('3x2', $descriptors[$product->id]['badge']);
        $this->assertSame('Lleva 3 y paga 2', $descriptors[$product->id]['hint']);
    }

    public function test_la_caja_cobra_la_promocion_cuando_el_cajero_la_deja(): void
    {
        $product = $this->makeProduct(['price' => 5100, 'tax_rate' => 0], 50);
        $this->promotion(Promotion::TYPE_SPECIAL_PRICE, [$product], ['value' => 4200]);

        $sale = $this->sell($product, 2, skip: false, paid: 8400);

        $this->assertSame(8400, $sale->total);
        $this->assertSame(4200, $sale->items->first()->unit_price);
    }

    public function test_el_cajero_puede_no_aplicar_la_promocion(): void
    {
        $product = $this->makeProduct(['price' => 5100, 'tax_rate' => 0], 50);
        $this->promotion(Promotion::TYPE_SPECIAL_PRICE, [$product], ['value' => 4200]);

        $sale = $this->sell($product, 2, skip: true, paid: 10200);

        $this->assertSame(10200, $sale->total);
        $this->assertSame(5100, $sale->items->first()->unit_price);
    }

    public function test_declinar_el_tres_por_dos_cobra_las_tres_unidades(): void
    {
        $product = $this->makeProduct(['price' => 4000, 'tax_rate' => 0], 50);
        $this->promotion(Promotion::TYPE_N_FOR_M, [$product], [], ['buy' => 3, 'pay' => 2]);

        $conPromo = $this->sell($product, 3, skip: false, paid: 8000);
        $this->assertSame(8000, $conPromo->total);

        $sinPromo = $this->sell($product, 3, skip: true, paid: 12000);
        $this->assertSame(12000, $sinPromo->total);
    }

    public function test_la_cotizacion_le_dice_al_pos_cuanto_rebaja(): void
    {
        $product = $this->makeProduct(['price' => 4000, 'tax_rate' => 0], 50);
        $this->promotion(Promotion::TYPE_N_FOR_M, [$product], [], ['buy' => 3, 'pay' => 2]);

        $response = $this->actingAs($this->cashier)
            ->withSession($this->withActiveStore())
            ->postJson(route('pos.quote'), [
                'items' => [['product_id' => $product->id, 'quantity' => 3, 'unit_price' => 4000, 'discount' => 0]],
            ]);

        $response->assertOk();
        $this->assertSame(4000, $response->json('lines.0.promo_discount'));

        // Y con la promocion declinada, no rebaja nada.
        $declined = $this->actingAs($this->cashier)
            ->withSession($this->withActiveStore())
            ->postJson(route('pos.quote'), [
                'items' => [['product_id' => $product->id, 'quantity' => 3, 'unit_price' => 4000, 'discount' => 0, 'skip_promotion' => true]],
            ]);

        $this->assertSame(0, $declined->json('lines.0.promo_discount'));
    }

    public function test_el_descuento_del_cajero_no_se_confunde_con_el_de_la_promocion(): void
    {
        $product = $this->makeProduct(['price' => 4000, 'tax_rate' => 0], 50);
        $this->promotion(Promotion::TYPE_N_FOR_M, [$product], [], ['buy' => 3, 'pay' => 2]);

        $response = $this->actingAs($this->cashier)
            ->withSession($this->withActiveStore())
            ->postJson(route('pos.quote'), [
                'items' => [['product_id' => $product->id, 'quantity' => 3, 'unit_price' => 4000, 'discount' => 500]],
            ]);

        // Los 500 los puso el cajero; la cotizacion solo reporta lo de la promocion.
        $this->assertSame(4000, $response->json('lines.0.promo_discount'));
    }

    /**
     * @param  list<Product>  $products
     * @param  array<string, mixed>  $target
     * @param  array<string, mixed>  $config
     */
    protected function promotion(string $type, array $products, array $target = [], array $config = []): Promotion
    {
        $promotion = Promotion::query()->withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'name' => 'Promocion de caja',
            'type' => $type,
            'applies_online' => true,
            'applies_pos' => true,
            'is_active' => true,
            'config' => $config,
        ]);

        foreach ($products as $product) {
            $promotion->targets()->create(array_merge([
                'target_type' => PromotionTarget::TYPE_PRODUCT,
                'target_id' => $product->id,
            ], $target));
        }

        return $promotion;
    }

    protected function sell(Product $product, float $quantity, bool $skip, int $paid): Sale
    {
        app(PromotionService::class)->forget();

        return app(SaleService::class)->create([
            'items' => [[
                'product_id' => $product->id,
                'quantity' => $quantity,
                'unit_price' => (int) $product->price,
                'skip_promotion' => $skip,
            ]],
            'payments' => [['method' => SalePayment::METHOD_CASH, 'amount' => $paid]],
        ], $this->cashier, $this->store, $this->session)->load('items');
    }
}
