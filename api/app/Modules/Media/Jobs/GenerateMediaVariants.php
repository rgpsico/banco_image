<?php

namespace App\Modules\Media\Jobs;

use App\Models\Media;
use App\Modules\Media\Services\MediaProcessor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class GenerateMediaVariants implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;
    public int $timeout = 120;

    public function __construct(public int $mediaId)
    {
    }

    /** Se o job morrer (falta de memória, tempo esgotado...), a mídia não pode ficar "processando" para sempre. */
    public function failed(?\Throwable $e): void
    {
        $media = Media::withoutGlobalScope('tenant')->find($this->mediaId);

        if ($media && $media->status === \App\Enums\MediaStatus::Processing) {
            $media->update(['status' => \App\Enums\MediaStatus::Failed]);
        }
    }

    public function handle(MediaProcessor $processor): void
    {
        // Job roda fora de uma requisição (sem tenant atual): busca explicitamente sem o filtro.
        $media = Media::withoutGlobalScope('tenant')->find($this->mediaId);

        if ($media) {
            $processor->process($media);
        }
    }
}
