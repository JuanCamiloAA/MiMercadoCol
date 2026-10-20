<?php

namespace Tests\Feature;

use App\Landing\LandingBlockCatalogue;
use App\Models\LandingBlock;
use App\Models\MediaAsset;
use App\Models\Storefront;
use App\Models\User;
use App\Services\Landing\LandingManager;
use App\Services\Storefront\StorefrontManager;
use App\Services\Storefront\StorefrontPublisher;
use App\Services\Storefront\StorefrontRenderer;
use App\Services\Storefront\StorefrontTemplateService;
use App\Storefront\BlockCatalogue;
use App\Storefront\Blocks\BlockData;
use App\Support\MediaVariants;
use App\Support\SectionAppearance;
use App\Support\Uploads;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\Concerns\EditsStorefront;
use Tests\TestCase;

/**
 * Las cuatro versiones de cada imagen o video de banner (computador y celular, en modo claro y en
 * modo oscuro) y el diseño de modo oscuro de la pestaña «Diseño» y de cada lámina, en la tienda y
 * en la landing. Ninguna version oscura es obligatoria: si falta, va la clara; solo se avisa al
 * publicar cuando la tienda se puede ver en los dos modos.
 */
class MediaVariantsTest extends TestCase
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

    protected function asset(?int $companyId, string $alt = 'Banner', string $mime = 'image/jpeg'): MediaAsset
    {
        $path = 'storefront/'.($companyId ?? 'plataforma').'/'.uniqid().($mime === 'video/mp4' ? '.mp4' : '.jpg');
        Storage::disk('public')->put($path, 'x');

        return MediaAsset::create(['company_id' => $companyId, 'disk' => 'public', 'path' => $path, 'mime' => $mime, 'bytes' => 1000, 'alt' => $alt]);
    }

    public function test_las_claves_de_cada_version_son_hermanas_que_se_reconocen_como_imagen(): void
    {
        $this->assertSame('media_mobile_id', MediaVariants::storeKey('media_id', MediaVariants::MOBILE));
        $this->assertSame('dark_media_mobile_id', MediaVariants::storeKey('media_id', MediaVariants::MOBILE_DARK));
        $this->assertSame('video_dark_media_id', MediaVariants::storeKey('video_media_id', MediaVariants::DARK));
        $this->assertSame('image_mobile_dark', MediaVariants::landingKey('image', MediaVariants::MOBILE_DARK));

        // Publicar, pintar e independizarse las encuentran por el nombre.
        foreach (['media_mobile_id', 'dark_media_id', 'dark_media_mobile_id', 'video_media_mobile_id', 'video_dark_media_id', 'video_dark_media_mobile_id', 'poster_dark_media_id'] as $key) {
            $this->assertTrue(BlockData::isMediaKey($key), $key);
        }

        $item = BlockCatalogue::for('hero')->fields()['slides']['item'];
        $this->assertSame(['mobile' => 'media_mobile_id', 'dark' => 'dark_media_id', 'mobile_dark' => 'dark_media_mobile_id'], $item['media_id']['variants']);
        $this->assertSame('media_id', $item['dark_media_id']['variant_of']);
        // Un logo no tiene versión de celular, pero sí para fondo oscuro.
        $this->assertSame(['dark' => 'dark_media_id'], BlockCatalogue::for('brand_logos')->fields()['items']['item']['media_id']['variants']);
    }

    public function test_la_lamina_guarda_sus_versiones_y_su_diseno_de_modo_oscuro(): void
    {
        $payload = BlockCatalogue::for('hero')->normalize([
            'slides' => [[
                'media_id' => 1, 'media_mobile_id' => 2, 'dark_media_id' => 3, 'dark_media_mobile_id' => 'x',
                'dark' => ['text_color' => 'dark', 'overlay' => 20, 'style' => ['title_size' => 'xl', 'ratio' => 'nope'], 'title' => 'no va'],
            ]],
        ]);

        $slide = $payload['slides'][0];
        $this->assertSame([1, 2, 3, null], [$slide['media_id'], $slide['media_mobile_id'], $slide['dark_media_id'], $slide['dark_media_mobile_id']]);
        // De lo oscuro solo el grupo «Diseño» de la lámina, limpio con su esquema.
        $this->assertSame(['text_color' => 'dark', 'overlay' => 20, 'style' => ['title_size' => 'xl']], $slide['dark']);

        // Sin nada oscuro la fila queda como siempre.
        $plain = BlockCatalogue::for('hero')->normalize(['slides' => [['media_id' => 1]]]);
        $this->assertArrayNotHasKey('dark', $plain['slides'][0]);
    }

    public function test_la_tienda_recibe_las_cuatro_versiones_y_el_diseno_oscuro_resuelto(): void
    {
        [$light, $phone, $dark, $phoneDark, $bg, $bgDark] = array_map(fn () => $this->asset($this->company->id), range(1, 6));

        $this->addBlock('hero', [
            'slides' => [['media_id' => $light->id, 'media_mobile_id' => $phone->id, 'dark_media_id' => $dark->id, 'dark_media_mobile_id' => $phoneDark->id, 'dark' => ['text_color' => 'dark']]],
            'appearance' => ['bg_type' => 'image', 'bg_media_id' => $bg->id, 'overlay' => 30, 'dark' => ['bg_media_id' => $bgDark->id, 'ink' => 'light', 'anim' => 'fade']],
        ]);

        $block = collect(app(StorefrontRenderer::class)->draft($this->store)['blocks'])->firstWhere('type', 'hero');
        $slide = $block['data']['slides'][0];

        $this->assertSame($light->id, $slide['media']['id']);
        $this->assertSame($phone->id, $slide['media_mobile']['id']);
        $this->assertSame($dark->id, $slide['media_dark']['id']);
        $this->assertSame($phoneDark->id, $slide['media_mobile_dark']['id']);
        $this->assertSame(['text_color' => 'dark'], $slide['dark']);

        // El diseño de modo oscuro llega entero: lo de claro con lo propio de oscuro encima.
        $appearance = $block['appearance'];
        $this->assertSame($bg->id, $appearance['bg_media']['id']);
        $this->assertSame($bgDark->id, $appearance['dark']['bg_media']['id']);
        $this->assertSame('image', $appearance['dark']['bg_type']);
        $this->assertSame(30, $appearance['dark']['overlay']);
        $this->assertSame('light', $appearance['dark']['ink']);
        // La animación no es del fondo: es la misma en los dos modos y no se guarda en oscuro.
        $this->assertSame('none', $appearance['dark']['anim']);
    }

    public function test_sin_nada_oscuro_la_seccion_y_el_bloque_salen_como_antes(): void
    {
        $image = $this->asset($this->company->id);
        $this->addBlock('image_text', ['title' => 'Hola', 'media_id' => $image->id, 'appearance' => ['bg_type' => 'color', 'bg_color' => 'dark']]);

        $block = collect(app(StorefrontRenderer::class)->draft($this->store)['blocks'])->firstWhere('type', 'image_text');

        $this->assertNull($block['appearance']['dark']);
        $this->assertNull($block['data']['media_dark']);
        $this->assertSame($image->id, $block['data']['media']['id']);
    }

    public function test_lo_oscuro_igual_a_lo_claro_no_se_guarda(): void
    {
        $saved = SectionAppearance::normalize(['bg_type' => 'color', 'bg_color' => 'brand', 'dark' => ['bg_type' => 'color', 'bg_color' => 'dark', 'height' => 'screen']], [], SectionAppearance::SURFACE_STORE);

        $this->assertSame(['bg_color' => 'dark'], $saved['dark']);
        $this->assertArrayNotHasKey('dark', SectionAppearance::normalize(['bg_type' => 'color', 'dark' => ['bg_type' => 'color']], [], SectionAppearance::SURFACE_STORE));
    }

    public function test_el_editor_rechaza_una_imagen_oscura_de_otra_empresa(): void
    {
        $block = $this->addBlock('rich_text', ['title' => 'x']);
        $foreign = $this->asset($this->makeOtherCompany()['company']->id);

        $this->editor()
            ->patch(route('storefront.editor.blocks.update', $block), ['payload' => array_merge($block->payload, ['appearance' => ['bg_type' => 'image', 'dark' => ['bg_media_id' => $foreign->id]]])])
            ->assertSessionHasErrors();

        $own = $this->asset($this->company->id);
        $this->editor()
            ->patch(route('storefront.editor.blocks.update', $block), ['payload' => array_merge($block->payload, ['appearance' => ['bg_type' => 'image', 'dark' => ['bg_media_id' => $own->id]]])])
            ->assertSessionHasNoErrors();

        $this->assertSame($own->id, $block->refresh()->payload['appearance']['dark']['bg_media_id']);
    }

    public function test_al_independizarse_las_versiones_oscuras_tambien_se_copian(): void
    {
        $dark = $this->asset(null);
        $bgDark = $this->asset(null);

        $design = app(StorefrontTemplateService::class)->copyPlatformMedia(
            ['blocks' => [['type' => 'hero', 'payload' => ['slides' => [['dark_media_mobile_id' => $dark->id]], 'appearance' => ['dark' => ['bg_media_id' => $bgDark->id]]]]]],
            $this->company->id,
        );

        $payload = $design['blocks'][0]['payload'];
        $this->assertSame($this->company->id, MediaAsset::query()->findOrFail($payload['slides'][0]['dark_media_mobile_id'])->company_id);
        $this->assertSame($this->company->id, MediaAsset::query()->findOrFail($payload['appearance']['dark']['bg_media_id'])->company_id);
    }

    public function test_al_publicar_avisa_lo_que_falta_en_oscuro_solo_si_la_tienda_se_ve_en_los_dos_modos(): void
    {
        $image = $this->asset($this->company->id);
        $this->addBlock('hero', ['slides' => [['media_id' => $image->id]]]);
        $publisher = app(StorefrontPublisher::class);
        $warns = fn () => collect($publisher->validate($this->storefront->refresh())['warnings'])->pluck('message')->filter(fn ($message) => str_contains($message, 'modo oscuro'))->values();

        // Solo en claro: no hay nada que avisar.
        $this->assertCount(0, $warns());

        $this->storefront->theme()->update(['allow_scheme_toggle' => true]);
        $this->assertSame(['Banner principal (portada): 1 imagen o video no tiene versión para modo oscuro: en oscuro se verá la de modo claro.'], $warns()->all());

        // Avisar no bloquea.
        $this->assertSame([], $publisher->validate($this->storefront->refresh())['blocking']);
    }

    public function test_la_vista_previa_muestra_el_modo_que_revisa_el_editor_sin_volver_a_firmar(): void
    {
        $url = URL::temporarySignedRoute('storefront.preview', now()->addMinutes(30), ['store' => $this->store->id]);

        $this->get($url.'&scheme=dark')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Storefront/Preview')->where('previewScheme', 'dark'));

        $this->get($url)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('previewScheme', null));
        $this->get($url.'&scheme=sepia')->assertSessionHasErrors('scheme');
    }

    public function test_la_landing_guarda_y_resuelve_las_versiones_y_el_fondo_oscuro(): void
    {
        $hero = LandingBlockCatalogue::for('hero')->normalize(['image' => 'landing/a.jpg', 'image_dark' => 'landing/b.jpg', 'image_mobile' => 'http://evil.co/x.jpg']);
        $this->assertSame(['landing/a.jpg', null, 'landing/b.jpg', null], [$hero['image'], $hero['image_mobile'], $hero['image_dark'], $hero['image_mobile_dark']]);
        $this->assertSame(Uploads::url('landing/b.jpg'), LandingBlockCatalogue::for('hero')->withMediaUrls($hero)['image_dark_url']);

        $super = $this->makeSuperAdmin();
        app(LandingManager::class)->ensureSeeded($super);
        $block = LandingBlock::query()->where('type', 'faq')->firstOrFail();

        $urls = $this->actingAs($super)
            ->patchJson(route('platform.landing.blocks.update', $block->uuid), ['data' => array_merge($block->data, [
                'appearance' => ['bg_type' => 'image', 'bg_image' => 'landing/claro.jpg', 'overlay' => 40, 'dark' => ['bg_image' => 'landing/oscuro.jpg']],
            ])])
            ->assertOk()
            ->json('block.image_urls');

        // El editor recibe tambien la direccion de la de modo oscuro (por ruta).
        $this->assertSame(Uploads::url('landing/oscuro.jpg'), $urls['landing/oscuro.jpg'] ?? null);

        $page = $this->actingAs($super)->get(route('platform.landing.preview', ['scheme' => 'dark']))->assertOk()->viewData('page');
        $this->assertSame('dark', $page['props']['previewScheme']);

        $faq = collect(json_decode(json_encode($page['props']['blocks']), true))->firstWhere('type', 'faq');
        $this->assertSame(Uploads::url('landing/claro.jpg'), $faq['appearance']['bg_image_url']);
        $this->assertSame(Uploads::url('landing/oscuro.jpg'), $faq['appearance']['dark']['bg_image_url']);
        // Lo que oscuro no cambia lo hereda de claro.
        $this->assertSame(40, $faq['appearance']['dark']['overlay']);
        $this->assertSame('image', $faq['appearance']['dark']['bg_type']);
    }
}
