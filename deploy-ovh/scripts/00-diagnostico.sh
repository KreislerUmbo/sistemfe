#!/usr/bin/env bash
# ============================================================================
# 00-diagnostico.sh
# Diagnóstico inicial del servidor OVH antes de configurar producción.
# No instala ni cambia NADA. Solo reporta. Ejecutar por SSH y pegar la
# salida completa de vuelta para afinar el resto de la guía a tu servidor real.
#
# Uso:
#   chmod +x 00-diagnostico.sh
#   ./00-diagnostico.sh | tee diagnostico-$(date +%F).txt
# ============================================================================
set -uo pipefail

sep() { echo; echo "==================== $1 ===================="; }

echo ">> Este script va a pedir tu password de sudo UNA vez y la cachea unos"
echo "   minutos, para no interrumpir el reporte con prompts repetidos."
sudo -v 2>/dev/null || true

sep "SISTEMA OPERATIVO"
cat /etc/os-release 2>/dev/null
uname -a

sep "RECURSOS: CPU / RAM / DISCO"
echo "-- CPU --"
nproc --all
lscpu 2>/dev/null | grep -E "Model name|CPU\(s\)|Socket"
echo "-- RAM --"
free -h
echo "-- SWAP --"
swapon --show 2>/dev/null || echo "(sin swap configurado)"
echo "-- DISCO --"
df -h /
echo "-- Top 15 procesos por uso de RAM (para ver qué está consumiendo memoria) --"
ps aux --sort=-%mem | head -n 16
echo "-- Docker (evolution-api suele correr en contenedores) --"
if command -v docker >/dev/null 2>&1; then
  sudo docker ps 2>&1
else
  echo "docker no instalado (evolution-api corre nativo, no en contenedor)"
fi

sep "RED / FIREWALL"
ip -4 addr show scope global 2>/dev/null
echo "-- UFW --"
ufw status verbose 2>/dev/null || echo "ufw no instalado o requiere sudo"
echo "-- Puertos en escucha --"
ss -tulpn 2>/dev/null | grep LISTEN

sep "SSH"
grep -E "^Port|^PermitRootLogin|^PasswordAuthentication|^PubkeyAuthentication" /etc/ssh/sshd_config 2>/dev/null
echo "(si no aparece nada, están en el valor por defecto de OpenSSH)"

sep "VERSIONES DE SOFTWARE"
for cmd in git psql redis-server php composer node npm supervisord certbot ufw fail2ban-client; do
  if command -v "$cmd" >/dev/null 2>&1; then
    v=$("$cmd" --version 2>&1 | head -n1)
    printf "%-15s -> %s\n" "$cmd" "$v"
  else
    printf "%-15s -> NO INSTALADO\n" "$cmd"
  fi
done
if command -v nginx >/dev/null 2>&1; then
  printf "%-15s -> %s\n" "nginx" "$(nginx -v 2>&1)"
else
  printf "%-15s -> NO INSTALADO\n" "nginx"
fi
# php-fpm suele instalarse como binario versionado (php-fpm8.5), no "php-fpm"
FPM_BIN=$(command -v php-fpm 2>/dev/null || ls /usr/sbin/php-fpm* 2>/dev/null | head -n1)
if [ -n "${FPM_BIN:-}" ]; then
  printf "%-15s -> %s\n" "php-fpm" "$("$FPM_BIN" -v 2>&1 | head -n1)"
else
  printf "%-15s -> NO INSTALADO\n" "php-fpm"
fi

sep "PHP: MÓDULOS INSTALADOS"
if command -v php >/dev/null 2>&1; then
  php -v
  echo "-- Extensiones relevantes para Laravel --"
  for ext in bcmath ctype curl dom fileinfo json mbstring openssl pdo pdo_pgsql pgsql tokenizer xml zip gd intl redis opcache; do
    php -m 2>/dev/null | grep -iqx "$ext" && echo "  [OK] $ext" || echo "  [FALTA] $ext"
  done
else
  echo "PHP no instalado todavía"
fi

sep "SERVICIOS SYSTEMD RELEVANTES"
for svc in nginx postgresql php8.5-fpm php8.3-fpm php8.2-fpm redis-server supervisor fail2ban ssh; do
  systemctl is-active "$svc" >/dev/null 2>&1 && state="active" || state="inactive/no-existe"
  systemctl is-enabled "$svc" >/dev/null 2>&1 && enabled="enabled" || enabled="disabled/no-existe"
  printf "%-16s active=%-20s enabled=%s\n" "$svc" "$state" "$enabled"
done

sep "POSTGRESQL"
if command -v psql >/dev/null 2>&1; then
  sudo -u postgres psql -c "SELECT version();" 2>/dev/null
  echo "-- Roles existentes --"
  sudo -u postgres psql -c "\du" 2>/dev/null
  echo "-- Bases de datos existentes --"
  sudo -u postgres psql -c "\l" 2>/dev/null
  echo "-- pg_hba.conf (solo líneas activas) --"
  sudo -u postgres psql -t -c "SHOW hba_file;" 2>/dev/null | xargs -r sudo grep -Ev "^\s*#|^\s*$" 2>/dev/null
fi

sep "NGINX: SITIOS CONFIGURADOS"
ls -la /etc/nginx/sites-enabled/ 2>/dev/null
echo "-- Contenido de cada site habilitado --"
for f in /etc/nginx/sites-enabled/*; do
  [ -e "$f" ] || continue
  echo "  ---- $f ----"
  cat "$f" 2>/dev/null | sed 's/^/  /'
done
sudo nginx -t 2>&1

sep "PUERTOS NO ESTÁNDAR EN ESCUCHA (fuera de 22/80/443/5432)"
ss -tulpn 2>/dev/null | grep LISTEN | grep -Ev ":(22|80|443|5432)\s"
echo "(si algo de tu app ya en producción depende de estos puertos hacia"
echo " afuera, dímelo antes de activar el firewall, o el firewall lo bloquea)"

sep "REPOSITORIO GIT (busca el proyecto en ubicaciones comunes)"
for base in /var/www /home/*/apps /home/*/www /srv; do
  sudo find "$base" -maxdepth 3 -name ".git" 2>/dev/null | while read -r gitdir; do
    proj="$(dirname "$gitdir")"
    echo "-- Proyecto encontrado: $proj --"
    is_bare=$(sudo git -C "$proj" rev-parse --is-bare-repository 2>/dev/null || echo "?")
    echo "   ¿repo bare (sin working tree)?: $is_bare"
    sudo git -C "$proj" remote -v 2>/dev/null
    sudo git -C "$proj" branch --show-current 2>/dev/null
    sudo git -C "$proj" log --oneline -3 2>/dev/null
    echo "   contenido de la carpeta (sudo ls):"
    sudo ls -la "$proj" 2>/dev/null | sed 's/^/     /'
    echo "   composer.json: $(sudo test -f "$proj/composer.json" && echo SI || echo no)"
    echo "   artisan:       $(sudo test -f "$proj/artisan" && echo SI || echo no)"
    echo "   package.json:  $(sudo test -f "$proj/package.json" && echo SI || echo no)"
    echo "   .env:          $(sudo test -f "$proj/.env" && echo SI || echo no)"
    echo "   resources/js:  $(sudo test -d "$proj/resources/js" && echo SI || echo no)"
  done
done

sep "USUARIOS Y PERMISOS EN /var/www"
ls -la /var/www 2>/dev/null

sep "FIN DEL DIAGNÓSTICO"
echo "Copia toda esta salida y pégala de vuelta para ajustar la guía a tu servidor real."
