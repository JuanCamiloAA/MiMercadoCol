<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrderItem extends Model
{
    use HasFactory;

    public const POLICY_REPLACE = 'replace';

    public const POLICY_CALL = 'call';

    public const POLICY_REMOVE = 'remove';

    /** @var array<string, string> */
    public const POLICY_LABELS = [
        self::POLICY_REPLACE => 'Reemplazar por otro similar',
        self::POLICY_CALL => 'Llamarme antes',
        self::POLICY_REMOVE => 'Quitar del pedido',
    ];

    protected $fillable = [
        'order_id', 'product_id', 'service_id', 'parent_order_item_id', 'quantity', 'unit_price', 'line_total',
        'substitution_policy', 'picked_quantity', 'pick_note', 'service_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'float',
            'picked_quantity' => 'float',
            'unit_price' => 'integer',
            'line_total' => 'integer',
            'service_snapshot' => 'array',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class)->withTrashed();
    }

    /** En un componente: el renglon del servicio. */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_order_item_id');
    }

    /** En el renglon de un servicio: los articulos que consume. */
    public function components(): HasMany
    {
        return $this->hasMany(self::class, 'parent_order_item_id')->orderBy('id');
    }

    public function isService(): bool
    {
        return $this->service_id !== null;
    }

    public function isComponent(): bool
    {
        return $this->parent_order_item_id !== null;
    }
}
