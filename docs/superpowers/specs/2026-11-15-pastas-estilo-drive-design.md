# Complemento — Pastas e etiquetas estilo Google Drive dentro de Documentos (Fase 1c)

Data: 15/11/2026 · Status: **aprovado em 15/11/2026 · entregue em 16/11/2026** · Complementa `2026-11-13-menu-modulos-planos-design.md`.

## 1. Intenção

Organizar os documentos como no Google Drive: pastas com **subpastas**, caminho clicável, cartões de pasta, etiquetas coloridas e ações
(criar, renomear, mover, apagar) por botões e popups, funcionando bem no **celular**. Tudo dentro de **Documentos**; sai de Configurações.

## 2. Objetivos, não-objetivos e anti-objetivos

**Objetivos**
- Navegar por pastas e subpastas na própria lista "Documentos criados", com caminho (migalhas) clicável.
- Criar, renomear, mover e apagar pastas; mover documentos; criar, renomear, trocar a cor, apagar e aplicar etiquetas.
- Usar bem no celular (toque, sem depender de arrastar nem de passar o mouse).

**Não-objetivos (por ora)**
- Arrastar e soltar (pode vir depois no desktop; o celular nunca depende disso).
- Seleção em massa de documentos e compartilhamento de pastas.
- Pasta "inteligente" (por regra) e cores de pasta.

**Anti-objetivos**
- Apagar uma pasta e perder documento assinado ou em andamento.
- Criar ciclo (pasta dentro de si mesma ou de uma subpasta).
- Pastas e etiquetas de uma empresa aparecerem para outra.

## 3. Regras de negócio

- **Subpastas:** quantidade livre, mas só **3 níveis abaixo da pasta primária** (decisão sua): primária › subpasta 1 › subpasta 2 › subpasta 3, 4 níveis no total. Uma pasta do 4º nível não aceita subpastas.
- Nome da pasta: até 120 caracteres, **único dentro da mesma pasta** (sem diferenciar maiúsculas); em pastas diferentes pode repetir.
- **Mover pasta:** nunca para dentro de si mesma nem de uma descendente; o limite de níveis vale também para tudo que vai junto (mover uma pasta com subpastas não pode estourar os 4 níveis).
- **Pastas nunca vão para a lixeira nem para o arquivo.** Só existem criar, renomear, mover e **apagar** (com confirmação).
- **Apagar pasta** (vale para a pasta e todas as subpastas dela):
  - rascunhos, cancelados e expirados vão para a **lixeira de 30 dias** (a regra de sempre);
  - em andamento, concluídos e recusados **não podem ser apagados**: ficam e passam para a **pasta de cima** da pasta apagada (raiz, se for de primeiro nível); arquivados continuam arquivados e também sobem;
  - a confirmação mostra os números antes: "N documentos vão para a lixeira, M ficam em *Pasta de cima*, K subpastas serão apagadas".
- Restaurar da lixeira um documento cuja pasta já não existe devolve o documento à raiz.
- **Etiquetas:** nome até 60 caracteres, único por empresa; cor de uma lista fixa. Apagar a etiqueta só a tira dos documentos.
- **A lista de documentos continua como está** (decisão sua): lista **todos** os documentos, com os mesmos filtros, busca, status, arquivados e paginação de hoje. Ela só ganha, como informação, o **caminho da pasta** e as **etiquetas** de cada documento.
- As pastas entram como **navegação por cima da lista**:
  - sem pasta aberta, aparecem os cartões das pastas primárias e a lista mostra **todos** os documentos, como hoje;
  - abrir uma pasta mostra o caminho clicável, os cartões das subpastas e **filtra a lista para os documentos daquela pasta e das subpastas dela** (nada fica escondido numa subpasta);
  - busca, status e etiqueta continuam funcionando junto com a pasta aberta.

## 4. Telas e ações

**Documentos criados** (`/envelopes`, ganha a visão de pastas; a URL de filtro `?folder=` continua funcionando)
- Barra superior: caminho **Documentos › Contratos › 2026**, **Nova pasta**, **Criar documento**, alternador grade/lista (a preferência fica guardada no navegador).
- Faixa de **etiquetas** (chips coloridos): toque filtra; **Nova etiqueta** abre popup (nome e cor); cada chip tem menu ⋮ com Renomear/Cor, Apagar.
- **Cartões de pasta** (nome e quantidade de itens) com ⋮: Abrir, Renomear, Mover, Apagar.
- **Documentos** abaixo (lista como hoje, com pasta e etiquetas), cada um com ⋮: Abrir, **Mover para…**, **Etiquetas…**, Mover para a lixeira/Arquivar (as regras de status que já existem).
- **Popups** (Bootstrap): criar/renomear pasta, criar/editar etiqueta, **Mover para…** (árvore de pastas com a pasta atual marcada e as inválidas desabilitadas), confirmar apagar pasta (com os números).
- Configurações perde o item **Pastas e etiquetas**; a antiga `/organizacao` redireciona para `/documentos/pastas` (marcadores antigos não quebram).
- **Ajuste de 17/11 (pedido do dono):** a navegação de pastas (migalhas, cartões, etiquetas, botões e popups) fica numa página própria, **Documentos › Pastas e etiquetas** (`/documentos/pastas`), como o Explorador do Windows: só as pastas e, dentro delas, os documentos daquela pasta como itens (sem busca, filtros nem tabela). **Documentos criados** (`/envelopes`) volta a ser a lista simples, com pasta/etiquetas e o ⋮ em cada linha; a raiz da página nova mostra todos os documentos.

**Celular (obrigatório validar)**
- Popups em **tela cheia** abaixo de 576 px, botões de ação no rodapé, alvos de toque com pelo menos 44 px.
- Menus ⋮ por **toque** (sem depender de hover), nenhum controle escondido atrás de arrastar.
- Sem rolagem horizontal; lista de documentos vira cartões em tela estreita; migalhas rolam na horizontal se forem longas.
- Verificação em 375 px (celular) e 768 px (tablet) nos dois temas, mais auditoria de contraste (≥ 4,5:1).

## 5. Dados e API

- `folders.parent_id` (uuid nulo, referência à própria tabela). A unicidade passa de `(tenant_id, name)` para nome único **por pasta pai** (sem diferenciar maiúsculas; trata a raiz como um valor próprio).
- Serviço único `FolderService` (criar, renomear, mover, árvore, caminho, contagens, apagar com a regra de 3) usado pela tela **e** pela API, para as duas se comportarem igual.
- **API v1 de pastas:** ganha `parent_id` na criação e na resposta, `PATCH /folders/{id}` (renomear e mover) e `DELETE` com a mesma regra de apagar; etiquetas ganham `PATCH`. Documentação OpenAPI atualizada.
- `GET /me` e os filtros existentes (`folder_id`, `tag_id`) continuam iguais; `folder_id` passa a aceitar subpastas.

## 6. Abordagens consideradas

| Tema | Alternativas | Escolha |
|---|---|---|
| Hierarquia | (a) lista de adjacência (`parent_id`) com consultas recursivas; (b) caminho materializado; (c) pastas planas | **(a)**: simples, o PostgreSQL resolve árvore com consulta recursiva, e o limite de 4 níveis mantém tudo barato. |
| Mover/ações | (a) arrastar e soltar; (b) menus e popups por toque | **(b)**: funciona igual no celular; arrastar fica como melhoria futura no desktop. |
| Local | (a) página nova "Pastas"; (b) dentro de Documentos criados | **(b)**, como você pediu: uma tela só, sem trocar de lugar para organizar. |

## 7. Testes obrigatórios

- Criar, renomear (duplicado na mesma pasta recusado; igual em pastas diferentes aceito), mover (ciclo e limite de 4 níveis recusados, inclusive com subpastas junto) e caminho/árvore corretos.
- Apagar pasta: rascunho/cancelado/expirado vão para a lixeira; em andamento/concluído/recusado/arquivado sobem para a pasta de cima; subpastas apagadas em cascata; números da confirmação conferem; restaurar da lixeira volta à raiz.
- Isolamento entre empresas em todas as ações (404) e na API.
- Visão: sem pasta aberta a lista traz todos os documentos como hoje; abrir uma pasta filtra para os documentos dela e das subpastas; busca, status, etiqueta e arquivados funcionam junto; cada linha mostra pasta e etiquetas.
- Etiquetas: criar, renomear, trocar cor, apagar (some dos documentos), aplicar pelo ⋮.
- API: `parent_id`, `PATCH`, `DELETE` com a regra; OpenAPI completo (teste que exige toda rota documentada).
- Interface (no navegador): 375 e 768 px, popups em tela cheia, menus por toque, sem rolagem horizontal, contraste.

## 8. Decisões minhas (para você conferir)

- Nome único por pasta pai (a profundidade de 3 níveis abaixo da primária foi decisão sua).
- Documentos que não podem ir para a lixeira sobem para a pasta de cima ao apagar a pasta.
- A API segue a mesma regra de apagar pasta que a tela.
- Abrir uma pasta inclui os documentos das subpastas na lista (nada fica escondido).
- Sem arrastar e soltar nem seleção em massa nesta fase.

## 9. Riscos

- **Apagar pasta com muitos documentos:** o popup mostra os números antes; a lixeira dá 30 dias para recuperar o que foi para ela.
- **Integrações que apagam pastas pela API:** passam a mover/ lixeirar documentos como a tela; avisado na documentação da API.
