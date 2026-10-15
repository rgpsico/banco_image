<?php

namespace Tests\Feature;

use Illuminate\Database\Console\Migrations\FreshCommand;
use Illuminate\Database\Console\WipeCommand;
use Tests\TestCase;

/** Só LÊ o estado de bloqueio dos comandos: nunca executa nada destrutivo. */
class DestructiveCommandsGuardTest extends TestCase
{
    private function prohibited(string $class): bool
    {
        return (new \ReflectionClass($class))->getStaticPropertyValue('prohibitedFromRunning');
    }

    protected function tearDown(): void
    {
        unset($_ENV['ALLOW_DESTRUCTIVE_DB'], $_SERVER['ALLOW_DESTRUCTIVE_DB']);
        putenv('ALLOW_DESTRUCTIVE_DB');
        $this->app['env'] = 'testing';
        (new \App\Providers\AppServiceProvider($this->app))->boot(); // devolve o estado de "testing" aos demais testes
        parent::tearDown();
    }

    public function test_destructive_commands_are_blocked_outside_testing_unless_explicitly_allowed(): void
    {
        $provider = new \App\Providers\AppServiceProvider($this->app);

        $this->app['env'] = 'local'; // desenvolvimento / servidor
        $provider->boot();
        $this->assertTrue($this->prohibited(FreshCommand::class), 'migrate:fresh deveria estar bloqueado');
        $this->assertTrue($this->prohibited(WipeCommand::class), 'db:wipe deveria estar bloqueado');

        putenv('ALLOW_DESTRUCTIVE_DB=true'); // liberação consciente
        $_ENV['ALLOW_DESTRUCTIVE_DB'] = $_SERVER['ALLOW_DESTRUCTIVE_DB'] = 'true';
        $provider->boot();
        $this->assertFalse($this->prohibited(FreshCommand::class));

        unset($_ENV['ALLOW_DESTRUCTIVE_DB'], $_SERVER['ALLOW_DESTRUCTIVE_DB']);
        putenv('ALLOW_DESTRUCTIVE_DB');
        $this->app['env'] = 'testing'; // nos testes as migrações precisam rodar (RefreshDatabase)
        $provider->boot();
        $this->assertFalse($this->prohibited(FreshCommand::class));
    }
}
