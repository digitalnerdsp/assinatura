"""Testes do serviço de selagem (rodar com: pytest sealer/tests). Requer requirements-dev.txt."""
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
from pyhanko.pdf_utils.reader import PdfFileReader
from pyhanko.sign.validation import validate_pdf_signature
from pyhanko_certvalidator import ValidationContext

import app as sealer
from pdfs import hybrid_pdf, plain_pdf

H = {"Authorization": "Bearer t"}
CRED = {"credential": {"type": "self_signed_test"}}


@pytest.fixture()
def client():
    return TestClient(sealer.app, raise_server_exceptions=False)


def seal(client, pdf: bytes):
    return client.post(
        "/v1/seal", headers=H, files={"pdf": ("a.pdf", pdf, "application/pdf")},
        data={"metadata": json.dumps({**CRED, "timestamp": False})},
    )


def test_health_and_auth(client):
    assert client.get("/health").json() == {"status": "ok"}
    assert client.post("/v1/certificate", json=CRED).status_code == 401


def test_certificate_is_stable_and_not_icp(client):
    a = client.post("/v1/certificate", json=CRED, headers=H).json()
    b = client.post("/v1/certificate", json=CRED, headers=H).json()
    assert a["serial"] == b["serial"] and a["icp_brasil"] is False


@pytest.mark.parametrize("make", [plain_pdf, hybrid_pdf], ids=["plain", "hybrid-xref"])
def test_seal_produces_one_valid_signature(client, make):
    r = seal(client, make())
    assert r.status_code == 200, r.text
    out = base64.b64decode(r.json()["pdf_base64"])
    sigs = PdfFileReader(io.BytesIO(out)).embedded_signatures
    assert len(sigs) == 1
    status = validate_pdf_signature(sigs[0], ValidationContext(trust_roots=[sigs[0].signer_cert]))
    assert status.intact and status.valid


def test_plain_pdf_keeps_original_bytes(client):
    pdf = plain_pdf()
    out = base64.b64decode(seal(client, pdf).json()["pdf_base64"])
    assert out.startswith(pdf)


def test_tampering_is_detected(client):
    out = bytearray(base64.b64decode(seal(client, plain_pdf()).json()["pdf_base64"]))
    out[20] ^= 0x01
    sig = PdfFileReader(io.BytesIO(bytes(out))).embedded_signatures[0]
    status = validate_pdf_signature(sig, ValidationContext(trust_roots=[sig.signer_cert]))
    assert not status.intact


def test_error_detail_is_readable(client):
    r = client.post("/v1/seal", headers=H, files={"pdf": ("a.pdf", b"isto nao e pdf", "application/pdf")},
                    data={"metadata": json.dumps({**CRED, "timestamp": False})})
    assert r.status_code == 500 and "detail" in r.json()
