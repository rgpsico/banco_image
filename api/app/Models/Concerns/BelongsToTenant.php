<?php

namespace App\Models\Concerns;

use App\Models\Tenant;
use App\Modules\Tenants\Support\CurrentTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Isolamento lógico por tenant_id.
 * FALHA FECHADA: sem tenant definido (e sem bypass explícito) nenhuma linha é retornada.
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope('tenant', function (Builder $query) {
            $current = app(CurrentTenant::class);

            if ($current->isBypassed()) {
                return;
            }

            $table = $query->getModel()->getTable();

            if ($current->id() === null) {
                $query->whereRaw('1 = 0');

                return;
            }

            $query->where($table . '.tenant_id', $current->id());
        });

        static::creating(function ($model) {
            $current = app(CurrentTenant::class)->id();

            // Nunca troca um tenant_id já definido pelo código do servidor; só preenche quando ausente.
            if (empty($model->tenant_id) && $current !== null) {
                $model->tenant_id = $current;
            }
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
