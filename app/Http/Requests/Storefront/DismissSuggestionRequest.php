<?php

namespace App\Http\Requests\Storefront;

use App\Models\StorefrontSuggestionDismissal;
use App\Support\StoreContext;
use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Descartar (o restaurar) una sugerencia de publicar. Es por sede: lo que no conviene en una
 * puede convenir en otra, asi que la sede es parte de lo que se descarta.
 */
class DismissSuggestionRequest extends FormRequest
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
            'store_id' => ['required', 'integer'],
            'product_id' => [
                'required',
                'integer',
                Rule::exists('products', 'id')->where('company_id', TenantContext::requireCompanyId())->whereNull('deleted_at'),
            ],
            'group' => ['required', 'string', Rule::in(StorefrontSuggestionDismissal::GROUPS)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'product_id.exists' => 'Ese artículo no existe en esta empresa.',
            'group.in' => 'Ese motivo de sugerencia no existe.',
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if (! StoreContext::availableStores($this->user())->contains('id', (int) $this->input('store_id'))) {
                    $validator->errors()->add('store_id', 'Esa sede no existe o no la tienes asignada.');
                }
            },
        ];
    }
}
