# Assistente de IA no WhatsApp — escopo e desenho (para aprovação)

Data: 18/11/2026. Ainda não construído. Este documento fixa o escopo da primeira versão; os pontos marcados como **decisão minha** podem ser trocados.

## Intenção
O cliente (quem já usa a plataforma) pede pelo WhatsApp, em linguagem natural, o que faria na tela: criar e enviar documentos para assinatura, acompanhar o andamento, baixar o PDF assinado e verificar uma assinatura. O objetivo é **enviar um documento em poucas mensagens**, sem abrir o sistema.

## Quem fala com o agente (v1)
- Somente **usuários da plataforma com login** (dono e equipe), depois de vincular o próprio WhatsApp à conta. **Decisão minha:** signatários e público ficam de fora da v1 (exigem outro desenho de identidade).
- Um número de WhatsApp pertence a **um** usuário. Quem pertence a mais de uma empresa fica fora (hoje cada usuário tem uma empresa).

## Canal: número único da plataforma, API da Meta direta
- **API de Nuvem da Meta**, sem intermediário, com **um número exclusivo da plataforma**. O número do cliente (conexão por empresa, Embedded Signup) fica para uma fase 2, como recurso de plano mais alto.
- Regras do canal: resposta livre só dentro de 24 horas da última mensagem da pessoa; fora disso, só mensagens de modelo aprovadas (usadas para lembrar, por exemplo, "documento assinado por todos"). Webhook de entrada com **assinatura verificada** e **deduplicação pelo id da mensagem** (a Meta reenvia).
- **Regra da Meta para IA (desde 15/01/2026):** são proibidos chatbots de IA de uso geral. O agente é **restrito a documentos**: recusa e redireciona qualquer outro assunto. Isso é requisito de produto, não detalhe.

## O que o agente faz na v1
| Pedido | Exemplo | Confirmação antes de agir |
|---|---|---|
| Consultar documentos | "como está o contrato da Ana?" | não |
| Quem falta assinar / reenviar link | "reenvia pro João" | sim (reenviar) |
| Baixar PDF assinado e evidências | "me manda o assinado" | não |
| Verificar assinatura | "verifica o código ABC123" | não |
| **Criar e enviar** | "manda o contrato de aluguel pra Ana, valor 2.500" | **sim, sempre** |
| Cancelar documento | "cancela o contrato do Pedro" | sim |
| Vencimento do contrato | "avisa 30 dias antes de 31/03" | sim |

**Fora da v1:** cobrança e plano, configurações da empresa, usuários, API/webhooks, apagar definitivamente, editar modelos, falar com signatários, conversa livre fora de documentos.

## Criar e enviar com poucas perguntas (o centro do produto)
Princípio: **perguntar só o que falta, uma coisa por vez, com padrão sugerido**, e usar botões de resposta rápida do WhatsApp para evitar digitar.
1. **Pedido livre:** o agente extrai tudo o que a frase já traz (modelo ou PDF, signatários, valores das lacunas).
2. **Documento:** se não ficou claro, oferece os modelos da empresa em lista ("Contrato de aluguel · Proposta · …") ou pede o PDF (a pessoa envia o arquivo na conversa).
3. **Lacunas do modelo:** pergunta cada valor obrigatório que faltou, na ordem do documento.
4. **Signatários:** pergunta nome e WhatsApp ou e-mail de quem falta; sugere contatos já salvos ("Ana Souza, 11 9…?").
5. **Resumo e confirmação:** "Contrato de aluguel para Ana Souza (WhatsApp), valor 2.500,00, prazo de 7 dias. **Enviar?** [Enviar] [Mudar] [Cancelar]". Os padrões (prazo, lembretes, política de quem envia) vêm das configurações da empresa.
6. **Envio:** o agente responde com o resultado e o link de acompanhamento. Valores que não cabem na lacuna voltam como pergunta ("o endereço ficou grande, resume?").
Meta de desenho: **no máximo 3 perguntas** no caso comum (modelo + pessoa + valores já ditos).

## Segurança (requisitos)
- **Identidade:** vínculo do número feito pela pessoa, logada no site (código de 6 dígitos enviado ao WhatsApp, validade curta, tentativas limitadas). Trocar de número exige o mesmo processo. Mensagem de número não vinculado só recebe orientação.
- **Ações com efeito** (enviar, cancelar, reenviar, vencimento) só executam depois de um **"sim" explícito ao resumo exato** que o agente mostrou; o resumo é gerado pelo sistema, não pela IA. A confirmação expira em 10 minutos.
- **Autorização:** o agente usa as mesmas regras e limites de plano da pessoa (função, cota, recursos do plano). Nunca age fora da empresa dela.
- **Injeção de instruções:** nomes de arquivo, títulos, textos de PDF e mensagens de terceiros são **dados**, nunca ordens. O agente só chama ferramentas de uma lista fixa, com parâmetros validados pelo servidor.
- **Privacidade (LGPD):** o modelo de IA recebe só o necessário (título, status, nomes das lacunas; **nunca** o conteúdo do PDF nem o PDF). Histórico de conversa guardado com prazo (ex.: 90 dias) e apagável. Sem dados sensíveis nos registros técnicos.
- **Trilha:** cada ação do agente fica registrada como ação do usuário, com a origem "assistente WhatsApp" e o texto confirmado.
- **Abuso e custo:** limite de mensagens por usuário por minuto e por mês (do plano); corte de conversas longas; resposta padrão quando o limite acaba.

## Arquitetura (resumo)
`WhatsApp ➜ webhook (verifica, deduplica) ➜ fila ➜ orquestrador do agente ➜ ferramentas internas ➜ resposta WhatsApp`
- **Orquestrador:** guarda a conversa e um **rascunho de pedido** (o que já foi coletado) no banco; a IA (Claude, com uso de ferramentas) decide a próxima pergunta ou ferramenta. **Decisão minha:** modelo de custo menor para a maior parte das mensagens e um mais capaz para pedidos complexos.
- **Ferramentas internas:** funções do servidor que chamam os **mesmos serviços** da plataforma (`EnvelopeService`, `TemplateFiller`, verificação, contatos), não a API por HTTP. Cada ferramenta valida, checa permissão e plano e devolve texto curto.
- **Entrada de PDF:** o arquivo recebido pela Meta é baixado, validado (tamanho, tipo) e entra no mesmo caminho do passo 1 do assistente (rascunho).
- **Independente do canal:** o orquestrador não conhece o WhatsApp; ele recebe "texto + anexo" e devolve "texto + botões". Isso permite o mesmo assistente no site e o número do cliente na fase 2.

## Como fica configurado
**Plataforma (super administrador):** credenciais da Meta (token, id do número, segredo do webhook), chave e modelos de IA, textos-base do agente (limites de assunto), mensagens de modelo aprovadas, painel de custo e saúde (mensagens por dia, falhas, taxa de recusa).
**Empresa (dono):** liga/desliga o assistente; lista quem pode usar (usuários vinculados); quais ações são permitidas (ex.: "só consultar" para alguns); padrões de envio (prazo, lembretes) que o agente aplica sem perguntar; tela de uso (mensagens no mês).
**Usuário:** em *Meu perfil*, vincula e desvincula o WhatsApp.

## Planos e cobrança (decisão minha)
- Recurso novo do plano `assistente` (liga/desliga) e limite novo `assistente_mensagens` por mês, no modelo que o sistema já tem (recursos, limites e **consumo medido**). **Só em planos superiores**, com a franquia de mensagens incluída e **excedente cobrado por consumo** (ou pacote extra), porque cada mensagem custa dinheiro (Meta + IA) e não pode ser ilimitada.
- Cobrança em si segue adiada (fase 4); nesta etapa o sistema só **mede e barra** pela franquia.
- Preços exatos dependem da tabela oficial da Meta (as fontes divergem sobre as respostas dentro de 24 h) e do custo real de IA medido no piloto.

## Fases
1. **Piloto interno:** número de teste, 1 empresa, só consultar + verificar. Mede custo e qualidade.
2. **Criar e enviar** com modelos e confirmação (o fluxo central).
3. **PDF recebido na conversa**, vencimento de contrato e lembretes por mensagem de modelo.
4. **Número do cliente** (Embedded Signup) e/ou assistente no site.

## Riscos
- **Meta classificar o agente como "IA de uso geral"** e bloquear o número: mitigação = escopo restrito, recusa de outros assuntos, descrição de uso objetiva na análise do app.
- **Bloqueio do número único** afeta todos os clientes: mitigação = qualidade de mensagem, plano B pelo site, e segundo número reserva.
- **Erro da IA ao montar o envio:** mitigação = resumo gerado pelo sistema + confirmação explícita + validação de servidor.
- **Custo imprevisível:** mitigação = franquia, limites por usuário, modelo barato por padrão, painel de custo.
- **Cadastro na Meta demora** (verificação da empresa, revisão do app, modelos): começar o processo já, em paralelo.

## Critérios de aceite da v1
Fluxo "modelo + pessoa + valores" em ≤ 3 perguntas; nenhuma ação com efeito sem confirmação; número não vinculado nunca acessa dado; mensagem duplicada da Meta não duplica envio; limite do plano barra com mensagem clara; recusa de assuntos fora de documentos; custo médio por conversa medido.

## Testes previstos
Webhook (assinatura inválida, duplicada, fora de ordem); vínculo do número (código, expiração, tentativas); orquestrador com IA simulada (extração, perguntas mínimas, resumo, "sim" que não corresponde ao resumo, expiração); ferramentas (permissão, plano, isolamento entre empresas); injeção de instruções em título/arquivo; limites e consumo; recusa de assunto geral.
