# Troubleshooting and uninstall

## Cron must be running

Changes publish in the background with Action Scheduler. See the queue under
WooCommerce, Status, Scheduled Actions (group `mibizum-search`). If "changes
pending" never drops, check cron. As a one off, click **Resync all products**.

## Common issues

| Symptom | Check |
|---------|-------|
| Nothing new in search | Is it Enabled with keys set? Is cron running? Resync. |
| A product is missing | Is it published and not hidden from the catalog? Does it have a price? Reindex. |
| Search looks like the old one | The fallback kicked in: the engine did not respond, or the public key is missing. |
| Widget not showing | Did you enable the widget and paste the snippet? |
| Changed badges, not visible | Wait for the scheduled reindex, or Resync. |

## Pause (reversible, never breaks the store)

- **Deactivate the plugin:** the override, observers, cron and widget stop;
  search returns to native WordPress. Background actions are unscheduled.
- **Connection off** (`Enabled = No`, or missing key/URL): observers and cron
  short circuit; the override falls back to native.
- The override also falls back to native on any engine error (5xx / network).

Reversible pause: set `Enabled = No` and save. Resume with `Enabled = Yes`.

## Full uninstall

Deleting the plugin (Plugins, Delete) runs the uninstall routine: it removes the
options (`mibizum_search_*`), drops the plugin tables, and unschedules the
background actions. On Multisite it cleans every site.

Deactivating keeps the data so you can resume; deleting wipes it. Either way the
store keeps working: if Mibizum is unavailable, WordPress search takes over.
