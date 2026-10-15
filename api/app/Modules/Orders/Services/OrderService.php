<?php

namespace App\Modules\Orders\Services;

use App\Enums\OrderStatus;
use App\Models\Listing;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderService
{
    public function __construct(private CommissionCalculator $commission)
    {
    }

    /**
     * @param list<array{listing: string, license: string}> $items
     * Preço e comissão são calculados AQUI, no servidor, e congelados no item (o cliente nunca informa valores).
     */
    public function create(User $buyer, array $items, ?string $idempotencyKey = null): Order
    {
        $key = $idempotencyKey ? $buyer->id . ':' . $idempotencyKey : null;

        if ($key && ($existing = Order::where('idempotency_key', $key)->first())) {
            return $existing->load('items');
        }

        $seen = [];
        $lines = [];

        foreach ($items as $i => $row) {
            $listing = Listing::published()->with(['prices.license', 'seller.sellerProfile'])->where('ulid', $row['listing'])->first();

            if (! $listing) {
                throw ValidationException::withMessages(["items.{$i}.listing" => ['Mídia indisponível.']]);
            }
            if ($listing->seller_id === $buyer->id) {
                throw ValidationException::withMessages(["items.{$i}.listing" => ['Você não pode comprar a sua própria mídia.']]);
            }

            $price = $listing->prices->first(fn ($p) => $p->license->code === $row['license']);

            if (! $price) {
                throw ValidationException::withMessages(["items.{$i}.license" => ['Licença não oferecida para esta mídia.']]);
            }

            $dupKey = $listing->id . ':' . $price->license_id;
            if (isset($seen[$dupKey])) {
                throw ValidationException::withMessages(["items.{$i}" => ['Item repetido no pedido.']]);
            }
            $seen[$dupKey] = true;

            if ($this->alreadyBought($buyer, $listing, $price->license_id)) {
                throw ValidationException::withMessages(["items.{$i}" => ["Você já comprou \"{$listing->title}\" com a licença {$price->license->name}."]]);
            }

            $lines[] = [$listing, $price];
        }

        return DB::transaction(function () use ($buyer, $lines, $key) {
            $order = Order::create([
                'buyer_id' => $buyer->id, 'status' => OrderStatus::Pending, 'currency' => 'BRL',
                'idempotency_key' => $key, 'expires_at' => now()->addMinutes((int) config('mediahub.payments.order_ttl_minutes')),
            ]);

            $subtotal = $fees = 0;

            foreach ($lines as [$listing, $price]) {
                $bps = $listing->seller->sellerProfile->effectiveCommissionBps();
                $split = $this->commission->split($price->price_cents, $bps);

                OrderItem::create([
                    'order_id' => $order->id, 'listing_id' => $listing->id, 'license_id' => $price->license_id, 'seller_id' => $listing->seller_id,
                    'title_snapshot' => $listing->title, 'unit_price_cents' => $price->price_cents, 'commission_bps' => $bps,
                    'platform_fee_cents' => $split['platform_fee'], 'seller_earning_cents' => $split['seller_earning'],
                ]);

                $subtotal += $price->price_cents;
                $fees += $split['platform_fee'];
            }

            // O comprador paga o preço de vitrine; a comissão sai da parte do vendedor.
            $order->update(['subtotal_cents' => $subtotal, 'platform_fee_cents' => $fees, 'total_cents' => $subtotal]);
            Audit::record('order.created', ['order' => $order->ulid, 'total_cents' => $subtotal]);

            return $order->load('items');
        });
    }

    private function alreadyBought(User $buyer, Listing $listing, int $licenseId): bool
    {
        return OrderItem::where('listing_id', $listing->id)->where('license_id', $licenseId)
            ->whereHas('order', fn ($q) => $q->where('buyer_id', $buyer->id)->where('status', OrderStatus::Paid->value))
            ->exists();
    }

    /** Expira pedidos pendentes vencidos (comando agendado). */
    public function expireStale(): int
    {
        return Order::where('status', OrderStatus::Pending->value)->where('expires_at', '<', now())
            ->update(['status' => OrderStatus::Expired->value]);
    }
}
