<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Cuentas de acceso de los empleados.
 *
 * El empleado es la ficha laboral y el usuario es la llave de entrada: son cosas distintas
 * y se crean por separado. Este servicio es el unico sitio donde se enlazan.
 */
class EmployeeAccessService
{
    public function __construct(protected UserPermissionService $permissions) {}

    /**
     * @return array{user: User, password: string}
     */
    public function createAccount(Employee $employee, string $email, string $roleName, User $actor, ?string $pin = null): array
    {
        if ($employee->user) {
            throw ValidationException::withMessages([
                'access_email' => ['Ese empleado ya tiene una cuenta de acceso.'],
            ]);
        }

        /** @var Role $role */
        $role = Role::query()
            ->where('company_id', $employee->company_id)
            ->where('name', $roleName)
            ->firstOr(fn () => throw ValidationException::withMessages([
                'access_role' => ['Ese rol no existe en la empresa.'],
            ]));

        $password = $this->temporaryPassword();

        $user = DB::transaction(function () use ($employee, $email, $role, $actor, $pin, $password) {
            $user = User::create([
                'company_id' => $employee->company_id,
                'employee_id' => $employee->id,
                'name' => $employee->first_name,
                'last_name' => $employee->last_name,
                'email' => $email,
                'document' => $employee->document_number,
                'phone' => $employee->phone,
                'job_title' => $employee->position,
                'password' => Hash::make($password),
                'pos_pin' => $pin ? Hash::make($pin) : null,
                'is_active' => true,
                // La contrasena temporal se cambia en el primer ingreso: nadie mas debe conocerla.
                'password_change_required' => true,
            ]);

            $user->syncRoles([$role]);
            $this->permissions->applyRoleTemplate($user, $role, $actor);
            $user->stores()->sync([$employee->store_id]);

            return $user;
        });

        return ['user' => $user, 'password' => $password];
    }

    public function toggle(Employee $employee): User
    {
        $user = $this->requireUser($employee);

        $user->update(['is_active' => ! $user->is_active]);

        return $user->refresh();
    }

    public function changeRole(Employee $employee, string $roleName, User $actor): User
    {
        $user = $this->requireUser($employee);

        /** @var Role $role */
        $role = Role::query()
            ->where('company_id', $employee->company_id)
            ->where('name', $roleName)
            ->firstOr(fn () => throw ValidationException::withMessages([
                'role' => ['Ese rol no existe en la empresa.'],
            ]));

        $user->syncRoles([$role]);
        $this->permissions->applyRoleTemplate($user, $role, $actor);

        return $user->refresh();
    }

    public function resetPassword(Employee $employee): string
    {
        $user = $this->requireUser($employee);
        $password = $this->temporaryPassword();

        $user->forceFill([
            'password' => Hash::make($password),
            'password_change_required' => true,
            'password_changed_at' => null,
        ])->save();

        return $password;
    }

    public function resetPin(Employee $employee, string $pin): void
    {
        $user = $this->requireUser($employee);

        $user->forceFill(['pos_pin' => Hash::make($pin)])->save();
    }

    protected function requireUser(Employee $employee): User
    {
        $user = $employee->user;

        if (! $user) {
            throw ValidationException::withMessages([
                'user' => ['Ese empleado todavia no tiene cuenta de acceso.'],
            ]);
        }

        return $user;
    }

    /** Contrasena legible pero no adivinable: se dicta una vez y se cambia al entrar. */
    protected function temporaryPassword(): string
    {
        return Str::ucfirst(Str::lower(Str::random(6))).random_int(100, 999).'*';
    }
}
