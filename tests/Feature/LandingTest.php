<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\LandingBlock;
use App\Models\LandingVersion;
use App\Models\Store;
use App\Models\User;
use App\Services\Landing\LandingManager;
use App\Services\PlatformSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\TestCase;

/**
 * La landing de plataforma: borrador contra publicado, bloques obligatorios, historial y las
 * sedes que muestra el buscador.
 */
class LandingTest extends TestCase
{
    use CreatesCompanyEnvironment, RefreshDatabase;

    protected User $super;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEnvironment();
        $this->super = $this->makeSuperAdmin();
        app(LandingManager::class)->ensureSeeded($this->super);
    }

    protected function block(string $type): LandingBlock
    {
        return LandingBlock::query()->where('type', $type)->firstOrFail();
    }

    /** @return array<string, mixed> */
    protected function publicBlock(string $type): ?array
    {
        // Por JSON, como lo recibe el navegador: los precios por ciclo llegan como objeto.
        $blocks = json_decode(json_encode($this->get('/')->assertOk()->viewData('page')['props']['blocks']), true);

        return collect($blocks)->firstWhere('type', $type);
    }

    public function test_la_raiz_muestra_la_landing_publicada_sin_sesion(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Landing/Public')
                ->where('isPreview', false)
                ->where('panel', null)
                ->where('blocks.0.type', 'header')
                ->where('site.login_url', route('login')));
    }

    public function test_el_borrador_no_se_ve_hasta_publicar(): void
    {
        $hero = $this->block('hero');

        $this->actingAs($this->super)
            ->patchJson(route('platform.landing.blocks.update', $hero->uuid), ['data' => array_merge($hero->data, ['title' => 'Título nuevo del borrador'])])
            ->assertOk()
            ->assertJsonPath('status.has_unpublished_changes', true);

        $this->assertNotSame('Título nuevo del borrador', $this->publicBlock('hero')['data']['title']);

        // La vista previa si lo muestra.
        $this->actingAs($this->super)
            ->get(route('platform.landing.preview'))
            ->assertInertia(fn (Assert $page) => $page->where('isPreview', true)->where('blocks.1.data.title', 'Título nuevo del borrador'));

        $this->actingAs($this->super)->post(route('platform.landing.publish'), ['note' => 'Nuevo título'])->assertSessionHasNoErrors();

        auth('web')->logout();
        $this->assertSame('Título nuevo del borrador', $this->publicBlock('hero')['data']['title']);
        $this->assertSame(2, LandingVersion::query()->max('number'));
    }

    public function test_los_datos_se_limpian_al_guardar(): void
    {
        $hero = $this->block('hero');

        $this->actingAs($this->super)->patchJson(route('platform.landing.blocks.update', $hero->uuid), ['data' => [
            'title' => 'Hola',
            'cta_url' => 'javascript:alert(1)',
            'secondary_url' => '/contratar',
            'image' => '../../.env',
            'align' => 'inventado',
            'sobrante' => 'no se guarda',
        ]])->assertOk();

        $data = $hero->fresh()->data;
        $this->assertSame('', $data['cta_url']);
        $this->assertSame('/contratar', $data['secondary_url']);
        $this->assertNull($data['image']);
        $this->assertSame('left', $data['align']);
        $this->assertArrayNotHasKey('sobrante', $data);
    }

    public function test_el_buscador_no_se_borra_solo_se_oculta(): void
    {
        $finder = $this->block('store_finder');

        $this->actingAs($this->super)
            ->delete(route('platform.landing.blocks.destroy', $finder->uuid))
            ->assertSessionHasErrors('block');
        $this->assertNotNull($finder->fresh());

        $this->actingAs($this->super)
            ->patch(route('platform.landing.blocks.visibility', $finder->uuid), ['is_visible' => false])
            ->assertSessionHasNoErrors();
        $this->assertFalse($finder->fresh()->is_visible);

        // El encabezado y el pie ni se ocultan.
        $this->actingAs($this->super)
            ->patch(route('platform.landing.blocks.visibility', $this->block('header')->uuid), ['is_visible' => false])
            ->assertSessionHasErrors('block');
        $this->actingAs($this->super)
            ->delete(route('platform.landing.blocks.destroy', $this->block('footer')->uuid))
            ->assertSessionHasErrors('block');
    }

    public function test_un_bloque_unico_no_se_repite_y_los_demas_si(): void
    {
        $this->actingAs($this->super)->post(route('platform.landing.blocks.store'), ['type' => 'plans'])->assertSessionHasErrors('type');

        $this->actingAs($this->super)->post(route('platform.landing.blocks.store'), ['type' => 'faq'])->assertSessionHasNoErrors();
        $this->assertSame(2, LandingBlock::query()->where('type', 'faq')->count());

        // Lo nuevo queda antes del pie.
        $this->assertSame('footer', LandingBlock::query()->orderByDesc('position')->value('type'));
    }

    public function test_restaurar_una_version_la_publica_como_nueva(): void
    {
        $first = LandingVersion::query()->where('number', 1)->firstOrFail();
        $original = $this->block('hero')->data['title'];

        $this->actingAs($this->super)->patchJson(route('platform.landing.blocks.update', $this->block('hero')->uuid), ['data' => ['title' => 'Otra cosa']]);
        $this->actingAs($this->super)->post(route('platform.landing.publish'));
        $this->actingAs($this->super)->delete(route('platform.landing.blocks.destroy', $this->block('faq')->uuid));

        $this->actingAs($this->super)->post(route('platform.landing.restore', $first))->assertSessionHasNoErrors();

        $this->assertSame(3, LandingVersion::query()->max('number'));
        $this->assertSame($original, $this->block('hero')->data['title']);
        $this->assertSame(1, LandingBlock::query()->where('type', 'faq')->count());

        auth('web')->logout();
        $this->assertSame($original, $this->publicBlock('hero')['data']['title']);
    }

    public function test_el_buscador_solo_trae_sedes_que_pueden_vender(): void
    {
        $this->publishStorefront();
        $this->store->update(['city' => 'Medellín', 'latitude' => 6.2383, 'longitude' => -75.5698]);

        // Otra sede sin tienda publicada: no aparece.
        Store::query()->withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'name' => 'Norte', 'code' => 'NOR', 'opens_at' => '07:00:00', 'closes_at' => '21:00:00', 'suggested_cash_base' => 0, 'is_active' => true,
        ]);

        // Una empresa suspendida con su tienda publicada: no aparece.
        $other = $this->makeOtherCompany();
        $this->publishStorefront($other['store'], $this->super);
        ['plan' => $plan, 'cycle' => $cycle] = $this->makePlan([], ['online_store']);
        $other['company']->forceFill(['membership_plan_id' => $plan->id, 'billing_cycle_id' => $cycle->id, 'membership_status' => Company::MEMBERSHIP_SUSPENDED])->save();

        $stores = $this->publicBlock('store_finder')['source']['stores'];

        $this->assertCount(1, $stores);
        $this->assertSame('Centro', $stores[0]['name']);
        $this->assertSame('La Canasta', $stores[0]['company']);
        $this->assertSame(route('storefront.entry', ['la-canasta', $stores[0]['url'] ? basename($stores[0]['url']) : '']), $stores[0]['url']);

        // Reactivada pero sin la tienda en su plan: tampoco.
        $plan->update(['features' => ['promotions']]);
        $other['company']->forceFill(['membership_status' => Company::MEMBERSHIP_ACTIVE])->save();
        $this->assertCount(1, $this->publicBlock('store_finder')['source']['stores']);

        // Con la tienda en el plan, si.
        $plan->update(['features' => ['online_store']]);
        $this->assertCount(2, $this->publicBlock('store_finder')['source']['stores']);

        // Apagada, no.
        \App\Models\Storefront::query()->withoutGlobalScopes()->where('store_id', $other['store']->id)->update(['is_online' => false]);
        $this->assertCount(1, $this->publicBlock('store_finder')['source']['stores']);
    }

    public function test_sedes_cercanas_ordena_por_distancia_sin_guardar_la_ubicacion(): void
    {
        $this->publishStorefront();
        $this->store->update(['latitude' => 6.2383, 'longitude' => -75.5698]);

        $far = Store::query()->withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'name' => 'Bello', 'code' => 'BEL', 'latitude' => 6.3373, 'longitude' => -75.5448,
            'opens_at' => '07:00:00', 'closes_at' => '21:00:00', 'suggested_cash_base' => 0, 'is_active' => true,
        ]);
        $this->publishStorefront($far);

        $response = $this->postJson(route('landing.nearby'), ['lat' => 6.2442, 'lng' => -75.5812])->assertOk();

        $this->assertSame(['Centro', 'Bello'], array_column($response->json('stores'), 'name'));
        $this->assertLessThan(2, $response->json('stores.0.distance_km'));
        $this->assertGreaterThan(10, $response->json('stores.1.distance_km'));

        $this->postJson(route('landing.nearby'), ['lat' => 120, 'lng' => 0])->assertStatus(422);
    }

    public function test_los_planes_llegan_con_el_precio_del_servidor(): void
    {
        ['plan' => $plan, 'cycle' => $monthly] = $this->makePlan(['name' => 'Pro', 'monthly_price' => 189000, 'is_featured' => true]);
        $annual = \App\Models\BillingCycle::query()->create(['name' => 'Anual', 'code' => 'annual', 'months' => 12, 'discount_percent' => 15, 'is_active' => true, 'sort_order' => 4]);
        $this->makePlan(['name' => 'Privado', 'is_public' => false]);

        $source = $this->publicBlock('plans')['source'];

        $this->assertCount(1, $source['plans']);
        $this->assertSame(189000, $source['plans'][0]['prices'][(string) $monthly->id]);
        $this->assertSame(1928000, $source['plans'][0]['prices'][(string) $annual->id]);
    }

    public function test_la_marca_de_plataforma_viste_la_landing(): void
    {
        $this->actingAs($this->super)->put(route('platform.landing.settings'), [
            'platform_name' => 'Mercados Unidos',
            'brand_accent' => '#7A2E8C',
            'font_body' => 'Inter',
            'font_display' => 'Bricolage Grotesque',
            'font_numeric' => 'IBM Plex Mono',
            'terms_text' => 'Términos.',
            'privacy_text' => 'Datos.',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('#7A2E8C', app(PlatformSettingsService::class)->get('platform.brand_accent'));

        auth('web')->logout();
        $this->get('/')
            ->assertInertia(fn (Assert $page) => $page
                ->where('site.name', 'Mercados Unidos')
                ->where('brand.display_name', 'Mercados Unidos')
                ->where('brand.theme_color', '#7A2E8C'));

        $this->get(route('legal.terms'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('Landing/Legal')->where('body', 'Términos.'));
    }

    public function test_subir_una_imagen_solo_acepta_imagenes(): void
    {
        Storage::fake('public');

        $path = $this->actingAs($this->super)
            ->postJson(route('platform.landing.upload'), ['image' => UploadedFile::fake()->create('portada.jpg', 120, 'image/jpeg')])
            ->assertOk()
            ->json('path');

        Storage::disk('public')->assertExists($path);
        $this->assertMatchesRegularExpression('#^landing/\d{4}/\d{2}/[0-9a-f-]+\.jpg$#', $path);

        $this->actingAs($this->super)
            ->postJson(route('platform.landing.upload'), ['image' => UploadedFile::fake()->create('script.php', 1, 'text/x-php')])
            ->assertStatus(422);
    }

    public function test_el_editor_es_solo_del_super_usuario(): void
    {
        $this->actingAs($this->super)->get(route('platform.landing.index'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Platform/Landing/Editor')->has('catalogue', 12));

        $admin = $this->makeUser('admin');
        $this->actingAs($admin)->get(route('platform.landing.index'))->assertForbidden();
        $this->actingAs($admin)->post(route('platform.landing.publish'))->assertForbidden();
    }

    public function test_con_sesion_de_personal_el_encabezado_lleva_al_panel(): void
    {
        $this->actingAs($this->makeUser('admin'))
            ->get('/')
            ->assertInertia(fn (Assert $page) => $page->where('panel.url', route('dashboard')));
    }
}
