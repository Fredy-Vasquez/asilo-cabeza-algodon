#!/usr/bin/env bash
# Borra la base de datos del servidor y la vuelve a crear con los datos de ejemplo de schema.sql.
# ¡Se pierde todo lo registrado! Útil antes de una demostración o de las pruebas del SQA.
set -euo pipefail
cd /opt/asilo-cabeza-algodon
docker compose -f docker-compose.prod.yml down -v
docker compose -f docker-compose.prod.yml up -d
echo "Base de datos reiniciada con los datos de ejemplo."
