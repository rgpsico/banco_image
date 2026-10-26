<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUlid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/** Galeria pública do projeto: quem tem o link vê as fotos (por data), sem login e sem compra. */
#[Fillable(['tenant_id', 'collection_id', 'created_by', 'title', 'description', 'is_active', 'allow_download', 'watermark'])]
#[Hidden(['id', 'tenant_id', 'collection_id', 'created_by'])]
class Gallery extends Model
{
    use BelongsToTenant, HasUlid;

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'allow_download' => 'boolean', 'watermark' => 'boolean', 'views_count' => 'integer'];
    }

    protected static function booted(): void
    {
        static::creating(function (self $g) {
            $g->token ??= self::newToken();
        });
    }

    public static function newToken(): string
    {
        do {
            $token = Str::random(40);
        } while (self::withoutGlobalScope('tenant')->where('token', $token)->exists());

        return $token;
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function collection(): BelongsTo
    {
        return $this->belongsTo(Collection::class);
    }

    public function publicUrl(): string
    {
        return rtrim((string) config('app.frontend_url'), '/') . '/g/' . $this->token;
    }

    /** Texto da marca d'água: o nome do projeto (cada cliente seu vê a própria marca). */
    public function watermarkText(): string
    {
        return mb_strtoupper(mb_substr((string) ($this->tenant?->name ?? 'GALERIA'), 0, 24));
    }
}
