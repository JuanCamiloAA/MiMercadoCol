<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Solicitudes de traslado.
 *
 * La bodega que necesita mercancia la pide; la que la tiene decide cuanto manda y cuando. La
 * solicitud no mueve inventario: es la conversacion previa al traslado, y queda escrita para
 * que se sepa que se pidio, que se mando y que no se pudo mandar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_transfer_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('number', 20);
            // La que surte: a quien se le pide.
            $table->foreignId('from_warehouse_id')->constrained('warehouses')->restrictOnDelete();
            // La que pide.
            $table->foreignId('to_warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->string('status', 20)->default('pending');
            $table->string('note')->nullable();
            // Por que se rechazo o se cerro sin completar.
            $table->string('response_note')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('responded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('responded_at')->nullable();
            $table->foreignId('cancelled_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('cancelled_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'number']);
            $table->index(['company_id', 'status']);
            $table->index(['from_warehouse_id', 'status']);
            $table->index(['to_warehouse_id', 'status']);
        });

        Schema::create('stock_transfer_request_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_transfer_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 12, 3);
            // Lo que ya va en traslados vigentes. Se recalcula, no se acumula a mano: si se
            // anula un traslado generado, esas unidades vuelven a quedar pendientes.
            $table->decimal('sent_quantity', 12, 3)->default(0);
            $table->timestamps();

            $table->unique(['stock_transfer_request_id', 'product_id'], 'transfer_request_items_unique');
        });

        Schema::table('stock_transfers', function (Blueprint $table) {
            $table->foreignId('stock_transfer_request_id')->nullable()->after('company_id')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('stock_transfers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('stock_transfer_request_id');
        });

        Schema::dropIfExists('stock_transfer_request_items');
        Schema::dropIfExists('stock_transfer_requests');
    }
};
