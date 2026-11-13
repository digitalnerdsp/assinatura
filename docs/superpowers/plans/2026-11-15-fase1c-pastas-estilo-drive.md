# Fase 1c — Pastas e etiquetas estilo Drive dentro de Documentos Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Pastas com subpastas (3 níveis abaixo da primária), etiquetas e ações (criar, renomear, mover, apagar) por botões e popups dentro de "Documentos criados", validadas no celular, sem mudar a lista de documentos.

**Architecture:** `folders.parent_id` (lista de adjacência) e um `FolderService` único (regras de nível, nome, ciclo, caminho, árvore, apagar) usado pela tela e pela API. A tela de documentos ganha navegação de pastas por cima da lista (migalhas, cartões, chips de etiquetas) e modais Bootstrap em tela cheia no celular; o filtro de pasta passa a incluir subpastas. Os endereços `POST|DELETE /organizacao/*` continuam (compatíveis); `GET /organizacao` redireciona.

**Tech Stack:** PHP 8.4, Laravel 13, Pest 4, PostgreSQL (consulta recursiva), Blade + Bootstrap 5 modais/dropdowns, JS puro.

**Spec:** `docs/superpowers/specs/2026-11-15-pastas-estilo-drive-design.md`

## Global Constraints

- Níveis: **4 no total** (primária + 3 subníveis). `FolderService::MAX_LEVELS = 4`.
- Nome da pasta: até 120 caracteres, **único dentro da mesma pasta pai, sem diferenciar maiúsculas** (raiz é um "pai" próprio). Etiqueta: até 60 caracteres, única por empresa, cor de `Tag::COLORS`.
- Pastas **nunca** vão para a lixeira nem para o arquivo; só criar, renomear, mover e apagar.
- Apagar pasta (e subpastas): `draft`/`cancelled`/`expired` não lixeirados vão para a lixeira (`trashed_at`); os demais (em andamento, concluídos, recusados, arquivados) **sobem para a pasta pai da pasta apagada** (raiz se for primária). Restaurar da lixeira sem pasta devolve à raiz.
- A **lista de documentos não muda**: todos os documentos, mesmos filtros, busca, arquivados e paginação; só ganha pasta (caminho) e etiquetas como informação. Abrir uma pasta filtra para os documentos dela **e das subpastas**.
- Celular: modais `modal-fullscreen-sm-down`, alvos de toque ≥ 44 px, menus ⋮ por toque e **sem corte** dentro de `.table-responsive`, sem rolagem horizontal em 375 px.
- Contraste ≥ 4,5:1 nos dois temas; textos em pt-BR; testes Pest com `uses(RefreshDatabase::class)`; nenhum teste usa a internet.
- Commits por tarefa; push uma vez ao fim da fase.

## Review Focus

1. **Outra empresa:** `parent_id`, `folder_id` e etiquetas de outra empresa em criar, renomear, mover, apagar, mover documento e API devolvem 404/422 sem alterar nada. (Tarefas 1, 2, 4 e 6.)
2. **Apagar nunca perde documento assinado ou em andamento:** inclui documentos de subpastas (sobem para a **pasta pai da pasta apagada**) e os números do popup batem com o que acontece. (Tarefa 1.)
3. **Ciclo, nível e nome:** mover para dentro de si ou de descendente, mover uma pasta com subpastas que estoura os 4 níveis, e nomes duplicados (maiúsculas/minúsculas e corrida entre duas requisições) são recusados. (Tarefa 1.)
4. **Filtro por pasta:** inclui subpastas, mantém `none` (sem pasta), não vaza de outra empresa e convive com busca, status, etiqueta, arquivados e lixeira. (Tarefa 3.)
5. **Celular:** menus ⋮ cortados pela tabela responsiva, modais que não cabem na tela e controles só por hover são os modos de falha; verificar em 375 e 768 px. (Tarefas 5 e 7.)

---

### Task 1: Hierarquia e `FolderService`

**Files:**
- Create: `database/migrations/2026_11_16_000001_add_parent_to_folders.php`, `app/Folders/FolderService.php`
- Modify: `app/Models/Folder.php`
- Test: `tests/Feature/FolderServiceTest.php`

**Interfaces:**
- Consumes: `App\Envelopes\Exceptions\FlowException::because(string $reason, string $message)`; `Envelope` (`folder_id`, `status`, `trashed_at`, `archived_at`).
- Produces:
  - `folders.parent_id` (uuid nulo, FK para `folders`, `cascadeOnDelete`); índice único `(tenant_id, COALESCE(parent_id,'00000000-0000-0000-0000-000000000000'), lower(name))` no PostgreSQL, no lugar do `(tenant_id, name)`.
  - `Folder::parent(): BelongsTo`, `Folder::children(): HasMany`.
  - `FolderService::MAX_LEVELS = 4`.
  - `create(Tenant $tenant, string $name, ?Folder $parent = null): Folder`, `rename(Folder $folder, string $name): void`, `move(Folder $folder, ?Folder $newParent): void` — `FlowException` com `reason` `invalid_name`, `folder_name_taken`, `folder_too_deep`, `folder_cycle`, `folder_other_tenant`.
  - `path(Folder $folder): Collection` (da primária até a pasta, incluindo ela), `level(Folder $folder): int`, `descendantIds(Folder $folder): list<string>` (inclui a própria), `tree(Tenant $tenant): list<array{id:string,name:string,level:int,children:array}>`.
  - `impact(Folder $folder): array{trash:int,keep:int,folders:int,destination:?string}` (`folders` = quantidade de subpastas apagadas junto; `destination` = nome da pasta pai ou `null` para a raiz).
  - `delete(Folder $folder): array{trashed:int,kept:int,folders:int}`.

- [ ] **Step 1: Write the failing tests** em `tests/Feature/FolderServiceTest.php` (helper local cria `Tenant`, `Document` e `Envelope` direto nos models; `Storage::fake('local')`):
  - `it('cria pastas e subpastas até 4 níveis e recusa o 5º')`: nomes `A`, `B`, `C`, `D` aninhados criam; criar dentro de `D` → `folder_too_deep`.
  - `it('nome único por pasta pai, sem diferenciar maiúsculas')`: `Contratos` e `contratos` na raiz → `folder_name_taken`; o mesmo nome em pastas diferentes é aceito; renomear para nome de irmã → `folder_name_taken`; nome vazio/só espaços → `invalid_name`; 121 caracteres → `invalid_name`.
  - `it('o índice único do banco segura a corrida entre duas criações')`: inserir direto duas linhas com mesmo `(tenant, parent null, lower(name))` via `DB::table` → `QueryException`.
  - `it('mover recusa ciclo, nível excedido (com subpastas junto) e pasta de outra empresa')`: mover `A` para dentro de `A`/descendente → `folder_cycle`; `A`(com filho) para dentro de uma pasta de nível 4 → `folder_too_deep`; pasta de outro tenant como destino → `folder_other_tenant`; mover para a raiz (`null`) funciona; mover para um pai onde já há irmã de mesmo nome → `folder_name_taken`.
  - `it('devolve caminho, nível, descendentes e árvore')`: `path()` na ordem primária→pasta, `level()`, `descendantIds()` inclui a própria, `tree()` aninhado e ordenado por nome.
  - `it('apagar manda para a lixeira o que pode e sobe o resto para a pasta pai')`: pasta `A` com subpasta `A1`; documentos: `draft`, `cancelled`, `expired` (um em `A` e um em `A1`) viram `trashed_at`; `sent`, `in_progress`, `completed`, `refused` e um `completed` arquivado em `A`/`A1` passam a ter `folder_id` = pai de `A` (ou `null` se `A` é primária); `A` e `A1` somem; o retorno bate com `impact()` calculado antes.
  - `it('os números de impact() batem com o que delete() faz')` e `it('apagar não toca em documentos de outra empresa nem já lixeirados')`.
  - `it('restaurar da lixeira um documento de pasta apagada devolve à raiz')`: usa `TrashService::restore` e confirma `folder_id` nulo.
- [ ] **Step 2: Run** `php artisan test tests/Feature/FolderServiceTest.php` — Expected: FAIL.
- [ ] **Step 3: Implement** a migration (reversível; `UNIQUE INDEX` por SQL cru só em `pgsql`, com `down()` recriando a unicidade antiga), `Folder` e `FolderService`. `descendantIds` por consulta recursiva (`WITH RECURSIVE`) restrita ao `tenant_id`; `delete` em transação: calcular ids, lixeirar (`update` em massa dos `draft/cancelled/expired` com `trashed_at` nulo), mover o resto com `folder_id = pai`, apagar as pastas (`whereIn`); os lixeirados ficam com `folder_id` nulo pela FK `nullOnDelete`.
- [ ] **Step 4: Run** o teste e `php artisan test` — Expected: PASS.
- [ ] **Step 5: Commit** `git add -A && git commit -m "Pastas com subpastas (4 níveis): FolderService com nome, ciclo, caminho, árvore e apagar seguro"`.

---

### Task 2: Ações de pasta na web e saída de Configurações

**Files:**
- Modify: `app/Http/Controllers/Web/OrganizationController.php`, `routes/web.php`, `resources/views/layouts/partials/sidebar.blade.php`
- Delete: `resources/views/organization/index.blade.php`
- Test: `tests/Feature/FolderWebTest.php`

**Interfaces:**
- Consumes: `FolderService` (Tarefa 1).
- Produces (os endereços antigos continuam): `POST /organizacao/pastas` (`name`, `parent_id` opcional) `folders.store`; `PUT /organizacao/pastas/{folder}` (`name`) `folders.update`; `POST /organizacao/pastas/{folder}/mover` (`parent_id` ou vazio = raiz) `folders.move`; `GET /organizacao/pastas/{folder}/impacto` (JSON `{trash,keep,folders,destination}`) `folders.impact`; `DELETE /organizacao/pastas/{folder}` `folders.destroy`; `GET /organizacao` `organization.index` passa a redirecionar (301) para `envelopes.index`. O item "Pastas e etiquetas" sai do menu Configurações e `organization.*` sai de `$cfgOpen`.

- [ ] **Step 1: Write the failing tests** em `tests/Feature/FolderWebTest.php` (registrar empresa pelo `/cadastro`):
  - `it('cria pasta na raiz e subpasta e mostra mensagem')`; `it('recusa nome duplicado na mesma pasta com erro em name')`; `it('recusa o 5º nível com mensagem em português')`.
  - `it('renomeia e move pasta, recusando ciclo')`.
  - `it('o impacto devolve JSON com os números')` e `it('apagar remove a pasta e aplica a regra, com mensagem que cita os números')`.
  - `it('ações em pasta de outra empresa devolvem 404 e não alteram nada')` para `update`, `mover`, `impacto`, `destroy` e para `parent_id` de outra empresa em `store` (404 ou erro em `parent_id`).
  - `it('GET /organizacao redireciona para Documentos criados')` (301 para `/envelopes`).
  - `it('o menu Configurações não tem mais Pastas e etiquetas')`: `GET /painel` não contém "Pastas e etiquetas".
- [ ] **Step 2: Run** `php artisan test tests/Feature/FolderWebTest.php` — Expected: FAIL.
- [ ] **Step 3: Implement** as ações (validar com `FlowException` → `withErrors(['name' => ...])`/`['parent_id' => ...]`; tenant sempre conferido com `abort_unless`), as rotas, o redirecionamento e o ajuste do menu; remover a view antiga e a ação `index`.
- [ ] **Step 4: Run** o teste e `php artisan test` (o teste legado de `GrowthFeaturesTest` com `/organizacao/pastas` deve seguir passando) — Expected: PASS.
- [ ] **Step 5: Commit** `git add -A && git commit -m "Ações de pasta na web (criar, renomear, mover, impacto, apagar) e Pastas e etiquetas sai de Configurações"`.

---

### Task 3: Navegação de pastas e filtro com subpastas na lista de documentos

**Files:**
- Modify: `app/Http/Controllers/Web/EnvelopeController.php` (`index`), `resources/views/envelopes/index.blade.php`
- Create: `resources/views/envelopes/_drive.blade.php`
- Test: `tests/Feature/DriveViewTest.php`

**Interfaces:**
- Consumes: `FolderService::path/descendantIds/tree/level` (Tarefa 1).
- Produces: `Web\EnvelopeController::index` aceita `folder` = uuid de pasta (filtra **a pasta e suas descendentes**, só da empresa; uuid de outra empresa → lista vazia sem vazar) ou `none`; passa para a view `currentFolder` (`?Folder`), `breadcrumb` (`Collection`), `childFolders` (cada um com `items_count` = documentos visíveis na pasta e descendentes, e `children_count`), `rootFolders` (quando sem pasta), `tags` com `envelopes_count`, `folderPaths` (`[folder_id => 'A › B']`). A tabela mostra, por documento, o **caminho** da pasta e as etiquetas (a lista, filtros e paginação não mudam).

- [ ] **Step 1: Write the failing tests** em `tests/Feature/DriveViewTest.php`:
  - `it('sem pasta aberta lista todos os documentos como hoje e mostra os cartões das pastas primárias')`: documentos em `A`, em `A/A1` e sem pasta aparecem todos; os cartões mostram só `A` (e outras primárias), não `A1`.
  - `it('abrir uma pasta mostra migalhas, subpastas e filtra para a pasta e as subpastas')`: `/envelopes?folder={A}` lista docs de `A` e `A1` e não os sem pasta; breadcrumb "Documentos › A"; cartão da subpasta `A1`.
  - `it('none continua mostrando só os sem pasta')`.
  - `it('o filtro de pasta de outra empresa não vaza documentos')`.
  - `it('convive com busca, status, etiqueta e arquivados')`: `?folder=A&status=draft&q=...` e `?folder=A&arquivados=1`.
  - `it('cada linha mostra o caminho da pasta e as etiquetas')`: caminho "A › A1" e nome da etiqueta na linha.
  - `it('documentos na lixeira não contam nos cartões')`.
- [ ] **Step 2: Run** `php artisan test tests/Feature/DriveViewTest.php` — Expected: FAIL.
- [ ] **Step 3: Implement** o filtro por descendentes (usar `FolderService::descendantIds` com `whereIn('folder_id', ...)`), os dados da view e o partial `_drive` (migalhas, cartões, faixa de etiquetas); os controles de ação entram na Tarefa 5. Manter `array_filter($filters)`/"Limpar" coerentes com o novo parâmetro.
- [ ] **Step 4: Run** o teste e `php artisan test` — Expected: PASS.
- [ ] **Step 5: Commit** `git add -A && git commit -m "Navegação de pastas por cima da lista de documentos, com filtro que inclui subpastas"`.

---

### Task 4: Etiquetas (renomear/cor) e ações do documento (mover, etiquetar)

**Files:**
- Modify: `app/Http/Controllers/Web/OrganizationController.php`, `app/Http/Controllers/Web/EnvelopeController.php`, `routes/web.php`
- Test: `tests/Feature/TagAndMoveWebTest.php`

**Interfaces:**
- Consumes: `FolderService` e models `Tag`, `Envelope`.
- Produces: `PUT /organizacao/etiquetas/{tag}` (`name`, `color`) `tags.update`; `POST /envelopes/{envelope}/mover` (`folder_id` ou vazio = sem pasta) `envelopes.move`; `POST /envelopes/{envelope}/etiquetas` (`tags[]`; vazio remove todas) `envelopes.retag`.

- [ ] **Step 1: Write the failing tests** em `tests/Feature/TagAndMoveWebTest.php`:
  - `it('renomeia e troca a cor da etiqueta; nome duplicado e cor fora da lista são recusados')`.
  - `it('mover documento para pasta, para a raiz e entre pastas')`, `it('pasta de outra empresa ao mover documento é recusada')`, `it('documento de outra empresa devolve 404')`.
  - `it('mover vale para documento arquivado e na lixeira não')` (documento na lixeira → erro).
  - `it('aplica e remove etiquetas do documento, recusando etiqueta de outra empresa')`.
  - `it('apagar etiqueta a tira dos documentos e não apaga os documentos')`.
- [ ] **Step 2: Run** `php artisan test tests/Feature/TagAndMoveWebTest.php` — Expected: FAIL.
- [ ] **Step 3: Implement** os endpoints (tenant conferido; `folder_id` validado contra a empresa; `Rule::unique('tags')->where('tenant_id',...)->ignore($tag)` no rename).
- [ ] **Step 4: Run** o teste e `php artisan test` — Expected: PASS.
- [ ] **Step 5: Commit** `git add -A && git commit -m "Etiquetas editáveis e ações de mover/etiquetar documento"`.

---

### Task 5: Interface — popups, menus ⋮ e visual Drive (celular incluído)

**Files:**
- Create: `resources/views/envelopes/_drive_modals.blade.php`, `public/assets/js/drive.js`, `public/assets/css/drive.css`
- Modify: `resources/views/envelopes/index.blade.php`, `resources/views/envelopes/_drive.blade.php`, `resources/views/layouts/partials/head.blade.php` (carregar `drive.css?v=1`)
- Test: `tests/Feature/DriveUiMarkupTest.php`

**Interfaces:**
- Consumes: rotas das Tarefas 2 e 4; dados da Tarefa 3; `FolderService::tree`.
- Produces: na página de documentos — botões **Nova pasta** e **Nova etiqueta** (abrem modais), ⋮ em cartão de pasta (Abrir, Renomear, Mover, Apagar), ⋮ em chip de etiqueta (Renomear/Cor, Apagar), ⋮ em cada documento (Abrir, Mover para…, Etiquetas…, e as ações de lixeira/arquivo que já existem), alternador grade/lista (preferência em `localStorage`), modais: criar/renomear pasta, criar/editar etiqueta, **Mover para…** (árvore com a pasta atual marcada e destinos inválidos desabilitados: a própria pasta, descendentes e o que estouraria 4 níveis), confirmar apagar pasta (números vindos de `folders.impact`), etiquetas do documento. Todos com `modal-fullscreen-sm-down`.

- [ ] **Step 1: Write the failing tests** em `tests/Feature/DriveUiMarkupTest.php` (HTML da página de documentos com pastas, etiquetas e um documento):
  - `it('traz os botões Nova pasta e Nova etiqueta e os modais')`: `assertSee` de `id="modal-folder"`, `id="modal-tag"`, `id="modal-move"`, `id="modal-delete-folder"` e `modal-fullscreen-sm-down` em cada um.
  - `it('cada cartão de pasta, chip e documento tem menu ⋮ por toque')`: `data-bs-toggle="dropdown"` e `aria-label` descritivo; nenhum item de ação depende de `:hover`.
  - `it('os menus não são cortados pela tabela responsiva')`: `data-bs-popper-config` com `strategy: fixed` (ou `data-bs-boundary="viewport"`) nos ⋮ dentro de `.table-responsive`.
  - `it('o modal Mover para… embute a árvore e marca destinos inválidos')`: o JSON da árvore (atributo `data-tree`) contém as pastas da empresa e **só** da empresa.
  - `it('alvos de toque têm a classe de 44 px')`: botões de ação com `drive-tap` (definida em `drive.css` com `min-height: 44px; min-width: 44px`); `drive.css` e `drive.js` referenciados com versão.
- [ ] **Step 2: Run** `php artisan test tests/Feature/DriveUiMarkupTest.php` — Expected: FAIL.
- [ ] **Step 3: Implement** os partials, o JS (abrir modal com dados da pasta/documento, preencher `action` do formulário, buscar `impacto` e mostrar "N vão para a lixeira, M ficam em *destino*, K subpastas serão apagadas", árvore de destino com radio, alternador de visão) e o CSS (cartões de pasta em grade responsiva, lista em tela estreita vira cartões, migalhas com rolagem horizontal própria, `drive-tap`, contraste nos dois temas conforme `contrast.css`). Sem dependências novas.
- [ ] **Step 4: Run** o teste e `php artisan test` — Expected: PASS.
- [ ] **Step 5: Commit** `git add -A && git commit -m "Pastas estilo Drive: modais, menus por toque, grade/lista e layout para celular"`.

---

### Task 6: API (subpastas, renomear/mover, apagar com a regra, etiquetas)

**Files:**
- Modify: `app/Http/Controllers/Api/V1/OrganizationController.php`, `app/Api/Presenter.php`, `app/Api/OpenApiSpec.php`, `routes/api.php`, `app/Http/Controllers/Api/V1/EnvelopeController.php` (`folder_id` aceita subpastas)
- Test: `tests/Feature/FolderApiTest.php`

**Interfaces:**
- Consumes: `FolderService`.
- Produces: `POST /folders` aceita `parent_id`; `Presenter::folder` traz `parent_id` e `path` (`"A / B"`); `PATCH /folders/{id}` (`name` e/ou `parent_id`, `null` = raiz); `DELETE /folders/{id}` aplica a mesma regra de apagar e responde `{"trashed": n, "kept": n, "folders": n}`; `PATCH /tags/{id}` (`name`, `color`). Erros no formato `{error, message}` com os `reason` do `FolderService` (422) e 404 para recursos de outra empresa. Chave somente leitura continua bloqueada.

- [ ] **Step 1: Write the failing tests** em `tests/Feature/FolderApiTest.php`: criar com `parent_id`; `parent_id`/`id` de outra empresa → 404; 5º nível e duplicado → 422 com `error`; `PATCH` renomeia e move (ciclo → 422); `DELETE` devolve os números e aplica a regra (documentos lixeirados/subidos); `PATCH /tags/{id}`; chave somente leitura → 403 em `PATCH`/`DELETE`; `GET /openapi.json` contém `parent_id`, `trashed` e as rotas novas (o teste existente que exige toda rota documentada precisa passar).
- [ ] **Step 2: Run** `php artisan test tests/Feature/FolderApiTest.php` — Expected: FAIL.
- [ ] **Step 3: Implement** os endpoints chamando `FolderService` (capturar `FlowException` → `$this->error($e->reason, $e->getMessage(), 422)`), `Presenter` e `OpenApiSpec`.
- [ ] **Step 4: Run** o teste e `php artisan test` — Expected: PASS.
- [ ] **Step 5: Commit** `git add -A && git commit -m "API de pastas com subpastas, renomear/mover, apagar com a regra e etiquetas editáveis"`.

---

### Task 7: Verificação no navegador (celular e tablet), documentação e entrega

**Files:**
- Modify: `docs/ESCOPO.md` (Fase 1c entregue), `docs/superpowers/specs/2026-11-15-pastas-estilo-drive-design.md` (status)

**Interfaces:** consome todas as tarefas anteriores.

- [ ] **Step 1:** `php artisan test` completo — Expected: tudo passa.
- [ ] **Step 2:** Subir o preview (`laravel`), migrar o banco local, criar empresa de teste e dados (pastas em 4 níveis, documentos de vários status, etiquetas). No navegador, em **375 px** e **768 px**, nos dois temas: criar/renomear/mover/apagar pasta pelos popups; criar/editar/apagar etiqueta; mover documento e etiquetar pelo ⋮; confirmar que o popup de apagar mostra os números certos e que o resultado confere; popups em tela cheia, menus ⋮ não cortados dentro da tabela, **sem rolagem horizontal** (`documentElement.scrollWidth <= innerWidth`), alvos ≥ 44 px, migalhas longas rolando.
- [ ] **Step 3:** Auditoria de contraste (≥ 4,5:1) em `/envelopes` com pastas abertas, nos dois temas, em 375 e em desktop — Expected: nenhuma ocorrência.
- [ ] **Step 4:** Atualizar a documentação, fazer o commit final **e o push**: `git add -A && git commit -m "Fase 1c entregue: pastas e etiquetas estilo Drive" && git push origin main`.

---

## Self-review

**Cobertura do spec:** níveis e nome → T1; apagar com a regra de 3 e números do popup → T1/T2/T5; mover/ciclo → T1/T2; lista inalterada com caminho e etiquetas e filtro com subpastas → T3; etiquetas (criar, renomear, cor, apagar, aplicar) → T2/T4/T5; popups, ⋮, grade/lista e celular → T5/T7; menu e redirecionamento → T2; API e OpenAPI → T6; testes obrigatórios do spec → distribuídos. Fora desta fase (decidido): arrastar e soltar, seleção em massa.

**Decisões do plano fora do spec (minhas):** os endereços `/organizacao/*` ficam para não quebrar nada (a tela é que mudou de lugar); documento na lixeira não pode ser movido; `GET /organizacao/pastas/{id}/impacto` é um JSON auxiliar do popup; a preferência grade/lista fica no navegador.

**Consistência de nomes:** `FolderService::{create,rename,move,path,level,descendantIds,tree,impact,delete}`, `MAX_LEVELS`, razões `invalid_name`, `folder_name_taken`, `folder_too_deep`, `folder_cycle`, `folder_other_tenant`, rotas `folders.{store,update,move,impact,destroy}`, `tags.update`, `envelopes.{move,retag}` — iguais em todas as tarefas.

**Proporção:** tarefas dão assinaturas, testes e valores do spec; os corpos ficam para quem implementa.
