<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\TestCase;

class BrandImageTest extends TestCase
{
    use CreatesCompanyEnvironment, RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEnvironment();
        $this->admin = $this->makeUser('admin');
        Storage::fake('public');
    }

    protected function requireGd(): void
    {
        if (! function_exists('imagecreatetruecolor') || ! function_exists('imagepng')) {
            $this->markTestSkipped('Sin GD no se pueden generar imagenes de prueba con dimensiones.');
        }
    }

    public function test_el_isotipo_se_guarda_en_png_con_sus_variantes(): void
    {
        $this->requireGd();

        $this->actingAs($this->admin)
            ->post(route('settings.brand.images.store', 'mark'), ['file' => UploadedFile::fake()->image('isotipo.png', 400, 400)])
            ->assertSessionHasNoErrors();

        $mark = app(SettingsService::class)->get($this->company->id, 'brand.mark');

        $this->assertIsArray($mark);
        foreach (['path', 'favicon', 'touch'] as $variant) {
            $this->assertStringEndsWith('.png', $mark[$variant]);
            Storage::disk('public')->assertExists($mark[$variant]);
        }

        $this->assertSame([32, 32], array_slice(getimagesizefromstring(Storage::disk('public')->get($mark['favicon'])), 0, 2));
        $this->assertSame([180, 180], array_slice(getimagesizefromstring(Storage::disk('public')->get($mark['touch'])), 0, 2));

        // Sin logo claro, el tiquete saca su logo del isotipo.
        $receipt = app(SettingsService::class)->get($this->company->id, 'brand.logo_receipt');
        Storage::disk('public')->assertExists($receipt['path']);

        $this->actingAs($this->admin)
            ->withSession($this->withActiveStore())
            ->get(route('dashboard'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('brand.mark_url', '/storage/'.$mark['path'])
                ->where('brand.favicon_url', '/storage/'.$mark['favicon']));
    }

    public function test_cambiar_el_color_rehace_el_icono_de_ios(): void
    {
        $this->requireGd();

        // Un isotipo con fondo transparente: en el icono de iOS ese fondo se vuelve el color.
        $image = imagecreatetruecolor(300, 300);
        imagesavealpha($image, true);
        imagealphablending($image, false);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
        imagefilledrectangle($image, 100, 100, 200, 200, imagecolorallocatealpha($image, 255, 255, 255, 0));
        $path = tempnam(sys_get_temp_dir(), 'mark').'.png';
        imagepng($image, $path);

        $this->actingAs($this->admin)
            ->post(route('settings.brand.images.store', 'mark'), ['file' => new UploadedFile($path, 'isotipo.png', 'image/png', null, true)])
            ->assertSessionHasNoErrors();

        $before = app(SettingsService::class)->get($this->company->id, 'brand.mark');

        $this->actingAs($this->admin)
            ->put(route('settings.brand.update'), ['brand' => ['display_name' => 'La Canasta', 'tagline' => null, 'accent' => '#A8301F', 'accent_dark' => null]])
            ->assertSessionHasNoErrors();

        $after = app(SettingsService::class)->get($this->company->id, 'brand.mark');

        $this->assertSame($before['path'], $after['path']);
        $this->assertNotSame($before['touch'], $after['touch']);
        Storage::disk('public')->assertMissing($before['touch']);

        // El fondo del icono es el color nuevo.
        $icon = imagecreatefromstring(Storage::disk('public')->get($after['touch']));
        $corner = imagecolorsforindex($icon, imagecolorat($icon, 0, 0));
        $this->assertSame([0xA8, 0x30, 0x1F], [$corner['red'], $corner['green'], $corner['blue']]);
    }

    public function test_la_transparencia_se_conserva(): void
    {
        $this->requireGd();

        // Un PNG transparente con un cuadro en el centro.
        $image = imagecreatetruecolor(300, 120);
        imagesavealpha($image, true);
        imagealphablending($image, false);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
        imagefilledrectangle($image, 100, 30, 200, 90, imagecolorallocatealpha($image, 14, 110, 78, 0));
        $path = tempnam(sys_get_temp_dir(), 'logo').'.png';
        imagepng($image, $path);

        $this->actingAs($this->admin)
            ->post(route('settings.brand.images.store', 'logo_light'), ['file' => new UploadedFile($path, 'logo.png', 'image/png', null, true)])
            ->assertSessionHasNoErrors();

        $logo = app(SettingsService::class)->get($this->company->id, 'brand.logo_light');
        $stored = imagecreatefromstring(Storage::disk('public')->get($logo['path']));

        // La esquina sigue transparente: no le pusieron fondo.
        $this->assertSame(127, (imagecolorat($stored, 2, 2) >> 24) & 0x7F);
        $this->assertSame(0, (imagecolorat($stored, 150, 60) >> 24) & 0x7F);
    }

    public function test_reemplazar_borra_lo_anterior(): void
    {
        $this->actingAs($this->admin)
            ->post(route('settings.brand.images.store', 'logo_dark'), ['file' => UploadedFile::fake()->create('uno.png', 120, 'image/png')])
            ->assertSessionHasNoErrors();

        $first = app(SettingsService::class)->get($this->company->id, 'brand.logo_dark')['path'];
        Storage::disk('public')->assertExists($first);

        app()->forgetInstance(SettingsService::class);

        $this->actingAs($this->admin)
            ->post(route('settings.brand.images.store', 'logo_dark'), ['file' => UploadedFile::fake()->create('dos.png', 120, 'image/png')])
            ->assertSessionHasNoErrors();

        $second = app(SettingsService::class)->get($this->company->id, 'brand.logo_dark')['path'];

        $this->assertNotSame($first, $second);
        Storage::disk('public')->assertMissing($first);
        Storage::disk('public')->assertExists($second);
    }

    public function test_quitar_una_imagen_la_borra(): void
    {
        $this->actingAs($this->admin)
            ->post(route('settings.brand.images.store', 'logo_light'), ['file' => UploadedFile::fake()->create('logo.png', 120, 'image/png')]);

        $path = app(SettingsService::class)->get($this->company->id, 'brand.logo_light')['path'];
        app()->forgetInstance(SettingsService::class);

        $this->actingAs($this->admin)
            ->delete(route('settings.brand.images.destroy', 'logo_light'))
            ->assertSessionHasNoErrors();

        Storage::disk('public')->assertMissing($path);
        $this->assertNull(app(SettingsService::class)->get($this->company->id, 'brand.logo_light'));
        $this->assertNull(app(SettingsService::class)->get($this->company->id, 'brand.logo_receipt'));
    }

    public function test_un_svg_se_rechaza(): void
    {
        $this->actingAs($this->admin)
            ->post(route('settings.brand.images.store', 'logo_light'), [
                'file' => UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
            ])
            ->assertSessionHasErrors(['file' => 'La imagen tiene que ser PNG, JPG o WebP. SVG no se acepta.']);
    }

    public function test_un_isotipo_de_100_px_se_rechaza(): void
    {
        $this->requireGd();

        $this->actingAs($this->admin)
            ->post(route('settings.brand.images.store', 'mark'), ['file' => UploadedFile::fake()->image('chico.png', 100, 100)])
            ->assertSessionHasErrors('file');
    }

    public function test_un_isotipo_que_no_es_cuadrado_se_rechaza(): void
    {
        $this->requireGd();

        $this->actingAs($this->admin)
            ->post(route('settings.brand.images.store', 'mark'), ['file' => UploadedFile::fake()->image('ancho.png', 600, 300)])
            ->assertSessionHasErrors(['file' => 'El isotipo tiene que ser cuadrado y este mide 600 × 300 px.']);
    }

    public function test_un_lugar_que_no_existe_da_404(): void
    {
        $this->actingAs($this->admin)
            ->post('/ajustes/marca/imagenes/fondo', ['file' => UploadedFile::fake()->create('x.png', 120, 'image/png')])
            ->assertNotFound();
    }

    public function test_sin_permiso_de_marca_no_se_sube(): void
    {
        $this->actingAs($this->makeUser('supervisor'))
            ->post(route('settings.brand.images.store', 'logo_light'), ['file' => UploadedFile::fake()->create('x.png', 120, 'image/png')])
            ->assertForbidden();
    }
}
