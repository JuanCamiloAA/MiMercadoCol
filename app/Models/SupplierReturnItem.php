<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierReturnItem extends Model
{
    /** @var array<string, string> */
    public const REASONS = [
        'expired' => 'Vencido',
        'near_expiry' => 'Próximo a vencer',
        'damaged' => 'Averiado o roto',
        'quality' => 'Defecto de calidad',
        'wrong_item' => 'No corresponde al pedido',
        'surplus' => 'Sobrante (llegó de más)',
        'recall' => 'Retiro del mercado',
        'agreement' => 'Acuerdo comercial o rotación',
        'other' => 'Otro (con nota)',
    ];

    public const REASON_OTHER = 'other';

    protected $fillable = [
        'supplier_return_id', 'product_id', 'purchase_order_item_id', 'quantity', 'rejected_quantity',
        'unit_cost', 'tax_rate', 'line_total', 'inventory_unit_cost', 'reason', 'note', 'lot_id', 'lots',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'float',
            'rejected_quantity' => 'float',
            'unit_cost' => 'integer',
            'tax_rate' => 'float',
            'line_total' => 'integer',
            'inventory_unit_cost' => 'integer',
            'lots' => 'array',
        ];
    }

    public function supplierReturn(): BelongsTo
    {
        return $this->belongsTo(SupplierReturn::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function purchaseOrderItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderItem::class);
    }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(ProductLot::class, 'lot_id');
    }

    /** Lo que de verdad se quedo el proveedor. */
    public function netQuantity(): float
    {
        return round(max(0, (float) $this->quantity - (float) $this->rejected_quantity), 3);
    }

    public function reasonLabel(): string
    {
        return self::REASONS[$this->reason] ?? $this->reason;
    }

    /**
     * Los motivos listos para pintar un selector.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function reasonOptions(): array
    {
        return array_values(array_map(
            fn (string $value, string $label) => ['value' => $value, 'label' => $label],
            array_keys(self::REASONS),
            self::REASONS,
        ));
    }
}
