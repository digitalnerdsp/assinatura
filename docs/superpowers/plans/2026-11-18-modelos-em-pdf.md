# Modelos em PDF com lacunas — Plano de implementação

> **Para quem executar:** usar superpowers:executing-plans (execução na mesma sessão) ou superpowers:subagent-driven-development. Passos com caixa de seleção (`- [ ]`).

**Objetivo:** trocar o modelo em HTML por um modelo em PDF com lacunas marcadas, preenchidas por quem envia.

**Arquitetura:** o PDF do modelo e as lacunas (`template_fields`) ficam no banco/disco da empresa; o serviço de selagem (Python) ganha `POST /v1/fill` que desenha os textos no PDF; "Usar modelo" gera o PDF preenchido e cria o rascunho pelo `EnvelopeService::createDraft`, levando ao passo 2 do assistente. O editor de lacunas reaproveita o visualizador pdf.js e o estilo de arrastar do passo 3.

**Stack:** Laravel 13, Pest 4 (PostgreSQL `assinatura_test`, rodar com `/tmp/runsuite.sh [--filter=...]`), Blade, JS puro + pdf.js 3.11.174, Python (FastAPI, `pypdf`, `reportlab`) com pytest em `sealer/tests`.

**Spec:** `docs/superpowers/specs/2026-11-18-modelos-em-pdf-design.md`

## Restrições globais
- Textos de tela e mensagens em português do Brasil; "documento", nunca "envelope".
- Coordenadas das lacunas: frações da página, origem no canto superior esquerdo (igual a `signature_fields`).
- Limites: 40 lacunas por modelo, nome da lacuna ≤ 60 caracteres, valor ≤ 500, nome do modelo ≤ 150.
- Letra de 11 pt diminuindo até 6 pt; fonte Helvetica (cobre os acentos do português; outros alfabetos viram "?", como no relatório).
- O PDF do modelo conta no armazenamento do plano (`StorageUsage`); remover o modelo devolve o espaço e apaga o arquivo.
- Tudo isolado por empresa (`tenant_id`); rotas com `feature:templates`; prévia com limite de requisições.
- CSS/JS novos com `?v=N` e seletores `.tpl .tpl-…` (telas internas ficam dentro de `.legacy-scope`).
- Commit e push na `main` ao fim de cada tarefa, com a linha `Co-Authored-By` pedida; **nunca** encadear `git commit` depois de um comando de teste com `| tail` (o `&&` não vê a falha): rodar os testes, ler o resultado, só então commitar.

## Foco da revisão final
1. PDF girado (`/Rotate`) ou com caixa de corte diferente da página: o texto cai na lacuna certa.
2. Valor com acento, aspas, `<`, `%`, quebra de linha, emoji ou 500 caracteres: nada quebra nem é interpretado.
3. Lacuna muito pequena ou valor longo: recusa clara apontando a lacuna, sem PDF corrompido.
4. Dois usos simultâneos do mesmo modelo, e remoção do modelo durante um uso: cota de armazenamento não duplica nem vaza.
5. Modelo de outra empresa por URL, API ou prévia: sempre 404.

---

### Tarefa 1: `POST /v1/fill` no serviço de selagem e cliente PHP

**Arquivos:**
- Modificar: `sealer/app.py` (rota), criar `sealer/fill.py` (lógica), criar `sealer/tests/test_fill.py`
- Modificar: `app/Signing/Support/SealerClient.php`
- Criar: `tests/Feature/SealerFillClientTest.php`

**Interfaces:**
- Produz (Python): `fill.fill_pdf(pdf: bytes, fields: list[dict]) -> bytes`; cada campo: `page:int` (1-based), `x,y,width,height: float` (frações), `text:str`, `multiline:bool`, `key:str`. Levanta `fill.FitError(key)` quando não cabe em 6 pt.
- Produz (HTTP): `POST /v1/fill` multipart `pdf` + `fields` (JSON), token `Bearer` do selador. 200 → corpo `application/pdf`; 422 → `{"error":"does_not_fit","field":"<key>"}`; 422 `{"error":"invalid_pdf"}` para PDF ilegível ou protegido por senha.
- Produz (PHP): `SealerClient::fill(string $pdf, array $fields): string` (bytes do PDF) — lança `App\Templates\FillException` (criar em `app/Templates/FillException.php`, com `public readonly ?string $fieldKey`) em 422 e `SigningException` se o serviço estiver fora.

- [ ] **Passo 1: testes que falham (pytest)** — em `sealer/tests/test_fill.py`, com PDFs gerados por `reportlab`: (a) texto simples fica dentro da caixa (extrair com `pypdf` e conferir a posição pelo texto presente na página 1); (b) acentos `ção Ñ` aparecem; (c) texto longo reduz a letra e ainda cabe (consultar o tamanho gravado no conteúdo da página); (d) texto impossível levanta `FitError` com a chave; (e) `multiline` quebra em várias linhas dentro da altura; (f) página com `/Rotate 90` e `MediaBox` não-zero recebe o texto na posição visual certa; (g) campo em página inexistente é recusado; (h) PDF criptografado → erro `invalid_pdf`; (i) texto com `\n` numa lacuna de uma linha vira espaço.
- [ ] **Passo 2: rodar** `cd sealer && python -m pytest tests/test_fill.py -q` — Esperado: FALHA (módulo não existe).
- [ ] **Passo 3: implementar `fill.py`** — por página, criar camada `reportlab` do tamanho da MediaBox, converter fração (origem topo-esquerdo) para coordenadas do PDF considerando `/Rotate` e origem da MediaBox, desenhar o texto com `stringWidth` reduzindo de 11 até 6 pt (passo 0,5), quebrar linhas só se `multiline`, e mesclar com `page.merge_page` do `pypdf`. Rota em `app.py` com `Depends(auth)`, mapeando `FitError`/leitura inválida para 422.
- [ ] **Passo 4: rodar** os testes Python — Esperado: PASSAM; e `python -m pytest -q` completo do selador sem regressão.
- [ ] **Passo 5: testes PHP que falham** — `SealerFillClientTest` com `Http::fake`: envia `pdf` + `fields` com token; devolve os bytes em 200; 422 `does_not_fit` vira `FillException` com `fieldKey`; falha de conexão vira `SigningException`.
- [ ] **Passo 6: implementar `SealerClient::fill`** e `FillException`; rodar `/tmp/runsuite.sh --filter=SealerFillClientTest` — Esperado: PASSA.
- [ ] **Passo 7: suíte completa, ler o resultado, commitar e enviar.**

---

### Tarefa 2: Dados do modelo e serviço de modelos

**Arquivos:**
- Criar: `database/migrations/2026_11_26_000001_templates_as_pdf.php`, `app/Models/TemplateField.php`, `app/Templates/TemplateService.php`
- Modificar: `app/Models/DocumentTemplate.php`
- Apagar: `app/Templates/TemplateRenderer.php` (e usos; as telas e a API são trocadas nas tarefas 3–6, então nesta tarefa o renderizador só deixa de ser usado ao final da Tarefa 6)
- Criar: `tests/Feature/TemplateServiceTest.php`

**Interfaces:**
- Produz: tabela `template_fields` (`id` uuid, `template_id` FK cascade, `key` string 60, `label` string 60, `page` smallint, `x,y,width,height` decimal(6,5), `multiline` bool, `required` bool, `position` smallint; único `(template_id,key)`); `document_templates` ganha `pdf_path`, `pdf_sha256`, `pdf_size`, `page_count` (nulos permitidos só durante a migração) e `body` passa a nulo. A migração **apaga** os modelos existentes (e `envelopes.template_id` fica nulo).
- `DocumentTemplate::fields(): HasMany` (ordenado por `position`), `pdfContents(): string`.
- `TemplateService::create(Tenant $t, string $name, string $pdf, string $filename): DocumentTemplate` — valida PDF (`%PDF-`, tamanho, cota via `Entitlements::canUpload`, recusa protegido por senha, conta páginas), grava em `tenants/{id}/templates/{uuid}.pdf`, soma em `StorageUsage`.
- `TemplateService::syncFields(DocumentTemplate $t, array $fields): void` — `$fields` = lista de `{label, page, x, y, width, height, multiline, required}`; valida limites (≤ 40, página existente, retângulo dentro da página e com tamanho mínimo, rótulo não vazio), gera `key` único a partir do rótulo (`Str::slug` com `_`, sufixo `_2`, `_3`…), regrava tudo numa transação, `position` = ordem de leitura (página, depois y, depois x).
- `TemplateService::replacePdf(DocumentTemplate $t, string $pdf, string $filename): void` — troca o arquivo, ajusta a cota (diferença), **apaga as lacunas**.
- `TemplateService::delete(DocumentTemplate $t): void` — apaga lacunas, arquivo e devolve a cota.
- Erros: `FlowException::because(código, mensagem em português)`.

- [ ] **Passo 1: testes que falham** — criar com PDF válido (grava arquivo, `page_count`, soma cota); PDF inválido, grande, cota estourada, protegido; `syncFields` (chaves únicas e estáveis, ordem de leitura, 41ª lacuna recusada, página inexistente, retângulo fora da página, rótulo vazio/61 caracteres); `replacePdf` zera lacunas e acerta a cota; `delete` apaga arquivo e devolve cota; migração deixa `template_id` das envelopes nulo.
- [ ] **Passo 2: rodar** `--filter=TemplateServiceTest` — Esperado: FALHA.
- [ ] **Passo 3: implementar** migração, modelos e `TemplateService` (contar páginas com `smalot/pdfparser` se já houver no `composer.json`, senão pedir ao selador? — **decisão do executor, registrar no livro-razão**: preferir contar `/Type /Page` por regex robusta ou usar `pypdf` via novo campo de retorno em `/v1/fill`; o teste fixa só o resultado).
- [ ] **Passo 4: rodar** o filtro — Esperado: PASSA. Suíte completa, ler resultado, commitar e enviar.

---

### Tarefa 3: Telas de lista, criar e editar modelo (servidor)

**Arquivos:**
- Modificar: `app/Http/Controllers/Web/TemplateController.php`, `routes/web.php`, `resources/views/templates/index.blade.php`
- Criar: `resources/views/templates/form.blade.php` (reescrita), `tests/Feature/TemplateWebTest.php`

**Interfaces:**
- Consome: `TemplateService` (Tarefa 2).
- Rotas (todas `feature:templates`): `GET /modelos/novo` (`templates.create`), `POST /modelos` (`templates.store`: `name`, `pdf` arquivo → cria e redireciona para `templates.edit`), `GET /modelos/{template}` (`templates.edit`), `PUT /modelos/{template}` (`templates.update`: `name`, `fields` JSON → `syncFields`), `POST /modelos/{template}/pdf` (`templates.pdf.replace`), `GET /modelos/{template}/pdf` (`templates.pdf`, entrega o PDF da empresa para o visualizador, `Content-Type: application/pdf`, `nosniff`, com limite de requisições), `DELETE /modelos/{template}`.
- A tela `templates.edit` expõe `data-fields` (JSON), `data-pdf-url`, `data-pages` para o editor (Tarefa 4).

- [ ] **Passo 1: testes que falham** — criar com PDF (redireciona à edição); sem PDF/nome vazio/PDF inválido (erros em português); atualizar nome e lacunas; atualizar com lacunas inválidas não muda nada; trocar PDF zera lacunas e exige confirmação (`confirm=1`); remover; outra empresa recebe 404 em todas as rotas; sem `feature:templates` recebe 403; visitante vai ao login; `GET pdf` devolve o arquivo certo e 404 para outra empresa; lista mostra nome, quantidade de lacunas e botões Usar/Editar.
- [ ] **Passo 2: rodar** — Esperado: FALHA.
- [ ] **Passo 3: implementar** controlador, rotas e views (a edição renderiza o contêiner do editor e o formulário oculto com `fields`; o editor visual vem na Tarefa 4).
- [ ] **Passo 4: rodar** o filtro e a suíte — Esperado: PASSA. Commitar e enviar.

---

### Tarefa 4: Editor visual de lacunas (navegador)

**Arquivos:**
- Criar: `public/assets/js/template-editor.js`, `public/assets/css/template-editor.css`
- Modificar: `resources/views/templates/form.blade.php`
- Criar: `tests/Feature/TemplateEditorMarkupTest.php`

**Interfaces:**
- Consome: `data-fields`, `data-pdf-url`, `data-pages` da Tarefa 3; contrato de coordenadas das restrições globais.
- Produz: o JS preenche o campo oculto `fields` (JSON no formato de `syncFields`) antes de enviar; comportamento: clique/toque no documento abre popup (nome da lacuna, "várias linhas", "obrigatório"); retângulo arrasta, redimensiona, duplica, remove; lista lateral com renomear e remover; setas/Delete no teclado; máximo 40; aviso ao sair com alterações não salvas; pdf.js 3.11.174 com `crossorigin`/`referrerpolicy` iguais às do passo 3.

- [ ] **Passo 1: testes que falham** — a tela de edição contém `#tpl-viewer`, `data-pdf-url`, os scripts com `?v=1`, o texto de ajuda em português ("Clique no documento onde fica a lacuna") e **não** contém "HTML"; trocar PDF mostra a confirmação.
- [ ] **Passo 2: implementar** o JS reaproveitando o padrão de `wizard-placement.js` (arrastar, redimensionar, popup acessível com foco, toque), com `.tpl .tpl-…` no CSS e cores legíveis em modo escuro.
- [ ] **Passo 3: rodar** o filtro e a suíte; **verificar no navegador** (`preview_start laravel`, login com usuário de desenvolvimento): subir um PDF, criar três lacunas, mover, redimensionar, salvar e reabrir; registrar o que não foi possível ver (canvas do pdf.js em painel oculto). Commitar e enviar.

---

### Tarefa 5: Usar o modelo (formulário, prévia e rascunho)

**Arquivos:**
- Criar: `app/Templates/TemplateFiller.php`, `resources/views/templates/use.blade.php` (reescrita), `tests/Feature/TemplateUseTest.php`
- Modificar: `app/Http/Controllers/Web/TemplateController.php`, `routes/web.php`

**Interfaces:**
- Consome: `SealerClient::fill`, `TemplateService`, `EnvelopeService::createDraft(Tenant, User, string $pdf, string $filename, string $title, ?string $folderId)`; o `template_id` é gravado no rascunho (a coluna já existe em `envelopes`).
- Produz: `TemplateFiller::render(DocumentTemplate $t, array $values): string` — valida cada valor (obrigatório, ≤ 500, texto simples), monta a lista de campos e chama o selador; traduz `FillException` em `FlowException::because('does_not_fit', "O valor de '{label}' não cabe na lacuna. Escreva menos texto.")`.
- Rotas: `GET /modelos/{template}/usar` (formulário: `title`, `folder_id`, um campo por lacuna na ordem de leitura, com `maxlength=500` e textarea nas "várias linhas"), `POST /modelos/{template}/previa` (PDF preenchido inline, `throttle:20,1`), `POST /modelos/{template}/usar` (gera, cria o rascunho e redireciona a `/documentos/{id}/passo/2`).

- [ ] **Passo 1: testes que falham** (selador falso com `Http::fake` devolvendo um PDF fixo): formulário com um campo por lacuna e rótulos corretos; obrigatório vazio volta com erro no campo; valor de 501 caracteres recusado; valores com `<script>`, `{{x}}`, `%`, aspas e emoji seguem como texto simples; "não cabe" volta com a mensagem apontando a lacuna e não cria rascunho; sucesso cria rascunho com `template_id`, `wizard_step = 1`, título e pasta escolhidos, PDF preenchido como documento, cota somada; redireciona ao passo 2; prévia devolve PDF e não cria nada; outra empresa recebe 404; modelo sem lacunas mostra aviso para marcar ao menos uma.
- [ ] **Passo 2: implementar** e **rodar** o filtro e a suíte — Esperado: PASSA. **Verificar no navegador** o fluxo completo (criar modelo → usar → cair no passo 2) com o selador local, ou registrar que não foi possível. Commitar e enviar.

---

### Tarefa 6: API v1, limpeza, documentação e revisão

**Arquivos:**
- Modificar: `app/Http/Controllers/Api/V1/TemplateController.php`, `app/Api/Presenter.php`, `routes/api.php`, `tests/Feature/GrowthFeaturesTest.php` (trechos de modelos), `docs/ESCOPO.md`, API docs/OpenAPI se existirem
- Apagar: `app/Templates/TemplateRenderer.php` e `dompdf/dompdf` do `composer.json` **somente se** nada mais o usar (`grep`); caso use, manter e registrar
- Criar: `tests/Feature/TemplateApiTest.php`

**Interfaces:**
- API: `GET /templates` e `GET /templates/{id}` devolvem `fields: [{key,label,page,multiline,required}]` (sem `body`); `POST /templates/{id}/documents` recebe `variables` indexadas por `key` (obrigatórias conforme `required`) e gera pelo `TemplateFiller`; `POST`/`PUT /templates` removidos (404); `DELETE` continua. Erro de "não cabe": 422 com `{error:"does_not_fit", field:"<key>"}`.

- [ ] **Passo 1: testes que falham** — listagem e detalhe com `fields`; geração com `variables` por chave (documento criado com `template_id`, enviado se `send`); obrigatório faltando → 422 por chave; "não cabe" → 422; outra empresa → 404; criar/editar → 404; relatório por modelo (`ReportTest`) segue passando.
- [ ] **Passo 2: implementar**, ajustar os testes antigos que dependiam de HTML (apagar os que não fazem mais sentido, registrando no livro-razão), documentar em `docs/ESCOPO.md` (seção "Modelos em PDF") e nota de quebra da API.
- [ ] **Passo 3: suíte completa; revisão independente** de todo o diff da feature com o "Foco da revisão final"; corrigir importantes com teste que falha antes; registrar menores. Commitar e enviar.
