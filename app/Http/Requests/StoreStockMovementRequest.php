<?php

namespace App\Http\Requests;

use App\Models\StockMovement;
use App\Support\StoreContext;
use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Una entrada o una salida de bodega con varios articulos.
 *
 * El motivo se valida contra los de su direccion y no contra todos: «vencido» no es una
 * entrada, y aceptarlo dejaria una entrada registrada en el kardex como averia.
 */
class StoreStockMovementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('inventory.index.adjust') ?? false;
    }

    public function rules(): array
    {
        $companyId = TenantContext::activeCompanyId();
        $reasons = array_keys(StockMovement::REASONS[$this->direction()] ?? []);

        return [
            'direction' => ['required', Rule::in([StockMovement::DIRECTION_IN, StockMovement::DIRECTION_OUT])],
            'warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')->where(fn ($query) => $query->where('store_id', StoreContext::activeStoreId()))],
            'reason' => ['required', 'string', Rule::in($reasons)],
            'note' => ['nullable', 'string', 'max:160'],

            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => [
                'required', 'integer', 'distinct',
                Rule::exists('products', 'id')->where(fn ($query) => $query->where('company_id', $companyId)),
            ],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'warehouse_id.required' => 'Elige la bodega.',
            'warehouse_id.exists' => 'Esa bodega no es de esta sede.',
            'reason.required' => 'Elige el motivo del movimiento.',
            'reason.in' => 'Ese motivo no corresponde a este movimiento.',
            'items.required' => 'Agrega al menos un articulo.',
            'items.min' => 'Agrega al menos un articulo.',
            'items.*.product_id.distinct' => 'Un articulo no puede ir dos veces: suma las cantidades en un solo renglon.',
            'items.*.product_id.exists' => 'Uno de los articulos no es de tu empresa.',
            'items.*.quantity.gt' => 'Las cantidades deben ser mayores que cero.',
            'items.*.quantity.required' => 'Indica la cantidad de cada articulo.',
        ];
    }

    public function direction(): string
    {
        return $this->input('direction') === StockMovement::DIRECTION_OUT
            ? StockMovement::DIRECTION_OUT
            : StockMovement::DIRECTION_IN;
    }

    /**
     * @return list<array{product_id: int, quantity: float}>
     */
    public function items(): array
    {
        return array_values(array_map(
            fn (array $item) => [
                'product_id' => (int) $item['product_id'],
                'quantity' => (float) $item['quantity'],
            ],
            $this->validated('items'),
        ));
    }
}
