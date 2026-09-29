<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cierre de la historia de un descuadre.
 *
 * Hasta ahora el cierre dejaba la diferencia anotada y la pantalla de apertura se la recordaba
 * al siguiente cajero, pero no habia donde resolverla: el aviso se quedaba para siempre. Con
 * estas columnas un administrador decide que paso con esa plata y el turno deja de aparecer
 * como pendiente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('register_sessions', function (Blueprint $table) {
            /** pending | resolved. Solo significa algo cuando la diferencia no es cero. */
            $table->string('difference_status', 12)->default('pending')->after('closing_note');
            /** justified | absorbed | charged | recovered */
            $table->string('resolution', 20)->nullable()->after('difference_status');
            $table->string('resolution_note', 200)->nullable()->after('resolution');
            $table->foreignId('resolved_by_user_id')->nullable()->after('resolution_note')->constrained('users')->nullOnDelete();
            $table->dateTime('resolved_at')->nullable()->after('resolved_by_user_id');

            $table->index(['company_id', 'difference_status']);
        });

        // Los turnos que cuadraron no tienen nada que revisar.
        \Illuminate\Support\Facades\DB::table('register_sessions')
            ->where('status', 'closed')
            ->where(function ($query) {
                $query->whereNull('difference')->orWhere('difference', 0);
            })
            ->update(['difference_status' => 'resolved', 'resolution' => 'justified']);
    }

    public function down(): void
    {
        Schema::table('register_sessions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('resolved_by_user_id');
            $table->dropIndex(['company_id', 'difference_status']);
            $table->dropColumn(['difference_status', 'resolution', 'resolution_note', 'resolved_at']);
        });
    }
};
