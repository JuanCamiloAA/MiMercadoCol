<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Quien vendio, aparte de quien cobro.
 *
 * No son lo mismo y hasta ahora se confundian en `user_id`. La caja la abre una persona y
 * responde por el efectivo del turno; la venta la puede hacer otra, y es esa la que cuenta
 * para comisiones y para saber quien mueve el mostrador.
 *
 * Apunta a `employees` y no a `users` a proposito: un asesor puede vender sin tener cuenta
 * para entrar al sistema, y aun asi la venta tiene que quedar a su nombre.
 *
 * Nulo quiere decir «no se dijo»: las ventas anteriores a este cambio se quedan asi, porque
 * inventarles un vendedor seria escribir algo que nadie afirmo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->foreignId('seller_employee_id')->nullable()->after('user_id')->constrained('employees')->nullOnDelete();
        });

        // Las ventas que ya existen se atribuyen a la ficha de quien las cobro, cuando la
        // tiene: es el dato que habia, y dejarlas en blanco perderia informacion real.
        DB::statement('
            UPDATE sales
            INNER JOIN users ON users.id = sales.user_id
            SET sales.seller_employee_id = users.employee_id
            WHERE users.employee_id IS NOT NULL
        ');
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropForeign(['seller_employee_id']);
            $table->dropColumn('seller_employee_id');
        });
    }
};
