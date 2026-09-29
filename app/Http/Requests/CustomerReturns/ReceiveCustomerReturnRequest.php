<?php

namespace App\Http\Requests\CustomerReturns;

use App\Models\CustomerReturnItem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Recibir la mercancia: por renglon, cuanto llego y a donde va. Recibir una solicitud que nadie
 * ha aprobado tambien es aprobarla: pide ese permiso.
 */
class ReceiveCustomerReturnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('sales.returns.receive') ?? false;
    }

    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'numeric', 'min:0'],
            'items.*.disposition' => ['nullable', Rule::in(array_keys(CustomerReturnItem::DISPOSITION_LABELS))],
        ];
    }

    /**
     * @return list<array{id: int, quantity: float, disposition: string|null}>
     */
    public function lines(): array
    {
        return array_values($this->validated('items'));
    }
}
