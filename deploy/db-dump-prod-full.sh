#!/usr/bin/env bash
# Plný mysqldump produkce optica_sklad → backups_DB_alivegirl.net
set -euo pipefail

BACKUP_DIR=/home/httpd/html/backups_DB_alivegirl.net
DB_NAME=optica_sklad
DB_USER=optica_sklad

mkdir -p "$BACKUP_DIR"
STAMP=$(date +%Y%m%d_%H%M)
FILE="${BACKUP_DIR}/${DB_NAME}_prod_full_${STAMP}.sql.gz"

mysqldump -u "$DB_USER" -p "$DB_NAME" \
  --single-transaction \
  --default-character-set=utf8mb4 \
  | gzip > "$FILE"

echo "OK: $FILE"
ls -lh "$FILE"
