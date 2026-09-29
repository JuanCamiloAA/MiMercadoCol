<?php

namespace Database\Seeders;

use App\Helpers\PermissionHelper;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        $rows = array_map(fn (string $name) => [
            'name' => $name,
            'guard_name' => 'web',
            'created_at' => $now,
            'updated_at' => $now,
        ], PermissionHelper::flatPermissions());

        DB::table('permissions')->upsert($rows, ['name', 'guard_name'], ['updated_at']);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
