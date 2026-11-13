# Fase 1b — Testemunhas e remetente que assina junto Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Completar o papel `witness` no fluxo de assinatura e fazer todo documento sair já assinado por quem o enviou (painel por sessão logada, API por chave autorizada), sem selfie nem código para o remetente.

**Architecture:** O remetente e as testemunhas são linhas de `signers` (campos novos `is_sender` e `signed_via`), então conclusão, relatório, posicionamento e webhooks continuam funcionando sobre a mesma tabela. A assinatura do remetente reaproveita a gravação de assinatura do `SigningService`, extraída em `recordSignature()`, sem passar pelos passos de verificação nem por link público. Uma `SenderPolicy` decide por empresa se o remetente assina (`always` | `optional` | `never`).

**Tech Stack:** PHP 8.4, Laravel 13, Pest 4, PostgreSQL, Blade, Python/pyHanko (sealer, só relatório).

**Spec:** `docs/superpowers/specs/2026-11-14-remetente-e-testemunhas-design.md` (seções 4 a 7); contexto em `docs/superpowers/specs/2026-11-13-menu-modulos-planos-design.md`.

## Global Constraints

- Papéis de `signers.role` válidos nesta fase: `signer` e `witness` (qualquer outro valor: `FlowException` com código `invalid_role`).
- Máximo de testemunhas por envelope: `config('signing.max_witnesses')`, padrão **10** (`SIGNING_MAX_WITNESSES`).
- Política da empresa em `tenant_signing_settings.sender_policy`: `always` (padrão) | `optional` | `never`.
- Em envelope **sequencial**: remetente com `position = 0` (sempre o primeiro); testemunhas formam o **último grupo** (`position` = maior posição de signatário + 1).
- O remetente **não** passa por OTP, selfie, documento nem localização, e **nunca** tem link público (sem `token_hash`). Signatários e testemunhas seguem as verificações do envelope.
- A prova do remetente é o evento `document_signed` com `method` = `session` (painel) ou `api_key` (API), mais IP e aparelho; nunca registra `document_viewed` nem eventos de OTP.
- Na API, a empresa só assina com chave `can_sign_as_company = true` (padrão `false`), **representante cadastrado** e **termo de delegação aceito**; caso contrário **422** com código `sender_signature_not_allowed` ou `no_company_representative`, e **nenhum envelope é criado**.
- Textos da interface em pt-BR; testes em Pest com `uses(RefreshDatabase::class)`; nenhum teste usa a internet.
- Commits por tarefa; push uma vez ao fim da fase (junto do commit final).

## Review Focus

1. **Chave sem permissão ou empresa sem delegação:** a API recusa antes de criar qualquer coisa (nada de rascunho órfão) e nunca produz assinatura da empresa. (Tarefa 7.)
2. **Testemunha fora de ordem ou pendente:** em sequencial, a testemunha não assina antes dos signatários; o documento nunca conclui com testemunha pendente; recusa de testemunha recusa o documento. (Tarefa 2.)
3. **Remetente não enfraquece os outros:** com selfie/código exigidos no envelope, o remetente assina sem eles, mas signatários e testemunhas continuam exigindo; o remetente não pode ser acionado por link público. (Tarefa 5.)
4. **Prova honesta:** o evento do remetente traz o método real e nunca marca o remetente como "visto" ou "verificado por código". (Tarefa 5.)
5. **Isolamento entre empresas:** a assinatura salva do usuário e o representante nunca vazam entre empresas; a chave da empresa B nunca usa o representante da A; trocar o representante exige novo aceite do termo. (Tarefas 6 e 7.)
6. **Envelope só do remetente:** conclui e sela na hora, sem outros signatários. (Tarefa 5.)

---

### Task 1: Esquema, modelos e configuração

**Files:**
- Create: `database/migrations/2026_11_15_000001_add_sender_and_witness_support.php`
- Modify: `app/Models/Signer.php`, `app/Models/TenantSigningSetting.php`, `app/Models/ApiKey.php`, `app/Models/User.php`, `config/signing.php`
- Test: `tests/Feature/SenderSchemaTest.php`

**Interfaces:**
- Consumes: nada.
- Produces:
  - `signers.is_sender` (bool, padrão `false`), `signers.signed_via` (string nula: `link` | `session` | `api_key`).
  - `tenant_signing_settings`: `sender_policy` (padrão `always`), `representative_name`, `representative_email`, `representative_cpf` (nulos), `delegation_accepted_at` (timestampTz nulo), `delegation_accepted_by` (foreignId nulo para `users`, `nullOnDelete`), `delegation_accepted_ip` (string 45 nula).
  - `api_keys.can_sign_as_company` (bool, padrão `false`).
  - `users.signature_type` (string nula: `drawn` | `typed`), `users.signature_data` (text nulo).
  - `Signer::isSender(): bool`, `Signer::isWitness(): bool`; `Envelope::PARTICIPANT_ROLES = ['signer', 'witness']`.
  - `config('signing.max_witnesses')` (int, 10).

- [ ] **Step 1: Write the failing test** `tests/Feature/SenderSchemaTest.php`:
  - `it('cria as colunas novas com os padrões do escopo')`: `Schema::hasColumns('signers', ['is_sender','signed_via'])`, `tenant_signing_settings` com as 7 colunas, `api_keys.can_sign_as_company`, `users` com `signature_type` e `signature_data`; uma `TenantSigningSetting` nova tem `sender_policy === 'always'`, uma `ApiKey` nova tem `can_sign_as_company === false`, um `Signer` novo tem `isSender() === false` e `isWitness() === false` (e `true` para `role = 'witness'`).
  - `it('configura o limite de testemunhas')`: `config('signing.max_witnesses') === 10`; `Envelope::PARTICIPANT_ROLES === ['signer','witness']`.
- [ ] **Step 2: Run** `php artisan test tests/Feature/SenderSchemaTest.php` — Expected: FAIL.
- [ ] **Step 3: Implement** a migration (reversível), os casts (`is_sender` boolean, `can_sign_as_company` boolean, `delegation_accepted_at` datetime), `isSender()`/`isWitness()` e a constante. `users.signature_data` entra em `$hidden` do `User` (o atributo `#[Hidden]` já existe). `config/signing.php`: `'max_witnesses' => (int) env('SIGNING_MAX_WITNESSES', 10)`.
- [ ] **Step 4: Run** o teste e `php artisan test` — Expected: PASS.
- [ ] **Step 5: Commit** `git add -A && git commit -m "Esquema da fase 1b: remetente, testemunhas, política e permissão de chave"`.

---

### Task 2: Testemunhas no fluxo (ordem, conclusão, recusa, validação)

**Files:**
- Modify: `app/Models/Envelope.php` (`activeSigners`), `app/Envelopes/EnvelopeService.php` (`create`), `app/Envelopes/SigningService.php` (conclusão em `sign()`, `assertMyTurn`, e o caminho de recusa — ler `refuse()` e aplicar a mesma regra), `app/Http/Controllers/Web/MySignaturesController.php`
- Test: `tests/Feature/WitnessFlowTest.php`

**Interfaces:**
- Consumes: `Envelope::PARTICIPANT_ROLES`, `config('signing.max_witnesses')` (Tarefa 1); `EnvelopeService::create(Tenant, ?User, string $pdf, string $filename, string $title, array $signers, SigningOrder, ...)`, `EnvelopeService::send`, `SigningService::sign(Signer, string $type, string $data, bool $accepted, ?RequestContext)`.
- Produces: `create()` aceita em cada item de `$signers` o `role` (`signer` | `witness`); regras: papel inválido → `invalid_role`; mais testemunhas que o limite → `too_many_witnesses`; nenhum item com `role = signer` → `no_signers`; testemunha sem `position` recebe `maior posição de signatário + 1`.

- [ ] **Step 1: Write the failing tests** em `tests/Feature/WitnessFlowTest.php` (helper local que cria tenant, usuário e PDF mínimo `"%PDF-1.4\n%%EOF"`; usar `Queue::fake()`):
  - `it('em envelope paralelo todos assinam juntos, testemunhas incluídas')`: 1 signatário + 2 testemunhas → `activeSigners()` tem 3.
  - `it('em sequencial a testemunha só assina depois dos signatários')`: `activeSigners()` inicial = só o signatário; assinar uma testemunha antes → `FlowException` com `reason === 'not_your_turn'`; depois do signatário assinar, as duas testemunhas ficam ativas juntas.
  - `it('só conclui quando signatários e testemunhas assinaram')`: depois do signatário, status `in_progress` e `SealEnvelope` **não** despachado; depois da última testemunha, status `sealing` e `Queue::assertPushed(SealEnvelope::class)`.
  - `it('recusa de testemunha recusa o documento')`: após `refuse` da testemunha, `status === refused`.
  - `it('valida papéis e limites')`: papel `approver` → `reason === 'invalid_role'`; 11 testemunhas → `too_many_witnesses`; só testemunhas → `no_signers`.
  - `it('testemunha aparece em Minhas assinaturas')`: usuário logado cujo e-mail é de uma testemunha da própria empresa vê o documento e o botão Assinar quando é a vez.
- [ ] **Step 2: Run** `php artisan test tests/Feature/WitnessFlowTest.php` — Expected: FAIL.
- [ ] **Step 3: Implement** as regras: `Envelope::activeSigners()` e a contagem de pendentes em `SigningService::sign()` e `assertMyTurn()` usam `whereIn('role', Envelope::PARTICIPANT_ROLES)`; `create()` valida e calcula posições; `MySignaturesController` usa `whereIn('role', Envelope::PARTICIPANT_ROLES)`. Conferir `refuse()` e `LifecycleService` (lembretes/expiração) e ajustar o que filtrar `role = 'signer'` por engano.
- [ ] **Step 4: Run** o teste e `php artisan test` — Expected: PASS.
- [ ] **Step 5: Commit** `git add -A && git commit -m "Testemunhas no fluxo: ordem, conclusão, recusa e validação"`.

---

### Task 3: Testemunhas na tela e na API

**Files:**
- Modify: `resources/views/envelopes/create.blade.php`, `resources/views/envelopes/show.blade.php`, `app/Http/Controllers/Web/EnvelopeController.php` (`store`, `formData`), `app/Http/Controllers/Api/V1/EnvelopeController.php` (validação de `signers.*.role`), `app/Api/Presenter.php`, `app/Api/OpenApiSpec.php`
- Test: `tests/Feature/WitnessUiApiTest.php`

**Interfaces:**
- Consumes: regras da Tarefa 2.
- Produces: formulário com seção **"Testemunhas (opcional)"** (campos `witnesses[i][name|email|cpf|phone]`); API aceita `signers[].role` (`signer` | `witness`, padrão `signer`) e devolve `role`, `is_sender` e `signed_via` em cada signatário; lista de posicionamento do PDF inclui as testemunhas.

- [ ] **Step 1: Write the failing tests** em `tests/Feature/WitnessUiApiTest.php`:
  - `it('o formulário tem a seção Testemunhas (opcional) com adicionar e remover')`: `GET /envelopes/novo` vê "Testemunhas (opcional)" e o botão "Adicionar testemunha".
  - `it('cria o envelope com testemunhas e mostra o papel na página')`: `POST /envelopes` com 1 signatário e 2 `witnesses` → 3 signers, 2 com `role = witness`; a página do envelope mostra "Testemunha".
  - `it('a API aceita role witness e devolve o papel')`: `POST /api/v1/envelopes` com `signers[1].role = witness` → resposta com `role: "witness"`, `is_sender: false`; `role: "approver"` → 422 no formato `{error, message, errors}`.
  - `it('o OpenAPI documenta role e os campos novos')`: `GET /api/v1/openapi.json` contém `witness`, `is_sender` e `signed_via`.
- [ ] **Step 2: Run** `php artisan test tests/Feature/WitnessUiApiTest.php` — Expected: FAIL.
- [ ] **Step 3: Implement** o repeater (mesmo padrão do de signatários, JS incluído; mesclar `signers` + `witnesses` no controller como lista única com `role`), validação de `witnesses` (máx. `config('signing.max_witnesses')`, e-mail obrigatório por item), coluna "Papel" na tabela de signatários da `show`, `Presenter` e `OpenApiSpec`. Fluxo por **modelo** (`TemplateController`) fica só com signatários nesta fase (registrar no ledger).
- [ ] **Step 4: Run** o teste e `php artisan test` (inclui o teste que exige toda rota documentada) — Expected: PASS.
- [ ] **Step 5: Commit** `git add -A && git commit -m "Testemunhas no formulário, na página e na API"`.

---

### Task 4: Papel no relatório e no carimbo

**Files:**
- Modify: `app/Signing/ReportBuilder.php` (rótulo e ordem), `sealer/report.py` (subtítulo "Testemunhas")
- Test: o arquivo de teste existente do `ReportBuilder` (`grep -rl ReportBuilder tests`), `sealer/tests/test_report.py`

**Interfaces:**
- Consumes: `signers.role`, `signers.is_sender` (Tarefa 1).
- Produces: `ReportBuilder` devolve os signatários na ordem remetente, signatários, testemunhas, com `role` = `remetente` | `signatário` | `testemunha`; o relatório do sealer separa as testemunhas sob o subtítulo **"Testemunhas"**.

- [ ] **Step 1: Write the failing tests:** no teste do `ReportBuilder`, um envelope com remetente, signatário e 2 testemunhas gera `signers` com os `role` acima, nessa ordem; em `sealer/tests/test_report.py`, um relatório com uma testemunha gera PDF cujo texto contém "Testemunhas" (seguir o jeito que os testes existentes extraem texto).
- [ ] **Step 2: Run** `php artisan test --filter=ReportBuilder` e `pytest sealer/tests/test_report.py` (venv: `C:/Users/User/AppData/Local/Temp/claude/74ce2e37c3b1b6f3/35d6713c-a6f4-4697-a55d-a8a56e49ffd8/scratchpad/sealer-venv/Scripts/python.exe -m pytest`) — Expected: FAIL.
- [ ] **Step 3: Implement** o rótulo (`remetente` se `is_sender`, `testemunha` se `witness`, senão `signatário`) e a ordenação; no `report.py`, imprimir o subtítulo antes da primeira testemunha sem alterar o layout dos demais.
- [ ] **Step 4: Run** os dois conjuntos de testes — Expected: PASS.
- [ ] **Step 5: Commit** `git add -A && git commit -m "Relatório e carimbo identificam remetente, signatários e testemunhas"`.

---

### Task 5: Núcleo do remetente que assina junto

**Files:**
- Create: `app/Envelopes/SenderSignature.php`, `app/Envelopes/SenderSigner.php`, `app/Envelopes/SenderPolicy.php`
- Modify: `app/Envelopes/SigningService.php` (extrair `recordSignature`), `app/Envelopes/EnvelopeService.php` (`create` com `options['sender']`, `send` sem token para o remetente, `sendAndSignAsSender`)
- Test: `tests/Feature/SenderSigningTest.php`

**Interfaces:**
- Consumes: `Signer::isSender()`, `tenant_signing_settings.sender_policy` (Tarefa 1); `SigningService::sign` e o corpo da transação de assinatura.
- Produces:
  - `final readonly class SenderSignature { public function __construct(public string $type /* drawn|typed */, public string $data, public string $method /* session|api_key */, public ?int $userId = null, public ?string $apiKeyId = null) }`
  - `SigningService::recordSignature(Signer $signer, string $type, string $data, ?RequestContext $context = null, array $evidence = []): void` — contém a transação, `Signature`, evento `document_signed` (com `$evidence` mesclado ao payload), conclusão/selagem, webhook e convite do próximo; `sign()` passa a chamá-lo depois das suas checagens (comportamento dos testes atuais não muda).
  - `SenderSigner::apply(Envelope $envelope, SenderSignature $signature, ?RequestContext $context = null): void` — acha o signatário `is_sender` pendente, grava `signed_via = $signature->method`, chama `recordSignature` com `['method' => ..., 'user_id' => ..., 'api_key_id' => ...]`, **sem** `pendingSteps`.
  - `EnvelopeService::create(...)`: `options['sender'] = ['name' => string, 'email' => string, 'cpf' => ?string]` cria o signatário `is_sender = true`, `role = signer`, `position = 0`, sem `token`; `$signers` pode ser vazio quando há remetente; `signature_fields[].signer = 'sender'` posiciona a assinatura dele; os índices numéricos continuam contando só `$signers`.
  - `EnvelopeService::sendAndSignAsSender(Envelope $envelope, SenderSignature $signature, ?RequestContext $context = null, ?int $validDays = null): array` — `send()` (sem token e sem convite para o remetente; `invited_at` preenchido) seguido de `SenderSigner::apply`; devolve os tokens dos demais.
  - `SenderPolicy::policy(Tenant $tenant): string`; `SenderPolicy::wantsSenderSignature(Tenant $tenant, ?bool $requested): bool` (`always` + `false` → `FlowException` `sender_signature_required`; `never` + `true` → `sender_signature_disabled`; padrão sem pedido: `always` → `true`, `optional` e `never` → `false`).

- [ ] **Step 1: Write the failing tests** em `tests/Feature/SenderSigningTest.php` (`Queue::fake()`, `Mail::fake()`):
  - `it('o remetente assina ao liberar, sem verificações, e o próximo da fila é convidado')`: envelope sequencial com sender + 1 signatário e `require_selfie = true`; `sendAndSignAsSender` com `SenderSignature('typed','Empresa X','session',userId)` → remetente `Signed` com linha em `signatures`, `signed_via = 'session'`, nenhum evento `otp_*`, `selfie_*` nem `document_viewed` do remetente, evento `document_signed` com `method = 'session'` e `user_id`; `activeSigners()` = só o outro signatário, que ainda exige selfie (`pendingSteps` contém `selfie`); status `in_progress`.
  - `it('envelope só do remetente conclui e sela na hora')`: sem outros signatários → status `sealing` e `SealEnvelope` despachado.
  - `it('o remetente não tem link público')`: `token_hash` e `token_encrypted` nulos; `findByToken` de qualquer token não devolve o remetente.
  - `it('a posição da assinatura do remetente é gravada')`: `signature_fields` com `signer = 'sender'` cria o campo no signatário `is_sender`.
  - `it('a política decide quem assina')`: padrão `always` → `wantsSenderSignature(t, null) === true`; `(t, false)` → `FlowException` `sender_signature_required`; `optional` → padrão `false`, `true` aceito; `never` + `true` → `sender_signature_disabled`.
  - `it('o remetente sempre vem primeiro em sequencial')`: `position` do remetente 0 e menor que a dos demais.
- [ ] **Step 2: Run** `php artisan test tests/Feature/SenderSigningTest.php` — Expected: FAIL.
- [ ] **Step 3: Implement** na ordem: extrair `recordSignature` (rodar `php artisan test` para provar que nada quebrou), depois `SenderPolicy`, `SenderSignature`, `SenderSigner`, e por fim `create`/`send`/`sendAndSignAsSender`. O evento do remetente usa o mesmo tipo `document_signed`; a prova mostra `method`, IP e aparelho do `RequestContext`.
- [ ] **Step 4: Run** o teste e `php artisan test` — Expected: PASS.
- [ ] **Step 5: Commit** `git add -A && git commit -m "Remetente assina junto: política, assinatura sem verificações e envio atômico"`.

---

### Task 6: "Minha assinatura" e o remetente no painel web

**Files:**
- Modify: `app/Http/Controllers/Web/ProfileController.php`, `routes/web.php`, `resources/views/profile/edit.blade.php`, `resources/views/envelopes/create.blade.php`, `app/Http/Controllers/Web/EnvelopeController.php` (`store`, `send`)
- Test: `tests/Feature/MySignatureAndSenderWebTest.php`

**Interfaces:**
- Consumes: `SenderPolicy`, `SenderSignature`, `EnvelopeService::create(... options['sender'])`, `sendAndSignAsSender` (Tarefa 5); `users.signature_type/signature_data` (Tarefa 1).
- Produces: rotas `profile.signature.update` (PUT `/perfil/assinatura`) e `profile.signature.destroy` (DELETE `/perfil/assinatura`); `User::hasSignature(): bool`; formulário de criar com a linha **"Eu (remetente)"**.

- [ ] **Step 1: Write the failing tests** em `tests/Feature/MySignatureAndSenderWebTest.php`:
  - `it('salva a assinatura desenhada (PNG) e a digitada')`: PNG válido ≤ 200 KB em data URL e nome digitado ≤ 80 caracteres são aceitos; PNG corrompido, imagem de outro tipo e data URL gigante são recusados com erro em `signature`; `DELETE` limpa os dois campos.
  - `it('a assinatura de um usuário não aparece para outra empresa')`: a página de perfil e as respostas nunca trazem `signature_data` de outro usuário.
  - `it('o formulário mostra a linha Eu (remetente) fixa quando a política é always')`; `it('com optional vira caixa marcada e com never não aparece')`.
  - `it('sem Minha assinatura salva a criação é recusada com link para o perfil')`: `POST /envelopes` com política `always` e usuário sem assinatura → erro "Crie a sua assinatura em Meu perfil" e nenhum envelope criado.
  - `it('criar e liberar assina na hora e conclui o primeiro passo')`: usuário com assinatura salva cria e libera (`POST /envelopes/{id}/enviar`) → remetente `Signed` com `signed_via = 'session'`, e os demais convidados; a assinatura vem de `Minha assinatura`.
  - `it('o posicionamento aceita o remetente')`: `signature_fields[0][signer] = sender` cria o campo no signatário `is_sender`.
- [ ] **Step 2: Run** `php artisan test tests/Feature/MySignatureAndSenderWebTest.php` — Expected: FAIL.
- [ ] **Step 3: Implement** a seção "Minha assinatura" no perfil (desenhar em canvas com o mesmo script do pad da tela de assinatura, ou digitar o nome; validar PNG com `getimagesizefromstring`), a linha do remetente no formulário (e "Eu (remetente)" na lista do posicionamento), `store` montando `options['sender']` com nome e e-mail do usuário quando `SenderPolicy::wantsSenderSignature` for verdadeiro, e `send` chamando `sendAndSignAsSender` com `SenderSignature(type, data, 'session', userId)` quando o envelope tem remetente.
- [ ] **Step 4: Run** o teste e `php artisan test` — Expected: PASS.
- [ ] **Step 5: Commit** `git add -A && git commit -m "Minha assinatura e remetente que assina ao liberar no painel"`.

---

### Task 7: Assinatura da empresa pela API (representante, termo e chave)

**Files:**
- Create: `app/Http/Controllers/Web/CompanySigningController.php`, `resources/views/company/signing.blade.php`
- Modify: `routes/web.php`, `resources/views/layouts/partials/sidebar.blade.php` (item "Assinatura da empresa" em Configurações), `app/Models/ApiKey.php` (`issue`), `app/Http/Controllers/Web/ApiKeyController.php`, `resources/views/api-keys/index.blade.php`, `app/Http/Controllers/Api/V1/EnvelopeController.php` (`store`, `send`), `app/Http/Controllers/Api/V1/MeController.php`, `app/Api/OpenApiSpec.php`
- Test: `tests/Feature/CompanyApiSenderTest.php`

**Interfaces:**
- Consumes: `SenderPolicy`, `SenderSignature`, `sendAndSignAsSender`, `create(... options['sender'])` (Tarefa 5); campos de `tenant_signing_settings` e `api_keys.can_sign_as_company` (Tarefa 1).
- Produces:
  - `ApiKey::issue(Tenant $tenant, string $name, bool $readOnly = false, bool $canSignAsCompany = false): array`.
  - Rotas `company.signing.edit` (GET `/configuracoes/assinatura-da-empresa`) e `company.signing.update` (PUT, só `role = owner`): política, representante (nome, e-mail, CPF) e o aceite do termo, que grava `delegation_accepted_at/by/ip`; **trocar o representante apaga o aceite** (exige novo).
  - API `POST /envelopes`: campo `sender_signs` (bool). Se a empresa deve assinar (`SenderPolicy::wantsSenderSignature`): chave sem `can_sign_as_company` → 422 `sender_signature_not_allowed`; sem representante ou sem aceite → 422 `no_company_representative`; **validar antes de criar** o envelope. O signatário `is_sender` leva nome/e-mail/CPF do representante.
  - API `POST /envelopes/{id}/send`: se há remetente pendente, `sendAndSignAsSender` com `SenderSignature('typed', <nome do representante>, 'api_key', null, <id da chave>)`, **reconferindo** a permissão da chave usada no envio.
  - `GET /me` informa `can_sign_as_company`.

- [ ] **Step 1: Write the failing tests** em `tests/Feature/CompanyApiSenderTest.php` (usar o helper de chave de `ApiIntegrationTest`: `ApiKey::issue($tenant, 'k', false, $can)` e cabeçalho `Authorization: Bearer`):
  - `it('chave sem permissão recusa com 422 e não cria envelope')`: política `always`, representante e aceite ok, chave `can_sign_as_company = false` → 422 `sender_signature_not_allowed`; `Envelope::count() === 0`.
  - `it('sem representante ou sem aceite recusa com no_company_representative e não cria envelope')` (dois casos).
  - `it('com tudo configurado cria, e o envio assina pela chave')`: `POST /envelopes` → 201 com um signatário `is_sender`; `POST /envelopes/{id}/send` → remetente `Signed`, `signed_via = 'api_key'`, evento `document_signed` com `method = 'api_key'`, `api_key_id` e IP.
  - `it('sender_signs=false só vale com política optional')`: `always` + `false` → 422 `sender_signature_required`; `optional` + `false` → sem signatário `is_sender`.
  - `it('o envio reconfere a permissão da chave')`: envelope criado com a chave A (permitida) e enviado com a chave B sem permissão → 422 e o envelope continua rascunho.
  - `it('isolamento entre empresas')`: a chave da empresa B nunca usa o representante da empresa A (a B sem representante recebe `no_company_representative`).
  - `it('trocar o representante apaga o aceite do termo')` e `it('só o owner altera a assinatura da empresa')`.
  - `it('o painel emite chave com a permissão e mostra o aviso')`: `POST /api-keys` com `can_sign_as_company = 1` cria a chave com a flag; a listagem mostra "Assina pela empresa".
  - `it('o OpenAPI e o /me expõem o campo novo')`.
- [ ] **Step 2: Run** `php artisan test tests/Feature/CompanyApiSenderTest.php` — Expected: FAIL.
- [ ] **Step 3: Implement** a tela (`company.signing.*`) com texto-base do termo de delegação (revisão final do advogado, registrar no ledger), o item de menu, a flag na chave (checkbox com aviso "use só em chaves dos seus sistemas"), a lógica da API (validação antes de criar; `send` com reconferência) e a documentação OpenAPI dos códigos de erro e do campo `sender_signs`.
- [ ] **Step 4: Run** o teste e `php artisan test` — Expected: PASS.
- [ ] **Step 5: Commit** `git add -A && git commit -m "Assinatura da empresa pela API: representante, termo de delegação e permissão da chave"`.

---

### Task 8: Verificação ponta a ponta, documentação e entrega

**Files:**
- Modify: `docs/ESCOPO.md` (Fase 1b entregue), `docs/superpowers/specs/2026-11-14-remetente-e-testemunhas-design.md` (status), `docs/ESCOPO.md` seção 15 (API: `sender_signs`, `role`, `can_sign_as_company`)

**Interfaces:** consome todas as tarefas anteriores.

- [ ] **Step 1:** `php artisan test` completo e `pytest sealer/tests` — Expected: tudo passa.
- [ ] **Step 2:** Subir o preview (`laravel`), criar empresa de teste e usuário; salvar **Minha assinatura**; criar um documento com 1 signatário e 1 testemunha, posicionar a assinatura do remetente, liberar; conferir que o remetente já aparece como assinado e que só o signatário recebe convite; assinar como signatário e depois como testemunha pelos links; conferir que o status só passa a `sealing` ao final. Se o sealer local estiver disponível (venv `uvicorn` com `SEALER_TOKEN` e `SIGNING_ALLOW_LOCAL_TEST=true`), rodar a fila e abrir o PDF selado: relatório com remetente, signatário e subtítulo "Testemunhas".
- [ ] **Step 3:** Auditoria de contraste (≥ 4,5:1) nas páginas novas (`/envelopes/novo`, `/perfil`, `/configuracoes/assinatura-da-empresa`, `/api-keys`) nos dois temas.
- [ ] **Step 4:** Atualizar a documentação e fazer o commit final seguido do push: `git add -A && git commit -m "Fase 1b entregue: remetente que assina junto e testemunhas" && git push origin main`.

---

## Self-review

**Cobertura do spec (seções 4 a 7):** política por empresa e remetente sempre primeiro → T5/T6; "Minha assinatura" → T6; assinatura sem selfie/código com prova `session`/`api_key` → T5; posicionamento do remetente → T5/T6; testemunhas (repeater, ordem, conclusão, recusa, limite, relatório/carimbo, API/webhooks) → T2/T3/T4; representante, termo, permissão da chave, 422 e `sender_signs` → T7; dados novos → T1; testes obrigatórios da seção 7 → distribuídos nas tarefas. Lacuna conhecida e decidida: documento gerado por **modelo** não ganha testemunhas nem remetente nesta fase (registrar no ledger).

**Decisões do plano que não estavam no spec (minhas):** a assinatura do remetente acontece ao **liberar** (não ao criar o rascunho), inclusive na API (`/send`); a `SigningService::recordSignature` é extraída para reaproveitar a conclusão; só `owner` edita a assinatura da empresa; o remetente da API assina com o **nome digitado** do representante; o texto do termo é uma base a revisar pelo advogado.

**Consistência de nomes:** `is_sender`, `signed_via`, `sender_policy`, `can_sign_as_company`, `Envelope::PARTICIPANT_ROLES`, `SenderSignature`, `SenderSigner::apply`, `SenderPolicy::{policy,wantsSenderSignature}`, `SigningService::recordSignature`, `EnvelopeService::sendAndSignAsSender`, códigos `invalid_role`, `too_many_witnesses`, `no_signers`, `sender_signature_required`, `sender_signature_disabled`, `sender_signature_not_allowed`, `no_company_representative` — iguais em todas as tarefas.

**Proporção:** tarefas dão assinaturas, testes e valores do spec; os corpos ficam para quem implementa.
