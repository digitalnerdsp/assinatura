"""Converte DOCX em PDF com o LibreOffice (sem rede, perfil e pastas temporários, tempo limite)."""
from __future__ import annotations

import io
import os
import shutil
import subprocess
import tempfile
import zipfile

MAX_INPUT = 25 * 1024 * 1024
MAX_UNZIPPED = 300 * 1024 * 1024  # contra "bomba" de compressão
TIMEOUT = int(os.environ.get("SEALER_CONVERT_TIMEOUT", "60"))
SOFFICE = os.environ.get("SOFFICE_BIN", "soffice")


class ConvertError(Exception):
    """reason: invalid_docx | timeout | convert_failed | unavailable"""

    def __init__(self, reason: str):
        super().__init__(reason)
        self.reason = reason


def _check_docx(data: bytes) -> None:
    if len(data) > MAX_INPUT or not data.startswith(b"PK"):
        raise ConvertError("invalid_docx")
    try:
        with zipfile.ZipFile(io.BytesIO(data)) as z:
            names = set(z.namelist())
            if "word/document.xml" not in names or "[Content_Types].xml" not in names:
                raise ConvertError("invalid_docx")
            if sum(i.file_size for i in z.infolist()) > MAX_UNZIPPED:
                raise ConvertError("invalid_docx")
    except zipfile.BadZipFile:
        raise ConvertError("invalid_docx")


def convert_docx(data: bytes) -> bytes:
    _check_docx(data)
    work = tempfile.mkdtemp(prefix="conv-")
    try:
        src = os.path.join(work, "in.docx")
        out_dir = os.path.join(work, "out")
        profile = os.path.join(work, "profile")
        os.makedirs(out_dir)
        with open(src, "wb") as fh:
            fh.write(data)
        cmd = [
            SOFFICE, "--headless", "--norestore", "--nolockcheck", "--nodefault", "--nofirststartwizard",
            f"-env:UserInstallation=file://{profile}", "--convert-to", "pdf", "--outdir", out_dir, src,
        ]
        try:
            subprocess.run(cmd, check=True, timeout=TIMEOUT, capture_output=True, env={**os.environ, "HOME": work})
        except FileNotFoundError:
            raise ConvertError("unavailable")
        except subprocess.TimeoutExpired:
            raise ConvertError("timeout")
        except subprocess.CalledProcessError:
            raise ConvertError("convert_failed")
        out = os.path.join(out_dir, "in.pdf")
        if not os.path.isfile(out):
            raise ConvertError("convert_failed")
        with open(out, "rb") as fh:
            pdf = fh.read()
        if not pdf.startswith(b"%PDF"):
            raise ConvertError("convert_failed")
        return pdf
    finally:
        shutil.rmtree(work, ignore_errors=True)
