<?php

namespace Tests\Feature;

use App\Models\Register;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\TestCase;

/**
 * Los topes del plan: sedes, usuarios y cajas. Se cuentan los activos.
 */
class MembershipLimitsTest extends TestCase
{
    use CreatesCompanyEnvironment, RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEnvironment();
        ['plan' => $plan, 'cycle' => $cycle] = $this->makePlan(['name' => 'Pro', 'max_stores' => 3, 'max_users' => 2, 'max_registers' => 2], ['online_store']);
        $this->putCompanyOnPlan($plan, $cycle);
        $this->admin = $this->makeUser('admin');
    }

    protected function storePayload(string $code): array
    {
        return ['name' => "Sede {$code}", 'code' => $code, 'opens_at' => '07:00', 'closes_at' => '21:00', 'suggested_cash_base' => 150000, 'is_active' => true];
    }

    public function test_la_cuarta_sede_no_entra_y_dice_por_que(): void
    {
        foreach (['NOR', 'SUR'] as $code) {
            $this->actingAs($this->admin)->withSession($this->withActiveStore())
                ->post(route('stores.store'), $this->storePayload($code))
                ->assertSessionHasNoErrors();
        }

        $this->actingAs($this->admin)->withSession($this->withActiveStore())
            ->post(route('stores.store'), $this->storePayload('OCC'))
            ->assertSessionHasErrors(['name' => 'Tu plan permite 3 sedes. Cámbialo en Ajustes > Membresía.']);

        $this->assertSame(3, Store::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->count());
    }

    public function test_desactivar_libera_cupo_y_reactivar_lo_revisa(): void
    {
        foreach (['NOR', 'SUR'] as $code) {
            $this->actingAs($this->admin)->withSession($this->withActiveStore())->post(route('stores.store'), $this->storePayload($code));
        }

        $sur = Store::query()->withoutGlobalScopes()->where('code', 'SUR')->firstOrFail();
        $this->actingAs($this->admin)->withSession($this->withActiveStore())
            ->put(route('stores.update', $sur), array_merge($this->storePayload('SUR'), ['is_active' => false]))
            ->assertSessionHasNoErrors();

        $this->actingAs($this->admin)->withSession($this->withActiveStore())
            ->post(route('stores.store'), $this->storePayload('OCC'))
            ->assertSessionHasNoErrors();

        // Con tres activas, la desactivada no puede volver.
        $this->actingAs($this->admin)->withSession($this->withActiveStore())
            ->put(route('stores.update', $sur), $this->storePayload('SUR'))
            ->assertSessionHasErrors('is_active');

        $this->assertFalse((bool) $sur->fresh()->is_active);
    }

    public function test_el_tope_de_usuarios(): void
    {
        $payload = fn (string $email) => ['name' => 'Nuevo', 'email' => $email, 'role' => 'cajero', 'store_ids' => [$this->store->id]];

        $this->actingAs($this->admin)->withSession($this->withActiveStore())
            ->post(route('users.store'), $payload('uno@lacanasta.co'))
            ->assertSessionHasNoErrors();

        $this->actingAs($this->admin)->withSession($this->withActiveStore())
            ->post(route('users.store'), $payload('dos@lacanasta.co'))
            ->assertSessionHasErrors(['email' => 'Tu plan permite 2 usuarios. Cámbialo en Ajustes > Membresía.']);

        $this->assertFalse(User::query()->where('email', 'dos@lacanasta.co')->exists());
    }

    public function test_reactivar_un_usuario_revisa_el_tope(): void
    {
        $other = $this->makeUser('cajero');
        $other->update(['is_active' => false]);
        $this->makeUser('cajero');

        $this->actingAs($this->admin)->withSession($this->withActiveStore())
            ->patch(route('users.toggle', $other))
            ->assertSessionHas('error', 'Tu plan permite 2 usuarios. Cámbialo en Ajustes > Membresía.');

        $this->assertFalse((bool) $other->fresh()->is_active);
    }

    public function test_el_tope_de_cajas(): void
    {
        $payload = fn (string $code) => ['store_id' => $this->store->id, 'name' => "Caja {$code}", 'code' => $code, 'is_active' => true];

        $this->actingAs($this->admin)->withSession($this->withActiveStore())
            ->post(route('registers.store'), $payload('C2'))
            ->assertSessionHasNoErrors();

        $this->actingAs($this->admin)->withSession($this->withActiveStore())
            ->post(route('registers.store'), $payload('C3'))
            ->assertSessionHasErrors(['name' => 'Tu plan permite 2 cajas. Cámbialo en Ajustes > Membresía.']);

        $this->assertSame(2, Register::query()->withoutGlobalScopes()->count());
    }

    public function test_la_importacion_de_sedes_respeta_el_tope_fila_por_fila(): void
    {
        $this->actingAs($this->admin)->withSession($this->withActiveStore())
            ->post(route('stores.import'), [
                'file' => UploadedFile::fake()->createWithContent('sedes.csv', implode("\n", [
                    'codigo;nombre;direccion;ciudad;latitud;longitud;telefono;abre;cierra;base_caja;activa',
                    'NOR;Norte;Cra 50 # 80-10;Medellín;6.2968;-75.5645;;07:00;21:00;200000;si',
                    'SUR;Sur;Cra 43A # 10-20;Medellín;;;;07:00;21:00;200000;si',
                    'OCC;Occidente;Calle 33 # 80-10;Medellín;;;;07:00;21:00;200000;si',
                ])."\n"),
            ]);

        $result = session('import_result');

        $this->assertSame(2, $result['created']);
        $this->assertSame(1, $result['skipped']);
        $this->assertStringContainsString('Tu plan permite 3 sedes', implode(' ', $result['errors']));
        $this->assertSame(3, Store::query()->withoutGlobalScopes()->count());

        $norte = Store::query()->withoutGlobalScopes()->where('code', 'NOR')->firstOrFail();
        $this->assertSame('Medellín', $norte->city);
        $this->assertEqualsWithDelta(6.2968, (float) $norte->latitude, 0.00001);
    }

    public function test_sin_plan_no_hay_tope(): void
    {
        $this->company->forceFill(['membership_plan_id' => null])->save();

        foreach (['NOR', 'SUR', 'OCC', 'ORI'] as $code) {
            $this->actingAs($this->admin)->withSession($this->withActiveStore())
                ->post(route('stores.store'), $this->storePayload($code))
                ->assertSessionHasNoErrors();
        }

        $this->assertSame(5, Store::query()->withoutGlobalScopes()->count());
    }
}
