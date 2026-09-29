<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockTransferItem extends Model
{
    protected $fillable = ['stock_transfer_id', 'product_id', 'quantity', 'received_quantity', 'unit_cost', 'lots'];

    protected function casts(): array
    {
        return [
            'quantity' => 'float',
            'received_quantity' => 'float',
            'unit_cost' => 'integer',
            'lots' => 'array',
        ];
    }

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(StockTransfer::class, 'stock_transfer_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** Lo que se despacho y no llego. Solo significa algo despues de recibir. */
    public function missingQuantity(): float
    {
        return round(max(0, $this->quantity - $this->received_quantity), 3);
    }
}
