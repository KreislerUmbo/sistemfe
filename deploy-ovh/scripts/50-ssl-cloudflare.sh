#!/usr/bin/env bash
# ============================================================================
# 50-ssl-cloudflare.sh
# Emite UN certificado wildcard (umbosystem.com + *.umbosystem.com) de Let's
# Encrypt validando por DNS-01 contra Cloudflare, y deja la renovación
# automática + recarga de nginx lista.
#
# Por qué DNS-01: un wildcard no se puede validar por HTTP. Por qué Cloudflare:
# es donde está el DNS del dominio (NS nile/miki.ns.cloudflare.com, verificado
# 2026-09-21) — NO OVH, como decía la Fase 6 original de la guía.
#
# Requisito previo (una sola vez, en el panel de Cloudflare):
#   My Profile > API Tokens > Create Token > plantilla "Edit zone DNS"
#   Permisos: Zone / DNS / Edit   |   Zone Resources: Include > Specific zone
#   > umbosystem.com.  Copia el token (solo se muestra una vez).
#
# Uso:
#   sudo ./50-ssl-cloudflare.sh tu-correo@dominio.com
# El token se pide por teclado SIN eco (no queda en el historial de bash).
#
# Este script NO toca nginx — solo emite el certificado. Cambiar nginx a
# HTTPS es un paso aparte (con diff antes de copiar, ver GUIA).
# ============================================================================
set -euo pipefail

EMAIL="${1:?Uso: sudo $0 tu-correo@dominio.com}"
DOMINIO="umbosystem.com"
CRED="/root/.secrets/cloudflare.ini"

if [ "$(id -u)" -ne 0 ]; then
  echo "Corre con sudo." >&2; exit 1
fi

echo ">> [1/4] Instalando certbot + plugin de Cloudflare"
apt-get update -qq
apt-get install -y certbot python3-certbot-dns-cloudflare

echo ">> [2/4] Guardando el token de Cloudflare (chmod 600, solo root)"
if [ -f "$CRED" ]; then
  echo "   $CRED ya existe — se reutiliza (bórralo a mano si quieres cambiar el token)"
else
  read -r -s -p "   Pega el token de Cloudflare (no se ve al escribir): " CF_TOKEN
  echo
  [ -n "$CF_TOKEN" ] || { echo "Token vacío." >&2; exit 1; }
  install -d -m 700 /root/.secrets
  umask 077
  printf 'dns_cloudflare_api_token = %s\n' "$CF_TOKEN" > "$CRED"
  chmod 600 "$CRED"
  unset CF_TOKEN
fi

echo ">> [3/4] Emitiendo el certificado wildcard (puede tardar ~1 minuto)"
certbot certonly \
  --dns-cloudflare \
  --dns-cloudflare-credentials "$CRED" \
  --dns-cloudflare-propagation-seconds 30 \
  -d "$DOMINIO" -d "*.$DOMINIO" \
  --email "$EMAIL" --agree-tos --no-eff-email \
  --keep-until-expiring --non-interactive

echo ">> [4/4] Hook de renovación: recarga nginx cuando el certificado se renueve"
install -d /etc/letsencrypt/renewal-hooks/deploy
cat > /etc/letsencrypt/renewal-hooks/deploy/reload-nginx.sh <<'EOF'
#!/bin/sh
systemctl reload nginx
EOF
chmod +x /etc/letsencrypt/renewal-hooks/deploy/reload-nginx.sh

echo
echo ">> Verificación:"
certbot certificates
echo
echo ">> Prueba en seco de la renovación (no emite nada real):"
certbot renew --dry-run
echo
systemctl list-timers 2>/dev/null | grep -i certbot || \
  echo "   (no se vio el timer de certbot — revisa: systemctl list-timers | grep certbot)"
echo
echo ">> Listo. El certificado quedó en /etc/letsencrypt/live/$DOMINIO/"
echo "   Siguiente paso: pasar nginx a HTTPS (diff antes de copiar)."
