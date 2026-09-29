<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * El valor propio de una sede para un ajuste que admite excepcion. Lo lee y lo escribe
 * `SettingsService::forStore()` / `setForStore()`, nunca nadie mas.
 */
class StoreSettingOverride extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'store_id', 'key', 'value'];

    protected function casts(): array
    {
        return ['value' => 'array'];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
