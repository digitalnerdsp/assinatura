// Teste de carga das páginas públicas e da API (k6: https://k6.io).
//
//   k6 run -e BASE_URL=https://staging.exemplo.com.br -e API_KEY=sk_live_xxx -e SIGN_URL=https://staging.../assinar/TOKEN loadtest/k6-public.js
//
// NÃO rode contra produção. Use um ambiente de teste com SIGNING_ALLOW_LOCAL_TEST=true e uma chave de API de teste.
import http from 'k6/http';
import { check, sleep } from 'k6';

const BASE = __ENV.BASE_URL;
const KEY = __ENV.API_KEY;
const SIGN_URL = __ENV.SIGN_URL;   // link de assinatura de um envelope de teste (só leitura: GET)

export const options = {
  scenarios: {
    publico: { executor: 'ramping-vus', exec: 'publico', startVUs: 0, stages: [
      { duration: '1m', target: 30 }, { duration: '3m', target: 30 }, { duration: '30s', target: 0 } ] },
    api: { executor: 'constant-arrival-rate', exec: 'api', rate: 1, timeUnit: '1s', duration: '4m', preAllocatedVUs: 10 },
  },
  thresholds: {
    http_req_failed: ['rate<0.01'],                       // menos de 1% de erros
    'http_req_duration{scenario:publico}': ['p(95)<800'], // páginas públicas
    'http_req_duration{scenario:api}': ['p(95)<1500'],    // criação de envelope (grava PDF + banco)
  },
};

export function publico() {
  const r = http.get(`${BASE}/verificar`);
  check(r, { 'verificar 200': (x) => x.status === 200 });
  if (SIGN_URL) {
    const s = http.get(SIGN_URL);
    check(s, { 'assinar 200': (x) => x.status === 200 });
  }
  sleep(1);
}

// PDF mínimo válido para a criação (o app só confere o cabeçalho %PDF-).
const PDF = '%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 200 200]>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n';

export function api() {
  const r = http.post(`${BASE}/api/v1/envelopes`, {
    title: `Carga ${__VU}-${__ITER}`,
    document: http.file(PDF, 'carga.pdf', 'application/pdf'),
    'signers[0][name]': 'Teste', 'signers[0][email]': `carga+${__VU}.${__ITER}@example.com`,
  }, { headers: { Authorization: `Bearer ${KEY}` } });
  check(r, { 'criou 201': (x) => x.status === 201 });
}
