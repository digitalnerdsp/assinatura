# Fase 2 — Contatos, Relatórios, Calendário e Usuários Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Entregar os módulos de gestão do escopo: Contatos (com preenchimento automático e CSV/API), Relatórios (indicadores, tempo de assinatura por faixas, filtros e CSV), Calendário (mês/semana a partir dos envelopes) e Usuários da empresa (convite, desativar, remover).

**Architecture:** Cada módulo tem um serviço próprio (`ContactService`, `ReportService`, `CalendarService`, `TeamService`) com consultas sempre filtradas pela empresa, e telas Blade no padrão atual (contraste ≥ 4,5:1, alvos de 44 px, popups em tela cheia no celular). O Painel passa a usar os números do `ReportService`. Convite de usuário usa token próprio com hash e validade.

**Tech Stack:** PHP 8.4, Laravel 13, Pest 4, PostgreSQL, Blade + Bootstrap 5, JS puro.

**Spec:** `docs/superpowers/specs/2026-11-13-menu-modulos-planos-design.md` (seção 5 e a Fase 2 da seção 8).

## Global Constraints

- Tudo filtrado por `tenant_id`; recurso de outra empresa → 404.
- Contato: e-mail **único por empresa sem diferenciar maiúsculas**; telefone guardado em E.164 (`App\Messaging\Phone::normalize`); CPF/CNPJ só dígitos com até 14; nome até 150; empresa até 150; observação até 1000.
- Signatários e testemunhas usados num envelope viram contatos automaticamente (nunca o remetente; sem duplicar por e-mail; não sobrescreve dados que o cliente já editou, só preenche campos vazios).
- Faixas de tempo de assinatura (do envio até a conclusão): `< 30 min`, `< 1 h`, `< 24 h`, `< 2 dias`, `< 5 dias`, `< 10 dias`, `10 dias ou mais`.
- Calendário: fonte são os envelopes (sem tabela nova); eventos `prazo` (`expires_at` de envelope aberto), `criado`, `concluido`, `lembrete` (próximo lembrete previsto).
- Usuários: só o `owner` gerencia; ninguém remove ou desativa a si mesmo nem o `owner`; usuário desativado não entra e é desconectado na próxima requisição; convite vale 7 dias e o token só é guardado como hash.
- Menu: **Contatos**, **Calendário** e **Relatórios** como itens próprios (depois de Modelos); **Usuários** dentro de Configurações.
- Textos em pt-BR; testes Pest com `uses(RefreshDatabase::class)`; nenhum teste usa a internet; commits por tarefa; push ao fim, **com a suíte verde conferida pelo código de saída**.

## Review Focus

1. **Outra empresa:** contatos, relatórios, calendário e usuários nunca mostram nem alteram dados de outra empresa (inclusive filtros com ids alheios e importação CSV). (Todas as tarefas.)
2. **CSV:** importação com cabeçalhos diferentes, linhas inválidas, e-mails repetidos, arquivo grande e **injeção de fórmula** na exportação (`=`, `+`, `-`, `@` no início) não quebram nem perdem dados. (Tarefas 2 e 3.)
3. **Usuários:** convite expirado/reutilizado, e-mail já cadastrado em outra empresa, desativar a si mesmo ou o dono, sessão aberta de quem foi desativado. (Tarefa 5.)
4. **Números do relatório:** documentos na lixeira não contam; o tempo médio e as faixas usam só concluídos com `sent_at`; filtros por pasta incluem subpastas; o Painel bate com o Relatório. (Tarefa 3.)
5. **Celular:** calendário em 375 px vira lista por dia sem rolagem horizontal; popups em tela cheia; tabelas viram cartões. (Tarefas 4 e 6.)

---

### Task 1: Contatos — cadastro, tela e criação automática

**Files:**
- Create: `database/migrations/2026_11_17_000001_create_contacts_table.php`, `app/Models/Contact.php`, `app/Contacts/ContactService.php`, `app/Http/Controllers/Web/ContactController.php`, `resources/views/contacts/index.blade.php`
- Modify: `app/Envelopes/EnvelopeService.php` (`create` chama `ContactService::rememberFromSigners`), `routes/web.php`, `resources/views/layouts/partials/sidebar.blade.php`
- Test: `tests/Feature/ContactTest.php`

**Interfaces:**
- Produces: tabela `contacts` (`id` uuid, `tenant_id`, `name`, `email`, `phone` nulo, `document` nulo, `company` nulo, `notes` nulo, timestamps; índice único `(tenant_id, lower(email))` no PostgreSQL) e pivô `contact_tag` (`contact_id`, `tag_id`, cascata); `Contact::tags()`.
- `ContactService::save(Tenant $tenant, array $data, ?Contact $contact = null): Contact` (valida/normaliza; `FlowException` `contact_email_taken`, `invalid_phone`), `delete(Contact $c): void`, `rememberFromSigners(Tenant $tenant, array $people): void`, `search(Tenant $tenant, string $q, int $limit = 8): Collection`.
- Rotas: `contacts.index` (GET `/contatos`, busca `q` e `tag`), `contacts.store` (POST), `contacts.update` (PUT `/contatos/{contact}`), `contacts.destroy` (DELETE).

- [ ] **Step 1: Write the failing tests** (`tests/Feature/ContactTest.php`): criar, editar e apagar contato pela tela; e-mail repetido (maiúsculas diferentes) recusado; telefone normalizado para E.164 e telefone inválido recusado; CPF/CNPJ guardado só com dígitos; busca por nome/e-mail/empresa e filtro por etiqueta; contato de outra empresa → 404 em editar/apagar; ao criar envelope com 1 signatário + 1 testemunha, os dois viram contatos (o remetente não); repetir o mesmo e-mail não duplica e não sobrescreve nome editado, mas preenche telefone vazio; apagar contato não altera o envelope já criado.
- [ ] **Step 2: Run** `php artisan test tests/Feature/ContactTest.php` — Expected: FAIL.
- [ ] **Step 3: Implement** migração, model, serviço, controller, tela (lista com busca, chips de etiqueta, popup em tela cheia no celular para criar/editar, confirmação de apagar), rotas e item **Contatos** no menu.
- [ ] **Step 4: Run** o teste e `php artisan test` — Expected: PASS.
- [ ] **Step 5: Commit** `git commit -m "Contatos: cadastro, tela e criação automática a partir dos signatários"`.

---

### Task 2: Contatos — preenchimento no formulário, CSV e API

**Files:**
- Create: `app/Contacts/ContactCsv.php`, `app/Http/Controllers/Api/V1/ContactController.php`
- Modify: `app/Http/Controllers/Web/ContactController.php` (`suggest`, `import`, `export`), `resources/views/envelopes/create.blade.php` (sugestões nos campos de signatário/testemunha), `resources/views/contacts/index.blade.php`, `routes/web.php`, `routes/api.php`, `app/Api/Presenter.php`, `app/Api/OpenApiSpec.php`
- Test: `tests/Feature/ContactCsvApiTest.php`

**Interfaces:**
- Rotas web: `contacts.suggest` (GET `/contatos/sugestoes?q=` → JSON `[{name,email,phone}]`, no máximo 8, só da empresa), `contacts.import` (POST `/contatos/importar`, arquivo `.csv` até 2 MB), `contacts.export` (GET `/contatos/exportar` → `contatos.csv`).
- `ContactCsv::import(Tenant $tenant, string $path): array{created:int,updated:int,skipped:int,errors:list<string>}` (cabeçalhos aceitos sem diferenciar maiúsculas/acentos: `nome|name`, `e-mail|email`, `telefone|phone`, `cpf|cnpj|documento`, `empresa|company`, `observacao|notes`; separador `;` ou `,`; UTF-8 com ou sem BOM; até 5000 linhas), `export(Tenant $tenant): string` (UTF-8 com BOM, `;`, células que começam com `= + - @` ganham `'` na frente).
- API: `GET /contacts` (busca `q`, paginação), `POST /contacts`, `GET|PATCH|DELETE /contacts/{id}`; `Presenter::contact`; OpenAPI completo.

- [ ] **Step 1: Write the failing tests:** sugestões só da empresa e no máximo 8; importar CSV com `;` e com `,`, BOM, cabeçalhos em português e inglês, linha sem e-mail ignorada com erro listado, e-mail repetido atualiza campos vazios; arquivo que não é CSV recusado; exportar com BOM e com proteção contra fórmula (`=SOMA(1)` sai como `'=SOMA(1)`); API CRUD, 404 de outra empresa, chave somente leitura bloqueada em escrita, rotas no OpenAPI.
- [ ] **Step 2: Run** `php artisan test tests/Feature/ContactCsvApiTest.php` — Expected: FAIL.
- [ ] **Step 3: Implement** o serviço de CSV, as rotas, as sugestões no formulário de criar documento (lista abaixo do campo ao digitar nome ou e-mail; tocar preenche nome, e-mail e telefone; funciona por toque), botões Importar/Exportar na tela e a API.
- [ ] **Step 4: Run** o teste e `php artisan test` — Expected: PASS.
- [ ] **Step 5: Commit** `git commit -m "Contatos: sugestões ao criar documento, importar/exportar CSV e API"`.

---

### Task 3: Relatórios (e o Painel com os mesmos números)

**Files:**
- Create: `database/migrations/2026_11_17_000002_add_template_to_envelopes.php`, `app/Reports/ReportService.php`, `app/Http/Controllers/Web/ReportController.php`, `resources/views/reports/index.blade.php`
- Modify: `app/Http/Controllers/Web/TemplateController.php` e `app/Http/Controllers/Api/V1/TemplateController.php` (gravam `template_id`), `app/Envelopes/EnvelopeService.php` (`options['template_id']`), `app/Http/Controllers/Web/DashboardController.php`, `routes/web.php`, sidebar
- Test: `tests/Feature/ReportTest.php`

**Interfaces:**
- `envelopes.template_id` (uuid nulo, FK `document_templates`, `nullOnDelete`).
- `ReportService::summary(Tenant $tenant, array $filters): array{total:int,completed:int,in_progress:int,refused:int,expired:int,cancelled:int,avg_seconds:?int,buckets:array<string,int>}` e `rows(Tenant $tenant, array $filters): Builder`; filtros: `from`, `to` (datas de criação, inclusivas), `folder` (inclui subpastas), `tag`, `template`, `signer` (e-mail exato, sem diferenciar maiúsculas); ids de outra empresa são ignorados (resultado vazio).
- `ReportService::BUCKETS` com as chaves `30m`, `1h`, `24h`, `2d`, `5d`, `10d`, `more`.
- Rotas: `reports.index` (GET `/relatorios`), `reports.export` (GET `/relatorios/exportar` → CSV com a mesma proteção contra fórmula).

- [ ] **Step 1: Write the failing tests:** totais por status (lixeira fora); tempo médio e faixas só com concluídos que têm `sent_at` (casos de 10 min, 2 h, 3 dias, 12 dias caem nas faixas certas); cada filtro (período, pasta com subpasta, etiqueta, modelo, signatário) muda o resultado como esperado e ids de outra empresa dão vazio; CSV com cabeçalho e proteção de fórmula; o Painel mostra o mesmo total e os mesmos concluídos do relatório; gerar documento por modelo grava `template_id`.
- [ ] **Step 2: Run** `php artisan test tests/Feature/ReportTest.php` — Expected: FAIL.
- [ ] **Step 3: Implement** a migração, o serviço, a tela (cartões de indicadores, barra de faixas com legenda e números, filtros, tabela que vira cartões no celular, exportar) e o Painel usando `summary`.
- [ ] **Step 4: Run** o teste e `php artisan test` — Expected: PASS.
- [ ] **Step 5: Commit** `git commit -m "Relatórios: indicadores, tempo de assinatura por faixas, filtros e CSV; Painel com os mesmos números"`.

---

### Task 4: Calendário

**Files:**
- Create: `app/Calendar/CalendarService.php`, `app/Http/Controllers/Web/CalendarController.php`, `resources/views/calendar/index.blade.php`
- Modify: `routes/web.php`, sidebar, `public/assets/css/drive.css` (estilos do calendário)
- Test: `tests/Feature/CalendarTest.php`

**Interfaces:**
- `CalendarService::events(Tenant $tenant, CarbonImmutable $from, CarbonImmutable $to): Collection` de `['date' => 'Y-m-d', 'type' => 'prazo'|'criado'|'concluido'|'lembrete', 'envelope_id', 'title']` em `America/Sao_Paulo`, sem documentos da lixeira; `lembrete` = próximo lembrete previsto do envelope aberto (menor `coalesce(last_reminded_at, invited_at) + reminder_every_days` entre os signatários pendentes ativos, se `reminder_every_days` não for nulo e não passou de `config('signing.max_reminders')`).
- Rota `calendar.index` (GET `/calendario?view=month|week&date=Y-m-d`), navegação anterior/próximo/hoje.

- [ ] **Step 1: Write the failing tests:** mês mostra os quatro tipos nos dias certos (fuso de São Paulo, incluindo evento perto da meia-noite UTC); semana mostra só os 7 dias; envelope de outra empresa e da lixeira não aparecem; prazo só para envelope aberto; lembrete previsto calculado e omitido quando `reminder_every_days` é nulo; parâmetros inválidos caem no mês atual sem erro; cada evento leva ao envelope.
- [ ] **Step 2: Run** `php artisan test tests/Feature/CalendarTest.php` — Expected: FAIL.
- [ ] **Step 3: Implement** o serviço, o controller e a tela (grade de mês e de semana com cores e legenda por tipo; abaixo de 576 px vira lista por dia; hoje destacado; alvos de 44 px).
- [ ] **Step 4: Run** o teste e `php artisan test` — Expected: PASS.
- [ ] **Step 5: Commit** `git commit -m "Calendário de prazos, criações, conclusões e lembretes"`.

---

### Task 5: Usuários da empresa (convite, desativar, remover)

**Files:**
- Create: `database/migrations/2026_11_17_000003_add_team_fields_to_users.php`, `app/Team/TeamService.php`, `app/Mail/TeamInviteMail.php`, `resources/views/mail/team-invite.blade.php`, `app/Http/Controllers/Web/TeamController.php`, `app/Http/Controllers/Web/InviteController.php`, `resources/views/team/index.blade.php`, `resources/views/team/accept.blade.php`
- Modify: `app/Models/User.php`, `app/Http/Controllers/Web/AuthController.php` (login bloqueia desativado), `app/Http/Middleware/EnsureTenantActive.php` (desconecta desativado), `routes/web.php`, sidebar
- Test: `tests/Feature/TeamTest.php`

**Interfaces:**
- `users.deactivated_at` (nulo), `users.invite_token_hash` (64, nulo, único), `users.invite_expires_at` (nulo), `users.invited_by` (FK nula). `User::isActive()`, `User::isPendingInvite()`.
- `TeamService::invite(User $owner, string $name, string $email): array{0:User,1:string}` (token em texto puro só para o e-mail; e-mail já usado em qualquer empresa → `FlowException` `email_taken`), `accept(string $token, string $password): User` (`invite_invalid` para token inexistente, expirado ou já usado), `deactivate/reactivate/remove(User $owner, User $member)` (`cannot_change_self`, `cannot_change_owner`, `other_tenant`).
- Rotas: `team.index` (GET `/configuracoes/usuarios`, só owner vê ações), `team.invite` (POST), `team.resend` (POST `/configuracoes/usuarios/{user}/reenviar`), `team.deactivate`, `team.reactivate`, `team.destroy`; públicas `invite.show` (GET `/convite/{token}`) e `invite.accept` (POST), com limite de tentativas.

- [ ] **Step 1: Write the failing tests:** o owner convida, o e-mail sai com link, o convidado define a senha e entra na mesma empresa como `member`; token errado, expirado ou já usado recusado; e-mail já cadastrado (mesmo de outra empresa) recusado; reenviar gera token novo e invalida o anterior; desativar impede login e derruba sessão aberta; reativar volta; remover apaga o usuário e mantém os envelopes (com `created_by` nulo); owner não pode desativar/remover a si nem outro owner; member não vê as ações (403); usuário de outra empresa → 404.
- [ ] **Step 2: Run** `php artisan test tests/Feature/TeamTest.php` — Expected: FAIL.
- [ ] **Step 3: Implement** migração, serviço, e-mail, telas (lista com situação: ativo, convite pendente, desativado; popup de convidar em tela cheia no celular), página pública de aceite, bloqueio no login e no middleware, item **Usuários** em Configurações.
- [ ] **Step 4: Run** o teste e `php artisan test` — Expected: PASS.
- [ ] **Step 5: Commit** `git commit -m "Usuários da empresa: convite por e-mail, desativar, reativar e remover"`.

---

### Task 6: Verificação no navegador, documentação e entrega

**Files:**
- Modify: `docs/ESCOPO.md` (Fase 2 entregue e API de contatos), `docs/superpowers/specs/2026-11-13-menu-modulos-planos-design.md` (status da Fase 2)

- [ ] **Step 1:** `php artisan test` completo com o código de saída conferido — Expected: `exit=0`.
- [ ] **Step 2:** No navegador, com dados de teste: Contatos (criar, editar, importar/exportar, sugestão ao criar documento), Relatórios (filtros e exportação), Calendário (mês, semana, navegação), Usuários (convidar e aceitar pelo link do log de e-mail, desativar). Em **375 px** e **768 px**, nos dois temas: sem rolagem horizontal, popups em tela cheia no celular, alvos ≥ 44 px, calendário em lista no celular.
- [ ] **Step 3:** Auditoria de contraste (≥ 4,5:1) nas quatro telas novas, nos dois temas, incluindo o conteúdo dos popups.
- [ ] **Step 4:** Atualizar a documentação, commit e push (só com a suíte verde).

---

## Self-review

**Cobertura do spec (seção 5):** Contatos (cadastro, etiquetas, criação automática, preenchimento, CSV, API, LGPD) → T1/T2; Calendário (mês/semana, quatro tipos, clique abre envelope, sem tabela nova) → T4; Relatórios (indicadores, tempo médio e faixas, filtros incluindo modelo e signatário, CSV, Painel com os mesmos números) → T3; Usuários (listar, convidar, desativar, remover) → T5. Fora desta fase (spec): feed ICS, compromissos manuais, papéis de usuário.

**Decisões deste plano (minhas):** contato automático nunca sobrescreve campo editado; convite válido por 7 dias com token em hash; e-mail de usuário único no sistema todo (o login é por e-mail); "modelo" no relatório exige gravar `template_id` nos envelopes daqui para frente (os antigos ficam sem modelo); CSV com `;` e BOM para abrir direto no Excel em português.

**Consistência:** nomes de serviços, rotas, campos e códigos de erro iguais entre as tarefas.
