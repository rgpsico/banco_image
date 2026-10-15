# MediaHub

Plataforma para **armazenar, gerenciar e comercializar imagens e vídeos**, com dois produtos na mesma base:

1. **Biblioteca de mídia privada multi-tenant**: cada projeto (SurfGestão, PilatesGestão, Comunidade PPG...) tem seus usuários, coleções e arquivos, totalmente isolados.
2. **Marketplace público** (Fase 3): fotógrafos e criadores enviam mídia, definem preço e licença, e a plataforma fica com uma comissão configurável.

> **Status:** Fases 1 (Fundação) e 2 (Biblioteca) concluídas. Veja [docs/roadmap.md](docs/roadmap.md).

**O que já funciona:** contas e projetos isolados (papéis owner/admin/editor/viewer), **biblioteca de mídia** com upload em partes e barra de progresso
(imagens até 25 MB, vídeos até 500 MB), validação real do tipo pelo conteúdo, miniaturas, busca, filtros, pastas, renomear, excluir,
download seguro por link temporário e cota de armazenamento por projeto.

## Stack

| Camada | Tecnologia |
|---|---|
| API | PHP 8.4, Laravel 13, MySQL 8, Sanctum, Policies, Queues |
| Front-end | Vue 3 (Composition API), Vite, Pinia, Vue Router, Axios, Bootstrap 5 |
| Armazenamento | Disco local do Laravel (fase 1), com camada de abstração para Cloudflare R2/S3 (fase 4) |
| Ambiente | Docker Compose (MySQL, API, fila, Vite, Mailpit) |

## Estrutura

```
mediahub/
├── api/      Laravel (API REST em /api/v1)
├── web/      Vue 3
├── docs/     Documentação
├── docker/   Dockerfile da API e init do MySQL
└── docker-compose.yml
```

## Como rodar localmente

Requisito: **Docker** (Docker Desktop no Windows/Mac). Não precisa instalar PHP, Node nem MySQL.

```bash
git clone https://github.com/rgpsico/banco_image.git mediahub
cd mediahub
docker compose up -d
```

Na primeira vez, a API instala as dependências, gera o `.env` e a chave e roda as migrations (leva alguns minutos).
Acompanhe com `docker compose logs -f api`. Quando estiver pronto:

| Serviço | Endereço |
|---|---|
| Front-end (Vue) | http://localhost:5180 |
| API | http://localhost:8110/api/v1/health |
| E-mails de teste (recuperação de senha) | http://localhost:8025 |

**Usuários de demonstração** (criados só em `APP_ENV=local`):

| E-mail | Senha | Projeto |
|---|---|---|
| alice@mediahub.test | `password` | SurfGestão (owner) |
| bruno@mediahub.test | `password` | PilatesGestão (owner) |

Ou crie a sua conta em **Criar conta** e cadastre um projeto.

## Testes

```bash
docker compose run --rm api php artisan test
```

Os testes usam o banco `mediahub_test` (criado automaticamente no MySQL do Docker).
Cobrem cadastro/autenticação, projetos, papéis, membros, **isolamento entre projetos**, upload em partes, validação de arquivos,
cota, URLs assinadas, download autorizado e pastas (67 testes).

## Documentação

- [docs/architecture.md](docs/architecture.md): módulos, multi-tenancy e decisões
- [docs/api.md](docs/api.md): endpoints da API
- [docs/security.md](docs/security.md): modelo de segurança
- [docs/roadmap.md](docs/roadmap.md): fases e pendências
