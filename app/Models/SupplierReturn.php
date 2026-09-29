<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Una devolucion de mercancia al proveedor, con orden de compra o suelta.
 *
 * El borrador no mueve inventario. Despachada, la mercancia ya salio y la devolucion queda
 * «pendiente de respuesta» hasta que lo respondido mas lo rechazado cubra el total; ahi la
 * cierra `syncSettlement()`, nunca un update suelto. Despachada no se anula: si la mercancia
 * vuelve, es un rechazo.
 */
class SupplierReturn extends Model
{
    use BelongsToCompany, HasFactory;

    public const STATUS_DRAFT = 'draft';

    /** La mercancia salio y se espera lo que responda el proveedor. */
    public const STATUS_DISPATCHED = 'dispatched';

    public const STATUS_CLOSED = 'closed';

    public const STATUS_CANCELLED = 'cancelled';

    /** @var array<string, string> */
    public const STATUS_LABELS = [
        self::STATUS_DRAFT => 'Borrador',
        self::STATUS_DISPATCHED => 'Pendiente de respuesta',
        self::STATUS_CLOSED => 'Cerrada',
        self::STATUS_CANCELLED => 'Anulada',
    ];

    protected $fillable = [
        'company_id', 'store_id', 'warehouse_id', 'supplier_id', 'purchase_order_id', 'number', 'status',
        'subtotal', 'tax_total', 'total', 'rejected_total', 'settled_total', 'note', 'shipping_reference',
        'created_by_user_id', 'edited_by_user_id', 'dispatched_by_user_id', 'cancelled_by_user_id',
        'edited_at', 'dispatched_at', 'closed_at', 'cancelled_at', 'cancel_reason',
    ];

    protected function casts(): array
    {
        return [
            'subtotal' => 'integer',
            'tax_total' => 'integer',
            'total' => 'integer',
            'rejected_total' => 'integer',
            'settled_total' => 'integer',
            'edited_at' => 'datetime',
            'dispatched_at' => 'datetime',
            'closed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(SupplierReturnItem::class);
    }

    public function settlements(): HasMany
    {
        return $this->hasMany(SupplierReturnSettlement::class)->orderBy('received_at')->orderBy('id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /** Las ordenes de compra de reposicion que salieron de esta devolucion. */
    public function replacementOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class, 'supplier_return_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'edited_by_user_id');
    }

    public function dispatcher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dispatched_by_user_id');
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by_user_id');
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isPendingResponse(): bool
    {
        return $this->status === self::STATUS_DISPATCHED;
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    /** Lo que el proveedor todavia debe: ni rechazado ni respondido. */
    public function pendingTotal(): int
    {
        if (! in_array($this->status, [self::STATUS_DISPATCHED, self::STATUS_CLOSED], true)) {
            return 0;
        }

        return max(0, (int) $this->total - (int) $this->rejected_total - (int) $this->settled_total);
    }

    /**
     * Recalcula lo respondido y decide si la devolucion queda cerrada.
     *
     * Cierra cuando respondido + rechazado cubre el total; si baja de ahi (una respuesta que se
     * borro), vuelve a quedar pendiente. Solo mira devoluciones despachadas o cerradas.
     */
    public function syncSettlement(): void
    {
        if (! in_array($this->status, [self::STATUS_DISPATCHED, self::STATUS_CLOSED], true)) {
            return;
        }

        $settled = (int) $this->settlements()->sum('amount');
        $covered = (int) $this->rejected_total + $settled >= (int) $this->total;

        $this->forceFill([
            'settled_total' => $settled,
            'status' => $covered ? self::STATUS_CLOSED : self::STATUS_DISPATCHED,
            'closed_at' => $covered ? ($this->closed_at ?? now()) : null,
        ])->save();
    }
}
