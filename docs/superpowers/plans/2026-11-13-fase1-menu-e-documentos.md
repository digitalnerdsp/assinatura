# Fase 1 — Roboto local, novo menu e Documentos (Criados, Minhas assinaturas, Lixeira) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Entregar a identidade visual da fase 1 do escopo: fonte Roboto hospedada no servidor, menu lateral no padrão ZapSign, e a seção Documentos com lista, "Minhas assinaturas" e lixeira/arquivo.

**Architecture:** Laravel 13 + Blade (tema Rocker) como hoje. O menu sai do `layouts/app.blade.php` para um partial próprio. Lixeira e arquivo são duas colunas novas em `envelopes` (`trashed_at`, `archived_at`) geridas por um serviço (`TrashService`) com regras de status; "Minhas assinaturas" é uma consulta de leitura sobre `signers`. Nenhuma rota existente muda de URL.

**Tech Stack:** PHP 8.4, Laravel 13, Pest 4, PostgreSQL, Blade + metisMenu (já presente), CSS próprio (`public/assets/css`).

**Spec:** `docs/superpowers/specs/2026-11-13-menu-modulos-planos-design.md` (seções 4, 5 e 10; esta fase é a "Fase 1" da seção 8)

## Global Constraints

- Fonte **Roboto**, pesos 400, 500 e 700, **hospedada em `public/assets/fonts/roboto/`**; nenhuma referência a `fonts.googleapis.com` nas páginas do sistema (inclui páginas públicas de assinatura).
- Todo texto com contraste mínimo **4,5:1** nos temas claro e escuro; menu, títulos, rótulos e cabeçalhos de tabela em **negrito** (regras já em `public/assets/css/contrast.css`).
- Nomes do menu em português: **Documentos** (com **Documentos criados**, **Minhas assinaturas**, **Lixeira**), **Modelos**, **Configurações** (com **Meu perfil**, **Pastas e etiquetas**, **API**, **Webhooks**); botão do topo **Criar documento**; **Central de ajuda** só aparece se o administrador configurar o link.
- **Lixeira de 30 dias** só para envelopes em rascunho, cancelados ou expirados. **Documento assinado e selado nunca é apagado pelo usuário**: só pode ser arquivado.
- Textos da interface em pt-BR; datas em `America/Sao_Paulo`.
- Testes em Pest com `uses(RefreshDatabase::class)` por arquivo; nenhum teste usa a internet.
- Commits por tarefa; o **push é feito uma vez, no fim da fase** (o Coolify faz deploy a cada push), junto do commit final (preferência do dono: commit e push andam juntos).

## Review Focus

1. **E-mail igual em outra empresa:** "Minhas assinaturas" só lista envelopes **da própria empresa** do usuário. Como não há verificação de e-mail no cadastro, mostrar links de assinatura de outras empresas permitiria a qualquer pessoa ler documentos alheios cadastrando o e-mail da vítima. (Teste na Tarefa 4.)
2. **POST forjado:** mover para a lixeira ou arquivar um envelope em andamento, concluído ou de outra empresa deve falhar (erro/404) sem alterar nada. (Tarefas 2 e 3.)
3. **Biometria na limpeza:** ao apagar da lixeira um envelope **expirado**, as selfies e fotos de documento já enviadas por signatários que não terminaram também são apagadas do disco (LGPD). (Tarefa 2.)
4. **Restaurar não reabre:** restaurar um envelope expirado ou cancelado da lixeira não o torna assinável de novo; só volta à lista com o mesmo status. (Tarefa 3.)
5. **Submenu no escuro e no celular:** o item filho ativo mantém o submenu aberto e todo texto do submenu passa em 4,5:1 nos dois temas. (Tarefa 5 e verificação da Tarefa 7.)

---

### Task 1: Roboto hospedada no servidor

**Files:**
- Create: `public/assets/fonts/roboto/roboto-{latin,latin-ext}-{400,500,700}-normal.woff2` (6 arquivos)
- Create: `public/assets/css/typography.css`
- Modify: `resources/views/layouts/partials/head.blade.php` (remove o `<link>` do Google Fonts na linha 12; adiciona `typography.css` logo após `app.css`)
- Test: `tests/Feature/TypographyTest.php`

**Interfaces:**
- Consumes: nada.
- Produces: classe de fonte global (`font-family: Roboto, system-ui, sans-serif` em `body`, `input`, `select`, `textarea`, `button`); base `font-size: 15px`, `line-height: 1.5`.

- [ ] **Step 1: Write the failing test** `tests/Feature/TypographyTest.php` com `uses(RefreshDatabase::class)`:
  - `it('serve a Roboto do próprio servidor e não carrega fontes do Google')`: `get('/entrar')` → `assertOk()`, `assertDontSee('fonts.googleapis.com', false)`, `assertSee('assets/css/typography.css', false)`.
  - `it('inclui os arquivos de fonte nos três pesos')`: para `latin` e `latin-ext` e `400|500|700`, `expect(file_exists(public_path("assets/fonts/roboto/roboto-{$sub}-{$w}-normal.woff2")))->toBeTrue()`; e `expect(file_get_contents(public_path('assets/css/typography.css')))->toContain('font-weight: 700')`.
- [ ] **Step 2: Run** `php artisan test tests/Feature/TypographyTest.php` — Expected: FAIL.
- [ ] **Step 3: Obter as fontes.** Numa pasta temporária fora do projeto: `npm pack @fontsource/roboto@5`, extrair, copiar `files/roboto-latin-{400,500,700}-normal.woff2` e `files/roboto-latin-ext-{400,500,700}-normal.woff2` para `public/assets/fonts/roboto/` (licença Apache 2.0; copiar também o `LICENSE` do pacote para a mesma pasta).
- [ ] **Step 4: Escrever `typography.css`:** um `@font-face` por arquivo (`font-display: swap`, `unicode-range` do subconjunto: latin `U+0000-00FF,U+0131,U+0152-0153,U+02BB-02BC,U+02C6,U+02DA,U+02DC,U+2000-206F,U+20AC,U+2122,U+2191,U+2193,U+2212,U+2215,U+FEFF,U+FFFD`; latin-ext `U+0100-02BA,U+02BD-02C5,U+02C7-02CC,U+02CE-02D7,U+02DD-02FF,U+0304,U+0308,U+0329,U+1D00-1DBF,U+1E00-1E9F,U+1EF2-1EFF,U+2020,U+20A0-20AB,U+20AD-20C0,U+2113,U+2C60-2C7F,U+A720-A7FF`), `url('../fonts/roboto/…')`, e as regras da seção Produces. Em `head.blade.php`, trocar o link do Google por `<link href="{{ asset('assets/css/typography.css') }}?v=1" rel="stylesheet">` logo depois de `app.css` (e antes de `icons.css`, `dark-theme.css`, `contrast.css`).
- [ ] **Step 5: Run** o teste — Expected: PASS. Rodar também `php artisan test` (nada deve quebrar).
- [ ] **Step 6: Commit** `git add -A && git commit -m "Roboto hospedada no servidor (400/500/700) no lugar do Google Fonts"`.

---

### Task 2: Lixeira e arquivo — modelo e serviço

**Files:**
- Create: `database/migrations/2026_11_14_000001_add_trash_and_archive_to_envelopes.php`
- Create: `app/Envelopes/TrashService.php`
- Modify: `app/Models/Envelope.php` (casts + métodos abaixo)
- Modify: `routes/console.php` (comando e agenda)
- Test: `tests/Feature/TrashArchiveTest.php`

**Interfaces:**
- Consumes: `App\Envelopes\Exceptions\FlowException::because(string $code, string $message)` (já existe); `Document` (`storage_path`), `SignerAttachment` (`path`), `Storage::disk('local')`.
- Produces:
  - `envelopes.trashed_at` e `envelopes.archived_at`, `timestampTz` nulos, com índice em `(tenant_id, trashed_at)`.
  - `Envelope::isTrashable(): bool` (status `draft`, `cancelled`, `expired`), `Envelope::isArchivable(): bool` (status `completed`, `refused`), `Envelope::isTrashed(): bool`, `Envelope::isArchived(): bool`, `Envelope::scopeVisible(Builder $q): Builder` (`trashed_at` e `archived_at` nulos).
  - `TrashService::trash(Envelope): void`, `restore(Envelope): void`, `archive(Envelope): void`, `unarchive(Envelope): void` (lançam `FlowException` com código `not_trashable` / `not_archivable` / `not_trashed` / `not_archived`), `purge(int $olderThanDays = 30): int` (devolve quantos apagou).
  - Comando `envelopes:purge-trash` agendado `daily()`.

- [ ] **Step 1: Write the failing tests** em `tests/Feature/TrashArchiveTest.php` (`uses(RefreshDatabase::class)`; criar tenant, documento e envelopes direto pelos models com `Storage::fake('local')`):
  - `it('move para a lixeira rascunho, cancelado e expirado')` com dataset de 3 status → `trashed_at` preenchido e `isTrashed()` verdadeiro.
  - `it('recusa lixeira para sent, in_progress, sealing, completed e refused')` → `FlowException` com código `not_trashable` e `trashed_at` continua nulo.
  - `it('arquiva concluído e recusado e recusa os demais')` → `archived_at` preenchido; para `draft`, `sent`, `in_progress`, `sealing`, `cancelled`, `expired` lança `not_archivable`.
  - `it('restaura e desarquiva')`: `restore` limpa `trashed_at` mantendo o status; `unarchive` limpa `archived_at`; restaurar quem não está na lixeira lança `not_trashed`.
  - `it('purge apaga só o que está há 30 dias ou mais na lixeira')`: envelope expirado com `trashed_at` de 31 dias → some do banco, o `Document` some e o arquivo `storage_path` some do disco; outro com 10 dias permanece; retorno `1`.
  - `it('purge remove também as fotos e selfies dos signatários')`: envelope expirado com um `SignerAttachment` (arquivo no disco falso) → o arquivo some.
  - `it('purge nunca apaga concluído, mesmo com trashed_at antigo')`: envelope `completed` com `trashed_at` de 90 dias permanece, com documento e arquivo.
  - `it('agenda a limpeza diária')`: `Artisan::call('envelopes:purge-trash')` retorna 0 e a saída contém "envelope(s)"; `collect(app(Schedule::class)->events())->contains(fn ($e) => str_contains($e->command, 'envelopes:purge-trash'))` é verdadeiro.
- [ ] **Step 2: Run** `php artisan test tests/Feature/TrashArchiveTest.php` — Expected: FAIL.
- [ ] **Step 3: Implement** a migration, os métodos do `Envelope` (casts `datetime` para as duas colunas) e o `TrashService`. `purge` seleciona envelopes `trashed_at <= now()->subDays($olderThanDays)` **e** status em `draft/cancelled/expired`, e para cada um, dentro de transação: apaga arquivos de `SignerAttachment` dos signatários, o arquivo do `Document` e o envelope (signatários, anexos e eventos de evidência caem por cascata; depois apagar a linha de `Document`, que tem `restrictOnDelete` a partir do envelope).
- [ ] **Step 4: Implement** o comando `envelopes:purge-trash` em `routes/console.php` (saída `"{n} envelope(s) removido(s) da lixeira."`) e `Schedule::command('envelopes:purge-trash')->daily()`.
- [ ] **Step 5: Run** o teste — Expected: PASS.
- [ ] **Step 6: Commit** `git add -A && git commit -m "Lixeira (30 dias) e arquivo de envelopes com serviço e limpeza diária"`.

---

### Task 3: Telas e rotas de Documentos criados, Lixeira e arquivo

**Files:**
- Modify: `app/Http/Controllers/Web/EnvelopeController.php` (`index` e novas ações), `routes/web.php`
- Modify: `app/Http/Controllers/Api/V1/EnvelopeController.php` (lista exclui lixeira)
- Create: `resources/views/documents/trash.blade.php`
- Modify: `resources/views/envelopes/index.blade.php`, `resources/views/envelopes/show.blade.php`, `resources/views/dashboard.blade.php` (textos)
- Test: `tests/Feature/DocumentsTrashHttpTest.php`

**Interfaces:**
- Consumes: `TrashService` e métodos do `Envelope` da Tarefa 2; verificação de empresa usada por `EnvelopeController::cancel` (mesmo `abort_unless` de `tenant_id`).
- Produces: rotas nomeadas `envelopes.trash` (POST `/envelopes/{envelope}/lixeira`), `envelopes.restore` (POST `/envelopes/{envelope}/restaurar`), `envelopes.archive` (POST `/envelopes/{envelope}/arquivar`), `envelopes.unarchive` (POST `/envelopes/{envelope}/desarquivar`), `documents.trash` (GET `/documentos/lixeira`). `Web\EnvelopeController::index` aceita `arquivados=1` (mostra só os arquivados) e por padrão usa `Envelope::visible()`.

- [ ] **Step 1: Write the failing tests** em `tests/Feature/DocumentsTrashHttpTest.php` (registrar empresa pelo `/cadastro`, criar envelopes pelo fluxo web ou direto no banco, ajustar status com `forceFill`):
  - `it('a lista Documentos criados não mostra lixeira nem arquivados')` e `it('arquivados=1 mostra só os arquivados')`; a página tem o título "Documentos criados" e o botão "Criar documento".
  - `it('move rascunho para a lixeira e lista em /documentos/lixeira')` → redirect com flash "Movido para a lixeira."; a página da lixeira mostra o título e "apagado em 30 dias" (ou o número de dias restantes).
  - `it('não deixa mover para a lixeira um envelope em andamento ou concluído')` → resposta com erro, `trashed_at` nulo.
  - `it('restaura da lixeira sem reabrir o envelope')`: envelope `expired` restaurado continua `expired` e não aparece como assinável (`isOpenForSigning()` falso).
  - `it('arquiva concluído e permite desarquivar')`.
  - `it('POST de outra empresa devolve 404')` para as quatro ações.
  - `it('a API não lista envelope na lixeira')`: `GET /api/v1/envelopes` com chave de API não contém o id do envelope em `trashed_at`.
  - `it('a página do envelope oferece Mover para a lixeira (rascunho) e Arquivar (concluído)')` com `assertSee` e `assertDontSee` cruzados.
- [ ] **Step 2: Run** `php artisan test tests/Feature/DocumentsTrashHttpTest.php` — Expected: FAIL.
- [ ] **Step 3: Implement** as quatro ações no `Web\EnvelopeController` (capturar `FlowException` e voltar com `withErrors(['envelope' => $e->getMessage()])`, como as demais ações), a página `documents.trash` (tabela com título, status, "apagado em N dias", botão Restaurar) e o ajuste do `index` (link "Ver arquivados"/"Ver ativos"). Registrar as rotas dentro do grupo `auth` + `tenant.active` de `routes/web.php`. Na API, adicionar `->visible()`? **Não:** usar só `->whereNull('trashed_at')` (arquivados continuam na API).
- [ ] **Step 4: Textos:** trocar "Envelopes"/"Novo envelope"/"Criar envelope" por "Documentos criados"/"Criar documento" em `index`, estado vazio e CTA do `dashboard`; botões "Mover para a lixeira" e "Arquivar" na `show` (só quando `isTrashable()`/`isArchivable()`).
- [ ] **Step 5: Run** o teste e `php artisan test` — Expected: PASS (nenhum teste antigo depende dos textos trocados; se algum depender, ajustar o teste).
- [ ] **Step 6: Commit** `git add -A && git commit -m "Documentos criados, lixeira e arquivo nas telas e na lista da API"`.

---

### Task 4: Minhas assinaturas

**Files:**
- Create: `app/Http/Controllers/Web/MySignaturesController.php`, `resources/views/documents/mine.blade.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/MySignaturesTest.php`

**Interfaces:**
- Consumes: `Signer::signUrl(): ?string`, `Envelope::activeSigners()`, `Envelope::visible()`, `partials.status-badge`.
- Produces: `MySignaturesController::index(Request): View`; rota `documents.mine` (GET `/documentos/minhas-assinaturas`).

- [ ] **Step 1: Write the failing tests** em `tests/Feature/MySignaturesTest.php`:
  - `it('lista os envelopes da minha empresa em que sou signatário, pelo meu e-mail (sem diferenciar maiúsculas)')` com botão "Assinar" apontando para `$signer->signUrl()` quando o envelope está aberto e é a minha vez.
  - `it('não mostra envelopes de outra empresa mesmo com o mesmo e-mail')` — **Review Focus 1**: o título do envelope da outra empresa e o link de assinatura **não** aparecem.
  - `it('não mostra lixeira nem arquivados')`.
  - `it('quem já assinou vê o status Assinado e nenhum botão Assinar')`.
  - `it('em ordem sequencial, quem não é a vez vê "Aguardando a sua vez" e nenhum link')`.
  - `it('exige login')` → redireciona para `/entrar`.
- [ ] **Step 2: Run** `php artisan test tests/Feature/MySignaturesTest.php` — Expected: FAIL.
- [ ] **Step 3: Implement** `index`: `Signer` com `role = 'signer'` e `LOWER(email) = LOWER(usuário)` cujo envelope tem `tenant_id` do usuário e está em `Envelope::visible()`, status diferente de `draft`, ordenado do mais recente; paginar 20; para cada um, decidir o botão com `$envelope->status->isOpenForSigning()` e `$envelope->activeSigners()->contains('id', $signer->id)`. A view mostra título, quem criou, status do envelope, meu status e a ação.
- [ ] **Step 4: Run** o teste — Expected: PASS.
- [ ] **Step 5: Commit** `git add -A && git commit -m "Minhas assinaturas: documentos da empresa em que o usuário é signatário"`.

---

### Task 5: Novo menu lateral e botão Criar documento

**Files:**
- Create: `resources/views/layouts/partials/sidebar.blade.php`
- Modify: `resources/views/layouts/app.blade.php` (usa o partial; botão `Criar documento` na barra superior; item "Meu perfil" e "Chaves de API" do menu do usuário permanecem)
- Modify: `public/assets/css/contrast.css` (regras do submenu; subir `?v=` no `head.blade.php`)
- Test: `tests/Feature/SidebarMenuTest.php`

**Interfaces:**
- Consumes: rotas das Tarefas 3 e 4 (`envelopes.index`, `documents.mine`, `documents.trash`), `templates.index`, `profile.edit`, `organization.index`, `api-keys.index`, `webhooks.index`, `verify.index` e as rotas `admin.*` existentes.
- Produces: estrutura do menu (abaixo); o parâmetro `$helpUrl` (string|null) lido de `Setting::helpUrl()` na Tarefa 6 — **nesta tarefa o item "Central de ajuda" já existe no partial, escondido quando `$helpUrl` é nulo**.

Estrutura do menu do cliente (nesta ordem):

```
Painel
Documentos ▸  Documentos criados · Minhas assinaturas · Lixeira
Modelos
Configurações ▸  Meu perfil · Pastas e etiquetas · API · Webhooks
Verificar documento            (abre em nova aba)
Central de ajuda               (só com link configurado; nova aba, rel="noopener")
[Plataforma — só super admin: Visão geral, Clientes, Saúde do sistema, Selagem do PDF, E-mail (SMTP), Configurações]
```

- [ ] **Step 1: Write the failing tests** em `tests/Feature/SidebarMenuTest.php`:
  - `it('mostra o menu na ordem do escopo')`: `assertSeeInOrder(['Painel','Documentos','Documentos criados','Minhas assinaturas','Lixeira','Modelos','Configurações','Meu perfil','Pastas e etiquetas','API','Webhooks','Verificar documento'])`.
  - `it('esconde a seção Plataforma de quem não é super admin e mostra ao super admin')`.
  - `it('o pai fica aberto e ativo na página de um filho')`: `GET /documentos/lixeira` → o HTML do menu contém `mm-active` no item "Documentos" e a lista filha com `mm-show`.
  - `it('tem o botão Criar documento no topo apontando para a criação')`.
  - `it('não mostra Central de ajuda sem link configurado')`.
- [ ] **Step 2: Run** `php artisan test tests/Feature/SidebarMenuTest.php` — Expected: FAIL.
- [ ] **Step 3: Implement** o partial no padrão metisMenu do Rocker (item pai com `class="has-arrow"` e `<ul>` filha; pai recebe `mm-active` e a `<ul>` `mm-show` quando a rota atual casa com qualquer filho, usando `request()->routeIs()`). Mover para ele o `$item` closure e o bloco de menu atual do `app.blade.php`, mantendo a seção Plataforma. Em `app.blade.php`, substituir o bloco por `@include('layouts.partials.sidebar')` e adicionar na barra superior (antes do ícone de tema) `<a class="btn btn-primary" href="{{ route('envelopes.create') }}">Criar documento</a>` (somente `d-none d-sm-inline-flex`; no celular, o botão fica no menu "Documentos").
- [ ] **Step 4: CSS do submenu** em `contrast.css`: itens filhos em negrito (`font-weight: 600`), cor `#ffffff` no escuro e `#1f2933` no claro (≥ 4,5:1 sobre o fundo do menu em cada tema), destaque do filho ativo em `#0b63c7` (claro) / `#7db7ff` (escuro), recuo de 1,5rem.
- [ ] **Step 5: Run** o teste e `php artisan test` — Expected: PASS.
- [ ] **Step 6: Commit** `git add -A && git commit -m "Novo menu lateral (Documentos, Configurações) e botão Criar documento"`.

---

### Task 6: Link da Central de ajuda configurável

**Files:**
- Modify: `app/Models/Setting.php` (`helpUrl()`), `app/Http/Controllers/Admin/SettingsController.php`, `resources/views/admin/settings.blade.php`, `resources/views/layouts/partials/sidebar.blade.php` (liga o `$helpUrl`)
- Test: `tests/Feature/HelpLinkSettingTest.php`

**Interfaces:**
- Consumes: partial da Tarefa 5 (item "Central de ajuda").
- Produces: `Setting::helpUrl(): ?string` (chave `help_url`; devolve `null` se vazia).

- [ ] **Step 1: Write the failing tests:**
  - `it('o super admin salva o link da Central de ajuda e ele aparece no menu de todos')`: após `PUT /admin/configuracoes` com `help_url=https://ajuda.exemplo.com`, o menu de um usuário comum contém `href="https://ajuda.exemplo.com"`, `target="_blank"` e `rel="noopener"`.
  - `it('recusa esquemas perigosos e links malformados')`: `javascript:alert(1)`, `data:text/html,x` e `ftp://x.com` geram erro de validação em `help_url`.
  - `it('link vazio remove o item do menu')`.
  - `it('usuário comum não consegue alterar')` → 403/redirect como as demais rotas `admin.*`.
- [ ] **Step 2: Run** `php artisan test tests/Feature/HelpLinkSettingTest.php` — Expected: FAIL.
- [ ] **Step 3: Implement** a validação `['nullable','url:http,https','max:300']` no `update` (mantendo o salvamento de `registration_open`), o campo na view de configurações e `Setting::helpUrl()`; o partial passa a ler `\App\Models\Setting::helpUrl()`.
- [ ] **Step 4: Run** o teste — Expected: PASS.
- [ ] **Step 5: Commit** `git add -A && git commit -m "Link da Central de ajuda configurável pelo administrador"`.

---

### Task 7: Verificação visual, documentação e entrega

**Files:**
- Modify: `docs/ESCOPO.md` (marcar a Fase 1 como entregue na seção 14), `docs/superpowers/specs/2026-11-13-menu-modulos-planos-design.md` (status: Fase 1 entregue em `<data>`)

**Interfaces:** consome todas as tarefas anteriores.

- [ ] **Step 1:** Rodar a suíte completa: `php artisan test` — Expected: todos passam (118 anteriores mais os novos).
- [ ] **Step 2:** Subir o servidor de preview (`laravel`), criar uma empresa de teste local e conferir em `/painel`, `/envelopes`, `/documentos/minhas-assinaturas` e `/documentos/lixeira`, **nos temas claro e escuro** e com largura de 375 px: submenu abre e fecha pela seta do pai, o filho ativo fica destacado, menu recolhe pelo botão, nenhum texto cortado.
- [ ] **Step 3:** Rodar a auditoria de contraste (WCAG, razão ≥ 4,5:1 para cada nó de texto visível) nessas quatro páginas nos dois temas — Expected: nenhuma ocorrência abaixo de 4,5:1.
- [ ] **Step 4:** Conferir no DevTools da aba de rede que as fontes carregam de `/assets/fonts/roboto/` e que não há chamada a `fonts.googleapis.com`.
- [ ] **Step 5:** Atualizar os dois documentos e fazer o commit final **seguido do push**: `git add -A && git commit -m "Fase 1 entregue: Roboto local, menu ZapSign e Documentos (lixeira, arquivo, minhas assinaturas)" && git push origin main`.

---

## Self-review

**Cobertura do escopo (seção 4/5/8, Fase 1):** Roboto local → T1; menu (Documentos com 3 filhos, Modelos, Configurações agrupada, Central de ajuda, botão Criar documento) → T5/T6; Documentos criados → T3; Minhas assinaturas → T4; Lixeira de 30 dias e arquivo → T2/T3; "Contatos, Calendário, Relatórios, Usuários, Empresa, Plano e cobrança, Planos e preços" ficam para as fases 2 a 4 (o menu da T5 os acrescentará então). Lacunas: nenhuma para a Fase 1.

**Decisões deste plano que não estavam no escopo (marcadas como minhas):** o status `refused` só pode ser **arquivado** (não vai para a lixeira); a API continua listando arquivados mas não os da lixeira; "Minhas assinaturas" fica restrita à própria empresa por falta de verificação de e-mail; o botão "Criar documento" do topo some no celular; push uma vez ao fim da fase.

**Consistência de nomes:** `trashed_at`, `archived_at`, `isTrashable/isArchivable/isTrashed/isArchived`, `visible()`, `TrashService::{trash,restore,archive,unarchive,purge}`, rotas `envelopes.{trash,restore,archive,unarchive}`, `documents.{trash,mine}`, `Setting::helpUrl()` — iguais em todas as tarefas.

**Proporção:** tarefas definem assinaturas, testes e valores do escopo; corpos de métodos ficam com quem implementa.
