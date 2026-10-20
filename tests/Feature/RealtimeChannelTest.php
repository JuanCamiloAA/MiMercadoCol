<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Store;
use App\Models\User;
use App\Support\Channels;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\TestCase;

/**
 * Quien puede escuchar que canal.
 *
 * Es lo mas delicado del tiempo real: un canal mal autorizado le transmite a una empresa lo
 * que pasa en otra, en vivo, y sin dejar rastro en ningun listado. Aqui se fija que no pase.
 */
class RealtimeChannelTest extends TestCase
{
    use CreatesCompanyEnvironment, RefreshDatabase;

    protected User $admin;

    protected Store $north;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEnvironment();

        $this->admin = $this->makeUser('admin');

        $this->north = Store::query()->withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'name' => 'Norte',
            'code' => 'NOR',
            'opens_at' => '07:00:00',
            'closes_at' => '21:00:00',
            'suggested_cash_base' => 0,
            'is_active' => true,
        ]);
    }

    public function test_el_administrador_entra_a_los_canales_de_su_sede(): void
    {
        foreach ([
            Channels::orders($this->company->id, $this->store->id),
            Channels::pos($this->company->id, $this->store->id),
            Channels::registers($this->company->id, $this->store->id),
            Channels::inventory($this->company->id, $this->store->id),
            Channels::catalog($this->company->id),
        ] as $channel) {
            $this->authorize($this->admin, $channel)->assertOk();
        }
    }

    public function test_nadie_escucha_los_canales_de_otra_empresa(): void
    {
        $otherCompany = Company::create([
            'name' => 'Otra empresa',
            'legal_name' => 'Otra S.A.S.',
            'tax_id' => '901222333-4',
            'currency' => 'COP',
            'is_active' => true,
        ]);

        $otherStore = Store::query()->withoutGlobalScopes()->create([
            'company_id' => $otherCompany->id,
            'name' => 'Ajena',
            'code' => 'AJE',
            'opens_at' => '07:00:00',
            'closes_at' => '21:00:00',
            'suggested_cash_base' => 0,
            'is_active' => true,
        ]);

        // Lo que pasa en la caja de otra empresa no se escucha desde aqui.
        foreach ([
            Channels::orders($otherCompany->id, $otherStore->id),
            Channels::pos($otherCompany->id, $otherStore->id),
            Channels::registers($otherCompany->id, $otherStore->id),
            Channels::catalog($otherCompany->id),
            Channels::changes($otherCompany->id),
        ] as $channel) {
            $this->authorize($this->admin, $channel)->assertForbidden();
        }
    }

    public function test_el_aviso_de_cambios_lo_escucha_cualquiera_de_la_empresa(): void
    {
        // Lleva solo el tema y la sede: basta ser de la empresa. Los datos los cuida cada ruta.
        $this->authorize($this->admin, Channels::changes($this->company->id))->assertOk();

        $courier = $this->makeUser('domiciliario');
        $this->authorize($courier, Channels::changes($this->company->id))->assertOk();
    }

    public function test_un_id_de_empresa_cambiado_a_mano_no_sirve(): void
    {
        // El truco evidente: dejar el nombre del canal igual y cambiar solo la empresa.
        $this->authorize($this->admin, "company.999.store.{$this->store->id}.orders")->assertForbidden();
    }

    public function test_un_cajero_de_una_sede_no_escucha_la_otra(): void
    {
        $cashier = $this->makeUser('cajero');
        $cashier->stores()->sync([$this->store->id]);

        $this->authorize($cashier, Channels::pos($this->company->id, $this->store->id))->assertOk();
        $this->authorize($cashier, Channels::pos($this->company->id, $this->north->id))->assertForbidden();
    }

    public function test_cada_rol_escucha_solo_lo_que_sus_permisos_justifican(): void
    {
        // El domiciliario es el caso limpio: solo tiene permisos de pedidos.
        $courier = $this->makeUser('domiciliario');
        $courier->stores()->sync([$this->store->id]);

        $this->authorize($courier, Channels::orders($this->company->id, $this->store->id))->assertOk();

        foreach ([
            Channels::pos($this->company->id, $this->store->id),
            Channels::registers($this->company->id, $this->store->id),
            Channels::inventory($this->company->id, $this->store->id),
            Channels::catalog($this->company->id),
        ] as $channel) {
            $this->authorize($courier, $channel)->assertForbidden();
        }
    }

    public function test_el_cajero_escucha_su_caja_pero_no_puede_ver_las_ajenas(): void
    {
        $cashier = $this->makeUser('cajero');
        $cashier->stores()->sync([$this->store->id]);

        // Abre y cierra su propia caja, asi que el canal es suyo.
        $this->authorize($cashier, Channels::registers($this->company->id, $this->store->id))->assertOk();

        // Pero no puede ver los cierres de los demas. Por eso los eventos son un aviso y no
        // traen montos: lo que se ve sale despues por la pantalla, donde ese permiso manda.
        $this->assertFalse($cashier->can('registers.close.view_others'));

        // Bodega no es lo suyo.
        $this->authorize($cashier, Channels::inventory($this->company->id, $this->store->id))->assertForbidden();
    }

    public function test_un_cliente_de_la_tienda_en_linea_no_escucha_nada(): void
    {
        $customer = $this->makeCustomer([
            'is_online_user' => true,
            'password' => Hash::make('Cliente123*'),
        ]);

        // El guard `customer` no es el guard `web`. Sin fijarlo explicitamente, el cliente
        // llegaba hasta el callback del canal y reventaba con un 500; la respuesta correcta
        // para la tienda es que no hay nada que escuchar.
        $this->actingAs($customer, 'customer')
            ->post('/broadcasting/auth', [
                'socket_id' => '1234.5678',
                'channel_name' => 'private-'.Channels::orders($this->company->id, $this->store->id),
            ])
            ->assertStatus(302);
    }

    public function test_sin_sesion_no_se_autoriza_ningun_canal(): void
    {
        $this->post('/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => 'private-'.Channels::orders($this->company->id, $this->store->id),
        ])->assertStatus(302);
    }

    public function test_el_canal_de_presencia_solo_entrega_lo_justo(): void
    {
        $response = $this->actingAs($this->admin)
            ->withSession($this->withActiveStore())
            ->post('/broadcasting/auth', [
                'socket_id' => '1234.5678',
                'channel_name' => 'presence-'.Channels::presence($this->company->id, $this->store->id),
            ]);

        $response->assertOk();

        $info = json_decode($response->json('channel_data') ?? '{}', true)['user_info'] ?? [];

        $this->assertSame($this->admin->full_name, $info['name']);
        // Lo que se devuelve aqui lo ve todo el canal: ni correo, ni documento, ni permisos.
        $this->assertEqualsCanonicalizing(['id', 'name', 'initials', 'role', 'avatar'], array_keys($info));
    }

    public function test_los_canales_compartidos_llevan_la_empresa_y_la_sede(): void
    {
        $this->actingAs($this->admin)
            ->withSession($this->withActiveStore())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('realtime.company_id', $this->company->id)
                ->where('realtime.store_id', $this->store->id)
                ->where('realtime.channels.orders', Channels::orders($this->company->id, $this->store->id))
                ->where('realtime.enabled', true)
            );
    }

    /** Pide autorizacion de un canal privado, como lo haria el navegador. */
    protected function authorize(User $user, string $channel): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($user)
            ->withSession($this->withActiveStore())
            ->post('/broadcasting/auth', [
                'socket_id' => '1234.5678',
                'channel_name' => 'private-'.$channel,
            ]);
    }
}
