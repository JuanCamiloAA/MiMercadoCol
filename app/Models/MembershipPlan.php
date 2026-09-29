<?php

namespace App\Models;

use App\Services\Membership\MembershipFeatures;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un plan de membresia. El precio guardado es el mensual en COP; el de cada ciclo lo calcula
 * `MembershipPricing::priceFor()`.
 */
class MembershipPlan extends Model
{
    protected $fillable = [
        'name', 'slug', 'description', 'monthly_price', 'trial_days',
        'max_stores', 'max_users', 'max_registers', 'features',
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
            'features' => 'array',
            'is_public' => 'boolean',
            'is_featured' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
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

    public function hasFeature(string $feature): bool
    {
        return in_array($feature, $this->features ?? [], true) && array_key_exists($feature, MembershipFeatures::CATALOGUE);
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
