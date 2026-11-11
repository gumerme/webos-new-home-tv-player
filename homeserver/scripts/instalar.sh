#!/bin/bash
# Prepara /srv/casa: segredos do .env, pastas e config inicial do Homebridge.
# Idempotente: não sobrescreve segredos nem a config do Homebridge já existentes.
set -euo pipefail
cd /srv/casa

aleatorio() { head -c "$1" /dev/urandom | od -An -tx1 | tr -d ' \n'; }

[ -f .env ] || cp .env.example .env
chmod 600 .env
for var in POSTGRES_PASSWORD:24 CASA_TOKEN:24; do
    nome=${var%%:*}; bytes=${var##*:}
    if grep -q "^$nome=$" .env || ! grep -q "^$nome=" .env; then
        grep -q "^$nome=" .env || echo "$nome=" >> .env
        sed -i "s/^$nome=.*/$nome=$(aleatorio "$bytes")/" .env
        echo "gerado: $nome"
    fi
done

mkdir -p matter postgres/data tailscale/state homebridge backup/postgres

if [ ! -f homebridge/config.json ]; then
    token=$(grep '^CASA_TOKEN=' .env | cut -d= -f2)
    # PIN no formato XXX-XX-XXX (evita sequências que a Apple recusa)
    while :; do
        d=$(od -An -N8 -tu1 /dev/urandom | tr -s ' ' '\n' | grep -v '^$' | awk '{printf "%d", $1 % 10}')
        pin="${d:0:3}-${d:3:2}-${d:5:3}"
        case "$pin" in 000-00-000|111-11-111|222-22-222|333-33-333|444-44-444|555-55-555|666-66-666|777-77-777|888-88-888|999-99-999|123-45-678|876-54-321) ;; *) break ;; esac
    done
    mac=$(od -An -N6 -tx1 /dev/urandom | tr -d ' \n' | sed 's/../&:/g; s/:$//' | tr a-f A-F)
    mac="0E${mac:2}"   # endereço "administrado localmente"
    cat > homebridge/config.json <<EOF
{
  "bridge": {
    "name": "Casa",
    "username": "$mac",
    "port": 51826,
    "pin": "$pin"
  },
  "ports": { "start": 52100, "end": 52150 },
  "accessories": [],
  "platforms": [
    { "platform": "config", "name": "Config", "port": 8581 },
    { "platform": "Casa", "name": "Casa", "url": "http://127.0.0.1:8080", "token": "$token", "intervalo": 5 }
  ]
}
EOF
    chmod 600 homebridge/config.json
    echo "homebridge: PIN $pin"
fi

# PIN do Homebridge no .env (o painel mostra em Integrações)
pin=$(grep -o '"pin": *"[0-9-]*"' homebridge/config.json | grep -o '[0-9]\{3\}-[0-9]\{2\}-[0-9]\{3\}' || true)
if [ -n "$pin" ]; then
    grep -q '^HOMEBRIDGE_PIN=' .env || echo 'HOMEBRIDGE_PIN=' >> .env
    sed -i "s/^HOMEBRIDGE_PIN=.*/HOMEBRIDGE_PIN=$pin/" .env
fi
grep -q '^TARIFA_KWH=' .env || echo 'TARIFA_KWH=' >> .env

# Sessões do painel (Apache roda como www-data, uid 33)
mkdir -p casa/var/sessoes
chown -R 33:33 casa/var
chmod 700 casa/var/sessoes

# Instala o plugin local a cada início do container
cat > homebridge/startup.sh <<'EOF'
#!/bin/sh
cd /homebridge && npm install --no-audit --no-fund --install-links /plugins/homebridge-casa >/dev/null 2>&1 \
  && echo "homebridge-casa instalado" || echo "FALHA instalando homebridge-casa"
EOF
chmod 755 homebridge/startup.sh
echo "pronto"
