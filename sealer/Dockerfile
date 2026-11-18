FROM python:3.12-slim

ENV PYTHONDONTWRITEBYTECODE=1 PYTHONUNBUFFERED=1
WORKDIR /srv

# LibreOffice (só o Writer) converte DOCX em PDF dentro do próprio servidor; as fontes evitam texto trocado.
RUN apt-get update \
    && apt-get install -y --no-install-recommends libreoffice-writer fonts-liberation fonts-dejavu-core fonts-crosextra-carlito fonts-crosextra-caladea \
    && rm -rf /var/lib/apt/lists/*

COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

COPY app.py report.py fill.py convert.py ./

RUN useradd --system --no-create-home sealer
USER sealer

EXPOSE 8080
CMD ["uvicorn", "app:app", "--host", "0.0.0.0", "--port", "8080"]
