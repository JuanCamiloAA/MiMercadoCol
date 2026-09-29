<?php

namespace App\Http\Requests\SupplierReturns;

use App\Models\SupplierReturnItem;
use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Guardar el borrador de una devolucion a proveedor.
 *
 * Aqui se revisa la forma; los topes (lo recibido, lo ya devuelto, otros borradores, lo
 * disponible en la bodega) los mide `SupplierReturnService`, que es el mismo que vuelve a
 * medirlos bajo bloqueo al despachar. Los renglones en cero se ignoran: «no lo devuelvo».
 */
class StoreSupplierReturnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('purchases.returns.create') ?? false;
    }

    public function rules(): array
    {
        $companyId = TenantContext::activeCompanyId();
        $ofCompany = fn ($query) => $query->where('company_id', $companyId);

        return [
            'purchase_order_id' => ['nullable', 'integer', Rule::exists('purchase_orders', 'id')->where($ofCompany)],
            'supplier_id' => ['required_without:purchase_order_id', 'nullable', 'integer', Rule::exists('suppliers', 'id')->where($ofCompany)],
            'warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')->where($ofCompany)],
            'note' => ['nullable', 'string', 'max:255'],
            'shipping_reference' => ['nullable', 'string', 'max:60'],
            'dispatch_now' => ['boolean'],

            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['nullable', 'integer'],
            'items.*.purchase_order_item_id' => ['nullable', 'integer'],
            'items.*.quantity' => ['nullable', 'numeric', 'min:0', 'regex:/^\d+(\.\d{1,3})?$/'],
            'items.*.reason' => ['nullable', 'string', Rule::in(array_keys(SupplierReturnItem::REASONS))],
            'items.*.note' => ['nullable', 'string', 'max:255'],
            'items.*.lot_id' => ['nullable', 'integer'],
            'items.*.unit_cost' => ['nullable', 'integer', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'supplier_id.required_without' => 'Elige el proveedor.',
            'supplier_id.exists' => 'Ese proveedor no es de tu empresa.',
            'warehouse_id.required' => 'Elige la bodega de donde sale la mercancía.',
            'warehouse_id.exists' => 'Esa bodega no es de tu empresa.',
            'items.required' => 'Indica al menos un artículo con cantidad a devolver.',
            'items.*.quantity.regex' => 'La cantidad admite hasta tres decimales.',
            'items.*.quantity.min' => 'La cantidad no puede ser negativa.',
            'items.*.reason.in' => 'Elige un motivo de la lista.',
            'items.*.unit_cost.min' => 'El costo no puede ser negativo.',
            'shipping_reference.max' => 'La guía admite hasta 60 caracteres.',
        ];
    }

    /**
     * Lo que recibe el servicio. Con orden, el proveedor y la sede salen de la orden.
     *
     * @return array<string, mixed>
     */
    public function payload(int $companyId, int $storeId): array
    {
        return [
            'company_id' => $companyId,
            'store_id' => $storeId,
            'purchase_order_id' => $this->validated('purchase_order_id'),
            'supplier_id' => $this->validated('supplier_id'),
            'warehouse_id' => (int) $this->validated('warehouse_id'),
            'note' => $this->validated('note'),
            'shipping_reference' => $this->validated('shipping_reference'),
            'items' => $this->validated('items'),
        ];
    }
}
