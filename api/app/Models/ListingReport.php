<?php

namespace App\Models;

use App\Enums\ReportReason;
use App\Enums\ReportStatus;
use App\Models\Concerns\HasUlid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['listing_id', 'reporter_id', 'reason', 'details', 'status', 'handled_by', 'handled_at', 'resolution_note'])]
#[Hidden(['id', 'listing_id', 'reporter_id', 'handled_by'])]
class ListingReport extends Model
{
    use HasFactory, HasUlid;

    protected function casts(): array
    {
        return ['reason' => ReportReason::class, 'status' => ReportStatus::class, 'handled_at' => 'datetime'];
    }

    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }
}
