<?php

namespace App\Modules\Media\Services;

use App\Enums\MediaStatus;
use App\Enums\MediaType;
use App\Models\Media;
use App\Models\MediaVariant;
use App\Modules\Storage\Contracts\MediaStorage;
use App\Modules\Storage\Support\MediaPaths;
use Illuminate\Support\Facades\Log;

/** Gera miniatura e preview (WebP) e extrai metadados seguros. Vídeos: sem poster nesta fase (requer ffmpeg). */
class MediaProcessor
{
    public function __construct(private MediaStorage $storage)
    {
    }

    public function process(Media $media): void
    {
        if ($media->type !== MediaType::Image) {
            $media->update(['status' => MediaStatus::Ready]);

            return;
        }

        try {
            $this->storage->withLocalCopy($media->path, function (string $local) use ($media) {
                $image = $this->load($local, $media->mime_type);
                $image = $this->fixOrientation($image, $local, $media->mime_type);

                foreach (config('mediahub.variants') as $kind => $maxEdge) {
                    $this->storeVariant($media, $kind, $image, (int) $maxEdge);
                }

                imagedestroy($image);
                $media->update(['metadata' => $this->metadata($local, $media->mime_type), 'status' => MediaStatus::Ready]);
            });
        } catch (\Throwable $e) {
            Log::warning('Falha ao processar mídia', ['media' => $media->ulid, 'erro' => $e->getMessage()]);
            $media->update(['status' => MediaStatus::Failed]);
        }
    }

    private function load(string $path, string $mime): \GdImage
    {
        $image = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($path),
            'image/png' => @imagecreatefrompng($path),
            'image/webp' => @imagecreatefromwebp($path),
            'image/gif' => @imagecreatefromgif($path),
            default => false,
        };

        if (! $image) {
            throw new \RuntimeException('Não foi possível decodificar a imagem.');
        }

        return $image;
    }

    /** Aplica a rotação EXIF (fotos de celular) para a miniatura sair na posição certa. */
    private function fixOrientation(\GdImage $image, string $path, string $mime): \GdImage
    {
        if ($mime !== 'image/jpeg' || ! function_exists('exif_read_data')) {
            return $image;
        }

        $exif = @exif_read_data($path);
        $angle = match ($exif['Orientation'] ?? 1) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };

        if ($angle !== 0) {
            $rotated = imagerotate($image, $angle, 0);
            if ($rotated) {
                imagedestroy($image);

                return $rotated;
            }
        }

        return $image;
    }

    private function storeVariant(Media $media, string $kind, \GdImage $image, int $maxEdge): void
    {
        [$w, $h] = [imagesx($image), imagesy($image)];
        $scale = min(1, $maxEdge / max($w, $h)); // nunca aumenta a imagem
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));

        $resized = imagescale($image, $nw, $nh, IMG_BICUBIC);
        imagealphablending($resized, false);
        imagesavealpha($resized, true);

        ob_start();
        imagewebp($resized, null, 82);
        $data = (string) ob_get_clean();
        imagedestroy($resized);

        $path = MediaPaths::variant($media, $kind);
        $this->storage->putContents($path, $data);

        MediaVariant::updateOrCreate(
            ['media_id' => $media->id, 'kind' => $kind],
            ['disk' => config('mediahub.disk'), 'path' => $path, 'width' => $nw, 'height' => $nh, 'size_bytes' => strlen($data)]
        );
    }

    /** Somente campos inofensivos. GPS e dados do dono do equipamento NÃO são guardados (privacidade). */
    private function metadata(string $path, string $mime): array
    {
        if ($mime !== 'image/jpeg' || ! function_exists('exif_read_data')) {
            return [];
        }

        $exif = @exif_read_data($path) ?: [];
        $camera = trim(($exif['Make'] ?? '') . ' ' . ($exif['Model'] ?? ''));
        $taken = $exif['DateTimeOriginal'] ?? null;

        return array_filter([
            'camera' => $camera !== '' ? mb_substr($camera, 0, 80) : null,
            'taken_at' => is_string($taken) && preg_match('/^\d{4}:\d{2}:\d{2} /', $taken) ? str_replace(':', '-', substr($taken, 0, 10)) . substr($taken, 10) : null,
        ]);
    }
}
