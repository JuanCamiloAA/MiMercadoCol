<?php

namespace App\Models;

use App\Services\Membership\EntitlementService;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una excepcion al plan de una empresa: suma o quita un nodo del arbol, con vigencia.
 *
 * No se borra: terminarla antes de tiempo es ponerle `ends_at = now()`. El vencimiento se
 * resuelve al leer (`EntitlementService`), sin depender de que corra ningun comando.
 */
class CompanyEntitlement extends Model
{
    public const EFFECT_GRANT = 'grant';

    public const EFFECT_REVOKE = 'revoke';

    public const EFFECTS = [self::EFFECT_GRANT, self::EFFECT_REVOKE];

    public const EFFECT_LABELS = [
        self::EFFECT_GRANT => 'Suma',
        self::EFFECT_REVOKE => 'Quita',
    ];

    protected $fillable = [
        'company_id', 'node', 'effect', 'starts_at', 'ends_at', 'reason',
        'created_by_user_id', 'ended_by_user_id', 'expiry_notified_at', 'expiry_recorded_at',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'expiry_notified_at' => 'datetime',
            'expiry_recorded_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        $forget = fn (CompanyEntitlement $entitlement) => app(EntitlementService::class)->forget((int) $entitlement->company_id);

        static::saved($forget);
        static::deleted($forget);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function endedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ended_by_user_id');
    }

    /** Vigente en ese instante: ya empezo y no ha vencido. */
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

    /** Las que todavia no vencen: vigentes y programadas. */
    public function scopeNotExpired(Builder $query, CarbonInterface $moment): Builder
    {
        return $query->where(fn (Builder $inner) => $inner->whereNull('ends_at')->orWhere('ends_at', '>', $moment));
    }
}
