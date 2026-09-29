<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\StoreCreditMovement;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * El saldo a favor de los clientes. Unico camino que lo abona, lo cobra o lo vence.
 *
 * Cada abono guarda cuanto le queda y cuando vence; un cobro gasta primero lo que vence antes.
 * `customers.store_credit_balance` se reescribe desde los movimientos en la misma transaccion y
 * con la fila del cliente bloqueada: dos cajas cobrando el mismo saldo no lo pueden gastar dos
 * veces. Quien llama ya esta dentro de una transaccion (venta, devolucion, pedido); si no, abre una.
 */
class StoreCreditService
{
    public function __construct(protected SettingsService $settings) {}

    /** Lo que el cliente puede gastar hoy: lo vencido que el comando aun no ha cerrado no cuenta. */
    public function available(Customer $customer): int
    {
        return (int) StoreCreditMovement::query()
            ->withoutGlobalScopes()
            ->where('customer_id', $customer->id)
            ->where('remaining', '>', 0)
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->sum('remaining');
    }

    /**
     * Abona saldo. El vencimiento sale de Ajustes (`returns.store_credit_days`) salvo que se diga.
     *
     * @param  array{customer_return_id?: int|null, sale_id?: int|null, order_id?: int|null}  $refs
     */
    public function grant(Customer $customer, int $amount, string $type, array $refs, ?User $user, string $note): StoreCreditMovement
    {
        if ($amount <= 0) {
            throw ValidationException::withMessages(['store_credit' => ['El saldo a abonar debe ser mayor que cero.']]);
        }

        if ($customer->isGenericConsumer()) {
            throw ValidationException::withMessages(['customer_id' => ['Al consumidor final no se le abona saldo a favor: elige la ficha del cliente.']]);
        }

        return DB::transaction(function () use ($customer, $amount, $type, $refs, $user, $note) {
            $locked = $this->lock($customer);
            $days = (int) $this->settings->get($locked->company_id, 'returns.store_credit_days');

            $movement = StoreCreditMovement::create([
                'company_id' => $locked->company_id,
                'customer_id' => $locked->id,
                'type' => $type,
                'amount' => $amount,
                'remaining' => $amount,
                'expires_at' => $days > 0 ? CarbonImmutable::now()->addDays($days)->endOfDay() : null,
                'customer_return_id' => $refs['customer_return_id'] ?? null,
                'sale_id' => $refs['sale_id'] ?? null,
                'order_id' => $refs['order_id'] ?? null,
                'user_id' => $user?->id,
                'note' => mb_substr($note, 0, 255),
                'occurred_at' => now(),
            ]);

            $this->syncBalance($locked);

            return $movement;
        });
    }

    /**
     * Cobra saldo, gastando primero lo que vence antes.
     *
     * @param  array{customer_return_id?: int|null, sale_id?: int|null, order_id?: int|null}  $refs
     */
    public function consume(Customer $customer, int $amount, string $type, array $refs, ?User $user, string $note): StoreCreditMovement
    {
        if ($amount <= 0) {
            throw ValidationException::withMessages(['store_credit' => ['El saldo a usar debe ser mayor que cero.']]);
        }

        return DB::transaction(function () use ($customer, $amount, $type, $refs, $user, $note) {
            $locked = $this->lock($customer);
            $available = $this->available($locked);

            if ($amount > $available) {
                throw ValidationException::withMessages([
                    'payments' => ["{$locked->name} tiene $ ".number_format($available, 0, ',', '.').' de saldo a favor: no alcanza para $ '.number_format($amount, 0, ',', '.').'.'],
                ]);
            }

            $pending = $amount;

            $credits = StoreCreditMovement::query()
                ->withoutGlobalScopes()
                ->where('customer_id', $locked->id)
                ->where('remaining', '>', 0)
                ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                // Lo que vence primero se gasta primero; lo que no vence, al final.
                ->orderByRaw('expires_at IS NULL')
                ->orderBy('expires_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($credits as $credit) {
                if ($pending <= 0) {
                    break;
                }

                $take = min($pending, (int) $credit->remaining);
                $credit->update(['remaining' => (int) $credit->remaining - $take]);
                $pending -= $take;
            }

            $movement = StoreCreditMovement::create([
                'company_id' => $locked->company_id,
                'customer_id' => $locked->id,
                'type' => $type,
                'amount' => -$amount,
                'remaining' => null,
                'customer_return_id' => $refs['customer_return_id'] ?? null,
                'sale_id' => $refs['sale_id'] ?? null,
                'order_id' => $refs['order_id'] ?? null,
                'user_id' => $user?->id,
                'note' => mb_substr($note, 0, 255),
                'occurred_at' => now(),
            ]);

            $this->syncBalance($locked);

            return $movement;
        });
    }

    /**
     * Cierra lo vencido: un movimiento por abono con lo que le quedaba. Lo corre el comando
     * `store-credit:expire` a diario; tambien sirve a mano.
     */
    public function expire(?int $companyId = null): int
    {
        $expired = StoreCreditMovement::query()
            ->withoutGlobalScopes()
            ->when($companyId, fn ($query, $id) => $query->where('company_id', $id))
            ->where('remaining', '>', 0)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->pluck('customer_id')
            ->unique();

        $count = 0;

        foreach ($expired as $customerId) {
            DB::transaction(function () use ($customerId, &$count) {
                $customer = Customer::query()->withoutGlobalScopes()->find($customerId);

                if (! $customer) {
                    return;
                }

                $locked = $this->lock($customer);

                $credits = StoreCreditMovement::query()
                    ->withoutGlobalScopes()
                    ->where('customer_id', $locked->id)
                    ->where('remaining', '>', 0)
                    ->whereNotNull('expires_at')
                    ->where('expires_at', '<=', now())
                    ->lockForUpdate()
                    ->get();

                foreach ($credits as $credit) {
                    StoreCreditMovement::create([
                        'company_id' => $locked->company_id,
                        'customer_id' => $locked->id,
                        'type' => StoreCreditMovement::TYPE_EXPIRED,
                        'amount' => -(int) $credit->remaining,
                        'remaining' => null,
                        'customer_return_id' => $credit->customer_return_id,
                        'note' => 'Vencio el saldo abonado el '.$credit->occurred_at->format('d/m/Y'),
                        'occurred_at' => now(),
                    ]);

                    $credit->update(['remaining' => 0]);
                    $count++;
                }

                $this->syncBalance($locked);
            });
        }

        return $count;
    }

    protected function lock(Customer $customer): Customer
    {
        return Customer::query()->withoutGlobalScopes()->whereKey($customer->id)->lockForUpdate()->firstOrFail();
    }

    /** El saldo guardado es siempre la suma del libro, nunca un contador que se suma a mano. */
    protected function syncBalance(Customer $customer): void
    {
        $balance = (int) StoreCreditMovement::query()->withoutGlobalScopes()->where('customer_id', $customer->id)->sum('amount');

        Customer::query()->withoutGlobalScopes()->whereKey($customer->id)->update(['store_credit_balance' => $balance]);
    }
}
