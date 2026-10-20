<?php

namespace Tests\Feature;

use App\Models\PaymentMethod;
use App\Models\Storefront;
use App\Models\StorefrontVersion;
use App\Models\User;
use App\Services\Payments\PaymentMethodCatalog;
use App\Services\Storefront\StorefrontManager;
use App\Services\Storefront\StorefrontPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\Concerns\EditsStorefront;
use Tests\TestCase;

/**
 * Publicar, revertir y el historial.
 *
 * Publicar es un acto deliberado que deja constancia: cada version guarda el retrato completo
 * del sitio, de modo que volver atras no depende de que los bloques sigan existiendo.
 */
class StorefrontPublishTest extends TestCase
{
    use CreatesCompanyEnvironment, EditsStorefront, RefreshDatabase;

    protected User $admin;

    protected Storefront $storefront;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEnvironment();
        // La tienda en linea atiende la sede que el cliente eligio.
        $this->shopAt();

        $this->admin = $this->makeUser('admin');
        $this->storefront = app(StorefrontManager::class)->for($this->store);
        $this->makeStorefrontPublishable($this->storefront);
    }

    public function test_publicar_crea_una_version_con_el_retrato_completo(): void
    {
        $this->addBlock('announcement', ['text' => 'Semana del mercado']);

        $this->editor()->post(route('storefront.editor.publish'), ['label' => 'Semana del mercado'])
            ->assertSessionHasNoErrors();

        $storefront = $this->storefront->refresh();
        $version = StorefrontVersion::query()->where('storefront_id', $storefront->id)->latest('id')->firstOrFail();

        $this->assertSame($version->id, $storefront->published_version_id);
        $this->assertSame('Semana del mercado', $version->label);
        $this->assertSame($this->admin->id, $version->created_by_user_id);
        $this->assertNotNull($version->published_at);

        // El retrato es autosuficiente: trae tema, bloques, ajustes, plantilla, zonas y franjas.
        $this->assertSame(
            ['theme', 'blocks', 'settings', 'template', 'zones', 'slots'],
            array_keys($version->snapshot)
        );
        $this->assertSame('Semana del mercado', $version->snapshot['blocks'][0]['payload']['text']);

        // Recien publicado, el borrador y lo publicado coinciden.
        $this->assertFalse(app(StorefrontManager::class)->hasUnpublishedChanges($storefront));
    }

    public function test_revertir_crea_una_version_nueva_y_no_borra_historia(): void
    {
        $block = $this->addBlock('announcement', ['text' => 'Version uno']);

        $publisher = app(StorefrontPublisher::class);
        $first = $publisher->publish($this->storefront->refresh(), $this->admin, 'Uno');

        $block->update(['payload' => ['text' => 'Version dos', 'link' => null, 'tone' => 'accent']]);
        $publisher->publish($this->storefront->refresh(), $this->admin, 'Dos');

        $this->editor()->post(route('storefront.editor.restore', $first))->assertSessionHasNoErrors();

        $storefront = $this->storefront->refresh();

        // Tres versiones: las dos publicaciones y la que dejo la reversion.
        $this->assertSame(3, $storefront->versions()->count());
        $this->assertTrue($storefront->versions()->whereKey($first->id)->exists());

        // El borrador quedo con el contenido de la primera.
        $this->assertSame('Version uno', $storefront->blocks()->first()->payload['text']);
    }

    public function test_publicar_sin_medios_de_pago_se_rechaza_con_el_motivo(): void
    {
        $this->addBlock('announcement', ['text' => 'Hola']);

        // Ningun medio encendido para la tienda en linea de la sede (Medios de pago).
        PaymentMethod::query()->withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->whereIn('system_key', ['cash_on_delivery', 'card_on_delivery', 'credit'])
            ->update(['is_active' => false]);
        app(PaymentMethodCatalog::class)->forget();

        $this->editor()->from(route('storefront.editor.index'))
            ->post(route('storefront.editor.publish'), ['label' => 'Intento'])
            ->assertSessionHasErrors('publish');

        $this->assertStringContainsString('medio de pago', session('errors')->first('publish'));
        $this->assertNull($this->storefront->refresh()->published_version_id);
    }

    public function test_publicar_con_el_domicilio_sin_zonas_se_rechaza(): void
    {
        $this->addBlock('announcement', ['text' => 'Hola']);

        $this->storefront->settings()->updateOrCreate(['key' => 'coverage'], ['value' => ['mode' => 'zones', 'radius_km' => 3]]);

        $this->expectException(ValidationException::class);

        app(StorefrontPublisher::class)->publish($this->storefront->refresh(), $this->admin, 'Intento');
    }

    public function test_el_historial_se_puede_consultar(): void
    {
        $this->addBlock('announcement', ['text' => 'Hola']);

        app(StorefrontPublisher::class)->publish($this->storefront->refresh(), $this->admin, 'Uno');

        $this->editor()->get(route('storefront.editor.history'))
            ->assertOk()
            ->assertSee('Uno', false);
    }

    public function test_el_aspecto_publicado_llega_a_la_tienda(): void
    {
        $this->addBlock('announcement', ['text' => 'Hola']);
        $this->storefront->theme()->update(['accent' => '#FFFF00', 'font_pair' => 'editorial']);

        app(StorefrontPublisher::class)->publish($this->storefront->refresh(), $this->admin, 'Amarillo');
        app(StorefrontPublisher::class)->forget($this->storefront->refresh());

        // La tienda —no solo la vista previa— recibe el tema por las props compartidas.
        $this->get(route('storefront.home'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('storeTheme.accent', '#FFFF00')
                // Letra oscura sobre amarillo: con blanco el boton no se leeria.
                ->where('storeTheme.accent_ink', '#10161C')
                ->where('storeTheme.font_pair', 'editorial')
            );
    }

    public function test_sin_permiso_de_publicar_la_ruta_responde_403(): void
    {
        $this->addBlock('announcement', ['text' => 'Hola']);

        $cashier = $this->makeUser('cajero');

        $this->actingAs($cashier, 'web')
            ->withSession($this->withActiveStore())
            ->post(route('storefront.editor.publish'), ['label' => 'Intento'])
            ->assertForbidden();

        $this->assertNull($this->storefront->refresh()->published_version_id);
    }
}
