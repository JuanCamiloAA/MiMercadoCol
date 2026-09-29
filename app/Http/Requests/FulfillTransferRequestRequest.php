<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Generar un traslado a partir de una solicitud.
 *
 * Aqui solo se mira la forma. Que no se mande mas de lo pendiente lo decide
 * `StockTransferRequestService::fulfill`, con la fila bloqueada.
 */
class FulfillTransferRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('inventory.transfer_requests.fulfill') ?? false;
    }

    public function rules(): array
    {
        return [
            'dispatch' => ['boolean'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'numeric', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'items.required' => 'Indica cuanto vas a mandar.',
            'items.*.quantity.min' => 'Las cantidades no pueden ser negativas.',
        ];
    }

    /**
     * @return array<int, float> Cantidad por id de renglon de la solicitud.
     */
    public function quantities(): array
    {
        $quantities = [];

        foreach ($this->validated('items') as $item) {
            $quantities[(int) $item['id']] = (float) $item['quantity'];
        }

        return $quantities;
    }
}
