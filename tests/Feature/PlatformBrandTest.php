<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Landing\LandingManager;
use App\Services\Platform\PlatformBrandAssets;
use App\Services\PlatformSettingsService;
use App\Services\SettingsService;
use App\Support\Uploads;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\TestCase;

/**
 * La marca de la plataforma (VentaIO) viste la landing y el panel sin empresa, y nada mas: una
 * empresa no la hereda ni la pisa.
 */
class PlatformBrandTest extends TestCase
{
    use CreatesCompanyEnvironment, RefreshDatabase;

    protected User $super;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->seedEnvironment();
        $this->super = $this->makeSuperAdmin();
        app(LandingManager::class)->ensureSeeded($this->super);
    }

    protected function setting(string $key): mixed
    {
        return app(PlatformSettingsService::class)->get($key);
    }

    public function test_el_comando_sube_la_marca_y_la_deja_en_uso(): void
    {
        $this->artisan('platform:brand')->assertSuccessful();

        foreach (array_keys(PlatformBrandAssets::FILES) as $key) {
            $this->assertStringStartsWith(PlatformBrandAssets::FOLDER.'/', $this->setting($key));
            Storage::disk('public')->assertExists($this->setting($key));
        }

        // Repetirlo no duplica nada: la ruta sale del contenido.
        $this->artisan('platform:brand')->assertSuccessful();
        $this->assertCount(count(PlatformBrandAssets::FILES), Storage::disk('public')->files(PlatformBrandAssets::FOLDER));
    }

    public function test_no_borra_lo_que_el_super_usuario_subio_a_mano(): void
    {
        Storage::disk('public')->put('landing/2026/10/logo-a-mano.png', 'png');
        app(PlatformSettingsService::class)->set('platform.brand_logo', 'landing/2026/10/logo-a-mano.png');

        app(PlatformBrandAssets::class)->install();

        Storage::disk('public')->assertExists('landing/2026/10/logo-a-mano.png');
        $this->assertStringStartsWith(PlatformBrandAssets::FOLDER.'/', $this->setting('platform.brand_logo'));
    }

    public function test_viste_la_landing_y_el_panel_del_super_usuario(): void
    {
        app(PlatformBrandAssets::class)->install();
        $url = fn (string $key) => Uploads::url($this->setting($key));

        $this->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('site.logo_url', $url('platform.brand_logo'))
                ->where('site.logo_dark_url', $url('platform.brand_logo_dark'))
                ->where('site.mark_url', $url('platform.brand_mark'))
                ->where('site.favicon_url', $url('landing.favicon'))
                ->where('site.seo_image_url', $url('landing.seo_image'))
                ->where('brand.favicon_url', $url('landing.favicon')));

        // Sin empresa elegida, el panel del super usuario lleva los dos logos, el isotipo y el favicon.
        $this->actingAs($this->super)
            ->get(route('platform.landing.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('brand.logo_light_url', $url('platform.brand_logo'))
                ->where('brand.logo_dark_url', $url('platform.brand_logo_dark'))
                ->where('brand.mark_url', $url('platform.brand_mark'))
                ->where('brand.touch_icon_url', $url('platform.brand_mark'))
                ->where('brand.favicon_url', $url('landing.favicon'))
                ->where('settings.brand_logo_dark_url', $url('platform.brand_logo_dark'))
                ->where('settings.brand_mark_url', $url('platform.brand_mark')));

        // Guardar los ajustes de la landing con la marca instalada pasa la validacion.
        $this->actingAs($this->super)->put(route('platform.landing.settings'), [
            'brand_accent' => '#2563EB',
            'brand_logo' => $this->setting('platform.brand_logo'),
            'brand_logo_dark' => $this->setting('platform.brand_logo_dark'),
            'brand_mark' => $this->setting('platform.brand_mark'),
            'seo_image' => $this->setting('landing.seo_image'),
            'favicon' => $this->setting('landing.favicon'),
            'font_body' => 'IBM Plex Sans',
            'font_display' => 'Bricolage Grotesque',
            'font_numeric' => 'IBM Plex Mono',
            'terms_text' => 'Términos.',
            'privacy_text' => 'Datos.',
        ])->assertRedirect()->assertSessionHasNoErrors();
    }

    public function test_una_empresa_no_hereda_ni_pisa_la_marca_de_plataforma(): void
    {
        $paths = app(PlatformBrandAssets::class)->install();
        $admin = $this->makeUser('admin');

        // Sin logo propio, la empresa se presenta con su nombre y su canasta, no con VentaIO.
        $this->actingAs($admin)
            ->withSession($this->withActiveStore())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('brand.logo_light_url', null)
                ->where('brand.logo_dark_url', null)
                ->where('brand.mark_url', null)
                ->where('brand.favicon_url', fn (string $favicon) => str_starts_with($favicon, 'data:image/svg+xml')));

        $this->actingAs($admin)
            ->post(route('settings.brand.images.store', 'logo_dark'), ['file' => UploadedFile::fake()->create('logo.png', 120, 'image/png')])
            ->assertSessionHasNoErrors();

        $companyLogo = app(SettingsService::class)->get($this->company->id, 'brand.logo_dark')['path'];

        $this->assertStringStartsNotWith(PlatformBrandAssets::FOLDER.'/', $companyLogo);

        // La plataforma conserva sus ajustes y sus archivos.
        foreach ($paths as $key => $path) {
            $this->assertSame($path, $this->setting($key));
            Storage::disk('public')->assertExists($path);
        }

        auth('web')->logout();
        $this->get('/')->assertInertia(fn (Assert $page) => $page->where('site.logo_url', Uploads::url($paths['platform.brand_logo'])));
    }
}
