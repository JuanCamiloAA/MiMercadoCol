<?php

namespace App\Http\Requests\Storefront\Settings;

use App\Services\Storefront\StorefrontStructure;
use Illuminate\Validation\Rule;

/** Pagina de categoria, ofertas y busqueda: filtros, orden y paginacion. */
class CategoryPageSettingRequest extends StorefrontSettingRequest
{
    protected function valueRules(): array
    {
        return [
            'value.layout' => ['required', 'in:sidebar,top'],
            'value.filters' => ['present', 'array'],
            'value.filters.*' => ['distinct', Rule::in(array_keys(StorefrontStructure::FILTERS))],
            'value.sorts' => ['required', 'array', 'min:1'],
            'value.sorts.*' => ['distinct', Rule::in(array_keys(StorefrontStructure::SORTS))],
            'value.default_sort' => ['required', Rule::in(array_keys(StorefrontStructure::SORTS)), 'in_array:value.sorts.*'],
            'value.page_size' => ['required', 'integer', 'min:12', 'max:60'],
            'value.pagination' => ['required', 'in:pages,load_more'],
            'value.show_subcategory_chips' => ['required', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'value.default_sort.in_array' => 'El orden por defecto tiene que ser uno de los que se ofrecen.',
            'value.sorts.min' => 'Ofrece al menos un orden.',
        ]);
    }
}
