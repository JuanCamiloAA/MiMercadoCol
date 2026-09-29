<?php

namespace App\Providers;

use App\Broadcasting\GracefulBroadcaster;
use App\Models\CatalogImport;
use App\Services\Catalog\ExternalCatalogClient;
use App\Services\Catalog\OpenFoodFactsClient;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Contracts\Broadcasting\Broadcaster;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // La fuente de catalogo esta detras de una interfaz para poder cambiarla o sustituirla
        // en las pruebas sin tocar el importador.
        $this->app->bind(ExternalCatalogClient::class, OpenFoodFactsClient::class);
    }

    public function boot(): void
    {
        /*
         * El tiempo real no puede tumbar una venta.
         *
         * Se envuelve el driver de Reverb para que un servidor de sockets caido —o que nadie
         * arranco— quede en el registro y no como excepcion en mitad de un cobro.
         */
        Broadcast::extend('reverb', function ($app, array $config) {
            $manager = new class($app) extends BroadcastManager
            {
                public function reverb(array $config): Broadcaster
                {
                    return $this->createReverbDriver($config);
                }
            };

            return new GracefulBroadcaster($manager->reverb($config));
        });

        /*
         * Una importacion de otra empresa responde 403, no 404.
         *
         * El filtro por empresa es un scope global, asi que el enlace automatico no la
         * encontraria y Laravel devolveria 404. Se resuelve sin el scope a proposito, para que
         * sea la politica la que responda: «existe, pero no es tuya» es la respuesta correcta
         * dentro del panel, donde el administrador ya sabe que hay otras empresas.
         */
        Route::bind('import', function (string $value) {
            return CatalogImport::query()
                ->withoutGlobalScopes()
                ->whereKey($value)
                ->firstOrFail();
        });
    }
}
