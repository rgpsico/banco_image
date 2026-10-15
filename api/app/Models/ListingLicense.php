<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['listing_id', 'license_id', 'price_cents', 'currency'])]
class ListingLicense extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return ['price_cents' => 'integer'];
    }

    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }

    public function license(): BelongsTo
    {
        return $this->belongsTo(License::class);
    }
}
