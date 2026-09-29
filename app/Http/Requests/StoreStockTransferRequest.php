<?php

namespace App\Http\Requests;

use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Armar un traslado entre dos bodegas.
 *
 * No se valida la existencia aqui: un traslado por despachar no prejuzga, y entre armarlo y
 * despacharlo puede entrar mercancia. Quien valida es `StockTransferService::dispatch`,
 * que es el momento en que la mercancia de verdad sale del estante.
 */
class StoreStockTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('inventory.transfers.create') ?? false;
    }

    public function rules(): array
    {
        $companyId = TenantContext::activeCompanyId();
        $ofCompany = fn ($query) => $query->where('company_id', $companyId)->where('is_active', true);

        return [
            'from_warehouse_id' => ['required', 'integer', 'different:to_warehouse_id', Rule::exists('warehouses', 'id')->where($ofCompany)],
            'to_warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')->where($ofCompany)],
            'note' => ['nullable', 'string', 'max:160'],
            'dispatch' => ['boolean'],
            'direct' => ['boolean'],

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
            'from_warehouse_id.required' => 'Elige la bodega de origen.',
            'from_warehouse_id.different' => 'La bodega de destino debe ser distinta a la de origen.',
            'to_warehouse_id.required' => 'Elige la bodega de destino.',
            'items.required' => 'Agrega al menos un articulo.',
            'items.min' => 'Agrega al menos un articulo.',
            'items.*.product_id.distinct' => 'Un articulo no puede ir dos veces: suma las cantidades en un solo renglon.',
            'items.*.quantity.gt' => 'Las cantidades deben ser mayores que cero.',
        ];
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
