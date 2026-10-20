<?php

namespace App\Http\Requests\Platform;

use App\Payments\Gateways\GatewayRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Que pasarelas pueden conectar las empresas a su propia cuenta.
 */
class UpdateCompanyGatewayProvidersRequest extends FormRequest
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
            'providers' => ['present', 'array'],
            'providers.*' => ['string', 'distinct', Rule::in(app(GatewayRegistry::class)->providers())],
        ];
    }
}
