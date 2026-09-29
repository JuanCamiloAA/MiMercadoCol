<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El rojo de oferta, el isotipo y si el cliente puede cambiar entre claro y oscuro.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('storefront_themes', function (Blueprint $table) {
            $table->string('deal_color', 9)->default('#D92D20')->after('accent');
            $table->foreignId('mark_media_id')->nullable()->after('favicon_media_id')->constrained('media_assets')->nullOnDelete();
            $table->boolean('allow_scheme_toggle')->default(false)->after('scheme');
        });
    }

    public function down(): void
    {
        Schema::table('storefront_themes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('mark_media_id');
            $table->dropColumn(['deal_color', 'allow_scheme_toggle']);
        });
    }
};
