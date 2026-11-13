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
| `APP_URL` | `https://seu-dominio` (com `https://`; é o endereço que vai no QR e no rodapé do PDF selado e nos e-mails) |
| `DB_PASSWORD` | senha forte para o Postgres |
| `SEALER_TOKEN` | string aleatória longa (compartilhada entre app e sealer) |
| `SUPER_ADMIN_EMAILS` | e-mail(s) **raiz** com acesso total a `/admin` (a tela não altera esses acessos). **Cadastre a conta antes** de colocar o e-mail aqui; não há verificação de e-mail no cadastro. Os demais administradores e super administradores são criados em **Plataforma › Usuários da plataforma** |
| `SIGNING_ALLOW_LOCAL_TEST` | `true` **somente em teste** (certificado autoassinado, sem validade jurídica) |
| `MAIL_MAILER`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS` | SMTP real; com `log` os e-mails só aparecem nos logs |

4. Deploy. As migrations rodam sozinhas ao subir o `app` (`AUTORUN_ENABLED`).

## Testar

- `https://seu-dominio/cadastro` → criar a conta → novo envelope → enviar.
- Sem SMTP, pegue o link de assinatura nos logs do serviço `worker`/`app` (mensagens de e-mail).
- Com `SIGNING_ALLOW_LOCAL_TEST=true` e `SIGNING_PLATFORM_PROVIDER=local_test`, a selagem usa certificado de teste.

## Assinatura do remetente (padrão: sempre)

Por padrão toda empresa tem a política `always`: o documento sai assinado por quem o enviou. **Antes de criar documentos pelo painel, cada usuário precisa criar a sua assinatura em Meu perfil → Minha assinatura.** Pela API, a empresa precisa de representante e termo aceitos em Configurações → Assinatura da empresa, e a chave precisa da permissão "Pode assinar em nome da empresa". O dono da conta pode mudar a política para opcional ou nunca na mesma tela.

## Selar com o e-CNPJ (certificado A1 ICP-Brasil)

1. Converta o arquivo para base64, em uma linha. No PowerShell: `[Convert]::ToBase64String([IO.File]::ReadAllBytes("C:\caminho\ecnpj.pfx"))`.
2. No Coolify, em **Environment Variables**, crie (marque a senha e o base64 como secretos):
   | Variável | Valor |
   |---|---|
   | `SEALER_PFX_ECNPJ_B64` | o texto em base64 do passo 1 |
   | `SEALER_PFX_ECNPJ_PASSWORD` | a senha do certificado |
   | `SIGNING_PLATFORM_PROVIDER` | `platform_a1` |
   | `SIGNING_ALLOW_LOCAL_TEST` | `false` |
3. Redeploy. Em **Plataforma → Selagem do PDF**, use **Testar conexão e certificado**: deve mostrar o titular (sua empresa), o emissor, a validade e o final "· ICP-Brasil".
4. Crie e conclua um envelope e abra o PDF no Adobe Reader e em validar.iti.gov.br: a assinatura deve aparecer como válida e confiável.

O sealer nunca devolve a senha em respostas nem em log. Para trocar de certificado, mude as duas variáveis e faça o redeploy.

## Antes de produção

- Trocar o provider para `platform_a1` / `platform_hsm` com o e-CNPJ ICP-Brasil.
- `SIGNING_ALLOW_LOCAL_TEST=false`.
- Backups do volume `pgdata` e do volume `documents` (PDFs e documentos selados são prova).
- Mover documentos para armazenamento S3 compatível.

## Serviços do compose (atualizado)

| Serviço | Função |
|---|---|
| `app` | site e API (porta 8080; é o único com domínio) |
| `worker` | fila: envio de e-mail/WhatsApp, selagem do PDF, entrega de webhooks |
| `scheduler` | **novo:** `schedule:work` — expira envelopes vencidos (a cada 15 min) e envia lembretes (de hora em hora) e confere o armazenamento de cada empresa (`storage:reconcile`, 03:30) |
| `sealer` | selagem PAdES + carimbo na página + relatório |
| `backup` | **novo:** a cada 24 h grava `db-AAAAMMDD-HHMMSS.sql.gz` e `documents-….tar.gz` no volume `backups` e apaga o que passar de `BACKUP_RETENTION_DAYS` (padrão 14) |
| `db` | PostgreSQL 17 |

Sem o `scheduler` rodando, lembretes e expiração automática **não acontecem** (o painel *Plataforma → Saúde do sistema* avisa).

### Variáveis novas (todas opcionais)

| Variável | Para quê |
|---|---|
| `SIGNING_MAX_PDF_MB` (20) | limite de tamanho do PDF. Mantenha `PHP_UPLOAD_MAX_FILESIZE` (25M) e `PHP_POST_MAX_SIZE` (30M) acima dele |
| `MESSAGING_DRIVER` (`log`) | `twilio` ativa WhatsApp/SMS; com `log` as mensagens só vão para o log |
| `SEALER_TSA_URL` | carimbo de tempo RFC 3161 no PDF selado. Para teste: `https://freetsa.org/tsr` (sem validade ICP-Brasil). Em produção, use uma ACT credenciada na ICP-Brasil. Vazio = sem carimbo |
| `TWILIO_SID`, `TWILIO_TOKEN`, `TWILIO_FROM_SMS`, `TWILIO_FROM_WHATSAPP` | credenciais e números de origem da Twilio. WhatsApp exige número aprovado (ou o sandbox da Twilio para testes) |
| `SENTRY_LARAVEL_DSN` | liga os alertas de erro. Corpo da requisição, cookies e tokens da URL são removidos antes do envio |
| `SENTRY_TRACES_SAMPLE_RATE` | desempenho (0 a 1); deixe vazio para não coletar |
| `BACKUP_RETENTION_DAYS` (14), `BACKUP_INTERVAL_SECONDS` (86400) | rotina do backup |
| `WEBHOOKS_ALLOW_PRIVATE` (false) | **só desenvolvimento:** permite webhook para localhost/rede interna. Em produção deixe false (proteção contra SSRF) |

### Backup: o que falta fazer fora do servidor

O serviço `backup` protege contra erro de software e exclusão acidental, **não** contra perder o servidor inteiro, porque o
volume `backups` fica na mesma máquina. Copie-o para fora:

- No Coolify, configure *S3 Backups* para o volume `backups` (ou rode `rclone sync` de um agendamento do Coolify).
- Teste a restauração pelo menos uma vez:

```bash
gunzip -c db-AAAAMMDD-HHMMSS.sql.gz | psql -h db -U assinatura_app -d assinatura   # banco vazio
tar -xzf documents-AAAAMMDD-HHMMSS.tar.gz -C /var/www/html/storage/app            # documentos
```

- Guarde também o `APP_KEY`: sem ele, os links de assinatura cifrados e as configurações salvas (SMTP) não abrem após a restauração.

### Repositório privado

O repositório contém o template Rocker (licença comercial) e hoje é público. Para torná-lo privado **sem quebrar o deploy**:

1. No Coolify, troque a origem para *Private Repository (with Deploy Key)* (ou GitHub App) e copie a chave pública que ele mostra.
2. No GitHub: *Settings → Deploy keys → Add deploy key*, cole a chave (somente leitura).
3. Só então: *Settings → General → Danger Zone → Change visibility → Private*.
4. Faça um *Redeploy* para confirmar que o Coolify ainda consegue clonar.

Se inverter a ordem, o próximo deploy falha por falta de acesso ao repositório.

### Teste de carga

Veja `loadtest/README.md` (k6). O limite de PDF é conferido no app (`SIGNING_MAX_PDF_MB`) e no PHP/nginx (`PHP_UPLOAD_MAX_FILESIZE`).
