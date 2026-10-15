<?php

namespace App\Support;

use App\Modules\Tenants\Support\CurrentTenant;
use Illuminate\Support\Facades\Log;

/** Registro de operações importantes (canal "audit"). Nunca registrar senhas, tokens ou caminhos internos. */
class Audit
{
    public static function record(string $event, array $context = []): void
    {
        $request = request();

        Log::channel('audit')->info($event, array_merge([
            'user' => $request?->user()?->ulid,
            'tenant' => app(CurrentTenant::class)->get()?->ulid,
            'ip' => $request?->ip(),
        ], $context));
    }
}
