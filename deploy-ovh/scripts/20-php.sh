#!/usr/bin/env bash
# ============================================================================
# 20-php.sh
# Instala PHP-FPM 8.5 (misma versión que ya trae tu PHP-CLI en Ubuntu 26.04)
# + extensiones necesarias para Laravel 12, y aplica tuning básico de
# opcache y del pool www.
#
# NOTA: se evaluó instalar 8.3 en su lugar (por el límite <8.5.0 que impone
# phpoffice/phpspreadsheet vía maatwebsite/excel), pero el PPA ondrej/php
# todavía NO soporta Ubuntu 26.04 "resolute" (release muy nuevo, issues
# abiertas sin resolver a la fecha) — instalarlo así falla con 404. El fix
# se maneja del lado de la app (actualizar maatwebsite/excel a una versión
# que soporte PHP 8.5), no del sistema operativo. Este script se queda en
# 8.5 nativo.
#
# NOTA (post-despliegue real): se agregó php${PHP_VER}-xsl a la lista de
# paquetes — sin ella, php-fpm arranca igual pero deja un warning en
# journalctl sobre la extensión xsl faltante.
# ============================================================================
set -euo pipefail

PHP_VER="8.5"

apt update
apt -y install software-properties-common ca-certificates lsb-release apt-transport-https

# NOTA: opcache viene integrado en el paquete base de PHP 8.5 en Ubuntu
# 26.04 (ya lo viste activo en `php -v` antes de instalar nada) — no existe
# como paquete "php8.5-opcache" separado, así que no lo pedimos aparte.
#
# Instalamos uno por uno (no todos en un solo `apt install`) para que si
# algún nombre de paquete no existe en tu repo, no cancele TODA la
# instalación — solo avisa y sigue con el resto.
PKGS="php${PHP_VER}-fpm php${PHP_VER}-cli php${PHP_VER}-pgsql php${PHP_VER}-mbstring php${PHP_VER}-xml php${PHP_VER}-curl php${PHP_VER}-zip php${PHP_VER}-bcmath php${PHP_VER}-gd php${PHP_VER}-intl php${PHP_VER}-redis php${PHP_VER}-readline php${PHP_VER}-xsl"
FAILED=""
for pkg in $PKGS; do
  apt -y install "$pkg" || FAILED="$FAILED $pkg"
done
if [ -n "$FAILED" ]; then
  echo ">> ADVERTENCIA: no se pudieron instalar estos paquetes:$FAILED"
  echo "   Revisa el nombre exacto con: apt-cache search php${PHP_VER} | grep -i <extensión>"
fi

PHP_INI="/etc/php/${PHP_VER}/fpm/php.ini"
cp "$PHP_INI" "$PHP_INI.bak.$(date +%s)"

# --- Ajustes de producción en php.ini ---
sed -i \
  -e 's/^expose_php.*/expose_php = Off/' \
  -e 's/^memory_limit.*/memory_limit = 256M/' \
  -e 's/^upload_max_filesize.*/upload_max_filesize = 25M/' \
  -e 's/^post_max_size.*/post_max_size = 25M/' \
  -e 's/^max_execution_time.*/max_execution_time = 60/' \
  -e 's/^;date.timezone.*/date.timezone = America\/Lima/' \
  -e 's/^display_errors.*/display_errors = Off/' \
  -e 's/^log_errors.*/log_errors = On/' \
  "$PHP_INI"

# --- Opcache (crítico para el rendimiento de Laravel) ---
cat >> "$PHP_INI" <<'EOF'

; --- Opcache tuning (producción) ---
opcache.enable=1
opcache.enable_cli=0
opcache.memory_consumption=192
opcache.interned_strings_buffer=16
opcache.max_accelerated_files=20000
opcache.validate_timestamps=0
opcache.save_comments=1
opcache.jit_buffer_size=64M
opcache.jit=1255
EOF
echo ">> NOTA: opcache.validate_timestamps=0 significa que PHP NO detecta"
echo "   cambios de código solos. Cada deploy debe hacer:"
echo "   sudo systemctl reload php${PHP_VER}-fpm"

# --- Pool www: tuning dinámico según CPU/RAM disponible (no total) ---
POOL="/etc/php/${PHP_VER}/fpm/pool.d/www.conf"
cp "$POOL" "$POOL.bak.$(date +%s)"
CPU=$(nproc)
RAM_TOTAL_MB=$(free -m | awk '/^Mem:/{print $2}')
RAM_AVAIL_MB=$(free -m | awk '/^Mem:/{print $7}')
# Este servidor comparte RAM con otros servicios (evolution-api, postgres,
# nginx, etc.) — usamos memoria DISPONIBLE, no total, y reservamos la mitad
# de esa disponibilidad para el resto del sistema/picos.
# Estimación: ~40MB por worker PHP-FPM promedio en Laravel
MAX_CHILDREN=$(( RAM_AVAIL_MB / 2 / 40 ))
[ "$MAX_CHILDREN" -lt 4 ] && MAX_CHILDREN=4
if [ "$RAM_AVAIL_MB" -lt 1024 ]; then
  echo ">> ADVERTENCIA: solo ${RAM_AVAIL_MB}MB de RAM disponible ahora mismo."
  echo "   pm.max_children va a quedar bajo (${MAX_CHILDREN}). Si esto no alcanza"
  echo "   para tu tráfico real, la opción correcta es más RAM en el plan OVH,"
  echo "   no forzar un número más alto aquí (te quedarías sin memoria y el"
  echo "   OOM killer empieza a matar procesos al azar, incluido Postgres)."
fi
START_SERVERS=$(( MAX_CHILDREN / 4 )); [ "$START_SERVERS" -lt 2 ] && START_SERVERS=2
MIN_SPARE=$(( START_SERVERS ))
MAX_SPARE=$(( MAX_CHILDREN / 2 )); [ "$MAX_SPARE" -lt "$START_SERVERS" ] && MAX_SPARE=$START_SERVERS

sed -i \
  -e "s/^pm = .*/pm = dynamic/" \
  -e "s/^pm.max_children = .*/pm.max_children = ${MAX_CHILDREN}/" \
  -e "s/^pm.start_servers = .*/pm.start_servers = ${START_SERVERS}/" \
  -e "s/^pm.min_spare_servers = .*/pm.min_spare_servers = ${MIN_SPARE}/" \
  -e "s/^pm.max_spare_servers = .*/pm.max_spare_servers = ${MAX_SPARE}/" \
  -e "s/^;pm.max_requests = .*/pm.max_requests = 500/" \
  "$POOL"

echo "listen = /run/php/php${PHP_VER}-fpm.sock" >> /tmp/_check_listen.txt
grep -q "^listen = /run/php/php${PHP_VER}-fpm.sock" "$POOL" || \
  sed -i "s#^listen = .*#listen = /run/php/php${PHP_VER}-fpm.sock#" "$POOL"

echo ">> pool www.conf: CPU=${CPU} RAM_disponible=${RAM_AVAIL_MB}MB (de ${RAM_TOTAL_MB}MB totales) -> max_children=${MAX_CHILDREN}"

systemctl enable "php${PHP_VER}-fpm"
systemctl restart "php${PHP_VER}-fpm"
php -v

echo ">> Composer:"
if ! command -v composer >/dev/null 2>&1; then
  php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
  php composer-setup.php --install-dir=/usr/local/bin --filename=composer
  rm -f composer-setup.php
fi
composer --version
