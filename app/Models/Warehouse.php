<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Una bodega dentro de una sede.
 *
 * La sede sigue siendo la unidad de negocio; la bodega es donde esta fisicamente la mercancia.
 * Una tienda tipica tiene dos: el piso de venta y el deposito de atras.
 *
 * El `type` es informativo y no decide nada. Lo que decide son las banderas: cual descuenta la
 * caja, cual respalda la tienda en linea y donde entra una compra. De cada una hay exactamente
 * una encendida por sede, y quien lo hace cumplir es `WarehouseService`, no la base.
 */
class Warehouse extends Model
{
    use BelongsToCompany, HasFactory;

    public const TYPE_SALES_FLOOR = 'sales_floor';

    public const TYPE_STORAGE = 'storage';

    /** @var array<string, string> */
    public const TYPE_LABELS = [
        self::TYPE_SALES_FLOOR => 'Piso de venta',
        self::TYPE_STORAGE => 'Almacenaje',
    ];

    /** Las banderas de las que hay una sola encendida por sede. */
    public const FLAG_POS = 'is_pos_source';

    public const FLAG_ONLINE = 'is_online_source';

    public const FLAG_RECEIVING = 'is_receiving_default';

    /** @var array<string, string> */
    public const FLAG_LABELS = [
        self::FLAG_POS => 'Vende en caja',
        self::FLAG_ONLINE => 'Vende en la tienda en linea',
        self::FLAG_RECEIVING => 'Recibe las compras',
    ];

    protected $fillable = [
        'company_id', 'store_id', 'name', 'code', 'type',
        'is_pos_source', 'is_online_source', 'is_receiving_default', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_pos_source' => 'boolean',
            'is_online_source' => 'boolean',
            'is_receiving_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function productStocks(): HasMany
    {
        return $this->hasMany(ProductStock::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function typeLabel(): string
    {
        return self::TYPE_LABELS[$this->type] ?? $this->type;
    }

    /** Las banderas encendidas, para explicar en pantalla por que no se puede desactivar. */
    public function flags(): array
    {
        return array_values(array_filter(
            array_keys(self::FLAG_LABELS),
            fn (string $flag) => (bool) $this->{$flag},
        ));
    }

    public function hasAnyFlag(): bool
    {
        return $this->flags() !== [];
    }

    /** El nombre como se lee cuando hay varias sedes en la misma lista. */
    public function fullName(): string
    {
        return $this->store?->name ? "{$this->store->name} · {$this->name}" : $this->name;
    }
}
