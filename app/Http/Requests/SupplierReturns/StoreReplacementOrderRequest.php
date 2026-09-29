<?php

namespace App\Http\Requests\SupplierReturns;

use Illuminate\Foundation\Http\FormRequest;

/**
 * La reposicion: que manda el proveedor a cambio. Se vuelve una orden de compra que se recibe
 * con el boton de siempre.
 */
class StoreReplacementOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('purchases.returns.respond') ?? false;
    }

    public function rules(): array
    {
        return [
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.id' => ['required', 'integer'],
            'lines.*.quantity' => ['nullable', 'numeric', 'min:0', 'regex:/^\d+(\.\d{1,3})?$/'],
            'note' => ['nullable', 'string', 'max:160'],
        ];
    }

    public function messages(): array
    {
        return [
            'lines.required' => 'Indica qué va a reponer el proveedor.',
            'lines.*.quantity.regex' => 'La cantidad admite hasta tres decimales.',
        ];
    }

    /** @return array<int, float> */
    public function quantities(): array
    {
        return collect($this->validated('lines'))
            ->mapWithKeys(fn (array $line) => [(int) $line['id'] => (float) ($line['quantity'] ?? 0)])
            ->all();
    }
}
