<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Paso 2: cada pago de venta y cada pedido que ya existia apunta a su medio configurado.
 *
 * - Ventas de la caja: el medio de fabrica de su `kind`.
 * - Ventas en linea (pedidos entregados): el efectivo y el datafono son los «al recibir».
 * - Pedidos: su clave de siempre (`cash_on_delivery`, `card`, `transfer`, `credit`).
 *
 * Como una venta tenia a lo sumo un pago por `kind`, despues de esto tiene a lo sumo uno por
 * medio: el indice unico nuevo se puede crear (paso 3).
 */
return new class extends Migration
{
    protected const POS = [
        'cash' => 'cash', 'card' => 'card', 'transfer' => 'transfer', 'credit' => 'credit',
        'store_credit' => 'store_credit', 'exchange' => 'exchange',
    ];

    protected const ONLINE = [
        'cash' => 'cash_on_delivery', 'card' => 'card_on_delivery', 'transfer' => 'transfer', 'credit' => 'credit',
        'store_credit' => 'store_credit', 'exchange' => 'exchange',
    ];

    protected const ORDERS = [
        'cash_on_delivery' => 'cash_on_delivery', 'card' => 'card_on_delivery', 'transfer' => 'transfer', 'credit' => 'credit',
    ];

    public function up(): void
    {
        foreach (DB::table('companies')->orderBy('id')->pluck('id') as $companyId) {
            $methods = DB::table('payment_methods')->where('company_id', $companyId)->whereNotNull('system_key')->pluck('id', 'system_key');

            foreach (['pos' => self::POS, 'online' => self::ONLINE] as $channel => $map) {
                foreach ($map as $kind => $key) {
                    if (! isset($methods[$key])) {
                        continue;
                    }

                    DB::table('sale_payments')
                        ->join('sales', 'sales.id', '=', 'sale_payments.sale_id')
                        ->where('sales.company_id', $companyId)
                        ->where('sales.channel', $channel)
                        ->where('sale_payments.method', $kind)
                        ->whereNull('sale_payments.payment_method_id')
                        ->update(['sale_payments.payment_method_id' => $methods[$key]]);
                }
            }

            // Un canal que no fuera ninguno de los dos: el de la caja.
            foreach (self::POS as $kind => $key) {
                if (isset($methods[$key])) {
                    DB::table('sale_payments')
                        ->join('sales', 'sales.id', '=', 'sale_payments.sale_id')
                        ->where('sales.company_id', $companyId)
                        ->where('sale_payments.method', $kind)
                        ->whereNull('sale_payments.payment_method_id')
                        ->update(['sale_payments.payment_method_id' => $methods[$key]]);
                }
            }

            foreach (self::ORDERS as $paymentKey => $key) {
                if (isset($methods[$key])) {
                    DB::table('orders')
                        ->where('company_id', $companyId)
                        ->where('payment_method', $paymentKey)
                        ->whereNull('payment_method_id')
                        ->update(['payment_method_id' => $methods[$key]]);
                }
            }
        }
    }

    public function down(): void
    {
        DB::table('sale_payments')->update(['payment_method_id' => null]);
        DB::table('orders')->update(['payment_method_id' => null]);
    }
};
