<?php

namespace Tests\Feature;

use App\Landing\Blocks\AppDemoBlock;
use App\Landing\Blocks\MediaCarouselBlock;
use App\Landing\Blocks\VideoBlock;
use App\Landing\Blocks\VideoShowcaseBlock;
use App\Landing\Demo\DemoWorkspace;
use App\Models\LandingBlock;
use App\Models\User;
use App\Services\Landing\LandingManager;
use App\Support\Uploads;
use App\Support\VideoOptions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\TestCase;

/**
 * El carrusel de imagenes y videos (archivos dentro de una lista) y la demo del sistema (datos
 * de ejemplo que no tocan la base).
 */
class LandingMediaAndDemoTest extends TestCase
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

    /** @return array<string, mixed>|null */
    protected function previewBlock(string $type): ?array
    {
        $blocks = json_decode(json_encode($this->actingAs($this->super)->get(route('platform.landing.preview'))->assertOk()->viewData('page')['props']['blocks']), true);

        return collect($blocks)->firstWhere('type', $type);
    }

    public function test_el_carrusel_resuelve_las_imagenes_y_videos_de_cada_lamina(): void
    {
        $this->actingAs($this->super)->post(route('platform.landing.blocks.store'), ['type' => 'media_carousel'])->assertSessionHasNoErrors();
        $block = LandingBlock::query()->where('type', 'media_carousel')->firstOrFail();
        $slides = [
            ['image' => 'landing/2026/10/portada.jpg', 'video' => null, 'caption' => 'Una imagen'],
            ['image' => 'landing/2026/10/cuadro.jpg', 'video' => 'landing/videos/2026/10/demo.mp4', 'caption' => 'Un video'],
            ['image' => 'javascript:alert(1)', 'video' => 'https://otro-sitio.com/x.mp4', 'caption' => 'Sin nada valido'],
        ];

        // El editor recibe las direcciones de lo que hay dentro de la lista, por ruta.
        $this->actingAs($this->super)
            ->patchJson(route('platform.landing.blocks.update', $block->uuid), ['data' => array_merge($block->data, ['slides' => $slides])])
            ->assertOk()
            ->assertJsonPath('block.image_urls', [
                'landing/2026/10/portada.jpg' => Uploads::url('landing/2026/10/portada.jpg'),
                'landing/2026/10/cuadro.jpg' => Uploads::url('landing/2026/10/cuadro.jpg'),
                'landing/videos/2026/10/demo.mp4' => Uploads::url('landing/videos/2026/10/demo.mp4'),
            ]);

        $rendered = $this->previewBlock('media_carousel')['data']['slides'];

        $this->assertSame(Uploads::url('landing/2026/10/portada.jpg'), $rendered[0]['image_url']);
        $this->assertNull($rendered[0]['video_url']);
        $this->assertSame(Uploads::url('landing/videos/2026/10/demo.mp4'), $rendered[1]['video_url']);
        $this->assertNull($rendered[2]['image']);
        $this->assertNull($rendered[2]['video_url']);
    }

    public function test_el_carrusel_corrige_lo_que_no_ofrece(): void
    {
        $data = (new MediaCarouselBlock())->normalize(['interval' => '99', 'ratio' => '7/3', 'slides' => array_fill(0, 14, ['caption' => 'x'])]);

        $this->assertSame('6', $data['interval']);
        $this->assertSame('16/9', $data['ratio']);
        $this->assertCount(10, $data['slides']);
    }

    public function test_los_bloques_con_video_comparten_sus_opciones_y_las_limpian(): void
    {
        foreach ([new VideoShowcaseBlock(), new VideoBlock(), new MediaCarouselBlock()] as $block) {
            $fields = $block->fields();

            foreach ($block::VIDEO_OPTIONS as $key) {
                $this->assertSame(VideoOptions::fields([$key])[$key]['type'], $fields[$key]['type'], $block->type().' '.$key);
            }

            // El fondo ya no es del bloque: va en la pestaña «Diseño» de la sección.
            $this->assertArrayNotHasKey('tone', $fields);
            $this->assertArrayNotHasKey('background_color', $fields);

            // Un bloque guardado antes de las opciones las recibe con su defecto.
            $old = $block->normalize(['tone' => 'dark']);
            $this->assertSame('1', $old['video_speed']);
            $this->assertTrue($old['video_autoplay']);
            $this->assertSame('round', $old['video_radius']);
            $this->assertArrayNotHasKey('tone', $old);

            // Lo que no se ofrece vuelve a su defecto; el color viejo pasa al diseño, en mayúsculas.
            $dirty = $block->normalize(['video_speed' => '9', 'video_radius' => 'circulo', 'background_color' => 'rojo']);
            $this->assertSame('1', $dirty['video_speed']);
            $this->assertSame('round', $dirty['video_radius']);
            $this->assertArrayNotHasKey('background_color', $dirty);
            $this->assertSame('#FF8800', $block->normalize(['tone' => 'custom', 'background_color' => '#ff8800'])['appearance']['bg_color']);
            $this->assertSame('0.5', $block->normalize(['video_speed' => '0.5'])['video_speed']);
        }

        // El carrusel no ofrece «se repite»: un video pasa a la siguiente lámina al terminar.
        $this->assertArrayNotHasKey('video_loop', (new MediaCarouselBlock())->fields());
        $this->assertSame('Avanza solo', (new MediaCarouselBlock())->fields()['video_autoplay']['label']);
    }

    public function test_la_demo_llega_con_los_modulos_elegidos_y_sus_datos_de_ejemplo(): void
    {
        $this->actingAs($this->super)->post(route('platform.landing.blocks.store'), ['type' => 'app_demo'])->assertSessionHasNoErrors();
        $block = LandingBlock::query()->where('type', 'app_demo')->firstOrFail();

        $this->actingAs($this->super)->patchJson(route('platform.landing.blocks.update', $block->uuid), ['data' => array_merge($block->data, [
            'business_name' => 'Boutique Aurora',
            'business_kind' => 'fashion',
            'modules' => [['module' => 'pos'], ['module' => 'inventario-falso'], ['module' => 'finance'], ['module' => 'pos']],
        ])])->assertOk();

        $source = $this->previewBlock('app_demo')['source'];

        // Lo que no existe vale el defecto del select (el inicio), como en todo bloque; el repetido sale una vez.
        $this->assertSame(['pos', 'dashboard', 'finance'], array_column($source['modules'], 'key'));
        $this->assertSame(['pos', 'dashboard', 'finance'], array_keys($source['data']));
        $this->assertSame('Boutique Aurora', $source['business']['name']);
        $this->assertSame('BA', $source['business']['initials']);
        $this->assertSame('Camiseta básica de algodón', $source['data']['pos']['products'][0]['name']);
        // En la caja solo se ofrece lo que tiene existencias.
        $this->assertNotContains(0, array_column($source['data']['pos']['products'], 'stock'));
    }

    public function test_armar_la_demo_no_toca_la_base(): void
    {
        $block = new AppDemoBlock();
        $data = $block->normalize(['modules' => array_map(fn (string $key) => ['module' => $key], array_keys(DemoWorkspace::MODULES))]);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $demo = app(DemoWorkspace::class)->build($data);
        DB::disableQueryLog();

        $this->assertSame([], DB::getQueryLog());
        $this->assertCount(count(DemoWorkspace::MODULES), $demo['modules']);
        $this->assertSame(array_keys(DemoWorkspace::MODULES), array_keys($demo['data']));
    }

    public function test_sin_modulos_la_demo_muestra_el_inicio(): void
    {
        $demo = app(DemoWorkspace::class)->build((new AppDemoBlock())->normalize(['modules' => []]));

        $this->assertSame(['dashboard'], array_column($demo['modules'], 'key'));
    }
}
