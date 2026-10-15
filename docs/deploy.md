# Deploy (VPS)

## Duas stacks
- **Desenvolvimento** (`docker-compose.yml`): `artisan serve` + servidor do Vite, com recarga automática. É o que atende hoje o domínio de testes.
- **Produção** (`docker-compose.prod.yml`): imagem `api` (php-fpm, opcache, `composer --no-dev`, caches de config/rotas/eventos/views) e imagem `web` (nginx servindo o build do Vue e repassando `/api/` ao php-fpm). Healthchecks em tudo.

## Subir a produção
1. `cp api/.env.production.example api/.env.production`, ajustar e **`chmod 600`**. Defina `APP_ENV=production` quando for usar pagamento real e remova `staging` de `PAYMENT_SIMULATED_ENVS`.
2. **Backup antes de tudo:** `mysqldump` do banco atual.
3. `docker compose --env-file api/.env.production -f docker-compose.prod.yml build`
4. `docker compose --env-file api/.env.production -f docker-compose.prod.yml up -d` (as migrações rodam na subida com `RUN_MIGRATIONS=1`).
5. Verificar `GET /api/v1/health` e um fluxo de compra completo.

O Nginx Proxy Manager aponta para o serviço `web` (porta 5173 dentro da rede `proxy_net`; arquivo `docker-compose.override.yml` só do servidor). Ative **Force SSL** no domínio.

## Administradores
Defina `PLATFORM_ADMIN_EMAILS=seu@email.com` no `.env` (vários separados por vírgula): essas contas são administradoras assim que existirem. Depois disso tudo é feito pela interface em *Admin > Usuários*; não há comando a rodar.

## Asaas
`.env.production`: `PAYMENT_GATEWAY=asaas`, `ASAAS_URL` (sandbox: `https://api-sandbox.asaas.com/v3`; produção: `https://api.asaas.com/v3`), `ASAAS_KEY` (começa com `$aact_`; entre aspas simples), `ASAAS_WEBHOOK_TOKEN`.
No painel do Asaas (sandbox e produção são contas separadas), webhook: URL `https://<dominio>/api/v1/webhooks/asaas`, API v3, fila de sincronização ligada, token igual ao `ASAAS_WEBHOOK_TOKEN`, eventos `PAYMENT_CONFIRMED`, `PAYMENT_RECEIVED`, `PAYMENT_REFUNDED`, `PAYMENT_OVERDUE`, `PAYMENT_DELETED`, `PAYMENT_CREDIT_CARD_CAPTURE_REFUSED`.
Split automático: cadastre o `walletId` do vendedor na aba *Vendedores* do admin; sem ele, o repasse é manual (aba *Repasses*).

## Cloudflare R2 (opcional)
Crie um bucket **privado** e um token de API S3. Configure `R2_ACCESS_KEY_ID`, `R2_SECRET_ACCESS_KEY`, `R2_BUCKET`, `R2_ENDPOINT` e `MEDIA_DISK=r2` (`MEDIA_UPLOADS_DISK` continua local). Os arquivos já gravados no disco local precisam ser copiados para o bucket antes da troca.

## Operação
- Fila e agendador rodam como serviços próprios (`queue`, `scheduler`).
- Logs: `docker compose logs -f api`.
- Nunca rode `migrate:fresh`/`db:wipe` no servidor: estão bloqueados de propósito (ver `docs/security.md`).
