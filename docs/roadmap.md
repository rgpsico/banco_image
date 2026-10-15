# Roadmap

## Fase 1: Fundação ✅
Estrutura do repositório, Laravel 13 + Vue 3, MySQL, autenticação, multi-tenancy, 15 migrations/models/factories/seeders,
papéis e policies, API de projetos e membros, telas de login/cadastro/recuperação/projetos/membros/configurações.

## Fase 2: Biblioteca ✅
Upload em partes (múltiplo, com progresso e cancelamento), validação real de tipo e tamanho, listagem paginada, busca, filtros por tipo,
pastas aninhadas, renomear/excluir, miniaturas e preview em WebP (fila), metadados, download privado por link temporário,
cota por projeto, camada de armazenamento independente de provedor, limpeza agendada de uploads abandonados. 67 testes automatizados.

## Fase 3: Marketplace (próxima)
Vendedores e perfil público, publicação explícita a partir da biblioteca, licenças e preços (centavos), prévia com marca-d'água,
catálogo e busca, checkout simulado (`PaymentGatewayInterface` + gateway simulado), download após pagamento, comissão configurável,
moderação, denúncias e direitos autorais, painel do vendedor.

## Fase 4: Produção
Testes ampliados, endurecimento de segurança (cookie HttpOnly), filas, otimização, Cloudflare R2 (S3 compatível), gateway Asaas
(webhooks com validação e idempotência), miniatura de vídeo (ffmpeg), documentação de deploy.

## Pendências conhecidas
- Miniatura/poster de vídeo (requer ffmpeg) e duração do vídeo.
- Mover mídia entre pastas por arrastar e soltar; seleção em lote.
- Convite por e-mail e verificação de e-mail no cadastro.
- Resolução de projeto por domínio verificado (`tenant_domains`).
- Retomar upload interrompido (hoje reinicia o arquivo).
