# Enviar documento para assinatura pelo WhatsApp (Fase 2) — plano

> Execução inline, tarefa por tarefa, com teste antes do código. Commit e push na `main` juntos. Sem IA no fluxo: tudo é conversa guiada com botões (não gasta token e funciona com a IA fora do ar).

**Meta:** o usuário vinculado manda um PDF (ou Word) no WhatsApp e, em poucos passos guiados, o documento sai para assinatura por e-mail, com a mesma cobrança, limites do plano e trilha de evidências do site.

## O passo a passo (conversa)
1. **Arquivo:** o usuário manda um PDF ou DOCX (ou escreve "enviar documento" e o sistema pede o arquivo). O sistema baixa o arquivo, converte o Word, cria um **rascunho** (o mesmo do site) e responde: "Recebi X. Qual o título?" com o botão **Usar "X"**; se o usuário digitar, vale o texto.
2. **Signatários:** "Quem vai assinar? Mande nome e e-mail (um por mensagem). Ex.: Maria Souza maria@email.com". Cada mensagem acrescenta uma pessoa e o sistema confirma. Só o nome, sem e-mail, procura nos **contatos** da empresa (uma correspondência certa; senão pede o e-mail). Botões: **Pronto** e **Cancelar**.
3. **Revisão:** título, signatários (numerados), ordem (todos juntos ou um por vez), prazo e lembretes padrão da empresa, envio por e-mail. Botões: **Enviar agora**, **Ordem: um por vez / todos juntos** (alterna) e **Cancelar**.
4. **Envio:** cria os signatários, envia (conta no plano como no site), manda os links por e-mail e responde com o resumo. O usuário depois recebe os avisos de assinatura que já existem.
5. **Cancelar** (ou 30 minutos parado): o rascunho vai para a lixeira e o fluxo termina. O rascunho nunca fica "perdido": também aparece no site.

## Regras fixas
- Só usuário vinculado, ativo, com o recurso do assistente e o grupo de ações **"Enviar documentos para assinatura"** ligado pela empresa (novo grupo; padrão ligado como os demais).
- Política de assinatura do remetente da empresa **"always"** exige que o remetente assine no site: o fluxo explica e para (não cria nada). Com **optional**/**never** o fluxo não inclui o remetente.
- Limites do plano valem como no site (documentos, armazenamento, tamanho do PDF, assinatura bloqueada/empresa suspensa): avisa cedo, antes de pedir signatários.
- Envio exatamente uma vez (botão duas vezes envia uma): a transição do fluxo é reivindicada de forma atômica e o documento só envia se ainda for rascunho.
- Segurança do arquivo: só PDF (começa com `%PDF`) ou DOCX (zip, extensão `.docx` ou tipo do Word), até o limite de PDF; mídia só dos domínios da Meta (já existe no cliente); título e nomes limpos e com tamanho máximo; e-mail validado; no máximo 20 signatários; sem duplicar e-mail.
- Um fluxo ativo por usuário; arquivo novo durante um fluxo pergunta se descarta o anterior (botões Descartar e continuar / Manter o atual).
- Dados do fluxo (apenas ids e nomes/e-mails digitados) ficam em tabela própria e são apagados com a limpeza dos 90 dias.
- Textos dizem "documento", nunca "envelope".

## Fora deste plano
Posicionar a assinatura no PDF (o documento sai com a assinatura no padrão do sistema, como na API sem campos), o remetente assinar, verificações extras (selfie, código) por signatário, testemunhas e observadores, e envio de links por WhatsApp ao signatário (exige aceite). O usuário pode abrir o rascunho no site para qualquer ajuste.

## Tarefa 1 — Base: arquivo, fluxo e permissão
- Migração `whatsapp_flows` (id uuid, tenant_id, user_id único, envelope_id nullable, step, data json, expires_at, timestamps).
- `DocumentIntake::fromBytes(string $bytes, string $name, ?string $mime)` (PDF ou DOCX → PDF), usado também por `fromUpload`.
- `ToolGroups`: grupo `send_documents` (sem ferramentas de IA; só libera o fluxo) e `ToolGroups::allows(Tenant, 'send_documents')`.
- `App\Assistant\Flow\SignerLine::parse(string): array{name,email}|null` e `FlowStore` (obter/abrir/avançar/limpar com expiração).
- Testes: parse de linhas ("Maria Souza maria@x.com", "maria@x.com Maria", só e-mail usa a parte antes do @, e-mail inválido, nome longo), fromBytes (PDF, DOCX, lixo), grupo ligado/desligado.

## Tarefa 2 — Conversa: do arquivo à revisão
- `App\Assistant\Flow\SendDocumentFlow::handle(User, InboundMessage, ?list<string> allowedTools): ?AssistantReply` (null = não é do fluxo). Chamado pelo orquestrador depois das travas de empresa/usuário/limite de mensagens e antes do saldo de tokens.
- Passos: arquivo → título → signatários → revisão; botões `flow_title_keep`, `flow_done`, `flow_cancel`, `flow_order`, `flow_send`, `flow_discard`, `flow_keep`.
- Verificações cedo (política do remetente, limites do plano, tamanho do arquivo, empresa suspensa).
- Testes: o caminho feliz até a revisão, título digitado e botão, vários signatários, contato por nome, e-mail inválido/duplicado, 21º signatário, arquivo inválido/grande, política "always", plano sem espaço, fluxo expirado, arquivo novo no meio do fluxo, cancelar.

## Tarefa 3 — Envio
- `flow_send`: reivindica o fluxo (atômico), `syncPeople` + `applyDelivery` (prazo e lembretes padrão) + `assertSendable` + `send` + `SignerNotifier::inviteActive`; resposta com o resumo e quem não recebeu o e-mail.
- Falhas viram mensagens claras (plano, e-mail, conversão) sem perder o rascunho.
- Testes: envio completo (status enviado, signatários com e-mail, e-mails enfileirados, uso cobrado uma vez), botão duas vezes, plano estourado, falha de e-mail, ordem alternada, que o documento aparece no site e que o usuário recebe os avisos depois.

## Tarefa 4 — Integração e documentação
- Menu fixo: o botão "Enviar documento" não cabe (máximo 3); o texto "enviar documento"/"novo documento" e o próprio arquivo iniciam o fluxo; o prompt da IA manda o usuário enviar o arquivo.
- Painel da empresa: o novo grupo aparece em "Ações permitidas". Limpeza de 90 dias cobre `whatsapp_flows`.
- `DEPLOY.md`, `ASSISTENTE-PILOTO.md`, suíte completa, revisão independente, commit e push.
