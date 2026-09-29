<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockTransferRequestItem extends Model
{
    protected $fillable = ['stock_transfer_request_id', 'product_id', 'quantity', 'sent_quantity'];

    protected function casts(): array
    {
        return [
            'quantity' => 'float',
            'sent_quantity' => 'float',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(StockTransferRequest::class, 'stock_transfer_request_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** Lo que se pidio y todavia no va en ningun traslado. */
    public function pendingQuantity(): float
    {
        return round(max(0, $this->quantity - $this->sent_quantity), 3);
    }
}
