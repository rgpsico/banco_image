<?php

namespace App\Modules\Storage\Contracts;

use Symfony\Component\HttpFoundation\Response;

/**
 * Contrato de armazenamento de mídia. As regras de negócio só conhecem esta interface:
 * trocar o disco local pelo Cloudflare R2 (S3 compatível) é configuração, não reescrita.
 */
interface MediaStorage
{
    /** Envia um arquivo local para o destino (por streaming, sem carregar tudo em memória). */
    public function putFile(string $localPath, string $destination): void;

    public function putContents(string $destination, string $contents): void;

    /** @param string|list<string> $paths */
    public function delete(string|array $paths): void;

    public function exists(string $path): bool;

    public function size(string $path): int;

    /** Executa $callback com um caminho LOCAL do arquivo (em discos remotos, baixa uma cópia temporária). */
    public function withLocalCopy(string $path, callable $callback): mixed;

    /** Resposta HTTP em streaming (com suporte a Range quando o disco permite). */
    public function response(string $path, string $mime, string $filename, bool $download, ?string $cacheControl = null): Response;
}
