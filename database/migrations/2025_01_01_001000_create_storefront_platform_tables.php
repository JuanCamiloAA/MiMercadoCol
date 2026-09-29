<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La tienda en linea pasa a ser configurable por sede.
 *
 * Hasta ahora la portada era una lista de bloques por empresa. Ahora cada sede tiene su propio
 * sitio —aspecto, contenido y funciones—, su borrador vivo y un historial de publicaciones al
 * que se puede volver. La tienda publica nunca lee el borrador: lee el retrato de la version
 * publicada, que es autosuficiente.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Imagenes del editor, con su texto alternativo y su punto de recorte.
        Schema::create('media_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->string('disk', 20)->default('public');
            $table->string('mime', 60)->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedBigInteger('bytes')->nullable();
            /** Sin texto alternativo una imagen no existe para quien no la puede ver. */
            $table->string('alt', 160)->nullable();
            /** Punto que nunca se recorta, en fracciones de 0 a 1. */
            $table->decimal('focal_x', 4, 3)->default(0.5);
            $table->decimal('focal_y', 4, 3)->default(0.5);
            /** Variantes 16:9, 4:5 y 1:1 en WebP, si se pudieron generar. */
            $table->json('variants')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('company_id');
        });

        // Un sitio por sede.
        Schema::create('storefronts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('slug', 80);
            $table->string('custom_domain', 120)->nullable();
            $table->boolean('is_online')->default(true);
            /** Apunta a la version que ve el cliente. Null = todavia no se ha publicado. */
            $table->unsignedBigInteger('published_version_id')->nullable();
            $table->dateTime('published_at')->nullable();
            $table->foreignId('published_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'slug']);
        });

        // Las tablas viejas se apartan: la forma nueva no es compatible y los datos se pasan
        // en la migracion siguiente, que si conoce el significado de cada campo.
        Schema::rename('storefront_versions', 'storefront_versions_legacy');

        Schema::create('storefront_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('storefront_id')->constrained()->cascadeOnDelete();
            /** Consecutivo por sitio, para nombrarlas en el historial. */
            $table->unsignedInteger('number');
            $table->string('label', 80)->nullable();
            /** Retrato completo y autosuficiente: aspecto, bloques y funciones. */
            $table->json('snapshot');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            /** Null = punto de restauracion guardado, no publicado. */
            $table->dateTime('published_at')->nullable();
            $table->timestamps();

            $table->unique(['storefront_id', 'number']);
            $table->index(['storefront_id', 'published_at']);
        });

        Schema::table('storefronts', function (Blueprint $table) {
            $table->foreign('published_version_id')->references('id')->on('storefront_versions')->nullOnDelete();
        });

        Schema::create('storefront_themes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('storefront_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('logo_media_id')->nullable()->constrained('media_assets')->nullOnDelete();
            $table->foreignId('favicon_media_id')->nullable()->constrained('media_assets')->nullOnDelete();
            $table->string('display_name', 60)->nullable();
            $table->string('tagline', 120)->nullable();
            $table->string('accent', 9)->default('#0E6E4E');
            $table->string('scheme', 6)->default('light');
            $table->string('font_pair', 12)->default('moderna');
            $table->string('radius', 8)->default('soft');
            $table->string('density', 8)->default('cozy');
            $table->string('button_style', 8)->default('solid');
            $table->timestamps();
        });

        Schema::create('storefront_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('storefront_id')->constrained()->cascadeOnDelete();
            $table->string('key', 20);
            $table->json('value');
            $table->timestamps();

            $table->unique(['storefront_id', 'key']);
        });

        Schema::create('delivery_zones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('storefront_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->bigInteger('fee')->default(0);
            $table->unsignedSmallInteger('eta_minutes')->default(40);
            $table->boolean('is_active')->default(true);
            /** Poligono o centro con radio; null mientras se cobra por barrio a secas. */
            $table->json('geometry')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['storefront_id', 'is_active']);
        });

        Schema::create('delivery_slots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('storefront_id')->constrained()->cascadeOnDelete();
            /** 0 = domingo, como lo cuenta PHP. */
            $table->unsignedTinyInteger('weekday');
            $table->time('starts_at');
            $table->time('ends_at');
            /** Pedidos maximos de la franja; al llenarse se deja de ofrecer. */
            $table->unsignedSmallInteger('capacity')->default(10);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['storefront_id', 'weekday']);
        });

        Schema::create('coupons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            /** Null = sirve en todas las sedes. */
            $table->foreignId('storefront_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('code', 40);
            $table->string('type', 14);
            $table->bigInteger('value')->default(0);
            $table->bigInteger('min_order_total')->default(0);
            $table->bigInteger('max_discount')->nullable();
            $table->string('scope', 10)->default('all');
            $table->json('scope_ids')->nullable();
            $table->dateTime('starts_at')->nullable();
            $table->dateTime('ends_at')->nullable();
            $table->unsignedInteger('usage_limit')->nullable();
            $table->unsignedInteger('usage_limit_per_customer')->nullable();
            $table->boolean('first_order_only')->default(false);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'is_active']);
        });

        Schema::create('coupon_redemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coupon_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->bigInteger('discount_amount')->default(0);
            $table->dateTime('redeemed_at');
            $table->timestamps();

            $table->index(['coupon_id', 'customer_id']);
        });

        // El borrador vivo se reconstruye con la forma nueva; los datos se pasan aparte.
        Schema::rename('storefront_blocks', 'storefront_blocks_legacy');

        Schema::create('storefront_blocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('storefront_id')->constrained()->cascadeOnDelete();
            /** Clave estable para React y para el `postMessage` de la vista previa. */
            $table->uuid('uuid')->unique();
            $table->string('type', 24);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_visible')->default(true);
            $table->dateTime('visible_from')->nullable();
            $table->dateTime('visible_to')->nullable();
            $table->string('devices', 8)->default('all');
            $table->json('payload');
            $table->timestamps();

            $table->index(['storefront_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('storefront_blocks');
        Schema::rename('storefront_blocks_legacy', 'storefront_blocks');

        Schema::dropIfExists('coupon_redemptions');
        Schema::dropIfExists('coupons');
        Schema::dropIfExists('delivery_slots');
        Schema::dropIfExists('delivery_zones');
        Schema::dropIfExists('storefront_settings');
        Schema::dropIfExists('storefront_themes');

        Schema::table('storefronts', function (Blueprint $table) {
            $table->dropForeign(['published_version_id']);
        });

        Schema::dropIfExists('storefront_versions');
        Schema::rename('storefront_versions_legacy', 'storefront_versions');
        Schema::dropIfExists('storefronts');
        Schema::dropIfExists('media_assets');
    }
};
