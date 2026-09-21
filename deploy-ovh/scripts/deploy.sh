#!/usr/bin/env bash
# ============================================================================
# deploy.sh
# Script de despliegue repetible para sistemafe. Correr como umbo.
#
# Estructura real (monorepo):
#   /var/www/html/sistemfe/api-sistema-fe    <- backend Laravel
#   /var/www/html/sistemfe/admin-start-kit   <- frontend (dashboard tenant)
#   /var/www/html/sistemfe/central-panel     <- frontend (panel superadmin)
#
# Uso:
#   cd /var/www/html/sistemfe
#   ./deploy.sh [rama]      # por defecto: main
# ============================================================================
set -euo pipefail

BRANCH="${1:-main}"
APP_ROOT="/var/www/html/sistemfe"
BACKEND="$APP_ROOT/api-sistema-fe"
FRONTEND="$APP_ROOT/admin-start-kit"
ADMIN_PANEL="$APP_ROOT/central-panel"

cd "$BACKEND"
echo ">> [1/11] Modo mantenimiento ON"
php artisan down --retry=15 || true

# Salvaguarda: si cualquier paso de acá en adelante falla (set -euo pipefail
# corta el script ahí mismo — composer sin red, git pull con conflicto,
# migración rota, etc.), el paso [11/11] normal de "artisan up" nunca se
# alcanza y el sitio queda colgado en mantenimiento hasta que alguien lo
# note y lo saque a mano. Este trap corre "artisan up" pase lo que pase al
# salir del script (éxito o error) — correrlo dos veces (acá + el paso
# normal de más abajo) es inofensivo, artisan up es idempotente.
trap 'cd "$BACKEND" && php artisan up 2>/dev/null || true' EXIT

cd "$APP_ROOT"
echo ">> [2/11] git pull ($BRANCH)"
git fetch origin
git checkout "$BRANCH"
git pull origin "$BRANCH"

cd "$BACKEND"
echo ">> [3/11] Composer (producción, sin dev deps)"
composer install --no-dev --optimize-autoloader --no-interaction

echo ">> [4/11] Frontend builds (si aplican)"
if [ -f "$FRONTEND/package.json" ]; then
  echo "   -> admin-start-kit"
  (cd "$FRONTEND" && npm ci && npm run build)
else
  echo "   -> admin-start-kit: no se encontró package.json, se omite"
fi
if [ -f "$ADMIN_PANEL/package.json" ]; then
  echo "   -> central-panel"
  (cd "$ADMIN_PANEL" && npm ci && npm run build)
else
  echo "   -> central-panel: no se encontró package.json en $ADMIN_PANEL, se omite"
  echo "      (si central-panel vive en otra ruta, ajusta la variable ADMIN_PANEL arriba)"
fi

echo ">> [5/11] Migraciones — base CENTRAL"
php artisan migrate --force

echo ">> [6/11] Migraciones — tenants ya provisionados (core + verticals/{giro})"
php artisan tenants:migrate-verticales || \
  echo "   (revisa 'php artisan tenants:migrate-verticales --help' si pide" \
       "argumentos distintos, o si falló por no haber tenants todavía)"

# Catálogos que se SIEMBRAN por seeder (no por migración). Este script corre
# migraciones pero no seeders por sí solo — sin este paso, una tabla creada
# por migración queda VACÍA en producción. Caso real (21-sep-2026): el menú
# lateral pasó a armarse desde la tabla central `menu_items`; el deploy la
# creó pero nadie la llenó y todos los usuarios veían solo el Dashboard.
#
# MenuItemsSeeder es idempotente (updateOrCreate por `codigo`), así que
# correrlo en cada deploy es seguro y además propaga ítems de menú nuevos que
# lleguen en releases futuras. Limitación conocida: NO borra ítems que se
# quiten del seeder, solo crea/actualiza.
#
# Si en el futuro otro catálogo se siembra por seeder, agrégalo acá también.
#
# Falla "blanda" a propósito (mismo criterio que el paso 6): un seeder roto
# no debe tumbar el deploy entero, pero el aviso tiene que verse.
echo ">> [7/11] Catálogos sembrados (seeders idempotentes) + caché del menú"
php artisan db:seed --class=MenuItemsSeeder --force || \
  echo "   !! ADVERTENCIA: MenuItemsSeeder falló — el menú lateral puede quedar" \
       "vacío o desactualizado. Corre a mano: php artisan db:seed --class=MenuItemsSeeder --force"
# El resultado de GET /me/menu se cachea hasta 24 h por usuario (un menú vacío
# o viejo se queda pegado). Se limpia el caché central y el de cada tenant.
php artisan cache:clear || true
php artisan tenants:run cache:clear || \
  echo "   (no se pudo limpiar el caché por tenant — el menú se refrescará solo en <24 h)"

echo ">> [8/11] Cache de producción"
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
php artisan storage:link 2>/dev/null || true

echo ">> [9/11] Permisos (por si el pull trajo archivos nuevos)"
sudo chown -R "$(whoami):www-data" "$BACKEND/storage" "$BACKEND/bootstrap/cache"
sudo chmod -R 775 "$BACKEND/storage" "$BACKEND/bootstrap/cache"

echo ">> [10/11] Reiniciar PHP-FPM y workers de cola"
sudo systemctl reload php8.5-fpm
sudo supervisorctl restart sistemafe-worker:*

echo ">> [11/11] Modo mantenimiento OFF"
php artisan up

echo ">> Deploy completo. Commit desplegado:"
git -C "$APP_ROOT" rev-parse --short HEAD

echo ">> Revisa storage/logs si algo no luce bien:"
echo "   tail -n 50 $BACKEND/storage/logs/laravel-$(date +%F).log"