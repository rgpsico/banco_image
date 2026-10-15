<?php

namespace Tests\Concerns;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

trait MakesMedia
{
    protected function fakeDisks(): void
    {
        Storage::fake('media');
        Storage::fake('uploads');
    }

    /** JPEG real (com ruído para ocupar espaço e render em várias partes). */
    protected function jpegBytes(int $w = 800, int $h = 600): string
    {
        $im = imagecreatetruecolor($w, $h);
        for ($i = 0; $i < 12000; $i++) {
            imagesetpixel($im, random_int(0, $w - 1), random_int(0, $h - 1), imagecolorallocate($im, random_int(0, 255), random_int(0, 255), random_int(0, 255)));
        }
        ob_start();
        imagejpeg($im, null, 90);

        return (string) ob_get_clean();
    }

    protected function pngBytes(int $w = 200, int $h = 100): string
    {
        $im = imagecreatetruecolor($w, $h);
        ob_start();
        imagepng($im);

        return (string) ob_get_clean();
    }

    /** MP4 mínimo (assinatura ftyp/isom): suficiente para a detecção real de tipo. */
    protected function mp4Bytes(int $size = 4000): string
    {
        return hex2bin('000000186674797069736f6d0000020069736f6d69736f32') . str_repeat("\0", $size);
    }

    protected function base(Tenant $tenant): string
    {
        return "/api/v1/tenants/{$tenant->ulid}";
    }

    /** Fluxo completo de upload em partes pela API. Retorna a resposta do "complete". */
    protected function uploadBytes(User $user, Tenant $tenant, string $bytes, string $name = 'foto.jpg', string $mime = 'image/jpeg', int $chunk = 50000): TestResponse
    {
        Sanctum::actingAs($user);
        $session = $this->postJson($this->base($tenant) . '/uploads', [
            'original_name' => $name, 'mime_type' => $mime, 'size_bytes' => strlen($bytes), 'chunk_size' => $chunk,
        ])->assertCreated()->json('data');

        foreach (str_split($bytes, $chunk) as $i => $part) {
            $this->sendChunk($tenant, $session['id'], $i, $part)->assertOk();
        }

        return $this->postJson($this->base($tenant) . "/uploads/{$session['id']}/complete");
    }

    protected function sendChunk(Tenant $tenant, string $sessionId, int $index, string $bytes): TestResponse
    {
        $tmp = tempnam(sys_get_temp_dir(), 'chunk');
        file_put_contents($tmp, $bytes);

        return $this->post(
            $this->base($tenant) . "/uploads/{$sessionId}/chunks/{$index}",
            ['chunk' => new UploadedFile($tmp, 'chunk.bin', 'application/octet-stream', null, true)],
            ['Accept' => 'application/json']
        );
    }
}
