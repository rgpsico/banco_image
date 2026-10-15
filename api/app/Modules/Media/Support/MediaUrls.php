<?php

namespace App\Modules\Media\Support;

use App\Models\Media;
use Illuminate\Support\Facades\URL;

/**
 * URLs assinadas e temporárias (RELATIVAS: funcionam atrás de proxy/Vite sem depender do host).
 * A permissão é verificada quando a URL é emitida; a assinatura impede alterar o arquivo ou a validade.
 */
class MediaUrls
{
    public static function signed(Media $media, string $kind, ?int $ttlSeconds = null): string
    {
        $ttl = $ttlSeconds ?? (int) config('mediahub.url_ttl_minutes') * 60;

        return URL::temporarySignedRoute('files.media', now()->addSeconds($ttl), ['media' => $media->ulid, 'kind' => $kind], absolute: false);
    }
}
