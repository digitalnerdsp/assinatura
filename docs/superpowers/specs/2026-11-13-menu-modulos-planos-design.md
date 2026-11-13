# Escopo — Menu e identidade, módulos de gestão, planos e cobrança, integração com a hospedagem

Data: 13/11/2026 · Status: **aprovado em 13/11/2026 · Fase 1 entregue em 14/11/2026 · Fase 2 entregue em 17/11/2026** · Complementa `docs/ESCOPO.md` (seção 14, "Monetização e produto").

## 1. Intenção

Deixar a plataforma com a cara e a usabilidade da ZapSign (menu com os mesmos nomes, fonte legível), acrescentar o que
facilita o dia a dia (Contatos, Relatórios, Calendário) e **cobrar das empresas clientes** por planos totalmente
configuráveis, inclusive o espaço de armazenamento. O primeiro sistema a se integrar é o `hospedagem_tenants` (do próprio
dono), que também serve como teste real da API.

## 2. Objetivos, não-objetivos e anti-objetivos

**Objetivos**
- Navegação reconhecível para quem já usa ZapSign; leitura confortável nos temas claro e escuro.
- O administrador da plataforma cria e altera planos pela tela, sem mexer em código: preço, período, limites e recursos.
- Cobrança mensal (e em períodos maiores) por fatura com link de pagamento, via Mercado Pago e InfinitePay.
- Limites de documentos, usuários e armazenamento aplicados em um único lugar do código.
- Contratos da hospedagem assinados pela API, com retorno automático por webhook.

**Não-objetivos (por ora)**
- "Negócios" (funil de vendas de CRM) e "Antecedentes" (consulta de antecedentes). Não fazem parte de assinatura eletrônica.
- Cobrança automática no cartão (assinatura/recorrência dentro do gateway). Evolução futura.
- Nota fiscal (NFS-e), divisão de pagamento (split), outras moedas e **domínio próprio** por empresa (segue no backlog). A marca própria, só logo e cores, **está no escopo** (seção 5.1).
- Parcelamento do plano anual "em 12x" como na ZapSign (o anual é cobrado à vista).

**Anti-objetivos (seria fracasso mesmo funcionando)**
- Bloquear indevidamente uma empresa que pagou, ou prender um signatário que está no meio de uma assinatura.
- Dar baixa em fatura sem conferir o pagamento no próprio gateway.
- Vazar ou gravar em log as chaves dos gateways.
- Apagar documento assinado por causa de inadimplência ou de limite de armazenamento.

## 3. Abordagens consideradas

| Tema | Alternativas | Escolha e motivo |
|---|---|---|
| Planos | (a) planos em arquivo de configuração; (b) planos no banco com serviço central de limites; (c) Laravel Cashier | **(b)**. (a) não é editável pela tela; (c) só atende Stripe/Paddle, não Mercado Pago nem InfinitePay. |
| Recorrência | (a) assinatura gerenciada pelo gateway (preapproval do Mercado Pago); (b) a plataforma gera a fatura do período e envia o link | **(b)**, definida pelo dono: é o modelo que já funciona na hospedagem, serve aos dois gateways (a InfinitePay não tem recorrência) e deixa o (a) como evolução. |
| Visual | (a) ajustar o tema atual (Rocker); (b) reescrever a interface em outro framework | **(a)**: custo e risco bem menores; o resultado visual pedido é alcançável com CSS e nova estrutura de menu. |

## 4. Parte A — Identidade e menu

**Fonte:** Roboto (escolha sua), **hospedada no próprio servidor** (pesos 400, 500 e 700), em vez de carregar do Google, para
funcionar igual em qualquer rede e não depender de terceiros. Aplica-se a todo o sistema, inclusive páginas públicas de assinatura.
Mantém as regras de contraste e negrito já entregues.

**Menu do cliente** (nomes iguais aos da ZapSign onde fizer sentido):

```
Painel
Documentos ▸  Documentos criados · Minhas assinaturas · Lixeira
Modelos
Contatos
Calendário
Relatórios
Configurações ▸  Meu perfil · Empresa · Usuários · Pastas e etiquetas · API · Webhooks · Plano e cobrança
───────────
Planos e preços
Central de ajuda
```

- **Documentos criados:** a lista de envelopes de hoje (filtros, pastas e etiquetas continuam). "Novo envelope" vira o botão
  **Criar documento** no topo, como na ZapSign.
- **Minhas assinaturas:** documentos em que o usuário logado é signatário (pelo e-mail dele), com atalho para assinar.
- **Lixeira:** hoje não existe exclusão. Rascunhos, cancelados e expirados podem ir para a lixeira e ficam 30 dias antes de
  serem apagados de vez. **Documento assinado e selado nunca é apagado pelo usuário**: só pode ser arquivado (some da lista, continua verificável).
- **Menu da plataforma (administrador):** Visão geral, Clientes, **Planos**, **Assinaturas e faturas**, **Pagamentos (gateways)**,
  Saúde do sistema, Selagem do PDF, E-mail (SMTP), Configurações.
- **Central de ajuda:** link configurável pelo administrador (v1 não tem base de conhecimento própria).

## 5. Parte B — Módulos de gestão

**Contatos**
- Cadastro por empresa: nome, e-mail, telefone (E.164), CPF/CNPJ, empresa e observação; etiquetas.
- Todo signatário usado em um envelope entra automaticamente nos contatos (sem duplicar por e-mail).
- Ao criar o documento, o campo de signatário completa nome, e-mail e telefone a partir dos contatos.
- Importar e exportar CSV; endpoints na API (`/contacts`).
- LGPD: o contato pode ser excluído pelo cliente; o envelope já assinado mantém a evidência (dado necessário ao documento).

**Calendário**
- Visão de mês e de semana com: prazos de assinatura (`expires_at`), documentos criados, concluídos e lembretes programados.
- Clique abre o envelope. Fonte dos dados são os próprios envelopes (sem tabela nova de eventos na v1).
- Fora da v1: feed ICS para Google Agenda e criação manual de compromissos.

**Relatórios**
- Indicadores: total de documentos, assinados, em andamento, recusados, expirados; **tempo médio de assinatura** com a
  distribuição por faixas (menos de 30 min, 1 h, 24 h, 2 dias, 5 dias, 10 dias, mais), como no modelo que você enviou.
- Filtros por período, pasta, etiqueta, modelo e signatário; tabela detalhada com exportação em CSV.
- Painel inicial passa a usar os mesmos números.

### 5.1 Marca própria (white label simples: só logo e cores)

- Cada empresa pode enviar **o próprio logo** (PNG, JPG ou WebP até 2 MB, reprocessado como a foto de perfil) e escolher **a cor principal** e a cor de destaque, com prévia ao vivo.
- Onde aparece: painel da empresa, **página de assinatura do signatário**, e-mails de convite/lembrete/código e o cabeçalho do relatório de assinaturas no PDF selado.
- Proteção de leitura: se a cor escolhida tiver contraste insuficiente com o texto, o sistema avisa e sugere o tom mais próximo que passa (mantém a regra de contraste do projeto).
- O nome "Assinaturas" da plataforma pode ser trocado pelo nome da empresa nesses mesmos lugares. **Fora** desta versão: domínio próprio, remover o rodapé de verificação e e-mail com remetente próprio.
- **Como é cobrado (você decide por plano, sem código):** ou é **recurso incluso** no plano (liga/desliga), ou é **pacote extra** com preço próprio que a empresa contrata à parte. Os dois podem existir ao mesmo tempo.

**Usuários da empresa (mínimo necessário para o limite do plano)**
- Listar, convidar por e-mail, desativar e remover. Papéis (administrador, operador, leitor) continuam no backlog.

## 6. Parte C — Planos, limites e cobrança

### 6.1 Planos (tela do administrador, sem código)

Cada **plano** tem: nome, descrição, ativo, visível na página pública de preços, ordem, e:
- **Limites** (vazio = ilimitado): documentos, usuários, **armazenamento em GB**, tamanho máximo de PDF.
- **Período de teste grátis, por plano:** dias de teste (0 = sem teste), se o teste pede ou não forma de pagamento, o que acontece
  ao fim (virar o plano pago com fatura, cair para um plano gratuito ou bloquear novos documentos) e, se quiser, **limites próprios
  do teste** (por exemplo, só 5 documentos). A empresa que se cadastra recebe o plano padrão escolhido pelo administrador.
- **Regras de consumo de documentos (todas configuráveis por plano):**
  - *Quando desconta:* ao **enviar** (padrão) ou ao **concluir** (quando todos assinaram). Documento cancelado ou expirado sem assinatura pode ser **devolvido ao saldo** (opção).
  - *Tipo de saldo:* **cota que renova** a cada período (ex.: 80 por mês) ou **pacote que não renova** (créditos que vão acabando e podem ser recarregados), ou os dois juntos (consome primeiro a cota, depois os créditos).
  - *Ao acabar o saldo:* **bloquear** novos documentos, **cobrar o excedente** por documento (valor por unidade, somado à próxima fatura) ou liberar até um teto.
  - Tudo isso é escolhido na tela do plano e pode ser sobrescrito por empresa.
- **Recursos** ligados/desligados: WhatsApp/SMS, API, webhooks, modelos, posicionamento da assinatura, signatários em ordem,
  selfie e documento, carimbo de tempo, relatórios, **marca própria (logo e cores)** (a lista cresce sem migration: guardada como JSON).
- **Preços por período:** mensal, trimestral, semestral e anual (valor em centavos, rótulo de desconto, ativo ou não).
  Todos seguem o mesmo processo: uma fatura do período inteiro, paga por link.

**Totalmente customizável por cliente** (resolve "plano sob medida"):
- A assinatura de cada empresa aceita **sobrescrever** preço, limites e recursos do plano (ex.: 200 GB só para ela, ou desconto fixo).
- **Pacotes extras** (add-ons) cadastrados pelo administrador, por exemplo "+10 GB de armazenamento" ou "+100 documentos/mês",
  com preço e quantidade por assinatura; entram na fatura seguinte.
- **Cupons** simples: percentual ou valor fixo, validade e número máximo de usos (equivale ao campo "Oferta especial" da ZapSign).
- Empresas que já existem passam para um plano **"Legado"**, ilimitado e sem cobrança, para nada quebrar no dia da virada.

### 6.2 Como os limites são aplicados

Um serviço único (`Entitlements`) responde "esta empresa pode fazer isto agora?", e é chamado pelos mesmos pontos que o
painel e a API já usam (criação e envio de envelope, convite de usuário, upload de arquivo).
- **Documento contado:** segue a regra do plano (6.1): por padrão, o envelope **enviado** (rascunho nunca conta), descontado da cota do período e/ou do pacote de créditos. Cada desconto vira um lançamento no extrato de uso da empresa (data, envelope, saldo antes e depois), para ela e o administrador conferirem.
- **Armazenamento:** soma do tamanho dos arquivos da empresa (original, selado e anexos dos signatários; o tamanho já é gravado).
  Mantido em um contador por empresa, atualizado a cada gravação ou remoção e conciliado por uma rotina noturna.
- **Avisos:** a 80% e a 100% do limite (faixa no painel e e-mail). Ao estourar, bloqueia só **criar/enviar novos documentos**.
- **Nunca bloqueia:** assinar documentos já enviados, baixar, verificar, receber webhook e consultar a API em leitura.
- `GET /me` da API passa a informar plano e consumo.

### 6.3 Gateways de pagamento (Mercado Pago e InfinitePay)

Mesmo desenho que já funciona na hospedagem (`PaymentGateway`: `createCheckout`, `verifyPayment`, `testCredentials`),
reescrito em Laravel:
- **Mercado Pago (Checkout Pro):** cria a preferência com PIX e cartão, `external_reference` = id da fatura, retorno e
  `notification_url`; confirma consultando `GET /v1/payments/{id}`.
- **InfinitePay (Checkout Integrado):** cria o link com a InfiniteTag e confirma em `payment_check`. Como o webhook **não é
  assinado**, a baixa só acontece depois da reconferência no servidor.
- **Regra de ouro:** nenhuma fatura é baixada com base no que chegou pelo navegador ou pelo webhook. Sempre se reconsulta o
  gateway e se confere que o valor pago não é menor que o da fatura (tolerância de 1 centavo). Baixa idempotente, com trava de linha.
- Credenciais ficam em **Pagamentos** no painel da plataforma, cifradas como a senha do SMTP, com botão **Testar**.
  O dinheiro cai direto na conta do dono da plataforma. Os dois podem estar ativos; o cliente escolhe na hora de pagar.
- Rotas públicas de webhook com limite de requisições; todo evento fica registrado (sem segredos) para auditoria.

### 6.3.1 Interruptor "Cobrança ativa" (o que é)

É uma chave geral, no painel da plataforma, que separa **ter** planos de **cobrar** por eles:
- **Desligada (como a plataforma sai):** planos, limites e telas existem e podem ser montados e testados, mas **nenhuma fatura é gerada, nenhum e-mail de cobrança sai e ninguém é bloqueado**. Todas as empresas atuais ficam no plano "Legado".
- **Ligada:** a rotina diária passa a gerar faturas, enviar lembretes e aplicar a tolerância e o bloqueio de 6.4.
- Serve para subir o código, cadastrar planos e testar com a empresa de teste sem risco de cobrar quem não deve. Para ir entrando aos poucos, cada assinatura também tem a opção **"isenta de cobrança"** (cliente amigo, parceiro, teste), que vale mesmo com a chave ligada.

### 6.4 Ciclo da cobrança (rotina diária `billing:run`)

0. **Teste grátis:** a assinatura nasce "em teste" pelos dias definidos no plano; perto do fim, a empresa recebe aviso e a fatura do primeiro período. Ao terminar, segue a regra do plano (virar pago, cair para o gratuito ou bloquear novos documentos).
1. **Fatura:** gerada alguns dias antes do fim do período (padrão 7; configurável), com o link, enviada por e-mail. Inclui pacotes extras e **excedente** de documentos do período, se o plano cobra excedente.
2. **Lembretes:** 3 dias antes, no vencimento e 1 dia depois.
3. **Atraso:** a empresa continua usando por uma **tolerância** (padrão 5 dias; configurável). Passada a tolerância, a assinatura vai
   para "bloqueada" (aplica só as restrições de 6.2). Pagou, reativa na hora.
4. **Cancelamento:** a pedido, vale ao fim do período pago. Dados e documentos ficam preservados.
5. **Troca de plano:** upgrade vale na hora com fatura proporcional da diferença; downgrade vale no fim do período e só se o
   uso atual couber no plano menor (principalmente armazenamento).
6. **Pagamento manual:** o administrador pode registrar um pagamento recebido fora (PIX direto, por exemplo) e reenviar link ou cancelar fatura.

### 6.5 Telas

- **Empresa cliente:** *Planos e preços* (cartões por plano com seletor de período, como a ZapSign), *Plano e cobrança* (plano
  atual, barras de uso de documentos/usuários/armazenamento, faturas, pagar, recibo).
- **Administrador:** Planos (CRUD), Add-ons e cupons, Assinaturas (status, vencimentos, sobrescritas), Faturas, Pagamentos
  (gateways), indicadores: receita mensal recorrente, inadimplência, cancelamentos.
- Valores sempre em centavos inteiros, em reais (BRL). Recibo simples em PDF (não é nota fiscal).

## 7. Parte D — Integração com o `hospedagem_tenants` (primeiro teste real)

Fluxo definido com você: **hospedagem → assinatura → hospedagem**.
1. Ao aprovar uma contratação, a hospedagem chama `POST /api/v1/envelopes` (ou o endpoint de modelo com variáveis) com
   `external_id` = id do pedido e `metadata` com cliente e pedido, sem precisar guardar o id da plataforma.
2. O cliente assina; a plataforma envia o webhook **`envelope.completed`** assinado (HMAC).
3. A hospedagem valida a assinatura do webhook, marca o contrato como assinado e baixa o PDF selado
   (`GET /envelopes/{external_id}/signed`).
- Entregáveis aqui: um **guia com exemplo em PHP** (cliente de chamada + rota de webhook com validação HMAC), uma empresa de teste
  com chave de API e a checklist de testes ponta a ponta. A alteração dentro do projeto da hospedagem é feita à parte, naquele repositório.
- Fica de fora o uso das chaves de pagamento da hospedagem: cada sistema tem as suas.

## 8. Fases (cada uma entra em produção sozinha)

| Fase | Entrega | Depende de |
|---|---|---|
| 1 | Roboto local, novo menu, Documentos (Criados, Minhas assinaturas, Lixeira), Configurações agrupada | — |
| 1b | Remetente que assina junto e testemunhas (ver `2026-11-14-remetente-e-testemunhas-design.md`) | 1 |
| 2 | Contatos, Relatórios, Calendário, Usuários da empresa | 1b |
| 3 | Planos (com teste grátis e regras de consumo), assinaturas, `Entitlements`, limites e armazenamento, **marca própria (logo e cores)**, telas de administrador e de uso (ainda sem cobrança automática; pagamento manual) | 2 (usuários) |
| 4 | Faturas, Mercado Pago e InfinitePay, rotina de cobrança, bloqueio, add-ons e cupons, Planos e preços | 3 |
| 5 | Guia e teste de integração com a hospedagem | só a API atual, que já está pronta |

A fase 5 não depende das outras e pode rodar a qualquer momento (por exemplo logo depois da fase 1); está por último na lista apenas por não bloquear nenhuma outra.

## 9. Restrições e requisitos

- Laravel 13, PostgreSQL, Coolify como hoje; GD e demais dependências já no `Dockerfile`.
- Todo dado financeiro e de limite novo tem teste automatizado (o gateway é simulado nos testes; nenhum teste chama a internet).
- Migração que preserva as empresas existentes (plano "Legado"); nada é cobrado sem o administrador ativar a cobrança.
- Chaves de gateway e segredos de webhook nunca em log, URL ou resposta de API.
- A cobrança só começa quando o administrador ligar o interruptor "Cobrança ativa" (permite subir o código e testar antes).

## 10. Decisões minhas (para você conferir)

- Roboto hospedada localmente (e não o Google Fonts).
- Documento contado ao **enviar** por padrão, mas o momento do desconto, o tipo de saldo e o que fazer ao acabar são configuráveis por plano (6.1).
- Marca própria: só logo e cores nos lugares listados em 5.1; cobrada como recurso incluso e/ou pacote extra, à sua escolha por plano.
- Períodos fixos: mensal, trimestral, semestral e anual; anual à vista.
- Padrões editáveis: fatura 7 dias antes, tolerância de 5 dias, avisos a 80% e 100%.
- Lixeira de 30 dias só para rascunho, cancelado e expirado; assinado só arquiva.
- Bloqueio por limite ou atraso impede apenas **novos** documentos, nunca assinatura em andamento, download ou verificação.
- Contatos criados automaticamente a partir dos signatários.
- Planos "sob medida" por sobrescrita na assinatura, sem criar um plano para cada cliente.

## 11. Pendências suas (só você responde)

1. **Preços, limites, dias de teste e regras de consumo dos primeiros planos**: não bloqueia nada, você cadastra pela tela quando a fase 3 estiver pronta (o teste grátis agora é uma configuração de cada plano).
2. **Contas de recebimento**: o Access Token do Mercado Pago e a InfiniteTag da plataforma entram no painel só na fase 4, nunca no chat.

## 12. Riscos

- **Sem recorrência automática no cartão:** o cliente precisa pagar o link todo período; mitigado por lembretes e tolerância. Se a inadimplência
  incomodar, a evolução é a assinatura do Mercado Pago.
- **InfinitePay sem webhook assinado:** mitigado pela reconferência obrigatória no `payment_check`.
- **Armazenamento medido nos arquivos hoje no volume do servidor:** a migração futura para S3 mantém o mesmo contador (o tamanho já é gravado por arquivo).
- **Escopo grande:** por isso as cinco fases independentes; a ordem pode mudar sem retrabalho.
