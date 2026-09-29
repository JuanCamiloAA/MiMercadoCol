<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La plantilla base de la tienda, que define el super usuario.
 *
 * Las tiendas que ya existian quedan con `follows_template = false`: ya tienen su contenido y
 * no se les cambia nada. Las nuevas nacen con la plantilla por defecto y la siguen.
 *
 * Los medios de plataforma son filas de `media_assets` sin empresa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_assets', function (Blueprint $table) {
            $table->unsignedBigInteger('company_id')->nullable()->change();
        });

        Schema::create('storefront_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80);
            $table->text('description')->nullable();
            $table->foreignId('preview_media_id')->nullable()->constrained('media_assets')->nullOnDelete();
            $table->json('draft');
            $table->json('published')->nullable();
            $table->dateTime('published_at')->nullable();
            $table->foreignId('published_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('storefront_template_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('template_id')->constrained('storefront_templates')->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->json('snapshot');
            $table->string('label', 80)->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('published_at')->nullable();
            $table->timestamps();

            $table->unique(['template_id', 'number']);
        });

        Schema::table('storefronts', function (Blueprint $table) {
            $table->foreignId('template_id')->nullable()->after('published_by_user_id')->constrained('storefront_templates')->nullOnDelete();
            $table->boolean('follows_template')->default(false)->after('template_id');
        });
    }

    public function down(): void
    {
        Schema::table('storefronts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('template_id');
            $table->dropColumn('follows_template');
        });

        Schema::dropIfExists('storefront_template_versions');
        Schema::dropIfExists('storefront_templates');

        Schema::table('media_assets', function (Blueprint $table) {
            $table->unsignedBigInteger('company_id')->nullable(false)->change();
        });
    }
};
