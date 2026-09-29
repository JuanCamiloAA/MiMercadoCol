<?php

namespace App\Http\Requests;

use App\Models\CatalogImport;
use App\Services\Catalog\SearchCriteria;
use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Lanzar una importacion.
 *
 * La categoria de destino es obligatoria y no por capricho: en este proyecto `category_id` no
 * admite nulos, y dejar que el sistema elija «la primera» termina con mil artículos en
 * Despensa sin que nadie lo haya decidido.
 */
class StoreCatalogImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('products.catalog_import.run') ?? false;
    }

    public function rules(): array
    {
        $companyId = TenantContext::activeCompanyId();

        return [
            'source' => ['required', Rule::in([CatalogImport::SOURCE_API, CatalogImport::SOURCE_CSV])],

            'category_id' => [
                'required', 'integer',
                Rule::exists('categories', 'id')->where(fn ($query) => $query->where('company_id', $companyId)),
            ],

            // Criterios de la API.
            'country' => ['required_if:source,'.CatalogImport::SOURCE_API, 'nullable', 'string', 'max:60'],
            'brand' => ['nullable', 'string', 'max:80'],
            'category' => ['nullable', 'string', 'max:80'],
            'search' => ['nullable', 'string', 'max:80'],
            'only_with_image' => ['boolean'],
            'limit' => ['required_if:source,'.CatalogImport::SOURCE_API, 'nullable', 'integer', 'min:1', 'max:'.(int) config('catalog.import.max_limit')],

            // Seleccion explicita de la previsualizacion: si viene, manda sobre los criterios.
            'eans' => ['nullable', 'array', 'max:'.(int) config('catalog.import.max_limit')],
            'eans.*' => ['string', 'max:14'],

            // Archivo, cuando la fuente es CSV.
            'file' => [
                'required_if:source,'.CatalogImport::SOURCE_CSV, 'nullable', 'file',
                'mimetypes:text/plain,text/csv,application/csv,application/vnd.ms-excel',
                'max:'.(int) config('catalog.import.csv_max_kb'),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'category_id.required' => 'Elige en qué categoría van a caer los artículos nuevos.',
            'category_id.exists' => 'Esa categoría no es de tu empresa.',
            'country.required_if' => 'Elige un país.',
            'limit.required_if' => 'Indica cuántos artículos quieres traer.',
            'file.required_if' => 'Adjunta el archivo CSV.',
            'file.mimetypes' => 'El archivo debe ser un CSV.',
            'file.max' => 'El archivo pesa más de lo permitido.',
        ];
    }

    public function isCsv(): bool
    {
        return $this->input('source') === CatalogImport::SOURCE_CSV;
    }

    public function criteria(): SearchCriteria
    {
        return SearchCriteria::fromArray($this->validated());
    }

    /**
     * Los codigos que el administrador marco en la previsualizacion, ya limpios.
     *
     * Se tratan como texto de punta a punta: convertirlos a entero en cualquier paso se come
     * los ceros de la izquierda y el articulo entra con otro codigo del que se escaneo.
     *
     * @return list<string>
     */
    public function selectedEans(): array
    {
        $eans = $this->input('eans');

        if (! is_array($eans)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            fn ($ean) => trim((string) $ean),
            $eans
        ))));
    }
}
