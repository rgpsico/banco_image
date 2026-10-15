<?php

namespace App\Modules\Downloads\Http\Controllers;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\OrderItem;
use App\Modules\Downloads\Services\DownloadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PurchaseController extends Controller
{
    /** "Minhas compras": itens de pedidos PAGOS do usuário. */
    public function index(Request $request)
    {
        $items = OrderItem::whereHas('order', fn ($q) => $q->where('buyer_id', $request->user()->id)->where('status', OrderStatus::Paid->value))
            ->with(['order', 'license', 'listing', 'downloadGrants' => fn ($q) => $q->where('user_id', $request->user()->id)])
            ->latest('id')->paginate(20);

        return response()->json([
            'data' => $items->getCollection()->map(function (OrderItem $i) {
                $grant = $i->downloadGrants->first();

                return [
                    'id' => $i->ulid, 'title' => $i->title_snapshot, 'license' => $i->license?->code, 'license_name' => $i->license?->name,
                    'license_terms' => $i->license?->terms, 'price_cents' => $i->unit_price_cents,
                    'purchased_at' => $i->order->paid_at?->toIso8601String(),
                    'thumbnail_url' => $i->listing ? "/api/v1/market/listings/{$i->listing->ulid}/image/thumb" : null,
                    'downloads_remaining' => $grant && ! $grant->revoked_at ? max(0, $grant->max_downloads - $grant->downloads_count) : 0,
                    'download_expires_at' => $grant?->expires_at?->toIso8601String(),
                ];
            })->values(),
            'meta' => ['current_page' => $items->currentPage(), 'last_page' => $items->lastPage(), 'total' => $items->total()],
        ]);
    }

    public function downloadUrl(Request $request, string $item, DownloadService $downloads): JsonResponse
    {
        // Só itens dos pedidos do próprio usuário; de outros: 404.
        $model = OrderItem::where('ulid', $item)->whereHas('order', fn ($q) => $q->where('buyer_id', $request->user()->id))->with('order')->firstOrFail();

        return response()->json(['data' => $downloads->issue($request->user(), $model)]);
    }
}
