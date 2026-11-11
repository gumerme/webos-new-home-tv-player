#!/bin/bash
# Dump diário do Postgres da casa. Mantém 14 dias. Rodado por casa-backup.timer.
# Registra o resultado na linha do tempo do painel (tabela evento).
set -uo pipefail
DEST=/srv/casa/backup/postgres
mkdir -p "$DEST"
ARQ="$DEST/casa-$(date +%F_%H%M).sql.gz"

evento() {  # nivel titulo detalhe
    docker exec -i postgres psql -q -U casa -d casa -v ON_ERROR_STOP=1 \
        -v nivel="$1" -v titulo="$2" -v detalhe="$3" >/dev/null 2>&1 <<'SQL'
insert into evento (tipo, nivel, titulo, detalhe, origem) values ('backup', :'nivel', :'titulo', :'detalhe', 'backup');
SQL
}

if docker exec postgres pg_dump -U casa -d casa | gzip -9 > "$ARQ.tmp" && [ -s "$ARQ.tmp" ]; then
    mv "$ARQ.tmp" "$ARQ"
    find "$DEST" -name 'casa-*.sql.gz' -mtime +14 -delete
    tam=$(du -h "$ARQ" | cut -f1)
    echo "backup ok: $ARQ ($tam)"
    evento ok "Backup do banco concluído" "$(basename "$ARQ") · $tam"
else
    rm -f "$ARQ.tmp"
    echo "backup FALHOU" >&2
    evento erro "Backup do banco falhou" "pg_dump não gerou arquivo"
    exit 1
fi
