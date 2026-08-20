#!/usr/bin/env bash
# ============================================================================
# backup-postgres.sh
# Backup diario de TODAS las bases de datos del cluster (central + cada
# tenant), comprimido y con retención. Pensado para correr por cron como
# el usuario 'postgres' o con sudo.
#
# Instalación (crontab de root):
#   crontab -e
#   0 3 * * * /var/backups/sistemafe/scripts/backup-postgres.sh >> /var/log/backup-postgres.log 2>&1
# ============================================================================
set -euo pipefail

BACKUP_DIR="/var/backups/sistemafe/postgres"
RETENTION_DAYS=14
DATE=$(date +%F_%H%M%S)

mkdir -p "$BACKUP_DIR"

# Backup de cada base (excluye plantillas y la BD 'postgres')
DATABASES=$(sudo -u postgres psql -tAc "SELECT datname FROM pg_database WHERE datistemplate = false AND datname <> 'postgres';")

for DB in $DATABASES; do
  OUT="${BACKUP_DIR}/${DB}_${DATE}.sql.gz"
  echo "[$(date)] Backup de ${DB} -> ${OUT}"
  sudo -u postgres pg_dump -Fc "$DB" | gzip > "$OUT"
done

# Backup de roles/globals (usuarios, permisos) — no está dentro de pg_dump por BD
sudo -u postgres pg_dumpall --globals-only | gzip > "${BACKUP_DIR}/globals_${DATE}.sql.gz"

echo "[$(date)] Limpiando backups con más de ${RETENTION_DAYS} días..."
find "$BACKUP_DIR" -type f -name "*.sql.gz" -mtime +${RETENTION_DAYS} -delete

echo "[$(date)] Backup completo. Archivos actuales:"
ls -lh "$BACKUP_DIR" | tail -n 20

# --------------------------------------------------------------------------
# RECOMENDADO: copiar offsite. OVH ofrece Object Storage (S3-compatible).
# Ejemplo con rclone (instalar y configurar `rclone config` una sola vez):
#
#   rclone sync "$BACKUP_DIR" ovh-s3:sistemafe-backups/postgres --min-age 1h
#
# Sin copia offsite, un backup en el mismo disco NO te protege si el
# servidor se pierde completo (falla de disco, borrado accidental, etc.)
# --------------------------------------------------------------------------
