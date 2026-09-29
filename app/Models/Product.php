<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Support\SearchText;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use BelongsToCompany, HasFactory, SoftDeletes;

    public const UNIT_UNIT = 'unit';

    public const UNIT_KG = 'kg';

    public const UNIT_G = 'g';

    public const UNIT_L = 'l';

    public const UNIT_PACK = 'pack';

    /** @var list<string> */
    public const UNITS = [self::UNIT_UNIT, self::UNIT_KG, self::UNIT_G, self::UNIT_L, self::UNIT_PACK];

    /** @var array<string, string> */
    public const UNIT_LABELS = [
        self::UNIT_UNIT => 'Unidad',
        self::UNIT_KG => 'Kilogramo',
        self::UNIT_G => 'Gramo',
        self::UNIT_L => 'Litro',
        self::UNIT_PACK => 'Paquete',
    ];

    /** @var array<string, string> Lo que trae una presentacion: `content_unit`. */
    public const CONTENT_UNITS = ['ml' => 'ml', 'l' => 'L', 'g' => 'g', 'kg' => 'kg', 'un' => 'und'];

    /** @var list<float> */
    public const TAX_RATES = [0, 5, 19];

    /** @var array<int, string> */
    public const TAX_LABELS = [0 => 'Exento (0 %)', 5 => 'IVA 5 %', 19 => 'IVA 19 %'];

    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_API = 'api';

    public const SOURCE_CSV = 'csv';

    /** @var array<string, string> */
    public const SOURCE_LABELS = [
        self::SOURCE_MANUAL => 'Digitado a mano',
        self::SOURCE_API => 'Catálogo externo',
        self::SOURCE_CSV => 'Archivo importado',
    ];

    protected $fillable = [
        'company_id', 'category_id', 'supplier_id', 'product_family_id', 'variant_value_1_id', 'variant_value_2_id',
        'variant_key', 'variant_label', 'variant_sort', 'name', 'sku', 'barcode', 'brand', 'unit',
        'sold_by_weight', 'content_amount', 'content_unit', 'purchase_pack_size', 'reorder_multiplier',
        'cost', 'price', 'wholesale_price',
        'online_price', 'tax_rate', 'min_stock', 'track_lots', 'allow_negative_stock',
        'is_active', 'is_online_visible', 'is_featured', 'description', 'image_path',
        'thumb_path', 'image_url', 'source', 'source_ref',
    ];

    protected static function booted(): void
    {
        // La llave del buscador de la tienda. Lo que entre por consulta directa, sin modelo, queda
        // en nulo y el buscador lo compara por el nombre tal cual.
        static::saving(function (Product $product) {
            if ($product->isDirty(['name', 'brand']) || $product->search_name === null) {
                $product->search_name = mb_substr(SearchText::key($product->name.' '.$product->brand), 0, 255);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'sold_by_weight' => 'boolean',
            'track_lots' => 'boolean',
            'allow_negative_stock' => 'boolean',
            'is_active' => 'boolean',
            'is_online_visible' => 'boolean',
            'is_featured' => 'boolean',
            'cost' => 'integer',
            'price' => 'integer',
            'wholesale_price' => 'integer',
            'online_price' => 'integer',
            'min_stock' => 'integer',
            'tax_rate' => 'float',
            'content_amount' => 'float',
            'purchase_pack_size' => 'integer',
            'reorder_multiplier' => 'float',
            'variant_sort' => 'integer',
        ];
    }

    /** El articulo principal, si es un subarticulo. */
    public function family(): BelongsTo
    {
        return $this->belongsTo(ProductFamily::class, 'product_family_id')->withTrashed();
    }

    public function value1(): BelongsTo
    {
        return $this->belongsTo(ProductAttributeValue::class, 'variant_value_1_id');
    }

    public function value2(): BelongsTo
    {
        return $this->belongsTo(ProductAttributeValue::class, 'variant_value_2_id');
    }

    public function isVariant(): bool
    {
        return $this->product_family_id !== null;
    }

    /** Quienes le surten este articulo a la empresa. */
    public function suppliers(): HasMany
    {
        return $this->hasMany(ProductSupplier::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /** Los servicios que consumen este articulo. */
    public function serviceComponents(): HasMany
    {
        return $this->hasMany(ServiceComponent::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('sort_order');
    }

    public function stocks(): HasMany
    {
        return $this->hasMany(ProductStock::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    /** Margen sobre el precio de venta, en porcentaje. */
    public function getMarginAttribute(): float
    {
        if ($this->price <= 0) {
            return 0;
        }

        return round((($this->price - $this->cost) / $this->price) * 100, 1);
    }

    /** Miniatura de la portada; si no hay, la imagen completa. */
    /**
     * La foto que se muestra.
     *
     * Lo propio manda: si el comerciante subio una imagen, esa se usa. La de la fuente externa
     * es apenas un respaldo mientras no haya otra.
     */
    public function thumb(): ?string
    {
        return $this->thumb_path ?: ($this->image_path ?: $this->image_url);
    }

    public function storePrice(): int
    {
        return $this->online_price ?: $this->price;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOnlineVisible(Builder $query): Builder
    {
        return $query->where('is_active', true)->where('is_online_visible', true);
    }
}
