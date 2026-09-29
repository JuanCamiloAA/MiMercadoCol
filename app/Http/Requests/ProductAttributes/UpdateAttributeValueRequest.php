<?php

namespace App\Http\Requests\ProductAttributes;

use App\Models\MediaAsset;
use App\Models\ProductAttributeValue;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Corregir un valor, su muestra de color o foto, o desactivarlo. En una medida el texto sale de
 * la cantidad, que no se cambia: los subarticulos que la usan ya tomaron ese contenido.
 */
class UpdateAttributeValueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('products.attributes.manage') ?? false;
    }

    public function rules(): array
    {
        /** @var ProductAttributeValue $value */
        $value = $this->route('value');

        return [
            'value' => ['sometimes', 'required', 'string', 'max:40'],
            'color_hex' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'media_id' => ['nullable', 'integer', Rule::exists(MediaAsset::class, 'id')->where('company_id', $value->attribute->company_id)],
            'is_active' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return ['color_hex.regex' => 'El color va como #RRGGBB.'];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            /** @var ProductAttributeValue $value */
            $value = $this->route('value');

            if (! $this->has('value') || $value->attribute->isMeasure()) {
                return;
            }

            $exists = ProductAttributeValue::query()
                ->where('product_attribute_id', $value->product_attribute_id)
                ->where('slug', ProductAttributeValue::slugFor((string) $this->input('value')))
                ->whereKeyNot($value->id)
                ->exists();

            if ($exists) {
                $validator->errors()->add('value', 'Ese valor ya existe en esta característica.');
            }
        }];
    }
}
