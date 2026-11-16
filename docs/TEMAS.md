# Temas — contrato v1 (guia de quem cria temas)

Um tema troca a **aparência** do sistema. Ele é um `.zip` enviado em *Plataforma › Temas* (só super admin) e vale para toda a plataforma.

## Estrutura do pacote
```
theme.json                  {"name","slug","version":"1.0.0","contract":1,"author","description"}
layouts/app.blade.php       casca: menu, topo, área de conteúdo
pages/dashboard.blade.php   painel (@extends('theme::layouts.app'))
assets/                     css, js, fontes, imagens; assets/preview.png aparece na central
```
Só `assets/` é público (rota `/temas/<slug>/<versão>/assets/...`); Blade e JSON nunca saem.

## Modelo de confiança (leia antes de enviar um tema)
Um tema é **código confiável, equivalente a um deploy**: o Blade pode executar PHP e o JS roda na origem do sistema, com acesso às telas de todos os clientes. A conferência abaixo pega erros e truques comuns (e foi endurecida após uma revisão de segurança), mas **uma lista de bloqueios não consegue provar que um pacote malicioso é inofensivo**. Por isso só o super admin envia temas, e só de fontes confiáveis. Se algum dia temas de terceiros forem aceitos, será preciso um motor de templates sem PHP (ex.: Twig em modo sandbox) e CSP.

## Regras que o upload confere (recusa o pacote inteiro)
- Extensões: `blade.php json css js map png jpg jpeg gif webp svg ico woff woff2 ttf otf eot txt md`. Nada de `.php`.
- Sem `@php`, `<?php`, `{!! !!}`, `@inject`, `@use`, `@dd`, `@dump`.
- `@include/@extends/@component...` só com `'theme::...'`.
- Sem expressões com chamada dinâmica (`(...)(...)`, `$f(...)`), `new`, backtick, comentário `/* */` ou `$__env`/`$this`/`$GLOBALS` dentro de `{{ }}` e diretivas; sem componentes `<x-...>`.
- Nomes de arquivo só com letras, números, `.`, `-`, `_` e `/` (sem ocultos nem reservados do Windows); até 3000 arquivos.
- Sem funções como `system() exec() eval() config() env() app() auth() request() session() cache() file_get_contents()...` e sem `\App\...` ou `Classe::método`.
- JS (fora de `assets/vendor/`): sem `eval(`, `new Function(`, `document.cookie`. SVG sem `<script`/`on*=`.
- Até 25 MB o zip, 8 MB por arquivo, 80 MB descompactado.
- Use `{{ }}` (com escape). HTML pronto vem em variáveis `HtmlString` e também sai com `{{ }}`.

## Variáveis no layout (`layouts/app.blade.php`)
| Variável | Conteúdo |
|---|---|
| `$themeAssets` | URL base de `assets/` (sem barra final): `{{ $themeAssets }}/css/style.css` |
| `$brand` | `name`, `logo_url` (ou null), `primary`, `accent`, `active` (marca própria da empresa) |
| `$user` | usuário logado (`name`, `email`, `avatarUrl()`, `role`) |
| `$isAdmin`, `$isSuper` | pertence à equipe da plataforma / é super admin |
| `$menu` | lista de itens (abaixo) |
| `$flash` | avisos (sucesso/erro) e faixa de limite do plano, HTML pronto: `{{ $flash }}` |
| `$legacyCss` | `[icons.css, legacy-scope.css]` — incluir quando houver telas internas |
| `$legacyScripts` | scripts das telas internas + `@stack('scripts')`, HTML pronto: `{{ $legacyScripts }}` antes de `</body>` |

Seções (`@yield`): `title`, `section`, `actions`, `content`. Telas internas ainda são Bootstrap:
envolva o conteúdo em `<div class="legacy-scope">@yield('content')</div>` **a menos que** `@hasSection('native')` (a página do tema declara `@section('native', '1')`).
Dark mode: se o tema tem modo escuro, aplique também a classe `dark-theme` em `<html>` (as telas internas usam essa classe).

### Item de `$menu`
`label`, `icon` (chave: `home folder templates contacts calendar reports settings verify help platform tenants subscriptions health sealing plans platform-users themes mail platform-settings`), `url`, `active` (bool), `external` (bool), `group` (rótulo de seção ou null), `children` (lista de `{label,url,active}`; item com filhos tem `url` `#`).

## Variáveis de `pages/dashboard.blade.php`
`$kpi` (`month`, `monthDelta`, `pending`, `completedMonth`, `completedDelta`, `failed`, `total`), `$labels` (6 meses), `$created`, `$completed` (6 números), `$statusChart` (coleção `{label,value}`), `$pending` (envelopes: `title`, `status`, `signers_count`, `signed_count`, `created_at`, `id`), `$recent` (envelopes: `title`, `status`, `signers_count`, `created_at`), `$usedThisMonth`, `$tenant`.
Rotas úteis: `route('envelopes.show', $e)`, `route('envelopes.create')`, `route('envelopes.index')`, `route('plan-usage.index')`.
Texto de status: `__('status.'.$e->status->value)` (`$e->status` é um enum). Datas: `$e->created_at->timezone('America/Sao_Paulo')->format('d/m/Y')`. Limite do plano: `$tenant->monthly_envelope_limit` (null = ilimitado) e `$usedThisMonth`.

## Reserva
Se o tema ativo faltar, quebrar ou for removido, o sistema usa `layouts.legacy` automaticamente. Ativar testa a abertura do painel com dados de exemplo e recusa o tema que não abrir.

## Empacotar e testar
`php artisan themes:pack <slug>` gera `storage/app/theme-packages/<slug>.zip` a partir de `resources/themes-src/<slug>/` e confere com as mesmas regras do upload.

## Temas que vêm com o sistema
Ficam em `resources/themes-src/<slug>/`. A migration `install_bundled_themes` roda `php artisan themes:install-bundled` no deploy: instala os que faltam e, na primeira vez, ativa o padrão (`config/themes.php` → `default`, hoje `able`). Uma versão nova de um tema do sistema precisa subir o `version` do `theme.json` e de uma nova migration que chame o mesmo comando. Os temas instalados ficam em `storage/app/themes` (dentro do volume `documents`, que persiste entre deploys).
