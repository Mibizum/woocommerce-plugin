# FAQ

**Does it change my products, prices or stock?**
No. The plugin only reads the catalog to build the search index.

**What happens if Mibizum is down?**
Search falls back to native WordPress search. The customer always sees results,
never an error. By design, the plugin never breaks the store.

**Do I have to wait for indexing to finish?**
No. It runs in the background with Action Scheduler.

**Why two API keys?**
The private key (write) lives only on the server, encrypted. The public key (read)
is the only one that reaches the browser, in the widget snippet. The customer
facing key can never modify anything.

**Where are the keys stored?**
Encrypted in the database (AES-256-GCM). The private key is never sent to the
browser or written to logs.

**A product is missing from search. Why?**
Make sure it is published, not hidden from the catalog, and has a price, then
reindex (Resync all products). Products hidden from the catalog are excluded on
purpose.

**Does it work with multiple languages or stores?**
Yes. One Mibizum data source per language (WPML / Polylang) and per site
(Multisite). See [[Multistore]].

**Is it HPOS compatible?**
Yes. HPOS only affects orders, not products. The plugin declares HPOS
compatibility.

**How do I fully uninstall it?**
Delete it from Plugins, Delete: the uninstall routine removes options and tables
and unschedules background actions. Deactivating only pauses and keeps the data.

**Is the plugin paid?**
The plugin is free and open source (GPLv2 or later). It needs a Mibizum account to work.
