"""Carimbo visível em cada página + página(s) de relatório de assinaturas.

Recebe o PDF original e um dicionário `report` (montado pelo Laravel) e devolve um novo PDF, ainda
não assinado digitalmente. A assinatura PAdES é aplicada depois, em app.py.

report = {
  "code", "url", "tenant", "title", "document_name", "document_sha256", "chain_hash", "generated_at",
  "signers": [{"name","email","cpf","role","status","signed_at","signature","ip","device","checks":[...],"geo"}],
  "events":  [{"seq","label","at","who","ip"}],
}
"""
from __future__ import annotations

import base64
import io
from xml.sax.saxutils import escape

import qrcode
from pypdf import PdfReader, PdfWriter
from qrcode.constants import ERROR_CORRECT_L, ERROR_CORRECT_M
from reportlab.lib import colors
from reportlab.lib.pagesizes import A4
from reportlab.lib.styles import ParagraphStyle, getSampleStyleSheet
from reportlab.lib.utils import ImageReader
from reportlab.pdfgen import canvas
from reportlab.platypus import KeepTogether, Paragraph, SimpleDocTemplate, Spacer, Table, TableStyle

# Região reservada no rodapé da última página do relatório para o selo digital visível.
SEAL_BOX = (40, 28, 555, 100)
BOTTOM_MARGIN = 120

BLUE = colors.HexColor("#1f4fd8")
GREY = colors.HexColor("#555555")
LIGHT = colors.HexColor("#f1f4fb")


def _draw_qr(c: canvas.Canvas, url: str, x: float, y: float, size: float, quiet: int = 2, ecc: int = ERROR_CORRECT_L) -> None:
    """Desenha o QR como retângulos sólidos (sem contorno), com zona de silêncio branca.

    Módulos vizinhos na mesma linha são unidos para não aparecerem "costuras" em alguns visualizadores.
    Correção de erro baixa (L) = menos módulos = quadradinhos maiores e mais fáceis de ler na tela.
    """
    q = qrcode.QRCode(error_correction=ecc, border=0, box_size=1)
    q.add_data(url)
    q.make(fit=True)
    matrix = q.get_matrix()
    n = len(matrix)
    total = n + 2 * quiet
    mod = size / total

    c.setFillColor(colors.white)
    c.rect(x, y, size, size, stroke=0, fill=1)
    c.setFillColor(colors.black)
    for r, row in enumerate(matrix):
        col = 0
        while col < n:
            if not row[col]:
                col += 1
                continue
            start = col
            while col < n and row[col]:
                col += 1
            # linha 0 fica no topo; +0.2 de sobreposição vertical evita frestas entre linhas
            c.rect(x + (quiet + start) * mod, y + (total - quiet - r - 1) * mod - 0.2, (col - start) * mod, mod + 0.2, stroke=0, fill=1)


def _footer_overlay(width: float, height: float, code: str, url: str) -> PdfReader:
    buf = io.BytesIO()
    c = canvas.Canvas(buf, pagesize=(width, height))
    qr_size, margin = 66, 12

    _draw_qr(c, url, width - margin - qr_size, 6, qr_size)

    c.setFillColor(GREY)
    c.setFont("Helvetica", 6.5)
    available = width - margin - 22 - qr_size - 8  # não deixa o texto encostar no QR em páginas estreitas
    c.drawString(22, 22, "Documento assinado eletronicamente")
    for line in (f"Código de verificação {code}  |  {url}", f"Código de verificação {code}", code):
        if c.stringWidth(line, "Helvetica", 6.5) <= available:
            c.drawString(22, 13, line)
            break
    c.save()
    buf.seek(0)

    return PdfReader(buf)


def _placement_overlay(width: float, height: float, placements: list[dict]) -> PdfReader:
    """Desenha as assinaturas dos signatários nas posições escolhidas (frações da página, origem no topo)."""
    buf = io.BytesIO()
    c = canvas.Canvas(buf, pagesize=(width, height))

    for p in placements:
        bw, bh = float(p["width"]) * width, float(p["height"]) * height
        x0 = float(p["x"]) * width
        y0 = height - float(p["y"]) * height - bh  # y do PDF cresce para cima

        c.setStrokeColor(colors.HexColor("#9aa6c4"))
        c.setLineWidth(0.5)
        c.setFillColor(colors.white)
        c.roundRect(x0, y0, bw, bh, 2, stroke=1, fill=1)

        caption_h = 8.5
        area_h = bh - caption_h - 3
        drew = False
        if p.get("image"):
            try:
                raw = base64.b64decode(p["image"], validate=False)
                img = ImageReader(io.BytesIO(raw))
                iw, ih = img.getSize()
                scale = min((bw - 6) / iw, area_h / ih)
                c.drawImage(img, x0 + (bw - iw * scale) / 2, y0 + caption_h + 2 + (area_h - ih * scale) / 2,
                            iw * scale, ih * scale, mask="auto")
                drew = True
            except Exception:  # imagem corrompida: cai para o nome digitado
                drew = False
        if not drew:
            text = str(p.get("text") or p.get("name") or "")[:60]
            size = min(16.0, area_h * 0.7)
            while size > 6 and c.stringWidth(text, "Times-Italic", size) > bw - 8:
                size -= 0.5
            c.setFillColor(colors.HexColor("#1f2a5a"))
            c.setFont("Times-Italic", size)
            c.drawCentredString(x0 + bw / 2, y0 + caption_h + 2 + (area_h - size) / 2 + size * 0.2, text)

        c.setFillColor(GREY)
        c.setFont("Helvetica", 5.5)
        caption = f"{p.get('name', '')} · {p.get('signed_at', '')}"
        while len(caption) > 8 and c.stringWidth(caption, "Helvetica", 5.5) > bw - 6:
            caption = caption[:-2]
        c.drawString(x0 + 3, y0 + 2.5, caption)

    c.save()
    buf.seek(0)

    return PdfReader(buf)


def _styles() -> dict[str, ParagraphStyle]:
    base = getSampleStyleSheet()
    return {
        "title": ParagraphStyle("t", parent=base["Title"], fontName="Helvetica-Bold", fontSize=17, textColor=BLUE, alignment=0, spaceAfter=2),
        "sub": ParagraphStyle("s", parent=base["Normal"], fontSize=9, textColor=GREY, spaceAfter=8),
        "h2": ParagraphStyle("h", parent=base["Heading2"], fontName="Helvetica-Bold", fontSize=11, textColor=BLUE, spaceBefore=10, spaceAfter=4),
        "cell": ParagraphStyle("c", parent=base["Normal"], fontName="Helvetica", fontSize=8, leading=10),
        "label": ParagraphStyle("l", parent=base["Normal"], fontName="Helvetica-Bold", fontSize=8, leading=10, textColor=GREY),
        "mono": ParagraphStyle("m", parent=base["Normal"], fontName="Courier", fontSize=7, leading=9),
        "note": ParagraphStyle("n", parent=base["Normal"], fontSize=7.5, leading=10, textColor=GREY),
    }


def _p(text: object, style: ParagraphStyle) -> Paragraph:
    return Paragraph(escape(str(text if text not in (None, "") else "-")), style)


def _kv_table(rows: list[tuple[str, object, bool]], st: dict[str, ParagraphStyle], widths=(120, 395)) -> Table:
    data = [[_p(k, st["label"]), _p(v, st["mono"] if mono else st["cell"])] for k, v, mono in rows]
    t = Table(data, colWidths=widths, hAlign="LEFT")
    t.setStyle(TableStyle([
        ("VALIGN", (0, 0), (-1, -1), "TOP"),
        ("LINEBELOW", (0, 0), (-1, -1), 0.25, colors.HexColor("#d9deea")),
        ("TOPPADDING", (0, 0), (-1, -1), 3), ("BOTTOMPADDING", (0, 0), (-1, -1), 3),
    ]))
    return t


def _report_pdf(report: dict) -> bytes:
    st = _styles()
    buf = io.BytesIO()
    doc = SimpleDocTemplate(
        buf, pagesize=A4, leftMargin=40, rightMargin=40, topMargin=40, bottomMargin=BOTTOM_MARGIN,
        title="Relatorio de assinaturas", author=str(report.get("tenant", "")),
    )
    el: list = []

    el.append(Paragraph("Relatório de assinaturas", st["title"]))
    el.append(Paragraph(escape(str(report.get("title", ""))), st["sub"]))

    el.append(_kv_table([
        ("Emitido por", report.get("tenant"), False),
        ("Documento", report.get("document_name"), False),
        ("Código de verificação", report.get("code"), True),
        ("Verificar online", report.get("url"), True),
        ("SHA-256 do original", report.get("document_sha256"), True),
        ("Hash final da trilha", report.get("chain_hash"), True),
        ("Gerado em", report.get("generated_at"), False),
    ], st, widths=(105, 292)))  # deixa ~110 pt livres à direita para o QR grande

    el.append(Paragraph("Signatários", st["h2"]))
    for s in report.get("signers", []):
        rows = [
            ("Nome", f"{s.get('name', '-')} ({s.get('role', 'signer')})", False),
            ("E-mail", s.get("email"), False),
            ("CPF", s.get("cpf"), False),
            ("Situação", f"{s.get('status', '-')}  {('em ' + s['signed_at']) if s.get('signed_at') else ''}".strip(), False),
            ("Assinatura", s.get("signature"), False),
            ("IP", s.get("ip"), True),
            ("Dispositivo", s.get("device"), False),
            ("Verificações", ", ".join(s.get("checks", [])) or "nenhuma (assinatura simples)", False),
        ]
        if s.get("geo"):
            rows.append(("Localização", s["geo"], False))
        block = _kv_table(rows, st)
        block.setStyle(TableStyle([("BACKGROUND", (0, 0), (-1, 0), LIGHT)]))
        el.append(KeepTogether([block, Spacer(1, 6)]))

    el.append(Paragraph("Trilha de eventos", st["h2"]))
    head = [_p(h, st["label"]) for h in ("#", "Evento", "Data/hora (BRT)", "IP")]
    rows = [head] + [
        [_p(e.get("seq"), st["cell"]), _p(f"{e.get('label', '')}{(' - ' + e['who']) if e.get('who') else ''}", st["cell"]),
         _p(e.get("at"), st["cell"]), _p(e.get("ip"), st["mono"])]
        for e in report.get("events", [])
    ]
    t = Table(rows, colWidths=(24, 270, 125, 96), repeatRows=1)
    t.setStyle(TableStyle([
        ("BACKGROUND", (0, 0), (-1, 0), LIGHT), ("VALIGN", (0, 0), (-1, -1), "TOP"),
        ("LINEBELOW", (0, 0), (-1, -1), 0.25, colors.HexColor("#d9deea")),
        ("TOPPADDING", (0, 0), (-1, -1), 2.5), ("BOTTOMPADDING", (0, 0), (-1, -1), 2.5),
    ]))
    el.append(t)

    el.append(Spacer(1, 10))
    el.append(Paragraph(
        escape(
            "A autenticidade deste documento pode ser conferida no endereço de verificação acima, informando o código ou enviando "
            "o arquivo. Os eventos são registrados em uma trilha encadeada por hash: qualquer alteração posterior invalida a "
            "cadeia. O selo digital ao final garante que o arquivo não foi modificado depois de selado."
        ),
        st["note"],
    ))

    def on_page(c, d):  # numeração discreta
        c.setFont("Helvetica", 7)
        c.setFillColor(GREY)
        c.drawRightString(A4[0] - 40, A4[1] - 24, f"Relatório de assinaturas  |  página {c.getPageNumber()}")

    def on_first_page(c, d):
        on_page(c, d)
        # QR grande (fácil de ler na tela do computador) no canto superior direito, ao lado dos dados.
        size = 104
        x, y = A4[0] - 40 - size, A4[1] - 40 - 24 - size
        _draw_qr(c, str(report.get("url", "")), x, y, size, quiet=3, ecc=ERROR_CORRECT_M)
        c.setFont("Helvetica", 7)
        c.setFillColor(GREY)
        c.drawCentredString(x + size / 2, y - 9, "Aponte a câmera para verificar")

    doc.build(el, onFirstPage=on_first_page, onLaterPages=on_page)

    return buf.getvalue()


def decorate(pdf: bytes, report: dict) -> bytes:
    """Aplica o carimbo em todas as páginas e anexa o relatório. Lança exceção se o PDF não puder ser lido."""
    code, url = str(report["code"]), str(report["url"])
    reader = PdfReader(io.BytesIO(pdf))
    if reader.is_encrypted:
        raise ValueError("PDF protegido por senha: não é possível carimbar")

    # pypdf 7: as páginas precisam pertencer ao escritor antes de receber o carimbo.
    writer = PdfWriter(clone_from=reader)
    by_page: dict[int, list[dict]] = {}
    for p in report.get("placements") or []:
        by_page.setdefault(int(p["page"]) - 1, []).append(p)

    for index, page in enumerate(writer.pages):
        w, h = float(page.mediabox.width), float(page.mediabox.height)
        page.merge_page(_footer_overlay(w, h, code, url).pages[0])
        if index in by_page:  # posições fora do documento são ignoradas
            page.merge_page(_placement_overlay(w, h, by_page[index]).pages[0])

    writer.append(PdfReader(io.BytesIO(_report_pdf(report))))

    out = io.BytesIO()
    writer.write(out)

    return out.getvalue()
