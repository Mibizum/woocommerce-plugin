=== Mibizum Search for WooCommerce ===
Contributors: mibizum
Tags: search, woocommerce, instant search, product search, search engine
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 0.1.0
License: MIT
License URI: https://opensource.org/licenses/MIT

Privacy first search for WooCommerce: background indexing, an instant search widget, and a native search override with automatic fallback.

== Description ==

Mibizum Search connects a WooCommerce store to the Mibizum search engine. Once configured it does three things:

* Keeps the catalog indexed automatically. Every product you save, and every stock change, is published to Mibizum in the background. The request is never blocked.
* Replaces the native product search. When a customer searches, results come from the Mibizum engine, with an automatic fallback to native WooCommerce search if the engine does not respond.
* Adds an instant search widget over the search box, with images, price and badges as the customer types.

Safe by design. The plugin never breaks the store. If you deactivate it, leave a key half set, or Mibizum does not respond, the store keeps working and search falls back to native WooCommerce search on its own.

== Two API keys ==

* Indexer key (write): publishes the catalog. Stored encrypted on the server. Never sent to the browser.
* Search key (read): used by the search override and embedded in the widget snippet. The only key that reaches the browser.

== Multi store and multi language ==

One Mibizum data source per language (WPML or Polylang) and per site (WordPress Multisite). Each scope has its own connection and is indexed in its own translated context.

== Installation ==

1. Install and activate the plugin (WooCommerce must be active).
2. Follow the first run setup wizard, or configure it manually under WooCommerce, Settings, Mibizum.
3. Paste the indexer and search keys, choose the data source, and run the first indexing.

== Frequently Asked Questions ==

= Does it change my products, prices or stock? =
No. It only reads them to build the search index.

= What happens if Mibizum is down? =
Search falls back to native WooCommerce search. The customer always sees results, never an error.

== Screenshots ==

1. Connection settings under WooCommerce, Settings, Mibizum.
2. The first run setup wizard.
3. The Reindex panel with live pending counter.
4. Badge settings (system, category and attribute).
5. The category and attribute badge editor.
6. Search results served by the Mibizum engine on the storefront.

== Changelog ==

= 0.1.0 =
* Initial release.
