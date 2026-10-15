<?php

namespace App\Modules\Marketplace\Http\Controllers;

use App\Enums\ListingStatus;
use App\Http\Controllers\Controller;
use App\Models\Listing;
use App\Modules\Marketplace\Actions\ChangeListingStatus;
use App\Modules\Marketplace\Actions\SaveListing;
use App\Modules\Marketplace\Http\Requests\SaveListingRequest;
use App\Modules\Marketplace\Http\Resources\SellerListingResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class SellerListingController extends Controller
{
    private const WITH = ['prices.license', 'media.variants'];

    public function index(Request $request)
    {
        $query = Listing::where('seller_id', $request->user()->id)->with(self::WITH)->latest('id');

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        return SellerListingResource::collection($query->paginate(20)->withQueryString());
    }

    public function store(SaveListingRequest $request, SaveListing $save): JsonResponse
    {
        $media = $save->findSellableMedia($request->user(), $request->validated('media_id'));
        $listing = $save->create($request->user(), $media, $request->validated());

        return (new SellerListingResource($listing))->response()->setStatusCode(201);
    }

    public function show(Request $request, string $listing): SellerListingResource
    {
        return new SellerListingResource($this->mine($request, $listing)->load(self::WITH));
    }

    public function update(SaveListingRequest $request, string $listing, SaveListing $save): SellerListingResource
    {
        return new SellerListingResource($save->update($this->mine($request, $listing), $request->validated()));
    }

    public function submit(Request $request, string $listing, ChangeListingStatus $flow): SellerListingResource
    {
        return new SellerListingResource($flow->submit($this->mine($request, $listing), $request->user())->load(self::WITH));
    }

    public function unpublish(Request $request, string $listing, ChangeListingStatus $flow): SellerListingResource
    {
        return new SellerListingResource($flow->unpublish($this->mine($request, $listing))->load(self::WITH));
    }

    /** Só rascunhos/recusadas/arquivadas SEM vendas podem ser apagadas. */
    public function destroy(Request $request, string $listing): JsonResponse
    {
        $model = $this->mine($request, $listing);

        if (in_array($model->status, [ListingStatus::Published, ListingStatus::PendingReview], true) || $model->sales_count > 0 || $model->orderItems()->exists()) {
            throw ValidationException::withMessages(['status' => ['Esta publicação não pode ser excluída (está no ar, em análise ou já teve vendas). Despublique-a.']]);
        }

        $model->forceDelete();

        return response()->json(null, 204);
    }

    /** Apenas as MINHAS publicações; as dos outros viram 404. */
    private function mine(Request $request, string $ulid): Listing
    {
        return Listing::where('ulid', $ulid)->where('seller_id', $request->user()->id)->firstOrFail();
    }
}
