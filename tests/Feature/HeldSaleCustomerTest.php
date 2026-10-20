<?php

namespace Tests\Feature;

use App\Models\StoreCreditMovement;
use App\Services\StoreCreditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\Concerns\SellsServicesAtPos;
use Tests\TestCase;

/**
 * Un tiquete suspendido guarda su cliente: al retomarlo, la caja lo deja puesto con sus datos de
 * ahora (cupo, saldo, saldo a favor) y no hay que volver a pedirlos.
 */
class HeldSaleCustomerTest extends TestCase
{
    use CreatesCompanyEnvironment, RefreshDatabase, SellsServicesAtPos;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEnvironment();
        $this->seedServiceScenario();
    }

    public function test_el_cliente_vuelve_con_el_tiquete_y_con_sus_datos_de_ahora(): void
    {
        $customer = $this->makeCustomer(['name' => 'Luz Marina Agudelo', 'phone' => '3001234567', 'credit_enabled' => true, 'credit_limit' => 300000, 'credit_term_days' => 30]);

        $this->hold('Señora Luz', $customer->id)->assertOk();

        // Mientras estaba suspendido le abonaron saldo a favor: al retomarlo se ve.
        app(StoreCreditService::class)->grant($customer, 12000, StoreCreditMovement::TYPE_RETURN_CREDIT, [], $this->cashier, 'Devolución de prueba');

        $held = $this->getJson(route('pos.hold.index'))->assertOk()->json('held.0');

        $this->assertSame('Luz Marina Agudelo', $held['customer_name']);
        $this->assertSame($customer->id, $held['customer']['id']);
        $this->assertSame($customer->document_number, $held['customer']['document_number']);
        $this->assertSame('3001234567', $held['customer']['phone']);
        $this->assertTrue($held['customer']['credit_enabled']);
        $this->assertSame(300000, $held['customer']['available_credit']);
        $this->assertSame(12000, $held['customer']['store_credit']);

        // Es lo mismo que entrega el buscador de clientes de la caja.
        $searched = $this->getJson(route('pos.customers', ['search' => $customer->document_number]))->json('customers.0');
        $this->assertSame($searched, $held['customer']);
    }

    public function test_sin_cliente_sigue_siendo_consumidor_final(): void
    {
        $this->hold('Mostrador', null)->assertOk();

        $held = $this->getJson(route('pos.hold.index'))->json('held.0');

        $this->assertNull($held['customer']);
        $this->assertNull($held['customer_name']);
    }

    public function test_un_cliente_de_otra_empresa_no_se_guarda(): void
    {
        ['company' => $other] = $this->makeOtherCompany();
        $foreign = $this->makeCustomer(['company_id' => $other->id, 'name' => 'De otra empresa']);

        $this->hold('Ajeno', $foreign->id)->assertStatus(422)->assertJsonValidationErrors('customer_id');
        $this->assertSame([], $this->getJson(route('pos.hold.index'))->json('held'));
    }

    public function test_un_cliente_desactivado_mientras_tanto_no_se_vuelve_a_poner(): void
    {
        $customer = $this->makeCustomer(['name' => 'Cliente que se retiró']);
        $this->hold('Retirado', $customer->id)->assertOk();

        $customer->forceFill(['is_active' => false])->save();

        $held = $this->getJson(route('pos.hold.index'))->json('held.0');

        // La caja avisa con el nombre y deja elegir otro.
        $this->assertNull($held['customer']);
        $this->assertSame('Cliente que se retiró', $held['customer_name']);
    }

    protected function hold(string $label, ?int $customerId): \Illuminate\Testing\TestResponse
    {
        $line = ['product_id' => $this->galletas->id, 'name' => 'Galletas', 'quantity' => 1, 'unit_price' => 5000, 'discount' => 0];

        return $this->actingAs($this->cashier)->withSession($this->withActiveStore())
            ->postJson(route('pos.hold.store'), ['label' => $label, 'total' => 5000, 'customer_id' => $customerId, 'payload' => ['items' => [$line], 'customer_id' => $customerId]]);
    }
}
