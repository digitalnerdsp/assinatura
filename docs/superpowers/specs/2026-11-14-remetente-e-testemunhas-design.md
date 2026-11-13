# Complemento — Remetente que assina junto e testemunhas (Fase 1b)

Data: 14/11/2026 · Status: **aguardando aprovação** · Complementa `2026-11-13-menu-modulos-planos-design.md` e entra **antes da Fase 2**.

## 1. Intenção

1. Todo documento criado sai **já assinado por quem o enviou** (a empresa), com a assinatura posicionada no PDF e **sem selfie nem
   código**, como na ZapSign: quem envia já está autenticado.
2. Contratos que exigem testemunhas ganham uma **lista opcional de testemunhas**, separada da de signatários, com adicionar e remover.
3. Pela **API**, a empresa assina sozinha (decisão do dono): o primeiro cliente é o sistema de hospedagem, que é da própria empresa.

## 2. Objetivos, não-objetivos e anti-objetivos

**Objetivos**
- Assinatura do remetente automática no painel e na API, com prova suficiente para o documento ser verificável.
- Testemunhas que realmente assinam: contam para concluir o documento, aparecem no carimbo, no relatório e podem ser posicionadas no PDF.
- Nada disso aumenta o atrito de quem só quer enviar um contrato.

**Não-objetivos (por ora)**
- Assinatura do remetente com certificado digital próprio da empresa (e-CNPJ A1 de cada cliente). Fica como evolução: quando existir, substitui a assinatura eletrônica simples do remetente sem mudar a interface.
- Testemunha com papel diferente de assinar (sem "apenas ciente").
- Testemunhas com regras de verificação próprias (usam as mesmas do envelope, ver 5).

**Anti-objetivos**
- Uma chave de API vazada assinar contratos em nome da empresa sem que ninguém tenha autorizado essa função.
- Documento ficar "concluído" sem as testemunhas que o remetente incluiu.
- Aparecer como se uma pessoa tivesse visto e assinado quando foi o sistema (a prova deve dizer a verdade: assinou **via API**).

## 3. Abordagens consideradas

| Tema | Alternativas | Escolha |
|---|---|---|
| Remetente na API | (a) chave assina sozinha sem controle; (b) assina só se a chave tiver permissão e a empresa tiver delegado a um representante; (c) representante recebe link | **(b)**, definida com o dono. A chave só assina se for habilitada para isso (desligado por padrão) e há um representante cadastrado que aceitou o termo de delegação. (a) falha no anti-objetivo da chave vazada; (c) o dono escolheu assinar pela API. |
| Remetente como dado | (a) coluna nova só para "quem enviou assinou"; (b) o remetente é um signatário comum (`signers`) marcado como remetente e já assinado | **(b)**: o relatório, o carimbo, o posicionamento, a conclusão e os webhooks já funcionam sobre `signers`, então nada precisa de caminho paralelo. |
| Testemunha | (a) papel em tabela própria; (b) o papel `witness` que `signers.role` já prevê | **(b)**, completando o fluxo que hoje só existe no banco. |

## 4. Remetente que assina junto

**Política por empresa** (`tenant_signing_settings.sender_policy`): `always` (padrão, como você pediu), `optional` ou `never`.
Fica aí porque você quer liberdade de configuração; o padrão atende "sempre".

**No painel (web)**
- O formulário de criar documento sempre mostra a linha **"Eu (remetente)"**, como primeiro signatário, fixa quando a política é `always`.
- Ao **Liberar para assinatura**, a assinatura do remetente é aplicada naquele instante, sem selfie, código ou localização. A assinatura vem de
  **Minha assinatura** (novo, em Meu perfil: desenhar ou digitar uma vez, reaproveitada sempre); sem uma salva, o formulário pede para criar antes de enviar.
- A posição no PDF usa a mesma tela de posicionamento de hoje, com "Eu (remetente)" na lista de quem posicionar.
- Em envelopes **sequenciais**, o remetente é sempre o primeiro (os demais já recebem o documento assinado por ele).
- Documento só do remetente (sem outros signatários) é permitido e conclui na hora.

**Pela API**
- Nova permissão na chave: `can_sign_as_company` (padrão desligada, no mesmo padrão do `read_only`).
- Nova configuração da empresa: **representante** (nome, e-mail, CPF) mais o **termo de delegação** aceito por um usuário da empresa
  (registra quem aceitou, quando e de qual IP). Sem representante e termo, a API não assina.
- Na criação (`POST /envelopes`): `sender_signs` (booleano). Padrão segue a política da empresa. Com a política `always` e uma chave sem permissão
  ou sem representante, a resposta é **422** com código claro (`sender_signature_not_allowed` ou `no_company_representative`);
  com `optional`, `sender_signs: false` é aceito. Posição da assinatura da empresa vai em `signature_fields` com `signer: "sender"`.
- A assinatura aplicada usa a imagem salva do representante; se não houver, o nome digitado em estilo de assinatura.

**Prova gravada (evento `document_signed` do remetente):** método (`session` no painel, `api_key` na API), usuário ou id da chave, IP, aparelho,
hash do documento e hora. O carimbo e o relatório dizem *"Assinado por Empresa X, por seu representante Fulano (CPF ***.123.***-**), via integração"* quando for pela API.

## 5. Testemunhas

- Formulário de criar documento: seção **"Testemunhas (opcional)"** logo abaixo de Signatários, com o mesmo botão de adicionar e remover; cada testemunha tem nome, e-mail,
  CPF e telefone, como os signatários. Limite de 10 por envelope (ajustável em configuração).
- **Ordem:** em envelope paralelo, todos assinam juntos; em sequencial, as testemunhas formam o **último grupo**, depois de todos os signatários.
- **Conclusão:** o documento só conclui quando todos os signatários **e todas as testemunhas** assinam. Recusa de testemunha recusa o documento, como a de signatário.
- **Verificações:** a testemunha segue as verificações marcadas para o envelope (código, selfie, documento, localização). É minha decisão; se você quiser dispensá-las para testemunhas, vira uma opção.
- **Posicionamento:** testemunhas aparecem na lista de quem posicionar no PDF.
- **Carimbo e relatório:** o relatório ganha a seção "Testemunhas" separada de "Signatários"; o carimbo do rodapé identifica o papel.
- **Outros lugares:** "Minhas assinaturas" inclui testemunhas; avisos, lembretes e links funcionam como para signatários; reenviar e novo link também.
- **API e webhooks:** `signers[]` aceita `role: "signer" | "witness"` (o campo já aparece nas respostas); eventos de assinatura de testemunha saem como `signer.signed` com `role: "witness"`.

## 6. Mudanças de dados

- `signers`: `is_sender` (booleano, padrão falso) e `signed_via` (`link` | `session` | `api_key`, nulo para os já existentes).
- `tenant_signing_settings`: `sender_policy` (padrão `always`), `representative_name`, `representative_email`, `representative_cpf`,
  `delegation_accepted_at`, `delegation_accepted_by`, `delegation_accepted_ip`.
- `api_keys`: `can_sign_as_company` (padrão falso).
- `users`: `signature_type` (`drawn` | `typed`) e `signature_data` (assinatura salva do usuário).
- Regras de fluxo hoje fixas em `role = 'signer'` (conclusão em `SigningService`, `Envelope::activeSigners`, `Minhas assinaturas`, relatório) passam a considerar
  `signer` **e** `witness` na conclusão e na vez de assinar.

## 7. Testes obrigatórios

- Web: criar com política `always` cria o remetente já assinado ao liberar, sem exigir selfie, código ou localização; falta de "Minha assinatura" bloqueia com mensagem.
- Sequencial: remetente primeiro, testemunhas por último; testemunha não assina antes dos signatários.
- Conclusão só com signatários **e** testemunhas; recusa de testemunha recusa o documento.
- API: sem permissão da chave, sem representante ou sem termo → 422 com o código certo; com tudo → assina e a prova diz `api_key`; `sender_signs:false` só com política `optional`.
- Evidência: o evento do remetente traz método, IP, aparelho e hash do documento; nunca marca como "visto" quem não viu.
- Selagem: PDF final traz o remetente, signatários e testemunhas no relatório e no carimbo.
- Posicionamento: campos para `sender`, signatário e testemunha são desenhados na página certa.
- Isolamento: uma chave de outra empresa nunca assina em nome desta; a assinatura salva do usuário nunca vaza entre empresas.

## 8. Fase e entrega

**Fase 1b** (antes da Fase 2), em duas partes que entram em produção sozinhas:
1. **Testemunhas** (completar o papel `witness` no fluxo, formulário, relatório, API).
2. **Remetente que assina junto** (Minha assinatura, política, representante, permissão da chave, aplicação no envio, prova).

## 9. Decisões minhas (para você conferir)

- Política da empresa com padrão `always` (como você pediu), mas configurável.
- Remetente é sempre o primeiro em envelopes sequenciais.
- Testemunhas sempre no último grupo em envelopes sequenciais, com as mesmas verificações do envelope.
- Limite de 10 testemunhas por envelope.
- A API só assina com chave habilitada, representante cadastrado e termo aceito; caso contrário, erro claro em vez de enviar sem assinatura da empresa.
- A assinatura do remetente é eletrônica simples com prova de sessão ou de chave de API; certificado próprio da empresa fica como evolução.

## 10. Pendências suas

1. **Texto do termo de delegação** que o representante aceita (autoriza o sistema a assinar em nome da empresa). Posso redigir uma base; a revisão final é do advogado, junto com os termos de uso.
2. **Quantas testemunhas no máximo** (padrão 10) e se testemunhas devem **dispensar selfie e documento** (padrão: seguem o envelope).

## 11. Riscos

- **Chave de API vazada assina contratos:** mitigado por permissão desligada por padrão, representante e termo obrigatórios, e prova que registra `api_key` e IP. Recomendação: usar a permissão só em chaves dos seus próprios sistemas.
- **Validade jurídica da assinatura da empresa por API:** é assinatura eletrônica simples, válida entre as partes por acordo (MP 2.200-2, art. 10, §2º); a cláusula de aceite de assinatura eletrônica nos contratos e o termo de delegação devem passar pelo advogado.
- **Testemunha que não responde trava o contrato:** os lembretes e a expiração já existentes se aplicam; a lista de pendentes deixa claro quem falta.
