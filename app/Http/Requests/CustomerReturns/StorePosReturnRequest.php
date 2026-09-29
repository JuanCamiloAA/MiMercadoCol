<?php

namespace App\Http\Requests\CustomerReturns;

use App\Models\CustomerReturn;
use App\Models\CustomerReturnItem;
use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Devolucion en caja. Por articulo: cuanto, por que y a donde va. Para toda la devolucion: como
 * se le responde al cliente. Lo que se puede devolver lo decide `CustomerReturnService`.
 */
class StorePosReturnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('pos.sale.refund') ?? false;
    }

    public function rules(): array
    {
        $companyId = TenantContext::activeCompanyId();

        return [
            'items' => ['required', 'array', 'min:1'],
            'items.*.sale_item_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'numeric', 'min:0'],
            // El formulario de antes: en un articulo de servicio, si vuelve al estante.
            'items.*.restock' => ['nullable', 'boolean'],
            'items.*.reason' => ['nullable', Rule::in(array_keys(CustomerReturnItem::REASONS))],
            'items.*.disposition' => ['nullable', Rule::in(array_keys(CustomerReturnItem::DISPOSITION_LABELS))],
            'resolution' => ['nullable', Rule::in([CustomerReturn::RESOLUTION_REFUND, CustomerReturn::RESOLUTION_STORE_CREDIT, 'rejected'])],
            'method' => ['nullable', Rule::in(CustomerReturn::REFUND_METHODS)],
            'reason' => ['required', 'string', 'max:160'],
            'customer_id' => ['nullable', 'integer', Rule::exists('customers', 'id')->where(fn ($query) => $query->where('company_id', $companyId))],
        ];
    }

    public function messages(): array
    {
        return [
            'items.required' => 'Elige qué se está devolviendo.',
            'reason.required' => 'Escribe el motivo de la devolución.',
            'items.*.reason.in' => 'Elige el motivo de cada artículo.',
            'method.in' => 'La plata se devuelve en efectivo, tarjeta o transferencia.',
        ];
    }

    /**
     * @return array{items: list<array<string, mixed>>, resolution: string, refund_method: string, reason: string, customer_id: int|null}
     */
    public function returnData(): array
    {
        $data = $this->validated();

        return [
            'items' => array_values($data['items']),
            'resolution' => $data['resolution'] ?? CustomerReturn::RESOLUTION_REFUND,
            'refund_method' => $data['method'] ?? 'cash',
            'reason' => $data['reason'],
            'customer_id' => $data['customer_id'] ?? null,
        ];
    }
}
