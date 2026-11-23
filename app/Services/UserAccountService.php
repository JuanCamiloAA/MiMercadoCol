<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use App\Services\Membership\MembershipLimiter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Altas y cambios de cuentas de acceso. Lo usan Usuarios (dentro de una empresa) y Plataforma >
 * Usuarios, para que crear una cuenta desde cualquiera de los dos deje lo mismo: tope del plan,
 * clave temporal, rol con su plantilla de permisos y sedes.
 */
class UserAccountService
{
    /** Datos de la persona que se editan igual en cualquier cuenta. */
    public const PROFILE_FIELDS = ['name', 'last_name', 'email', 'document', 'phone', 'job_title'];

    public function __construct(
        protected UserPermissionService $permissions,
        protected MembershipLimiter $limiter,
    ) {}

    /**
     * @param  array<string, mixed>  $data  datos de la persona, `role` (nombre) y `store_ids`
     * @return array{0: User, 1: string} la cuenta y su clave temporal, que no se vuelve a poder leer
     */
    public function createForCompany(Company $company, array $data, User $actor): array
    {
        $this->limiter->assertCanAdd($company, 'users', 'email');

        $password = self::temporaryPassword();

        $user = DB::transaction(function () use ($company, $data, $actor, $password) {
            $user = User::query()->create([
                ...$this->profile($data),
                'company_id' => $company->id,
                'password' => Hash::make($password),
                'is_active' => true,
                'password_change_required' => true,
            ]);

            $role = $this->companyRole($company->id, (string) $data['role']);
            $user->syncRoles([$role]);
            $this->permissions->applyRoleTemplate($user, $role, $actor);
            $user->stores()->sync($data['store_ids'] ?? []);

            return $user;
        });

        return [$user, $password];
    }

    /**
     * Cambiar de rol reemplaza los permisos: es la unica via en que la plantilla se aplica entera.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateCompanyUser(User $user, array $data, User $actor): void
    {
        DB::transaction(function () use ($user, $data, $actor) {
            $user->update($this->profile($data));

            if ($data['role'] !== $user->roles->first()?->name) {
                $role = $this->companyRole((int) $user->company_id, (string) $data['role']);
                $user->syncRoles([$role]);
                $this->permissions->applyRoleTemplate($user, $role, $actor);
            }

            $user->stores()->sync($data['store_ids'] ?? []);
        });
    }

    /**
     * Un super usuario: sin empresa, sin sedes y sin permisos uno a uno (los tiene todos).
     *
     * @param  array<string, mixed>  $data
     * @return array{0: User, 1: string}
     */
    public function createSuperAdmin(array $data): array
    {
        $password = self::temporaryPassword();

        $user = DB::transaction(function () use ($data, $password) {
            $user = User::query()->create([
                ...$this->profile($data),
                'company_id' => null,
                'password' => Hash::make($password),
                'is_active' => true,
                'password_change_required' => true,
            ]);

            $user->syncRoles([
                Role::query()->where('name', 'super_admin')->where('guard_name', 'web')->whereNull('company_id')->firstOrFail(),
            ]);

            return $user;
        });

        return [$user, $password];
    }

    /**
     * Clave temporal nueva; la anterior deja de servir y al entrar tiene que cambiarla.
     */
    public function resetPassword(User $user): string
    {
        $password = self::temporaryPassword();

        $user->forceFill([
            'password' => Hash::make($password),
            'password_change_required' => true,
            'password_changed_at' => null,
        ])->save();

        return $password;
    }

    public static function temporaryPassword(): string
    {
        return Str::password(12, true, true, false);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function profile(array $data): array
    {
        $profile = [];

        foreach (self::PROFILE_FIELDS as $field) {
            $profile[$field] = $data[$field] ?? null;
        }

        return $profile;
    }

    protected function companyRole(int $companyId, string $name): Role
    {
        /** @var Role */
        return Role::query()->where('company_id', $companyId)->where('name', $name)->firstOrFail();
    }
}
