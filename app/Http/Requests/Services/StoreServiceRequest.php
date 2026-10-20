<?php

namespace App\Http\Requests\Services;

use App\Models\Product;
use App\Models\Service;
use App\Models\ServiceImage;
use App\Services\Catalog\CatalogImages;
use App\Support\ScanCodeGuard;
use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Crear un servicio. Los componentes (misma empresa, activos, sin repetir, enteros si no son
 * por peso) los revisa `ServiceManager`, que es el mismo camino de la importacion.
 */
class StoreServiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('services.index.create') ?? false;
    }

    protected function prepareForValidation(): void
    {
        // En un formulario con imagen los vacios llegan como texto: se vuelven nulos.
        foreach (['barcode', 'online_price', 'category_id', 'description', 'cost'] as $field) {
            if ($this->input($field) === '') {
                $this->merge([$field => null]);
            }
        }

        if ($this->boolean('open_price')) {
            $this->merge(['price' => 0]);
        }

        // El precio de un articulo vacio es «el vigente del articulo», no cero.
        if (is_array($this->input('components'))) {
            $this->merge(['components' => array_map(function ($component) {
                if (is_array($component) && array_key_exists('unit_price', $component) && $component['unit_price'] === '') {
                    $component['unit_price'] = null;
                }

                return $component;
            }, $this->input('components'))]);
        }
    }

    public function rules(): array
    {
        $companyId = (int) TenantContext::activeCompanyId();
        $ignore = $this->ignoredServiceId();
        $ofCompany = fn ($query) => $query->where('company_id', $companyId);

        return [
            'category_id' => ['nullable', 'integer', Rule::exists('categories', 'id')->where($ofCompany)],
            'code' => [
                'required', 'string', 'max:40',
                Rule::unique('services', 'code')->where($ofCompany)->ignore($ignore),
                ScanCodeGuard::serviceRule($companyId, $ignore),
            ],
            'barcode' => [
                'nullable', 'string', 'max:60',
                Rule::unique('services', 'barcode')->where($ofCompany)->ignore($ignore),
                ScanCodeGuard::serviceRule($companyId, $ignore),
            ],
            'name' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:2000'],
            // Varias fotos; cuantas, lo decide la empresa (Ajustes > Catalogo). `image` suelta
            // sigue valiendo y cuenta como una mas.
            'images' => ['nullable', 'array'],
            'images.*' => CatalogImages::FILE_RULES,
            'image' => ['nullable', ...CatalogImages::FILE_RULES],
            'open_price' => ['boolean'],
            'price' => ['required', 'integer', $this->boolean('open_price') ? 'min:0' : 'min:1'],
            'online_price' => ['nullable', 'integer', 'min:1'],
            'cost' => ['nullable', 'integer', 'min:0'],
            'tax_rate' => ['required', 'numeric', Rule::in(Product::TAX_RATES)],
            'pricing_mode' => ['required', Rule::in([Service::PRICING_BUNDLED, Service::PRICING_ITEMIZED])],
            'is_active' => ['boolean'],
            'is_online_visible' => ['boolean'],
            'is_featured' => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65000'],
            'components' => ['nullable', 'array', 'max:60'],
            'components.*.product_id' => ['required', 'integer', 'distinct'],
            'components.*.quantity' => ['required', 'numeric', 'gt:0', 'regex:/^\d+(\.\d{1,3})?$/'],
            'components.*.unit_price' => ['nullable', 'integer', 'min:0', 'max:999999999'],
            'components.*.returnable_by_default' => ['boolean'],
            // El articulo con subarticulos completo: `product_id` es la presentacion por defecto.
            'components.*.family' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'code.required' => 'El código es obligatorio.',
            'code.unique' => 'Ya hay un servicio con ese código.',
            'barcode.unique' => 'Ya hay un servicio con ese código de barras.',
            'name.required' => 'El nombre es obligatorio.',
            'price.required' => 'El precio es obligatorio.',
            'price.min' => 'El precio debe ser mayor que cero. Si se digita en la caja, marca «Precio variable».',
            'online_price.min' => 'El precio en línea debe ser mayor que cero, o déjalo vacío para usar el de la caja.',
            'tax_rate.in' => 'El IVA solo puede ser 0, 5 o 19.',
            'pricing_mode.in' => 'Elige cómo se cobra.',
            'components.*.product_id.distinct' => 'Un artículo no puede ir dos veces: suma la cantidad en un solo renglón.',
            'components.*.quantity.gt' => 'La cantidad debe ser mayor que cero.',
            'components.*.quantity.regex' => 'La cantidad admite hasta tres decimales.',
            'components.*.unit_price.integer' => 'El precio del artículo va en pesos enteros.',
            'components.*.unit_price.min' => 'El precio del artículo no puede ser negativo.',
            'image.image' => 'El archivo debe ser una imagen.',
            'image.mimes' => 'Las fotos van en JPG, PNG o WebP.',
            'image.max' => 'Cada foto debe pesar menos de 4 MB.',
            ...CatalogImages::fileMessages(),
        ];
    }

    public function after(): array
    {
        return [
            fn (Validator $validator) => app(CatalogImages::class)->check(
                $validator,
                (int) TenantContext::activeCompanyId(),
                $this->ignoredServiceId() ? ServiceImage::query()->where('service_id', $this->ignoredServiceId())->count() : 0,
                count($this->uploadedImages()),
            ),
        ];
    }

    /**
     * Las fotos que llegaron, en el orden en que se eligieron.
     *
     * @return list<UploadedFile>
     */
    public function uploadedImages(): array
    {
        $files = array_values(array_filter((array) $this->file('images', []), fn ($file) => $file instanceof UploadedFile));
        $single = $this->file('image');

        return $single instanceof UploadedFile ? [...$files, $single] : $files;
    }

    /**
     * Los campos del servicio. El costo solo lo toca quien lo puede ver.
     *
     * @return array<string, mixed>
     */
    public function serviceData(): array
    {
        $data = collect($this->validated())->except(['image', 'images'])->all();
        $data['components'] = array_values($data['components'] ?? []);
        $data['open_price'] = $this->boolean('open_price');
        $data['is_active'] = $this->boolean('is_active');
        $data['is_online_visible'] = $this->boolean('is_online_visible');
        $data['is_featured'] = $this->boolean('is_featured');
        $data['sort_order'] = (int) ($data['sort_order'] ?? 0);

        if (! $this->user()->can('services.show.view_cost')) {
            unset($data['cost']);
        } else {
            $data['cost'] = (int) ($data['cost'] ?? 0);
        }

        return $data;
    }

    protected function ignoredServiceId(): ?int
    {
        return null;
    }
}
