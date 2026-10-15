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

## Fase 4: Produção (em andamento)
**Feito:** gateway Asaas (cobrança com split por carteira, webhook autenticado e idempotente, reembolso), repasse manual para quem não tem
carteira, telas de admin (pedidos, reembolso, repasses, carteira do vendedor), checkout com a página de pagamento do Asaas,
e-mails em fila (compra, venda, reembolso), disco `r2` (S3 compatível, bucket privado) e índices de desempenho, `/health`, cabeçalhos
de segurança, imagens Docker de produção (nginx + php-fpm), trava contra `migrate:fresh` fora dos testes, suíte sem acesso à rede.
**Falta:** colocar a stack de produção no ar na VPS (cutover) e usar o Asaas em produção real (hoje validado só com o sandbox);
migrar os arquivos para um bucket R2 real; cookie HttpOnly (Sanctum SPA).

## Pendências conhecidas
- **Asaas em produção real:** o código está pronto e coberto por testes com respostas simuladas; falta validar a ponta a ponta com a conta de produção
  (chave, token de webhook, carteira de cada vendedor) depois do sandbox. Sem carteira, o repasse é manual (aba *Repasses*).
- Extrato de repasses por período e conciliação com o extrato do Asaas.
- Prévia de vídeo animada (hoje só um quadro com marca d'água) e downloads de vários itens num ZIP.
- E-mails de aprovação e recusa de publicação (compra, venda e reembolso já existem).
- Moderação com histórico de decisões e recurso do vendedor.
- Mover mídia entre pastas por arrastar e soltar; seleção em lote.
- Convite por e-mail e verificação de e-mail no cadastro.
- Resolução de projeto por domínio verificado (`tenant_domains`).
- Retomar upload interrompido (hoje reinicia o arquivo).
