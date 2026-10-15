# Roadmap

## Fase 1: Fundação ✅
Estrutura do repositório, Laravel 13 + Vue 3, MySQL, autenticação, multi-tenancy, 15 migrations/models/factories/seeders,
papéis e policies, API de projetos e membros, telas de login/cadastro/recuperação/projetos/membros/configurações.

## Fase 2: Biblioteca ✅
Upload em partes (múltiplo, com progresso e cancelamento), validação real de tipo e tamanho, listagem paginada, busca, filtros por tipo,
pastas aninhadas, renomear/excluir, miniaturas e preview em WebP (fila), metadados, download privado por link temporário,
cota por projeto, camada de armazenamento independente de provedor, limpeza agendada de uploads abandonados. 67 testes automatizados.

## Fase 3: Marketplace ✅
Vendedores e perfil público, publicação explícita a partir da biblioteca, licenças e preços em centavos, prévia com marca d'água, catálogo público com busca,
filtros e ordenação, carrinho e checkout, `PaymentGatewayInterface` + gateway simulado (webhook assinado e idempotente), download só após pagamento,
comissão configurável (padrão 20%, por vendedor), moderação, denúncias com suspensão automática, direitos autorais e autorização de imagem, painel do vendedor
(vendas, estatísticas, comissões por mês), administração de vendedores. Miniatura e duração de vídeo com ffmpeg. 144 testes automatizados.

## Fase 4: Produção
Testes ampliados, endurecimento de segurança (cookie HttpOnly), filas, otimização, Cloudflare R2 (S3 compatível), gateway Asaas
(webhooks com validação e idempotência), miniatura de vídeo (ffmpeg), documentação de deploy.

## Pendências conhecidas
- **Pagamento real e repasse (split) pelo Asaas**, com subconta por vendedor e webhook de produção (Fase 4). Hoje a comissão é calculada e guardada, mas o repasse é manual.
- Reembolso iniciado pelo administrador (hoje só por evento do gateway) e extrato de repasses.
- Prévia de vídeo animada (hoje só um quadro com marca d'água) e downloads de vários itens num ZIP.
- Notificações por e-mail (venda, aprovação, recusa).
- Moderação com histórico de decisões e recurso do vendedor.
- Mover mídia entre pastas por arrastar e soltar; seleção em lote.
- Convite por e-mail e verificação de e-mail no cadastro.
- Resolução de projeto por domínio verificado (`tenant_domains`).
- Retomar upload interrompido (hoje reinicia o arquivo).
