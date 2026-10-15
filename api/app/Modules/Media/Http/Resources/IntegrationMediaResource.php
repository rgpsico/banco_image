<?php

namespace App\Modules\Media\Http\Resources;

use App\Modules\Media\Support\MediaUrls;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Mídia para OUTROS sistemas (chave de API do projeto). Links absolutos e assinados, com validade escolhida por quem pede.
 * O caminho interno do arquivo nunca é exposto. @mixin \App\Models\Media
 */
class IntegrationMediaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $kinds = $this->relationLoaded('variants') ? $this->variants->pluck('kind')->map(fn ($k) => $k->value)->all() : [];
        $ttl = (int) $request->attributes->get('url_ttl', config('mediahub.url_ttl_minutes') * 60);
        // URL pública configurada (APP_URL), nunca o host da requisição: atrás de proxy ele pode ser o nome interno do contêiner.
        $host = rtrim((string) config('app.url'), '/');
        $link = fn (string $kind) => $host . MediaUrls::signed($this->resource, $kind, $ttl);
        $isImage = $this->type->value === 'image';

        return [
            'id' => $this->ulid,
            'type' => $this->type->value,
            'name' => $this->display_name,
            'mime_type' => $this->mime_type,
            'width' => $this->width,
            'height' => $this->height,
            'size_bytes' => $this->size_bytes,
            'collections' => $this->whenLoaded('collections', fn () => $this->collections->pluck('ulid')->values()),
            'urls' => [
                'thumbnail' => in_array('thumb', $kinds, true) ? $link('thumb') : null,   // WebP até 480 px
                'preview' => in_array('preview', $kinds, true) ? $link('preview') : null, // WebP até 1200 px
                'original' => $link('original'),                                           // arquivo original, exibível no navegador
                'download' => $link('download'),                                           // mesmo arquivo, como anexo
            ],
            'urls_expire_at' => now()->addSeconds($ttl)->toIso8601String(),
            'file_endpoints' => $isImage || in_array('thumb', $kinds, true) ? [
                'thumb' => "/api/v1/integration/media/{$this->ulid}/file/thumb",
                'preview' => "/api/v1/integration/media/{$this->ulid}/file/preview",
                'original' => "/api/v1/integration/media/{$this->ulid}/file/original",
            ] : null,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
