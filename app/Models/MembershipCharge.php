<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un intento de cobro de la membresia.
 *
 * Se crea `pending` ANTES de llamar a la pasarela: al reves, un cobro exitoso seguido de una
 * caida dejaria a la empresa cobrada sin rastro. La `reference` (MEM-...) es unica y es lo que
 * hace idempotente la liquidacion: un webhook repetido no renueva dos veces.
 */
class MembershipCharge extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_DECLINED = 'declined';

    public const STATUS_ERROR = 'error';

    public const STATUS_VOIDED = 'voided';

    public const STATUS_LABELS = [
        self::STATUS_PENDING => 'Pendiente',
        self::STATUS_APPROVED => 'Aprobado',
        self::STATUS_DECLINED => 'Rechazado',
        self::STATUS_ERROR => 'Con error',
        self::STATUS_VOIDED => 'Anulado',
    ];

    public const METHOD_SAVED_CARD = 'saved_card';

    public const METHOD_CHECKOUT = 'checkout';

    public const METHOD_MANUAL = 'manual';

    public const METHOD_LABELS = [
        self::METHOD_SAVED_CARD => 'Tarjeta guardada',
        self::METHOD_CHECKOUT => 'Pago en línea',
        self::METHOD_MANUAL => 'Registro manual',
    ];

    public const REFERENCE_PREFIX = 'MEM-';

    protected $fillable = [
        'company_id', 'membership_plan_id', 'billing_cycle_id', 'amount', 'currency', 'concept',
        'period_starts_at', 'period_ends_at', 'status', 'method', 'reference', 'gateway_transaction_id',
        'attempts', 'failure_reason', 'charged_at', 'paid_at', 'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'attempts' => 'integer',
            'period_starts_at' => 'datetime',
            'period_ends_at' => 'datetime',
            'charged_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(MembershipPlan::class, 'membership_plan_id');
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(BillingCycle::class, 'billing_cycle_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function isFinal(): bool
    {
        return $this->status !== self::STATUS_PENDING;
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }
}
