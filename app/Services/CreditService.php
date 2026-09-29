<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerBalance;
use App\Models\CustomerPayment;
use App\Models\CustomerPaymentAllocation;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cupo, saldo y abonos.
 *
 * El saldo vive en `customer_balances` y lo mantiene este servicio; ninguna pantalla lo
 * recalcula por su cuenta. El cupo disponible es siempre `credit_limit - balance`.
 */
class CreditService
{
    /**
     * Por que no se le puede vender a credito, o null si si se puede.
     */
    public function rejectionReason(Customer $customer, int $amount, ?User $seller = null): ?string
    {
        if (! $customer->credit_enabled) {
            return 'Este cliente no tiene credito habilitado.';
        }

        if ($customer->credit_blocked) {
            return $customer->blocked_reason
                ? "Credito bloqueado: {$customer->blocked_reason}"
                : 'El credito de este cliente esta bloqueado.';
        }

        $available = $this->availableCredit($customer);

        if ($amount > $available) {
            return 'Supera el cupo disponible ('.self::money($available).').';
        }

        if ($seller && ! $seller->can('pos.sale.credit')) {
            return 'No tienes permiso para vender a credito.';
        }

        return null;
    }

    public function canSellOnCredit(Customer $customer, int $amount, ?User $seller = null): bool
    {
        return $this->rejectionReason($customer, $amount, $seller) === null;
    }

    /**
     * Unico sitio que decide si el credito cabe. Lo usan el POS, la tienda en linea y las pruebas.
     *
     * Con `lock` se bloquea la fila del saldo antes de mirarlo: dos cajas pueden estar
     * cobrandole a credito al mismo cliente en el mismo segundo, y sin el bloqueo las dos
     * verian cupo suficiente y las dos venderian.
     */
    public function assertCanTake(Customer $customer, int $amount, ?User $seller = null, bool $lock = false): void
    {
        if ($lock) {
            CustomerBalance::query()->where('customer_id', $customer->id)->lockForUpdate()->first();
            $customer->unsetRelation('balance');
        }

        $reason = $this->rejectionReason($customer, $amount, $seller);

        if ($reason !== null) {
            throw ValidationException::withMessages(['credit' => [$reason]]);
        }
    }

    public function availableCredit(Customer $customer): int
    {
        return max(0, $customer->credit_limit - $this->currentBalance($customer));
    }

    public function currentBalance(Customer $customer): int
    {
        return (int) ($this->balanceRow($customer)->balance ?? 0);
    }

    public function balanceRow(Customer $customer): CustomerBalance
    {
        return CustomerBalance::query()->firstOrCreate(
            ['customer_id' => $customer->id],
            ['balance' => 0, 'overdue_balance' => 0]
        );
    }

    /**
     * Recalcula el saldo del cliente desde sus facturas pendientes.
     *
     * Se recalcula en vez de sumar y restar porque cualquier anulacion, devolucion o abono
     * mal aplicado dejaria el saldo desviado para siempre.
     */
    public function recalculate(Customer $customer): CustomerBalance
    {
        $pending = Sale::query()
            ->withoutGlobalScopes()
            ->where('customer_id', $customer->id)
            ->where('status', Sale::STATUS_CREDIT)
            ->where('due_total', '>', 0)
            ->get(['id', 'due_total', 'due_date']);

        $balance = (int) $pending->sum('due_total');
        $today = now()->startOfDay();

        $overdue = (int) $pending
            ->filter(fn (Sale $sale) => $sale->due_date && $sale->due_date->lt($today))
            ->sum('due_total');

        $oldestDue = $pending
            ->filter(fn (Sale $sale) => $sale->due_date !== null)
            ->sortBy('due_date')
            ->first()?->due_date;

        $row = $this->balanceRow($customer);
        $row->fill([
            'balance' => $balance,
            'overdue_balance' => $overdue,
            'oldest_due_date' => $oldestDue,
        ])->save();

        return $row;
    }

    /**
     * Registra un abono y lo aplica a las facturas mas antiguas primero.
     *
     * @return array{payment: CustomerPayment, applied: int, unapplied: int}
     */
    public function registerPayment(
        Customer $customer,
        int $amount,
        string $method,
        int $storeId,
        ?User $receivedBy = null,
        ?int $registerSessionId = null,
        ?string $note = null,
        ?\DateTimeInterface $paidAt = null,
    ): array {
        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => ['El abono debe ser mayor que cero.']]);
        }

        return DB::transaction(function () use ($customer, $amount, $method, $storeId, $receivedBy, $registerSessionId, $note, $paidAt) {
            $payment = CustomerPayment::create([
                'company_id' => $customer->company_id,
                'store_id' => $storeId,
                'customer_id' => $customer->id,
                'amount' => $amount,
                'method' => $method,
                'received_by_user_id' => $receivedBy?->id,
                'register_session_id' => $registerSessionId,
                'note' => $note,
                'paid_at' => $paidAt ?? now(),
            ]);

            $remaining = $amount;

            $pendingSales = Sale::query()
                ->withoutGlobalScopes()
                ->where('customer_id', $customer->id)
                ->where('status', Sale::STATUS_CREDIT)
                ->where('due_total', '>', 0)
                ->orderBy('sold_at')
                ->lockForUpdate()
                ->get();

            foreach ($pendingSales as $sale) {
                if ($remaining <= 0) {
                    break;
                }

                $applied = min($remaining, (int) $sale->due_total);

                CustomerPaymentAllocation::create([
                    'customer_payment_id' => $payment->id,
                    'sale_id' => $sale->id,
                    'amount' => $applied,
                ]);

                $sale->paid_total += $applied;
                $sale->due_total -= $applied;

                if ($sale->due_total <= 0) {
                    $sale->status = Sale::STATUS_PAID;
                    $sale->due_total = 0;
                }

                $sale->save();

                $remaining -= $applied;
            }

            $this->recalculate($customer);

            // Un cliente que se pone al dia recupera el credito sin que nadie tenga que acordarse.
            if ($customer->credit_blocked && $this->balanceRow($customer)->overdue_balance === 0) {
                $customer->forceFill(['credit_blocked' => false, 'blocked_reason' => null])->save();
            }

            return [
                'payment' => $payment,
                'applied' => $amount - $remaining,
                'unapplied' => $remaining,
            ];
        });
    }

    /**
     * Bloquea a quien tenga facturas vencidas hace mas dias de los tolerados.
     *
     * @return int clientes bloqueados
     */
    public function blockOverdueCustomers(int $companyId, int $toleranceDays): int
    {
        $limitDate = now()->startOfDay()->subDays($toleranceDays);

        $customerIds = Sale::query()
            ->withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('status', Sale::STATUS_CREDIT)
            ->where('due_total', '>', 0)
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<', $limitDate)
            ->distinct()
            ->pluck('customer_id')
            ->filter()
            ->all();

        if ($customerIds === []) {
            return 0;
        }

        return Customer::query()
            ->withoutGlobalScopes()
            ->whereIn('id', $customerIds)
            ->where('credit_blocked', false)
            ->update([
                'credit_blocked' => true,
                'blocked_reason' => "Facturas vencidas hace mas de {$toleranceDays} dias.",
            ]);
    }

    protected static function money(int $amount): string
    {
        return '$ '.number_format($amount, 0, ',', '.');
    }
}
