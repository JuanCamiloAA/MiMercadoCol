<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\CustomerBalance;
use App\Models\CustomerReturn;
use App\Models\Order;
use App\Models\Sale;
use App\Models\StoreCreditMovement;
use App\Services\CreditService;
use App\Services\PaymentTermService;
use App\Services\StoreCreditService;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CustomerController extends Controller
{
    public function __construct(protected CreditService $credit, protected PaymentTermService $terms) {}

    public function index(Request $request): Response
    {
        $companyId = TenantContext::requireCompanyId();

        $filters = [
            'search' => $request->string('search')->toString(),
            'credit' => $request->string('credit')->toString(),
            'status' => $request->string('status')->toString(),
        ];

        $customers = Customer::query()
            ->where('company_id', $companyId)
            ->with('balance')
            ->when($filters['search'], fn ($query, $search) => $query->where(function ($inner) use ($search) {
                $inner->where('name', 'like', "%{$search}%")
                    ->orWhere('document_number', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            }))
            ->when($filters['credit'] === 'enabled', fn ($query) => $query->where('credit_enabled', true))
            ->when($filters['credit'] === 'blocked', fn ($query) => $query->where('credit_blocked', true))
            ->when($filters['status'] !== '', fn ($query) => $query->where('is_active', $filters['status'] === 'active'))
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Customer $customer) => $this->present($customer));

        return Inertia::render('Customers/Index', [
            'customers' => $customers,
            'filters' => $filters,
            'metrics' => [
                'total' => (int) Customer::query()->where('company_id', $companyId)->count(),
                'with_credit' => (int) Customer::query()->where('company_id', $companyId)->where('credit_enabled', true)->count(),
                'blocked' => (int) Customer::query()->where('company_id', $companyId)->where('credit_blocked', true)->count(),
                'online' => (int) Customer::query()->where('company_id', $companyId)->where('is_online_user', true)->count(),
            ],
            'documentTypes' => Customer::DOCUMENT_TYPES,
            // El plazo de credito se elige de la lista de la empresa, no se escribe a mano.
            'paymentTerms' => $this->terms->options($companyId),
            'defaultPaymentTermId' => $this->terms->defaultFor($companyId, PaymentTermService::TYPE_CUSTOMERS)?->id,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $companyId = TenantContext::requireCompanyId();
        $validated = $this->withTerm($this->validateCustomer($request, $companyId), $companyId);

        $password = $validated['password'] ?? null;
        unset($validated['password']);

        $customer = Customer::create(array_merge($validated, [
            'company_id' => $companyId,
            'password' => $password ? Hash::make($password) : null,
        ]));

        CustomerBalance::query()->firstOrCreate(['customer_id' => $customer->id], ['balance' => 0, 'overdue_balance' => 0]);

        return back()->with('success', 'El cliente quedo creado.');
    }

    public function update(Request $request, Customer $customer): RedirectResponse
    {
        $this->authorizeCompany($customer);

        $validated = $this->withTerm($this->validateCustomer($request, $customer->company_id, $customer->id), $customer->company_id, $customer->payment_term_id);
        $password = $validated['password'] ?? null;
        unset($validated['password']);

        $customer->update($validated);

        if ($password) {
            $customer->forceFill(['password' => Hash::make($password)])->save();
        }

        return back()->with('success', 'El cliente quedo actualizado.');
    }

    public function destroy(Customer $customer): RedirectResponse
    {
        $this->authorizeCompany($customer);

        if (Sale::query()->where('customer_id', $customer->id)->exists() || Order::query()->where('customer_id', $customer->id)->exists()) {
            return back()->with('error', 'Ese cliente ya tiene historial: desactivalo en vez de eliminarlo.');
        }

        $customer->delete();

        return back()->with('success', 'El cliente quedo eliminado.');
    }

    public function show(Customer $customer): Response
    {
        $this->authorizeCompany($customer);

        $customer->load('balance');

        return Inertia::render('Customers/Show', [
            'customer' => array_merge($this->present($customer), [
                'email' => $customer->email,
                'address' => $customer->address,
                'neighborhood' => $customer->neighborhood,
                'delivery_notes' => $customer->delivery_notes,
                'business_name' => $customer->business_name,
                'credit_term_days' => $customer->credit_term_days,
                'blocked_reason' => $customer->blocked_reason,
                'is_online_user' => (bool) $customer->is_online_user,
            ]),
            'sales' => Sale::query()
                ->where('customer_id', $customer->id)
                ->orderByDesc('sold_at')
                ->limit(20)
                ->get()
                ->map(fn (Sale $sale) => [
                    'id' => $sale->id,
                    'number' => $sale->number,
                    'status' => $sale->status,
                    'status_label' => $sale->statusLabel(),
                    'total' => $sale->total,
                    'due_total' => $sale->due_total,
                    'due_date' => $sale->due_date?->toDateString(),
                    'sold_at' => $sale->sold_at->toIso8601String(),
                ])
                ->values(),
            'orders' => Order::query()
                ->where('customer_id', $customer->id)
                ->orderByDesc('placed_at')
                ->limit(10)
                ->get()
                ->map(fn (Order $order) => [
                    'id' => $order->id,
                    'number' => $order->number,
                    'status' => $order->status,
                    'status_label' => $order->statusLabel(),
                    'total' => $order->total,
                    'placed_at' => $order->placed_at->toIso8601String(),
                ])
                ->values(),
            // Saldo a favor: lo que se puede gastar hoy, cuando vence lo proximo y el libro.
            'storeCredit' => [
                'available' => app(StoreCreditService::class)->available($customer),
                'next_expiry' => StoreCreditMovement::query()
                    ->where('customer_id', $customer->id)
                    ->where('remaining', '>', 0)
                    ->whereNotNull('expires_at')
                    ->where('expires_at', '>', now())
                    ->orderBy('expires_at')
                    ->first(['remaining', 'expires_at'])
                    ?->only(['remaining', 'expires_at']),
                'movements' => StoreCreditMovement::query()
                    ->where('customer_id', $customer->id)
                    ->with(['customerReturn:id,number', 'sale:id,number', 'order:id,number'])
                    ->latest('occurred_at')
                    ->latest('id')
                    ->limit(15)
                    ->get()
                    ->map(fn (StoreCreditMovement $movement) => [
                        'id' => $movement->id,
                        'type_label' => $movement->typeLabel(),
                        'amount' => $movement->amount,
                        'occurred_at' => $movement->occurred_at->toIso8601String(),
                        'expires_at' => $movement->expires_at?->toIso8601String(),
                        'note' => $movement->note,
                        'return' => $movement->customerReturn ? ['id' => $movement->customerReturn->id, 'number' => $movement->customerReturn->number] : null,
                    ])
                    ->values(),
            ],
            'returns' => CustomerReturn::query()
                ->where('customer_id', $customer->id)
                ->orderByDesc('id')
                ->limit(10)
                ->get(['id', 'number', 'status', 'total', 'resolution', 'created_at'])
                ->map(fn (CustomerReturn $return) => [
                    'id' => $return->id,
                    'number' => $return->number,
                    'status_label' => $return->statusLabel(),
                    'resolution_label' => $return->resolutionLabel(),
                    'total' => $return->total,
                    'created_at' => $return->created_at?->toIso8601String(),
                ])
                ->values(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function present(Customer $customer): array
    {
        return [
            'id' => $customer->id,
            'name' => $customer->name,
            'document_type' => $customer->document_type,
            'document_number' => $customer->document_number,
            'phone' => $customer->phone,
            'email' => $customer->email,
            'address' => $customer->address,
            'neighborhood' => $customer->neighborhood,
            'delivery_notes' => $customer->delivery_notes,
            'business_name' => $customer->business_name,
            'credit_enabled' => (bool) $customer->credit_enabled,
            'credit_blocked' => (bool) $customer->credit_blocked,
            'credit_limit' => $customer->credit_limit,
            'credit_term_days' => $customer->credit_term_days,
            'payment_term_id' => $customer->payment_term_id,
            'is_online_user' => (bool) $customer->is_online_user,
            'is_active' => (bool) $customer->is_active,
            'balance' => $customer->currentBalance(),
            'overdue_balance' => (int) ($customer->balance?->overdue_balance ?? 0),
            'available_credit' => $customer->availableCredit(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function validateCustomer(Request $request, int $companyId, ?int $ignoreId = null): array
    {
        return $request->validate([
            'document_type' => ['required', Rule::in(Customer::DOCUMENT_TYPES)],
            'document_number' => [
                'required', 'string', 'max:40',
                Rule::unique('customers', 'document_number')->ignore($ignoreId)->where(fn ($query) => $query->where('company_id', $companyId)),
            ],
            'name' => ['required', 'string', 'max:120'],
            'business_name' => ['nullable', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:120'],
            'phone' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:160'],
            'neighborhood' => ['nullable', 'string', 'max:80'],
            'delivery_notes' => ['nullable', 'string', 'max:160'],
            'credit_enabled' => ['boolean'],
            'credit_limit' => ['required_if:credit_enabled,true', 'integer', 'min:0'],
            'payment_term_id' => ['required_if:credit_enabled,true', 'nullable', 'integer'],
            'is_online_user' => ['boolean'],
            'password' => ['nullable', 'string', 'min:8', 'max:60'],
            'is_active' => ['boolean'],
        ], [
            'document_number.required' => 'El documento es obligatorio.',
            'document_number.unique' => 'Ya hay un cliente con ese documento.',
            'name.required' => 'El nombre es obligatorio.',
            'credit_limit.required_if' => 'Indica el cupo del cliente.',
            'payment_term_id.required_if' => 'Elige el plazo de pago.',
            'password.min' => 'La contrasena debe tener al menos 8 caracteres.',
        ]);
    }

    /**
     * El plazo elegido, y sus dias copiados en `credit_term_days`, que es lo que leen la mora y
     * el bloqueo de cartera.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    protected function withTerm(array $validated, int $companyId, ?int $current = null): array
    {
        $term = $this->terms->resolve($companyId, $validated['payment_term_id'] ?? null, $current);

        $validated['payment_term_id'] = $term?->id;
        $validated['credit_term_days'] = $term?->days ?? 0;

        return $validated;
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
