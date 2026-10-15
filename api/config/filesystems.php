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

    'disks' => [

        // Mídia privada (originais e variantes). Fora de public/: nunca servida diretamente pelo servidor web.
        'media' => [
            'driver' => 'local',
            'root' => storage_path('app/private/media'),
            'visibility' => 'private',
            'throw' => true,
            'report' => true,
        ],

        // Partes de uploads em andamento (temporário, limpo por mediahub:prune-uploads).
        'uploads' => [
            'driver' => 'local',
            'root' => storage_path('app/private/uploads'),
            'visibility' => 'private',
            'throw' => true,
            'report' => true,
        ],

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => false, // sem rota /storage/*: arquivos privados só saem por URL assinada do MediaHub
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        // Cloudflare R2 (compatível com S3). Para usar: MEDIA_DISK=r2 e MEDIA_UPLOADS_DISK=uploads (partes temporárias ficam locais).
        // O bucket DEVE ser privado: os arquivos só saem pelo MediaHub, com URL assinada e permissão checada.
        'r2' => [
            'driver' => 's3',
            'key' => env('R2_ACCESS_KEY_ID'),
            'secret' => env('R2_SECRET_ACCESS_KEY'),
            'region' => 'auto',
            'bucket' => env('R2_BUCKET'),
            'endpoint' => env('R2_ENDPOINT'), // https://<ACCOUNT_ID>.r2.cloudflarestorage.com
            'use_path_style_endpoint' => true,
            'visibility' => 'private',
            'throw' => true,
            'report' => true,
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
            'report' => false,
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
