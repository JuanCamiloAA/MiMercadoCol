<?php

namespace App\Http\Requests\Platform;

use App\Models\BillingCycle;
use App\Models\Company;
use App\Models\MembershipPlan;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Editar una empresa desde Plataforma: datos legales y, a mano, su membresia. Todo cambio de
 * membresia exige motivo y deja evento en la bitacora. El logo no se edita aqui: la marca vive
 * en Ajustes > Marca.
 */
class UpdateCompanyRequest extends FormRequest
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
        /** @var Company $company */
        $company = $this->route('company');

        return [
            'name' => ['required', 'string', 'max:120'],
            'legal_name' => ['required', 'string', 'max:160'],
            'tax_id' => ['required', 'string', 'max:40', Rule::unique('companies', 'tax_id')->ignore($company->id)],
            'email' => ['nullable', 'email', 'max:160'],
            'phone' => ['nullable', 'string', 'max:40'],
            'address' => ['nullable', 'string', 'max:160'],
            'city' => ['nullable', 'string', 'max:80'],
            // Una desactivada se puede conservar, no elegir de nuevo.
            'business_category_id' => ['required', 'integer', Rule::exists('business_categories', 'id')->where(
                fn ($query) => $query->where('is_active', true)->orWhere('id', $company->business_category_id)
            )],
            'show_on_landing' => ['required', 'boolean'],

            'membership_plan_id' => ['nullable', 'integer', Rule::exists(MembershipPlan::class, 'id')],
            'billing_cycle_id' => ['nullable', 'integer', Rule::exists(BillingCycle::class, 'id')],
            'membership_status' => ['required', Rule::in(Company::MEMBERSHIP_STATUSES)],
            'membership_ends_at' => ['nullable', 'date'],
            'grace_ends_at' => ['nullable', 'date'],
            'membership_reason' => ['nullable', 'string', 'max:250'],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($this->membershipChanges() !== [] && trim((string) $this->input('membership_reason')) === '') {
                    $validator->errors()->add('membership_reason', 'Escribe el motivo del cambio de membresía: queda en la bitácora.');
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
            'name.required' => 'Escribe el nombre de la empresa.',
            'legal_name.required' => 'Escribe la razón social.',
            'tax_id.required' => 'Escribe el NIT.',
            'tax_id.unique' => 'Ya hay otra empresa con ese NIT.',
            'email.email' => 'El correo no es válido.',
            'membership_status.in' => 'Ese estado no existe.',
            'membership_ends_at.date' => 'La fecha de vencimiento no es válida.',
            'grace_ends_at.date' => 'La fecha de fin de gracia no es válida.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function legalData(): array
    {
        return $this->safe()->only(['name', 'legal_name', 'tax_id', 'email', 'phone', 'address', 'city', 'business_category_id', 'show_on_landing']);
    }

    /**
     * Solo lo que de verdad cambia de la membresia.
     *
     * @return array<string, mixed>
     */
    public function membershipChanges(): array
    {
        /** @var Company $company */
        $company = $this->route('company');
        $changes = [];

        foreach (['membership_plan_id', 'billing_cycle_id'] as $key) {
            $value = $this->input($key) !== null && $this->input($key) !== '' ? (int) $this->input($key) : null;

            if ($value !== ($company->{$key} !== null ? (int) $company->{$key} : null)) {
                $changes[$key] = $value;
            }
        }

        if ($this->input('membership_status') !== $company->membership_status) {
            $changes['membership_status'] = $this->input('membership_status');
        }

        foreach (['membership_ends_at', 'grace_ends_at'] as $key) {
            $raw = $this->input($key);
            $value = $raw ? Carbon::parse($raw, 'America/Bogota')->endOfDay() : null;
            $current = $company->{$key};

            if (($value?->toDateString()) !== ($current?->toDateString())) {
                $changes[$key] = $value;
            }
        }

        return $changes;
    }
}
