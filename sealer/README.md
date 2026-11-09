# Sealer — selagem PAdES

Microserviço chamado por `App\Signing\Support\SealerClient`. **Não foi executado ainda** (o ambiente de
desenvolvimento não tinha Python nem Docker): validar o fluxo ponta a ponta antes de usar.

## Rodar (dev)

```bash
docker build -t sealer ./sealer
docker run --rm -p 8080:8080 \
  -e SEALER_TOKEN=dev-token -e SEALER_ALLOW_TEST=true sealer
```

No `.env` do Laravel: `SEALER_URL=http://localhost:8080`, `SEALER_TOKEN=dev-token`,
`SIGNING_PLATFORM_PROVIDER=local_test`.

## Variáveis

| Variável | Uso |
|---|---|
| `SEALER_TOKEN` | Bearer exigido nas chamadas (obrigatório) |
| `SEALER_ALLOW_TEST` | `true` só em dev/staging: habilita certificado autoassinado |
| `SEALER_TSA_URL` | Carimbo do tempo RFC 3161 (ACT ICP-Brasil) |
| `SEALER_PFX_<REF>_PATH` / `_PASSWORD` | e-CNPJ A1 para `pkcs12_ref` (monte como secret, não na imagem) |

## Pendências

- `hsm_key` (PKCS#11/KMS) e `cloud_cert` (BirdID/VIDaaS...) retornam 501.
- PAdES-LT/LTA (`embed_validation_info`) exige `ValidationContext` com a cadeia ICP-Brasil.
- Rede: expor apenas na rede interna; nunca publicamente.
