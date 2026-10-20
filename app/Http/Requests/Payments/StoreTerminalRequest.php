<?php

namespace App\Http\Requests\Payments;

use App\Models\PaymentGatewayAccount;
use App\Models\Register;
use App\Payments\Gateways\GatewayRegistry;
use App\Payments\Gateways\PaymentGateway;
use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Registrar un datafono integrado: de que cuenta de pasarela es, en que sede y (si se quiere) en
 * que caja. Solo de una pasarela que de verdad tenga datafono integrado.
 */
class StoreTerminalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->input('register_id') === '') {
            $this->merge(['register_id' => null]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $companyId = TenantContext::activeCompanyId();

        return [
            'name' => ['required', 'string', 'max:80'],
            'gateway_account_id' => ['required', 'integer', Rule::exists('payment_gateway_accounts', 'id')->where(fn ($query) => $query->where('company_id', $companyId))],
            'store_id' => ['required', 'integer', Rule::exists('stores', 'id')->where(fn ($query) => $query->where('company_id', $companyId))],
            'register_id' => ['nullable', 'integer', Rule::exists('registers', 'id')->where(fn ($query) => $query->where('company_id', $companyId))],
            'device_id' => ['required', 'string', 'max:120'],
            'model' => ['nullable', 'string', 'max:60'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $account = PaymentGatewayAccount::query()->find($this->input('gateway_account_id'));
            $registry = app(GatewayRegistry::class);

            if ($account && (! $registry->exists($account->provider) || ! in_array(PaymentGateway::CAPABILITY_TERMINAL, $registry->for($account->provider)->capabilities(), true))) {
                $validator->errors()->add('gateway_account_id', 'Esa pasarela no tiene datáfono integrado.');
            }

            if ($this->input('register_id')) {
                $register = Register::query()->find($this->input('register_id'));

                if ($register && (int) $register->store_id !== (int) $this->input('store_id')) {
                    $validator->errors()->add('register_id', 'Esa caja es de otra sede.');
                }
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Ponle un nombre al datáfono («Datáfono caja 1»).',
            'device_id.required' => 'Escribe el serial del datáfono, como aparece en la pasarela.',
        ];
    }
}
