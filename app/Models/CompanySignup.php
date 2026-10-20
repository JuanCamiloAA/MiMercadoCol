<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una contratacion en linea con pago: se guarda antes de ir a la pasarela y la empresa nace
 * cuando el webhook confirma el pago. Idempotente por `reference` (SIG-...).
 */
class CompanySignup extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PAID = 'paid';

    public const STATUS_FAILED = 'failed';

    public const STATUS_EXPIRED = 'expired';

    public const REFERENCE_PREFIX = 'SIG-';

    /** Cuanto espera una contratacion sin pagar. */
    public const TTL_HOURS = 2;

    protected $fillable = [
        'reference', 'membership_plan_id', 'billing_cycle_id', 'amount', 'currency',
        'company_name', 'tax_id', 'city', 'business_category_id', 'company_phone', 'company_email',
        'admin_name', 'admin_last_name', 'admin_email', 'admin_document', 'admin_password_hash',
        'estimated_stores', 'message', 'accepted_terms_at', 'status', 'transaction_id', 'failure_reason',
        'paid_at', 'expires_at', 'company_id',
    ];

    protected $hidden = ['admin_password_hash'];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'estimated_stores' => 'integer',
            'accepted_terms_at' => 'datetime',
            'paid_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /** Alimentos, textiles, belleza…: con ella la landing agrupa las tiendas. */
    public function businessCategory(): BelongsTo
    {
        return $this->belongsTo(BusinessCategory::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(MembershipPlan::class, 'membership_plan_id');
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(BillingCycle::class, 'billing_cycle_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
