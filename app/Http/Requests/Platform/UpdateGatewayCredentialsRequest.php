<?php

namespace App\Http\Requests\Platform;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Las llaves de un entorno. Un campo secreto vacio conserva el que ya estaba: la pantalla nunca
 * recibe el valor, asi que no puede devolverlo.
 */
class UpdateGatewayCredentialsRequest extends FormRequest
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
            'public_key' => ['nullable', 'string', 'max:191', 'regex:/^pub_(test|prod)_[A-Za-z0-9]+$/'],
            'private_key' => ['nullable', 'string', 'max:191', 'regex:/^prv_(test|prod)_[A-Za-z0-9]+$/'],
            'events_secret' => ['nullable', 'string', 'max:191', 'regex:/^(test|prod)_events_[A-Za-z0-9]+$/'],
            'integrity_secret' => ['nullable', 'string', 'max:191', 'regex:/^(test|prod)_integrity_[A-Za-z0-9]+$/'],
        ];
    }

    /**
     * Cada llave tiene que ser del entorno de ESTE formulario: una de produccion en el bloque de
     * pruebas cobraria de verdad creyendo que se prueba.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $tag = (string) $this->route('environment') === 'production' ? 'prod' : 'test';
                $fields = [
                    'public_key' => ['llave pública', "pub_{$tag}_"],
                    'private_key' => ['llave privada', "prv_{$tag}_"],
                    'events_secret' => ['secreto de eventos', "{$tag}_events_"],
                    'integrity_secret' => ['secreto de integridad', "{$tag}_integrity_"],
                ];

                foreach ($fields as $field => [$label, $prefix]) {
                    $value = trim((string) $this->input($field));

                    if ($value !== '' && ! str_starts_with($value, $prefix) && ! $validator->errors()->has($field)) {
                        $validator->errors()->add($field, "El valor de {$label} de este entorno debe empezar por «{$prefix}».");
                    }
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'public_key.regex' => 'La llave pública no tiene el formato de Wompi (pub_test_… o pub_prod_…).',
            'private_key.regex' => 'La llave privada no tiene el formato de Wompi (prv_test_… o prv_prod_…).',
            'events_secret.regex' => 'El secreto de eventos no tiene el formato de Wompi (test_events_… o prod_events_…).',
            'integrity_secret.regex' => 'El secreto de integridad no tiene el formato de Wompi (test_integrity_… o prod_integrity_…).',
        ];
    }
}
