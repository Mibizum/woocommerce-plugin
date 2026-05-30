# Badges

Colored labels shown over result images. Configured under WooCommerce, Settings,
Mibizum, Badges. Labels travel inside the indexed document; the panel controls the
final style. Changing badges schedules a reindex.

## System badges

Computed from product state:

- **Out of stock** when the product has no stock.
- **Last units** when it manages stock and is at or below your threshold.
- **On sale** when the product is on sale.
- **New** for products created within the last N days.
- **Featured** for products marked as featured.

## Category badges

A badge attached to a product category, optionally including subcategories. All
products in that category get the label. At most one per product (lowest priority
wins).

## Attribute badges

A badge driven by a product attribute term (a `pa_*` taxonomy), for example
"Vegan" or "Organic", with an optional category filter. Zero or more per product.
