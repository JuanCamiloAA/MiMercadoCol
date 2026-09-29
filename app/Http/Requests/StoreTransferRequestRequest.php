<?php

namespace App\Http\Requests;

use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Pedirle mercancia a otra bodega.
 *
 * No se mira la existencia del origen: se pide lo que hace falta, y quien surte decide cuanto
 * puede mandar al generar el traslado.
 */
class StoreTransferRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('inventory.transfer_requests.create') ?? false;
    }

    public function rules(): array
    {
        $companyId = TenantContext::activeCompanyId();
        $ofCompany = fn ($query) => $query->where('company_id', $companyId)->where('is_active', true);

        return [
            'to_warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')->where($ofCompany)],
            'from_warehouse_id' => ['required', 'integer', 'different:to_warehouse_id', Rule::exists('warehouses', 'id')->where($ofCompany)],
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
            'to_warehouse_id.required' => 'Elige la bodega que pide.',
            'from_warehouse_id.required' => 'Elige a que bodega se le pide.',
            'from_warehouse_id.different' => 'Se le pide a otra bodega, no a la misma que pide.',
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
