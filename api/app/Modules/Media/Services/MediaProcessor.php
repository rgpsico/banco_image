<?php

namespace App\Modules\Media\Services;

use App\Enums\MediaStatus;
use App\Enums\MediaType;
use App\Models\Media;
use App\Models\MediaVariant;
use App\Modules\Storage\Contracts\MediaStorage;
use App\Modules\Storage\Support\MediaPaths;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/** Gera miniatura e preview (WebP) e extrai metadados seguros. Vídeos: sem poster nesta fase (requer ffmpeg). */
class MediaProcessor
{
    public function __construct(private MediaStorage $storage)
    {
    }

    public function process(Media $media): void
    {
        if ($media->type === MediaType::Video) {
            $this->processVideo($media);

            return;
        }

        try {
            $this->storage->withLocalCopy($media->path, function (string $local) use ($media) {
                $this->buildVariants($media, $local, $media->mime_type);
                $media->update(['metadata' => $this->metadata($local, $media->mime_type), 'status' => MediaStatus::Ready]);
            });
        } catch (\Throwable $e) {
            Log::warning('Falha ao processar mídia', ['media' => $media->ulid, 'erro' => $e->getMessage()]);
            $media->update(['status' => MediaStatus::Failed]);
        }
    }

    /**
     * Vídeo: usa o ffmpeg para tirar um quadro e gerar as mesmas variantes das imagens (miniatura/preview).
     * Se o ffmpeg falhar, o vídeo continua utilizável (reprodução e download), apenas sem miniatura.
     */
    private function processVideo(Media $media): void
    {
        try {
            $this->storage->withLocalCopy($media->path, function (string $local) use ($media) {
                $info = $this->probe($local);
                $frame = tempnam(sys_get_temp_dir(), 'mhframe') . '.jpg';

                try {
                    $at = ($info['duration'] ?? 0) > 2 ? '1' : '0';
                    $run = new Process(['ffmpeg', '-y', '-v', 'error', '-ss', $at, '-i', $local, '-frames:v', '1', '-vf', "scale='min(1600,iw)':-2", '-q:v', '3', $frame]);
                    $run->setTimeout(60)->run();

                    if ($run->isSuccessful() && is_file($frame) && filesize($frame) > 0) {
                        $this->buildVariants($media, $frame, 'image/jpeg');
                    }
                } finally {
                    @unlink($frame);
                }

                $update = ['status' => MediaStatus::Ready];
                if (! empty($info['duration'])) {
                    $update['duration_seconds'] = (int) round($info['duration']);
                }
                if (! empty($info['width'])) {
                    $update['width'] = $info['width'];
                    $update['height'] = $info['height'];
                }
                $media->update($update);
            });
        } catch (\Throwable $e) {
            Log::warning('Falha ao processar vídeo', ['media' => $media->ulid, 'erro' => $e->getMessage()]);
            $media->update(['status' => MediaStatus::Ready]); // utilizável mesmo sem miniatura
        }
    }

    /** @return array{duration?: float, width?: int, height?: int} */
    private function probe(string $path): array
    {
        $run = new Process(['ffprobe', '-v', 'error', '-select_streams', 'v:0', '-show_entries', 'stream=width,height:format=duration', '-of', 'json', $path]);
        $run->setTimeout(30)->run();

        if (! $run->isSuccessful()) {
            return [];
        }

        $data = json_decode($run->getOutput(), true) ?: [];

        return array_filter([
            'duration' => isset($data['format']['duration']) ? (float) $data['format']['duration'] : null,
            'width' => $data['streams'][0]['width'] ?? null,
            'height' => $data['streams'][0]['height'] ?? null,
        ]);
    }

    /** Gera thumb/preview a partir de um arquivo de imagem local. */
    private function buildVariants(Media $media, string $localImage, string $mime): void
    {
        $size = @getimagesize($localImage);
        if ($size) {
            $this->ensureMemoryFor((int) $size[0], (int) $size[1]);
        }

        $image = $this->load($localImage, $mime);
        $image = $this->fixOrientation($image, $localImage, $mime);

        foreach (config('mediahub.variants') as $kind => $maxEdge) {
            $this->storeVariant($media, $kind, $image, (int) $maxEdge);
        }

        imagedestroy($image);
    }

    /**
     * O GD descompacta a imagem inteira na memória (4 bytes por pixel) e ainda faz cópias ao girar/redimensionar:
     * uma foto de 24 MP passa de 250 MB. Sobe o limite do processo só o necessário, até um teto configurável;
     * acima disso recusa com mensagem clara em vez de derrubar o processo.
     */
    public function ensureMemoryFor(int $width, int $height): void
    {
        $needed = (int) ($width * $height * 4 * 2.6) + 64 * 1024 * 1024;
        $cap = (int) config('mediahub.processing_memory_mb', 1024) * 1024 * 1024;
        $current = $this->memoryLimitBytes();

        if ($current === -1 || $current >= $needed) {
            return;
        }

        if ($needed > $cap) {
            throw new \RuntimeException("Imagem de {$width}x{$height} grande demais para processar.");
        }

        ini_set('memory_limit', (string) $needed);
    }

    private function memoryLimitBytes(): int
    {
        $v = trim((string) ini_get('memory_limit'));
        if ($v === '-1') {
            return -1;
        }
        $n = (int) $v;

        return match (strtolower(substr($v, -1))) {
            'g' => $n * 1024 ** 3,
            'm' => $n * 1024 ** 2,
            'k' => $n * 1024,
            default => $n,
        };
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
