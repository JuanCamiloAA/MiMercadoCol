<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SaleItem extends Model
{
    use HasFactory;

    /** El cajero uso menos de lo que dice el maestro. */
    public const CHANGE_REDUCED = 'reduced';

    /** Uso mas. */
    public const CHANGE_INCREASED = 'increased';

    /** Un articulo que el maestro no trae. */
    public const CHANGE_ADDED = 'added';

    /**
     * Lo que cuenta como vendido: todo menos los articulos de un servicio cerrado, que valen $0
     * y son consumo, no venta. Lo que cuenta unidades lo usa; lo que suma plata no lo necesita.
     */
    public const COUNTS_AS_SOLD_SQL = '(sale_items.parent_sale_item_id IS NULL OR sale_items.line_total <> 0)';

    protected $fillable = [
        'sale_id', 'product_id', 'service_id', 'parent_sale_item_id', 'description', 'quantity',
        'master_quantity', 'component_change', 'returnable_by_default', 'service_snapshot',
        'refunded_from_sale_item_id', 'restocked', 'unit_price', 'tax_rate', 'discount', 'line_total', 'unit_cost',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'float',
            'master_quantity' => 'float',
            'unit_price' => 'integer',
            'tax_rate' => 'float',
            'discount' => 'integer',
            'line_total' => 'integer',
            'unit_cost' => 'integer',
            'returnable_by_default' => 'boolean',
            'restocked' => 'boolean',
            'service_snapshot' => 'array',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class)->withTrashed();
    }

    /** En un componente: la linea del servicio. */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_sale_item_id');
    }

    /** En la linea de un servicio: lo que consumio. */
    public function components(): HasMany
    {
        return $this->hasMany(self::class, 'parent_sale_item_id')->orderBy('id');
    }

    public function isService(): bool
    {
        return $this->service_id !== null;
    }

    public function isComponent(): bool
    {
        return $this->parent_sale_item_id !== null;
    }

    /** Un componente que no es igual al maestro: menos, mas o agregado. */
    public function isAdjusted(): bool
    {
        return $this->component_change !== null;
    }
}
