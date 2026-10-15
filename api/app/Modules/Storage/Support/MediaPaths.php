<?php

namespace App\Modules\Storage\Support;

use App\Models\Media;
use App\Models\Tenant;

/** Todos os caminhos são gerados pelo servidor (ULID + extensão validada): nada vem do usuário, sem path traversal. */
class MediaPaths
{
    public static function original(Tenant $tenant, string $mediaUlid, string $extension): string
    {
        return sprintf('tenants/%s/%s/%s.%s', $tenant->ulid, now()->format('Y/m'), $mediaUlid, $extension);
    }

    public static function variant(Media $media, string $kind): string
    {
        return sprintf('tenants/%s/variants/%s/%s.webp', $media->tenant->ulid, $media->ulid, $kind);
    }
}
