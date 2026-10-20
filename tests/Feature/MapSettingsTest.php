<?php

namespace Tests\Feature;

use App\Landing\Blocks\StoreFinderBlock;
use App\Models\User;
use App\Services\Landing\LandingRenderer;
use App\Services\PlatformSettingsService;
use App\Support\Maps\MapConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\TestCase;

/**
 * Plataforma > Mapas: el super usuario elige el proveedor (OpenStreetMap, Google, MapTiler, Mapbox
 * o uno propio), lo prueba sin guardar, y la landing y los formularios de sede lo reciben hecho.
 */
class MapSettingsTest extends TestCase
{
    use CreatesCompanyEnvironment, RefreshDatabase;

    protected User $super;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEnvironment();
        $this->super = $this->makeSuperAdmin();
    }

    /**
     * Lo que manda el formulario, con los defectos de cada campo.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function payload(array $overrides = []): array
    {
        return array_merge([
            'provider' => 'osm',
            'osm_dark' => 'filter',
            'google_key' => '',
            'google_map_id' => '',
            'google_map_type' => 'roadmap',
            'maptiler_key' => '',
            'maptiler_style' => 'streets-v2',
            'maptiler_dark' => 'streets-v2-dark',
            'mapbox_token' => '',
            'mapbox_style' => 'mapbox/streets-v12',
            'mapbox_dark' => 'mapbox/dark-v11',
            'custom_url' => '',
            'custom_dark_url' => '',
            'custom_attribution' => '',
            'custom_max_zoom' => 19,
            'custom_tile_size' => 256,
            'default_lat' => '4.6097',
            'default_lng' => '-74.0817',
            'default_zoom' => 6,
        ], $overrides);
    }

    public function test_de_fabrica_es_openstreetmap_y_el_super_usuario_lo_cambia(): void
    {
        $this->actingAs($this->super)->get(route('platform.maps.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Platform/Maps/Index')
                ->where('map.provider', 'osm')
                ->where('map.driver', 'leaflet')
                ->has('providers', 5)
                ->where('settings.provider', 'osm'));

        $this->actingAs($this->super)
            ->put(route('platform.maps.update'), $this->payload(['provider' => 'google', 'google_key' => 'AIzaPrueba_123-abc', 'google_map_type' => 'hybrid']))
            ->assertSessionHasNoErrors();

        $config = MapConfig::current();
        $this->assertSame('google', $config['driver']);
        $this->assertSame(['key' => 'AIzaPrueba_123-abc', 'map_id' => null, 'map_type' => 'hybrid'], $config['google']);
    }

    public function test_cada_proveedor_pide_lo_suyo(): void
    {
        $put = fn (array $data) => $this->actingAs($this->super)->put(route('platform.maps.update'), $this->payload($data));

        $put(['provider' => 'google'])->assertSessionHasErrors('google_key');
        $put(['provider' => 'maptiler'])->assertSessionHasErrors('maptiler_key');
        $put(['provider' => 'maptiler', 'maptiler_key' => 'abc', 'maptiler_style' => 'inventado'])->assertSessionHasErrors('maptiler_style');
        // El token secreto de Mapbox da acceso a la cuenta: nunca va en una pagina.
        $put(['provider' => 'mapbox', 'mapbox_token' => 'sk.eyJ1Ijoic2VjcmV0byJ9.x'])->assertSessionHasErrors('mapbox_token');
        $put(['provider' => 'custom', 'custom_attribution' => '© Alguien'])->assertSessionHasErrors('custom_url');
        $put(['provider' => 'custom', 'custom_url' => 'http://tiles.example.com/{z}/{x}/{y}.png', 'custom_attribution' => '© Alguien'])->assertSessionHasErrors('custom_url');
        $put(['provider' => 'custom', 'custom_url' => 'https://tiles.example.com/{z}/{x}.png', 'custom_attribution' => '© Alguien'])->assertSessionHasErrors('custom_url');
        $put(['provider' => 'custom', 'custom_url' => 'https://tiles.example.com/{z}/{x}/{y}.png'])->assertSessionHasErrors('custom_attribution');
        $put(['default_lat' => '95'])->assertSessionHasErrors('default_lat');

        $this->assertSame('osm', app(PlatformSettingsService::class)->get('maps.provider'));

        $put(['provider' => 'mapbox', 'mapbox_token' => 'pk.eyJ1IjoicHJ1ZWJhIn0.abc', 'mapbox_dark' => 'filter'])->assertSessionHasNoErrors();
        $tiles = MapConfig::current()['tiles'];
        $this->assertStringStartsWith('https://api.mapbox.com/styles/v1/mapbox/streets-v12/tiles/512/', $tiles['url']);
        $this->assertSame(512, $tiles['tile_size']);
        $this->assertSame(-1, $tiles['zoom_offset']);
        $this->assertTrue($tiles['dark_filter']);
    }

    public function test_probar_no_guarda_nada(): void
    {
        $this->actingAs($this->super)
            ->postJson(route('platform.maps.preview'), $this->payload(['provider' => 'maptiler', 'maptiler_key' => 'Llave123', 'maptiler_style' => 'satellite']))
            ->assertOk()
            ->assertJsonPath('map.provider', 'maptiler')
            ->assertJsonPath('map.tiles.url', 'https://api.maptiler.com/maps/satellite/256/{z}/{x}/{y}{r}.jpg?key=Llave123')
            ->assertJsonPath('map.tiles.dark_url', 'https://api.maptiler.com/maps/streets-v2-dark/256/{z}/{x}/{y}{r}.png?key=Llave123');

        $this->actingAs($this->super)
            ->postJson(route('platform.maps.preview'), $this->payload(['provider' => 'google']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('google_key');

        $this->assertSame('osm', MapConfig::current()['provider']);
    }

    public function test_solo_viaja_la_llave_del_proveedor_en_uso(): void
    {
        // Volver a OpenStreetMap conserva la llave de Google, pero no la manda al navegador.
        $this->actingAs($this->super)
            ->put(route('platform.maps.update'), $this->payload(['provider' => 'osm', 'google_key' => 'AIzaGuardada', 'maptiler_key' => 'TilerGuardada']))
            ->assertSessionHasNoErrors();

        $this->assertSame('AIzaGuardada', app(PlatformSettingsService::class)->get('maps.google.key'));

        $json = json_encode(MapConfig::current());
        $this->assertStringNotContainsString('AIzaGuardada', $json);
        $this->assertStringNotContainsString('TilerGuardada', $json);

        $this->actingAs($this->super)->get(route('platform.maps.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('settings.google_key', 'AIzaGuardada')->missing('map.google'));
    }

    public function test_el_credito_libre_se_pinta_como_texto(): void
    {
        $this->actingAs($this->super)->put(route('platform.maps.update'), $this->payload([
            'provider' => 'custom',
            'custom_url' => 'https://{s}.teselas.example.com/{z}/{x}/{y}{r}.png',
            'custom_attribution' => '<img src=x onerror=alert(1)> © Teselas',
        ]))->assertSessionHasNoErrors();

        $attribution = MapConfig::current()['tiles']['attribution'];
        $this->assertStringNotContainsString('<img', $attribution);
        $this->assertStringContainsString('&lt;img', $attribution);
    }

    public function test_un_usuario_de_empresa_no_entra(): void
    {
        $admin = $this->makeUser('admin');

        $this->actingAs($admin)->get(route('platform.maps.index'))->assertForbidden();
        $this->actingAs($admin)->put(route('platform.maps.update'), $this->payload())->assertForbidden();
        $this->actingAs($admin)->postJson(route('platform.maps.preview'), $this->payload())->assertForbidden();
    }

    public function test_la_landing_y_los_formularios_de_sede_reciben_el_mapa(): void
    {
        $blocks = app(LandingRenderer::class)->resolve([
            ['uuid' => 'b1', 'type' => 'store_finder', 'is_visible' => true, 'data' => (new StoreFinderBlock)->defaults()],
        ]);
        $this->assertSame('osm', $blocks[0]['source']['map']['provider']);

        $this->actingAs($this->makeUser('admin'))->withSession($this->withActiveStore())
            ->get(route('stores.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('map.provider', 'osm')->has('map.tiles.url'));

        $this->actingAs($this->super)->get(route('companies.create'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('map.provider', 'osm'));
    }

    public function test_el_bloque_trae_el_mapa_al_lado_y_completa_lo_que_falta(): void
    {
        $block = new StoreFinderBlock;

        // Un bloque guardado antes del mapa sale con el mapa al lado de la lista.
        $old = $block->normalize(['title' => 'Encuentra tu tienda', 'show_hours' => true]);
        $this->assertSame('side', $old['map_layout']);
        $this->assertSame('md', $old['map_height']);
        $this->assertSame('auto', $old['map_theme']);
        $this->assertTrue($old['map_cluster']);
        $this->assertTrue($old['map_expand']);
        $this->assertTrue($old['map_search_area']);

        // Lo que no esta en la lista vuelve al defecto.
        $odd = $block->normalize(['map_layout' => 'flotante', 'map_height' => 'xl', 'map_theme' => 'sepia']);
        $this->assertSame(['side', 'md', 'auto'], [$odd['map_layout'], $odd['map_height'], $odd['map_theme']]);

        // Solo un botón vale en un diseño que lo admite; «Lista compacta + mapa» lleva el mapa al lado.
        $chosen = $block->normalize(['layout' => StoreFinderBlock::LAYOUT_NEAREST_FIRST, 'map_layout' => 'button', 'map_cluster' => false]);
        $this->assertSame('button', $chosen['map_layout']);
        $this->assertFalse($chosen['map_cluster']);
        $this->assertSame('side', $block->normalize(['map_layout' => 'button'])['map_layout']);
    }
}
