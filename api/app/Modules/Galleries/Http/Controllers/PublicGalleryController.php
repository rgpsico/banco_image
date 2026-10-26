<?php

namespace App\Modules\Galleries\Http\Controllers;

use App\Enums\MediaStatus;
use App\Http\Controllers\Controller;
use App\Models\Gallery;
use App\Models\Media;
use App\Modules\Galleries\Services\GalleryImages;
use App\Modules\Galleries\Support\PhotoDate;
use App\Modules\Storage\Contracts\MediaStorage;
use App\Support\Audit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Galeria PÚBLICA: quem tem o link (token longo e aleatório) vê as fotos do projeto por data. Sem login, sem compra.
 * Só imagens prontas do projeto da galeria; o original só sai se o dono liberar o download.
 */
class PublicGalleryController extends Controller
{
    private const DAY = 'COALESCE(media.taken_at, media.created_at)';

    /** Dados da galeria e os dias que têm fotos. "Hoje" vem selecionado no front. */
    public function show(string $token): JsonResponse
    {
        $g = $this->gallery($token);
        $g->increment('views_count');

        $days = (clone $this->photos($g))
            ->selectRaw('DATE(CONVERT_TZ(' . self::DAY . ", '+00:00', ?)) as d, count(*) as n", [PhotoDate::offset()])
            ->groupBy('d')->orderByDesc('d')->limit(120)->get()
            ->map(fn ($r) => ['date' => $r->d, 'count' => (int) $r->n])->values();

        return $this->noIndex(response()->json(['data' => [
            'title' => $g->title,
            'description' => $g->description,
            'project' => $g->tenant->name,
            'allow_download' => $g->allow_download,
            'today' => PhotoDate::today(),
            'days' => $days,
        ]]));
    }

    /** Fotos de um dia (padrão: hoje), da mais antiga para a mais nova (na ordem em que foram tiradas). */
    public function index(Request $request, string $token): JsonResponse
    {
        $g = $this->gallery($token);
        $v = $request->validate(['date' => ['nullable', 'date_format:Y-m-d'], 'page' => ['nullable', 'integer', 'min:1', 'max:500']]);
        $date = $v['date'] ?? PhotoDate::today();
        $range = PhotoDate::dayRange($date);

        abort_unless($range, 422, 'Data inválida.');

        $page = $this->photos($g)
            ->whereRaw(self::DAY . ' >= ? and ' . self::DAY . ' < ?', [$range[0]->toDateTimeString(), $range[1]->toDateTimeString()])
            ->orderByRaw(self::DAY . ' asc')->orderBy('media.id')
            ->paginate((int) config('mediahub.galleries.per_page'));

        $base = "/api/v1/galleries/{$token}/photos";

        return $this->noIndex(response()->json([
            'date' => $date,
            'data' => $page->getCollection()->map(fn (Media $m) => [
                'id' => $m->ulid,
                'width' => $m->width, 'height' => $m->height,
                'time' => ($m->taken_at ?? $m->created_at)->copy()->setTimezone(PhotoDate::tz())->format('H:i'),
                'thumb_url' => "{$base}/{$m->ulid}/thumb",
                'view_url' => "{$base}/{$m->ulid}/view",
                'download_url' => $g->allow_download ? "{$base}/{$m->ulid}/download" : null,
            ])->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]));
    }

    /** Miniatura ou foto grande. Em galeria com marca d'água, NUNCA devolve a versão sem marca. */
    public function image(string $token, string $media, string $size, GalleryImages $images, MediaStorage $storage): Response
    {
        $g = $this->gallery($token);
        $model = $this->photos($g)->where('media.ulid', $media)->firstOrFail();
        $variant = $images->variant($model, $g, $size);

        abort_unless($variant, 404);

        return $this->noIndex($storage->response($variant->path, 'image/webp', 'foto-' . $size . '.webp', false, 'private, max-age=3600'));
    }

    /** Original, como anexo. Só com o download liberado pelo dono da galeria. */
    public function download(string $token, string $media, MediaStorage $storage): Response
    {
        $g = $this->gallery($token);
        abort_unless($g->allow_download, 403, 'O download desta galeria não está liberado.');

        $model = $this->photos($g)->where('media.ulid', $media)->firstOrFail();
        Audit::record('gallery.downloaded', ['gallery' => $g->ulid, 'media' => $model->ulid]);

        return $this->noIndex($storage->response($model->path, $model->mime_type, $model->display_name, true, 'private, no-store'));
    }

    private function gallery(string $token): Gallery
    {
        return Gallery::withoutGlobalScope('tenant')->with('tenant')->where('token', $token)->where('is_active', true)->firstOrFail();
    }

    /** Fotos da galeria: do projeto dela, imagens prontas e (se a galeria for de uma pasta) só dessa pasta. */
    private function photos(Gallery $g): Builder
    {
        $q = Media::withoutGlobalScope('tenant')->where('media.tenant_id', $g->tenant_id)->where('media.type', 'image')->where('media.status', MediaStatus::Ready->value);

        if ($g->collection_id) {
            $q->whereIn('media.id', DB::table('collection_media')->where('collection_id', $g->collection_id)->select('media_id'));
        }

        return $q;
    }

    private function noIndex($response)
    {
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
