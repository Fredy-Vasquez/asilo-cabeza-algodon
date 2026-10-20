#!/usr/bin/env bash
# =====================================================================
# Asilo Cabeza de Algodón — instalación en una máquina virtual Ubuntu de Azure
# Uso (como root, por ejemplo desde "Ejecutar comando" del portal de Azure):
#   curl -fsSL https://raw.githubusercontent.com/Fredy-Vasquez/asilo-cabeza-algodon/main/deploy/azure/instalar.sh | bash
# Se puede ejecutar varias veces: si ya está instalado, actualiza el código y reconstruye.
# =====================================================================
set -euo pipefail

REPO="${REPO:-https://github.com/Fredy-Vasquez/asilo-cabeza-algodon.git}"
RAMA="${RAMA:-main}"
DIR="/opt/asilo-cabeza-algodon"
COMPOSE="docker compose -f docker-compose.prod.yml"

paso() { echo; echo "==> $*"; }

paso "1/6 Memoria de intercambio (la VM pequeña tiene 1 GB de RAM)"
if ! swapon --show | grep -q /swapfile; then
    fallocate -l 2G /swapfile && chmod 600 /swapfile && mkswap /swapfile && swapon /swapfile
    grep -q '/swapfile' /etc/fstab || echo '/swapfile none swap sw 0 0' >> /etc/fstab
fi

paso "2/6 Docker"
if ! command -v docker >/dev/null 2>&1; then
    export DEBIAN_FRONTEND=noninteractive
    apt-get update -y
    apt-get install -y ca-certificates curl git
    curl -fsSL https://get.docker.com | sh
fi
systemctl enable --now docker
command -v git >/dev/null 2>&1 || apt-get install -y git

paso "3/6 Código desde GitHub ($REPO, rama $RAMA)"
if [ -d "$DIR/.git" ]; then
    git -C "$DIR" fetch --depth 1 origin "$RAMA"
    git -C "$DIR" reset --hard "origin/$RAMA"
else
    git clone --depth 1 --branch "$RAMA" "$REPO" "$DIR"
fi
cd "$DIR"

paso "4/6 Contraseñas (archivo .env, solo existe en este servidor)"
if [ ! -f .env ]; then
    cat > .env <<ENV
# Generado por instalar.sh el $(date '+%Y-%m-%d %H:%M'). No se sube a GitHub.
DB_ROOT_PASS=$(openssl rand -hex 16)
DB_PASS=$(openssl rand -hex 16)
# Correo al familiar (opcional): use deploy/azure/correo.sh para configurarlo
CORREO_ACTIVO=false
CORREO_METODO=apps_script
CORREO_APPS_SCRIPT_URL=
CORREO_APPS_SCRIPT_CLAVE=
ENV
    chmod 600 .env
    echo "Se creó .env con contraseñas aleatorias."
else
    echo ".env ya existe: se conservan las contraseñas."
fi

paso "5/6 Construyendo y levantando los contenedores (la primera vez tarda 5-10 minutos)"
$COMPOSE up -d --build
docker image prune -f >/dev/null 2>&1 || true

paso "6/6 Verificación"
for i in $(seq 1 30); do
    if curl -fs -o /dev/null http://localhost/login.php; then break; fi
    sleep 5
done
$COMPOSE ps
IP=$(curl -fs --max-time 5 https://api.ipify.org || hostname -I | awk '{print $1}')
if curl -fs -o /dev/null http://localhost/login.php; then
    echo
    echo "LISTO. El sistema está en:  http://$IP/"
    echo "Cuentas de demostración: admin@asilo.com (y las demás) con la contraseña Asilo2026!"
else
    echo "La página todavía no responde. Revise los registros con:"
    echo "  cd $DIR && $COMPOSE logs --tail=50 web db"
    exit 1
fi
