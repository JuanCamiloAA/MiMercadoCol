<?php

namespace App\Http\Requests\Platform;

use App\Models\PaymentGatewaySetting;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * El entorno en uso de la pasarela y el interruptor de cobros.
 */
class UpdateGatewaySettingsRequest extends FormRequest
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
            'environment' => ['required', Rule::in(PaymentGatewaySetting::ENVIRONMENTS)],
            'is_enabled' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['environment.in' => 'Ese entorno no existe.'];
    }
}
