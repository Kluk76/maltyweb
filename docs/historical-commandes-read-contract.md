# Historical Commandes — Read Contract

> This document is the wiring spec for the `expeditions.php` overhaul to integrate
> the historical (pre-cutover) Commandes lane. It describes the three MySQL views
> that surface BC-canonical shipment data, and how to blend them with the live
> `ord_orders` lane at render time.

## Grain decision

**Shipment documents = Commandes (deliveries).** BC exports one `doc_type='shipment'`
row per invoice line per shipment document. The views aggregate to
`bc_document_no` level (the physical delivery document), matching the operator's
mental model of a commande.

The `inv_sales_ledger` fact table is the sole canonical source for historical
data — never duplicate/materialise it into a separate store.

## Cutover constant

```
CUTOVER = '2026-06-08'
```

- **Historical lane**: `posting_date < '2026-06-08'` → read from the three views below.
- **Live lane**: `ord_orders.requested_date >= '2026-06-08'` → read from `ord_orders` / `ord_order_lines`.

At render time, merge both lanes into a single chronological list, ordered by date descending.

## Channel scope

B2B + staff/giveaway/event are included. Excluded via:

```sql
c.sale_class NOT IN ('eshop','taproom','customs_artifact','transfer','sample')
```

This matches `ref_customers.sale_class`; the passing classes are:
`b2b`, `giveaway`, `staff`, `event`, `other`.

## Views

### `v_sales_ledger_orders` — order headers

One row per `bc_document_no` (shipment document). Use this for the Commandes list
and its day-group headers.

| Column             | Type          | Notes                                              |
|--------------------|---------------|----------------------------------------------------|
| `synthetic_order_id` | `VARCHAR`  | `'BC:' \|\| bc_document_no` — stable opaque ID      |
| `bc_document_no`   | `VARCHAR(64)` | Raw BC document number                             |
| `posting_date`     | `DATE`        | Date of shipment (hard cap < 2026-06-08)           |
| `customer_id_fk`   | `INT UNSIGNED`| FK → `ref_customers.id`                            |
| `customer_name`    | `VARCHAR(160)`| Denormalized for display (from `ref_customers`)    |
| `trade_channel`    | `ENUM`        | `on_trade` / `off_trade` / NULL                    |
| `line_count`       | `BIGINT`      | Number of resolved SKU lines                       |
| `total_units`      | `DECIMAL`     | `-SUM(qty_signed)` — positive = outbound           |
| `total_hl`         | `DECIMAL(14,2)` | Total hecto-litres shipped                       |

**Only resolved-FG rows** (`sku_id_fk IS NOT NULL`) are counted. Documents that have
exclusively unresolved lines are absent from this view — they appear in
`v_sales_ledger_unresolved` instead.

### `v_sales_ledger_order_lines` — order line detail

One row per `(bc_document_no, sku_id_fk)`. Use this to render the line detail
panel when the operator expands an order.

| Column             | Type          | Notes                                              |
|--------------------|---------------|----------------------------------------------------|
| `synthetic_order_id` | `VARCHAR`  | Matches the header view — join key                 |
| `bc_document_no`   | `VARCHAR(64)` |                                                    |
| `posting_date`     | `DATE`        |                                                    |
| `sku_id_fk`        | `INT UNSIGNED`| FK → `ref_skus.id`                                 |
| `sku_code`         | `VARCHAR(16)` | Denormalized SKU code for display                  |
| `qty`              | `DECIMAL`     | Units shipped (positive)                           |
| `hl`               | `DECIMAL(14,2)` | HL for this SKU line                             |

### `v_sales_ledger_unresolved` — audit tail

Unresolved `sku_code_raw` values by year. Surface as a muted "lignes non
rattachées" footnote in the UI — informational only, not clickable.

| Column       | Notes                                                         |
|--------------|---------------------------------------------------------------|
| `sku_code_raw` | Raw BC SKU code string (no `ref_skus` match found)          |
| `yr`         | `YEAR(posting_date)`                                          |
| `line_count` | Number of unresolved lines for this raw code × year           |
| `units`      | Total units (positive)                                        |
| `first_seen` | Earliest `posting_date` for this code × year                  |
| `last_seen`  | Latest `posting_date` for this code × year                    |

## PHP wiring pattern

```php
// --- Historical orders (pre-cutover) ---
$cutover = '2026-06-08';

$stmtOrders = $pdo->prepare("
    SELECT synthetic_order_id, bc_document_no, posting_date,
           customer_id_fk, customer_name, trade_channel,
           line_count, total_units, total_hl
    FROM v_sales_ledger_orders
    ORDER BY posting_date DESC, bc_document_no DESC
");
$stmtOrders->execute();
$cmdOrders = $stmtOrders->fetchAll(PDO::FETCH_ASSOC);

// Lines — fetch all, index by synthetic_order_id
$stmtLines = $pdo->prepare("
    SELECT synthetic_order_id, sku_code, qty, hl
    FROM v_sales_ledger_order_lines
    ORDER BY bc_document_no, sku_code
");
$stmtLines->execute();
$cmdLines = [];
foreach ($stmtLines->fetchAll(PDO::FETCH_ASSOC) as $line) {
    $cmdLines[$line['synthetic_order_id']][] = $line;
}

// Day grouping
$cmdByDay = [];
foreach ($cmdOrders as $ord) {
    $cmdByDay[$ord['posting_date']][] = $ord;
}

// Unresolved footnote
$unresolvedRows = $pdo->query("
    SELECT sku_code_raw, yr, line_count, units
    FROM v_sales_ledger_unresolved
    ORDER BY yr DESC, units DESC
")->fetchAll(PDO::FETCH_ASSOC);
```

## Render rules

1. **Read-only — no status chips.** Historical commandes are terminal (BC is the
   system of record). Do not render Accept / Refuse / En cours chips on these rows.
   A muted "Historique BC" badge is sufficient to distinguish them from live orders.

2. **Day grouping.** Use `$cmdByDay` to group under date headers, same rhythm as the
   live `ord_orders` lane. Merge both lanes by date key before rendering.

3. **`v_sales_ledger_unresolved` is a footnote.** Show at the bottom of the page as
   a collapsed/muted section ("N lignes BC non rattachées à un SKU"). Not per-day,
   not per-order — a global summary table grouped by `sku_code_raw` and `yr`.

4. **No CHF amounts** in this surface — `inv_sales_ledger` holds `sales_amount_chf`
   but it is not exposed in these views. The Commandes page is a logistics view
   (units, HL, SKU), not a financial view (Allnet / facturation is a separate basis).

## Allnet / facturation note

These views cover the **shipment-document grain** (physical deliveries). The Allnet
basis (invoice grain, `doc_type='invoice'`) is a separate audit scope and is NOT
wired here — it is the reconciliation counterpart, not the Commandes surface.

### `v_sales_ledger_weekly_client` — weekly per-client aggregate (mig 329)

One row per `(iso_yearweek, customer_id_fk)`. Used by the **Historique tab** in
`expeditions.php`. Groups shipment lines to ISO week × customer, giving the
week-block → client-row → SKU-drill hierarchy.

| Column           | Notes                                                          |
|------------------|----------------------------------------------------------------|
| `iso_yearweek`   | `YEARWEEK(posting_date, 3)` — ISO week integer (e.g. 202623) |
| `iso_year`       | Year part of the ISO week                                      |
| `iso_week`       | Week number (ISO-8601 mode 3)                                  |
| `week_start`     | Monday date of the ISO week (`STR_TO_DATE(…, '%X%V %W')`)     |
| `customer_id_fk` | FK → `ref_customers.id`                                       |
| `customer_name`  | Denormalized from `ref_customers.name`                         |
| `trade_channel`  | `on_trade` / `off_trade` / NULL                               |
| `doc_count`      | Distinct BC document numbers in this week × client             |
| `total_units`    | `ROUND(-SUM(qty_signed))` — positive outbound units            |
| `total_hl`       | `ROUND(-SUM(hl_resolved),2)` — total HL                       |

Same scope as the other three views: shipment docs, resolved-FG, B2B scope,
`posting_date < '2026-06-08'`. `ONLY_FULL_GROUP_BY` on derived columns
(`iso_year`, `iso_week`, `week_start`) handled via `ANY_VALUE()` — they are
deterministic on `iso_yearweek`.

## Historique tab wiring (expeditions.php, mig 329)

The **Historique** view (`?view=historique`) in `expeditions.php` renders
week-blocks → client-rows → SKU-drill using the four views above:

- **Period toolbar**: reuses `exp_parse_isoweek()` / `exp_date_to_isoweek()` /
  `exp_isoweek_label()` helpers and the shared `$cmdMode`/`$cmdKw`/`$cmdDu`/
  `$cmdAu` variables. Default week = last week before cutover (2026-06-07).
- **Week aggregate**: `v_sales_ledger_weekly_client WHERE week_start BETWEEN ? AND ?`.
- **SKU drill**: direct `inv_sales_ledger GROUP BY (YEARWEEK, customer_id_fk, sku_code)`,
  filtered to the same date window + customer IDs in scope. Pre-fetched in one query;
  indexed by `"{iso_yearweek}:{customer_id_fk}"` for O(1) render lookup.
- **Unresolved footnote**: `v_sales_ledger_unresolved` (no date filter — global).
- **JS**: `public/js/expeditions-historique.js` — delegated click toggle on
  `.exp-hist-client-btn` / `aria-expanded` / `[hidden]` panel.
- **CSS**: `public/css/expeditions.css` — `.exp-hist-*` token namespace.

## Schema metadata

Views are read-only lenses over `inv_sales_ledger` (classified `source` /
`corrections_policy='blocked'` in `schema_meta`). No `schema_meta` row is needed
for the views themselves.
