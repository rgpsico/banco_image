<?php

namespace App\Modules\Tenants\Http\Middleware;

use App\Models\TenantApiKey;
use App\Modules\Tenants\Support\CurrentTenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Autentica pela chave de API do PROJETO (cabeçalho "Authorization: Bearer mhk_..." ou "X-Api-Key").
 * A chave nunca é aceita na URL (iria para logs e históricos). O projeto atual vem SEMPRE da chave:
 * nenhum parâmetro enviado pelo cliente escolhe o projeto.
 */
class AuthenticateApiKey
{
    public function __construct(private CurrentTenant $current)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $plain = $request->bearerToken() ?: $request->header('X-Api-Key');

        if (! is_string($plain) || ! str_starts_with($plain, TenantApiKey::PREFIX) || strlen($plain) > 100) {
            return $this->deny();
        }

        $key = TenantApiKey::with('tenant')->where('key_hash', TenantApiKey::hash($plain))->first();

        if (! $key || ! $key->isUsable() || ! $key->tenant) {
            return $this->deny();
        }

        // Uma gravação por minuto no máximo, para a lista mostrar "último uso" sem sobrecarregar o banco.
        if (! $key->last_used_at || $key->last_used_at->lt(now()->subMinute())) {
            $key->forceFill(['last_used_at' => now()])->saveQuietly();
        }

        $this->current->set($key->tenant);
        $request->attributes->set('api_key', $key);

        try {
            return $next($request);
        } finally {
            $this->current->clear();
        }
    }

    private function deny(): Response
    {
        return response()->json(['message' => 'Chave de API inválida, expirada ou revogada.'], 401, ['WWW-Authenticate' => 'Bearer']);
    }
}
