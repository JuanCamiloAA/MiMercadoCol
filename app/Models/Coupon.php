<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Coupon extends Model
{
    use BelongsToCompany, HasFactory;

    public const TYPE_PERCENT = 'percent';

    public const TYPE_AMOUNT = 'amount';

    public const TYPE_FREE_DELIVERY = 'free_delivery';

    /** @var array<string, string> */
    public const TYPE_LABELS = [
        self::TYPE_PERCENT => 'Porcentaje',
        self::TYPE_AMOUNT => 'Valor fijo',
        self::TYPE_FREE_DELIVERY => 'Domicilio gratis',
    ];

    /** @var array<string, string> */
    public const SCOPE_LABELS = [
        'all' => 'Todo el pedido',
        'category' => 'Solo unas categorías',
        'product' => 'Solo unos artículos',
    ];

    protected $fillable = [
        'company_id', 'storefront_id', 'code', 'type', 'value', 'min_order_total', 'max_discount',
        'scope', 'scope_ids', 'starts_at', 'ends_at', 'usage_limit', 'usage_limit_per_customer',
        'first_order_only', 'is_active', 'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'integer',
            'min_order_total' => 'integer',
            'max_discount' => 'integer',
            'scope_ids' => 'array',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'usage_limit' => 'integer',
            'usage_limit_per_customer' => 'integer',
            'first_order_only' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(CouponRedemption::class);
    }

    public function storefront(): BelongsTo
    {
        return $this->belongsTo(Storefront::class);
    }

    public function scopeRunning(Builder $query, ?\DateTimeInterface $at = null): Builder
    {
        $at ??= now();

        return $query->where('is_active', true)
            ->where(fn ($inner) => $inner->whereNull('starts_at')->orWhere('starts_at', '<=', $at))
            ->where(fn ($inner) => $inner->whereNull('ends_at')->orWhere('ends_at', '>=', $at));
    }

    public function typeLabel(): string
    {
        return self::TYPE_LABELS[$this->type] ?? $this->type;
    }

    /** Activo, Programado, Vencido, Agotado o Apagado. */
    public function status(): string
    {
        if (! $this->is_active) {
            return 'Apagado';
        }

        if ($this->starts_at && $this->starts_at->isFuture()) {
            return 'Programado';
        }

        if ($this->ends_at && $this->ends_at->isPast()) {
            return 'Vencido';
        }

        if ($this->usage_limit !== null && $this->redemptions()->count() >= $this->usage_limit) {
            return 'Agotado';
        }

        return 'Activo';
    }
}
