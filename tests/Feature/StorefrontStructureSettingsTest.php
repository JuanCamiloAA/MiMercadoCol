<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\Storefront;
use App\Models\User;
use App\Services\Storefront\StorefrontManager;
use App\Services\Storefront\StorefrontPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsStorefront;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\Concerns\EditsStorefront;
use Tests\TestCase;

/**
 * La estructura global de la tienda: cada clave con su request, listas cerradas y destinos
 * validos; y como todo lo demas, se versiona, se publica, se restaura y se copia entre sedes.
 */
class StorefrontStructureSettingsTest extends TestCase
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

    public function test_la_barra_superior_solo_acepta_sus_variables(): void
    {
        $value = app(StorefrontManager::class)->defaultSettings()['topbar'];

        $value['messages'] = [['icon' => 'Truck', 'text' => 'Llega en {tiempo}', 'target' => null]];
        $this->editor()->patch(route('storefront.editor.structure', 'topbar'), ['value' => $value])->assertSessionHasErrors('value.messages.0.text');

        $value['messages'] = [['icon' => 'Truck', 'text' => 'Domicilio {domicilio} en {sede}', 'target' => ['kind' => 'page', 'value' => '/tienda/ofertas']]];
        $value['tone'] = 'arcoiris';
        $this->editor()->patch(route('storefront.editor.structure', 'topbar'), ['value' => $value])->assertSessionHasErrors('value.tone');

        $value['tone'] = 'deal';
        $this->editor()->patch(route('storefront.editor.structure', 'topbar'), ['value' => $value])->assertSessionHasNoErrors();

        $this->assertSame('deal', $this->storefront->refresh()->setting('topbar')['tone']);
    }

    public function test_listas_cerradas_y_destinos_seguros(): void
    {
        $this->editor()->patch(route('storefront.editor.structure', 'mobile_nav'), ['value' => ['items' => ['home', 'search']]])->assertSessionHasErrors('value.items');
        $this->editor()->patch(route('storefront.editor.structure', 'mobile_nav'), ['value' => ['items' => ['home', 'home', 'search']]])->assertSessionHasErrors();
        $this->editor()->patch(route('storefront.editor.structure', 'mobile_nav'), ['value' => ['items' => ['home', 'categories', 'cart']]])->assertSessionHasNoErrors();

        $nav = app(StorefrontManager::class)->defaultSettings()['nav'];
        $nav['items'] = [['label' => 'Truco', 'icon' => null, 'target' => ['kind' => 'url', 'value' => 'javascript:alert(1)'], 'highlight' => false]];
        $this->editor()->patch(route('storefront.editor.structure', 'nav'), ['value' => $nav])->assertSessionHasErrors('value.items.0.target');

        $footer = app(StorefrontManager::class)->defaultSettings()['footer'];
        $footer['social'] = [['network' => 'instagram', 'url' => 'http://instagram.com/lacanasta']];
        $this->editor()->patch(route('storefront.editor.structure', 'footer'), ['value' => $footer])->assertSessionHasErrors('value.social.0.url');

        // La tarjeta se guarda por Aspecto; una opcion que no existe no entra.
        $card = app(StorefrontManager::class)->defaultSettings()['card'];
        $card['stock_display'] = 'siempre';
        $this->editor()->patch(route('storefront.editor.look', 'card'), ['value' => $card])->assertSessionHasErrors('value.stock_display');
    }

    public function test_el_rojo_de_oferta_y_la_marca_se_validan(): void
    {
        $this->editor()->patch(route('storefront.editor.theme'), ['deal_color' => '#FFB3B3'])->assertSessionHasErrors('deal_color');
        $this->editor()->patch(route('storefront.editor.theme'), ['deal_color' => '#B42318'])->assertSessionHasNoErrors();

        ['company' => $other] = $this->makeOtherCompany();
        $ajena = $this->mediaFor($other->id, 'Logo ajeno');
        $plataforma = $this->mediaFor(null, 'Banner de la plantilla');

        $this->editor()->patch(route('storefront.editor.theme'), ['logo_media_id' => $ajena->id])->assertSessionHasErrors('logo_media_id');
        $this->editor()->patch(route('storefront.editor.theme'), ['mark_media_id' => $plataforma->id])->assertSessionHasErrors('mark_media_id');
        $this->editor()->patch(route('storefront.editor.theme'), ['mark_media_id' => $this->mediaFor($this->company->id, 'Isotipo')->id])->assertSessionHasNoErrors();
    }

    public function test_sin_tratamiento_de_datos_no_se_publica(): void
    {
        $footer = app(StorefrontManager::class)->defaultSettings()['footer'];
        $footer['privacy_target'] = null;
        $this->storefront->settings()->updateOrCreate(['key' => 'footer'], ['value' => $footer]);

        $messages = array_column(app(StorefrontPublisher::class)->validate($this->storefront->refresh())['blocking'], 'message');

        $this->assertContains('El pie no tiene enlace a la política de tratamiento de datos.', $messages);
    }

    public function test_la_estructura_se_publica_y_se_restaura_con_la_version(): void
    {
        $topbar = app(StorefrontManager::class)->defaultSettings()['topbar'];
        $topbar['tone'] = 'accent';
        $this->editor()->patch(route('storefront.editor.structure', 'topbar'), ['value' => $topbar])->assertSessionHasNoErrors();
        $first = app(StorefrontPublisher::class)->publish($this->storefront->refresh(), $this->admin, 'Acento');

        $topbar['tone'] = 'deal';
        $this->editor()->patch(route('storefront.editor.structure', 'topbar'), ['value' => $topbar])->assertSessionHasNoErrors();
        $second = app(StorefrontPublisher::class)->publish($this->storefront->refresh(), $this->admin, 'Rojo');

        $this->assertContains('barra superior', app(StorefrontPublisher::class)->diff($first->snapshot, $second->snapshot));

        $this->editor()->post(route('storefront.editor.restore', $first))->assertSessionHasNoErrors();

        $this->assertSame('accent', $this->storefront->refresh()->setting('topbar')['tone']);
        $this->assertSame('accent', $this->storefront->publishedVersion->snapshot['settings']['topbar']['tone']);
    }

    public function test_copiar_a_otras_sedes_lleva_la_estructura(): void
    {
        $norte = Store::query()->withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'name' => 'Norte', 'code' => 'NOR', 'is_active' => true,
        ]);

        $header = app(StorefrontManager::class)->defaultSettings()['header'];
        $header['style'] = 'brand';
        $this->editor()->patch(route('storefront.editor.structure', 'header'), ['value' => $header])->assertSessionHasNoErrors();

        $this->editor()->post(route('storefront.editor.copy'), ['direction' => 'to_all', 'parts' => ['settings']])->assertSessionHasNoErrors();

        $this->assertSame('brand', app(StorefrontManager::class)->for($norte)->setting('header')['style']);
    }
}
