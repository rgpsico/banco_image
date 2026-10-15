<?php

namespace App\Modules\Marketplace\Actions;

use App\Enums\ListingStatus;
use App\Enums\ReportStatus;
use App\Models\Listing;
use App\Models\ListingReport;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;

class ReportListing
{
    public function handle(User $reporter, Listing $listing, string $reason, ?string $details): ListingReport
    {
        if ($listing->seller_id === $reporter->id) {
            throw ValidationException::withMessages(['listing' => ['Você não pode denunciar a sua própria publicação.']]);
        }

        try {
            $report = ListingReport::create([
                'listing_id' => $listing->id, 'reporter_id' => $reporter->id, 'reason' => $reason, 'details' => $details, 'status' => ReportStatus::Open,
            ]);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['listing' => ['Você já denunciou esta publicação. Nossa equipe vai analisar.']]);
        }

        Audit::record('listing.reported', ['listing' => $listing->ulid, 'reason' => $reason]);

        // Denúncias demais derrubam a publicação até a moderação decidir (proteção de direitos autorais).
        $open = $listing->reports()->where('status', ReportStatus::Open->value)->count();
        if ($open >= (int) config('mediahub.marketplace.report_auto_suspend') && $listing->status === ListingStatus::Published) {
            $listing->update(['status' => ListingStatus::Suspended, 'rejection_reason' => 'Suspensa automaticamente por denúncias, em análise.']);
            Audit::record('listing.auto_suspended', ['listing' => $listing->ulid, 'open_reports' => $open]);
        }

        return $report;
    }
}
