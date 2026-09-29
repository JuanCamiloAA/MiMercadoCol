<?php

namespace App\Http\Requests\ProductAttributes;

use App\Models\MediaAsset;
use App\Models\ProductAttribute;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Un valor nuevo: texto («Mora», «XL») o, en una medida, cantidad y unidad («1,5 L»). Se crea
 * desde el catalogo o al vuelo desde el formulario del articulo.
 */
class StoreAttributeValueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('products.attributes.manage') ?? false;
    }

    public function rules(): array
    {
        /** @var ProductAttribute $attribute */
        $attribute = $this->route('attribute');
        $measure = $attribute->isMeasure();

        return [
            'value' => [$measure ? 'nullable' : 'required', 'string', 'max:40'],
            'amount' => [$measure ? 'required' : 'nullable', 'numeric', 'gt:0', 'max:99999'],
            'unit' => [$measure ? 'required' : 'nullable', Rule::in($measure ? $attribute->units() : [])],
            'color_hex' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'media_id' => ['nullable', 'integer', Rule::exists(MediaAsset::class, 'id')->where('company_id', $attribute->company_id)],
        ];
    }

    public function messages(): array
    {
        return [
            'value.required' => 'Escribe el valor.',
            'amount.required' => 'Escribe la cantidad.',
            'amount.gt' => 'La cantidad tiene que ser mayor que cero.',
            'unit.required' => 'Elige la unidad.',
            'unit.in' => 'Esa unidad no es de esta medida.',
            'color_hex.regex' => 'El color va como #RRGGBB.',
        ];
    }
}
