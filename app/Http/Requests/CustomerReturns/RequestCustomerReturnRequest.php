<?php

namespace App\Http\Requests\CustomerReturns;

use App\Models\CustomerReturn;
use App\Models\CustomerReturnItem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * El cliente de la tienda en linea pide una devolucion: que, cuanto, por que y, si quiere, fotos.
 * Que el pedido sea suyo, este entregado y en plazo lo revisa `CustomerReturnService`.
 */
class RequestCustomerReturnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth('customer')->check();
    }

    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1'],
            'items.*.sale_item_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'numeric', 'min:0'],
            'items.*.reason' => ['required', Rule::in(array_keys(CustomerReturnItem::REASONS))],
            'note' => ['nullable', 'string', 'max:2000'],
            'photos' => ['nullable', 'array', 'max:'.CustomerReturn::MAX_PHOTOS],
            'photos.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
        ];
    }

    public function messages(): array
    {
        return [
            'items.required' => 'Elige qué quieres devolver.',
            'items.*.reason.required' => 'Cuéntanos por qué devuelves cada artículo.',
            'items.*.reason.in' => 'Cuéntanos por qué devuelves cada artículo.',
            'photos.max' => 'Puedes adjuntar hasta '.CustomerReturn::MAX_PHOTOS.' fotos.',
            'photos.*.image' => 'Las fotos deben ser imágenes.',
            'photos.*.max' => 'Cada foto debe pesar menos de 4 MB.',
        ];
    }

    /**
     * @return array{items: list<array<string, mixed>>, note: string|null}
     */
    public function returnData(): array
    {
        $data = $this->validated();

        return [
            'items' => array_values(array_filter($data['items'], fn (array $item) => (float) $item['quantity'] > 0)),
            'note' => $data['note'] ?? null,
        ];
    }
}
