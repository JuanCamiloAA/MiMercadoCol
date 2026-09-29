<?php

namespace App\Http\Requests\Platform;

use App\Models\MembershipPlan;
use App\Services\Membership\MembershipFeatures;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Crear o editar un plan de membresia.
 */
class SaveMembershipPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['slug' => Str::slug((string) ($this->input('slug') ?: $this->input('name')))]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var MembershipPlan|null $plan */
        $plan = $this->route('plan');

        return [
            'name' => ['required', 'string', 'max:80'],
            'slug' => ['required', 'string', 'max:80', Rule::unique('membership_plans', 'slug')->ignore($plan?->id)],
            'description' => ['nullable', 'string', 'max:500'],
            'monthly_price' => ['required', 'integer', 'min:0', 'max:100000000'],
            'trial_days' => ['required', 'integer', 'min:0', 'max:90'],
            'max_stores' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'max_users' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'max_registers' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'features' => ['array'],
            'features.*' => ['string', Rule::in(array_keys(MembershipFeatures::CATALOGUE))],
            'is_public' => ['required', 'boolean'],
            'is_featured' => ['required', 'boolean'],
            'is_active' => ['required', 'boolean'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:1000'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function planData(): array
    {
        $data = $this->validated();
        $data['features'] = MembershipFeatures::sanitize($data['features'] ?? []);

        return $data;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Escribe el nombre del plan.',
            'slug.unique' => 'Ya hay un plan con esa dirección; cambia el nombre o la dirección.',
            'monthly_price.required' => 'Escribe el precio mensual.',
            'monthly_price.integer' => 'El precio va en pesos, sin decimales.',
            'trial_days.max' => 'La prueba gratis no puede pasar de 90 días.',
            'max_stores.min' => 'El tope de sedes es de al menos 1; déjalo vacío para no poner tope.',
            'max_users.min' => 'El tope de usuarios es de al menos 1; déjalo vacío para no poner tope.',
            'max_registers.min' => 'El tope de cajas es de al menos 1; déjalo vacío para no poner tope.',
            'features.*.in' => 'Esa función no existe.',
        ];
    }
}
