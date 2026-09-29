<?php

namespace Tests\Feature;

use App\Models\Promotion;
use App\Models\PromotionTarget;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\User;
use App\Services\PromotionService;
use App\Services\RegisterSessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsVariants;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\TestCase;

/**
 * Una promocion puede apuntar al articulo con subarticulos: alcanza a todos, y en el lleva N paga M
 * sus presentaciones cuentan juntas (un aceite de 500 ml y dos de 900 ml son tres aceites). Dos
 * articulos distintos siguen sin juntarse. La cotizacion de la caja y la venta dicen lo mismo.
 */
class PromotionFamilyTest extends TestCase
{
    use BuildsVariants, CreatesCompanyEnvironment, RefreshDatabase;

    protected User $cashier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEnvironment();
        $this->seedAttributes();
        $this->cashier = $this->makeUser('admin');
    }

    protected function familyPromotion(int $familyId, string $type, array $config = [], ?int $value = null): Promotion
    {
        $promotion = Promotion::query()->withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'name' => 'Promo aceites', 'type' => $type,
            'applies_online' => true, 'applies_pos' => true, 'is_active' => true, 'config' => $config,
        ]);
        $promotion->targets()->create(['target_type' => PromotionTarget::TYPE_FAMILY, 'target_id' => $familyId, 'value' => $value, 'quantity' => 1]);
        app(PromotionService::class)->forget();

        return $promotion;
    }

    public function test_el_destino_familia_alcanza_a_todos_sus_subarticulos(): void
    {
        $family = $this->makeFamily('Aceite de girasol', 'Volumen', null, [['500 ml', null, 8000], ['900 ml', null, 14000]], user: $this->cashier);
        $this->familyPromotion($family->id, Promotion::TYPE_PERCENT_OFF, ['percent' => 10]);

        $prices = app(PromotionService::class)->unitPrices($family->variants()->get(), $this->company->id, PromotionService::CHANNEL_POS);

        $this->assertSame([7200, 12600], array_values(array_column($prices, 'price')));
    }

    public function test_tres_por_dos_junta_presentaciones_de_la_misma_familia(): void
    {
        $family = $this->makeFamily('Aceite de girasol', 'Volumen', null, [['500 ml', null, 8000], ['900 ml', null, 14000]], user: $this->cashier);
        [$small, $big] = $family->variants()->get()->all();
        $water = $this->makeProduct(['name' => 'Agua', 'price' => 2000], 50);
        $this->familyPromotion($family->id, Promotion::TYPE_N_FOR_M, ['buy' => 3, 'pay' => 2]);

        $result = app(PromotionService::class)->apply([
            ['product_id' => $small->id, 'quantity' => 1],
            ['product_id' => $big->id, 'quantity' => 2],
            ['product_id' => $water->id, 'quantity' => 1],
        ], $this->company->id, PromotionService::CHANNEL_POS);

        // Se regala la unidad mas barata de las tres: la de 500 ml.
        $this->assertSame(8000, $result['lines'][0]['discount']);
        $this->assertSame(0, $result['lines'][1]['discount']);
        $this->assertSame(0, $result['lines'][2]['discount']);

        // Otro articulo con subarticulos no se junta con este.
        $other = $this->makeFamily('Vinagre', 'Volumen', null, [['500 ml', null, 5000]], user: $this->cashier);
        $result = app(PromotionService::class)->apply([
            ['product_id' => $small->id, 'quantity' => 2],
            ['product_id' => $other->variants()->first()->id, 'quantity' => 1],
        ], $this->company->id, PromotionService::CHANNEL_POS);
        $this->assertSame(0, (int) collect($result['lines'])->sum('discount'));

        // El aviso dice que se puede combinar.
        $descriptors = app(PromotionService::class)->descriptors([$small], $this->company->id, PromotionService::CHANNEL_POS);
        $this->assertSame('Lleva 3 y paga 2 en cualquier presentación', $descriptors[$small->id]['hint']);
    }

    public function test_la_cotizacion_y_la_venta_coinciden(): void
    {
        $family = $this->makeFamily('Aceite de girasol', 'Volumen', null, [['500 ml', null, 8000], ['900 ml', null, 14000]], user: $this->cashier);
        [$small, $big] = $family->variants()->get()->all();
        $this->familyPromotion($family->id, Promotion::TYPE_N_FOR_M, ['buy' => 3, 'pay' => 2]);

        $items = [['product_id' => $small->id, 'quantity' => 1], ['product_id' => $big->id, 'quantity' => 2]];

        $quote = $this->actingAs($this->cashier)->withSession($this->withActiveStore())
            ->postJson(route('pos.quote'), ['items' => $items])
            ->assertOk()
            ->json('lines');

        $session = app(RegisterSessionService::class)->open($this->register, $this->cashier, 100000);
        $this->actingAs($this->cashier)->withSession($this->withActiveStore())->post(route('pos.sales.store'), [
            'items' => $items,
            'payments' => [['method' => SalePayment::METHOD_CASH, 'amount' => 100000]],
            'register_session_id' => $session->id,
        ])->assertSessionHasNoErrors();

        $shown = 8000 + 2 * 14000 - (int) collect($quote)->sum('promo_discount');
        $this->assertSame(28000, $shown);
        $this->assertSame($shown, Sale::query()->withoutGlobalScopes()->sole()->total);
    }

    public function test_el_armador_ofrece_la_familia_como_una_entrada(): void
    {
        $this->makeFamily('Aceite de girasol', 'Volumen', null, [['500 ml', null, 8000]], ['code' => 'ACE-GIR'], $this->cashier);

        $this->actingAs($this->cashier)->getJson(route('promotions.products', ['search' => 'Aceite']))
            ->assertOk()
            ->assertJsonPath('0.target_type', PromotionTarget::TYPE_FAMILY)
            ->assertJsonPath('0.name', 'Aceite de girasol');
    }
}
