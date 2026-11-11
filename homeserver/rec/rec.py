"""
casa-rec — filtragem colaborativa do Player 2.0 (09/11/2026).

"Quem gostou de A também gostou de B", aprendido com o MovieLens 32M (GroupLens, Universidade de Minnesota:
32 milhões de avaliações de ~200 mil pessoas; uso pessoal/não comercial, sem redistribuir) pela biblioteca
open source `implicit` (ALS, fatoração de matriz — a técnica popularizada pelo prêmio Netflix).

  python rec.py baixar      baixa e confere o ml-32m.zip (uma vez)
  python rec.py treinar     treina o modelo (alguns minutos, 3 núcleos) -> /dados/modelo.npz
  python rec.py genoma      Tag Genome do MovieLens 25M (1.128 características por filme) -> /dados/genoma.npz
  python rec.py vizinhos    para cada filme do catálogo (e da Netflix importada), os 40 filmes do catálogo
                            mais parecidos pelo comportamento -> play.cache 'cf:ml:<tmdb>'
                            e pelo "clima" (Tag Genome) -> 'cf:tg:<tmdb>'
  python rec.py servir      laço do container: garante dados+modelo e refaz os vizinhos 1x por dia

O PHP (Dados\\Colaborativo) só lê play.cache. Nada roda na TV.
"""
import hashlib
import json
import os
import sys
import time
import urllib.request
import zipfile
from datetime import datetime, timezone

import numpy as np

DADOS = os.environ.get("REC_DADOS", "/dados")
URL = "https://files.grouplens.org/datasets/movielens/ml-32m.zip"
ZIP = os.path.join(DADOS, "ml-32m.zip")
MODELO = os.path.join(DADOS, "modelo.npz")
URL_25M = "https://files.grouplens.org/datasets/movielens/ml-25m.zip"      # só pela Tag Genome (o 32M não traz)
ZIP_25M = os.path.join(DADOS, "ml-25m.zip")
GENOMA = os.path.join(DADOS, "genoma.npz")
VIZINHOS = 40
MIN_POSITIVOS = 15          # filme com menos avaliações boas que isso fica de fora (fatores ruidosos)
NUCLEOS = int(os.environ.get("REC_NUCLEOS", "3"))


def log(msg):
    print(datetime.now().strftime("%Y-%m-%d %H:%M:%S"), msg, flush=True)


def conectar():
    import psycopg
    return psycopg.connect(host=os.environ.get("DB_HOST", "127.0.0.1"), port=os.environ.get("DB_PORT", "5432"),
                           dbname=os.environ.get("DB_NAME", "casa"), user=os.environ.get("DB_USER", "casa"),
                           password=os.environ.get("DB_PASS", ""), autocommit=True)


def baixar(url=URL, destino=ZIP):
    os.makedirs(DADOS, exist_ok=True)
    if os.path.exists(destino) and zipfile.is_zipfile(destino):
        return
    log(f"baixando {url}")
    tmp = destino + ".parcial"
    urllib.request.urlretrieve(url, tmp)
    esperado = urllib.request.urlopen(url + ".md5", timeout=30).read().decode().split()[0].strip().lower()
    h = hashlib.md5()
    with open(tmp, "rb") as f:
        for bloco in iter(lambda: f.read(1 << 20), b""):
            h.update(bloco)
    if h.hexdigest() != esperado:
        os.remove(tmp)
        raise RuntimeError(f"md5 não confere ({h.hexdigest()} != {esperado})")
    os.replace(tmp, destino)
    log(f"{os.path.basename(destino)} ok ({os.path.getsize(destino) // 1048576} MB, md5 conferido)")


def treinar():
    import pandas as pd
    import scipy.sparse as sp
    from implicit.als import AlternatingLeastSquares
    from implicit.nearest_neighbours import bm25_weight

    baixar()
    t0 = time.time()
    with zipfile.ZipFile(ZIP) as z:
        with z.open("ml-32m/links.csv") as f:
            links = pd.read_csv(f, usecols=["movieId", "tmdbId"], dtype={"movieId": "int32", "tmdbId": "Int64"})
        with z.open("ml-32m/ratings.csv") as f:
            r = pd.read_csv(f, usecols=["userId", "movieId", "rating"],
                            dtype={"userId": "int32", "movieId": "int32", "rating": "float32"})
    log(f"lidas {len(r):,} avaliações em {time.time() - t0:.0f} s")
    # sinal implícito: só o que a pessoa gostou (3,5+), mais forte quanto maior a nota
    r = r[r.rating >= 3.5]
    pos = r.groupby("movieId").size()
    r = r[r.movieId.isin(pos[pos >= MIN_POSITIVOS].index)]
    links = links.dropna()
    tmdb_de = dict(zip(links.movieId.astype(int), links.tmdbId.astype(int)))
    r = r[r.movieId.isin(tmdb_de.keys())]
    usuarios = r.userId.astype("category")
    filmes = r.movieId.astype("category")
    m = sp.csr_matrix(((r.rating.values - 2.5).astype(np.float32), (usuarios.cat.codes.values, filmes.cat.codes.values)),
                      shape=(len(usuarios.cat.categories), len(filmes.cat.categories)))
    del r
    m = bm25_weight(m, K1=100, B=0.8).tocsr().astype(np.float32)
    log(f"matriz {m.shape[0]:,} pessoas × {m.shape[1]:,} filmes, {m.nnz:,} gostos")
    modelo = AlternatingLeastSquares(factors=128, regularization=0.05, alpha=1.0, iterations=15,
                                     num_threads=NUCLEOS, random_state=42)
    modelo.fit(m, show_progress=False)
    fatores = np.asarray(modelo.item_factors, dtype=np.float32)
    normas = np.linalg.norm(fatores, axis=1, keepdims=True)
    fatores = fatores / np.maximum(normas, 1e-9)
    tmdb = np.array([tmdb_de[int(x)] for x in filmes.cat.categories], dtype=np.int32)
    # popularidade (nº de gostos) para a TV poder preferir o que é conhecido em empate
    pop = np.asarray((m > 0).sum(axis=0)).ravel().astype(np.int32)
    np.savez_compressed(MODELO + ".tmp.npz", fatores=fatores, tmdb=tmdb, pop=pop,
                        info=json.dumps({"treinado_em": datetime.now(timezone.utc).isoformat(), "pessoas": int(m.shape[0]),
                                         "filmes": int(m.shape[1]), "gostos": int(m.nnz)}))
    os.replace(MODELO + ".tmp.npz", MODELO)
    log(f"modelo treinado em {time.time() - t0:.0f} s -> {MODELO}")


def _gravar_vizinhos(db, fatores, tmdb, prefixo, alvo, origem, info):
    """Para cada filme de `origem`, os 40 de `alvo` (catálogo) mais parecidos pelo cosseno -> play.cache '<prefixo>:<tmdb>'."""
    pos_de = {int(t): i for i, t in enumerate(tmdb)}
    alvo_i = np.array(sorted(pos_de[t] for t in alvo if t in pos_de), dtype=np.int64)
    origem_i = np.array(sorted(pos_de[t] for t in origem if t in pos_de), dtype=np.int64)
    if len(alvo_i) < 2:
        log(f"{prefixo}: nenhum filme do catálogo na base")
        return
    alvo_f = fatores[alvo_i]
    n = min(VIZINHOS, len(alvo_i) - 1)
    linhas = []
    for ini in range(0, len(origem_i), 2000):
        bloco = origem_i[ini:ini + 2000]
        sim = fatores[bloco] @ alvo_f.T                      # cosseno (vetores normalizados)
        sim[alvo_i[None, :] == bloco[:, None]] = -1          # ele mesmo não
        top = np.argpartition(-sim, n, axis=1)[:, :n]
        for k, i in enumerate(bloco):
            ordem = top[k][np.argsort(-sim[k, top[k]])]
            v = [[int(tmdb[alvo_i[j]]), round(float(sim[k, j]), 3)] for j in ordem if sim[k, j] > 0.05]
            if v:
                linhas.append((f"{prefixo}:{int(tmdb[i])}", json.dumps({"v": v}, separators=(",", ":"))))
    with db.transaction():
        db.execute(f"delete from play.cache where chave like '{prefixo}:%'")
        with db.cursor() as c:
            c.executemany("insert into play.cache (chave, dados) values (%s, cast(%s as jsonb))", linhas)
        meta = dict(info, vizinhos_em=datetime.now(timezone.utc).isoformat(), origens=len(linhas), catalogo=int(len(alvo_i)),
                    catalogo_total=len(alvo))
        db.execute("insert into play.cache (chave, dados) values (%s, cast(%s as jsonb)) "
                   "on conflict (chave) do update set dados = excluded.dados, em = now()", (prefixo, json.dumps(meta)))
    log(f"{prefixo}: {len(linhas):,} filmes com vizinhos; catálogo coberto {len(alvo_i):,} de {len(alvo):,}")


def vizinhos():
    with conectar() as db:
        alvo = {int(x[0]) for x in db.execute(
            "select distinct tmdb_id from play.titulo where ativo and tipo = 'm' and tmdb_id is not null")}
        origem = set(alvo)
        for (v,) in db.execute("select valor from play.preferencia where chave = 'netflix'"):
            v = v if isinstance(v, dict) else json.loads(v)
            origem |= {int(o["tmdb"]) for o in v.get("obras", []) if o.get("t") == "m" and o.get("tmdb")}
        d = np.load(MODELO)
        _gravar_vizinhos(db, d["fatores"], d["tmdb"], "cf:ml", alvo, origem, json.loads(str(d["info"])))
        if os.path.exists(GENOMA):
            g = np.load(GENOMA)
            _gravar_vizinhos(db, g["vetores"], g["tmdb"], "cf:tg", alvo, origem, json.loads(str(g["info"])))


def genoma():
    """Tag Genome (MovieLens 25M): relevância de 1.128 características para ~13,8 mil filmes -> /dados/genoma.npz."""
    import pandas as pd
    baixar(URL_25M, ZIP_25M)
    t0 = time.time()
    with zipfile.ZipFile(ZIP_25M) as z:
        with z.open("ml-25m/links.csv") as f:
            links = pd.read_csv(f, usecols=["movieId", "tmdbId"]).dropna()
        with z.open("ml-25m/genome-tags.csv") as f:
            ntags = len(pd.read_csv(f))
        with z.open("ml-25m/genome-scores.csv") as f:
            g = pd.read_csv(f, dtype={"movieId": "int32", "tagId": "int32", "relevance": "float32"})
    filmes = np.sort(g.movieId.unique())
    linha = {int(m): i for i, m in enumerate(filmes)}
    m = np.zeros((len(filmes), ntags), dtype=np.float32)
    m[g.movieId.map(linha).values, g.tagId.values - 1] = g.relevance.values
    del g
    # centrado por característica (senão tudo parece com tudo) e normalizado: cosseno = "mesmo clima"
    m -= m.mean(axis=0, keepdims=True)
    m /= np.maximum(np.linalg.norm(m, axis=1, keepdims=True), 1e-9)
    tmdb_de = dict(zip(links.movieId.astype(int), links.tmdbId.astype(int)))
    ok = np.array([int(x) in tmdb_de for x in filmes])
    tmdb = np.array([tmdb_de[int(x)] for x in filmes[ok]], dtype=np.int32)
    np.savez_compressed(GENOMA + ".tmp.npz", vetores=m[ok], tmdb=tmdb,
                        info=json.dumps({"gerado_em": datetime.now(timezone.utc).isoformat(), "filmes": int(ok.sum()), "caracteristicas": ntags}))
    os.replace(GENOMA + ".tmp.npz", GENOMA)
    log(f"genoma: {int(ok.sum()):,} filmes × {ntags} características em {time.time() - t0:.0f} s")


def servir():
    ultimo = 0.0
    while True:
        try:
            if not os.path.exists(MODELO):
                treinar()
            if not os.path.exists(GENOMA):
                genoma()
                ultimo = 0.0
            pedido = False
            with conectar() as db:
                pedido = db.execute("delete from play.cache where chave = 'cf:pedido' returning 1").fetchone() is not None
            if pedido or time.time() - ultimo > 86400:
                vizinhos()
                ultimo = time.time()
        except Exception as e:  # noqa: BLE001 — o laço não pode morrer; tenta de novo em 10 min
            log(f"erro: {e!r}")
            time.sleep(600)
            continue
        time.sleep(300)


if __name__ == "__main__":
    {"baixar": baixar, "treinar": treinar, "genoma": genoma, "vizinhos": vizinhos, "servir": servir}.get(
        sys.argv[1] if len(sys.argv) > 1 else "", lambda: sys.exit(__doc__))()
