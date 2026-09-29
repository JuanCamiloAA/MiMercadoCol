<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una publicacion de la landing: el retrato completo de lo que se publico, para restaurarlo.
 */
class LandingVersion extends Model
{
    protected $fillable = ['number', 'snapshot', 'note', 'published_by', 'published_at'];

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'published_at' => 'datetime', 'number' => 'integer'];
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }
}
