# Local test environment

Two ways to run a real WordPress + WooCommerce store with this plugin mounted
live, for development and testing.

## Option A: Docker (recommended when Docker is available)

A dedicated, throwaway stack: WordPress + MariaDB, with this plugin mounted and
WooCommerce + sample products provisioned automatically.

```bash
cd packages/adapter-woocommerce/dev
docker compose up -d
docker compose logs -f wpcli      # watch provisioning
```

- Store:  http://localhost:8088
- Admin:  http://localhost:8088/wp-admin  (admin / admin)
- Reset everything:  `docker compose down -v`

The plugin is mounted from the package root, so code edits are reflected
immediately (no rebuild).

## Option B: No Docker (SQLite + PHP built in server)

Needs only `php` and `wp-cli` on PATH. Builds an isolated site on SQLite, so no
MySQL is required.

```bash
cd packages/adapter-woocommerce/dev
./install-local.sh          # builds into ~/mibizum-wp-test
./install-local.sh serve    # starts http://localhost:8089
```

Override the location/port:

```bash
SANDBOX=/tmp/wp PORT=8090 ./install-local.sh
```

- Admin:  http://localhost:8089/wp-admin  (admin / admin)

## Connecting to Mibizum

After either option, connect the store in one of two ways:

1. The first run **setup wizard** (prompted on activation), or
2. **WooCommerce > Settings > Mibizum > Connection**: set the API URL, the
   indexer key, the search key and the data source slug, tick Enabled, save,
   then **Reindex > Resync all products**.

Until real keys are entered the store behaves exactly as stock WooCommerce
(native search), by design.
