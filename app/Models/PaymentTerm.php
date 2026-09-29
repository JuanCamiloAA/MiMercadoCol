<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Un plazo de pago de la empresa (contado, 15 dias...). Solo uno por defecto de clientes y uno
 * de proveedores: lo hace cumplir `PaymentTermService`, en una transaccion.
 */
class PaymentTerm extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'name', 'days', 'is_default_customers', 'is_default_suppliers', 'is_active', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'days' => 'integer',
            'is_default_customers' => 'boolean',
            'is_default_suppliers' => 'boolean',
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
        return $query->orderBy('sort_order')->orderBy('days');
    }
}
