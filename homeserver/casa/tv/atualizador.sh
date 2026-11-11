#!/bin/sh
# Atualizador do WebOS TV Player (org.webostv.play), roda como root na TV (Homebrew).
# Iniciado por /var/lib/webosbrew/init.d/62-webostv-play-update. Distribuído pelo homeserver
# (GET /api/play/app/atualizador) e se atualiza sozinho quando o md5 muda.
#
# A cada 5 min (e já quando /tmp/webostv-play-update-now aparece):
#   1. acha o homeserver (último endereço bom → homeserver:8080 → varredura da rede /24);
#   2. GET /api/play/app/versao?versao=<instalada> → {versao, arquivo, md5, atualizador_md5};
#   3. versão publicada diferente da instalada (vale para voltar versão também): baixa, confere o md5
#      e instala pelo safe_install.sh (espera o Player fechar; nunca instala com ele em uso).
# Uso manual: sh atualizador.sh agora   (uma verificação e sai)

DIR=/var/lib/webosbrew/webostv-play
APP=/media/developer/apps/usr/palm/applications/org.webostv.play/appinfo.json
LOG=$DIR/atualizador.log
SRV=$DIR/servidor
EU=$DIR/atualizador.sh
GATILHO=/tmp/webostv-play-update-now
PORTA=8080

log() {
    echo "$(date '+%Y-%m-%d %H:%M:%S') $*" >> "$LOG"
    # log curto: guarda as últimas 200 linhas
    [ "$(wc -l < "$LOG")" -gt 400 ] && tail -n 200 "$LOG" > "$LOG.tmp" && mv "$LOG.tmp" "$LOG"
}

instalada() {
    sed -n 's/.*"version"[^"]*"\([^"]*\)".*/\1/p' "$APP" 2>/dev/null | head -1
}

campo() {   # campo <json> <nome>  (JSON plano, só strings/números)
    printf '%s' "$1" | sed -n "s/.*\"$2\":\"\{0,1\}\([^\",}]*\)\"\{0,1\}.*/\1/p" | head -1
}

saude_ok() {   # saude_ok <base>
    curl -s -m 3 "$1/api/play/saude" 2>/dev/null | grep -q '"app":"casa-play"'
}

meu_prefixo() {
    ( ip -4 addr 2>/dev/null || ifconfig 2>/dev/null ) \
        | sed -n 's/.*inet \(addr:\)\{0,1\}\(192\.168\.[0-9]*\.\)[0-9]*.*/\2/p' | head -1
}

achar_servidor() {
    if [ -s "$SRV" ] && saude_ok "$(cat "$SRV")"; then cat "$SRV"; return 0; fi
    if saude_ok "http://homeserver:$PORTA"; then
        echo "http://homeserver:$PORTA" > "$SRV"; cat "$SRV"; return 0
    fi
    P=$(meu_prefixo)
    [ -n "$P" ] || return 1
    rm -f /tmp/webostv-play-achou
    i=1
    while [ $i -le 254 ]; do
        ( curl -s -m 2 "http://$P$i:$PORTA/api/play/saude" 2>/dev/null | grep -q '"app":"casa-play"' \
            && echo "http://$P$i:$PORTA" > /tmp/webostv-play-achou ) &
        [ $((i % 32)) -eq 0 ] && wait
        [ -s /tmp/webostv-play-achou ] && break
        i=$((i + 1))
    done
    wait
    if [ -s /tmp/webostv-play-achou ]; then
        mv /tmp/webostv-play-achou "$SRV"
        log "homeserver achado em $(cat "$SRV")"
        cat "$SRV"; return 0
    fi
    return 1
}

verificar() {
    B=$(achar_servidor) || { log "homeserver não encontrado"; return 1; }
    V=$(instalada)
    R=$(curl -s -m 10 "$B/api/play/app/versao?versao=$V&atualizador=$(md5sum "$EU" 2>/dev/null | cut -c1-32)")
    PUB=$(campo "$R" versao)
    [ -n "$PUB" ] || return 0                       # nada publicado (ou servidor antigo)

    # o atualizador primeiro: se mudou, troca e recomeça com o novo
    AM=$(campo "$R" atualizador_md5)
    if [ -n "$AM" ] && [ "$AM" != "$(md5sum "$EU" | cut -c1-32)" ]; then
        if curl -s -m 30 -o "$EU.novo" "$B/api/play/app/atualizador" && [ "$(md5sum "$EU.novo" | cut -c1-32)" = "$AM" ]; then
            mv "$EU.novo" "$EU"; chmod 755 "$EU"
            log "atualizador trocado ($AM); reiniciando"
            [ "$MODO" = laco ] && exec setsid nice -n 10 sh "$EU" laco </dev/null >/dev/null 2>&1
        else
            rm -f "$EU.novo"; log "atualizador novo com md5 errado; mantido o atual"
        fi
    fi

    [ "$PUB" = "$V" ] && return 0
    ARQ=$(campo "$R" arquivo); MD5=$(campo "$R" md5)
    log "versão publicada $PUB (instalada $V): baixando $ARQ"
    rm -f /tmp/play-upd.ipk
    if ! curl -s -m 300 -o /tmp/play-upd.ipk "$B/api/play/app/pacote/$ARQ"; then
        log "download falhou"; rm -f /tmp/play-upd.ipk; return 1
    fi
    if [ "$(md5sum /tmp/play-upd.ipk | cut -c1-32)" != "$MD5" ]; then
        log "md5 errado; não instalado"; rm -f /tmp/play-upd.ipk; return 1
    fi
    log "$(sh $DIR/safe_install.sh /tmp/play-upd.ipk 2>&1 | tr '\n' ' ')"
    rm -f /tmp/play-upd.ipk
    log "agora instalada: $(instalada)"
    # avisa o servidor da versão nova
    curl -s -m 10 -o /dev/null "$B/api/play/app/versao?versao=$(instalada)&atualizador=$(md5sum "$EU" | cut -c1-32)"
}

MODO=${1:-laco}
if [ "$MODO" = agora ]; then
    verificar
    exit $?
fi

# laço: um por vez
for p in $(pidof sh 2>/dev/null); do
    [ "$p" = "$$" ] && continue
    grep -q 'atualizador\.sh' /proc/$p/cmdline 2>/dev/null && grep -q 'laco' /proc/$p/cmdline 2>/dev/null && exit 0
done
log "atualizador iniciado (instalada $(instalada))"
sleep 60
while true; do
    verificar
    n=0
    while [ $n -lt 60 ]; do                          # 60 × 5 s = 5 min
        [ -e "$GATILHO" ] && { rm -f "$GATILHO"; break; }
        sleep 5
        n=$((n + 1))
    done
done
