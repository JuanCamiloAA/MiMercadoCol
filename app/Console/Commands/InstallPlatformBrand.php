<?php

namespace App\Console\Commands;

use App\Services\Platform\PlatformBrandAssets;
use App\Support\Uploads;
use Illuminate\Console\Command;
use Throwable;

/**
 * Publica la marca de la plataforma en el disco de archivos subidos (Firebase en produccion) y la
 * deja en uso en Plataforma > Landing. No toca la marca de ninguna empresa.
 */
class InstallPlatformBrand extends Command
{
    protected $signature = 'platform:brand';

    protected $description = 'Sube el logo, el isotipo, el favicon y la imagen para compartir de la plataforma y los deja en uso';

    public function handle(PlatformBrandAssets $assets): int
    {
        try {
            $firebase = Uploads::usesFirebase();
            $this->line('Disco: «'.Uploads::diskName().'»'.($firebase ? ' (Firebase Storage)' : ''));

            $paths = $assets->install();
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->table(['Ajuste', 'Ruta', 'Enlace'], collect($paths)->map(fn (string $path, string $key) => [$key, $path, Uploads::url($path)])->values()->all());

        if (! $firebase) {
            $this->warn('Quedaron en este servidor. Al pasar a Firebase, `uploads:firebase` los sube con la misma ruta; o corre este comando otra vez con UPLOADS_DISK=firebase.');
        }

        $this->info('La marca de la plataforma quedó en uso. Las empresas siguen con la suya.');

        return self::SUCCESS;
    }
}
