<?php

namespace App\Modules\Collections\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Collection;
use App\Models\Media;
use App\Models\Tenant;
use App\Modules\Collections\Actions\SaveCollection;
use App\Modules\Collections\Http\Requests\AttachMediaRequest;
use App\Modules\Collections\Http\Requests\StoreCollectionRequest;
use App\Modules\Collections\Http\Requests\UpdateCollectionRequest;
use App\Modules\Collections\Http\Resources\CollectionResource;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CollectionController extends Controller
{
    public function index(Tenant $tenant)
    {
        $this->authorize('viewAny', [Collection::class, $tenant]);

        return CollectionResource::collection(
            Collection::query()->with('parent')->withCount('media')->orderBy('name')->get()
        );
    }

    public function store(StoreCollectionRequest $request, Tenant $tenant, SaveCollection $save): JsonResponse
    {
        $this->authorize('create', [Collection::class, $tenant]);

        $collection = new Collection(['tenant_id' => $tenant->id, 'created_by' => $request->user()->id]);
        $save->handle($collection, $request->validated(), true);
        Audit::record('collection.created', ['collection' => $collection->ulid]);

        return (new CollectionResource($collection->load('parent')))->response()->setStatusCode(201);
    }

    public function update(UpdateCollectionRequest $request, Tenant $tenant, string $collection, SaveCollection $save): CollectionResource
    {
        $model = $this->find($collection);
        $this->authorize('update', $model);

        $save->handle($model, $request->validated(), false);

        return new CollectionResource($model->load('parent')->loadCount('media'));
    }

    /** Subpastas sobem um nível; as mídias continuam na biblioteca (só o vínculo é removido). */
    public function destroy(Tenant $tenant, string $collection): JsonResponse
    {
        $model = $this->find($collection);
        $this->authorize('delete', $model);

        DB::transaction(function () use ($model) {
            Collection::where('parent_id', $model->id)->update(['parent_id' => $model->parent_id]);
            $model->media()->detach();
            $model->delete();
        });
        Audit::record('collection.deleted', ['collection' => $model->ulid]);

        return response()->json(null, 204);
    }

    public function attach(AttachMediaRequest $request, Tenant $tenant, string $collection): JsonResponse
    {
        $model = $this->find($collection);
        $this->authorize('update', $model);

        // Só mídias DESTE projeto (global scope). Se algum ulid não existir aqui, tudo é recusado.
        $media = Media::whereIn('ulid', $request->validated('media'))->get();

        if ($media->count() !== count($request->validated('media'))) {
            throw ValidationException::withMessages(['media' => ['Alguma mídia não foi encontrada neste projeto.']]);
        }

        $model->media()->syncWithoutDetaching($media->mapWithKeys(fn ($m) => [$m->id => ['added_by' => $request->user()->id]])->all());

        return response()->json(['data' => ['media_count' => $model->media()->count()]]);
    }

    public function detach(Tenant $tenant, string $collection, string $media): JsonResponse
    {
        $model = $this->find($collection);
        $this->authorize('update', $model);

        $item = Media::where('ulid', $media)->firstOrFail();
        $model->media()->detach($item->id);

        return response()->json(null, 204);
    }

    private function find(string $ulid): Collection
    {
        return Collection::where('ulid', $ulid)->firstOrFail();
    }
}
