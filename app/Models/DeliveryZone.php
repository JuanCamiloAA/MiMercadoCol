<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeliveryZone extends Model
{
    use HasFactory;

    protected $fillable = ['storefront_id', 'name', 'fee', 'eta_minutes', 'is_active', 'geometry', 'sort_order'];

    protected function casts(): array
    {
        return [
            'fee' => 'integer',
            'eta_minutes' => 'integer',
            'is_active' => 'boolean',
            'geometry' => 'array',
            'sort_order' => 'integer',
        ];
    }

    public function storefront(): BelongsTo
    {
        return $this->belongsTo(Storefront::class);
    }
}
