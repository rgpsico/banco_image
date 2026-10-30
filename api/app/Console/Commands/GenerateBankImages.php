<?php

namespace App\Console\Commands;

use App\Models\Collection;
use App\Models\Media;
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
 * Gera fotos de produto com IA (Gemini) e salva no banco de imagens, já com pasta/subpasta e tags.
 * A chave vem do ambiente (GEMINI_API_KEY) e nunca é gravada. Retomável: pula o que já existe com o mesmo nome.
 */
class GenerateBankImages extends Command
{
    protected $signature = 'mediahub:generate-images {tenant : ULID do projeto que recebe as imagens}
                            {--manifest=resources/bank-generate.json : JSON [{title,subject,collection,tags}]}
                            {--model=gemini-2.5-flash-image}
                            {--only= : gera só os itens cujo título contém este texto}
                            {--dry-run : só lista o que geraria}';
    protected $description = 'Gera imagens de produtos com o Gemini e salva no banco de imagens.';

    private const STYLE = 'Foto profissional de produto para cardápio de barraca de praia: %s. '
        . 'Fundo liso em tom areia claro, luz natural de dia, produto centralizado ocupando cerca de 70%% do quadro, '
        . 'leve brilho de gelado quando for bebida. Quadrada (1:1), alta resolução. '
        . 'Sem texto, sem preço, sem pessoas, sem mãos, sem marca d\'água e sem logotipos.';

    public function handle(CurrentTenant $current, PhotoIngestService $ingest): int
    {
        $key = (string) config('services.gemini.key');
        if ($key === '' && ! $this->option('dry-run')) {
            $this->error('Defina GEMINI_API_KEY no ambiente (chave do Google AI Studio, com cobrança ativada para imagens).');

            return self::FAILURE;
        }

        $tenant = Tenant::where('ulid', $this->argument('tenant'))->first();
        if (! $tenant) {
            $this->error('Projeto não encontrado.');

            return self::FAILURE;
        }
        $current->set($tenant);

        $manifest = json_decode((string) File::get(base_path($this->option('manifest'))), true) ?: [];
        $made = 0;

        foreach ($manifest as $item) {
            if ($this->option('only') && ! Str::contains(Str::lower($item['title']), Str::lower($this->option('only')))) {
                continue;
            }
            if (Media::where('display_name', $item['title'])->exists()) {
                $this->line("= {$item['title']}: já existe");
                continue;
            }
            if ($this->option('dry-run')) {
                $this->line("→ geraria: {$item['title']} ({$item['collection']})");
                continue;
            }

            $res = Http::withHeaders(['x-goog-api-key' => $key])->timeout(120)->post(
                'https://generativelanguage.googleapis.com/v1beta/models/' . $this->option('model') . ':generateContent',
                [
                    'contents' => [['parts' => [['text' => sprintf(self::STYLE, $item['subject'])]]]],
                    'generationConfig' => ['responseModalities' => ['IMAGE'], 'imageConfig' => ['aspectRatio' => '1:1']],
                ]
            );

            if ($res->status() === 429) {
                $this->warn('O Gemini recusou por cota/limite (429). Em contas grátis o limite de imagens é zero: ative a cobrança no projeto do Google ou espere a cota renovar. Rode de novo: continua de onde parou.');

                return self::SUCCESS;
            }
            if (! $res->successful()) {
                $this->warn("  falhou {$item['title']}: HTTP {$res->status()}");
                continue;
            }

            $b64 = collect($res->json('candidates.0.content.parts') ?? [])->pluck('inlineData.data')->filter()->first();
            if (! $b64) {
                $this->warn("  {$item['title']}: o Gemini não devolveu imagem (filtro de segurança?). Ajuste o texto e tente de novo.");
                continue;
            }

            try {
                $tmp = tempnam(sys_get_temp_dir(), 'gem');
                File::put($tmp, base64_decode($b64));
                $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($tmp);
                $ext = $mime === 'image/jpeg' ? 'jpg' : 'png';
                $media = $ingest->ingest($tenant, new UploadedFile($tmp, Str::slug($item['title']) . '.' . $ext, $mime, null, true), $item['title'], null, $this->collection($tenant, $item['collection']));
                $media->update(['tags' => implode(',', Media::normalizeTags($item['tags'] ?? [])) ?: null]);
                @unlink($tmp);
            } catch (Throwable $e) {
                $this->warn("  não salvou {$item['title']}: " . Str::limit($e->getMessage(), 90));
                continue;
            }

            $made++;
            $this->info("✓ {$item['title']}");
        }

        $this->info("Geradas agora: {$made}");

        return self::SUCCESS;
    }

    private function collection(Tenant $tenant, string $path): Collection
    {
        $parent = null;

        foreach (array_filter(array_map('trim', explode('/', $path))) as $name) {
            $parent = Collection::where('name', $name)->where('parent_id', $parent?->id)->first() ?? Collection::create([
                'tenant_id' => $tenant->id, 'parent_id' => $parent?->id, 'name' => $name,
                'slug' => Str::slug($name) . ($parent ? '' : '') . (Collection::where('parent_id', $parent?->id)->where('slug', Str::slug($name))->exists() ? '-2' : ''),
                'created_by' => $tenant->created_by,
            ]);
        }

        return $parent;
    }
}
