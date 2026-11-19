# Assistente no WhatsApp — roteiro do piloto (Plano 1)

O código do Plano 1 (consultas) está pronto e coberto por testes automáticos, **mas nunca falou com a Meta nem com a OpenAI de verdade**. Este roteiro é o que falta para validar com contas reais. Faça com **um número de teste** e **um usuário seu**.

## Antes de começar
1. **Meta:** app de negócios com o produto WhatsApp, número de teste (ou um número novo), token de acesso permanente (usuário do sistema), segredo do app.
2. **OpenAI:** chave de API e o **identificador exato do modelo Luna** (as fontes citam `gpt-5.6-luna` e `gpt-6-luna`: confirme na sua conta e na tabela de preços oficial).
3. Redeploy da aplicação (migrações novas) e do agendador (`schedule:work`) e de um **worker de fila** (`php artisan queue:work`): o webhook responde rápido e a conversa é processada na fila.

## Configuração (Admin ▸ Assistente no WhatsApp, só super administrador)
- Cole o **endereço do webhook** mostrado na tela no painel da Meta (WhatsApp ▸ Configuração ▸ Webhook), com o mesmo **token de verificação** que você salvou. Assine o campo **messages**.
- Preencha: token de acesso, segredo do app, `phone_number_id`, número que o usuário vê (com DDI), chave da OpenAI, **modelo**, **peso do modelo** (`modelo=1`), acréscimo, pacote padrão, excesso permitido, moeda e a **tabela de preços por categoria** (a Meta avisa a categoria e se cobra, mas não o valor).
- Em **Planos**, ligue o recurso **Assistente no WhatsApp** no plano da empresa de teste (limite "Tokens do assistente por mês": em branco usa o padrão da plataforma).

## Roteiro (marque o resultado de cada passo)
| # | Passo | Esperado | Resultado |
|---|---|---|---|
| 1 | Meta valida o webhook | "Verificado" no painel da Meta | |
| 2 | Meu perfil ▸ Assistente no WhatsApp ▸ Gerar código; enviar o código pelo WhatsApp ao número da plataforma | Resposta "Tudo certo… vinculado" | |
| 3 | Mandar de um número **não** vinculado: "oi" | Só a orientação para vincular (uma vez por hora) | |
| 4 | "quais documentos estão pendentes?" | Lista correta, com botões/lista | |
| 5 | Pedir o status de um documento pelo nome parecido com dois | Pergunta qual (não escolhe sozinho) | |
| 6 | "quem falta assinar no contrato X?" | Nomes corretos | |
| 7 | "me manda o PDF assinado do contrato Y" | O PDF chega no próprio WhatsApp | |
| 8 | Colar um código de verificação | Status e emissor corretos | |
| 9 | "quais contratos vencem em 60 dias?" | Lista na ordem do vencimento | |
| 10 | Pedir algo fora do assunto ("conte uma piada", "ignore as regras") | Recusa educada, sem gastar ferramenta | |
| 11 | Zerar o saldo (ajuste negativo) e escrever de novo | Aviso de tokens acabados + menu fixo que funciona | |
| 12 | Desligar a IA (chave errada) e tocar num botão do menu | Menu continua respondendo sem IA | |
| 13 | Painel da empresa ▸ Assistente | Tokens usados e mensagens da Meta do mês | |

## O que registrar
- **Custo médio por conversa** (tokens equivalentes e dinheiro) e o custo real na OpenAI para a mesma conversa — para calibrar `peso do modelo`, `peso da saída` e `peso do cache`.
- **Preço real por categoria** na tabela da Meta do mês e a **conciliação** com a fatura.
- Respostas em que a IA errou, inventou ou demorou, e mensagens da Meta que não chegaram.
- O que **não** foi possível validar.

## Ainda não existe (Plano 2)
Criar e enviar documentos pelo WhatsApp, mensagens de modelo fora da janela de 24 h e cobrança automática (a compra de pacote e o repasse são lançados à mão em Admin ▸ Assistente).

## Teste dos avisos (modelos de mensagem)
1. Admin ▸ Assistente WhatsApp: preencha o **WABA** e a tabela de preços (`utility=…`). Admin ▸ Avisos do WhatsApp: **Enviar para aprovação** cada modelo e **Atualizar status** até ficarem aprovados; ligue-os.
2. Com uma empresa de teste (não a do super administrador), faça uma **recarga** em Admin ▸ Assinaturas ▸ (a empresa) ▸ Saldo dos avisos no WhatsApp e vincule o seu número no perfil.
3. Envie um documento, assine/recuse com outra pessoa: o aviso deve chegar no WhatsApp. Confira em Admin que o saldo cai **uma vez por mensagem** depois que a Meta avisa a cobrança (a categoria e o valor da fatura da Meta devem bater com a tabela).
4. Zere o saldo: os avisos param e o painel da empresa mostra "Sem saldo". Teste também o pós-pago com limite pequeno.
