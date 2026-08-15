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
echo ">> [1/10] Modo mantenimiento ON"
php artisan down --retry=15 || true

cd "$APP_ROOT"
echo ">> [2/10] git pull ($BRANCH)"
git fetch origin
git checkout "$BRANCH"
git pull origin "$BRANCH"

cd "$BACKEND"
echo ">> [3/10] Composer (producción, sin dev deps)"
composer install --no-dev --optimize-autoloader --no-interaction

echo ">> [4/10] Frontend builds (si aplican)"
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

echo ">> [5/10] Migraciones — base CENTRAL"
php artisan migrate --force

echo ">> [6/10] Migraciones — tenants ya provisionados (core + verticals/{giro})"
php artisan tenants:migrate-verticales || \
  echo "   (revisa 'php artisan tenants:migrate-verticales --help' si pide" \
       "argumentos distintos, o si falló por no haber tenants todavía)"

echo ">> [7/10] Cache de producción"
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
php artisan storage:link 2>/dev/null || true

echo ">> [8/10] Permisos (por si el pull trajo archivos nuevos)"
sudo chown -R "$(whoami):www-data" "$BACKEND/storage" "$BACKEND/bootstrap/cache"
sudo chmod -R 775 "$BACKEND/storage" "$BACKEND/bootstrap/cache"

echo ">> [9/10] Reiniciar PHP-FPM y workers de cola"
sudo systemctl reload php8.5-fpm
sudo supervisorctl restart sistemafe-worker:*

echo ">> [10/10] Modo mantenimiento OFF"
php artisan up

echo ">> Deploy completo. Commit desplegado:"
git -C "$APP_ROOT" rev-parse --short HEAD

echo ">> Revisa storage/logs si algo no luce bien:"
echo "   tail -n 50 $BACKEND/storage/logs/laravel-$(date +%F).log"