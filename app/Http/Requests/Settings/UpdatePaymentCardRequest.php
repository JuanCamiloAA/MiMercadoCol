<?php

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Guardar la tarjeta de la membresia.
 *
 * Solo llega el token de un solo uso que el navegador saco de la pasarela y los tokens de
 * aceptacion. Si la peticion trae el numero o el CVC se rechaza entera: este servidor no debe
 * recibirlos nunca, ni siquiera para descartarlos.
 */
class UpdatePaymentCardRequest extends FormRequest
{
    /** Campos que delatan datos de tarjeta en claro. */
    public const FORBIDDEN = ['card_number', 'number', 'cvc', 'cvv', 'card', 'pan'];

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
            'token' => ['required', 'string', 'max:120', 'regex:/^tok_[A-Za-z0-9_]+$/'],
            'acceptance_token' => ['required', 'string', 'max:2000'],
            'personal_auth_token' => ['required', 'string', 'max:2000'],
            'accepted' => ['accepted'],
            'email' => ['required', 'email', 'max:160'],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                foreach (self::FORBIDDEN as $field) {
                    if ($this->exists($field)) {
                        $validator->errors()->add('token', 'Los datos de la tarjeta no se envían a este servidor. Recarga la página e intenta de nuevo.');

                        return;
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
            'token.required' => 'No llegó la tarjeta tokenizada. Intenta de nuevo.',
            'token.regex' => 'La tarjeta tokenizada no es válida.',
            'acceptance_token.required' => 'Faltan los términos de la pasarela. Recarga e intenta de nuevo.',
            'personal_auth_token.required' => 'Falta la autorización de datos de la pasarela.',
            'accepted.accepted' => 'Acepta los términos y la autorización de datos para guardar la tarjeta.',
            'email.required' => 'Escribe el correo del titular.',
            'email.email' => 'El correo del titular no es válido.',
        ];
    }
}
