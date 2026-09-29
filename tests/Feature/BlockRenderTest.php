<?php

namespace Tests\Feature;

use App\Models\MediaAsset;
use App\Models\Storefront;
use App\Models\StorefrontBlock;
use App\Models\User;
use App\Services\Storefront\StorefrontManager;
use App\Services\Storefront\StorefrontPublisher;
use App\Services\Storefront\StorefrontRenderer;
use App\Storefront\BlockCatalogue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\Concerns\EditsStorefront;
use Tests\TestCase;

/**
 * Los bloques en la tienda.
 *
 * El catalogo y el renderizador tienen que ir del brazo: un tipo que el editor ofrece y la
 * tienda no sabe pintar es un bloque que el cliente nunca ve.
 */
class BlockRenderTest extends TestCase
{
    use CreatesCompanyEnvironment, EditsStorefront, RefreshDatabase;

    protected User $admin;

    protected Storefront $storefront;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEnvironment();

        $this->admin = $this->makeUser('admin');
        $this->storefront = app(StorefrontManager::class)->for($this->store);
        $this->makeStorefrontPublishable($this->storefront);
    }

    public function test_cada_tipo_del_catalogo_se_renderiza_con_su_contenido(): void
    {
        $this->makeProduct(['name' => 'Arroz visible', 'is_online_visible' => true], 30);
        $media = $this->makeMedia();

        foreach (BlockCatalogue::names() as $type) {
            $this->addBlock($type, $this->payloadFor($type, $media));
        }

        $rendered = app(StorefrontRenderer::class)->draft($this->store);

        $this->assertCount(count(BlockCatalogue::names()), $rendered['blocks']);

        foreach ($rendered['blocks'] as $block) {
            $this->assertContains($block['type'], BlockCatalogue::names());
            $this->assertIsArray($block['data'], "El bloque {$block['type']} no trajo datos.");
            $this->assertNotSame([], $block['data'], "El bloque {$block['type']} llego vacio.");
        }
    }

    public function test_un_bloque_programado_fuera_de_ventana_no_aparece(): void
    {
        $vigente = $this->addBlock('announcement', ['text' => 'Aviso vigente']);
        $futuro = $this->addBlock('announcement', ['text' => 'Aviso de diciembre']);
        $vencido = $this->addBlock('announcement', ['text' => 'Aviso de enero']);

        $futuro->update(['visible_from' => now()->addWeek()]);
        $vencido->update(['visible_to' => now()->subDay()]);

        app(StorefrontPublisher::class)->publish($this->storefront->refresh(), $this->admin, 'Base');

        $uuids = array_column($this->published()['blocks'], 'uuid');

        $this->assertContains($vigente->uuid, $uuids);
        $this->assertNotContains($futuro->uuid, $uuids);
        $this->assertNotContains($vencido->uuid, $uuids);
    }

    public function test_un_bloque_oculto_no_llega_al_cliente(): void
    {
        $visible = $this->addBlock('announcement', ['text' => 'Se ve']);
        $oculto = $this->addBlock('announcement', ['text' => 'No se ve']);

        $oculto->update(['is_visible' => false]);

        app(StorefrontPublisher::class)->publish($this->storefront->refresh(), $this->admin, 'Base');

        $uuids = array_column($this->published()['blocks'], 'uuid');

        $this->assertContains($visible->uuid, $uuids);
        $this->assertNotContains($oculto->uuid, $uuids);
    }

    public function test_un_bloque_solo_de_celular_no_aparece_en_escritorio(): void
    {
        $ambos = $this->addBlock('announcement', ['text' => 'Para todos']);
        $celular = $this->addBlock('announcement', ['text' => 'Solo celular']);

        $celular->update(['devices' => StorefrontBlock::DEVICE_MOBILE]);

        app(StorefrontPublisher::class)->publish($this->storefront->refresh(), $this->admin, 'Base');

        $snapshot = $this->storefront->refresh()->publishedVersion->snapshot;
        $renderer = app(StorefrontRenderer::class);

        $escritorio = array_column($renderer->render($snapshot, $this->store, 'desktop')['blocks'], 'uuid');
        $movil = array_column($renderer->render($snapshot, $this->store, 'mobile')['blocks'], 'uuid');

        $this->assertContains($ambos->uuid, $escritorio);
        $this->assertNotContains($celular->uuid, $escritorio);
        $this->assertContains($celular->uuid, $movil);
    }

    public function test_la_vista_previa_muestra_los_bloques_ocultos_y_programados(): void
    {
        $oculto = $this->addBlock('announcement', ['text' => 'No se ve todavia']);
        $oculto->update(['is_visible' => false]);

        $draft = app(StorefrontRenderer::class)->draft($this->store);
        $block = collect($draft['blocks'])->firstWhere('uuid', $oculto->uuid);

        // En la vista previa aparece, pero marcado: quien edita tiene que verlo para moverlo.
        $this->assertNotNull($block);
        $this->assertFalse($block['is_visible']);
        $this->assertTrue($block['scheduled']);
    }

    /**
     * @return array<string, mixed>
     */
    protected function published(): array
    {
        app(StorefrontPublisher::class)->forget($this->storefront->refresh());

        return app(StorefrontRenderer::class)->published($this->store);
    }

    protected function makeMedia(): MediaAsset
    {
        return MediaAsset::create([
            'company_id' => $this->company->id,
            'disk' => 'public',
            'path' => 'media/prueba.webp',
            'variants' => ['wide' => 'media/prueba-wide.webp'],
            'mime_type' => 'image/webp',
            'size' => 12000,
            'width' => 1600,
            'height' => 900,
            'alt' => 'Frutas frescas en el mercado',
            'uploaded_by_user_id' => $this->admin->id,
        ]);
    }

    /**
     * Contenido minimo para que cada tipo tenga algo que pintar.
     *
     * @return array<string, mixed>
     */
    protected function payloadFor(string $type, MediaAsset $media): array
    {
        return match ($type) {
            'announcement' => ['text' => 'Domicilio gratis hoy'],
            'hero' => ['title' => 'Mercado fresco', 'media_id' => $media->id],
            'categories' => ['title' => 'Categorías', 'category_ids' => [$this->category->id]],
            'product_grid' => ['title' => 'Lo más pedido', 'source' => 'category', 'category_id' => $this->category->id],
            'promo_carousel' => ['title' => 'Promociones'],
            'benefits' => ['items' => [['icon' => 'truck', 'title' => 'Domicilio', 'text' => 'En 40 minutos']]],
            'rich_text' => ['body' => 'Somos el mercado del barrio desde 1998.'],
            'faq' => ['items' => [['question' => '¿Hacen domicilios?', 'answer' => 'Sí, todos los días.']]],
            'store_info' => ['title' => 'Dónde estamos'],
            default => [],
        };
    }
}
