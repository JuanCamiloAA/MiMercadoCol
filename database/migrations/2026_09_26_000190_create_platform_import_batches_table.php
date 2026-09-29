<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bitacora de la carga masiva de plataforma: que se cargo, para que empresa, como salio y con
 * el reporte de errores y el archivo original para descargar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_import_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 30);
            $table->string('original_filename');
            $table->string('stored_path');
            $table->string('status', 20)->default('pending');
            $table->unsignedInteger('rows_total')->default(0);
            $table->unsignedInteger('rows_success')->default(0);
            $table->unsignedInteger('rows_failed')->default(0);
            $table->string('error_report_path')->nullable();
            $table->text('message')->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('finished_at')->nullable();
            $table->timestamps();

            $table->index(['type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_import_batches');
    }
};
