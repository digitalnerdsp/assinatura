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


# ---- Certificado de produção (e-CNPJ A1 em .pfx) -------------------------------------------------

def _make_pfx(password: bytes) -> bytes:
    """.pfx de teste no formato do e-CNPJ A1: folha emitida por uma AC 'ICP-Brasil', cadeia embutida."""
    from datetime import datetime, timedelta, timezone

    from cryptography import x509
    from cryptography.hazmat.primitives import hashes, serialization
    from cryptography.hazmat.primitives.asymmetric import rsa
    from cryptography.hazmat.primitives.serialization import pkcs12
    from cryptography.x509.oid import NameOID

    now = datetime.now(timezone.utc)
    ca_key = rsa.generate_private_key(public_exponent=65537, key_size=2048)
    ca_name = x509.Name([
        x509.NameAttribute(NameOID.ORGANIZATION_NAME, "ICP-Brasil"),
        x509.NameAttribute(NameOID.COMMON_NAME, "AC Teste v1"),
    ])
    ca = (
        x509.CertificateBuilder().subject_name(ca_name).issuer_name(ca_name).public_key(ca_key.public_key())
        .serial_number(x509.random_serial_number()).not_valid_before(now - timedelta(days=30)).not_valid_after(now + timedelta(days=3650))
        .add_extension(x509.BasicConstraints(ca=True, path_length=None), critical=True)
        .sign(ca_key, hashes.SHA256())
    )
    key = rsa.generate_private_key(public_exponent=65537, key_size=2048)
    leaf = (
        x509.CertificateBuilder()
        .subject_name(x509.Name([x509.NameAttribute(NameOID.COMMON_NAME, "EMPRESA TESTE LTDA:12345678000199")]))
        .issuer_name(ca_name).public_key(key.public_key())
        .serial_number(x509.random_serial_number()).not_valid_before(now - timedelta(days=1)).not_valid_after(now + timedelta(days=365))
        .add_extension(x509.KeyUsage(
            digital_signature=True, content_commitment=True, key_encipherment=False, data_encipherment=False,
            key_agreement=False, key_cert_sign=False, crl_sign=False, encipher_only=False, decipher_only=False,
        ), critical=True)
        .sign(ca_key, hashes.SHA256())
    )
    return pkcs12.serialize_key_and_certificates(
        b"ecnpj", key, leaf, [ca], serialization.BestAvailableEncryption(password),
    )


PFX_CRED = {"credential": {"type": "pkcs12_ref", "secret_ref": "empresa"}}


def test_pkcs12_from_file_path(client, monkeypatch, tmp_path):
    path = tmp_path / "ecnpj.pfx"
    path.write_bytes(_make_pfx(b"senha-teste"))
    monkeypatch.setenv("SEALER_PFX_EMPRESA_PATH", str(path))
    monkeypatch.setenv("SEALER_PFX_EMPRESA_PASSWORD", "senha-teste")

    info = client.post("/v1/certificate", json=PFX_CRED, headers=H).json()

    assert info["icp_brasil"] is True
    assert "EMPRESA TESTE LTDA" in info["subject"]


def test_pkcs12_from_base64_env_and_file_is_private(client, monkeypatch):
    monkeypatch.delenv("SEALER_PFX_EMPRESA_PATH", raising=False)
    monkeypatch.setenv("SEALER_PFX_EMPRESA_B64", base64.b64encode(_make_pfx(b"senha-teste")).decode())
    monkeypatch.setenv("SEALER_PFX_EMPRESA_PASSWORD", "senha-teste")

    info = client.post("/v1/certificate", json=PFX_CRED, headers=H).json()

    assert info["icp_brasil"] is True
    written = sealer._materialized_pfx("EMPRESA")
    assert os.path.isfile(written)
    if os.name == "posix":
        assert (os.stat(written).st_mode & 0o077) == 0  # só o dono lê


def test_pkcs12_wrong_password_has_readable_error(client, monkeypatch):
    monkeypatch.delenv("SEALER_PFX_EMPRESA_PATH", raising=False)
    monkeypatch.setenv("SEALER_PFX_EMPRESA_B64", base64.b64encode(_make_pfx(b"senha-teste")).decode())
    monkeypatch.setenv("SEALER_PFX_EMPRESA_PASSWORD", "errada")

    r = client.post("/v1/certificate", json=PFX_CRED, headers=H)

    assert r.status_code == 500
    assert "senha" in r.json()["detail"].lower()
    assert "errada" not in r.text and "senha-teste" not in r.text  # a senha nunca vai na resposta


def test_pkcs12_missing_credential_is_clear(client, monkeypatch):
    for v in ("SEALER_PFX_EMPRESA_PATH", "SEALER_PFX_EMPRESA_B64"):
        monkeypatch.delenv(v, raising=False)

    r = client.post("/v1/certificate", json=PFX_CRED, headers=H)

    assert r.status_code == 500 and "SEALER_PFX_EMPRESA" in r.json()["detail"]


def test_seal_with_pkcs12_embeds_the_chain(client, monkeypatch):
    monkeypatch.delenv("SEALER_PFX_EMPRESA_PATH", raising=False)
    monkeypatch.setenv("SEALER_PFX_EMPRESA_B64", base64.b64encode(_make_pfx(b"senha-teste")).decode())
    monkeypatch.setenv("SEALER_PFX_EMPRESA_PASSWORD", "senha-teste")

    r = client.post(
        "/v1/seal", headers=H, files={"pdf": ("a.pdf", plain_pdf(), "application/pdf")},
        data={"metadata": json.dumps({**PFX_CRED, "timestamp": False})},
    )

    assert r.status_code == 200, r.text
    assert r.json()["certificate"]["icp_brasil"] is True
    sig = PdfFileReader(io.BytesIO(base64.b64decode(r.json()["pdf_base64"]))).embedded_signatures[0]
    assert len(sig.other_embedded_certs) >= 1  # a AC intermediária vai dentro da assinatura (validação no Adobe)
