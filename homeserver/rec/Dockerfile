# casa-rec: filtragem colaborativa do Player 2.0 (MovieLens + implicit/ALS).
# Treina com as avaliações públicas do MovieLens e grava os "vizinhos" de cada filme do catálogo em play.cache.
FROM python:3.11-slim
ENV PYTHONUNBUFFERED=1 OPENBLAS_NUM_THREADS=1 MKL_NUM_THREADS=1
RUN apt-get update && apt-get install -y --no-install-recommends libgomp1 && rm -rf /var/lib/apt/lists/*
RUN pip install --no-cache-dir "implicit==0.7.2" "numpy<2" "scipy<1.14" "pandas<2.3" "psycopg[binary]==3.2.*"
WORKDIR /rec
COPY rec.py /rec/rec.py
CMD ["python", "/rec/rec.py", "servir"]
