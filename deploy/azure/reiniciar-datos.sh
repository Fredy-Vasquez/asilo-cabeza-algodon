#!/usr/bin/env bash
# Borra la base de datos del servidor y la vuelve a crear con los datos de ejemplo de schema.sql.
# ¡Se pierde todo lo registrado! Útil antes de una demostración o de las pruebas del SQA.
# Antes de borrar se guarda un respaldo en /opt/asilo-respaldos (por si acaso).
# Solo se borra el volumen de la base de datos: el certificado HTTPS y la configuración del correo se conservan.
set -euo pipefail
cd /opt/asilo-cabeza-algodon
COMPOSE="docker compose -f docker-compose.prod.yml"
bash deploy/azure/respaldo.sh || echo "AVISO: no se pudo respaldar antes de reiniciar (se continúa)."
VOLUMEN="$($COMPOSE config --format json 2>/dev/null | grep -o '"name": *"[^"]*datos_asilo"' | head -1 | sed 's/.*"\([^"]*\)"$/\1/')"
[ -n "$VOLUMEN" ] || VOLUMEN="asilo-cabeza-algodon-prod_datos_asilo"
$COMPOSE stop web db
$COMPOSE rm -f db
docker volume rm "$VOLUMEN"
$COMPOSE up -d
echo "Base de datos reiniciada con los datos de ejemplo (el certificado HTTPS y el correo se conservaron)."
