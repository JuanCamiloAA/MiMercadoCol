<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Un valor de una caracteristica: «8», «Azul», «900 ml».
 *
 * «M», «m» y « M » son el mismo valor: el `slug` los junta. Un valor que ya usa algun subarticulo
 * no se borra, se desactiva: los articulos que lo tienen lo conservan y los nuevos no lo ofrecen.
 */
class ProductAttributeValue extends Model
{
    protected $fillable = ['product_attribute_id', 'value', 'slug', 'amount', 'unit', 'color_hex', 'media_id', 'sort_order', 'is_active'];

    protected function casts(): array
    {
        return [
            'amount' => 'float',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function attribute(): BelongsTo
    {
        return $this->belongsTo(ProductAttribute::class, 'product_attribute_id');
    }

    public function media(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'media_id');
    }

    /** La llave que junta las formas de escribir el mismo valor. */
    public static function slugFor(string $value): string
    {
        return Str::limit(Str::slug(trim($value)) ?: Str::lower(trim($value)), 40, '');
    }

    /** Cuantos subarticulos lo usan, en una o en otra caracteristica. */
    public function usage(): int
    {
        return Product::query()->withoutGlobalScopes()
            ->where(fn ($query) => $query->where('variant_value_1_id', $this->id)->orWhere('variant_value_2_id', $this->id))
            ->count();
    }

    /** La cantidad en la unidad base de su medida (litros, kilos, unidades), para ordenar. */
    public function baseAmount(): ?float
    {
        if ($this->amount === null || $this->unit === null) {
            return null;
        }

        foreach (ProductAttribute::MEASURE_UNITS_MAP as $units) {
            if (isset($units[$this->unit])) {
                return $this->amount * $units[$this->unit];
            }
        }

        return null;
    }
}
