<?php

namespace App\Services;

use App\Models\Store;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Las bodegas de una sede y la regla de que solo una lleva cada bandera.
 *
 * Esa regla no se le confia a la base: un indice unico parcial no existe en MariaDB y uno
 * completo impediria tener dos bodegas apagadas. Se hace aqui, dentro de una transaccion que
 * bloquea las bodegas de la sede, apagando la bandera en las demas al encenderla en una.
 *
 * Los resolutores son explicitos a proposito. Ningun camino debe recibir una sede y adivinar
 * la bodega: quien mueve mercancia dice de cual, y si es la de caja lo dice pidiendo
 * `posSourceFor`.
 */
class WarehouseService
{
    /** La bodega que descuenta las ventas de caja. */
    public function posSourceFor(int $storeId): Warehouse
    {
        return $this->flagged($storeId, Warehouse::FLAG_POS);
    }

    /** La bodega que respalda la tienda en linea. */
    public function onlineSourceFor(int $storeId): Warehouse
    {
        return $this->flagged($storeId, Warehouse::FLAG_ONLINE);
    }

    /** Donde entra una compra si nadie dice otra cosa. */
    public function receivingDefaultFor(int $storeId): Warehouse
    {
        return $this->flagged($storeId, Warehouse::FLAG_RECEIVING);
    }

    /**
     * Las bodegas de una sede.
     *
     * @return Collection<int, Warehouse>
     */
    public function forStore(int $storeId, bool $onlyActive = true): Collection
    {
        return Warehouse::query()
            ->withoutGlobalScopes()
            ->where('store_id', $storeId)
            ->when($onlyActive, fn ($query) => $query->where('is_active', true))
            ->orderByDesc('is_pos_source')
            ->orderBy('name')
            ->get();
    }

    /**
     * Todas las bodegas de la empresa, con su sede, para los paneles que comparan.
     *
     * @return Collection<int, Warehouse>
     */
    public function forCompany(int $companyId, bool $onlyActive = true): Collection
    {
        // Se ordena en PHP y no con un join: `warehouses` y `stores` comparten `company_id` e
        // `is_active`, y unirlas deja esas condiciones ambiguas para MariaDB.
        return Warehouse::query()
            ->withoutGlobalScopes()
            ->where('warehouses.company_id', $companyId)
            ->when($onlyActive, fn ($query) => $query->where('warehouses.is_active', true))
            ->with('store:id,name')
            ->get()
            ->sortBy([
                fn (Warehouse $a, Warehouse $b) => strcmp((string) $a->store?->name, (string) $b->store?->name),
                fn (Warehouse $a, Warehouse $b) => ($b->is_pos_source <=> $a->is_pos_source),
                fn (Warehouse $a, Warehouse $b) => strcmp($a->name, $b->name),
            ])
            ->values();
    }

    /**
     * La bodega principal de una sede recien creada.
     *
     * La crea el modelo `Store` al nacer, para que ninguna sede exista sin donde guardar: una
     * sede sin bodega rompe la caja en el primer cobro.
     */
    public function createDefaultFor(Store $store): Warehouse
    {
        return Warehouse::query()->withoutGlobalScopes()->create([
            'company_id' => $store->company_id,
            'store_id' => $store->id,
            'name' => 'Bodega principal',
            'code' => 'PRINCIPAL',
            'type' => Warehouse::TYPE_SALES_FLOOR,
            'is_pos_source' => true,
            'is_online_source' => true,
            'is_receiving_default' => true,
            'is_active' => true,
        ]);
    }

    /**
     * Enciende una bandera en esta bodega y la apaga en las demas de su sede.
     *
     * @param  list<string>  $flags
     */
    public function assignFlags(Warehouse $warehouse, array $flags): void
    {
        $known = array_keys(Warehouse::FLAG_LABELS);
        $flags = array_values(array_intersect($flags, $known));

        DB::transaction(function () use ($warehouse, $flags, $known) {
            $siblings = Warehouse::query()
                ->withoutGlobalScopes()
                ->where('store_id', $warehouse->store_id)
                ->lockForUpdate()
                ->get();

            foreach ($known as $flag) {
                if (! in_array($flag, $flags, true)) {
                    continue;
                }

                foreach ($siblings as $sibling) {
                    if ($sibling->id !== $warehouse->id && $sibling->{$flag}) {
                        $sibling->{$flag} = false;
                        $sibling->save();
                    }
                }
            }

            foreach ($known as $flag) {
                $warehouse->{$flag} = in_array($flag, $flags, true);
            }

            $warehouse->save();
        });
    }

    /**
     * Apaga una bodega.
     *
     * Una bodega con bandera no se apaga: dejaria la sede sin de donde vender o donde recibir.
     * Primero se le pasa la bandera a otra.
     */
    public function deactivate(Warehouse $warehouse): void
    {
        if ($warehouse->hasAnyFlag()) {
            $labels = array_map(fn (string $flag) => Warehouse::FLAG_LABELS[$flag], $warehouse->flags());

            throw ValidationException::withMessages([
                'is_active' => ['Esta bodega todavia '.implode(' y ', $labels).'. Pasale esa funcion a otra antes de desactivarla.'],
            ]);
        }

        $warehouse->is_active = false;
        $warehouse->save();
    }

    /**
     * La bodega con la bandera, o la unica activa si nadie la lleva.
     *
     * El respaldo existe por bases que vengan de antes o de una migracion a medias; no es un
     * camino normal. Si la sede no tiene ninguna bodega, es un error de datos y truena aqui,
     * que es donde se puede explicar, y no tres capas abajo con un saldo en cero.
     */
    protected function flagged(int $storeId, string $flag): Warehouse
    {
        $warehouses = $this->forStore($storeId);

        $chosen = $warehouses->firstWhere($flag, true) ?? $warehouses->first();

        if (! $chosen) {
            throw new RuntimeException("La sede {$storeId} no tiene ninguna bodega activa.");
        }

        return $chosen;
    }
}
