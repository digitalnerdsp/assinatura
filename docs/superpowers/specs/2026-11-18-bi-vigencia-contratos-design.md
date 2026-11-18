# B.I. de vigência de contratos — desenho

Status: **para aprovação do dono**. Decisões do dono: a data de vencimento é **informada por quem cria**, no último passo antes de enviar (opção A); só contratos **assinados** com vigência monitorada aparecem; o aviso vai **só para os e-mails indicados** e fica **visível na tela do sistema**; as faixas de período foram aprovadas; a tela mostra contratos a vencer com **pelo menos 30 dias** de antecedência.

## Objetivo
Ninguém perder a renovação ou o fim de um contrato: quem cria o documento diz quando ele vence e quantos dias antes quer ser avisado. O sistema avisa por e-mail e mantém uma tela de B.I. com o que está vencido, vencendo e vigente.

## Fora do escopo (agora)
Ler a data do PDF por inteligência artificial (fica como evolução); valor do contrato; "tipo: contrato"; renovação automática; histórico de aditivos e vários vencimentos por contrato; aviso por WhatsApp/SMS; sino de notificações; preencher dados dentro do PDF.

## Como o usuário vê
**No passo 4, logo acima de "Enviar e finalizar" (opcional, desligado por padrão):**
```
Lembrar do vencimento deste contrato?   [ ] sim
   Data de vencimento:          [ 31/12/2027 ]
   Avisar quantos dias antes:   [ 30 ▾ ]    7 · 15 · 30 · 60 · 90
   Avisar nestes e-mails:       [ voce@empresa.com ]  [ + outro e-mail ]
```
O e-mail de quem cria já vem preenchido e pode ser trocado; até 5 e-mails. Com "sim" marcado, a data e ao menos um e-mail são obrigatórios.

**No detalhe do documento**, cartão **Vigência do contrato**: mostra a data, os dias que faltam e os e-mails; permite **definir, alterar (renovar) ou remover**. Serve para contratos que já existem e para renovar. Alterar a data zera os avisos já enviados daquela data.

**Tela "Contratos" (B.I.), no menu lateral, junto de Relatórios:**
- Cartões clicáveis por faixa: **Vencidos · Até 30 dias · 31 a 60 · 61 a 90 · Vigentes (mais de 90)**.
- Lista ordenada por vencimento: título, pasta/etiquetas, vencimento, **dias restantes** com cor (vermelho vencido ou até 7; laranja até 30; amarelo até 60; verde acima), e-mails de aviso, atalho para abrir o documento e editar a vigência.
- Filtros por pasta, etiqueta e busca; exportar CSV; gráfico de vencimentos por mês nos próximos 12 meses.
- **Janela "a vencer":** entra quem vence em até **30 dias**, ou em até os dias de aviso escolhidos naquele contrato, o que for maior (aviso de 60 ou 90 dias amplia a janela dele).
- Só aparecem documentos **concluídos** (assinados por todos), na empresa do usuário, fora da lixeira, com vencimento informado. Arquivados continuam monitorados.

**No painel inicial:** cartão "Contratos a vencer" com a contagem por faixa e os 5 mais próximos, e uma faixa de alerta quando houver vencido ou vencendo em até 7 dias.

## Avisos por e-mail
- Rotina diária (08:00 em Brasília). Para cada contrato concluído com vigência: **aviso X dias antes** (a partir do dia em que faltarem X dias ou menos) e **aviso no dia do vencimento**. Cada um é enviado **uma vez por data de vencimento**.
- Contrato que conclui já dentro da janela recebe o aviso na rotina seguinte. Se o envio falhar, nada é marcado como enviado e a rotina tenta de novo no dia seguinte.
- E-mail com a marca da empresa: título, data, dias restantes, link para o documento e para a tela Contratos. Não envia para ninguém além dos e-mails indicados.
- Documento cancelado, recusado, expirado ou na lixeira não é monitorado (a vigência fica guardada e volta a valer se for restaurado).

## Dados
- `envelopes`: `contract_expires_on` (data, nula = sem monitoramento), `contract_alert_days` (7, 15, 30, 60 ou 90), `contract_alert_emails` (lista de até 5 e-mails).
- `contract_alerts`: `envelope_id`, `kind` (`before` | `due`), `due_on`, `sent_to`, `sent_at`; único por (`envelope_id`, `kind`, `due_on`). É o registro de "já avisei".
- Datas em Brasília (vence ao fim do dia). Recurso liberado pelo mesmo recurso de plano dos Relatórios (`reports`).

## Regras e bordas
Data de vencimento tem de ser depois de hoje (Brasília) e no máximo daqui a 20 anos. Vencimento antes do fim do prazo de assinatura é aceito (o contrato só passa a ser monitorado quando concluir). Mudar a data ou os dias de aviso reavalia tudo; remover a vigência apaga o monitoramento. Documentos de outra empresa: 404. A API de documentos não muda; a vigência pode vir depois, se você quiser.

## Abordagem
Campos no próprio documento + rotina diária + tela de consulta (opção A). Descartada por ora: tabela própria de contratos com histórico de aditivos (mais flexível, bem maior).

## Testes
Bloco do passo 4 (validações, e-mail pré-preenchido, limites); detalhe (definir, renovar, remover e zerar avisos); faixas e janela mínima de 30 dias; só concluídos, só da empresa, fora da lixeira; rotina (avisa uma vez, no dia, reenvia após falha, ignora cancelado e lixeira, respeita data renovada); e-mail só aos indicados; painel inicial; exportação; plano sem o recurso não vê a tela; fuso de Brasília perto da meia-noite.

## Entrega (ordem)
1. Dados e serviço. 2. Bloco no passo 4. 3. Cartão no detalhe. 4. Tela Contratos (B.I.). 5. Cartão e faixa no painel. 6. Rotina e e-mail de aviso. 7. Documentação. A tela e o aviso só fazem sentido juntos: tudo entra antes de divulgar.

## Escolhas minhas (o dono pode trocar)
Aviso também **no dia** do vencimento (além de X dias antes); janela de 30 dias como mínimo; opções de aviso 7, 15, 30, 60 e 90 dias; menu "Contratos" separado de Relatórios; horário 08:00; limite de 5 e-mails.
