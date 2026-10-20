<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StorefrontSetting extends Model
{
    use HasFactory;

    /**
     * Lo operativo de la sede (domicilio, cobertura, franjas, pagos, textos, buscadores) y la
     * estructura de la tienda (barra superior, encabezado, menu, tarjeta, carrito, pie y las
     * paginas de categoria, producto y busqueda). Todo se versiona y se publica junto.
     *
     * @var list<string>
     */
    public const KEYS = [
        'delivery', 'coverage', 'slots', 'payments', 'catalog', 'texts', 'seo',
        'topbar', 'header', 'nav', 'mobile_nav', 'card', 'cart_bar', 'category_page', 'product_page', 'search', 'footer',
        'floating',
    ];

    protected $fillable = ['storefront_id', 'key', 'value'];

    protected function casts(): array
    {
        return ['value' => 'array'];
    }

    public function storefront(): BelongsTo
    {
        return $this->belongsTo(Storefront::class);
    }
}
