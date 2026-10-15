<?php

namespace App\Modules\Media\Services;

use App\Enums\MediaStatus;
use App\Enums\UploadSessionStatus;
use App\Models\Media;
use App\Models\Tenant;
use App\Models\UploadSession;
use App\Models\User;
use App\Modules\Media\Jobs\GenerateMediaVariants;
use App\Modules\Storage\Contracts\MediaStorage;
use App\Modules\Storage\Support\MediaPaths;
use App\Support\Audit;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class UploadService
{
    public function __construct(
        private MediaStorage $storage,
        private FileInspector $inspector,
        private QuotaService $quota,
    ) {
    }

    /** @param array{original_name: string, mime_type: string, size_bytes: int, chunk_size: int} $data */
    public function start(Tenant $tenant, User $user, array $data): UploadSession
    {
        [$type] = $this->inspector->resolve($data['mime_type']);
        $size = (int) $data['size_bytes'];
        $chunkSize = (int) $data['chunk_size'];

        if ($size > $this->inspector->maxBytes($type)) {
            throw ValidationException::withMessages([
                'size_bytes' => ['Arquivo acima do limite de ' . round($this->inspector->maxBytes($type) / 1048576) . ' MB para ' . ($type->value === 'image' ? 'imagens' : 'vídeos') . '.'],
            ]);
        }

        $total = (int) ceil($size / $chunkSize);

        if ($total > (int) config('mediahub.max_chunks')) {
            throw ValidationException::withMessages(['chunk_size' => ['Parte muito pequena para este arquivo.']]);
        }

        $this->quota->assertAvailable($tenant, $size);

        return UploadSession::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'status' => UploadSessionStatus::Pending,
            'original_name' => $this->inspector->displayName($data['original_name']),
            'mime_type' => $data['mime_type'],
            'size_bytes' => $size,
            'chunk_size' => $chunkSize,
            'total_chunks' => $total,
            'expires_at' => now()->addHours((int) config('mediahub.upload_ttl_hours')),
        ]);
    }

    public function receiveChunk(UploadSession $session, int $index, UploadedFile $chunk): void
    {
        $this->assertOpen($session);

        if ($index < 0 || $index >= $session->total_chunks) {
            throw ValidationException::withMessages(['index' => ['Parte inexistente.']]);
        }

        if ($chunk->getSize() !== $this->expectedChunkSize($session, $index)) {
            throw ValidationException::withMessages(['chunk' => ['Tamanho da parte diferente do esperado.']]);
        }

        $disk = Storage::disk(config('mediahub.uploads_disk'));
        $disk->putFileAs($this->dir($session), $chunk, $index . '.part'); // nome gerado pelo servidor

        $received = count($disk->files($this->dir($session)));
        $session->update(['status' => UploadSessionStatus::Uploading, 'received_chunks' => $received]);
    }

    public function complete(UploadSession $session): Media
    {
        $this->assertOpen($session);
        $tenant = Tenant::findOrFail($session->tenant_id);
        $disk = Storage::disk(config('mediahub.uploads_disk'));
        $assembled = tempnam(sys_get_temp_dir(), 'mhup');

        try {
            $this->assemble($session, $assembled);

            if (filesize($assembled) !== (int) $session->size_bytes) {
                throw ValidationException::withMessages(['file' => ['O arquivo enviado está incompleto.']]);
            }

            $info = $this->inspector->inspect($assembled, $session->mime_type);

            if ($session->size_bytes > $this->inspector->maxBytes($info['type'])) {
                throw ValidationException::withMessages(['file' => ['Arquivo acima do limite permitido.']]);
            }

            $checksum = hash_file('sha256', $assembled);
            $media = $this->persist($session, $tenant, $assembled, $info, $checksum);
        } catch (ValidationException $e) {
            $session->update(['status' => UploadSessionStatus::Failed, 'error' => mb_substr(collect($e->errors())->flatten()->first() ?? 'Falha no upload.', 0, 250)]);
            $disk->deleteDirectory($this->dir($session));
            throw $e;
        } finally {
            @unlink($assembled);
        }

        $disk->deleteDirectory($this->dir($session));
        GenerateMediaVariants::dispatch($media->id);
        Audit::record('media.uploaded', ['tenant' => $tenant->ulid, 'media' => $media->ulid, 'bytes' => $media->size_bytes, 'mime' => $media->mime_type]);

        return $media;
    }

    public function abort(UploadSession $session): void
    {
        Storage::disk(config('mediahub.uploads_disk'))->deleteDirectory($this->dir($session));
        $session->update(['status' => UploadSessionStatus::Failed, 'error' => 'Cancelado pelo usuário.']);
    }

    private function persist(UploadSession $session, Tenant $tenant, string $file, array $info, string $checksum): Media
    {
        $ulid = strtolower((string) Str::ulid());
        $path = MediaPaths::original($tenant, $ulid, $info['extension']);

        // A cota é conferida de novo AQUI, com a linha do projeto travada (evita corrida entre uploads paralelos).
        return DB::transaction(function () use ($session, $tenant, $file, $info, $checksum, $ulid, $path) {
            $locked = Tenant::whereKey($tenant->id)->lockForUpdate()->first();
            $this->quota->assertAvailable($locked, (int) $session->size_bytes);

            $this->storage->putFile($file, $path);

            try {
                $media = new Media([
                    'tenant_id' => $tenant->id,
                    'uploaded_by' => $session->user_id,
                    'type' => $info['type'],
                    'original_name' => $session->original_name,
                    'display_name' => $session->original_name,
                    'disk' => config('mediahub.disk'),
                    'path' => $path,
                    'mime_type' => $info['mime'],
                    'extension' => $info['extension'],
                    'size_bytes' => $session->size_bytes,
                    'checksum_sha256' => $checksum,
                    'width' => $info['width'],
                    'height' => $info['height'],
                    'status' => $info['type']->value === 'image' ? MediaStatus::Processing : MediaStatus::Ready,
                ]);
                $media->ulid = $ulid; // o caminho do arquivo já usa este ULID
                $media->save();

                $locked->increment('storage_used_bytes', (int) $session->size_bytes);
                $session->update(['status' => UploadSessionStatus::Completed, 'media_id' => $media->id, 'completed_at' => now()]);
            } catch (\Throwable $e) {
                $this->storage->delete($path); // não deixa arquivo órfão
                throw $e;
            }

            return $media;
        });
    }

    private function assemble(UploadSession $session, string $target): void
    {
        $disk = Storage::disk(config('mediahub.uploads_disk'));
        $out = fopen($target, 'wb');

        try {
            for ($i = 0; $i < $session->total_chunks; $i++) {
                $part = $this->dir($session) . '/' . $i . '.part';

                if (! $disk->exists($part)) {
                    throw ValidationException::withMessages(['file' => ["Parte {$i} não foi enviada."]]);
                }

                $in = $disk->readStream($part);
                stream_copy_to_stream($in, $out);
                fclose($in);
            }
        } finally {
            fclose($out);
        }
    }

    private function expectedChunkSize(UploadSession $session, int $index): int
    {
        return $index < $session->total_chunks - 1
            ? (int) $session->chunk_size
            : (int) $session->size_bytes - (int) $session->chunk_size * ($session->total_chunks - 1);
    }

    private function assertOpen(UploadSession $session): void
    {
        $open = in_array($session->status, [UploadSessionStatus::Pending, UploadSessionStatus::Uploading], true);

        if (! $open || ($session->expires_at && $session->expires_at->isPast())) {
            throw ValidationException::withMessages(['upload' => ['Sessão de upload encerrada ou expirada. Comece o envio novamente.']]);
        }
    }

    /** Pasta temporária da sessão (ULIDs do servidor, nunca nomes do usuário). */
    public function dir(UploadSession $session): string
    {
        return $session->tenant->ulid . '/' . $session->ulid;
    }
}
