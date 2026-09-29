<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un articulo que consume una unidad del servicio.
 *
 * `unit_price` es el precio del articulo dentro del servicio cuando se cobra por articulos. Nulo:
 * el precio vigente del articulo en el canal. Lo lee `ServicePricing`, nadie mas.
 *
 * Con `product_family_id` el componente es el articulo con subarticulos completo: `product_id` es
 * el subarticulo por defecto, y al vender se puede cambiar por otro de la misma familia sin
 * autorizacion. Un componente asi se cobra siempre al precio vigente del subarticulo elegido.
 */
class ServiceComponent extends Model
{
    protected $fillable = ['service_id', 'product_id', 'product_family_id', 'quantity', 'unit_price', 'returnable_by_default', 'sort_order'];

    protected function casts(): array
    {
        return [
            'quantity' => 'float',
            'unit_price' => 'integer',
            'returnable_by_default' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class)->withTrashed();
    }

    /** Con los eliminados: el panel tiene que poder advertir un componente que ya no existe. */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function family(): BelongsTo
    {
        return $this->belongsTo(ProductFamily::class, 'product_family_id')->withTrashed();
    }

    /** Si es el articulo completo, en la presentacion que se elija. */
    public function isFamily(): bool
    {
        return $this->product_family_id !== null;
    }
}
