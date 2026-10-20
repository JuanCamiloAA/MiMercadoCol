<?php

namespace Tests\Feature;

use App\Models\MediaAsset;
use App\Models\Storefront;
use App\Models\User;
use App\Services\Storefront\StorefrontManager;
use App\Services\Storefront\StorefrontPublisher;
use App\Services\Storefront\StorefrontRenderer;
use App\Services\Storefront\StorefrontTemplateService;
use App\Storefront\Blocks\MediaCarouselBlockData;
use App\Storefront\Blocks\VideoBlockData;
use App\Storefront\Blocks\VideoShowcaseBlockData;
use App\Support\VideoOptions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\Concerns\EditsStorefront;
use Tests\TestCase;

/**
 * Los videos en la tienda en linea de una empresa: la biblioteca los recibe, los bloques de video
 * los pintan, publicar los revisa y nada se mezcla con las imagenes ni con otra empresa.
 */
class StorefrontVideoBlocksTest extends TestCase
{
    use CreatesCompanyEnvironment, EditsStorefront, RefreshDatabase;

    protected User $admin;

    protected Storefront $storefront;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->seedEnvironment();

        $this->admin = $this->makeUser('admin');
        $this->storefront = app(StorefrontManager::class)->for($this->store);
        $this->makeStorefrontPublishable($this->storefront);
    }

    protected function video(array $attributes = []): MediaAsset
    {
        return MediaAsset::create(array_merge([
            'company_id' => $this->company->id,
            'disk' => 'public',
            'path' => 'storefront/'.$this->company->id.'/videos/demo.mp4',
            'mime' => 'video/mp4',
            'bytes' => 2 * 1024 * 1024,
            'alt' => 'Así se arma un pedido',
        ], $attributes));
    }

    protected function image(): MediaAsset
    {
        return MediaAsset::create([
            'company_id' => $this->company->id,
            'disk' => 'public',
            'path' => 'storefront/foto.webp',
            'mime' => 'image/webp',
            'bytes' => 90000,
            'width' => 1600,
            'height' => 900,
            'alt' => 'Frutas frescas',
        ]);
    }

    public function test_la_empresa_sube_un_video_a_su_biblioteca_y_no_se_mezcla_con_las_imagenes(): void
    {
        $image = $this->image();

        $response = $this->actingAs($this->admin)
            ->withSession($this->withActiveStore())
            ->postJson(route('media.store-video'), ['video' => UploadedFile::fake()->create('pedido.mp4', 2048, 'video/mp4'), 'alt' => 'Así se arma un pedido'])
            ->assertCreated()
            ->assertJsonPath('media.kind', 'video');

        $asset = MediaAsset::query()->findOrFail($response->json('media.id'));
        $this->assertSame($this->company->id, $asset->company_id);
        $this->assertTrue($asset->isVideo());
        Storage::disk('public')->assertExists($asset->path);

        // La biblioteca de imagenes sigue mostrando solo imagenes; la de videos, solo videos.
        $this->actingAs($this->admin)->withSession($this->withActiveStore())->getJson(route('media.index'))
            ->assertOk()->assertJsonCount(1, 'media')->assertJsonPath('media.0.id', $image->id);
        $this->actingAs($this->admin)->withSession($this->withActiveStore())->getJson(route('media.index', ['kind' => 'video']))
            ->assertOk()->assertJsonCount(1, 'media')->assertJsonPath('media.0.id', $asset->id);
    }

    public function test_subir_un_video_pide_descripcion_formato_y_tope(): void
    {
        $upload = fn (array $data) => $this->actingAs($this->admin)->withSession($this->withActiveStore())->postJson(route('media.store-video'), $data);

        $upload(['video' => UploadedFile::fake()->create('a.mp4', 100, 'video/mp4')])->assertStatus(422)->assertJsonValidationErrors('alt');
        $upload(['video' => UploadedFile::fake()->create('a.gif', 100, 'image/gif'), 'alt' => 'Un gif'])->assertStatus(422)->assertJsonPath('errors.video.0', 'El video debe ser MP4 o WebM.');
        $upload(['video' => UploadedFile::fake()->create('a.mp4', VideoOptions::MAX_VIDEO_KB + 1, 'video/mp4'), 'alt' => 'Muy pesado'])->assertStatus(422);

        // Sin permiso del editor de la tienda no se sube nada.
        $cashier = $this->makeUser('cajero');
        $this->actingAs($cashier)->withSession($this->withActiveStore())->postJson(route('media.store-video'), ['video' => UploadedFile::fake()->create('a.mp4', 10, 'video/mp4'), 'alt' => 'Intento'])->assertForbidden();
    }

    public function test_los_bloques_de_video_llegan_a_la_tienda_con_su_video_y_sus_opciones(): void
    {
        $video = $this->video();
        $image = $this->image();

        $this->addBlock('video', ['video_media_id' => $video->id, 'poster_media_id' => $image->id, 'video_speed' => '1.5', 'background' => 'custom', 'background_color' => '#1e3a8a']);
        $this->addBlock('video_showcase', ['video_media_id' => $video->id]);
        $this->addBlock('media_carousel', ['slides' => [
            ['media_id' => $image->id, 'video_media_id' => null, 'caption' => 'Foto'],
            ['media_id' => null, 'video_media_id' => $video->id, 'caption' => 'Video'],
            ['media_id' => null, 'video_media_id' => null, 'caption' => 'Vacía'],
        ]]);

        $blocks = collect(app(StorefrontRenderer::class)->draft($this->store)['blocks'])->keyBy('type');

        $this->assertSame('video', $blocks['video']['data']['video']['kind']);
        $this->assertSame($video->id, $blocks['video']['data']['video']['id']);
        $this->assertSame($image->id, $blocks['video']['data']['poster']['id']);
        $this->assertSame('1.5', $blocks['video']['data']['video_speed']);
        // El fondo de antes llega como diseño de la sección.
        $this->assertArrayNotHasKey('background_color', $blocks['video']['data']);
        $this->assertSame('color', $blocks['video']['appearance']['bg_type']);
        $this->assertSame('#1E3A8A', $blocks['video']['appearance']['bg_color']);
        // «Video con pasos» sale oscuro de fábrica, aunque nadie haya tocado su diseño.
        $this->assertSame('brand_dark', $blocks['video_showcase']['appearance']['bg_color']);
        $this->assertSame($video->id, $blocks['video_showcase']['data']['video']['id']);
        $this->assertCount(3, $blocks['video_showcase']['data']['steps']);

        // La lamina sin nada no llega; la de imagen trae su imagen y la de video, su video.
        $slides = $blocks['media_carousel']['data']['slides'];
        $this->assertCount(2, $slides);
        $this->assertSame($image->id, $slides[0]['media']['id']);
        $this->assertSame($video->id, $slides[1]['video']['id']);
    }

    public function test_un_campo_de_video_no_pinta_una_imagen_ni_el_video_de_otra_empresa(): void
    {
        $image = $this->image();
        $foreign = $this->video(['company_id' => $this->makeOtherCompany()['company']->id]);

        $this->addBlock('video', ['video_media_id' => $image->id]);
        $this->addBlock('video_showcase', ['video_media_id' => $foreign->id]);
        $this->addBlock('hero', ['slides' => [['media_id' => $this->video()->id, 'title' => 'Un video en un campo de imagen']]]);

        $blocks = collect(app(StorefrontRenderer::class)->draft($this->store)['blocks'])->keyBy('type');

        $this->assertNull($blocks['video']['data']['video']);
        $this->assertNull($blocks['video_showcase']['data']['video']);
        $this->assertNull($blocks['hero']['data']['slides'][0]['media']);
    }

    public function test_publicar_revisa_los_videos(): void
    {
        $heavy = $this->video(['bytes' => VideoOptions::HEAVY_VIDEO_BYTES + 1]);

        $this->addBlock('video', []);
        $this->addBlock('video_showcase', ['video_media_id' => $heavy->id]);
        $this->addBlock('media_carousel', ['slides' => [['media_id' => null, 'video_media_id' => null, 'caption' => 'Nada']]]);

        $review = app(StorefrontPublisher::class)->validate($this->storefront->refresh());
        $blocking = implode(' | ', array_column($review['blocking'], 'message'));
        $warnings = implode(' | ', array_column($review['warnings'], 'message'));

        $this->assertStringContainsString('no tiene video', $blocking);
        $this->assertStringContainsString('no tiene ni imagen ni video', $blocking);
        $this->assertStringContainsString('pesa más de 8 MB', $warnings);
    }

    public function test_la_plataforma_sube_videos_para_sus_plantillas_y_al_independizarse_se_copian(): void
    {
        $response = $this->actingAs($this->makeSuperAdmin())
            ->postJson(route('platform.store_templates.media.store-video'), ['video' => UploadedFile::fake()->create('plantilla.webm', 1024, 'video/webm'), 'alt' => 'Recorrido por la tienda'])
            ->assertCreated()
            ->assertJsonPath('media.kind', 'video');

        $platformVideo = MediaAsset::platform()->findOrFail($response->json('media.id'));
        $this->assertNull($platformVideo->company_id);

        // Ninguna biblioteca de empresa ve el video de la plataforma.
        $this->actingAs($this->admin)->withSession($this->withActiveStore())->getJson(route('media.index', ['kind' => 'video']))
            ->assertOk()->assertJsonCount(0, 'media');

        // Al independizarse, el diseño se queda con una copia propia del video.
        $design = app(StorefrontTemplateService::class)->copyPlatformMedia(
            ['blocks' => [['type' => 'video', 'payload' => ['video_media_id' => $platformVideo->id]]]],
            $this->company->id,
        );

        $copy = MediaAsset::query()->findOrFail($design['blocks'][0]['payload']['video_media_id']);
        $this->assertNotSame($platformVideo->id, $copy->id);
        $this->assertSame($this->company->id, $copy->company_id);
        $this->assertTrue($copy->isVideo());
        Storage::disk('public')->assertExists($copy->path);
    }

    public function test_los_tres_bloques_comparten_las_opciones_de_video_y_limpian_lo_que_no_ofrecen(): void
    {
        foreach ([new VideoBlockData(), new VideoShowcaseBlockData(), new MediaCarouselBlockData()] as $block) {
            foreach ($block::VIDEO_OPTIONS as $key) {
                $this->assertSame(VideoOptions::fields([$key])[$key]['type'], $block->fields()[$key]['type'], $block->type().' '.$key);
            }

            $clean = $block->normalize(['video_speed' => '9', 'video_radius' => 'circulo', 'background' => 'neon', 'background_color' => 'rojo']);
            $this->assertSame('1', $clean['video_speed']);
            $this->assertSame('round', $clean['video_radius']);
            // El fondo ya no es del bloque: uno que no vale no deja diseño ni claves viejas.
            $this->assertArrayNotHasKey('background', $clean);
            $this->assertArrayNotHasKey('background_color', $clean);
            $this->assertArrayNotHasKey('background', $block->fields());
            $this->assertSame([5], array_values(array_unique($block->mediaIds(['video_media_id' => 5]))));
        }

        $this->assertArrayNotHasKey('video_loop', (new MediaCarouselBlockData())->fields());
        $this->assertSame(['bg_type' => 'color', 'bg_color' => 'brand_dark'], (new VideoShowcaseBlockData())->appearanceDefaults());
    }
}
