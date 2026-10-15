<?php

namespace App\Modules\Marketplace\Services;

use App\Enums\OrderStatus;
use App\Models\Listing;
use App\Models\OrderItem;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/** Números do vendedor. Só contam pedidos PAGOS (reembolsados/pendentes ficam de fora). */
class SellerReports
{
    private function paid(User $seller): Builder
    {
        return OrderItem::query()->where('seller_id', $seller->id)
            ->whereHas('order', fn ($q) => $q->where('status', OrderStatus::Paid->value));
    }

    public function salesQuery(User $seller): Builder
    {
        return $this->paid($seller)->with(['order.buyer', 'license', 'listing'])->latest('id');
    }

    /** @return array<string, mixed> */
    public function stats(User $seller): array
    {
        $totals = $this->paid($seller)->selectRaw('count(*) as sales, coalesce(sum(unit_price_cents),0) as gross, coalesce(sum(platform_fee_cents),0) as fee, coalesce(sum(seller_earning_cents),0) as net')->first();

        $from = CarbonImmutable::now()->subDays(29)->startOfDay();
        $byDay = $this->paid($seller)
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.paid_at', '>=', $from)
            ->selectRaw('DATE(orders.paid_at) as day, count(*) as sales, sum(order_items.seller_earning_cents) as net')
            ->groupBy('day')->get()->keyBy('day');

        $series = [];
        for ($i = 0; $i < 30; $i++) {
            $day = $from->addDays($i)->toDateString();
            $series[] = ['date' => $day, 'sales' => (int) ($byDay[$day]->sales ?? 0), 'earnings_cents' => (int) ($byDay[$day]->net ?? 0)];
        }

        $byStatus = Listing::where('seller_id', $seller->id)->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        return [
            'sales_count' => (int) $totals->sales,
            'gross_cents' => (int) $totals->gross,
            'platform_fee_cents' => (int) $totals->fee,
            'earnings_cents' => (int) $totals->net,
            'listings' => $byStatus,
            'views_total' => (int) Listing::where('seller_id', $seller->id)->sum('views_count'),
            'last_30_days' => $series,
        ];
    }

    /** Comissões por mês: bruto, parte da plataforma e líquido do vendedor. @return array{rows: list<array>, totals: array} */
    public function commissions(User $seller, ?string $from, ?string $to): array
    {
        $start = $from ? CarbonImmutable::parse($from)->startOfDay() : CarbonImmutable::now()->subMonths(11)->startOfMonth();
        $end = $to ? CarbonImmutable::parse($to)->endOfDay() : CarbonImmutable::now()->endOfDay();

        $rows = $this->paid($seller)
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereBetween('orders.paid_at', [$start, $end])
            ->selectRaw("DATE_FORMAT(orders.paid_at, '%Y-%m') as month, count(*) as sales, sum(order_items.unit_price_cents) as gross, sum(order_items.platform_fee_cents) as fee, sum(order_items.seller_earning_cents) as net")
            ->groupBy('month')->orderBy('month')->get()
            ->map(fn ($r) => ['month' => $r->month, 'sales' => (int) $r->sales, 'gross_cents' => (int) $r->gross, 'platform_fee_cents' => (int) $r->fee, 'earnings_cents' => (int) $r->net])->all();

        return ['rows' => $rows, 'totals' => [
            'sales' => array_sum(array_column($rows, 'sales')),
            'gross_cents' => array_sum(array_column($rows, 'gross_cents')),
            'platform_fee_cents' => array_sum(array_column($rows, 'platform_fee_cents')),
            'earnings_cents' => array_sum(array_column($rows, 'earnings_cents')),
        ]];
    }
}
