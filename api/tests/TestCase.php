<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Trava de segurança: a suíte recria tabelas, então só roda em banco de testes, nunca no real.
        if (! str_ends_with((string) config('database.connections.' . config('database.default') . '.database'), '_test')) {
            throw new \RuntimeException('Os testes só podem rodar em um banco cujo nome termina em "_test".');
        }

        // Nenhum teste fala com a internet (Asaas etc.): chamadas HTTP precisam ser simuladas com Http::fake().
        Http::preventStrayRequests();
    }
}
