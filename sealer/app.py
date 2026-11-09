"""Serviço de selagem PAdES (pyHanko). Contrato descrito em app/Signing/Support/SealerClient.php.

POST /v1/seal         multipart: pdf (arquivo) + metadata (JSON)
POST /v1/certificate  JSON: {"credential": {...}}
GET  /health
"""
from __future__ import annotations

import base64
import hmac
import io
import json
import os
from datetime import datetime, timedelta, timezone

from asn1crypto import keys as asn1_keys
from asn1crypto import x509 as asn1_x509
from cryptography import x509
from cryptography.hazmat.primitives import hashes, serialization
from cryptography.hazmat.primitives.asymmetric import rsa
from cryptography.x509.oid import NameOID
from pyhanko_certvalidator.registry import SimpleCertificateStore
from fastapi import Depends, FastAPI, File, Form, Header, HTTPException, Request, UploadFile
from fastapi.responses import JSONResponse
from pyhanko.pdf_utils.incremental_writer import IncrementalPdfFileWriter
from pyhanko.sign import signers, timestamps
from pyhanko.sign.fields import SigSeedSubFilter
from pyhanko.sign.signers.pdf_signer import PdfSignatureMetadata, PdfSigner
from pydantic import BaseModel

TOKEN = os.environ.get("SEALER_TOKEN", "")
TSA_URL = os.environ.get("SEALER_TSA_URL", "")  # ACT ICP-Brasil / RFC 3161
ALLOW_TEST = os.environ.get("SEALER_ALLOW_TEST", "false").lower() == "true"

app = FastAPI(title="sealer")


def auth(authorization: str = Header(default="")) -> None:
    expected = f"Bearer {TOKEN}"
    if not TOKEN or not hmac.compare_digest(authorization, expected):
        raise HTTPException(status_code=401, detail="unauthorized")


_TEST_SIGNER: signers.SimpleSigner | None = None


def _self_signed() -> signers.SimpleSigner:
    """Certificado autoassinado de teste (gerado uma vez por processo). SEM validade ICP-Brasil."""
    global _TEST_SIGNER
    if not ALLOW_TEST:
        raise HTTPException(
            status_code=403,
            detail="certificado de teste desabilitado: defina SIGNING_ALLOW_LOCAL_TEST=true (apenas em testes)",
        )
    if _TEST_SIGNER is None:
        _TEST_SIGNER = _build_self_signed()
    return _TEST_SIGNER


def _build_self_signed() -> signers.SimpleSigner:
    key = rsa.generate_private_key(public_exponent=65537, key_size=2048)
    name = x509.Name([x509.NameAttribute(NameOID.COMMON_NAME, "Selo de Teste (sem validade juridica)")])
    now = datetime.now(timezone.utc)
    cert = (
        x509.CertificateBuilder()
        .subject_name(name).issuer_name(name).public_key(key.public_key())
        .serial_number(x509.random_serial_number())
        .not_valid_before(now - timedelta(days=1)).not_valid_after(now + timedelta(days=365))
        .sign(key, hashes.SHA256())
    )
    # Monta o SimpleSigner direto (a versão do pyHanko do container não tem load_pkcs12_data).
    signing_cert = asn1_x509.Certificate.load(cert.public_bytes(serialization.Encoding.DER))
    signing_key = asn1_keys.PrivateKeyInfo.load(
        key.private_bytes(
            serialization.Encoding.DER,
            serialization.PrivateFormat.PKCS8,
            serialization.NoEncryption(),
        )
    )
    return signers.SimpleSigner(
        signing_cert=signing_cert,
        signing_key=signing_key,
        cert_registry=SimpleCertificateStore(),
    )


def _pkcs12_from_ref(secret_ref: str) -> signers.SimpleSigner:
    """secret_ref -> variáveis SEALER_PFX_<REF>_PATH e SEALER_PFX_<REF>_PASSWORD (ou montagem de secret)."""
    ref = "".join(c for c in secret_ref.upper() if c.isalnum() or c == "_")
    path = os.environ.get(f"SEALER_PFX_{ref}_PATH")
    password = os.environ.get(f"SEALER_PFX_{ref}_PASSWORD", "")
    if not path or not os.path.isfile(path):
        raise HTTPException(status_code=500, detail="credential not configured")
    return signers.SimpleSigner.load_pkcs12(pfx_file=path, passphrase=password.encode() or None)


def load_signer(credential: dict) -> signers.SimpleSigner:
    kind = credential.get("type")
    if kind == "self_signed_test":
        return _self_signed()
    if kind == "pkcs12_ref":
        return _pkcs12_from_ref(str(credential.get("secret_ref", "")))
    # hsm_key e cloud_cert: integrar PKCS#11 / API do provedor (fase 2).
    raise HTTPException(status_code=501, detail=f"credential type not implemented: {kind}")


def cert_info(signer: signers.SimpleSigner) -> dict:
    cert: asn1_x509.Certificate = signer.signing_cert
    issuer = cert.issuer.human_friendly
    return {
        "serial": format(cert.serial_number, "x"),
        "issuer": issuer,
        "subject": cert.subject.human_friendly,
        "valid_from": cert["tbs_certificate"]["validity"]["not_before"].native.isoformat(),
        "valid_to": cert["tbs_certificate"]["validity"]["not_after"].native.isoformat(),
        "icp_brasil": "ICP-Brasil" in issuer or "ICP-Brasil" in cert.subject.human_friendly,
    }


class CertRequest(BaseModel):
    credential: dict


@app.exception_handler(Exception)
async def unhandled(_request: Request, exc: Exception) -> JSONResponse:
    """Devolve o motivo real do erro (o Laravel mostra na trilha de auditoria e no painel admin)."""
    import logging

    logging.getLogger("sealer").exception("erro não tratado")
    return JSONResponse(status_code=500, content={"detail": f"{type(exc).__name__}: {exc}"})


@app.get("/health")
def health() -> dict:
    return {"status": "ok"}


@app.post("/v1/certificate", dependencies=[Depends(auth)])
def certificate(req: CertRequest) -> dict:
    return cert_info(load_signer(req.credential))


@app.post("/v1/seal", dependencies=[Depends(auth)])
def seal(pdf: UploadFile = File(...), metadata: str = Form(...)) -> dict:
    # "def" (não "async def"): o FastAPI roda em thread própria, e o pyHanko usa asyncio.run()
    # por dentro, que não pode ser chamado de dentro de um laço de eventos já em execução.
    meta = json.loads(metadata)
    signer = load_signer(meta["credential"])
    info = cert_info(signer)

    valid_to = datetime.fromisoformat(info["valid_to"])
    if valid_to < datetime.now(timezone.utc):
        raise HTTPException(status_code=409, detail="certificate expired")

    timestamper = None
    if meta.get("timestamp", True) and TSA_URL:
        timestamper = timestamps.HTTPTimeStamper(TSA_URL)

    # PAdES. embed_validation_info (PAdES-LT) exige um ValidationContext com a cadeia ICP-Brasil
    # e acesso a OCSP/CRL; habilitar quando o certificado de produção estiver disponível.
    signature_meta = PdfSignatureMetadata(
        field_name="SeloPlataforma",
        reason=meta.get("reason", "Documento selado"),
        location=meta.get("location", "Brasil"),
        subfilter=SigSeedSubFilter.PADES,
        md_algorithm="sha256",
    )

    data = pdf.file.read()
    writer = IncrementalPdfFileWriter(io.BytesIO(data))
    out = io.BytesIO()
    PdfSigner(signature_meta, signer=signer, timestamper=timestamper).sign_pdf(writer, output=out)

    return {
        "pdf_base64": base64.b64encode(out.getvalue()).decode(),
        "sealed_at": datetime.now(timezone.utc).isoformat(),
        "timestamped": timestamper is not None,
        "certificate": info,
    }
