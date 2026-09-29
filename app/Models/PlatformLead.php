<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una solicitud de contratacion: «Quiero que me contacten» o el formulario de contacto de la
 * landing. La gestiona el super usuario hasta ganarla (se crea la empresa) o perderla.
 */
class PlatformLead extends Model
{
    public const STATUS_NEW = 'new';

    public const STATUS_CONTACTED = 'contacted';

    public const STATUS_WON = 'won';

    public const STATUS_LOST = 'lost';

    public const STATUSES = [self::STATUS_NEW, self::STATUS_CONTACTED, self::STATUS_WON, self::STATUS_LOST];

    public const STATUS_LABELS = [
        self::STATUS_NEW => 'Nueva',
        self::STATUS_CONTACTED => 'Contactada',
        self::STATUS_WON => 'Ganada',
        self::STATUS_LOST => 'Perdida',
    ];

    public const SOURCE_SIGNUP = 'signup';

    public const SOURCE_CONTACT = 'contact';

    public const SOURCE_LABELS = [
        self::SOURCE_SIGNUP => 'Contratar',
        self::SOURCE_CONTACT => 'Contacto',
    ];

    protected $fillable = [
        'source', 'company_name', 'tax_id', 'city', 'contact_name', 'email', 'phone', 'estimated_stores',
        'membership_plan_id', 'billing_cycle_id', 'message', 'status', 'notes', 'handled_by_user_id', 'company_id',
    ];

    protected function casts(): array
    {
        return ['estimated_stores' => 'integer'];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(MembershipPlan::class, 'membership_plan_id');
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(BillingCycle::class, 'billing_cycle_id');
    }

    public function handledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by_user_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
