"""Testes de fill.py (texto estampado nas lacunas) e da rota POST /v1/fill."""
import io
import json
import os
import re
import sys

os.environ.setdefault("SEALER_TOKEN", "t")
os.environ["SEALER_ALLOW_TEST"] = "true"  # igual ao test_sealer: o módulo app lê isto ao ser importado
sys.path.insert(0, os.path.join(os.path.dirname(__file__), ".."))
sys.path.insert(0, os.path.dirname(__file__))

import pytest
from fastapi.testclient import TestClient
from pypdf import PdfReader, PdfWriter
from reportlab.pdfgen import canvas

import app as sealer
import fill

H = {"Authorization": "Bearer t"}


def make_pdf(pages=1, size=(400, 600), rotate=0, mediabox_offset=(0, 0)) -> bytes:
    buf = io.BytesIO()
    c = canvas.Canvas(buf, pagesize=size)
    for _ in range(pages):
        c.drawString(20, size[1] - 20, "Contrato")
        c.showPage()
    c.save()
    reader = PdfReader(io.BytesIO(buf.getvalue()))
    w = PdfWriter()
    for p in reader.pages:
        if mediabox_offset != (0, 0):
            ox, oy = mediabox_offset
            p.mediabox.lower_left = (ox, oy)
            p.mediabox.upper_right = (ox + size[0], oy + size[1])
        if rotate:
            p.rotate(rotate)
        w.add_page(p)
    out = io.BytesIO()
    w.write(out)
    return out.getvalue()


def field(**over):
    return {"key": "nome", "page": 1, "x": 0.1, "y": 0.1, "width": 0.5, "height": 0.05, "text": "João da Silva", "multiline": False, **over}


def page_text(pdf: bytes, n=0) -> str:
    return PdfReader(io.BytesIO(pdf)).pages[n].extract_text()


def font_sizes(pdf: bytes, n=0) -> list[float]:
    content = PdfReader(io.BytesIO(pdf)).pages[n].get_contents().get_data().decode("latin-1")
    return [float(m) for m in re.findall(r"/F\d+-\d+\s+([\d.]+)\s+Tf", content)]


def test_texto_simples_aparece_na_pagina():
    out = fill.fill_pdf(make_pdf(), [field()])
    assert "João da Silva" in page_text(out) and "Contrato" in page_text(out)


def test_acentos_do_portugues():
    out = fill.fill_pdf(make_pdf(), [field(text="Ação Ñandú çãõéü")])
    assert "Ação" in page_text(out)


def test_letra_comeca_em_11_e_diminui_ate_caber():
    short = fill.fill_pdf(make_pdf(), [field(text="Ana", width=0.8)])
    long_ = fill.fill_pdf(make_pdf(), [field(text="Maria das Dores Albuquerque Cavalcanti Junior", width=0.5)])
    assert font_sizes(short)[-1] == 11  # o último Tf é o da lacuna (o primeiro é a inicialização do reportlab)
    assert 6 <= font_sizes(long_)[-1] < 11


def test_texto_impossivel_levanta_erro_com_a_chave():
    with pytest.raises(fill.FitError) as e:
        fill.fill_pdf(make_pdf(), [field(key="valor", text="x" * 400, width=0.1)])
    assert e.value.key == "valor"


def test_varias_linhas_quebra_dentro_da_altura():
    text = "Rua das Flores, 123, apartamento 45, bairro Jardim Primavera, São Paulo, SP"
    out = fill.fill_pdf(make_pdf(), [field(key="end", text=text, width=0.4, height=0.12, multiline=True)])
    assert "Primavera" in page_text(out)
    with pytest.raises(fill.FitError):
        fill.fill_pdf(make_pdf(), [field(key="end", text=text * 5, width=0.4, height=0.03, multiline=True)])


def test_quebra_de_linha_em_lacuna_de_uma_linha_vira_espaco():
    out = fill.fill_pdf(make_pdf(), [field(text="linha um\nlinha dois")])
    assert "linha um linha dois" in page_text(out).replace("\n", " ")


@pytest.mark.parametrize("rotate", [90, 180, 270])
def test_pagina_girada_e_mediabox_deslocada_recebem_o_texto(rotate):
    out = fill.fill_pdf(make_pdf(rotate=rotate, mediabox_offset=(50, 70)), [field(text="GIRADO")])
    assert "GIRADO" in page_text(out)


def test_posicao_visual_no_topo_esquerdo_sem_rotacao():
    out = fill.fill_pdf(make_pdf(), [field(x=0.0, y=0.0, width=0.3, height=0.05, text="TOPO")])
    parts = []
    PdfReader(io.BytesIO(out)).pages[0].extract_text(visitor_text=lambda t, cm, tm, fd, fs: parts.append((t.strip(), tm[4], tm[5])) if t.strip() == "TOPO" else None)
    assert parts and parts[0][2] > 500  # perto do topo da página de 600 pt de altura


def test_pagina_inexistente_e_recusada():
    with pytest.raises(fill.InvalidPdf):
        fill.fill_pdf(make_pdf(), [field(page=3)])


def test_pdf_criptografado_ou_ilegivel_e_recusado():
    w = PdfWriter(clone_from=PdfReader(io.BytesIO(make_pdf())))
    w.encrypt("segredo")
    buf = io.BytesIO(); w.write(buf)
    with pytest.raises(fill.InvalidPdf):
        fill.fill_pdf(buf.getvalue(), [field()])
    with pytest.raises(fill.InvalidPdf):
        fill.fill_pdf(b"%PDF-1.4 lixo", [field()])


def test_texto_com_caracteres_especiais_nao_quebra():
    out = fill.fill_pdf(make_pdf(), [field(text="<b>%(x)s</b> \\ ) ( 😀 中")])
    assert "<b>" in page_text(out)


@pytest.fixture()
def client():
    return TestClient(sealer.app, raise_server_exceptions=False)


def post(client, pdf, fields, headers=H):
    return client.post("/v1/fill", headers=headers, files={"pdf": ("a.pdf", pdf, "application/pdf")}, data={"fields": json.dumps(fields)})


def test_rota_devolve_o_pdf_preenchido(client):
    r = post(client, make_pdf(), [field()])
    assert r.status_code == 200 and r.headers["content-type"].startswith("application/pdf") and "João" in page_text(r.content)


def test_rota_exige_token(client):
    assert post(client, make_pdf(), [field()], headers={}).status_code == 401


def test_rota_nao_cabe_devolve_422_com_o_campo(client):
    r = post(client, make_pdf(), [field(key="valor", text="x" * 400, width=0.1)])
    assert r.status_code == 422 and r.json() == {"error": "does_not_fit", "field": "valor"}


def test_rota_pdf_invalido_devolve_422(client):
    r = post(client, b"nao e pdf", [field()])
    assert r.status_code == 422 and r.json()["error"] == "invalid_pdf"


def test_rota_devolve_a_quantidade_de_paginas_no_cabecalho(client):
    r = post(client, make_pdf(pages=3), [field(page=2)])
    assert r.headers["x-page-count"] == "3"


def _origin(pdf: bytes, word: str):
    hits = []
    PdfReader(io.BytesIO(pdf)).pages[0].extract_text(visitor_text=lambda t, cm, tm, fd, fs: hits.append((tm[4] * cm[0] + tm[5] * cm[2] + cm[4], tm[4] * cm[1] + tm[5] * cm[3] + cm[5])) if t.strip() == word else None)
    return hits[0]


def test_pagina_girada_90_grava_na_posicao_visual_certa():
    # lacuna em x=0,1 y=0,1 de uma página 400x600 girada 90°: visualmente 600x400; origem do texto ≈ (61,5; 346,9) visuais
    out = fill.fill_pdf(make_pdf(rotate=90), [field(text="ROT")])
    x, y = _origin(out, "ROT")
    assert abs(x - 53.1) < 2 and abs(y - 61.5) < 2


def test_mediabox_deslocada_soma_a_origem():
    base = _origin(fill.fill_pdf(make_pdf(), [field(text="OFF")]), "OFF")
    moved = _origin(fill.fill_pdf(make_pdf(mediabox_offset=(50, 70)), [field(text="OFF")]), "OFF")
    assert abs((moved[0] - base[0]) - 50) < 0.5 and abs((moved[1] - base[1]) - 70) < 0.5
