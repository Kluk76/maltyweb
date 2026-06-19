# Fulfilment — Foundations: provenance, mig 279 schema, module, Commandes dashboard, Stock PF compute

## Provenance / approval
PM pre-approval brief delivered + **approved by Kouros 2026-06-07** with three PM recommendations confirmed: (1) saisie + actionable dashboard (not display-only); (2) «Expéditions» is the christening build of **Le Cockpit** (commercial family); (3) full v1 in one arc. All below DEPLOYED to VPS, linted, smoke-tested, committed 2026-06-07.

## Schema — mig 279 `279_expeditions_orders.sql` (commit `bbc49fe`)
- **`ref_transporters`** — seeded Galliker/Loxya. PENDING: add **Transport Express** (17 mentions in the sheet).
- **`ref_customers`** — commercial sold-to master: `name` UNIQUE, `bc_customer_no` UNIQUE NULL, `trade_channel`, `is_private`, `default_transporter_id_fk`, `needs_review`. **Intentionally DISTINCT from `ref_clients`** (= contract-brewing companies; documented in-mig).
- **`ord_orders`** — `order_type` + `customer_id_fk` + `internal_channel` exactly-one-of CHECK (RESTRICT FK); `status` ENUM entered→confirmed→picked→bl_printed→shipped + cancelled **as CACHE only**; email-ingestion-ready cols: `source`, `source_file_id_fk`→`doc_files.id`, `parse_confidence`, `review_status`.
- **`ord_order_lines`** — `sku_id_fk` RESTRICT, `qty` DECIMAL(10,2) in **SKU units** CHECK>0; HL derived at read.
- **`ord_order_status_events`** — append-only TRUTH; cache updated same-transaction.
- 5 `schema_meta` rows; constraints probed live; fixtures self-cleaned. `ref_pages`: page_key `fulfilment`→`expeditions`, href set, **`is_active=0` — flip = go-live gate, after operator smoke**.

## Module (commit `f9535ef`)
- `public/modules/expeditions.php` — 3 tabs + saisie POST: one transaction, `log_revision` throughout, PRG, inline new-customer (`needs_review=1`), `?edit=` prefill, shipped/cancelled rows read-only.
- `api/expeditions-status.php` — advance/revert/cancel via explicit **rank map, NEVER ENUM order** (mig-276 lesson honored).
- `public/css/expeditions.css` (`exp-` prefix), `public/js/expeditions-form.js` (typeahead over `window.EXP_*` injections) + `expeditions.js`.
- Fix applied during build: `require`→`require_once` (csrf.php double-include 500).

## Commandes dashboard (commit `af6e0ea`)
ISO-week nav + **date-range mode** (Kouros mid-build request): `?view=commandes&mode=range&du=&au=` GET contract, ≤92 j, bookmarkable — the past-delivery pull the legacy sheet lacked. Filters client/SKU/statut/canal. Day blocks with per-day HL + counts, SKU pills, status chips one-click advance. **Eshop/taproom rows = READ-ONLY auto rows from `inv_sales_*`** (muted, never double-counted into ord_*). 3 queries, no N+1.

### Stock-warning DETAIL MODAL ✅ SHIPPED 2026-06-10 (commit `badd153` on main, NOT pushed; 4 files: `expeditions.php`/`.css` + `expeditions.js` + `expeditions-form.js`)
Per-order stock-risk chip → click → modal short-list of the SKUs that fall short. **NO second compute, NO API round-trip** — built inside the EXISTING `$view==='commandes'` `fg_stock_compute` rollup pass and injected as `window.EXP_CMD_STOCK_DETAIL` = `{oid: [{sku_code, requested, available, physique, short_by}]}`. Removed the early `break` in the per-order rollup so ALL short lines are captured (was lossy — only first short line before). `$cmdStockMap` enriched to also carry `physique`.
- **Headline / short_by math keys off `live_futur` (=disponible)** to MATCH the chip trigger exactly (Kouros-specified); `physique` shown as a SECOND advisory column alongside disponible, not the driver.
- Chip = focusable `<button class="exp-stock-risk-chip" data-order-id>`; modal = single `<dialog id="exp-stock-detail-modal">` opened from **`expeditions.js`** (the Commandes-view JS — NOT `expeditions-stock.js`). Closes ✕/backdrop/Escape. Built per `bom-review.js` idiom; `dialog[open]`-scoped CSS, no display-trap, no z-index hack (UI-skill rendering discipline honored).
- **Stays advisory — never blocks.** KNOWN: shipped orders can still show the chip (pre-existing rollup filters only cancelled, not shipped). Flagged to Kouros as a possible SEPARATE tweak; not requested.
- VERIFY: `node --check` + `php -l` clean; live data probe = 9 orders flagged with correct short-lists (#6 STIB −1 + DIB4 −11; #28 DIV4 −48). Browser click-smoke NOT completed (Playwright snagged on login) — verified by code soundness + data probe; Kouros asked to confirm the click opens it.
- ⚠️ COMMIT NOTE: `badd153` ALSO bundled a PRE-EXISTING uncommitted mouvements-form stock-hint feature (`EXP_MOV_STOCK_MAP`, another session's in-flight work) — 24 intermixed hunks couldn't be safely split. NOT pushed. The 2-week predictive compute (`fg-stock.php`) was committed out-of-band by another session earlier. (Parallel-session tree churn ongoing: financier `6a013ad`/untracked financier.php+css; mon-tableau kpi-handlers Phase 2b `09006ce`/`d264111`/`4f6a98c`/`598f788`.)

## Stock PF (commits `4a8f49e` + fix `b676c9b`)
`app/fg-stock.php` pure-SELECT library **`fg_stock_compute()`**:
`anchor` (`inv_fg_stocktake` latest month_closed — currently 2026-04) **+ production** (`bd_packaging_v2` **÷ `ref_skus.units_per_pack`** → production in PACK units; caught a 24× inflation, same defect class as maltytask `942431e`) **− shipped** (ord lines) **− eshop** (`inv_sales_orders` — SOLE eshop source) **− taproom** (**`inv_sales_bc`** — inspection showed `inv_sales_orders` has NO taproom rows; documented in code).
4 metrics + survendu/<2-semaines flags + drill-down ledger + dormant toggle. Velocity = trailing 8 wk from BC+eshop history.
