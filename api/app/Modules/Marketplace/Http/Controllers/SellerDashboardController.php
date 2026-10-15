<?php

namespace App\Modules\Marketplace\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\OrderItem;
use App\Modules\Marketplace\Services\SellerReports;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SellerDashboardController extends Controller
{
    public function __construct(private SellerReports $reports)
    {
    }

    public function stats(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->reports->stats($request->user())]);
    }

    /** Histórico de vendas. O comprador aparece só pelo primeiro nome (privacidade). */
    public function sales(Request $request): JsonResponse
    {
        $page = $this->reports->salesQuery($request->user())->paginate(20);

        return response()->json([
            'data' => $page->getCollection()->map(fn (OrderItem $i) => [
                'id' => $i->ulid,
                'title' => $i->title_snapshot,
                'license' => $i->license?->code,
                'license_name' => $i->license?->name,
                'price_cents' => $i->unit_price_cents,
                'commission_bps' => $i->commission_bps,
                'platform_fee_cents' => $i->platform_fee_cents,
                'earnings_cents' => $i->seller_earning_cents,
                'buyer' => Str::before($i->order->buyer->name, ' '),
                'paid_at' => $i->order->paid_at?->toIso8601String(),
                'thumbnail_url' => $i->listing ? "/api/v1/market/listings/{$i->listing->ulid}/image/thumb" : null,
            ])->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function commissions(Request $request): JsonResponse
    {
        $v = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);

        return response()->json(['data' => $this->reports->commissions($request->user(), $v['from'] ?? null, $v['to'] ?? null)]);
    }
}
