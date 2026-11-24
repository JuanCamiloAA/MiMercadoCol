<?php

namespace Database\Seeders;

use App\Models\BillingCycle;
use App\Models\Company;
use App\Models\CompanyMembershipEvent;
use App\Models\MembershipPlan;
use App\Models\PaymentTerm;
use App\Models\Register;
use App\Models\Store;
use App\Services\Membership\MembershipService;
use App\Services\PaymentTermService;
use App\Services\Platform\BusinessCategoryCatalog;
use App\Services\SettingsService;
use Illuminate\Database\Seeder;

class CompanySeeder extends Seeder
{
    public function run(): void
    {
        $company = Company::query()->firstOrCreate(
            ['tax_id' => '901456789-3'],
            [
                'name' => 'La Canasta',
                'legal_name' => 'La Canasta S.A.S.',
                'email' => 'contacto@lacanasta.co',
                'phone' => '604 444 1122',
                'address' => 'Carrera 48 # 32-15, Medellín',
                'city' => 'Medellín',
                // El catalogo lo siembra su migracion, antes que las empresas.
                'business_category_id' => app(BusinessCategoryCatalog::class)->defaultId(),
                'currency' => 'COP',
                'is_active' => true,
            ]
        );

        $stores = [
            // Coordenadas reales de Medellin: el buscador de la landing ordena por cercania.
            ['name' => 'Centro', 'code' => 'CEN', 'address' => 'Carrera 48 # 32-15', 'city' => 'Medellín', 'latitude' => 6.2383, 'longitude' => -75.5698, 'phone' => '604 444 1122', 'suggested_cash_base' => 200000],
            ['name' => 'Norte', 'code' => 'NOR', 'address' => 'Calle 104 # 52-30', 'city' => 'Medellín', 'latitude' => 6.2968, 'longitude' => -75.5645, 'phone' => '604 444 3344', 'suggested_cash_base' => 150000],
            ['name' => 'Sur', 'code' => 'SUR', 'address' => 'Calle 30 # 65-12', 'city' => 'Medellín', 'latitude' => 6.2295, 'longitude' => -75.5856, 'phone' => '604 444 5566', 'suggested_cash_base' => 150000],
        ];

        foreach ($stores as $index => $data) {
            $store = Store::query()->withoutGlobalScopes()->firstOrCreate(
                ['company_id' => $company->id, 'code' => $data['code']],
                array_merge($data, [
                    'company_id' => $company->id,
                    'opens_at' => '07:00:00',
                    'closes_at' => '21:00:00',
                    'is_active' => true,
                ])
            );

            $registerCount = $index === 0 ? 3 : 2;

            for ($number = 1; $number <= $registerCount; $number++) {
                Register::query()->withoutGlobalScopes()->firstOrCreate(
                    ['store_id' => $store->id, 'code' => 'C'.$number],
                    [
                        'company_id' => $company->id,
                        'name' => 'Caja '.$number,
                        'is_active' => true,
                    ]
                );
            }
        }

        // Solo las reglas de negocio quedan como filas, para que se vean en la base. La marca,
        // la apariencia y la caja no: sin fila vale el defecto, y asi un defecto que cambie
        // manana le llega tambien a la empresa de demostracion.
        $settings = app(SettingsService::class);

        foreach (['pos.receipt_footer', 'pos.allow_discount_percent', 'pos.cash_rounding', 'credit.block_on_overdue_days'] as $key) {
            $settings->set($company->id, $key, SettingsService::DEFAULTS[$key]);
        }

        if (! PaymentTerm::query()->withoutGlobalScopes()->where('company_id', $company->id)->exists()) {
            app(PaymentTermService::class)->seedDefaults($company);
        }

        // Plan Pro, mensual, al dia y a 25 dias de vencer: se ve la membresia en uso.
        $plan = MembershipPlan::query()->where('slug', 'pro')->first();
        $cycle = BillingCycle::query()->where('code', 'monthly')->first();

        if ($plan && $cycle && $company->membership_plan_id === null) {
            $company->forceFill([
                'membership_plan_id' => $plan->id,
                'billing_cycle_id' => $cycle->id,
                'membership_status' => Company::MEMBERSHIP_ACTIVE,
                'membership_started_at' => now()->subDays(5),
                'membership_ends_at' => now()->addDays(25)->endOfDay(),
                'auto_renew' => false,
            ])->save();

            app(MembershipService::class)->record($company, CompanyMembershipEvent::PROVISIONED, [
                'plan' => $plan->name,
                'cycle' => $cycle->name,
                'status' => Company::MEMBERSHIP_ACTIVE,
            ]);
        }
    }
}
