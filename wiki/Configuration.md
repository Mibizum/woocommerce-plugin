# Configuration

Connect the store with the **wizard** (first run) or **manually** under
WooCommerce, Settings, Mibizum.

## Setup wizard

1. **Start.** A summary of what will be connected.
2. **Account.** Sign in or create your Mibizum account in an embedded window. The
   issued keys are saved encrypted and the connection is enabled.
3. **Indexing.** The first catalog index runs in the background.
4. **Widget.** Paste the widget snippet (or skip).
5. **Done.** The search box now uses the Mibizum engine.

If the embedded window fails to load, the wizard offers a "Configure manually"
link and never blocks the admin.

## Manual: Connection

| Field | Value |
|-------|-------|
| Enabled | `No` until the keys are set, then `Yes`. |
| API URL | `https://app.mibizum.io` |
| Private API key | Write key. Stored encrypted, never sent to the browser. |
| Public API key | Read key. Used by the override and the public widget. Stored encrypted. |
| Data source slug | The catalog slug (e.g. `products`). Empty uses the account first data source. |

Saving a connection change schedules a full reindex.

## Manual: Frontend

- **Enable widget** and **Widget snippet** (pasted from the Mibizum panel,
  Domains, JS code). Injected verbatim in the head. Empty means no widget.

## Manual: Sync (advanced)

`Batch size` (max 500), `Maximum retries`, `HTTP timeout`. Sensible defaults.

## Manual: Reindex

Last reindex status, live "changes pending" counter, and the **Resync all
products** button. Draining happens in the background.
