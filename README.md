# MediaHub

Plataforma para **armazenar, gerenciar e comercializar imagens e vídeos**, com dois produtos na mesma base:

1. **Biblioteca de mídia privada multi-tenant**: cada projeto (SurfGestão, PilatesGestão, Comunidade PPG...) tem seus usuários, coleções e arquivos, totalmente isolados.
2. **Marketplace público** (Fase 3): fotógrafos e criadores enviam mídia, definem preço e licença, e a plataforma fica com uma comissão configurável.

> **Status:** Fases 1 (Fundação), 2 (Biblioteca) e 3 (Marketplace) concluídas. Veja [docs/roadmap.md](docs/roadmap.md).

**O que já funciona:** contas e projetos isolados (papéis owner/admin/editor/viewer), **biblioteca de mídia** com upload em partes e barra de progresso
(imagens até 25 MB, vídeos até 500 MB), validação real do tipo pelo conteúdo, miniaturas, busca, filtros, pastas, renomear, excluir,
download seguro por link temporário e cota de armazenamento por projeto.

**Marketplace:** qualquer pessoa pode virar vendedor, publicar uma mídia da própria biblioteca (com declaração de direitos e autorização de imagem),
definir preços por licença (Pessoal, Comercial, Estendida), e a publicação passa por moderação. O catálogo público mostra **somente prévias com marca d'água**;
compradores montam um carrinho, pagam (gateway **simulado** nesta fase, nenhum dinheiro real) e só então baixam o original. A plataforma retém uma comissão
configurável (padrão 20%, ajustável por vendedor). Há painel de vendas e relatório de comissões, denúncias e moderação.

**Moderação:** para aprovar publicações é preciso ter um usuário administrador da plataforma (veja *Administrador* abaixo).

## Stack

| Camada | Tecnologia |
|---|---|
| API | PHP 8.4, Laravel 13, MySQL 8, Sanctum, Policies, Queues |
| Front-end | Vue 3 (Composition API), Vite, Pinia, Vue Router, Axios, Bootstrap 5 |
| Armazenamento | Disco local do Laravel, com camada de abstração e disco `r2` (Cloudflare R2/S3) pronto para uso |
| Pagamentos | Gateway simulado (testes) e Asaas (Pix, boleto, cartão, com split por vendedor) |
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

## Administrador da plataforma (moderação)

Aprovar publicações, tratar denúncias e ajustar comissões exige o papel de administrador. Para conceder a uma conta já cadastrada:

```bash
docker compose exec api php artisan tinker --execute="App\Models\User::where('email','SEU@EMAIL')->update(['is_platform_admin'=>true]);"
```

## Testes

```bash
docker compose run --rm api php artisan test
```

Os testes usam o banco `mediahub_test` (criado automaticamente no MySQL do Docker).
Cobrem cadastro/autenticação, projetos, papéis, membros, **isolamento entre projetos**, upload em partes, validação de arquivos,
cota, URLs assinadas, download autorizado, pastas, marketplace, comissão, pagamento simulado e downloads (144 testes).

## Documentação

- [docs/architecture.md](docs/architecture.md): módulos, multi-tenancy e decisões
- [docs/api.md](docs/api.md): endpoints da API
- [docs/integration.md](docs/integration.md): usar o banco de imagens em outros projetos (chave de API)
- [docs/security.md](docs/security.md): modelo de segurança
- [docs/roadmap.md](docs/roadmap.md): fases e pendências
