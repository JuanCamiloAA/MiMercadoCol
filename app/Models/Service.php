<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Un servicio que la tienda vende: solo por valor, o consumiendo articulos del inventario.
 *
 * No tiene existencias: lo que consume sale del inventario como lineas hijas de la venta. Su
 * precio por canal lo decide `ServicePricing`, nunca `price` a secas.
 */
class Service extends Model
{
    use BelongsToCompany, HasFactory, SoftDeletes;

    /** Precio cerrado: los articulos van incluidos y quitarlos no cambia el precio. */
    public const PRICING_BUNDLED = 'bundled';

    /** Servicio + articulos: cada articulo se cobra aparte a su precio de venta. */
    public const PRICING_ITEMIZED = 'itemized';

    /** @var array<string, string> */
    public const PRICING_LABELS = [
        self::PRICING_BUNDLED => 'Precio cerrado',
        self::PRICING_ITEMIZED => 'Servicio + artículos',
    ];

    protected $fillable = [
        'company_id', 'category_id', 'code', 'barcode', 'name', 'description', 'image_path', 'thumb_path',
        'price', 'online_price', 'cost', 'tax_rate', 'pricing_mode', 'open_price', 'is_active',
        'is_online_visible', 'is_featured', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'online_price' => 'integer',
            'cost' => 'integer',
            'tax_rate' => 'float',
            'open_price' => 'boolean',
            'is_active' => 'boolean',
            'is_online_visible' => 'boolean',
            'is_featured' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function components(): HasMany
    {
        return $this->hasMany(ServiceComponent::class)->orderBy('sort_order')->orderBy('id');
    }

    /** Sus fotos, la portada primero; la portada tambien queda en `image_path` para los listados. */
    public function images(): HasMany
    {
        return $this->hasMany(ServiceImage::class)->orderBy('sort_order')->orderBy('id');
    }

    public function isBundled(): bool
    {
        return $this->pricing_mode !== self::PRICING_ITEMIZED;
    }

    public function isItemized(): bool
    {
        return $this->pricing_mode === self::PRICING_ITEMIZED;
    }

    public function pricingLabel(): string
    {
        return self::PRICING_LABELS[$this->pricing_mode] ?? $this->pricing_mode;
    }

    public function thumb(): ?string
    {
        return $this->thumb_path ?: $this->image_path;
    }

    /**
     * Los componentes que hoy impiden cobrarlo: articulo inactivo o eliminado.
     *
     * @return list<string>
     */
    public function blockedComponents(): array
    {
        $this->loadMissing('components.product');

        return $this->components
            ->filter(fn (ServiceComponent $component) => ! $component->product || $component->product->trashed() || ! $component->product->is_active)
            ->map(fn (ServiceComponent $component) => ($component->product?->name ?? 'Un artículo eliminado')
                .($component->product?->trashed() ? ' (eliminado)' : ' (inactivo)'))
            ->values()
            ->all();
    }

    /** Se puede cobrar: activo y con todos sus articulos activos. */
    public function isSellable(): bool
    {
        return $this->is_active && $this->blockedComponents() === [];
    }

    /** Activo y sin componentes inactivos o eliminados. */
    public function scopeSellable(Builder $query): Builder
    {
        return $query->where('services.is_active', true)
            ->whereDoesntHave('components', fn (Builder $components) => $components->whereHas(
                'product',
                fn (Builder $product) => $product->withTrashed()->where(fn (Builder $inner) => $inner->whereNotNull('products.deleted_at')->orWhere('products.is_active', false)),
            ));
    }
}
