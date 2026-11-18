"""Escreve textos em lacunas de um PDF (modelos). Coordenadas das lacunas: frações da página visível,
origem no canto superior esquerdo. A letra começa em 11 pt e diminui até 6 pt; se não couber, FitError."""
from __future__ import annotations

import io

from pypdf import PageObject, PdfReader, PdfWriter, Transformation
from reportlab.pdfbase.pdfmetrics import stringWidth
from reportlab.pdfgen import canvas

FONT = "Helvetica"
MAX_PAGES = 500    # PDFs maiores são recusados (evita ocupar o selador com arquivos gigantes)
MAX_SIZE = 11.0
MIN_SIZE = 6.0
STEP = 0.5
PAD = 1.5          # folga lateral dentro da lacuna, em pt
LEADING = 1.2      # entrelinha (múltiplo do tamanho da letra)


class InvalidPdf(Exception):
    """PDF ilegível, protegido por senha ou lacuna em página que não existe."""


class InvalidField(Exception):
    """Lacuna malformada na requisição."""


class FitError(Exception):
    """O texto não cabe na lacuna nem com a letra mínima."""

    def __init__(self, key: str) -> None:
        super().__init__(key)
        self.key = key


def _safe(text: str, multiline: bool) -> str:
    """Helvetica só desenha o alfabeto latino (WinAnsi): o resto vira "?" em vez de quebrar."""
    text = text.replace("\r\n", "\n").replace("\r", "\n").replace("\t", " ").rstrip("\n")
    if not multiline:
        text = " ".join(text.split("\n"))
    return text.encode("cp1252", "replace").decode("cp1252")


def _wrap(text: str, size: float, width: float) -> list[str]:
    lines: list[str] = []
    for paragraph in text.split("\n"):
        current = ""
        for word in paragraph.split(" "):
            candidate = word if not current else current + " " + word
            if stringWidth(candidate, FONT, size) <= width:
                current = candidate
                continue
            if current:
                lines.append(current)
            # palavra maior que a largura: quebra por caractere
            current = ""
            for ch in word:
                if stringWidth(current + ch, FONT, size) <= width:
                    current += ch
                else:
                    lines.append(current)
                    current = ch
        lines.append(current)
    return lines


def _layout(f: dict, w: float, h: float) -> tuple[float, list[str]]:
    """Menor redução de letra em que o texto cabe: (tamanho, linhas)."""
    text = _safe(str(f.get("text", "")), bool(f.get("multiline")))
    avail_w = max(w - 2 * PAD, 1.0)
    size = MAX_SIZE
    while size >= MIN_SIZE - 1e-9:
        if f.get("multiline"):
            lines = _wrap(text, size, avail_w)
            if len(lines) * size * LEADING <= h + 1e-6:
                return size, lines
        elif stringWidth(text, FONT, size) <= avail_w and size * LEADING <= h + 1e-6:
            return size, [text]
        size -= STEP
    raise FitError(str(f.get("key", "")))


def _check_field(f: object) -> None:
    """Campo malformado (chave ausente, número inválido) vira InvalidField em vez de erro 500."""
    try:
        for k in ("page", "x", "y", "width", "height"):
            v = float(f[k])  # type: ignore[index]
            if v != v or v in (float("inf"), float("-inf")):
                raise ValueError(k)
        if not isinstance(f.get("text", ""), str):  # type: ignore[union-attr]
            raise ValueError("text")
    except (KeyError, TypeError, ValueError, AttributeError) as exc:
        raise InvalidField(str(exc)) from exc


def _visible_box(page) -> tuple[float, float, float, float]:
    """Área visível da página: CropBox limitada à MediaBox (é o que o visualizador mostra e onde o cliente marcou a lacuna)."""
    media = page.mediabox
    left, bottom, right, top = float(media.left), float(media.bottom), float(media.right), float(media.top)
    if "/CropBox" in page:
        crop = page.cropbox
        l, b, r, t = float(crop.left), float(crop.bottom), float(crop.right), float(crop.top)
        l, b, r, t = max(l, left), max(b, bottom), min(r, right), min(t, top)
        if r > l and t > b:
            left, bottom, right, top = l, b, r, t
    return left, bottom, right - left, top - bottom


def _to_user_space(rotate: int, wu: float, hu: float, llx: float, lly: float) -> Transformation:
    """Leva o desenho feito na página visível (origem embaixo à esquerda) para o espaço do PDF."""
    ctm = {0: (1, 0, 0, 1, 0, 0), 90: (0, 1, -1, 0, wu, 0), 180: (-1, 0, 0, -1, wu, hu), 270: (0, -1, 1, 0, 0, hu)}[rotate]
    return Transformation(ctm=(ctm[0], ctm[1], ctm[2], ctm[3], ctm[4] + llx, ctm[5] + lly))


def fill_pdf(pdf: bytes, fields: list[dict]) -> bytes:
    try:
        reader = PdfReader(io.BytesIO(pdf))
        if reader.is_encrypted and not reader.decrypt(""):
            raise InvalidPdf("pdf protegido por senha")
        if len(reader.pages) > MAX_PAGES:
            raise InvalidPdf("pdf com páginas demais")
        pages = list(reader.pages)
    except InvalidPdf:
        raise
    except Exception as exc:  # noqa: BLE001
        raise InvalidPdf(str(exc)) from exc

    by_page: dict[int, list[dict]] = {}
    for f in fields:
        _check_field(f)
        n = int(f["page"])
        if n < 1 or n > len(pages):
            raise InvalidPdf(f"página {n} não existe")
        by_page.setdefault(n, []).append(f)

    writer = PdfWriter()
    for index, source in enumerate(pages, start=1):
        page = writer.add_page(source)  # anexar ao writer antes de alterar (exigência do pypdf 7)
        if index in by_page:
            llx, lly, wu, hu = _visible_box(page)
            rotate = int(page.get("/Rotate", 0) or 0) % 360
            if rotate not in (0, 90, 180, 270):
                rotate = 0
            wv, hv = (hu, wu) if rotate in (90, 270) else (wu, hu)

            buf = io.BytesIO()
            c = canvas.Canvas(buf, pagesize=(wv, hv))
            for f in by_page[index]:
                bw, bh = float(f["width"]) * wv, float(f["height"]) * hv
                bx, by_top = float(f["x"]) * wv, float(f["y"]) * hv
                size, lines = _layout(f, bw, bh)
                c.setFont(FONT, size)
                top = hv - by_top                      # topo da lacuna, de baixo para cima
                if len(lines) == 1:
                    baseline = top - bh + (bh - size) / 2 + size * 0.22
                    c.drawString(bx + PAD, baseline, lines[0])
                else:
                    for i, line in enumerate(lines):
                        c.drawString(bx + PAD, top - size - i * size * LEADING, line)
            c.showPage()
            c.save()

            overlay = PdfReader(io.BytesIO(buf.getvalue())).pages[0]
            page.merge_transformed_page(overlay, _to_user_space(rotate, wu, hu, llx, lly))

    out = io.BytesIO()
    writer.write(out)
    return out.getvalue()
