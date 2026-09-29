<?php

namespace App\Http\Requests\ProductAttributes;

use App\Models\ProductAttribute;
use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** Una caracteristica nueva: «Sabor», «Volumen», «Talla de calzado»… */
class StoreProductAttributeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('products.attributes.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:40'],
            'display' => ['required', Rule::in(array_keys(ProductAttribute::DISPLAYS))],
            'measure' => ['nullable', Rule::in(array_keys(ProductAttribute::MEASURES))],
            'name_in_label' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return ['name.required' => 'Ponle nombre a la característica.'];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            $slug = Str::slug((string) $this->input('name'));

            $exists = ProductAttribute::query()
                ->where('company_id', TenantContext::activeCompanyId())
                ->where('slug', $slug)
                ->exists();

            if ($slug !== '' && $exists) {
                $validator->errors()->add('name', 'Ya hay una característica con ese nombre.');
            }
        }];
    }
}
