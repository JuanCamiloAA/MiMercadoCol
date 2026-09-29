<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Una publicacion de la plantilla. El historial solo crece, como el de las tiendas. */
class StorefrontTemplateVersion extends Model
{
    use HasFactory;

    protected $fillable = ['template_id', 'number', 'snapshot', 'label', 'created_by_user_id', 'published_at'];

    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'snapshot' => 'array',
            'published_at' => 'datetime',
        ];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(StorefrontTemplate::class, 'template_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
