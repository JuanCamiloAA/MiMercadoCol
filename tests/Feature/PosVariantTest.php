<?php

namespace Tests\Feature;

use App\Models\ProductStock;
use App\Models\SalePayment;
use App\Models\User;
use App\Services\RegisterSessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsVariants;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\TestCase;

/**
 * En caja cada subarticulo es un articulo: se escanea su codigo, sale con su nombre completo y su
 * distintivo, y solo baja su existencia. Buscar por el principal trae todos juntos y en orden; el
 * codigo de la familia no cobra nada, ofrece elegir.
 */
class PosVariantTest extends TestCase
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

    public function test_buscar_por_el_principal_trae_todos_en_orden_y_con_su_distintivo(): void
    {
        $this->makeFamily('Aceite de girasol', 'Volumen', null, [
            ['2 L', null, 27500], ['500 ml', null, 7900], ['900 ml', null, 13900],
        ], ['code' => 'ACE-GIR'], $this->cashier);
        $this->makeProduct(['name' => 'Aceite de oliva']);

        $products = $this->actingAs($this->cashier)->withSession($this->withActiveStore())
            ->getJson(route('pos.products', ['search' => 'Aceite de girasol']))
            ->assertOk()
            ->assertJsonPath('family', null)
            ->json('products');

        $this->assertSame(['500 ml', '900 ml', '2 L'], array_column($products, 'variant_label'));
        $this->assertSame('Aceite de girasol · 500 ml', $products[0]['name']);
    }

    public function test_el_codigo_de_la_familia_ofrece_elegir_y_no_cobra_nada(): void
    {
        $this->makeFamily('Aceite de girasol', 'Volumen', null, [['500 ml', null, 7900], ['900 ml', null, 13900]], ['code' => 'ACE-GIR'], $this->cashier);

        $this->actingAs($this->cashier)->withSession($this->withActiveStore())
            ->getJson(route('pos.products', ['search' => 'ACE-GIR']))
            ->assertOk()
            ->assertJsonPath('family.name', 'Aceite de girasol')
            ->assertJsonCount(2, 'family.variants')
            ->assertJsonPath('family.variants.1.variant_label', '900 ml');
    }

    public function test_se_cobra_el_subarticulo_y_solo_baja_el_suyo(): void
    {
        $family = $this->makeFamily('Aceite de girasol', 'Volumen', null, [
            ['500 ml', null, 7900, ['barcode' => '7702001000011']], ['900 ml', null, 13900, ['barcode' => '7702001000028']],
        ], user: $this->cashier);
        [$small, $big] = $family->variants()->get()->all();

        $found = $this->actingAs($this->cashier)->withSession($this->withActiveStore())
            ->getJson(route('pos.products', ['search' => '7702001000028']))
            ->json('products');
        $this->assertSame([$big->id], array_column($found, 'id'));

        $session = app(RegisterSessionService::class)->open($this->register, $this->cashier, 100000);
        $this->actingAs($this->cashier)->withSession($this->withActiveStore())->post(route('pos.sales.store'), [
            'items' => [['product_id' => $big->id, 'quantity' => 2]],
            'payments' => [['method' => SalePayment::METHOD_CASH, 'amount' => 30000]],
            'register_session_id' => $session->id,
        ])->assertSessionHasNoErrors();

        $stock = fn ($product) => (float) ProductStock::query()->where('product_id', $product->id)->sum('quantity');
        $this->assertSame(48.0, $stock($big));
        $this->assertSame(50.0, $stock($small));
    }
}
