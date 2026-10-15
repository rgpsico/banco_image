<?php

return [
    // Discos do Laravel. Trocar 'media' por um disco S3/R2 não exige mudar as regras de negócio.
    // E-mails que são administradores da plataforma sem precisar de comando no servidor (separados por vírgula).
    // Quem está aqui é admin assim que a conta existe; os demais admins são promovidos pela tela Admin > Usuários.
    'platform_admin_emails' => array_values(array_filter(array_map(fn ($e) => mb_strtolower(trim($e)), explode(',', (string) env('PLATFORM_ADMIN_EMAILS', ''))))),

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

    // ----- Marketplace (Fase 3) -----
    'marketplace' => [
        // Comissão da plataforma em pontos-base (2000 = 20%). Cada vendedor pode ter a sua (seller_profiles.commission_bps).
        'commission_bps' => (int) env('MARKETPLACE_COMMISSION_BPS', 2000),
        'min_price_cents' => 200,          // R$ 2,00
        'max_price_cents' => 10_000_000,   // R$ 100.000,00
        'max_tags' => 10,
        // Quando true, publicações do vendedor entram direto (sem moderação). Em produção, deixe false.
        'auto_approve' => (bool) env('MARKETPLACE_AUTO_APPROVE', false),
        'categories' => [
            'praia' => 'Praia', 'natureza' => 'Natureza', 'pessoas' => 'Pessoas', 'cidade' => 'Cidade',
            'esporte' => 'Esporte', 'gastronomia' => 'Gastronomia', 'viagem' => 'Viagem', 'eventos' => 'Eventos', 'outros' => 'Outros',
        ],
        // N denúncias abertas suspendem a publicação até a análise.
        'report_auto_suspend' => 3,
        // Marca d'água das prévias públicas.
        'watermark_text' => env('MARKETPLACE_WATERMARK', 'MEDIAHUB'),
        'watermark_font' => env('MARKETPLACE_WATERMARK_FONT', '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf'),
        'public_preview_edge' => 1200,
        'public_thumb_edge' => 480,
        // Downloads de quem comprou.
        'download_max' => 10,
        'download_days' => 365,
    ],

    // ----- Pagamentos -----
    'payments' => [
        // simulated (somente local/testes) | asaas (Fase 4)
        'gateway' => env('PAYMENT_GATEWAY', 'simulated'),
        'simulated_secret' => env('PAYMENT_SIMULATED_SECRET', 'dev-only-simulated-secret'),
        // Ambientes em que o gateway SIMULADO pode rodar. "production" nunca deve estar na lista.
        'simulated_environments' => array_values(array_filter(array_map('trim', explode(',', (string) env('PAYMENT_SIMULATED_ENVS', 'local,testing'))))),
        'order_ttl_minutes' => 60,
        'asaas' => [
            'key' => env('ASAAS_KEY'),
            'url' => env('ASAAS_URL', 'https://api.asaas.com/v3'),
            'webhook_token' => env('ASAAS_WEBHOOK_TOKEN'),
        ],
    ],
];
