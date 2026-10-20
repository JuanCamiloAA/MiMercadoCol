<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * La clasificacion de una empresa: alimentos, textiles, belleza, articulos varios…
 *
 * Es de la plataforma (no de una empresa) y la administra el super usuario. La landing agrupa
 * por aqui las tiendas y las empresas. Desactivar una solo la saca de las opciones para crear o
 * contratar: las empresas que ya la tienen la conservan.
 */
class BusinessCategory extends Model
{
    protected $fillable = ['name', 'slug', 'icon', 'sort_order', 'is_active'];

    protected $attributes = ['is_active' => true, 'sort_order' => 0];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'sort_order' => 'integer'];
    }

    public function companies(): HasMany
    {
        return $this->hasMany(Company::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    /** @return array{id: int, name: string, slug: string, icon: string} */
    public function toLanding(): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'slug' => $this->slug, 'icon' => $this->icon];
    }
}
