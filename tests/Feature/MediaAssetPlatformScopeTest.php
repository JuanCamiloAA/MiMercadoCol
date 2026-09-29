<?php

namespace Tests\Feature;

use App\Models\MediaAsset;
use App\Models\Store;
use App\Services\Storefront\StorefrontRenderer;
use App\Storefront\BlockCatalogue;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsStorefront;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\TestCase;

/**
 * Los medios de plataforma (`company_id` nulo) son de las plantillas: ninguna empresa los ve
 * en su biblioteca, ni el super usuario en consolidado; el renderizador los pinta solo cuando
 * el diseño es de la plantilla.
 */
class MediaAssetPlatformScopeTest extends TestCase
{
    use BuildsStorefront, CreatesCompanyEnvironment, RefreshDatabase;

    public function test_una_empresa_no_ve_los_medios_de_plataforma(): void
    {
        $this->seedEnvironment();
        $admin = $this->makeUser('admin');
        $propia = $this->mediaFor($this->company->id, 'De la empresa');
        $plataforma = $this->mediaFor(null, 'De la plantilla');

        $this->actingAs($admin, 'web');
        $this->assertSame([$propia->id], MediaAsset::query()->pluck('id')->all());

        $this->withSession($this->withActiveStore())->getJson(route('media.index'))
            ->assertOk()
            ->assertJsonCount(1, 'media')
            ->assertJsonPath('media.0.id', $propia->id);

        $this->assertSame([$plataforma->id], MediaAsset::platform()->pluck('id')->all());
    }

    public function test_el_super_usuario_en_consolidado_tampoco_los_mezcla(): void
    {
        $this->seedEnvironment();
        $super = $this->makeSuperAdmin();
        $propia = $this->mediaFor($this->company->id, 'De la empresa');
        $this->mediaFor(null, 'De la plantilla');

        $this->actingAs($super, 'web');
        $this->assertNull(TenantContext::activeCompanyId());
        $this->assertSame([$propia->id], MediaAsset::query()->pluck('id')->all());

        // En la biblioteca de plataforma, en cambio, solo estan esos.
        $this->getJson(route('platform.store_templates.media.index'))->assertOk()->assertJsonCount(1, 'media');
    }

    public function test_el_renderizador_los_pinta_solo_con_la_plantilla(): void
    {
        $this->seedEnvironment();
        $banner = $this->mediaFor(null, 'Frutas frescas');

        $template = $this->makeTemplate('Supermercado', [
            ['hero', 'home', ['slides' => [['media_id' => $banner->id, 'title' => 'Fresco']]]],
        ]);

        // Una sede nueva nace siguiendo la plantilla: su banner sale con la imagen de plataforma.
        $nueva = Store::query()->withoutGlobalScopes()->create(['company_id' => $this->company->id, 'name' => 'Sur', 'code' => 'SUR', 'is_active' => true]);
        $slides = app(StorefrontRenderer::class)->draft($nueva)['blocks'][0]['data']['slides'];
        $this->assertSame($banner->id, $slides[0]['media']['id']);

        // La misma imagen, puesta a mano en una tienda propia, no se pinta.
        $this->storefrontWithOwnHero($banner->id);
        $own = app(StorefrontRenderer::class)->draft($this->store)['blocks'][0]['data']['slides'];
        $this->assertNull($own[0]['media']);

        $this->assertTrue($template->is_default);
    }

    protected function storefrontWithOwnHero(int $mediaId): void
    {
        $storefront = $this->site();
        $storefront->forceFill(['follows_template' => false])->save();
        $storefront->blocks()->delete();
        $storefront->blocks()->create([
            'uuid' => (string) Str::uuid(),
            'type' => 'hero',
            'page' => 'home',
            'sort_order' => 0,
            'is_visible' => true,
            'devices' => 'all',
            'payload' => BlockCatalogue::for('hero')->normalize(['slides' => [['media_id' => $mediaId, 'title' => 'Propio']]]),
        ]);
    }
}
