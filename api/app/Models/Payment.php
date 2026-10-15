<?php

namespace App\Models;

use App\Enums\PaymentStatus;
use App\Models\Concerns\HasUlid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'order_id', 'gateway', 'gateway_reference', 'idempotency_key', 'status', 'amount_cents', 'currency', 'payload',
    'webhook_event_id', 'confirmed_at', 'failed_at',
])]
#[Hidden(['id', 'order_id', 'payload', 'gateway_reference', 'idempotency_key', 'webhook_event_id'])]
class Payment extends Model
{
    use HasFactory, HasUlid;

    protected function casts(): array
    {
        return [
            'status' => PaymentStatus::class,
            'amount_cents' => 'integer',
            'payload' => 'array',
            'confirmed_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
