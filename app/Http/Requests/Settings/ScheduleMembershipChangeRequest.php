<?php

namespace App\Http\Requests\Settings;

use App\Models\BillingCycle;
use App\Models\MembershipPlan;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Cambiar de plan o de ciclo desde la empresa: aplica en la proxima renovacion.
 */
class ScheduleMembershipChangeRequest extends FormRequest
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
            'membership_plan_id' => ['required', 'integer', Rule::exists(MembershipPlan::class, 'id')->where('is_active', true)],
            'billing_cycle_id' => ['required', 'integer', Rule::exists(BillingCycle::class, 'id')->where('is_active', true)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'membership_plan_id.required' => 'Elige un plan.',
            'membership_plan_id.exists' => 'Ese plan no está disponible.',
            'billing_cycle_id.required' => 'Elige un ciclo.',
            'billing_cycle_id.exists' => 'Ese ciclo no está disponible.',
        ];
    }
}
