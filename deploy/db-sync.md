# Zálohy a sync MariaDB (prod → beta / lokál)

## Adresář záloh na serveru MSD

Všechny dumpy ukládat sem (ne do `~`):

```text
/home/httpd/html/backups_DB_alivegirl.net/
```

```bash
mkdir -p /home/httpd/html/backups_DB_alivegirl.net
```

## Instance

| | DB | Projekt |
|---|-----|---------|
| **Produkce** | `optica_sklad` / user `optica_sklad` | `/home/httpd/html/optica.lowpartners.net` |
| **Beta** | `lowpartners_app` / user `lowpartners_app` | `/home/httpd/html/lowpartners.net` |
| **Lokál** | `optica_sklad` / user `OpS_User` | `.env.local` → `DATABASE_URL` |

**Nikdy** neimportovat beta → prod.

---

## 1. Plný dump produkce (včetně `app_user`)

```bash
BACKUP_DIR=/home/httpd/html/backups_DB_alivegirl.net
STAMP=$(date +%Y%m%d_%H%M)
FILE="$BACKUP_DIR/optica_sklad_prod_full_${STAMP}.sql.gz"

mysqldump -u optica_sklad -p optica_sklad \
  --single-transaction \
  --default-character-set=utf8mb4 \
  | gzip > "$FILE"

ls -lh "$FILE"
```

Stažení na PC: `scp viktor_ssh@msd5487.mjhst.com:"$FILE" .`

### Import lokálně (`OpS_User` / `optica_sklad`)

```bash
gunzip -c optica_sklad_prod_full_*.sql.gz | mysql -u OpS_User -p optica_sklad

cd /path/to/Optica_Sklad
php bin/console cache:clear
```

---

## 2. Dump skladu bez uživatelů (prod → beta, loginy na betě beze změny)

```bash
BACKUP_DIR=/home/httpd/html/backups_DB_alivegirl.net
STAMP=$(date +%Y%m%d_%H%M)
FILE="$BACKUP_DIR/optica_sklad_warehouse_only_${STAMP}.sql.gz"

mysqldump -u optica_sklad -p optica_sklad \
  --single-transaction \
  --default-character-set=utf8mb4 \
  --ignore-table=optica_sklad.app_user \
  --ignore-table=optica_sklad.user_audit_log \
  | gzip > "$FILE"
```

### Import do beta (`lowpartners_app`)

Nejdřív záloha bety (warehouse only):

```bash
BACKUP_DIR=/home/httpd/html/backups_DB_alivegirl.net
mysqldump -u lowpartners_app -p lowpartners_app \
  --single-transaction \
  --ignore-table=lowpartners_app.app_user \
  --ignore-table=lowpartners_app.user_audit_log \
  | gzip > "$BACKUP_DIR/lowpartners_app_warehouse_before_prod_${STAMP}.sql.gz"
```

Import:

```bash
gunzip -c "$BACKUP_DIR/optica_sklad_warehouse_only_${STAMP}.sql.gz" \
  | mysql -u lowpartners_app -p lowpartners_app \
  --init-command="SET FOREIGN_KEY_CHECKS=0;"

mysql -u lowpartners_app -p lowpartners_app -e "
UPDATE cable_spool SET created_by_id=NULL, updated_by_id=NULL;
UPDATE cable_spool_event SET created_by_id=NULL;
UPDATE cable_type SET created_by_id=NULL, updated_by_id=NULL;
"

cd /home/httpd/html/lowpartners.net && php84 bin/console cache:clear --env=prod
```

---

## 3. Lokál jen sklad (bez přepsání `app_user`)

Stejný soubor `optica_sklad_warehouse_only_*.sql.gz` jako v §2:

```bash
gunzip -c optica_sklad_warehouse_only_*.sql.gz | mysql -u OpS_User -p optica_sklad \
  --init-command="SET FOREIGN_KEY_CHECKS=0;"
```

Volitelně vynulovat `created_by_id` (viz SQL výše).

---

## Pomocné skripty

- `deploy/db-dump-prod-full.sh` — plný dump prod do `backups_DB_alivegirl.net`
- `deploy/db-dump-prod-warehouse-only.sh` — sklad bez uživatelů

Spouštět na serveru po `chmod +x`. Heslo: interaktivně `-p` (neukládat do skriptu).
