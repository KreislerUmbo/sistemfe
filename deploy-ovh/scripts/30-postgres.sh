#!/usr/bin/env bash
# ============================================================================
# 30-postgres.sh
# Configura PostgreSQL para el backend multi-tenant (stancl/tenancy):
#   - Rol de aplicación NO superusuario, pero con CREATEDB (requerido por
#     stancl/tenancy para crear una BD por tenant automáticamente).
#   - Base central db_tenant_central.
#   - pg_hba.conf restringido a localhost (Laravel corre en el mismo server).
#   - Tuning de memoria según la RAM detectada.
#
# NOTA: tu diagnóstico mostró PostgreSQL 18.6 (Ubuntu 18.6-1.pgdg26.04+2)
# instalado, NO la versión 16 — este script detecta la versión real en vez
# de asumirla, así que sirve igual sin editar nada.
#
# IMPORTANTE: este mismo cluster de Postgres puede tener ya bases de datos
# de otros proyectos (tu diagnóstico mostró el puerto 5432 escuchando en
# 0.0.0.0, señal de que ya hay algo usándolo). Este script SOLO agrega un
# rol y una base nueva — no toca roles ni bases existentes — pero antes de
# correrlo confirma con \du y \l qué hay ya ahí para no chocar nombres.
#
# Pide la contraseña de la app por input seguro, no la deja en el historial.
# ============================================================================
set -euo pipefail

# Instala Postgres si no está (lo desinstalaste a propósito para partir
# limpio). Tu servidor ya tiene el repo de apt.postgresql.org configurado,
# así que esto trae la misma versión (18) que tenías antes.
if [ -z "$(ls /etc/postgresql/ 2>/dev/null)" ]; then
  echo ">> PostgreSQL no está instalado — instalando..."
  apt update
  apt -y install postgresql postgresql-contrib
  systemctl enable postgresql
fi

PG_VER=$(ls /etc/postgresql/ 2>/dev/null | sort -V | tail -n1)
if [ -z "$PG_VER" ]; then
  echo "No se encontró /etc/postgresql/<version>/ después de instalar — algo falló arriba."
  exit 1
fi
PG_CONF_DIR="/etc/postgresql/${PG_VER}/main"
echo ">> Versión de PostgreSQL detectada: ${PG_VER} (${PG_CONF_DIR})"

echo ">> Roles existentes en el cluster:"
sudo -u postgres psql -c "\du"
echo ">> Bases de datos existentes en el cluster:"
sudo -u postgres psql -c "\l"
echo
read -rp "¿Los nombres que vas a usar abajo NO chocan con lo de arriba? (si/no): " confirm
[ "$confirm" = "si" ] || { echo "Aborta y revisa antes de continuar. Nada se creó."; exit 1; }

read -rp "Nombre del rol de aplicación (ej. sistemafe_app): " APP_ROLE
read -rsp "Password para ${APP_ROLE}: " APP_PASS; echo
read -rp "Nombre de la base central (ej. db_tenant_central): " CENTRAL_DB

# --- 1. Rol y base central --------------------------------------------------
sudo -u postgres psql <<SQL
DO \$\$
BEGIN
  IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = '${APP_ROLE}') THEN
    CREATE ROLE ${APP_ROLE} LOGIN PASSWORD '${APP_PASS}' CREATEDB;
  ELSE
    ALTER ROLE ${APP_ROLE} PASSWORD '${APP_PASS}' CREATEDB;
  END IF;
END
\$\$;
SQL

sudo -u postgres psql -tc "SELECT 1 FROM pg_database WHERE datname = '${CENTRAL_DB}'" | grep -q 1 || \
  sudo -u postgres psql -c "CREATE DATABASE ${CENTRAL_DB} OWNER ${APP_ROLE} ENCODING 'UTF8' LC_COLLATE='en_US.UTF-8' LC_CTYPE='en_US.UTF-8' TEMPLATE template0;"

echo ">> Rol '${APP_ROLE}' (CREATEDB, NO superusuario) y BD '${CENTRAL_DB}' listos."
echo ">> IMPORTANTE: este rol puede crear/borrar BASES DE DATOS (lo necesita"
echo "   stancl/tenancy), pero no es superusuario de Postgres. No lo uses para"
echo "   nada fuera de la app Laravel."

# --- 2. pg_hba.conf: solo local + localhost, con scram-sha-256 -------------
# ADVERTENCIA: tu servidor tiene Postgres escuchando en 0.0.0.0:5432 (visible
# en el diagnóstico), y hay al menos otro proyecto (evolution-api) en este
# mismo servidor que podría depender de ESE acceso. Este bloque REEMPLAZA
# pg_hba.conf completo por una versión que solo permite localhost — si
# evolution-api (u otra cosa) se conecta a Postgres desde una IP que no sea
# 127.0.0.1/::1 (ej. una red interna de Docker), esto lo va a romper.
HBA="${PG_CONF_DIR}/pg_hba.conf"
cp "$HBA" "$HBA.bak.$(date +%s)"
echo ">> Contenido ACTUAL de pg_hba.conf (líneas activas):"
grep -Ev "^\s*#|^\s*$" "$HBA" || true
echo
echo ">> Se va a REEMPLAZAR por una versión que solo permite localhost."
read -rp "   ¿Confirmaste que nada más depende de acceso no-local a Postgres? (si/no): " hba_ok
if [ "$hba_ok" != "si" ]; then
  echo "Se deja pg_hba.conf sin tocar. Edítalo a mano cuando estés seguro."
else
cat > "$HBA" <<EOF
# TYPE  DATABASE        USER            ADDRESS                 METHOD
local   all             postgres                                peer
local   all             all                                     scram-sha-256
host    all             all             127.0.0.1/32            scram-sha-256
host    all             all             ::1/128                 scram-sha-256
EOF
fi

CONF="${PG_CONF_DIR}/postgresql.conf"
cp "$CONF" "$CONF.bak.$(date +%s)"
sed -i "s/^#\?password_encryption.*/password_encryption = scram-sha-256/" "$CONF"
sed -i "s/^#\?listen_addresses.*/listen_addresses = 'localhost'/" "$CONF"

# --- 3. Tuning de memoria según RAM realmente disponible --------------------
# Este servidor comparte RAM con otros servicios (evolution-api, etc.), así
# que basamos el tuning en memoria DISPONIBLE ahora mismo, no en el total —
# usar el total en un servidor compartido sobreestima lo que le queda a
# Postgres y puede llevar a que el kernel mate procesos por falta de memoria.
RAM_TOTAL_MB=$(free -m | awk '/^Mem:/{print $2}')
RAM_AVAIL_MB=$(free -m | awk '/^Mem:/{print $7}')
if [ "$RAM_AVAIL_MB" -lt 1024 ]; then
  echo ">> ADVERTENCIA: solo ${RAM_AVAIL_MB}MB disponibles de ${RAM_TOTAL_MB}MB totales."
  echo "   El tuning va a quedar conservador. Si Postgres necesita más para"
  echo "   tu carga real, la respuesta correcta es más RAM en el plan, no"
  echo "   forzar shared_buffers más alto de lo que el servidor realmente tiene."
fi
RAM_MB=$RAM_AVAIL_MB
SHARED_BUFFERS_MB=$(( RAM_MB / 4 )); [ "$SHARED_BUFFERS_MB" -lt 64 ] && SHARED_BUFFERS_MB=64
EFFECTIVE_CACHE_MB=$(( RAM_MB * 3 / 4 )); [ "$EFFECTIVE_CACHE_MB" -lt 128 ] && EFFECTIVE_CACHE_MB=128
WORK_MEM_MB=$(( RAM_MB / 64 )); [ "$WORK_MEM_MB" -lt 4 ] && WORK_MEM_MB=4
MAINT_WORK_MEM_MB=$(( RAM_MB / 16 )); [ "$MAINT_WORK_MEM_MB" -lt 64 ] && MAINT_WORK_MEM_MB=64

sed -i \
  -e "s/^shared_buffers.*/shared_buffers = ${SHARED_BUFFERS_MB}MB/" \
  -e "s/^#\?effective_cache_size.*/effective_cache_size = ${EFFECTIVE_CACHE_MB}MB/" \
  -e "s/^#\?work_mem.*/work_mem = ${WORK_MEM_MB}MB/" \
  -e "s/^#\?maintenance_work_mem.*/maintenance_work_mem = ${MAINT_WORK_MEM_MB}MB/" \
  -e "s/^#\?max_connections.*/max_connections = 100/" \
  -e "s/^#\?random_page_cost.*/random_page_cost = 1.1/" \
  -e "s/^#\?effective_io_concurrency.*/effective_io_concurrency = 200/" \
  -e "s/^#\?wal_compression.*/wal_compression = on/" \
  -e "s/^#\?checkpoint_completion_target.*/checkpoint_completion_target = 0.9/" \
  "$CONF"

echo ">> Tuning aplicado (RAM detectada: ${RAM_MB}MB):"
echo "   shared_buffers=${SHARED_BUFFERS_MB}MB effective_cache_size=${EFFECTIVE_CACHE_MB}MB work_mem=${WORK_MEM_MB}MB"
echo "   NOTA: random_page_cost=1.1 asume disco SSD/NVMe (típico en OVH). Si tu"
echo "   plan usa disco rotacional, súbelo a 2.0-4.0."
echo "   max_connections=100: con muchos tenants concurrentes evalúa sumar"
echo "   PgBouncer más adelante en vez de subir este número a lo bruto."

systemctl restart postgresql

echo ">> Verificación:"
sudo -u postgres psql -c "\du" | grep "$APP_ROLE" || true
sudo -u postgres psql -c "\l" | grep "$CENTRAL_DB" || true
