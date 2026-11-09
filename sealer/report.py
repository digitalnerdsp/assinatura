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

import io
from xml.sax.saxutils import escape

from pypdf import PdfReader, PdfWriter
from reportlab.graphics import renderPDF
from reportlab.graphics.barcode import qr
from reportlab.graphics.shapes import Drawing
from reportlab.lib import colors
from reportlab.lib.pagesizes import A4
from reportlab.lib.styles import ParagraphStyle, getSampleStyleSheet
from reportlab.pdfgen import canvas
from reportlab.platypus import KeepTogether, Paragraph, SimpleDocTemplate, Spacer, Table, TableStyle

# Região reservada no rodapé da última página do relatório para o selo digital visível.
SEAL_BOX = (40, 28, 555, 100)
BOTTOM_MARGIN = 120

BLUE = colors.HexColor("#1f4fd8")
GREY = colors.HexColor("#555555")
LIGHT = colors.HexColor("#f1f4fb")


def _qr_drawing(url: str, size: float) -> Drawing:
    widget = qr.QrCodeWidget(url)
    x0, y0, x1, y1 = widget.getBounds()
    d = Drawing(size, size, transform=[size / (x1 - x0), 0, 0, size / (y1 - y0), 0, 0])
    d.add(widget)
    return d


def _footer_overlay(width: float, height: float, code: str, url: str) -> PdfReader:
    buf = io.BytesIO()
    c = canvas.Canvas(buf, pagesize=(width, height))
    qr_size, margin = 34, 22

    c.setFillColor(colors.white)
    c.rect(width - margin - qr_size - 2, 8, qr_size + 4, qr_size + 4, stroke=0, fill=1)
    renderPDF.draw(_qr_drawing(url, qr_size), c, width - margin - qr_size, 10)

    c.setFillColor(GREY)
    c.setFont("Helvetica", 6.5)
    available = width - 2 * margin - qr_size - 8  # não deixa o texto encostar no QR em páginas estreitas
    c.drawString(margin, 22, "Documento assinado eletronicamente")
    for line in (f"Código de verificação {code}  |  {url}", f"Código de verificação {code}", code):
        if c.stringWidth(line, "Helvetica", 6.5) <= available:
            c.drawString(margin, 13, line)
            break
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
    t = Table(data, colWidths=widths)
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
    ], st))

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

    doc.build(el, onFirstPage=on_page, onLaterPages=on_page)

    return buf.getvalue()


def decorate(pdf: bytes, report: dict) -> bytes:
    """Aplica o carimbo em todas as páginas e anexa o relatório. Lança exceção se o PDF não puder ser lido."""
    code, url = str(report["code"]), str(report["url"])
    reader = PdfReader(io.BytesIO(pdf))
    if reader.is_encrypted:
        raise ValueError("PDF protegido por senha: não é possível carimbar")

    # pypdf 7: as páginas precisam pertencer ao escritor antes de receber o carimbo.
    writer = PdfWriter(clone_from=reader)
    for page in writer.pages:
        w, h = float(page.mediabox.width), float(page.mediabox.height)
        page.merge_page(_footer_overlay(w, h, code, url).pages[0])

    writer.append(PdfReader(io.BytesIO(_report_pdf(report))))

    out = io.BytesIO()
    writer.write(out)

    return out.getvalue()
