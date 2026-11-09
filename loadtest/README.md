# Teste de carga

`k6-public.js` simula 30 visitantes simultâneos nas páginas públicas (verificação e assinatura) e 1 criação de envelope
por segundo pela API, durante cerca de 4 minutos. Limites de aceite no próprio script: menos de 1% de erros, p95 abaixo de
800 ms nas páginas públicas e abaixo de 1,5 s na API.

1. Suba um ambiente **de teste** (não produção) com `SIGNING_ALLOW_LOCAL_TEST=true`.
2. Crie uma chave de API de teste e um envelope enviado; copie o link de assinatura de um signatário.
3. Rode:

```bash
k6 run -e BASE_URL=https://staging.exemplo.com.br -e API_KEY=sk_live_xxx -e SIGN_URL=https://staging.exemplo.com.br/assinar/TOKEN loadtest/k6-public.js
```

Os envelopes criados ficam como rascunho; apague-os depois (ou use um tenant só para teste).

**Atenção:** a API tem o limite de 120 requisições por minuto por chave (`throttle:120,1`); o cenário usa 60/min para ficar abaixo dele,
então aumentar `rate` acima de 2 por segundo passará a gerar respostas 429. As páginas públicas têm limites por IP
(`throttle:60,1` na assinatura): como o k6 sai de um único IP, ajuste o número de usuários virtuais ou rode de vários
endereços para não medir só o limitador.

Use também o painel *Plataforma → Saúde do sistema* durante o teste para ver a fila, as falhas e o espaço em disco.
