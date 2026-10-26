<?php

namespace App\Modules\Media\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Collection;
use App\Models\Gallery;
use App\Modules\Media\Services\PhotoIngestService;
use App\Modules\Tenants\Support\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Envio de fotos e galerias por outros sistemas (chave de API do PROJETO com permissão de envio). */
class IntegrationPhotoController extends Controller
{
    /** Lista as galerias do projeto com o link público (leitura). */
    public function galleries(): JsonResponse
    {
        return response()->json(['data' => Gallery::latest('id')->get()->map(fn (Gallery $g) => $this->present($g))->values()]);
    }

    /** Garante que o projeto tem uma galeria e devolve o link. Idempotente: chamar de novo devolve a mesma. */
    public function ensureGallery(Request $request, CurrentTenant $current): JsonResponse
    {
        $v = $request->validate(['title' => ['nullable', 'string', 'min:2', 'max:120'], 'collection' => ['nullable', 'string', 'size:26']]);
        $collectionId = null;

        if (! empty($v['collection'])) {
            $collectionId = Collection::where('ulid', $v['collection'])->value('id')
                ?? throw ValidationException::withMessages(['collection' => ['Pasta não encontrada neste projeto.']]);
        }

        $g = Gallery::where('collection_id', $collectionId)->oldest('id')->first();
        $created = false;

        if (! $g) {
            $g = new Gallery(['tenant_id' => $current->id(), 'title' => $v['title'] ?? $current->get()->name, 'is_active' => true]);
            $g->collection_id = $collectionId;
            $g->save();
            $g->refresh(); // traz os padrões do banco (download desligado, marca d'água ligada)
            \App\Modules\Galleries\Jobs\BackfillGalleryVariants::dispatch($current->id());
            $created = true;
        }

        return response()->json(['data' => $this->present($g)], $created ? 201 : 200);
    }

    /** Recebe uma foto. Multipart: file (obrigatório), taken_at (ISO 8601), collection (id da pasta), name. */
    public function store(Request $request, PhotoIngestService $ingest, CurrentTenant $current): JsonResponse
    {
        $v = $request->validate([
            'file' => ['required', 'file', 'max:' . (int) (config('mediahub.types.image.max_bytes') / 1024)],
            'taken_at' => ['nullable', 'date'],
            'collection' => ['nullable', 'string', 'size:26'],
            'name' => ['nullable', 'string', 'max:150'],
        ]);

        $collection = null;
        if (! empty($v['collection'])) {
            $collection = Collection::where('ulid', $v['collection'])->first()
                ?? throw ValidationException::withMessages(['collection' => ['Pasta não encontrada neste projeto.']]);
        }

        $media = $ingest->ingest($current->get(), $request->file('file'), $v['name'] ?? null, isset($v['taken_at']) ? CarbonImmutable::parse($v['taken_at']) : null, $collection);

        $gallery = Gallery::where('is_active', true)->oldest('id')->first();

        return response()->json(['data' => [
            'id' => $media->ulid, 'name' => $media->display_name, 'status' => 'processing',
            'width' => $media->width, 'height' => $media->height, 'taken_at' => $media->taken_at?->toIso8601String(),
            'gallery_url' => $gallery?->publicUrl(),
        ]], 201);
    }

    private function present(Gallery $g): array
    {
        return ['id' => $g->ulid, 'title' => $g->title, 'url' => $g->publicUrl(), 'is_active' => $g->is_active, 'allow_download' => $g->allow_download, 'watermark' => $g->watermark];
    }
}
