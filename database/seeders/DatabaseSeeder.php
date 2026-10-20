<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            PermissionSeeder::class,
            PlatformSeeder::class,
            CompanySeeder::class,
            RoleSeeder::class,
            UserSeeder::class,
            // La plantilla base antes de las empresas: las tiendas que se aprovisionan nacen con ella.
            StorefrontTemplateSeeder::class,
            CatalogSeeder::class,
            CustomerSeeder::class,
            // La segunda empresa va antes de las tiendas: su sede tambien se publica.
            NeighborCompanySeeder::class,
            // Despues de los dos catalogos y antes de los servicios: el desayuno lleva la leche entera
            // como articulo completo.
            ProductFamilySeeder::class,
            ServiceSeeder::class,
            PromotionSeeder::class,
            StorefrontSeeder::class,
            LandingSeeder::class,
            DemoOperationSeeder::class,
            // Despues de la operacion: devuelve sobre ventas y pedidos que ya existen.
            CustomerReturnSeeder::class,
            // La tienda de ropa va al final: trae su propia historia de cuatro semanas, con su tienda
            // en linea y sus promociones, y no la tocan los seeders de mercado de arriba.
            FashionCompanySeeder::class,
        ]);
    }
}
