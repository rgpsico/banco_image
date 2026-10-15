# Usar o banco de imagens em outros projetos

Cada projeto do MediaHub (SurfGestão, Comunidade PPG...) é um banco de imagens separado. Outro sistema seu lê as imagens **só desse projeto**
com uma **chave de API somente leitura**.

## 1. Criar a chave
No MediaHub: escolha o projeto → **Configurações → Chaves de API** → nome do sistema (ex.: *SurfGestão site*) → **Criar chave**.
A chave (`mhk_...`) aparece **uma única vez**: copie e guarde no arquivo de configuração (`.env`) do outro sistema. Crie uma chave por sistema:
assim dá para **revogar** uma sem afetar as outras. Só administradores do projeto criam e revogam.

## 2. Regras de segurança
- Use a chave **no servidor** do outro sistema, nunca no JavaScript do navegador nem no código versionado.
- Ela só lê (nada de enviar, editar ou apagar) e só enxerga o projeto dela.
- Vai no cabeçalho `Authorization: Bearer mhk_...` (ou `X-Api-Key`). Na URL **não** funciona.
- Revogada, expirada ou com o projeto excluído = `401` imediato.
- Limite de 600 requisições por minuto por chave.

## 3. Rotas (base: `https://SEU-DOMINIO/api/v1/integration`)
| Rota | O que faz |
|---|---|
| `GET /me` | Testa a chave: devolve o projeto e o nome da chave |
| `GET /media` | Lista as imagens/vídeos prontos. Filtros: `search`, `type` (`image`/`video`), `collection` (id da pasta), `per_page` (até 100), `page`, `url_ttl` (segundos, 60 a 86400) |
| `GET /media/{id}` | Uma mídia |
| `GET /collections` | As pastas do projeto |
| `GET /media/{id}/file/{thumb|preview|original}` | O arquivo direto (exige a chave): útil para o servidor do outro sistema repassar ou guardar em cache |

Cada mídia traz `urls.thumbnail` (WebP até 480 px), `urls.preview` (WebP até 1200 px), `urls.original` e `urls.download`.
Esses links são **assinados e temporários** (1 hora por padrão, ajustável com `url_ttl`) e funcionam direto em `<img src>`, sem a chave.
Por isso o outro sistema deve **pedir a lista ao servidor** e renderizar os links; quando expirarem, pede de novo.

## 4. Exemplos
**curl**
```bash
curl -H "Authorization: Bearer $MEDIAHUB_KEY" "https://SEU-DOMINIO/api/v1/integration/media?search=surf&per_page=12"
```

**Laravel (SurfGestão, Comunidade PPG)**: `.env`: `MEDIAHUB_URL=https://SEU-DOMINIO` e `MEDIAHUB_KEY=mhk_...`
```php
$items = Cache::remember('mediahub:surf', 3000, fn () => Http::withToken(config('services.mediahub.key'))
    ->get(config('services.mediahub.url') . '/api/v1/integration/media', ['search' => 'surf', 'per_page' => 24, 'url_ttl' => 3600])
    ->throw()->json('data'));
// no Blade: <img src="{{ $item['urls']['preview'] }}" alt="{{ $item['name'] }}" loading="lazy">
```
Guarde em cache por menos tempo que o `url_ttl` (aqui 3000 s < 3600 s) para nunca exibir link vencido.

**Node/JavaScript (no servidor)**
```js
const r = await fetch(`${process.env.MEDIAHUB_URL}/api/v1/integration/media?per_page=24`, { headers: { Authorization: `Bearer ${process.env.MEDIAHUB_KEY}` } })
const { data } = await r.json()
```

## 5. Quando o armazenamento for o Cloudflare R2
Nada muda para o outro sistema: os links continuam saindo do MediaHub. O que muda é onde os arquivos ficam guardados.
