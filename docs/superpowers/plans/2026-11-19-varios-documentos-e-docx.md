# Vários documentos por envio e aceite de DOCX — plano

**Meta:** o remetente envia até 10 arquivos (PDF ou DOCX) num só envio; o signatário vê todos, assina **uma vez**, e cada PDF é selado e entregue.

**Decisões do cliente:** DOCX convertido por LibreOffice dentro do selador (nada sai do servidor); pacote com uma assinatura só.

## Arquitetura (decisões minhas)
- **Documento principal continua em `envelopes.document_id`** (e `final_path` etc.), então tudo que existe segue funcionando. Os extras ficam em `envelope_documents` (envelope_id, document_id, position, final_path, final_sha256, final_size, sealed_at). Um envio com 1 arquivo não muda em nada.
- `signature_fields.document_id` (nulo = principal). Os campos continuam em frações da página.
- Um código de verificação por envio; a página de verificação lista os arquivos e o hash de cada um.
- Cota do plano: um envio com vários arquivos conta **um** documento no plano; o armazenamento conta todos os bytes. Limite de arquivos por envio: `signing.max_documents` (10).
- DOCX: o Laravel manda ao selador `POST /v1/convert` (multipart `docx`), recebe PDF. Guarda **só o PDF**; o hash do DOCX original vai na trilha (`source_converted`). Validação por conteúdo (zip com `word/document.xml`), não pela extensão. Conversão com tempo limite de 60 s, perfil temporário, sem rede. Só `.docx` (não `.doc`, `.docm`).
- Erros de conversão viram aviso claro ("não conseguimos converter; salve como PDF").

## Tarefas
1. **Selador: `/v1/convert` + LibreOffice.** `sealer/convert.py` (subprocess `soffice --headless --convert-to pdf`, pasta temporária, timeout), rota em `app.py`, Dockerfile instala `libreoffice-writer` e fontes (`fonts-liberation`, `fonts-dejavu`) e copia `convert.py`; pytest com `soffice` simulado; teste de guarda do Dockerfile. `SealerClient::convert(string $docx): string` + `ConvertException`.
2. **Entrada de arquivos.** `App\Documents\DocumentIntake::toPdf(UploadedFile): array{pdf,name,source}`; passo 1 do assistente e "substituir arquivo" aceitam DOCX; mensagens amigáveis; testes (docx válido, falso com extensão docx, selador fora do ar).
3. **Modelo.** Migração `envelope_documents` e `signature_fields.document_id`; `Envelope::allDocuments()` (principal + extras, ordenados); `StorageUsage` e exclusão definitiva contam/apagam todos; testes.
4. **Assistente com vários arquivos.** Passo 1 com seleção múltipla e lista ordenável/removível; posicionamento de assinaturas com abas por documento; `EnvelopeService` grava campos por documento e valida que todo signatário tem campo em cada arquivo (ou em ao menos um, regra a decidir ao implementar e registrar).
5. **Tela do signatário.** Abas/lista de documentos no visualizador, uma assinatura; rota `/assinar/{token}/pdf/{n}`; campos exibidos por documento.
6. **Selagem em lote.** `SealEnvelope` sela cada arquivo (relatório por arquivo), grava `final_*` por arquivo, falha de um mantém o envio em `sealing` e tenta de novo só o que falta (idempotente por arquivo); evidência `document_sealed` por arquivo.
7. **Entrega.** `SignedCopyMail`/`ObserverCopyMail` anexam todos; tela final lista os arquivos com "Baixar" e "Baixar todos" (zip) e "Ver"; verificação pública lista os arquivos; API e webhook expõem `documents[]`.
8. **Revisão final e documentação** (`ESCOPO.md`, `DEPLOY.md`: o selador agora precisa de ~500 MB e de mais memória no Coolify).

## Riscos
- Fidelidade do DOCX → PDF varia com fontes e recursos avançados do Word; avisar no envio ("confira a prévia").
- Imagem do selador maior e primeiro deploy mais lento.
- Selagem parcial (um arquivo falha): tratada na tarefa 6.
