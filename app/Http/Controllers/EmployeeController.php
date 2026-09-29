<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreEmployeeRequest;
use App\Http\Requests\UpdateEmployeeRequest;
use App\Models\Employee;
use App\Models\Role;
use App\Models\Sale;
use App\Models\Store;
use App\Models\User;
use App\Services\EmployeeAccessService;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EmployeeController extends Controller
{
    public function __construct(protected EmployeeAccessService $access) {}

    public function index(Request $request): Response
    {
        $companyId = TenantContext::requireCompanyId();

        $filters = [
            'search' => $request->string('search')->toString(),
            'position' => $request->string('position')->toString(),
            'store' => $request->string('store')->toString(),
            'status' => $request->string('status')->toString(),
        ];

        $employees = Employee::query()
            ->where('company_id', $companyId)
            ->with(['store:id,name', 'user.roles:id,name,display_name,color'])
            ->when($filters['search'], fn ($query, $search) => $query->where(function ($inner) use ($search) {
                $inner->where('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('document_number', 'like', "%{$search}%")
                    ->orWhere('position', 'like', "%{$search}%");
            }))
            ->when($filters['position'], fn ($query, $position) => $query->where('position', $position))
            ->when($filters['store'], fn ($query, $store) => $query->where('store_id', $store))
            ->when($filters['status'], fn ($query, $status) => $query->where('status', $status))
            ->orderBy('first_name')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Employee $employee) => $this->present($employee));

        return Inertia::render('Employees/Index', [
            'employees' => $employees,
            'filters' => $filters,
            'metrics' => $this->metrics($companyId),
            'positions' => $this->positionOptions($companyId),
            'stores' => $this->storeOptions($companyId),
        ]);
    }

    public function create(): Response
    {
        $companyId = TenantContext::requireCompanyId();

        return Inertia::render('Employees/Form', [
            'mode' => 'create',
            'employee' => null,
            'stores' => $this->storeOptions($companyId),
            'roles' => $this->roleOptions($companyId),
            'canCreateAccess' => request()->user()->can('employees.access.create'),
        ]);
    }

    public function store(StoreEmployeeRequest $request): RedirectResponse
    {
        $companyId = TenantContext::requireCompanyId();
        $validated = $request->validated();

        $employee = Employee::create(array_merge(
            collect($validated)->except(['create_access', 'access_email', 'access_role', 'access_pin'])->all(),
            ['company_id' => $companyId, 'status' => Employee::STATUS_ACTIVE]
        ));

        if (! empty($validated['create_access'])) {
            $result = $this->access->createAccount(
                $employee,
                $validated['access_email'],
                $validated['access_role'],
                $request->user(),
                $validated['access_pin'] ?? null,
            );

            return redirect()->route('employees.show', $employee)
                ->with('success', 'El empleado y su cuenta de acceso quedaron creados.')
                ->with('temporary_password', $result['password']);
        }

        return redirect()->route('employees.show', $employee)->with('success', 'El empleado quedo creado.');
    }

    public function show(Employee $employee): Response
    {
        $this->authorizeCompany($employee);

        $employee->load(['store:id,name', 'user.roles:id,name,display_name,color', 'user.stores:id,name']);

        return Inertia::render('Employees/Show', [
            'employee' => array_merge($this->present($employee), [
                'birth_date' => $employee->birth_date?->toDateString(),
                'address' => $employee->address,
                'email' => $employee->email,
                'emergency_contact_name' => $employee->emergency_contact_name,
                'emergency_contact_phone' => $employee->emergency_contact_phone,
                'contract_type' => $employee->contract_type,
                'terminated_at' => $employee->terminated_at?->toDateString(),
                'work_schedule' => $employee->work_schedule ?? [],
            ]),
            'performance' => $this->performance($employee),
            'roles' => $this->roleOptions($employee->company_id),
        ]);
    }

    public function edit(Employee $employee): Response
    {
        $this->authorizeCompany($employee);

        return Inertia::render('Employees/Form', [
            'mode' => 'edit',
            'employee' => [
                'id' => $employee->id,
                'store_id' => $employee->store_id,
                'first_name' => $employee->first_name,
                'last_name' => $employee->last_name,
                'document_type' => $employee->document_type,
                'document_number' => $employee->document_number,
                'birth_date' => $employee->birth_date?->toDateString(),
                'phone' => $employee->phone,
                'email' => $employee->email,
                'address' => $employee->address,
                'emergency_contact_name' => $employee->emergency_contact_name,
                'emergency_contact_phone' => $employee->emergency_contact_phone,
                'position' => $employee->position,
                'contract_type' => $employee->contract_type,
                'hired_at' => $employee->hired_at?->toDateString(),
                'work_schedule' => $employee->work_schedule ?? [],
            ],
            'stores' => $this->storeOptions($employee->company_id),
            'roles' => $this->roleOptions($employee->company_id),
            'canCreateAccess' => false,
        ]);
    }

    public function update(UpdateEmployeeRequest $request, Employee $employee): RedirectResponse
    {
        $this->authorizeCompany($employee);

        $employee->update($request->validated());

        return redirect()->route('employees.show', $employee)->with('success', 'La ficha quedo actualizada.');
    }

    public function destroy(Employee $employee): RedirectResponse
    {
        $this->authorizeCompany($employee);

        if ($employee->user) {
            return back()->with('error', 'Ese empleado tiene una cuenta de acceso: desactivalo en vez de eliminarlo.');
        }

        $employee->delete();

        return redirect()->route('employees.index')->with('success', 'El empleado quedo eliminado.');
    }

    public function deactivate(Employee $employee): RedirectResponse
    {
        $this->authorizeCompany($employee);

        DB::transaction(function () use ($employee) {
            $employee->update([
                'status' => Employee::STATUS_INACTIVE,
                'terminated_at' => now()->toDateString(),
            ]);

            // Dar de baja al empleado apaga su acceso: dejarlo abierto seria un hueco de seguridad.
            $employee->user?->update(['is_active' => false]);
        });

        return back()->with('success', 'El empleado quedo desactivado.');
    }

    public function reactivate(Employee $employee): RedirectResponse
    {
        $this->authorizeCompany($employee);

        $employee->update([
            'status' => Employee::STATUS_ACTIVE,
            'terminated_at' => null,
        ]);

        return back()->with('success', 'El empleado quedo reactivado.');
    }

    public function export(): StreamedResponse
    {
        $companyId = TenantContext::requireCompanyId();

        $employees = Employee::query()
            ->where('company_id', $companyId)
            ->with('store:id,name')
            ->orderBy('first_name')
            ->get();

        $filename = 'empleados-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($employees) {
            $handle = fopen('php://output', 'wb');
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, ['Nombre', 'Documento', 'Cargo', 'Sede', 'Contrato', 'Ingreso', 'Estado', 'Telefono', 'Correo'], ';');

            foreach ($employees as $employee) {
                fputcsv($handle, [
                    $employee->full_name,
                    $employee->document_type.' '.$employee->document_number,
                    $employee->position,
                    $employee->store?->name,
                    $employee->contract_type,
                    $employee->hired_at?->toDateString(),
                    $employee->status === Employee::STATUS_ACTIVE ? 'Activo' : 'Inactivo',
                    $employee->phone,
                    $employee->email,
                ], ';');
            }

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @return array<string, mixed>
     */
    protected function present(Employee $employee): array
    {
        $user = $employee->user;

        return [
            'id' => $employee->id,
            'first_name' => $employee->first_name,
            'last_name' => $employee->last_name,
            'full_name' => $employee->full_name,
            'document_type' => $employee->document_type,
            'document_number' => $employee->document_number,
            'phone' => $employee->phone,
            'position' => $employee->position,
            'hired_at' => $employee->hired_at?->toDateString(),
            'status' => $employee->status,
            'store' => $employee->store ? ['id' => $employee->store->id, 'name' => $employee->store->name] : null,
            'user' => $user ? [
                'id' => $user->id,
                'email' => $user->email,
                'is_active' => (bool) $user->is_active,
                'has_pin' => $user->pos_pin !== null,
                'password_change_required' => (bool) $user->password_change_required,
                'last_login_at' => $user->last_login_at?->toIso8601String(),
                'role' => $user->roles->first() ? [
                    'name' => $user->roles->first()->name,
                    'display_name' => $user->roles->first()->display_name ?: $user->roles->first()->name,
                    'color' => $user->roles->first()->color,
                ] : null,
            ] : null,
        ];
    }

    /**
     * @return array<string, int>
     */
    protected function metrics(int $companyId): array
    {
        $base = Employee::query()->where('company_id', $companyId);

        return [
            'active' => (int) $base->clone()->where('status', Employee::STATUS_ACTIVE)->count(),
            'with_access' => (int) $base->clone()->whereHas('user')->count(),
            'without_pin' => (int) $base->clone()->whereHas('user', fn ($query) => $query->whereNull('pos_pin'))->count(),
            'inactive' => (int) $base->clone()->where('status', Employee::STATUS_INACTIVE)->count(),
        ];
    }

    /**
     * Ventas y tiquetes del mes, solo si el empleado tiene cuenta.
     *
     * @return array<string, mixed>
     */
    protected function performance(Employee $employee): array
    {
        if (! $employee->user) {
            return ['sales_total' => 0, 'tickets' => 0, 'has_account' => false];
        }

        $from = CarbonImmutable::now()->startOfMonth();

        $row = Sale::query()
            ->withoutGlobalScopes()
            ->where('user_id', $employee->user->id)
            ->where('sold_at', '>=', $from)
            ->where('status', '!=', Sale::STATUS_CANCELLED)
            ->selectRaw('COALESCE(SUM(total), 0) as total, COUNT(*) as count')
            ->first();

        return [
            'sales_total' => (int) $row->total,
            'tickets' => (int) $row->count,
            'has_account' => true,
        ];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    protected function positionOptions(int $companyId): array
    {
        return Employee::query()
            ->where('company_id', $companyId)
            ->distinct()
            ->orderBy('position')
            ->pluck('position')
            ->map(fn (string $position) => ['value' => $position, 'label' => $position])
            ->values()
            ->all();
    }

    /**
     * @return list<array{value: int, label: string}>
     */
    protected function storeOptions(int $companyId): array
    {
        return Store::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->map(fn (Store $store) => ['value' => $store->id, 'label' => $store->name])
            ->values()
            ->all();
    }

    /**
     * @return list<array{value: string, label: string, color: string}>
     */
    protected function roleOptions(int $companyId): array
    {
        return Role::query()
            ->where('company_id', $companyId)
            ->orderBy('display_name')
            ->get()
            ->map(fn (Role $role) => ['value' => $role->name, 'label' => $role->label(), 'color' => $role->color])
            ->values()
            ->all();
    }

    protected function authorizeCompany(Employee $employee): void
    {
        abort_if(
            ! auth()->user()->isSuperAdmin() && $employee->company_id !== TenantContext::activeCompanyId(),
            403,
            'Ese empleado no pertenece a tu empresa.'
        );
    }
}
