<?php

namespace App\Models;

use App\Models\Concerns\HasUlid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Chave de acesso (somente leitura) de UM projeto, para outros sistemas buscarem as imagens dele.
 * A chave completa só existe no momento da criação; no banco fica apenas o hash.
 */
#[Fillable(['tenant_id', 'created_by', 'name', 'prefix', 'key_hash', 'last_used_at', 'expires_at', 'revoked_at'])]
#[Hidden(['id', 'tenant_id', 'created_by', 'key_hash'])]
class TenantApiKey extends Model
{
    use HasUlid;

    public const PREFIX = 'mhk_';

    public const MAX_ACTIVE_PER_TENANT = 10;

    protected function casts(): array
    {
        return ['last_used_at' => 'datetime', 'expires_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return array{0: TenantApiKey, 1: string} o modelo e a chave em texto puro (mostrar UMA vez) */
    public static function issue(Tenant $tenant, string $name, ?User $by, ?\DateTimeInterface $expiresAt): array
    {
        $plain = self::PREFIX . Str::random(48);

        $key = self::create([
            'tenant_id' => $tenant->id, 'created_by' => $by?->id, 'name' => $name,
            'prefix' => substr($plain, 0, 12), 'key_hash' => self::hash($plain), 'expires_at' => $expiresAt,
        ]);

        return [$key, $plain];
    }

    public static function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }

    public function isUsable(): bool
    {
        return $this->revoked_at === null && ($this->expires_at === null || $this->expires_at->isFuture());
    }
}
