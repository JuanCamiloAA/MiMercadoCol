<?php

namespace Tests\Feature;

use App\Models\Storefront;
use App\Models\User;
use App\Services\Storefront\StorefrontManager;
use App\Services\Storefront\StorefrontRenderer;
use App\Storefront\BlockCatalogue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\Concerns\EditsStorefront;
use Tests\TestCase;

/**
 * «Diseño de esta lámina»: cada banner del banner principal y del mosaico guarda su proporción,
 * la posición del texto, el tamaño del título, el velo y el botón en `item.style`, solo con lo que
 * alguien cambio. Un banner que nadie toco queda igual que antes.
 */
class BannerStyleTest extends TestCase
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

    public function test_guarda_solo_el_diseno_que_vale_y_no_es_automatico(): void
    {
        $payload = BlockCatalogue::for('hero')->normalize(['slides' => [
            ['title' => 'Con diseño', 'style' => ['ratio' => 'wide', 'text_position' => 'bottom-center', 'title_size' => 'display', 'button_style' => 'pill_white', 'overlay_style' => 'auto', 'inventado' => 'x']],
            ['title' => 'Con basura', 'style' => ['ratio' => 'gigante', 'text_position' => 'afuera']],
            ['title' => 'Sin diseño'],
        ]]);

        $this->assertSame(['ratio' => 'wide', 'text_position' => 'bottom-center', 'title_size' => 'display', 'button_style' => 'pill_white'], $payload['slides'][0]['style']);
        // Sin nada que valga, la fila queda como antes de que existiera el diseño.
        $this->assertArrayNotHasKey('style', $payload['slides'][1]);
        $this->assertArrayNotHasKey('style', $payload['slides'][2]);
        $this->assertSame('light', $payload['slides'][2]['text_color']);
    }

    public function test_el_mosaico_y_los_laterales_ganan_color_velo_y_diseno(): void
    {
        $grid = BlockCatalogue::for('banner_grid')->normalize(['tiles' => [
            ['kind' => 'image', 'title' => 'Temporada', 'text_color' => 'dark', 'overlay' => 25, 'style' => ['ratio' => 'square', 'button_style' => 'link']],
            ['kind' => 'image', 'title' => 'Como siempre'],
        ]]);

        $this->assertSame('dark', $grid['tiles'][0]['text_color']);
        $this->assertSame(25, $grid['tiles'][0]['overlay']);
        // El mosaico lleva botón (las plantillas de moda lo usan en píldora), así que su estilo se guarda.
        $this->assertSame(['ratio' => 'square', 'button_style' => 'link'], $grid['tiles'][0]['style']);
        $this->assertNull($grid['tiles'][1]['text_color']);
        $this->assertNull($grid['tiles'][1]['overlay']);

        $fields = BlockCatalogue::for('banner_grid')->fields()['tiles']['item'];
        $this->assertSame('design', $fields['style']['group']);
        $this->assertSame('design', $fields['overlay']['group']);
        $this->assertArrayHasKey('media_mobile_id', BlockCatalogue::for('hero')->fields()['side']['item']);
    }

    public function test_el_editor_lo_guarda_y_la_tienda_lo_recibe(): void
    {
        $block = $this->addBlock('banner_grid', ['layout' => '2', 'tiles' => [['kind' => 'color', 'title' => 'Ofertas'], ['kind' => 'color', 'title' => 'Nuevo']]]);

        $tiles = $block->payload['tiles'];
        $tiles[0]['style'] = ['text_position' => 'middle-center', 'title_size' => 'xl'];

        $this->editor()->patch(route('storefront.editor.blocks.update', $block), ['payload' => array_merge($block->payload, ['tiles' => $tiles])])->assertSessionHasNoErrors();

        $rendered = collect(app(StorefrontRenderer::class)->draft($this->store)['blocks'])->firstWhere('type', 'banner_grid');
        $this->assertEquals(['text_position' => 'middle-center', 'title_size' => 'xl'], $rendered['data']['tiles'][0]['style']);
        $this->assertArrayNotHasKey('style', $rendered['data']['tiles'][1]);
    }
}
