<?php

namespace App\Http\Requests\Storefront;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Suscribirse a las novedades desde el pie de la tienda. La autorizacion de datos es obligatoria
 * y `website` es una trampa para robots: una persona no la ve ni la llena.
 */
class StoreNewsletterSubscriptionRequest extends FormRequest
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
            'email' => ['required', 'string', 'email:rfc', 'max:160'],
            'consent' => ['accepted'],
            'website' => ['nullable', 'max:0'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.required' => 'Escribe tu correo.',
            'email.email' => 'Ese correo no parece válido.',
            'consent.accepted' => 'Necesitamos tu autorización para guardar el correo.',
            'website.max' => 'No pudimos guardar tu correo.',
        ];
    }

    /** El correo como se guarda: sin espacios y en minúsculas, para no repetirlo. */
    public function email(): string
    {
        return mb_strtolower(trim((string) $this->validated('email')));
    }
}
