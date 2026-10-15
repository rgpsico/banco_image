<?php

namespace App\Modules\Tenants\Support;

use App\Models\Tenant;

/**
 * Tenant da requisição atual. É preenchido SOMENTE pelo middleware ResolveTenant,
 * depois de verificar que o usuário autenticado é membro do tenant pedido.
 */
class CurrentTenant
{
    private ?Tenant $tenant = null;
    private bool $bypass = false;

    public function set(Tenant $tenant): void
    {
        $this->tenant = $tenant;
    }

    public function clear(): void
    {
        $this->tenant = null;
    }

    public function get(): ?Tenant
    {
        return $this->tenant;
    }

    public function id(): ?int
    {
        return $this->tenant?->id;
    }

    public function isBypassed(): bool
    {
        return $this->bypass;
    }

    /** Executa um bloco sem o filtro de tenant (jobs, marketplace, relatórios de plataforma). Use com critério. */
    public function withoutTenancy(callable $callback): mixed
    {
        $previous = $this->bypass;
        $this->bypass = true;

        try {
            return $callback();
        } finally {
            $this->bypass = $previous;
        }
    }

    /** Executa um bloco com um tenant específico (ex.: jobs em fila). */
    public function runAs(Tenant $tenant, callable $callback): mixed
    {
        $previous = $this->tenant;
        $this->tenant = $tenant;

        try {
            return $callback();
        } finally {
            $this->tenant = $previous;
        }
    }
}
