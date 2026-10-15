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
