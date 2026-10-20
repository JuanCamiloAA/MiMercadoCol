<?php

namespace Tests\Feature;

use App\Models\MediaAsset;
use App\Models\Store;
use App\Models\StorefrontTemplate;
use App\Models\User;
use App\Services\ImageService;
use App\Services\Storefront\StorefrontManager;
use App\Services\Storefront\StorefrontRenderer;
use App\Services\Storefront\StorefrontTemplateService;
use App\Storefront\BlockCatalogue;
use App\Support\Color;
use App\Support\StoreContext;
use Database\Seeders\StorefrontTemplateCatalogSeeder;
use Database\Seeders\Support\BannerPainter;
use Database\Seeders\Support\ScenePainter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsStorefront;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\TestCase;

/**
 * El catalogo de plantillas sembrado desde `data/storefront_templates.json`: las doce entran una
 * sola vez, publicadas, activas y sin advertencias, con imagenes de plataforma y sin ids de
 * ninguna empresa; «Supermercado» sigue por defecto. Una sede adopta una, se independiza
 * copiando sus imagenes y vuelve.
 */
class StorefrontTemplateCatalogSeederTest extends TestCase
{
    use BuildsStorefront, CreatesCompanyEnvironment, RefreshDatabase;

    protected User $super;

    protected StorefrontTemplate $supermarket;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEnvironment();
        Storage::fake('public');
        $this->super = $this->makeSuperAdmin();

        // Pintar las sesenta imagenes tarda minutos: aqui cada una es un medio de plataforma con
        // su descripcion y su archivo, que es lo que miran la validacion y el independizarse.
        // Que el pintor pinta cada motivo lo mide `test_el_pintor_pinta_cada_motivo`.
        $this->app->instance(BannerPainter::class, new class(app(ImageService::class)) extends BannerPainter
        {
            public function scene(array $recipe, int $width, int $height, string $alt, string $focus = 'center', string $folder = 'storefront-platform'): ?MediaAsset
            {
                $path = $folder.'/0/'.Str::random(12).'.jpg';
                Storage::disk('public')->put($path, 'jpg');

                return MediaAsset::createForPlatform(['path' => $path, 'disk' => 'public', 'mime' => 'image/jpeg', 'width' => $width, 'height' => $height, 'bytes' => 90000, 'alt' => $alt, 'variants' => []]);
            }

            public function thumbnail(array $recipe, string $name, string $caption, string $folder = 'storefront-platform'): ?MediaAsset
            {
                return $this->scene($recipe, 1200, 800, 'Miniatura de la plantilla «'.$name.'»', 'center', $folder);
            }
        });

        $this->supermarket = $this->makeTemplate('Supermercado');
        $this->supermarket->forceFill(['slug' => 'supermercado', 'sort_order' => 0])->save();
    }

    public function test_siembra_las_doce_publicadas_activas_y_sin_advertencias(): void
    {
        $this->seed(StorefrontTemplateCatalogSeeder::class);

        $catalog = collect(StorefrontTemplateCatalogSeeder::catalog());
        $this->assertCount(12, $catalog);
        $this->assertSame(13, StorefrontTemplate::query()->count());

        foreach ($catalog as $entry) {
            $template = StorefrontTemplate::query()->where('slug', $entry['slug'])->firstOrFail();
            $checks = app(StorefrontTemplateService::class)->validate($template);

            $this->assertNotNull($template->published, $entry['slug']);
            $this->assertEquals($template->draft, $template->published, $entry['slug']);
            $this->assertTrue($template->is_active, $entry['slug']);
            $this->assertFalse($template->is_default, $entry['slug']);
            $this->assertSame([], $checks['blocking'], $entry['slug']);
            $this->assertSame([], $checks['warnings'], $entry['slug']);
            $this->assertSame($entry['audience_group'], $template->audience_group);
            $this->assertContains($template->audience_group, StorefrontTemplate::AUDIENCE_GROUPS);
            $this->assertSame('Plantilla inicial', $template->versions()->value('label'));

            // Portada, articulo y carrito, como «Supermercado».
            $this->assertEqualsCanonicalizing(['home', 'product', 'cart'], array_values(array_unique(array_column($template->draft['blocks'], 'page'))));

            // Ningun id de empresa: ni articulos, ni categorias, ni promociones, ni imagenes ajenas.
            $media = [];
            foreach ($template->draft['blocks'] as $block) {
                $definition = BlockCatalogue::for($block['type']);
                $this->assertSame([], $definition->violations($block['payload']), $entry['slug'].' '.$block['type']);
                array_push($media, ...$definition->mediaIds($block['payload']));
            }

            $media = array_values(array_unique([...$media, $template->preview_media_id]));
            $this->assertNotEmpty($media, $entry['slug']);
            $this->assertSame(count($media), MediaAsset::platform()->whereIn('id', $media)->count(), $entry['slug']);

            // La marca no viaja: ni acento ni oferta en el tema.
            $this->assertArrayNotHasKey('accent', $template->draft['theme']);
            $this->assertArrayNotHasKey('deal_color', $template->draft['theme']);
        }

        $this->assertTrue($this->supermarket->refresh()->is_default);
        $this->assertSame(['supermercado', 'vitrina', 'pasarela'], StorefrontTemplate::query()->orderBy('sort_order')->limit(3)->pluck('slug')->all());
    }

    public function test_es_idempotente_y_no_pisa_lo_editado(): void
    {
        $this->seed(StorefrontTemplateCatalogSeeder::class);
        $media = MediaAsset::platform()->count();

        $vitrina = StorefrontTemplate::query()->where('slug', 'vitrina')->firstOrFail();
        $vitrina->forceFill(['name' => 'Vitrina de la casa'])->save();

        $this->seed(StorefrontTemplateCatalogSeeder::class);

        $this->assertSame(13, StorefrontTemplate::query()->count());
        $this->assertSame($media, MediaAsset::platform()->count());
        $this->assertSame('Vitrina de la casa', $vitrina->refresh()->name);
    }

    public function test_nunca_toma_el_por_defecto(): void
    {
        $this->supermarket->forceFill(['is_default' => false])->save();

        $this->seed(StorefrontTemplateCatalogSeeder::class);

        $this->assertSame(0, StorefrontTemplate::query()->where('is_default', true)->count());
    }

    public function test_los_colores_sugeridos_pasan_las_reglas_de_aspecto(): void
    {
        foreach (StorefrontTemplateCatalogSeederTest::catalogPalettes() as $slug => $palette) {
            // Las mismas cuentas de `ThemeController`: si no, «Usar los colores sugeridos» se rechazaria.
            $this->assertGreaterThanOrEqual(4.5, max(Color::contrast($palette['accent'], '#FFFFFF'), Color::contrast($palette['accent'], '#000000')), $slug.' acento');
            $this->assertGreaterThanOrEqual(4.5, Color::contrast($palette['deal_color'], '#FFFFFF'), $slug.' oferta');
        }
    }

    public function test_una_sede_adopta_vitrina_se_independiza_y_vuelve(): void
    {
        $this->seed(StorefrontTemplateCatalogSeeder::class);
        $vitrina = StorefrontTemplate::query()->where('slug', 'vitrina')->firstOrFail();
        $admin = $this->makeUser('admin');
        $store = Store::query()->withoutGlobalScopes()->create(['company_id' => $this->company->id, 'name' => 'Galería', 'code' => 'GAL', 'is_active' => true]);
        $admin->stores()->syncWithoutDetaching([$store->id]);
        $storefront = app(StorefrontManager::class)->for($store);
        $session = [StoreContext::SESSION_KEY => $store->id];

        // El selector del editor la ofrece con su miniatura, para quien y sus colores.
        $this->actingAs($admin, 'web')->withSession($session)->get(route('storefront.editor.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('template.available.1.name', 'Vitrina')
                ->where('template.available.1.audience', 'Moda contemporánea')
                ->where('template.available.1.audience_group', 'Moda')
                ->where('template.available.1.palette.accent', '#08101D')
                ->has('template.available.1.preview'));

        $this->actingAs($admin, 'web')->withSession($session)
            ->post(route('storefront.editor.template.reattach'), ['template_id' => $vitrina->id])
            ->assertSessionHasNoErrors();

        $draft = app(StorefrontRenderer::class)->draft($store->refresh());
        $this->assertSame('hero', $draft['blocks'][0]['type']);
        $this->assertSame('cover_center', $draft['blocks'][0]['data']['layout']);
        $this->assertSame('vitrina', $draft['theme']['font_pair']);
        // El color sigue siendo el de la sede: la sugerencia no se aplica sola.
        $this->assertNotSame('#08101D', $draft['theme']['accent']);

        // Independizarse copia las imagenes a la biblioteca de la empresa.
        $this->actingAs($admin, 'web')->withSession($session)->post(route('storefront.editor.template.detach'))->assertSessionHasNoErrors();
        $hero = $storefront->refresh()->blocks()->where('type', 'hero')->firstOrFail();
        $copy = MediaAsset::query()->withoutGlobalScopes()->findOrFail($hero->payload['slides'][0]['media_id']);
        $this->assertSame($this->company->id, $copy->company_id);
        Storage::disk('public')->assertExists($copy->path);

        // Y vuelve.
        $this->actingAs($admin, 'web')->withSession($session)
            ->post(route('storefront.editor.template.reattach'), ['template_id' => $vitrina->id])
            ->assertSessionHasNoErrors();
        $this->assertTrue($storefront->refresh()->follows_template);
        $this->assertSame($vitrina->id, $storefront->template_id);
    }

    public function test_duplicar_deja_una_copia_sin_publicar(): void
    {
        $this->seed(StorefrontTemplateCatalogSeeder::class);
        $noir = StorefrontTemplate::query()->where('slug', 'noir')->firstOrFail();

        $this->actingAs($this->makeUser('admin'), 'web')->post(route('platform.store_templates.duplicate', $noir))->assertForbidden();

        $response = $this->actingAs($this->super, 'web')->post(route('platform.store_templates.duplicate', $noir));
        $copy = StorefrontTemplate::query()->where('name', 'Noir (copia)')->firstOrFail();
        $response->assertRedirect(route('platform.store_templates.edit', $copy));

        $this->assertNull($copy->published);
        $this->assertFalse($copy->is_default);
        $this->assertNotSame($noir->slug, $copy->slug);
        $this->assertStringStartsWith('noir-copia-', $copy->slug);
        $this->assertSame($noir->audience_group, $copy->audience_group);
        $this->assertSame($noir->preview_media_id, $copy->preview_media_id);
        $this->assertSame(array_column($noir->draft['blocks'], 'type'), array_column($copy->draft['blocks'], 'type'));
        $this->assertNotSame($noir->draft['blocks'][0]['uuid'], $copy->draft['blocks'][0]['uuid']);
        $this->assertSame(0, $copy->followers()->count());
    }

    public function test_la_galeria_va_en_el_orden_del_catalogo_con_sus_familias(): void
    {
        $this->seed(StorefrontTemplateCatalogSeeder::class);

        $this->actingAs($this->super, 'web')->get(route('platform.store_templates.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Platform/StoreTemplates/Index')
                ->where('groups', StorefrontTemplate::AUDIENCE_GROUPS)
                ->where('templates.0.name', 'Supermercado')
                ->where('templates.1.name', 'Vitrina')
                ->where('templates.1.audience_group', 'Moda')
                ->has('templates', 13));
    }

    public function test_el_pintor_pinta_cada_motivo(): void
    {
        if (! function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('Sin GD no hay pintor.');
        }

        $recipes = collect(StorefrontTemplateCatalogSeeder::catalog())
            ->flatMap(fn (array $entry) => self::recipes($entry['blocks']))
            ->push(['beach', '#BFE3F2', '#F3E3C3']);

        // Todos los motivos del archivo existen en el pintor, y al reves.
        $this->assertEqualsCanonicalizing(ScenePainter::MOTIFS, $recipes->pluck(0)->unique()->values()->all());

        foreach ($recipes->unique(fn ($recipe) => json_encode($recipe)) as $recipe) {
            $image = ScenePainter::render(96, 64, $recipe, 'right');
            $this->assertSame([96, 64], [imagesx($image), imagesy($image)], json_encode($recipe));
            $this->assertNotSame('', ScenePainter::describe($recipe));
        }

        // Y se guarda como medio de plataforma, con su descripcion.
        $asset = (new BannerPainter(app(ImageService::class)))->scene(['glow', '#3A2C1C', '#0E0D0C', '#C98B3C', 'perfume'], 160, 90, 'Perfume de prueba');
        $this->assertNull($asset->company_id);
        $this->assertSame('Perfume de prueba', $asset->alt);
        Storage::disk('public')->assertExists($asset->path);
    }

    /**
     * Las paletas del archivo.
     *
     * @return array<string, array{accent: string, deal_color: string}>
     */
    public static function catalogPalettes(): array
    {
        return collect(StorefrontTemplateCatalogSeeder::catalog())->mapWithKeys(fn (array $entry) => [$entry['slug'] => $entry['palette_suggestion']])->all();
    }

    /**
     * Todas las recetas (`media`) de unos bloques.
     *
     * @return list<list<mixed>>
     */
    protected static function recipes(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        if (isset($value['media']) && is_array($value['media']) && array_is_list($value['media'])) {
            return [$value['media'], ...self::recipes(array_diff_key($value, ['media' => true]))];
        }

        return array_merge([], ...array_map(fn ($item) => self::recipes($item), array_values($value)));
    }
}
