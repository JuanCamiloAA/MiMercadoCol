<?php

namespace Tests\Feature;

use App\Models\NewsletterSubscription;
use App\Services\Storefront\StorefrontStructure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\BuildsStorefront;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\TestCase;

/**
 * El formulario de novedades del pie: es opcional (la sede lo enciende), pide la autorización de
 * datos, no repite correos, tiene tope de intentos, y la lista del panel pide su permiso.
 */
class NewsletterSubscriptionTest extends TestCase
{
    use BuildsStorefront, CreatesCompanyEnvironment, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEnvironment();
    }

    protected function enable(bool $on = true): void
    {
        $this->publishSetting('footer', array_merge(StorefrontStructure::DEFAULTS['footer'], ['newsletter' => $on]));
    }

    public function test_sin_encender_el_formulario_no_recibe_correos(): void
    {
        $this->enable(false);

        $this->shopAt()->postJson(route('storefront.newsletter.store'), ['email' => 'ana@correo.co', 'consent' => true])->assertNotFound();
        $this->assertSame(0, NewsletterSubscription::query()->withoutGlobalScopes()->count());
    }

    public function test_suscribe_con_autorizacion_y_no_repite(): void
    {
        $this->enable();

        $this->shopAt()->postJson(route('storefront.newsletter.store'), ['email' => 'Ana@Correo.co', 'consent' => false])->assertUnprocessable()->assertJsonValidationErrors('consent');
        $this->shopAt()->postJson(route('storefront.newsletter.store'), ['email' => 'Ana@Correo.co', 'consent' => true])->assertOk()->assertJsonPath('message', '¡Listo! Te escribiremos a ana@correo.co.');
        $this->shopAt()->postJson(route('storefront.newsletter.store'), ['email' => 'ana@correo.co ', 'consent' => true])->assertOk();
        // La trampa para robots.
        $this->shopAt()->postJson(route('storefront.newsletter.store'), ['email' => 'robot@spam.co', 'consent' => true, 'website' => 'http://spam'])->assertUnprocessable();

        $rows = NewsletterSubscription::query()->withoutGlobalScopes()->get();
        $this->assertCount(1, $rows);
        $this->assertSame($this->company->id, $rows[0]->company_id);
        $this->assertSame($this->store->id, $rows[0]->store_id);
        $this->assertNotNull($rows[0]->consent_at);
    }

    public function test_tiene_tope_de_intentos(): void
    {
        $this->enable();

        foreach (range(1, 5) as $attempt) {
            $this->shopAt()->postJson(route('storefront.newsletter.store'), ['email' => "c{$attempt}@correo.co", 'consent' => true])->assertOk();
        }

        $this->shopAt()->postJson(route('storefront.newsletter.store'), ['email' => 'c6@correo.co', 'consent' => true])->assertStatus(429);
    }

    public function test_la_lista_del_panel_pide_su_permiso_y_se_descarga(): void
    {
        NewsletterSubscription::query()->withoutGlobalScopes()->create(['company_id' => $this->company->id, 'store_id' => $this->store->id, 'email' => 'ana@correo.co', 'consent_at' => now()]);
        NewsletterSubscription::query()->withoutGlobalScopes()->create(['company_id' => $this->makeOtherCompany()['company']->id, 'email' => 'otra@empresa.co', 'consent_at' => now()]);

        $admin = $this->makeUser('admin');
        $this->assertTrue($admin->can('storefront.newsletter.view'));

        $this->actingAs($admin, 'web')->withSession($this->withActiveStore())->get(route('storefront.newsletter.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Storefront/Newsletter/Index')->where('total', 1)->where('subscriptions.data.0.email', 'ana@correo.co'));

        $csv = $this->actingAs($admin, 'web')->withSession($this->withActiveStore())->get(route('storefront.newsletter.export'))->assertOk()->streamedContent();
        $this->assertStringContainsString('ana@correo.co', $csv);
        $this->assertStringNotContainsString('otra@empresa.co', $csv);

        $cashier = $this->makeUser('cajero');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actingAs($cashier, 'web')->withSession($this->withActiveStore())->get(route('storefront.newsletter.index'))->assertForbidden();
    }
}
