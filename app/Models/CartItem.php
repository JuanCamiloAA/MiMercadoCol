<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CartItem extends Model
{
    protected $fillable = ['cart_id', 'product_id', 'service_id', 'quantity', 'choices', 'substitution_policy'];

    protected function casts(): array
    {
        // `choices`: en un servicio, el subarticulo que el cliente eligio de cada articulo completo.
        return ['quantity' => 'float', 'choices' => 'array'];
    }

    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }
}
