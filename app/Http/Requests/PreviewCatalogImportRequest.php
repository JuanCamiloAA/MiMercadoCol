<?php

namespace App\Http\Requests;

use App\Services\Catalog\SearchCriteria;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Los filtros con los que se le pregunta a la fuente externa.
 *
 * Previsualizar no escribe nada; aun asi valida igual que importar, porque lo que se ve aqui
 * es lo que se va a traer y un filtro que cambia entre los dos pasos confunde a cualquiera.
 */
class PreviewCatalogImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('products.catalog_import.view') ?? false;
    }

    public function rules(): array
    {
        return [
            'country' => ['required', 'string', 'max:60'],
            'brand' => ['nullable', 'string', 'max:80'],
            'category' => ['nullable', 'string', 'max:80'],
            'search' => ['nullable', 'string', 'max:80'],
            'only_with_image' => ['boolean'],
            'limit' => ['required', 'integer', 'min:1', 'max:'.(int) config('catalog.import.max_limit')],
            'page' => ['integer', 'min:1', 'max:200'],
        ];
    }

    public function messages(): array
    {
        return [
            'country.required' => 'Elige un país.',
            'limit.max' => 'De una sola vez no se pueden traer más de '.number_format((int) config('catalog.import.max_limit'), 0, ',', '.').' artículos.',
            'limit.min' => 'Indica cuántos artículos quieres traer.',
        ];
    }

    public function criteria(): SearchCriteria
    {
        return SearchCriteria::fromArray($this->validated());
    }

    public function page(): int
    {
        return max(1, (int) $this->input('page', 1));
    }
}
