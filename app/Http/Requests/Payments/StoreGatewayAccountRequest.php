<?php

namespace App\Http\Requests\Payments;

use App\Models\PaymentGatewayAccount;
use App\Payments\Gateways\GatewayRegistry;
use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Conectar una cuenta de pasarela de la empresa. Las llaves que pide salen del conector
 * (`fields()`): la pantalla y esta validacion se arman con lo mismo.
 */
class StoreGatewayAccountRequest extends FormRequest
{
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
            'provider' => ['required', Rule::in(app(GatewayRegistry::class)->enabled())],
            'environment' => [
                'required', Rule::in(PaymentGatewayAccount::ENVIRONMENTS),
                Rule::unique('payment_gateway_accounts', 'environment')->where(fn ($query) => $query->where('company_id', $companyId)->where('provider', $this->input('provider'))),
            ],
            'label' => ['nullable', 'string', 'max:80'],
            'fields' => ['nullable', 'array'],
            'fields.*' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $registry = app(GatewayRegistry::class);

            if (! $registry->exists((string) $this->input('provider'))) {
                return;
            }

            foreach ($registry->for((string) $this->input('provider'))->fields() as $field) {
                if ($field['required'] && trim((string) ($this->input('fields.'.$field['key']) ?? '')) === '') {
                    $validator->errors()->add('fields.'.$field['key'], "Falta «{$field['label']}».");
                }
            }
        });
    }

    /**
     * Las llaves partidas en publicas y secretas, segun las declara el conector. Lo que no
     * declara no se guarda.
     *
     * @return array{public: array<string, string>, secret: array<string, string>}
     */
    public function gatewayKeys(): array
    {
        $fields = app(GatewayRegistry::class)->for((string) $this->validated('provider'))->fields();
        $input = (array) ($this->validated('fields') ?? []);
        $public = [];
        $secret = [];

        foreach ($fields as $field) {
            $value = trim((string) ($input[$field['key']] ?? ''));

            if ($value === '') {
                continue;
            }

            if ($field['secret']) {
                $secret[$field['key']] = $value;
            } else {
                $public[$field['key']] = $value;
            }
        }

        return ['public' => $public, 'secret' => $secret];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'provider.in' => 'Esa pasarela no está disponible.',
            'environment.unique' => 'Ya hay una cuenta de esa pasarela en ese entorno.',
        ];
    }
}
