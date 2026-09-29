<?php

namespace App\Http\Requests;

use App\Models\Store;
use App\Models\Warehouse;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Crear o editar una bodega de una sede.
 *
 * Las banderas se validan pero no se aplican aqui: encender una implica apagarla en las demas
 * de la sede, y eso pasa dentro de una transaccion en `WarehouseService::assignFlags`.
 */
class StoreWarehouseRequest extends FormRequest
{
    public function authorize(): bool
    {
        $action = $this->route('warehouse') ? 'edit' : 'create';

        return $this->user()?->can("stores.warehouses.{$action}") ?? false;
    }

    public function rules(): array
    {
        /** @var Store $store */
        $store = $this->route('store');
        /** @var Warehouse|null $warehouse */
        $warehouse = $this->route('warehouse');

        return [
            'name' => ['required', 'string', 'max:60'],
            'code' => [
                'required', 'string', 'max:20',
                Rule::unique('warehouses', 'code')
                    ->ignore($warehouse?->id)
                    ->where(fn ($query) => $query->where('store_id', $store->id)),
            ],
            'type' => ['required', Rule::in(array_keys(Warehouse::TYPE_LABELS))],
            'flags' => ['array'],
            'flags.*' => [Rule::in(array_keys(Warehouse::FLAG_LABELS))],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Ponle un nombre a la bodega.',
            'code.required' => 'El codigo es obligatorio.',
            'code.unique' => 'Ya hay una bodega con ese codigo en esta sede.',
            'type.required' => 'Elige de que tipo es.',
        ];
    }

    /**
     * @return list<string>
     */
    public function flags(): array
    {
        $flags = $this->input('flags');

        return is_array($flags) ? array_values(array_intersect($flags, array_keys(Warehouse::FLAG_LABELS))) : [];
    }
}
