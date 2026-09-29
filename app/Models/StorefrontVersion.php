<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una foto completa del sitio.
 *
 * El retrato es autosuficiente: trae tema, bloques, ajustes, zonas y franjas. Por eso volver a
 * una version anterior es copiarla, no reconstruirla, y por eso la tienda publica no necesita
 * consultar el borrador para nada.
 */
class StorefrontVersion extends Model
{
    use HasFactory;

    protected $fillable = ['storefront_id', 'number', 'label', 'snapshot', 'created_by_user_id', 'published_at'];

    protected function casts(): array
    {
        return ['number' => 'integer', 'snapshot' => 'array', 'published_at' => 'datetime'];
    }

    public function storefront(): BelongsTo
    {
        return $this->belongsTo(Storefront::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function isPublished(): bool
    {
        return $this->published_at !== null;
    }
}
