<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\CustomerReturn;
use App\Models\Order;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Store;
use App\Models\User;
use App\Services\CustomerReturnService;
use Illuminate\Database\Seeder;

/**
 * Devoluciones de venta de demostracion, por el mismo camino de la caja y de la tienda: una en
 * caja con saldo a favor, otra reembolsada con tarjeta, y dos solicitudes de la tienda en linea
 * (una por revisar y una aprobada esperando la mercancia).
 */
class CustomerReturnSeeder extends Seeder
{
    public function __construct(protected CustomerReturnService $returns) {}

    public function run(): void
    {
        $company = Company::query()->orderBy('id')->firstOrFail();

        if (CustomerReturn::query()->withoutGlobalScopes()->where('company_id', $company->id)->exists()) {
            return;
        }

        $admin = User::query()->where('email', 'admin@lacanasta.co')->first()
            ?? User::query()->where('company_id', $company->id)->firstOrFail();
        $store = Store::query()->withoutGlobalScopes()->where('company_id', $company->id)->orderBy('id')->firstOrFail();

        $posSales = Sale::query()->withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('store_id', $store->id)
            ->where('channel', Sale::CHANNEL_POS)
            ->where('status', Sale::STATUS_PAID)
            ->whereNotNull('customer_id')
            // A una ficha real: al consumidor final no se le abona saldo.
            ->whereHas('customer', fn ($query) => $query->where('document_number', 'not regexp', '^2{9,}$'))
            ->where('sold_at', '>=', now()->subDays(10))
            ->latest('sold_at')
            ->limit(2)
            ->get();

        foreach ($posSales as $index => $sale) {
            $line = $this->firstProductLine($sale);

            if (! $line) {
                continue;
            }

            $this->returns->registerAtPos($sale, [
                'items' => [['sale_item_id' => $line->id, 'quantity' => $this->oneOf($line), 'reason' => $index === 0 ? 'damaged' : 'changed_mind']],
                'resolution' => $index === 0 ? CustomerReturn::RESOLUTION_STORE_CREDIT : CustomerReturn::RESOLUTION_REFUND,
                'refund_method' => 'card',
                'reason' => $index === 0 ? 'El empaque venía roto' : 'Compró de más',
            ], $admin, $store, null);
        }

        $orders = Order::query()->withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('status', Order::STATUS_DELIVERED)
            ->whereNotNull('sale_id')
            ->whereNotNull('customer_id')
            ->latest('delivered_at')
            ->limit(2)
            ->get();

        foreach ($orders as $index => $order) {
            $sale = Sale::query()->withoutGlobalScopes()->find($order->sale_id);
            $line = $sale ? $this->firstProductLine($sale) : null;

            if (! $line || ! $order->customer) {
                continue;
            }

            $return = $this->returns->requestOnline($order, $order->customer, [
                'items' => [['sale_item_id' => $line->id, 'quantity' => $this->oneOf($line), 'reason' => $index === 0 ? 'expired' : 'wrong_item']],
                'note' => $index === 0 ? 'Venía vencido desde hace dos días.' : 'Pedí la presentación grande y llegó la pequeña.',
            ]);

            if ($index === 1) {
                $this->returns->approve($return, $admin);
            }
        }
    }

    protected function firstProductLine(Sale $sale): ?SaleItem
    {
        return $sale->items()->whereNotNull('product_id')->whereNull('parent_sale_item_id')->with('product')->orderBy('id')->first();
    }

    /** Una unidad, o medio kilo de lo que se vende por peso (sin pasarse de lo vendido). */
    protected function oneOf(SaleItem $line): float
    {
        $unit = $line->product?->sold_by_weight ? 0.5 : 1.0;

        return min($unit, (float) $line->quantity);
    }
}
