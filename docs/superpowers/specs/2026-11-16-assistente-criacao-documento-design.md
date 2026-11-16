# Assistente de criação de documento em 4 passos — desenho

Status: **para aprovação do dono**. Fontes: os dois PDFs de escopo do ZapSign (plano gratuito), os prints do celular enviados pelo dono e o código atual. Copiamos o **fluxo e a usabilidade**, não a aparência (o visual segue o tema ativo).

## Objetivo
Trocar a página única "Novo documento" (documento, signatários, testemunhas, posição, opções, tudo junto) por um assistente linear de 4 passos, uma decisão por tela, com rascunho gravado e dados mantidos ao voltar.

## Decisões do dono (já fechadas)
- Só **PDF**, **um arquivo** por documento (DOCX e vários arquivos ficam para depois).
- A **criação de pasta volta para o passo 1** (escolher ou criar a pasta ali mesmo).
- Posicionar a assinatura **por clique do mouse ou toque na tela** no documento.
- Plano/limites não entram na conversa de usabilidade (já existem e continuam valendo).
- O sistema fala "documento", nunca "envelope" (feito no commit f1cf863).

## Fora do escopo (agora)
DOCX; vários arquivos; Observadores; "Contatos e grupos" (só Contatos, sem grupos); forma de envio **por signatário** (continua uma só para o documento); "Enviar documento assinado por WhatsApp"; girar campo; autenticação por selo de plano (SMS/WhatsApp de código, biometria, certificado digital). O assistente de **detalhe com abas**, os **cards de contagem** da lista e as **preferências da organização** são entregas seguintes.

## Os 4 passos

Stepper no topo do cartão: passo atual = círculo azul com o número; concluído = check; pendente = número cinza; ao voltar a um concluído = ícone de lápis. **Não é clicável** (navegação só por Voltar e Continuar, como no ZapSign). No celular vira lista vertical e só o passo atual fica aberto. Botão principal desabilitado (cinza) até o passo ser válido.

**Passo 1 — Selecionar documento.** "Novo documento", link "Preciso de ajuda", linha da pasta (`Documentos · Alterar pasta`, com **Criar pasta** no mesmo seletor), pergunta "Qual documento será assinado?", zona de arrastar/soltar ("PDF, até {max} MB"). Depois do envio: barra "Subir outro arquivo" e o arquivo listado com ação de abrir em outra aba e remover. O nome do documento vem do nome do arquivo (sem `.pdf`) e pode ser editado ali.
- O arquivo é gravado **neste momento** (cria o rascunho). Trocar o arquivo depois apaga o antigo, acerta o armazenamento da empresa e **zera os campos posicionados** (o número de páginas pode mudar), com aviso antes.

**Passo 2 — Quem irá assinar?** Começa só com o campo **Nome do signatário** e o botão **+ Signatário(a)**.
- Switch **Eu vou assinar este documento**: respeita a política da empresa (sempre = ligado e travado; opcional = switch; nunca = não aparece). Sem assinatura salva em Meu perfil, avisa com link, como hoje.
- Atalho **Contatos** (preenche nome, e-mail e telefone a partir da agenda).
- Switch **Autenticação avançada** abre o formulário completo de cada signatário (e-mail, CPF, telefone, **função**: Assinar ou Assinar como testemunha) e o bloco **verificações do documento** (código por e-mail, selfie, foto do documento, localização, as mesmas de hoje, valem para o documento todo).
- Com 2 ou mais signatários aparece **Assinar em ordem** (sequencial/paralelo).
- E-mail **não é pedido aqui** (só no passo 4), como no ZapSign.

**Passo 3 — Posicionar assinaturas (opcional).** Documento em rolagem contínua (pdf.js). **Clique ou toque** em qualquer ponto abre um popup ancorado no ponto (no celular, uma folha na parte de baixo) com o seletor de signatário e três tipos de campo: **Assinatura**, **Visto** (rubrica) e **Texto**. O campo nasce no ponto, como caixa tracejada com o nome do signatário e a legenda; dá para **arrastar, redimensionar, duplicar e remover**. Botões: *Editar documento* (volta ao passo 1), *Continuar sem posicionar*, *Salvar e continuar* (desabilitado até existir um campo). Aviso verde "Assinaturas posicionadas com sucesso". Ao voltar: *Salvar alterações*.
- As alterações ficam na tela até salvar; sair com alteração pendente avisa.

**Passo 4 — Enviar documento.** Switch **Ativar envio automático dos links** (ligado = convites por e-mail/WhatsApp/SMS pelos canais marcados; desligado = modo manual, os links aparecem no detalhe depois do envio). Por signatário: **e-mail obrigatório** quando o envio automático está ligado. Mensagem personalizada (até 500 caracteres). **Lembretes automáticos a cada N dias** (padrão 3). **Data limite para assinatura** (data; vira os dias de validade, entre 1 e 365). **Permitir recusar o documento** (padrão desligado). Etiquetas. Botões **Voltar** e **Enviar e finalizar**, que libera o documento (mesmo fluxo de envio de hoje, incluindo assinatura do remetente) e leva ao detalhe com o aviso de sucesso.
- Os links do signatário não existem antes do envio (só o hash é guardado), então "Copiar link" e "Enviar por WhatsApp" ficam no detalhe, onde já existem.

## Regras de navegação e estado
- **Cada passo é uma página** (`/documentos/{id}/passo/{n}`) e o rascunho vive no banco: recarregar, fechar e voltar mais tarde retoma de onde parou; Voltar mantém tudo.
- Só se entra no passo *n* se os anteriores estiverem válidos (`wizard_step`); caso contrário vai ao primeiro passo pendente. Documento que já saiu de rascunho abre o detalhe, não o assistente.
- O rascunho aparece na lista como **Rascunho** e a linha leva de volta ao passo em que parou. Pode ir para a lixeira.
- **Rascunhos abandonados** (sem envio e sem alteração há 14 dias) vão para a lixeira pela rotina diária que já existe; a lixeira apaga de vez em 30 dias. Liberam o armazenamento quando apagados.

## Abordagem (escolhida)
Cada passo com sua própria página (opção A), em vez de uma página única com JavaScript. Motivo: recarregar e voltar funcionam, o celular fica leve e cada passo valida no servidor. A tela de posicionamento é a única com JavaScript pesado (pdf.js, que o sistema já usa). Descartada: página única (perde o estado ao recarregar). Descartados por ora: DOCX (precisa de conversão no servidor) e vários arquivos (mexe em selagem, verificação e downloads).

## Dados
- `envelopes`: `wizard_step` (tinyint, nulo = criado fora do assistente, como API e modelos); `allow_refuse` (bool, padrão **true**, o assistente grava o que o usuário marcou; API e modelos seguem como hoje).
- `signers`: `email` passa a aceitar nulo no rascunho; **exigido no envio** quando o envio automático está ligado.
- `signature_fields`: `type` (`signature` | `initials` | `text`, padrão `signature`) e `value` (texto, preenchido pelo signatário no tipo Texto).
- `signatures`: `initials_type`, `initials_data` (a rubrica do signatário, desenhada ou digitada, usada em todos os campos Visto dele).
- Campos passam a apontar para o `signer_id` real (o remetente é um signatário criado no passo 2), sem o índice "sender|0..n" de hoje.

## Impacto fora do assistente
- **Serviço:** `EnvelopeService` ganha operações de rascunho (criar com o PDF e sem signatários, trocar documento, sincronizar signatários, sincronizar campos, opções de envio). O `create` atual (API, modelos) continua e passa a usar as mesmas peças. `send` recusa documento sem signatário ou sem e-mail quando exigido.
- **Tela do signatário:** com campo **Visto** ele desenha/digita uma rubrica (um quadro a mais); com campo **Texto** preenche cada texto pedido (obrigatório). Com `allow_refuse` falso o "Recusar assinatura" some e a rota recusa (403).
- **Selagem:** o carimbo em `placements` e o `sealer/report.py` passam a desenhar rubrica e texto, além da assinatura; relatório e verificação seguem iguais.
- **Telas antigas:** a página única `envelopes/create` e o `store` web saem (o assistente os substitui); API e geração a partir de modelo ficam.

## Erros e casos de borda
PDF inválido, vazio, protegido por senha ou acima do limite (mensagem clara no passo 1). Estouro do armazenamento/limite do plano bloqueia o upload com a mensagem de hoje. Documento de outra empresa = 404. Dois cliques em "Enviar e finalizar" não enviam duas vezes. Remover um signatário remove os campos dele. Só Texto/Visto de signatário que existe. Coordenadas sempre entre 0 e 1; no máximo 60 campos. Documento enviado enquanto outra aba edita: a aba antiga recebe "este documento já foi enviado".

## Testes
Passos e guardas (entrada fora de ordem, outra empresa, já enviado); retomar rascunho; trocar o arquivo (armazenamento e campos); política do remetente (sempre/opcional/nunca, com e sem assinatura salva); signatário sem e-mail com envio automático (barra) e em modo manual (passa); campos por tipo e por signatário; `allow_refuse`; limpeza de rascunhos abandonados; envio idempotente; API e modelos sem regressão; selagem com Assinatura, Visto e Texto. Conferência no navegador em 375, 768 e 1024 px, claro e escuro, com contraste mínimo de 4,5:1.

## Entrega (ordem)
1. Dados e serviço de rascunho. 2. Passo 1 (inclui criar pasta). 3. Passo 2. 4. Passo 3 só com Assinatura. 5. Passo 4 e envio. 6. Lista com Rascunho, retomada e limpeza. 7. **Visto e Texto** (tela do signatário e selagem). 8. Remover a página antiga e atualizar a documentação. Tudo entra antes de usar em produção.

## Deixado para quem constrói
Textos exatos das mensagens e do "Preciso de ajuda"; animações; tamanho padrão de cada tipo de campo; ícones.

## Minhas escolhas (o dono pode trocar)
Observadores, grupos de contatos, forma de envio por signatário e girar campo ficam para depois. "Permitir recusar" vem **desligado** no assistente (como no ZapSign) mas **ligado** na API e nos modelos para não mudar o que já existe. Links dos signatários só depois do envio. Rascunhos abandonados vão para a lixeira em 14 dias. As verificações de identidade ficam por documento, não por signatário.
