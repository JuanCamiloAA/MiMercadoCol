<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductStock extends Model
{
    use BelongsToCompany, HasFactory;

    protected $fillable = ['company_id', 'product_id', 'store_id', 'warehouse_id', 'quantity', 'reserved_quantity', 'location'];

    protected function casts(): array
    {
        return [
            'quantity' => 'float',
            'reserved_quantity' => 'float',
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

    public function getAvailableAttribute(): float
    {
        return round($this->quantity - $this->reserved_quantity, 3);
    }
}
