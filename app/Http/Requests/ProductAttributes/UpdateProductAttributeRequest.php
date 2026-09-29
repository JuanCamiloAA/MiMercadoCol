<?php

namespace App\Http\Requests\ProductAttributes;

use App\Models\ProductAttribute;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Renombrar, cambiar como se muestra o desactivar una caracteristica. La medida no se cambia: sus
 * valores ya dicen «500 ml», y eso no se vuelve «500 g».
 */
class UpdateProductAttributeRequest extends FormRequest
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
            'name_in_label' => ['boolean'],
            'is_active' => ['boolean'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            /** @var ProductAttribute $attribute */
            $attribute = $this->route('attribute');
            $slug = Str::slug((string) $this->input('name'));

            $exists = ProductAttribute::query()
                ->where('company_id', $attribute->company_id)
                ->where('slug', $slug)
                ->whereKeyNot($attribute->id)
                ->exists();

            if ($exists) {
                $validator->errors()->add('name', 'Ya hay una característica con ese nombre.');
            }
        }];
    }
}
