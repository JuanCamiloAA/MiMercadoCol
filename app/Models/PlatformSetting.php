<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Un ajuste de la plataforma. Se lee y se escribe solo por `PlatformSettingsService`.
 */
class PlatformSetting extends Model
{
    protected $fillable = ['key', 'value'];

    protected function casts(): array
    {
        return ['value' => 'array'];
    }
}
