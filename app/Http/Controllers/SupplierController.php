<?php

namespace App\Http\Controllers;

use App\Models\Supplier;
use App\Models\SupplierReturn;
use App\Services\PaymentTermService;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class SupplierController extends Controller
{
    /** @var list<string> */
    public const DELIVERY_DAYS = ['lun', 'mar', 'mie', 'jue', 'vie', 'sab', 'dom'];

    public function index(Request $request): Response
    {
        $companyId = TenantContext::requireCompanyId();
        $search = $request->string('search')->toString();

        $suppliers = Supplier::query()
            ->where('company_id', $companyId)
            ->withCount('products')
            ->with('paymentTerm:id,name,days')
            // Lo que el proveedor todavia debe por devoluciones despachadas.
            ->addSelect(['returns_pending' => SupplierReturn::query()
                ->selectRaw('COALESCE(SUM(GREATEST(total - rejected_total - settled_total, 0)), 0)')
                ->whereColumn('supplier_returns.supplier_id', 'suppliers.id')
                ->where('supplier_returns.status', SupplierReturn::STATUS_DISPATCHED)])
            ->when($search, fn ($query, $term) => $query->where(function ($inner) use ($term) {
                $inner->where('name', 'like', "%{$term}%")
                    ->orWhere('tax_id', 'like', "%{$term}%")
                    ->orWhere('contact_name', 'like', "%{$term}%");
            }))
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Supplier $supplier) => [
                'id' => $supplier->id,
                'name' => $supplier->name,
                'tax_id' => $supplier->tax_id,
                'contact_name' => $supplier->contact_name,
                'phone' => $supplier->phone,
                'email' => $supplier->email,
                'address' => $supplier->address,
                'lead_time_days' => $supplier->lead_time_days,
                'delivery_days' => $supplier->delivery_days ?? [],
                'payment_term_id' => $supplier->payment_term_id,
                'payment_term' => $supplier->paymentTerm?->name,
                'is_active' => (bool) $supplier->is_active,
                'products_count' => $supplier->products_count,
                'returns_pending' => (int) $supplier->returns_pending,
            ]);

        return Inertia::render('Suppliers/Index', [
            'suppliers' => $suppliers,
            'filters' => ['search' => $search],
            'deliveryDays' => self::DELIVERY_DAYS,
            'paymentTerms' => app(PaymentTermService::class)->options($companyId),
            'defaultPaymentTermId' => app(PaymentTermService::class)->defaultFor($companyId, PaymentTermService::TYPE_SUPPLIERS)?->id,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $companyId = TenantContext::requireCompanyId();

        Supplier::create(array_merge($this->withTerm($this->validateSupplier($request, $companyId), $companyId), ['company_id' => $companyId]));

        return back()->with('success', 'El proveedor quedo creado.');
    }

    public function update(Request $request, Supplier $supplier): RedirectResponse
    {
        $this->authorizeCompany($supplier);

        $supplier->update($this->withTerm($this->validateSupplier($request, $supplier->company_id, $supplier->id), $supplier->company_id, $supplier->payment_term_id));

        return back()->with('success', 'El proveedor quedo actualizado.');
    }

    public function destroy(Supplier $supplier): RedirectResponse
    {
        $this->authorizeCompany($supplier);

        if ($supplier->products()->exists()) {
            return back()->with('error', 'Ese proveedor tiene articulos asociados: desactivalo en vez de eliminarlo.');
        }

        if ($supplier->purchaseOrders()->exists()) {
            return back()->with('error', 'Ese proveedor tiene ordenes de compra en el historico.');
        }

        if (SupplierReturn::query()->where('supplier_id', $supplier->id)->exists()) {
            return back()->with('error', 'Ese proveedor tiene devoluciones en el historico: desactivalo en vez de eliminarlo.');
        }

        $supplier->delete();

        return back()->with('success', 'El proveedor quedo eliminado.');
    }

    /**
     * @return array<string, mixed>
     */
    protected function validateSupplier(Request $request, int $companyId, ?int $ignoreId = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'tax_id' => [
                'nullable', 'string', 'max:40',
                Rule::unique('suppliers', 'tax_id')->ignore($ignoreId)->where(fn ($query) => $query->where('company_id', $companyId)),
            ],
            'contact_name' => ['nullable', 'string', 'max:80'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:120'],
            'address' => ['nullable', 'string', 'max:160'],
            'lead_time_days' => ['required', 'integer', 'min:0', 'max:60'],
            'delivery_days' => ['array'],
            'delivery_days.*' => [Rule::in(self::DELIVERY_DAYS)],
            // El plazo que da el proveedor: lo toman sus ordenes de compra.
            'payment_term_id' => ['nullable', 'integer'],
            'is_active' => ['boolean'],
        ], [
            'name.required' => 'El nombre es obligatorio.',
            'tax_id.unique' => 'Ya hay un proveedor con ese NIT.',
            'lead_time_days.required' => 'Indica en cuantos dias entrega.',
        ]);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    protected function withTerm(array $validated, int $companyId, ?int $current = null): array
    {
        if (array_key_exists('payment_term_id', $validated)) {
            $validated['payment_term_id'] = app(PaymentTermService::class)->resolve($companyId, $validated['payment_term_id'], $current)?->id;
        }

        return $validated;
    }

    protected function authorizeCompany(Supplier $supplier): void
    {
        abort_if(
            ! auth()->user()->isSuperAdmin() && $supplier->company_id !== TenantContext::activeCompanyId(),
            403,
            'Ese proveedor no pertenece a tu empresa.'
        );
    }
}
