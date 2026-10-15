<?php

namespace App\Modules\Marketplace\Http\Controllers;

use App\Enums\ReportStatus;
use App\Http\Controllers\Controller;
use App\Models\Listing;
use App\Models\ListingReport;
use App\Models\SellerProfile;
use App\Modules\Marketplace\Actions\ChangeListingStatus;
use App\Modules\Marketplace\Actions\ReportListing;
use App\Modules\Marketplace\Http\Requests\ReportListingRequest;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ReportController extends Controller
{
    /** Denúncia de uma publicação (logado, uma por pessoa por publicação). */
    public function store(ReportListingRequest $request, string $listing, ReportListing $action): JsonResponse
    {
        $model = Listing::published()->where('ulid', $listing)->firstOrFail();
        $action->handle($request->user(), $model, $request->validated('reason'), $request->validated('details'));

        return response()->json(['message' => 'Denúncia recebida. Nossa equipe vai analisar.'], 201);
    }

    // ---- Moderação (admin) ----

    public function index(Request $request): JsonResponse
    {
        $page = ListingReport::where('status', $request->query('status', 'open'))->with(['listing.seller.sellerProfile', 'reporter'])->oldest('id')->paginate(20);

        return response()->json([
            'data' => $page->getCollection()->map(fn (ListingReport $r) => [
                'id' => $r->ulid, 'status' => $r->status->value, 'reason' => $r->reason->value, 'details' => $r->details,
                'reporter' => $r->reporter ? Str::before($r->reporter->name, ' ') : null,
                'listing' => ['id' => $r->listing->ulid, 'title' => $r->listing->title, 'status' => $r->listing->status->value, 'seller' => $r->listing->seller->sellerProfile?->display_name],
                'created_at' => $r->created_at?->toIso8601String(),
            ])->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function resolve(Request $request, string $report, ChangeListingStatus $flow): JsonResponse
    {
        $v = $request->validate(['action' => ['required', Rule::in(['dismiss', 'suspend_listing'])], 'note' => ['nullable', 'string', 'max:500']]);
        $model = ListingReport::where('ulid', $report)->where('status', ReportStatus::Open->value)->with('listing')->firstOrFail();

        if ($v['action'] === 'suspend_listing') {
            $listing = $model->listing;
            if (in_array($listing->status->value, ['published', 'pending_review'], true)) {
                $flow->suspend($listing, $request->user(), $v['note'] ?? 'Suspensa após denúncia.');
            }
            // todas as denúncias abertas desta publicação são resolvidas junto
            ListingReport::where('listing_id', $listing->id)->where('status', ReportStatus::Open->value)
                ->update(['status' => ReportStatus::Actioned, 'handled_by' => $request->user()->id, 'handled_at' => now(), 'resolution_note' => $v['note'] ?? null]);
        } else {
            $model->update(['status' => ReportStatus::Dismissed, 'handled_by' => $request->user()->id, 'handled_at' => now(), 'resolution_note' => $v['note'] ?? null]);
        }
        Audit::record('report.resolved', ['report' => $model->ulid, 'action' => $v['action']]);

        return response()->json(['data' => ['id' => $model->ulid, 'status' => $model->fresh()->status->value]]);
    }

    // ---- Vendedores (admin) ----

    public function sellers(): JsonResponse
    {
        $page = SellerProfile::with('user')->withCount(['user as listings_count' => fn ($q) => $q->selectRaw('0')])->latest('id')->paginate(30);

        return response()->json(['data' => $page->getCollection()->map(fn (SellerProfile $s) => [
            'slug' => $s->slug, 'display_name' => $s->display_name, 'status' => $s->status,
            'commission_bps' => $s->commission_bps, 'effective_commission_bps' => $s->effectiveCommissionBps(),
            'listings' => Listing::where('seller_id', $s->user_id)->count(), 'member_since' => $s->created_at?->toDateString(),
        ])->values(), 'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()]]);
    }

    public function updateSeller(Request $request, string $slug): JsonResponse
    {
        $v = $request->validate([
            'status' => ['sometimes', Rule::in(['active', 'suspended'])],
            'commission_bps' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:5000'], // até 50%; nulo = padrão da plataforma
        ]);
        $profile = SellerProfile::where('slug', $slug)->firstOrFail();
        $profile->update($v);
        Audit::record('seller.updated_by_admin', ['seller' => $profile->slug, 'changes' => array_keys($v)]);

        return response()->json(['data' => ['slug' => $profile->slug, 'status' => $profile->status, 'commission_bps' => $profile->commission_bps, 'effective_commission_bps' => $profile->effectiveCommissionBps()]]);
    }
}
