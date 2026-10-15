<?php

namespace App\Modules\Downloads\Services;

use App\Enums\OrderStatus;
use App\Models\DownloadGrant;
use App\Models\OrderItem;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;

class DownloadService
{
    /** Emite um link temporário de download. Só para o comprador, com pedido PAGO e direito de download vigente. */
    public function issue(User $buyer, OrderItem $item): array
    {
        return DB::transaction(function () use ($buyer, $item) {
            $grant = DownloadGrant::where('order_item_id', $item->id)->where('user_id', $buyer->id)->lockForUpdate()->first();

            // Sem pagamento confirmado não existe permissão (o pedido pendente nem tem "grant").
            if (! $grant || $item->order->status !== OrderStatus::Paid) {
                throw ValidationException::withMessages(['download' => ['Download não autorizado: o pagamento ainda não foi confirmado.']])->status(403);
            }

            if ($grant->revoked_at) {
                throw ValidationException::withMessages(['download' => ['Este download foi cancelado (pedido reembolsado).']])->status(403);
            }
            if ($grant->expires_at && $grant->expires_at->isPast()) {
                throw ValidationException::withMessages(['download' => ['O prazo para baixar esta mídia expirou.']])->status(403);
            }
            if ($grant->downloads_count >= $grant->max_downloads) {
                throw ValidationException::withMessages(['download' => ['Você atingiu o limite de downloads desta compra.']])->status(403);
            }

            $grant->update(['downloads_count' => $grant->downloads_count + 1, 'last_downloaded_at' => now()]);
            Audit::record('purchase.download_issued', ['item' => $item->ulid, 'remaining' => $grant->max_downloads - $grant->downloads_count]);

            $ttl = (int) config('mediahub.download_ttl_seconds');

            return [
                'url' => URL::temporarySignedRoute('files.purchase', now()->addSeconds($ttl), ['grant' => $grant->ulid], absolute: false),
                'expires_at' => now()->addSeconds($ttl)->toIso8601String(),
                'downloads_remaining' => $grant->max_downloads - $grant->downloads_count,
            ];
        });
    }
}
