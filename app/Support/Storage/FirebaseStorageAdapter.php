<?php

namespace App\Support\Storage;

use Google\Cloud\Storage\Bucket;
use League\Flysystem\Config;
use League\Flysystem\GoogleCloudStorage\GoogleCloudStorageAdapter;
use League\Flysystem\GoogleCloudStorage\UniformBucketLevelAccessVisibility;
use League\Flysystem\Visibility;
use RuntimeException;

/**
 * Firebase Storage como disco de Laravel.
 *
 * Por debajo es un bucket de Cloud Storage. Lo publico se sirve con el enlace de descarga de
 * Firebase (`...?alt=media&token=`): cada archivo publico se escribe con el metadato
 * `firebaseStorageDownloadTokens`, y con ese token el enlace funciona sin tocar las reglas de
 * seguridad. Lo privado (fotos de devoluciones, archivos de importacion) se escribe sin token:
 * nadie lo abre desde afuera y la aplicacion lo sirve con sesion y permiso.
 *
 * Sin ACL por objeto: los buckets de Firebase tienen acceso uniforme y un ACL haria fallar la
 * escritura.
 */
class FirebaseStorageAdapter extends GoogleCloudStorageAdapter
{
    public function __construct(
        private Bucket $firebaseBucket,
        private string $root,
        private ?string $downloadToken,
        private string $cacheControl = 'public, max-age=3600',
    ) {
        parent::__construct($firebaseBucket, $root, new UniformBucketLevelAccessVisibility, Visibility::PRIVATE);
    }

    public function write(string $path, string $contents, Config $config): void
    {
        parent::write($path, $contents, $this->withDownloadToken($config));
    }

    public function writeStream(string $path, $contents, Config $config): void
    {
        parent::writeStream($path, $contents, $this->withDownloadToken($config));
    }

    /**
     * El enlace publico. Laravel lo pide aqui desde `Storage::disk(...)->url()`.
     */
    public function getUrl(string $path): string
    {
        if ($this->downloadToken === null) {
            throw new RuntimeException('Los archivos privados no tienen enlace público: se sirven por la aplicación, con permiso.');
        }

        return self::downloadUrl($this->firebaseBucket->name(), $this->objectName($path), $this->downloadToken);
    }

    /** El nombre completo del objeto en el bucket (`public/products/1/abc.jpg`). */
    public function objectName(string $path): string
    {
        $root = trim($this->root, '/');

        return ($root === '' ? '' : $root.'/').ltrim($path, '/');
    }

    public function bucketName(): string
    {
        return $this->firebaseBucket->name();
    }

    public function downloadToken(): ?string
    {
        return $this->downloadToken;
    }

    /**
     * Le pone el token de descarga a un archivo que ya esta en el bucket: uno subido por otro
     * camino, o todos despues de cambiar `FIREBASE_DOWNLOAD_TOKEN`.
     */
    public function ensureDownloadToken(string $path): void
    {
        if ($this->downloadToken === null) {
            return;
        }

        $this->firebaseBucket->object($this->objectName($path))->update([
            'metadata' => ['firebaseStorageDownloadTokens' => $this->downloadToken],
        ]);
    }

    /** El enlace de descarga de Firebase: el nombre del objeto va codificado entero, con sus barras. */
    public static function downloadUrl(string $bucket, string $object, string $token): string
    {
        return 'https://firebasestorage.googleapis.com/v0/b/'.$bucket.'/o/'.rawurlencode($object).'?alt=media&token='.rawurlencode($token);
    }

    /**
     * Lo publico se escribe con su token de descarga. Se suma a lo que ya traiga la escritura,
     * sin pisarlo, y con cache: una imagen guardada no cambia en la misma ruta.
     */
    private function withDownloadToken(Config $config): Config
    {
        if ($this->downloadToken === null) {
            return $config;
        }

        $metadata = (array) $config->get('metadata', []);
        $metadata['metadata'] = array_merge((array) ($metadata['metadata'] ?? []), ['firebaseStorageDownloadTokens' => $this->downloadToken]);
        $metadata['cacheControl'] ??= $this->cacheControl;

        return $config->extend(['metadata' => $metadata]);
    }
}
