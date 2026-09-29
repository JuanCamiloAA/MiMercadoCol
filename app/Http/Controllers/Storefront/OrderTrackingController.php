<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\CustomerReturn;
use App\Models\CustomerReturnItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Sale;
use App\Services\CustomerReturnService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OrderTrackingController extends Controller
{
    public function index(Request $request): Response
    {
        $customer = auth('customer')->user();

        $orders = Order::query()
            ->withoutGlobalScopes()
            ->where('customer_id', $customer->id)
            ->orderByDesc('placed_at')
            ->paginate(10)
            ->through(fn (Order $order) => [
                'id' => $order->id,
                'number' => $order->number,
                'status' => $order->status,
                'status_label' => $order->statusLabel(),
                'total' => $order->total,
                'placed_at' => $order->placed_at->toIso8601String(),
            ]);

        return Inertia::render('Storefront/Orders', [
            'orders' => $orders,
        ]);
    }

    public function show(Request $request, Order $order): Response
    {
        $customer = auth('customer')->user();

        abort_if($order->customer_id !== $customer->id, 403, 'Ese pedido no es tuyo.');

        $order->load(['items.product:id,name,unit', 'items.service:id,name', 'histories', 'store:id,name,phone', 'courier:id,name,last_name', 'couponRedemption.coupon:id,code']);

        return Inertia::render('Storefront/OrderTracking', [
            'order' => [
                'id' => $order->id,
                'number' => $order->number,
                'status' => $order->status,
                'status_label' => $order->statusLabel(),
                'payment_label' => Order::PAYMENT_LABELS[$order->payment_method] ?? $order->payment_method,
                'payment_status' => $order->payment_status,
                'delivery_address' => $order->delivery_address,
                'delivery_notes' => $order->delivery_notes,
                'delivery_slot_start' => $order->delivery_slot_start?->toIso8601String(),
                'delivery_slot_end' => $order->delivery_slot_end?->toIso8601String(),
                'subtotal' => $order->subtotal,
                'delivery_fee' => $order->delivery_fee,
                'discount_total' => $order->discount_total,
                'coupon' => $order->couponRedemption?->coupon?->code,
                'total' => $order->total,
                'placed_at' => $order->placed_at->toIso8601String(),
                'delivered_at' => $order->delivered_at?->toIso8601String(),
                'store' => $order->store?->only(['name', 'phone']),
                'courier' => $order->courier?->full_name,
                // El servicio con su «Incluye»: los articulos van debajo, sin precio propio si es cerrado.
                'items' => $order->items->map(fn (OrderItem $item) => [
                    'id' => $item->id,
                    'is_service' => $item->isService(),
                    'parent_id' => $item->parent_order_item_id,
                    'name' => $item->product?->name ?? $item->service?->name,
                    'unit' => $item->product?->unit ?? 'unit',
                    'quantity' => round((float) $item->quantity, 3),
                    'picked_quantity' => $item->picked_quantity !== null ? round((float) $item->picked_quantity, 3) : null,
                    'unit_price' => $item->unit_price,
                    'line_total' => $item->line_total,
                    'pick_note' => $item->pick_note,
                ])->values(),
                'histories' => $order->histories->map(fn ($history) => [
                    'id' => $history->id,
                    'to_status' => $history->to_status,
                    'to_label' => Order::STATUS_LABELS[$history->to_status] ?? $history->to_status,
                    'note' => $history->note,
                    'changed_at' => $history->changed_at->toIso8601String(),
                ])->values(),
                'timeline' => array_keys(Order::STATUS_LABELS),
                'store_credit_amount' => $order->store_credit_amount,
            ],
            'returns' => CustomerReturn::query()->withoutGlobalScopes()
                ->where('order_id', $order->id)
                ->where('customer_id', $customer->id)
                ->with('items')
                ->orderByDesc('id')
                ->get()
                ->map(fn (CustomerReturn $return) => [
                    'id' => $return->id,
                    'number' => $return->number,
                    'status' => $return->status,
                    'status_label' => $return->statusLabel(),
                    'created_at' => $return->created_at?->toIso8601String(),
                    'total' => $return->total,
                    'reject_reason' => $return->reject_reason,
                    'resolution' => $return->resolution,
                    'refunded_total' => $return->refunded_total,
                    'credited_total' => $return->credited_total,
                    'can_cancel' => $return->customerCanCancel(),
                    'items' => $return->items->whereNull('parent_item_id')->values()->map(fn (CustomerReturnItem $item) => [
                        'id' => $item->id,
                        'description' => $item->description,
                        'quantity' => round((float) $item->quantity, 3),
                        'reason_label' => $item->reasonLabel(),
                    ]),
                ])
                ->values(),
            'canRequestReturn' => $this->canRequestReturn($order),
        ]);
    }

    /** Se puede pedir mientras el pedido este entregado, en plazo y quede algo por devolver. */
    protected function canRequestReturn(Order $order): bool
    {
        if ($order->status !== Order::STATUS_DELIVERED || ! $order->sale_id) {
            return false;
        }

        $sale = Sale::query()->withoutGlobalScopes()->find($order->sale_id);

        if (! $sale || $sale->isCancelled()) {
            return false;
        }

        $returns = app(CustomerReturnService::class);

        if (! $returns->window($sale)['within']) {
            return false;
        }

        $available = $returns->returnableQuantities($sale);

        return $sale->items
            ->whereNull('parent_sale_item_id')
            ->filter(fn ($item) => ($item->product_id || $item->service_id) && ($available[$item->id] ?? 0) > 0)
            ->isNotEmpty();
    }
}
