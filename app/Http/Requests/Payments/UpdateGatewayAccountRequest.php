<?php

namespace App\Http\Requests\Payments;

use App\Models\PaymentGatewayAccount;
use App\Payments\Gateways\GatewayRegistry;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Cambiar las llaves de una cuenta. Las secretas nunca se leen: se reemplazan. Una secreta vacia
 * quiere decir «la dejo como esta».
 */
class UpdateGatewayAccountRequest extends FormRequest
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
        return [
            'label' => ['nullable', 'string', 'max:80'],
            'fields' => ['nullable', 'array'],
            'fields.*' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * Lo publico se reemplaza entero; lo secreto, solo lo que llego escrito.
     *
     * @return array{public: array<string, string>, secret: array<string, string>}
     */
    public function gatewayKeys(PaymentGatewayAccount $account): array
    {
        $fields = app(GatewayRegistry::class)->for($account->provider)->fields();
        $input = (array) ($this->validated('fields') ?? []);
        $public = [];
        $secret = (array) ($account->secret_fields ?? []);

        foreach ($fields as $field) {
            $value = trim((string) ($input[$field['key']] ?? ''));

            if ($field['secret']) {
                if ($value !== '') {
                    $secret[$field['key']] = $value;
                }

                continue;
            }

            if ($value !== '') {
                $public[$field['key']] = $value;
            }
        }

        return ['public' => $public, 'secret' => array_intersect_key($secret, array_flip(array_column(array_filter($fields, fn ($field) => $field['secret']), 'key')))];
    }
}
