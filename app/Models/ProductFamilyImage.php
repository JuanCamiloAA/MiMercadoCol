<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una foto comun del articulo principal. Si esta marcada con un valor (un color), la tienda la
 * muestra al elegir ese valor.
 */
class ProductFamilyImage extends Model
{
    protected $fillable = ['product_family_id', 'path', 'thumb_path', 'attribute_value_id', 'sort_order'];

    public function family(): BelongsTo
    {
        return $this->belongsTo(ProductFamily::class, 'product_family_id');
    }

    public function value(): BelongsTo
    {
        return $this->belongsTo(ProductAttributeValue::class, 'attribute_value_id');
    }

    public function thumb(): string
    {
        return $this->thumb_path ?: $this->path;
    }
}
