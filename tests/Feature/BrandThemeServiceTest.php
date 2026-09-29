<?php

namespace Tests\Feature;

use App\Services\BrandThemeService;
use App\Services\SettingsService;
use App\Support\Color;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\TestCase;

class BrandThemeServiceTest extends TestCase
{
    use CreatesCompanyEnvironment, RefreshDatabase;

    protected BrandThemeService $brand;

    protected function setUp(): void
    {
        parent::setUp();
        $this->brand = app(BrandThemeService::class);
    }

    public function test_de_fabrica_no_se_inyecta_nada(): void
    {
        $this->assertSame('', $this->brand->css(SettingsService::DEFAULTS));
    }

    public function test_de_fabrica_las_tipografias_son_las_de_siempre(): void
    {
        $this->assertSame(
            'https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,700&family=IBM+Plex+Mono:wght@400;500;600&family=IBM+Plex+Sans:wght@400;500;600;700&display=swap',
            $this->brand->fontsHref(SettingsService::DEFAULTS),
        );
    }

    public function test_un_amarillo_se_oscurece_hasta_leerse_en_claro_y_se_lee_en_oscuro(): void
    {
        $palette = $this->brand->palette('#E8B100');

        $light = $palette['light']['accent'];
        $soft = Color::mix(BrandThemeService::LIGHT['surface'], $light, BrandThemeService::SOFT_WEIGHT['light']);

        $this->assertNotSame('#E8B100', $light);
        $this->assertGreaterThanOrEqual(4.5, Color::contrast($light, BrandThemeService::LIGHT['surface']));
        $this->assertGreaterThanOrEqual(4.5, Color::contrast($light, BrandThemeService::LIGHT['bg']));
        $this->assertGreaterThanOrEqual(4.5, Color::contrast($light, $soft));

        $dark = $palette['dark']['accent'];
        $this->assertGreaterThanOrEqual(4.5, Color::contrast($dark, BrandThemeService::DARK['surface']));
        $this->assertGreaterThanOrEqual(4.5, Color::contrast($dark, BrandThemeService::DARK['bg']));

        // La letra sobre el acento tambien se lee.
        $this->assertGreaterThanOrEqual(4.5, Color::contrast($palette['light']['ink'], $light));
    }

    public function test_el_css_solo_lleva_lo_que_cambio(): void
    {
        $css = $this->brand->css(array_merge(SettingsService::DEFAULTS, [
            'appearance.radius' => 'sharp',
            'panel.ui_scale' => 110,
        ]));

        $this->assertStringContainsString('--r-chip:2px', $css);
        $this->assertStringContainsString("html[data-surface='panel']{--ui-scale:1.1}", $css);
        // El color no se toco: no se recalcula el verde de fabrica.
        $this->assertStringNotContainsString('--accent', $css);
        $this->assertStringNotContainsString("data-surface='pos'", $css);
    }

    public function test_con_acento_de_fabrica_y_oscuro_a_mano_solo_cambia_el_oscuro(): void
    {
        $css = $this->brand->css(array_merge(SettingsService::DEFAULTS, ['brand.accent_dark' => '#8FE0B8']));

        $this->assertStringNotContainsString("[data-theme='light']", $css);
        $this->assertStringContainsString("[data-theme='dark'][data-theme]{--accent:#8FE0B8", $css);
    }

    /**
     * El CSS se imprime sin escapar: nada de texto libre puede llegar a el.
     */
    public function test_el_css_no_deja_pasar_texto_libre(): void
    {
        $this->seedEnvironment();
        $settings = app(SettingsService::class);

        $settings->set($this->company->id, 'brand.display_name', 'Mala</style><script>alert(1)</script>');
        $settings->set($this->company->id, 'brand.accent', 'red;}</style><script>');
        $settings->set($this->company->id, 'appearance.font_body', "Inter';}</style>");
        $settings->set($this->company->id, 'appearance.radius', '1px}body{display:none');
        $settings->set($this->company->id, 'panel.ui_scale', '110}</style>');

        $brand = $this->brand->forCompany($this->company->id);

        $this->assertStringNotContainsString('</style>', $brand['css']);
        $this->assertStringNotContainsString('script', $brand['css']);
        $this->assertStringNotContainsString('display:none', $brand['css']);
        $this->assertSame(BrandThemeService::FACTORY_ACCENT, $brand['theme_color']);
    }

    public function test_sin_empresa_la_marca_es_neutra(): void
    {
        $brand = $this->brand->forCompany(null);

        $this->assertSame(config('app.name'), $brand['display_name']);
        $this->assertSame('', $brand['css']);
        $this->assertNull($brand['mark_url']);
        $this->assertStringStartsWith('data:image/svg+xml,', $brand['favicon_url']);
    }
}
