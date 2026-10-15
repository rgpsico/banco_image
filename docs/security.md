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

## Decisão a revisitar

O front guarda o token em `localStorage` (simples e adequado ao MVP). Um XSS poderia roubá-lo. Alternativa mais forte:
cookie HttpOnly via modo SPA do Sanctum (mesmo domínio ou subdomínios). Entra na Fase 4.

## Fases seguintes

Upload com validação real de tipo (MIME por conteúdo), limites e cotas; downloads por URL temporária assinada; originais
sem URL pública; proteção contra path traversal (nomes gerados pelo servidor); webhooks com validação e idempotência;
moderação, denúncias e declaração de direitos autorais.
