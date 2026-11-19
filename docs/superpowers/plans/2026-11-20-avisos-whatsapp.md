# Avisos no WhatsApp (modelos, saldo em reais, pré e pós-pago) — plano

> Execução inline, tarefa por tarefa, com teste antes do código. Commit e push na `main` juntos.

**Meta:** o usuário vinculado recebe no WhatsApp avisos de documento (assinado por todos, alguém assinou, recusa, contrato perto de vencer, documento parado). Cada aviso usa um modelo aprovado pela Meta, configurável numa tela do super admin, e o custo é repassado com o acréscimo, em saldo em reais (pré-pago, padrão) ou pós-pago com limite, somado à mensalidade.

**Fora deste plano (próximo):** lembrete e convite ao signatário externo pelo WhatsApp (precisa de aceite do signatário) e cobrança automática pelo gateway (Fase 4).

## Regras fixas
- Super admin não recebe avisos de plataforma; empresa dele (hospeda super admin) não é cobrada (a plataforma absorve).
- O custo real vem do aviso de status da Meta (categoria que a Meta cobrou), nunca da categoria que escolhemos ao cadastrar o modelo.
- Mensagem de modelo fora da janela de 24 h exige modelo aprovado: sem modelo aprovado, o aviso fica "ignorado" (registrado, nunca enviado em texto livre).
- Nunca enviar duas vezes o mesmo aviso (chave de deduplicação única) e nunca cobrar duas vezes (débito único por mensagem).
- Textos ao usuário dizem "documento", nunca "envelope".

## Tarefa 1 — Saldo em reais e modo de cobrança
- Migração: `whatsapp_credit_entries` (id, tenant_id, kind topup|adjust|usage|settle, amount decimal(14,6) com sinal, currency, source_id nullable único por tipo usage, note, created_by), colunas em `tenants`: `wa_billing_mode` (prepaid|postpaid, padrão prepaid), `wa_credit_limit` decimal nullable.
- `App\Assistant\Metering\WhatsAppCredit`: `balance(Tenant)`, `available(Tenant)` (= saldo + limite se pós-pago), `canAfford(Tenant, float)`, `topUp/adjust/settle`, `debitUsage(Tenant, MetaMessageUsage)` idempotente, `monthUsage`.
- `Tenant::isPlatformOwned()` (tem super admin ativo; `Entitlements` passa a usar).
- `MetaUsageLedger::record` debita o saldo quando a mensagem é cobrável e a empresa não é da plataforma.
- Testes: pré-pago debita e fica negativo só pelo custo real já enviado; pós-pago respeita limite; débito idempotente; empresa da plataforma não é debitada; extrato mês.

## Tarefa 2 — Modelos de mensagem
- Catálogo em código (`NotificationCatalog`): 5 situações, rótulo, variáveis (ordem fixa), texto padrão em pt-BR em tom de utilidade.
- Tabela `whatsapp_templates` (key única, meta_name, language, category, body, enabled, status rascunho|enviado|aprovado|rejeitado|pausado, rejected_reason, meta_template_id).
- `MetaWhatsAppClient`: `sendTemplate`, `createTemplate` (precisa do WABA), `templateStatuses`.
- Tela `/admin/avisos-whatsapp` (super admin): editar cada situação, "Enviar para aprovação", "Atualizar status", ligar/desligar; validação de variáveis `{{1}}…{{n}}` sequenciais e fora do início/fim.
- Testes: validação, envio de criação com `Http::fake`, sincronização de status, acesso só do super admin.

## Tarefa 3 — Envio dos avisos
- Tabela `whatsapp_notifications` (dedupe_key único, tenant_id, user_id, template_key, status queued|sent|skipped|failed, reason, wa_message_id).
- Preferências: `users.whatsapp_notifications` json (null = todos ligados), alterável no perfil.
- `WhatsAppNotifier::notify(User, key, params, dedupeKey)`: usuário vinculado e ativo; empresa com o recurso e não da plataforma; preferência ligada; modelo aprovado; saldo (`canAfford` com o preço da categoria do modelo); reserva por dedupe; envia por job (uma tentativa).
- Ganchos: conclusão (`SealEnvelope`), assinatura e recusa (`SigningService`), vencimento (`ContractAlerts`), parado (`documents:notify-stalled`, diário, dias configuráveis na tela).
- Testes por situação, deduplicação, sem saldo, sem modelo aprovado, preferência desligada, super admin, uma tentativa só.

## Tarefa 4 — Cobrança e perfil
- Na tela do super admin: por empresa, modo, limite, saldo, extrato do mês e lançamento (recarga, ajuste, quitação do pós-pago).
- Perfil: alternar cada aviso. Painel da empresa: saldo/consumo do mês e aviso de saldo baixo.
- Docs (`DEPLOY.md`, `ASSISTENTE-PILOTO.md`, `ESCOPO.md`), suíte completa, commit e push.
