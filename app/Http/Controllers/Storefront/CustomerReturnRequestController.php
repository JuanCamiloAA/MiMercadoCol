<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Http\Requests\CustomerReturns\RequestCustomerReturnRequest;
use App\Models\CustomerReturn;
use App\Models\CustomerReturnItem;
use App\Models\Order;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Services\CustomerReturnService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Devoluciones desde la tienda en linea: el cliente las pide sobre un pedido entregado, ve como
 * van y puede retirarlas mientras nadie haya recibido la mercancia.
 */
class CustomerReturnRequestController extends Controller
{
    public function __construct(protected CustomerReturnService $returns) {}

    public function create(Order $order): Response|RedirectResponse
    {
        $customer = auth('customer')->user();
        abort_if((int) $order->customer_id !== (int) $customer->id, 403, 'Ese pedido no es tuyo.');

        $sale = $order->sale_id ? Sale::query()->withoutGlobalScopes()->with('items.product', 'items.service')->find($order->sale_id) : null;

        if ($order->status !== Order::STATUS_DELIVERED || ! $sale || $sale->isCancelled()) {
            return redirect()->route('storefront.orders.show', $order)->with('warning', 'Solo se puede devolver un pedido entregado.');
        }

        $window = $this->returns->window($sale);

        if (! $window['within']) {
            return redirect()->route('storefront.orders.show', $order)->with('warning', 'El plazo para devolver este pedido venció el '.$window['until']->format('d/m/Y').'.');
        }

        $available = $this->returns->returnableQuantities($sale);

        // El cliente devuelve articulos y servicios enteros; el domicilio no se devuelve aqui.
        $items = $sale->items
            ->whereNull('parent_sale_item_id')
            ->filter(fn (SaleItem $item) => $item->product_id || $item->service_id)
            ->map(fn (SaleItem $item) => [
                'sale_item_id' => $item->id,
                'name' => $item->description,
                'unit' => $item->product?->unit ?? 'unit',
                'sold_by_weight' => (bool) $item->product?->sold_by_weight,
                'is_service' => $item->service_id !== null,
                'quantity' => round((float) $item->quantity, 3),
                'available' => $available[$item->id] ?? 0,
                'unit_price' => (float) $item->quantity > 0 ? (int) round($item->line_total / (float) $item->quantity) : 0,
                'image' => $item->product?->thumb_path ?? $item->product?->image_path ?? $item->service?->thumb_path ?? $item->service?->image_path,
                'includes' => $sale->items->where('parent_sale_item_id', $item->id)->pluck('description')->values(),
            ])
            ->values();

        if ($items->sum('available') <= 0) {
            return redirect()->route('storefront.orders.show', $order)->with('warning', 'Ya no queda nada por devolver de este pedido.');
        }

        return Inertia::render('Storefront/ReturnRequest', [
            'order' => ['id' => $order->id, 'number' => $order->number, 'delivered_at' => $order->delivered_at?->toIso8601String()],
            'items' => $items,
            'reasons' => CustomerReturnItem::reasonOptions(),
            'until' => $window['until']->toDateString(),
            'maxPhotos' => CustomerReturn::MAX_PHOTOS,
        ]);
    }

    public function store(RequestCustomerReturnRequest $request, Order $order): RedirectResponse
    {
        $return = $this->returns->requestOnline(
            $order,
            auth('customer')->user(),
            $request->returnData(),
            array_values($request->file('photos', [])),
        );

        return redirect()->route('storefront.orders.show', $order)
            ->with('success', "Recibimos tu solicitud de devolución {$return->number}. Te avisamos por correo cuando la revisemos.");
    }

    public function cancel(int $customerReturn): RedirectResponse
    {
        $return = $this->owned($customerReturn);
        $this->returns->cancelByCustomer($return, auth('customer')->user());

        return back()->with('success', "Retiramos tu solicitud {$return->number}.");
    }

    /** Las fotos son privadas: solo el cliente que las subio (y el equipo, desde el panel). */
    public function photo(int $customerReturn, int $index): \Symfony\Component\HttpFoundation\Response
    {
        $return = $this->owned($customerReturn);
        $path = $this->returns->photoPath($return, $index);

        abort_unless($path !== null, 404);

        return Storage::disk('local')->response($path);
    }

    protected function owned(int $id): CustomerReturn
    {
        $return = CustomerReturn::query()->withoutGlobalScopes()->findOrFail($id);
        abort_if((int) $return->customer_id !== (int) auth('customer')->id(), 403, 'Esa devolución no es tuya.');

        return $return;
    }
}
