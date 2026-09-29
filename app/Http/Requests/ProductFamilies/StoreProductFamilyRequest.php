<?php

namespace App\Http\Requests\ProductFamilies;

/** Crear un articulo con subarticulos desde la matriz. */
class StoreProductFamilyRequest extends ProductFamilyRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('products.index.create') ?? false;
    }

    protected function familyId(): ?int
    {
        return null;
    }
}
