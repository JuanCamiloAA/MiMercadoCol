<?php

namespace Database\Seeders;

use App\Helpers\PermissionHelper;
use App\Models\User;
use App\Services\Platform\PlatformSnapshot;
use App\Services\StoreDefaultRolesService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Para montar la plataforma en produccion sin datos de demostracion:
 *
 *     php artisan db:seed --class=PlatformSnapshotSeeder --force
 *
 * Crea el super usuario de la plataforma y carga `database/data/platform_snapshot` (planes y ciclos
 * de cobro, clasificaciones de empresa, ajustes de plataforma, landing y plantillas de tienda con
 * sus imagenes y videos). No crea empresas, sedes ni usuarios de empresa, y no llama a ningun otro
 * seeder: por eso no esta en `DatabaseSeeder`. Se puede repetir sin duplicar nada.
 */
class PlatformSnapshotSeeder extends Seeder
{
    public const EMAIL = 'camiloamariles1024@gmail.com';

    /** Bcrypt de la contraseña acordada: en el repositorio nunca va en claro. */
    public const PASSWORD_HASH = '$2y$10$OJEl4TeYVXkQcW3U9xTWZu/Cc8sztrzCtElvCpPKsiUilXtMBV04e';

    public function __construct(
        protected StoreDefaultRolesService $roles,
        protected PlatformSnapshot $snapshot,
    ) {}

    public function run(): void
    {
        // Una base que solo corrio migraciones no tiene el catalogo de permisos.
        $now = now();
        DB::table('permissions')->upsert(array_map(fn (string $name) => [
            'name' => $name, 'guard_name' => 'web', 'created_at' => $now, 'updated_at' => $now,
        ], PermissionHelper::flatPermissions()), ['name', 'guard_name'], ['updated_at']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $user = User::query()->withoutGlobalScopes()->where('email', self::EMAIL)->first();

        // Si ya existe no se le cambia la contraseña: pudo haberla cambiado.
        if (! $user) {
            $user = new User;
            $user->forceFill([
                'email' => self::EMAIL,
                'name' => 'Camilo',
                'last_name' => 'Amariles',
                'job_title' => 'Super administrador',
                'company_id' => null,
                'is_active' => true,
                'password_changed_at' => $now,
            ]);
            // Crudo: el cast `hashed` rechaza un bcrypt con mas rondas que el BCRYPT_ROUNDS del servidor.
            $user->setRawAttributes([...$user->getAttributes(), 'password' => self::PASSWORD_HASH]);
            $user->save();
        }

        $user->syncRoles([$this->roles->createSuperAdminRole()]);
        $user->syncPermissions(Permission::query()->where('guard_name', 'web')->get());

        $result = $this->snapshot->import($user);

        $this->command?->info('Super usuario: '.self::EMAIL);

        foreach ($result as $key => $value) {
            $this->command?->line('  '.str_pad($key, 22).(is_array($value) ? json_encode($value) : $value));
        }
    }
}
