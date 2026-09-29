<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeliverySlot extends Model
{
    use HasFactory;

    /** @var array<int, string> */
    public const WEEKDAYS = [
        0 => 'Domingo',
        1 => 'Lunes',
        2 => 'Martes',
        3 => 'Miércoles',
        4 => 'Jueves',
        5 => 'Viernes',
        6 => 'Sábado',
    ];

    protected $fillable = ['storefront_id', 'weekday', 'starts_at', 'ends_at', 'capacity', 'is_active'];

    protected function casts(): array
    {
        return ['weekday' => 'integer', 'capacity' => 'integer', 'is_active' => 'boolean'];
    }

    public function storefront(): BelongsTo
    {
        return $this->belongsTo(Storefront::class);
    }

    public function weekdayLabel(): string
    {
        return self::WEEKDAYS[$this->weekday] ?? '';
    }

    /** «Lunes 8:00 a 12:00» */
    public function label(): string
    {
        return $this->weekdayLabel().' '.substr((string) $this->starts_at, 0, 5).' a '.substr((string) $this->ends_at, 0, 5);
    }
}
