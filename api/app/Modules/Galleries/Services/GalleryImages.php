<?php

namespace App\Modules\Galleries\Services;

use App\Models\Gallery;
use App\Models\Media;
use App\Models\MediaVariant;
use App\Modules\Marketplace\Services\WatermarkService;

/** Imagens que a galeria pública entrega: miniatura e foto grande, com marca d'água (ligada por padrão). */
class GalleryImages
{
    public function __construct(private WatermarkService $watermark)
    {
    }

    /** Garante as variantes marcadas (g_thumb/g_view) com o nome do projeto. Sempre parte da "preview", nunca do original. */
    public function ensure(Media $media, Gallery $gallery): void
    {
        if ($media->variants()->whereIn('kind', ['g_thumb', 'g_view'])->count() >= 2) {
            return;
        }

        $this->watermark->generateFor(
            $media, $gallery->watermarkText(), 'g_view', 'g_thumb',
            (int) config('mediahub.galleries.view_edge'), (int) config('mediahub.galleries.thumb_edge')
        );
    }

    /** @param 'thumb'|'view' $size */
    public function variant(Media $media, Gallery $gallery, string $size): ?MediaVariant
    {
        if ($gallery->watermark) {
            try {
                $this->ensure($media, $gallery); // fotos antigas ou ainda sem variante: gera na hora (uma vez)
            } catch (\Throwable $e) {
                report($e);

                return null; // nunca serve a foto SEM marca em uma galeria que pede marca
            }

            return $media->variants()->where('kind', $size === 'thumb' ? 'g_thumb' : 'g_view')->first();
        }

        return $media->variants()->where('kind', $size === 'thumb' ? 'thumb' : 'preview')->first();
    }
}
