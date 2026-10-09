#!/usr/bin/env bash
# ============================================================================
# backup-offsite.sh
# Copia FUERA del servidor (Backblaze B2), cifrada, de:
#   1. los dumps de Postgres que deja backup-postgres.sh (central + tenants +
#      globals);
#   2. un .tar.gz del storage de Laravel (logos, membretes, fotos,
#      certificados SUNAT por tenant, backups del panel superadmin) + el .env
#      (sin el APP_KEY no se pueden leer los datos que Laravel guarda
#      cifrados; sin las credenciales no se levanta el servidor nuevo).
#
# Por qué (09-oct-2026): hasta hoy el backup vivía en el MISMO disco que la
# base. Si el VPS se pierde, se borra o lo compromete un atacante con root,
# se iban la base y el backup juntos.
#
# Protecciones:
#   - Cifrado del lado del servidor ANTES de subir (remote "crypt" de rclone):
#     Backblaze solo ve archivos ilegibles. Las 2 contraseñas del cifrado
#     tienen que estar guardadas FUERA del servidor (gestor de contraseñas):
#     sin ellas, la copia no sirve para nada.
#   - Bucket con Object Lock (retención 30 días, modo Compliance): cada
#     archivo subido no se puede borrar ni modificar durante 30 días, ni
#     siquiera con la llave que vive en este servidor.
#   - `rclone copy --immutable`: nunca borra ni reescribe nada en el destino
#     (no usar `sync`, que borraría allá lo que se borró acá).
#
# Instalación y restauración: GUIA-DESPLIEGUE-PRODUCCION.md, Fase 8.
# Cron (crontab de ROOT), después de backup-postgres.sh:
#   0 4 * * * /var/backups/sistemafe/scripts/backup-offsite.sh >> /var/log/backup-offsite.log 2>&1
# ============================================================================
set -euo pipefail

# ── Configuración ───────────────────────────────────────────────────────────
APP_DIR="${APP_DIR:-/home/umbo/sistemfe/api-sistema-fe}"
PG_DIR="${PG_DIR:-/var/backups/sistemafe/postgres}"
ARCHIVOS_DIR="${ARCHIVOS_DIR:-/var/backups/sistemafe/archivos}"
REMOTO="${REMOTO:-sistemafe-cifrado:}"
RETENCION_LOCAL_ARCHIVOS_DIAS=7
# Opcional: URL de https://healthchecks.io (gratis) — avisa por correo si una
# noche la copia NO llega. Dejar vacío para no usarlo.
HEALTHCHECK_URL="${HEALTHCHECK_URL:-}"
# ────────────────────────────────────────────────────────────────────────────

FECHA=$(date +%F_%H%M%S)
log() { echo "[$(date '+%F %T')] $*"; }

avisar() {
  [ -n "$HEALTHCHECK_URL" ] || return 0
  curl -fsS -m 10 --retry 3 "${HEALTHCHECK_URL}$1" >/dev/null || true
}
trap 'log "ERROR: la copia offsite falló (línea $LINENO)"; avisar /fail' ERR

avisar /start

[ -d "$APP_DIR/storage" ] || { log "ERROR: no existe $APP_DIR/storage (¿APP_DIR correcto?)"; exit 1; }
[ -d "$PG_DIR" ] || { log "ERROR: no existe $PG_DIR (¿corrió backup-postgres.sh?)"; exit 1; }

# 1. Paquete del storage + .env (framework/ y logs/ se regeneran solos)
mkdir -p "$ARCHIVOS_DIR"
chmod 700 "$ARCHIVOS_DIR"
PAQUETE="$ARCHIVOS_DIR/storage-y-env_${FECHA}.tar.gz"
log "Empaquetando storage + .env -> $PAQUETE"
tar -czf "$PAQUETE" -C "$APP_DIR" \
  --exclude='storage/framework' --exclude='storage/logs' \
  storage .env
chmod 600 "$PAQUETE"
find "$ARCHIVOS_DIR" -type f -name 'storage-y-env_*.tar.gz' -mtime +${RETENCION_LOCAL_ARCHIVOS_DIAS} -delete

# 2. Subir (cifrado). --min-age evita subir un dump que se está escribiendo.
log "Subiendo dumps de Postgres a ${REMOTO}postgres"
rclone copy "$PG_DIR" "${REMOTO}postgres" --immutable --min-age 2m --transfers 4
log "Subiendo storage + .env a ${REMOTO}archivos"
rclone copy "$ARCHIVOS_DIR" "${REMOTO}archivos" --immutable --transfers 2

# 3. Verificar que lo de hoy está allá (no alcanza con que rclone no falle)
HOY=$(date +%F)
EN_DESTINO=$(rclone lsf "${REMOTO}postgres" --include "*_${HOY}_*" | wc -l)
LOCALES=$(find "$PG_DIR" -maxdepth 1 -type f -name "*_${HOY}_*" -mmin +2 | wc -l)
if [ "$EN_DESTINO" -lt "$LOCALES" ]; then
  log "ERROR: en el destino hay $EN_DESTINO dumps de hoy y en local $LOCALES"
  avisar /fail
  exit 1
fi
rclone lsf "${REMOTO}archivos" --include "storage-y-env_${FECHA}.tar.gz" | grep -q . \
  || { log "ERROR: el paquete de storage no aparece en el destino"; avisar /fail; exit 1; }

log "Copia offsite completa: $EN_DESTINO dumps de hoy + storage-y-env_${FECHA}.tar.gz"
avisar ""
