<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'slug', 'display_name', 'bio', 'status', 'commission_bps', 'asaas_wallet_id', 'auto_approve', 'terms_accepted_at'])]
#[Hidden(['id', 'user_id', 'asaas_wallet_id'])]
class SellerProfile extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return ['terms_accepted_at' => 'datetime', 'commission_bps' => 'integer', 'auto_approve' => 'boolean'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /** Comissão desta pessoa: a combinada com ela (se houver) ou a padrão da plataforma. */
    public function effectiveCommissionBps(): int
    {
        return $this->commission_bps ?? (int) config('mediahub.marketplace.commission_bps');
    }
}
