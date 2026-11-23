<?php

namespace App\Http\Requests\Platform;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Crear o editar un super usuario. No lleva empresa, rol ni sedes: entra a todas.
 */
class SavePlatformUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var User|null $user */
        $user = $this->route('user');

        // Un usuario de empresa no se edita por aqui: 404 antes de validar, igual que las demas acciones.
        abort_if($user !== null && ! $user->isSuperAdmin(), 404);

        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => trim((string) $this->input('name')),
            'last_name' => trim((string) $this->input('last_name')) ?: null,
            'email' => mb_strtolower(trim((string) $this->input('email'))),
            'document' => trim((string) $this->input('document')) ?: null,
            'phone' => trim((string) $this->input('phone')) ?: null,
            'job_title' => trim((string) $this->input('job_title')) ?: null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var User|null $user */
        $user = $this->route('user');

        return [
            'name' => ['required', 'string', 'max:60'],
            'last_name' => ['nullable', 'string', 'max:60'],
            // Sin `whereNull('deleted_at')`: el indice unico de la base tambien cuenta las cuentas
            // eliminadas, y dejarlo pasar terminaria en un error de SQL en vez de un mensaje.
            'email' => ['required', 'email', 'max:120', Rule::unique('users', 'email')->ignore($user?->id)],
            // El indice `company_id, document` no frena repetidos con empresa nula: se mide aqui.
            'document' => [
                'nullable', 'string', 'max:40',
                Rule::unique('users', 'document')->ignore($user?->id)->whereNull('company_id')->whereNull('deleted_at'),
            ],
            'phone' => ['nullable', 'string', 'max:30'],
            'job_title' => ['nullable', 'string', 'max:60'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Escribe el nombre.',
            'email.required' => 'Escribe el correo.',
            'email.email' => 'Ese correo no es válido.',
            'email.unique' => 'Ya hay una cuenta con ese correo, en la plataforma o en alguna empresa.',
            'document.unique' => 'Ya hay un super usuario con ese documento.',
        ];
    }
}
