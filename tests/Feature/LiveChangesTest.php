<?php

namespace Tests\Feature;

use App\Broadcasting\GracefulBroadcaster;
use App\Events\ResourcesChanged;
use App\Models\User;
use App\Realtime\ChangeCollector;
use App\Realtime\Topics;
use App\Support\Channels;
use Illuminate\Contracts\Broadcasting\Broadcaster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia;
use RuntimeException;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\TestCase;

/**
 * Lo que cambia en una pestaña llega a las demas: un aviso por peticion, con el tema y la sede,
 * y cada pantalla sabe que temas muestra.
 */
class LiveChangesTest extends TestCase
{
    use CreatesCompanyEnvironment, RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEnvironment();
        $this->admin = $this->makeUser('admin');
        config(['broadcasting.changes' => true]);
    }

    public function test_un_cambio_sale_en_un_solo_aviso_con_su_tema_y_su_sede(): void
    {
        Event::fake([ResourcesChanged::class]);
        $product = $this->makeProduct(['name' => 'Arroz'], 20);

        // Descartar guarda un modelo de la sede: el aviso dice «storefront» en esta sede.
        $this->actingAs($this->admin)->withSession($this->withActiveStore())
            ->post(route('storefront.catalog.suggestions.dismiss'), ['store_id' => $this->store->id, 'product_id' => $product->id, 'group' => 'pos_sellers'])
            ->assertSessionHasNoErrors();

        Event::assertDispatchedTimes(ResourcesChanged::class, 1);
        Event::assertDispatched(ResourcesChanged::class, fn (ResourcesChanged $event) => $event->companyId === $this->company->id
            && $event->channelName() === Channels::changes($this->company->id)
            && $event->changes === [['topic' => 'storefront', 'stores' => [$this->store->id]]]
            && $event->userId === $this->admin->id);
    }

    public function test_lo_que_se_escribe_sin_modelo_tambien_avisa(): void
    {
        Event::fake([ResourcesChanged::class]);
        $product = $this->makeProduct(['name' => 'Panela'], 5);

        // Publicar es un `update` masivo: el controlador avisa a mano.
        $this->actingAs($this->admin)->withSession($this->withActiveStore())
            ->post(route('storefront.catalog.update'), ['product_ids' => [$product->id], 'field' => 'is_online_visible', 'value' => true])
            ->assertSessionHasNoErrors();

        Event::assertDispatched(ResourcesChanged::class, fn (ResourcesChanged $event) => collect($event->changes)->contains(fn (array $change) => $change['topic'] === 'products' && $change['stores'] === [null]));
    }

    public function test_un_get_no_avisa_nada_aunque_guarde_algo(): void
    {
        $collector = app(ChangeCollector::class);

        // Lo que se guarda leyendo una pagina no se transmite: si no, dos pestañas abiertas se
        // recargarian la una a la otra para siempre.
        $this->app->instance('request', Request::create('/inventario', 'GET'));
        $this->makeProduct(['name' => 'Sal']);
        $this->assertSame([], $collector->pending());

        $this->app->instance('request', Request::create('/productos', 'POST'));
        $this->makeProduct(['name' => 'Azucar']);
        $this->assertArrayHasKey('products', $collector->pending()[$this->company->id]);
        $this->assertArrayHasKey($this->store->id, $collector->pending()[$this->company->id]['inventory']);
    }

    public function test_apagado_no_transmite_y_vacia_lo_juntado(): void
    {
        config(['broadcasting.changes' => false]);
        Event::fake([ResourcesChanged::class]);

        $collector = app(ChangeCollector::class);
        $this->app->instance('request', Request::create('/productos', 'POST'));
        $this->makeProduct(['name' => 'Cafe']);
        $collector->flush();

        Event::assertNotDispatched(ResourcesChanged::class);
        $this->assertSame([], $collector->pending());
    }

    public function test_cada_pantalla_sabe_que_temas_escucha(): void
    {
        $this->actingAs($this->admin)->withSession($this->withActiveStore())
            ->get(route('inventory.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('realtime.channels.changes', Channels::changes($this->company->id))
                ->where('realtime.topics', fn ($topics) => collect($topics)->contains('inventory') && collect($topics)->contains('products')));

        // Gana el prefijo mas largo, y lo que no esta mapeado no se recarga solo.
        $this->assertContains('storefront', Topics::forRoute('storefront.catalog.out_of_stock'));
        $this->assertSame([], Topics::forRoute('storefront.editor.index'));
        $this->assertSame(Topics::ROUTES['transfers'], Topics::forRoute('transfers.show'));
        $this->assertSame([], Topics::forRoute(null));
    }

    public function test_con_reverb_caido_deja_de_intentar_un_rato(): void
    {
        Cache::forget('realtime:paused_until');

        $inner = new class implements Broadcaster
        {
            public int $calls = 0;

            public function auth($request) {}

            public function validAuthenticationResponse($request, $result) {}

            public function broadcast(array $channels, $event, array $payload = []): void
            {
                $this->calls++;

                throw new RuntimeException('Reverb no contesta');
            }
        };

        $broadcaster = new GracefulBroadcaster($inner);

        // El primer fallo no sube; los siguientes ni siquiera intentan, para no demorar cada guardado.
        $broadcaster->broadcast(['private-x'], 'ResourcesChanged');
        $broadcaster->broadcast(['private-x'], 'ResourcesChanged');
        $broadcaster->broadcast(['private-x'], 'ResourcesChanged');

        $this->assertSame(1, $inner->calls);
    }
}
