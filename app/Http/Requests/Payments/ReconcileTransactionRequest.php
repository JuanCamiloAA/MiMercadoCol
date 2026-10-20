<?php

namespace App\Http\Requests\Payments;

use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Resolver un pago por conciliar: aplicarlo a una venta o a un pedido, abonarlo como saldo a
 * favor o registrar que se reembolso. Siempre con una nota.
 */
class ReconcileTransactionRequest extends FormRequest
{
    public const ACTIONS = ['apply_sale', 'apply_order', 'store_credit', 'refunded_outside', 'refund_gateway'];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $companyId = TenantContext::activeCompanyId();

        return [
            'action' => ['required', Rule::in(self::ACTIONS)],
            'register_session_id' => ['required_if:action,apply_sale', 'nullable', 'integer', Rule::exists('register_sessions', 'id')->where(fn ($query) => $query->where('company_id', $companyId)->where('status', 'open'))],
            'order_id' => ['required_if:action,apply_order', 'nullable', 'integer', Rule::exists('orders', 'id')->where(fn ($query) => $query->where('company_id', $companyId))],
            'customer_document' => ['required_if:action,store_credit', 'nullable', 'string', 'max:30'],
            'note' => ['required', 'string', 'min:5', 'max:200'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'action.in' => 'Elige qué hacer con el pago.',
            'register_session_id.required_if' => 'Elige la caja abierta donde queda la venta.',
            'register_session_id.exists' => 'Esa caja ya no está abierta.',
            'order_id.required_if' => 'Elige el pedido que se paga con esto.',
            'customer_document.required_if' => 'Escribe el documento del cliente.',
            'note.required' => 'Deja una nota de lo que pasó.',
            'note.min' => 'Explica lo que pasó en al menos cinco letras.',
        ];
    }
}
