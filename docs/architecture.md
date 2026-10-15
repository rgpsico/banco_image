# Arquitetura

**Monólito modular Laravel.** Uma única aplicação e um único banco MySQL, com responsabilidades separadas por módulo
em `api/app/Modules/`. Os models Eloquent ficam em `app/Models` (núcleo compartilhado).

| Módulo | Fase | Responsabilidade |
|---|---|---|
| Authentication | 1 | Cadastro, login/logout, recuperação de senha, tokens Sanctum |
| Tenants | 1 | Projetos, membros, papéis, policies, resolução do tenant |
| Media / Collections / Storage | 2 | Upload, biblioteca, coleções, miniaturas, camada de armazenamento |
| Marketplace | 3 | Vendedores, publicações, licenças, catálogo, moderação |
| Orders / Payments / Downloads | 3 | Carrinho, gateway (simulado), liberação de download |

Controllers enxutos; regra de negócio em **Actions** (`app/Modules/*/Actions`) e **Services** (`.../Services`);
autorização em **Policies**; validação em **Form Requests**; saída em **API Resources**.

## Multi-tenancy

Isolamento **lógico** por `tenant_id` no mesmo banco.

- Um usuário pode pertencer a vários projetos (`tenant_user`, com o papel `owner | admin | editor | viewer`).
- O projeto da requisição é definido por `{tenant}` na rota ou pelo cabeçalho `X-Tenant`, **sempre resolvido entre os projetos do usuário autenticado**. Se ele não for membro, a resposta é `404`.
- O `tenant_id` enviado pelo front-end nunca é usado.
- Models com dados do projeto (`Media`, `Collection`, `UploadSession`) usam o trait `BelongsToTenant`:
  um *global scope* **que falha fechado** (sem tenant definido, nenhuma linha é retornada) e preenchimento automático do `tenant_id` na criação.
- `CurrentTenant::withoutTenancy()` e `runAs()` existem para jobs e relatórios de plataforma e devem ser usados com critério.
- Resolução por domínio verificado: a tabela `tenant_domains` já existe; a resolução entra em fase futura.

## Identificadores e dinheiro

- Cada registro tem `id` numérico **interno** e `ulid` **público** (usado nas rotas e nas respostas). O id numérico nunca sai pela API.
- Valores monetários são **inteiros em centavos** (`*_cents`). Nunca `float`.

## Armazenamento (planejado para a Fase 2)

Um `StorageService` independente de provedor sobre o `Storage` do Laravel. Fase 1/2: disco local **privado**
(`storage/app/private`), sem URL pública para os originais. Fase 4: trocar o disco por Cloudflare R2 (S3 compatível)
sem mudar as regras de negócio.

## Banco de dados

15 tabelas de domínio: `users`, `tenants`, `tenant_user`, `tenant_domains`, `media`, `media_variants`, `collections`,
`collection_media`, `listings`, `licenses`, `listing_licenses`, `orders`, `order_items`, `payments`, `download_grants`,
`upload_sessions` (+ tabelas padrão do Laravel: tokens, cache, jobs, sessões).
