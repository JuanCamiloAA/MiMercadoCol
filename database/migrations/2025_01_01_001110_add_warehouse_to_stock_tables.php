<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El inventario pasa de llevarse por sede a llevarse por bodega.
 *
 * Cada sede que ya existe recibe su «Bodega principal» con las tres banderas encendidas, y
 * todo lo que hoy cuelga de `store_id` queda apuntando a ella. Una empresa que nunca cree una
 * segunda bodega no deberia notar nada: mismos saldos, mismo kardex, mismos lotes.
 *
 * `store_id` se conserva desnormalizado en las tres tablas de inventario porque medio sistema
 * lo usa en joins e indices; la regla es que siempre coincida con el `store_id` de la bodega.
 */
return new class extends Migration
{
    /** @var list<string> */
    protected array $tables = ['product_stocks', 'stock_movements', 'product_lots'];

    public function up(): void
    {
        $this->createPrincipalWarehouses();

        foreach ($this->tables as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->unsignedBigInteger('warehouse_id')->nullable()->after('store_id');
            });
        }

        Schema::table('orders', function (Blueprint $table) {
            // La bodega que reservo. Si despues cambian la bandera, el pedido se despacha de
            // donde reservo: lo apartado esta en ese estante, no en el que hoy vende.
            $table->unsignedBigInteger('warehouse_id')->nullable()->after('store_id');
        });

        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->unsignedBigInteger('warehouse_id')->nullable()->after('store_id');
        });

        $this->backfill();

        foreach ([...$this->tables, 'orders', 'purchase_orders'] as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                $blueprint->foreign('warehouse_id')->references('id')->on('warehouses')->restrictOnDelete();

                if (in_array($table, $this->tables, true)) {
                    $blueprint->index(['warehouse_id', 'product_id']);
                }
            });
        }

        /*
         * El renglon de existencias pasa a ser uno por bodega, no uno por sede.
         *
         * El nuevo unico se crea **antes** de soltar el viejo: en MariaDB la llave foranea de
         * `product_id` se apoya en el indice que la empieza, y soltarlo primero responde
         * «Cannot drop index: needed in a foreign key constraint».
         */
        Schema::table('product_stocks', function (Blueprint $table) {
            $table->unique(['product_id', 'warehouse_id']);
        });

        Schema::table('product_stocks', function (Blueprint $table) {
            $table->dropUnique(['product_id', 'store_id']);
        });

        // Ya relleno: de aqui en adelante ninguna existencia ni movimiento vive sin bodega.
        foreach ($this->tables as $table) {
            DB::statement("ALTER TABLE `{$table}` MODIFY `warehouse_id` BIGINT UNSIGNED NOT NULL");
        }
    }

    public function down(): void
    {
        Schema::table('product_stocks', function (Blueprint $table) {
            $table->unique(['product_id', 'store_id']);
        });

        Schema::table('product_stocks', function (Blueprint $table) {
            $table->dropUnique(['product_id', 'warehouse_id']);
        });

        foreach ([...$this->tables, 'orders', 'purchase_orders'] as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                $blueprint->dropForeign(['warehouse_id']);

                if (in_array($table, $this->tables, true)) {
                    $blueprint->dropIndex(['warehouse_id', 'product_id']);
                }

                $blueprint->dropColumn('warehouse_id');
            });
        }
    }

    protected function createPrincipalWarehouses(): void
    {
        $now = now();

        foreach (DB::table('stores')->orderBy('id')->get(['id', 'company_id']) as $store) {
            $exists = DB::table('warehouses')->where('store_id', $store->id)->exists();

            if ($exists) {
                continue;
            }

            DB::table('warehouses')->insert([
                'company_id' => $store->company_id,
                'store_id' => $store->id,
                'name' => 'Bodega principal',
                'code' => 'PRINCIPAL',
                'type' => 'sales_floor',
                'is_pos_source' => true,
                'is_online_source' => true,
                'is_receiving_default' => true,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    protected function backfill(): void
    {
        $principals = DB::table('warehouses')
            ->where('code', 'PRINCIPAL')
            ->pluck('id', 'store_id');

        foreach ($principals as $storeId => $warehouseId) {
            foreach ([...$this->tables, 'orders', 'purchase_orders'] as $table) {
                DB::table($table)
                    ->where('store_id', $storeId)
                    ->whereNull('warehouse_id')
                    ->update(['warehouse_id' => $warehouseId]);
            }
        }
    }
};
