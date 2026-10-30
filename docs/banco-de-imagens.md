# Banco de imagens compartilhado (catálogo para todos os sistemas)

Um projeto do MediaHub, **Banco de Imagens PPG**, guarda fotos de produtos (hambúrguer, caipirinha, fone, tênis...)
organizadas em pastas (Comidas, Bebidas, Sobremesas, Eletrônicos, Moda). Qualquer sistema escolhe uma imagem dali ao cadastrar um produto.

## Como cada sistema usa
1. Crie **uma chave de API somente leitura por sistema** no projeto (Configurações → Chaves de API). Ex.: `PPGFood`, `Barraca 100`, `Lojas PPG`.
2. No `.env` do sistema: `MEDIAHUB_URL=https://mediahub.comunidadeppg.com.br` e `MEDIAHUB_KEY=mhk_...` (só no servidor).
3. No cadastro de produto o sistema mostra **"Escolher do banco de imagens"**: busca por nome, filtro por pasta, clique na imagem.
4. O servidor do sistema **copia** a imagem (WebP 1200 px) para o próprio storage. Assim o cardápio não quebra se a imagem for
   apagada do banco ou se o link assinado (1 h) expirar.

Sem `MEDIAHUB_URL`/`MEDIAHUB_KEY` o botão simplesmente não aparece e o upload de arquivo continua igual.

### PPGFood (pronto)
`ppgfood-back`: `App\Services\ImageBank` (lista/busca/copia), `Admin\ImageBankController` (`GET /admin/image-bank`, JSON para o seletor),
seletor em `resources/views/admin/pages/products/_partials/form.blade.php`, e `ProductController` aceita `bank_media_id`.

## Popular o banco com fotos livres (Unsplash)
O Unsplash bloqueia robôs no site; o caminho permitido é a **API oficial** (chave gratuita, modo demonstração: 50 pedidos/hora).
1. Crie um app em https://unsplash.com/developers e copie a **Access Key**.
2. `api/.env`: `UNSPLASH_ACCESS_KEY=...`
3. Rode (o comando é retomável; se bater o limite por hora, rode de novo depois):
```bash
docker compose exec -T api php artisan mediahub:import-unsplash <ULID_DO_PROJETO>
```
O manifesto das categorias está em `api/resources/bank-manifest.json` (`query` em inglês para buscar, `title` em português com
sinônimos para a busca do seletor, `collection`, `count`). `--only=Pizza` importa só uma categoria; `--dry-run` só mostra.
O comando avisa o download à API (exigência do Unsplash) e guarda o crédito de cada foto em `storage/app/unsplash-imported.json`.
As fotos do Unsplash têm licença que permite uso comercial sem pedir permissão (atribuição é opcional, mas apreciada).
