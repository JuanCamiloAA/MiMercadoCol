<?php

namespace App\Http\Requests;

use App\Models\PaymentMethod;
use App\Support\TenantContext;
use Illuminate\Validation\Rule;

/**
 * Abrir un cobro con QR, link o datafono en la caja: el tiquete completo (como un cobro normal),
 * el renglon de la pasarela y, para no perderlo si se recarga, el tiquete como suspendido.
 */
class StartPosGatewayPaymentRequest extends StorePosSaleRequest
{
    public function authorize(): bool
    {
        return parent::authorize() && $this->user()->can('pos.sale.gateway');
    }

    public function rules(): array
    {
        $companyId = TenantContext::activeCompanyId();
        $rules = parent::rules();

        // Puede no haber otros medios: el QR paga todo.
        $rules['payments'] = ['present', 'array', 'max:9'];

        return [
            ...$rules,
            'gateway' => ['required', 'array'],
            'gateway.payment_method_id' => [
                'required', 'integer',
                Rule::exists('payment_methods', 'id')->where(fn ($query) => $query->where('company_id', $companyId)->whereIn('kind', [PaymentMethod::KIND_GATEWAY_LINK, PaymentMethod::KIND_GATEWAY_TERMINAL])),
            ],
            'gateway.amount' => ['required', 'integer', 'min:1'],
            'gateway.mode' => ['nullable', Rule::in(['qr', 'link', 'terminal'])],
            'gateway.terminal_id' => ['nullable', 'integer', Rule::exists('payment_terminals', 'id')->where(fn ($query) => $query->where('company_id', $companyId))],
            'held' => ['nullable', 'array'],
            'held.label' => ['required_with:held', 'string', 'max:40'],
            'held.total' => ['required_with:held', 'integer', 'min:0'],
            'held.customer_id' => ['nullable', 'integer', Rule::exists('customers', 'id')->where(fn ($query) => $query->where('company_id', $companyId))],
            'held.payload' => ['required_with:held', 'array'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function saleData(): array
    {
        $data = parent::saleData();
        unset($data['gateway'], $data['held']);

        return $data;
    }

    public function messages(): array
    {
        return [
            ...parent::messages(),
            'gateway.payment_method_id.exists' => 'Ese medio no cobra con QR, link ni datáfono.',
            'gateway.amount.min' => 'Asigna el monto que se cobra por la pasarela.',
        ];
    }
}
