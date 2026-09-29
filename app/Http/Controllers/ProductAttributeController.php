<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProductAttributes\ReorderAttributeValuesRequest;
use App\Http\Requests\ProductAttributes\StoreAttributeValueRequest;
use App\Http\Requests\ProductAttributes\StoreProductAttributeRequest;
use App\Http\Requests\ProductAttributes\UpdateAttributeValueRequest;
use App\Http\Requests\ProductAttributes\UpdateProductAttributeRequest;
use App\Models\ProductAttribute;
use App\Models\ProductAttributeValue;
use App\Models\ProductFamily;
use App\Services\ProductAttributeCatalog;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * El catalogo de caracteristicas de la empresa: volumen, peso, unidades, sabor, tamaño, color,
 * talla… con sus valores en el orden en que se muestran.
 */
class ProductAttributeController extends Controller
{
    public function __construct(protected ProductAttributeCatalog $catalog) {}

    public function index(Request $request): Response
    {
        $companyId = TenantContext::requireCompanyId();

        return Inertia::render('Attributes/Index', [
            'attributes' => $this->catalog->catalogFor($companyId),
            'displays' => collect(ProductAttribute::DISPLAYS)->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values(),
            'measures' => collect(ProductAttribute::MEASURES)->map(fn ($label, $value) => [
                'value' => $value,
                'label' => $label,
                'units' => collect(ProductAttribute::MEASURE_UNITS_MAP[$value])->keys()->map(fn ($unit) => ['value' => $unit, 'label' => ProductAttribute::UNIT_LABELS[$unit]])->values(),
            ])->values(),
            'canManage' => $request->user()->can('products.attributes.manage'),
        ]);
    }

    public function store(StoreProductAttributeRequest $request): RedirectResponse
    {
        $companyId = TenantContext::requireCompanyId();

        ProductAttribute::query()->create([
            'company_id' => $companyId,
            'name' => trim($request->string('name')->toString()),
            'slug' => Str::slug($request->string('name')->toString()),
            'display' => $request->string('display')->toString(),
            'measure' => $request->input('measure') ?: null,
            'name_in_label' => $request->boolean('name_in_label'),
            'sort_order' => (int) ProductAttribute::query()->where('company_id', $companyId)->max('sort_order') + 1,
            'is_active' => true,
        ]);

        return back()->with('success', 'La característica quedó creada.');
    }

    public function update(UpdateProductAttributeRequest $request, ProductAttribute $attribute): RedirectResponse
    {
        $this->authorizeCompany($attribute);

        $attribute->update([
            'name' => trim($request->string('name')->toString()),
            'slug' => Str::slug($request->string('name')->toString()),
            'display' => $request->string('display')->toString(),
            'name_in_label' => $request->boolean('name_in_label'),
            'is_active' => $request->boolean('is_active', true),
        ]);

        return back()->with('success', 'La característica quedó actualizada.');
    }

    /** Solo se borra si ningun articulo la usa; si no, se desactiva. */
    public function destroy(ProductAttribute $attribute): RedirectResponse
    {
        $this->authorizeCompany($attribute);

        $used = ProductFamily::query()->withoutGlobalScopes()->withTrashed()
            ->where(fn ($query) => $query->where('attribute_1_id', $attribute->id)->orWhere('attribute_2_id', $attribute->id))
            ->exists();

        if ($used) {
            return back()->with('error', 'Hay artículos que la usan: desactívala en vez de eliminarla.');
        }

        $attribute->delete();

        return back()->with('success', 'La característica quedó eliminada.');
    }

    /** Un valor nuevo. Desde el formulario del articulo llega en JSON y vuelve en JSON. */
    public function storeValue(StoreAttributeValueRequest $request, ProductAttribute $attribute): RedirectResponse|JsonResponse
    {
        $this->authorizeCompany($attribute);

        $value = $this->catalog->addValue(
            $attribute,
            $request->input('value'),
            amount: $request->filled('amount') ? (float) $request->input('amount') : null,
            unit: $request->input('unit'),
            colorHex: $request->input('color_hex'),
        );

        if ($request->filled('media_id')) {
            $value->update(['media_id' => (int) $request->input('media_id')]);
        }

        if ($request->wantsJson()) {
            return response()->json(['value' => $this->catalog->valuePayload($value->refresh(), 0)], 201);
        }

        return back()->with('success', "«{$value->value}» quedó en {$attribute->name}.");
    }

    public function updateValue(UpdateAttributeValueRequest $request, ProductAttributeValue $value): RedirectResponse
    {
        $this->authorizeCompany($value->attribute);

        $changes = $request->safe()->only(['color_hex', 'media_id', 'is_active']);

        if ($request->has('value') && ! $value->attribute->isMeasure()) {
            $changes['value'] = trim(preg_replace('/\s+/', ' ', $request->string('value')->toString()) ?? '');
            $changes['slug'] = ProductAttributeValue::slugFor($changes['value']);
        }

        $value->update($changes);

        return back()->with('success', 'El valor quedó actualizado.');
    }

    /** Un valor usado por algun subarticulo no se borra: se desactiva. */
    public function destroyValue(ProductAttributeValue $value): RedirectResponse
    {
        $this->authorizeCompany($value->attribute);

        if ($value->usage() > 0) {
            return back()->with('error', "«{$value->value}» lo usan subartículos: desactívalo en vez de eliminarlo.");
        }

        $value->delete();

        return back()->with('success', 'El valor quedó eliminado.');
    }

    public function reorderValues(ReorderAttributeValuesRequest $request, ProductAttribute $attribute): RedirectResponse
    {
        $this->authorizeCompany($attribute);

        DB::transaction(function () use ($request, $attribute) {
            foreach ($request->input('order') as $position => $id) {
                ProductAttributeValue::query()
                    ->where('product_attribute_id', $attribute->id)
                    ->whereKey((int) $id)
                    ->update(['sort_order' => $position]);
            }
        });

        return back(303);
    }

    protected function authorizeCompany(ProductAttribute $attribute): void
    {
        abort_if(
            ! auth()->user()->isSuperAdmin() && $attribute->company_id !== TenantContext::activeCompanyId(),
            403,
            'Esa característica no es de tu empresa.'
        );
    }
}
