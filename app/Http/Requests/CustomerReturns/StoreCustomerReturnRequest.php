<?php

namespace App\Http\Requests\CustomerReturns;

use App\Models\CustomerReturnItem;
use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Registrar una devolucion desde el panel: aprobada (por recibir) o ya recibida, con el destino
 * de cada articulo.
 */
class StoreCustomerReturnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('sales.returns.create') ?? false;
    }

    public function rules(): array
    {
        $companyId = TenantContext::activeCompanyId();

        return [
            'sale_id' => ['required', 'integer', Rule::exists('sales', 'id')->where(fn ($query) => $query->where('company_id', $companyId))],
            'received' => ['boolean'],
            'reason' => ['nullable', 'string', 'max:160'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.sale_item_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'numeric', 'min:0'],
            'items.*.reason' => ['required', Rule::in(array_keys(CustomerReturnItem::REASONS))],
            'items.*.disposition' => ['nullable', Rule::in(array_keys(CustomerReturnItem::DISPOSITION_LABELS))],
        ];
    }

    public function messages(): array
    {
        return [
            'items.required' => 'Elige qué se está devolviendo.',
            'items.*.reason.required' => 'Elige el motivo de cada artículo.',
            'items.*.reason.in' => 'Elige el motivo de cada artículo.',
        ];
    }

    /**
     * @return array{items: list<array<string, mixed>>, received: bool, reason: string|null}
     */
    public function returnData(): array
    {
        $data = $this->validated();

        return [
            'items' => array_values($data['items']),
            'received' => $this->boolean('received'),
            'reason' => $data['reason'] ?? null,
        ];
    }
}
