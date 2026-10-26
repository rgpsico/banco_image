<?php

namespace App\Modules\Galleries\Http\Controllers;

use App\Enums\MediaStatus;
use App\Http\Controllers\Controller;
use App\Models\Collection;
use App\Models\Gallery;
use App\Models\Media;
use App\Models\Tenant;
use App\Modules\Galleries\Jobs\BackfillGalleryVariants;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Gestão das galerias do projeto (administradores). O link público fica em public_url. */
class GalleryController extends Controller
{
    public function index(Tenant $tenant): JsonResponse
    {
        $this->authorize('update', $tenant);

        return response()->json(['data' => Gallery::with('collection')->latest('id')->get()->map(fn (Gallery $g) => $this->present($g))->values()]);
    }

    public function store(Request $request, Tenant $tenant): JsonResponse
    {
        $this->authorize('update', $tenant);
        $v = $this->validated($request, creating: true);

        if (Gallery::count() >= (int) config('mediahub.galleries.max_per_project')) {
            throw ValidationException::withMessages(['title' => ['Limite de galerias do projeto atingido.']]);
        }

        $g = new Gallery(['tenant_id' => $tenant->id, 'created_by' => $request->user()->id, 'title' => $v['title'], 'description' => $v['description'] ?? null,
            'allow_download' => $v['allow_download'] ?? false, 'watermark' => $v['watermark'] ?? true, 'is_active' => true]);
        $g->collection_id = $this->collectionId($v['collection'] ?? null);
        $g->save();

        if ($g->watermark) {
            BackfillGalleryVariants::dispatch($tenant->id);
        }
        Audit::record('gallery.created', ['gallery' => $g->ulid]);

        return response()->json(['data' => $this->present($g->load('collection'))], 201);
    }

    public function update(Request $request, Tenant $tenant, string $gallery): JsonResponse
    {
        $this->authorize('update', $tenant);
        $g = Gallery::where('ulid', $gallery)->firstOrFail();
        $v = $this->validated($request, creating: false);

        $g->fill(collect($v)->only(['title', 'description', 'allow_download', 'watermark', 'is_active'])->all());
        if (array_key_exists('collection', $v)) {
            $g->collection_id = $this->collectionId($v['collection']);
        }
        $g->save();

        if ($g->watermark && $g->is_active) {
            BackfillGalleryVariants::dispatch($tenant->id);
        }
        Audit::record('gallery.updated', ['gallery' => $g->ulid, 'changes' => array_keys($v)]);

        return response()->json(['data' => $this->present($g->load('collection'))]);
    }

    /** Troca o link: o antigo para de funcionar na hora (use se o link foi parar em mãos erradas). */
    public function rotate(Tenant $tenant, string $gallery): JsonResponse
    {
        $this->authorize('update', $tenant);
        $g = Gallery::where('ulid', $gallery)->firstOrFail();
        $g->forceFill(['token' => Gallery::newToken()])->save();
        Audit::record('gallery.link_rotated', ['gallery' => $g->ulid]);

        return response()->json(['data' => $this->present($g->load('collection'))]);
    }

    public function destroy(Tenant $tenant, string $gallery): JsonResponse
    {
        $this->authorize('update', $tenant);
        $g = Gallery::where('ulid', $gallery)->firstOrFail();
        $g->delete();
        Audit::record('gallery.deleted', ['gallery' => $g->ulid]);

        return response()->json(null, 204);
    }

    private function validated(Request $request, bool $creating): array
    {
        return $request->validate([
            'title' => [$creating ? 'required' : 'sometimes', 'string', 'min:2', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'collection' => ['sometimes', 'nullable', 'string', 'size:26'],
            'allow_download' => ['sometimes', 'boolean'],
            'watermark' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }

    private function collectionId(?string $ulid): ?int
    {
        if (! $ulid) {
            return null;
        }

        $id = Collection::where('ulid', $ulid)->value('id'); // já filtrado pelo projeto atual

        if (! $id) {
            throw ValidationException::withMessages(['collection' => ['Pasta não encontrada neste projeto.']]);
        }

        return $id;
    }

    private function present(Gallery $g): array
    {
        $photos = Media::where('type', 'image')->where('status', MediaStatus::Ready->value);
        if ($g->collection_id) {
            $photos->whereIn('id', \Illuminate\Support\Facades\DB::table('collection_media')->where('collection_id', $g->collection_id)->select('media_id'));
        }

        return [
            'id' => $g->ulid, 'title' => $g->title, 'description' => $g->description,
            'public_url' => $g->publicUrl(), 'is_active' => $g->is_active,
            'allow_download' => $g->allow_download, 'watermark' => $g->watermark,
            'collection' => $g->collection ? ['id' => $g->collection->ulid, 'name' => $g->collection->name] : null,
            'photos_count' => $photos->count(), 'views_count' => $g->views_count,
            'created_at' => $g->created_at?->toIso8601String(),
        ];
    }
}
