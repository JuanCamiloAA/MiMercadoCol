<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Archivos subidos y adjuntos
    |--------------------------------------------------------------------------
    |
    | Donde quedan las fotos, logos y banners (`uploads`) y los adjuntos privados: fotos de
    | devoluciones, archivos de importacion (`private_uploads`). En produccion, Firebase.
    | Todo el codigo los usa por `App\Support\Uploads`, nunca por el nombre del disco.
    |
    */

    'uploads' => env('UPLOADS_DISK', 'public'),

    'private_uploads' => env('PRIVATE_UPLOADS_DISK', 'local'),

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => env('APP_URL').'/storage',
            'visibility' => 'public',
            'throw' => false,
        ],

        /*
         * Firebase Storage (un bucket de Cloud Storage). Lo publico va bajo `public/` y se sirve con
         * el enlace de descarga de Firebase; lo privado, bajo `private/`, sin enlace: lo sirve la
         * aplicacion con permiso. La llave es la de la cuenta de servicio del proyecto.
         */
        'firebase' => [
            'driver' => 'firebase',
            'credentials' => env('FIREBASE_CREDENTIALS'),
            'bucket' => env('FIREBASE_STORAGE_BUCKET'),
            'root' => 'public',
            'public' => true,
            // El token de los enlaces publicos. Vacio, sale de la llave de la aplicacion; cambiarlo
            // exige `php artisan uploads:firebase --retag` para que los enlaces viejos sigan sirviendo.
            'download_token' => env('FIREBASE_DOWNLOAD_TOKEN'),
            'throw' => true,
        ],

        'firebase_private' => [
            'driver' => 'firebase',
            'credentials' => env('FIREBASE_CREDENTIALS'),
            'bucket' => env('FIREBASE_STORAGE_BUCKET'),
            'root' => 'private',
            'public' => false,
            'throw' => true,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
