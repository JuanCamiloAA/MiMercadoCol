<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\Storefront;
use App\Models\User;
use App\Services\Storefront\StorefrontManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\Concerns\EditsStorefront;
use Tests\TestCase;

/**
 * La vista previa del borrador.
 *
 * Se abre sin sesion para poder mirarla en el telefono, asi que la firma es lo unico que la
 * protege: sin firma no entra, y a la media hora deja de servir.
 */
class StorefrontPreviewTest extends TestCase
{
    use CreatesCompanyEnvironment, EditsStorefront, RefreshDatabase;

    protected User $admin;

    protected Storefront $storefront;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEnvironment();

        $this->admin = $this->makeUser('admin');
        $this->storefront = app(StorefrontManager::class)->for($this->store);
        $this->makeStorefrontPublishable($this->storefront);
    }

    public function test_la_vista_previa_exige_firma(): void
    {
        $this->get(route('storefront.preview', $this->store))->assertForbidden();
    }

    public function test_con_firma_valida_muestra_el_borrador_sin_sesion(): void
    {
        $this->addBlock('announcement', ['text' => 'Borrador en vivo']);

        $this->get($this->signedUrl())
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Storefront/Preview')
                ->where('view', 'home')
                ->where('blocks.0.data.text', 'Borrador en vivo')
                ->has('shell.settings.topbar')
            );
    }

    public function test_la_firma_vence_a_la_media_hora(): void
    {
        $url = $this->signedUrl();

        $this->travel(29)->minutes();
        $this->get($url)->assertOk();

        $this->travel(2)->minutes();
        $this->get($url)->assertForbidden();

        $this->travelBack();
    }

    public function test_una_firma_manipulada_no_sirve(): void
    {
        $url = $this->signedUrl();
        $other = Store::query()->withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'name' => 'Otra', 'code' => 'OT', 'is_active' => true,
        ]);

        // Otra sede con la misma firma: no.
        $this->get(str_replace('/preview/'.$this->store->id, '/preview/'.$other->id, $url))->assertForbidden();
        // La firma cambiada: no.
        $this->get(preg_replace('/signature=([a-f0-9])/', 'signature=0$1', $url))->assertForbidden();
        // Lo que el editor elige sin volver a firmar (pagina, dispositivo) si sirve.
        $this->get($url.'&device=mobile&page=cart')->assertOk();
    }

    public function test_el_editor_entrega_el_enlace_firmado(): void
    {
        $this->openEditor()
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('storefront.store.id', $this->store->id)
                ->where('storefront.preview_url', fn (string $url) => str_contains($url, 'signature='))
            );
    }

    protected function signedUrl(): string
    {
        return URL::temporarySignedRoute('storefront.preview', now()->addMinutes(30), ['store' => $this->store->id]);
    }
}
