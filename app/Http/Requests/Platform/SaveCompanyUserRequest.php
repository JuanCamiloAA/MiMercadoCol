<?php

namespace App\Http\Requests\Platform;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Crear o editar desde la plataforma un usuario de cualquier empresa. La empresa se elige al
 * crearlo y no se cambia despues: el rol, las sedes y el historial son de esa empresa.
 */
class SaveCompanyUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var User|null $user */
        $user = $this->route('user');

        // Un super usuario no se edita por aqui: 404 antes de validar, igual que las demas acciones.
        abort_if($user !== null && ($user->company_id === null || $user->isSuperAdmin()), 404);

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
     * La empresa de la cuenta: la del usuario al editar, la elegida al crear.
     */
    public function companyId(): ?int
    {
        /** @var User|null $user */
        $user = $this->route('user');

        if ($user) {
            return $user->company_id ? (int) $user->company_id : null;
        }

        return $this->integer('company_id') ?: null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var User|null $user */
        $user = $this->route('user');
        $companyId = $this->companyId();

        return [
            'company_id' => $user ? ['prohibited'] : ['required', 'integer', Rule::exists('companies', 'id')],
            'name' => ['required', 'string', 'max:60'],
            'last_name' => ['nullable', 'string', 'max:60'],
            // El indice unico de la base cuenta tambien las cuentas eliminadas.
            'email' => ['required', 'email', 'max:120', Rule::unique('users', 'email')->ignore($user?->id)],
            'document' => [
                'nullable', 'string', 'max:40',
                Rule::unique('users', 'document')->ignore($user?->id)->where('company_id', $companyId),
            ],
            'phone' => ['nullable', 'string', 'max:30'],
            'job_title' => ['nullable', 'string', 'max:60'],
            'role' => ['required', 'string', Rule::exists('roles', 'name')->where('company_id', $companyId)],
            'store_ids' => ['array'],
            'store_ids.*' => ['integer', 'distinct', Rule::exists('stores', 'id')->where('company_id', $companyId)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'company_id.required' => 'Elige la empresa.',
            'company_id.exists' => 'Esa empresa no existe.',
            'company_id.prohibited' => 'Un usuario no se cambia de empresa.',
            'name.required' => 'Escribe el nombre.',
            'email.required' => 'Escribe el correo.',
            'email.email' => 'Ese correo no es válido.',
            'email.unique' => 'Ya hay una cuenta con ese correo, en la plataforma o en alguna empresa.',
            'document.unique' => 'Ya hay alguien en esa empresa con ese documento.',
            'role.required' => 'Elige un rol.',
            'role.exists' => 'Ese rol no es de esta empresa.',
            'store_ids.*.exists' => 'Una de las sedes no es de esta empresa.',
        ];
    }
}
