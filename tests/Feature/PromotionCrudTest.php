<?php

namespace Tests\Feature;

use App\Models\Promotion;
use App\Models\PromotionTarget;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\TestCase;

/**
 * Pantalla de promociones.
 *
 * Cada tipo pide lo suyo, y lo que se cuida aqui es que no se guarde una promocion que no
 * hace nada: un 3x2 sin cantidades o un combo con un solo articulo.
 */
class PromotionCrudTest extends TestCase
{
    use CreatesCompanyEnvironment, RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEnvironment();
        $this->admin = $this->makeUser('admin');
    }

    public function test_crea_un_precio_especial_con_su_articulo(): void
    {
        $product = $this->makeProduct(['price' => 5100]);

        $this->admin()->post(route('promotions.store'), [
            'name' => 'Semana de la despensa',
            'type' => Promotion::TYPE_SPECIAL_PRICE,
            'applies_online' => true,
            'applies_pos' => true,
            'is_active' => true,
            'targets' => [
                ['target_type' => PromotionTarget::TYPE_PRODUCT, 'target_id' => $product->id, 'value' => 4200],
            ],
        ])->assertRedirect(route('promotions.index'));

        $promotion = Promotion::query()->withoutGlobalScopes()->firstOrFail();

        $this->assertSame('Semana de la despensa', $promotion->name);
        $this->assertTrue($promotion->applies_pos);
        $this->assertSame(4200, $promotion->targets->first()->value);
    }

    public function test_un_precio_especial_sin_valor_se_rechaza(): void
    {
        $product = $this->makeProduct();

        $this->admin()->post(route('promotions.store'), [
            'name' => 'Sin precio',
            'type' => Promotion::TYPE_SPECIAL_PRICE,
            'applies_online' => true,
            'targets' => [['target_type' => PromotionTarget::TYPE_PRODUCT, 'target_id' => $product->id]],
        ])->assertSessionHasErrors('targets.0.value');

        $this->assertDatabaseCount('promotions', 0);
    }

    public function test_el_tres_por_dos_no_admite_pagar_mas_de_lo_que_lleva(): void
    {
        $product = $this->makeProduct();

        $this->admin()->post(route('promotions.store'), [
            'name' => 'Mal armada',
            'type' => Promotion::TYPE_N_FOR_M,
            'buy' => 2,
            'pay' => 3,
            'applies_online' => true,
            'targets' => [['target_type' => PromotionTarget::TYPE_PRODUCT, 'target_id' => $product->id]],
        ])->assertSessionHasErrors('pay');
    }

    public function test_un_combo_necesita_al_menos_dos_articulos(): void
    {
        $product = $this->makeProduct();

        $this->admin()->post(route('promotions.store'), [
            'name' => 'Combo flojo',
            'type' => Promotion::TYPE_BUNDLE,
            'bundle_price' => 18000,
            'applies_online' => true,
            'targets' => [['target_type' => PromotionTarget::TYPE_PRODUCT, 'target_id' => $product->id]],
        ])->assertSessionHasErrors('targets');
    }

    public function test_una_promocion_sin_canal_no_se_guarda(): void
    {
        $product = $this->makeProduct();

        $this->admin()->post(route('promotions.store'), [
            'name' => 'Sin canal',
            'type' => Promotion::TYPE_PERCENT_OFF,
            'percent' => 10,
            'applies_online' => false,
            'applies_pos' => false,
            'targets' => [['target_type' => PromotionTarget::TYPE_PRODUCT, 'target_id' => $product->id]],
        ])->assertSessionHasErrors('applies_online');
    }

    public function test_el_porcentaje_acepta_una_categoria_entera(): void
    {
        $this->admin()->post(route('promotions.store'), [
            'name' => 'Despensa al 20',
            'type' => Promotion::TYPE_PERCENT_OFF,
            'percent' => 20,
            'applies_online' => true,
            'is_active' => true,
            'targets' => [['target_type' => PromotionTarget::TYPE_CATEGORY, 'target_id' => $this->category->id]],
        ])->assertRedirect(route('promotions.index'));

        $this->assertSame(20.0, (float) Promotion::query()->withoutGlobalScopes()->first()->config['percent']);
    }

    public function test_apagar_una_promocion_la_saca_de_circulacion(): void
    {
        $product = $this->makeProduct(['price' => 5100]);

        $this->admin()->post(route('promotions.store'), [
            'name' => 'Oferta',
            'type' => Promotion::TYPE_SPECIAL_PRICE,
            'applies_online' => true,
            'is_active' => true,
            'targets' => [['target_type' => PromotionTarget::TYPE_PRODUCT, 'target_id' => $product->id, 'value' => 4200]],
        ]);

        $promotion = Promotion::query()->withoutGlobalScopes()->firstOrFail();

        $this->admin()->patch(route('promotions.toggle', $promotion))->assertRedirect();

        $this->assertFalse($promotion->fresh()->is_active);
        $this->assertSame(0, Promotion::query()->withoutGlobalScopes()->running()->count());
    }

    public function test_el_listado_abre_con_sus_cifras(): void
    {
        $this->admin()->get(route('promotions.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Promotions/Index')
                ->has('types', 4)
                ->has('metrics'));
    }

    public function test_un_cajero_no_entra_a_promociones(): void
    {
        $cashier = $this->makeUser('cajero');

        $this->actingAs($cashier)
            ->withSession($this->withActiveStore())
            ->get(route('promotions.index'))
            ->assertStatus(403);
    }

    /** Todas las llamadas van como administrador y con la sede activa puesta. */
    protected function admin(): self
    {
        $this->actingAs($this->admin);
        $this->withSession($this->withActiveStore());

        return $this;
    }
}
