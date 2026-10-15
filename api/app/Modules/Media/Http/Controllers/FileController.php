<?php

namespace App\Modules\Media\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Media;
use App\Modules\Storage\Contracts\MediaStorage;
use App\Support\Audit;
use Symfony\Component\HttpFoundation\Response;

/**
 * Entrega de arquivos por URL ASSINADA (middleware signed:relative). Sem autenticação por cabeçalho porque
 * <img>/<video> não enviam Authorization; a segurança vem da permissão checada na emissão + assinatura + validade.
 */
class FileController extends Controller
{
    public function show(string $media, string $kind, MediaStorage $storage): Response
    {
        $model = Media::withoutGlobalScope('tenant')->where('ulid', $media)->firstOrFail();

        if ($kind === 'thumb' || $kind === 'preview') {
            $variant = $model->variants()->where('kind', $kind)->firstOrFail();

            return $storage->response($variant->path, 'image/webp', $this->filename($model, 'webp', $kind), false);
        }

        if ($kind === 'download') {
            Audit::record('media.downloaded', ['media' => $model->ulid]);
        }

        return $storage->response($model->path, $model->mime_type, $this->filename($model, $model->extension), $kind === 'download');
    }

    /** Download de uma compra: link assinado e curto emitido por DownloadService (que já checou pagamento e limites). */
    public function purchase(string $grant, MediaStorage $storage): Response
    {
        $model = \App\Models\DownloadGrant::where('ulid', $grant)->with('orderItem.order', 'orderItem.listing.media', 'orderItem.license')->firstOrFail();
        $item = $model->orderItem;

        // Revalida no momento da entrega (ex.: reembolso entre a emissão e o clique).
        abort_unless($model->revoked_at === null && $item->order->status === \App\Enums\OrderStatus::Paid, 403);

        $media = $item->listing->media;
        $name = \Illuminate\Support\Str::slug($item->title_snapshot) . '-' . $item->license->code . '.' . $media->extension;

        return $storage->response($media->path, $media->mime_type, $name, true);
    }

    private function filename(Media $media, string $extension, ?string $suffix = null): string
    {
        $base = pathinfo($media->display_name, PATHINFO_FILENAME) ?: 'arquivo';
        $base = $suffix ? $base . '-' . $suffix : $base;

        return $base . '.' . $extension;
    }
}
