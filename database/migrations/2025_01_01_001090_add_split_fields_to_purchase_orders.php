<?php

use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lo que hace falta para pedirle a varios proveedores en una sola pasada.
 *
 * En la orden: de donde salio al separarla y cuanto se estimo al crearla. En cada renglon: el
 * proveedor de ese articulo, si no es el de la orden, y una foto de la existencia y el minimo
 * del momento.
 *
 * La foto no es adorno: tres meses despues, cuando alguien pregunta por que se pidieron
 * cincuenta, la respuesta es «habia seis y el minimo era veinte», y ese dato ya no esta en
 * ninguna parte si no se guarda aqui.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->foreignId('parent_order_id')->nullable()->after('supplier_id')
                ->constrained('purchase_orders')->nullOnDelete();
            $table->bigInteger('suggested_total')->default(0)->after('total');
            $table->string('source', 12)->default(PurchaseOrder::SOURCE_MANUAL)->after('suggested_total');
        });

        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->foreignId('supplier_id')->nullable()->after('product_id')->constrained()->nullOnDelete();
            $table->boolean('is_off_supplier')->default(false)->after('supplier_id');
            $table->decimal('suggested_quantity', 12, 3)->default(0)->after('quantity');
            $table->decimal('stock_at_order', 12, 3)->default(0)->after('suggested_quantity');
            $table->decimal('min_at_order', 12, 3)->default(0)->after('stock_at_order');
            $table->string('origin', 12)->default(PurchaseOrderItem::ORIGIN_MANUAL)->after('min_at_order');
        });

        // Las ordenes que ya existen se quedan con lo que se estimo igual al total, y cada
        // renglon con el proveedor de la orden: era el unico que podia tener.
        DB::statement('UPDATE purchase_orders SET suggested_total = total');
        DB::statement('
            UPDATE purchase_order_items
            INNER JOIN purchase_orders ON purchase_orders.id = purchase_order_items.purchase_order_id
            SET purchase_order_items.supplier_id = purchase_orders.supplier_id
        ');
    }

    public function down(): void
    {
        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->dropForeign(['supplier_id']);
            $table->dropColumn([
                'supplier_id', 'is_off_supplier', 'suggested_quantity',
                'stock_at_order', 'min_at_order', 'origin',
            ]);
        });

        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropForeign(['parent_order_id']);
            $table->dropColumn(['parent_order_id', 'suggested_total', 'source']);
        });
    }
};
