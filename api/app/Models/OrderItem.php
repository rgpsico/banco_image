<?php

namespace App\Models;

use App\Models\Concerns\HasUlid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'order_id', 'listing_id', 'license_id', 'seller_id', 'title_snapshot', 'unit_price_cents', 'commission_bps',
    'platform_fee_cents', 'seller_earning_cents', 'payout_status', 'payout_paid_at', 'payout_note',
])]
#[Hidden(['id', 'order_id', 'listing_id', 'license_id', 'seller_id'])]
class OrderItem extends Model
{
    use HasFactory, HasUlid;

    protected function casts(): array
    {
        return [
            'unit_price_cents' => 'integer',
            'commission_bps' => 'integer',
            'platform_fee_cents' => 'integer',
            'seller_earning_cents' => 'integer',
            'payout_paid_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }

    public function license(): BelongsTo
    {
        return $this->belongsTo(License::class);
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function downloadGrants(): HasMany
    {
        return $this->hasMany(DownloadGrant::class);
    }
}
