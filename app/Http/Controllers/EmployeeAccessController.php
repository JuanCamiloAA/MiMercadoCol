<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Services\EmployeeAccessService;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class EmployeeAccessController extends Controller
{
    public function __construct(protected EmployeeAccessService $access) {}

    public function store(Request $request, Employee $employee): RedirectResponse
    {
        $this->authorizeCompany($employee);

        $validated = $request->validate([
            'email' => ['required', 'email', 'max:120', Rule::unique('users', 'email')->whereNull('deleted_at')],
            'role' => ['required', 'string', Rule::exists('roles', 'name')->where(fn ($query) => $query->where('company_id', $employee->company_id))],
            'pin' => ['nullable', 'digits:4'],
        ], [
            'email.required' => 'El correo es obligatorio.',
            'email.unique' => 'Ya hay una cuenta con ese correo.',
            'role.required' => 'Elige un rol.',
            'pin.digits' => 'El PIN debe tener 4 digitos.',
        ]);

        $result = $this->access->createAccount(
            $employee,
            $validated['email'],
            $validated['role'],
            $request->user(),
            $validated['pin'] ?? null,
        );

        return back()
            ->with('success', 'La cuenta de acceso quedo creada.')
            ->with('temporary_password', $result['password']);
    }

    public function toggle(Employee $employee): RedirectResponse
    {
        $this->authorizeCompany($employee);

        $user = $this->access->toggle($employee);

        return back()->with('success', $user->is_active ? 'El acceso quedo activo.' : 'El acceso quedo desactivado.');
    }

    public function changeRole(Request $request, Employee $employee): RedirectResponse
    {
        $this->authorizeCompany($employee);

        $validated = $request->validate([
            'role' => ['required', 'string', Rule::exists('roles', 'name')->where(fn ($query) => $query->where('company_id', $employee->company_id))],
        ], ['role.required' => 'Elige un rol.']);

        $this->access->changeRole($employee, $validated['role'], $request->user());

        return back()->with('success', 'El rol de la cuenta quedo cambiado y sus permisos se reemplazaron por los de la plantilla.');
    }

    public function resetPassword(Employee $employee): RedirectResponse
    {
        $this->authorizeCompany($employee);

        $password = $this->access->resetPassword($employee);

        return back()
            ->with('success', 'La contrasena quedo restablecida.')
            ->with('temporary_password', $password);
    }

    public function resetPin(Request $request, Employee $employee): RedirectResponse
    {
        $this->authorizeCompany($employee);

        $validated = $request->validate(
            ['pin' => ['required', 'digits:4']],
            ['pin.required' => 'Escribe el PIN.', 'pin.digits' => 'El PIN debe tener 4 digitos.']
        );

        $this->access->resetPin($employee, $validated['pin']);

        return back()->with('success', 'El PIN de caja quedo actualizado.');
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
