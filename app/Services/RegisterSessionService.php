<?php

namespace App\Services;

use App\Models\CashMovement;
use App\Models\CustomerPayment;
use App\Models\Register;
use App\Models\RegisterSession;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Turnos de caja.
 *
 * No puede haber dos sesiones abiertas en la misma caja ni ventas sin sesion abierta. La
 * comprobacion va dentro de la transaccion y con bloqueo, porque dos cajeros pulsando
 * "abrir" a la vez es exactamente el caso que rompe un arqueo.
 */
class RegisterSessionService
{
    /**
     * @param  array<string, int>  $breakdown  denominacion => cantidad
     */
    public function open(
        Register $register,
        User $user,
        int $openingAmount,
        array $breakdown = [],
        ?string $note = null,
        ?\DateTimeInterface $openedAt = null,
    ): RegisterSession {
        return DB::transaction(function () use ($register, $user, $openingAmount, $breakdown, $note, $openedAt) {
            $existing = RegisterSession::query()
                ->withoutGlobalScopes()
                ->where('register_id', $register->id)
                ->where('status', RegisterSession::STATUS_OPEN)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                throw ValidationException::withMessages([
                    'register_id' => ['Esa caja ya tiene un turno abierto. Cierralo antes de abrir otro.'],
                ]);
            }

            $openUserSession = RegisterSession::query()
                ->withoutGlobalScopes()
                ->where('user_id', $user->id)
                ->where('status', RegisterSession::STATUS_OPEN)
                ->first();

            if ($openUserSession) {
                throw ValidationException::withMessages([
                    'user_id' => ['Ese cajero ya tiene un turno abierto en otra caja.'],
                ]);
            }

            return RegisterSession::create([
                'company_id' => $register->company_id,
                'store_id' => $register->store_id,
                'register_id' => $register->id,
                'user_id' => $user->id,
                'opened_at' => $openedAt ?? now(),
                'opening_amount' => $openingAmount,
                'opening_breakdown' => $breakdown ?: null,
                'opening_note' => $note,
                'status' => RegisterSession::STATUS_OPEN,
            ]);
        });
    }

    /**
     * Efectivo que deberia haber en la gaveta.
     */
    public function expectedAmount(RegisterSession $session): int
    {
        $cashSales = (int) SalePayment::query()
            ->join('sales', 'sales.id', '=', 'sale_payments.sale_id')
            ->where('sales.register_session_id', $session->id)
            ->where('sales.status', '!=', Sale::STATUS_CANCELLED)
            ->where('sale_payments.method', SalePayment::METHOD_CASH)
            // El monto de la linea de efectivo trae el cambio adentro: en la gaveta queda la resta.
            ->sum(DB::raw(SalePayment::NET_AMOUNT_SQL));

        $cashPayments = (int) CustomerPayment::query()
            ->withoutGlobalScopes()
            ->where('register_session_id', $session->id)
            ->where('method', CustomerPayment::METHOD_CASH)
            ->sum('amount');

        $movementsIn = (int) CashMovement::query()
            ->where('register_session_id', $session->id)
            ->where('type', CashMovement::TYPE_IN)
            ->sum('amount');

        $movementsOut = (int) CashMovement::query()
            ->where('register_session_id', $session->id)
            ->where('type', CashMovement::TYPE_OUT)
            ->sum('amount');

        return $session->opening_amount + $cashSales + $cashPayments + $movementsIn - $movementsOut;
    }

    /**
     * Resumen del turno para la pantalla de cierre y el informe Z.
     *
     * @return array<string, mixed>
     */
    public function summary(RegisterSession $session): array
    {
        $byMethod = SalePayment::query()
            ->join('sales', 'sales.id', '=', 'sale_payments.sale_id')
            ->where('sales.register_session_id', $session->id)
            ->where('sales.status', '!=', Sale::STATUS_CANCELLED)
            ->groupBy('sale_payments.method')
            ->selectRaw('sale_payments.method, SUM('.SalePayment::NET_AMOUNT_SQL.') as total, COUNT(*) as count')
            ->get()
            ->map(fn ($row) => [
                'method' => $row->method,
                'label' => SalePayment::METHOD_LABELS[$row->method] ?? $row->method,
                'total' => (int) $row->total,
                'count' => (int) $row->count,
            ])
            ->values()
            ->all();

        $salesQuery = Sale::query()
            ->withoutGlobalScopes()
            ->where('register_session_id', $session->id)
            ->where('status', '!=', Sale::STATUS_CANCELLED);

        $movements = CashMovement::query()
            ->where('register_session_id', $session->id)
            ->orderBy('occurred_at')
            ->get()
            ->map(fn (CashMovement $movement) => [
                'id' => $movement->id,
                'type' => $movement->type,
                'amount' => $movement->amount,
                'reason' => $movement->reason,
                'occurred_at' => $movement->occurred_at->toIso8601String(),
            ])
            ->all();

        $customerPayments = (int) CustomerPayment::query()
            ->withoutGlobalScopes()
            ->where('register_session_id', $session->id)
            ->where('method', CustomerPayment::METHOD_CASH)
            ->sum('amount');

        return [
            'sales_count' => (int) $salesQuery->clone()->count(),
            'sales_total' => (int) $salesQuery->clone()->sum('total'),
            'payments_by_method' => $byMethod,
            'customer_payments_cash' => $customerPayments,
            'cash_in' => (int) CashMovement::query()->where('register_session_id', $session->id)->where('type', CashMovement::TYPE_IN)->sum('amount'),
            'cash_out' => (int) CashMovement::query()->where('register_session_id', $session->id)->where('type', CashMovement::TYPE_OUT)->sum('amount'),
            'movements' => $movements,
            'expected_amount' => $this->expectedAmount($session),
        ];
    }

    /**
     * @param  array<string, int>  $breakdown
     */
    public function close(RegisterSession $session, int $countedAmount, array $breakdown = [], ?string $note = null): RegisterSession
    {
        if (! $session->isOpen()) {
            throw ValidationException::withMessages([
                'register_session_id' => ['Ese turno ya esta cerrado.'],
            ]);
        }

        $expected = $this->expectedAmount($session);
        $difference = $countedAmount - $expected;

        if ($difference !== 0 && blank($note)) {
            throw ValidationException::withMessages([
                'closing_note' => ['Hay una diferencia en el arqueo: escribe una observacion que la explique.'],
            ]);
        }

        $session->fill([
            'closed_at' => now(),
            'counted_amount' => $countedAmount,
            'closing_breakdown' => $breakdown ?: null,
            'expected_amount' => $expected,
            'difference' => $difference,
            'closing_note' => $note,
            'status' => RegisterSession::STATUS_CLOSED,
            // Un turno que cuadra no tiene nada que revisar; el que no, queda esperando que
            // un administrador decida que paso con esa plata.
            'difference_status' => $difference === 0
                ? RegisterSession::DIFFERENCE_RESOLVED
                : RegisterSession::DIFFERENCE_PENDING,
        ])->save();

        return $session->refresh();
    }

    public function registerCashMovement(RegisterSession $session, string $type, int $amount, string $reason, User $user): CashMovement
    {
        if (! $session->isOpen()) {
            throw ValidationException::withMessages([
                'register_session_id' => ['No se pueden registrar movimientos en un turno cerrado.'],
            ]);
        }

        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => ['El monto debe ser mayor que cero.']]);
        }

        if ($type === CashMovement::TYPE_OUT && $amount > $this->expectedAmount($session)) {
            throw ValidationException::withMessages([
                'amount' => ['No puedes sacar mas efectivo del que hay en la caja.'],
            ]);
        }

        return CashMovement::create([
            'register_session_id' => $session->id,
            'type' => $type,
            'amount' => $amount,
            'reason' => $reason,
            'user_id' => $user->id,
            'occurred_at' => now(),
        ]);
    }

    /**
     * El turno en el que esta persona va a vender.
     *
     * Manda el suyo, si tiene uno abierto. Si no, puede vender en una caja que abrio otro:
     * en el mostrador se turnan la caja durante el dia y no tiene sentido obligar a cada
     * asesor a abrir la suya. El efectivo sigue siendo del turno y responde por el quien lo
     * abrio; a quien se le atribuye la **venta** se decide al cobrar.
     *
     * `$preferred` es la caja que la persona eligio cuando hay varias abiertas.
     */
    public function openSessionFor(User $user, int $storeId, ?int $preferred = null): ?RegisterSession
    {
        $own = $this->ownOpenSession($user, $storeId);

        if ($preferred !== null) {
            $chosen = $this->openSessions($storeId)->firstWhere('id', $preferred);

            if ($chosen) {
                return $chosen;
            }
        }

        return $own ?? $this->openSessions($storeId)->first();
    }

    /** El turno propio, el unico que esta persona puede cerrar. */
    public function ownOpenSession(User $user, int $storeId): ?RegisterSession
    {
        return RegisterSession::query()
            ->where('user_id', $user->id)
            ->where('store_id', $storeId)
            ->where('status', RegisterSession::STATUS_OPEN)
            ->with('register')
            ->first();
    }

    /**
     * Todas las cajas abiertas de la sede, para poder elegir.
     *
     * @return \Illuminate\Support\Collection<int, RegisterSession>
     */
    public function openSessions(int $storeId): \Illuminate\Support\Collection
    {
        // Se ordena en PHP y no con un `join`: `register_sessions` y `registers` tienen las dos
        // una columna `store_id`, y unirlas deja la condicion ambigua. Son dos o tres filas.
        return RegisterSession::query()
            ->where('store_id', $storeId)
            ->where('status', RegisterSession::STATUS_OPEN)
            ->with(['register', 'user:id,name,last_name,employee_id'])
            ->get()
            ->sortBy(fn (RegisterSession $session) => $session->register?->name ?? '')
            ->values();
    }
}
