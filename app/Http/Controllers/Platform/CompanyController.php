<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\RecordManualPaymentRequest;
use App\Http\Requests\Platform\StoreCompanyRequest;
use App\Http\Requests\Platform\ToggleCompanyStatusRequest;
use App\Http\Requests\Platform\UpdateCompanyRequest;
use App\Models\BillingCycle;
use App\Models\BusinessCategory;
use App\Models\Company;
use App\Models\CompanyEntitlement;
use App\Models\CompanyMembershipEvent;
use App\Models\CompanyPlanPrice;
use App\Models\MembershipCharge;
use App\Models\MembershipPlan;
use App\Models\PlatformLead;
use App\Models\User;
use App\Services\Membership\Entitlements;
use App\Services\Membership\MembershipBillingService;
use App\Services\Membership\MembershipLimiter;
use App\Services\Membership\MembershipPricing;
use App\Services\Membership\MembershipService;
use App\Services\Membership\PlanCatalogue;
use App\Services\Platform\BusinessCategoryCatalog;
use App\Services\Platform\CompanyProvisioner;
use App\Support\Maps\MapConfig;
use App\Support\StoreContext;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Plataforma > Empresas: el catalogo de empresas cliente y su aprovisionamiento.
 *
 * Crear es aprovisionar completa (`CompanyProvisioner`). Desactivar no borra nada. La membresia
 * se cambia a mano con motivo, y queda en la bitacora.
 */
class CompanyController extends Controller
{
    public function __construct(
        protected MembershipLimiter $limiter,
        protected MembershipService $membership,
    ) {}

    public function index(Request $request): Response
    {
        $filters = $this->filters($request);

        $companies = $this->query($filters)
            ->with(['plan:id,name,max_stores,max_users,max_registers', 'cycle:id,name', 'businessCategory:id,name'])
            ->withCount([
                // Sin `CompanyScope`: con otra empresa elegida arriba contaria cero.
                'stores as active_stores_count' => fn ($query) => $query->withoutGlobalScopes()->where('is_active', true),
                'users as active_users_count' => fn ($query) => $query->where('is_active', true),
            ])
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Company $company) => $this->row($company));

        $counts = Company::query()
            ->select('membership_status', DB::raw('COUNT(*) as total'))
            ->groupBy('membership_status')
            ->pluck('total', 'membership_status');

        return Inertia::render('Platform/Companies/Index', [
            'companies' => $companies,
            // Con los mismos nombres de la direccion (`?estado=grace&orden=ends`).
            'filters' => [
                'q' => $filters['q'],
                'estado' => $filters['status'],
                'plan' => $filters['plan'] ? (string) $filters['plan'] : '',
                'clasificacion' => $filters['category'] ? (string) $filters['category'] : '',
                'orden' => $filters['sort'] === 'name' ? '' : $filters['sort'],
            ],
            'counts' => [
                'all' => (int) $counts->sum(),
                ...collect(Company::MEMBERSHIP_STATUSES)->mapWithKeys(fn ($status) => [$status => (int) ($counts[$status] ?? 0)])->all(),
                'inactive' => Company::query()->where('is_active', false)->count(),
            ],
            'statusOptions' => $this->statusOptions(),
            'planOptions' => MembershipPlan::query()->ordered()->get(['id', 'name'])->map(fn ($plan) => ['value' => $plan->id, 'label' => $plan->name]),
            'categoryOptions' => BusinessCategory::query()->ordered()->get(['id', 'name'])->map(fn ($category) => ['value' => $category->id, 'label' => $category->name]),
            'sortOptions' => [['value' => '', 'label' => 'Por nombre'], ['value' => 'ends', 'label' => 'Vencen primero']],
            'activeCompanyId' => TenantContext::superAdminSelectedCompanyId(),
        ]);
    }

    /**
     * El listado filtrado en CSV, para abrirlo en una hoja de calculo.
     */
    public function export(Request $request): StreamedResponse
    {
        $filters = $this->filters($request);
        $rows = $this->query($filters)
            ->with(['plan:id,name,max_stores,max_users,max_registers', 'cycle:id,name', 'businessCategory:id,name'])
            ->withCount([
                'stores as active_stores_count' => fn ($query) => $query->withoutGlobalScopes()->where('is_active', true),
                'users as active_users_count' => fn ($query) => $query->where('is_active', true),
            ])
            ->get();

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            // BOM: Excel en espanol abre bien las tildes solo con esto.
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Empresa', 'NIT', 'Ciudad', 'Clasificación', 'Correo', 'Plan', 'Ciclo', 'Estado', 'Vence', 'Sedes', 'Tope sedes', 'Usuarios', 'Tope usuarios', 'Activa'], ';');

            foreach ($rows as $company) {
                fputcsv($out, [
                    $company->name,
                    $company->tax_id,
                    $company->city,
                    $company->businessCategory?->name,
                    $company->email,
                    $company->plan?->name,
                    $company->cycle?->name,
                    $company->membershipLabel(),
                    $company->membership_ends_at?->timezone('America/Bogota')->format('Y-m-d'),
                    $company->active_stores_count,
                    $company->plan?->max_stores ?? 'Sin tope',
                    $company->active_users_count,
                    $company->plan?->max_users ?? 'Sin tope',
                    $company->is_active ? 'Sí' : 'No',
                ], ';');
            }

            fclose($out);
        }, 'empresas-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function create(Request $request): Response
    {
        $lead = $request->integer('solicitud') ? PlatformLead::query()->find($request->integer('solicitud')) : null;

        return Inertia::render('Platform/Companies/Create', [
            ...$this->membershipOptions(),
            'categories' => app(BusinessCategoryCatalog::class)->options(),
            'map' => MapConfig::current(),
            // Crear desde una solicitud: sus datos llegan puestos.
            'lead' => $lead ? [
                'id' => $lead->id,
                'company_name' => $lead->company_name,
                'tax_id' => $lead->tax_id,
                'city' => $lead->city,
                'business_category_id' => $lead->business_category_id,
                'contact_name' => $lead->contact_name,
                'email' => $lead->email,
                'phone' => $lead->phone,
                'membership_plan_id' => $lead->membership_plan_id,
                'billing_cycle_id' => $lead->billing_cycle_id,
            ] : null,
        ]);
    }

    public function store(StoreCompanyRequest $request, CompanyProvisioner $provisioner): RedirectResponse
    {
        $result = $provisioner->provision($request->provisioningData(), $request->user());

        if ($leadId = $request->validated('lead_id')) {
            PlatformLead::query()->whereKey($leadId)->update([
                'status' => PlatformLead::STATUS_WON,
                'company_id' => $result->company->id,
                'handled_by_user_id' => $request->user()->id,
            ]);
        }

        return redirect()->route('companies.edit', $result->company)
            ->with('success', "La empresa {$result->company->name} quedó creada. Su administrador entra con {$result->admin->email}.")
            ->with('temporary_password', $result->temporaryPassword);
    }

    public function edit(Company $company): Response
    {
        $company->load(['plan', 'cycle', 'nextPlan:id,name', 'nextCycle:id,name', 'paymentMethod']);

        return Inertia::render('Platform/Companies/Edit', [
            ...$this->membershipOptions(),
            'categories' => app(BusinessCategoryCatalog::class)->options($company->business_category_id),
            'company' => [
                'id' => $company->id,
                'name' => $company->name,
                'slug' => $company->slug,
                'legal_name' => $company->legal_name,
                'tax_id' => $company->tax_id,
                'email' => $company->email,
                'phone' => $company->phone,
                'address' => $company->address,
                'city' => $company->city,
                'business_category_id' => $company->business_category_id,
                'is_active' => $company->is_active,
                'show_on_landing' => $company->show_on_landing,
                'deactivated_at' => $company->deactivated_at?->toIso8601String(),
                'deactivation_reason' => $company->deactivation_reason,
                'membership_plan_id' => $company->membership_plan_id,
                'billing_cycle_id' => $company->billing_cycle_id,
                'membership_status' => $company->membership_status,
                'membership_label' => $company->membershipLabel(),
                'membership_started_at' => $company->membership_started_at?->toIso8601String(),
                'membership_ends_at' => $company->membership_ends_at?->timezone('America/Bogota')->toDateString(),
                'grace_ends_at' => $company->grace_ends_at?->timezone('America/Bogota')->toDateString(),
                'next_charge_at' => $company->next_charge_at?->toIso8601String(),
                'days_left' => MembershipService::daysLeft($company),
                'auto_renew' => $company->auto_renew,
                'next_plan' => $company->nextPlan?->name,
                'next_cycle' => $company->nextCycle?->name,
                'card' => $company->paymentMethod ? [
                    'brand' => $company->paymentMethod->brand,
                    'last4' => $company->paymentMethod->last4,
                    'exp' => sprintf('%02d/%02d', $company->paymentMethod->exp_month, $company->paymentMethod->exp_year % 100),
                ] : null,
            ],
            'usage' => $this->limiter->usage($company),
            'planAccess' => $this->planAccess($company),
            'admins' => User::query()
                ->where('company_id', $company->id)
                ->whereHas('roles', fn ($query) => $query->where('name', 'admin'))
                ->orderBy('name')
                ->get(['id', 'name', 'last_name', 'email', 'is_active', 'password_change_required'])
                ->map(fn (User $user) => [
                    'id' => $user->id,
                    'name' => trim($user->name.' '.$user->last_name),
                    'email' => $user->email,
                    'is_active' => $user->is_active,
                    'pending_password' => $user->password_change_required,
                ]),
            'events' => CompanyMembershipEvent::query()
                ->where('company_id', $company->id)
                ->with('user:id,name,last_name')
                ->latest('id')
                ->limit(30)
                ->get()
                ->map(fn (CompanyMembershipEvent $event) => [
                    'id' => $event->id,
                    'type' => $event->type,
                    'label' => CompanyMembershipEvent::LABELS[$event->type] ?? $event->type,
                    'data' => $event->data,
                    'user' => $event->user ? trim($event->user->name.' '.$event->user->last_name) : null,
                    'created_at' => $event->created_at?->toIso8601String(),
                ]),
            'charges' => MembershipCharge::query()
                ->where('company_id', $company->id)
                ->latest('id')
                ->limit(12)
                ->get()
                ->map(fn (MembershipCharge $charge) => [
                    'id' => $charge->id,
                    'reference' => $charge->reference,
                    'concept' => $charge->concept,
                    'amount' => $charge->amount,
                    'status' => $charge->status,
                    'status_label' => $charge->statusLabel(),
                    'method' => MembershipCharge::METHOD_LABELS[$charge->method] ?? $charge->method,
                    'charged_at' => $charge->charged_at?->toIso8601String(),
                ]),
        ]);
    }

    public function update(UpdateCompanyRequest $request, Company $company): RedirectResponse
    {
        $changes = $request->membershipChanges();

        DB::transaction(function () use ($request, $company, $changes) {
            $company->update($request->legalData());

            if ($changes !== []) {
                $this->membership->manualUpdate($company, $changes, trim((string) $request->validated('membership_reason')), $request->user());
            }
        });

        return back()->with('success', 'Los cambios de la empresa quedaron guardados.');
    }

    public function status(ToggleCompanyStatusRequest $request, Company $company): RedirectResponse
    {
        $active = $request->boolean('is_active');

        $company->update([
            'is_active' => $active,
            'deactivated_at' => $active ? null : now(),
            'deactivation_reason' => $active ? null : $request->validated('reason'),
        ]);

        $this->membership->record($company, $active ? CompanyMembershipEvent::ACTIVATED : CompanyMembershipEvent::DEACTIVATED, [
            'reason' => $request->validated('reason'),
        ], $request->user());

        return back()->with('success', $active
            ? "{$company->name} quedó activa otra vez."
            : "{$company->name} quedó desactivada: sus usuarios ya no pueden entrar.");
    }

    /**
     * Un pago recibido por fuera de la pasarela: renueva el periodo igual que uno en linea.
     */
    public function recordPayment(RecordManualPaymentRequest $request, Company $company, MembershipBillingService $billing): RedirectResponse
    {
        $charge = $billing->recordManual($company, $request->user(), trim($request->validated('note')));

        return back()->with('success', "Pago registrado ({$charge->reference}). La membresía vence ahora el ".
            $company->fresh()->membership_ends_at?->timezone('America/Bogota')->format('d/m/Y').'.');
    }

    /**
     * Elegir la empresa y entrar a su inicio, como su administrador.
     */
    public function enter(Company $company): RedirectResponse
    {
        session([TenantContext::SESSION_KEY => $company->id]);
        session()->forget(StoreContext::SESSION_KEY);
        TenantContext::forgetCompany();

        return redirect()->route('dashboard')->with('success', "Ahora trabajas en {$company->name}.");
    }

    /**
     * @return array{q: string, status: string, plan: ?int, category: ?int, sort: string}
     */
    protected function filters(Request $request): array
    {
        $status = (string) $request->string('estado');
        $sort = (string) $request->string('orden');

        return [
            'q' => trim((string) $request->string('q')),
            'status' => in_array($status, [...Company::MEMBERSHIP_STATUSES, 'inactive'], true) ? $status : '',
            'plan' => $request->integer('plan') ?: null,
            'category' => $request->integer('clasificacion') ?: null,
            'sort' => in_array($sort, ['name', 'ends'], true) ? $sort : 'name',
        ];
    }

    /**
     * @param  array{q: string, status: string, plan: ?int, category: ?int, sort: string}  $filters
     */
    protected function query(array $filters): Builder
    {
        return Company::query()
            ->when($filters['q'] !== '', fn (Builder $query) => $query->where(function (Builder $inner) use ($filters) {
                $term = '%'.$filters['q'].'%';
                $inner->where('name', 'like', $term)->orWhere('tax_id', 'like', $term)->orWhere('city', 'like', $term)->orWhere('email', 'like', $term);
            }))
            ->when($filters['status'] === 'inactive', fn (Builder $query) => $query->where('is_active', false))
            ->when($filters['status'] !== '' && $filters['status'] !== 'inactive', fn (Builder $query) => $query->where('membership_status', $filters['status']))
            ->when($filters['plan'] !== null, fn (Builder $query) => $query->where('membership_plan_id', $filters['plan']))
            ->when($filters['category'] !== null, fn (Builder $query) => $query->where('business_category_id', $filters['category']))
            ->when(
                $filters['sort'] === 'ends',
                fn (Builder $query) => $query->orderByRaw('membership_ends_at IS NULL')->orderBy('membership_ends_at'),
                fn (Builder $query) => $query->orderBy('name'),
            );
    }

    /**
     * @return array<string, mixed>
     */
    protected function row(Company $company): array
    {
        return [
            'id' => $company->id,
            'name' => $company->name,
            'tax_id' => $company->tax_id,
            'city' => $company->city,
            'category' => $company->businessCategory?->name,
            'is_active' => $company->is_active,
            'plan' => $company->plan?->name,
            'cycle' => $company->cycle?->name,
            'membership_status' => $company->membership_status,
            'membership_label' => $company->membershipLabel(),
            'membership_ends_at' => $company->membership_ends_at?->toIso8601String(),
            'days_left' => MembershipService::daysLeft($company),
            'stores' => ['used' => (int) $company->active_stores_count, 'limit' => $company->plan?->max_stores],
            'users' => ['used' => (int) $company->active_users_count, 'limit' => $company->plan?->max_users],
        ];
    }

    /**
     * La pestaña «Plan y funciones»: el arbol con el origen de cada nodo, las excepciones y los
     * precios especiales (vigentes y su historia).
     *
     * @return array<string, mixed>
     */
    protected function planAccess(Company $company): array
    {
        $now = now();
        $cycles = BillingCycle::query()->active()->ordered()->get();
        $status = fn ($row) => match (true) {
            $row->ends_at !== null && $row->ends_at->lte($now) => 'ended',
            $row->starts_at->gt($now) => 'scheduled',
            default => 'active',
        };
        $person = fn (?User $user) => $user ? trim($user->name.' '.$user->last_name) : null;

        return [
            'tree' => $company->entitlement()->tree(),
            'catalogue' => app(PlanCatalogue::class)->editorCatalogue(),
            'always' => Entitlements::alwaysPermissions(),
            'exceptions' => $company->entitlementExceptions()
                ->with(['createdBy:id,name,last_name', 'endedBy:id,name,last_name'])
                ->limit(60)
                ->get()
                ->map(fn (CompanyEntitlement $exception) => [
                    'id' => $exception->id,
                    'node' => $exception->node,
                    'label' => Entitlements::label($exception->node),
                    'effect' => $exception->effect,
                    'starts_at' => $exception->starts_at?->toIso8601String(),
                    'ends_at' => $exception->ends_at?->toIso8601String(),
                    'reason' => $exception->reason,
                    'status' => $status($exception),
                    'created_by' => $person($exception->createdBy),
                    'ended_by' => $person($exception->endedBy),
                ])->values()->all(),
            'prices' => $company->planPrices()
                ->with(['plan:id,name', 'createdBy:id,name,last_name'])
                ->limit(30)
                ->get()
                ->map(fn (CompanyPlanPrice $price) => [
                    'id' => $price->id,
                    'plan' => $price->plan?->name,
                    'membership_plan_id' => $price->membership_plan_id,
                    'description' => $price->describe(),
                    'monthly_price' => $price->monthly_price,
                    'discount_percent' => $price->discount_percent,
                    'starts_at' => $price->starts_at?->toIso8601String(),
                    'ends_at' => $price->ends_at?->toIso8601String(),
                    'reason' => $price->reason,
                    'status' => $status($price),
                    'created_by' => $person($price->createdBy),
                ])->values()->all(),
            // Lo que paga hoy en cada ciclo de su plan, con y sin el precio especial.
            'pricing' => $company->plan ? $cycles->map(fn (BillingCycle $cycle) => [
                'cycle' => $cycle->name,
                'list' => MembershipPricing::priceFor($company->plan, $cycle),
                'company' => MembershipPricing::priceFor($company->plan, $cycle, $company),
            ])->values()->all() : [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function membershipOptions(): array
    {
        return [
            'plans' => MembershipPlan::query()->ordered()->get()->map(fn (MembershipPlan $plan) => [
                'value' => $plan->id,
                'label' => $plan->name.($plan->is_active ? '' : ' (inactivo)'),
                'trial_days' => $plan->trial_days,
                'is_active' => $plan->is_active,
            ]),
            'cycles' => BillingCycle::query()->ordered()->get()->map(fn (BillingCycle $cycle) => [
                'value' => $cycle->id,
                'label' => $cycle->name.($cycle->is_active ? '' : ' (inactivo)'),
                'months' => $cycle->months,
                'is_active' => $cycle->is_active,
            ]),
            'statusOptions' => $this->statusOptions(),
        ];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    protected function statusOptions(): array
    {
        return array_map(
            fn (string $status) => ['value' => $status, 'label' => Company::MEMBERSHIP_LABELS[$status]],
            Company::MEMBERSHIP_STATUSES,
        );
    }
}
