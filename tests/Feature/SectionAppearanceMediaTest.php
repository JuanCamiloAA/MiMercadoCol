<?php

namespace Tests\Feature;

use App\Models\LandingBlock;
use App\Models\MediaAsset;
use App\Models\Storefront;
use App\Models\User;
use App\Services\Landing\LandingManager;
use App\Services\Storefront\StorefrontManager;
use App\Services\Storefront\StorefrontTemplateService;
use App\Storefront\BlockCatalogue;
use App\Support\Uploads;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\Concerns\EditsStorefront;
use Tests\TestCase;

/**
 * Las imagenes y videos de fondo de la pestaña «Diseño»: en la tienda son medios de la biblioteca
 * (viajan como cualquier imagen al publicar, copiar e independizarse); en la landing, rutas que
 * llegan con su direccion.
 */
class SectionAppearanceMediaTest extends TestCase
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
    }

    protected function asset(?int $companyId, string $mime = 'image/jpeg'): MediaAsset
    {
        $path = 'storefront/'.($companyId ?? 'plataforma').'/fondo-'.uniqid().($mime === 'video/mp4' ? '.mp4' : '.jpg');
        Storage::disk('public')->put($path, 'x');

        return MediaAsset::create([
            'company_id' => $companyId,
            'disk' => 'public',
            'path' => $path,
            'mime' => $mime,
            'bytes' => 1000,
            'alt' => 'Fondo',
        ]);
    }

    public function test_los_fondos_de_la_tienda_cuentan_como_imagenes_del_bloque(): void
    {
        $ids = BlockCatalogue::for('rich_text')->mediaIds(['title' => 'x', 'appearance' => ['bg_media_id' => 5, 'bg_media_mobile_id' => 6, 'bg_video_media_id' => 7]]);

        $this->assertEqualsCanonicalizing([5, 6, 7], $ids);
    }

    public function test_al_independizarse_los_fondos_de_plataforma_se_copian_a_la_empresa(): void
    {
        $image = $this->asset(null);
        $video = $this->asset(null, 'video/mp4');

        $design = app(StorefrontTemplateService::class)->copyPlatformMedia(
            ['blocks' => [['type' => 'rich_text', 'payload' => ['title' => 'x', 'appearance' => ['bg_type' => 'video', 'bg_media_id' => $image->id, 'bg_video_media_id' => $video->id]]]]],
            $this->company->id,
        );

        $appearance = $design['blocks'][0]['payload']['appearance'];
        $this->assertNotSame($image->id, $appearance['bg_media_id']);
        $this->assertNotSame($video->id, $appearance['bg_video_media_id']);
        $this->assertSame($this->company->id, MediaAsset::query()->findOrFail($appearance['bg_media_id'])->company_id);
        $this->assertTrue(MediaAsset::query()->findOrFail($appearance['bg_video_media_id'])->isVideo());
    }

    public function test_copiar_a_otra_sede_lleva_el_diseno_con_su_fondo(): void
    {
        $image = $this->asset($this->company->id);
        $this->addBlock('rich_text', ['title' => 'Con fondo', 'appearance' => ['bg_type' => 'image', 'bg_media_id' => $image->id]]);

        $snapshot = app(StorefrontManager::class)->snapshot($this->storefront->refresh());
        $block = collect($snapshot['blocks'])->firstWhere('type', 'rich_text');

        // Lo que se publica y se copia es el retrato: el diseño va dentro del bloque.
        $this->assertSame($image->id, $block['payload']['appearance']['bg_media_id']);
    }

    public function test_la_landing_resuelve_el_fondo_y_el_editor_recibe_su_direccion(): void
    {
        $super = $this->makeSuperAdmin();
        app(LandingManager::class)->ensureSeeded($super);
        $block = LandingBlock::query()->where('type', 'faq')->firstOrFail();

        $urls = $this->actingAs($super)
            ->patchJson(route('platform.landing.blocks.update', $block->uuid), ['data' => array_merge($block->data, ['appearance' => ['bg_type' => 'image', 'bg_image' => 'landing/2026/10/fondo.jpg']])])
            ->assertOk()
            ->json('block.image_urls');

        // Por ruta, como lo de las listas (la ruta lleva puntos: no sirve `assertJsonPath`).
        $this->assertSame(Uploads::url('landing/2026/10/fondo.jpg'), $urls['landing/2026/10/fondo.jpg'] ?? null);

        $page = $this->actingAs($super)->get(route('platform.landing.preview'))->assertOk()->viewData('page');
        $faq = collect(json_decode(json_encode($page['props']['blocks']), true))->firstWhere('type', 'faq');
        $this->assertSame(Uploads::url('landing/2026/10/fondo.jpg'), $faq['appearance']['bg_image_url']);

        // Una ruta que no es de la landing se rechaza; quitar la imagen la deja sin fondo propio.
        $this->actingAs($super)
            ->patchJson(route('platform.landing.blocks.update', $block->uuid), ['data' => array_merge($block->data, ['appearance' => ['bg_type' => 'image', 'bg_image' => '../.env']])])
            ->assertUnprocessable();

        $this->actingAs($super)
            ->patchJson(route('platform.landing.blocks.update', $block->uuid), ['data' => array_merge($block->data, ['appearance' => []])])
            ->assertOk();
        $this->assertArrayNotHasKey('appearance', $block->refresh()->data);
    }
}
