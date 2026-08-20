#!/usr/bin/env bash
# ============================================================================
# 40-redis.sh
# Instala y asegura Redis para usarlo como cache/sesión/cola de Laravel.
# No viene instalado por defecto en tu servidor; es la pieza que falta para
# que las colas de tenant-provisioning, notificaciones y PDFs de cotización
# no se procesen de forma síncrona (bloqueando al usuario).
# ============================================================================
set -euo pipefail

apt update
apt -y install redis-server

REDIS_PASS="${1:-}"
if [ -z "$REDIS_PASS" ]; then
  read -rsp "Password para Redis (requirepass): " REDIS_PASS; echo
fi

CONF="/etc/redis/redis.conf"
cp "$CONF" "$CONF.bak.$(date +%s)"

sed -i \
  -e "s/^# *bind .*/bind 127.0.0.1 -::1/" \
  -e "s/^bind .*/bind 127.0.0.1 -::1/" \
  -e "s/^# *requirepass .*/requirepass ${REDIS_PASS}/" \
  -e "s/^protected-mode .*/protected-mode yes/" \
  -e "s/^supervised .*/supervised systemd/" \
  -e "s/^# *maxmemory-policy .*/maxmemory-policy allkeys-lru/" \
  "$CONF"

# maxmemory: usa hasta 1/8 de la RAM DISPONIBLE (no total) — este servidor
# comparte memoria con otros servicios (evolution-api, etc.), mínimo 64MB
RAM_AVAIL_MB=$(free -m | awk '/^Mem:/{print $7}')
MAXMEM_MB=$(( RAM_AVAIL_MB / 8 )); [ "$MAXMEM_MB" -lt 64 ] && MAXMEM_MB=64
if grep -q "^maxmemory " "$CONF"; then
  sed -i "s/^maxmemory .*/maxmemory ${MAXMEM_MB}mb/" "$CONF"
else
  echo "maxmemory ${MAXMEM_MB}mb" >> "$CONF"
fi

systemctl enable --now redis-server
systemctl restart redis-server

echo ">> Redis configurado: bind localhost, requirepass activo, maxmemory=${MAXMEM_MB}mb (LRU)."
echo ">> Guarda este password para tu .env: REDIS_PASSWORD=${REDIS_PASS}"
echo ">> Prueba: redis-cli -a '${REDIS_PASS}' ping   (debe responder PONG)"
