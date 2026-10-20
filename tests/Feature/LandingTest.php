<?php

namespace Tests\Feature;

use App\Landing\Blocks\StoreFinderBlock;
use App\Landing\LandingBlockCatalogue;
use App\Models\Company;
use App\Models\LandingBlock;
use App\Models\LandingVersion;
use App\Models\Store;
use App\Models\User;
use App\Services\Landing\LandingManager;
use App\Services\PlatformSettingsService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
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
        $plan->update(['entitlements' => ['promotions.*']]);
        $other['company']->forceFill(['membership_status' => Company::MEMBERSHIP_ACTIVE])->save();
        $this->assertCount(1, $this->publicBlock('store_finder')['source']['stores']);

        // Con la tienda en el plan, si.
        $plan->update(['entitlements' => ['storefront.*']]);
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
            ->assertInertia(fn (Assert $page) => $page->component('Platform/Landing/Editor')->has('catalogue', count(LandingBlockCatalogue::TYPES)));

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

    /** Guarda el buscador como lo hace el editor y devuelve lo que quedo en la base. */
    protected function saveFinder(array $data): array
    {
        $finder = $this->block('store_finder');

        $this->actingAs($this->super)
            ->patchJson(route('platform.landing.blocks.update', $finder->uuid), ['data' => $data])
            ->assertOk();

        return $finder->fresh()->data;
    }

    /** Guarda el buscador y publica la landing, como al tocar «Publicar». */
    protected function publishFinder(array $changes): void
    {
        $this->saveFinder(array_merge($this->block('store_finder')->data, $changes));
        $this->actingAs($this->super)->post(route('platform.landing.publish'))->assertSessionHasNoErrors();
        auth('web')->logout();
    }

    /** Un borrador de ajuste de la tienda (domicilio, franjas…), sin publicar. */
    protected function draftSetting(\App\Models\Storefront $storefront, string $key, array $value): void
    {
        $storefront->settings()->updateOrCreate(['key' => $key], ['value' => $value]);
    }

    protected function at(string $time): void
    {
        $now = CarbonImmutable::parse($time, 'America/Bogota');
        Carbon::setTestNow($now);
        CarbonImmutable::setTestNow($now);
    }

    public function test_un_buscador_sin_diseno_vale_como_lista_con_mapa_y_conserva_su_mapa(): void
    {
        // Como quedo guardado antes de los tres diseños: solo con las claves de entonces.
        $before = [
            'title' => 'Encuentra tu tienda', 'text' => '', 'default_city' => '', 'show_hours' => true, 'show_distance' => true, 'empty_text' => 'Nada.',
            'map_layout' => 'side', 'map_height' => 'lg', 'map_theme' => 'dark', 'map_cluster' => false, 'map_expand' => false, 'map_search_area' => false,
        ];

        $data = $this->saveFinder($before);

        $this->assertSame(StoreFinderBlock::LAYOUT_LIST_MAP, $data['layout']);
        $this->assertSame(['side', 'lg', 'dark', false, false, false], [$data['map_layout'], $data['map_height'], $data['map_theme'], $data['map_cluster'], $data['map_expand'], $data['map_search_area']]);
        $this->assertSame('Comprar en esta tienda', $data['cta_label']);

        // Una version publicada con esa forma vale igual en la pagina publica, sin migrar nada.
        $version = LandingVersion::query()->orderByDesc('number')->firstOrFail();
        $version->forceFill(['snapshot' => collect($version->snapshot)->map(fn (array $block) => $block['type'] === 'store_finder' ? [...$block, 'data' => $before] : $block)->all()])->save();

        $public = $this->publicBlock('store_finder')['data'];
        $this->assertSame('list_map', $public['layout']);
        $this->assertSame('lg', $public['map_height']);
        $this->assertFalse($public['map_cluster']);
        $this->assertSame('sheet', $public['mobile_mode']);
    }

    public function test_cada_diseno_solo_admite_su_mapa(): void
    {
        $finder = $this->block('store_finder');

        // El editor recibe lo que quedo: con eso corrige el select.
        $this->actingAs($this->super)
            ->patchJson(route('platform.landing.blocks.update', $finder->uuid), ['data' => ['layout' => 'list_map', 'map_layout' => 'button']])
            ->assertOk()
            ->assertJsonPath('block.data.map_layout', 'side');

        $this->assertSame('button', $this->saveFinder(['layout' => 'by_company', 'map_layout' => 'top'])['map_layout']);
        $this->assertSame('button', $this->saveFinder(['layout' => 'nearest_first', 'map_layout' => 'side'])['map_layout']);
        $this->assertSame('top', $this->saveFinder(['layout' => 'nearest_first', 'map_layout' => 'top'])['map_layout']);
        $this->assertSame('none', $this->saveFinder(['layout' => 'by_company', 'map_layout' => 'none'])['map_layout']);

        // Un diseño que no existe cae al de siempre.
        $this->assertSame('list_map', $this->saveFinder(['layout' => 'carrusel'])['layout']);
    }

    public function test_cambiar_de_diseno_y_volver_no_pierde_lo_configurado(): void
    {
        $data = $this->saveFinder(array_merge($this->block('store_finder')->data, [
            'layout' => 'nearest_first',
            'hero_badge' => 'Tu súper de siempre',
            'others_limit' => '12',
            'company_order' => 'stores_count',
            'list_width' => 'narrow',
        ]));

        // El editor manda todo lo del bloque, también lo que tiene oculto.
        $data = $this->saveFinder([...$data, 'layout' => 'by_company']);
        $data = $this->saveFinder([...$data, 'layout' => 'nearest_first']);

        $this->assertSame('Tu súper de siempre', $data['hero_badge']);
        $this->assertSame('12', $data['others_limit']);
        $this->assertSame('stores_count', $data['company_order']);
        $this->assertSame('narrow', $data['list_width']);

        // Los numericos se guardan como texto, igual que el resto de select.
        $this->assertSame('3', $this->saveFinder([...$data, 'others_columns' => 4])['others_columns']);
        $this->assertSame('4', $this->saveFinder([...$data, 'others_columns' => '4'])['others_columns']);
    }

    public function test_el_domicilio_sale_de_la_version_publicada_de_la_tienda(): void
    {
        $storefront = $this->publishStorefront();

        $delivery = $this->publicBlock('store_finder')['source']['stores'][0]['delivery'];
        // Los defectos: 5.000 y sin domicilio gratis (0 es «nunca», no «gratis desde $ 0»).
        $this->assertSame(['enabled' => true, 'fee_from' => 5000, 'free_from' => null, 'min_order_total' => 0, 'next_slot' => null], $delivery);

        // El borrador no llega a la landing.
        $this->draftSetting($storefront, 'delivery', ['enabled' => true, 'fee' => 4000, 'free_from' => 60000, 'min_order_total' => 15000]);
        $this->assertSame(5000, $this->publicBlock('store_finder')['source']['stores'][0]['delivery']['fee_from']);

        // Publicado, si.
        $this->publishStorefront();
        $delivery = $this->publicBlock('store_finder')['source']['stores'][0]['delivery'];
        $this->assertSame([4000, 60000, 15000], [$delivery['fee_from'], $delivery['free_from'], $delivery['min_order_total']]);
    }

    public function test_con_zonas_el_desde_es_lo_menos_que_cobra_el_checkout(): void
    {
        $storefront = $this->publishStorefront();
        $this->draftSetting($storefront, 'delivery', ['enabled' => true, 'fee' => 4000]);

        // Con zonas el checkout obliga a elegir una: la tarifa general ya no la paga nadie.
        $storefront->zones()->create(['name' => 'Norte', 'fee' => 7000, 'eta_minutes' => 40, 'is_active' => true, 'sort_order' => 1]);
        $storefront->zones()->create(['name' => 'Sur', 'fee' => 6000, 'eta_minutes' => 40, 'is_active' => true, 'sort_order' => 2]);
        $storefront->zones()->create(['name' => 'Apagada', 'fee' => 1000, 'eta_minutes' => 40, 'is_active' => false, 'sort_order' => 3]);
        $this->publishStorefront();

        $this->assertSame(6000, $this->publicBlock('store_finder')['source']['stores'][0]['delivery']['fee_from']);
    }

    public function test_una_sede_sin_domicilio_llega_sin_tarifa(): void
    {
        $storefront = $this->publishStorefront();
        $this->draftSetting($storefront, 'delivery', ['enabled' => false, 'fee' => 4000, 'free_from' => 60000, 'min_order_total' => 10000]);
        $this->publishStorefront();

        $delivery = $this->publicBlock('store_finder')['source']['stores'][0]['delivery'];

        $this->assertFalse($delivery['enabled']);
        $this->assertNull($delivery['fee_from']);
        $this->assertNull($delivery['free_from']);
    }

    public function test_la_proxima_franja_solo_se_cuenta_si_algun_buscador_la_muestra(): void
    {
        $this->at('2026-10-14 09:00'); // miercoles
        $storefront = $this->publishStorefront();
        $this->draftSetting($storefront, 'slots', ['enabled' => true]);
        $storefront->slots()->create(['weekday' => 4, 'starts_at' => '10:00:00', 'ends_at' => '12:00:00', 'capacity' => 5, 'is_active' => true]);
        $this->publishStorefront();

        // El cascaron de la tienda (logo, color) cuenta su propia franja para la barra superior y
        // queda en cache: se calienta antes, para medir solo lo que agrega el buscador.
        $this->publicBlock('store_finder');

        $countsSlots = function (): bool {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->publicBlock('store_finder');
            $queries = collect(DB::getQueryLog())->pluck('query');
            DB::disableQueryLog();

            return $queries->contains(fn (string $sql) => str_contains($sql, 'delivery_slot_start'));
        };

        // Nadie la muestra: no se cuentan cupos.
        $this->assertFalse($countsSlots());
        $this->assertNull($this->publicBlock('store_finder')['source']['stores'][0]['delivery']['next_slot']);

        // Con el buscador publicado mostrandola, si.
        $this->publishFinder(['show_next_slot' => true]);
        $this->assertTrue($countsSlots());
        $this->assertSame('mañana entre 10:00 y 12:00 p. m.', $this->publicBlock('store_finder')['source']['stores'][0]['delivery']['next_slot']);
    }

    public function test_el_horario_llega_como_frase(): void
    {
        $this->publishStorefront();

        $this->at('2026-10-14 06:00');
        $store = $this->publicBlock('store_finder')['source']['stores'][0];
        $this->assertFalse($store['is_open']);
        $this->assertSame('abre 7:00 a. m.', $store['hours_label']);

        $this->at('2026-10-14 10:00');
        $store = $this->publicBlock('store_finder')['source']['stores'][0];
        $this->assertTrue($store['is_open']);
        $this->assertSame('cierra 9:00 p. m.', $store['hours_label']);

        $this->at('2026-10-14 21:30');
        $store = $this->publicBlock('store_finder')['source']['stores'][0];
        $this->assertFalse($store['is_open']);
        $this->assertSame('abre mañana 7:00 a. m.', $store['hours_label']);
    }

    public function test_sedes_cercanas_trae_el_mismo_domicilio_y_horario_que_la_carga_inicial(): void
    {
        $this->at('2026-10-14 09:00');
        $storefront = $this->publishStorefront();
        $this->store->update(['latitude' => 6.2383, 'longitude' => -75.5698]);
        $this->draftSetting($storefront, 'delivery', ['enabled' => true, 'fee' => 4500, 'free_from' => 90000, 'min_order_total' => 20000]);
        $this->draftSetting($storefront, 'slots', ['enabled' => true]);
        $storefront->slots()->create(['weekday' => 3, 'starts_at' => '14:00:00', 'ends_at' => '16:00:00', 'capacity' => 0, 'is_active' => true]);
        $this->publishStorefront();
        $this->publishFinder(['show_next_slot' => true]);

        $initial = $this->publicBlock('store_finder')['source']['stores'][0];
        $nearby = $this->postJson(route('landing.nearby'), ['lat' => 6.2442, 'lng' => -75.5812])->assertOk()->json('stores.0');

        $this->assertSame($initial['delivery'], $nearby['delivery']);
        $this->assertSame($initial['hours_label'], $nearby['hours_label']);
        $this->assertSame('hoy entre 2:00 y 4:00 p. m.', $nearby['delivery']['next_slot']);
        $this->assertSame('https://www.google.com/maps/dir/?api=1&destination=6.2383%2C-75.5698', $nearby['directions_url']);
    }

    public function test_el_formulario_del_buscador_solo_muestra_lo_del_diseno_elegido(): void
    {
        $this->actingAs($this->super)
            ->get(route('platform.landing.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('catalogue', fn ($catalogue) => collect($catalogue)->firstWhere('type', 'store_finder')['fields']['list_numbered']['when'] === ['layout' => ['list_map']]
                    && collect(collect($catalogue)->firstWhere('type', 'store_finder')['fields']['map_layout']['options'])->firstWhere('value', 'top')['when'] === ['layout' => ['nearest_first']]));
    }
}
