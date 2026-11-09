"""Carimbo visível + relatório de assinaturas (rodar com: pytest sealer/tests)."""
import base64
import io
import json
import os
import sys

os.environ.setdefault("SEALER_TOKEN", "t")
os.environ["SEALER_ALLOW_TEST"] = "true"
sys.path.insert(0, os.path.join(os.path.dirname(__file__), ".."))
sys.path.insert(0, os.path.dirname(__file__))

import pytest
from fastapi.testclient import TestClient
from pypdf import PdfReader
from pyhanko.pdf_utils.reader import PdfFileReader
from pyhanko.sign.validation import validate_pdf_signature
from pyhanko_certvalidator import ValidationContext
from reportlab.lib.pagesizes import A4, A5, landscape
from reportlab.pdfgen import canvas

import app as sealer
from pdfs import hybrid_pdf, plain_pdf

H = {"Authorization": "Bearer t"}
REPORT = {
    "code": "ABCDEF123456", "url": "https://exemplo.com.br/verificar/ABCDEF123456", "tenant": "ACME Ltda", "title": "Contrato",
    "document_name": "contrato.pdf", "document_sha256": "a" * 64, "chain_hash": "b" * 64, "generated_at": "09/11/2026 14:00:00 (BRT)",
    "signers": [{"name": "Ana Souza", "role": "signatário", "email": "ana@ex.com", "cpf": "***.456.789-**", "status": "Assinado",
                 "signed_at": "09/11/2026 13:48:56", "signature": "Desenhada - nível avançado", "ip": "203.0.113.9",
                 "device": "Mozilla/5.0", "checks": ["Selfie (câmera)"], "geo": "-23.496, -47.430 (±13.9 m)"}],
    "events": [{"seq": i, "label": "Evento " + str(i), "at": "09/11/2026 13:40:00", "who": "Ana Souza", "ip": "203.0.113.9"} for i in range(1, 40)],
}


def pdf_pages(sizes):
    buf = io.BytesIO()
    c = canvas.Canvas(buf)
    for i, size in enumerate(sizes, start=1):
        c.setPageSize(size)
        c.drawString(40, size[1] - 60, f"Pagina original {i}")
        c.showPage()
    c.save()
    return buf.getvalue()


@pytest.fixture()
def client():
    return TestClient(sealer.app, raise_server_exceptions=False)


def seal(client, pdf, report=REPORT):
    meta = {"credential": {"type": "self_signed_test"}, "timestamp": False}
    if report is not None:
        meta["report"] = report
    return client.post("/v1/seal", headers=H, files={"pdf": ("a.pdf", pdf, "application/pdf")}, data={"metadata": json.dumps(meta)})


def test_footer_on_every_page_and_report_appended(client):
    r = seal(client, pdf_pages([A4, A4, landscape(A5)]))
    assert r.status_code == 200 and r.json()["decorated"] is True
    reader = PdfReader(io.BytesIO(base64.b64decode(r.json()["pdf_base64"])))

    assert len(reader.pages) > 3  # originais + relatório
    for page in reader.pages[:3]:
        assert "ABCDEF123456" in page.extract_text()
    report_text = "".join(p.extract_text() for p in reader.pages[3:])
    assert "Relatório de assinaturas" in report_text and "Ana Souza" in report_text and "ACME Ltda" in report_text
    assert "***.456.789-**" in report_text  # CPF mascarado chega como recebido


def test_decorated_pdf_still_has_valid_visible_signature(client):
    out = base64.b64decode(seal(client, pdf_pages([A4])).json()["pdf_base64"])
    sig = PdfFileReader(io.BytesIO(out)).embedded_signatures[0]
    assert validate_pdf_signature(sig, ValidationContext(trust_roots=[sig.signer_cert])).intact

    last = PdfReader(io.BytesIO(out)).pages[-1]
    widgets = [a.get_object() for a in last.get("/Annots", [])]
    assert any(w.get("/FT") == "/Sig" or w.get("/Subtype") == "/Widget" for w in widgets)  # selo visível


def test_hybrid_xref_pdf_is_decorated_and_sealed(client):
    r = seal(client, hybrid_pdf())
    assert r.status_code == 200 and r.json()["decorated"] is True


def test_without_report_seals_invisibly(client):
    r = seal(client, plain_pdf(), report=None)
    assert r.status_code == 200 and r.json()["decorated"] is False


def test_unreadable_pdf_does_not_break_when_report_requested(client):
    r = seal(client, b"isto nao e pdf")
    assert r.status_code == 500 and "detail" in r.json()
