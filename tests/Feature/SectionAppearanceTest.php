<?php

namespace Tests\Feature;

use App\Landing\LandingBlockCatalogue;
use App\Models\MediaAsset;
use App\Models\Storefront;
use App\Models\User;
use App\Services\Landing\LandingRenderer;
use App\Services\Storefront\StorefrontManager;
use App\Services\Storefront\StorefrontRenderer;
use App\Storefront\BlockCatalogue;
use App\Support\SectionAppearance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\Concerns\EditsStorefront;
use Tests\TestCase;

/**
 * La pestaña «Diseño» de las secciones: el catalogo, lo que se guarda (solo lo cambiado), lo que
 * se rechaza, quien puede cambiarlo y como llega resuelto a la tienda y a la landing.
 */
class SectionAppearanceTest extends TestCase
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

    protected function image(?int $companyId = null, string $mime = 'image/jpeg'): MediaAsset
    {
        return MediaAsset::create([
            'company_id' => $companyId,
            'disk' => 'public',
            'path' => 'storefront/'.($companyId ?? 'platform').'/'.Str::random(8).($mime === 'video/mp4' ? '.mp4' : '.jpg'),
            'mime' => $mime,
            'width' => 1920,
            'height' => 1080,
            'bytes' => 120000,
            'alt' => 'Fondo de la sección',
        ]);
    }

    public function test_guarda_solo_lo_que_difiere_del_defecto_del_tipo(): void
    {
        $saved = SectionAppearance::normalize([
            'height' => 'half',
            'pad_top' => 'md',
            'bg_type' => 'color',
            'bg_color' => '#1e3a8a',
            'overlay' => 47,
            'anim' => 'up',
            'anim_once' => 'false',
            'inventado' => 'x',
            'frame' => 'marco-raro',
        ], [], SectionAppearance::SURFACE_STORE);

        // `pad_top` es el neutro, `inventado` no existe y el marco no vale: no se guardan.
        $this->assertSame(['height' => 'half', 'bg_type' => 'color', 'bg_color' => '#1E3A8A', 'overlay' => 45, 'anim' => 'up', 'anim_once' => false], $saved);

        // El defecto del tipo cuenta: «Video con pasos» ya es oscuro de fábrica.
        $showcase = BlockCatalogue::for('video_showcase')->appearanceDefaults();
        $this->assertSame([], SectionAppearance::normalize(['bg_type' => 'color', 'bg_color' => 'brand_dark'], $showcase, SectionAppearance::SURFACE_STORE));

        // Un color con nombre de otra superficie no vale aquí.
        $this->assertSame([], SectionAppearance::normalize(['bg_color' => 'band'], [], SectionAppearance::SURFACE_STORE));
        $this->assertSame(['bg_color' => 'band'], SectionAppearance::normalize(['bg_color' => 'band'], [], SectionAppearance::SURFACE_LANDING));
    }

    public function test_el_catalogo_trae_los_tres_grupos_y_cada_superficie_sus_archivos(): void
    {
        foreach ([SectionAppearance::SURFACE_STORE, SectionAppearance::SURFACE_LANDING] as $surface) {
            $groups = SectionAppearance::groups($surface);
            $this->assertSame(['size', 'background', 'animation'], array_keys($groups));

            foreach (SectionAppearance::MEDIA_KEYS[$surface] as $key) {
                $this->assertArrayHasKey($key, $groups['background']['fields']);
            }
        }

        // La tienda guarda ids de la biblioteca; la landing, rutas.
        $this->assertSame('media', SectionAppearance::fields(SectionAppearance::SURFACE_STORE)['bg_media_id']['type']);
        $this->assertSame('image', SectionAppearance::fields(SectionAppearance::SURFACE_LANDING)['bg_image']['type']);

        // Cada tipo de bloque lleva su diseño de fábrica en el catálogo del editor.
        $entry = collect(BlockCatalogue::toPayload())->firstWhere('type', 'video_showcase');
        $this->assertSame('brand_dark', $entry['appearance_defaults']['bg_color']);
        $landing = collect(LandingBlockCatalogue::toArray())->keyBy('type');
        $this->assertFalse($landing['header']['supports_appearance']);
        $this->assertSame('bleed', $landing['hero']['appearance_defaults']['frame']);
    }

    public function test_rechaza_colores_rutas_y_archivos_que_no_son_suyos(): void
    {
        $this->assertNotEmpty(SectionAppearance::violations(['bg_color' => 'rojo'], SectionAppearance::SURFACE_STORE));
        $this->assertNotEmpty(SectionAppearance::violations(['bg_image' => '../../.env'], SectionAppearance::SURFACE_LANDING));
        $this->assertSame([], SectionAppearance::violations(['bg_image' => 'landing/fondo.jpg', 'bg_color' => 'band'], SectionAppearance::SURFACE_LANDING));

        $own = $this->image($this->company->id);
        $foreign = $this->image($this->makeOtherCompany()['company']->id);
        $platform = $this->image();
        $video = $this->image($this->company->id, 'video/mp4');

        $this->assertSame([], SectionAppearance::violations(['bg_media_id' => $own->id], SectionAppearance::SURFACE_STORE, $this->company->id));
        $this->assertNotEmpty(SectionAppearance::violations(['bg_media_id' => $foreign->id], SectionAppearance::SURFACE_STORE, $this->company->id));
        $this->assertNotEmpty(SectionAppearance::violations(['bg_media_id' => $video->id], SectionAppearance::SURFACE_STORE, $this->company->id));
        $this->assertNotEmpty(SectionAppearance::violations(['bg_video_media_id' => $own->id], SectionAppearance::SURFACE_STORE, $this->company->id));

        // Una plantilla solo usa lo de la plataforma.
        $this->assertSame([], SectionAppearance::violations(['bg_media_id' => $platform->id], SectionAppearance::SURFACE_STORE, null, true));
        $this->assertNotEmpty(SectionAppearance::violations(['bg_media_id' => $own->id], SectionAppearance::SURFACE_STORE, null, true));
    }

    public function test_el_editor_guarda_el_diseno_y_rechaza_una_imagen_ajena(): void
    {
        $block = $this->addBlock('rich_text', ['title' => 'Quiénes somos']);
        $own = $this->image($this->company->id);
        $foreign = $this->image($this->makeOtherCompany()['company']->id);

        $this->editor()->patch(route('storefront.editor.blocks.update', $block), [
            'payload' => ['title' => 'Quiénes somos', 'appearance' => ['bg_type' => 'image', 'bg_media_id' => $own->id, 'overlay' => 40, 'pad_top' => 'md']],
        ])->assertSessionHasNoErrors();

        // MySQL reordena las claves de una columna JSON: se compara sin importar el orden.
        $this->assertEquals(['bg_type' => 'image', 'bg_media_id' => $own->id, 'overlay' => 40], $block->refresh()->payload['appearance']);

        $this->editor()->patch(route('storefront.editor.blocks.update', $block), [
            'payload' => ['title' => 'Quiénes somos', 'appearance' => ['bg_type' => 'image', 'bg_media_id' => $foreign->id]],
        ])->assertSessionHasErrors('payload');

        $this->assertSame($own->id, $block->refresh()->payload['appearance']['bg_media_id']);

        // Restablecer deja el bloque sin diseño propio.
        $this->editor()->patch(route('storefront.editor.blocks.update', $block), ['payload' => ['title' => 'Quiénes somos', 'appearance' => []]]);
        $this->assertArrayNotHasKey('appearance', $block->refresh()->payload);
    }

    public function test_sin_permiso_de_aspecto_el_diseno_se_conserva(): void
    {
        $block = $this->addBlock('rich_text', ['title' => 'Hola', 'appearance' => ['height' => 'half']]);
        $editor = $this->makeUser('admin');
        $editor->revokePermissionTo('storefront.editor.appearance');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($editor, 'web')->withSession($this->withActiveStore())->patch(route('storefront.editor.blocks.update', $block), [
            'payload' => ['title' => 'Hola de nuevo', 'appearance' => ['height' => 'screen', 'anim' => 'up']],
        ])->assertSessionHasNoErrors();

        $payload = $block->refresh()->payload;
        $this->assertSame('Hola de nuevo', $payload['title']);
        $this->assertSame(['height' => 'half'], $payload['appearance']);
    }

    public function test_la_tienda_recibe_el_diseno_resuelto_con_sus_imagenes(): void
    {
        $own = $this->image($this->company->id);
        $this->addBlock('rich_text', ['title' => 'Con fondo', 'appearance' => ['bg_type' => 'image', 'bg_media_id' => $own->id]]);
        $this->addBlock('faq', []);

        $blocks = collect(app(StorefrontRenderer::class)->draft($this->store)['blocks'])->keyBy('type');

        $this->assertSame('image', $blocks['rich_text']['appearance']['bg_type']);
        $this->assertSame($own->id, $blocks['rich_text']['appearance']['bg_media']['id']);
        // Sin tocar «Diseño», el diseño es el neutro: la sección se ve como siempre.
        $this->assertSame('none', $blocks['faq']['appearance']['bg_type']);
        $this->assertSame('md', $blocks['faq']['appearance']['pad_top']);
        $this->assertNull($blocks['faq']['appearance']['bg_media']);
    }

    public function test_la_landing_recibe_el_diseno_con_sus_direcciones_menos_encabezado_y_pie(): void
    {
        $resolved = collect(app(LandingRenderer::class)->resolve([
            ['uuid' => 'a', 'type' => 'header', 'is_visible' => true, 'data' => []],
            ['uuid' => 'b', 'type' => 'faq', 'is_visible' => true, 'data' => ['appearance' => ['bg_type' => 'image', 'bg_image' => 'landing/fondo.jpg', 'width' => 'narrow']]],
            ['uuid' => 'c', 'type' => 'video_showcase', 'is_visible' => true, 'data' => []],
        ]))->keyBy('type');

        $this->assertNull($resolved['header']['appearance']);
        $this->assertSame('narrow', $resolved['faq']['appearance']['width']);
        $this->assertSame('bleed', $resolved['faq']['appearance']['frame']);
        $this->assertStringContainsString('landing/fondo.jpg', (string) $resolved['faq']['appearance']['bg_image_url']);
        $this->assertSame('dark', $resolved['video_showcase']['appearance']['bg_color']);
    }
}
