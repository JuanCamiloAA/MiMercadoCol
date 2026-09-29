<?php

namespace Tests\Feature;

use App\Models\RegisterSession;
use App\Models\User;
use App\Services\RegisterSessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\TestCase;

/**
 * Diferencias de caja.
 *
 * Un descuadre no puede quedarse como un aviso eterno en la pantalla de apertura: alguien
 * tiene que decidir que paso con esa plata y que quede escrito quien lo decidio.
 */
class CashDifferenceTest extends TestCase
{
    use CreatesCompanyEnvironment, RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEnvironment();
        $this->admin = $this->makeUser('admin');
    }

    public function test_un_turno_que_cuadra_no_queda_pendiente(): void
    {
        $session = $this->closeWith(0);

        $this->assertSame(RegisterSession::DIFFERENCE_RESOLVED, $session->difference_status);
        $this->assertSame(0, RegisterSession::query()->withoutGlobalScopes()->pendingReview()->count());
    }

    public function test_un_turno_descuadrado_queda_esperando_revision(): void
    {
        $session = $this->closeWith(-1000);

        $this->assertSame(-1000, $session->difference);
        $this->assertSame(RegisterSession::DIFFERENCE_PENDING, $session->difference_status);
        $this->assertSame(1, RegisterSession::query()->withoutGlobalScopes()->pendingReview()->count());
    }

    public function test_resolver_deja_la_decision_con_nombre_y_fecha(): void
    {
        $session = $this->closeWith(-1000);

        $this->as()->post(route('registers.differences.resolve', $session), [
            'resolution' => RegisterSession::RESOLUTION_ABSORBED,
            'note' => 'Se le dio mal el vuelto en la tarde; se hablo con la cajera.',
        ])->assertRedirect();

        $session = $session->fresh();

        $this->assertTrue($session->isResolved());
        $this->assertSame('La asume la empresa', $session->resolutionLabel());
        $this->assertSame($this->admin->id, $session->resolved_by_user_id);
        $this->assertNotNull($session->resolved_at);
        $this->assertSame(0, RegisterSession::query()->withoutGlobalScopes()->pendingReview()->count());
    }

    public function test_la_decision_queda_en_la_bitacora(): void
    {
        $session = $this->closeWith(2500);

        $this->as()->post(route('registers.differences.resolve', $session), [
            'resolution' => RegisterSession::RESOLUTION_JUSTIFIED,
            'note' => 'Sobrante de un pago que se registro dos veces y ya se corrigio.',
        ]);

        $this->assertDatabaseHas('access_logs', [
            'user_id' => $this->admin->id,
            'action' => 'cash_difference_resolved',
        ]);
    }

    public function test_no_se_resuelve_sin_explicacion(): void
    {
        $session = $this->closeWith(-1000);

        $this->as()->post(route('registers.differences.resolve', $session), [
            'resolution' => RegisterSession::RESOLUTION_ABSORBED,
            'note' => 'na',
        ])->assertSessionHasErrors('note');

        $this->assertFalse($session->fresh()->isResolved());
    }

    public function test_un_turno_que_cuadro_no_se_puede_resolver(): void
    {
        $session = $this->closeWith(0);

        $this->as()->post(route('registers.differences.resolve', $session), [
            'resolution' => RegisterSession::RESOLUTION_ABSORBED,
            'note' => 'No hay nada que resolver aqui.',
        ])->assertStatus(422);
    }

    public function test_reabrir_devuelve_la_diferencia_a_pendiente(): void
    {
        $session = $this->closeWith(-1000);

        $this->as()->post(route('registers.differences.resolve', $session), [
            'resolution' => RegisterSession::RESOLUTION_CHARGED,
            'note' => 'Se le descuenta de la quincena.',
        ]);

        $this->as()->post(route('registers.differences.reopen', $session))->assertRedirect();

        $session = $session->fresh();

        $this->assertFalse($session->isResolved());
        $this->assertNull($session->resolution);
        $this->assertNull($session->resolved_by_user_id);
    }

    public function test_el_listado_muestra_lo_pendiente_con_sus_cifras(): void
    {
        $this->closeWith(-1000);

        $this->as()->get(route('registers.differences.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Registers/Differences')
                ->has('differences.data', 1)
                ->where('differences.data.0.difference', -1000)
                ->where('differences.data.0.kind', 'faltante')
                ->where('metrics.pending', 1)
                ->where('metrics.shortfall', 1000)
                ->where('metrics.surplus', 0));
    }

    public function test_la_apertura_avisa_si_la_diferencia_sigue_sin_revisar(): void
    {
        $this->closeWith(-1000);

        $this->as()->get(route('registers.opening.create'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('lastClosing.difference', -1000)
                ->where('lastClosing.resolved', false)
                ->where('lastClosing.can_review', true));
    }

    public function test_un_cajero_no_resuelve_diferencias(): void
    {
        $session = $this->closeWith(-1000);
        $cashier = $this->makeUser('cajero');

        $this->actingAs($cashier)
            ->withSession($this->withActiveStore())
            ->post(route('registers.differences.resolve', $session), [
                'resolution' => RegisterSession::RESOLUTION_ABSORBED,
                'note' => 'Yo decido que la asume la empresa.',
            ])
            ->assertStatus(403);
    }

    /** Abre un turno y lo cierra con la diferencia pedida. */
    protected function closeWith(int $difference): RegisterSession
    {
        $sessions = app(RegisterSessionService::class);
        $session = $sessions->open($this->register, $this->admin, 200000);

        // Sin ventas, lo esperado es la base: contar de mas o de menos crea la diferencia.
        return $sessions->close(
            $session,
            200000 + $difference,
            [],
            $difference === 0 ? null : 'Conteo del turno',
        );
    }

    /** Todas las llamadas van como administrador y con la sede activa puesta. */
    protected function as(): self
    {
        $this->actingAs($this->admin);
        $this->withSession($this->withActiveStore());

        return $this;
    }
}
