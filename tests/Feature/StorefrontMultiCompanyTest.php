<?php

namespace Tests\Feature;

use App\Models\Cart;
use App\Models\Category;
use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerBalance;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Store;
use App\Models\Storefront;
use App\Models\Warehouse;
use App\Support\StorefrontContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\TestCase;

/**
 * La tienda en linea con varias empresas: la empresa sale de la sede elegida, el cliente es de
 * una empresa y el carrito tambien.
 */
class StorefrontMultiCompanyTest extends TestCase
{
    use CreatesCompanyEnvironment, RefreshDatabase;

    protected array $other;

    protected Storefront $ours;

    protected Storefront $theirs;

    protected Product $rice;

    protected Product $beans;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEnvironment();
        $this->other = $this->makeOtherCompany();

        $this->ours = $this->publishStorefront();
        $this->theirs = $this->publishStorefront($this->other['store'], $this->makeSuperAdmin());

        $this->rice = $this->makeProduct(['name' => 'Arroz de La Canasta', 'price' => 5000, 'online_price' => 5000, 'is_online_visible' => true], 50);
        $this->beans = $this->productOf($this->other['company'], $this->other['store'], 'Frijol del Vecino', 7000);
    }

    protected function productOf(Company $company, Store $store, string $name, int $price): Product
    {
        $category = Category::query()->withoutGlobalScopes()->create(['company_id' => $company->id, 'name' => 'Granos', 'slug' => 'granos-'.$company->id, 'is_active' => true]);

        $product = Product::query()->withoutGlobalScopes()->create([
            'company_id' => $company->id, 'category_id' => $category->id, 'name' => $name, 'sku' => 'SKU-'.random_int(1000, 99999),
            'unit' => 'unit', 'cost' => 1000, 'price' => $price, 'online_price' => $price, 'tax_rate' => 0, 'is_active' => true, 'is_online_visible' => true,
        ]);

        ProductStock::query()->withoutGlobalScopes()->create([
            'company_id' => $company->id, 'product_id' => $product->id, 'store_id' => $store->id,
            'warehouse_id' => Warehouse::query()->withoutGlobalScopes()->where('store_id', $store->id)->value('id'),
            'quantity' => 40, 'reserved_quantity' => 0,
        ]);

        return $product;
    }

    protected function enter(Storefront $storefront): void
    {
        $company = Company::query()->findOrFail($storefront->company_id);

        $this->get(route('storefront.entry', [$company->slug, $storefront->slug]))->assertRedirect(route('storefront.home'));
    }

    protected function onlineCustomer(Company $company, string $document): Customer
    {
        $customer = Customer::query()->withoutGlobalScopes()->create([
            'company_id' => $company->id, 'document_type' => 'CC', 'document_number' => $document, 'name' => 'Luz Marina',
            'email' => "c{$document}@correo.co", 'address' => 'Calle 30 # 65-12', 'is_online_user' => true,
            'password' => Hash::make('Cliente123*'), 'credit_limit' => 0, 'credit_term_days' => 0, 'credit_enabled' => false, 'is_active' => true,
        ]);
        CustomerBalance::query()->create(['customer_id' => $customer->id, 'balance' => 0, 'overdue_balance' => 0]);

        return $customer;
    }

    public function test_sin_sede_elegida_la_tienda_vuelve_al_buscador(): void
    {
        $this->get(route('storefront.home'))->assertRedirect('/#tiendas');
        $this->getJson(route('storefront.cart.index'))->assertStatus(409);
    }

    public function test_el_enlace_de_una_sede_muestra_el_catalogo_de_su_empresa(): void
    {
        $this->enter($this->theirs);

        $this->get(route('storefront.search'))
            ->assertOk()
            ->assertSee('Frijol del Vecino', false)
            ->assertDontSee('Arroz de La Canasta', false)
            ->assertInertia(fn (Assert $page) => $page
                ->where('storefrontCompany.name', 'Mercado El Vecino')
                ->where('storefrontCompany.store.name', 'Envigado'));

        $this->enter($this->ours);

        $this->get(route('storefront.search'))->assertSee('Arroz de La Canasta', false)->assertDontSee('Frijol del Vecino', false);
    }

    public function test_el_selector_interno_solo_ofrece_sedes_de_la_misma_empresa(): void
    {
        $this->enter($this->ours);

        $this->assertSame([$this->store->id], StorefrontContext::stores()->pluck('id')->all());
    }

    public function test_cambiar_de_empresa_cierra_la_sesion_del_cliente(): void
    {
        $customer = $this->onlineCustomer($this->company, '43112233');
        $this->enter($this->ours);
        $this->actingAs($customer, 'customer');

        $this->enter($this->theirs);

        $this->assertGuest('customer');
    }

    public function test_cada_empresa_tiene_su_carrito_y_al_volver_sigue_ahi(): void
    {
        $this->enter($this->ours);
        $this->post(route('storefront.cart.store'), ['product_id' => $this->rice->id, 'quantity' => 2])->assertSessionHasNoErrors();

        $this->enter($this->theirs);
        $this->get(route('storefront.cart.index'))->assertOk()->assertDontSee('Arroz de La Canasta', false);
        $this->post(route('storefront.cart.store'), ['product_id' => $this->beans->id, 'quantity' => 1])->assertSessionHasNoErrors();

        // Un articulo de la otra empresa no entra en este carrito.
        $this->post(route('storefront.cart.store'), ['product_id' => $this->rice->id, 'quantity' => 1]);

        $this->enter($this->ours);
        $this->get(route('storefront.cart.index'))->assertSee('Arroz de La Canasta', false)->assertDontSee('Frijol del Vecino', false);

        $carts = Cart::query()->withoutGlobalScopes()->with('items')->get()->keyBy('company_id');
        $this->assertCount(2, $carts);
        $this->assertSame([$this->rice->id], $carts[$this->company->id]->items->pluck('product_id')->all());
        $this->assertSame([$this->beans->id], $carts[$this->other['company']->id]->items->pluck('product_id')->all());
    }

    public function test_el_pago_exige_un_cliente_de_la_empresa_de_la_tienda(): void
    {
        $customer = $this->onlineCustomer($this->company, '43112233');
        $this->enter($this->ours);
        $this->actingAs($customer, 'customer');
        $this->post(route('storefront.cart.store'), ['product_id' => $this->rice->id, 'quantity' => 1]);
        $this->get(route('storefront.checkout.create'))->assertOk();

        // La sede cambio por fuera (otra pestaña): el cliente de A no paga en B.
        $this->withSession([StorefrontContext::SESSION_KEY => $this->other['store']->id]);
        $this->get(route('storefront.checkout.create'))->assertRedirect(route('storefront.login'));
        $this->assertGuest('customer');
    }

    public function test_el_ingreso_busca_el_cliente_en_la_empresa_de_la_sede(): void
    {
        $this->onlineCustomer($this->company, '43112233');
        $this->enter($this->theirs);

        $this->post(route('storefront.login.store'), ['document_number' => '43112233', 'password' => 'Cliente123*'])
            ->assertSessionHasErrors('document_number');
        $this->assertGuest('customer');

        $this->enter($this->ours);
        $this->post(route('storefront.login.store'), ['document_number' => '43112233', 'password' => 'Cliente123*'])
            ->assertSessionHasNoErrors();
        $this->assertAuthenticated('customer');
    }

    public function test_el_pedido_cae_en_la_sede_elegida(): void
    {
        $customer = $this->onlineCustomer($this->other['company'], '71998877');
        $this->enter($this->theirs);
        $this->actingAs($customer, 'customer');
        $this->post(route('storefront.cart.store'), ['product_id' => $this->beans->id, 'quantity' => 2])->assertSessionHasNoErrors();

        $this->post(route('storefront.checkout.store'), [
            'delivery_address' => 'Calle 30 # 65-12',
            'payment_method' => Order::PAYMENT_CASH_ON_DELIVERY,
        ])->assertSessionHasNoErrors();

        $order = Order::query()->withoutGlobalScopes()->sole();
        $this->assertSame($this->other['company']->id, $order->company_id);
        $this->assertSame($this->other['store']->id, $order->store_id);
        $this->assertSame($customer->id, $order->customer_id);
    }

    public function test_una_empresa_suspendida_no_abre_su_tienda_por_el_enlace(): void
    {
        ['plan' => $plan, 'cycle' => $cycle] = $this->makePlan([], ['online_store']);
        $this->other['company']->forceFill(['membership_plan_id' => $plan->id, 'billing_cycle_id' => $cycle->id, 'membership_status' => Company::MEMBERSHIP_SUSPENDED])->save();

        $company = $this->other['company']->fresh();

        $this->get(route('storefront.entry', [$company->slug, $this->theirs->slug]))
            ->assertStatus(503)
            ->assertInertia(fn (Assert $page) => $page->component('Storefront/Unavailable')->where('companyName', 'Mercado El Vecino'));

        $this->get(route('storefront.entry', ['no-existe', 'tampoco']))->assertNotFound();
    }
}
