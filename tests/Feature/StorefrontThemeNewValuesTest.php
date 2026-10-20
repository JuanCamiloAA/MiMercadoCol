<?php

namespace Tests\Feature;

use App\Models\Storefront;
use App\Models\StorefrontTemplate;
use App\Models\User;
use App\Services\Storefront\StorefrontManager;
use App\Services\Storefront\StorefrontRenderer;
use App\Services\Storefront\StorefrontStructure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsStorefront;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\Concerns\EditsStorefront;
use Tests\TestCase;

/**
 * Lo nuevo del tema (letra del nombre, fondo, títulos, ancho, botones) y de la estructura
 * (encabezado, menú, tarjeta, pie, navegación del celular y botón de WhatsApp): se guarda, se
 * publica, viaja en el diseño de la plantilla y lo de siempre no cambia si nadie lo toca.
 */
class StorefrontThemeNewValuesTest extends TestCase
{
    use BuildsStorefront, CreatesCompanyEnvironment, EditsStorefront, RefreshDatabase;

    protected User $admin;

    protected Storefront $storefront;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEnvironment();

        $this->admin = $this->makeUser('admin');
        $this->storefront = $this->site();
    }

    public function test_sin_tocar_nada_el_tema_vale_lo_de_siempre(): void
    {
        $theme = app(StorefrontManager::class)->themePayload($this->storefront);

        $this->assertSame(['none', 'white', 'normal', 'normal', 'default'], [$theme['logo_font'], $theme['surface'], $theme['heading_case'], $theme['container'], $theme['button_shape']]);
        $this->assertSame('search', StorefrontStructure::DEFAULTS['header']['layout']);
        $this->assertSame('boxed', StorefrontStructure::DEFAULTS['card']['style']);
        $this->assertFalse(StorefrontStructure::DEFAULTS['footer']['newsletter']);
        $this->assertFalse(StorefrontStructure::DEFAULTS['floating']['whatsapp']);
    }

    public function test_el_tema_nuevo_se_guarda_y_se_publica(): void
    {
        $this->editor()->patch(route('storefront.editor.theme'), [
            'font_pair' => 'vitrina',
            'logo_font' => 'italiana',
            'surface' => 'warm',
            'heading_case' => 'upper',
            'container' => 'full',
            'button_shape' => 'pill',
        ])->assertSessionHasNoErrors();

        $this->editor()->patch(route('storefront.editor.theme'), ['surface' => 'neon'])->assertSessionHasErrors('surface');

        $published = $this->publishSite($this->storefront, $this->admin);
        $theme = $published->publishedVersion->snapshot['theme'];

        $this->assertSame('vitrina', $theme['font_pair']);
        $this->assertSame('italiana', $theme['logo_font']);
        $this->assertSame('warm', $theme['surface']);
        $this->assertSame('pill', $theme['button_shape']);
    }

    public function test_la_plantilla_lleva_la_forma_pero_nunca_los_colores(): void
    {
        foreach (['logo_font', 'surface', 'heading_case', 'container', 'button_shape'] as $key) {
            $this->assertContains($key, StorefrontTemplate::DESIGN_KEYS['theme']);
        }

        $this->assertNotContains('accent', StorefrontTemplate::DESIGN_KEYS['theme']);
        $this->assertNotContains('deal_color', StorefrontTemplate::DESIGN_KEYS['theme']);
        $this->assertContains('floating', StorefrontTemplate::DESIGN_KEYS['settings']);
    }

    public function test_la_estructura_nueva_se_guarda_y_se_valida(): void
    {
        $header = array_merge(StorefrontStructure::DEFAULTS['header'], ['style' => 'overlay', 'layout' => 'inline', 'overlay' => true, 'sticky_style' => 'blur_dark', 'search_style' => 'underline']);
        $this->editor()->patch(route('storefront.editor.structure', 'header'), ['value' => $header])->assertSessionHasNoErrors();

        $card = array_merge(StorefrontStructure::DEFAULTS['card'], ['style' => 'photo', 'image_ratio' => '4:5', 'add_button' => 'icon', 'image_bg' => 'tint', 'price_size' => 'sm', 'text_align' => 'center', 'image_hover' => 'second']);
        $this->editor()->patch(route('storefront.editor.look', 'card'), ['value' => $card])->assertSessionHasNoErrors();

        $this->editor()->patch(route('storefront.editor.structure', 'floating'), ['value' => ['whatsapp' => true, 'position' => 'left']])->assertSessionHasNoErrors();
        $this->editor()->patch(route('storefront.editor.structure', 'mobile_nav'), ['value' => ['enabled' => false, 'items' => []]])->assertSessionHasNoErrors();
        $this->editor()->patch(route('storefront.editor.structure', 'card'), ['value' => $card])->assertNotFound();

        $bad = array_merge($card, ['style' => 'giratoria']);
        $this->editor()->patch(route('storefront.editor.look', 'card'), ['value' => $bad])->assertSessionHasErrors('value.style');

        $settings = $this->storefront->refresh()->settings->mapWithKeys(fn ($row) => [$row->key => $row->value]);
        $this->assertSame('inline', $settings['header']['layout']);
        $this->assertSame('photo', $settings['card']['style']);
        $this->assertSame('left', $settings['floating']['position']);
        $this->assertFalse($settings['mobile_nav']['enabled']);
    }

    public function test_el_boton_de_whatsapp_sale_solo_con_numero(): void
    {
        $structure = app(StorefrontStructure::class);
        $this->store->forceFill(['phone' => null])->save();

        $without = $structure->resolve(['floating' => ['whatsapp' => true, 'position' => 'right']], $this->store->refresh());
        $this->assertNull($without['floating']['whatsapp_href']);

        $footer = array_merge(StorefrontStructure::DEFAULTS['footer'], ['contact' => ['whatsapp' => '3001234567', 'phone' => null, 'email' => null, 'address' => null]]);
        $with = $structure->resolve(['floating' => ['whatsapp' => true, 'position' => 'right'], 'footer' => $footer], $this->store);
        $this->assertStringStartsWith('https://wa.me/', (string) $with['floating']['whatsapp_href']);

        // La navegación del celular apagada no lleva opciones.
        $this->assertSame([], $structure->resolve(['mobile_nav' => ['enabled' => false, 'items' => ['home', 'search', 'cart']]], $this->store)['mobile_nav']['items']);
    }

    public function test_el_encabezado_va_transparente_solo_sobre_un_banner_de_borde_a_borde(): void
    {
        $hero = ['type' => 'hero', 'appearance' => ['frame' => 'bleed']];

        $this->assertTrue(StorefrontRenderer::headerOverlay(['overlay' => true], [$hero]));
        $this->assertTrue(StorefrontRenderer::headerOverlay(['style' => 'overlay'], [$hero]));
        $this->assertFalse(StorefrontRenderer::headerOverlay(['overlay' => false, 'style' => 'light'], [$hero]));
        // Sin borde a borde, o sin el banner de primero, va sólido.
        $this->assertFalse(StorefrontRenderer::headerOverlay(['overlay' => true], [['type' => 'hero', 'appearance' => ['frame' => 'contained']]]));
        $this->assertFalse(StorefrontRenderer::headerOverlay(['overlay' => true], [['type' => 'categories', 'appearance' => ['frame' => 'bleed']], $hero]));
        $this->assertFalse(StorefrontRenderer::headerOverlay(['overlay' => true], []));
    }

    public function test_la_portada_publicada_dice_si_el_encabezado_va_encima(): void
    {
        $media = $this->mediaFor($this->company->id);
        $this->addBlock('hero', ['slides' => [['media_id' => $media->id, 'title' => 'Hola']], 'appearance' => ['frame' => 'bleed', 'height' => 'screen']]);
        $this->storefront->settings()->updateOrCreate(['key' => 'header'], ['value' => array_merge(StorefrontStructure::DEFAULTS['header'], ['overlay' => true])]);
        $this->publishSite($this->storefront->refresh(), $this->admin);

        $this->shopAt()->get(route('storefront.home'))->assertOk()->assertInertia(fn ($page) => $page->component('Storefront/Home')->where('headerOverlay', true));
        // En las demas paginas el encabezado va solido: ni siquiera reciben la marca.
        $this->shopAt()->get(route('storefront.search', ['q' => 'arroz']))->assertOk()->assertInertia(fn ($page) => $page->missing('headerOverlay'));
    }
}
