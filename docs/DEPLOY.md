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

- `https://seu-dominio/cadastro` → criar a conta → novo documento → enviar.
- Sem SMTP, pegue o link de assinatura nos logs do serviço `worker`/`app` (mensagens de e-mail).
- Com `SIGNING_ALLOW_LOCAL_TEST=true` e `SIGNING_PLATFORM_PROVIDER=local_test`, a selagem usa certificado de teste.

## Assinatura do remetente (padrão: opcional)

Por padrão toda empresa tem a política `optional`: em cada documento, quem cria escolhe se assina (switch "Eu vou assinar" no passo 2). Quem quiser que todo documento saia assinado escolhe `always` em Configurações › Assinatura da empresa. **Antes de criar documentos pelo painel, cada usuário precisa criar a sua assinatura em Meu perfil → Minha assinatura.** Pela API, a empresa precisa de representante e termo aceitos em Configurações → Assinatura da empresa, e a chave precisa da permissão "Pode assinar em nome da empresa". O dono da conta pode mudar a política para opcional ou nunca na mesma tela.

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
4. Crie e conclua um documento e abra o PDF no Adobe Reader e em validar.iti.gov.br: a assinatura deve aparecer como válida e confiável.

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
| `scheduler` | **novo:** `schedule:work` — expira documentos vencidos (a cada 15 min) e envia lembretes (de hora em hora) e confere o armazenamento de cada empresa (`storage:reconcile`, 03:30) e envia os avisos de vencimento de contratos (`contracts:send-alerts`, todo dia às 11:00 UTC = 08:00 em Brasília; precisa do SMTP configurado) |
| `sealer` | selagem PAdES + carimbo na página + relatório + conversão de DOCX em PDF (LibreOffice). A imagem tem cerca de 500 MB e o contêiner precisa de pelo menos 1 GB de memória; o primeiro build demora mais |
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

## Vários arquivos e DOCX
- `SIGNING_MAX_DOCUMENTS` (padrão 10): arquivos por envio. `SIGNING_MAX_PDF_MB` vale para cada arquivo.
- Arquivos DOCX são convertidos pelo selador (`/v1/convert`, até 60 s por arquivo, no máximo 2 conversões ao mesmo tempo: `SEALER_CONVERT_SLOTS`). Se o selador estiver fora do ar, o envio de DOCX mostra um aviso e PDF continua funcionando.
- Cada arquivo é selado separadamente; se um falhar, o envio fica em "selando" e a nova tentativa sela só o que falta.

### Segurança da conversão de DOCX
- O LibreOffice roda com ambiente mínimo (sem o token nem os certificados do selador), em grupo próprio de processos, com limite de memória (`SEALER_CONVERT_MAX_MB`, padrão 3072) e de CPU (`SEALER_CONVERT_MAX_CPU`, padrão 120 s). No tempo limite o grupo inteiro é encerrado. Sem vaga livre (`SEALER_CONVERT_SLOTS`), a conversão é recusada na hora.
- Limite que ainda **não** existe: o conversor roda no mesmo contêiner do selador, com acesso à rede e ao mesmo usuário. Antes de aceitar DOCX de qualquer cliente desconhecido, o ideal é movê-lo para um contêiner próprio, sem rede e sem os certificados (por exemplo o Gotenberg).
- `SIGNING_MAIL_ATTACHMENT_MB` (padrão 15): teto de anexos por e-mail de cópia. O que não couber vai pelo link.

## Assistente no WhatsApp (Plano 1: consultas)
- **Processos:** além do `web`, precisa de um **worker de fila** (`php artisan queue:work`) e do **agendador** (`schedule:work`). O webhook da Meta responde rápido e a conversa roda na fila; sem worker, ninguém recebe resposta.
- **Webhook:** `POST/GET {APP_URL}/webhooks/meta/whatsapp` (sem login e sem CSRF; a proteção é a assinatura HMAC-SHA256 do corpo com o segredo do app, conferida antes de qualquer processamento). O endereço aparece em Admin ▸ Assistente no WhatsApp.
- **Credenciais** (token da Meta, segredo do app, token de verificação, chave da OpenAI): salvas **cifradas no banco** pela tela da plataforma, nunca em variável de ambiente nem no repositório. Os padrões de modelo, pacote, excesso e acréscimo podem vir de `ASSISTANT_*` (ver `config/assistant.php`); o que for salvo na tela vale mais.
- **Dados de conversa:** guardados por 90 dias (`assistant:prune`, diário). Lançamentos de tokens e uso da Meta não são apagados (registro financeiro). O modelo de IA recebe só texto, títulos e nomes; **nunca** o PDF.
- **Cobrança:** a medição existe (tokens por modelo, mensagens da Meta com categoria e acréscimo), mas a **fatura automática não**: pacote comprado, ajuste e repasse são lançados à mão em Admin ▸ Assistente no WhatsApp ▸ Lançamento manual.
- **Roteiro do piloto:** `docs/ASSISTENTE-PILOTO.md`.

## Avisos no WhatsApp (modelos, saldo em reais, pré e pós-pago)
Plano e decisões: `docs/superpowers/plans/2026-11-20-avisos-whatsapp.md`.
- **O que envia:** ao usuário vinculado que criou o documento: assinado por todos, alguém assinou, recusa, contrato perto de vencer e documento parado (`documents:notify-stalled`, diário às 12:00 UTC). Cada usuário liga/desliga cada aviso no perfil. Super administrador e a empresa dele não recebem nem são cobrados.
- **Modelos aprovados pela Meta:** fora da janela de 24 h só vai mensagem de modelo. Em Admin ▸ Avisos do WhatsApp edite o texto de cada situação, clique em **Enviar para aprovação** e depois **Atualizar status na Meta** (precisa do **WABA** em Admin ▸ Assistente WhatsApp). Só modelo aprovado e ligado envia. Mudar o texto de um modelo aprovado o devolve a rascunho. Mantenha tom de aviso (sem oferta) para a Meta classificar como utilidade.
- **Custo e saldo:** o valor vem do aviso de status da Meta (a categoria que ela de fato cobrou) × a tabela de preços por categoria em Admin ▸ Assistente WhatsApp, mais o acréscimo. Cada mensagem cobrada vira **um** débito no saldo em reais da empresa. **Pré-pago** (padrão): só envia com saldo. **Pós-pago:** envia até o limite da empresa e o valor é somado à mensalidade; depois de cobrar, lance a **quitação**. Recarga, ajuste e quitação são lançados à mão na mesma tela (com nota); a cobrança automática pelo gateway (Fase 4) continua adiada.
- **Sem preço na tabela da categoria do modelo, nada é enviado** (não inventa valor). Avisos de signatário externo pelo WhatsApp ficam para o próximo plano (precisam de aceite do signatário).
