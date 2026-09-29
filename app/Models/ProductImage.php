<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductImage extends Model
{
    protected $fillable = ['product_id', 'path', 'thumb_path', 'sort_order'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** La miniatura si existe; si no, la imagen completa. */
    public function thumb(): string
    {
        return $this->thumb_path ?: $this->path;
    }
}
