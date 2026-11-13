# Escopo — Plataforma de Assinatura Eletrônica (Laravel + PostgreSQL)

Referências de mercado: ZapSign, Clicksign, D4Sign, Autentique, DocuSign.
Data: 2026-11-09 · Status: rascunho para validação.

> Aviso: este documento não é parecer jurídico. A estratégia de prova e os textos legais (termos, política de privacidade, relatório de assinaturas) devem ser revisados por advogado antes do lançamento.

---

## 1. Visão geral

SaaS multi-tenant em que empresas enviam documentos (PDF) para um ou mais signatários, que assinam por link (e-mail/WhatsApp/SMS) em qualquer dispositivo, sem instalar nada. Ao final, o sistema gera o PDF assinado + relatório de evidências (trilha de auditoria) verificável publicamente. Oferece também API REST + webhooks para integração.

## 2. Pesquisa de mercado (resumo)

| Plataforma | Destaques observados |
|---|---|
| **ZapSign** | Assinatura simples/avançada/qualificada; link único de assinatura; modelos e formulários; selfie/reconhecimento facial; SMS; integração Gov.br e certificados em nuvem (BirdID, RemoteID, SafeID, VidaaS); API REST + sandbox + webhooks; Zapier/Make/n8n; plano grátis (~5 docs/mês); planos de API por volume de documentos (Start/Grow/Scale/Enterprise); SSO e usuários customizados no Enterprise; carimbo do tempo. |
| **Clicksign** | Foco corporativo/jurídico, trial limitado, integrações e API. |
| **D4Sign** | Certificados em nuvem (BirdID, SafeID), cofre de documentos, modelos em planos pagos. |
| **Autentique** | Plano gratuito, foco em simplicidade e custo. |
| **DocuSign** | Referência global: envelope, ordem de roteamento, lembretes, *Certificate of Completion* (trilha de auditoria com IPs, horários, autenticação, PDF selado). |

Fontes: [docs.zapsign.com.br](https://docs.zapsign.com.br/), [preços ZapSign](https://zapsign.co/pricing), [comparativo ZapSign](https://blog.zapsign.com.br/en/melhor-plataforma-assinatura-eletronica/), [Lei 14.063/2020](https://planalto.gov.br/ccivil_03/_ato2019-2022/2020/Lei/L14063.htm), [Dizer o Direito](https://www.dizerodireito.com.br/2020/09/lei-140632020-dispoe-sobre-as.html), [Autentique – validade](https://intercom.help/autentiqueqg/pt-BR/articles/2973256-validade-das-assinaturas-eletronicas). Dados de preço/recursos de concorrentes vêm em boa parte de material do próprio setor — conferir nas páginas oficiais.

## 3. Base legal (Brasil)

- **MP 2.200-2/2001, art. 10 §1º:** assinatura com certificado ICP-Brasil presume-se verdadeira (qualificada). **§2º:** outras formas de assinatura eletrônica têm validade se as partes a aceitarem (ou se aceita por quem a opõe).
- **Lei 14.063/2020:** define três níveis — **simples**, **avançada** e **qualificada**. A lei regula principalmente relações com entes públicos, mas o enquadramento é usado como padrão de mercado.
- **CPC (arts. 369, 411, 412) e Código Civil (art. 107):** liberdade de forma e admissibilidade de prova eletrônica; o ônus de provar autoria/integridade recai sobre quem apresenta, por isso a **qualidade das evidências** é o coração do produto.
- **Exceções que exigem qualificada ou forma específica:** atos de Chefes de Poder, alguns registros imobiliários (ver Provimento 180 CNJ), escrituras públicas. Mostrar aviso no produto.
- **LGPD (Lei 13.709/2018):** dados pessoais, biometria facial (dado sensível), base legal, retenção, direitos do titular, DPO, RIPD.
- **Marco Civil (Lei 12.965/2014):** guarda de registros de acesso (IP + data/hora).

### Estratégia por nível de assinatura

| Nível | Implementação no produto | Evidências |
|---|---|---|
| Simples | Link único + e-mail/WhatsApp com token | IP, user agent, data/hora (UTC + NTP), geolocalização opcional, hash do documento |
| Avançada | Simples + 2º fator: OTP SMS/WhatsApp, selfie com prova de vida, foto do documento (OCR), validação Gov.br (nível prata/ouro) | Tudo acima + dados do fator, imagem/vetor biométrico (criptografado), CPF validado |
| Qualificada | Certificado ICP-Brasil: A1/A3 local, ou certificado em nuvem (BirdID, VIDaaS, SafeID, RemoteID) + assinatura **PAdES** | Cadeia de certificado, LTV, carimbo do tempo (ACT ICP-Brasil) |

## 4. Personas

1. **Remetente/Administrador** (empresa): cria envelopes, modelos, acompanha status.
2. **Signatário**: recebe link, autentica-se e assina (mobile-first).
3. **Verificador/terceiro**: confere autenticidade via código/QR sem login.
4. **Integrador/dev**: usa API e webhooks.
5. **Super admin da plataforma**: planos, cobrança, suporte, auditoria.

## 5. Escopo funcional

### 5.1 MVP (Fase 1)
- Cadastro/login de empresas (multi-tenant), usuários e papéis (owner, admin, membro), 2FA para usuários.
- Upload de PDF (e conversão DOCX→PDF opcional).
- Criação de envelope: 1..N documentos, 1..N signatários, papéis (assina, testemunha, aprovador, observador), **ordem de assinatura** (sequencial/paralela), prazo de validade, mensagem.
- Editor de posicionamento de campos (assinatura, rubrica, data, texto, CPF, checkbox) no PDF.
- Envio por e-mail e link compartilhável; lembretes automáticos.
- Página de assinatura (responsiva): visualização do PDF, desenho/digitação/upload da rubrica, aceite de termos, **assinatura simples** com captura de evidências.
- **Verificação por OTP** (e-mail e SMS/WhatsApp) = assinatura avançada básica.
- Geração do **PDF final** com carimbo visual + **relatório de assinaturas** (trilha de auditoria) anexado.
- **Selagem do PDF** com certificado da plataforma (PAdES-B-LT) + carimbo do tempo — garante integridade.
- Página pública de **verificação** (código/QR/upload do PDF → confere hash e mostra evidências).
- Painel: status (rascunho, enviado, em andamento, concluído, recusado, expirado, cancelado), busca, filtros, download.
- Recusa de assinatura com motivo, cancelamento, reenvio.
- Notificações e **webhooks** de eventos; **API REST** v1 + sandbox + chaves de API.
- Log de auditoria imutável (append-only, encadeado por hash).
- Conformidade LGPD básica: termos, consentimento, política, exportação/eliminação.

### 5.2 Fase 2
- Modelos de documentos reutilizáveis com variáveis (mail merge) e formulários públicos.
- Selfie com prova de vida + foto de documento + OCR; consulta CPF.
- Login/validação **Gov.br**.
- **Assinatura qualificada ICP-Brasil**: certificado A1 (upload, protegido) e certificados em nuvem via provedores (BirdID/VIDaaS/etc.).
- Assinatura em lote, pastas/etiquetas, equipes/departamentos, branding (logo, domínio de e-mail próprio).
- Planos e cobrança (Stripe/Asaas/Pagar.me), medição por documento, excedente.
- Integrações: Zapier/Make/n8n, Google Drive, WhatsApp Business API.
- Relatórios e exportações (CSV), painel de uso.

### 5.3 Fase 3
- SSO (SAML/OIDC), SCIM, papéis granulares.
- Cofre/arquivamento de longo prazo (LTV, re-carimbo periódico — PAdES-LTA).
- Assinatura presencial (tablet), anexos obrigatórios do signatário, campos condicionais.
- IA: extração de dados, resumo, detecção de campos.
- App mobile / SDK embed (iframe de assinatura embutida).
- Multi-idioma (pt-BR, en, es).

### Fora de escopo (por ora)
Notarização/cartório, escrituras públicas, assinatura de processos judiciais (PJe), blockchain (pode ser adicionado como ancoragem extra), gestão do ciclo de vida de contratos (CLM) completa.

## 6. Requisitos de evidência (validade jurídica)

Por assinatura, registrar:
- Identificação do signatário: nome, e-mail, telefone, CPF (quando exigido), fator de autenticação usado e resultado.
- Hash **SHA-256** do documento original e do final; versão do documento.
- Data/hora UTC de cada evento (relógio sincronizado por NTP) + carimbo do tempo confiável (RFC 3161) na conclusão.
- IP, porta quando disponível, user agent, dispositivo, geolocalização (com consentimento).
- Eventos: criado, enviado, e-mail entregue/aberto, link acessado, termos aceitos, OTP enviado/validado, documento visualizado, assinado, recusado.
- Prova de que o signatário viu o documento (visualização/rolagem).
- Termo de consentimento e política vigente (versão) no momento.
- Relatório PDF com QR de verificação, dentro do PDF final.
- Guarda por prazo definido (sugestão ≥ 5 anos, configurável; conferir com jurídico).

Garantias técnicas: documentos imutáveis após envio (nova versão = novo envelope), armazenamento criptografado, trilha encadeada por hash, backups.

## 7. Requisitos não funcionais

- **Segurança:** TLS 1.2+, criptografia em repouso (S3 SSE-KMS / campos sensíveis com cifra de aplicação), segredos em cofre, rate limiting, proteção anti-bot (Turnstile/hCaptcha), tokens de assinatura únicos, expiráveis e de alta entropia, CSP, OWASP ASVS nível 2, pentest antes do go-live.
- **Disponibilidade:** meta 99,9%; filas e retentativas; idempotência em webhooks/API.
- **Desempenho:** abrir página de assinatura < 2 s em 4G; PDFs até 50 MB / 200 páginas.
- **Escalabilidade:** stateless app, workers horizontais, armazenamento de objetos.
- **Observabilidade:** logs estruturados, métricas, alertas (Sentry, Prometheus/Grafana ou similar).
- **Acessibilidade:** WCAG 2.1 AA na página de assinatura.
- **Privacidade:** minimização, RIPD, DPO, retenção e anonimização, registro de operações.

## 8. Arquitetura proposta

**Stack:** Laravel 12 (PHP 8.3+), PostgreSQL 16+, Redis (cache/filas/rate limit), Laravel Horizon, S3 compatível (AWS S3/Cloudflare R2/MinIO) para arquivos, Meilisearch ou `pg_trgm`/FTS do Postgres para busca.

**Frontend:** Inertia + Vue 3 (ou Livewire) para painel; página de assinatura leve e mobile-first; visualizador/editor de PDF com **PDF.js**.

**Processamento de PDF:** serviço dedicado (Java com **PDFBox/DSS** da UE ou **pyHanko** em Python, ou **iText** — licença AGPL/comercial) chamado pelo Laravel via fila/HTTP interno, para PAdES, carimbo do tempo e validação. FPDI/TCPDF/Dompdf para carimbos visuais e relatório.

**Chaves e certificados:** certificado da plataforma em **HSM/KMS** (AWS CloudHSM/KMS ou provedor ICP-Brasil), nunca em disco aberto.

**Módulos (Laravel):** Auth/Tenancy · Documents · Envelopes · Signers · Fields · SigningFlow · Evidence/Audit · Verification · Notifications (mail/SMS/WhatsApp) · Billing · API/Webhooks · Templates · Integrations · Admin.

**Multi-tenancy:** coluna `tenant_id` + Row Level Security do PostgreSQL (defesa em profundidade) ou `stancl/tenancy`. Recomendado: single DB com RLS no MVP.

**Filas:** envio de notificações, geração/selagem de PDF, webhooks, lembretes (scheduler).

## 9. Modelo de dados (PostgreSQL) — visão inicial

- `tenants`, `users`, `memberships`, `api_keys`
- `documents` (arquivo, hash_sha256, storage_key, versão)
- `envelopes` (status, expira_em, ordem, mensagem, nível_exigido)
- `envelope_documents`
- `signers` (nome, email, telefone, cpf, papel, ordem, token_hash, status)
- `fields` (tipo, página, x, y, w, h, signer_id, obrigatório, valor)
- `signatures` (signer_id, tipo, imagem, hash, assinado_em, nível)
- `auth_factors` (otp, selfie, govbr, certificado) com resultado e metadados
- `evidence_events` (envelope_id, signer_id, tipo, payload jsonb, ip inet, user_agent, ocorrido_em, hash_anterior, hash) — **append-only**
- `timestamps_tokens` (RFC 3161, bytea)
- `final_artifacts` (pdf assinado, relatório, hashes)
- `templates`, `template_fields`
- `webhooks`, `webhook_deliveries`
- `plans`, `subscriptions`, `usage_records`, `invoices`
- `consents` (versão dos termos, aceite, data)
- `data_requests` (LGPD)

Boas práticas: UUID v7 como PK, `timestamptz`, `inet` para IP, `jsonb` p/ payloads, triggers/permissões impedindo UPDATE/DELETE em `evidence_events`, particionamento por data quando crescer.

## 10. API REST (v1) — endpoints principais

`POST /v1/envelopes` · `GET /v1/envelopes/{id}` · `POST /v1/envelopes/{id}/send` · `POST /v1/envelopes/{id}/cancel` · `GET /v1/envelopes/{id}/download` · `GET /v1/envelopes/{id}/evidence` · `POST /v1/templates/{id}/envelopes` · `GET /v1/verify/{codigo}` · webhooks (`envelope.sent`, `signer.viewed`, `signer.signed`, `signer.refused`, `envelope.completed`, `envelope.expired`) com assinatura HMAC, retentativas e idempotência. Autenticação por Bearer token; sandbox separado.

## 10.1 Certificados e selagem (modelo híbrido)

**Quem tem qual certificado**

| Item | Responsável |
|---|---|
| Certificado que sela os PDFs (padrão) | Plataforma — um e-CNPJ ICP-Brasil para todos os tenants |
| Carimbo do tempo (ACT) | Plataforma — custo repassado por documento/plano |
| Certificado do signatário (assinatura qualificada) | O próprio signatário, via certificado em nuvem |
| Certificado próprio do cliente nos PDFs dele | Opcional (plano Enterprise) |

**Abstração obrigatória desde o MVP:** interface `SigningProvider` com implementações intercambiáveis:
- `LocalTestProvider`: certificado autoassinado, **somente dev/staging** (sem validade ICP-Brasil; bloqueado em produção).
- `PlatformA1Provider`: e-CNPJ A1 da plataforma, chave fora do repositório (cofre/KMS).
- `PlatformHsmProvider`: chave em HSM/nuvem (alvo de produção).
- `TenantCloudCertProvider`: certificado em nuvem do cliente, autorizado por API do provedor.

A escolha é feita por tenant (`tenant_signing_settings`): provedor, referência da credencial (nunca a chave), política de *fallback* (`bloquear` | `usar_plataforma`), data de validade do certificado e alertas de vencimento (30/15/7 dias).

**Regras:**
- Certificado só é necessário para o go-live comercial; o desenvolvimento pode seguir com o `LocalTestProvider`.
- Documento selado fica gravado com o provedor/certificado usado (serial, emissor, validade) em `final_artifacts`.
- Se o certificado do tenant falhar ou vencer: o envelope fica pendente de selagem e o admin é notificado; *fallback* para o certificado da plataforma só se o tenant autorizou.
- Troca ou renovação de certificado não invalida PDFs já selados (requer PAdES-LT/LTA com cadeia e carimbo gravados).
- Credenciais de integração com provedores de nuvem ficam criptografadas, com trilha de auditoria de uso.

**Planos sugeridos:** Básico/Pro usam o certificado da plataforma; Enterprise permite certificado próprio, SSO e SLA.

## 11. Riscos e decisões em aberto

1. **Qualificada ICP-Brasil:** exige parceria com AC/provedores de certificado em nuvem e/ou integração PAdES — maior esforço; deixar para Fase 2.
2. **Certificado da plataforma:** ICP-Brasil (A1/A3 em HSM) vs. certificado próprio — afeta aceitação em validadores (ITI/verificador).
3. **Biometria facial:** dado sensível LGPD; avaliar build vs. buy (Unico, idwall, Serpro Datavalid).
4. **Custo de SMS/WhatsApp/KYC:** repassar no plano ou por consumo.
5. **Jurídico:** revisar termos, relatório de evidências, retenção e limites de uso (ex.: contratos imobiliários).
6. **Licença do iText/PDF lib:** decidir entre DSS/PDFBox (open source) e alternativas.
7. **Modelo de negócio:** SaaS por documentos (como ZapSign) vs. por usuário.

## 12. Roadmap sugerido

| Fase | Duração estimada* | Entregas |
|---|---|---|
| 0 — Discovery/Jurídico | 2–3 sem | Validação legal, arquitetura, protótipos, escolha de libs |
| 1 — MVP | 10–14 sem | Seção 5.1 completa, API básica, selagem PAdES-LT + timestamp, verificação pública |
| 2 — Crescimento | 10–12 sem | Modelos, selfie/Gov.br, qualificada ICP, cobrança, integrações |
| 3 — Escala | contínuo | SSO, LTA, embed, IA, app |

\*Para equipe de ~3–4 devs; ajustar conforme time.

## 13. Critérios de aceite do MVP

- Fluxo ponta a ponta: criar → enviar → autenticar (OTP) → assinar → concluir → baixar PDF selado + relatório.
- PDF final valida em verificadores de PAdES e a página pública confere o hash.
- Alteração de 1 byte no PDF invalida a verificação.
- `evidence_events` não permite alteração; cadeia de hash íntegra.
- Webhook entregue com HMAC e retentativas.
- Testes automatizados (Pest/PHPUnit) para fluxo de assinatura, expiração, recusa e ordem de assinatura.
- Checklist LGPD e revisão jurídica concluídos.

## 14. Backlog priorizado (decidido em 09/11/2026)

**Fase 1 do menu entregue em 14/11/2026** (Roboto local, menu ZapSign com Documentos e Configurações, Minhas assinaturas, lixeira de 30 dias e arquivo, Central de ajuda configurável). Próximas: fase 2 (Contatos, Relatórios, Calendário, Usuários), fase 3 (planos e limites), fase 4 (cobrança), fase 5 (integração com a hospedagem).

**Entregue em 09/11/2026** (itens 2 e 4 da lista de ideias; ainda sem teste em produção): lembretes e expiração, webhooks, WhatsApp/SMS, modelos com variáveis,
posição da assinatura no PDF, signatários em ordem, pastas/etiquetas/busca, painel de saúde, Sentry, backup, limite de PDF,
teste de carga, repositório privado.

**Guardado para depois**

1. *Obrigatório antes de vender (jurídico e confiança)*
   - Certificado ICP-Brasil e-CNPJ A1 (hoje o selo é de teste e aparece como "não confiável").
   - Carimbo de tempo (TSA): configurar `SEALER_TSA_URL`.
   - Termos de uso, política de privacidade, consentimento e retenção/exclusão de biometria (LGPD); revisão por advogado.
   - Recuperação de senha e verificação de e-mail.
   - Documentos em S3 (ou similar) em vez do volume do servidor.
2. *Monetização e produto* — **detalhado em `docs/superpowers/specs/2026-11-13-menu-modulos-planos-design.md`** (menu ZapSign, contatos, relatórios, calendário, planos e cobrança com Mercado Pago e InfinitePay, integração com a hospedagem)
   - Planos e cobrança recorrente (Asaas, Stripe ou Mercado Pago), bloqueio por inadimplência.
   - Marca própria (white label) e domínio personalizado por empresa.
   - Papéis de usuário (admin, operador, leitor) dentro de cada empresa, com log de auditoria.

**Ainda pendente dentro dos itens entregues**

- Teste de carga: o script k6 existe (`loadtest/`), mas **não foi executado**; precisa de um ambiente de teste.
- Repositório privado: exige configurar a chave de deploy no Coolify antes de trocar a visibilidade (passo a passo em `docs/DEPLOY.md`).
- Backup fora do servidor (S3) e teste de restauração.
- WhatsApp/SMS: só Twilio implementado, sem teste com conta real; o código de verificação (OTP) continua só por e-mail.
- Posição da assinatura: não está disponível para documentos gerados por modelo; assinatura posicionada só é desenhada em PDFs que o sealer consegue decorar.
- Webhooks: sem sandbox separado e sem reenvio em lote.

## 15. API v1 — estado em 11/11/2026

Referência interativa em `/api/docs` e especificação em `/api/v1/openapi.json` (um teste garante que toda rota está documentada).

- **Conta:** `GET /me` (confere a chave, mostra consumo e eventos de webhook).
- **Envelopes:** listar com filtros e paginação, criar (multipart ou JSON com PDF em base64), consultar, enviar, cancelar, selar de novo, evidências, baixar selado e original. `{id}` aceita também o `external_id` do cliente; `metadata` livre volta nas respostas e nos webhooks.
- **Signatários:** reenviar aviso e gerar novo link.
- **Modelos:** CRUD e geração de envelope com variáveis.
- **Webhooks:** CRUD, ativar/desativar, teste (`ping`) e histórico de entregas.
- **Pastas e etiquetas:** listar, criar e remover.
- **Verificação pública:** `GET /verify/{codigo}` sem chave, com conferência opcional de hash.
- **Segurança e robustez:** chave somente leitura; `Idempotency-Key` (24 h); erros em formato único; 120 req/min por chave.

**Fora da API por enquanto:** criar/revogar chaves, usuários da conta, provas biométricas (selfie e foto do documento, de propósito), cobrança, sandbox separado, paginação por cursor.
