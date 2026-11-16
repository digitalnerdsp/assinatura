# Central de temas — plano de execução

> Execução inline (executing-plans). Desenho: `docs/superpowers/specs/2026-11-16-central-de-temas-design.md` (aprovado: tema global, só super admin).

**Objetivo:** central de temas com upload (.zip) validado + dois temas de origem: **Able Pro** (Phoenixcoded) e **W3 CRM**, ambos Tailwind 4.

**Arquitetura:** tema = pacote com `theme.json`, `layouts/app.blade.php`, `pages/dashboard.blade.php`, `assets/`. Instalado em `storage/app/themes/<slug>/<versão>`, views no namespace `theme`, assets servidos por rota própria. Reserva automática no layout atual.

## Rulings (decididos pelo desenho)
- R1 Telas internas seguem em Bootstrap dentro da casca do tema. O Bootstrap do legado é gerado **escopado** em `.legacy-scope` (arquivo `public/assets/css/legacy-scope.css`, script `scripts/build-legacy-scope.mjs`), sem classe global; o CSS dos templates usa `@layer`, e o escopado (fora de layer) vence só dentro de `.legacy-scope`.
- R2 Licença: os dois templates são da ThemeForest (licença regular = um produto final, **SaaS cobrado de clientes exige licença estendida**). Os arquivos de origem não entram no git; o tema gerado entra como arquivos do pacote só depois de o dono confirmar a licença. Registrar no ledger e avisar o dono.
- R3 (revisado a pedido do dono) O visual Bootstrap deixa de ser um tema: o **padrão do sistema passa a ser o Able Pro**, embarcado no repositório e instalado/ativado pela migração. O W3 CRM fica como segundo tema opcional na central. O layout Bootstrap antigo (`layouts.legacy`) sobrevive só como **reserva técnica invisível** (se o tema ativo falhar) e o CSS Bootstrap escopado continua servindo as telas internas até serem migradas; ambos somem quando a última tela migrar.

## Contrato v1 (variáveis entregues às views do tema)
`layout`: `$brand` (nome, cor, logo), `$user`, `$isAdmin`, `$isSuper`, `$menu` (array de itens {label, icon, route, active, children[]}), seções `title`, `section`, `actions`, `content`, `scripts`.
`pages/dashboard`: `$kpi`, `$labels`, `$created`, `$completed`, `$statusChart`, `$pending`, `$recent`, `$usedThisMonth`, `$tenant`.

## Tarefas
### T1 Infra do tema (TDD)
Arquivos: migration `themes`; `app/Themes/{ThemeValidator,ThemeInstaller,ThemeManager}.php`; `app/Models/Theme.php`; `Admin/ThemeController`; rota de assets; view `admin/themes/index`; item de menu; testes `tests/Feature/ThemesTest.php`.
- `ThemeValidator::validate(string $zipPath): array` → lista de problemas (vazia = ok); regras do desenho (ext, traversal, tamanho, `@php`, `<?php`, funções proibidas, `theme.json`).
- `ThemeInstaller::install(User $by, UploadedFile|string $zip): Theme` (valida em quarentena, move, registra); `remove(Theme)`.
- `ThemeManager::active(): ?Theme`, `activate(Theme)`, `reset()`, `layout(): string`, `page(string $name): string` (reserva se tema ausente/erro).
- Rota `GET /temas/{slug}/{versao}/{arquivo}` (`where arquivo .+`), só extensões permitidas, `nosniff`.
- Testes: válido instala; recusa `@php`, `<?php`, `..`, `.php`, zip grande, `theme.json` inválido; assets só permitidos; ativar/reset; reserva se view do tema falhar; admin não-super 403/404.

### T2 Menu e variáveis do contrato
`app/Themes/MenuBuilder.php` monta `$menu` a partir da mesma lógica do `sidebar.blade.php`; `View composer` entrega `$brand/$user/$menu` ao layout do tema. Teste: itens por perfil (owner, membro, admin, super).

### T3 Legado escopado
`scripts/build-legacy-scope.mjs` (postcss + prefix `.legacy-scope`; `html/body/:root` → escopo) gera `legacy-scope.css` a partir de bootstrap, bootstrap-extended, app (conteúdo), icons, dark-theme, contrast, drive. Teste manual no navegador (telas Documentos, Contatos, Relatórios) com o tema Able ativo.

### T4 Tema Able Pro
Pacote em `resources/themes-src/able/` → `scripts/build-theme.mjs` gera zip. Converte `dist/dashboard/index.html` em `pages/dashboard.blade.php` ligado aos dados reais e `layouts/app.blade.php` com `$menu`. Assets: `style.css`, fontes usadas, js essenciais (apexcharts, simplebar, theme/component), sem i18n remoto. Validado e instalado pela própria central.

### T5 Tema W3 CRM
Idem em `resources/themes-src/w3crm/` a partir de `index.html`.

### T6 Verificação e revisão
Navegador (375/768/desktop, claro e escuro) com cada tema; suíte completa; revisão final (modelo fable); docs.
