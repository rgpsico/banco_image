<?php

namespace App\Modules\Media\Services;

use App\Enums\MediaType;
use Illuminate\Validation\ValidationException;

/** Validação REAL do arquivo pelo conteúdo (finfo/getimagesize), ignorando extensão e Content-Type do cliente. */
class FileInspector
{
    /** @return array{type: MediaType, mime: string, extension: string, width: ?int, height: ?int} */
    public function inspect(string $absolutePath, string $declaredMime): array
    {
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($absolutePath) ?: 'application/octet-stream';

        [$type, $extension] = $this->resolve($mime);

        // O tipo declarado (imagem/vídeo) precisa bater com o conteúdo real.
        $declaredCategory = strtok($declaredMime, '/');
        if ($declaredCategory !== $type->value) {
            throw ValidationException::withMessages(['file' => ['O conteúdo do arquivo não corresponde ao tipo informado.']]);
        }

        $width = $height = null;

        if ($type === MediaType::Image) {
            $size = @getimagesize($absolutePath);

            if (! $size || empty($size[0]) || empty($size[1])) {
                throw ValidationException::withMessages(['file' => ['Imagem inválida ou corrompida.']]);
            }

            [$width, $height] = [(int) $size[0], (int) $size[1]];

            if ($width * $height > (int) config('mediahub.max_pixels')) {
                throw ValidationException::withMessages(['file' => ['Imagem com resolução acima do limite permitido.']]);
            }
        }

        return compact('type', 'mime', 'extension', 'width', 'height');
    }

    /** Tipo e extensão a partir do MIME detectado; rejeita o que não está na lista. */
    public function resolve(string $mime): array
    {
        foreach (config('mediahub.types') as $category => $rules) {
            if (isset($rules['mimes'][$mime])) {
                return [MediaType::from($category), $rules['mimes'][$mime]];
            }
        }

        throw ValidationException::withMessages(['file' => ['Tipo de arquivo não permitido. Use JPG, PNG, WebP, GIF, MP4, WebM ou MOV.']]);
    }

    public function maxBytes(MediaType $type): int
    {
        return (int) config("mediahub.types.{$type->value}.max_bytes");
    }

    /** Nome de exibição seguro: sem caminhos, tags ou caracteres de controle. */
    public function displayName(string $original): string
    {
        // Ordem importa: primeiro remove tags (que contêm "/"), depois pega só o último trecho do caminho.
        // (basename() do PHP depende de locale e quebra nomes com acento.)
        $name = strip_tags($original);
        $name = \Illuminate\Support\Str::afterLast(str_replace('\\', '/', $name), '/');
        $name = preg_replace('/[\x00-\x1f\x7f]+/u', '', $name) ?? '';
        $name = trim(mb_substr($name, 0, 150));

        return $name !== '' ? $name : 'arquivo';
    }
}
