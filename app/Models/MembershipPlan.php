<?php

namespace App\Models;

use App\Services\Membership\EntitlementService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un plan de membresia. El precio guardado es el mensual en COP; el de cada ciclo lo calcula
 * `MembershipPricing::priceFor()`.
 *
 * Lo que incluye son nodos del arbol de permisos (`entitlements`, ver `Entitlements`): es un
 * techo, y quien responde que tiene una empresa es `EntitlementService`, no este modelo.
 */
class MembershipPlan extends Model
{
    /** Cuantos destacados se muestran en la landing y en «Contratar». */
    public const MAX_HIGHLIGHTS = 8;

    protected $fillable = [
        'name', 'slug', 'description', 'monthly_price', 'trial_days',
        'max_stores', 'max_users', 'max_registers', 'entitlements', 'highlights',
        'is_public', 'is_featured', 'is_active', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'monthly_price' => 'integer',
            'trial_days' => 'integer',
            'max_stores' => 'integer',
            'max_users' => 'integer',
            'max_registers' => 'integer',
            'entitlements' => 'array',
            'highlights' => 'array',
            'is_public' => 'boolean',
            'is_featured' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // El plan describe el producto: un cambio aplica de inmediato a quien lo tiene.
        static::saved(fn () => app(EntitlementService::class)->forget());
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('monthly_price');
    }

    public function companies(): HasMany
    {
        return $this->hasMany(Company::class);
    }

    /**
     * El tope de un recurso, o null si el plan no lo limita.
     */
    public function limitFor(string $resource): ?int
    {
        return match ($resource) {
            'stores' => $this->max_stores,
            'users' => $this->max_users,
            'registers' => $this->max_registers,
            default => null,
        };
    }
}
