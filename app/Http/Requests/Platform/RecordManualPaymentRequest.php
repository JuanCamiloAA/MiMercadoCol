<?php

namespace App\Http\Requests\Platform;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Un pago de membresia recibido por fuera de la pasarela (transferencia, consignacion).
 */
class RecordManualPaymentRequest extends FormRequest
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
        return ['note' => ['required', 'string', 'min:4', 'max:120']];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'note.required' => 'Escribe cómo llegó el pago (por ejemplo, «Transferencia Bancolombia 12/10»).',
            'note.min' => 'Escribe cómo llegó el pago.',
        ];
    }
}
