<?php

namespace Tests\Feature;

use App\Models\MediaAsset;
use App\Models\Store;
use App\Models\Storefront;
use App\Models\StorefrontTemplate;
use App\Models\StorefrontVersion;
use App\Models\User;
use App\Services\Platform\CompanyProvisioner;
use App\Services\Storefront\StorefrontManager;
use App\Services\Storefront\StorefrontPublisher;
use App\Services\Storefront\StorefrontRenderer;
use App\Services\Storefront\StorefrontTemplateService;
use App\Support\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\BuildsStorefront;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\TestCase;

/**
 * La plantilla base del super usuario: las tiendas nuevas la siguen, reciben cada
 * publicacion con su propia marca, se independizan copiando sus imagenes y pueden volver.
 */
class StorefrontTemplateTest extends TestCase
{
    use BuildsStorefront, CreatesCompanyEnvironment, RefreshDatabase;

    protected User $super;

    protected User $admin;

    protected MediaAsset $banner;

    protected StorefrontTemplate $template;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEnvironment();
        Storage::fake('public');

        $this->super = $this->makeSuperAdmin();
        $this->admin = $this->makeUser('admin');

        $this->banner = $this->mediaFor(null, 'Frutas frescas');
        Storage::disk('public')->put($this->banner->path, 'jpg');

        $this->template = $this->makeTemplate('Supermercado', [
            ['hero', 'home', ['slides' => [['media_id' => $this->banner->id, 'title' => 'Fresco todos los días']]]],
            ['product_grid', 'home', ['title' => 'Ofertas', 'source' => 'on_sale']],
        ]);
    }

    protected function newStore(string $name = 'Norte'): Store
    {
        $store = Store::query()->withoutGlobalScopes()->create(['company_id' => $this->company->id, 'name' => $name, 'code' => strtoupper(substr($name, 0, 3)), 'is_active' => true]);
        // El editor trabaja sobre la sede activa, y solo si la persona tiene acceso a ella.
        $this->admin->stores()->syncWithoutDetaching([$store->id]);

        return $store;
    }

    protected function followerSite(): Storefront
    {
        $store = $this->newStore();
        $storefront = app(StorefrontManager::class)->for($store);
        $storefront->settings()->updateOrCreate(['key' => 'coverage'], ['value' => ['mode' => 'radius', 'radius_km' => 3]]);
        $storefront->theme()->update(['accent' => '#0F5F8A', 'display_name' => 'Mercado Norte']);
        app(StorefrontPublisher::class)->publish($storefront->refresh(), $this->admin);
        $storefront->forceFill(['is_online' => true])->save();

        return $storefront->refresh();
    }

    public function test_una_tienda_nueva_la_sigue_y_se_ve_con_su_marca(): void
    {
        $storefront = $this->followerSite();

        $this->assertTrue($storefront->follows_template);
        $this->assertSame($this->template->id, $storefront->template_id);

        $page = app(StorefrontRenderer::class)->published($storefront->store);
        $this->assertSame('hero', $page['blocks'][0]['type']);
        $this->assertSame('Fresco todos los días', $page['blocks'][0]['data']['slides'][0]['title']);
        // La marca y el color son de la sede.
        $this->assertSame('#0F5F8A', $page['theme']['accent']);
        $this->assertSame('Mercado Norte', $page['theme']['display_name']);
        $this->assertSame('mercado', $page['theme']['font_pair']);
    }

    public function test_publicar_la_plantilla_le_llega_a_quien_la_sigue(): void
    {
        $storefront = $this->followerSite();
        $versions = $storefront->versions()->count();

        $draft = $this->template->draft;
        $draft['blocks'][0]['payload']['slides'][0]['title'] = 'Nueva temporada';
        $this->template->forceFill(['draft' => $draft])->save();

        $this->actingAs($this->super, 'web')
            ->post(route('platform.store_templates.publish', $this->template))
            ->assertSessionHasNoErrors();

        $page = app(StorefrontRenderer::class)->published($storefront->store);
        $this->assertSame('Nueva temporada', $page['blocks'][0]['data']['slides'][0]['title']);
        // Sin tocar las versiones de la sede.
        $this->assertSame($versions, $storefront->versions()->count());
    }

    public function test_personalizar_copia_las_imagenes_y_ya_no_recibe_cambios(): void
    {
        $storefront = $this->followerSite();

        $this->actingAs($this->admin, 'web')
            ->withSession([StoreContext::SESSION_KEY => $storefront->store_id])
            ->post(route('storefront.editor.template.detach'))
            ->assertSessionHasNoErrors();

        $storefront->refresh();
        $this->assertFalse($storefront->follows_template);

        // El banner ahora es de la empresa, con su propio archivo.
        $hero = $storefront->blocks()->where('type', 'hero')->firstOrFail();
        $copy = MediaAsset::query()->withoutGlobalScopes()->findOrFail($hero->payload['slides'][0]['media_id']);
        $this->assertSame($this->company->id, $copy->company_id);
        $this->assertNotSame($this->banner->path, $copy->path);
        Storage::disk('public')->assertExists($copy->path);

        // Lo que ve el cliente no cambio: se publico con el mismo diseño.
        $this->assertSame('Fresco todos los días', app(StorefrontRenderer::class)->published($storefront->store)['blocks'][0]['data']['slides'][0]['title']);

        // Un cambio nuevo de la plantilla ya no le llega…
        $draft = $this->template->draft;
        $draft['blocks'][0]['payload']['slides'][0]['title'] = 'Solo para las que siguen';
        $this->template->forceFill(['draft' => $draft])->save();
        app(StorefrontTemplateService::class)->publish($this->template->refresh(), $this->super);
        app(StorefrontPublisher::class)->forget($storefront);
        $this->assertSame('Fresco todos los días', app(StorefrontRenderer::class)->published($storefront->store)['blocks'][0]['data']['slides'][0]['title']);

        // …y borrar la plantilla y su imagen no le rompe nada.
        $this->banner->delete();
        $this->template->delete();
        app(StorefrontPublisher::class)->forget($storefront);
        $page = app(StorefrontRenderer::class)->published($storefront->store->refresh());
        $this->assertSame($copy->id, $page['blocks'][0]['data']['slides'][0]['media']['id']);
    }

    public function test_volver_a_la_plantilla_deja_una_version_para_deshacer(): void
    {
        $storefront = $this->followerSite();
        app(StorefrontTemplateService::class)->detach($storefront, $this->admin);
        $storefront->refresh();

        $this->actingAs($this->admin, 'web')
            ->withSession([StoreContext::SESSION_KEY => $storefront->store_id])
            ->post(route('storefront.editor.template.reattach'), ['template_id' => $this->template->id])
            ->assertSessionHasNoErrors();

        $storefront->refresh();
        $this->assertTrue($storefront->follows_template);

        $saved = StorefrontVersion::query()->where('storefront_id', $storefront->id)->latest('number')->firstOrFail();
        $this->assertNull($saved->published_at);
        $this->assertFalse($saved->snapshot['template']['follows']);

        // Deshacer es volver a esa version: queda otra vez con diseño propio.
        app(StorefrontPublisher::class)->restore($storefront, $saved, $this->admin);
        $this->assertFalse($storefront->refresh()->follows_template);
    }

    public function test_en_la_plantilla_no_caben_ids_de_una_empresa(): void
    {
        $uuid = $this->template->draft['blocks'][1]['uuid'];

        $this->actingAs($this->super, 'web')
            ->patch(route('platform.store_templates.blocks.update', [$this->template, $uuid]), [
                'payload' => ['title' => 'A mano', 'source' => 'manual', 'product_ids' => [1, 2]],
            ])
            ->assertSessionHasErrors('payload');

        $nav = app(StorefrontManager::class)->defaultSettings()['nav'];
        $nav['items'] = [['label' => 'Aseo', 'icon' => null, 'target' => ['kind' => 'category', 'value' => '3'], 'highlight' => false]];

        $this->actingAs($this->super, 'web')
            ->patch(route('platform.store_templates.settings', [$this->template, 'nav']), ['value' => $nav])
            ->assertSessionHasErrors('value.items.0.target');

        // El editor de plantillas no ofrece fuentes por id.
        $this->actingAs($this->super, 'web')->get(route('platform.store_templates.edit', $this->template))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('context', 'template')
                ->where('catalogue', fn ($catalogue) => ! array_key_exists('product_ids', collect($catalogue)->firstWhere('type', 'product_grid')['fields'])));
    }

    public function test_una_sola_por_defecto(): void
    {
        $otra = $this->makeTemplate('Minimalista', [], default: false);

        app(StorefrontTemplateService::class)->setDefault($otra);

        $this->assertSame([$otra->id], StorefrontTemplate::query()->where('is_default', true)->pluck('id')->all());

        $this->expectException(ValidationException::class);
        app(StorefrontTemplateService::class)->setActive($otra->refresh(), false);
    }

    public function test_una_empresa_nueva_nace_con_la_plantilla_por_defecto(): void
    {
        $result = app(CompanyProvisioner::class)->provision([
            'company' => ['name' => 'Autoservicio La 80', 'tax_id' => '901000111-2'],
            'admin' => ['name' => 'Paula', 'email' => 'paula@la80.co'],
        ], $this->super);

        $storefront = Storefront::query()->withoutGlobalScopes()->where('store_id', $result->store->id)->firstOrFail();

        $this->assertTrue($storefront->follows_template);
        $this->assertSame($this->template->id, $storefront->template_id);
        // Nace apagada: la primera publicacion la enciende.
        $this->assertFalse($storefront->is_online);
    }

    public function test_con_la_plantilla_el_diseno_no_se_toca_sin_personalizar(): void
    {
        $storefront = $this->followerSite();
        $session = [StoreContext::SESSION_KEY => $storefront->store_id];

        $this->actingAs($this->admin, 'web')->withSession($session)
            ->post(route('storefront.editor.blocks.store'), ['type' => 'faq', 'page' => 'home'])
            ->assertSessionHasErrors('template');

        // Lo de la sede si: el domicilio y el contacto del pie.
        $delivery = app(StorefrontManager::class)->defaultSettings()['delivery'];
        $delivery['fee'] = 7000;
        $this->actingAs($this->admin, 'web')->withSession($session)
            ->patch(route('storefront.editor.settings', 'delivery'), ['value' => $delivery])
            ->assertSessionHasNoErrors();

        $footer = app(StorefrontManager::class)->defaultSettings()['footer'];
        $footer['contact']['whatsapp'] = '3001234567';
        $footer['columns'] = [];
        $this->actingAs($this->admin, 'web')->withSession($session)
            ->patch(route('storefront.editor.structure', 'footer'), ['value' => $footer])
            ->assertSessionHasNoErrors();

        $saved = $storefront->refresh()->setting('footer');
        $this->assertSame('3001234567', $saved['contact']['whatsapp']);
        // Las columnas (diseño) no se tocaron.
        $this->assertNotSame([], $saved['columns']);
    }
}
