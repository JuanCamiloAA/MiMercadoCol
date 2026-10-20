<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * El precio especial de una empresa en un plan: un precio mensual fijo o un descuento.
 *
 * Es de ese plan: si la empresa cambia de plan no se arrastra. Lo aplica solo
 * `MembershipPricing::priceFor()`; un cobro ya generado conserva su monto.
 */
class CompanyPlanPrice extends Model
{
    protected $fillable = [
        'company_id', 'membership_plan_id', 'monthly_price', 'discount_percent', 'starts_at', 'ends_at',
        'reason', 'created_by_user_id', 'ended_by_user_id', 'expiry_notified_at', 'expiry_recorded_at',
    ];

    protected function casts(): array
    {
        return [
            'monthly_price' => 'integer',
            'discount_percent' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'expiry_notified_at' => 'datetime',
            'expiry_recorded_at' => 'datetime',
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

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function isActiveAt(CarbonInterface $moment): bool
    {
        return $this->starts_at !== null
            && $this->starts_at->lte($moment)
            && ($this->ends_at === null || $this->ends_at->gt($moment));
    }

    public function scopeActiveAt(Builder $query, CarbonInterface $moment): Builder
    {
        return $query->where('starts_at', '<=', $moment)
            ->where(fn (Builder $inner) => $inner->whereNull('ends_at')->orWhere('ends_at', '>', $moment));
    }

    /**
     * La base mensual con este precio: el fijo, o el del plan menos el descuento.
     */
    public function monthlyBase(MembershipPlan $plan): int
    {
        if ($this->monthly_price !== null) {
            return max(0, (int) $this->monthly_price);
        }

        $percent = min(90, max(0, (int) $this->discount_percent));

        return intdiv((int) $plan->monthly_price * (100 - $percent), 100);
    }

    /** «$ 150.000 al mes» o «20 % menos». */
    public function describe(): string
    {
        return $this->monthly_price !== null
            ? '$ '.number_format((int) $this->monthly_price, 0, ',', '.').' al mes'
            : ((int) $this->discount_percent).' % menos';
    }
}
