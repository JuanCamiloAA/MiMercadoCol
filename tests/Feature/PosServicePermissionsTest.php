<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\Concerns\SellsServicesAtPos;
use Tests\TestCase;

/**
 * Cada ajuste a los articulos de un servicio se autoriza en el servidor: con el permiso del
 * cajero o con el PIN de alguien que lo tenga.
 */
class PosServicePermissionsTest extends TestCase
{
    use CreatesCompanyEnvironment, RefreshDatabase, SellsServicesAtPos;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEnvironment();
        $this->seedServiceScenario();
    }

    public function test_cada_tipo_de_cambio_sin_permiso_se_rechaza(): void
    {
        $this->cashier->revokePermissionTo(['pos.sale.service_remove', 'pos.sale.service_reduce', 'pos.sale.service_increase']);
        $cashier = $this->cashier->fresh();

        $cases = [
            'quitar' => [['product_id' => $this->vino->id, 'quantity' => 1], ['product_id' => $this->galletas->id, 'quantity' => 2]],
            'bajar' => [['product_id' => $this->vino->id, 'quantity' => 1], ['product_id' => $this->galletas->id, 'quantity' => 1], ['product_id' => $this->chocolate->id, 'quantity' => 1]],
            'subir' => [['product_id' => $this->vino->id, 'quantity' => 2], ['product_id' => $this->galletas->id, 'quantity' => 2], ['product_id' => $this->chocolate->id, 'quantity' => 1]],
            'agregar' => [['product_id' => $this->vino->id, 'quantity' => 1], ['product_id' => $this->galletas->id, 'quantity' => 2], ['product_id' => $this->chocolate->id, 'quantity' => 1], ['product_id' => $this->banano->id, 'quantity' => 1]],
        ];

        foreach ($cases as $case => $components) {
            $this->sell([['service_id' => $this->ancheta->id, 'quantity' => 1, 'components' => $components]], user: $cashier)
                ->assertSessionHasErrors('service', "El caso «{$case}» debio pedir autorizacion.");
        }

        $this->assertEqualsWithDelta(50, $this->stockOf($this->vino), 0.001);
    }

    public function test_con_el_permiso_pasa(): void
    {
        // El cajero trae quitar, bajar y subir por defecto.
        $this->sell([['service_id' => $this->ancheta->id, 'quantity' => 1, 'components' => [
            ['product_id' => $this->vino->id, 'quantity' => 2],
            ['product_id' => $this->galletas->id, 'quantity' => 1],
        ]]])->assertSessionHasNoErrors();

        $this->assertNull($this->lastSale()->authorized_by_user_id);
    }

    public function test_agregar_con_el_pin_de_un_supervisor_que_lo_tiene_pasa_y_queda_quien_autorizo(): void
    {
        $supervisor = $this->supervisorWithPin('9876', ['pos.sale.service_add']);

        $components = [
            ['product_id' => $this->vino->id, 'quantity' => 1],
            ['product_id' => $this->galletas->id, 'quantity' => 2],
            ['product_id' => $this->chocolate->id, 'quantity' => 1],
            ['product_id' => $this->banano->id, 'quantity' => 2],
        ];

        $this->sell([['service_id' => $this->ancheta->id, 'quantity' => 1, 'components' => $components]])->assertSessionHasErrors('service');

        $this->authorize('service_add', '9876');
        $this->sell([['service_id' => $this->ancheta->id, 'quantity' => 1, 'components' => $components]])->assertSessionHasNoErrors();

        $sale = $this->lastSale();
        $this->assertSame($supervisor->id, $sale->authorized_by_user_id);
        $line = $sale->items->firstWhere('service_id', $this->ancheta->id);
        $this->assertSame($supervisor->id, $line->service_snapshot['authorized_by_user_id']);
        $this->assertSame('added', $sale->items->firstWhere('product_id', $this->banano->id)->component_change);
        $this->assertNull($sale->items->firstWhere('product_id', $this->banano->id)->master_quantity);
    }

    public function test_un_pin_sin_el_permiso_no_autoriza_agregar(): void
    {
        $this->supervisorWithPin('5555');

        $this->actingAs($this->cashier)->withSession($this->withActiveStore())
            ->postJson(route('pos.authorization'), ['action' => 'service_add', 'pin' => '5555'])
            ->assertStatus(422);
    }

    public function test_descuento_y_agregar_en_la_misma_venta_con_dos_autorizaciones(): void
    {
        $discounter = $this->supervisorWithPin('1111');
        $adder = $this->supervisorWithPin('2222', ['pos.sale.service_add']);

        $this->authorize('discount', '1111');
        $this->authorize('service_add', '2222');

        // 20 % de descuento (tope 10 %) y un banano de mas en la ancheta.
        $this->sell([['service_id' => $this->ancheta->id, 'quantity' => 1, 'discount' => 12000, 'components' => [
            ['product_id' => $this->vino->id, 'quantity' => 1],
            ['product_id' => $this->galletas->id, 'quantity' => 2],
            ['product_id' => $this->chocolate->id, 'quantity' => 1],
            ['product_id' => $this->banano->id, 'quantity' => 1],
        ]]])->assertSessionHasNoErrors();

        $sale = $this->lastSale();
        $this->assertSame(48000, $sale->total);
        // Queda el de la accion mas restrictiva: agregar.
        $this->assertSame($adder->id, $sale->authorized_by_user_id);
        $this->assertNotSame($discounter->id, $sale->authorized_by_user_id);
    }

    public function test_la_autorizacion_vale_para_una_sola_venta(): void
    {
        $this->supervisorWithPin('9876', ['pos.sale.service_add']);
        $components = [
            ['product_id' => $this->vino->id, 'quantity' => 1],
            ['product_id' => $this->galletas->id, 'quantity' => 2],
            ['product_id' => $this->chocolate->id, 'quantity' => 1],
            ['product_id' => $this->banano->id, 'quantity' => 1],
        ];

        $this->authorize('service_add', '9876');
        $this->sell([['service_id' => $this->ancheta->id, 'quantity' => 1, 'components' => $components]])->assertSessionHasNoErrors();
        $this->sell([['service_id' => $this->ancheta->id, 'quantity' => 1, 'components' => $components]])->assertSessionHasErrors('service');
    }

    /**
     * @param  list<string>  $extra
     */
    protected function supervisorWithPin(string $pin, array $extra = []): User
    {
        $supervisor = $this->makeUser('supervisor', ['email' => 'sup'.$pin.'@lacanasta.co']);
        $supervisor->forceFill(['pos_pin' => Hash::make($pin)])->save();

        if ($extra !== []) {
            $supervisor->givePermissionTo($extra);
        }

        return $supervisor->fresh();
    }

    protected function authorize(string $action, string $pin): void
    {
        $this->actingAs($this->cashier)->withSession($this->withActiveStore())
            ->postJson(route('pos.authorization'), ['action' => $action, 'pin' => $pin])
            ->assertOk()
            ->assertJson(['authorized' => true]);
    }
}
