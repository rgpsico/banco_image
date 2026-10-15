<?php

namespace App\Modules\Orders\Http\Controllers;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\SellerProfile;
use App\Modules\Payments\Services\PaymentService;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Pedidos, reembolsos e repasses — somente administradores da plataforma (rotas sob "platform.admin"). */
class AdminOrderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $v = $request->validate(['status' => ['nullable', Rule::enum(OrderStatus::class)]]);

        $page = Order::query()
            ->when($v['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->with(['buyer', 'items', 'payments'])
            ->latest('id')->paginate(20);

        return response()->json([
            'data' => $page->getCollection()->map(fn (Order $o) => [
                'id' => $o->ulid,
                'status' => $o->status->value,
                'total_cents' => $o->total_cents,
                'platform_fee_cents' => $o->items->sum('platform_fee_cents'),
                'buyer' => ['name' => $o->buyer->name, 'email' => $o->buyer->email],
                'items' => $o->items->map(fn ($i) => ['title' => $i->title_snapshot, 'price_cents' => $i->unit_price_cents, 'payout_status' => $i->payout_status])->values(),
                'gateway' => $o->payments->sortByDesc('id')->first()?->gateway,
                'paid_at' => $o->paid_at?->toIso8601String(),
                'created_at' => $o->created_at?->toIso8601String(),
            ])->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function refund(Request $request, string $order, PaymentService $payments): JsonResponse
    {
        $model = Order::where('ulid', $order)->firstOrFail();
        $payments->requestRefund($model);
        Audit::record('order.refunded_by_admin', ['order' => $model->ulid, 'admin' => $request->user()->ulid]);

        return response()->json(['data' => ['id' => $model->ulid, 'status' => $model->fresh()->status->value]]);
    }

    /** Valores a repassar manualmente por vendedor (vendas pagas, sem split automático e ainda não repassadas). */
    public function payouts(): JsonResponse
    {
        $rows = OrderItem::query()
            ->where('payout_status', 'pending')
            ->whereHas('order', fn ($q) => $q->where('status', OrderStatus::Paid->value))
            ->select('seller_id', DB::raw('count(*) as sales'), DB::raw('sum(seller_earning_cents) as owed_cents'))
            ->groupBy('seller_id')->get();

        $profiles = SellerProfile::whereIn('user_id', $rows->pluck('seller_id'))->get()->keyBy('user_id');

        return response()->json(['data' => $rows->map(fn ($r) => [
            'seller' => $profiles[$r->seller_id]?->slug,
            'display_name' => $profiles[$r->seller_id]?->display_name,
            'sales' => (int) $r->sales,
            'owed_cents' => (int) $r->owed_cents,
        ])->filter(fn ($r) => $r['seller'])->values()]);
    }

    /** Registra que o repasse manual das vendas pendentes de um vendedor foi feito. */
    public function markPaid(Request $request, string $slug): JsonResponse
    {
        $v = $request->validate(['note' => ['nullable', 'string', 'max:200']]);
        $profile = SellerProfile::where('slug', $slug)->firstOrFail();

        $count = OrderItem::where('seller_id', $profile->user_id)->where('payout_status', 'pending')
            ->whereHas('order', fn ($q) => $q->where('status', OrderStatus::Paid->value))
            ->update(['payout_status' => 'paid_manual', 'payout_paid_at' => now(), 'payout_note' => $v['note'] ?? null]);

        Audit::record('payout.marked_paid', ['seller' => $profile->slug, 'items' => $count]);

        return response()->json(['data' => ['seller' => $profile->slug, 'items' => $count]]);
    }
}
