<?php

namespace Tests\Feature;

use App\Models\Storefront;
use App\Models\User;
use App\Services\Storefront\StorefrontRenderer;
use App\Storefront\BlockCatalogue;
use App\Storefront\Blocks\BlockData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsStorefront;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\Concerns\EditsStorefront;
use Tests\TestCase;

/**
 * Los bloques nuevos (imagen y texto, marcas, cinta, compra el look) y las variantes de los de
 * siempre: se normalizan, se resumen, quedan vacíos cuando deben y una plantilla no guarda ids de
 * una empresa.
 */
class StorefrontNewBlocksTest extends TestCase
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

    public function test_los_bloques_nuevos_existen_y_se_normalizan(): void
    {
        foreach (['image_text', 'brand_logos', 'ticker', 'look'] as $type) {
            $this->assertTrue(BlockCatalogue::has($type), $type);
        }

        $ticker = BlockCatalogue::for('ticker')->normalize(['items' => [['text' => 'Nueva colección'], ['text' => '']], 'tone' => 'arcoiris', 'speed' => 'fast']);
        $this->assertSame('accent', $ticker['tone']);
        $this->assertSame('fast', $ticker['speed']);
        $this->assertSame('Cinta — Nueva colección', BlockCatalogue::for('ticker')->summary($ticker));
        $this->assertFalse(BlockCatalogue::for('ticker')->isEmpty($ticker));
        $this->assertTrue(BlockCatalogue::for('ticker')->isEmpty(['items' => [['text' => ' ']]]));

        $image = BlockCatalogue::for('image_text');
        $this->assertTrue($image->isEmpty($image->normalize(['title' => 'Sin foto'])));
        $this->assertTrue($image->isEmpty($image->normalize(['media_id' => 5, 'title' => ''])));
        $this->assertFalse($image->isEmpty($image->normalize(['media_id' => 5, 'title' => 'Con foto'])));

        $brands = BlockCatalogue::for('brand_logos');
        $this->assertTrue($brands->isEmpty($brands->normalize(['items' => [['text' => '', 'media_id' => null]]])));
        $this->assertFalse($brands->isEmpty($brands->normalize(['items' => [['text' => 'FUERTE']]])));
    }

    public function test_en_plantilla_ningun_bloque_guarda_ids_de_una_empresa(): void
    {
        $look = BlockCatalogue::for('look');

        $this->assertArrayNotHasKey('product_ids', $look->fields(BlockData::CONTEXT_TEMPLATE));
        $this->assertNotEmpty($look->violations(['source' => 'manual', 'product_ids' => [4, 5]]));
        $this->assertSame([], $look->violations(['source' => 'best_sellers']));
        $this->assertSame([], $look->normalize(['source' => 'best_sellers', 'product_ids' => [4]], BlockData::CONTEXT_TEMPLATE)['product_ids']);

        // Las categorías de la plantilla traen fotos de ejemplo, no categorías de nadie.
        $categories = BlockCatalogue::for('categories');
        $this->assertArrayHasKey('preview_media', $categories->fields(BlockData::CONTEXT_TEMPLATE));
        $this->assertArrayNotHasKey('preview_media', $categories->fields());
    }

    public function test_una_plantilla_rechaza_un_logo_de_una_empresa(): void
    {
        $template = $this->makeTemplate('Moda', [['brand_logos', 'home', ['items' => []]]], default: false);
        $uuid = $template->draft['blocks'][0]['uuid'];
        $company = $this->mediaFor($this->company->id);
        $platform = $this->mediaFor(null);
        $super = $this->makeSuperAdmin();

        $this->actingAs($super)->patch(route('platform.store_templates.blocks.update', [$template, $uuid]), ['payload' => ['items' => [['media_id' => $company->id, 'text' => '']]]])
            ->assertSessionHasErrors('payload');

        $this->actingAs($super)->patch(route('platform.store_templates.blocks.update', [$template, $uuid]), ['payload' => ['items' => [['media_id' => $platform->id, 'text' => ''], ['media_id' => null, 'text' => 'FUERTE']]]])
            ->assertSessionHasNoErrors();
    }

    public function test_las_variantes_de_los_bloques_de_siempre(): void
    {
        $hero = BlockCatalogue::for('hero')->normalize(['layout' => 'cover_center', 'wordmark' => true, 'subtitle' => 'Moda contemporánea', 'height' => 'full', 'slides' => [['title' => '']]]);
        $this->assertSame('cover_center', $hero['layout']);
        $this->assertTrue($hero['wordmark']);
        $this->assertSame('full', $hero['height']);

        $grid = BlockCatalogue::for('product_grid')->normalize(['columns_desktop' => 3, 'title_align' => 'center', 'arrows' => 'top', 'scrollbar' => true]);
        $this->assertSame(3, $grid['columns_desktop']);
        $this->assertSame('top', $grid['arrows']);

        // Las opciones numéricas llegan como número (como «2» o «3» del mosaico).
        $this->assertEquals('1', BlockCatalogue::for('banner_grid')->normalize(['layout' => '1'])['layout']);
        $this->assertSame('bold', BlockCatalogue::for('rich_text')->normalize(['style' => 'bold'])['style']);
        $this->assertSame('inline', BlockCatalogue::for('benefits')->normalize(['style' => 'inline'])['style']);

        // Un lanzamiento sin fecha por venir no tiene nada que contar.
        $countdown = BlockCatalogue::for('deal_countdown');
        $this->assertTrue($countdown->isEmpty($countdown->normalize(['style' => 'drop'])));
        $this->assertTrue($countdown->isEmpty($countdown->normalize(['style' => 'drop', 'starts_at' => now()->subDay()->format('Y-m-d\TH:i')])));
        $this->assertFalse($countdown->isEmpty($countdown->normalize(['style' => 'drop', 'starts_at' => now()->addDays(3)->format('Y-m-d\TH:i')])));
    }

    public function test_la_tienda_recibe_los_bloques_nuevos_resueltos(): void
    {
        $media = $this->mediaFor($this->company->id);
        $this->makeProduct(['name' => 'Camisa lino', 'is_online_visible' => true, 'online_price' => 89000, 'price' => 89000, 'is_featured' => true], 10);
        $category = $this->category;
        // «Avísame» va al WhatsApp de la sede: sin número no habría a dónde.
        $this->store->forceFill(['phone' => '3001234567'])->save();

        $this->addBlock('image_text', ['media_id' => $media->id, 'title' => 'El taller', 'target' => ['kind' => 'whatsapp', 'value' => 'Hola']]);
        $this->addBlock('brand_logos', ['items' => [['media_id' => $media->id, 'text' => ''], ['media_id' => null, 'text' => 'FUERTE'], ['media_id' => null, 'text' => '']]]);
        $this->addBlock('deal_countdown', ['style' => 'drop', 'title' => 'Bloque 07', 'starts_at' => now()->addDays(5)->format('Y-m-d\TH:i')]);
        $this->addBlock('categories', ['style' => 'text_list', 'source' => 'all']);

        $blocks = collect(app(StorefrontRenderer::class)->draft($this->store)['blocks'])->keyBy('type');

        $this->assertSame($media->id, $blocks['image_text']['data']['media']['id']);
        $this->assertCount(2, $blocks['brand_logos']['data']['items']);
        $this->assertNotNull($blocks['deal_countdown']['data']['drop']);
        $this->assertStringContainsString('wa.me', (string) ($blocks['deal_countdown']['data']['drop']['notify']['href'] ?? ''));

        $item = collect($blocks['categories']['data']['items'])->firstWhere('category_id', $category->id);
        $this->assertSame(1, $item['count']);
    }
}
