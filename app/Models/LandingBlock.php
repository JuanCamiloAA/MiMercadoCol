<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Un bloque de la landing de plataforma. `data` es el borrador que se edita; el publico solo ve
 * `published_data`, que se copia al publicar.
 */
class LandingBlock extends Model
{
    protected $fillable = [
        'uuid', 'type', 'position', 'is_visible', 'data', 'published_data', 'published_visible', 'published_position',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'is_visible' => 'boolean',
            'data' => 'array',
            'published_data' => 'array',
            'published_visible' => 'boolean',
            'published_position' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (LandingBlock $block) {
            $block->uuid ??= (string) Str::uuid();
        });
    }
}
