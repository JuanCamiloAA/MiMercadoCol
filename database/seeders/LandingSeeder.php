<?php

namespace Database\Seeders;

use App\Models\User;
use App\Services\Landing\LandingManager;
use App\Services\Platform\PlatformBrandAssets;
use App\Services\PlatformSettingsService;
use Illuminate\Database\Seeder;

/**
 * La landing por defecto, completa y publicada: despues de `migrate:fresh --seed`, `/` nunca
 * queda vacia. Y los datos de contacto y la marca (logo, isotipo, favicon) de la plataforma.
 */
class LandingSeeder extends Seeder
{
    public function run(LandingManager $landing, PlatformSettingsService $platform, PlatformBrandAssets $brand): void
    {
        $platform->setMany([
            'platform.name' => 'VentaIO',
            'platform.support_email' => 'soporte@mimercado.co',
            'platform.leads_notify_email' => 'ventas@mimercado.co',
            'landing.seo_title' => 'VentaIO · El mercado de tu barrio, en línea',
            'landing.seo_description' => 'Encuentra la tienda más cercana, arma tu pedido y recíbelo en casa. Para mercados: caja, inventario y tienda en línea.',
        ]);

        $brand->install();

        $landing->ensureSeeded(User::query()->where('email', 'super@lacanasta.co')->first());
    }
}
