<?php

namespace App\Modules\Tenants\Http\Controllers;

use App\Enums\MediaStatus;
use App\Http\Controllers\Controller;
use App\Models\Gallery;
use App\Models\Media;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;

/** Números da "Visão geral" do projeto (qualquer membro vê). Tudo já filtrado pelo projeto atual. */
class TenantOverviewController extends Controller
{
    public function show(Tenant $tenant): JsonResponse
    {
        $this->authorize('view', $tenant);

        $media = Media::query();

        return response()->json(['data' => [
            'photos' => (clone $media)->where('type', 'image')->count(),
            'videos' => (clone $media)->where('type', 'video')->count(),
            'processing' => (clone $media)->where('status', MediaStatus::Processing->value)->count(),
            'galleries' => Gallery::count(),
            'gallery_views' => (int) Gallery::sum('views_count'),
            'last_upload_at' => $media->latest('created_at')->value('created_at'),
            'storage' => ['used_bytes' => (int) $tenant->storage_used_bytes, 'quota_bytes' => (int) $tenant->storage_quota_bytes],
        ]]);
    }
}
