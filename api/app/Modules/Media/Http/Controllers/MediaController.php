<?php

namespace App\Modules\Media\Http\Controllers;

use App\Enums\ListingStatus;
use App\Http\Controllers\Controller;
use App\Models\Media;
use App\Models\Tenant;
use App\Modules\Media\Http\Requests\IndexMediaRequest;
use App\Modules\Media\Http\Requests\UpdateMediaRequest;
use App\Modules\Media\Http\Resources\MediaResource;
use App\Modules\Media\Services\FileInspector;
use App\Modules\Media\Support\MediaUrls;
use App\Modules\Storage\Contracts\MediaStorage;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MediaController extends Controller
{
    public function index(IndexMediaRequest $request, Tenant $tenant)
    {
        $this->authorize('viewAny', [Media::class, $tenant]);

        $query = Media::query()->with(['variants', 'collections'])->latest('id');

        if ($search = $request->validated('search')) {
            $query->where('display_name', 'like', '%' . addcslashes($search, '%_\\') . '%');
        }

        if ($type = $request->validated('type')) {
            $query->where('type', $type);
        }

        if ($collection = $request->validated('collection')) {
            $query->whereHas('collections', fn ($q) => $q->where('collections.ulid', $collection));
        }

        return MediaResource::collection($query->paginate($request->validated('per_page') ?? 24)->withQueryString());
    }

    public function show(Tenant $tenant, string $media): MediaResource
    {
        $model = $this->find($media);
        $this->authorize('view', $model);

        return new MediaResource($model->load(['variants', 'collections']));
    }

    public function update(UpdateMediaRequest $request, Tenant $tenant, string $media, FileInspector $inspector): MediaResource
    {
        $model = $this->find($media);
        $this->authorize('update', $model);

        $model->update(['display_name' => $inspector->displayName($request->validated('display_name'))]);
        Audit::record('media.renamed', ['media' => $model->ulid]);

        return new MediaResource($model->load(['variants', 'collections']));
    }

    public function destroy(Tenant $tenant, string $media, MediaStorage $storage): JsonResponse
    {
        $model = $this->find($media);
        $this->authorize('delete', $model);

        // Mídia à venda (ou em análise) no marketplace não pode ser apagada: despublique antes.
        if ($model->listing()->whereIn('status', [ListingStatus::Published->value, ListingStatus::PendingReview->value])->exists()) {
            throw ValidationException::withMessages(['media' => ['Esta mídia está publicada no marketplace. Despublique antes de excluir.']]);
        }

        $paths = $model->variants->pluck('path')->push($model->path)->all();

        DB::transaction(function () use ($model, $tenant) {
            $model->collections()->detach();
            $model->variants()->delete();
            $model->delete();
            Tenant::whereKey($tenant->id)->update(['storage_used_bytes' => DB::raw('GREATEST(0, storage_used_bytes - ' . (int) $model->size_bytes . ')')]);
        });

        $storage->delete($paths);
        Audit::record('media.deleted', ['media' => $model->ulid]);

        return response()->json(null, 204);
    }

    /** Emite uma URL de download temporária. A permissão é verificada AQUI; a URL expira em poucos minutos. */
    public function downloadUrl(Tenant $tenant, string $media): JsonResponse
    {
        $model = $this->find($media);
        $this->authorize('download', $model);

        $ttl = (int) config('mediahub.download_ttl_seconds');
        Audit::record('media.download_url_issued', ['media' => $model->ulid]);

        return response()->json(['data' => [
            'url' => MediaUrls::signed($model, 'download', $ttl),
            'expires_at' => now()->addSeconds($ttl)->toIso8601String(),
        ]]);
    }

    /** Busca pelo ULID já filtrada pelo projeto atual (global scope): de outro projeto vira 404. */
    private function find(string $ulid): Media
    {
        return Media::where('ulid', $ulid)->firstOrFail();
    }
}
