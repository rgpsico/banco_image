<?php

namespace App\Modules\Media\Services;

use App\Models\Tenant;
use Illuminate\Validation\ValidationException;

class QuotaService
{
    public function assertAvailable(Tenant $tenant, int $bytes): void
    {
        if ($tenant->availableStorageBytes() < $bytes) {
            throw ValidationException::withMessages([
                'file' => ['Cota de armazenamento do projeto excedida. Libere espaço ou peça mais cota.'],
            ])->status(422);
        }
    }
}
