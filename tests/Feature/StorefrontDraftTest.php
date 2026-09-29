<?php

namespace Tests\Feature;

use App\Models\Storefront;
use App\Models\StorefrontBlock;
use App\Models\User;
use App\Services\Storefront\StorefrontManager;
use App\Services\Storefront\StorefrontPublisher;
use App\Services\Storefront\StorefrontRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\Concerns\EditsStorefront;
use Tests\TestCase;

/**
 * El borrador del editor.
 *
 * La promesa del editor es que se puede desarmar la portada entera sin que el cliente vea
 * nada: hasta que alguien publique, la tienda sigue mostrando lo de ayer.
 */
class StorefrontDraftTest extends TestCase
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

    public function test_editar_un_bloque_no_cambia_lo_que_ve_el_cliente_hasta_publicar(): void
    {
        $block = $this->addBlock('announcement', ['text' => 'Mercado de siempre']);

        app(StorefrontPublisher::class)->publish($this->storefront->refresh(), $this->admin, 'Primera');

        $this->assertStringContainsString('Mercado de siempre', $this->publishedText());

        $this->editor()->patch(route('storefront.editor.blocks.update', $block), [
            'payload' => ['text' => 'Mercado renovado'],
        ])->assertSessionHasNoErrors();

        // El borrador ya cambio, pero la tienda sigue con lo publicado.
        $this->assertStringContainsString('Mercado renovado', $this->draftText());
        $this->assertStringContainsString('Mercado de siempre', $this->publishedText());
        $this->assertStringNotContainsString('Mercado renovado', $this->publishedText());

        app(StorefrontPublisher::class)->publish($this->storefront->refresh(), $this->admin, 'Segunda');

        $this->assertStringContainsString('Mercado renovado', $this->publishedText());
    }

    public function test_el_guardado_automatico_actualiza_solo_el_borrador(): void
    {
        $block = $this->addBlock('rich_text', ['body' => 'Primera version']);

        app(StorefrontPublisher::class)->publish($this->storefront->refresh(), $this->admin, 'Base');

        $versions = $this->storefront->refresh()->versions()->count();

        // Tres guardados seguidos, como los que dispara escribir en el formulario.
        foreach (['Segunda', 'Tercera', 'Cuarta'] as $body) {
            $this->editor()->patch(route('storefront.editor.blocks.update', $block), [
                'payload' => ['body' => $body],
            ])->assertSessionHasNoErrors();
        }

        $this->assertSame('Cuarta', StorefrontBlock::query()->find($block->id)->payload['body']);

        // Guardar no publica ni deja versiones sueltas en el historial.
        $this->assertSame($versions, $this->storefront->refresh()->versions()->count());
        $this->assertStringContainsString('Primera version', $this->publishedText());
        $this->assertTrue(app(StorefrontManager::class)->hasUnpublishedChanges($this->storefront->refresh()));
    }

    public function test_reordenar_y_ocultar_solo_tocan_el_borrador(): void
    {
        $first = $this->addBlock('announcement', ['text' => 'Arriba']);
        $second = $this->addBlock('rich_text', ['body' => 'Abajo']);

        app(StorefrontPublisher::class)->publish($this->storefront->refresh(), $this->admin, 'Base');

        $this->editor()->post(route('storefront.editor.blocks.reorder'), [
            'order' => [$second->id, $first->id],
        ])->assertSessionHasNoErrors();

        $order = $this->storefront->refresh()->blocks()->orderBy('sort_order')->pluck('uuid')->all();

        $this->assertSame([$second->uuid, $first->uuid], $order);

        // Lo publicado conserva el orden anterior.
        $published = app(StorefrontRenderer::class)->published($this->store);
        $this->assertSame([$first->uuid, $second->uuid], array_column($published['blocks'], 'uuid'));
    }

    protected function draftText(): string
    {
        return json_encode(app(StorefrontRenderer::class)->draft($this->store), JSON_UNESCAPED_UNICODE);
    }

    protected function publishedText(): string
    {
        app(StorefrontPublisher::class)->forget($this->storefront);

        return json_encode(app(StorefrontRenderer::class)->published($this->store), JSON_UNESCAPED_UNICODE);
    }
}
