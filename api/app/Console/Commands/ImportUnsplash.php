<?php

namespace App\Console\Commands;

use App\Models\Collection;
use App\Models\Tenant;
use App\Modules\Media\Services\PhotoIngestService;
use App\Modules\Tenants\Support\CurrentTenant;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Popula um projeto com fotos livres do Unsplash (API oficial), seguindo as diretrizes deles:
 * busca pela API, avisa o download (download_location) e guarda o crédito do fotógrafo.
 * Retomável: o que já foi importado fica em storage/app/unsplash-imported.json.
 */
class ImportUnsplash extends Command
{
    protected $signature = 'mediahub:import-unsplash {tenant : ULID do projeto que recebe as fotos}
                            {--manifest=resources/bank-manifest.json : JSON [{query,title,collection,count}]}
                            {--only= : importa só as entradas cujo título contém este texto}
                            {--dry-run : só mostra o que faria}';
    protected $description = 'Importa fotos livres (Unsplash) para o banco de imagens, por categoria.';

    public function handle(CurrentTenant $current, PhotoIngestService $ingest): int
    {
        $key = (string) config('services.unsplash.key');
        if ($key === '') {
            $this->error('Defina UNSPLASH_ACCESS_KEY no api/.env (chave gratuita em unsplash.com/developers).');

            return self::FAILURE;
        }

        $tenant = Tenant::where('ulid', $this->argument('tenant'))->first();
        if (! $tenant) {
            $this->error('Projeto não encontrado.');

            return self::FAILURE;
        }
        $current->set($tenant);

        $manifest = json_decode((string) File::get(base_path($this->option('manifest'))), true) ?: [];
        $statePath = storage_path('app/unsplash-imported.json');
        $state = File::exists($statePath) ? (json_decode(File::get($statePath), true) ?: []) : [];
        $api = Http::withHeaders(['Authorization' => 'Client-ID ' . $key, 'Accept-Version' => 'v1'])->timeout(30);
        $total = 0;

        foreach ($manifest as $entry) {
            if ($this->option('only') && ! Str::contains(Str::lower($entry['title']), Str::lower($this->option('only')))) {
                continue;
            }

            $wanted = (int) ($entry['count'] ?? 3);
            $have = collect($state)->where('title', $entry['title'])->count();
            if ($have >= $wanted) {
                $this->line("= {$entry['title']}: já tem {$have}");
                continue;
            }

            if ($this->option('dry-run')) {
                $this->line("→ {$entry['title']}: buscaria \"{$entry['query']}\" ({$wanted})");
                continue;
            }

            $search = $api->get('https://api.unsplash.com/search/photos', [
                'query' => $entry['query'], 'per_page' => 12, 'orientation' => 'landscape', 'content_filter' => 'high',
            ]);
            if ($this->rateLimited($search)) {
                return self::SUCCESS;
            }
            $search->throw();

            $collection = $this->collection($tenant, $entry['collection'] ?? 'Geral');
            $n = $have;

            foreach ($search->json('results') ?? [] as $photo) {
                if ($n >= $wanted) {
                    break;
                }
                if (isset($state[$photo['id']])) {
                    continue;
                }

                try {
                    // Diretriz da API: registrar o download antes de baixar o arquivo.
                    $track = $api->get($photo['links']['download_location']);
                    if ($this->rateLimited($track)) {
                        return self::SUCCESS;
                    }

                    $bin = Http::timeout(60)->get($photo['urls']['regular']);
                    if (! $bin->successful()) {
                        continue;
                    }

                    $tmp = tempnam(sys_get_temp_dir(), 'unsp');
                    File::put($tmp, $bin->body());
                    $name = sprintf('%s %d', $entry['title'], $n + 1);
                    $file = new UploadedFile($tmp, $photo['id'] . '.jpg', 'image/jpeg', null, true);
                    $media = $ingest->ingest($tenant, $file, $name, null, $collection);
                    @unlink($tmp);
                } catch (Throwable $e) {
                    $this->warn("  falhou {$photo['id']}: " . Str::limit($e->getMessage(), 90));
                    continue;
                }

                $state[$photo['id']] = [
                    'title' => $entry['title'], 'media' => $media->ulid, 'author' => $photo['user']['name'] ?? '',
                    'link' => $photo['links']['html'] ?? '',
                ];
                File::put($statePath, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                $n++;
                $total++;
            }

            $this->info("✓ {$entry['title']}: {$n}/{$wanted}");
        }

        $this->info("Importadas agora: {$total}. Créditos em storage/app/unsplash-imported.json");

        return self::SUCCESS;
    }

    private function collection(Tenant $tenant, string $name): Collection
    {
        return Collection::where('name', $name)->first() ?? Collection::create([
            'tenant_id' => $tenant->id, 'name' => $name, 'slug' => Str::slug($name), 'created_by' => $tenant->created_by,
        ]);
    }

    /** A chave de demonstração permite 50 pedidos por hora: ao acabar, para e dá para rodar de novo depois. */
    private function rateLimited($response): bool
    {
        if ($response->status() === 403 && Str::contains((string) $response->body(), 'Rate Limit')) {
            $this->warn('Limite por hora do Unsplash atingido. Rode o mesmo comando de novo daqui a ~1 hora: ele continua de onde parou.');

            return true;
        }

        return false;
    }
}
