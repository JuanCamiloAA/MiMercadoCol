<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\CustomerBalance;
use App\Models\CustomerPayment;
use App\Models\Sale;
use App\Services\CreditService;
use App\Services\PaymentTermService;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CreditController extends Controller
{
    public function __construct(protected CreditService $credit) {}

    public function index(Request $request): Response
    {
        $companyId = TenantContext::requireCompanyId();

        $filters = [
            'search' => $request->string('search')->toString(),
            'state' => $request->string('state')->toString(),
        ];

        $customers = Customer::query()
            ->where('customers.company_id', $companyId)
            ->where('customers.credit_enabled', true)
            ->leftJoin('customer_balances', 'customer_balances.customer_id', '=', 'customers.id')
            ->when($filters['search'], fn ($query, $search) => $query->where(function ($inner) use ($search) {
                $inner->where('customers.name', 'like', "%{$search}%")
                    ->orWhere('customers.document_number', 'like', "%{$search}%");
            }))
            ->when($filters['state'] === 'overdue', fn ($query) => $query->where('customer_balances.overdue_balance', '>', 0))
            ->when($filters['state'] === 'current', fn ($query) => $query->where('customer_balances.overdue_balance', '=', 0)->where('customer_balances.balance', '>', 0))
            ->when($filters['state'] === 'blocked', fn ($query) => $query->where('customers.credit_blocked', true))
            ->orderByDesc('customer_balances.overdue_balance')
            ->orderByDesc('customer_balances.balance')
            ->select([
                'customers.id',
                'customers.name',
                'customers.document_number',
                'customers.phone',
                'customers.credit_limit',
                'customers.credit_term_days',
                'customers.credit_blocked',
                'customers.blocked_reason',
                'customer_balances.balance',
                'customer_balances.overdue_balance',
                'customer_balances.oldest_due_date',
            ])
            ->paginate(20)
            ->withQueryString()
            ->through(fn ($row) => [
                'id' => (int) $row->id,
                'name' => $row->name,
                'document_number' => $row->document_number,
                'phone' => $row->phone,
                'credit_limit' => (int) $row->credit_limit,
                'credit_term_days' => (int) $row->credit_term_days,
                'credit_blocked' => (bool) $row->credit_blocked,
                'blocked_reason' => $row->blocked_reason,
                'balance' => (int) ($row->balance ?? 0),
                'overdue_balance' => (int) ($row->overdue_balance ?? 0),
                'available' => max(0, (int) $row->credit_limit - (int) ($row->balance ?? 0)),
                'oldest_due_date' => $row->oldest_due_date,
                'days_overdue' => $row->oldest_due_date
                    ? max(0, now()->startOfDay()->diffInDays(\Carbon\Carbon::parse($row->oldest_due_date)->startOfDay(), false) * -1)
                    : 0,
            ]);

        $selectedId = $request->integer('customer') ?: null;

        return Inertia::render('Credit/Index', [
            'customers' => $customers,
            'filters' => $filters,
            'metrics' => $this->metrics($companyId),
            'selected' => $selectedId ? $this->customerDetail($companyId, $selectedId) : null,
            'paymentTerms' => app(PaymentTermService::class)->options($companyId),
            'defaultPaymentTermId' => app(PaymentTermService::class)->defaultFor($companyId, PaymentTermService::TYPE_CUSTOMERS)?->id,
        ]);
    }

    public function show(Request $request, Customer $customer): Response
    {
        $this->authorizeCompany($customer);

        return $this->index($request->merge(['customer' => $customer->id]));
    }

    public function setLimit(Request $request, Customer $customer, PaymentTermService $terms): RedirectResponse
    {
        $this->authorizeCompany($customer);

        $validated = $request->validate([
            'credit_limit' => ['required', 'integer', 'min:0'],
            'payment_term_id' => ['required', 'integer'],
            'credit_enabled' => ['boolean'],
        ], [
            'credit_limit.required' => 'Indica el cupo.',
            'payment_term_id.required' => 'Elige el plazo de pago.',
        ]);

        $term = $terms->resolve($customer->company_id, $validated['payment_term_id'], $customer->payment_term_id);

        $customer->update([
            'credit_limit' => $validated['credit_limit'],
            'payment_term_id' => $term->id,
            // Copia de los dias del plazo: la leen la mora y el bloqueo de cartera.
            'credit_term_days' => $term->days,
            'credit_enabled' => $validated['credit_enabled'] ?? true,
        ]);

        return back()->with('success', 'El cupo quedo actualizado.');
    }

    public function toggleBlock(Request $request, Customer $customer): RedirectResponse
    {
        $this->authorizeCompany($customer);

        $validated = $request->validate(['reason' => ['nullable', 'string', 'max:160']]);

        if ($customer->credit_blocked) {
            $customer->update(['credit_blocked' => false, 'blocked_reason' => null]);

            return back()->with('success', 'El credito quedo desbloqueado.');
        }

        $customer->update([
            'credit_blocked' => true,
            'blocked_reason' => $validated['reason'] ?? 'Bloqueo manual',
        ]);

        return back()->with('success', 'El credito quedo bloqueado.');
    }

    public function export(): StreamedResponse
    {
        $companyId = TenantContext::requireCompanyId();

        $rows = Customer::query()
            ->where('customers.company_id', $companyId)
            ->where('customers.credit_enabled', true)
            ->leftJoin('customer_balances', 'customer_balances.customer_id', '=', 'customers.id')
            ->orderBy('customers.name')
            ->get([
                'customers.name',
                'customers.document_number',
                'customers.phone',
                'customers.credit_limit',
                'customers.credit_blocked',
                'customer_balances.balance',
                'customer_balances.overdue_balance',
                'customer_balances.oldest_due_date',
            ]);

        return response()->streamDownload(function () use ($rows) {
            $handle = fopen('php://output', 'wb');
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, ['Cliente', 'Documento', 'Telefono', 'Cupo', 'Saldo', 'En mora', 'Disponible', 'Vence', 'Bloqueado'], ';');

            foreach ($rows as $row) {
                fputcsv($handle, [
                    $row->name,
                    $row->document_number,
                    $row->phone,
                    $row->credit_limit,
                    $row->balance ?? 0,
                    $row->overdue_balance ?? 0,
                    max(0, $row->credit_limit - ($row->balance ?? 0)),
                    $row->oldest_due_date,
                    $row->credit_blocked ? 'Si' : 'No',
                ], ';');
            }

            fclose($handle);
        }, 'cartera-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @return array<string, int>
     */
    protected function metrics(int $companyId): array
    {
        $balances = CustomerBalance::query()
            ->join('customers', 'customers.id', '=', 'customer_balances.customer_id')
            ->where('customers.company_id', $companyId);

        return [
            'total' => (int) $balances->clone()->sum('customer_balances.balance'),
            'overdue' => (int) $balances->clone()->sum('customer_balances.overdue_balance'),
            'customers_with_debt' => (int) $balances->clone()->where('customer_balances.balance', '>', 0)->count(),
            'payments_today' => (int) CustomerPayment::query()
                ->where('company_id', $companyId)
                ->whereDate('paid_at', today())
                ->sum('amount'),
        ];
    }

    /**
     * Estado de cuenta del cliente elegido: facturas pendientes y abonos recientes.
     *
     * @return array<string, mixed>|null
     */
    protected function customerDetail(int $companyId, int $customerId): ?array
    {
        $customer = Customer::query()->where('company_id', $companyId)->with('balance')->find($customerId);

        if (! $customer) {
            return null;
        }

        return [
            'id' => $customer->id,
            'name' => $customer->name,
            'document_number' => $customer->document_number,
            'phone' => $customer->phone,
            'credit_limit' => $customer->credit_limit,
            'credit_term_days' => $customer->credit_term_days,
            'payment_term_id' => $customer->payment_term_id,
            'credit_blocked' => (bool) $customer->credit_blocked,
            'blocked_reason' => $customer->blocked_reason,
            'balance' => $customer->currentBalance(),
            'overdue_balance' => (int) ($customer->balance?->overdue_balance ?? 0),
            'available' => $customer->availableCredit(),
            'pending_sales' => Sale::query()
                ->where('customer_id', $customer->id)
                ->where('status', Sale::STATUS_CREDIT)
                ->where('due_total', '>', 0)
                ->orderBy('due_date')
                ->get()
                ->map(fn (Sale $sale) => [
                    'id' => $sale->id,
                    'number' => $sale->number,
                    'total' => $sale->total,
                    'due_total' => $sale->due_total,
                    'due_date' => $sale->due_date?->toDateString(),
                    'sold_at' => $sale->sold_at->toIso8601String(),
                    'is_overdue' => $sale->due_date !== null && $sale->due_date->isPast(),
                ])
                ->values(),
            'payments' => CustomerPayment::query()
                ->where('customer_id', $customer->id)
                ->with('receivedBy:id,name,last_name')
                ->orderByDesc('paid_at')
                ->limit(10)
                ->get()
                ->map(fn (CustomerPayment $payment) => [
                    'id' => $payment->id,
                    'amount' => $payment->amount,
                    'method' => $payment->method,
                    'note' => $payment->note,
                    'received_by' => $payment->receivedBy?->full_name,
                    'paid_at' => $payment->paid_at->toIso8601String(),
                ])
                ->values(),
        ];
    }

    protected function authorizeCompany(Customer $customer): void
    {
        abort_if(
            ! auth()->user()->isSuperAdmin() && $customer->company_id !== TenantContext::activeCompanyId(),
            403,
            'Ese cliente no pertenece a tu empresa.'
        );
    }
}
