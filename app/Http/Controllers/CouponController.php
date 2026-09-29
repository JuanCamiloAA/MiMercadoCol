<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Coupon;
use App\Models\Product;
use App\Models\Storefront;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Cupones de la tienda en linea.
 */
class CouponController extends Controller
{
    public function index(Request $request): Response
    {
        $companyId = TenantContext::requireCompanyId();

        $filters = [
            'search' => $request->string('search')->toString(),
            'status' => $request->string('status')->toString(),
        ];

        $coupons = Coupon::query()
            ->where('company_id', $companyId)
            ->withCount('redemptions')
            ->with('storefront.store:id,name')
            ->when($filters['search'], fn ($query, $search) => $query->where('code', 'like', "%{$search}%"))
            ->when($filters['status'] === 'active', fn ($query) => $query->running())
            ->when($filters['status'] === 'off', fn ($query) => $query->where('is_active', false))
            ->orderByDesc('is_active')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        // Los nombres de los articulos alcanzados se resuelven de una sola vez para toda la
        // pagina: uno por cupon dejaria veinte consultas donde basta una.
        $productNames = $this->productNames($coupons->getCollection(), $companyId);

        $coupons->through(fn (Coupon $coupon) => [
                'id' => $coupon->id,
                'code' => $coupon->code,
                'type' => $coupon->type,
                'type_label' => $coupon->typeLabel(),
                'value' => $coupon->value,
                'min_order_total' => $coupon->min_order_total,
                'max_discount' => $coupon->max_discount,
                'scope' => $coupon->scope,
                'scope_ids' => $coupon->scope_ids ?? [],
                'starts_at' => $coupon->starts_at?->format('Y-m-d'),
                'ends_at' => $coupon->ends_at?->format('Y-m-d'),
                'usage_limit' => $coupon->usage_limit,
                'usage_limit_per_customer' => $coupon->usage_limit_per_customer,
                'first_order_only' => $coupon->first_order_only,
                'is_active' => $coupon->is_active,
                'storefront_id' => $coupon->storefront_id,
                'store' => $coupon->storefront?->store?->name,
                'used' => $coupon->redemptions_count,
                'status' => $coupon->status(),
                'scope_names' => $coupon->scope === 'product'
                    ? collect($coupon->scope_ids ?? [])
                        ->mapWithKeys(fn (int $id) => [$id => $productNames[$id] ?? "Artículo {$id}"])
                        ->all()
                    : [],
            ]);

        return Inertia::render('Coupons/Index', [
            'coupons' => $coupons,
            'filters' => $filters,
            'metrics' => [
                'active' => Coupon::query()->where('company_id', $companyId)->running()->count(),
                'used' => Coupon::query()->where('company_id', $companyId)->withCount('redemptions')->get()->sum('redemptions_count'),
            ],
            'types' => collect(Coupon::TYPE_LABELS)
                ->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])
                ->values()
                ->all(),
            'scopes' => collect(Coupon::SCOPE_LABELS)
                ->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])
                ->values()
                ->all(),
            'categories' => Category::query()
                ->where('company_id', $companyId)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Category $category) => ['value' => $category->id, 'label' => $category->name])
                ->all(),
            'storefronts' => Storefront::query()
                ->where('company_id', $companyId)
                ->with('store:id,name')
                ->get()
                ->map(fn (Storefront $storefront) => ['value' => $storefront->id, 'label' => $storefront->store?->name ?? $storefront->slug])
                ->all(),
        ]);
    }

    /**
     * Buscador de articulos para el alcance «Solo unos articulos».
     *
     * Con `ids` resuelve nombres de los ya elegidos; con `search`, busca.
     */
    public function products(Request $request): JsonResponse
    {
        $companyId = TenantContext::requireCompanyId();
        $search = $request->string('search')->toString();

        /** @var array<int, int> $ids */
        $ids = array_map('intval', $request->input('ids', []));

        return response()->json(
            Product::query()
                ->where('company_id', $companyId)
                ->when($ids !== [], fn ($query) => $query->whereIn('id', $ids))
                ->when($ids === [], fn ($query) => $query->where('is_active', true))
                ->when($ids === [] && $search !== '', fn ($query) => $query->where(
                    fn ($inner) => $inner->where('name', 'like', "%{$search}%")->orWhere('sku', 'like', "%{$search}%")
                ))
                ->orderBy('name')
                ->limit(25)
                ->get(['id', 'name', 'sku'])
                ->map(fn (Product $product) => [
                    'id' => $product->id,
                    'name' => $product->name,
                    'sku' => $product->sku,
                ])
        );
    }

    public function store(Request $request): RedirectResponse
    {
        $companyId = TenantContext::requireCompanyId();

        $validated = $this->validated($request, null);

        Coupon::create(array_merge($validated, [
            'company_id' => $companyId,
            'created_by_user_id' => $request->user()->id,
        ]));

        return back()->with('success', 'El cupón quedó creado.');
    }

    public function update(Request $request, Coupon $coupon): RedirectResponse
    {
        $this->authorizeCompany($coupon);

        $coupon->update($this->validated($request, $coupon));

        return back()->with('success', 'El cupón quedó actualizado.');
    }

    public function toggle(Coupon $coupon): RedirectResponse
    {
        $this->authorizeCompany($coupon);

        $coupon->update(['is_active' => ! $coupon->is_active]);

        return back()->with('success', $coupon->is_active ? 'El cupón quedó encendido.' : 'El cupón quedó apagado.');
    }

    public function destroy(Coupon $coupon): RedirectResponse
    {
        $this->authorizeCompany($coupon);

        $coupon->delete();

        return back()->with('success', 'El cupón quedó eliminado.');
    }

    /**
     * @return array<string, mixed>
     */
    protected function validated(Request $request, ?Coupon $coupon): array
    {
        $companyId = TenantContext::requireCompanyId();

        $validated = $request->validate([
            'code' => [
                'required', 'string', 'max:40', 'regex:/^[A-Za-z0-9-]+$/',
                Rule::unique('coupons', 'code')->where('company_id', $companyId)->ignore($coupon?->id),
            ],
            'type' => ['required', Rule::in(array_keys(Coupon::TYPE_LABELS))],
            'value' => ['required', 'integer', 'min:0'],
            'min_order_total' => ['nullable', 'integer', 'min:0'],
            'max_discount' => ['nullable', 'integer', 'min:0'],
            'scope' => ['required', Rule::in(array_keys(Coupon::SCOPE_LABELS))],
            'scope_ids' => ['nullable', 'array'],
            'scope_ids.*' => ['integer'],
            'storefront_id' => [
                'nullable', 'integer',
                Rule::exists('storefronts', 'id')->where('company_id', $companyId),
            ],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'usage_limit' => ['nullable', 'integer', 'min:1'],
            'usage_limit_per_customer' => ['nullable', 'integer', 'min:1'],
            'first_order_only' => ['boolean'],
            'is_active' => ['boolean'],
        ], [
            'code.required' => 'Escribe el código del cupón.',
            'code.regex' => 'El código solo admite letras, números y guiones.',
            'code.unique' => 'Ya existe un cupón con ese código.',
            'ends_at.after_or_equal' => 'La fecha de fin no puede ser anterior a la de inicio.',
        ]);

        // Un porcentaje mayor que cien regalaria plata.
        if ($validated['type'] === Coupon::TYPE_PERCENT && $validated['value'] > 100) {
            throw ValidationException::withMessages([
                'value' => 'Un descuento por porcentaje no puede pasar de 100.',
            ]);
        }

        if ($validated['scope'] !== 'all' && ($validated['scope_ids'] ?? []) === []) {
            throw ValidationException::withMessages([
                'scope_ids' => $validated['scope'] === 'category'
                    ? 'Elige al menos una categoría.'
                    : 'Elige al menos un artículo.',
            ]);
        }

        $validated['code'] = mb_strtoupper($validated['code']);
        $validated['min_order_total'] ??= 0;

        return $validated;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Coupon>  $coupons
     * @return array<int, string>
     */
    protected function productNames($coupons, int $companyId): array
    {
        $ids = $coupons
            ->filter(fn (Coupon $coupon) => $coupon->scope === 'product')
            ->flatMap(fn (Coupon $coupon) => $coupon->scope_ids ?? [])
            ->unique()
            ->all();

        if ($ids === []) {
            return [];
        }

        return Product::query()
            ->where('company_id', $companyId)
            ->whereIn('id', $ids)
            ->pluck('name', 'id')
            ->all();
    }

    protected function authorizeCompany(Coupon $coupon): void
    {
        abort_if(
            ! auth()->user()->isSuperAdmin() && $coupon->company_id !== TenantContext::activeCompanyId(),
            403,
            'Ese cupón no pertenece a tu empresa.'
        );
    }
}
