<?php

namespace App\Modules\Media\Http\Controllers;

use App\Enums\MediaStatus;
use App\Http\Controllers\Controller;
use App\Models\Collection;
use App\Models\Media;
use App\Modules\Media\Http\Resources\IntegrationMediaResource;
use App\Modules\Storage\Contracts\MediaStorage;
use App\Modules\Tenants\Support\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Leitura do banco de imagens de UM projeto por outros sistemas (chave de API, somente leitura).
 * O projeto vem da chave (middleware); o global scope já filtra tudo por ele: mídia de outro projeto é 404.
 */
class IntegrationController extends Controller
{
    public function me(Request $request, CurrentTenant $current): JsonResponse
    {
        $key = $request->attributes->get('api_key');

        return response()->json(['data' => [
            'project' => ['id' => $current->get()->ulid, 'name' => $current->get()->name],
            'key' => ['name' => $key->name, 'expires_at' => $key->expires_at?->toIso8601String()],
            'access' => 'read-only',
        ]]);
    }

    public function index(Request $request): \Illuminate\Http\Resources\Json\AnonymousResourceCollection
    {
        $v = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'type' => ['nullable', Rule::in(['image', 'video'])],
            'collection' => ['nullable', 'string', 'max:40'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'url_ttl' => ['nullable', 'integer', 'min:60', 'max:86400'],
        ]);
        $this->ttl($request, $v);

        $query = Media::query()->where('status', MediaStatus::Ready->value)->with(['variants', 'collections'])->latest('id');

        if (! empty($v['search'])) {
            $query->where('display_name', 'like', '%' . addcslashes($v['search'], '%_\\') . '%');
        }
        if (! empty($v['type'])) {
            $query->where('type', $v['type']);
        }
        if (! empty($v['collection'])) {
            $query->whereHas('collections', fn ($q) => $q->where('collections.ulid', $v['collection']));
        }

        return IntegrationMediaResource::collection($query->paginate($v['per_page'] ?? 24)->withQueryString());
    }

    public function show(Request $request, string $media): IntegrationMediaResource
    {
        $this->ttl($request, $request->validate(['url_ttl' => ['nullable', 'integer', 'min:60', 'max:86400']]));

        return new IntegrationMediaResource($this->find($media)->load(['variants', 'collections']));
    }

    public function collections(): JsonResponse
    {
        return response()->json(['data' => Collection::query()->with('parent')->withCount('media')->orderBy('name')->get()->map(fn (Collection $c) => [
            'id' => $c->ulid, 'name' => $c->name, 'parent_id' => $c->parent?->ulid, 'media_count' => $c->media_count,
        ])->values()]);
    }

    /** Entrega o arquivo diretamente (para o outro sistema repassar ao navegador, com cache). Exige a chave. */
    public function file(string $media, string $kind, MediaStorage $storage): Response
    {
        abort_unless(in_array($kind, ['thumb', 'preview', 'original'], true), 404);

        $model = $this->find($media);
        $base = pathinfo($model->display_name, PATHINFO_FILENAME) ?: 'arquivo';

        if ($kind === 'original') {
            return $storage->response($model->path, $model->mime_type, $base . '.' . $model->extension, false, 'private, max-age=3600');
        }

        $variant = $model->variants()->where('kind', $kind)->firstOrFail();

        return $storage->response($variant->path, 'image/webp', "{$base}-{$kind}.webp", false, 'private, max-age=3600');
    }

    private function find(string $ulid): Media
    {
        return Media::where('ulid', $ulid)->where('status', MediaStatus::Ready->value)->firstOrFail();
    }

    private function ttl(Request $request, array $v): void
    {
        $request->attributes->set('url_ttl', (int) ($v['url_ttl'] ?? config('mediahub.url_ttl_minutes') * 60));
    }
}
