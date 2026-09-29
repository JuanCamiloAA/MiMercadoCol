<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseOrderItem extends Model
{
    use HasFactory;

    public const ORIGIN_SUGGESTION = 'suggestion';

    public const ORIGIN_SEARCH = 'search';

    public const ORIGIN_SCAN = 'scan';

    public const ORIGIN_MANUAL = 'manual';

    /** @var list<string> */
    public const ORIGINS = [self::ORIGIN_SUGGESTION, self::ORIGIN_SEARCH, self::ORIGIN_SCAN, self::ORIGIN_MANUAL];

    protected $fillable = [
        'purchase_order_id', 'product_id', 'supplier_id', 'is_off_supplier', 'quantity',
        'suggested_quantity', 'stock_at_order', 'min_at_order', 'origin',
        'received_quantity', 'returned_quantity', 'unit_cost', 'expires_at', 'lot_code',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'float',
            'suggested_quantity' => 'float',
            'stock_at_order' => 'float',
            'min_at_order' => 'float',
            'is_off_supplier' => 'boolean',
            'received_quantity' => 'float',
            'returned_quantity' => 'float',
            'unit_cost' => 'integer',
            'expires_at' => 'date',
        ];
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    /**
     * Cuanto falta por llegar de este renglon.
     *
     * Nunca negativo: si el proveedor mando de mas, no queda nada pendiente.
     */
    public function pendingQuantity(): float
    {
        return max(0.0, (float) $this->quantity - (float) $this->received_quantity);
    }

    /**
     * Cuanto se le puede devolver al proveedor de este renglon: lo recibido menos lo que ya se
     * quedo en devoluciones despachadas. Los borradores los descuenta quien arma la devolucion.
     */
    public function returnableQuantity(): float
    {
        return round(max(0.0, (float) $this->received_quantity - (float) $this->returned_quantity), 3);
    }

    /** El proveedor de este renglon, que no siempre es el de la orden. */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
