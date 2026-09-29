<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un traslado de mercancia entre dos bodegas.
 *
 * Son dos momentos, cada uno de su bodega: la que surte despacha y la que pide recibe. En el
 * medio la mercancia esta en camino: no cuenta en ninguna bodega, pero el documento dice
 * cuanto es. El traslado directo junta los dos actos, y solo existe dentro de una sede y con
 * su propio permiso.
 *
 * Puede venir de una solicitud (`stock_transfer_request_id`) o armarse a mano.
 */
class StockTransfer extends Model
{
    use BelongsToCompany, HasFactory;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_IN_TRANSIT = 'in_transit';

    public const STATUS_RECEIVED = 'received';

    public const STATUS_CANCELLED = 'cancelled';

    /** @var array<string, string> */
    public const STATUS_LABELS = [
        self::STATUS_DRAFT => 'Por despachar',
        self::STATUS_IN_TRANSIT => 'En camino',
        self::STATUS_RECEIVED => 'Recibido',
        self::STATUS_CANCELLED => 'Anulado',
    ];

    protected $fillable = [
        'company_id', 'stock_transfer_request_id', 'number', 'from_warehouse_id', 'to_warehouse_id', 'status', 'note',
        'created_by_user_id', 'dispatched_by_user_id', 'dispatched_at',
        'received_by_user_id', 'received_at', 'cancelled_by_user_id', 'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'dispatched_at' => 'datetime',
            'received_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(StockTransferItem::class);
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(StockTransferRequest::class, 'stock_transfer_request_id');
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

    public function dispatcher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dispatched_by_user_id');
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by_user_id');
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isInTransit(): bool
    {
        return $this->status === self::STATUS_IN_TRANSIT;
    }

    /** Entre bodegas de la misma sede: la unica ruta donde se permite el traslado directo. */
    public function isInternal(): bool
    {
        return (int) $this->origin?->store_id === (int) $this->destination?->store_id;
    }

    /** Lo que vale lo que va en camino, al costo con que salio. */
    public function transitValue(): int
    {
        return (int) $this->items->sum(fn (StockTransferItem $item) => (int) round($item->quantity * $item->unit_cost));
    }
}
