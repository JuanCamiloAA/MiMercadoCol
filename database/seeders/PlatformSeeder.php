<?php

namespace Database\Seeders;

use App\Models\BillingCycle;
use App\Models\MembershipPlan;
use App\Models\PaymentGatewaySetting;
use App\Services\Membership\Entitlements;
use Illuminate\Database\Seeder;

/**
 * El catalogo de la plataforma: ciclos de cobro, planes de membresia y la pasarela (apagada y
 * sin llaves: se configura en Plataforma > Pasarela de pagos).
 */
class PlatformSeeder extends Seeder
{
    public function run(): void
    {
        $cycles = [
            ['code' => 'monthly', 'name' => 'Mensual', 'months' => 1, 'discount_percent' => 0],
            ['code' => 'quarterly', 'name' => 'Trimestral', 'months' => 3, 'discount_percent' => 5],
            ['code' => 'semiannual', 'name' => 'Semestral', 'months' => 6, 'discount_percent' => 10],
            ['code' => 'annual', 'name' => 'Anual', 'months' => 12, 'discount_percent' => 15],
        ];

        foreach ($cycles as $order => $cycle) {
            BillingCycle::query()->updateOrCreate(['code' => $cycle['code']], [...$cycle, 'is_active' => true, 'sort_order' => $order]);
        }

        $plans = [
            [
                'slug' => 'basico',
                'name' => 'Básico',
                'description' => 'Para el supermercado de barrio con una sede: caja, inventario, compras y cartera.',
                'monthly_price' => 89000,
                'trial_days' => 0,
                'max_stores' => 1,
                'max_users' => 5,
                'max_registers' => 2,
                'entitlements' => Entitlements::preset('basico'),
                'highlights' => ['Punto de venta con caja y cierre', 'Inventario, compras y proveedores', 'Clientes y cartera', 'Informes de ventas e inventario'],
                'is_featured' => false,
            ],
            [
                'slug' => 'pro',
                'name' => 'Pro',
                'description' => 'Varias sedes, tienda en línea con domicilios, promociones y cupones.',
                'monthly_price' => 189000,
                'trial_days' => 0,
                'max_stores' => 3,
                'max_users' => 20,
                'max_registers' => 8,
                'entitlements' => Entitlements::preset('pro'),
                'highlights' => ['Todo lo del Básico', 'Tienda en línea con domicilios', 'Promociones y cupones', 'Hasta 3 sedes'],
                'is_featured' => true,
            ],
            [
                'slug' => 'cadena',
                'name' => 'Cadena',
                'description' => 'Sin topes y con todo: varias bodegas por sede e importación del catálogo externo.',
                'monthly_price' => 390000,
                'trial_days' => 14,
                'max_stores' => null,
                'max_users' => null,
                'max_registers' => null,
                'entitlements' => Entitlements::preset('cadena'),
                'highlights' => ['Todo lo del Pro', 'Sedes, usuarios y cajas sin tope', 'Varias bodegas por sede', 'Importar el catálogo externo'],
                'is_featured' => false,
            ],
        ];

        foreach ($plans as $order => $plan) {
            MembershipPlan::query()->updateOrCreate(['slug' => $plan['slug']], [...$plan, 'is_public' => true, 'is_active' => true, 'sort_order' => $order]);
        }

        PaymentGatewaySetting::wompi();
    }
}
