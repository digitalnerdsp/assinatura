# Deploy no Coolify

**Status:** os arquivos de deploy (`Dockerfile`, `docker-compose.yml`) foram escritos mas **ainda não foram
construídos nem executados** (o ambiente de desenvolvimento não tinha Docker). Espere ajustes no primeiro build.

## Passos

1. Coolify → *New Resource* → repositório Git → build pack **Docker Compose** → arquivo `docker-compose.yml`.
2. Domínio: atribua ao serviço **app**, porta **8080**. Os demais serviços não devem ter domínio.
3. Variáveis de ambiente (aba *Environment Variables*):

| Variável | Valor |
|---|---|
| `APP_KEY` | gere com `php artisan key:generate --show` (formato `base64:...`) e **guarde**: perdê-la invalida sessões e dados cifrados |
| `APP_URL` | `https://seu-dominio` |
| `DB_PASSWORD` | senha forte para o Postgres |
| `SEALER_TOKEN` | string aleatória longa (compartilhada entre app e sealer) |
| `SIGNING_ALLOW_LOCAL_TEST` | `true` **somente em teste** (certificado autoassinado, sem validade jurídica) |
| `MAIL_MAILER`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS` | SMTP real; com `log` os e-mails só aparecem nos logs |

4. Deploy. As migrations rodam sozinhas ao subir o `app` (`AUTORUN_ENABLED`).

## Testar

- `https://seu-dominio/cadastro` → criar a conta → novo envelope → enviar.
- Sem SMTP, pegue o link de assinatura nos logs do serviço `worker`/`app` (mensagens de e-mail).
- Com `SIGNING_ALLOW_LOCAL_TEST=true` e `SIGNING_PLATFORM_PROVIDER=local_test`, a selagem usa certificado de teste.

## Antes de produção

- Trocar o provider para `platform_a1` / `platform_hsm` com o e-CNPJ ICP-Brasil.
- `SIGNING_ALLOW_LOCAL_TEST=false`.
- Backups do volume `pgdata` e do volume `documents` (PDFs e documentos selados são prova).
- Mover documentos para armazenamento S3 compatível.
