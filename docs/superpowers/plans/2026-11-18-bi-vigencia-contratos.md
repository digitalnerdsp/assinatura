# B.I. de vigência de contratos — plano de implementação

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:executing-plans (execução direta, uma tarefa por vez, TDD, commit e push em cada uma).

**Goal:** Quem cria o documento informa, no passo 4, a data de vencimento do contrato e quem avisar; o sistema avisa por e-mail e mostra uma tela de B.I. com vencidos, a vencer e vigentes.

**Architecture:** Colunas no `envelopes` (`contract_expires_on`, `contract_alert_days`, `contract_alert_emails`) + tabela `contract_alerts` (registro de aviso enviado). Serviço `App\Contracts\ContractWatch` concentra regras e consultas. Comando diário `contracts:send-alerts` manda o e-mail. Tela `/contratos` (recurso de plano `reports`).

**Tech Stack:** Laravel 13, PostgreSQL, Pest 4, Blade (telas internas em `.legacy-scope`), Chart.js (já usado no painel).

**Spec:** `docs/superpowers/specs/2026-11-18-bi-vigencia-contratos-design.md`

## Global Constraints
- Datas em Brasília (`America/Sao_Paulo`), vencimento vale até o fim do dia; "hoje" = dia de Brasília.
- Só documentos `completed`, fora da lixeira (`trashed_at` nulo), da empresa do usuário, com `contract_expires_on` preenchido. Arquivados continuam monitorados.
- Aviso só para os e-mails indicados (1 a 5, únicos, minúsculos); dias de aviso ∈ {7, 15, 30, 60, 90}; vencimento depois de hoje e no máximo 20 anos à frente.
- Janela "a vencer" = `max(30, contract_alert_days)`. Faixas por dias restantes `d`: vencido `d < 0`; até 30 `0 ≤ d ≤ 30`; 31–60; 61–90; vigente `d > 90`.
- Visual: português, contraste ≥ 4,5:1, claro e escuro, CSS escopado com `.legacy-scope`/prefixo próprio. Cada tarefa termina com commit e push na main; a main deve ficar sempre implantável.
- Nomes de helpers de teste únicos (prefixo `ct`); testes em `tests/Feature/Contract*Test.php`.

## Review Focus
1. Troca de fuso perto da meia-noite de Brasília (dias restantes e "vence hoje").
2. Renovar a data: avisos antigos não podem impedir os novos; remover a vigência apaga o monitoramento.
3. Aviso nunca duplicado (duas execuções no mesmo dia) e nunca para quem não foi indicado.
4. Documento de outra empresa, na lixeira, cancelado ou não concluído jamais entra na tela nem dispara e-mail.
5. Falha de e-mail não marca como enviado e não derruba a rotina inteira.

---

### Task 1: Dados e serviço `ContractWatch`
**Files:** Create `database/migrations/2026_11_24_000001_contract_watch.php`, `app/Models/ContractAlert.php`, `app/Contracts/ContractWatch.php`; Modify `app/Models/Envelope.php` (casts, `contractAlerts()`); Test `tests/Feature/ContractWatchTest.php`.
**Interfaces (produz):**
- `ContractWatch::ALERT_DAYS = [7,15,30,60,90]`, `MAX_EMAILS = 5`, `MIN_WINDOW = 30`.
- `setTerm(Envelope $e, string $dueOn, int $alertDays, array $emails): void` — valida (FlowException `invalid_term`), grava e apaga `contract_alerts` do documento.
- `clearTerm(Envelope $e): void`.
- `today(): CarbonImmutable` (Brasília, início do dia); `daysLeft(Envelope $e, ?CarbonImmutable $today = null): ?int`; `band(int $d): 'overdue'|'d30'|'d60'|'d90'|'valid'`; `windowDays(Envelope $e): int`; `dueSoon(Envelope $e, ?CarbonImmutable $today = null): bool`.
- `monitored(Tenant $t): Builder<Envelope>` (concluídos, fora da lixeira, com vencimento).
- [ ] Testes: validação (data passada/hoje/20 anos+, dias fora da lista, 0 e 6 e-mails, e-mail inválido, duplicados), gravar normaliza e-mails, renovar apaga avisos, remover zera colunas, `daysLeft` com `Carbon::setTestNow` em 23h30 e 00:30 de Brasília, limites das faixas (-1, 0, 30, 31, 60, 61, 90, 91), `windowDays` (30 e 90), `monitored` exclui rascunho, em andamento, cancelado, lixeira e outra empresa e inclui arquivado.
- [ ] Rodar (falha) → implementar → rodar (passa) → suíte → commit e push.

### Task 2: Bloco no passo 4
**Files:** Modify `DocumentWizardController::applyStep4/data`, `resources/views/documents/wizard/step4.blade.php`, `public/assets/js/wizard-send.js`, `wizard.css`; Test `tests/Feature/ContractStep4Test.php`.
**Consome:** `ContractWatch::setTerm/clearTerm`. **Campos do formulário:** `watch` (0|1), `contract_date` (`Y-m-d`), `contract_alert_days`, `contract_emails[]`.
- [ ] Testes: desligado não grava nada e limpa vigência antiga; ligado grava; e-mail do criador vem pré-preenchido; data passada/inválida, dias inválidos, sem e-mail, 6 e-mails dão erro e **nada** é enviado; "Voltar" grava o que for válido sem bloquear; o bloco reaparece preenchido; envio concluído guarda a vigência no documento.
- [ ] Implementar (bloco só aparece no passo 4; checkbox mostra/oculta os campos; "+ outro e-mail" até 5) → verificar no navegador → suíte → commit e push.

### Task 3: Cartão "Vigência do contrato" no detalhe
**Files:** Create `app/Http/Controllers/Web/ContractTermController.php`; Modify `routes/web.php` (`PUT/DELETE /documentos/{envelope}/vigencia`, nomes `envelopes.term.save` e `envelopes.term.clear`), `resources/views/envelopes/show.blade.php`; Test `tests/Feature/ContractTermTest.php`.
- [ ] Testes: cartão aparece para concluído/em andamento (não rascunho, não lixeira); salvar, renovar (zera avisos), remover; outra empresa = 404; erros mostram a mensagem; rascunho recusa (use o assistente).
- [ ] Implementar → navegador → suíte → commit e push.

### Task 4: Tela Contratos (B.I.)
**Files:** Create `app/Http/Controllers/Web/ContractController.php`, `resources/views/contracts/index.blade.php`; Modify `routes/web.php` (`GET /contratos`, `/contratos/exportar`, `feature:reports`, nomes `contracts.index`, `contracts.export`), `app/Themes/MenuBuilder.php` e `layouts/partials/sidebar.blade.php` (item "Contratos"); Test `tests/Feature/ContractBiTest.php`.
**Consome:** `ContractWatch::monitored/daysLeft/band/windowDays`. **Parâmetros:** `faixa` (`avencer` padrão | `vencidos` | `ate30` | `31a60` | `61a90` | `vigentes` | `todos`), `folder`, `tag`, `q`.
- [ ] Testes: contagens por faixa; padrão "a vencer" mostra vencidos + janela (30 ou o aviso maior); filtros; ordenação por data; só da empresa e só concluídos; CSV (cabeçalho, BOM, células seguras, mesmos filtros); gráfico recebe 12 meses; plano sem `reports` vê a página de upgrade; menu mostra o item.
- [ ] Implementar (cartões clicáveis, tabela com dias e cor, gráfico Chart.js) → navegador (claro/escuro, 375 px) → suíte → commit e push.

### Task 5: Painel inicial
**Files:** Modify `DashboardController`, `resources/views/dashboard.blade.php`, `resources/views/layouts/partials/plan-banner.blade.php`; Test `tests/Feature/ContractDashboardTest.php`.
- [ ] Testes: cartão "Contratos a vencer" com contagens e até 5 mais próximos; faixa de alerta só no painel quando há vencido ou vence em até 7 dias; some sem vigência; outra empresa não vaza; variável `contracts` chega às telas dos temas.
- [ ] Implementar → navegador → suíte → commit e push. (Os dashboards dos temas Able/W3 recebem a faixa pelo `$flash`; o cartão fica na tela de reserva até uma nova versão dos temas.)

### Task 6: Rotina e e-mail de aviso
**Files:** Create `app/Console/Commands/SendContractAlerts.php`, `app/Mail/ContractExpiryMail.php`, `resources/views/mail/contract-expiry.blade.php`; Modify `routes/console.php` (diário 11:00 UTC = 08:00 Brasília); Test `tests/Feature/ContractAlertsTest.php`.
**Assinatura:** comando `contracts:send-alerts`; `ContractExpiryMail(Envelope $e, string $kind /* before|due */, int $daysLeft)`.
- [ ] Testes: manda "before" quando faltam ≤ X dias e só uma vez; manda "due" no dia (e se a rotina perdeu o dia) uma vez; só aos e-mails indicados; ignora cancelado, em andamento, lixeira e sem vigência; renovar a data libera novos avisos; falha no e-mail não grava aviso e não impede os demais; segunda execução no mesmo dia não duplica; fuso (23h30 e 00:30 de Brasília); e-mail tem título, data, dias e links.
- [ ] Implementar → suíte → commit e push.

### Task 7: Fechamento
- [ ] Documentar em `docs/ESCOPO.md` e `docs/DEPLOY.md` (agendador, horário); suíte inteira; conferir no navegador o fluxo completo (passo 4 → concluir → tela Contratos); revisão independente do diff; corrigir achados importantes com teste; commit e push.
