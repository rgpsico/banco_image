# Roadmap

## Fase 1: Fundação ✅
Estrutura do repositório, Laravel 13 + Vue 3, MySQL, autenticação, multi-tenancy, 15 migrations/models/factories/seeders,
papéis e policies, API de projetos e membros, telas de login/cadastro/recuperação/projetos/membros/configurações,
35 testes automatizados.

## Fase 2: Biblioteca
Upload local (múltiplo, progresso, em partes), validação real de tipo e tamanho, listagem paginada, busca, filtros,
coleções/pastas, miniaturas (fila), download privado autorizado, cotas por projeto, `StorageService` independente de provedor.

## Fase 3: Marketplace
Vendedores e perfil público, publicação explícita a partir da biblioteca, licenças e preços (centavos), prévia com marca-d'água,
catálogo e busca, checkout simulado (`PaymentGatewayInterface` + gateway simulado), download após pagamento, comissão configurável,
moderação, denúncias e direitos autorais, painel do vendedor.

## Fase 4: Produção
Testes ampliados, endurecimento de segurança (cookie HttpOnly), filas, otimização, Cloudflare R2 (S3 compatível), gateway Asaas
(webhooks com validação e idempotência), documentação de deploy.

## Pendências conhecidas
- Convite por e-mail (hoje só se adiciona quem já tem conta).
- Verificação de e-mail no cadastro.
- Resolução de projeto por domínio verificado (`tenant_domains`).
