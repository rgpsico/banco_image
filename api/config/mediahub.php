<?php

return [
    // Discos do Laravel. Trocar 'media' por um disco S3/R2 não exige mudar as regras de negócio.
    'disk' => env('MEDIA_DISK', 'media'),
    'uploads_disk' => env('MEDIA_UPLOADS_DISK', 'uploads'),

    // Upload em partes: tamanho padrão de cada parte e validade da sessão.
    'chunk_size' => 5 * 1024 * 1024,
    'max_chunk_size' => 8 * 1024 * 1024,
    'max_chunks' => 2000,
    'upload_ttl_hours' => 6,

    // Tipos aceitos: validados pelo CONTEÚDO do arquivo (finfo), nunca pela extensão ou pelo Content-Type enviado.
    // SVG fica de fora de propósito (pode carregar script).
    'types' => [
        'image' => [
            'max_bytes' => 25 * 1024 * 1024,
            'mimes' => ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'],
        ],
        'video' => [
            'max_bytes' => 500 * 1024 * 1024,
            'mimes' => ['video/mp4' => 'mp4', 'video/webm' => 'webm', 'video/quicktime' => 'mov'],
        ],
    ],

    // Proteção contra imagens "bomba" (poucos KB, bilhões de pixels).
    'max_pixels' => 50_000_000,

    // Variantes geradas para imagens (lado maior em px).
    'variants' => ['thumb' => 400, 'preview' => 1600],

    // Validade das URLs assinadas.
    'url_ttl_minutes' => 60,
    'download_ttl_seconds' => 120,
];
