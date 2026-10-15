<?php

namespace App\Console\Commands;

use App\Enums\UploadSessionStatus;
use App\Models\UploadSession;
use App\Modules\Tenants\Support\CurrentTenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class PruneUploads extends Command
{
    protected $signature = 'mediahub:prune-uploads';
    protected $description = 'Expira sessões de upload abandonadas e apaga as partes temporárias.';

    public function handle(CurrentTenant $tenancy): int
    {
        $disk = Storage::disk(config('mediahub.uploads_disk'));
        $count = 0;

        $tenancy->withoutTenancy(function () use ($disk, &$count) {
            UploadSession::with('tenant')
                ->whereIn('status', [UploadSessionStatus::Pending->value, UploadSessionStatus::Uploading->value])
                ->where('expires_at', '<', now())
                ->each(function (UploadSession $session) use ($disk, &$count) {
                    $disk->deleteDirectory($session->tenant->ulid . '/' . $session->ulid);
                    $session->update(['status' => UploadSessionStatus::Expired]);
                    $count++;
                });
        });

        $this->info("Sessões expiradas: {$count}");

        return self::SUCCESS;
    }
}
