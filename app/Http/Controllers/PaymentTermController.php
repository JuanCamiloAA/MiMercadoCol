<?php

namespace App\Http\Controllers;

use App\Http\Requests\SavePaymentTermRequest;
use App\Models\Customer;
use App\Models\PaymentTerm;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Services\PaymentTermService;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Administracion > Empresa > Plazos de pago: los que se le dan a los clientes a credito y los
 * que dan los proveedores. Un plazo en uso no se borra, se desactiva.
 */
class PaymentTermController extends Controller
{
    public function __construct(protected PaymentTermService $terms) {}

    public function index(): Response
    {
        $companyId = TenantContext::requireCompanyId();

        $customers = Customer::query()->where('company_id', $companyId)->whereNotNull('payment_term_id')
            ->selectRaw('payment_term_id, COUNT(*) as total')->groupBy('payment_term_id')->pluck('total', 'payment_term_id');
        $suppliers = Supplier::query()->where('company_id', $companyId)->whereNotNull('payment_term_id')
            ->selectRaw('payment_term_id, COUNT(*) as total')->groupBy('payment_term_id')->pluck('total', 'payment_term_id');

        return Inertia::render('PaymentTerms/Index', [
            'terms' => PaymentTerm::query()
                ->where('company_id', $companyId)
                ->orderBy('sort_order')
                ->orderBy('days')
                ->get()
                ->map(fn (PaymentTerm $term) => [
                    'id' => $term->id,
                    'name' => $term->name,
                    'days' => $term->days,
                    'is_default_customers' => $term->is_default_customers,
                    'is_default_suppliers' => $term->is_default_suppliers,
                    'is_active' => $term->is_active,
                    'sort_order' => $term->sort_order,
                    'customers_count' => (int) ($customers[$term->id] ?? 0),
                    'suppliers_count' => (int) ($suppliers[$term->id] ?? 0),
                ])->values(),
        ]);
    }

    public function store(SavePaymentTermRequest $request): RedirectResponse
    {
        $term = $this->terms->create(TenantContext::requireCompanyId(), $request->validated());

        return back()->with('success', "El plazo «{$term->name}» quedó creado.");
    }

    public function update(SavePaymentTermRequest $request, PaymentTerm $term): RedirectResponse
    {
        $this->authorizeCompany($term);

        $data = $request->validated();

        // Quitar la marca de defecto dejaria la empresa sin plazo por defecto: se cambia eligiendo
        // otro, no desmarcando este.
        foreach (['is_default_customers' => 'clientes', 'is_default_suppliers' => 'proveedores'] as $column => $label) {
            if ($term->{$column} && array_key_exists($column, $data) && ! $data[$column]) {
                throw ValidationException::withMessages([$column => "Para cambiar el plazo por defecto de {$label}, márcalo en otro plazo."]);
            }
        }

        $this->terms->update($term, $data);

        return back()->with('success', "El plazo «{$term->name}» quedó guardado.");
    }

    public function toggle(PaymentTerm $term): RedirectResponse
    {
        $this->authorizeCompany($term);

        try {
            $this->terms->setActive($term, ! $term->is_active);
        } catch (ValidationException $exception) {
            return back()->with('error', (string) collect($exception->errors())->flatten()->first());
        }

        return back()->with('success', $term->is_active
            ? "«{$term->name}» quedó activo."
            : "«{$term->name}» quedó desactivado. Quien ya lo tiene lo conserva; no se ofrece para nuevos.");
    }

    protected function authorizeCompany(PaymentTerm $term): void
    {
        abort_if((int) $term->company_id !== (int) TenantContext::requireCompanyId(), 404);
    }
}
