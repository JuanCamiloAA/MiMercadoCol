<?php

namespace App\Http\Requests;

use App\Models\Employee;
use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('employees.index.create') ?? false;
    }

    public function rules(): array
    {
        $companyId = TenantContext::activeCompanyId();

        return [
            'store_id' => ['required', 'integer', Rule::exists('stores', 'id')->where(fn ($query) => $query->where('company_id', $companyId))],
            'first_name' => ['required', 'string', 'max:60'],
            'last_name' => ['required', 'string', 'max:60'],
            'document_type' => ['required', Rule::in(Employee::DOCUMENT_TYPES)],
            'document_number' => [
                'required', 'string', 'max:40',
                Rule::unique('employees', 'document_number')->where(fn ($query) => $query->where('company_id', $companyId))->whereNull('deleted_at'),
            ],
            'birth_date' => ['nullable', 'date', 'before:today'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:120'],
            'address' => ['nullable', 'string', 'max:160'],
            'emergency_contact_name' => ['nullable', 'string', 'max:80'],
            'emergency_contact_phone' => ['nullable', 'string', 'max:30'],
            'position' => ['required', 'string', 'max:60'],
            'contract_type' => ['required', Rule::in(Employee::CONTRACT_TYPES)],
            'hired_at' => ['required', 'date'],
            'work_schedule' => ['nullable', 'array'],
            'create_access' => ['boolean'],
            'access_email' => ['required_if:create_access,true', 'nullable', 'email', 'max:120', Rule::unique('users', 'email')->whereNull('deleted_at')],
            'access_role' => ['required_if:create_access,true', 'nullable', 'string', Rule::exists('roles', 'name')->where(fn ($query) => $query->where('company_id', $companyId))],
            'access_pin' => ['nullable', 'digits:4'],
        ];
    }

    public function messages(): array
    {
        return [
            'store_id.required' => 'Elige la sede.',
            'first_name.required' => 'El nombre es obligatorio.',
            'last_name.required' => 'El apellido es obligatorio.',
            'document_number.required' => 'El documento es obligatorio.',
            'document_number.unique' => 'Ya hay un empleado con ese documento.',
            'position.required' => 'El cargo es obligatorio.',
            'hired_at.required' => 'La fecha de ingreso es obligatoria.',
            'access_email.required_if' => 'El correo de acceso es obligatorio.',
            'access_email.unique' => 'Ya hay una cuenta con ese correo.',
            'access_role.required_if' => 'Elige el rol de la cuenta.',
            'access_pin.digits' => 'El PIN debe tener 4 digitos.',
        ];
    }
}
