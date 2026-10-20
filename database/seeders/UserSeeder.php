<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Employee;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Services\UserPermissionService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;

class UserSeeder extends Seeder
{
    public const PASSWORD = 'Password123*';

    /** @var list<array<string, mixed>> */
    protected const ACCOUNTS = [
        ['email' => 'admin@lacanasta.co', 'name' => 'Marcela', 'last_name' => 'Ospina', 'role' => 'admin', 'store' => 'CEN', 'job_title' => 'Administradora', 'document' => '43567123', 'position' => 'Administradora de tienda'],
        ['email' => 'supervisor@lacanasta.co', 'name' => 'Julian', 'last_name' => 'Restrepo', 'role' => 'supervisor', 'store' => 'CEN', 'job_title' => 'Supervisor', 'document' => '71234567', 'position' => 'Supervisor de tienda'],
        ['email' => 'cajera@lacanasta.co', 'name' => 'Diana', 'last_name' => 'Pulgarin', 'role' => 'cajero', 'store' => 'CEN', 'job_title' => 'Cajera', 'document' => '1017234567', 'pin' => '1234', 'position' => 'Cajera'],
        ['email' => 'cajero2@lacanasta.co', 'name' => 'Andres', 'last_name' => 'Zapata', 'role' => 'cajero', 'store' => 'CEN', 'job_title' => 'Cajero', 'document' => '1018345678', 'pin' => '4321', 'position' => 'Cajero'],
        ['email' => 'bodega@lacanasta.co', 'name' => 'Hernan', 'last_name' => 'Gomez', 'role' => 'bodega', 'store' => 'CEN', 'job_title' => 'Auxiliar de bodega', 'document' => '98765432', 'position' => 'Auxiliar de bodega'],
        ['email' => 'domicilios@lacanasta.co', 'name' => 'Camilo', 'last_name' => 'Arias', 'role' => 'domiciliario', 'store' => 'CEN', 'job_title' => 'Domiciliario', 'document' => '1020456789', 'position' => 'Domiciliario'],
        ['email' => 'domicilios2@lacanasta.co', 'name' => 'Sebastian', 'last_name' => 'Munoz', 'role' => 'domiciliario', 'store' => 'CEN', 'job_title' => 'Domiciliario', 'document' => '1020987654', 'position' => 'Domiciliario'],
        ['email' => 'contable@lacanasta.co', 'name' => 'Paula', 'last_name' => 'Cardona', 'role' => 'auxiliar_contable', 'store' => 'CEN', 'job_title' => 'Auxiliar contable', 'document' => '32456789', 'position' => 'Auxiliar contable'],
    ];

    public function __construct(protected UserPermissionService $permissions) {}

    public function run(): void
    {
        $company = Company::query()->firstOrFail();
        $stores = Store::query()->withoutGlobalScopes()->where('company_id', $company->id)->get()->keyBy('code');

        $superAdmin = User::query()->firstOrCreate(
            ['email' => 'super@lacanasta.co'],
            [
                'name' => 'Soporte',
                'last_name' => 'AgoraShop',
                'password' => Hash::make(self::PASSWORD),
                'is_active' => true,
                'job_title' => 'Super administrador',
                'password_changed_at' => now(),
            ]
        );

        $superAdmin->syncRoles(['super_admin']);
        $superAdmin->syncPermissions(Permission::query()->where('guard_name', 'web')->get());

        foreach (self::ACCOUNTS as $account) {
            $store = $stores->get($account['store']);

            $employee = Employee::query()->withoutGlobalScopes()->firstOrCreate(
                ['company_id' => $company->id, 'document_number' => $account['document']],
                [
                    'store_id' => $store->id,
                    'first_name' => $account['name'],
                    'last_name' => $account['last_name'],
                    'document_type' => 'CC',
                    'birth_date' => now()->subYears(random_int(24, 46))->subDays(random_int(0, 360))->toDateString(),
                    'phone' => '3'.random_int(100000000, 199999999),
                    'email' => $account['email'],
                    'address' => 'Medellin, Antioquia',
                    'emergency_contact_name' => 'Familiar',
                    'emergency_contact_phone' => '3'.random_int(100000000, 199999999),
                    'position' => $account['position'],
                    'contract_type' => 'indefinido',
                    'hired_at' => now()->subMonths(random_int(6, 40))->toDateString(),
                    'work_schedule' => $this->defaultSchedule(),
                    'status' => Employee::STATUS_ACTIVE,
                ]
            );

            $user = User::query()->firstOrCreate(
                ['email' => $account['email']],
                [
                    'company_id' => $company->id,
                    'employee_id' => $employee->id,
                    'name' => $account['name'],
                    'last_name' => $account['last_name'],
                    'document' => $account['document'],
                    'password' => Hash::make(self::PASSWORD),
                    'job_title' => $account['job_title'],
                    'pos_pin' => isset($account['pin']) ? Hash::make($account['pin']) : null,
                    'is_active' => true,
                    'password_changed_at' => now(),
                ]
            );

            /** @var Role $role */
            $role = Role::query()->where('company_id', $company->id)->where('name', $account['role'])->firstOrFail();

            $user->syncRoles([$role]);
            $this->permissions->applyRoleTemplate($user, $role, $superAdmin);

            $user->stores()->syncWithoutDetaching([$store->id]);

            // El administrador y el supervisor operan las tres sedes.
            if (in_array($account['role'], ['admin', 'supervisor'], true)) {
                $user->stores()->syncWithoutDetaching($stores->pluck('id')->all());
            }
        }
    }

    /**
     * @return array<string, array{start: string, end: string}>
     */
    protected function defaultSchedule(): array
    {
        return [
            'lunes' => ['start' => '07:00', 'end' => '15:00'],
            'martes' => ['start' => '07:00', 'end' => '15:00'],
            'miercoles' => ['start' => '07:00', 'end' => '15:00'],
            'jueves' => ['start' => '07:00', 'end' => '15:00'],
            'viernes' => ['start' => '07:00', 'end' => '15:00'],
            'sabado' => ['start' => '08:00', 'end' => '14:00'],
        ];
    }
}
