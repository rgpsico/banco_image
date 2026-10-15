<?php

namespace App\Modules\Marketplace\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Listing;
use App\Modules\Marketplace\Actions\ChangeListingStatus;
use App\Modules\Marketplace\Http\Requests\ReasonRequest;
use App\Modules\Marketplace\Http\Resources\SellerListingResource;
use Illuminate\Http\Request;

/** Moderação (somente admin da plataforma, garantido pelo middleware platform.admin). */
class ModerationController extends Controller
{
    private const WITH = ['prices.license', 'media.variants', 'seller.sellerProfile'];

    public function index(Request $request)
    {
        $status = $request->query('status', 'pending_review');

        return SellerListingResource::collection(
            Listing::where('status', $status)->with(self::WITH)->oldest('id')->paginate(20)->withQueryString()
        );
    }

    public function show(string $listing): SellerListingResource
    {
        return new SellerListingResource(Listing::where('ulid', $listing)->with(self::WITH)->firstOrFail());
    }

    public function approve(Request $request, string $listing, ChangeListingStatus $flow): SellerListingResource
    {
        return new SellerListingResource($flow->approve($this->find($listing), $request->user())->load(self::WITH));
    }

    public function reject(ReasonRequest $request, string $listing, ChangeListingStatus $flow): SellerListingResource
    {
        return new SellerListingResource($flow->reject($this->find($listing), $request->user(), $request->validated('reason'))->load(self::WITH));
    }

    public function suspend(ReasonRequest $request, string $listing, ChangeListingStatus $flow): SellerListingResource
    {
        return new SellerListingResource($flow->suspend($this->find($listing), $request->user(), $request->validated('reason'))->load(self::WITH));
    }

    private function find(string $ulid): Listing
    {
        return Listing::where('ulid', $ulid)->firstOrFail();
    }
}
