# Mibizum Search for WooCommerce

Privacy first search for WooCommerce. Connects a WooCommerce store to the
[Mibizum](https://mibizum.io) search engine: it indexes your catalog in the
background, replaces the native product search with engine results (with an
automatic fallback to native search), and adds an instant search widget.

> **Safe by design.** The plugin never breaks the store. If you deactivate it,
> leave a key half set, or the engine does not respond, the store keeps working
> and search falls back to native WordPress / WooCommerce search on its own.
> Nothing is published until the connection is enabled and the keys are present.

## Features

- **Background indexing.** Products are published to Mibizum on save, on stock
  change and on delete, via a queue drained by Action Scheduler. The web request
  is never blocked.
- **Native search override with fallback.** The product search results page is
  served by the Mibizum engine, consistent with the widget. Any engine error or
  timeout falls back to native search.
- **Instant search widget.** The merchant pastes the snippet from the Mibizum
  panel; it is injected verbatim in the page head.
- **Badges.** System badges (out of stock, last units, on sale, new, featured),
  per category and per attribute. Labels travel inside the indexed document.
- **Multi store and multi language.** One Mibizum data source per language
  (WPML / Polylang) and per site (WordPress Multisite).
- **First run setup wizard.** Guided connect, index and widget steps.

## Two API keys

| Key | Scope | Where it lives |
|-----|-------|----------------|
| Indexer | write | Server side, stored encrypted, never sent to the browser |
| Search  | read  | Used by the server side override and embedded in the public widget snippet |

## Requirements

- WordPress 6.0+
- WooCommerce 7.0+ (HPOS compatible)
- PHP 7.4+

## Install

From the WordPress admin: Plugins, Add New, search "Mibizum Search", Install,
Activate. Or upload the `.zip`. Or with WP-CLI:

```bash
wp plugin install mibizum-search --activate
```

On activation the setup wizard opens. You can also configure it manually under
**WooCommerce, Settings, Mibizum**.

## Development

A local test environment (Docker, or SQLite without Docker) lives in
[`dev/`](dev/README.md):

```bash
cd dev
docker compose up -d          # http://localhost:8088  (admin / admin)
# or, without Docker:
./install-local.sh && ./install-local.sh serve
```

Coding standards:

```bash
composer install
composer run lint
```

## Documentation

Full guides at [docs.mibizum.io/woocommerce](https://docs.mibizum.io/woocommerce),
and the [project wiki](../../wiki).

## License

[MIT](LICENSE).
