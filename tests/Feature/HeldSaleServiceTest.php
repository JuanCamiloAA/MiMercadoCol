<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\Concerns\SellsServicesAtPos;
use Tests\TestCase;

/**
 * Tiquetes suspendidos con servicios: se guardan tal cual y se recuperan igual. Los de antes,
 * sin `kind`, siguen abriendo (la caja los trata como articulos).
 */
class HeldSaleServiceTest extends TestCase
{
    use CreatesCompanyEnvironment, RefreshDatabase, SellsServicesAtPos;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEnvironment();
        $this->seedServiceScenario();
    }

    public function test_un_servicio_ajustado_se_suspende_y_se_recupera_igual(): void
    {
        $line = [
            'kind' => 'service',
            'key' => 's1-abc',
            'service_id' => $this->ancheta->id,
            'pricing_mode' => 'bundled',
            'name' => 'Armado de ancheta',
            'quantity' => 1,
            'unit_price' => 60000,
            'discount' => 0,
            'components' => [
                ['product_id' => $this->vino->id, 'name' => 'Vino tinto', 'master_quantity' => 1, 'quantity' => 1, 'unit_price' => 0, 'discount' => 0],
                ['product_id' => $this->galletas->id, 'name' => 'Galletas', 'master_quantity' => 2, 'quantity' => 1, 'unit_price' => 0, 'discount' => 0],
            ],
            'removed_components' => [['product_id' => $this->chocolate->id, 'name' => 'Chocolates', 'master_quantity' => 1]],
        ];

        $this->actingAs($this->cashier)->withSession($this->withActiveStore())
            ->postJson(route('pos.hold.store'), ['label' => 'Ancheta de Luz', 'total' => 60000, 'payload' => ['items' => [$line], 'customer_id' => null]])
            ->assertOk();

        $held = $this->getJson(route('pos.hold.index'))->assertOk()->json('held.0');

        $this->assertSame($line, $held['payload']['items'][0]);

        // Recuperado, se cobra con los mismos ajustes.
        $item = $held['payload']['items'][0];
        $this->sell([[
            'service_id' => $item['service_id'],
            'quantity' => $item['quantity'],
            'components' => array_map(fn ($component) => ['product_id' => $component['product_id'], 'quantity' => $component['quantity']], $item['components']),
        ]])->assertSessionHasNoErrors();

        $this->assertSame(60000, $this->lastSale()->total);
        $this->assertEqualsWithDelta(50, $this->stockOf($this->chocolate), 0.001);
    }

    public function test_un_tiquete_de_antes_sin_kind_sigue_abriendo(): void
    {
        $old = ['product_id' => $this->galletas->id, 'name' => 'Galletas', 'unit' => 'unit', 'sold_by_weight' => false, 'quantity' => 2, 'unit_price' => 5000, 'tax_rate' => 0, 'stock' => 50, 'allow_negative_stock' => false];

        $this->actingAs($this->cashier)->withSession($this->withActiveStore())
            ->postJson(route('pos.hold.store'), ['label' => 'Viejo', 'total' => 10000, 'payload' => ['items' => [$old], 'customer_id' => null]])
            ->assertOk();

        $held = $this->getJson(route('pos.hold.index'))->assertOk()->json('held.0.payload.items.0');
        $this->assertArrayNotHasKey('kind', $held);

        $this->sell([['product_id' => $held['product_id'], 'quantity' => $held['quantity'], 'unit_price' => $held['unit_price']]])->assertSessionHasNoErrors();
        $this->assertSame(10000, $this->lastSale()->total);
    }
}
