<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RegisterSession extends Model
{
    use BelongsToCompany, HasFactory;

    public const STATUS_OPEN = 'open';

    public const STATUS_CLOSED = 'closed';

    /** El descuadre todavia no lo ha revisado nadie. */
    public const DIFFERENCE_PENDING = 'pending';

    public const DIFFERENCE_RESOLVED = 'resolved';

    /** Se aclaro de donde salia: un vuelto mal dado, un conteo apurado. */
    public const RESOLUTION_JUSTIFIED = 'justified';

    /** La empresa la asume y la da por perdida. */
    public const RESOLUTION_ABSORBED = 'absorbed';

    /** Se le descuenta al cajero. */
    public const RESOLUTION_CHARGED = 'charged';

    /** El cajero repuso el faltante o devolvio el sobrante. */
    public const RESOLUTION_RECOVERED = 'recovered';

    /** @var array<string, string> */
    public const RESOLUTION_LABELS = [
        self::RESOLUTION_JUSTIFIED => 'Justificada',
        self::RESOLUTION_ABSORBED => 'La asume la empresa',
        self::RESOLUTION_CHARGED => 'Se le descuenta al cajero',
        self::RESOLUTION_RECOVERED => 'El cajero repuso la plata',
    ];

    protected $fillable = [
        'company_id', 'store_id', 'register_id', 'user_id', 'opened_at', 'opening_amount',
        'opening_breakdown', 'opening_note', 'closed_at', 'counted_amount', 'closing_breakdown',
        'expected_amount', 'difference', 'closing_note', 'status',
        'difference_status', 'resolution', 'resolution_note', 'resolved_by_user_id', 'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'opening_amount' => 'integer',
            'counted_amount' => 'integer',
            'expected_amount' => 'integer',
            'difference' => 'integer',
            'opening_breakdown' => 'array',
            'closing_breakdown' => 'array',
            'resolved_at' => 'datetime',
        ];
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_user_id');
    }

    /** Turnos cerrados que no cuadraron. */
    public function scopeWithDifference(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_CLOSED)
            ->whereNotNull('difference')
            ->where('difference', '!=', 0);
    }

    /** Descuadres que nadie ha revisado todavia. */
    public function scopePendingReview(Builder $query): Builder
    {
        return $query->withDifference()->where('difference_status', self::DIFFERENCE_PENDING);
    }

    public function resolutionLabel(): ?string
    {
        return $this->resolution ? (self::RESOLUTION_LABELS[$this->resolution] ?? $this->resolution) : null;
    }

    public function isResolved(): bool
    {
        return $this->difference_status === self::DIFFERENCE_RESOLVED;
    }

    public function register(): BelongsTo
    {
        return $this->belongsTo(Register::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }

    public function cashMovements(): HasMany
    {
        return $this->hasMany(CashMovement::class);
    }

    public function customerPayments(): HasMany
    {
        return $this->hasMany(CustomerPayment::class);
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_OPEN);
    }
}
