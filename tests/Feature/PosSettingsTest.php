<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\RegisterSessionService;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\TestCase;

class PosSettingsTest extends TestCase
{
    use CreatesCompanyEnvironment, RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEnvironment();
        $this->admin = $this->makeUser('admin');
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, array<string, mixed>>
     */
    protected function pos(array $overrides = []): array
    {
        return ['pos' => array_merge([
            'default_theme' => 'light',
            'allow_theme_toggle' => false,
            'ui_scale' => 115,
            'ticket_side' => 'left',
            'catalog_view' => 'list',
            'tile_size' => 'lg',
            'total_size' => 'large',
            'rail' => ['opening', 'sale', 'closing'],
            'shortcuts' => [
                'scan' => 'F1',
                'customer' => 'F2',
                'discount' => 'F7',
                'hold' => 'F4',
                'resume' => 'F5',
                'recent' => 'F6',
                'pay' => 'F3',
                'pay_alt' => 'F8',
            ],
            'sounds' => true,
        ], $overrides)];
    }

    public function test_guarda_la_caja_y_llega_a_la_pantalla_de_venta(): void
    {
        $this->actingAs($this->admin)
            ->put(route('settings.pos.update'), $this->pos())
            ->assertSessionHasNoErrors();

        app(RegisterSessionService::class)->open($this->register, $this->admin, 100000);

        $this->actingAs($this->admin)
            ->withSession($this->withActiveStore())
            ->get(route('pos.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Pos/Sale')
                ->where('posLayout.ticket_side', 'left')
                ->where('posLayout.catalog_view', 'list')
                ->where('posLayout.total_size', 'large')
                // F3 reasignado a cobrar: el boton lo muestra porque la tecla viaja en la prop.
                ->where('posLayout.shortcuts.pay', 'F3')
                ->where('posLayout.sounds', true)
                ->where('posRail', ['opening', 'sale', 'closing'])
                ->where('brand.surfaces.pos.default_theme', 'light')
                ->where('brand.surfaces.pos.allow_theme_toggle', false));
    }

    public function test_la_caja_se_pinta_con_su_tema_y_su_escala(): void
    {
        $this->actingAs($this->admin)->put(route('settings.pos.update'), $this->pos());
        app(RegisterSessionService::class)->open($this->register, $this->admin, 100000);

        $html = $this->actingAs($this->admin)
            ->withSession($this->withActiveStore())
            ->get(route('pos.index'))
            ->getContent();

        $this->assertStringContainsString('data-surface="pos"', $html);
        $this->assertStringContainsString('data-theme-default="light"', $html);
        $this->assertStringContainsString("html[data-surface='pos']{--ui-scale:1.15}", $html);
    }

    public function test_de_fabrica_la_caja_trae_los_atajos_de_siempre(): void
    {
        app(RegisterSessionService::class)->open($this->register, $this->admin, 100000);

        $this->actingAs($this->admin)
            ->withSession($this->withActiveStore())
            ->get(route('pos.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('posLayout.shortcuts', SettingsService::DEFAULTS['pos.shortcuts'])
                ->where('posLayout.ticket_side', 'right')
                ->where('posLayout.catalog_view', 'grid')
                ->where('posRail', ['sale', 'opening', 'closing']));
    }

    public function test_un_atajo_repetido_se_rechaza_con_el_mensaje(): void
    {
        $shortcuts = $this->pos()['pos']['shortcuts'];
        $shortcuts['resume'] = 'F4';

        $this->actingAs($this->admin)
            ->put(route('settings.pos.update'), $this->pos(['shortcuts' => $shortcuts]))
            ->assertSessionHasErrors(['pos.shortcuts' => 'La tecla F4 está en dos acciones: suspender venta y retomar venta.']);
    }

    public function test_una_letra_como_atajo_se_rechaza(): void
    {
        $shortcuts = $this->pos()['pos']['shortcuts'];
        $shortcuts['pay'] = 'C';

        $this->actingAs($this->admin)
            ->put(route('settings.pos.update'), $this->pos(['shortcuts' => $shortcuts]))
            ->assertSessionHasErrors('pos.shortcuts');
    }

    public function test_falta_un_atajo_se_rechaza(): void
    {
        $shortcuts = $this->pos()['pos']['shortcuts'];
        unset($shortcuts['pay_alt']);

        $this->actingAs($this->admin)
            ->put(route('settings.pos.update'), $this->pos(['shortcuts' => $shortcuts]))
            ->assertSessionHasErrors('pos.shortcuts');
    }

    public function test_el_riel_incompleto_se_rechaza(): void
    {
        $this->actingAs($this->admin)
            ->put(route('settings.pos.update'), $this->pos(['rail' => ['sale', 'closing']]))
            ->assertSessionHasErrors(['pos.rail' => 'El riel tiene que llevar Vender, Apertura y Cierre, cada uno una vez.']);

        $this->actingAs($this->admin)
            ->put(route('settings.pos.update'), $this->pos(['rail' => ['sale', 'sale', 'closing']]))
            ->assertSessionHasErrors('pos.rail');
    }

    public function test_las_reglas_de_caja_tienen_su_propio_permiso(): void
    {
        $supervisor = $this->makeUser('supervisor');

        // El supervisor ordena la caja pero no cambia reglas de plata.
        $this->actingAs($supervisor)->put(route('settings.pos.update'), $this->pos())->assertSessionHasNoErrors();
        $this->actingAs($supervisor)
            ->put(route('settings.pos_rules.update'), ['pos' => ['allow_discount_percent' => 50, 'cash_rounding' => 0]])
            ->assertForbidden();

        $this->actingAs($this->admin)
            ->put(route('settings.pos_rules.update'), ['pos' => ['allow_discount_percent' => 15, 'cash_rounding' => 50]])
            ->assertSessionHasNoErrors();

        $this->assertSame(15, app(SettingsService::class)->get($this->company->id, 'pos.allow_discount_percent'));
        $this->assertSame(50, app(SettingsService::class)->get($this->company->id, 'pos.cash_rounding'));
    }

    public function test_el_redondeo_fuera_de_la_lista_se_rechaza(): void
    {
        $this->actingAs($this->admin)
            ->put(route('settings.pos_rules.update'), ['pos' => ['allow_discount_percent' => 10, 'cash_rounding' => 30]])
            ->assertSessionHasErrors(['pos.cash_rounding' => 'El redondeo solo puede ser 0, 50 o 100 pesos.']);
    }

    public function test_el_credito_se_guarda(): void
    {
        $this->actingAs($this->admin)
            ->put(route('settings.credit.update'), ['credit' => ['block_on_overdue_days' => 30]])
            ->assertSessionHasNoErrors();

        $this->assertSame(30, app(SettingsService::class)->get($this->company->id, 'credit.block_on_overdue_days'));
    }
}
