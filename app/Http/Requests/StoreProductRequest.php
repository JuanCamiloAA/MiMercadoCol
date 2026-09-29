<?php

namespace App\Http\Requests;

use App\Models\Product;
use App\Support\ScanCodeGuard;
use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('products.index.create') ?? false;
    }

    public function rules(): array
    {
        $companyId = TenantContext::activeCompanyId();

        return [
            'category_id' => ['required', 'integer', Rule::exists('categories', 'id')->where(fn ($query) => $query->where('company_id', $companyId))],
            'supplier_id' => ['nullable', 'integer', Rule::exists('suppliers', 'id')->where(fn ($query) => $query->where('company_id', $companyId))],
            'name' => ['required', 'string', 'max:120'],
            'sku' => [
                'required', 'string', 'max:40',
                Rule::unique('products', 'sku')->where(fn ($query) => $query->where('company_id', $companyId))->whereNull('deleted_at'),
                ScanCodeGuard::productRule((int) $companyId),
            ],
            'barcode' => [
                'nullable', 'string', 'max:60',
                Rule::unique('products', 'barcode')->where(fn ($query) => $query->where('company_id', $companyId))->whereNull('deleted_at'),
                ScanCodeGuard::productRule((int) $companyId),
            ],
            'brand' => ['nullable', 'string', 'max:60'],
            'unit' => ['required', Rule::in(Product::UNITS)],
            'sold_by_weight' => ['boolean'],
            'cost' => ['required', 'integer', 'min:0'],
            'price' => ['required', 'integer', 'min:0'],
            'wholesale_price' => ['nullable', 'integer', 'min:0'],
            'online_price' => ['nullable', 'integer', 'min:0'],
            'tax_rate' => ['required', 'numeric', Rule::in(Product::TAX_RATES)],
            'min_stock' => ['required', 'integer', 'min:0'],
            'track_lots' => ['boolean'],
            'allow_negative_stock' => ['boolean'],
            'is_active' => ['boolean'],
            'is_online_visible' => ['boolean'],
            'is_featured' => ['boolean'],
            'description' => ['nullable', 'string', 'max:600'],
            'images' => ['nullable', 'array', 'max:5'],
            'images.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'initial_stocks' => ['array'],
            'initial_stocks.*.store_id' => ['required', 'integer', Rule::exists('stores', 'id')->where(fn ($query) => $query->where('company_id', $companyId))],
            'initial_stocks.*.quantity' => ['required', 'numeric', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'category_id.required' => 'Elige la categoria.',
            'name.required' => 'El nombre es obligatorio.',
            'sku.required' => 'El SKU es obligatorio.',
            'sku.unique' => 'Ya hay un articulo con ese SKU.',
            'barcode.unique' => 'Ya hay un articulo con ese codigo de barras.',
            'price.required' => 'El precio al publico es obligatorio.',
            'cost.required' => 'El costo es obligatorio.',
            'tax_rate.in' => 'El IVA solo puede ser 0, 5 o 19.',
            'images.max' => 'Maximo 5 imagenes por articulo.',
            'images.*.image' => 'Cada archivo debe ser una imagen.',
            'images.*.max' => 'Cada imagen debe pesar menos de 2 MB.',
        ];
    }
}
