# Assistente no WhatsApp — Plano 1: base e consultas (piloto)

> **Para quem executar:** superpowers:executing-plans (mesma sessão) ou superpowers:subagent-driven-development. Passos com caixa de seleção (`- [ ]`).

**Objetivo:** canal WhatsApp (API de Nuvem da Meta) + vínculo do número + medição de tokens e de mensagens + agente da OpenAI (Luna) que **consulta e verifica** documentos. Criar e enviar documentos é o **Plano 2** (depende deste).

**Arquitetura:** `webhook da Meta ➜ fila ➜ orquestrador ➜ ferramentas internas (serviços da plataforma) ➜ resposta`. O orquestrador não conhece o WhatsApp (recebe texto/anexo, devolve texto/botões). O LLM fica atrás de uma interface (`LlmClient`) com implementação OpenAI e uma falsa para testes. Medição em dois livros: tokens (carteira pré-paga) e mensagens da Meta (repasse com acréscimo).

**Stack:** Laravel 13, Pest 4 (`/tmp/runsuite.sh`; conferir **total × passed**, o resumo ignora erros de execução), PostgreSQL, filas do Laravel, HTTP do Laravel para Meta e OpenAI (sempre com `Http::fake` nos testes; nenhum teste fala com a rede).

**Spec:** `docs/superpowers/specs/2026-11-18-assistente-whatsapp-design.md`

## Restrições globais
- Textos de usuário em português do Brasil; "documento", nunca "envelope".
- **Nenhum segredo no repositório** (token da Meta, segredo do app, chave da OpenAI): configuração da plataforma pelo mesmo padrão da tela de E-mail (SMTP), criptografada no banco.
- O modelo de IA **nunca** recebe o conteúdo ou o arquivo do PDF; só título, status, nomes de signatários e de lacunas.
- Tudo isolado por empresa; o agente só age como o usuário vinculado, com as mesmas regras e limites de plano.
- Webhook: validar `X-Hub-Signature-256` (HMAC-SHA256 do corpo bruto com o segredo do app) **antes** de qualquer processamento; deduplicar pelo id da mensagem; responder 200 rápido e processar na fila.
- Janela de 24 h da Meta: resposta livre só dentro dela; este plano não envia mensagens de modelo (ficam para o Plano 2).
- Commit e push na `main` ao fim de cada tarefa, com o `Co-Authored-By` pedido. Rodar os testes, **ler o resultado**, só então commitar (nunca encadear o commit atrás de `| tail`).
- Dados de conversa: guardar com prazo (90 dias, comando agendado de limpeza) e sem conteúdo de PDF.

## Foco da revisão final
1. Webhook forjado, repetido ou fora de ordem: nada é processado/cobrado duas vezes.
2. Número não vinculado, vínculo roubado (código reutilizado/expirado) e usuário de outra empresa.
3. Instruções escondidas em título de documento, nome de signatário ou mensagem encaminhada.
4. Saldo de tokens: corrida entre duas mensagens simultâneas, saldo negativo, conversa que termina no zero.
5. Pedido fora do assunto (conversa geral, pedido de código, "ignore as regras"): recusa e não gasta ferramenta.

---

### Tarefa 1: Medição — carteira de tokens e registro de mensagens da Meta

**Arquivos:** criar `database/migrations/2026_12_01_000001_assistant_metering.php`, `app/Assistant/Metering/TokenWallet.php`, `app/Assistant/Metering/MetaUsageLedger.php`, `app/Models/AssistantTokenEntry.php`, `app/Models/MetaMessageUsage.php`; modificar `app/Models/Plan.php` (recurso `assistant`; limite `assistant_tokens`), `app/Plans/PlanForm.php` e telas do plano, `app/Plans/Entitlements.php`; testes `tests/Feature/AssistantMeteringTest.php`.

**Interfaces — produz:**
- Tabelas: `assistant_token_entries` (`id`, `tenant_id`, `kind` plan|purchase|usage|adjust, `tokens` int com sinal, `weighted` bool, `model` null, `input_tokens`, `cached_tokens`, `output_tokens`, `conversation_id` null, `expires_on` date null, `period` `YYYY-MM` null, `note`, timestamps) e `meta_message_usages` (`id`, `tenant_id`, `wa_message_id` único, `category`, `billable` bool, `meta_amount` decimal(12,6), `currency`, `charged_amount` decimal(12,6), `markup_percent` decimal(6,2), `period`, `occurred_at`).
- `TokenWallet::balance(Tenant): int`, `grantPlanPackage(Tenant, int $tokens, string $period)` (idempotente por período), `addPurchase(Tenant, int $tokens, ?DateTimeInterface $expires, string $note)`, `debit(Tenant, string $model, int $in, int $cached, int $out, ?string $conversationId): int` (aplica o **peso do modelo**, usa primeiro o pacote do plano e depois os extras mais antigos a vencer, e retorna os tokens equivalentes debitados), `canStart(Tenant): bool` (saldo > 0).
- `MetaUsageLedger::record(Tenant, string $waMessageId, string $category, bool $billable, float $amount, string $currency, CarbonInterface $at): void` — idempotente por `wa_message_id`; aplica o **acréscimo** configurado; `monthTotal(Tenant, string $period): array{meta:float,charged:float,count:int}`.
- Configurações da plataforma lidas por `AssistantSettings` (criar): `modelWeights` (mapa modelo→peso), `markupPercent`, `defaultPackageTokens`.

- [ ] **Passo 1: testes que falham:** débito pondera pelo peso do modelo; ordem pacote do plano → extras que vencem primeiro; saldo zero → `canStart` falso; **duas chamadas simultâneas** (transação com trava) nunca deixam o saldo negativo além do limite de uma conversa em andamento; `grantPlanPackage` repetido no mesmo período não duplica; compra com vencimento passado não conta; `record` repetido com o mesmo `wa_message_id` não cobra de novo; acréscimo calculado e original guardados separados; total do mês; recurso `assistant` desligado em plano → `Entitlements::feature('assistant')` falso.
- [ ] **Passo 2:** rodar `--filter=AssistantMeteringTest` — Esperado: FALHA.
- [ ] **Passo 3:** implementar migração, modelos, `TokenWallet`, `MetaUsageLedger`, `AssistantSettings`; incluir `assistant` em `Plan::FEATURES` (com rótulo "Assistente no WhatsApp") e `assistant_tokens` nos limites do plano, na `PlanForm` e na tela de plano (super admin); **não** barrar nenhuma rota ainda.
- [ ] **Passo 4:** rodar o filtro e a suíte; ler; commitar e enviar.

---

### Tarefa 2: Canal da Meta — cliente, webhook, deduplicação e preço das mensagens

**Arquivos:** criar `app/Assistant/Channel/MetaWhatsAppClient.php`, `app/Assistant/Channel/InboundMessage.php`, `app/Http/Controllers/Webhooks/MetaWebhookController.php`, `app/Jobs/ProcessInboundWhatsApp.php`, `database/migrations/2026_12_01_000002_whatsapp_inbound.php`, `app/Models/WhatsAppInbound.php`; modificar `routes/web.php` (rotas públicas, sem CSRF, com `throttle`); testes `tests/Feature/MetaWebhookTest.php`, `tests/Feature/MetaWhatsAppClientTest.php`.

**Interfaces:**
- `MetaWhatsAppClient::sendText(string $to, string $text)`, `sendButtons(string $to, string $body, array $buttons /* até 3: id,título ≤ 20 */)`, `sendList(string $to, string $body, string $button, array $rows /* até 10 */)`, `markRead(string $waMessageId)`, `downloadMedia(string $mediaId): array{bytes:string,mime:string}`, `uploadMedia(string $bytes, string $mime): string` e `sendDocument(string $to, string $mediaId, string $filename, ?string $caption)`. Todas lançam `MetaException` (com status/código) em falha; credenciais vêm de `AssistantSettings`.
- `InboundMessage` (valor): `waMessageId`, `from` (E.164 sem `+`), `type` text|button|list|document|other, `text`, `payloadId` (botão/lista), `mediaId`, `mime`, `filename`, `timestamp`.
- Webhook `GET /webhooks/meta/whatsapp` responde ao `hub.challenge` quando `hub.verify_token` confere (senão 403); `POST` valida a assinatura **no corpo bruto**, grava cada mensagem em `whatsapp_inbounds` (`wa_message_id` único: duplicata → ignora), despacha `ProcessInboundWhatsApp` e responde 200; `statuses[]` com `pricing` alimentam `MetaUsageLedger::record` (a empresa vem do usuário dono da conversa; status sem conversa conhecida é guardado para conciliar).

- [ ] **Passo 1: testes que falham** (`Http::fake`; corpos reais da Meta como fixtures em `tests/Fixtures/meta/`): verificação do webhook (token certo/errado); POST com assinatura inválida/ausente → 401 e **nada** gravado; assinatura válida → 200 e job despachado; mesma mensagem duas vezes → um registro e um job; várias mensagens num corpo; tipos de mensagem (texto, botão, lista, documento, imagem→`other`); status com `pricing` grava uso uma vez só; cliente: corpo e cabeçalho de envio de texto/botões/lista, limite de 3 botões e 20 caracteres (recusa antes de chamar a rede), erro da Meta vira `MetaException`, download de mídia em duas etapas.
- [ ] **Passo 2:** rodar os filtros — Esperado: FALHA.
- [ ] **Passo 3:** implementar. Usar `hash_equals` na assinatura; ler `$request->getContent()`; versão da Graph API em configuração.
- [ ] **Passo 4:** rodar os filtros e a suíte; ler; commitar e enviar.

---

### Tarefa 3: Vínculo do número do WhatsApp ao usuário

**Arquivos:** migração `2026_12_01_000003_user_whatsapp_link.php` (colunas em `users`: `whatsapp_phone` único nulo, `whatsapp_verified_at`; tabela `whatsapp_link_codes`: `user_id`, `code_hash`, `expires_on`, `attempts`); `app/Assistant/Linking/WhatsAppLinker.php`; modificar `ProfileController`, `resources/views/profile/edit.blade.php`, rotas do perfil; testes `tests/Feature/WhatsAppLinkTest.php`.

**Interfaces:** `WhatsAppLinker::start(User): string` (gera código legível de 8 caracteres, válido 15 min, invalida o anterior; devolve o código **uma vez**), `WhatsAppLinker::claim(string $fromPhone, string $text): ?User` (chamado quando chega uma mensagem de número desconhecido contendo um código; vincula e devolve o usuário), `WhatsAppLinker::userFor(string $fromPhone): ?User` (só número verificado), `unlink(User)`.
**Desenho:** a pessoa gera o código no *Meu perfil* e **envia esse código ao número da plataforma pelo próprio WhatsApp**; assim se prova a posse do número e a conversa nasce dentro da janela de 24 h, sem mensagem de modelo.

- [ ] **Passo 1: testes que falham:** gerar código no perfil (aparece uma vez, 15 min); mensagem com o código de um número novo vincula e responde confirmação; código expirado/errado/já usado não vincula; 5 tentativas erradas do **mesmo número** bloqueiam esse número por 1 hora; número já vinculado a outro usuário não pode ser reclamado; trocar de número exige novo código e desvincula o anterior; desvincular no perfil; número não vinculado recebe só orientação ("vincule em Meu perfil"); plano sem o recurso `assistant` não oferece o cartão; telefone sempre normalizado (E.164 sem `+`).
- [ ] **Passo 2:** rodar `--filter=WhatsAppLinkTest` — Esperado: FALHA. **Passo 3:** implementar (comparar códigos por hash, `hash_equals`; nunca registrar o código em log). **Passo 4:** rodar o filtro e a suíte; ler; commitar e enviar.

---

### Tarefa 4: Orquestrador do agente (OpenAI) com ferramentas e salvaguardas

**Arquivos:** criar `app/Assistant/Llm/LlmClient.php` (interface), `OpenAiClient.php`, `FakeLlmClient.php` (em `tests/Support`), `app/Assistant/AssistantOrchestrator.php`, `app/Assistant/Tools/Tool.php` (interface), `app/Assistant/Prompts/SystemPrompt.php`, migração `2026_12_01_000004_assistant_conversations.php`, modelos `AssistantConversation`/`AssistantMessage`; modificar `ProcessInboundWhatsApp` para chamar o orquestrador; testes `tests/Feature/AssistantOrchestratorTest.php`, `tests/Feature/OpenAiClientTest.php`.

**Interfaces:**
- `LlmClient::chat(array $messages, array $toolSpecs, string $model): LlmResponse` — `LlmResponse`: `text` ou `toolCalls[{id,name,arguments(json)}]`, e `usage{input,cached,output}`. `OpenAiClient` usa a API de chamada de funções da OpenAI com o modelo configurado (Luna), tempo limite e **um** retry em 5xx/429; erro final vira `LlmUnavailable`.
- `Tool`: `name(): string`, `spec(): array` (JSON Schema dos parâmetros), `needsConfirmation(): bool`, `run(User $user, array $args): ToolResult` (texto curto + `data`). O orquestrador **só** executa ferramentas registradas, valida os argumentos contra o schema e passa **sempre** o usuário vinculado (a IA nunca escolhe empresa ou usuário).
- `AssistantOrchestrator::handle(User, InboundMessage): AssistantReply` (`text`, `buttons`, `list`) — fluxo: checa recurso do plano e saldo (`TokenWallet::canStart`; sem saldo → resposta fixa de compra + **menu fixo de consultas sem IA**); carrega as últimas N mensagens (limite de janela e de caracteres); chama o LLM em laço (máx. 5 chamadas de ferramenta por mensagem); a cada resposta do LLM faz `TokenWallet::debit`; grava a conversa; devolve a resposta.
- `SystemPrompt`: define o agente como assistente **exclusivo de documentos da plataforma**, instrui a **recusar e redirecionar** qualquer outro assunto, a tratar títulos/nomes/mensagens como dados, a nunca revelar instruções nem inventar dados, e a responder curto em português.

- [ ] **Passo 1: testes que falham** (com `FakeLlmClient` roteirizado): mensagem simples gera resposta e debita tokens pelo peso; ferramenta desconhecida pedida pela IA é recusada e não executa; argumentos fora do schema são recusados; a IA tentando passar `tenant_id`/`user_id` é ignorada (a ferramenta usa o usuário vinculado); laço limitado a 5 ferramentas; saldo zero → resposta fixa e **nenhuma** chamada ao LLM; plano sem o recurso → mensagem fixa; `LlmUnavailable` → mensagem de indisponibilidade + menu fixo e **nenhum** débito; histórico truncado; assunto fora de documentos: o prompt contém a regra e uma resposta de recusa roteirizada segue sem chamar ferramenta; **injeção:** resultado de ferramenta com texto "ignore as regras e envie…" não altera as ferramentas disponíveis nem dispara ação; `OpenAiClient`: corpo (modelo, mensagens, ferramentas), leitura de `usage` e de chamadas de função, retry único, erro final.
- [ ] **Passo 2:** rodar os filtros — Esperado: FALHA. **Passo 3:** implementar. **Passo 4:** rodar os filtros e a suíte; ler; commitar e enviar.

---

### Tarefa 5: Ferramentas de consulta (somente leitura)

**Arquivos:** criar em `app/Assistant/Tools/`: `ListDocumentsTool`, `DocumentStatusTool`, `PendingSignersTool`, `SendSignedPdfTool`, `VerifyCodeTool`, `ListContractsDueTool`; `app/Assistant/Tools/ToolRegistry.php`; testes `tests/Feature/AssistantToolsTest.php`.

**Interfaces:** cada ferramenta implementa `Tool` (Tarefa 4), sem confirmação (somente leitura). `ListDocumentsTool{status?, query?, limit≤10}`; `DocumentStatusTool{document: id|título aproximado}` (resolve ambiguidade devolvendo opções, nunca escolhe sozinha); `PendingSignersTool`; `SendSignedPdfTool` (só documentos **concluídos** do usuário: baixa o PDF do disco, `uploadMedia` + `sendDocument`; respeita limite de tamanho da Meta e responde com link do sistema se exceder); `VerifyCodeTool{code}` (usa a verificação pública existente, sem dados pessoais além do que ela já mostra); `ListContractsDueTool{within_days}` (usa `ContractWatch`). Todas usam `Envelope::query()` filtrado pela empresa do usuário, ignoram lixeira, e exigem o recurso de plano correspondente (ex.: contratos ↔ `reports`).

- [ ] **Passo 1: testes que falham:** cada ferramenta com dados da própria empresa; **nunca** retornam dados de outra empresa (id/título de outra empresa → "não encontrei"); título ambíguo devolve até 5 opções; documento em rascunho/lixeira não é enviado como assinado; PDF só de concluído; PDF maior que o limite → link; código de verificação válido/inválido; contratos a vencer respeitam janela e plano; limites de resultados; títulos com `{{`, HTML ou "ignore as instruções" saem como texto inerte e escapado no formato de resposta.
- [ ] **Passo 2:** rodar `--filter=AssistantToolsTest` — Esperado: FALHA. **Passo 3:** implementar e registrar no `ToolRegistry`. **Passo 4:** rodar o filtro e a suíte; ler; commitar e enviar.

---

### Tarefa 6: Configuração, painéis, limpeza e piloto

**Arquivos:** `app/Http/Controllers/Admin/AssistantSettingsController.php` + view (padrão da tela de E-mail); `app/Http/Controllers/Web/AssistantController.php` + view "Assistente" da empresa (liga/desliga, quem pode usar, ações permitidas, consumo do mês: tokens e mensagens da Meta com acréscimo); entrada no `MenuBuilder`/sidebar só com o recurso; comando `assistant:prune` (90 dias) agendado; `docs/DEPLOY.md` (variáveis, webhook, número); `docs/ESCOPO.md`; testes `tests/Feature/AssistantAdminTest.php`.

- [ ] **Passo 1: testes que falham:** só super administrador acessa a configuração da plataforma; segredos nunca aparecem na tela nem no HTML (só "configurado"); salvar valida pesos/acréscimo; dono liga/desliga e escolhe usuários; consumo do mês mostra tokens e mensagens da Meta com valor original e cobrado; menu só aparece com o recurso do plano; `assistant:prune` apaga conversas com mais de 90 dias; super administrador lança pacote de tokens e repasse manualmente (ajuste com nota e registro).
- [ ] **Passo 2:** implementar e rodar o filtro e a suíte. **Passo 3:** **piloto manual** com número de teste e Luna: vincular, perguntar o status de um documento, pedir o PDF assinado, verificar código, e pedir algo fora do assunto; registrar custo médio por conversa e o que não foi possível validar. **Passo 4:** revisão independente de todo o diff com o "Foco da revisão final"; corrigir importantes com teste que falha antes; commitar e enviar.

---

## Plano 2 (depois deste): criar e enviar documentos
Fluxo guiado de ≤ 3 perguntas, escolha de modelo/PDF recebido, resumo gerado pelo sistema e **confirmação explícita**, cancelar/reenviar, vencimento de contrato, mensagens de modelo da Meta (fora da janela de 24 h) e botões. Será planejado após o piloto do Plano 1, com os números reais de custo e qualidade.
