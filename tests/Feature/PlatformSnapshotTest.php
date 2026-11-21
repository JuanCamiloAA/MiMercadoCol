<?php

namespace Tests\Feature;

use App\Models\BillingCycle;
use App\Models\Company;
use App\Models\LandingBlock;
use App\Models\LandingVersion;
use App\Models\MediaAsset;
use App\Models\MembershipPlan;
use App\Models\PlatformSetting;
use App\Models\Store;
use App\Models\StorefrontTemplate;
use App\Models\StorefrontTemplateVersion;
use App\Models\User;
use App\Services\Landing\LandingManager;
use App\Services\Platform\PlatformSnapshot;
use App\Services\PlatformSettingsService;
use App\Services\Storefront\StorefrontTemplateService;
use Database\Seeders\PlatformSnapshotSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\TestCase;

/**
 * Exportar los datos de plataforma de una base e importarlos en otra: planes, ajustes, landing y
 * plantillas llegan iguales, los ids de los medios se traducen a los de destino, los archivos
 * suben al disco y repetir la importacion no duplica nada.
 */
class PlatformSnapshotTest extends TestCase
{
    use CreatesCompanyEnvironment, RefreshDatabase;

    protected User $super;

    protected string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEnvironment();
        Storage::fake('public');
        $this->super = $this->makeSuperAdmin();
        $this->dir = storage_path('framework/testing/platform-snapshot-'.Str::random(8));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    public function test_el_seeder_de_produccion_solo_crea_el_super_usuario_y_lo_de_plataforma(): void
    {
        $data = json_decode((string) file_get_contents(PlatformSnapshot::path('snapshot.json')), true, 512, JSON_THROW_ON_ERROR);
        $companies = Company::query()->count();
        $stores = Store::query()->withoutGlobalScopes()->count();
        $users = User::query()->withoutGlobalScopes()->count();

        $this->seed(PlatformSnapshotSeeder::class);

        $this->assertSame($companies, Company::query()->count());
        $this->assertSame($stores, Store::query()->withoutGlobalScopes()->count());
        $this->assertSame($users + 1, User::query()->withoutGlobalScopes()->count());

        $user = User::query()->withoutGlobalScopes()->where('email', PlatformSnapshotSeeder::EMAIL)->firstOrFail();
        $this->assertTrue($user->isSuperAdmin());
        $this->assertNull($user->company_id);
        $this->assertSame(PlatformSnapshotSeeder::PASSWORD_HASH, $user->getRawOriginal('password'));

        $this->assertSame(count($data['membership_plans']), MembershipPlan::query()->count());
        $this->assertSame(count($data['templates']), StorefrontTemplate::query()->count());
        $this->assertSame(count($data['media']), MediaAsset::platform()->count());
        $this->assertSame(count($data['landing']['blocks']), LandingBlock::query()->count());
        $this->assertSame($data['landing']['published'], app(LandingManager::class)->latestVersion()->snapshot);

        // Correrlo otra vez en el servidor no duplica nada.
        $this->seed(PlatformSnapshotSeeder::class);

        $this->assertSame($users + 1, User::query()->withoutGlobalScopes()->count());
        $this->assertSame(count($data['templates']), StorefrontTemplateVersion::query()->count());
        $this->assertSame(1, LandingVersion::query()->count());
    }

    public function test_lleva_todo_a_una_base_vacia_traduciendo_los_medios(): void
    {
        $oldAsset = $this->buildSource();
        $snapshot = app(PlatformSnapshot::class);
        $exported = $snapshot->export($this->dir);

        $this->assertSame([], $exported['missing']);
        $this->assertFileExists($this->dir.'/files/storefront-platform/0/portada-sm.webp');
        $this->assertFileExists($this->dir.'/files/landing/2026/10/hero.png');

        $this->wipePlatformData();
        // Otro medio de plataforma primero, para que en destino los ids no coincidan con los de origen.
        MediaAsset::createForPlatform(['path' => 'storefront-platform/0/ajena.jpg', 'disk' => 'public']);

        $result = $snapshot->import($this->super, $this->dir);

        $this->assertSame([], $result['unmapped_media']);
        $this->assertSame(4, $result['files_uploaded']);

        $plan = MembershipPlan::query()->where('slug', 'basico')->firstOrFail();
        $this->assertSame(49000, (int) $plan->monthly_price);
        $this->assertSame(['pos.*'], $plan->entitlements);
        $this->assertSame(15, (int) BillingCycle::query()->where('code', 'annual')->value('discount_percent'));
        $this->assertSame('VentaIO', app(PlatformSettingsService::class)->get('platform.name'));

        $asset = MediaAsset::platform()->where('path', 'storefront-platform/0/portada.jpg')->firstOrFail();
        $this->assertNotSame($oldAsset->id, $asset->id);

        $template = StorefrontTemplate::query()->where('slug', 'prueba')->firstOrFail();
        $this->assertTrue($template->is_default);
        $this->assertSame($asset->id, $template->preview_media_id);
        $this->assertSame($asset->id, $template->draft['blocks'][0]['payload']['slides'][0]['media_id']);
        $this->assertSame($asset->id, $template->published['blocks'][0]['payload']['slides'][0]['media_id']);
        $this->assertSame($this->super->id, $template->published_by_user_id);
        $this->assertSame([1], $template->versions()->pluck('number')->all());
        $this->assertSame($asset->id, $template->versions()->first()->snapshot['blocks'][0]['payload']['slides'][0]['media_id']);

        $hero = LandingBlock::query()->where('type', 'hero')->firstOrFail();
        $this->assertSame('landing/2026/10/hero.png', $hero->data['image']);
        $this->assertSame(1, LandingVersion::query()->count());
        $this->assertSame(PlatformSnapshot::NOTE, LandingVersion::query()->value('note'));
        $this->assertSame('landing/2026/10/hero.png', collect(app(LandingManager::class)->publishedBlocks())->firstWhere('type', 'hero')['data']['image']);

        foreach (['storefront-platform/0/portada.jpg', 'storefront-platform/0/portada-sm.webp', 'landing/2026/10/hero.png', 'landing/brand/favicon.png'] as $file) {
            Storage::disk('public')->assertExists($file);
        }

        // Repetir no duplica nada ni vuelve a subir archivos.
        $again = $snapshot->import($this->super, $this->dir);

        $this->assertSame(0, $again['files_uploaded']);
        $this->assertSame(2, MediaAsset::platform()->count());
        $this->assertSame(1, StorefrontTemplateVersion::query()->count());
        $this->assertSame(1, LandingVersion::query()->count());
        $this->assertSame(1, MembershipPlan::query()->count());
    }

    public function test_en_una_base_con_datos_actualiza_y_publica_como_version_nueva(): void
    {
        $this->buildSource();
        $snapshot = app(PlatformSnapshot::class);
        $snapshot->export($this->dir);

        // Destino con su propia historia: otro precio, otra landing y la plantilla con otro diseño publicado.
        $template = StorefrontTemplate::query()->where('slug', 'prueba')->firstOrFail();
        $other = ['theme' => [], 'blocks' => [], 'settings' => []];
        $template->forceFill(['is_default' => false, 'draft' => $other, 'published' => $other])->save();
        StorefrontTemplateVersion::query()->create(['template_id' => $template->id, 'number' => 2, 'snapshot' => $other, 'label' => 'Cambio en destino', 'created_by_user_id' => $this->super->id, 'published_at' => now()]);
        StorefrontTemplate::query()->create(['name' => 'Otra', 'slug' => 'otra', 'draft' => [], 'is_default' => true, 'is_active' => true]);
        MembershipPlan::query()->where('slug', 'basico')->update(['monthly_price' => 89000]);
        $manager = app(LandingManager::class);
        $manager->update(LandingBlock::query()->where('type', 'hero')->firstOrFail(), ['title' => 'Otra portada']);
        $manager->publish($this->super, 'Cambio en destino');

        $snapshot->import($this->super, $this->dir);

        $this->assertSame(49000, (int) MembershipPlan::query()->where('slug', 'basico')->value('monthly_price'));
        $this->assertSame([1, 2, 3], $template->versions()->orderBy('number')->pluck('number')->all());
        $this->assertSame(PlatformSnapshot::NOTE, $template->versions()->where('number', 3)->value('label'));
        $this->assertSame('Hola', $template->refresh()->published['blocks'][0]['payload']['slides'][0]['title']);
        $this->assertTrue($template->refresh()->is_default);
        $this->assertFalse(StorefrontTemplate::query()->where('slug', 'otra')->value('is_default'));

        // 1 y 2 vienen del origen, 3 es el cambio en destino: lo traido entra como la 4.
        $latest = $manager->latestVersion();
        $this->assertSame(4, $latest->number);
        $this->assertSame(PlatformSnapshot::NOTE, $latest->note);
        $this->assertSame('landing/2026/10/hero.png', collect($manager->publishedBlocks())->firstWhere('type', 'hero')['data']['image']);
        $this->assertFalse($manager->hasUnpublishedChanges());
    }

    /**
     * La base de origen: un plan, un ciclo, ajustes con favicon, una plantilla publicada que usa
     * un medio de plataforma y la landing publicada con una imagen.
     */
    protected function buildSource(): MediaAsset
    {
        BillingCycle::query()->create(['code' => 'annual', 'name' => 'Anual', 'months' => 12, 'discount_percent' => 15, 'is_active' => true, 'sort_order' => 0]);
        MembershipPlan::query()->create([
            'slug' => 'basico', 'name' => 'Básico', 'monthly_price' => 49000, 'trial_days' => 0,
            'entitlements' => ['pos.*'], 'highlights' => ['Caja'], 'is_public' => true, 'is_featured' => false, 'is_active' => true, 'sort_order' => 0,
        ]);

        $disk = Storage::disk('public');
        $disk->put('landing/brand/favicon.png', 'png');
        $disk->put('landing/2026/10/hero.png', 'png');
        $disk->put('storefront-platform/0/portada.jpg', 'jpg');
        $disk->put('storefront-platform/0/portada-sm.webp', 'webp');

        app(PlatformSettingsService::class)->setMany(['platform.name' => 'VentaIO', 'landing.favicon' => 'landing/brand/favicon.png']);

        $asset = MediaAsset::createForPlatform([
            'path' => 'storefront-platform/0/portada.jpg', 'disk' => 'public', 'mime' => 'image/jpeg', 'width' => 1600, 'height' => 600,
            'alt' => 'Portada', 'variants' => ['sm' => 'storefront-platform/0/portada-sm.webp'],
        ]);

        $design = ['theme' => [], 'blocks' => [['type' => 'hero', 'page' => 'home', 'payload' => ['slides' => [['media_id' => $asset->id, 'title' => 'Hola']]]]], 'settings' => []];
        $template = app(StorefrontTemplateService::class)->create('Prueba', null, 'prueba');
        $template->forceFill(['draft' => $design, 'published' => $design, 'published_at' => now(), 'published_by_user_id' => $this->super->id, 'preview_media_id' => $asset->id, 'is_default' => true])->save();
        StorefrontTemplateVersion::query()->create(['template_id' => $template->id, 'number' => 1, 'snapshot' => $design, 'label' => 'Plantilla inicial', 'created_by_user_id' => $this->super->id, 'published_at' => now()]);

        $manager = app(LandingManager::class);
        $manager->ensureSeeded($this->super);
        $hero = LandingBlock::query()->where('type', 'hero')->firstOrFail();
        $manager->update($hero, [...$hero->data, 'image' => 'landing/2026/10/hero.png']);
        $manager->publish($this->super, 'Con imagen');

        return $asset;
    }

    protected function wipePlatformData(): void
    {
        StorefrontTemplate::query()->delete();
        MediaAsset::platform()->delete();
        LandingBlock::query()->delete();
        LandingVersion::query()->delete();
        PlatformSetting::query()->delete();
        MembershipPlan::query()->delete();
        BillingCycle::query()->delete();
        Storage::fake('public');
    }
}
