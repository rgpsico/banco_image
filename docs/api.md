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

## Convenções (próximas fases)

Rotas de recursos do projeto (mídia, coleções, uploads) usarão o cabeçalho `X-Tenant: <ulid>` e o middleware `tenant`.
