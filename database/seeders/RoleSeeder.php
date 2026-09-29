<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Services\StoreDefaultRolesService;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    public function __construct(protected StoreDefaultRolesService $roles) {}

    public function run(): void
    {
        $this->roles->createSuperAdminRole();

        Company::query()->each(fn (Company $company) => $this->roles->createFor($company));
    }
}
