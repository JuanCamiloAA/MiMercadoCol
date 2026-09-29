<?php

namespace Tests\Concerns;

use App\Models\ProductAttribute;
use App\Models\ProductAttributeValue;
use App\Models\ProductFamily;
use App\Models\User;
use App\Services\ProductAttributeCatalog;
use App\Services\ProductFamilyService;

/**
 * Articulos con subarticulos para las pruebas: el catalogo de fabrica de la empresa y familias
 * armadas por `ProductFamilyService`, el unico camino.
 */
trait BuildsVariants
{
    protected function seedAttributes(?int $companyId = null): void
    {
        app(ProductAttributeCatalog::class)->seedDefaults($companyId ?? $this->company->id);
    }

    protected function attribute(string $name, ?int $companyId = null): ProductAttribute
    {
        return ProductAttribute::query()->withoutGlobalScopes()
            ->where('company_id', $companyId ?? $this->company->id)
            ->where('name', $name)
            ->firstOrFail();
    }

    protected function value(string $attribute, string $value, ?int $companyId = null): ProductAttributeValue
    {
        $model = $this->attribute($attribute, $companyId);

        return $model->values()->where('slug', ProductAttributeValue::slugFor($value))->first()
            ?? app(ProductAttributeCatalog::class)->addValue($model, $value);
    }

    /**
     * Una familia con una fila por combinacion. Cada fila: [valor1, valor2|null, precio, extra[]].
     *
     * @param  list<array{0: string, 1: string|null, 2: int, 3?: array<string, mixed>}>  $rows
     * @param  array<string, mixed>  $common
     */
    protected function makeFamily(string $name, string $attribute1, ?string $attribute2, array $rows, array $common = [], ?User $user = null): ProductFamily
    {
        $user ??= User::query()->first() ?? $this->makeUser('admin');
        $prefix = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $name), 0, 4));

        $variants = [];

        foreach ($rows as $index => $row) {
            $variants[] = array_merge([
                'value_1_id' => $this->value($attribute1, $row[0])->id,
                'value_2_id' => $attribute2 && $row[1] !== null ? $this->value($attribute2, $row[1])->id : null,
                'sku' => $prefix.'-'.$index.'-'.random_int(100, 999),
                'price' => $row[2],
                'cost' => (int) round($row[2] * 0.7),
                'is_active' => true,
                'is_online_visible' => true,
                'min_stock' => 0,
                // Con existencia, como entraria al crearlo: por `StockService`, en un ajuste.
                'initial_stocks' => [['store_id' => $this->store->id, 'quantity' => 50]],
            ], $row[3] ?? []);
        }

        return app(ProductFamilyService::class)->create(array_merge([
            'name' => $name,
            'category_id' => $this->category->id,
            'tax_rate' => 19,
            'attribute_1_id' => $this->attribute($attribute1)->id,
            'attribute_2_id' => $attribute2 ? $this->attribute($attribute2)->id : null,
            'is_active' => true,
            'is_online_visible' => true,
            'variants' => $variants,
        ], $common), $user, $this->company->id);
    }
}
