<?php

namespace App\Http\Requests;

use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('roles.index.create') ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => \Illuminate\Support\Str::slug((string) $this->input('name', $this->input('display_name')), '_'),
        ]);
    }

    public function rules(): array
    {
        $companyId = TenantContext::activeCompanyId();

        return [
            'display_name' => ['required', 'string', 'max:60'],
            'name' => [
                'required', 'string', 'max:60', 'regex:/^[a-z0-9_]+$/',
                Rule::unique('roles', 'name')->where(fn ($query) => $query->where('company_id', $companyId)->where('guard_name', 'web')),
            ],
            'description' => ['nullable', 'string', 'max:180'],
            'color' => ['required', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'permissions' => ['array'],
            'permissions.*' => ['string'],
        ];
    }

    public function messages(): array
    {
        return [
            'display_name.required' => 'Ponle un nombre al rol.',
            'name.unique' => 'Ya existe un rol con ese nombre.',
            'name.regex' => 'El identificador solo admite letras minusculas, numeros y guiones bajos.',
            'color.regex' => 'El color debe venir en formato #RRGGBB.',
        ];
    }
}
