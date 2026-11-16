# Central de temas — desenho

Status: rascunho para aprovação. Decisão do dono: tema **global**, escolhido **só pelo super admin**. A estrutura deve permitir escolha por empresa no futuro (plano) sem refazer.

## Objetivo
Trocar a aparência do sistema (Bootstrap → Tailwind) por **temas instaláveis via upload (.zip)**, sem mudar a lógica. O primeiro tema nasce das duas dashboards em Tailwind que o dono vai enviar.

## Fora do escopo (agora)
- Escolha de tema por empresa (só a estrutura: `tenant_id` nulo = global).
- Editor visual de temas, loja de temas, temas de terceiros sem revisão.
- Reescrever todas as telas internas: elas continuam em Bootstrap dentro da casca do tema até migrarem (ver "Compatibilidade").

## Riscos (atualizado após revisão de segurança)
A validação por lista de bloqueios **não garante** que um pacote malicioso seja inofensivo (Blade executa PHP). O modelo adotado é: tema = código confiável; só super admin envia; a tela avisa. Endurecimentos feitos: sem chamada dinâmica, `new`, backtick, `$__env`, `<x-`; `@include/@extends` só com literal `theme::`; nomes de arquivo restritos; teto de arquivos; tipos do `theme.json`.

## Riscos que não podemos aceitar (intenção original)
- **Upload de PHP/Blade com código executável = execução remota.** O pacote nunca roda sem validação; o tema só pode usar um conjunto fixo de diretivas e variáveis.
- Tema quebrado derrubando o painel: sempre há o **tema padrão (atual)** como reserva automática.
- Quebrar white label e contraste (≥ 4,5:1): o tema usa as variáveis de marca (`--brand`) geradas pelo BrandService.

## Formato do pacote (.zip)
```
theme.json                 nome, slug, versão, autor, descrição, requer (versão do contrato)
layouts/app.blade.php      casca: menu, topo, @yield('content') e seções
pages/dashboard.blade.php  painel (recebe as variáveis do DashboardController)
assets/                    css, js, fontes, imagens (compilados)
preview.png                prévia da central
```
Contrato `requer: 1`. Slots opcionais: `layouts/auth`, `layouts/public` (fases seguintes).

## Validação no upload (recusa o pacote inteiro)
- Tamanho máximo do zip e de cada arquivo; sem path traversal (`..`, caminhos absolutos, links simbólicos).
- Extensões permitidas: `blade.php`, `json`, `css`, `js`, `png`, `jpg`, `webp`, `svg` (sanitizado), `woff2`, `woff`. Qualquer `.php` que não seja `.blade.php` é recusado.
- Blade: recusa `@php`, `@include` de fora do tema, `@inject`, `@use`, `{!! !!}` fora de lista de variáveis seguras, `<?php`, `eval`, `system`, `exec`, `shell_exec`, `passthru`, `proc_open`, `file_put_contents`, `unlink`, `base_path`, `env(`, `config(` fora de lista permitida, `DB::`, `app(`, `\App\`.
- JS: recusa `eval(`, `new Function`, URLs externas fora de uma lista (CDN permitidos) e `document.cookie`.
- `theme.json` válido, slug `[a-z0-9-]`, versão semver, contrato suportado.
- Tudo em quarentena (pasta temporária) até passar; só então é movido para `storage/app/themes/<slug>/<versão>`.

## Execução
- Views do tema registradas como namespace `theme::` apenas para o tema ativo; ativar = gravar `active_theme` em `settings` (`tenant_id` nulo) após validar de novo.
- Assets servidos por rota própria `/temas/{slug}/{versão}/{arquivo}` (só extensões permitidas, `Cache-Control` longo, `nosniff`); nunca da pasta `public`.
- `layouts.app` do sistema passa a escolher: `theme::layouts.app` se houver tema ativo e saudável; senão o atual. Idem `dashboard`.
- Falha ao renderizar o tema (exceção de view) → registra, mostra o padrão e avisa o super admin na central (reserva automática).

## Compatibilidade (ponto de atenção)
As telas internas usam classes Bootstrap. O tema Tailwind deve ser compilado **sem o reset global (preflight)** ou escopado, para não desfazer o Bootstrap das telas ainda não migradas; o sistema continua carregando o CSS do Bootstrap dentro da área de conteúdo até a migração acabar. Cada tela migrada sai desse regime.

## Central de temas (Plataforma › Temas, só super admin)
- Lista: prévia, nome, versão, situação (ativo / instalado / com erro), data.
- Ações: **Enviar tema (.zip)**, **Ativar**, **Voltar ao padrão**, **Remover** (o ativo não se remove).
- Relatório de validação legível quando o upload é recusado (arquivo, linha, motivo).
- Auditoria de quem instalou/ativou/removeu.

## Dados
`themes`: id (uuid), slug, name, version, contract, author, path, status (installed|error), checksum, installed_by, timestamps; único (slug, version).
`settings`: `active_theme` = id (nulo = padrão). Para o futuro: `tenants.theme_id` opcional.

## Testes
Pacote válido instala e ativa; pacote com `@php`, `<?php`, traversal, extensão proibida, zip gigante e `theme.json` inválido são recusados; ativar tema quebrado cai no padrão; admin (não super) recebe 403/404; rota de asset recusa extensão não permitida; white label e modo escuro continuam funcionando com o tema.

## Entregas
1. Infra: tabela, validador, serviço de instalação, rota de assets, escolha de layout com reserva, tela da central, auditoria, testes.
2. Tema 1: montado a partir das dashboards enviadas (layout `app` + `dashboard`), com prévia.
3. Migração gradual das demais telas para componentes do tema.
