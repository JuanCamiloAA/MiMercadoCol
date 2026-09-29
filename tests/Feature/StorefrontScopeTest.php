<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Store;
use App\Models\Storefront;
use App\Models\User;
use App\Services\Storefront\StorefrontManager;
use App\Services\Storefront\StorefrontPublisher;
use App\Services\Storefront\StorefrontRenderer;
use App\Support\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\Concerns\EditsStorefront;
use Tests\TestCase;

/**
 * Cada sede tiene su tienda.
 *
 * Es la diferencia entre un editor y un editor por sede: publicar en Centro no puede mover
 * nada en Norte, y copiar de otra sede deja el resultado en el borrador, nunca publicado.
 */
class StorefrontScopeTest extends TestCase
{
    use CreatesCompanyEnvironment, EditsStorefront, RefreshDatabase;

    protected User $admin;

    protected Storefront $storefront;

    protected Store $north;

    protected Storefront $northStorefront;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEnvironment();

        $this->admin = $this->makeUser('admin');

        $this->north = Store::query()->withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'name' => 'Norte',
            'code' => 'NOR',
            'opens_at' => '07:00:00',
            'closes_at' => '21:00:00',
            'suggested_cash_base' => 200000,
            'is_active' => true,
        ]);

        $this->admin->stores()->syncWithoutDetaching([$this->north->id]);

        $manager = app(StorefrontManager::class);
        $this->storefront = $manager->for($this->store);
        $this->northStorefront = $manager->for($this->north);

        $this->makeStorefrontPublishable($this->storefront);
        $this->makeStorefrontPublishable($this->northStorefront);
    }

    public function test_publicar_en_una_sede_no_altera_la_de_al_lado(): void
    {
        $this->addBlock('announcement', ['text' => 'Promo del Centro'], $this->storefront);
        $this->addBlock('announcement', ['text' => 'Promo del Norte'], $this->northStorefront);

        $publisher = app(StorefrontPublisher::class);
        $publisher->publish($this->northStorefront->refresh(), $this->admin, 'Norte base');
        $publisher->publish($this->storefront->refresh(), $this->admin, 'Centro base');

        // Se cambia el Centro y se vuelve a publicar.
        $this->storefront->blocks()->first()->update(['payload' => ['text' => 'Centro renovado', 'link' => null, 'tone' => 'accent']]);
        $publisher->publish($this->storefront->refresh(), $this->admin, 'Centro nuevo');

        $renderer = app(StorefrontRenderer::class);
        $publisher->forget($this->storefront->refresh());
        $publisher->forget($this->northStorefront->refresh());

        $centro = json_encode($renderer->published($this->store), JSON_UNESCAPED_UNICODE);
        $norte = json_encode($renderer->published($this->north), JSON_UNESCAPED_UNICODE);

        $this->assertStringContainsString('Centro renovado', $centro);
        $this->assertStringContainsString('Promo del Norte', $norte);
        $this->assertStringNotContainsString('Centro renovado', $norte);
    }

    public function test_copiar_de_otra_sede_llega_al_borrador_no_a_lo_publicado(): void
    {
        $this->addBlock('announcement', ['text' => 'Portada del Centro'], $this->storefront);
        $this->storefront->theme()->update(['accent' => '#7A2E8E']);
        $this->storefront->settings()->updateOrCreate(['key' => 'delivery'], ['value' => [
            'enabled' => true, 'fee' => 7500, 'min_order_total' => 30000,
            'free_from' => 0, 'eta_minutes' => 25, 'pickup' => false, 'tip' => false,
        ]]);

        app(StorefrontPublisher::class)->publish($this->storefront->refresh(), $this->admin, 'Centro');

        // Se trabaja parado en el Norte y se copia del Centro.
        $this->actingAs($this->admin, 'web')
            ->withSession([StoreContext::SESSION_KEY => $this->north->id])
            ->post(route('storefront.editor.copy'), [
                'direction' => 'from',
                'store_id' => $this->store->id,
                'parts' => ['theme', 'blocks', 'settings'],
            ])
            ->assertSessionHasNoErrors();

        $north = $this->northStorefront->refresh();

        // El borrador del Norte ya trae lo copiado.
        $this->assertSame('#7A2E8E', $north->theme->accent);
        $this->assertSame('Portada del Centro', $north->blocks()->first()->payload['text']);
        $this->assertSame(7500, $north->setting('delivery')['fee']);

        // Pero su tienda publicada sigue vacia: copiar no publica.
        $this->assertNull($north->published_version_id);
        $this->assertSame([], app(StorefrontRenderer::class)->published($this->north)['blocks']);
    }

    public function test_no_se_copia_desde_la_sede_de_otra_empresa(): void
    {
        $otherCompany = Company::create([
            'name' => 'Otra empresa',
            'legal_name' => 'Otra S.A.S.',
            'tax_id' => '901222333-4',
            'currency' => 'COP',
            'is_active' => true,
        ]);

        $other = Store::query()->withoutGlobalScopes()->create([
            'company_id' => $otherCompany->id,
            'name' => 'Ajena',
            'code' => 'AJE',
            'opens_at' => '07:00:00',
            'closes_at' => '21:00:00',
            'suggested_cash_base' => 0,
            'is_active' => true,
        ]);

        $this->actingAs($this->admin, 'web')
            ->withSession([StoreContext::SESSION_KEY => $this->north->id])
            ->post(route('storefront.editor.copy'), [
                'direction' => 'from',
                'store_id' => $other->id,
                'parts' => ['theme'],
            ])
            ->assertNotFound();

        $this->assertNull($this->northStorefront->refresh()->published_version_id);
    }
}
