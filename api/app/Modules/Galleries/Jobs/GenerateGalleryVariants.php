<?php

namespace App\Modules\Galleries\Jobs;

use App\Enums\MediaStatus;
use App\Enums\MediaType;
use App\Models\Gallery;
use App\Models\Media;
use App\Modules\Galleries\Services\GalleryImages;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Prepara as imagens com marca d'água de UMA foto para a galeria do projeto. */
class GenerateGalleryVariants implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;
    public int $timeout = 120;

    public function __construct(public int $mediaId)
    {
    }

    /** Só enfileira se o projeto tem galeria ativa com marca d'água. */
    public static function dispatchFor(Media $media): void
    {
        if ($media->type !== MediaType::Image) {
            return;
        }

        $has = Gallery::withoutGlobalScope('tenant')->where('tenant_id', $media->tenant_id)->where('is_active', true)->where('watermark', true)->exists();

        if ($has) {
            self::dispatch($media->id);
        }
    }

    public function handle(GalleryImages $images): void
    {
        $media = Media::withoutGlobalScope('tenant')->find($this->mediaId);

        if (! $media || $media->type !== MediaType::Image || $media->status !== MediaStatus::Ready) {
            return;
        }

        $gallery = Gallery::withoutGlobalScope('tenant')->with('tenant')->where('tenant_id', $media->tenant_id)->where('is_active', true)->where('watermark', true)->first();

        if ($gallery) {
            $images->ensure($media, $gallery);
        }
    }
}
