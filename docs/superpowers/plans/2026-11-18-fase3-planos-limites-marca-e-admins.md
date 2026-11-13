# Fase 3 — Usuários da plataforma, planos, limites e marca própria: Plano de Implementação

> **Para quem executa:** use superpowers:executing-plans (execução direta, tarefa por tarefa). Os passos usam caixas `- [ ]`.

**Meta:** o painel da plataforma ganha **usuários admin e super admin**; cada empresa passa a ter **plano, assinatura, limites (documentos, usuários, armazenamento, tamanho do PDF), extrato de uso e marca própria (logo e cores)**, tudo configurável pelo administrador, ainda **sem cobrança automática** (isso é a Fase 4).

**Arquitetura:** `users.platform_role` (`admin` | `super_admin`) soma-se à lista fixa `SUPER_ADMIN_EMAILS` (os "raiz", que a tela não altera). Um serviço único, `Entitlements`, responde "pode fazer isto agora?" e é chamado pelos mesmos pontos que o painel e a API já usam. Planos, preços por período e assinaturas ficam em tabelas próprias; limites, recursos e regras de consumo vão em JSON no plano e podem ser sobrescritos na assinatura da empresa.

**Tecnologia:** Laravel 13, PostgreSQL (UUID v7, `timestamptz`), Pest 4 (`uses(RefreshDatabase::class)` por arquivo), Blade + Bootstrap/Rocker já em uso, GD para o logo.

**Spec:** `docs/superpowers/specs/2026-11-13-menu-modulos-planos-design.md` (seções 5.1, 6.1, 6.2, 6.5 e fase 3 da tabela 8). O ajuste pedido em 18/11/2026 (usuários também no super admin) está na Tarefa 1.

## Restrições globais

- Texto de tela, e-mail e documentação em **português do Brasil**. Valores em **centavos inteiros**, BRL.
- Contraste de texto **≥ 4,5:1** nos dois temas; itens importantes em negrito; **validar em 375 e 768 px** (popups em tela cheia no celular, tabelas viram cartões, alvos de 44 px).
- Migração **preserva as empresas existentes**: todas passam para o plano **"Legado"** (ilimitado, sem cobrança). Nada bloqueia quem já usa.
- **Nunca bloqueia:** assinar documentos já enviados, baixar, verificar, receber webhook, consultar a API em leitura. Bloqueia só **criar/enviar novos** documentos, convidar usuário e gravar arquivo acima do limite.
- Segredos fora de log e de resposta de API. Todo dado de limite novo tem teste automatizado.
- Quem muda papel de plataforma é só super admin; **ninguém altera os raiz** (`SUPER_ADMIN_EMAILS`) nem a si mesmo.

## Decisões do plano (minhas, para você conferir)

- **Admin** opera: Visão geral, Clientes, Saúde, Selagem, Assinaturas (ver, trocar plano, pagamento manual). **Super admin** faz tudo isso e ainda: Usuários da plataforma, Planos, E-mail (SMTP), Configurações (e, na Fase 4, Pagamentos).
- **Convidar um novo admin** cria uma conta comum (com a própria empresa, como num cadastro) que já nasce com o papel; a pessoa define a senha pelo link do e-mail. Promover alguém que já tem conta é pelo e-mail dela.
- Plano "Legado" e plano padrão são criados pela migração; o administrador cadastra os planos reais pela tela.
- Rascunho nunca conta como documento; o desconto é ao **enviar** por padrão (configurável por plano).

## Revisão focada (o que os testes comuns não pegam)

1. Admin comum nunca vê nem altera: usuários da plataforma, planos, e-mail, configurações; ninguém rebaixa o último super admin, a si mesmo nem um raiz.
2. Limite **concorrente**: dois envios simultâneos com 1 documento de saldo não passam os dois (trava de linha); saldo nunca fica negativo.
3. Empresa existente continua enviando normalmente depois da migração (plano Legado), inclusive pela API.
4. Armazenamento: remover/mover arquivo devolve espaço; contador e soma real batem após a conciliação.
5. Marca própria: cor com contraste ruim é recusada/ajustada; logo hostil (SVG, arquivo enorme, extensão trocada) recusado; nunca vaza logo de outra empresa.

---

### Tarefa 1: Usuários da plataforma (admins e super admins)

**Arquivos:**
- Criar: `database/migrations/2026_11_18_000001_add_platform_role_to_users.php`, `app/Platform/PlatformUserService.php`, `app/Http/Middleware/EnsurePlatformAdmin.php`, `app/Http/Controllers/Admin/PlatformUserController.php`, `resources/views/admin/users/index.blade.php`
- Modificar: `app/Models/User.php`, `app/Http/Middleware/EnsureSuperAdmin.php`, `app/Http/Middleware/EnsureTenantActive.php`, `app/Http/Controllers/Web/ProfileController.php:157`, `bootstrap/app.php`, `routes/web.php`, `resources/views/layouts/partials/sidebar.blade.php`, `resources/views/layouts/app.blade.php`, `app/Team/TeamService.php` (aceitar convite de plataforma reutiliza `accept`)
- Teste: `tests/Feature/PlatformUsersTest.php`

**Interfaces:**
- `users.platform_role` (string, nulo: `admin`|`super_admin`), `users.platform_granted_by` (FK nula), `users.platform_granted_at` (nulo).
- `User::isRootAdmin(): bool` (e-mail em `config('platform.super_admins')`), `User::isSuperAdmin(): bool` (raiz **ou** `platform_role === 'super_admin'`, e ativo), `User::isPlatformAdmin(): bool` (super admin **ou** `platform_role === 'admin'`, e ativo).
- Middleware `platform` (admin ou super; senão 404) e `super.admin` (só super; admin comum recebe 403, os demais 404).
- `PlatformUserService::grant(User $actor, User $target, string $role): void`, `revoke(User $actor, User $target): void`, `invite(User $actor, string $name, string $email, string $role): array{0:User,1:string}`. Erros `FlowException`: `not_super_admin`, `cannot_change_self`, `cannot_change_root`, `invalid_role`, `email_taken`, `user_not_found`.
- Rotas (grupo `admin.`): `users.index` (GET `/admin/usuarios`), `users.grant` (POST, promove por e-mail), `users.invite` (POST), `users.role` (PUT `/admin/usuarios/{user}`, muda entre admin e super admin), `users.revoke` (DELETE `/admin/usuarios/{user}`, tira o papel; **não apaga a conta**).

- [ ] **Passo 1: testes que falham.** Super admin promove conta existente a admin e a super admin; convida novo admin (e-mail com link, aceita, entra com o papel e cai no painel da plataforma); admin comum vê Clientes/Saúde/Selagem mas leva **403** em usuários, planos, e-mail e configurações; usuário comum leva **404** em `/admin`; ninguém altera raiz nem a si mesmo; rebaixar/revogar reflete na hora (próxima requisição já sem acesso); conta desativada ou empresa suspensa perde o painel; lista mostra raiz (marcado "fixo"), super admins e admins com quem concedeu e quando.
- [ ] **Passo 2:** `php artisan test tests/Feature/PlatformUsersTest.php` — esperado: **FALHA** (rotas e métodos não existem).
- [ ] **Passo 3: implementar** migração, os três métodos do `User`, os dois middlewares, o serviço (grava `platform_granted_by/at`; convite reaproveita o fluxo de `TeamService` criando conta com empresa própria), controller, tela (lista em cartões no celular, popup de promover/convidar em tela cheia no celular, confirmação ao revogar) e o menu **Usuários da plataforma** só para super admin; trocar o uso de `isSuperAdmin()` por `isPlatformAdmin()` no menu "Plataforma", em `EnsureTenantActive` e em `ProfileController`.
- [ ] **Passo 4:** rodar o teste e `php artisan test` — esperado: **PASSA**.
- [ ] **Passo 5: commit e push** `Usuários da plataforma: admins e super admins`.

---

### Tarefa 2: Planos, preços e assinaturas (modelo e plano "Legado")

**Arquivos:**
- Criar: migrações `2026_11_18_000002_create_plans_tables.php` (`plans`, `plan_prices`) e `2026_11_18_000003_create_subscriptions_and_usage.php` (`subscriptions`, `usage_entries`, `tenants.storage_bytes`), modelos `Plan`, `PlanPrice`, `Subscription`, `UsageEntry`, `app/Plans/PlanService.php`
- Modificar: `app/Models/Tenant.php` (`subscription()`), `AuthController::register` (nova empresa recebe o plano padrão e o teste)
- Teste: `tests/Feature/PlanModelTest.php`

**Interfaces:**
- `plans`: `id`, `name`, `slug` (único), `description`, `active`, `is_public`, `is_default`, `position`, `limits` (JSON: `documents`, `users`, `storage_mb`, `max_pdf_mb`; ausente/nulo = ilimitado), `trial` (JSON: `days`, `requires_payment`, `on_end` = `convert`|`free_plan`|`block`, `limits`), `consumption` (JSON: `when` = `send`|`complete`, `refund_unsigned` bool, `balance` = `quota`|`credits`|`both`, `quota_amount`, `on_empty` = `block`|`overage`|`cap`, `overage_cents`, `cap`), `features` (JSON de booleanos: `whatsapp`, `api`, `webhooks`, `templates`, `positioning`, `ordered_signers`, `selfie`, `timestamp`, `reports`, `white_label`).
- `plan_prices`: `plan_id`, `period` (`month`|`quarter`|`semester`|`year`), `amount_cents`, `discount_label`, `active`; único por `(plan_id, period)`.
- `subscriptions`: `tenant_id` (único), `plan_id`, `status` (`trialing`|`active`|`blocked`|`canceled`), `billing_exempt` bool, `trial_ends_at`, `period_start`, `period_end`, `overrides` (JSON com as mesmas chaves de `limits`, `features`, `consumption`, `price_cents`), `credits_balance` int, `quota_used` int, `notes`.
- `usage_entries`: `tenant_id`, `envelope_id` (nulo), `kind` (`quota`|`credit`|`refund`|`adjust`), `delta`, `balance_after`, `note`, `occurred_at` (acrescentar apenas).
- `PlanService::legacy(): Plan` (cria se faltar: ilimitado, tudo ligado), `defaultPlan(): Plan`, `assign(Tenant $t, Plan $p, array $overrides = []): Subscription`, `effective(Subscription $s): array{limits:array,features:array,consumption:array}` (plano + sobrescritas, sobrescrita vence).

- [ ] **Passo 1: testes que falham.** Migração põe **toda empresa existente** no plano Legado (ilimitado, `billing_exempt`); empresa nova recebe o plano padrão (e entra em teste se o plano tem dias de teste); `effective()` aplica sobrescrita por chave sem perder as demais; valores guardados em centavos inteiros e recusam negativos; slug único; só um plano padrão.
- [ ] **Passo 2:** `php artisan test tests/Feature/PlanModelTest.php` — esperado: **FALHA**.
- [ ] **Passo 3: implementar** as migrações (a segunda cria o Legado e as assinaturas num único passo, em lote) e os modelos com casts JSON e validação nos serviços.
- [ ] **Passo 4:** o teste e a suíte completa — esperado: **PASSA**.
- [ ] **Passo 5: commit e push** `Planos, preços e assinaturas: modelo e plano Legado`.

---

### Tarefa 3: `Entitlements` e extrato de uso (limites de documentos e usuários)

**Arquivos:**
- Criar: `app/Plans/Entitlements.php`, `app/Plans/UsageLedger.php`, `app/Plans/Decision.php`
- Modificar: `app/Envelopes/EnvelopeService.php` (criar/enviar), `app/Http/Controllers/Api/V1/EnvelopeController.php`, `app/Http/Controllers/Web/EnvelopeController.php`, `app/Http/Controllers/Web/TemplateController.php`, `app/Http/Controllers/Api/V1/TemplateController.php`, `app/Team/TeamService.php` (convite respeita o limite de usuários), `app/Api/Presenter.php` e `OpenApiSpec.php` (`GET /me` com plano e consumo)
- Teste: `tests/Feature/EntitlementsTest.php`

**Interfaces:**
- `Decision` (somente leitura): `allowed: bool`, `code: string` (`ok`|`documents_limit`|`users_limit`|`storage_limit`|`pdf_too_large`|`feature_off`|`blocked`), `message: string` em português.
- `Entitlements::for(Tenant $tenant): self` com `canSendDocument(): Decision`, `canInviteUser(): Decision`, `canUpload(int $bytes): Decision`, `feature(string $key): bool`, `usage(): array{documents:{used,limit},users:{used,limit},storage:{used_bytes,limit_bytes}}`.
- `UsageLedger::charge(Envelope $e, string $when): void` e `refund(Envelope $e): void`: dentro de transação com trava de linha na assinatura (`lockForUpdate`), descontando da cota e/ou dos créditos conforme `consumption`, gravando `usage_entries` com saldo antes e depois; idempotente por envelope.
- Resposta de bloqueio: web volta ao formulário com a mensagem; API responde **403** `{error: {code: 'plan_limit_reached', reason: <code>, message}}`.

- [ ] **Passo 1: testes que falham.** Sem limite (Legado) nada muda; com cota de N documentos o N+1-ésimo **envio** é bloqueado (rascunho continua livre); dois envios simultâneos com 1 de saldo (simulados em transação) deixam passar só um; cancelado/expirado sem assinatura devolve o saldo quando o plano pede; `when=complete` desconta só ao concluir; créditos que não renovam e cota renovável consomem na ordem certa; `on_empty=overage` libera e registra; limite de usuários bloqueia o convite (conta pendentes); API e web respondem como definido; assinar, baixar e verificar **nunca** são bloqueados mesmo com limite estourado; extrato mostra data, envelope, saldo antes e depois.
- [ ] **Passo 2:** o teste — esperado: **FALHA**.
- [ ] **Passo 3: implementar** serviço, extrato e a chamada nos pontos acima (um único ponto de decisão por fluxo; o envio por modelo e o envio da API passam pelo mesmo caminho).
- [ ] **Passo 4:** o teste e a suíte completa — esperado: **PASSA**.
- [ ] **Passo 5: commit e push** `Entitlements: limites de documentos e usuários com extrato de uso`.

---

### Tarefa 4: Armazenamento, tamanho do PDF e avisos de 80% e 100%

**Arquivos:**
- Criar: `app/Plans/StorageUsage.php`, `app/Console/Commands/ReconcileStorage.php`, `app/Mail/PlanUsageWarningMail.php`, `resources/views/mail/plan-usage-warning.blade.php`, `resources/views/layouts/partials/plan-banner.blade.php`
- Modificar: pontos que gravam ou apagam arquivo (localizar com `grep -rn "Storage::" app/`: upload do PDF, PDF selado, anexos do signatário, lixeira definitiva), `app/Http/Controllers/Web/EnvelopeController.php` e API (limite de tamanho do PDF), `routes/console.php` (conciliação noturna), layout (faixa de aviso)
- Teste: `tests/Feature/StorageLimitTest.php`

**Interfaces:**
- `StorageUsage::add(Tenant, int $bytes): void`, `remove(Tenant, int $bytes): void`, `reconcile(Tenant): int` (soma real do que está gravado e corrige `tenants.storage_bytes`); comando `storage:reconcile`.
- `Entitlements::canUpload()` (Tarefa 3) passa a considerar `storage_bytes` e `max_pdf_mb`.
- Aviso por e-mail **uma vez** ao cruzar 80% e uma ao chegar a 100%, por período (marca em `subscriptions`: `warned_80_at`, `warned_100_at`), e faixa no painel enquanto estiver acima.

- [ ] **Passo 1: testes que falham.** Cada gravação soma e cada remoção devolve; conciliação corrige contador errado; PDF acima do tamanho do plano recusado com mensagem clara; estouro de armazenamento bloqueia **novos** envios mas permite baixar e assinar; faixa aparece a 80% e 100%; e-mail sai uma vez por limite e período; empresa com Legado nunca vê faixa.
- [ ] **Passo 2:** o teste — esperado: **FALHA**.
- [ ] **Passo 3: implementar** o serviço, ligar nos pontos de gravação/remoção (um único helper, sem espalhar contas), o comando agendado e a faixa.
- [ ] **Passo 4:** o teste e a suíte completa — esperado: **PASSA**.
- [ ] **Passo 5: commit e push** `Armazenamento, tamanho do PDF e avisos de limite`.

---

### Tarefa 5: Marca própria — configuração, painel e página de assinatura

**Arquivos:**
- Criar: migração `2026_11_18_000004_add_branding_to_tenants.php` (`brand_name`, `brand_logo_path`, `brand_primary`, `brand_accent`), `app/Branding/BrandService.php`, `app/Branding/Contrast.php`, `app/Http/Controllers/Web/BrandController.php`, `resources/views/brand/edit.blade.php`
- Modificar: `layouts/app.blade.php` e `partials/head.blade.php` (variáveis de cor e logo), a página de assinatura do signatário, sidebar (item **Marca própria** em Configurações, visível só com o recurso `white_label`)
- Teste: `tests/Feature/BrandingTest.php`

**Interfaces:**
- `Contrast::ratio(string $fg, string $bg): float`, `Contrast::nearestAccessible(string $hex, string $bg, float $min = 4.5): string`.
- `BrandService::save(Tenant, array $data, ?UploadedFile $logo): void` (logo PNG/JPG/WebP até 2 MB, **reprocessado** em GD como a foto de perfil, SVG recusado; cores `#RRGGBB`; cor sem contraste vira o tom mais próximo que passa, e a tela avisa), `BrandService::for(Tenant): array{name,logo_url,primary,accent}`, `remove(Tenant)`.
- Tela com **prévia ao vivo**, popup/tela cheia no celular, campo do nome exibido no lugar de "Assinaturas".
- Sem o recurso `white_label` no plano: a tela mostra o que é e como contratar (Fase 4); sem acesso para salvar.

- [ ] **Passo 1: testes que falham.** Salvar logo válido grava só imagem reprocessada; SVG, arquivo > 2 MB e extensão trocada recusados; cor ruim ajustada para ≥ 4,5:1 contra fundo claro e escuro; sem o recurso, 403; painel e página de assinatura usam a marca da empresa certa e **nunca** a de outra; remover volta ao padrão; só o responsável edita.
- [ ] **Passo 2:** o teste — esperado: **FALHA**.
- [ ] **Passo 3: implementar** serviço, tela, variáveis CSS e logo no painel e na página do signatário.
- [ ] **Passo 4:** o teste e a suíte completa — esperado: **PASSA**.
- [ ] **Passo 5: commit e push** `Marca própria: logo, cores e nome no painel e na assinatura`.

---

### Tarefa 6: Marca própria nos e-mails e no cabeçalho do PDF

**Arquivos:**
- Modificar: `resources/views/mail/*.blade.php` (convite, lembrete, código), `app/Mail/*` (passar a marca), gerador do relatório de assinaturas do PDF selado (localizar com `grep -rn "report" app/Signing app/Evidence`), `sealer` somente se o cabeçalho for lá
- Teste: `tests/Feature/BrandingMailTest.php`

**Interfaces:** `BrandService::for($envelope->tenant)` alimenta os e-mails e o cabeçalho do relatório (nome e logo). Sem o recurso, tudo como hoje.

- [ ] **Passo 1: testes que falham.** E-mail de convite de empresa com marca traz o nome dela e a cor do botão com contraste ≥ 4,5:1; sem marca, igual ao atual; o cabeçalho do relatório usa nome e logo; falha ao ler o logo não derruba o envio nem a selagem (cai no padrão).
- [ ] **Passo 2:** o teste — esperado: **FALHA**.
- [ ] **Passo 3: implementar** nas views de e-mail e no gerador do relatório, com queda segura para o padrão.
- [ ] **Passo 4:** o teste e a suíte completa — esperado: **PASSA**.
- [ ] **Passo 5: commit e push** `Marca própria nos e-mails e no relatório do PDF`.

---

### Tarefa 7: Telas — Planos (super admin), Assinaturas (admin) e "Plano e uso" da empresa

**Arquivos:**
- Criar: `app/Http/Controllers/Admin/PlanController.php`, `app/Http/Controllers/Admin/SubscriptionController.php`, `app/Http/Controllers/Web/PlanUsageController.php`, `resources/views/admin/plans/*.blade.php`, `resources/views/admin/subscriptions/*.blade.php`, `resources/views/plan-usage/index.blade.php`
- Modificar: `routes/web.php`, sidebar (Planos e Assinaturas em Plataforma; **Plano e uso** em Configurações)
- Teste: `tests/Feature/PlanAdminTest.php`

**Interfaces:**
- Planos (só super admin): listar, criar, editar, ativar/desativar, escolher o padrão, definir limites, teste grátis, regras de consumo, recursos e preços por período; apagar só se nenhuma empresa usa (senão desativa).
- Assinaturas (admin e super admin): lista com filtro por plano e situação; detalhe da empresa com plano atual, **sobrescritas** (limites, recursos, preço), isenta de cobrança, créditos (lançar ajuste no extrato), trocar plano, extrato de uso.
- **Plano e uso** (responsável da empresa): plano atual, barras de uso de documentos, usuários e armazenamento, extrato, fim do teste.
- Validação forte (inteiros ≥ 0, JSON só com chaves conhecidas), tudo em popups em tela cheia no celular, tabelas viram cartões.

- [ ] **Passo 1: testes que falham.** CRUD de plano com validação; só super admin edita plano (admin comum 403); admin troca plano e lança crédito com registro no extrato; sobrescrita vale na hora; empresa vê só o próprio uso (outra empresa 404); plano em uso não é apagado.
- [ ] **Passo 2:** o teste — esperado: **FALHA**.
- [ ] **Passo 3: implementar** controllers e telas reutilizando os padrões de Contatos e Usuários.
- [ ] **Passo 4:** o teste e a suíte completa — esperado: **PASSA**.
- [ ] **Passo 5: commit e push** `Telas de planos, assinaturas e plano e uso`.

---

### Tarefa 8: Verificação no navegador, documentação e entrega

**Arquivos:** `docs/ESCOPO.md` (Fase 3 entregue), `docs/superpowers/specs/2026-11-13-menu-modulos-planos-design.md` (status), `docs/DEPLOY.md` (`SUPER_ADMIN_EMAILS` e criar admins pela tela).

- [ ] **Passo 1:** `php artisan test` completo com o código de saída conferido — esperado: `exit=0`.
- [ ] **Passo 2:** no navegador com dados de teste: usuários da plataforma (promover, convidar, aceitar pelo link do log, revogar), planos, assinaturas, limite estourando e a faixa de aviso, marca própria. Em **375 px** e **768 px**, nos dois temas, sem rolagem horizontal, popups em tela cheia, alvos ≥ 44 px.
- [ ] **Passo 3:** auditoria de contraste ≥ 4,5:1 nas telas novas, nos dois temas.
- [ ] **Passo 4:** documentação, commit e push (só com a suíte verde).

---

## Autorrevisão

**Cobertura do spec (fase 3, tabela 8):** planos com teste grátis e regras de consumo → T2/T3; assinaturas e sobrescritas → T2/T7; `Entitlements`, limites e armazenamento → T3/T4; marca própria → T5/T6; telas de administrador e de uso → T7; pagamento manual fica **registrado como ajuste no extrato** (T7), sem fatura (Fase 4). Pedido de 18/11 (usuários no super admin) → T1.

**Fora da fase:** faturas, gateways, cobrança, cupons, add-ons e interruptor "Cobrança ativa" (Fase 4); fim automático do teste (fica como data e regra gravadas; a rotina que age sobre elas é da Fase 4).

**Consistência:** `Entitlements::for`, `Decision`, `UsageLedger::charge/refund`, `StorageUsage::add/remove/reconcile`, `BrandService::for/save/remove` e `PlanService::effective` têm os mesmos nomes em todas as tarefas.
