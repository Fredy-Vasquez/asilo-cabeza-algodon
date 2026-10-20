#!/usr/bin/env bash
# Configura el correo al familiar en el servidor (Google Apps Script) y reinicia la aplicación.
# Uso:  bash /opt/asilo-cabeza-algodon/deploy/azure/correo.sh "URL_DEL_SCRIPT" "CLAVE_DEL_SCRIPT"
set -euo pipefail
cd /opt/asilo-cabeza-algodon
[ $# -eq 2 ] || { echo 'Uso: correo.sh "https://script.google.com/macros/s/.../exec" "clave"'; exit 1; }
case "$1" in https://script.google.com/*) ;; *) echo "La URL debe empezar con https://script.google.com/"; exit 1;; esac
sed -i -e '/^CORREO_/d' .env
{ echo "CORREO_ACTIVO=true"; echo "CORREO_METODO=apps_script"; echo "CORREO_APPS_SCRIPT_URL=$1"; echo "CORREO_APPS_SCRIPT_CLAVE=$2"; } >> .env
docker compose -f docker-compose.prod.yml up -d web
echo "Correo configurado. Pruébelo en Configuración > Enviar prueba (como admin)."
