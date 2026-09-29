<?php

namespace App\Http\Requests\ProductAttributes;

use Illuminate\Foundation\Http\FormRequest;

/** El orden de los valores: 250 ml, 500 ml, 1 L… o Pequeño, Mediano, Grande; no alfabetico. */
class ReorderAttributeValuesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('products.attributes.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'order' => ['required', 'array', 'min:1'],
            'order.*' => ['integer'],
        ];
    }
}
