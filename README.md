# Microsserviço fiscal — NFC-e (SEFAZ-PA) + retaguarda de compras

## Instalação

```bash
composer install
cp .env.example .env
```

Preencha o `.env` com:
- Dados do CNPJ, IE e razão social do mercado
- CSC gerado no portal da SEFAZ-PA (necessário só pra NFC-e)
- Caminho e senha do certificado A1 (`.pfx`)
- URL e `service_role key` do seu projeto Supabase
- Um token aleatório longo para `MICROSERVICO_TOKEN`

**Nunca** deixe o `.env` real ou o certificado `.pfx` num repositório público.

## Estrutura

```
config/            (reservado para configs adicionais)
src/
  Config.php              -> monta o objeto Tools da NFePHP (autorizador PA)
  SupabaseClient.php       -> fala com o Supabase via REST usando service_role
  EmissorNFCe.php           -> monta, assina e transmite a NFC-e
  ImportadorNFeCompra.php  -> lê XML de compra, cadastra fornecedor/itens/contas a pagar
public/
  emitir-nfce.php          -> POST { "pedido_id": "..." }
  importar-compra.php      -> POST com o XML (arquivo ou corpo cru)
```

## Rodando localmente

```bash
php -S localhost:8080 -t public
```

## Como o backend chama isso

No Supabase, quando `pedidos.status` muda para `separando`, uma Edge
Function (ou um trigger com `pg_net`) faz:

```
POST https://seu-microsservico/emitir-nfce.php
Headers: X-Microservico-Token: <MICROSERVICO_TOKEN>
Body: { "pedido_id": "<uuid>" }
```

Pra importar uma compra, o operador sobe o XML pela tela de retaguarda,
que envia pra:

```
POST https://seu-microsservico/importar-compra.php
Headers: X-Microservico-Token: <MICROSERVICO_TOKEN>
Body: multipart/form-data, campo "xml" = arquivo .xml
```

## ⚠️ Antes de ir pra produção

Este é um esqueleto funcional, mas alguns pontos em `EmissorNFCe.php`
estão marcados com `TODO` e precisam ser completados com dados reais
do seu mercado antes de emitir qualquer nota valendo:

1. **Endereço do emitente** (`tagenderEmit`) — ainda não incluído
2. ~~Regime tributário real (`CRT`) e o grupo de ICMS correto por produto~~ — resolvido: agora vem da tabela `produtos` (campos `cfop`, `csosn`, `origem_mercadoria`), editável em `/admin/produtos`. Assume Simples Nacional (CSOSN) — se o mercado não for do Simples, precisa trocar pra `tagICMS` com CST, com ajuda do contador
3. ~~NCM real de cada produto~~ — resolvido: também vem de `produtos.ncm`
4. **Numeração sequencial da nota** (`nNF`) — precisa vir de um
   contador controlado no seu banco, nunca fixo
5. **Código do município** (`cMunFG`) — hoje está com um valor de
   exemplo, ajuste pro município correto do mercado
6. **Testar tudo em homologação** (`NFE_AMBIENTE=2`) até ter pelo
   menos algumas dezenas de notas autorizadas sem erro antes de
   trocar para produção (`NFE_AMBIENTE=1`)

Recomendo fortemente rodar isso junto com um contador ou consultoria
fiscal antes de emitir a primeira nota de produção — erros de CFOP,
CST/CSOSN ou NCM geram multa, não só rejeição.
