<?php

namespace App\Http\Requests\SupplierReturns;

use App\Models\SupplierReturnSettlement;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Nota credito o reembolso. Que no pase de lo pendiente lo decide el servicio, con la fila
 * bloqueada: dos personas registrando a la vez no pueden cobrar dos veces lo mismo.
 */
class StoreSupplierReturnSettlementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('purchases.returns.respond') ?? false;
    }

    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in([SupplierReturnSettlement::TYPE_CREDIT_NOTE, SupplierReturnSettlement::TYPE_REFUND])],
            'document_number' => ['nullable', 'required_if:type,'.SupplierReturnSettlement::TYPE_CREDIT_NOTE, 'string', 'max:40'],
            'amount' => ['required', 'integer', 'min:1'],
            'received_at' => ['required', 'date', 'before_or_equal:today'],
            'payment_method' => ['nullable', 'required_if:type,'.SupplierReturnSettlement::TYPE_REFUND, Rule::in(array_keys(SupplierReturnSettlement::METHOD_LABELS))],
            'note' => ['nullable', 'string', 'max:160'],
        ];
    }

    public function messages(): array
    {
        return [
            'type.required' => 'Elige si es nota crédito o reembolso.',
            'document_number.required_if' => 'Escribe el número de la nota crédito.',
            'amount.required' => 'Escribe el valor.',
            'amount.min' => 'El valor debe ser mayor que cero.',
            'received_at.required' => 'Indica la fecha del documento o del pago.',
            'received_at.before_or_equal' => 'La fecha no puede ser futura.',
            'payment_method.required_if' => 'Elige cómo llegó el reembolso.',
        ];
    }
}
