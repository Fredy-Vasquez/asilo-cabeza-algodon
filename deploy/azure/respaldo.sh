#!/usr/bin/env bash
# Respaldo de la base de datos del servidor en /opt/asilo-respaldos (se conservan los últimos 14).
# instalar.sh lo programa para que corra solo todos los días a las 23:30 (hora de Guatemala).
# Manual:      bash /opt/asilo-cabeza-algodon/deploy/azure/respaldo.sh
# Restaurar:   bash /opt/asilo-cabeza-algodon/deploy/azure/respaldo.sh restaurar /opt/asilo-respaldos/<archivo>.sql.gz
set -euo pipefail
cd /opt/asilo-cabeza-algodon
DESTINO=/opt/asilo-respaldos
mkdir -p "$DESTINO" && chmod 700 "$DESTINO"
CLAVE="$(grep '^DB_ROOT_PASS=' .env | cut -d= -f2-)"

if [ "${1:-}" = "restaurar" ]; then
    ARCHIVO="${2:?Indique el archivo .sql.gz a restaurar}"
    [ -f "$ARCHIVO" ] || { echo "No existe $ARCHIVO"; exit 1; }
    gunzip -c "$ARCHIVO" | docker exec -i asilo_db mariadb -uroot -p"$CLAVE" asilo_cabeza_algodon
    echo "Base de datos restaurada desde $ARCHIVO"
    exit 0
fi

ARCHIVO="$DESTINO/asilo-$(TZ=America/Guatemala date +%Y%m%d-%H%M).sql.gz"
docker exec asilo_db mariadb-dump -uroot -p"$CLAVE" --single-transaction --routines asilo_cabeza_algodon | gzip > "$ARCHIVO"
ls -1t "$DESTINO"/asilo-*.sql.gz 2>/dev/null | tail -n +15 | xargs -r rm -f
echo "Respaldo guardado: $ARCHIVO ($(du -h "$ARCHIVO" | cut -f1))"
ls -1t "$DESTINO"
