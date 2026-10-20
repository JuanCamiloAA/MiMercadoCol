<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\StoreCreditMovement;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * El cliente como lo usa la caja: datos de contacto, situacion de credito y saldo a favor.
 *
 * Lo arma un solo sitio para que el buscador de clientes y el tiquete suspendido que se retoma
 * entreguen lo mismo, y siempre calculado en el momento: el cupo y el saldo de la hora en que se
 * suspendio ya pueden no ser los de ahora.
 */
class PosCustomerPresenter
{
    public function __construct(protected CreditService $credit) {}

    /**
     * @param  Collection<int, Customer>  $customers
     * @return Collection<int, array<string, mixed>> por id de cliente
     */
    public function present(Collection $customers): Collection
    {
        if ($customers->isEmpty()) {
            return collect();
        }

        // Puede llegar de un `pluck` (una coleccion simple): se vuelve de Eloquent para cargar en bloque.
        $customers = (new EloquentCollection($customers->all()))->loadMissing('balance');

        // El saldo a favor vigente de todos en una sola consulta: lo vencido no cuenta.
        $storeCredit = StoreCreditMovement::query()
            ->whereIn('customer_id', $customers->pluck('id'))
            ->where('remaining', '>', 0)
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->groupBy('customer_id')
            ->selectRaw('customer_id, SUM(remaining) as available')
            ->pluck('available', 'customer_id');

        return $customers->mapWithKeys(fn (Customer $customer) => [$customer->id => [
            'id' => $customer->id,
            'name' => $customer->name,
            'document_number' => $customer->document_number,
            'phone' => $customer->phone,
            'credit_enabled' => (bool) $customer->credit_enabled,
            'credit_blocked' => (bool) $customer->credit_blocked,
            'blocked_reason' => $customer->blocked_reason,
            'credit_limit' => $customer->credit_limit,
            'credit_term_days' => $customer->credit_term_days,
            'balance' => $customer->currentBalance(),
            'available_credit' => $this->credit->availableCredit($customer),
            'store_credit' => (int) ($storeCredit[$customer->id] ?? 0),
        ]]);
    }
}
