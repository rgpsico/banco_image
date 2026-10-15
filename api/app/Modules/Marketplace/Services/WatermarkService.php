<?php

namespace App\Modules\Marketplace\Services;

use App\Models\Media;
use App\Models\MediaVariant;
use App\Modules\Storage\Contracts\MediaStorage;
use App\Modules\Storage\Support\MediaPaths;

/**
 * Prévias PÚBLICAS com marca d'água. Parte SEMPRE da variante "preview" (nunca do original),
 * então o arquivo vendido jamais é exposto no catálogo.
 */
class WatermarkService
{
    public function __construct(private MediaStorage $storage)
    {
    }

    public function generate(Media $media): void
    {
        $preview = $media->variants()->where('kind', 'preview')->first();

        if (! $preview) {
            throw new \RuntimeException('Mídia sem prévia para aplicar a marca d\'água.');
        }

        $this->storage->withLocalCopy($preview->path, function (string $local) use ($media) {
            $source = @imagecreatefromwebp($local);

            if (! $source) {
                throw new \RuntimeException('Não foi possível abrir a prévia.');
            }

            $this->store($media, 'watermarked', $source, (int) config('mediahub.marketplace.public_preview_edge'));
            $this->store($media, 'wm_thumb', $source, (int) config('mediahub.marketplace.public_thumb_edge'));
            imagedestroy($source);
        });
    }

    private function store(Media $media, string $kind, \GdImage $source, int $maxEdge): void
    {
        [$w, $h] = [imagesx($source), imagesy($source)];
        $scale = min(1, $maxEdge / max($w, $h));
        $img = imagescale($source, max(1, (int) round($w * $scale)), max(1, (int) round($h * $scale)), IMG_BICUBIC);

        $this->mark($img);

        ob_start();
        imagewebp($img, null, 72);
        $data = (string) ob_get_clean();
        $width = imagesx($img);
        $height = imagesy($img);
        imagedestroy($img);

        $path = MediaPaths::variant($media, $kind);
        $this->storage->putContents($path, $data);

        MediaVariant::updateOrCreate(
            ['media_id' => $media->id, 'kind' => $kind],
            ['disk' => config('mediahub.disk'), 'path' => $path, 'width' => $width, 'height' => $height, 'size_bytes' => strlen($data)]
        );
    }

    /** Texto diagonal repetido por toda a imagem (impossível de recortar sem estragar a foto). */
    private function mark(\GdImage $img): void
    {
        $text = (string) config('mediahub.marketplace.watermark_text');
        $font = (string) config('mediahub.marketplace.watermark_font');
        [$w, $h] = [imagesx($img), imagesy($img)];
        imagealphablending($img, true);
        $light = imagecolorallocatealpha($img, 255, 255, 255, 80);
        $dark = imagecolorallocatealpha($img, 0, 0, 0, 100);

        if (is_file($font) && function_exists('imagettftext')) {
            $size = max(12, (int) round(min($w, $h) / 14));
            $stepX = (int) round($size * strlen($text) * 0.95);
            $stepY = (int) round($size * 3.2);

            for ($y = -$h; $y < $h * 2; $y += $stepY) {
                for ($x = -$w; $x < $w * 2; $x += $stepX) {
                    $ox = $x + (intdiv($y, $stepY) % 2 === 0 ? 0 : (int) ($stepX / 2));
                    imagettftext($img, $size, 28, $ox + 2, $y + 2, $dark, $font, $text);
                    imagettftext($img, $size, 28, $ox, $y, $light, $font, $text);
                }
            }

            return;
        }

        // Plano B (sem fonte TrueType): fonte embutida do GD, sem rotação.
        for ($y = 10; $y < $h; $y += 60) {
            for ($x = 10; $x < $w; $x += 140) {
                imagestring($img, 5, $x, $y, $text, $light);
            }
        }
    }
}
