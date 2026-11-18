"""Testes de convert.py (DOCX -> PDF) e da rota POST /v1/convert. O LibreOffice é simulado."""
import io
import os
import subprocess
import sys
import zipfile

os.environ.setdefault("SEALER_TOKEN", "t")
os.environ["SEALER_ALLOW_TEST"] = "true"
sys.path.insert(0, os.path.join(os.path.dirname(__file__), ".."))
sys.path.insert(0, os.path.dirname(__file__))

import pytest
from fastapi.testclient import TestClient

import app as sealer
import convert

H = {"Authorization": "Bearer t"}


def make_docx(extra: dict | None = None, document=True) -> bytes:
    buf = io.BytesIO()
    with zipfile.ZipFile(buf, "w") as z:
        z.writestr("[Content_Types].xml", "<Types/>")
        if document:
            z.writestr("word/document.xml", "<w:document/>")
        for k, v in (extra or {}).items():
            z.writestr(k, v)
    return buf.getvalue()


def fake_soffice(monkeypatch, *, pdf=b"%PDF-1.7 ok", exc=None, write=True):
    def run(cmd, **kw):
        if exc:
            raise exc
        if write:
            out_dir = cmd[cmd.index("--outdir") + 1]
            with open(os.path.join(out_dir, "in.pdf"), "wb") as fh:
                fh.write(pdf)
        return subprocess.CompletedProcess(cmd, 0)

    monkeypatch.setattr(convert.subprocess, "run", run)


def test_converte_docx_valido(monkeypatch):
    fake_soffice(monkeypatch)
    assert convert.convert_docx(make_docx()).startswith(b"%PDF")


@pytest.mark.parametrize("data", [b"", b"nao e zip", make_docx(document=False)])
def test_rejeita_o_que_nao_e_docx(monkeypatch, data):
    fake_soffice(monkeypatch)
    with pytest.raises(convert.ConvertError) as e:
        convert.convert_docx(data)
    assert e.value.reason == "invalid_docx"


def test_rejeita_bomba_de_compressao(monkeypatch):
    fake_soffice(monkeypatch)
    monkeypatch.setattr(convert, "MAX_UNZIPPED", 10)
    with pytest.raises(convert.ConvertError) as e:
        convert.convert_docx(make_docx({"word/x.bin": "a" * 100}))
    assert e.value.reason == "invalid_docx"


@pytest.mark.parametrize("exc,reason", [
    (subprocess.TimeoutExpired("soffice", 1), "timeout"),
    (subprocess.CalledProcessError(1, "soffice"), "convert_failed"),
    (FileNotFoundError(), "unavailable"),
])
def test_falhas_do_libreoffice(monkeypatch, exc, reason):
    fake_soffice(monkeypatch, exc=exc)
    with pytest.raises(convert.ConvertError) as e:
        convert.convert_docx(make_docx())
    assert e.value.reason == reason


def test_saida_que_nao_e_pdf_ou_ausente_falha(monkeypatch):
    fake_soffice(monkeypatch, pdf=b"lixo")
    with pytest.raises(convert.ConvertError):
        convert.convert_docx(make_docx())
    fake_soffice(monkeypatch, write=False)
    with pytest.raises(convert.ConvertError):
        convert.convert_docx(make_docx())


def test_limpa_a_pasta_temporaria(monkeypatch, tmp_path):
    monkeypatch.setattr(convert.tempfile, "tempdir", str(tmp_path))
    fake_soffice(monkeypatch)
    convert.convert_docx(make_docx())
    assert list(tmp_path.iterdir()) == []


def test_rota_devolve_pdf_e_exige_token(monkeypatch):
    fake_soffice(monkeypatch)
    c = TestClient(sealer.app)
    files = {"docx": ("a.docx", make_docx(), "application/octet-stream")}
    assert c.post("/v1/convert", files=files).status_code in (401, 403)
    r = c.post("/v1/convert", files=files, headers=H)
    assert r.status_code == 200 and r.content.startswith(b"%PDF") and r.headers["content-type"] == "application/pdf"


def test_rota_traduz_erros(monkeypatch):
    c = TestClient(sealer.app)
    fake_soffice(monkeypatch)
    r = c.post("/v1/convert", files={"docx": ("a.docx", b"xx", "application/octet-stream")}, headers=H)
    assert r.status_code == 422 and r.json()["error"] == "invalid_docx"
    fake_soffice(monkeypatch, exc=subprocess.TimeoutExpired("soffice", 1))
    r = c.post("/v1/convert", files={"docx": ("a.docx", make_docx(), "application/octet-stream")}, headers=H)
    assert r.status_code == 422 and r.json()["error"] == "timeout"
    fake_soffice(monkeypatch, exc=FileNotFoundError())
    r = c.post("/v1/convert", files={"docx": ("a.docx", make_docx(), "application/octet-stream")}, headers=H)
    assert r.status_code == 503 and r.json()["error"] == "unavailable"
