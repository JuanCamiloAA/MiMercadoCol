<?php

use App\Services\Platform\BusinessCategoryCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La clasificacion de cada empresa (alimentos, textiles, belleza…), con su catalogo de plataforma.
 *
 * La llevan tambien la contratacion pendiente de pago y la solicitud de contacto, para que la
 * empresa nazca ya clasificada. Las empresas que ya existian quedan en «Alimentos y
 * supermercados»: la plataforma nacio para la canasta familiar y es lo que vendian.
 */
return new class extends Migration
{
    protected const TABLES = ['companies', 'company_signups', 'platform_leads'];

    public function up(): void
    {
        Schema::create('business_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60)->unique();
            $table->string('slug', 70)->unique();
            $table->string('icon', 40)->default('Storefront');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->foreignId('business_category_id')->nullable()->after('city')->constrained('business_categories')->nullOnDelete();
            });
        }

        app(BusinessCategoryCatalog::class)->seedDefaults();

        DB::table('companies')->whereNull('business_category_id')->update([
            'business_category_id' => app(BusinessCategoryCatalog::class)->defaultId(),
        ]);
    }

    public function down(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropConstrainedForeignId('business_category_id');
            });
        }

        Schema::dropIfExists('business_categories');
    }
};
