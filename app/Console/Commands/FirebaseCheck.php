<?php

namespace App\Console\Commands;

use App\Support\Storage\FirebaseStorageAdapter;
use App\Support\Uploads;
use Illuminate\Console\Command;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Prueba de punta a punta de Firebase Storage: escribe, lee, abre el enlace publico, confirma
 * que lo privado no se abre desde afuera y borra lo que creo. No toca ningun archivo real.
 */
class FirebaseCheck extends Command
{
    protected $signature = 'firebase:check';

    protected $description = 'Comprueba que Firebase Storage este listo: llave, bucket, enlaces públicos y archivos privados';

    public function handle(): int
    {
        $this->line('Bucket: '.(config('filesystems.disks.firebase.bucket') ?: '(falta FIREBASE_STORAGE_BUCKET)'));
        $this->line('Llave: '.(config('filesystems.disks.firebase.credentials') ?: '(falta FIREBASE_CREDENTIALS)'));
        $this->line('Discos en uso: público «'.Uploads::diskName().'», privado «'.Uploads::privateDiskName().'»');
        $this->newLine();

        $name = '_verificacion/'.Str::uuid().'.txt';
        $content = 'AgoraShop '.now()->toIso8601String();
        $ok = true;

        try {
            /** @var FilesystemAdapter $public */
            $public = Storage::disk('firebase');
            /** @var FilesystemAdapter $private */
            $private = Storage::disk('firebase_private');
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $ok = $this->step('Escribir en la zona pública', fn () => $public->put($name, $content)) && $ok;
        $ok = $ok && $this->step('Leerlo de vuelta', fn () => $public->get($name) === $content);
        $ok = $ok && $this->step('Abrir su enlace público (sin sesión)', function () use ($public, $name, $content) {
            $response = Http::timeout(20)->get($public->url($name));

            return $response->successful() && $response->body() === $content;
        });

        $ok = $this->step('Escribir en la zona privada', fn () => $private->put($name, $content)) && $ok;
        $ok = $ok && $this->step('Lo privado no se abre desde afuera', function () use ($private, $name) {
            /** @var FirebaseStorageAdapter $adapter */
            $adapter = $private->getAdapter();
            $url = 'https://firebasestorage.googleapis.com/v0/b/'.$adapter->bucketName().'/o/'.rawurlencode($adapter->objectName($name)).'?alt=media';

            return ! Http::timeout(20)->get($url)->successful();
        });

        $this->step('Borrar los archivos de prueba', function () use ($public, $private, $name) {
            $public->delete($name);
            $private->delete($name);

            return true;
        });

        $this->newLine();
        $ok ? $this->info('Firebase Storage está listo.') : $this->error('Firebase Storage todavía no está listo: revisa el primer paso que falló.');

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    protected function step(string $label, callable $check): bool
    {
        try {
            $passed = (bool) $check();
            $this->line(($passed ? '<info>✓</info> ' : '<error>✗</error> ').$label);

            return $passed;
        } catch (Throwable $exception) {
            $this->line('<error>✗</error> '.$label.': '.Str::limit($exception->getMessage(), 300));

            return false;
        }
    }
}
