<?php

namespace App\Http\Requests\ProductFamilies;

use App\Models\Product;
use App\Support\TenantContext;

/**
 * Agrupar articulos individuales que ya existen en un articulo con subarticulos: cada uno llega
 * como una fila de la matriz con su `id` y los valores que le tocan.
 */
class GroupProductsRequest extends ProductFamilyRequest
{
    public function authorize(): bool
    {
        return ($this->user()?->can('products.index.edit') && $this->user()->can('products.index.create')) ?? false;
    }

    protected function familyId(): ?int
    {
        return null;
    }

    /** Solo articulos individuales de la empresa: uno que ya tiene familia no se puede agrupar. */
    protected function ownVariantIds(): array
    {
        $ids = collect($this->input('variants', []))->pluck('id')->filter()->map(fn ($id) => (int) $id)->all();

        return Product::query()->withoutGlobalScopes()
            ->where('company_id', TenantContext::activeCompanyId())
            ->whereNull('deleted_at')
            ->whereNull('product_family_id')
            ->whereIn('id', $ids)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
