<?php

namespace App\Http\Requests;

use App\Models\Employee;
use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('employees.index.edit') ?? false;
    }

    public function rules(): array
    {
        $companyId = TenantContext::activeCompanyId();
        $employeeId = $this->route('employee')->id;

        return [
            'store_id' => ['required', 'integer', Rule::exists('stores', 'id')->where(fn ($query) => $query->where('company_id', $companyId))],
            'first_name' => ['required', 'string', 'max:60'],
            'last_name' => ['required', 'string', 'max:60'],
            'document_type' => ['required', Rule::in(Employee::DOCUMENT_TYPES)],
            'document_number' => [
                'required', 'string', 'max:40',
                Rule::unique('employees', 'document_number')->ignore($employeeId)->where(fn ($query) => $query->where('company_id', $companyId))->whereNull('deleted_at'),
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
        ];
    }

    public function messages(): array
    {
        return [
            'store_id.required' => 'Elige la sede.',
            'first_name.required' => 'El nombre es obligatorio.',
            'last_name.required' => 'El apellido es obligatorio.',
            'document_number.unique' => 'Ya hay un empleado con ese documento.',
            'position.required' => 'El cargo es obligatorio.',
        ];
    }
}
