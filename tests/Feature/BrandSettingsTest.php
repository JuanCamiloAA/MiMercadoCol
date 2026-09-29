<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\TestCase;

class BrandSettingsTest extends TestCase
{
    use CreatesCompanyEnvironment, RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEnvironment();
        // La tienda en linea atiende la sede que el cliente eligio.
        $this->shopAt();
        $this->admin = $this->makeUser('admin');
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, array<string, mixed>>
     */
    protected function brand(array $overrides = []): array
    {
        return ['brand' => array_merge([
            'display_name' => 'Canasta Express',
            'tagline' => 'Mercado de barrio',
            'accent' => '#E8B100',
            'accent_dark' => null,
        ], $overrides)];
    }

    public function test_guarda_la_marca_y_la_comparte_con_el_panel(): void
    {
        $this->actingAs($this->admin)
            ->put(route('settings.brand.update'), $this->brand())
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $settings = app(SettingsService::class);
        $this->assertSame('Canasta Express', $settings->get($this->company->id, 'brand.display_name'));
        $this->assertSame('#E8B100', $settings->get($this->company->id, 'brand.accent'));

        $this->actingAs($this->admin)
            ->withSession($this->withActiveStore())
            ->get(route('dashboard'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('brand.display_name', 'Canasta Express')
                ->where('brand.theme_color', '#E8B100')
                ->where('brand.css', fn (string $css) => str_contains($css, "[data-theme='light'][data-theme]{--accent:#806100")));
    }

    public function test_el_blade_inyecta_la_marca_despues_de_app_css(): void
    {
        $this->actingAs($this->admin)->put(route('settings.brand.update'), $this->brand());

        $html = $this->actingAs($this->admin)
            ->withSession($this->withActiveStore())
            ->get(route('dashboard'))
            ->getContent();

        $this->assertStringContainsString('<title inertia>Canasta Express</title>', $html);
        $this->assertStringContainsString('<meta name="theme-color" content="#E8B100">', $html);
        $this->assertMatchesRegularExpression('/<style id="brand-theme">[^<]*--accent:#806100/', $html);
        $this->assertStringContainsString('data-surface="panel"', $html);
    }

    public function test_un_color_mal_escrito_se_rechaza_en_espanol(): void
    {
        $this->actingAs($this->admin)
            ->put(route('settings.brand.update'), $this->brand(['accent' => 'amarillo']))
            ->assertSessionHasErrors(['brand.accent' => 'El color de la marca va en formato #RRGGBB.']);
    }

    public function test_un_oscuro_a_mano_que_no_se_lee_no_se_guarda(): void
    {
        $this->actingAs($this->admin)
            ->put(route('settings.brand.update'), $this->brand(['accent_dark' => '#1A1F22']))
            ->assertSessionHasErrors('brand.accent_dark');

        $this->assertNull(app(SettingsService::class)->get($this->company->id, 'brand.accent_dark'));
    }

    public function test_sin_permiso_de_marca_no_se_cambia(): void
    {
        $supervisor = $this->makeUser('supervisor');

        $this->assertFalse($supervisor->can('settings.index.brand'));

        $this->actingAs($supervisor)
            ->put(route('settings.brand.update'), $this->brand())
            ->assertForbidden();
    }

    public function test_una_pestana_no_cuela_claves_de_otra(): void
    {
        $this->actingAs($this->admin)
            ->put(route('settings.brand.update'), $this->brand() + ['pos' => ['cash_rounding' => 0]])
            ->assertSessionHasNoErrors();

        $this->assertSame(100, app(SettingsService::class)->get($this->company->id, 'pos.cash_rounding'));
    }

    public function test_ajustes_trae_las_pestanas_segun_el_permiso(): void
    {
        $this->actingAs($this->admin)
            ->withSession($this->withActiveStore())
            ->get(route('settings.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Settings/Index')
                ->where('editable.brand', true)
                ->where('editable.receipt', true)
                ->where('editable.credit', true)
                ->has('stores', 1)
                ->has('fontOptions.body')
                ->has('receiptSample.number')
                // La clave lleva punto: se lee entera, no como ruta.
                ->where('settings', fn ($settings) => collect($settings)->get('brand.accent') === '#0E6E4E'));

        $supervisor = $this->makeUser('supervisor');

        $this->actingAs($supervisor)
            ->withSession($this->withActiveStore())
            ->get(route('settings.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('editable.brand', false)
                ->where('editable.appearance', false)
                ->where('editable.pos', true)
                ->where('editable.receipt', true)
                ->where('editable.pos_rules', false));
    }

    public function test_apariencia_guarda_y_cambia_el_css(): void
    {
        $this->actingAs($this->admin)
            ->put(route('settings.appearance.update'), [
                'appearance' => [
                    'font_body' => 'Inter',
                    'font_display' => 'Outfit',
                    'font_numeric' => 'JetBrains Mono',
                    'radius' => 'round',
                    'density' => 'compact',
                ],
                'panel' => [
                    'default_theme' => 'dark',
                    'allow_theme_toggle' => false,
                    'sidebar_style' => 'brand',
                    'sidebar_mode' => 'icons',
                    'ui_scale' => 110,
                    'content_width' => 'wide',
                ],
            ])
            ->assertSessionHasNoErrors();

        $html = $this->actingAs($this->admin)
            ->withSession($this->withActiveStore())
            ->get(route('dashboard'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('brand.surfaces.panel.sidebar_style', 'brand')
                ->where('brand.surfaces.panel.sidebar_mode', 'icons')
                ->where('brand.surfaces.panel.allow_theme_toggle', false)
                ->where('brand.fonts_href', fn (string $href) => str_contains($href, 'family=Inter:') && str_contains($href, 'family=JetBrains+Mono:')))
            ->getContent();

        $this->assertStringContainsString("--brand-font-body:'Inter'", $html);
        $this->assertStringContainsString('--r-card:20px', $html);
        $this->assertStringContainsString("html[data-surface='panel']{--density:0.8;--ui-scale:1.1}", $html);
        $this->assertStringContainsString('--content-max:90rem', $html);
        // Tema inicial oscuro y sin interruptor: la eleccion guardada no se aplica.
        $this->assertStringContainsString('data-theme="dark"', $html);
        $this->assertStringContainsString('data-theme-locked="1"', $html);
    }

    public function test_una_fuente_fuera_de_la_lista_se_rechaza(): void
    {
        $this->actingAs($this->admin)
            ->put(route('settings.appearance.update'), [
                'appearance' => ['font_body' => 'Comic Sans MS', 'font_display' => 'Outfit', 'font_numeric' => 'IBM Plex Mono', 'radius' => 'soft', 'density' => 'cozy'],
                'panel' => ['default_theme' => 'light', 'allow_theme_toggle' => true, 'sidebar_style' => 'surface', 'sidebar_mode' => 'expanded', 'ui_scale' => 100, 'content_width' => 'centered'],
            ])
            ->assertSessionHasErrors('appearance.font_body');
    }

    public function test_la_tienda_en_linea_no_recibe_la_marca_del_panel(): void
    {
        $this->get('/tienda')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('brand', null));
    }
}
