#!/usr/bin/env bash
# Sklad z produkce bez app_user a user_audit_log → backups_DB_alivegirl.net
set -euo pipefail

BACKUP_DIR=/home/httpd/html/backups_DB_alivegirl.net
DB_NAME=optica_sklad
DB_USER=optica_sklad

mkdir -p "$BACKUP_DIR"
STAMP=$(date +%Y%m%d_%H%M)
FILE="${BACKUP_DIR}/${DB_NAME}_warehouse_only_${STAMP}.sql.gz"

mysqldump -u "$DB_USER" -p "$DB_NAME" \
  --single-transaction \
  --default-character-set=utf8mb4 \
  --ignore-table="${DB_NAME}.app_user" \
  --ignore-table="${DB_NAME}.user_audit_log" \
  | gzip > "$FILE"

echo "OK: $FILE"
ls -lh "$FILE"
