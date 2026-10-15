# Segurança

## Já implementado (Fase 1)

- **Isolamento entre projetos:** projeto resolvido só entre os do usuário; global scope que falha fechado; `tenant_id` do cliente ignorado; `404` em vez de `403` para não revelar existência. Coberto por `TenantIsolationTest`.
- **Papéis e policies:** `owner > admin > editor > viewer`; sem escalonamento de privilégio; último owner protegido.
- **Autenticação:** senhas com hash (bcrypt), política de senha (10+ caracteres, maiúsculas, minúsculas, números; verificação de senha vazada em produção), tokens Sanctum com expiração (7 dias), logout revoga o token, reset de senha revoga todos.
- **Sem enumeração de contas:** login e "esqueci a senha" respondem igual para e-mail existente ou não.
- **Limites de taxa:** `throttle:auth` (10/min por IP), limite por e-mail+IP no login (5/min), `throttle:api` (120/min por usuário).
- **Respostas de erro padronizadas** sem nomes de models, SQL ou caminhos; API pura nunca redireciona para tela de login.
- **Sanitização:** nomes passam por `strip_tags`; ids numéricos, caminhos e discos ficam ocultos nos models (`$hidden`).
- **Auditoria:** canal `audit` (`storage/logs/audit-*.log`, 90 dias) com login, falhas, projetos e membros; nunca registra senhas ou tokens.

## Biblioteca de mídia (Fase 2)

- **Tipo pelo conteúdo:** `finfo` + `getimagesize` decidem; extensão e `Content-Type` do cliente são ignorados. O tipo declarado precisa bater com o conteúdo.
  SVG e HTML ficam de fora (podem carregar script). Script PHP com extensão `.jpg` é recusado.
- **Limites:** 25 MB por imagem, 500 MB por vídeo, 50 megapixels (proteção contra imagem "bomba"), 20 MB por requisição de parte.
- **Cota com lock:** a cota é revalidada na finalização com `lockForUpdate` no projeto: dois uploads paralelos não estouram o limite.
- **Sem path traversal:** nomes de arquivo e pastas são ULIDs do servidor; o nome enviado vira só `display_name`, sanitizado (tags, caminhos e caracteres de controle removidos).
- **Originais protegidos:** disco privado, sem rota pública. Entrega por URL **assinada, relativa e temporária** (60 min para miniaturas/preview; 2 min para download).
  A permissão é verificada na emissão (`MediaPolicy`) e a emissão de download é auditada.
- **Cabeçalhos de entrega:** `X-Content-Type-Options: nosniff`, `Content-Security-Policy: default-src 'none'; sandbox`, `attachment` nos downloads.
- **Isolamento:** mídia, pastas e sessões de upload usam o escopo por projeto (falha fechado); mídia ou pasta de outro projeto é `404`; anexar mídia alheia a uma pasta é recusado (tudo ou nada).
  Sessões de upload só servem a quem as criou.
- **Metadados:** GPS não é guardado nem exposto.

## Marketplace (Fase 3)

- **Original nunca é público:** o catálogo serve só `wm_thumb`/`watermarked`; não existe rota para o original nem para a prévia sem marca (testado).
- **Preço e comissão no servidor:** o cliente só envia ids e a licença; valores e comissão são calculados e congelados no item. Preço de vitrine alterado depois não afeta pedidos já criados.
- **Pagamento:** webhook com assinatura HMAC validada antes de qualquer efeito, comparação em tempo constante, `event_id` idempotente, confirmação dentro de transação com lock.
  O gateway simulado não funciona em produção e o endpoint de confirmação simulada devolve 404 fora de `local`/`testing`.
- **Download só após pagamento:** sem `download_grant` (pedido pendente) não há link; limite, prazo e revogação; revalidação na entrega.
- **Idempotência de pedido:** `Idempotency-Key` por comprador evita pedidos duplicados em cliques repetidos.
- **Moderação e direitos autorais:** declaração de direitos com data, autorização de imagem para pessoas reconhecíveis, denúncias (uma por pessoa), suspensão automática
  com 3 denúncias abertas, suspensão manual de publicação e de vendedor. Área de moderação responde `404` a quem não é administrador.
- **Privacidade:** o vendedor vê o comprador só pelo primeiro nome; o comprador não vê comissão nem ganhos.
- **Vendedor não compra o que é seu**; não recompra a mesma licença já paga.

## Pagamentos, produção e dados (Fase 4)

- **Webhook do Asaas:** o token vem no cabeçalho `asaas-access-token` e é comparado em tempo constante; sem token válido nada muda (`401` + auditoria). Eventos repetidos não geram efeito duplo.
- **Segredos:** `ASAAS_KEY`, token do webhook e senhas do banco ficam só em arquivos de ambiente do servidor (`.env`, `.env.production`: fora do Git, permissão 600). O gateway se recusa a iniciar sem eles.
- **Falha do provedor:** o cliente vê uma mensagem genérica; o detalhe vai só para o log do servidor.
- **Administradores:** os e-mails em `PLATFORM_ADMIN_EMAILS` são admins fixos (sem comando no servidor e sem poder ser rebaixados pela tela); os demais são promovidos em *Admin > Usuários*. Ninguém altera o próprio acesso e toda mudança vai para a auditoria. Quem não é admin recebe `404` nas rotas de admin.
- **Aprovação automática:** só o admin liga, vendedor a vendedor. Os demais continuam passando pela fila de análise.
- **CPF/CNPJ do comprador:** pedido só quando o provedor exige (Asaas), validado com dígitos verificadores, guardado criptografado e nunca devolvido pela API.
- **Carteira Asaas do vendedor:** campo oculto em todas as respostas; só o admin define, com validação de formato.
- **Gateway simulado:** recusa-se a rodar em `production` e fora dos ambientes listados em `PAYMENT_SIMULATED_ENVS`.
- **Cabeçalhos:** `nosniff`, `X-Frame-Options: DENY`, `Referrer-Policy`, `Permissions-Policy`, CSP e HSTS no nginx de produção, `Cache-Control: no-store` nas respostas JSON autenticadas.
- **Banco:** `migrate:fresh`, `refresh`, `reset` e `db:wipe` ficam **bloqueados** fora do ambiente de testes (liberação consciente: `ALLOW_DESTRUCTIVE_DB=true`). A suíte de testes recusa rodar em banco cujo nome não termina em `_test` e não acessa a internet.
- **Binlog do MySQL** fica ligado (30 dias): permite recuperar o banco ponto a ponto. Faça backup (`mysqldump`) antes de qualquer operação destrutiva.
- **Armazenamento R2:** bucket **privado**; nada é público, os arquivos só saem pelo MediaHub com link assinado e permissão checada.

## Decisão a revisitar

O front guarda o token em `localStorage` (simples e adequado ao MVP). Um XSS poderia roubá-lo. Alternativa mais forte:
cookie HttpOnly via modo SPA do Sanctum (mesmo domínio ou subdomínios). Ainda **não implementado**: continua pendente.

## Fases seguintes

Upload com validação real de tipo (MIME por conteúdo), limites e cotas; downloads por URL temporária assinada; originais
sem URL pública; proteção contra path traversal (nomes gerados pelo servidor); webhooks com validação e idempotência;
moderação, denúncias e declaração de direitos autorais.
