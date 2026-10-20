<?php

namespace App\Console\Commands;

use App\Support\Storage\FirebaseStorageAdapter;
use Illuminate\Console\Command;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Sube a Firebase los archivos que ya estaban en este servidor, con la misma ruta.
 *
 * Lo publico (fotos, logos, banners) va a la zona publica con su token de descarga; los adjuntos
 * privados (fotos de devoluciones, archivos de importacion) a la privada. No borra nada de aqui:
 * lo local queda de respaldo. Se puede correr las veces que haga falta: lo que ya esta arriba
 * con el mismo tamaño se salta.
 */
class MigrateUploadsToFirebase extends Command
{
    /** Las carpetas del disco privado que son adjuntos. Los respaldos de la base no lo son. */
    public const PRIVATE_FOLDERS = ['customer-returns', 'platform-imports', 'catalog-imports'];

    protected $signature = 'uploads:firebase
        {--dry-run : Solo cuenta lo que subiria, sin subir nada}
        {--retag : Vuelve a ponerle el token de descarga a lo publico que ya esta arriba}';

    protected $description = 'Sube a Firebase Storage las imágenes y adjuntos que están en este servidor';

    /** @var array{copied: int, skipped: int, failed: int, bytes: int} */
    protected array $totals = ['copied' => 0, 'skipped' => 0, 'failed' => 0, 'bytes' => 0];

    /** @var list<string> */
    protected array $errors = [];

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $this->totals = ['copied' => 0, 'skipped' => 0, 'failed' => 0, 'bytes' => 0];
        $this->errors = [];

        try {
            /** @var FilesystemAdapter $public */
            $public = Storage::disk('firebase');
            /** @var FilesystemAdapter $private */
            $private = Storage::disk('firebase_private');
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $publicFiles = collect(Storage::disk('public')->allFiles())->reject(fn (string $path) => Str::startsWith(basename($path), '.'))->values();
        $privateFiles = collect(self::PRIVATE_FOLDERS)->flatMap(fn (string $folder) => Storage::disk('local')->allFiles($folder))->values();

        $this->info(($dry ? '[Simulación] ' : '')."Públicos: {$publicFiles->count()} · privados: {$privateFiles->count()}");

        $this->copyAll($publicFiles->all(), Storage::disk('public'), $public, $dry, retag: (bool) $this->option('retag'));
        $this->copyAll($privateFiles->all(), Storage::disk('local'), $private, $dry, retag: false);

        // La biblioteca de medios guarda en que disco esta cada imagen: ahora en Firebase.
        if (! $dry && $this->totals['failed'] === 0) {
            $moved = DB::table('media_assets')->where('disk', 'public')->update(['disk' => 'firebase']);
            $this->line("Biblioteca de medios: {$moved} imágenes apuntan ahora a Firebase.");
        }

        $this->newLine();
        $this->table(['Subidos', 'Ya estaban', 'Fallaron', 'Megas subidos'], [[
            $this->totals['copied'], $this->totals['skipped'], $this->totals['failed'], number_format($this->totals['bytes'] / 1048576, 1, ',', '.'),
        ]]);

        foreach (array_slice($this->errors, 0, 10) as $error) {
            $this->error($error);
        }

        if ($this->totals['failed'] > 0) {
            $this->warn('Hubo archivos que no subieron: corre el comando otra vez (lo que ya está arriba se salta). No cambies todavía UPLOADS_DISK.');

            return self::FAILURE;
        }

        if (! $dry) {
            $this->info('Listo. Ya puedes poner UPLOADS_DISK=firebase y PRIVATE_UPLOADS_DISK=firebase_private en el .env y correr php artisan config:clear.');
        }

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $paths
     */
    protected function copyAll(array $paths, FilesystemAdapter $source, FilesystemAdapter $target, bool $dry, bool $retag): void
    {
        $adapter = $target->getAdapter();
        $bar = $this->output->createProgressBar(count($paths));
        $bar->start();

        foreach ($paths as $path) {
            try {
                $size = (int) $source->size($path);

                if ($target->exists($path) && (int) $target->size($path) === $size) {
                    if ($retag && ! $dry && $adapter instanceof FirebaseStorageAdapter) {
                        $adapter->ensureDownloadToken($path);
                    }

                    $this->totals['skipped']++;
                } elseif ($dry) {
                    $this->totals['copied']++;
                    $this->totals['bytes'] += $size;
                } else {
                    $stream = $source->readStream($path);
                    $target->writeStream($path, $stream);

                    if (is_resource($stream)) {
                        fclose($stream);
                    }

                    $this->totals['copied']++;
                    $this->totals['bytes'] += $size;
                }
            } catch (Throwable $exception) {
                $this->totals['failed']++;
                $this->errors[] = $path.': '.Str::limit($exception->getMessage(), 200);
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
    }
}
