# Mibizum Search for WooCommerce

Connects a WooCommerce store to the Mibizum search engine. It indexes the catalog
in the background, replaces the native product search with engine results (with an
automatic fallback to native search), and adds an instant search widget.

**Safe by design:** the plugin never breaks the store. Deactivating it, leaving it
unconfigured, or any engine error always falls back to native WordPress /
WooCommerce search. Nothing is published until the connection is enabled and the
keys are present.

## Pages

- [[Installation]]
- [[Configuration]]
- [[Badges]]
- [[Multistore]]
- [[Troubleshooting]]

## Two API keys

- **Indexer** (write): server side, stored encrypted, never sent to the browser.
- **Search** (read): used by the server side override and embedded in the public
  widget snippet.

Full guides: https://docs.mibizum.io/woocommerce
