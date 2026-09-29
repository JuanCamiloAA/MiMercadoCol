<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Una bodega le pide mercancia a otra.
 *
 * No mueve inventario. Lo que mueve es el traslado que la bodega que surte genera a partir de
 * ella, y puede generar varios: manda hoy lo que tiene y el resto cuando le llegue. Mientras
 * falte algo la solicitud queda abierta; si la bodega que surte decide no mandar el resto, la
 * cierra y dice por que.
 */
class StockTransferRequest extends Model
{
    use BelongsToCompany;

    public const STATUS_PENDING = 'pending';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_FULFILLED = 'fulfilled';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CLOSED = 'closed';

    public const STATUS_CANCELLED = 'cancelled';

    /** @var array<string, string> */
    public const STATUS_LABELS = [
        self::STATUS_PENDING => 'Pendiente',
        self::STATUS_PARTIAL => 'Atendida en parte',
        self::STATUS_FULFILLED => 'Atendida',
        self::STATUS_REJECTED => 'Rechazada',
        self::STATUS_CLOSED => 'Cerrada',
        self::STATUS_CANCELLED => 'Anulada',
    ];

    /** Los estados en que todavia se le puede generar un traslado. */
    public const OPEN_STATUSES = [self::STATUS_PENDING, self::STATUS_PARTIAL];

    protected $fillable = [
        'company_id', 'number', 'from_warehouse_id', 'to_warehouse_id', 'status', 'note', 'response_note',
        'created_by_user_id', 'responded_by_user_id', 'responded_at', 'cancelled_by_user_id', 'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'responded_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(StockTransferRequestItem::class);
    }

    public function transfers(): HasMany
    {
        return $this->hasMany(StockTransfer::class);
    }

    public function origin(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'from_warehouse_id');
    }

    public function destination(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'to_warehouse_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function responder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responded_by_user_id');
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isPartial(): bool
    {
        return $this->status === self::STATUS_PARTIAL;
    }

    /**
     * Recalcula cuanto va en traslados vigentes y, si sigue abierta, en que estado queda.
     *
     * Se suma desde los traslados y no se lleva un contador aparte: un traslado generado que
     * se anula devuelve sus unidades a pendiente sin que nadie tenga que acordarse de restarlas.
     * Una solicitud rechazada, cerrada o anulada conserva su estado: ya se decidio sobre ella.
     */
    public function syncProgress(): void
    {
        $sent = StockTransferItem::query()
            ->join('stock_transfers', 'stock_transfers.id', '=', 'stock_transfer_items.stock_transfer_id')
            ->where('stock_transfers.stock_transfer_request_id', $this->id)
            ->where('stock_transfers.status', '!=', StockTransfer::STATUS_CANCELLED)
            ->groupBy('stock_transfer_items.product_id')
            ->selectRaw('stock_transfer_items.product_id, SUM(stock_transfer_items.quantity) as quantity')
            ->pluck('quantity', 'product_id');

        $this->loadMissing('items');

        foreach ($this->items as $item) {
            $item->update(['sent_quantity' => round((float) ($sent[$item->product_id] ?? 0), 3)]);
        }

        if (! in_array($this->status, [...self::OPEN_STATUSES, self::STATUS_FULFILLED], true)) {
            return;
        }

        $complete = $this->items->every(fn (StockTransferRequestItem $item) => $item->pendingQuantity() <= 0);
        $started = $this->items->contains(fn (StockTransferRequestItem $item) => $item->sent_quantity > 0);

        $this->update([
            'status' => match (true) {
                $complete => self::STATUS_FULFILLED,
                $started => self::STATUS_PARTIAL,
                default => self::STATUS_PENDING,
            },
        ]);
    }
}
