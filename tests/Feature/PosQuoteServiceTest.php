<?php

namespace Tests\Feature;

use App\Models\Promotion;
use App\Models\PromotionTarget;
use App\Services\PromotionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\Concerns\SellsServicesAtPos;
use Tests\TestCase;

/**
 * La cotizacion del tiquete: lo que muestra la caja sale del mismo armador que despues cobra.
 */
class PosQuoteServiceTest extends TestCase
{
    use CreatesCompanyEnvironment, RefreshDatabase, SellsServicesAtPos;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEnvironment();
        $this->seedServiceScenario();
    }

    public function test_la_cotizacion_devuelve_lo_mismo_que_cobra_la_venta(): void
    {
        $promotion = Promotion::query()->withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'name' => 'Fresa', 'type' => Promotion::TYPE_SPECIAL_PRICE,
            'applies_online' => true, 'applies_pos' => true, 'is_active' => true, 'config' => [],
        ]);
        $promotion->targets()->create(['target_type' => PromotionTarget::TYPE_PRODUCT, 'target_id' => $this->fresa->id, 'value' => 2600]);
        app(PromotionService::class)->forget();

        $items = [
            ['product_id' => $this->galletas->id, 'quantity' => 2, 'unit_price' => 5000],
            ['service_id' => $this->arreglo->id, 'quantity' => 1, 'components' => [
                ['product_id' => $this->fresa->id, 'quantity' => 1.5],
                ['product_id' => $this->banano->id, 'quantity' => 3],
            ]],
            ['service_id' => $this->ancheta->id, 'quantity' => 1],
        ];

        $quote = $this->actingAs($this->cashier)->withSession($this->withActiveStore())
            ->postJson(route('pos.quote'), ['items' => $items])
            ->assertOk()
            ->json('lines');

        $this->assertSame('product', $quote[0]['kind']);
        $this->assertSame('service', $quote[1]['kind']);
        $this->assertSame(5000, $quote[1]['unit_price']);
        $this->assertSame(2600, $quote[1]['components'][0]['unit_price']);
        $this->assertSame(0, $quote[2]['components'][0]['unit_price']);

        // Lo que la caja suma con la cotizacion...
        $shown = 2 * 5000
            + $quote[1]['unit_price'] + (int) round(1.5 * $quote[1]['components'][0]['unit_price']) + 3 * $quote[1]['components'][1]['unit_price']
            + $quote[2]['unit_price'];

        // ...es lo que cobra el servidor.
        $this->sell($items)->assertSessionHasNoErrors();
        $this->assertSame($shown, $this->lastSale()->total);
    }

    public function test_un_servicio_que_no_se_puede_cobrar_trae_su_motivo(): void
    {
        $this->chocolate->update(['is_active' => false]);

        $quote = $this->actingAs($this->cashier)->withSession($this->withActiveStore())
            ->postJson(route('pos.quote'), ['items' => [['service_id' => $this->ancheta->id, 'quantity' => 1]]])
            ->assertOk()
            ->json('lines');

        $this->assertStringContainsString('Chocolates', $quote[0]['error']);
    }
}
