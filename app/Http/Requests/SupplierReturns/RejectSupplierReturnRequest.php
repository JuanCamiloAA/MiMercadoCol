<?php

namespace App\Http\Requests\SupplierReturns;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Lo que el proveedor rechazo y volvio, por renglon. El tope (lo neto de cada renglon y lo que
 * todavia no ha respondido el proveedor) lo mide `SupplierReturnService::reject`.
 */
class RejectSupplierReturnRequest extends FormRequest
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
            'lines.required' => 'Indica cuánto rechazó el proveedor.',
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
