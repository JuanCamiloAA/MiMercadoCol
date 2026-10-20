<?php

namespace App\Http\Requests\Platform;

use App\Models\MembershipPlan;
use App\Services\Membership\Entitlements;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Crear o editar un plan de membresia.
 *
 * Lo que incluye llega como nodos del arbol (`inventory.*`, `inventory.transfers.direct`). Un
 * nodo que no corresponde a nada, o que es de la plataforma, se rechaza.
 */
class SaveMembershipPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $highlights = $this->input('highlights');

        $this->merge([
            'slug' => Str::slug((string) ($this->input('slug') ?: $this->input('name'))),
            // Un renglon vacio en la lista de destacados no es un destacado.
            'highlights' => is_array($highlights)
                ? array_values(array_filter(array_map(fn ($line) => is_string($line) ? trim($line) : $line, $highlights), fn ($line) => $line !== '' && $line !== null))
                : $highlights,
        ]);
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
            'entitlements' => ['present', 'array', 'max:1000'],
            'entitlements.*' => ['required', 'string', 'max:120', function (string $attribute, mixed $value, Closure $fail) {
                if (! is_string($value) || ! Entitlements::isValid($value)) {
                    $fail(is_string($value) && Entitlements::isPlatformOnly($value)
                        ? 'Lo de la plataforma no forma parte de ningún plan.'
                        : 'Esa función no existe en el árbol de permisos.');
                }
            }],
            'highlights' => ['nullable', 'array', 'max:'.MembershipPlan::MAX_HIGHLIGHTS],
            'highlights.*' => ['string', 'max:80'],
            // Quitarle algo a un plan que ya usan empresas se confirma: el acceso se corta de inmediato.
            'confirm_removals' => ['nullable', 'boolean'],
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
        $data = $this->safe()->except(['confirm_removals']);
        $data['entitlements'] = Entitlements::normalize($data['entitlements'] ?? []);
        $data['highlights'] = array_values($data['highlights'] ?? []);

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
            'entitlements.present' => 'Marca lo que incluye el plan.',
            'highlights.max' => 'Los destacados son máximo '.MembershipPlan::MAX_HIGHLIGHTS.'.',
            'highlights.*.max' => 'Cada destacado va en máximo 80 letras.',
        ];
    }
}
