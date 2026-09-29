<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Services\WarehouseService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Store extends Model
{
    use BelongsToCompany, HasFactory;

    protected $fillable = [
        'company_id', 'name', 'code', 'address', 'city', 'latitude', 'longitude', 'phone',
        'opens_at', 'closes_at', 'suggested_cash_base', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'suggested_cash_base' => 'integer',
            'latitude' => 'float',
            'longitude' => 'float',
        ];
    }

    /**
     * Toda sede nace con su bodega principal.
     *
     * Va en un gancho del modelo y no en cada controlador porque una sede sin bodega revienta
     * la caja en el primer cobro, y las sedes nacen por varios caminos: el formulario, la
     * importacion de maestras y los seeders. El gancho corre dentro de la transaccion de quien
     * la crea, que es justo lo que se necesita.
     */
    protected static function booted(): void
    {
        static::created(function (Store $store) {
            app(WarehouseService::class)->createDefaultFor($store);
        });
    }

    public function warehouses(): HasMany
    {
        return $this->hasMany(Warehouse::class);
    }

    public function registers(): HasMany
    {
        return $this->hasMany(Register::class);
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }

    public function productStocks(): HasMany
    {
        return $this->hasMany(ProductStock::class);
    }
}
