<?php

namespace App\Modules\Marketplace\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Listing;
use App\Models\SellerProfile;
use App\Modules\Marketplace\Http\Requests\CatalogRequest;
use App\Modules\Marketplace\Http\Resources\PublicListingResource;
use App\Modules\Marketplace\Http\Resources\SellerProfileResource;
use App\Modules\Storage\Contracts\MediaStorage;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/** Catálogo PÚBLICO (sem login). Só enxerga publicações publicadas, de vendedores ativos. */
class CatalogController extends Controller
{
    private const WITH = ['prices.license', 'media', 'seller.sellerProfile'];

    public function index(CatalogRequest $request)
    {
        $v = $request->validated();
        $query = Listing::published()->with(self::WITH)->withMin('prices', 'price_cents');

        if (! empty($v['q'])) {
            $like = '%' . addcslashes($v['q'], '%_\\') . '%';
            $query->where(fn ($q) => $q->where('title', 'like', $like)->orWhere('description', 'like', $like)->orWhere('tags_text', 'like', $like));
        }
        if (! empty($v['type'])) {
            $query->whereHas('media', fn ($q) => $q->where('type', $v['type']));
        }
        if (! empty($v['category'])) {
            $query->where('category', $v['category']);
        }
        if (! empty($v['tag'])) {
            $query->whereJsonContains('tags', mb_strtolower($v['tag']));
        }
        if (! empty($v['seller'])) {
            $query->whereHas('seller.sellerProfile', fn ($q) => $q->where('slug', $v['seller']));
        }
        if (isset($v['min_price']) || isset($v['max_price'])) {
            $query->whereHas('prices', function ($q) use ($v) {
                $q->where('price_cents', '>=', $v['min_price'] ?? 0)->where('price_cents', '<=', $v['max_price'] ?? PHP_INT_MAX);
            });
        }

        match ($v['sort'] ?? 'newest') {
            'popular' => $query->orderByDesc('sales_count')->orderByDesc('views_count')->orderByDesc('id'),
            'price_asc' => $query->orderBy('prices_min_price_cents')->orderByDesc('id'),
            'price_desc' => $query->orderByDesc('prices_min_price_cents')->orderByDesc('id'),
            default => $query->orderByDesc('published_at')->orderByDesc('id'),
        };

        return PublicListingResource::collection($query->paginate($v['per_page'] ?? 24)->withQueryString());
    }

    public function show(string $listing): PublicListingResource
    {
        $model = Listing::published()->with(self::WITH)->where('ulid', $listing)->firstOrFail();
        $model->increment('views_count');

        return new PublicListingResource($model);
    }

    public function categories(): JsonResponse
    {
        $counts = Listing::published()->selectRaw('category, count(*) as total')->groupBy('category')->pluck('total', 'category');

        return response()->json(['data' => collect(config('mediahub.marketplace.categories'))
            ->map(fn ($label, $key) => ['id' => $key, 'label' => $label, 'count' => (int) ($counts[$key] ?? 0)])->values()]);
    }

    public function licenses(): JsonResponse
    {
        return response()->json(['data' => \App\Models\License::where('is_active', true)->orderBy('id')->get(['code', 'name', 'description', 'terms', 'allows_commercial'])]);
    }

    public function seller(string $slug): JsonResponse
    {
        $profile = SellerProfile::where('slug', $slug)->where('status', 'active')->firstOrFail();
        $count = Listing::published()->where('seller_id', $profile->user_id)->count();

        return response()->json(['data' => array_merge((new SellerProfileResource($profile))->resolve(), ['listings_count' => $count])]);
    }

    /** Prévia com marca d'água. NUNCA serve o original nem a prévia limpa: só variantes "wm_*". */
    public function image(string $listing, string $size, MediaStorage $storage): Response
    {
        $model = Listing::published()->where('ulid', $listing)->firstOrFail();
        $kind = $size === 'thumb' ? 'wm_thumb' : 'watermarked';
        $variant = $model->media->variants()->where('kind', $kind)->firstOrFail();

        return $storage->response($variant->path, 'image/webp', $kind . '.webp', false, 'public, max-age=3600');
    }
}
