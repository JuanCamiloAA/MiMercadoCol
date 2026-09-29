<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un renglon de la bitacora de membresia de una empresa. Solo se agrega, nunca se edita.
 */
class CompanyMembershipEvent extends Model
{
    public const UPDATED_AT = null;

    public const PROVISIONED = 'provisioned';

    public const PLAN_CHANGED = 'plan_changed';

    public const CYCLE_CHANGED = 'cycle_changed';

    public const CHANGE_SCHEDULED = 'change_scheduled';

    public const RENEWED = 'renewed';

    public const GRACE_STARTED = 'grace_started';

    public const SUSPENDED = 'suspended';

    public const REACTIVATED = 'reactivated';

    public const MANUAL_EXTENSION = 'manual_extension';

    public const MANUAL_UPDATE = 'manual_update';

    public const CARD_UPDATED = 'card_updated';

    public const AUTO_RENEW_TOGGLED = 'auto_renew_toggled';

    public const CHARGE_FAILED = 'charge_failed';

    public const DEACTIVATED = 'deactivated';

    public const ACTIVATED = 'activated';

    public const CANCELLED = 'cancelled';

    public const REMINDER_SENT = 'reminder_sent';

    public const CARD_EXPIRING = 'card_expiring';

    public const LABELS = [
        self::PROVISIONED => 'Empresa creada',
        self::PLAN_CHANGED => 'Cambio de plan',
        self::CYCLE_CHANGED => 'Cambio de ciclo',
        self::CHANGE_SCHEDULED => 'Cambio programado',
        self::RENEWED => 'Renovación',
        self::GRACE_STARTED => 'Entró en gracia',
        self::SUSPENDED => 'Suspendida',
        self::REACTIVATED => 'Reactivada',
        self::MANUAL_EXTENSION => 'Extensión manual',
        self::MANUAL_UPDATE => 'Ajuste manual',
        self::CARD_UPDATED => 'Tarjeta actualizada',
        self::AUTO_RENEW_TOGGLED => 'Renovación automática',
        self::CHARGE_FAILED => 'Cobro fallido',
        self::DEACTIVATED => 'Desactivada',
        self::ACTIVATED => 'Activada',
        self::CANCELLED => 'Cancelada',
        self::REMINDER_SENT => 'Recordatorio enviado',
        self::CARD_EXPIRING => 'Aviso de tarjeta por vencer',
    ];

    protected $fillable = ['company_id', 'type', 'data', 'user_id', 'created_at'];

    protected function casts(): array
    {
        return ['data' => 'array', 'created_at' => 'datetime'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
