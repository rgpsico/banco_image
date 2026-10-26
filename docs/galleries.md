# Galerias de fotos

Cada projeto (cliente seu, em qualquer sistema) pode ter uma **galeria pública**: um link (e QR code) que os clientes dele abrem para ver as fotos **por data**, começando por **hoje**. Sem login e sem pagamento.

## Como funciona
1. O **dono do projeto** entra em **Galerias**, cria a galeria e copia o link, manda pelo WhatsApp ou imprime o **cartão com QR code**.
2. Quem abre o link vê as fotos do dia (hoje por padrão), troca de data, amplia e passa de uma foto para outra.
3. Padrões: **marca d'água ligada** (com o nome do projeto) e **download desligado**. O dono liga o download galeria por galeria; com ele ligado, quem tem o link baixa o original **sem marca**.
4. As fotos vêm da biblioteca do projeto (todas, ou só de uma pasta) ou de **outro sistema** que envia pela API.

## A data de cada foto
É a data em que a foto foi tirada (lida da própria imagem) ou, se a imagem não tiver, a do envio. O "dia" segue o fuso do negócio (`MEDIAHUB_TIMEZONE`, padrão `America/Sao_Paulo`): uma foto das 22h de segunda não cai em terça. Quem envia por API pode informar `taken_at`.

## Para outros sistemas enviarem fotos
Crie uma chave em **Configurações → Chaves de API** marcando **"Permitir enviar fotos"**. Use sempre **no servidor** do outro sistema.

| Rota (`/api/v1/integration`) | O que faz | Chave |
|---|---|---|
| `POST /gallery` | Garante a galeria do projeto e devolve o link (`{"title": "...", "collection": "id-da-pasta"}` opcionais). Repetir devolve a mesma | envio |
| `POST /photos` | Envia **uma** foto (multipart): `file`, e opcionais `taken_at` (ISO 8601), `collection` (id da pasta), `name`. Responde `gallery_url` | envio |
| `GET /galleries` | Lista as galerias com o link | leitura |

```bash
# 1) a galeria (uma vez) e o link para repassar ao cliente
curl -X POST -H "Authorization: Bearer $MEDIAHUB_KEY" -H "Accept: application/json" \
  -d '{"title":"Passeio de domingo"}' -H 'Content-Type: application/json' https://SEU-DOMINIO/api/v1/integration/gallery

# 2) cada foto
curl -X POST -H "Authorization: Bearer $MEDIAHUB_KEY" -H "Accept: application/json" \
  -F file=@IMG_0123.jpg -F taken_at=2026-10-26T10:15:00-03:00 https://SEU-DOMINIO/api/v1/integration/photos
```

**Laravel (site do cliente):**
```php
$res = Http::withToken(config('services.mediahub.key'))->acceptJson()
    ->attach('file', file_get_contents($path), basename($path))
    ->post(config('services.mediahub.url') . '/api/v1/integration/photos', ['taken_at' => $takenAt->toIso8601String()])
    ->throw()->json('data');
// $res['gallery_url'] é o link para mandar aos clientes
```

Só imagens (JPG, PNG, WebP, GIF), até 25 MB cada. O tipo é conferido pelo conteúdo, a cota do projeto vale e o processamento (miniaturas e marca d'água) acontece em segundo plano: a foto aparece na galeria em instantes.

## Segurança e privacidade
- O link é um código **longo e aleatório** (40 caracteres); a página avisa os buscadores para **não indexar**. "Trocar o link" invalida o antigo e o QR antigo na hora; "Desativar" e "Excluir" também.
- A galeria só mostra fotos do **próprio projeto**; foto de outro projeto nunca aparece, mesmo pelo endereço direto.
- Com marca d'água ligada, o sistema **nunca** serve a versão limpa. O original só sai se o download estiver liberado.
- A chave com envio é mais sensível que a de leitura: guarde no servidor, revogue se vazar. Ela só envia imagens **para o projeto dela**.
- **Fotos de pessoas** (principalmente crianças): use com consentimento e atenda pedidos de remoção. Ainda **não existe** botão "pedir remoção" nem apagamento automático por prazo (próximos passos).

## Ainda não existe (próximas fases)
Cobrança (galeria paga, Pix sem cadastro, comissão), pedido de remoção pelo cliente final, apagar fotos antigas automaticamente, senha e validade do link, texto próprio da marca d'água, favoritas do cliente, envio de vídeo.
