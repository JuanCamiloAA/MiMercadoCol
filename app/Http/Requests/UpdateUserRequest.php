<?php

namespace App\Http\Requests;

use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('users.index.edit') ?? false;
    }

    public function rules(): array
    {
        $companyId = TenantContext::activeCompanyId();
        $userId = $this->route('user')->id;

        return [
            'name' => ['required', 'string', 'max:60'],
            'last_name' => ['nullable', 'string', 'max:60'],
            'email' => ['required', 'email', 'max:120', Rule::unique('users', 'email')->ignore($userId)->whereNull('deleted_at')],
            'document' => [
                'nullable', 'string', 'max:40',
                Rule::unique('users', 'document')->ignore($userId)->where(fn ($query) => $query->where('company_id', $companyId))->whereNull('deleted_at'),
            ],
            'phone' => ['nullable', 'string', 'max:30'],
            'job_title' => ['nullable', 'string', 'max:60'],
            'role' => ['required', 'string', Rule::exists('roles', 'name')->where(fn ($query) => $query->where('company_id', $companyId))],
            'store_ids' => ['array'],
            'store_ids.*' => ['integer', Rule::exists('stores', 'id')->where(fn ($query) => $query->where('company_id', $companyId))],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'El nombre es obligatorio.',
            'email.required' => 'El correo es obligatorio.',
            'email.unique' => 'Ya hay una cuenta con ese correo.',
            'document.unique' => 'Ya hay una cuenta con ese documento.',
            'role.required' => 'Elige un rol.',
        ];
    }
}
