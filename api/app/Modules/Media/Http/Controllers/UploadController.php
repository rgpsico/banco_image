<?php

namespace App\Modules\Media\Http\Controllers;

use App\Enums\UploadSessionStatus;
use App\Http\Controllers\Controller;
use App\Models\Media;
use App\Models\Tenant;
use App\Models\UploadSession;
use App\Modules\Media\Http\Requests\CreateUploadRequest;
use App\Modules\Media\Http\Requests\UploadChunkRequest;
use App\Modules\Media\Http\Resources\MediaResource;
use App\Modules\Media\Http\Resources\UploadSessionResource;
use App\Modules\Media\Services\UploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UploadController extends Controller
{
    public function __construct(private UploadService $uploads)
    {
    }

    public function store(CreateUploadRequest $request, Tenant $tenant): JsonResponse
    {
        $this->authorize('create', [Media::class, $tenant]);

        $session = $this->uploads->start($tenant, $request->user(), $request->validated());

        return (new UploadSessionResource($session))->response()->setStatusCode(201);
    }

    public function chunk(UploadChunkRequest $request, Tenant $tenant, string $session, int $index): UploadSessionResource
    {
        $upload = $this->mySession($request, $session);
        $this->authorize('create', [Media::class, $tenant]);

        $this->uploads->receiveChunk($upload, $index, $request->file('chunk'));

        return new UploadSessionResource($upload->fresh());
    }

    public function complete(Request $request, Tenant $tenant, string $session): JsonResponse
    {
        $upload = $this->mySession($request, $session);
        $this->authorize('create', [Media::class, $tenant]);

        $media = $this->uploads->complete($upload);

        // A fila pode já ter processado a mídia (modo síncrono): devolve o estado atual, não o da criação.
        return (new MediaResource($media->refresh()->load('variants')))->response()->setStatusCode(201);
    }

    public function destroy(Request $request, Tenant $tenant, string $session): JsonResponse
    {
        $upload = $this->mySession($request, $session);
        $this->authorize('create', [Media::class, $tenant]);

        if ($upload->status !== UploadSessionStatus::Completed) {
            $this->uploads->abort($upload);
        }

        return response()->json(null, 204);
    }

    /** A sessão precisa ser do projeto atual (global scope) E de quem a criou. */
    private function mySession(Request $request, string $ulid): UploadSession
    {
        return UploadSession::where('ulid', $ulid)->where('user_id', $request->user()->id)->firstOrFail();
    }
}
