# Multistore and multi language

Mibizum maps each "store in one language" to one data source (catalog):

| WordPress | Mibizum |
|-----------|---------|
| Each language (WPML or Polylang) | Its own data source |
| Each site of a Multisite network | Its own data source |

Each language or site has its own search index, badges, rules and Smart item.

## Indexing fan out

Every saved product (or stock change) is published to each connected scope, mapped
in that scope's context (translated name, store price, store URL). Scopes that
share the same key + URL + slug are treated as the same catalog, so a single
language store is configured once.

## Connection change triggers a reindex

Changing the connection topology (connecting, disconnecting, or repointing a scope
to another catalog) enqueues a full reindex of the affected catalog. Other setting
changes do not.

## Per scope search and widget

The search override and the widget query the current language or site catalog with
its own public key. The Reindex panel shows a "Connected catalogs" table when more
than one scope exists.
