<?php

namespace App\Http\Requests\ProductFamilies;

use App\Models\ProductFamily;

/** Editar un articulo con subarticulos: lo comun y la matriz, con los subarticulos que ya tiene. */
class UpdateProductFamilyRequest extends ProductFamilyRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('products.index.edit') ?? false;
    }

    /**
     * La primera caracteristica no cambia: la matriz entera depende de ella. La segunda se puede
     * sumar si no habia; una vez puesta, tampoco cambia.
     */
    protected function prepareForValidation(): void
    {
        $family = $this->route('family');

        if ($family instanceof ProductFamily) {
            $this->merge(array_filter([
                'attribute_1_id' => $family->attribute_1_id,
                'attribute_2_id' => $family->attribute_2_id,
            ]));
        }
    }

    protected function familyId(): ?int
    {
        $family = $this->route('family');

        return $family instanceof ProductFamily ? $family->id : null;
    }
}
