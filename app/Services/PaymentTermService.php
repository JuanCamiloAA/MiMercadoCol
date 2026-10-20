<?php

namespace App\Services;

use App\Models\Company;
use App\Models\PaymentTerm;
use App\Realtime\ChangeCollector;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Los plazos de pago de una empresa.
 *
 * Solo uno por defecto de clientes y uno de proveedores por empresa. Lo hace cumplir este
 * servicio y no la base: un indice unico no sabria dejar los demas en falso.
 */
class PaymentTermService
{
    public const TYPE_CUSTOMERS = 'customers';

    public const TYPE_SUPPLIERS = 'suppliers';

    /** Nombre, dias y si es el defecto de clientes / proveedores. */
    public const DEFAULTS = [
        ['Contado', 0, true, false],
        ['8 días', 8, false, false],
        ['15 días', 15, false, false],
        ['30 días', 30, false, true],
    ];

    /**
     * Los cuatro plazos con que nace una empresa.
     */
    public function seedDefaults(Company $company): void
    {
        foreach (self::DEFAULTS as $index => [$name, $days, $customers, $suppliers]) {
            PaymentTerm::query()->withoutGlobalScopes()->create([
                'company_id' => $company->id,
                'name' => $name,
                'days' => $days,
                'is_default_customers' => $customers,
                'is_default_suppliers' => $suppliers,
                'is_active' => true,
                'sort_order' => $index,
            ]);
        }
    }

    /**
     * @param  array{name: string, days: int, is_default_customers?: bool, is_default_suppliers?: bool, sort_order?: int}  $data
     */
    public function create(int $companyId, array $data): PaymentTerm
    {
        return DB::transaction(function () use ($companyId, $data) {
            $term = PaymentTerm::query()->withoutGlobalScopes()->create([
                'company_id' => $companyId,
                'name' => $data['name'],
                'days' => (int) $data['days'],
                'is_default_customers' => false,
                'is_default_suppliers' => false,
                'is_active' => true,
                'sort_order' => (int) ($data['sort_order'] ?? (PaymentTerm::query()->withoutGlobalScopes()->where('company_id', $companyId)->max('sort_order') + 1)),
            ]);

            $this->applyDefaults($term, $data);

            return $term->refresh();
        });
    }

    /**
     * @param  array{name: string, days: int, is_default_customers?: bool, is_default_suppliers?: bool, sort_order?: int}  $data
     */
    public function update(PaymentTerm $term, array $data): PaymentTerm
    {
        return DB::transaction(function () use ($term, $data) {
            $term->update([
                'name' => $data['name'],
                'days' => (int) $data['days'],
                'sort_order' => (int) ($data['sort_order'] ?? $term->sort_order),
            ]);

            $this->applyDefaults($term, $data);

            // `credit_term_days` es copia de los dias del plazo: se mantiene al dia.
            DB::table('customers')->where('payment_term_id', $term->id)->update(['credit_term_days' => $term->days]);
            app(ChangeCollector::class)->record((int) $term->company_id, 'customers');

            return $term->refresh();
        });
    }

    /**
     * Activa o desactiva. El que esta por defecto no se desactiva: primero se elige otro.
     */
    public function setActive(PaymentTerm $term, bool $active): void
    {
        if (! $active && ($term->is_default_customers || $term->is_default_suppliers)) {
            throw ValidationException::withMessages([
                'term' => "«{$term->name}» es el plazo por defecto. Elige otro por defecto antes de desactivarlo.",
            ]);
        }

        $term->update(['is_active' => $active]);
    }

    /**
     * El plazo activo con esos dias. Con `$create`, si no existe se crea («45 días»): lo usan la
     * importacion de datos viejos y los seeders, donde el plazo llega como numero.
     */
    public function forDays(int $companyId, int $days, bool $create = false): ?PaymentTerm
    {
        $term = PaymentTerm::query()->withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->where('days', $days)
            ->orderBy('sort_order')
            ->first();

        if ($term || ! $create) {
            return $term;
        }

        return $this->create($companyId, ['name' => $days === 0 ? 'Contado' : "{$days} días", 'days' => $days]);
    }

    public function defaultFor(int $companyId, string $type): ?PaymentTerm
    {
        $column = $type === self::TYPE_SUPPLIERS ? 'is_default_suppliers' : 'is_default_customers';

        return PaymentTerm::query()->withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->where($column, true)
            ->first();
    }

    /**
     * Un plazo activo de la empresa, o null. Lo que venga de otra empresa no se acepta.
     */
    public function find(int $companyId, mixed $id): ?PaymentTerm
    {
        if ($id === null || $id === '') {
            return null;
        }

        return PaymentTerm::query()->withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->find((int) $id);
    }

    /**
     * Los plazos para un selector: los activos, y el que ya tenga asignado el registro aunque
     * se haya desactivado despues (si no, el formulario lo borraria sin que nadie lo pida).
     *
     * @return list<array{value: int, label: string, days: int}>
     */
    public function options(int $companyId, ?int $include = null): array
    {
        return PaymentTerm::query()->withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where(fn ($query) => $query->where('is_active', true)->when($include, fn ($q) => $q->orWhere('id', $include)))
            ->orderBy('sort_order')
            ->orderBy('days')
            ->get()
            ->map(fn (PaymentTerm $term) => [
                'value' => $term->id,
                'label' => $term->name,
                'days' => $term->days,
            ])
            ->values()
            ->all();
    }

    /**
     * Un plazo que sirva para asignar: activo, o el mismo que ya tenia el registro. Si no, un
     * error de validacion en el campo indicado.
     */
    public function resolve(int $companyId, mixed $id, ?int $current = null, string $field = 'payment_term_id'): ?PaymentTerm
    {
        if ($id === null || $id === '') {
            return null;
        }

        $term = PaymentTerm::query()->withoutGlobalScopes()->where('company_id', $companyId)->find((int) $id);

        if (! $term || (! $term->is_active && (int) $term->id !== (int) $current)) {
            throw ValidationException::withMessages([$field => 'Ese plazo no existe o está desactivado.']);
        }

        return $term;
    }

    /**
     * @param  array{is_default_customers?: bool, is_default_suppliers?: bool}  $data
     */
    protected function applyDefaults(PaymentTerm $term, array $data): void
    {
        foreach (['is_default_customers', 'is_default_suppliers'] as $column) {
            if (! ($data[$column] ?? false)) {
                continue;
            }

            PaymentTerm::query()->withoutGlobalScopes()
                ->where('company_id', $term->company_id)
                ->whereKeyNot($term->id)
                ->update([$column => false]);

            $term->update([$column => true, 'is_active' => true]);
        }
    }
}
