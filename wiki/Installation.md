# Installation

Requires WooCommerce installed and active. Pick one method.

## WordPress.org

Admin, Plugins, Add New, search "Mibizum Search", Install, then Activate.

## Upload the .zip

Plugins, Add New, Upload Plugin, choose `mibizum-search.zip`, Install Now, Activate.

## WP-CLI

```bash
wp plugin install mibizum-search --activate
# or from a local zip:
wp plugin install /path/to/mibizum-search.zip --activate
```

## On activation

- The plugin tables (the index queue and the badge tables) are created.
- Nothing is published yet: the connection is disabled and has no keys.
- The first run setup wizard opens. You can resume it later from the "Set up
  Mibizum" notice or from WooCommerce, Settings, Mibizum.

## Cron

Changes publish in the background with Action Scheduler (bundled with
WooCommerce), driven by WP-Cron. On low traffic sites, use a real system cron:

```php
// wp-config.php
define( 'DISABLE_WP_CRON', true );
```

```cron
*/5 * * * * curl -s https://your-store.com/wp-cron.php?doing_wp_cron >/dev/null 2>&1
```
