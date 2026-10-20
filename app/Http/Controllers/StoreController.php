<?php

namespace App\Http\Controllers;

use App\Models\Register;
use App\Models\Store;
use App\Services\Membership\MembershipLimiter;
use App\Support\Maps\MapConfig;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class StoreController extends Controller
{
    public function index(): Response
    {
        $companyId = TenantContext::requireCompanyId();

        $stores = Store::query()
            ->where('company_id', $companyId)
            ->withCount(['registers', 'employees'])
            ->orderBy('name')
            ->get()
            ->map(fn (Store $store) => [
                'id' => $store->id,
                'name' => $store->name,
                'code' => $store->code,
                'address' => $store->address,
                'city' => $store->city,
                'latitude' => $store->latitude !== null ? (float) $store->latitude : null,
                'longitude' => $store->longitude !== null ? (float) $store->longitude : null,
                'phone' => $store->phone,
                'opens_at' => substr((string) $store->opens_at, 0, 5),
                'closes_at' => substr((string) $store->closes_at, 0, 5),
                'suggested_cash_base' => $store->suggested_cash_base,
                'is_active' => (bool) $store->is_active,
                'registers_count' => $store->registers_count,
                'employees_count' => $store->employees_count,
            ]);

        return Inertia::render('Stores/Index', ['stores' => $stores, 'map' => MapConfig::current()]);
    }

    public function store(Request $request, MembershipLimiter $limiter): RedirectResponse
    {
        $companyId = TenantContext::requireCompanyId();
        $validated = $this->validateStore($request, $companyId);

        if ($validated['is_active'] ?? true) {
            $limiter->assertCanAdd(TenantContext::company(), 'stores', 'name');
        }

        Store::create(array_merge($validated, ['company_id' => $companyId]));

        return back()->with('success', 'La sede quedo creada.');
    }

    public function update(Request $request, Store $store, MembershipLimiter $limiter): RedirectResponse
    {
        $this->authorizeCompany($store);
        $validated = $this->validateStore($request, $store->company_id, $store->id);

        // Reactivar una sede ocupa cupo, igual que crearla.
        if (! $store->is_active && ($validated['is_active'] ?? false)) {
            $limiter->assertCanAdd(TenantContext::company(), 'stores', 'is_active');
        }

        $store->update($validated);

        return back()->with('success', 'La sede quedo actualizada.');
    }

    public function destroy(Store $store): RedirectResponse
    {
        $this->authorizeCompany($store);

        if (Register::query()->where('store_id', $store->id)->exists()) {
            return back()->with('error', 'Esa sede tiene cajas registradas: desactivala en vez de eliminarla.');
        }

        $store->delete();

        return back()->with('success', 'La sede quedo eliminada.');
    }

    /**
     * @return array<string, mixed>
     */
    protected function validateStore(Request $request, int $companyId, ?int $ignoreId = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'code' => [
                'required', 'string', 'max:20',
                Rule::unique('stores', 'code')->ignore($ignoreId)->where(fn ($query) => $query->where('company_id', $companyId)),
            ],
            'address' => ['nullable', 'string', 'max:160'],
            // Con ciudad y coordenadas la sede aparece en el buscador de la landing.
            'city' => ['nullable', 'string', 'max:80'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],
            'phone' => ['nullable', 'string', 'max:30'],
            'opens_at' => ['required', 'date_format:H:i'],
            'closes_at' => ['required', 'date_format:H:i', 'after:opens_at'],
            'suggested_cash_base' => ['required', 'integer', 'min:0'],
            'is_active' => ['boolean'],
        ], [
            'name.required' => 'Ponle un nombre a la sede.',
            'code.required' => 'El codigo es obligatorio.',
            'code.unique' => 'Ya hay una sede con ese codigo.',
            'closes_at.after' => 'La hora de cierre debe ser posterior a la de apertura.',
            'latitude.between' => 'La latitud va entre -90 y 90.',
            'longitude.between' => 'La longitud va entre -180 y 180.',
            'latitude.required_with' => 'Falta la latitud.',
            'longitude.required_with' => 'Falta la longitud.',
        ]);
    }

    protected function authorizeCompany(Store $store): void
    {
        abort_if(
            ! auth()->user()->isSuperAdmin() && $store->company_id !== TenantContext::activeCompanyId(),
            403,
            'Esa sede no pertenece a tu empresa.'
        );
    }
}
