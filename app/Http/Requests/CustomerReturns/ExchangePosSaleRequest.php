<?php

namespace App\Http\Requests\CustomerReturns;

use App\Http\Requests\StorePosSaleRequest;
use App\Models\CustomerReturnItem;
use App\Models\SalePayment;
use Illuminate\Validation\Rule;

/**
 * Cambio en caja: la venta nueva (con las mismas reglas de un cobro normal) y lo que se devuelve
 * de la venta original. El valor devuelto viaja como un pago `exchange` de la venta nueva.
 */
class ExchangePosSaleRequest extends StorePosSaleRequest
{
    public function authorize(): bool
    {
        return parent::authorize() && $this->user()->can('pos.sale.refund');
    }

    public function rules(): array
    {
        return [
            ...parent::rules(),
            'return' => ['required', 'array'],
            'return.items' => ['required', 'array', 'min:1'],
            'return.items.*.sale_item_id' => ['required', 'integer'],
            'return.items.*.quantity' => ['required', 'numeric', 'min:0'],
            'return.items.*.reason' => ['nullable', Rule::in(array_keys(CustomerReturnItem::REASONS))],
            'return.items.*.disposition' => ['nullable', Rule::in(array_keys(CustomerReturnItem::DISPOSITION_LABELS))],
            'return.reason' => ['nullable', 'string', 'max:160'],
            'return.leftover_method' => ['nullable', Rule::in([SalePayment::METHOD_CASH, SalePayment::METHOD_STORE_CREDIT])],
        ];
    }

    protected function paymentMethods(): array
    {
        return [...SalePayment::POS_METHODS, SalePayment::METHOD_EXCHANGE];
    }

    /**
     * @return array<string, mixed>
     */
    public function saleData(): array
    {
        $data = parent::saleData();
        unset($data['return']);

        return $data;
    }

    /**
     * @return array{items: list<array<string, mixed>>, reason?: string|null, leftover_method?: string|null}
     */
    public function returnData(): array
    {
        $data = $this->validated('return');
        $data['items'] = array_values($data['items']);

        return $data;
    }
}
