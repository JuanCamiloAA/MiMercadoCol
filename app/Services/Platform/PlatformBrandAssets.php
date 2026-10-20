<?php

namespace App\Services\Platform;

use App\Services\PlatformSettingsService;
use App\Support\Uploads;
use RuntimeException;

/**
 * La marca de la plataforma (VentaIO): logo, isotipo, favicon e imagen para compartir.
 *
 * Es la de la landing, la contratacion, los textos legales, el ingreso con varias empresas y el
 * panel del super usuario sin empresa elegida. Nunca la de una empresa: cada una guarda la suya en
 * sus ajustes (`brand.*`, `BrandAssetService`) y en su carpeta, y sin logo propio se presenta con
 * su nombre, no con el de la plataforma. Por eso esto escribe solo en `FOLDER`, donde ninguna
 * empresa sube nada, y solo en los ajustes de plataforma.
 *
 * Los originales viven en el repositorio (`SOURCE`) y se publican en el disco de archivos
 * subidos: en produccion, Firebase.
 */
class PlatformBrandAssets
{
    public const SOURCE = 'resources/brand/ventaio';

    /** Bajo `landing/` porque es lo unico que aceptan los campos de imagen de Plataforma > Landing. */
    public const FOLDER = 'landing/brand';

    /** Que archivo va en cada ajuste de plataforma. */
    public const FILES = [
        'platform.brand_logo' => 'ventaio-logo-horizontal-color.svg',
        'platform.brand_logo_dark' => 'ventaio-logo-horizontal-blanco.svg',
        'platform.brand_mark' => 'ventaio-isotipo-512.png',
        'landing.favicon' => 'ventaio-favicon-32.png',
        'landing.seo_image' => 'ventaio-redes-1200x630.png',
    ];

    public function __construct(protected PlatformSettingsService $platform) {}

    /**
     * Sube cada archivo y apunta su ajuste a el. Se puede repetir: lo que ya esta arriba se
     * salta, y lo que cambio sale con otro nombre (el disco guarda cache por ruta). El archivo
     * anterior se borra solo si era de esta carpeta: lo que el super usuario subio a mano se queda.
     *
     * @return array<string, string> ajuste => ruta en el disco
     */
    public function install(): array
    {
        $disk = Uploads::disk();
        $paths = [];

        foreach (self::FILES as $key => $file) {
            $contents = @file_get_contents(base_path(self::SOURCE.'/'.$file));

            if ($contents === false || $contents === '') {
                throw new RuntimeException('Falta el archivo de marca '.self::SOURCE.'/'.$file.'.');
            }

            $path = self::FOLDER.'/'.pathinfo($file, PATHINFO_FILENAME).'.'.substr(sha1($contents), 0, 10).'.'.pathinfo($file, PATHINFO_EXTENSION);

            if (! $disk->exists($path) && ! $disk->put($path, $contents)) {
                throw new RuntimeException("No se pudo guardar {$path} en el disco «".Uploads::diskName().'».');
            }

            $paths[$key] = $path;
        }

        $previous = [];

        foreach (array_keys($paths) as $key) {
            $previous[$key] = $this->platform->get($key);
        }

        $this->platform->setMany($paths);

        foreach ($previous as $key => $old) {
            if (is_string($old) && $old !== $paths[$key] && str_starts_with($old, self::FOLDER.'/')) {
                $disk->delete($old);
            }
        }

        return $paths;
    }
}
