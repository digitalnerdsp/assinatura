# Modelos em PDF com lacunas — desenho (para aprovação)

Data: 18/11/2026. Substitui o modelo em HTML (`{{variáveis}}`), que não serve para quem não é técnico.

## Intenção
Quem envia documentos parecidos (contrato de aluguel, proposta, declaração) hoje sobe um PDF já preenchido a cada vez. Com o modelo, sobe o PDF **uma vez**, marca onde ficam as lacunas (nome, valor, data…) e, nos usos seguintes, só preenche um formulário em português. O sistema escreve os valores no PDF e o documento segue para o assistente de criação normal.

## Objetivos
- Criar um modelo sem escrever código: subir o PDF, clicar onde há uma lacuna, dar um nome a ela.
- Usar o modelo: formulário com os nomes das lacunas, prévia do PDF preenchido, e seguir para o passo 2 do assistente (signatários, posição das assinaturas e envio).
- Valores sempre escritos como texto no PDF (nenhum valor vira código ou HTML).

## Fora do escopo (decidido com o usuário)
- O modelo **não guarda** posição de assinaturas nem signatários; isso continua sendo definido ao criar o documento.
- Sem Word, sem HTML, sem campos de formulário internos do PDF (AcroForm). Os modelos em HTML já criados são **removidos** (ninguém os usou).
- Sem tipos além de texto (uma linha ou várias linhas). Data, número e máscara de CPF ficam para depois.

## Como funciona

**Criar/editar modelo** (`/modelos/novo`, `/modelos/{id}`): nome do modelo + upload do PDF. O PDF aparece na tela (mesmo visualizador da etapa de posicionar assinaturas). Clicar ou tocar no documento abre um popup: **nome da lacuna** (ex.: "Nome do contratante"), "preencher com várias linhas?" e "obrigatório". A lacuna vira um retângulo que se arrasta e redimensiona; ao lado há a lista das lacunas (renomear, remover, duplicar). Trocar o PDF zera as lacunas, com confirmação.

**Usar modelo** (`/modelos/{id}/usar`): título do documento (começa com o nome do modelo), pasta opcional e um campo para cada lacuna, na ordem de cima para baixo da página. Botão "Ver prévia" abre o PDF preenchido. "Continuar" gera o PDF, cria o **rascunho** (`EnvelopeService::createDraft`, com `template_id`) já com o passo 1 concluído e leva para o passo 2.

## Dados
- `document_templates`: nova coluna de PDF (`pdf_path`, `pdf_sha256`, `pdf_size`, `page_count`); `body` deixa de ser usado. A migração apaga os modelos HTML existentes.
- Nova tabela `template_fields`: `id`, `template_id`, `key` (identificador gerado do nome, único no modelo; usado pela API), `label`, `page`, `x`, `y`, `width`, `height` (frações da página, origem no canto superior esquerdo, igual a `signature_fields`), `multiline`, `required`, `position`.
- O PDF do modelo fica no disco privado da empresa e **conta no armazenamento do plano**; remover o modelo apaga o arquivo e devolve o espaço.

## Escrever no PDF
Novo endpoint no serviço de selagem: `POST /v1/fill` (mesmo token do selador): recebe o PDF e uma lista `{página, x, y, largura, altura, texto, multilinha}` e devolve o PDF com os textos desenhados em cima, usando `pypdf` + `reportlab` (já instalados). Fonte com acentos (DejaVu). A letra começa em 11 pt e **diminui até caber** na lacuna (mínimo 6 pt); se ainda não couber, o sistema recusa com a mensagem "O valor de 'Nome' não cabe na lacuna" apontando o campo. Respeita página girada e caixa de corte do PDF.

## Segurança e limites
- Só PDF válido, dentro do limite de tamanho e de armazenamento do plano; PDF protegido por senha é recusado.
- Até 40 lacunas por modelo, nome até 60 caracteres, valor até 500.
- Valores nunca são interpretados (sem HTML, sem fórmulas); tudo isolado por empresa; a prévia tem limite de requisições.

## API v1
`GET /templates` e `GET /templates/{id}` passam a devolver as lacunas (`key`, `label`) em vez do corpo HTML. `POST /templates/{id}/documents` continua, com `variables` indexadas pelo `key` de cada lacuna. **Criar e editar modelos pela API sai** (exige PDF + posições, que só fazem sentido na tela); remover continua.

## Abordagens consideradas
1. **Estampar texto pelo serviço de selagem** — escolhida pelo usuário: reaproveita o que já existe, sem biblioteca nova.
2. Biblioteca PHP (FPDI): segunda ferramenta de PDF, sem ganho para o usuário.
3. Campos de formulário do PDF: complexo e inconsistente entre leitores, e atrapalha o selo final.

## Pontos que decidi (conferir)
- Apagar os modelos HTML existentes na migração.
- Tirar criar/editar modelos da API.
- Letra automática de 11 pt até 6 pt em vez de cortar o texto.
- Primeira versão só com texto (uma ou várias linhas).

## Testes previstos
Migração e modelo; criação/edição com PDF (válido, inválido, protegido, grande, cota); lacunas (limites, chave única, troca de PDF); isolamento entre empresas; uso do modelo (valores, obrigatórios, não cabe, acentos); rascunho criado no passo 1 com `template_id`; `POST /v1/fill` (posição, rotação, quebra de linha, redução de letra); API; relatórios por modelo continuam.
