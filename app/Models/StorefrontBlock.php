<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un bloque del borrador.
 *
 * Lo que hay aqui es lo que el editor esta armando. El cliente no lo ve hasta que alguien
 * publique: lo suyo sale del retrato de la version publicada.
 */
class StorefrontBlock extends Model
{
    use HasFactory;

    public const DEVICE_ALL = 'all';

    public const DEVICE_MOBILE = 'mobile';

    public const DEVICE_DESKTOP = 'desktop';

    /** @var list<string> */
    public const DEVICES = [self::DEVICE_ALL, self::DEVICE_MOBILE, self::DEVICE_DESKTOP];

    /**
     * Las paginas de la tienda que llevan bloques. Los de categoria y busqueda van arriba de los
     * resultados; los de producto, debajo de la ficha; los del carrito, debajo del resumen.
     */
    public const PAGE_HOME = 'home';

    public const PAGE_CATEGORY = 'category';

    public const PAGE_PRODUCT = 'product';

    public const PAGE_CART = 'cart';

    public const PAGE_SEARCH = 'search';

    /** @var list<string> */
    public const PAGES = [self::PAGE_HOME, self::PAGE_CATEGORY, self::PAGE_PRODUCT, self::PAGE_CART, self::PAGE_SEARCH];

    /** @var array<string, string> */
    public const PAGE_LABELS = [
        self::PAGE_HOME => 'Portada',
        self::PAGE_CATEGORY => 'Categoría',
        self::PAGE_PRODUCT => 'Producto',
        self::PAGE_CART => 'Carrito',
        self::PAGE_SEARCH => 'Búsqueda',
    ];

    protected $fillable = [
        'storefront_id', 'uuid', 'type', 'page', 'sort_order', 'is_visible',
        'visible_from', 'visible_to', 'devices', 'payload',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_visible' => 'boolean',
            'visible_from' => 'datetime',
            'visible_to' => 'datetime',
            'payload' => 'array',
        ];
    }

    public function storefront(): BelongsTo
    {
        return $this->belongsTo(Storefront::class);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }

    /** Tiene ventana de fechas, se muestre hoy o no. */
    public function isScheduled(): bool
    {
        return $this->visible_from !== null || $this->visible_to !== null;
    }

    /** Si hoy le toca estar en la tienda. */
    public function isLive(?\DateTimeInterface $at = null): bool
    {
        if (! $this->is_visible) {
            return false;
        }

        $at ??= now();

        if ($this->visible_from && $this->visible_from->gt($at)) {
            return false;
        }

        return ! ($this->visible_to && $this->visible_to->lt($at));
    }
}
