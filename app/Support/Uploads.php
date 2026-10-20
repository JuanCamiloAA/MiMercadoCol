<?php

namespace App\Support;

use App\Support\Storage\FirebaseStorageAdapter;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Local\LocalFilesystemAdapter;

/**
 * Donde se guardan los archivos que se suben o adjuntan, y como se enlazan.
 *
 * Todo pasa por aqui: el disco publico (fotos, logos, banners) y el privado (fotos de
 * devoluciones, archivos de importacion) salen de `config/filesystems.php`, y en produccion son
 * Firebase Storage. Nadie arma una URL con `/storage/` a mano: con Firebase esa ruta no existe.
 */
final class Uploads
{
    /** El disco de lo publico: `firebase` en produccion, `public` en local y en pruebas. */
    public static function diskName(): string
    {
        return (string) config('filesystems.uploads', 'public');
    }

    /** El disco de lo privado: `firebase_private` en produccion, `local` en local y en pruebas. */
    public static function privateDiskName(): string
    {
        return (string) config('filesystems.private_uploads', 'local');
    }

    public static function disk(): FilesystemAdapter
    {
        return self::adapter(self::diskName());
    }

    public static function privateDisk(): FilesystemAdapter
    {
        return self::adapter(self::privateDiskName());
    }

    /** El disco con todo lo de Laravel (`url`, `response`, `download`). */
    private static function adapter(string $name): FilesystemAdapter
    {
        $disk = Storage::disk($name);

        if (! $disk instanceof FilesystemAdapter) {
            throw new \RuntimeException("El disco {$name} no sirve para archivos subidos.");
        }

        return $disk;
    }

    /**
     * El enlace publico de un archivo. En local, `/storage/...` como siempre (sin depender de
     * `APP_URL`); en Firebase, su enlace de descarga. Una URL externa (una foto del catalogo de
     * internet) se deja tal cual.
     */
    public static function url(?string $path, ?string $disk = null): ?string
    {
        $path = trim((string) $path);

        if ($path === '') {
            return null;
        }

        if (preg_match('#^(https?:)?//#i', $path) === 1) {
            return $path;
        }

        $filesystem = Storage::disk($disk ?? self::diskName());

        if ($filesystem instanceof FilesystemAdapter && $filesystem->getAdapter() instanceof LocalFilesystemAdapter) {
            return '/storage/'.ltrim($path, '/');
        }

        return $filesystem->url(ltrim($path, '/'));
    }

    /**
     * Una ruta en este servidor para quien la necesita (el lector de CSV, el PDF): en un disco
     * local es la del archivo; en Firebase se baja a un temporal que se borra al terminar.
     */
    public static function localPath(string $disk, string $path): string
    {
        $filesystem = Storage::disk($disk);

        if ($filesystem instanceof FilesystemAdapter && $filesystem->getAdapter() instanceof LocalFilesystemAdapter) {
            return $filesystem->path($path);
        }

        $extension = pathinfo($path, PATHINFO_EXTENSION);
        $temp = tempnam(sys_get_temp_dir(), 'adjunto').($extension !== '' ? '.'.$extension : '');
        $source = $filesystem->readStream($path);
        $target = fopen($temp, 'wb');

        if (! is_resource($source) || $target === false) {
            throw new \RuntimeException("No se pudo leer el archivo {$path}.");
        }

        stream_copy_to_stream($source, $target);
        fclose($source);
        fclose($target);

        register_shutdown_function(static fn () => @unlink($temp));

        return $temp;
    }

    /**
     * El archivo como `data:` para incrustarlo (el logo del tiquete en PDF): DomPDF no sale a
     * internet a buscar imagenes.
     */
    public static function dataUri(?string $path, ?string $disk = null): ?string
    {
        $filesystem = Storage::disk($disk ?? self::diskName());

        if (! $path || ! $filesystem->exists($path)) {
            return null;
        }

        $mime = $filesystem->mimeType($path) ?: 'application/octet-stream';

        return 'data:'.$mime.';base64,'.base64_encode((string) $filesystem->get($path));
    }

    /** Si los archivos ya viven en Firebase (para el estado en pantalla y los comandos). */
    public static function usesFirebase(): bool
    {
        $filesystem = self::disk();

        return $filesystem instanceof FilesystemAdapter && $filesystem->getAdapter() instanceof FirebaseStorageAdapter;
    }
}
