<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Lote de un articulo en una sede.
 *
 * Solo existe para los articulos con `track_lots`: carnes, lacteos y todo lo que vence. El
 * consumo es FIFO por fecha de vencimiento, que es como se despacha en una bodega real.
 */
class ProductLot extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'product_id', 'store_id', 'warehouse_id', 'purchase_order_item_id', 'code', 'expires_at', 'quantity', 'unit_cost', 'received_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'date',
            'received_at' => 'datetime',
            'quantity' => 'float',
            'unit_cost' => 'integer',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function scopeAvailable(Builder $query): Builder
    {
        return $query->where('quantity', '>', 0);
    }

    /** Lo que vence antes sale primero; lo que no vence, por orden de llegada. */
    public function scopeFifo(Builder $query): Builder
    {
        return $query->orderByRaw('expires_at IS NULL, expires_at ASC')->orderBy('received_at');
    }

    public function daysToExpiry(): ?int
    {
        return $this->expires_at ? (int) now()->startOfDay()->diffInDays($this->expires_at->startOfDay(), false) : null;
    }
}
