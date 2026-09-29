<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SalePayment;
use Illuminate\Support\Carbon;

/**
 * Lo que pintan las vistas previas de Ajustes: un tiquete y la caja con datos de la empresa.
 *
 * El tiquete de muestra es la ultima venta de caja con pocas lineas, para que la vista previa
 * se parezca a lo que de verdad sale impreso. Solo si la empresa todavia no ha vendido nada se
 * arma uno de ejemplo, que se marca como tal.
 */
class SettingsPreviewService
{
    /** Lineas maximas de la venta que se usa de muestra: la vista previa tiene que caber. */
    protected const MAX_LINES = 5;

    public function __construct(
        protected ReceiptService $receipts,
        protected PromotionService $promotions,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function receiptSample(int $companyId): array
    {
        $sale = Sale::query()
            ->where('company_id', $companyId)
            ->where('channel', Sale::CHANNEL_POS)
            ->whereIn('status', [Sale::STATUS_PAID, Sale::STATUS_CREDIT])
            ->where('total', '>', 0)
            ->has('items', '<=', self::MAX_LINES)
            ->with(['items', 'payments', 'user', 'customer', 'seller'])
            ->latest('sold_at')
            ->first();

        $sample = $sale ? $this->fromSale($sale) : $this->example();

        return [
            ...$sample,
            // Los dos codigos, para que cambiar entre barras y QR no tenga que ir al servidor.
            'barcode' => $this->receipts->codeDataUri('barcode', $sample['number']),
            'qr' => $this->receipts->codeDataUri('qr', $sample['number']),
            // La canasta en negro que sale si se pide logo y la empresa no ha subido ninguno.
            'default_logo' => $this->receipts->defaultLogo(),
        ];
    }

    /**
     * Unos articulos de la empresa, con el precio que cobraria la caja hoy.
     *
     * @return array{products: list<array{name: string, price: int, badge: string|null}>, categories: list<string>}
     */
    public function posSample(int $companyId): array
    {
        $rows = Product::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->orderBy('name')
            ->limit(8)
            ->get(['id', 'name', 'price', 'tax_rate', 'category_id']);

        // El precio lo dice `PromotionService`, igual que en la caja: si esta vista mostrara el
        // de lista, diria otra cosa que la pantalla que imita.
        $promos = $rows->isEmpty() ? [] : $this->promotions->descriptors($rows, $companyId, PromotionService::CHANNEL_POS);

        return [
            'products' => $rows->map(fn (Product $product) => [
                'name' => $product->name,
                'price' => (int) ($promos[(int) $product->id]['price'] ?? $product->price),
                'badge' => $promos[(int) $product->id]['badge'] ?? null,
            ])->values()->all(),
            'categories' => Category::query()
                ->where('company_id', $companyId)
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('name')
                ->limit(5)
                ->pluck('name')
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function fromSale(Sale $sale): array
    {
        $cash = $sale->payments->firstWhere('method', SalePayment::METHOD_CASH);

        return [
            'is_example' => false,
            'number' => (string) $sale->number,
            'date' => $sale->sold_at->format('d/m/Y h:i a'),
            'cashier' => $sale->user?->name,
            'seller' => $sale->seller && $sale->seller->full_name !== $sale->user?->full_name ? $sale->seller->full_name : null,
            'customer' => $sale->customer ? ['name' => $sale->customer->name, 'document' => $sale->customer->document_number] : null,
            // Como el tiquete: los articulos de un servicio van debajo de su linea.
            'items' => $sale->items->whereNull('parent_sale_item_id')->map(fn ($item) => [
                'description' => $item->description,
                'quantity' => rtrim(rtrim(number_format((float) $item->quantity, 3, ',', '.'), '0'), ','),
                'unit_price' => (int) $item->unit_price,
                'discount' => (int) $item->discount,
                'line_total' => (int) $item->line_total,
                'components' => $sale->items->where('parent_sale_item_id', $item->id)->map(fn ($component) => [
                    'description' => $component->description,
                    'quantity' => rtrim(rtrim(number_format((float) $component->quantity, 3, ',', '.'), '0'), ','),
                    'line_total' => (int) $component->line_total,
                    'adjusted' => $component->component_change !== null,
                ])->values()->all(),
            ])->values()->all(),
            'subtotal' => (int) $sale->subtotal,
            'tax_total' => (int) $sale->tax_total,
            'discount_total' => (int) $sale->discount_total,
            'rounding_total' => (int) $sale->rounding_total,
            'total' => (int) $sale->total,
            'payments' => $sale->payments->sortBy('sequence')->values()->map(fn (SalePayment $payment) => [
                'label' => $payment->methodLabel(),
                'amount' => $payment->netAmount(),
            ])->all(),
            'cash_received' => $cash && $cash->change_amount > 0 ? (int) $cash->amount : null,
            'change' => $cash && $cash->change_amount > 0 ? (int) $cash->change_amount : null,
        ];
    }

    /**
     * Un tiquete de ejemplo para una empresa que todavia no ha vendido.
     *
     * @return array<string, mixed>
     */
    protected function example(): array
    {
        $items = [
            ['description' => 'Arroz 500 g', 'quantity' => '2', 'unit_price' => 3250, 'discount' => 0, 'line_total' => 6500],
            ['description' => 'Leche entera 1 L', 'quantity' => '1', 'unit_price' => 4100, 'discount' => 0, 'line_total' => 4100],
            ['description' => 'Huevos AA x12', 'quantity' => '1', 'unit_price' => 12900, 'discount' => 0, 'line_total' => 12900],
        ];

        return [
            'is_example' => true,
            'number' => 'POS-000123',
            'date' => Carbon::now('America/Bogota')->format('d/m/Y h:i a'),
            'cashier' => 'Ana',
            'seller' => null,
            'customer' => null,
            'items' => $items,
            'subtotal' => 23500,
            'tax_total' => 0,
            'discount_total' => 0,
            'rounding_total' => 0,
            'total' => 23500,
            'payments' => [['label' => 'Efectivo', 'amount' => 23500]],
            'cash_received' => 30000,
            'change' => 6500,
        ];
    }
}
