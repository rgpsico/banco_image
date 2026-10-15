# API v1

Base: `/api/v1`. Autenticação: `Authorization: Bearer <token>` (Sanctum). Envie sempre `Accept: application/json`.
Erros: `{ "message": "...", "errors": { "campo": ["..."] } }` (o campo `errors` só existe em validação, status 422).

## Públicos (limite de taxa por IP)

| Método | Rota | Descrição |
|---|---|---|
| GET | `/health` | Verificação de saúde |
| POST | `/auth/register` | `name`, `email`, `password`, `password_confirmation` → `{ user, token }` |
| POST | `/auth/login` | `email`, `password` → `{ user, token }` (5 falhas/min por e-mail+IP) |
| POST | `/auth/forgot-password` | `email` (resposta idêntica exista o e-mail ou não) |
| POST | `/auth/reset-password` | `token`, `email`, `password`, `password_confirmation` (revoga todos os tokens) |

## Autenticados

| Método | Rota | Quem pode |
|---|---|---|
| POST | `/auth/logout` | Qualquer usuário (revoga o token atual) |
| GET | `/auth/me` | Qualquer usuário |
| GET | `/tenants` | Lista só os projetos do usuário |
| POST | `/tenants` | Qualquer usuário (vira `owner`) |
| GET | `/tenants/{tenant}` | Membros |
| PATCH | `/tenants/{tenant}` | `admin`+ |
| DELETE | `/tenants/{tenant}` | `owner` |
| GET | `/tenants/{tenant}/members` | Membros |
| POST | `/tenants/{tenant}/members` | `admin`+ (`email`, `role`; a pessoa precisa ter conta) |
| PATCH | `/tenants/{tenant}/members/{user}` | `admin`+ (`role`) |
| DELETE | `/tenants/{tenant}/members/{user}` | `admin`+ para remover outro; qualquer membro pode sair |

`{tenant}` e `{user}` são ULIDs. Regras: admin não atribui nem altera `owner`; o último `owner` não pode ser rebaixado/removido.

## Biblioteca de mídia (Fase 2)

Rotas sob `/tenants/{tenant}` com o middleware `tenant` (projeto fixado e isolado antes do controller).

| Método | Rota | Quem pode |
|---|---|---|
| GET | `/media` | `viewer`+ (`?search=`, `?type=image\|video`, `?collection=<ulid>`, `?page=`, `?per_page=`) |
| GET | `/media/{media}` | `viewer`+ |
| PATCH | `/media/{media}` | `editor`+ (`display_name`) |
| DELETE | `/media/{media}` | `editor`+ (remove arquivos, libera cota; bloqueado se publicada no marketplace) |
| POST | `/media/{media}/download-url` | `viewer`+ → `{ url, expires_at }` (link temporário de 2 min, `attachment`) |
| POST | `/uploads` | `editor`+ (`original_name`, `mime_type`, `size_bytes`, `chunk_size`) → sessão |
| POST | `/uploads/{session}/chunks/{index}` | quem criou a sessão (multipart `chunk`) |
| POST | `/uploads/{session}/complete` | quem criou a sessão → mídia (valida conteúdo, cota e gera miniaturas) |
| DELETE | `/uploads/{session}` | quem criou a sessão (descarta as partes) |
| GET | `/collections` | `viewer`+ (com `media_count`) |
| POST | `/collections` | `editor`+ (`name`, `parent_id?`) |
| PATCH | `/collections/{collection}` | `editor`+ (`name?`, `parent_id?`; impede ciclos) |
| DELETE | `/collections/{collection}` | `editor`+ (subpastas sobem; mídias continuam na biblioteca) |
| POST | `/collections/{collection}/media` | `editor`+ (`media: [ulid...]`, tudo ou nada) |
| DELETE | `/collections/{collection}/media/{media}` | `editor`+ |

**Upload em partes:** crie a sessão, envie cada parte (`chunk`, ≤ 8 MB; o front usa 5 MB) e chame `complete`.
A sessão expira em 6 h; `mediahub:prune-uploads` (agendado de hora em hora) limpa as abandonadas.

**Entrega de arquivos:** `GET /files/media/{media}/{kind}` com `kind = thumb | preview | original | download`.
Exige URL **assinada** (parâmetros `expires` e `signature`), emitida pela API nas respostas de mídia (relativas, válidas por 60 min)
e por `download-url` (2 min). Sem assinatura, adulterada ou expirada: `403`.

Respostas de mídia **nunca** trazem caminho, disco ou ids numéricos: só `thumbnail_url`, `preview_url` e (vídeo) `stream_url` assinadas.


## Marketplace (Fase 3)

### Catálogo público (sem login)
| Método | Rota | Descrição |
|---|---|---|
| GET | `/market/listings` | `?q=`, `?type=`, `?category=`, `?tag=`, `?seller=<slug>`, `?min_price=`/`?max_price=` (centavos), `?sort=newest\|popular\|price_asc\|price_desc`, `?per_page=` (≤48) |
| GET | `/market/listings/{id}` | Detalhe (título, licenças e preços, vendedor, prévia). Nunca expõe ids internos, caminhos ou o original |
| GET | `/market/listings/{id}/image/{thumb\|large}` | **Somente** a prévia com marca d'água de publicações publicadas |
| GET | `/market/categories` · `/market/licenses` | Categorias (com contagem) e licenças ativas |
| GET | `/market/sellers/{slug}` | Perfil público do vendedor (vendedores suspensos: 404) |
| POST | `/market/listings/{id}/reports` | Denúncia (logado, uma por pessoa). `reason`: copyright, inappropriate, misleading, other |

### Vendedor (logado)
| Método | Rota | Descrição |
|---|---|---|
| GET | `/seller/status` | Perfil de vendedor do usuário (ou `null`) |
| POST | `/seller/join` | `display_name`, `bio?`, `accept_terms` |
| GET/PATCH | `/seller/profile` | Perfil |
| GET/POST | `/seller/listings` | Lista / cria rascunho (`media_id` + título, descrição, categoria, tags, `prices[{license, price_cents}]`, `has_people`, `model_release_confirmed`, `rights_declared`) |
| GET/PATCH/DELETE | `/seller/listings/{id}` | Detalhe / editar / excluir (só sem vendas e fora do ar) |
| POST | `/seller/listings/{id}/submit` | Envia à moderação (valida direitos, preço, mídia pronta, autorização de imagem) |
| POST | `/seller/listings/{id}/unpublish` | Tira do ar (quem já comprou mantém o download) |
| GET | `/seller/stats` · `/seller/sales` · `/seller/reports/commissions` | Painel, histórico e comissões por mês (`?from=&to=`) |

Publicada: só preços e tags podem mudar. Preços em **centavos inteiros** (mín. R$ 2,00).

### Compras (logado)
| Método | Rota | Descrição |
|---|---|---|
| POST | `/orders` | `items: [{listing, license}]` + cabeçalho `Idempotency-Key`. Preço e comissão são calculados no servidor |
| GET | `/orders` · `/orders/{id}` | Pedidos do próprio usuário |
| POST | `/orders/{id}/pay` | Inicia o pagamento no gateway ativo. Resposta: `payment`, `simulated` e, no Asaas, `payment_url` (página de pagamento: Pix, boleto ou cartão). Com o Asaas é obrigatório o `cpf_cnpj` do comprador (validado com os dígitos verificadores, guardado **criptografado**, pedido só uma vez; sem ele `422`). Falha do provedor → `422` sem expor detalhes |
| POST | `/orders/{id}/simulate-payment` | Confirma o pagamento **simulado** (só em `local`/`testing`) |
| GET | `/purchases` | Itens de pedidos **pagos**, com downloads restantes |
| POST | `/purchases/{item}/download-url` | Link assinado de 2 min do original. `403` sem pagamento, prazo vencido, limite atingido ou reembolso |
| GET | `/files/purchase/{grant}/download` | Entrega do original (URL assinada; revalida pagamento) |

### Webhooks
`POST /webhooks/{gateway}` (só o gateway ativo; os demais respondem `404`). Autenticação validada antes de qualquer efeito (`401` se inválida) e processamento **idempotente**
por `event_id`. Eventos normalizados: `payment.confirmed`, `payment.failed`, `payment.refunded`.
- `simulated`: assinatura HMAC no cabeçalho `X-Signature`.
- `asaas`: o token configurado no webhook do painel Asaas chega no cabeçalho `asaas-access-token` e é comparado com `ASAAS_WEBHOOK_TOKEN`
  (`hash_equals`). Mapeamento: `PAYMENT_CONFIRMED`/`PAYMENT_RECEIVED` → confirmado; `PAYMENT_REFUNDED` → reembolsado;
  `PAYMENT_OVERDUE`/`PAYMENT_DELETED`/`PAYMENT_CREDIT_CARD_CAPTURE_REFUSED` → falhou. Outros eventos são ignorados com `200`.

### Saúde
`GET /health` → `{"status":"ok"}` (ou `503` com `degraded` se o banco não responder). Sem autenticação e sem dados internos.

### Moderação (administrador da plataforma; para os demais, `404`)
`GET /admin/listings?status=` · `GET /admin/listings/{id}` · `POST .../approve` · `POST .../reject` e `.../suspend` (`reason`) ·
`GET /admin/reports` · `POST /admin/reports/{id}/resolve` (`action: dismiss|suspend_listing`) ·
`GET /admin/sellers` · `PATCH /admin/sellers/{slug}` (`status`, `commission_bps` 0–5000 ou `null` para o padrão, `asaas_wallet_id` — carteira Asaas para o split; só letras, números e hífen; nunca aparece em respostas públicas ou do vendedor).

### Pedidos e repasses (administrador)
| Método | Rota | Descrição |
|---|---|---|
| GET | `/admin/orders?status=` | Pedidos com comprador, itens, comissão e gateway |
| POST | `/admin/orders/{id}/refund` | Reembolso: avisa o provedor, revoga os downloads e avisa o comprador por e-mail. `422` se não estiver pago ou se o provedor recusar |
| GET | `/admin/payouts` | O que falta repassar **manualmente**, por vendedor (vendas pagas, sem split e ainda não repassadas) |
| POST | `/admin/payouts/{slug}/mark-paid` | Registra o repasse manual (`note` opcional) |
