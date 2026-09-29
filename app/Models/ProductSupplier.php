<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Quien le surte que articulo a la empresa, y a como se lo dejo la ultima vez.
 *
 * El mismo arroz se le puede comprar a dos proveedores segun quien tenga y a como este. De ahi
 * que esto sea una tabla y no una columna en `products`.
 *
 * `last_cost` se actualiza al **recibir**, no al pedir: lo que se pidio es una intencion, lo
 * que se recibio es lo que de verdad costo.
 */
class ProductSupplier extends Model
{
    use BelongsToCompany, HasFactory;

    protected $fillable = [
        'company_id', 'product_id', 'supplier_id', 'supplier_sku',
        'last_cost', 'last_purchased_at', 'lead_time_days', 'is_primary',
    ];

    protected function casts(): array
    {
        return [
            'last_cost' => 'integer',
            'last_purchased_at' => 'datetime',
            'lead_time_days' => 'integer',
            'is_primary' => 'boolean',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }
}
