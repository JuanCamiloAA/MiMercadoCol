<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una foto de un servicio. La primera es la portada, la misma que queda en `services.image_path`.
 */
class ServiceImage extends Model
{
    protected $fillable = ['service_id', 'path', 'thumb_path', 'sort_order'];

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function thumb(): string
    {
        return $this->thumb_path ?: $this->path;
    }
}
