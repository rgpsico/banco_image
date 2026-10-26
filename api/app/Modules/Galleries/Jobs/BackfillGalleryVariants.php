<?php

namespace App\Modules\Galleries\Jobs;

use App\Enums\MediaStatus;
use App\Enums\MediaType;
use App\Models\Media;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Ao criar/ligar a galeria, prepara em segundo plano as fotos que o projeto já tinha. */
class BackfillGalleryVariants implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $tenantId)
    {
    }

    public function handle(): void
    {
        Media::withoutGlobalScope('tenant')->where('tenant_id', $this->tenantId)->where('type', MediaType::Image->value)->where('status', MediaStatus::Ready->value)
            ->whereDoesntHave('variants', fn ($q) => $q->where('kind', 'g_view'))
            ->select('id')->chunkById(200, function ($rows) {
                foreach ($rows as $m) {
                    GenerateGalleryVariants::dispatch($m->id);
                }
            });
    }
}
