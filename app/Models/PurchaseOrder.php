<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseOrder extends Model
{
    use BelongsToCompany, HasFactory;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_SENT = 'sent';

    /** Llego parte del pedido. Sigue abierta: lo que falta puede llegar despues. */
    public const STATUS_PARTIAL = 'partial';

    public const STATUS_RECEIVED = 'received';

    public const STATUS_CANCELLED = 'cancelled';

    /** @var array<string, string> */
    public const STATUS_LABELS = [
        self::STATUS_DRAFT => 'Borrador',
        self::STATUS_SENT => 'Enviada',
        self::STATUS_PARTIAL => 'Recibida en parte',
        self::STATUS_RECEIVED => 'Recibida',
        self::STATUS_CANCELLED => 'Anulada',
    ];

    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_SUGGESTED = 'suggested';

    /** Lo normal en una compra de verdad: unas sugeridas y otras que el comprador agrego. */
    public const SOURCE_MIXED = 'mixed';

    /** El proveedor repone lo que se le devolvio: ya estaba pagado, no lleva plazo. */
    public const SOURCE_REPLACEMENT = 'replacement';

    /** @var array<string, string> */
    public const SOURCE_LABELS = [
        self::SOURCE_MANUAL => 'Armada a mano',
        self::SOURCE_SUGGESTED => 'Desde las sugerencias',
        self::SOURCE_MIXED => 'Sugerencias y agregados',
        self::SOURCE_REPLACEMENT => 'Reposición de una devolución',
    ];

    protected $fillable = [
        'company_id', 'store_id', 'warehouse_id', 'supplier_id', 'payment_term_id', 'parent_order_id', 'supplier_return_id', 'code', 'status',
        'expected_at', 'received_at', 'due_at', 'subtotal', 'tax_total', 'total', 'suggested_total',
        'source', 'user_id', 'note',
    ];

    protected function casts(): array
    {
        return [
            'expected_at' => 'date',
            'received_at' => 'datetime',
            'due_at' => 'datetime',
            'subtotal' => 'integer',
            'tax_total' => 'integer',
            'total' => 'integer',
            'suggested_total' => 'integer',
        ];
    }

    public function paymentTerm(): BelongsTo
    {
        return $this->belongsTo(PaymentTerm::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** De que orden salio esta, cuando se separo una mezclada. */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_order_id');
    }

    /** Las ordenes que salieron de esta al separarla por proveedor. */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_order_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    /** Las devoluciones a proveedor hechas contra esta orden. */
    public function returns(): HasMany
    {
        return $this->hasMany(SupplierReturn::class);
    }

    /** En una orden de reposicion: la devolucion que la origino. */
    public function supplierReturn(): BelongsTo
    {
        return $this->belongsTo(SupplierReturn::class);
    }

    /**
     * Recalcula lo devuelto de cada renglon desde las devoluciones despachadas y cerradas.
     *
     * No se suma a mano: lo que cuenta es lo que el proveedor se quedo (devuelto menos
     * rechazado). `received_quantity` no se toca nunca: dice lo que llego, no lo que quedo.
     */
    public function syncReturns(): void
    {
        $returned = SupplierReturnItem::query()
            ->join('supplier_returns', 'supplier_returns.id', '=', 'supplier_return_items.supplier_return_id')
            ->where('supplier_returns.purchase_order_id', $this->id)
            ->whereIn('supplier_returns.status', [SupplierReturn::STATUS_DISPATCHED, SupplierReturn::STATUS_CLOSED])
            ->whereNotNull('supplier_return_items.purchase_order_item_id')
            ->groupBy('supplier_return_items.purchase_order_item_id')
            ->selectRaw('supplier_return_items.purchase_order_item_id as item_id, SUM(supplier_return_items.quantity - supplier_return_items.rejected_quantity) as net')
            ->pluck('net', 'item_id');

        foreach ($this->items()->get() as $item) {
            $net = round(max(0, (float) ($returned[$item->id] ?? 0)), 3);

            if (abs($net - (float) $item->returned_quantity) > 0.0005) {
                $item->forceFill(['returned_quantity' => $net])->save();
            }
        }
    }

    /** Si se le puede devolver algo: tiene mercancia adentro y queda algo por devolver. */
    public function canReturn(): bool
    {
        return in_array($this->status, [self::STATUS_PARTIAL, self::STATUS_RECEIVED], true)
            && $this->items->contains(fn (PurchaseOrderItem $item) => $item->returnableQuantity() > 0);
    }

    /** Si todavia se le puede recibir mercancia. */
    /**
     * El vencimiento: la fecha de recepcion total mas los dias del plazo. Sin recepcion total o
     * sin plazo, no vence. Se llama al recibir, al cerrar y al cambiar el plazo.
     */
    public function syncDueAt(): void
    {
        // La reposicion ya estaba pagada con la compra original.
        $term = $this->payment_term_id && $this->source !== self::SOURCE_REPLACEMENT
            ? PaymentTerm::query()->withoutGlobalScopes()->find($this->payment_term_id)
            : null;

        $this->forceFill([
            'due_at' => $this->received_at && $term ? $this->received_at->copy()->addDays($term->days) : null,
        ])->save();
    }

    public function isOverdue(): bool
    {
        return $this->due_at !== null && $this->due_at->isPast();
    }

    public function canReceive(): bool
    {
        return in_array($this->status, [self::STATUS_DRAFT, self::STATUS_SENT, self::STATUS_PARTIAL], true);
    }

    /** Cuanto falta por llegar, sumando todos los renglones. */
    public function pendingQuantity(): float
    {
        return $this->items->sum(fn (PurchaseOrderItem $item) => $item->pendingQuantity());
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }
}
