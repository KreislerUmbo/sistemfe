#!/usr/bin/env bash
# ============================================================================
# 70-primer-deploy.sh
# Primer despliegue de sistemafe (una sola vez). Los despliegues siguientes
# usan deploy.sh dentro del repo.
#
# ESTRUCTURA REAL confirmada por diagnóstico: es un MONOREPO en
#   /var/www/html/sistemfe/
#     ├── api-sistema-fe/     <- backend Laravel (composer.json, artisan)
#     └── admin-start-kit/    <- frontend (dashboard/admin)
# El repo YA está clonado ahí (dueño actual: www-data). Este script hace
# `git pull` si ya existe, o clona si no — no asume que está vacío.
#
# Correr como tu usuario habitual con sudo (umbo), no como root.
# ============================================================================
set -euo pipefail

APP_ROOT="/var/www/html/sistemfe"
BACKEND="$APP_ROOT/api-sistema-fe"
FRONTEND="$APP_ROOT/admin-start-kit"
APP_USER="$(whoami)"   # umbo

# --- 1. Repo: pull si ya existe, clonar si no -------------------------------
if [ -d "$APP_ROOT/.git" ]; then
  echo ">> Ya existe un repo en $APP_ROOT — actualizando con git pull."
  echo ">> Antes de continuar, confirma que esto es lo que quieres: si este"
  echo "   checkout es una versión vieja/abandonada, avisa antes de seguir"
  echo "   (puede que quieras borrar todo y clonar limpio en su lugar)."
  sudo git -C "$APP_ROOT" remote -v
  sudo git -C "$APP_ROOT" log --oneline -5
  read -rp "   ¿Confirmas 'git pull' sobre este checkout? (si/no): " ok
  [ "$ok" = "si" ] || { echo "Cancelado. Nada se tocó."; exit 1; }
  sudo git -C "$APP_ROOT" pull
else
  REPO_URL="${1:?Uso: ./70-primer-deploy.sh git@github.com:tu-org/sistemfe.git}"
  # Deploy key de solo lectura, no tu credencial personal:
  #   ssh-keygen -t ed25519 -C "sistemafe-ovh" -f ~/.ssh/id_ed25519_deploy
  #   cat ~/.ssh/id_ed25519_deploy.pub   # pegar en GitHub/GitLab como Deploy Key
  sudo mkdir -p "$APP_ROOT"
  sudo chown "$APP_USER:$APP_USER" "$APP_ROOT"
  git clone "$REPO_URL" "$APP_ROOT"
fi

# --- 2. Backend: dependencias -----------------------------------------------
cd "$BACKEND"
composer install --no-dev --optimize-autoloader --no-interaction

# --- 3. .env del backend -----------------------------------------------------
if [ ! -f .env ]; then
  cp .env.example .env
  echo ">> .env creado desde .env.example — EDÍTALO AHORA con:"
  echo "   nano $BACKEND/.env"
  echo
  echo "   Variables mínimas a revisar (ver checklist completo en la guía):"
  cat <<'EOF'
   APP_ENV=production
   APP_DEBUG=false
   APP_URL=http://149.56.128.92     # cámbialo a https://TUDOMINIO.com cuando tengas dominio

   DB_CONNECTION=pgsql
   DB_HOST=127.0.0.1
   DB_PORT=5432
   DB_DATABASE=db_tenant_central
   DB_USERNAME=sistemafe_app
   DB_PASSWORD=<el password que pusiste en 30-postgres.sh>

   CACHE_STORE=redis
   SESSION_DRIVER=redis
   QUEUE_CONNECTION=redis
   REDIS_HOST=127.0.0.1
   REDIS_PASSWORD=<el password que pusiste en 40-redis.sh>
   REDIS_PORT=6379

   LOG_CHANNEL=daily
   LOG_LEVEL=warning

   # Requeridas por CentralUserSeeder (crea el usuario admin del panel
   # central/central-panel — sin esto el seeder falla más abajo):
   CENTRAL_ADMIN_EMAIL=tu-email@ejemplo.com
   CENTRAL_ADMIN_PASSWORD=<password seguro, generar con: openssl rand -base64 24>
   CENTRAL_ADMIN_NAME="Nombre Apellido"
EOF
  read -rp "Presiona Enter cuando termines de editar .env..." _
fi

php artisan key:generate --force

# --- 4. Frontend: dependencias y build ---------------------------------------
if [ -f "$FRONTEND/package.json" ]; then
  cd "$FRONTEND"
  npm ci
  npm run build
else
  echo ">> No se encontró package.json en $FRONTEND — revisa si el build ya"
  echo "   viene hecho (carpeta dist/) o si el frontend vive en otro lado."
fi

# --- 5. Permisos: solo storage/ y bootstrap/cache son escribibles ----------
sudo chown -R "$APP_USER:www-data" "$APP_ROOT"
sudo find "$APP_ROOT" -type f -exec chmod 644 {} \;
sudo find "$APP_ROOT" -type d -exec chmod 755 {} \;
sudo chmod -R 775 "$BACKEND/storage" "$BACKEND/bootstrap/cache"
sudo chown -R "$APP_USER:www-data" "$BACKEND/storage" "$BACKEND/bootstrap/cache"
# NUNCA uses chmod -R 777. www-data (nginx/php-fpm) solo necesita grupo, no "world".

# --- 6. Migraciones de la base CENTRAL ---------------------------------------
# Confirmado con `config/tenancy.php` + `tenants:migrate-verticales`: las
# migraciones de cada tenant (tenant/core, tenant/verticals/{giro}) NO se
# corren acá — se aplican solas cuando creas un tenant con `tenants:provision`,
# o con `tenants:migrate-verticales` para tenants ya existentes. Esto de acá
# es SOLO la base central (tenants, domains, catálogos compartidos, planes).
cd "$BACKEND"
php artisan migrate --force

php artisan storage:link

# --- 6b. Roles y usuario admin del panel central -----------------------------
# CentralRoleSeeder crea los roles (superadmin/soporte/solo-lectura) y
# CentralUserSeeder crea el usuario admin usando CENTRAL_ADMIN_EMAIL/
# PASSWORD/NAME del .env (paso 3) — sin esto no vas a poder entrar a
# central-panel (admin.tudominio.com) después del deploy.
php artisan db:seed --class=CentralRoleSeeder --force
php artisan db:seed --class=CentralUserSeeder --force

# --- 7. Cache de producción --------------------------------------------------
php artisan config:cache
php artisan route:cache
php artisan view:cache

echo ">> Primer deploy completo en $APP_ROOT"
echo ">> Siguiente:"
echo "   1. Configura el cron del scheduler y supervisor (ver guía)."
echo "   2. Si central-panel vive en una carpeta separada del monorepo (no"
echo "      admin-start-kit), hace falta compilarlo y darle su propio vhost"
echo "      de nginx — ver config/nginx-admin-panel.conf."
echo "   3. Entra a central-panel (admin.tudominio.com) con"
echo "      CENTRAL_ADMIN_EMAIL/PASSWORD y crea tus tenants desde ahí (NO con"
echo "      'php artisan tenants:provision' — ese comando es solo para uso"
echo "      manual/soporte, el flujo real es vía el panel)."
echo "   4. Para los próximos despliegues de código, usa ./deploy.sh."
