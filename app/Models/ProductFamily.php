<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Support\SearchText;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * El articulo principal de un articulo con subarticulos: «Aceite de girasol», con sus
 * presentaciones de 500 ml, 900 ml y 2 L.
 *
 * No se vende, no tiene existencias, no se compra ni se traslada. Agrupa, guarda lo comun y se
 * muestra una sola vez en la tienda. Lo comun (categoria, marca, proveedor, IVA y el nombre) se
 * copia a cada subarticulo, porque medio sistema lo lee de `products`: esas copias las escribe
 * solo `ProductFamilyService`.
 */
class ProductFamily extends Model
{
    use BelongsToCompany, SoftDeletes;

    protected $fillable = [
        'company_id', 'code', 'name', 'slug', 'description', 'category_id', 'brand', 'supplier_id', 'tax_rate',
        'attribute_1_id', 'attribute_2_id', 'default_variant_id', 'is_active', 'is_online_visible', 'is_featured',
    ];

    protected static function booted(): void
    {
        static::saving(function (ProductFamily $family) {
            if ($family->isDirty(['name', 'brand']) || $family->search_name === null) {
                $family->search_name = mb_substr(SearchText::key($family->name.' '.$family->brand), 0, 255);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'tax_rate' => 'float',
            'is_active' => 'boolean',
            'is_online_visible' => 'boolean',
            'is_featured' => 'boolean',
        ];
    }

    /** Los subarticulos, en el orden de sus valores (6, 8, 10… y no alfabetico). */
    public function variants(): HasMany
    {
        return $this->hasMany(Product::class)->orderBy('variant_sort')->orderBy('id');
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductFamilyImage::class)->orderBy('sort_order')->orderBy('id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function attribute1(): BelongsTo
    {
        return $this->belongsTo(ProductAttribute::class, 'attribute_1_id');
    }

    public function attribute2(): BelongsTo
    {
        return $this->belongsTo(ProductAttribute::class, 'attribute_2_id');
    }

    public function defaultVariant(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'default_variant_id');
    }

    /** La direccion de la tienda, unica en la empresa. */
    public static function uniqueSlug(int $companyId, string $name, ?int $ignoreId = null): string
    {
        $base = Str::limit(Str::slug($name) ?: 'articulo', 170, '');
        $slug = $base;
        $suffix = 2;

        while (self::query()->withoutGlobalScopes()->withTrashed()
            ->where('company_id', $companyId)
            ->where('slug', $slug)
            ->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }
}
