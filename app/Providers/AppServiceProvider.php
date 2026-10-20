<?php

namespace App\Providers;

use App\Broadcasting\GracefulBroadcaster;
use App\Models\CatalogImport;
use App\Realtime\ChangeCollector;
use App\Services\Catalog\ExternalCatalogClient;
use App\Services\Catalog\OpenFoodFactsClient;
use App\Support\Storage\FirebaseStorageAdapter;
use Google\Cloud\Storage\StorageClient;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Contracts\Broadcasting\Broadcaster;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use League\Flysystem\Filesystem;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // La fuente de catalogo esta detras de una interfaz para poder cambiarla o sustituirla
        // en las pruebas sin tocar el importador.
        $this->app->bind(ExternalCatalogClient::class, OpenFoodFactsClient::class);

        // Uno por proceso: junta lo de la peticion (o del trabajo) y se vacia al transmitir.
        $this->app->singleton(ChangeCollector::class);
    }

    public function boot(): void
    {
        $this->registerFirebaseStorage();
        $this->registerLiveChanges();

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

    /**
     * Las pestañas abiertas se enteran de lo que cambia en otras.
     *
     * Cada guardado o borrado de un modelo que esta en `Topics::MODELS` queda anotado, y al final
     * de la peticion (o de cada trabajo de la cola) sale un solo aviso por empresa. La pantalla que
     * lo recibe y muestra ese tema vuelve a pedir sus datos (`LiveRefresh`).
     */
    protected function registerLiveChanges(): void
    {
        Event::listen(['eloquent.saved: *', 'eloquent.deleted: *'], function (string $event, array $payload) {
            if (($model = $payload[0] ?? null) instanceof Model) {
                $this->app->make(ChangeCollector::class)->recordModel($model);
            }
        });

        $this->app->terminating(fn () => $this->app->make(ChangeCollector::class)->flush());
        Queue::after(fn () => $this->app->make(ChangeCollector::class)->flush());
        Queue::failing(fn () => $this->app->make(ChangeCollector::class)->flush());
    }

    /**
     * El disco `firebase`: Firebase Storage por la cuenta de servicio. Se arma solo cuando alguien
     * lo pide, y sin bucket o sin llave falla diciendo que falta.
     */
    protected function registerFirebaseStorage(): void
    {
        Storage::extend('firebase', function ($app, array $config) {
            $credentials = (string) ($config['credentials'] ?? '');
            // Relativa al proyecto, como queda en el .env (storage/...json).
            $credentials = $credentials !== '' && preg_match('#^([A-Za-z]:)?[\\\\/]#', $credentials) !== 1 ? base_path($credentials) : $credentials;
            $bucket = (string) ($config['bucket'] ?? '');

            if ($bucket === '') {
                throw new \RuntimeException('Falta FIREBASE_STORAGE_BUCKET en el .env: el bucket de Firebase Storage del proyecto.');
            }

            if ($credentials === '' || ! is_file($credentials)) {
                throw new \RuntimeException('No se encuentra la llave de Firebase (FIREBASE_CREDENTIALS).');
            }

            $client = new StorageClient(['keyFilePath' => $credentials]);
            $token = ! empty($config['public']) ? self::firebaseDownloadToken($config) : null;
            $adapter = new FirebaseStorageAdapter($client->bucket($bucket), (string) ($config['root'] ?? ''), $token);

            return new FilesystemAdapter(new Filesystem($adapter, $config), $adapter, $config);
        });
    }

    /**
     * El token de los enlaces publicos: el del .env o, si no hay, uno fijo sacado de la llave de
     * la aplicacion. Fijo a proposito: cada archivo publico lo lleva en sus metadatos.
     *
     * @param  array<string, mixed>  $config
     */
    public static function firebaseDownloadToken(array $config): string
    {
        $token = trim((string) ($config['download_token'] ?? ''));

        if ($token !== '') {
            return $token;
        }

        $hex = hash_hmac('sha256', 'firebase-downloads|'.($config['bucket'] ?? ''), (string) config('app.key'));

        return implode('-', [substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20, 12)]);
    }
}
