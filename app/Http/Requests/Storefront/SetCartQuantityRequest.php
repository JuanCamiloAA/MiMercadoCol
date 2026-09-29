<?php

namespace App\Http\Requests\Storefront;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Cuantos de un articulo (o de un servicio) quiere el cliente en el carrito: la cantidad
 * absoluta, no la que se suma. Cero lo quita.
 */
class SetCartQuantityRequest extends FormRequest
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
            // Un renglon es un articulo o un servicio, nunca los dos.
            'product_id' => ['nullable', 'integer', 'required_without:service_id', 'prohibits:service_id'],
            'service_id' => ['nullable', 'integer'],
            'quantity' => ['required', 'numeric', 'min:0', 'max:999'],
            // En un servicio: la presentacion elegida de cada articulo completo (familia => subarticulo).
            'choices' => ['nullable', 'array', 'max:30'],
            'choices.*' => ['integer'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'product_id.required_without' => 'Elige el artículo.',
            'product_id.prohibits' => 'Elige un artículo o un servicio, no los dos.',
            'quantity.min' => 'La cantidad no puede ser negativa.',
            'quantity.max' => 'Para pedir tanto, escríbenos: te armamos el pedido.',
        ];
    }
}
