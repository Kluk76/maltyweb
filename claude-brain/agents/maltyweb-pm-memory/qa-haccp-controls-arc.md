# QA / HACCP QUALITY-CONTROLS ARC — `/modules/qa.php`

> Salle de contrôle (QA/QC) family. **NON-FISCAL observation layer — NEVER feeds COGS / stock / WAC / BOM / beer-tax.** This is the first real build on the `qa` page (previously an inactive ref_pages placeholder with pre-written tour copy). SHIPPED + LIVE on the VPS 2026-06-15.
>
> Last updated: 2026-06-15.

## STATUS — DONE & LIVE 2026-06-15 (commits LOCAL-only, NOT pushed)

All shipped on PM-recommended defaults; operator granted "go for it". Work is fully LIVE on the VPS. **maltytask + maltyweb commits are LOCAL-only, NOT pushed** — a parallel cage session's commits are interleaved in the shared clone, so pushing would carry their in-flight work. Deferred to Kouros. (Same shared-tree hazard class as the index BUILD-LAUNCH CHECKLIST §commit-with-pathspec / §deploy-pushes-working-tree.)

## WHERE IT SITS IN THE DERIVATION TREE

Salle de contrôle / QA-QC family of Le Zeppelin. Pure DOWNSTREAM **observation** off existing canonical events — it READS production facts, writes only its own observation rows, and **NOTHING reads back into the SOT chain**. Dropdowns derive by FK from the real canonical tables (no string copy, no parallel store):
- net-content reading → `bd_packaging_v2` LEFT JOIN `ref_skus` (label "Beer — date — runtype (sku)")
- cleaning-efficacy check → `bd_cip_events` JOIN `ref_cip_types`
- bottle-reception check → `inv_deliveries` (status not cancelled/tombstoned)
- packaging-material MI → `ref_mi WHERE category_id=8` (Packaging; confirmed via `ref_mi_categories`)

## WHAT SHIPPED

### Migrations (maltyweb repo; committed locally, applied on VPS)
- **358_qa_net_content_readings.sql**
- **359_qa_cleaning_efficacy_checks.sql**
- **360_qa_bottle_reception_checks.sql**
  - 3 `source`-class tables, `corrections_policy='allowed'`, `schema_meta` rows written, `writer_script='/modules/qa.php'`.
  - FK types verified live: packaging/cip/delivery = **BIGINT UNSIGNED**; mi/user = **INT UNSIGNED**.
  - Commit `0b52f13`.
- **361_activate_qa_page.sql** — `UPDATE ref_pages SET is_active=1, href='/modules/qa.php' WHERE page_key='qa'` (id 11). Applied. Commit `eac31da`.
  - ⚠️ **Mig-number collision (harmless):** a parallel session also created `361_ord_orders_bc_*.sql`. migrate.php tracks by filename, so both coexist; theirs left untouched, theirs to commit.

### Handlers (async JSON, mirror `rm-stocktake-line-add.php`)
`public/api/qa-net-content.php`, `qa-cleaning-efficacy.php`, `qa-bottle-reception.php`. Envelope: `{ok:true,id,...}` / `{ok:false,error}` / `{ok:false,reason:'expired',csrf}` / duplicate→`{ok:true,duplicate:true}`. Each does csrf_verify + `require_page_access('qa')` + audit revision + row_hash idempotency + read-`??`-then-validate + ENUM whitelist + FK-existence checks.
- Shared helper **`parse_nullable_decimal()`** added to existing **`app/db-write-helpers.php`** — single canonical def, NOT copied (honors "call the accessor, never copy its literal").
- Commits `3ede620` then `01728e2` (PRG→JSON refactor).

### UI
`public/modules/qa.php` (747L) + `public/css/qa.css` (297L) + `public/js/qa.js` (314L). 3 stacked panels (net-content / cleaning-efficacy / bottle-reception), optional `?view=net|cip|recep`, async fetch + DOM prepend, escHtml, external CSS/JS (no inline). Commit `8734849`.

### Tour
Tour card for `qa` shipped by tour-steward (commit `f8478da`; also added a bonus journal-saisies card). Tour gap CRITICAL 2→1. **Remaining critical = `financier`** — steward correctly flagged it `PM-RATIFY` as sensitive COGS class; NOT covered here, belongs to the COGS-fiche arc (it owns that card).

### Smoke (webapp-testing — PASSED)
All panels render; dropdowns populated (A:50, B:30, C:50/125); 3 write round-trips OK; validation rejects bad enum (400 + JSON, NOT 500); test rows self-cleaned (COUNT=0); test account id=16 elevated→reverted.

## DESIGN DEFAULTS LOCKED (operator may revisit — all non-destructive)
- Units: weight=**g**, volume=**mL**; single `measured_value` + `measure_type` discriminator (NOT two columns).
- `surface_label` = **free text** (no `ref_qa_surfaces` lookup built).
- Targets: **per-reading** `target_value` + `tolerance_abs` (NO central `qa_targets` master).
- `is_conforming` **derived at write**.

## OPEN / FOLLOW-UP
1. Panel C `mi` dropdown shows the WHOLE Packaging category (125 options) — tighten to glass/bottle-only if operator asks.
2. Feature A inline capture on the conditionnement fiche (Q3 phase-2) NOT built — standalone qa-page capture only, as planned.
3. Optional `ref_qa_surfaces` lookup + central `qa_targets` master = future normalization, not built.
4. `financier` tour card still open (its arc owns it — COGS-fiche / financier-cogs-fiche.md).
5. Push: commits are LOCAL-only pending Kouros (cage-session interleave).
