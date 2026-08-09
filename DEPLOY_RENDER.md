# Publicar o microsserviço fiscal no Render

## 1. Colocar esse projeto no GitHub (separado do pdv-web)

```bash
cd microsservico-fiscal
git init
git add .
git commit -m "primeiro commit"
git branch -M main
git remote add origin https://github.com/castilho29/microsservico-fiscal.git
git push -u origin main
```

Confirma que o `.env` real (com suas chaves) **não** foi enviado — o
`.dockerignore` e o `.gitignore` já protegem isso, mas vale conferir
em `https://github.com/castilho29/microsservico-fiscal` se não
aparece um arquivo `.env` na lista (só `.env.example` deveria estar lá).

## 2. Criar o Web Service no Render

1. Entra em https://render.com, loga com GitHub
2. **New → Web Service**
3. Conecta o repositório `microsservico-fiscal`
4. Em **Runtime**, escolhe **Docker** (o Render detecta o `Dockerfile`
   sozinho, mas confirma que essa opção está selecionada)
5. **Instance Type**: escolhe **Free**
6. Não precisa preencher "Build Command" nem "Start Command" -- o
   Dockerfile já define isso

## 3. Configurar as variáveis de ambiente

Ainda na tela de criação (ou depois, em **Environment**), adiciona
as mesmas variáveis do seu `.env` local:

```
SUPABASE_URL=https://lllzmqwonapfvxpdsdgl.supabase.co
SUPABASE_SERVICE_ROLE_KEY=sua-service-role-key
MICROSERVICO_TOKEN=escolha-um-token-longo-aleatorio
```

Se for usar a emissão de NFC-e (mais pra frente, quando tiver os
dados fiscais certos), adiciona os `NFE_*` também.

## 4. Criar o serviço

Clica **Create Web Service**. O primeiro build demora um pouco mais
(o Docker precisa instalar as extensões do PHP do zero) -- espera
uns 3-5 minutos.

## 5. Pegar a URL pública

Quando terminar, aparece no topo da página algo como:
```
https://microsservico-fiscal.onrender.com
```

## 6. Conectar o pdv-web publicado a essa URL

No GitHub do `pdv-web` → **Settings → Secrets and variables →
Actions**, adiciona (ou edita):
```
NEXT_PUBLIC_MICROSSERVICO_URL=https://microsservico-fiscal.onrender.com
NEXT_PUBLIC_MICROSSERVICO_TOKEN=o-mesmo-token-que-voce-colocou-no-render
```

Depois dispara um novo deploy do `pdv-web`:
```bash
cd D:\MercadoOnline\pdv-web
git commit --allow-empty -m "conectar microsservico fiscal publicado"
git push
```

## Sobre o "dormir"

No plano free, se ninguém chamar o microsserviço por 15 minutos, ele
desliga sozinho. Na próxima chamada (por exemplo, quando você for
importar um XML depois de um tempo parado), demora uns 30-60
segundos pra "acordar" antes de responder. Isso é normal do plano
gratuito -- não é erro. Se isso incomodar no dia a dia, o upgrade
pro plano pago ($7/mês) remove esse comportamento.
