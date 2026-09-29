<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Promotion;
use App\Models\PromotionTarget;
use App\Services\PromotionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\TestCase;

/**
 * Motor de precios con promocion.
 *
 * Son cuatro tipos con aritmetica distinta y todos terminan en pesos enteros, asi que lo que
 * se cuida aqui es que no se pierda ni se invente un peso al repartir un ahorro.
 */
class PromotionEngineTest extends TestCase
{
    use CreatesCompanyEnvironment, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEnvironment();
    }

    public function test_precio_especial_reemplaza_el_de_lista(): void
    {
        $product = $this->makeProduct(['price' => 5100]);
        $promotion = $this->promotion(Promotion::TYPE_SPECIAL_PRICE, ['label' => 'Oferta']);
        $this->target($promotion, $product, ['value' => 4200]);

        $result = app(PromotionService::class)->apply(
            [['product_id' => $product->id, 'quantity' => 2]],
            $this->company->id,
            PromotionService::CHANNEL_ONLINE,
        );

        $this->assertSame(4200, $result['lines'][0]['unit_price']);
        $this->assertSame(1800, $result['applied'][0]['amount']);
        $this->assertSame('Oferta', $result['applied'][0]['badge']);
    }

    public function test_el_porcentaje_alcanza_a_toda_la_categoria(): void
    {
        $product = $this->makeProduct(['price' => 10000]);
        $promotion = $this->promotion(Promotion::TYPE_PERCENT_OFF, ['config' => ['percent' => 20]]);
        $promotion->targets()->create([
            'target_type' => PromotionTarget::TYPE_CATEGORY,
            'target_id' => $this->category->id,
        ]);

        $result = app(PromotionService::class)->apply(
            [['product_id' => $product->id, 'quantity' => 1]],
            $this->company->id,
            PromotionService::CHANNEL_ONLINE,
        );

        $this->assertSame(8000, $result['lines'][0]['unit_price']);
        $this->assertSame('-20%', $result['applied'][0]['badge']);
    }

    public function test_el_tres_por_dos_se_cuenta_por_articulo(): void
    {
        $caro = $this->makeProduct(['price' => 6000]);
        $barato = $this->makeProduct(['price' => 4000]);

        $promotion = $this->promotion(Promotion::TYPE_N_FOR_M, ['config' => ['buy' => 3, 'pay' => 2]]);
        $this->target($promotion, $caro);
        $this->target($promotion, $barato);

        $result = app(PromotionService::class)->apply([
            ['product_id' => $caro->id, 'quantity' => 3],
            ['product_id' => $barato->id, 'quantity' => 3],
        ], $this->company->id, PromotionService::CHANNEL_ONLINE);

        // Cada articulo se lleva su propia unidad gratis: no se mezclan.
        $this->assertSame(6000, (int) $result['lines'][0]['discount']);
        $this->assertSame(4000, (int) $result['lines'][1]['discount']);
        $this->assertSame('3x2', $result['applied'][0]['badge']);
    }

    public function test_el_tres_por_dos_no_junta_articulos_distintos(): void
    {
        $caro = $this->makeProduct(['price' => 6000]);
        $barato = $this->makeProduct(['price' => 4000]);

        $promotion = $this->promotion(Promotion::TYPE_N_FOR_M, ['config' => ['buy' => 3, 'pay' => 2]]);
        $this->target($promotion, $caro);
        $this->target($promotion, $barato);

        $result = app(PromotionService::class)->apply([
            ['product_id' => $caro->id, 'quantity' => 2],
            ['product_id' => $barato->id, 'quantity' => 1],
        ], $this->company->id, PromotionService::CHANNEL_ONLINE);

        // Son tres unidades, pero de dos articulos: ninguno completa su propio trio.
        $this->assertSame(0, (int) $result['lines'][0]['discount']);
        $this->assertSame(0, (int) $result['lines'][1]['discount']);
        $this->assertSame([], $result['applied']);
    }

    public function test_el_tres_por_dos_regala_la_unidad_mas_barata_del_mismo_articulo(): void
    {
        $product = $this->makeProduct(['price' => 6000]);

        $promotion = $this->promotion(Promotion::TYPE_N_FOR_M, ['config' => ['buy' => 3, 'pay' => 2]]);
        $this->target($promotion, $product);

        // El mismo articulo en dos lineas, una con el precio rebajado a mano.
        $result = app(PromotionService::class)->apply([
            ['product_id' => $product->id, 'quantity' => 2, 'unit_price' => 6000],
            ['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 4000],
        ], $this->company->id, PromotionService::CHANNEL_ONLINE);

        $this->assertSame(0, (int) $result['lines'][0]['discount']);
        $this->assertSame(4000, (int) $result['lines'][1]['discount']);
    }

    public function test_el_tres_por_dos_no_regala_nada_si_no_se_completa(): void
    {
        $product = $this->makeProduct(['price' => 4000]);
        $promotion = $this->promotion(Promotion::TYPE_N_FOR_M, ['config' => ['buy' => 3, 'pay' => 2]]);
        $this->target($promotion, $product);

        $result = app(PromotionService::class)->apply(
            [['product_id' => $product->id, 'quantity' => 2]],
            $this->company->id,
            PromotionService::CHANNEL_ONLINE,
        );

        $this->assertSame(0, (int) $result['lines'][0]['discount']);
        $this->assertSame([], $result['applied']);
    }

    public function test_el_combo_reparte_el_ahorro_sin_perder_un_peso(): void
    {
        $arroz = $this->makeProduct(['price' => 5100]);
        $aceite = $this->makeProduct(['price' => 12500]);
        $panela = $this->makeProduct(['price' => 3400]);

        $promotion = $this->promotion(Promotion::TYPE_BUNDLE, ['config' => ['price' => 18000], 'label' => 'Combo hogar']);
        $this->target($promotion, $arroz);
        $this->target($promotion, $aceite);
        $this->target($promotion, $panela);

        $result = app(PromotionService::class)->apply([
            ['product_id' => $arroz->id, 'quantity' => 1],
            ['product_id' => $aceite->id, 'quantity' => 1],
            ['product_id' => $panela->id, 'quantity' => 1],
        ], $this->company->id, PromotionService::CHANNEL_ONLINE);

        // Por separado 21.000; en combo 18.000.
        $descuentos = array_sum(array_map(fn ($line) => (int) $line['discount'], $result['lines']));

        $this->assertSame(3000, $descuentos);
        $this->assertSame(3000, $result['applied'][0]['amount']);

        $cobrado = array_sum(array_map(
            fn ($line) => (int) round($line['quantity'] * $line['unit_price']) - (int) $line['discount'],
            $result['lines'],
        ));

        $this->assertSame(18000, $cobrado);
    }

    public function test_el_combo_solo_cuenta_los_juegos_completos(): void
    {
        $arroz = $this->makeProduct(['price' => 5000]);
        $aceite = $this->makeProduct(['price' => 15000]);

        $promotion = $this->promotion(Promotion::TYPE_BUNDLE, ['config' => ['price' => 18000]]);
        $this->target($promotion, $arroz);
        $this->target($promotion, $aceite);

        $result = app(PromotionService::class)->apply([
            ['product_id' => $arroz->id, 'quantity' => 3],
            ['product_id' => $aceite->id, 'quantity' => 1],
        ], $this->company->id, PromotionService::CHANNEL_ONLINE);

        // Solo se arma un combo: el ahorro es 20.000 - 18.000.
        $this->assertSame(2000, array_sum(array_map(fn ($line) => (int) $line['discount'], $result['lines'])));
    }

    public function test_una_promocion_de_la_tienda_no_toca_el_mostrador(): void
    {
        $product = $this->makeProduct(['price' => 5100]);
        $promotion = $this->promotion(Promotion::TYPE_SPECIAL_PRICE, ['applies_pos' => false]);
        $this->target($promotion, $product, ['value' => 4200]);

        $promotions = app(PromotionService::class);

        $online = $promotions->apply([['product_id' => $product->id, 'quantity' => 1]], $this->company->id, PromotionService::CHANNEL_ONLINE);
        $promotions->forget();
        $pos = $promotions->apply([['product_id' => $product->id, 'quantity' => 1]], $this->company->id, PromotionService::CHANNEL_POS);

        $this->assertSame(4200, $online['lines'][0]['unit_price']);
        $this->assertSame(5100, $pos['lines'][0]['unit_price']);
    }

    public function test_una_promocion_vencida_no_aplica(): void
    {
        $product = $this->makeProduct(['price' => 5100]);
        $promotion = $this->promotion(Promotion::TYPE_SPECIAL_PRICE, [
            'starts_at' => now()->subDays(10),
            'ends_at' => now()->subDay(),
        ]);
        $this->target($promotion, $product, ['value' => 4200]);

        $result = app(PromotionService::class)->apply(
            [['product_id' => $product->id, 'quantity' => 1]],
            $this->company->id,
            PromotionService::CHANNEL_ONLINE,
        );

        $this->assertSame(5100, $result['lines'][0]['unit_price']);
    }

    public function test_entre_dos_promociones_manda_la_de_mayor_prioridad(): void
    {
        $product = $this->makeProduct(['price' => 10000]);

        $floja = $this->promotion(Promotion::TYPE_PERCENT_OFF, ['config' => ['percent' => 50], 'priority' => 1, 'name' => 'Mitad de precio']);
        $floja->targets()->create(['target_type' => PromotionTarget::TYPE_CATEGORY, 'target_id' => $this->category->id]);

        $manda = $this->promotion(Promotion::TYPE_PERCENT_OFF, ['config' => ['percent' => 10], 'priority' => 9, 'name' => 'La que manda']);
        $manda->targets()->create(['target_type' => PromotionTarget::TYPE_CATEGORY, 'target_id' => $this->category->id]);

        $result = app(PromotionService::class)->apply(
            [['product_id' => $product->id, 'quantity' => 1]],
            $this->company->id,
            PromotionService::CHANNEL_ONLINE,
        );

        $this->assertSame(9000, $result['lines'][0]['unit_price']);
        $this->assertSame('La que manda', $result['applied'][0]['name']);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function promotion(string $type, array $attributes = []): Promotion
    {
        return Promotion::query()->withoutGlobalScopes()->create(array_merge([
            'company_id' => $this->company->id,
            'name' => 'Promocion de prueba',
            'type' => $type,
            'applies_online' => true,
            'applies_pos' => true,
            'is_active' => true,
        ], $attributes));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function target(Promotion $promotion, Product $product, array $attributes = []): PromotionTarget
    {
        return $promotion->targets()->create(array_merge([
            'target_type' => PromotionTarget::TYPE_PRODUCT,
            'target_id' => $product->id,
        ], $attributes));
    }
}
