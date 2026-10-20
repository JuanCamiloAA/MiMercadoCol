<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un renglon de la devolucion, colgado de su linea de la venta original.
 *
 * `disposition` dice a donde fue: al inventario para volver a venderse, a averia (entra y se da de
 * baja, asi la perdida sale en los informes) o a ningun lado (un articulo de un servicio que ya se
 * consumio). Se decide al recibir; antes es nula.
 */
class CustomerReturnItem extends Model
{
    /** @var array<string, string> */
    public const REASONS = [
        'damaged' => 'Dañado o roto',
        'expired' => 'Vencido o en mal estado',
        'wrong_item' => 'No es lo que pidió',
        // Lo primero que se devuelve en una tienda de ropa: vuelve al inventario, no es averia.
        'wrong_size' => 'La talla o la medida no le quedó',
        'quality' => 'No cumple lo esperado',
        'changed_mind' => 'Ya no lo quiere',
        'other' => 'Otro',
    ];

    /** Motivos que por defecto mandan el articulo a averia: no se puede volver a vender. */
    public const WASTE_REASONS = ['damaged', 'expired'];

    public const DISPOSITION_RESTOCK = 'restock';

    public const DISPOSITION_WASTE = 'waste';

    public const DISPOSITION_NONE = 'none';

    /** @var array<string, string> */
    public const DISPOSITION_LABELS = [
        self::DISPOSITION_RESTOCK => 'Vuelve al inventario',
        self::DISPOSITION_WASTE => 'Avería',
        self::DISPOSITION_NONE => 'No se recibe (consumido)',
    ];

    protected $fillable = [
        'customer_return_id', 'sale_item_id', 'parent_item_id', 'product_id', 'service_id', 'description',
        'quantity', 'unit_price', 'line_total', 'tax_rate', 'unit_cost', 'reason', 'disposition', 'warehouse_id',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'float',
            'unit_price' => 'integer',
            'line_total' => 'integer',
            'tax_rate' => 'float',
            'unit_cost' => 'integer',
        ];
    }

    public function customerReturn(): BelongsTo
    {
        return $this->belongsTo(CustomerReturn::class);
    }

    public function saleItem(): BelongsTo
    {
        return $this->belongsTo(SaleItem::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_item_id');
    }

    public function components(): HasMany
    {
        return $this->hasMany(self::class, 'parent_item_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function reasonLabel(): string
    {
        return self::REASONS[$this->reason] ?? $this->reason;
    }

    public function dispositionLabel(): ?string
    {
        return $this->disposition ? (self::DISPOSITION_LABELS[$this->disposition] ?? $this->disposition) : null;
    }

    /** Lo que se propone al recibir, segun el motivo. */
    public static function defaultDisposition(string $reason): string
    {
        return in_array($reason, self::WASTE_REASONS, true) ? self::DISPOSITION_WASTE : self::DISPOSITION_RESTOCK;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function reasonOptions(): array
    {
        return array_values(array_map(fn (string $value, string $label) => ['value' => $value, 'label' => $label], array_keys(self::REASONS), self::REASONS));
    }
}
