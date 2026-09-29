<?php

namespace Tests\Feature;

use App\Models\Promotion;
use App\Models\PromotionTarget;
use App\Models\SalePayment;
use App\Models\Storefront;
use App\Models\User;
use App\Services\RegisterSessionService;
use App\Services\SaleService;
use App\Services\Storefront\StorefrontPublisher;
use App\Services\Storefront\StorefrontRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsStorefront;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\Concerns\EditsStorefront;
use Tests\TestCase;

/**
 * Los bloques nuevos y los estantes renovados.
 */
class StorefrontBlocksNewTest extends TestCase
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

    public function test_el_mosaico_trae_sus_imagenes_y_sus_destinos(): void
    {
        $media = $this->mediaFor($this->company->id, 'Despensa');

        $this->addBlock('banner_grid', ['layout' => '2', 'tiles' => [
            ['kind' => 'image', 'media_id' => $media->id, 'title' => 'Despensa', 'target' => ['kind' => 'search', 'value' => 'arroz']],
            ['kind' => 'color', 'tone' => 'deal', 'title' => 'Ofertas', 'target' => ['kind' => 'page', 'value' => '/tienda/ofertas']],
        ]]);

        $tiles = app(StorefrontRenderer::class)->draft($this->store)['blocks'][0]['data']['tiles'];

        $this->assertSame($media->id, $tiles[0]['media']['id']);
        $this->assertSame('/tienda/buscar?q=arroz', $tiles[0]['target']['href']);
        $this->assertSame('/tienda/ofertas', $tiles[1]['target']['href']);
    }

    public function test_la_oferta_relampago_cuenta_hasta_que_vence_y_se_va_sola(): void
    {
        $arroz = $this->makeProduct(['name' => 'Arroz 1 kg', 'price' => 5000, 'online_price' => 5000, 'is_online_visible' => true], 20);
        $promotion = Promotion::query()->withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'name' => 'Relámpago', 'type' => Promotion::TYPE_SPECIAL_PRICE, 'config' => [],
            'applies_online' => true, 'applies_pos' => true, 'is_active' => true, 'starts_at' => now()->subHour(), 'ends_at' => now()->addHours(3),
        ]);
        PromotionTarget::query()->create(['promotion_id' => $promotion->id, 'target_type' => PromotionTarget::TYPE_PRODUCT, 'target_id' => $arroz->id, 'value' => 3900, 'quantity' => 1]);

        $this->addBlock('deal_countdown', ['source' => 'next_ending']);
        $this->publishSite($this->storefront);

        $block = app(StorefrontRenderer::class)->published($this->store)['blocks'][0];
        $this->assertSame('Relámpago', $block['data']['promotion']['name']);
        $this->assertSame(3900, $block['data']['products'][0]['price']);

        // Vencida, no deja hueco.
        $this->travel(4)->hours();
        app(StorefrontPublisher::class)->forget($this->storefront);
        $this->assertSame([], app(StorefrontRenderer::class)->published($this->store)['blocks']);
        $this->travelBack();
    }

    public function test_la_franja_de_whatsapp_usa_el_numero_de_la_sede(): void
    {
        $this->store->update(['phone' => '300 123 4567']);
        $this->addBlock('cta_band', []);

        $data = app(StorefrontRenderer::class)->draft($this->store->refresh())['blocks'][0]['data'];

        $this->assertStringStartsWith('https://wa.me/573001234567?text=', $data['target']['href']);
    }

    public function test_el_estante_se_desliza_y_trae_lo_nuevo_primero(): void
    {
        $viejo = $this->makeProduct(['name' => 'Viejo', 'is_online_visible' => true], 10);
        $viejo->forceFill(['created_at' => now()->subMonth()])->save();
        $nuevo = $this->makeProduct(['name' => 'Nuevo', 'is_online_visible' => true], 10);

        $this->addBlock('product_grid', ['source' => 'new_arrivals', 'layout' => 'carousel', 'limit' => 5]);
        $data = app(StorefrontRenderer::class)->draft($this->store)['blocks'][0]['data'];

        $this->assertSame('carousel', $data['layout']);
        $this->assertSame([$nuevo->id, $viejo->id], array_column($data['products'], 'id'));
    }

    public function test_vuelve_a_comprar_solo_con_sesion_y_compras_de_esta_empresa(): void
    {
        $arroz = $this->makeProduct(['name' => 'Arroz 1 kg', 'price' => 5000, 'tax_rate' => 0, 'is_online_visible' => true], 20);
        $customer = $this->makeCustomer();

        $this->addBlock('product_grid', ['title' => 'Vuelve a comprar', 'source' => 'buy_again']);
        $this->publishSite($this->storefront);
        $renderer = app(StorefrontRenderer::class);

        // Sin sesion (o sin compras) el estante ni llega.
        $this->assertSame([], $renderer->published($this->store)['blocks']);
        $this->assertSame([], $renderer->published($this->store, context: ['customer' => $customer])['blocks']);

        $session = app(RegisterSessionService::class)->open($this->register, $this->admin, 100000);
        app(SaleService::class)->create([
            'items' => [['product_id' => $arroz->id, 'quantity' => 1]],
            'payments' => [['method' => SalePayment::METHOD_CASH, 'amount' => 5000, 'received_amount' => 5000]],
            'customer_id' => $customer->id,
        ], $this->admin, $this->store, $session);

        $blocks = $renderer->published($this->store, context: ['customer' => $customer])['blocks'];
        $this->assertSame($arroz->id, $blocks[0]['data']['products'][0]['id']);

        // Un cliente de otra empresa no ve compras de esta.
        ['company' => $other] = $this->makeOtherCompany();
        $stranger = $this->makeCustomer(['company_id' => $other->id, 'document_number' => '1017000999']);
        $this->assertSame([], $renderer->published($this->store, context: ['customer' => $stranger])['blocks']);
    }

    public function test_un_banner_sin_texto_alternativo_no_se_publica(): void
    {
        $sinAlt = $this->mediaFor($this->company->id, '');
        $pesada = $this->mediaFor($this->company->id, 'Frutas', 2_000_000);

        $this->addBlock('hero', ['slides' => [['media_id' => $sinAlt->id, 'title' => 'Hola']]]);
        $this->addBlock('promo_carousel', ['slides' => [['media_id' => $pesada->id]]]);

        $checks = app(StorefrontPublisher::class)->validate($this->storefront->refresh());
        $blocking = implode(' | ', array_column($checks['blocking'], 'message'));
        $warnings = implode(' | ', array_column($checks['warnings'], 'message'));

        $this->assertStringContainsString('no tiene texto alternativo', $blocking);
        $this->assertStringContainsString('pesa más de 1 MB', $warnings);

        $this->editor()->post(route('storefront.editor.publish'))->assertSessionHasErrors('publish');
    }
}
