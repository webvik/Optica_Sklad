# Provoz: beta (lowpartners.net) vs. produkce (optica.lowpartners.net)

## Schéma (schváleno)

| | **Beta (testování ve firmě)** | **Produkce (reálný sklad)** |
|---|---|---|
| URL | https://lowpartners.net/ | https://optica.lowpartners.net/ |
| DocumentRoot | `/home/httpd/html/lowpartners.net/public` | `/home/httpd/html/optica.lowpartners.net/public` |
| MariaDB DB | `lowpartners_app` | `optica_sklad` |
| MariaDB user | `lowpartners_app` | `optica_sklad` |
| `APP_ENV` | `prod` | `prod` |
| PHP | **8.4.21** (`php84`, Remi RPM, OPcache) | **8.4.21** — stejný handler jako beta |
| Data | testovací (stávající) | pouze reálné zásoby |

**PHP (beta ověřeno):** `php84 -v` → PHP 8.4.21 (cli), Zend OPcache 8.4.21. Lokálně může být 8.5+; na hostingu obě instance **php84 / 8.4.21**.

**Důležité:** dvě oddělené databáze — beta se nemění, produkce startuje čistě (nebo řízený import).

**DATABASE_URL:** na hostingu používat `localhost` (jako beta), ne `127.0.0.1`. Příklad beta:
`mysql://lowpartners_app:…@localhost:3306/lowpartners_app?serverVersion=10.6.23-MariaDB&charset=utf8mb4`

## Soubory env na serveru `optica.lowpartners.net`

V kořeni projektu `/home/httpd/html/optica.lowpartners.net/`:

1. `.env.local` — pouze `APP_ENV=prod` (viz `deploy/optica.lowpartners.net.env.local.template`)
2. `.env.prod.local` — tajné hodnoty (viz `deploy/optica.lowpartners.net.env.prod.local.template`)

Šablony zkopírovat a doplnit hesla od hostingu. Soubory **necommitovat**.

## Beta `lowpartners.net`

Stávající `.env.local` / `.env.prod.local` na `/home/httpd/html/lowpartners.net/` **neměnit** kvůli testerům.
