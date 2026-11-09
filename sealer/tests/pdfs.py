"""Gera PDFs de teste: simples e com xref híbrido (reproduz o erro 'hybrid cross-reference sections')."""
import struct


def plain_pdf() -> bytes:
    objs = [
        b"<</Type/Catalog/Pages 2 0 R>>",
        b"<</Type/Pages/Kids[3 0 R]/Count 1>>",
        b"<</Type/Page/Parent 2 0 R/MediaBox[0 0 200 200]>>",
    ]
    out = bytearray(b"%PDF-1.4\n")
    offs = []
    for i, body in enumerate(objs, start=1):
        offs.append(len(out))
        out += f"{i} 0 obj\n".encode() + body + b"\nendobj\n"
    xref = len(out)
    out += f"xref\n0 {len(objs)+1}\n".encode() + b"0000000000 65535 f \n"
    for o in offs:
        out += f"{o:010d} 00000 n \n".encode()
    out += f"trailer\n<</Size {len(objs)+1}/Root 1 0 R>>\nstartxref\n{xref}\n%%EOF\n".encode()
    return bytes(out)


def hybrid_pdf() -> bytes:
    out = bytearray(b"%PDF-1.5\n")
    o1 = len(out); out += b"1 0 obj\n<</Type/Catalog/Pages 2 0 R>>\nendobj\n"
    o3 = len(out); out += b"3 0 obj\n<</Type/Page/Parent 2 0 R/MediaBox[0 0 200 200]>>\nendobj\n"
    inner = b"2 0 <</Type/Pages/Kids[3 0 R]/Count 1>>"
    o4 = len(out)
    out += b"4 0 obj\n<</Type/ObjStm/N 1/First 4/Length %d>>\nstream\n" % len(inner) + inner + b"\nendstream\nendobj\n"
    o5 = len(out)
    entries = struct.pack(">BHB", 2, 4, 0) + struct.pack(">BHB", 1, o4, 0) + struct.pack(">BHB", 1, o5, 0)
    out += b"5 0 obj\n<</Type/XRef/W[1 2 1]/Index[2 1 4 2]/Size 6/Root 1 0 R/Length %d>>\nstream\n" % len(entries) + entries + b"\nendstream\nendobj\n"
    xref = len(out)
    out += b"xref\n0 6\n0000000000 65535 f \n"
    out += f"{o1:010d} 00000 n \n".encode()
    out += b"0000000000 00000 f \n"            # obj 2: livre na tabela clássica, real no XRefStm (híbrido)
    out += f"{o3:010d} 00000 n \n".encode()
    out += f"{o4:010d} 00000 n \n".encode()
    out += f"{o5:010d} 00000 n \n".encode()
    out += f"trailer\n<</Size 6/Root 1 0 R/XRefStm {o5}>>\nstartxref\n{xref}\n%%EOF\n".encode()
    return bytes(out)
