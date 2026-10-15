<?php

namespace App\Modules\Marketplace\Actions;

use App\Enums\ListingStatus;
use App\Models\Listing;
use App\Models\User;
use App\Modules\Marketplace\Services\ListingRules;
use App\Modules\Marketplace\Services\WatermarkService;
use App\Support\Audit;
use Illuminate\Validation\ValidationException;

/** Fluxo de publicação: rascunho → em análise → publicada / recusada / suspensa / arquivada. */
class ChangeListingStatus
{
    public function __construct(private ListingRules $rules, private WatermarkService $watermark)
    {
    }

    /** Vendedor envia para análise (ou publica direto, se for admin da plataforma ou houver auto-aprovação). */
    public function submit(Listing $listing, User $seller): Listing
    {
        $this->assertFrom($listing, [ListingStatus::Draft, ListingStatus::Rejected, ListingStatus::Archived], 'enviada para análise');

        if ($seller->sellerProfile?->status !== 'active') {
            throw ValidationException::withMessages(['seller' => ['Sua conta de vendedor está suspensa.']]);
        }

        $this->rules->assertReadyToSubmit($listing);
        $listing->update(['status' => ListingStatus::PendingReview, 'rejection_reason' => null]);
        Audit::record('listing.submitted', ['listing' => $listing->ulid]);

        if ($seller->is_platform_admin || config('mediahub.marketplace.auto_approve')) {
            return $this->approve($listing, $seller);
        }

        return $listing->fresh();
    }

    public function approve(Listing $listing, User $moderator): Listing
    {
        $this->assertFrom($listing, [ListingStatus::PendingReview, ListingStatus::Suspended], 'publicada');

        try {
            $this->watermark->generate($listing->media); // sem prévia com marca d'água não vai ao catálogo
        } catch (\Throwable $e) {
            report($e);
            throw ValidationException::withMessages(['media' => ['Não foi possível gerar a prévia com marca d\'água.']]);
        }

        $listing->update([
            'status' => ListingStatus::Published,
            'reviewed_by' => $moderator->id, 'reviewed_at' => now(),
            'published_at' => $listing->published_at ?? now(), 'rejection_reason' => null,
        ]);
        Audit::record('listing.published', ['listing' => $listing->ulid, 'by' => $moderator->ulid]);

        return $listing->fresh();
    }

    public function reject(Listing $listing, User $moderator, string $reason): Listing
    {
        $this->assertFrom($listing, [ListingStatus::PendingReview], 'recusada');
        $listing->update(['status' => ListingStatus::Rejected, 'rejection_reason' => $reason, 'reviewed_by' => $moderator->id, 'reviewed_at' => now()]);
        Audit::record('listing.rejected', ['listing' => $listing->ulid, 'by' => $moderator->ulid]);

        return $listing->fresh();
    }

    public function suspend(Listing $listing, User $moderator, string $reason): Listing
    {
        $this->assertFrom($listing, [ListingStatus::Published, ListingStatus::PendingReview], 'suspensa');
        $listing->update(['status' => ListingStatus::Suspended, 'rejection_reason' => $reason, 'reviewed_by' => $moderator->id, 'reviewed_at' => now()]);
        Audit::record('listing.suspended', ['listing' => $listing->ulid, 'by' => $moderator->ulid]);

        return $listing->fresh();
    }

    /** Vendedor tira do ar. Quem já comprou continua com o direito de baixar. */
    public function unpublish(Listing $listing): Listing
    {
        $this->assertFrom($listing, [ListingStatus::Published, ListingStatus::PendingReview], 'despublicada');
        $listing->update(['status' => ListingStatus::Archived]);
        Audit::record('listing.unpublished', ['listing' => $listing->ulid]);

        return $listing->fresh();
    }

    /** @param list<ListingStatus> $allowed */
    private function assertFrom(Listing $listing, array $allowed, string $action): void
    {
        if (! in_array($listing->status, $allowed, true)) {
            throw ValidationException::withMessages(['status' => ["Uma publicação '{$listing->status->value}' não pode ser {$action}."]]);
        }
    }
}
