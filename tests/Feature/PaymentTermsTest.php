<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\PaymentTerm;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\Supplier;
use App\Models\User;
use App\Services\PaymentTermService;
use App\Services\RegisterSessionService;
use App\Services\SaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\TestCase;

/**
 * Plazos de pago: la lista de la empresa, el credito de clientes y las compras.
 */
class PaymentTermsTest extends TestCase
{
    use CreatesCompanyEnvironment, RefreshDatabase;

    protected User $admin;

    protected PaymentTermService $terms;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEnvironment();
        $this->terms = app(PaymentTermService::class);
        $this->terms->seedDefaults($this->company);
        $this->admin = $this->makeUser('admin');
    }

    protected function term(string $name): PaymentTerm
    {
        return PaymentTerm::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->where('name', $name)->firstOrFail();
    }

    public function test_la_pantalla_lista_los_plazos_de_la_empresa(): void
    {
        $this->actingAs($this->admin)->withSession($this->withActiveStore())
            ->get(route('payment_terms.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('PaymentTerms/Index')
                ->count('terms', 4)
                ->where('terms.0.name', 'Contado')
                ->where('terms.0.is_default_customers', true)
                ->where('terms.3.is_default_suppliers', true));
    }

    public function test_crear_un_plazo_y_volverlo_defecto_quita_el_anterior(): void
    {
        $this->actingAs($this->admin)->withSession($this->withActiveStore())
            ->post(route('payment_terms.store'), ['name' => '45 días', 'days' => 45, 'is_default_suppliers' => true])
            ->assertSessionHasNoErrors();

        $this->assertTrue($this->term('45 días')->is_default_suppliers);
        $this->assertFalse($this->term('30 días')->is_default_suppliers);
        $this->assertSame(1, PaymentTerm::query()->withoutGlobalScopes()->where('is_default_suppliers', true)->count());

        $this->actingAs($this->admin)->withSession($this->withActiveStore())
            ->post(route('payment_terms.store'), ['name' => '45 días', 'days' => 45])
            ->assertSessionHasErrors('name');
    }

    public function test_el_plazo_por_defecto_no_se_desactiva(): void
    {
        $this->actingAs($this->admin)->withSession($this->withActiveStore())
            ->post(route('payment_terms.toggle', $this->term('Contado')))
            ->assertSessionHas('error');

        $this->assertTrue($this->term('Contado')->is_active);

        $this->actingAs($this->admin)->withSession($this->withActiveStore())
            ->post(route('payment_terms.toggle', $this->term('8 días')))
            ->assertSessionHas('success');

        $this->assertFalse($this->term('8 días')->is_active);
    }

    public function test_el_credito_del_cliente_se_elige_de_la_lista_y_copia_los_dias(): void
    {
        $customer = $this->makeCustomer(['credit_enabled' => true, 'credit_limit' => 100000]);

        $this->actingAs($this->admin)->withSession($this->withActiveStore())
            ->post(route('credit.limit', $customer), ['credit_limit' => 300000, 'payment_term_id' => $this->term('15 días')->id, 'credit_enabled' => true])
            ->assertSessionHasNoErrors();

        $customer->refresh();
        $this->assertSame($this->term('15 días')->id, $customer->payment_term_id);
        $this->assertSame(15, $customer->credit_term_days);

        // Cambiar los dias del plazo mueve a sus clientes.
        $this->actingAs($this->admin)->withSession($this->withActiveStore())
            ->put(route('payment_terms.update', $this->term('15 días')), ['name' => '15 días', 'days' => 20])
            ->assertSessionHasNoErrors();

        $this->assertSame(20, $customer->fresh()->credit_term_days);
    }

    public function test_un_plazo_de_otra_empresa_no_se_asigna(): void
    {
        $other = $this->makeOtherCompany();
        $this->terms->seedDefaults($other['company']);
        $foreign = PaymentTerm::query()->withoutGlobalScopes()->where('company_id', $other['company']->id)->firstOrFail();
        $customer = $this->makeCustomer(['credit_enabled' => true]);

        $this->actingAs($this->admin)->withSession($this->withActiveStore())
            ->post(route('credit.limit', $customer), ['credit_limit' => 300000, 'payment_term_id' => $foreign->id])
            ->assertSessionHasErrors('payment_term_id');

        $this->assertNull($customer->fresh()->payment_term_id);
    }

    public function test_crear_un_cliente_con_credito_pide_plazo(): void
    {
        $payload = ['document_type' => 'CC', 'document_number' => '43112233', 'name' => 'Luz Marina', 'credit_enabled' => true, 'credit_limit' => 200000];

        $this->actingAs($this->admin)->withSession($this->withActiveStore())
            ->post(route('customers.store'), $payload)
            ->assertSessionHasErrors('payment_term_id');

        $this->actingAs($this->admin)->withSession($this->withActiveStore())
            ->post(route('customers.store'), $payload + ['payment_term_id' => $this->term('8 días')->id])
            ->assertSessionHasNoErrors();

        $customer = Customer::query()->withoutGlobalScopes()->where('document_number', '43112233')->sole();
        $this->assertSame(8, $customer->credit_term_days);
    }

    public function test_la_venta_a_credito_vence_segun_el_plazo_y_la_mora_bloquea_como_siempre(): void
    {
        $customer = $this->makeCustomer(['credit_enabled' => true, 'credit_limit' => 500000]);
        $this->actingAs($this->admin)->withSession($this->withActiveStore())
            ->post(route('credit.limit', $customer), ['credit_limit' => 500000, 'payment_term_id' => $this->term('15 días')->id]);

        $product = $this->makeProduct(['price' => 20000]);
        $session = app(RegisterSessionService::class)->open($this->register, $this->admin, 100000);

        Carbon::setTestNow(Carbon::parse('2026-10-01 10:00:00'));
        app(SaleService::class)->create([
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'payments' => [['method' => SalePayment::METHOD_CREDIT, 'amount' => 20000]],
            'customer_id' => $customer->id,
        ], $this->admin, $this->store, $session);

        $sale = Sale::query()->withoutGlobalScopes()->sole();
        $this->assertSame('2026-10-16', $sale->due_date->toDateString());

        Carbon::setTestNow(Carbon::parse('2026-11-20 06:00:00'));
        $this->artisan('credit:block-overdue')->assertSuccessful();
        $this->assertTrue((bool) $customer->fresh()->credit_blocked);
        Carbon::setTestNow();
    }

    public function test_el_proveedor_tiene_su_plazo_y_la_orden_lo_toma(): void
    {
        $this->actingAs($this->admin)->withSession($this->withActiveStore())
            ->post(route('payment_terms.store'), ['name' => '45 días', 'days' => 45]);

        $this->actingAs($this->admin)->withSession($this->withActiveStore())
            ->post(route('suppliers.store'), ['name' => 'Distribuidora El Trigal', 'lead_time_days' => 2, 'payment_term_id' => $this->term('45 días')->id, 'is_active' => true])
            ->assertSessionHasNoErrors();

        $supplier = Supplier::query()->withoutGlobalScopes()->where('name', 'Distribuidora El Trigal')->sole();
        $this->assertSame($this->term('45 días')->id, $supplier->payment_term_id);

        $product = $this->makeProduct(['supplier_id' => $supplier->id]);

        $this->actingAs($this->admin)->withSession($this->withActiveStore())
            ->post(route('purchases.store'), [
                'supplier_id' => $supplier->id,
                'status' => PurchaseOrder::STATUS_SENT,
                'items' => [['product_id' => $product->id, 'quantity' => 10, 'unit_cost' => 3600]],
            ])
            ->assertSessionHasNoErrors();

        $order = PurchaseOrder::query()->withoutGlobalScopes()->sole();
        $this->assertSame($this->term('45 días')->id, $order->payment_term_id);
        $this->assertNull($order->due_at);

        // Recibida en parte no corre el plazo; completa, si.
        $item = PurchaseOrderItem::query()->where('purchase_order_id', $order->id)->sole();
        Carbon::setTestNow(Carbon::parse('2026-10-01 11:00:00'));

        $this->actingAs($this->admin)->withSession($this->withActiveStore())
            ->post(route('purchases.receive', $order), ['items' => [['id' => $item->id, 'received_quantity' => 4, 'unit_cost' => 3600]]])
            ->assertSessionHasNoErrors();
        $this->assertNull($order->fresh()->due_at);

        $this->actingAs($this->admin)->withSession($this->withActiveStore())
            ->post(route('purchases.receive', $order), ['items' => [['id' => $item->id, 'received_quantity' => 6, 'unit_cost' => 3600]]])
            ->assertSessionHasNoErrors();

        $this->assertSame('2026-11-15', $order->fresh()->due_at->toDateString());

        $this->actingAs($this->admin)->withSession($this->withActiveStore())
            ->get(route('purchases.index'))
            ->assertInertia(fn (Assert $page) => $page->where('orders.data.0.payment_term', '45 días')->has('orders.data.0.due_at'));

        // Cambiar el plazo despues recalcula el vencimiento.
        $this->actingAs($this->admin)->withSession($this->withActiveStore())
            ->patch(route('purchases.term', $order), ['payment_term_id' => $this->term('15 días')->id])
            ->assertSessionHasNoErrors();

        $this->assertSame('2026-10-16', $order->fresh()->due_at->toDateString());
        Carbon::setTestNow();
    }

    public function test_sin_plazo_del_proveedor_la_orden_toma_el_de_proveedores_por_defecto(): void
    {
        $supplier = Supplier::query()->withoutGlobalScopes()->create(['company_id' => $this->company->id, 'name' => 'Lácteos del Norte', 'lead_time_days' => 1, 'is_active' => true]);
        $product = $this->makeProduct(['supplier_id' => $supplier->id]);

        $this->actingAs($this->admin)->withSession($this->withActiveStore())
            ->post(route('purchases.store'), [
                'supplier_id' => $supplier->id,
                'status' => PurchaseOrder::STATUS_DRAFT,
                'items' => [['product_id' => $product->id, 'quantity' => 5, 'unit_cost' => 2000]],
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame($this->term('30 días')->id, PurchaseOrder::query()->withoutGlobalScopes()->sole()->payment_term_id);
    }
}
