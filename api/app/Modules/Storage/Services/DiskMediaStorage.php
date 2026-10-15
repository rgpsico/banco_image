<?php

namespace App\Modules\Storage\Services;

use App\Modules\Storage\Contracts\MediaStorage;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;

/** Implementação sobre qualquer disco do Laravel (local agora; S3/R2 depois). */
class DiskMediaStorage implements MediaStorage
{
    public function __construct(private string $diskName)
    {
    }

    private function disk(): Filesystem
    {
        return Storage::disk($this->diskName);
    }

    private function isLocal(): bool
    {
        return config("filesystems.disks.{$this->diskName}.driver") === 'local';
    }

    public function putFile(string $localPath, string $destination): void
    {
        $stream = fopen($localPath, 'rb');

        try {
            $this->disk()->put($destination, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    public function putContents(string $destination, string $contents): void
    {
        $this->disk()->put($destination, $contents);
    }

    public function delete(string|array $paths): void
    {
        $this->disk()->delete($paths);
    }

    public function exists(string $path): bool
    {
        return $this->disk()->exists($path);
    }

    public function size(string $path): int
    {
        return $this->disk()->size($path);
    }

    public function withLocalCopy(string $path, callable $callback): mixed
    {
        if ($this->isLocal()) {
            return $callback($this->disk()->path($path));
        }

        $tmp = tempnam(sys_get_temp_dir(), 'mhmedia');
        $in = $this->disk()->readStream($path);
        $out = fopen($tmp, 'wb');
        stream_copy_to_stream($in, $out);
        fclose($out);
        fclose($in);

        try {
            return $callback($tmp);
        } finally {
            @unlink($tmp);
        }
    }

    public function response(string $path, string $mime, string $filename, bool $download): Response
    {
        $headers = [
            'Content-Type' => $mime,
            'Content-Disposition' => HeaderUtils::makeDisposition(
                $download ? HeaderUtils::DISPOSITION_ATTACHMENT : HeaderUtils::DISPOSITION_INLINE,
                $filename,
                preg_replace('/[^\x20-\x7e]/', '_', $filename) ?: 'arquivo'
            ),
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Cache-Control' => 'private, max-age=300',
        ];

        if ($this->isLocal()) {
            // BinaryFileResponse: suporta Range (necessário para tocar vídeo) e ETag.
            return response()->file($this->disk()->path($path), $headers);
        }

        return $this->disk()->response($path, $filename, $headers, $download ? 'attachment' : 'inline');
    }
}
