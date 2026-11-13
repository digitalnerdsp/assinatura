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
import logging
import hashlib
import os
import tempfile
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
from pyhanko.pdf_utils.misc import PdfReadError
from pyhanko.pdf_utils.reader import PdfFileReader
from pyhanko.pdf_utils.writer import copy_into_new_writer
from pyhanko.sign import signers, timestamps
from pyhanko.sign.fields import SigFieldSpec, SigSeedSubFilter
from pyhanko.stamp import TextStampStyle
from pyhanko.sign.signers.pdf_signer import PdfSignatureMetadata, PdfSigner
from pydantic import BaseModel

from report import SEAL_BOX, decorate

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
        .add_extension(
            x509.KeyUsage(
                digital_signature=True, content_commitment=True, key_encipherment=False, data_encipherment=False,
                key_agreement=False, key_cert_sign=False, crl_sign=False, encipher_only=False, decipher_only=False,
            ),
            critical=True,
        )
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


_PFX_FILES: dict[tuple[str, str], str] = {}


def _ref_key(secret_ref: str) -> str:
    return "".join(c for c in secret_ref.upper() if c.isalnum() or c == "_")


def _materialized_pfx(ref: str) -> str | None:
    """Caminho do .pfx do e-CNPJ: SEALER_PFX_<REF>_PATH (arquivo montado) ou SEALER_PFX_<REF>_B64 (conteúdo em base64,
    o jeito mais simples no Coolify), gravado uma única vez em arquivo privado (0600) numa pasta temporária (0700)."""
    path = os.environ.get(f"SEALER_PFX_{ref}_PATH")
    if path and os.path.isfile(path):
        return path
    b64 = os.environ.get(f"SEALER_PFX_{ref}_B64", "").strip()
    if not b64:
        return None
    key = (ref, hashlib.sha256(b64.encode()).hexdigest())
    cached = _PFX_FILES.get(key)
    if cached and os.path.isfile(cached):
        return cached
    try:
        data = base64.b64decode(b64, validate=False)
    except Exception as exc:  # noqa: BLE001
        raise HTTPException(status_code=500, detail=f"SEALER_PFX_{ref}_B64 não é base64 válido") from exc
    folder = tempfile.mkdtemp(prefix="sealer-")
    target = os.path.join(folder, "cert.pfx")
    fd = os.open(target, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
    with os.fdopen(fd, "wb") as fh:
        fh.write(data)
    _PFX_FILES[key] = target
    return target


def _pkcs12_from_ref(secret_ref: str) -> signers.SimpleSigner:
    """secret_ref -> SEALER_PFX_<REF>_PATH ou _B64, mais SEALER_PFX_<REF>_PASSWORD. A senha nunca vai em resposta nem em log."""
    ref = _ref_key(secret_ref)
    path = _materialized_pfx(ref)
    if not path:
        raise HTTPException(
            status_code=500,
            detail=f"certificado não configurado: defina SEALER_PFX_{ref}_B64 (ou _PATH) e SEALER_PFX_{ref}_PASSWORD",
        )
    password = os.environ.get(f"SEALER_PFX_{ref}_PASSWORD", "")
    unreadable = HTTPException(
        status_code=500, detail="não foi possível abrir o certificado: senha incorreta ou arquivo .pfx inválido",
    )
    try:
        signer = signers.SimpleSigner.load_pkcs12(pfx_file=path, passphrase=password.encode() or None)
    except Exception as exc:  # noqa: BLE001
        raise unreadable from exc
    if signer is None:  # o pyHanko devolve None (sem lançar erro) quando a senha está errada
        raise unreadable
    return signer


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


def open_for_signing(data: bytes) -> IncrementalPdfFileWriter:
    """Abre o PDF para assinatura incremental (preserva os bytes originais).

    PDFs com xref "híbrido" (comuns em Word/Chrome/iText) ou com pequenos problemas de sintaxe são
    reescritos antes, para um arquivo limpo, porque o pyHanko recusa assinar híbridos no modo estrito.
    """
    try:
        reader = PdfFileReader(io.BytesIO(data), strict=True)
        if not reader.xrefs.hybrid_xrefs_present:
            return IncrementalPdfFileWriter(io.BytesIO(data))
    except PdfReadError:
        pass  # sintaxe fora do padrão: tenta normalizar abaixo

    lenient = PdfFileReader(io.BytesIO(data), strict=False)
    clean = io.BytesIO()
    copy_into_new_writer(lenient).write(clean)
    clean.seek(0)

    return IncrementalPdfFileWriter(clean)


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

    # Carimbo visível em cada página + relatório de assinaturas (opcional). Se o PDF não puder ser
    # decorado (ex.: protegido por senha), sela mesmo assim e avisa em "decorated": false.
    decorated, decorate_error = False, None
    if meta.get("report"):
        try:
            data = decorate(data, meta["report"])
            decorated = True
        except Exception as exc:  # noqa: BLE001
            decorate_error = f"{type(exc).__name__}: {exc}"
            logging.getLogger("sealer").warning("não foi possível carimbar o PDF: %s", decorate_error)

    signer_kwargs = {}
    if decorated:
        # Selo digital visível no espaço reservado do rodapé da última página (a do relatório).
        signer_kwargs = {
            "new_field_spec": SigFieldSpec(sig_field_name="SeloPlataforma", on_page=-1, box=SEAL_BOX),
            "stamp_style": TextStampStyle(
                stamp_text="Selo digital da plataforma\nAssinado por: %(signer)s\nData: %(ts)s",
                border_width=1,
            ),
        }

    writer = open_for_signing(data)
    out = io.BytesIO()
    PdfSigner(signature_meta, signer=signer, timestamper=timestamper, **signer_kwargs).sign_pdf(writer, output=out)

    return {
        "pdf_base64": base64.b64encode(out.getvalue()).decode(),
        "sealed_at": datetime.now(timezone.utc).isoformat(),
        "timestamped": timestamper is not None,
        "certificate": info,
        "decorated": decorated,
        "decorate_error": decorate_error,
    }
