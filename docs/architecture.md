# Arquitetura

**Monólito modular Laravel.** Uma única aplicação e um único banco MySQL, com responsabilidades separadas por módulo
em `api/app/Modules/`. Os models Eloquent ficam em `app/Models` (núcleo compartilhado).

| Módulo | Fase | Responsabilidade |
|---|---|---|
| Authentication | 1 | Cadastro, login/logout, recuperação de senha, tokens Sanctum |
| Tenants | 1 | Projetos, membros, papéis, policies, resolução do tenant |
| Media / Collections / Storage | 2 | Upload, biblioteca, coleções, miniaturas, camada de armazenamento |
| Marketplace | 3 | Vendedores, publicações, licenças, catálogo, moderação, denúncias, painel do vendedor |
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

## Armazenamento

Contrato `MediaStorage` (`app/Modules/Storage/Contracts`) com implementação `DiskMediaStorage` sobre o `Storage` do Laravel.
Hoje: disco local **privado** (`storage/app/private/media`), sem rota pública (`serve => false`) e fora de `public/`.
Fase 4: apontar `MEDIA_DISK` para um disco S3/R2: as regras de negócio não mudam (incluindo cópia local temporária para processar imagens).

- **Caminhos gerados pelo servidor:** `tenants/{ulid-do-projeto}/{ano}/{mês}/{ulid-da-mídia}.{ext}`; a extensão vem do conteúdo detectado, nunca do nome enviado.
- **Variantes** (`media_variants`): `thumb` (400 px) e `preview` (1600 px) em WebP, geradas por um job na fila (GD, com correção de rotação EXIF).
- **Metadados:** dimensões, câmera e data da captura. GPS **não** é guardado.
- **Vídeo:** aceito (MP4/WebM/MOV) e reproduzível com Range; ainda sem miniatura (precisa de ffmpeg, previsto para a Fase 4).

### Fluxo de upload
`POST /uploads` (cria sessão, confere tipo/tamanho/cota) → `POST /uploads/{id}/chunks/{n}` (partes) → `POST /uploads/{id}/complete`
(monta o arquivo, valida o conteúdo real com `finfo`/`getimagesize`, confere a cota **com o projeto travado**, grava e enfileira as miniaturas).

## Banco de dados

15 tabelas de domínio: `users`, `tenants`, `tenant_user`, `tenant_domains`, `media`, `media_variants`, `collections`,
`collection_media`, `listings`, `licenses`, `listing_licenses`, `orders`, `order_items`, `payments`, `download_grants`,
`upload_sessions` (+ tabelas padrão do Laravel: tokens, cache, jobs, sessões).


## Marketplace (Fase 3)

**Publicação:** `rascunho → em análise → publicada | recusada | suspensa | arquivada` (`ChangeListingStatus`). Só o dono (editor+ do projeto da mídia)
publica, por ação explícita. Exige declaração de direitos (com data), autorização de imagem quando há pessoas, preço em ao menos uma licença e a mídia pronta.
Administradores da plataforma publicam direto; `MARKETPLACE_AUTO_APPROVE=true` pula a moderação (não use em produção).

**Prévia com marca d'água:** `WatermarkService` parte da variante `preview` (nunca do original) e gera `watermarked` (1200 px) e `wm_thumb` (480 px).
O catálogo só serve essas duas variantes. Vídeo ganha prévia a partir de um quadro extraído com ffmpeg.

**Dinheiro:** tudo em centavos inteiros. `CommissionCalculator` divide o preço em comissão da plataforma e ganho do vendedor com aritmética inteira
(arredonda metade para cima, sempre fecha no centavo). A porcentagem usada fica **congelada em cada item** (`commission_bps`, `platform_fee_cents`,
`seller_earning_cents`), então mudar a comissão depois não altera vendas passadas. O comprador paga o preço de vitrine; a comissão sai da parte do vendedor.

**Pagamento:** `PaymentGatewayInterface` (`createCharge`, `parseWebhook`). Existe o `SimulatedGateway` (recusa-se a funcionar fora de `local`/`testing`).
O `AsaasGateway` implementa a mesma interface (`supportsSplit`, `createCharge`, `refund`, `parseWebhook`). Com carteira cadastrada, a cobrança leva o `split` (a parte do vendedor); sem ela o item fica `payout_status = pending` para repasse manual. Todo evento (webhook real ou confirmação simulada) passa por `PaymentService::handle`:
transação com lock, idempotente, cria os `download_grants` e conta a venda.

**Download:** só existe `download_grant` depois do pagamento confirmado; limite (10), prazo (365 dias), revogação em reembolso. O link é assinado e dura 2 minutos,
e o arquivo é **revalidado** na entrega.
