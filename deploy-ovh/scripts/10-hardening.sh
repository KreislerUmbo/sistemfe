#!/usr/bin/env bash
# ============================================================================
# 10-hardening.sh
# Hardening base del servidor Ubuntu Server 26.04 en OVH.
# EJECUTAR COMO root o con sudo. Revisa cada sección antes de correr:
# el bloque de SSH puede dejarte fuera si no tienes ya una llave configurada.
# ============================================================================
set -euo pipefail

echo ">> Antes de continuar, confirma que puedes entrar por llave SSH (no solo"
echo "   password), porque este script puede desactivar el login por password."
read -rp "   ¿Ya probaste conectarte con una clave SSH nueva en OTRA terminal? (si/no): " ok
[ "$ok" = "si" ] || { echo "Aborta y configura primero tu clave SSH. Nada se ejecutó."; exit 1; }

# ---------------------------------------------------------------------------
# 1. Actualizar sistema
# ---------------------------------------------------------------------------
apt update && apt -y upgrade

# ---------------------------------------------------------------------------
# 2. Usuario operativo
# ---------------------------------------------------------------------------
# Decisión: seguimos usando tu usuario actual ('umbo', ya con sudo y SSH por
# llave funcionando) para todo — administración y despliegue de la app. No
# se crea un usuario 'deploy' separado. Si más adelante sumas gente al
# equipo y quieres aislar el despliegue, ese es el momento de revisar esto.
DEPLOY_USER="${SUDO_USER:-$USER}"
echo ">> Usuario operativo: $DEPLOY_USER (ya existente, no se crea ninguno nuevo)"
id "$DEPLOY_USER" >/dev/null 2>&1 || { echo "ERROR: el usuario $DEPLOY_USER no existe"; exit 1; }

# ---------------------------------------------------------------------------
# 3. SSH: solo llave, sin root, puerto (opcional)
# ---------------------------------------------------------------------------
SSHD=/etc/ssh/sshd_config
cp "$SSHD" "$SSHD.bak.$(date +%s)"
sed -i \
  -e 's/^#\?PermitRootLogin.*/PermitRootLogin no/' \
  -e 's/^#\?PasswordAuthentication.*/PasswordAuthentication no/' \
  -e 's/^#\?PubkeyAuthentication.*/PubkeyAuthentication yes/' \
  -e 's/^#\?KbdInteractiveAuthentication.*/KbdInteractiveAuthentication no/' \
  -e 's/^#\?X11Forwarding.*/X11Forwarding no/' \
  -e 's/^#\?MaxAuthTries.*/MaxAuthTries 4/' \
  "$SSHD"
systemctl reload ssh

# ---------------------------------------------------------------------------
# 4. Firewall (ufw)
# ---------------------------------------------------------------------------
apt -y install ufw
ufw default deny incoming
ufw default allow outgoing
ufw allow OpenSSH
ufw allow 80/tcp
ufw allow 443/tcp
ufw --force enable
ufw status verbose

# ---------------------------------------------------------------------------
# 5. Fail2ban
# ---------------------------------------------------------------------------
apt -y install fail2ban
cat > /etc/fail2ban/jail.local <<'EOF'
[DEFAULT]
bantime  = 1h
findtime = 10m
maxretry = 5
backend  = systemd

[sshd]
enabled = true

[nginx-http-auth]
enabled = true

[nginx-limit-req]
enabled = true
EOF
systemctl enable --now fail2ban
systemctl restart fail2ban

# ---------------------------------------------------------------------------
# 6. Actualizaciones de seguridad automáticas
# ---------------------------------------------------------------------------
apt -y install unattended-upgrades
dpkg-reconfigure -f noninteractive unattended-upgrades

# ---------------------------------------------------------------------------
# 7. Timezone y hora
# ---------------------------------------------------------------------------
timedatectl set-timezone America/Lima
apt -y install chrony
systemctl enable --now chrony

# ---------------------------------------------------------------------------
# 8. Swap (si el servidor tiene poca RAM, ej. <=4GB)
# ---------------------------------------------------------------------------
if ! swapon --show | grep -q '.'; then
  RAM_MB=$(free -m | awk '/^Mem:/{print $2}')
  if [ "$RAM_MB" -le 4096 ]; then
    fallocate -l 2G /swapfile
    chmod 600 /swapfile
    mkswap /swapfile
    swapon /swapfile
    echo '/swapfile none swap sw 0 0' >> /etc/fstab
    sysctl -w vm.swappiness=10
    echo 'vm.swappiness=10' >> /etc/sysctl.conf
    echo ">> Swap de 2G creado (RAM <= 4GB detectada)."
  fi
fi

echo ">> Hardening base completo. Verifica en OTRA terminal que:"
echo "   - Puedes entrar por SSH con llave como '$DEPLOY_USER'"
echo "   - 'ssh root@servidor' YA NO funciona"
echo "   - 'ufw status' muestra solo 22/80/443 abiertos"
