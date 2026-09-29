<?php

namespace App\Http\Requests\ProductFamilies;

use App\Models\Product;

/**
 * Convertir un articulo individual en el primer subarticulo de un articulo con subarticulos. La
 * matriz trae su fila (con su `id`) y las presentaciones nuevas.
 */
class ConvertProductToFamilyRequest extends ProductFamilyRequest
{
    public function authorize(): bool
    {
        return ($this->user()?->can('products.index.edit') && $this->user()->can('products.index.create')) ?? false;
    }

    protected function familyId(): ?int
    {
        return null;
    }

    protected function ownVariantIds(): array
    {
        $product = $this->route('product');

        return $product instanceof Product && $product->product_family_id === null ? [$product->id] : [];
    }
}
