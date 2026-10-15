<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class HealthController extends Controller
{
    /** Verificação de saúde: confirma que a API responde e que o banco está acessível. Sem detalhes internos. */
    public function __invoke(): JsonResponse
    {
        try {
            DB::select('select 1');
        } catch (\Throwable) {
            return response()->json(['status' => 'degraded'], 503);
        }

        return response()->json(['status' => 'ok']);
    }
}
