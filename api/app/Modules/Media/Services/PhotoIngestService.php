<?php

namespace App\Modules\Media\Services;

use App\Enums\MediaStatus;
use App\Enums\MediaType;
use App\Models\Collection;
use App\Models\Media;
use App\Models\Tenant;
use App\Modules\Galleries\Support\PhotoDate;
use App\Modules\Media\Jobs\GenerateMediaVariants;
use App\Modules\Storage\Contracts\MediaStorage;
use App\Modules\Storage\Support\MediaPaths;
use App\Support\Audit;
use Carbon\CarbonInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Recebe UMA foto enviada por outro sistema (chave de API com permissão de envio).
 * Mesmas regras do upload da biblioteca: tipo REAL pelo conteúdo, limite de tamanho e de resolução, cota do projeto,
 * caminho gerado pelo servidor. Só imagens.
 */
class PhotoIngestService
{
    public function __construct(private FileInspector $inspector, private QuotaService $quota, private MediaStorage $storage)
    {
    }

    public function ingest(Tenant $tenant, UploadedFile $file, ?string $name, ?CarbonInterface $takenAt, ?Collection $collection): Media
    {
        $path = $file->getRealPath();
        $real = (new \finfo(FILEINFO_MIME_TYPE))->file($path) ?: 'application/octet-stream';
        $info = $this->inspector->inspect($path, $real);

        if ($info['type'] !== MediaType::Image) {
            throw ValidationException::withMessages(['file' => ['Por aqui só é possível enviar imagens (JPG, PNG, WebP ou GIF).']]);
        }

        $size = (int) filesize($path);

        if ($size > $this->inspector->maxBytes($info['type'])) {
            throw ValidationException::withMessages(['file' => ['Arquivo acima do limite permitido.']]);
        }

        $takenAt = $takenAt ? $takenAt->copy()->utc() : (PhotoDate::fromFile($path, $info['mime']) ?? now());
        $ulid = strtolower((string) Str::ulid());
        $dest = MediaPaths::original($tenant, $ulid, $info['extension']);

        $media = DB::transaction(function () use ($tenant, $path, $size, $info, $ulid, $dest, $file, $name, $takenAt, $collection) {
            $locked = Tenant::whereKey($tenant->id)->lockForUpdate()->first();
            $this->quota->assertAvailable($locked, $size);
            $this->storage->putFile($path, $dest);

            try {
                $display = $this->inspector->displayName($name ?: $file->getClientOriginalName());
                $media = new Media([
                    'tenant_id' => $tenant->id, 'uploaded_by' => null, 'type' => $info['type'],
                    'original_name' => $display, 'display_name' => $display, 'disk' => config('mediahub.disk'),
                    'path' => $dest, 'mime_type' => $info['mime'], 'extension' => $info['extension'], 'size_bytes' => $size,
                    'checksum_sha256' => hash_file('sha256', $path), 'width' => $info['width'], 'height' => $info['height'],
                    'status' => MediaStatus::Processing, 'taken_at' => $takenAt,
                ]);
                $media->ulid = $ulid;
                $media->save();
                $collection?->media()->syncWithoutDetaching([$media->id]);
                $locked->increment('storage_used_bytes', $size);
            } catch (\Throwable $e) {
                $this->storage->delete($dest); // não deixa arquivo órfão
                throw $e;
            }

            return $media;
        });

        GenerateMediaVariants::dispatch($media->id);
        Audit::record('media.uploaded_by_api', ['media' => $media->ulid, 'bytes' => $size]);

        return $media;
    }
}
