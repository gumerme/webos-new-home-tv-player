#!/bin/sh
# Publica uma versão do WebOS TV Player no homeserver; as TVs instalam sozinhas em até 5 min,
# sempre com o Player fechado (casa/tv/atualizador.sh).
#
#   Do PC (Git Bash):  sh scripts/publicar-player.sh [arquivo.ipk] [notas]
#   Sem arquivo: o .ipk mais novo de player/dist (deste repositório).
#   Voltar versão:     ssh root@homeserver 'docker exec casa-play php bin/play.php app-publicar var/play/app/org.webostv.play_X_all.ipk'
#   Ver as TVs:        ssh root@homeserver 'docker exec casa-play php bin/play.php app-status'
#
# Antes: subir "version" em player/appinfo.json e rodar `npm run package` em player/.
set -e
HS=${HOMESERVER:-root@homeserver}
IPK=${1:-$(ls -t "$(dirname "$0")"/../../player/dist/org.webostv.play_*_all.ipk | head -1)}
NOTAS=${2:-}
[ -f "$IPK" ] || { echo "ipk não encontrado: $IPK"; exit 1; }
NOME=$(basename "$IPK")
ssh "$HS" 'mkdir -p /srv/casa/casa/var/play/app/entrada && chmod 777 /srv/casa/casa/var/play/app /srv/casa/casa/var/play/app/entrada'
scp -q "$IPK" "$HS:/srv/casa/casa/var/play/app/entrada/$NOME"
ssh "$HS" "docker exec casa-play php bin/play.php app-publicar 'var/play/app/entrada/$NOME' --notas '$(printf %s "$NOTAS" | tr -d "'")' && rm -f '/srv/casa/casa/var/play/app/entrada/$NOME' && docker exec casa-play php bin/play.php app-status"
