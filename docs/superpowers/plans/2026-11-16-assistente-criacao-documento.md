# Assistente de criação de documento em 4 passos — plano de implementação

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Trocar a página única "Novo documento" por um assistente de 4 passos (PDF, signatários, posicionar assinaturas, enviar), com rascunho gravado, e fazer a tela do signatário e a selagem entenderem os campos Visto e Texto.

**Architecture:** Cada passo é uma página (`/documentos/{id}/passo/{n}`) servida por um `DocumentWizardController`; o rascunho é um `Envelope` em `draft` com `wizard_step` (último passo concluído). Operações novas no `EnvelopeService` (criar rascunho, trocar PDF, sincronizar pessoas, campos e opções de envio) são reaproveitadas pelo `create` atual (API e modelos). O posicionamento usa pdf.js (já usado hoje) em `public/assets/js`. Tipos de campo `signature | initials | text` chegam ao carimbo do `sealer`.

**Tech Stack:** Laravel 13, PostgreSQL, Pest 4 (RefreshDatabase), Bootstrap escopado nas telas internas (`.legacy-scope`), pdf.js 3.11.174, Python (pypdf/reportlab) no `sealer/`.

**Spec:** `docs/superpowers/specs/2026-11-16-assistente-criacao-documento-design.md` (protótipo clicável: `docs/superpowers/prototypes/assistente-4-passos.html`).

## Global Constraints

- Texto visível sempre "documento", nunca "envelope". Nomes internos (`Envelope`, `envelope_id`, rotas `envelopes.*`) ficam.
- Português do Brasil, verbos no infinitivo nos botões; contraste mínimo 4,5:1; itens importantes em negrito; claro e escuro; validar em 375, 768 e 1024 px.
- Só PDF, **um** arquivo por documento; limite `config('signing.max_pdf_mb')`; sempre `Entitlements::for($tenant)->canUpload($bytes)->orFail()` ao gravar PDF.
- Máximo 60 campos por documento; coordenadas e tamanhos entre 0 e 1; no máximo 20 signatários (`config('signing.max_witnesses')` para testemunhas).
- Tudo por empresa: documento de outra empresa = **404**; documento que não é rascunho redireciona ao detalhe.
- Ids: `users.id` bigint, demais modelos uuid. Nomes de helpers de teste globais únicos entre arquivos (prefixo `wz`).
- Rodar testes com `php artisan test > storage/x.out` e ler o JSON; remover o arquivo depois. Cada tarefa termina com **commit e push na main** (regra do projeto).
- A main deve ficar **sempre implantável** (o Coolify faz deploy de cada push): a criação antiga de documento na web só sai na Tarefa 6; antes disso o assistente vive em `/documentos/assistente`.
- Contratos que **não mudam**: `POST /api/v1/documents`, geração a partir de modelo, `EnvelopeService::create(...)` (mesma assinatura e comportamento).

## Review Focus

1. **Duplo clique em "Enviar e finalizar":** só um envio, uma cobrança de uso (teste em Tarefa 6).
2. **Trocar o PDF com campos já posicionados:** campos zerados, arquivo antigo apagado, armazenamento da empresa acertado (Tarefa 2).
3. **Remover signatário ou desligar "Eu vou assinar":** campos dele somem junto; nenhum campo órfão (Tarefa 2).
4. **Signatário com campo Visto/Texto não consegue assinar sem preencher**, nem adulterando o formulário (Tarefa 8).
5. **Rascunho aberto em duas abas:** a aba antiga, ao salvar depois do envio, recebe erro claro e não altera nada (Tarefa 6).

---

## File Structure

| Arquivo | Responsabilidade |
|---|---|
| `database/migrations/2026_11_21_000001_wizard_columns.php` | colunas novas |
| `app/Envelopes/EnvelopeService.php` | operações de rascunho (adicionar) |
| `app/Http/Controllers/Web/DocumentWizardController.php` | passos 1–4, upload, pasta rápida, finalizar |
| `resources/views/documents/wizard/{layout,step1,step2,step3,step4}.blade.php` | telas |
| `public/assets/css/wizard.css`, `public/assets/js/wizard-placement.js` | estilo e posicionamento |
| `app/Console/Commands/TrashStaleDrafts.php` | rascunhos abandonados |
| `app/Models/SignatureField.php`, `Signature.php`, `Envelope.php` | tipos, casts |
| `app/Envelopes/SigningService.php`, `app/Http/Controllers/Public/SigningController.php`, `resources/views/sign/show.blade.php` | rubrica, texto e recusa |
| `app/Signing/ReportBuilder.php`, `sealer/report.py` | carimbo dos tipos |
| removidos: `resources/views/envelopes/create.blade.php`, `EnvelopeController::create/store/formData` | substituídos |

---

### Task 1: Colunas e modelos

**Files:**
- Create: `database/migrations/2026_11_21_000001_wizard_columns.php`
- Modify: `app/Models/SignatureField.php`, `app/Models/Envelope.php`
- Test: `tests/Feature/WizardSchemaTest.php`

**Interfaces:**
- Produces: `envelopes.wizard_step` (tinyint nullable, último passo concluído, nulo = criado fora do assistente), `envelopes.allow_refuse` (bool, padrão true); `signers.email` nullable; `signature_fields.type` (string, padrão `signature`), `signature_fields.value` (text nullable); `signatures.initials_type` (string nullable), `signatures.initials_data` (text nullable). `SignatureField::TYPES = ['signature']` (constante, ampliada na Tarefa 8). `Envelope` cast `allow_refuse => boolean`, `wizard_step => integer`.

- [ ] **Step 1: Write the failing test** `it('a migration cria as colunas do assistente')`: `Schema::hasColumns('envelopes', ['wizard_step','allow_refuse'])`, idem `signature_fields` (`type`,`value`), `signatures` (`initials_type`,`initials_data`); inserir `Signer` com `email = null` não lança; `Envelope` novo tem `allow_refuse === true` e `wizard_step === null`; `SignatureField` novo tem `type === 'signature'`.
- [ ] **Step 2: Run** `php artisan test --filter=WizardSchemaTest` — Expected: FAIL (colunas ausentes).
- [ ] **Step 3: Implement** a migration (`$table->unsignedTinyInteger('wizard_step')->nullable()`, `boolean('allow_refuse')->default(true)`, `change()` do e-mail para nullable usando SQL do PostgreSQL se o `->change()` exigir doctrine) e os casts/constante nos modelos.
- [ ] **Step 4: Run** o teste e a suíte inteira — Expected: PASS, suíte verde.
- [ ] **Step 5: Commit e push:** `git add -A && git commit -m "Colunas do assistente de criação de documento" && git push origin main`.

---

### Task 2: Serviço de rascunho

**Files:**
- Modify: `app/Envelopes/EnvelopeService.php`
- Test: `tests/Feature/WizardDraftServiceTest.php`

**Interfaces:**
- Consumes: `EnvelopeService::create` internals (validações do PDF, `StorageUsage::add/remove`, `evidence->record`), `SenderPolicy`, `ContactService::rememberFromSigners`.
- Produces (todas só aceitam `Envelope` em `draft`, senão `FlowException::because('not_draft', ...)`):
  - `createDraft(Tenant $tenant, User $creator, string $pdfContents, string $filename, string $title, ?string $folderId = null): Envelope` — `status=draft`, `wizard_step=1`, sem signatários, `allow_refuse=false`, evento `document_created` com `signers=0`.
  - `replaceDocument(Envelope $e, string $pdfContents, string $filename): void` — mesmas validações do PDF; cria o novo `Document`, aponta o envelope, apaga o antigo (arquivo e linha), acerta `StorageUsage`, apaga `signatureFields`, `wizard_step=1`.
  - `syncPeople(Envelope $e, User $by, array $people, bool $withSender, SigningOrder $order, array $verifications): void` — `$people`: lista de `['id'?, 'name', 'email'?, 'phone'?, 'cpf'?, 'role' => 'signer'|'witness']`. Mantém os signatários com `id` existente (preserva campos), cria os novos, remove os ausentes (os campos caem em cascata), cria/remove o signatário remetente (`is_sender`, posição 0, nome e e-mail de `$by`). Posições como em `create` (testemunhas por último). E-mail em minúsculas ou nulo. Aplica `signing_order` e `require_*`; `wizard_step = max(wizard_step, 2)`.
  - `syncFields(Envelope $e, array $fields): void` — `$fields`: `['signer_id','type','page','x','y','width','height']`; recusa signatário de outro documento, tipo fora de `SignatureField::TYPES`, mais de 60, coordenadas fora de 0..1 (`FlowException`); substitui o conjunto inteiro; `wizard_step = max(wizard_step, 3)`.
  - `applyDelivery(Envelope $e, array $options): void` — chaves `message` (até 500), `reminder_every_days` (`?int`), `validity_days` (`?int` 1..365), `channels` (subconjunto de email/whatsapp/sms), `allow_refuse` (bool), `tags` (uuids da empresa).
  - `assertSendable(Envelope $e, bool $autoSend): void` — `FlowException` `no_signers` sem nenhum participante; `email_required` quando `$autoSend` e algum signatário não remetente sem e-mail válido.

- [ ] **Step 1: Write the failing tests** (um `it` por regra): rascunho sem signatário criado e `wizard_step=1`; `canUpload` barra acima do limite; `replaceDocument` apaga o arquivo antigo, mantém o armazenamento da empresa igual ao tamanho do novo e zera os campos; `syncPeople` mantém campos de quem permanece e apaga os de quem sai; ligar/desligar o remetente cria/remove o signatário `is_sender` e seus campos; papel inválido e e-mail nulo (aceito); `syncFields` recusa signatário alheio, tipo `text` (ainda não liberado), 61 campos e `x=1.2`; `assertSendable` com e-mail ausente e `autoSend` true lança, com `autoSend` false passa; qualquer método em documento `sent` lança `not_draft`; `create` (API) continua passando (suíte existente).
- [ ] **Step 2: Run** `php artisan test --filter=WizardDraftServiceTest` — Expected: FAIL (métodos inexistentes).
- [ ] **Step 3: Implement** os métodos em `EnvelopeService`, extraindo para métodos privados o que `create` e `createDraft` compartilham (validação do PDF, gravação do `Document`).
- [ ] **Step 4: Run** o teste e a suíte inteira — Expected: PASS.
- [ ] **Step 5: Commit e push** ("Serviço de rascunho do assistente").

---

### Task 3: Passo 1 (documento e pasta), rotas e guardas

**Files:**
- Create: `app/Http/Controllers/Web/DocumentWizardController.php`, `resources/views/documents/wizard/layout.blade.php` (stepper horizontal no computador, vertical no celular, não clicável), `.../step1.blade.php`, `public/assets/css/wizard.css`
- Modify: `routes/web.php` (rotas abaixo; a criação antiga continua funcionando até a Tarefa 6, para a main ficar sempre implantável)
- Test: `tests/Feature/WizardStep1Test.php`

**Interfaces:**
- Produces rotas (dentro do grupo `auth` + `tenant.active`), **provisórias** até a Tarefa 6: `GET /documentos/assistente` → `create` (`envelopes.wizard`); `POST /documentos/assistente` → `upload` (`envelopes.wizard.upload`); definitivas: `GET /documentos/{envelope}/passo/{n}` → `show` (`envelopes.step`, `n` 1..4); `PUT /documentos/{envelope}/passo/{n}` → `save` (`envelopes.step.save`, `n` 1..3); `POST /documentos/{envelope}/finalizar` → `finish` (`envelopes.finish`); `POST /documentos/pasta-rapida` → `quickFolder` (`documents.folders.quick`, JSON `{id, path}` via `FolderService::create`).
- Guarda comum `draftFor(Request, Envelope): Envelope`: tenant diferente = 404; na lixeira = 404; `status !== draft` = redirect `envelopes.show`; passo pedido > `wizard_step + 1` = redirect ao primeiro passo pendente.
- `upload` valida `document` (`file|mimes:pdf|max`), `title` opcional (padrão: nome do arquivo sem `.pdf`), `folder_id` opcional; chama `createDraft`; redireciona ao passo 2. `save` no passo 1: título, pasta e, se vier arquivo, `replaceDocument`.

- [ ] **Step 1: Write the failing tests:** `GET /documentos/assistente` mostra "Novo documento" e "Alterar pasta"; upload de PDF cria rascunho e redireciona a `/documentos/{id}/passo/2`; não-PDF e acima do limite voltam com erro no campo; título padrão sem `.pdf`; pasta de outra empresa é recusada; `GET passo/3` de rascunho com `wizard_step=1` redireciona ao passo 2; documento de outra empresa = 404; documento `sent` redireciona ao detalhe; `POST pasta-rapida` cria a pasta e devolve `id` e `path`; nome repetido devolve 422 com a mensagem do `FolderService`.
- [ ] **Step 2: Run** `php artisan test --filter=WizardStep1Test` — Expected: FAIL.
- [ ] **Step 3: Implement** controller, views e CSS do passo 1 e do stepper (estados atual, concluído, lápis ao voltar; botão principal desabilitado sem arquivo), reproduzindo o protótipo (zona de arrastar, "Subir outro arquivo", "Alterar pasta" com criar pasta no mesmo seletor). Menu: o item **Documentos criados** continua ativo nas telas do assistente (`MenuBuilder` e `sidebar`: padrões `envelopes.*`).
- [ ] **Step 4: Run** o teste e a suíte inteira. O teste antigo `SidebarMenuTest` e os que dependem de `envelopes.create` continuam verdes.
- [ ] **Step 5: Commit e push.**

---

### Task 4: Passo 2 (signatários)

**Files:**
- Create: `resources/views/documents/wizard/step2.blade.php`
- Modify: `DocumentWizardController::show/save`, `wizard.css`
- Test: `tests/Feature/WizardStep2Test.php`

**Interfaces:**
- Consumes: `EnvelopeService::syncPeople`, `SenderPolicy::policy(Tenant)` (`always|optional|never`), `SenderPolicy::wantsSenderSignature(Tenant, ?bool): bool`, `User::hasSignature()`, contatos (`Contact` da empresa).
- Produces: `PUT passo/2` com `people[]` (`id?`, `name`, `email?`, `phone?`, `cpf?`, `role`), `sender_signs`, `signing_order` (`parallel|sequential`), `require_otp|selfie|document|geolocation`. Passo 2 só avança com ao menos um participante (signatário ou remetente).

- [ ] **Step 1: Write the failing tests:** nome vazio recusado; só o nome basta (e-mail nulo salvo); política `always` força o remetente e `never` o proíbe; remetente sem assinatura salva volta com erro e link para Meu perfil; testemunha salva com papel `witness`; "Assinar em ordem" grava `sequential`; verificações gravadas no documento; reenviar o formulário com os `id` mantém os signatários e seus campos; remover um signatário o apaga; contatos da empresa aparecem na busca e os de outra empresa não; `wizard_step` vira 2.
- [ ] **Step 2: Run** — Expected: FAIL.
- [ ] **Step 3: Implement** a tela (campo único + "+ Signatário(a)", switch **Eu vou assinar**, atalho **Contatos**, **Autenticação avançada** com formulário completo e verificações, **Assinar em ordem** com 2 ou mais participantes, "Observadores" desabilitado com "em breve") e o `save`.
- [ ] **Step 4: Run** teste e suíte.
- [ ] **Step 5: Commit e push.**

---

### Task 5: Passo 3 (posicionar assinaturas, tipo Assinatura)

**Files:**
- Create: `resources/views/documents/wizard/step3.blade.php`, `public/assets/js/wizard-placement.js`
- Modify: `DocumentWizardController::show/save`, `wizard.css`
- Test: `tests/Feature/WizardStep3Test.php`

**Interfaces:**
- Consumes: `EnvelopeService::syncFields`, rota `envelopes.original` (PDF para o visualizador), `SignatureField::TYPES`.
- Produces: `PUT passo/3` com `fields[]` (`signer_id`, `type`, `page`, `x`, `y`, `width`, `height`) e `skipped` (bool, "continuar sem posicionar"); a view recebe `$fieldTypes = SignatureField::TYPES` e `$people` (id + nome, remetente incluso). O JS expõe o estado em `window.WizardPlacement` (`fields`, `addField`, `serialize()`).

- [ ] **Step 1: Write the failing tests (PHP):** `GET passo/3` renderiza o visualizador com o PDF e os signatários; `PUT` com campos salva com os `signer_id` reais; `skipped=1` com nenhum campo avança ao passo 4 e grava `wizard_step=3`; sem campos e sem `skipped` volta com erro; coordenada fora de 0..1, página 0, 61 campos e signatário de outro documento recusados; ao voltar ao passo 3 os campos salvos aparecem; salvar de novo substitui o conjunto.
- [ ] **Step 2: Run** — Expected: FAIL.
- [ ] **Step 3: Implement** a tela e o JS conforme o protótipo: pdf.js em rolagem contínua; **clique ou toque** abre o popup ancorado (folha inferior no celular) com seletor de signatário e botões de tipo vindos de `$fieldTypes`; campo tracejado com nome do signatário e legenda; arrastar, redimensionar, duplicar, remover com pointer events; botões *Editar documento*, *Continuar sem posicionar* (confirmação "Continuar sem posicionar assinaturas?" com **Posicionar agora** e **Continuar**) e *Salvar e continuar* (desabilitado sem campo); aviso verde de sucesso; `beforeunload` com alteração pendente. Sem localStorage.
- [ ] **Step 4: Run** teste e suíte; conferir no navegador: clicar/tocar, mover, salvar, voltar e rever os campos (375 e 1024 px).
- [ ] **Step 5: Commit e push.**

---

### Task 6: Passo 4, finalizar, recusa e fim da página antiga

**Files:**
- Create: `resources/views/documents/wizard/step4.blade.php`
- Modify: `DocumentWizardController::show/save/finish`, `EnvelopeController` (remover `create`, `store`, `formData`), `routes/web.php`, `resources/views/envelopes/*` (links "Criar documento" já apontam para `envelopes.create`), `app/Http/Controllers/Public/SigningController.php` e `resources/views/sign/show.blade.php` (recusa), `app/Envelopes/SigningService.php::refuse`
- Delete: `resources/views/envelopes/create.blade.php`
- Test: `tests/Feature/WizardStep4Test.php`, ajustes em `tests/Feature/{ContactCsvApiTest,FolderReviewFixesTest,MySignatureAndSenderWebTest,WitnessUiApiTest}.php`

**Interfaces:**
- Consumes: `applyDelivery`, `assertSendable`, `EnvelopeService::send` e `sendAndSignAsSender`, `SignerNotifier::inviteActive`, `SenderSignature`, `RequestContext::fromRequest`.
- Produces: `PUT passo/4` (opções: `auto` bool, `message`, `reminder_every_days` `off|1|2|3|5|7|10|14`, `limit_date` `Y-m-d` opcional → `validity_days = ceil` dias entre hoje e a data, de 1 a 365, `allow_refuse`, `channels[]`, `emails[signer_id]`, `tags[]`) e `POST finalizar` (valida, envia, convida e redireciona a `envelopes.show` com o aviso). Documento com `allow_refuse=false`: a tela do signatário **não mostra** "Recusar assinatura" e `SigningService::refuse` lança `FlowException::because('refuse_disabled', ...)` (rota responde 403).

- [ ] **Step 1: Write the failing tests:** e-mail obrigatório com envio automático (erro por signatário) e opcional no modo manual; modo manual grava `notify_channels=['manual']` e `reminder_every_days=null` como o `skip_email` de hoje; remetente: finalizar assina como remetente (`sendAndSignAsSender`) e os demais recebem convite; data limite vira `validity_days`; mensagem acima de 500 recusada; **duplo envio** (duas chamadas seguidas) → segunda vira redirect ao detalhe sem novo uso cobrado (`UsageLedger`); **salvar o passo depois do envio** → redirect ao detalhe sem alterar nada; limite do plano (`canSendDocument`) mostra a mensagem e mantém o rascunho; `allow_refuse=false` esconde o botão e `POST /assinar/{token}/recusar` responde 403 sem mudar o signatário; `allow_refuse=true` (API/modelos) continua recusando; link "Criar documento" da lista e do painel leva ao passo 1.
- [ ] **Step 2: Run** — Expected: FAIL.
- [ ] **Step 3: Implement** a tela (envio automático, e-mail por signatário, mensagem com contador, lembretes, data limite, permitir recusar, etiquetas) e `finish` (transação e trava de linha no envelope para o duplo envio). **Virar o padrão:** remover `create/store/formData`, a view antiga e as rotas antigas `envelopes.create`/`envelopes.store`; renomear as provisórias para `GET /documentos/novo` (`envelopes.create`) e `POST /documentos` (`envelopes.store`); trocar os links "Criar documento" (lista, painel, temas Able e W3 CRM, que usam `route('envelopes.create')`, e `MenuBuilder`/`sidebar`) e as telas vazias. Migrar os testes que postavam o formulário antigo (`grep -rn "post('/documentos'" tests`, 4 arquivos, 9 chamadas) para um helper `wzDraft(User $u, array $people, array $options = []): Envelope` em `tests/Feature/WizardStep4Test.php` que usa `EnvelopeService` e as rotas do assistente.
- [ ] **Step 4: Run** a suíte inteira — Expected: PASS.
- [ ] **Step 5: Commit e push.**

---

### Task 7: Lista com Rascunho, retomada e limpeza

**Files:**
- Create: `app/Console/Commands/TrashStaleDrafts.php`
- Modify: `resources/views/envelopes/_list.blade.php`, `_doc_menu.blade.php`, `routes/console.php`, `EnvelopeController::index` (se preciso)
- Test: `tests/Feature/WizardDraftListTest.php`

**Interfaces:**
- Produces: comando `documents:trash-stale-drafts` (`--days=14`) que move para a lixeira (`TrashService::trash`) rascunhos criados pelo assistente (`wizard_step` não nulo) sem alteração há N dias; agendado diariamente com `withoutOverlapping()`. Na lista, rascunho do assistente mostra o selo **Rascunho** e o título leva a `envelopes.step` no primeiro passo pendente (`wizard_step + 1`, máximo 4); os demais documentos seguem para `envelopes.show`.

- [ ] **Step 1: Write the failing tests:** link do rascunho aponta ao passo certo; documento enviado aponta ao detalhe; rascunho de API (sem `wizard_step`) aponta ao detalhe; comando move rascunho de 15 dias e preserva um de 13 e os enviados; rascunho na lixeira some da lista ativa e a lixeira o apaga em 30 dias (rotina existente); saída do comando "N documento(s) movido(s) para a lixeira.".
- [ ] **Step 2: Run** — Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** teste e suíte.
- [ ] **Step 5: Commit e push.**

---

### Task 8: Visto e Texto (tela do signatário e selagem)

**Files:**
- Modify: `app/Models/SignatureField.php` (`TYPES = ['signature','initials','text']`), `app/Envelopes/SigningService.php`, `app/Http/Controllers/Public/SigningController.php`, `resources/views/sign/show.blade.php`, `app/Signing/ReportBuilder.php::placements`, `sealer/report.py::_placement_overlay`, `resources/views/documents/wizard/step3.blade.php` (popup lista os 3 tipos)
- Test: `tests/Feature/WizardFieldTypesTest.php`, `sealer/tests/test_placements.py` (ou o arquivo de testes existente do `sealer`)

**Interfaces:**
- Consumes: `Signature` (`type`, `data`), colunas `initials_type`, `initials_data`, `SignatureField.value`.
- Produces: `SigningService::sign(Signer $signer, string $type, string $data, bool $acceptedTerms, ?RequestContext $context = null, ?array $initials = null, array $texts = []): void` — `$initials` = `['type' => 'drawn'|'typed', 'data' => string]`, `$texts` = `[fieldId => string]` (máx. 200 caracteres). Regras: se o signatário tem campo `initials`, `$initials` é obrigatório (`FlowException` `initials_required`); se tem campo `text`, cada um precisa de texto não vazio (`text_required`); ids de campo que não são do signatário são ignorados. Grava `initials_*` na assinatura e `value` em cada campo. `ReportBuilder::placements` passa `type` do campo (`signature|initials|text`) e, para `text`, o `value`; para `initials`, imagem ou texto da rubrica. `_placement_overlay` desenha: `signature` como hoje; `initials` com a rubrica (imagem ou texto) sem a legenda de data, em caixa menor; `text` como texto simples (fonte Helvetica, quebra por largura, sem caixa).

- [ ] **Step 1: Write the failing tests:** campo Visto sem rubrica → recusa `initials_required` e nada é gravado; campo Texto vazio → `text_required`; **adulteração**: enviar `texts` com id de campo de outro signatário não grava nada nele; texto acima de 200 caracteres recusado; assinatura completa grava `initials_*` e o `value`; `placements` traz os três tipos corretamente; `SignatureField::TYPES` aceita os três em `syncFields`; teste Python: o overlay com os três tipos gera PDF com uma página e não lança; `initials` sem imagem cai para o texto; texto longo quebra dentro da largura.
- [ ] **Step 2: Run** `php artisan test --filter=WizardFieldTypesTest` e o teste do sealer — Expected: FAIL.
- [ ] **Step 3: Implement** na ordem: serviço e controller → tela do signatário (segundo quadro "Seu visto" quando há campo Visto, com Desenhar e Digitar como o da assinatura; um campo de texto rotulado para cada campo Texto; tudo obrigatório) → `ReportBuilder` → `report.py`. Abrir um PDF selado de ponta a ponta no navegador para conferir Assinatura, Visto e Texto no lugar.
- [ ] **Step 4: Run** suíte PHP e testes Python do `sealer`.
- [ ] **Step 5: Commit e push.**

---

### Task 9: Conferência final e documentação

**Files:**
- Modify: `docs/ESCOPO.md` (seção do assistente e do `allow_refuse`), `docs/TEMAS.md` (rotas úteis: `envelopes.create`, `envelopes.step`), `docs/superpowers/specs/...-design.md` (status "implementado")
- Test: suíte inteira

- [ ] **Step 1:** Rodar a suíte inteira e os testes do `sealer` — Expected: tudo verde; listar qualquer falha pelo nome.
- [ ] **Step 2:** No navegador, com os temas Able e W3 CRM, claro e escuro, em 375, 768 e 1024 px: criar um documento de ponta a ponta (PDF → signatários com remetente → Assinatura, Visto e Texto → envio manual e automático); abrir o link do signatário, assinar e abrir o PDF selado; sair no meio e retomar do rascunho; trocar o PDF com campos posicionados. Anotar o que **não** foi conferido.
- [ ] **Step 3:** Revisão de código independente do diff inteiro (foco: tenant, duplo envio, validação dos campos, XSS nos textos do signatário no PDF e na tela, `allow_refuse`).
- [ ] **Step 4:** Corrigir o que a revisão e a conferência acharem, cada correção com teste que falhou antes.
- [ ] **Step 5: Commit e push** da documentação e das correções.
