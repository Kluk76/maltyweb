---
name: coder
description: Write, refactor, or debug general (non-parser) code in the maltytask repo and its sibling maltyweb VPS. Use this skill for any non-trivial coding work that isn't building invoice parsers — TypeScript migration tasks, MySQL repo writes, ingest pipeline changes, audit-log discipline, ref data writes, schema work, scripts/* automation, BSF Sheets writes (legacy), maltyweb PHP/SQL changes, deploy/ingest CLI work, or surface integrations between the canonical MySQL DB and computed views. The body encodes the maltytask architecture (MySQL canonical since 2026-05-12, TypeScript strangler-fig migration, lib/repos pattern, audit log on every write, BSF read-only legacy), 25+ workflow anti-patterns ("backfill must dedupe against bsf-mirror", "PHP 8 strict after column-type migration", "never cap rows OR cols", "deliveries tab formula-driven cols A/E/F/I/M/N/R", "RQ rows must be self-sufficient"), and verification recipes. Equip this skill whenever you are working on code outside `lib/invoice-parsers/` (which uses the parser-coder skill instead).
---

# Coder

This skill encodes the discipline learned from migrating maltytask into MySQL-canonical state + ~50 multi-file refactors + the maltyweb VPS surface. Read once at task start. Re-consult **Anti-patterns** before declaring done.

## Project manager — align with `maltyweb-pm`
A standing **`maltyweb-pm`** project-manager agent (`~/.claude/agents/maltyweb-pm.md`; knowledge base `~/.claude/agents/maltyweb-pm-memory.md`) holds the whole maltyweb picture: the Le Zeppelin derivation tree (the source-of-original-truth from which every computation derives by FK), the SQL build schema, the UI build sequence + project state, and the architecture/coding conventions. For any maltyweb build: align with it — where the work fits in the derivation tree, no parallel data stores (a replacement page writes to the SAME canonical tables it supersedes), derive-don't-duplicate, refuse-don't-NULL, never guess COGS-impacting mappings. After a page ships / phase completes / convention is decided, **its memory must be updated** by whoever landed the change. The orchestrator consults it when planning/sequencing.

## When to use this skill

- Any non-parser code change in `/home/kluk/projects/maltytask` (scripts, repos, schemas, ingest pipeline, harness, lib helpers)
- Changes in the maltyweb sibling at `/home/kluk/projects/maltyweb/` or operations on its VPS (`ubuntu@83.228.215.243`)
- Cross-surface integrations (MySQL → BSF, MySQL → interfaces/*.json, Form responses → tabs)
- TypeScript strangler-fig migrations of legacy JS scripts
- Audit-log work, schema additions, ref data writes
- BSF Sheets ops (legacy, but still required for some computed tabs)

**Not** for invoice parsers — use `parser-coder` skill for anything under `lib/invoice-parsers/`.

**Not** for the browser-rendering layer — use the `ui` skill for UI/rendering work (modals, previews, charts, the PHP→`window.JSON`→JS hydration, CSS/JS in `public/`, "it doesn't render / popup doesn't show" bugs). `ui` layers on top of this skill — equip both when a maltyweb change spans logic + rendering.

## Architecture: where things live

```
maltytask/
├── lib/
│   ├── repos/*.ts                  TypeScript repos — canonical MySQL accessors with logAudit()
│   ├── audit-log.ts                logAudit() emits to data/audit-log.jsonl
│   ├── enums.ts                    Zod enums (dropdown values, RQ types, parser names)
│   ├── invoice-parsers/            Parser plugins (parser-coder skill)
│   ├── repos/mysql-pool.ts         withConnection / closePool — only DB accessor
│   ├── mi.js                       Legacy BSF-backed MI loader (Phase D to migrate)
│   ├── sheets.js                   getSheetsClient(), ensureTab() — BSF/EXT/COGS access
│   └── config.js                   Spreadsheet IDs, KEY_FILE, EUR_CHF
├── scripts/
│   ├── _typed-*.ts                 TS rewrites of legacy JS scripts (Phase A)
│   ├── _phase-g-*.ts               Phase G ad-hoc tooling
│   ├── phase-g-bulk-reingest.ts    Bulk re-ingest from /mnt/c/Users/Kouros/Desktop/Factures/
│   ├── ingest-one-local.ts         Per-file ingest with oracle-override hook
│   ├── build-*.js / .ts            Computed-view producers
│   └── reconcile-master-data.js    Unified reconciler (BSF Suppliers + MI + RQ)
├── data/
│   ├── audit-log.jsonl             append-only audit stream — gitignored
│   ├── invoice-log.json            raw OCR + extracted (legacy mirror)
│   ├── snapshots/                  every destructive op writes a snapshot here
│   ├── ocr-cache/                  Tesseract output keyed by file UUID
│   ├── phase-g-oracle/             operator-audited xlsx data + per-parser diffs
│   └── oracle-overrides.json       OCR-degraded invoices' operator-truth lines
└── reports/                        pre-commit code-review logs

maltyweb/ (sibling — VPS at 83.228.215.243)
├── public/css/, public/js/         Always external — never inline in PHP
├── scripts/python/ingest_*.py      Form → maltytask MySQL writers (run on VPS)
├── scripts/migrate.php             PHP migration runner
├── bin/deploy.sh                   rsync local → VPS (--apply for live)
└── data/entity-overrides.json      raw human-curated GL pins / aliases
```

## Canonical store: MySQL `maltytask` DB

Since 2026-05-12, **MySQL on the maltyweb VPS is the only canonical surface**:

- `ref_mi`, `ref_mi_categories`, `ref_mi_subcategories`, `ref_mi_aliases` — MI master data
- `ref_suppliers`, `ref_supplier_aliases` — supplier master
- `ref_sku_bom` — SKU BOM
- `inv_deliveries` — canonical accounting Deliveries store
- `doc_files`, `doc_invoices`, `doc_invoice_lines`, `doc_delivery_notes`, `doc_ambiguous`, `doc_review_queue` — document pipeline
- `inv_rm_stocktake` — RM stocktake (Phase G target)

**BSF tabs (MasterIngredients, Suppliers, Deliveries, ReviewQueue, RM_StockTake) are read-only legacy views.** Crons that mirrored BSF → MySQL have been disabled. **Do not** write a BSF mirror "to keep legacy scripts in sync" — those scripts need migrating, not propping up (see [[stop-bsf-mirrors-mysql-canonical]] memory).

### `bd_*` v1 tables are FORBIDDEN reads — use `_v2` only (ASAP decommission, operator standing order 2026-06-11)

The brewing/fermentation tables exist in two generations. **The `_v2` table is the only canonical source; the v1 sibling is being erased.** v1 keeps getting read by mistake and that "cannot happen anymore" (operator). Whenever you touch any `bd_*` read:

- **Grep first:** `grep -rnE "\b(bd_brewing_brewday|bd_brewing_cooling|bd_brewing_gravity|bd_brewing_ingredients|bd_brewing_ingredients_parsed|bd_brewing_timings|bd_fermenting|bd_packaging|bd_racking)\b" <files>` — any hit **without** a `_v2` suffix is a v1 dependency to kill, not preserve. Confirm zero v1 reads before declaring a `bd_*` read correct.
- **v2 is cleaner, NOT lossy.** v1 carries duplicate brew rows and missing brews (e.g. `bd_brewing_cooling` rid=51 b52 had a duplicated brew → inflated volume; v2 is deduplicated/complete). Repointing to v2 *corrects* numbers. When the read feeds COGS/tax (sku-bom batch-HL, beer-tax OG), the correction MOVES money — produce a before/after delta and get operator sign-off before landing.
- **Mapping facts (verified):** `bd_brewing_cooling` → `bd_brewing_gravity_v2 WHERE event_type='Cooling'` (cols `cool_final_gravity`→`final_gravity`, `cool_final_volume_hl`→`final_volume`; **no `event_date` col** — date proxy is `DATE(submitted_at)`). EPH recipes get re-IDed per vintage, so v1 `*_recipe_id` is stale vs v2 `recipe_id_fk` — join by the v2 FK or beer name, never v1's recipe id.
- **`recipe_id_fk` is NULL on recent v2 rows** in `bd_brewing_timings_v2` (and others) — key timings reads on `(beer, batch)`, not `recipe_id_fk`. (The NULL FK is itself an ingest-root defect to fix, not key around forever — see [[feedback_minimize_null_via_masterdata]].)
- **`start_ferm` is 100% NULL in `bd_brewing_timings_v2`** (the v2 form captures `brew_start`/`brew_end`/`event_date` instead). Anchor fermentation day-0 on `COALESCE(MAX(STR_TO_DATE(start_ferm,'%d.%m.%Y %H:%i:%s')), MIN(event_date))` — do NOT read v1 timings for it.
- **`beer_raw` is NOT an identity key.** Its write-encoding flipped from `'PREFIX BATCH'` (e.g. `MOO 125`) to bare canonical name (`Moonshine`) ~2026-05-29. Any code reverse-parsing `beer_raw` silently drops post-May rows. Key brewing/fermentation reads on `(recipe_id_fk, batch)` (or `(beer, batch)` where FK is NULL), never on a parsed `beer_raw` string.
- **KEEP (NOT v1, despite no `_v2` suffix):** `bd_packaging_readings` (child of `bd_packaging_v2`, keyed by `packaging_v2_id`), `bd_cip_events`, `bd_tank_readings`. Do not "decommission" these.

PM owns the live decommission plan + reader checklist: `maltyweb-pm-memory/v1-bd-tables-decommission.md`. Consult it before any `bd_*` work.

Access:
```ts
import { withConnection, closePool } from '../lib/repos/mysql-pool';

await withConnection(async (conn) => {
  const [rows] = await conn.query('SELECT … FROM ref_mi WHERE …', [params]);
});
await closePool();
```

Tunnel must be active on `127.0.0.1:13306` (operator owns this). Env loaded automatically from `~/.config/maltytask/db.env` by dotenvx.

## TypeScript migration (strangler-fig)

- **TS can call JS, JS does NOT call TS.** `lib/repos/*.ts` may `require('../mi.js')` to delegate.
- **Need typed-repo behavior in a JS script? Rewrite the script to TS.** Delete the JS original in the same commit — no parallel write paths.
- **Runtime:** `npx tsx scripts/foo.ts` for TS. `node scripts/foo.js` for JS. No build step. No `dist/`.
- **New scripts default to TS** (when the legacy is fully replaceable) — operators learn `npx tsx` for new things.
- **Repos own the schema.** All Zod schemas for production data live in `lib/repos/*.ts`. Every field added/changed echoes into the eventual Postgres seed (Phase B).
- **Existing typed repos:** `deliveries.ts`, `mi.ts`, `review-queue.ts`, `invoice-log.ts`, `delivery-note-log.ts`. Direct `ref_mi` / `ref_suppliers` / `ref_sku_bom` reads still go via prepared SELECTs on `mysql-pool.ts` (Phase D will add typed repos).
- **`compute-weighted-prices`** is `.ts` and reads MySQL `inv_deliveries` (not BSF).

## Audit log discipline

**Every write through a typed repo (or any direct INSERT/UPDATE/DELETE on a canonical table) MUST emit a `logAudit({…})` event.** Schema:

```ts
import { logAudit } from '../lib/audit-log';

logAudit({
  actor:    'phase-g-cleanup',                  // who/what
  action:   'rq-auto-resolved',                 // verb-noun
  entity:   'doc_review_queue',                 // table name
  entityId: row.id,                             // PK
  before:   { status: 'open' },                 // pre-state
  after:    { status: 'resolved', decision: '…' },  // post-state
  meta:     { …context-specific… },              // file_name, invoice_ref, etc.
});
```

The audit log (`data/audit-log.jsonl`) is the forward event stream that becomes Phase B's Postgres seed. It is the only canonical record of who-changed-what. Do not skip it.

## Verify don't speculate

The codebase value system: **verify everything before writing**. Bullet points from past incidents:

- **Never speculate on mappings**: do not guess MI codes, GL accounts, supplier names from price/pattern. Read the raw source and surface for operator validation.
- **Schemas must match production byte-for-byte**: 0 parse errors against a full live read. If >5% of fields need `.unknown()` or `.optional()`, the data is more heterogeneous than expected — surface it, don't paper over.
- **Never cap rows OR columns** when reading Sheets: `A:CN` / `A:ZZ` open-ended ranges. Capping silently hides data.
- **Re-read between destructive ops**: every delete/insert shifts rows below. Always look up by content, not by saved row number.
- **Verify before chmod/permission/path assumptions**: WSL paths use `/mnt/c/`, VPS paths use `/var/www/maltytask/`. They differ.
- **A green test on an empty/trivial window proves nothing — exercise the actual targeted case.** A fix for "same-day events" verified on a day with zero same-day events, an idempotency fix "verified" by re-running a path that never had the dup, a dedup checked against data that happens to have no dups: the assertion passes because the new code path was never hit. **Construct a synthetic fixture that forces the exact condition the change targets** (inject a same-day order; a divergent-anchor transfer; a second row that should collide), assert the new behaviour WITH it live, then self-clean (fake 9999-range ids, DELETE same session — see [[feedback_test_fixtures_must_self_clean]]). The fixture IS the verification; "the invariant still holds today" without it is a non-test. (2026-06-10: a same-day-cutoff fix passed `Σcards==Σphysique=8599` only because that day's same-day window was empty; the real bug surfaced only under an injected same-day eshop order + divergent-anchor transfer.)
- **Two functions bound by a hard invariant must refine in lockstep, or the invariant silently breaks.** When `Σ(decomposition) == total` couples a per-X breakdown to a global aggregate (e.g. `fg_stock_location_snapshot` vs `fg_stock_compute`, both keyed off the same anchor), they must use **identical per-leg cutoffs/filters**. Refining one leg in one function (a tighter same-day predicate in the snapshot) while leaving the other coarse (strict global cutoff in compute) makes them disagree on the same fact the moment the refined path fires → the invariant breaks, undetected until live data hits the edge. Before refining any leg of an invariant-coupled pair, grep the sibling function for the same leg and change both — or prove the sibling is structurally exempt (e.g. transfers are globally net-zero so the aggregate carries no transfer term; the breakdown must then gate transfers as an atomic unit to preserve net-zero). (2026-06-10, `app/fg-stock.php`.)

## Verification commands

```bash
# Type-check TS
npx tsc --noEmit -p .

# Test (none yet repo-wide; verify by direct invocation)
npx tsx <script.ts> --dry-run

# Sheets dry-run
node <script.js> --dry-run

# Direct MySQL inspection (preferred to running scripts when investigating)
npx tsx -e "const { withConnection, closePool } = require('./lib/repos/mysql-pool'); (async () => { await withConnection(async (conn) => { const [r] = await conn.query('SELECT …'); console.log(r); }); await closePool(); })();"

# SSH to VPS for maltyweb DB reads
ssh maltyweb 'mysql -e "SELECT … FROM …"'

# Run remote ingest
ssh maltyweb 'cd /var/www/maltytask && python scripts/python/ingest_<tab>.py --apply'

# Deploy maltyweb
cd /home/kluk/projects/maltyweb && bin/deploy.sh --apply
```

## Anti-patterns (post-mortem catalog)

Named for memorability; each was a real incident.

- **Backfill dupes against bsf-mirror**: pre-insert SELECT must dedupe against existing `source_origin='bsf-mirror'` rows, not just self. 94% of one backfill was duplicate before we added this. Migrate `file_id_fk` to the canonical bsf-mirror row, not the new one.
- **deploy.sh strips +x on .mjs**: `npm ci` VPS-side removes execute bit on `tsx`, `esbuild`. Pipeline silently dies with `Permission denied`; `doc_uploads.pipeline_status` stuck at `'triggered'`. Manual `chmod +x` workaround after every deploy until fixed.
- **Ingest must store REAL PDF bytes on the VPS, never symlinks**: `moveToProcessed` used `fs.renameSync`, which preserves a symlink. A local batch ingest that symlinked `/mnt/c/.../Desktop/Factures/*` into the inbox produced `processed/{file_id}.pdf` symlinks that DANGLED when rsynced to the VPS (the `/mnt/c` target doesn't exist there) → 175/191 invoices had no viewable PDF and the sources were later deleted. Dereference (`fs.copyFileSync`) so `processed/` always holds real bytes, and push to the VPS archive when run locally (the ingestion function is the single channel). Canonical archive = `/var/www/maltytask/storage/documents/processed/{file_id}.pdf`. See [[ingest-real-bytes-not-symlinks]], [[vps-document-pdf-archive]].
- **php-fpm runs with empty PATH — use absolute binary paths in web `exec()`**: the FPM pool has `clear_env` defaulting to `yes` and `env[PATH]` commented out, so web workers have no PATH. Bare `exec("pdftoppm …")` from a PHP page fails (CLI works because the login shell has PATH). Symptom: the PNG preview 502s/won't load but the full-PDF serve (no exec) works fine. Resolve CLI tools by absolute path (`/usr/bin/pdftoppm`) in any web-context exec; or pre-warm the derived cache so the web path needs no exec.
- **PHP 8 strict after column-type migration**: VARCHAR → INT changes break `preg_replace` / `strpos` callers (TypeError vs PHP 7 silent coerce). **Grep PHP consumers** before merging any column-type migration.
- **maltyweb DB modeling**: when cleanup reveals weak modeling (multiple mutually-exclusive `_id` cols + no CHECK), refactor toward proper ENUM type + N exclusive cols + CHECK constraints rather than patch in-place. MaltyTask aims for durable modeling.
- **Fan-out schema change silently double-counts in per-row-summing consumers**: relaxing a UNIQUE to allow multiple rows per a key that was 1:1 (e.g. one hop line → one row per hop *stage*) is safe for the table itself, but any consumer that **sums per row** — a gap-fill loader, a COGS/COP accumulator that adds each row's CHF — now double-counts that key unless it dedups/sums **by the key**. The fix MUST ship in the SAME atom/commit as the migration: never land the schema change that *enables* the fan-out before the consumer fix that makes it count-safe. Blast-radius-grep every per-row-summing reader, add dedup-by-key with a unit guard (don't blind-sum mismatched units — `error_log` + skip, never silently mis-sum), and add a two-rows-same-key test. (2026-06-01, `ref_recipe_ingredients` multi-stage hops → `recipe-ingredients-loader.php` gap-fill + `warehouse.php` COP accumulator; mig 253.)
- **Sonnet agents leave helper scripts in repo**: explicitly forbid `scripts/<bundle>-helper-*.js` files; verify with `git status` post-agent-run.
- **Deliveries tab formula-driven cols A/E/F/I/M/N/R**: STRICT — never write static values to these. Write to source cols (B/C/D/G/H/J/K/L/P/Q/S/T/V) and let arrayformulas compute. Writing to A/E/F/I/M/N/R breaks the arrayformula spread and silently corrupts downstream.
- **Sheets clear: use `values.clear`, not `batchUpdate` with `""`**: empty-string write leaves cell user-occupied, blocks arrayformula spill.
- **Sheets alias-aware VLOOKUP gotchas**: `MAP/BYROW/SEARCH + MATCH(1, ..., 0)` is the working pattern. `FIND + MATCH(TRUE)` silently fails.
- **BOM parser pitfalls**: unbounded `A:ZZ` ranges, `continue` on blank rows, robust `_parseNum` for Swiss/EU/UK formatting all required.
- **RQ rows must be self-sufficient**: never emit `(unknown)`/`(none)`/`0.00` stubs. Parse filename for supplier+ref. Operator should never need to open the PDF to decide.
- **No invoice-no-dn RQ rows by default**: orphan-scan Scan 2 disabled by default — invoices without DNs are workflow noise. Opt-in via `--include-invoice-orphans`.
- **Re-read tab between destructive ops**: row indices shift. Lookup by content.
- **Never cap rows OR cols**: open-ended ranges everywhere.
- **No backwards-compat hacks**: don't rename unused `_vars`, don't re-export removed types, don't add `// removed` comments. If unused, delete cleanly.
- **No comments unless WHY non-obvious**: don't restate code in prose. Only write a comment when removing it would confuse a future reader (hidden constraint, subtle invariant, workaround for specific bug).
- **No half-finished implementations**: don't ship partial features behind flags "for later". Either complete or revert.
- **No fixture tests v1**: parser/script verification is end-to-end via harness + direct invocation, not via Jest fixtures. Fixtures rot; live tests catch real regressions.
- **Simple SQL UPDATE beats overkill script**: when the fix is a uniform pattern (e.g. "divide all qty by 10"), direct UPDATE is cleaner than a dispatcher-style typed-repo script. Don't over-engineer one-shot data corrections.
- **Sheets-writes require explicit per-run approval**: never auto-run `--live` writes against BSF/COGS/EXT. Operator runs or grants one-shot approval per CLAUDE.md.
- **`ensureTab()` clears all content** — safe ONLY for computed tabs (Suppliers, COGS_Report, etc.). Never use on transactional tabs (Deliveries, BrewingData, FermentingData, RackingData, PackagingData, StockTake, InventoryData).
- **Lockfile drift — `npm ci` on the VPS, never `npm install`**: deploy uses `npm ci`, which fails if `package-lock.json` is out of sync. Add deps locally, commit the lockfile, then deploy. Running `npm install <pkg>` on the VPS (or in a stray shell without committing the lockfile) silently diverges the dependency tree from what CI/deploy installs. Verify the lockfile is committed (`git ls-files package-lock.json`) and not stale (`npm install --package-lock-only --dry-run` shows no changes).
- **Look up a normalized-keyed map with the SAME normalizer — never a raw `.toLowerCase()`**: if a Map is keyed by `normalize(name)` (which strips parens/diacritics/whitespace), looking it up with `map.get(name.toLowerCase())` silently MISSES every key whose normalized form differs from its lowercased form — and the code then falls through to a default/fallback that may not even exist, producing a cost/quantity of 0 with no error. Real bug (2026-05-27, `build-sku-bom.js` `getContainerName`): `masterByName.get("can zep (printed)")` never matched the `normalize()`'d key (parens stripped), so EVERY can fell to a phantom `"Can Ardagh 50cl"` fallback (no such MI) → can cost = 0 for all 19 canned SKUs; canned-product COGS understated by the entire can, invisibly. **Fix = use the resolver that applies the same normalizer (`findIngredient()`), never an ad-hoc `.toLowerCase()`.** RULE: the lookup key MUST pass through the identical normalizer the map was built with; and a "fallback to a default item" path is a silent-zero trap — if the default doesn't resolve, refuse/flag, don't silently cost 0.

- **RM stocktake form is per-pallet multi-line (line-append + rollup) — do not reintroduce the retired single-summed-input model.** The old model (one `counted_qty` field, one submit) was replaced by `inv_rm_stocktake_lines` + `rm_recompute_rollup()`. Brewing/racking/packaging forms remain single-summed-input for now.

- **Recompute-on-write rollup: raw-append child → canonical parent.** When a feature needs multiple independent measurements that roll up to a canonical total, use a raw-append child table + a shared rollup helper that re-sums on every write, rather than accumulating in memory and writing once. The child table holds one raw row per real-world event (e.g. one pallet in `inv_rm_stocktake_lines`); the canonical parent figure (`inv_rm_stocktake.counted_qty`) is RE-SUMMED on every child write via a shared PHP function. The rollup function should: (a) re-use the canonical upsert (`bd_upsert` on the natural key) so the parent row is idempotent, (b) emit `log_revision` for the parent write, (c) be called by BOTH the add and delete endpoints — not embedded inline in either. Children are soft-deleted (`is_active=0`) then rollup is recomputed; hard-delete is not used (no 'delete' action in `audit_row_revisions.action` ENUM). Canonical example: `app/rm-stocktake-rollup.php` driving `inv_rm_stocktake_lines → inv_rm_stocktake`. **NULL-vs-0 invariant is load-bearing for COGS:** zero active children → parent `counted_qty = NULL` (falls back to `expected_qty`), not `0`. An explicit `0` is a real "operator counted a stock-out" — it MUST come from a child line with `qty=0`. In the rollup SQL, use `SELECT COALESCE(SUM(qty), NULL) AS s, COUNT(*) AS n` then set `$countedQty = ($lineCount === 0) ? null : $agg['s']` — do NOT use `COALESCE(SUM(qty), 0)` which would silently erase the NULL-means-no-count distinction.

- **JSON keepalive endpoint — use `current_user()` not `require_login()`.** A read-only session-touch endpoint called by `fetch()` must use `current_user()` directly (which resets the idle clock and returns null on expiry) rather than `require_login()` (which `header('Location:…'); exit;` — the 302 breaks `fetch()` silently; the JS gets an HTML login-page response it can't parse as JSON). Return `401 JSON` on null, never a redirect. Always include a fresh CSRF token in the success response so the caller can rewrite `input[name="csrf"]` elements. Set `Cache-Control: no-store` — proxies must never cache this. Example: `public/api/session-ping.php`.

- **Instant per-item write endpoints — CSRF-first with fresh-token-on-fail for one retry.** The endpoint checks CSRF before any validation; on fail returns `{ok:false, reason:'expired', csrf:<fresh_token>}` + HTTP 401 so the JS can update its local token and retry once. After CSRF, do two-step input reading: read with `?? ''` default first, then validate (avoids PHP 8 NULL-to-string TypeErrors — see [[feedback_php_query_param_validate_after_default]]). Verify business-object existence with a DB read (e.g. confirm `ref_mi.is_inventoried=1` for the FK before inserting). Include `log_revision` (audit) on every write. Use soft-delete (`is_active=0`) for "delete" endpoints — `audit_row_revisions.action` ENUM has no 'delete' value; tombstone via `action='update'` + `is_active=0` in `after_json`. Return a fresh CSRF token in every success response so the client stays hot. Examples: `public/api/rm-stocktake-line-add.php`, `public/api/rm-stocktake-line-delete.php`.

- **In a PRG handler, build the validation domain (allowed-set, lookup map, whitelist) inside the POST path — not only in the GET render path.** Our forms follow Post/Redirect/Get: the POST branch validates the submission then `header('Location: …'); exit;`, so it NEVER reaches the code that renders the page on GET. If you build the set a field is validated against — e.g. `$allowedLinerMis = ref_mi WHERE subcategory='Liner' AND is_active` — down in the render section, that variable is unset/empty when the POST branch runs, so `in_array($posted, $allowedLinerMis, true)` is false for EVERY value and the handler quietly writes NULL (or rejects everything). It looks fine on screen because the GET render after the redirect rebuilds the set correctly — the corruption is invisible until you inspect the stored row. Real bug (2026-05-31, `form-packaging.php` liner-MI dropdown, mig 239): the liner allowed-set was built only in the GET render path, unreachable from the redirecting POST handler, so every submission would have nulled the liner FK; caught in commit-stage review, fixed by building the allowed-set in the POST handler before the per-format loop. **Why it generalizes:** any data a POST handler consults to validate or resolve a submission (allowed-sets, slot defaults, FK-existence checks, enum domains) must be loaded inside the POST path — POST and the GET render are different scopes that don't share locals. When reviewing a form change, trace the variable a validation reads from back to where it's assigned: if that assignment lives below the `exit` of the POST branch, it's dead from the handler's point of view. A quick guard against silent-NULL here: after validation, if the resolved value is NULL but the operator submitted a non-empty field, refuse/flag rather than persist the NULL.

- **A form-captured FK must be resolved server-side from the human field — never trust a hidden input that JS only syncs on `change`.** A hidden `<input name="recipe_id_fk">` populated by a JS `select.addEventListener('change', …)` handler is only written when the operator *actively re-picks* the option. On a preselected/sticky/edit-mode render, or when the operator edits other fields and submits without touching the dropdown, the `change` never fires → the hidden stays empty → the handler saves the row with the FK NULL while the free-text label column (`beer`) is still populated. The row looks correct on the originating form (it renders the text), but any downstream INNER JOIN on the FK silently drops it. Real incident (2026-06-15, `form-brewing.php`, mig 353): brewday batch 172 saved `beer='Stirling', recipe_id_fk=NULL`; the fermentation form's `JOIN ref_recipes ON r.id=recipe_id_fk` dropped it, and being latest-on-CCT it shadowed the prior valid batch → "aucun lot éligible". Same class as the `bd_brewing_timings_v2` NULL-FK bug (mig 334). **Fix = make the FK authoritative server-side:** in the POST handler, resolve the human field to the canonical id (`SELECT id FROM ref_recipes WHERE name=? AND is_active=1 GROUP BY name HAVING COUNT(*)=1` — `HAVING COUNT(*)=1` is the refuse-don't-guess guard), overwrite `$recipeId` with that, and `throw` if it doesn't resolve to exactly one row (refuse-don't-NULL, [[feedback_minimize_null_via_masterdata]]). One resolution point covers all write arms + the edit path; the hidden stays as harmless UX prefill. Ship a read-only `WHERE <fk> IS NULL AND is_tombstoned=0` probe as a backstop. RULE: a client-populated hidden carrying a canonical FK is advisory only — the server must independently resolve and validate it. See [[feedback_match_on_recipe_id_not_beer_name]] (write-side mirror).

- **A PHP function defined inside a conditional/render block is NOT compile-time hoisted — it fatals any call site reached earlier in execution.** PHP only hoists `function foo(){}` declarations at the **unconditional top level** of a file. A helper nested inside an `if`/`foreach`/another function (e.g. dropped into a view-render block "just before its first use") only becomes defined when execution reaches that block — so a *different, earlier* code path that calls it dies with `Call to undefined function`. `php -l` does NOT catch this (the syntax is valid; the function exists, just conditionally), and isolated-logic checks won't either. Real incident (2026-06-09, `expeditions.php`): `exp_dow_fr()` was placed right before `exp_st_freshness_chip` inside the stocktake render block, but the locations-overview fresh-chip calls it ~600 lines earlier → **500 on the taproom PF-stock page** the moment a count existed this week. Fix (`ae297c2`): hoist the helper to the file's top-level date-helper section (also kills a latent "cannot redeclare" if the render runs twice). RULE: put shared helpers in the unconditional top-level helper section, never nested. And this is exactly the "500 after deploy" class the post-rsync `curl` smoke battery (see Pre-deploy gate below) exists to catch — after a PHP UI change that adds a function call or touches a render path, actually LOAD the changed view; lint + isolated checks are not a smoke test. See [[feedback_php_nested_fn_not_hoisted_smoke_test]], [[feedback_php_function_exists_guard]].

- **Never hardcode operator-configurable data — read it from `system_settings` / `ref_sites` (the Zeppelin "Données générales" page).** Brewery identity (name, address, site city/country), display formats (date/time), interface language, the list of sites, and ANY value an admin can edit on the "Données générales" settings page live in the DB, NOT in code. Hardcoding them — even as a "reasonable default", a header, or a footer string — is a divergence: the moment the operator edits the setting, the code lies, and nobody notices until a customer-facing artifact (an email, a report header) shows stale/wrong data. Real incident (2026-06-04): an invite-email template footer AND the set-password page hardcoded `La Nébuleuse · Brasserie artisanale · Neuchâtel` / `Lausanne · CH` — both invented; the "Neuchâtel" was literally copied from a greyed-out `placeholder=` attribute on the city input in the settings form, while the real data was `system_settings.brewery_name='La Nébuleuse'` + the `ref_sites` production row = **Renens (1020), CH**. Fix = a `brewery_identity()` helper in `app/settings-helpers.php` reading `system_settings` (`section='general'`, `key_name=...`, value in `value_text`) + `ref_sites` (`ORDER BY id ASC LIMIT 1` for the main/production site), wrapped in try/catch with literal fallbacks so a settings read never fatals a page/email. RULE: before typing any brewery name, address, city, country, date/time format, or operator-facing label into code, check whether it's (or should be) a row in `system_settings`/`ref_sites` — if so, READ it. A `placeholder=` attribute is example text for humans, NEVER a data source. See [[feedback_system_settings_central_no_hardcode]].

- **A canonical list/value already exposed by a function or constant must be CALLED, never re-typed as an inline literal in a second consumer.** This is the single-source-of-truth rule one level below ref data: it also applies to a *computed* list that some function/const already owns (a stub-domain list, an allowed-role set, an enum-domain array, a feature-gate whitelist). The moment a second consumer hardcodes its own copy of that literal, the two drift the instant the canonical changes — and the copy is silently, invisibly wrong. Real incident (2026-06-10, `scripts/send-kpi-recap.php`): the per-user KPI **recap email** carried a frozen inline copy `$stubbedDomains = ['utilities','tanks','racking','packaging','fg_stock','sales','qa_qc','equipment','logistics']` — a pre-Phase-2b snapshot of the stub-domain blacklist. The canonical owner, `kpi_stub_domains()` in `app/kpi-handlers.php`, had since been emptied to `[]` (every domain got a real handler), and `mon-tableau.php` consumed it at all four gate sites — so the dashboard showed every selected KPI, but the email silently dropped every tracker in those nine domains. Users got only `wort`-domain tiles ("HL brassés") regardless of what they'd activated on their Mon tableau. Fix (`6b04666`): `if (in_array($t['source_domain'], kpi_stub_domains(), true)) return false;` — collapses to a no-op today AND tracks future stub regressions automatically, in lockstep with the dashboard. RULE: before typing a list/array/set/threshold literal into a consumer, grep for an existing accessor (`grep -rn "function .*stub\|kpi_stub_domains\|mt_build_allowed_set" app/`); if one owns that fact, call it. **Two surfaces that must agree (a dashboard and the email that mirrors it, a validator and the UI that renders the same domain) must read the SAME accessor** — never two literal copies kept "in sync" by hand. A literal copy of a canonical fact is the same parallel-store smell as a duplicate data table, just at the constant level. See [[feedback_canonical_list_call_not_copy]], [[feedback_system_settings_central_no_hardcode]].

- **A delegated agent's "I did not deploy/apply" is a CLAIM — verify VPS ground truth before assuming your change isn't live.** Build agents sometimes run `bin/deploy.sh --apply` or `migrate.php --apply` mid-task even when told "write code only, don't deploy" (to smoke-test). Result: your code/table can be LIVE on the VPS before you intend it. After any delegated build, re-probe rather than trust the report: `diff <(ssh maltyweb 'cat /var/www/maltytask/<file>') <local file>` (byte-parity = it's deployed), `ssh maltyweb 'cd /var/www/maltytask && sudo -u www-data php scripts/migrate.php --status'` (is your migration already applied?), and check the actual table/`schema_migrations`. Real incident 2026-06-11 (`seasonal-burn` build): an agent deploy.sh'd `fg-stock.php`+`expeditions.php` and applied `mig 325` despite "do NOT deploy" — discovered only by diffing local-vs-VPS (`fg-stock.php` was byte-identical = already live) and finding `/etc/cron.d/maltytask-seasonal-index` already installed. The end-state was correct (byte-identical to reviewed local), but had it diverged, the live page would have been serving unreviewed code. Generalizes [[feedback_verify_ground_truth_after_handoff]] to deploy/apply state, not just git.

- **Rolling/seasonal analytic = materialized-projection-of-SoT + single compute path, never a second definition.** For a derived metric over a large history (weeks-of-cover, a seasonal index, a velocity), split the work: a **cron** recomputes the stable/heavy part **wholesale** into a `kpi_*` cache table (a projection of the SoT, never hand-edited, idempotent TRUNCATE+rebuild-in-txn), and the **render path** reads that cache + computes the cheap per-entity part + runs the final combine. Keep ONE shared lib that both the cron and the render call (e.g. `app/seasonal-burn.php`: cron writes the index, render reads it + computes the per-SKU level + runs the sim) — never a second copy of the formula in a view or a `kpi_*` handler that recomputes differently (that's the parallel-store smell at the compute level). **Cold-cache must degrade, never 500:** if the cron hasn't run, the read returns a neutral fallback (flat 1.0 index → the sim collapses to the simple estimate) so the page always renders. Cron files ship **DISABLED** (commented schedule line) — see the cron-enable note. (2026-06-11, `kpi_sku_seasonal_index` + `seasonal-index-cli.php` + `fg_stock_compute` Step 8.)

- **Enabling a cron (`/etc/cron.d` install) is an Unauthorized-Persistence action — the auto-classifier DENIES it; ship the cron DISABLED and let the operator enable.** A `db/cron/*.cron` is deployed by `bin/deploy.sh` with its schedule line **commented out**; the steady-state cache is kept fresh by a manual `--apply` until the operator flips it. Do NOT `sudo cp`/`install` to `/etc/cron.d` or `sed`-uncomment the schedule yourself — it gets blocked as persistence the user never approved. Hand the operator the one-liner (`sudo sed -i '/<cli> --apply/s/^#//' /etc/cron.d/<name>`). Also: uncomment the schedule in the **repo source** when enabling, or the next `deploy.sh` silently re-disables it. (2026-06-11.)

- **Inline `php -r` DB probes against the maltytask PDO bootstrap: set `FETCH_NUM` (or use named keys), and don't re-`require` a dep the entry file already pulled in.** `maltytask_pdo()` defaults the fetch mode to `FETCH_ASSOC`, so `$row[0]`/`$row[1]` numeric indexing silently yields "Undefined array key" warnings + empty output — call `$p->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_NUM)` first, or read by column-name. And a throwaway probe that does `require "app/fg-stock.php"; require "app/seasonal-burn.php";` fatals "Cannot redeclare" because `fg-stock.php` already `require_once`s `seasonal-burn.php` — require only the entry file; its transitive deps load themselves. (Functions like `fg_stock_compute()` return a WRAPPER `['rows'=>[...], 'computed_at'=>...]`, not a flat list — index into `['rows']`.)

## Workflow integration

### Writer + reviewer pattern — commit-stage review is a HARD COMMIT GATE (operator standing order, mandatory)

**At EVERY commit stage, a fresh-context reviewer agent MUST run BEFORE the commit.** This is not a nicety reserved for "non-trivial" work — no commit lands without it. The reviewer audits BOTH dimensions below; findings are addressed, or explicitly recorded as accepted, before the commit lands.

0. **Blast-radius first.** Before changing a shared lib (`entity-resolve.js`, `mysql-pool.ts`, `mi.js`, `review-queue.js`) or a migration on a multi-consumer table, list who depends on it: `grep -rl "require.*<module>\|from.*<module>" scripts/ lib/`; for a table, `grep -rl "<table>" /home/kluk/projects/maltyweb/public/modules/ --include="*.php"`. Tier the risk (shared lib / table with 3+ consumers / ingest entry point = CRITICAL) and scope the review accordingly.
1. Writer agent (Sonnet) implements + reports
2. Reviewer agent (Sonnet, fresh context) reads the diff + relevant context. **(a) Coding soundness / correctness:**
   - Anti-patterns from above catalog
   - Logic, error handling, transactions, SQL safety (injection/whitelist), escaping/XSS, race conditions
   - House-convention adherence (PHP: auth + role gate + `csrf_verify` + `log_revision` + snapshot-before-write + PRG + `must_be_one_of` whitelist on interpolated identifiers; CSS/JS not inline)
   - **When writing or reviewing auth/session/SQL-adjacent PHP** (login flow, CSRF, tokens, TOTP, dynamic identifiers, input validation, password storage), read `references/php-security.md` in this skill directory for the full checklist — sessions entropy + rotation, cookie flags, `hash_equals`, Argon2id, dynamic-identifier whitelist pattern, and the 15-point quick-review checklist.
   - Schema drift (field names, types)
   - Missing `logAudit()` calls
   - Sheets-write to formula-driven cols
   - Hardcoded values that belong in MI/categories — OR an inline literal list/value that a function/constant already owns (stub-domain list, allowed-set, enum domain): the consumer must CALL the canonical accessor, not copy the literal (drifts silently when the canonical changes — see the canonical-list anti-pattern above; `send-kpi-recap.php` 2026-06-10)
   - **New env var the VPS doesn't define yet**: `git diff <base> HEAD | grep '^+' | grep -oE 'process\.env\.[A-Z_]+|getenv\(.[A-Z_]+'` → cross-check `ssh maltyweb 'printenv'`. A new `process.env.X` / `getenv('X')` landing before the VPS env / systemd unit defines it = silent undefined → partial failure.
   - **PHP→JS JSON contract break**: when the diff touches a `/modules/*.php` that `json_encode`s a response, grep `interfaces/` + `public/` for any renamed/removed key — the JS hydration layer breaks silently (no types across the boundary). Renamed key → emit both old+new temporarily, drop the old in a follow-up.

   **(b) Dead-code / garbage / redundancy scrapping (architecture cleanliness):** the reviewer ALSO checks whether the change has rendered anything **useless or redundant** — leftover unused functions/branches, redundant queries, superseded pages/scripts/modules/migrations, orphaned tables/columns, dead CSS/JS. A superseded surface left in place is the SAME smell as a parallel data store. Each finding is either cleaned up in this commit, or surfaced to `maltyweb-pm` to record in its scrapping/retirement backlog with a gating condition (retire only after the replacement covers it AND nothing routes to it). Never let dead code accumulate silently.
3. Opus reviews both reports + spot-checks diff, ensures every finding (both dimensions) is addressed-or-accepted, THEN commits.

### Destructive operations

Per CLAUDE.md "Executing actions with care":
- Snapshot before destructive ops (snapshot script writes to `data/snapshots/`)
- `--dry-run` first, ALWAYS
- Get explicit per-run approval from operator for `--apply` / `--live` / TRUNCATE / DELETE
- Even if a similar op was approved an hour ago — re-request approval if not in CLAUDE.md permissions

### MySQL write protocol

1. `SHOW COLUMNS FROM <table>` first — confirm column names + types
2. Write the INSERT/UPDATE to a `/tmp/<task>.sql` file
3. Show the SQL to operator, dry-run inspect counts that will change
4. On approval, apply via `withConnection(conn => conn.query(sqlOrPath))` 
5. Emit `logAudit()` for every row touched
6. Snapshot the affected table state to `data/snapshots/` before any UPDATE/DELETE that's not a single-row precision change

### TypeScript script lifecycle

When rewriting a JS script to TS:
1. Confirm the typed repos exist for the data this script touches (or accept rewriting some `mysql-pool.ts` SELECTs inline)
2. Plan the rewrite to **delete the JS original in the same commit** — no parallel paths
3. Emit `logAudit()` on every write (the typed-repo writes do this; raw SQL writes need explicit calls)
4. Use Zod schemas at boundaries (validate inputs from CLI / files)
5. Document the runtime change in CLAUDE.md if operator-visible (e.g. `node scripts/foo.js` → `npx tsx scripts/foo.ts`)

### Dependency audit

We have ~6 prod npm deps + a handful of pip deps and no vuln scanning anywhere. After any dep bump, and quarterly:
```bash
npm audit --audit-level=high                                   # maltytask
npm audit --json | jq '.vulnerabilities|to_entries[]|select(.value.isDirect==true)|{name:.key,severity:.value.severity}'  # direct first
pip-audit -r /home/kluk/projects/maltyweb/scripts/python/requirements.txt
osv-scanner --lockfile package-lock.json                       # cross-ecosystem, optional
```
Triage rule: **direct-dep** vuln → fix by version bump now; **transitive-only** high/critical with no `fixAvailable` → document in a `# AUDIT-ACCEPTED` note and re-check quarterly. Pin pip deps (the `numpy>=1.26`-style unpinned ranges drift). This is also why the third-party-skill caution matters — see the `skill-vetting` skill before installing any external skill.

### Pre-deploy gate, smoke test & rollback

We have no CI — the smallest viable gate runs on the operator's machine before bytes leave. Wire into `bin/deploy.sh --apply`, fail-fast BEFORE the rsync:
```bash
npx tsc --noEmit -p . || { echo "TS errors — deploy aborted"; exit 1; }
find public app -name '*.php' -exec php -l {} \; 2>&1 | grep -v 'No syntax errors' | grep . && exit 1 || true
# + parser-count / git-sha parity smoke (see parser-coder "Pipeline integrity")
DEPLOY_SHA=$(git rev-parse HEAD); ssh maltyweb "echo \"$DEPLOY_SHA $(date -u +%FT%TZ)\" >> /var/www/maltytask/.deploy-history"
```
A `.git/hooks/pre-push` running `npx tsc --noEmit -p .` is the two-line CI you can have today (hooks aren't committed — install via a `scripts/install-dev-hooks.sh` run once). **After** rsync, a `curl` smoke battery catches the "500 after deploy" class without any test framework (consistent with no-fixture-tests-v1 — it's infra probing, not unit testing):
```bash
for P in / /modules/fournisseurs.php /modules/document-viewer.php; do
  S=$(curl -s -o /dev/null -w '%{http_code}' "https://<host>$P" --max-time 10)
  [[ "$S" =~ ^(200|302|401|403)$ ]] || { echo "SMOKE FAIL $P → $S"; exit 1; }
done
```
**Rollback** (no git on the VPS — re-rsync the previous known-good sha): `PREV=$(ssh maltyweb 'tail -2 /var/www/maltytask/.deploy-history | head -1' | awk '{print $1}'); git checkout $PREV -b rollback/$PREV; cd ../maltyweb && bin/deploy.sh --apply`.

**Dirty shared tree → deploy surgically, never blanket-rsync.** `bin/deploy.sh --apply` rsyncs the WHOLE working copy (no `--delete`, but it pushes every changed file) — in a tree carrying parallel sessions' uncommitted work it ships their half-baked files to prod. When the tree is dirty with work that isn't yours, deploy ONLY your files with a **single-file targeted rsync that reuses `bin/deploy.sh`'s own sudo mechanism**: `rsync -avz --rsync-path="sudo rsync" -e "ssh -o BatchMode=yes" <localfile> ubuntu@83.228.215.243:/var/www/maltytask/<path>` then `ssh maltyweb 'sudo chown maltytask:www-data /var/www/maltytask/<path> && sudo chmod 644 /var/www/maltytask/<path>'`, then `php -l` the deployed PHP on the VPS (CSS/JS cache-bust rides on filemtime). **Do NOT hand-deploy via `scp /tmp/ + sudo cp` over ssh — the auto-classifier now DENIES that as a guardrail-bypassing prod write** (incident 2026-06-12: "Hand-deploying a code hotfix directly onto the live VPS via sudo cp over remote shell"); the `--rsync-path="sudo rsync"` form is the same sudo path `bin/deploy.sh` uses, scoped to one file, and passes. **Build the artifact from the currently-DEPLOYED file + only your edit** (not blindly from your local working copy, which may carry a parallel session's uncommitted change — e.g. a feature-gate wrapper that calls a not-yet-deployed function and would 500): start from `ssh maltyweb 'cat /var/www/maltytask/<path>' > /tmp/cur`, apply just your hunk, push that. **Verify the deploy delta** = `ssh maltyweb 'diff <(cat /var/www/maltytask/<path>) /tmp/<f>'`: the `>` lines must be exactly your feature, and every `<` line (something on the VPS your file would REVERT) must be the *before* side of YOUR intentional edit — never a parallel session's deployed work. **If a surgically-deployed PHP/CSS change doesn't appear live**, reload php-fpm (`sudo systemctl reload php8.1-fpm` — flushes opcache) and hard-refresh (browser CSS cache) before suspecting a code bug: a CLI `php -l`/`php -r` smoke passes against the file on disk while FPM may still serve stale bytecode (the CLI process shares no opcache with FPM). **Migrations in a dirty tree:** `scripts/migrate.php` applies ALL pending `.sql` in filename order — in a shared tree it sweeps a parallel session's pending migrations too. Rsync ONLY your migration to the VPS `db/migrations/`, then confirm `migrate.php --status` lists **only yours** pending before running it; if theirs appear, coordinate or apply yours out-of-band + `INSERT` its `schema_migrations` row. (2026-06-12: `mig 339 count_type` shipped cleanly once the parallel session's 337/338 had applied, leaving only 339 pending.) A file that carries both your change AND another session's already-deployed change deploys both: confirm the other change is byte-identical local↔VPS (so your deploy is a no-op for it) and note it in the commit. Real incident 2026-06-10 (`maltyweb 4af2155`, réassigner-cuve): `form-packaging.php` held the feature + a parallel mig-313 `objective_hl` wiring; the local↔VPS delta showed `objective_hl` identical on both sides, so the 4-file surgical deploy touched only the feature. This is the deploy analog of the shared-git-index race below.

### Commit discipline

Use conventional-commit scopes so `git log` is machine-parseable and `docs/changelog.md` entries flow from `git log --since … --pretty='- %s'`, and so parser version-pinning (parser-coder "Pipeline integrity") has a clean audit trail:
`feat|fix(parser|ingest|schema|ui|cogs)`, `chore(ref|deps)`, `docs`. MAJOR-bump triggers: schema changes that break ingest idempotency, or MI/GL routing changes that invalidate historical COGS.

**Stage by named files, never `git add -A`** — long sessions accumulate unrelated untracked work that a blanket add bundles under the wrong message. See [[feedback_git_add_specific_files_not_dash_a]].

**Concurrent sessions sharing ONE clone race on the shared git index.** If a second session (e.g. a parallel build, another agent) is working in the same working copy, the index is shared: files it stages between your `git add` and your `git commit` get swept into YOUR commit — even if you staged only your own hunk via `git apply --cached`. Real incident 2026-06-10 (`maltyweb 6a013ad`): a Financier-page commit swallowed a parallel session's FG-stock suite + a migration. Code was correct, but the boundary was mixed. **Prevent it:** give each parallel session its own clone or `git worktree`; or serialize commits (one session idle while the other commits). **Isolating one hunk from a concurrently-edited file:** `git diff <file> | awk '…' > p.patch && git apply --cached p.patch` stages just your hunk (index-only) — but it still races at the commit step. **Never `reset`/`rebase` to fix a mixed commit on a live shared branch** — it collides with the other session's tree; leave it, flag it to the user + PM, move on. **Entanglement can self-resolve — re-check before assuming it.** A file that looked entangled earlier may be clean now if the other session committed its work in the interim: before committing, run `git diff HEAD -- <file>` and confirm the remaining hunks are 100% yours (grep for the other feature's tokens — if none appear, the parallel work is already in HEAD and a wholesale commit of that file is clean). Then stage by explicit pathspec and **verify the staged set before committing**: `git add -- <my files> && git diff --cached --name-only` must list exactly your files and `git status --short | grep '^??'` must show the parallel session's untracked files (new migrations, `data/*` scratch) still UNstaged. Real success 2026-06-11 (`maltyweb 6228e8a`): an 8-file feature committed cleanly this way — the morning's "entangled" `expeditions.php` had since been committed by the parallel session, so the afternoon delta was pure-mine, and the verify-staged step kept a stray parallel `mig 330` + a `data/merge-suggestions.md` out.

### Permissions (pre-approved vs requires approval)

Per CLAUDE.md:

**Pre-approved (no per-run prompt):**
- Reading files, running dev server/tests, git operations (commits ARE pre-approved when explicitly asked; pushes follow)
- maltyweb DB reads/writes via SSH
- `bin/deploy.sh --apply` for maltyweb code rsync
- VPS-side `python scripts/python/ingest_*.py --apply` runs (writes to maltyweb DB only)
- `php scripts/migrate.php` on VPS
- Editing files in `/home/kluk/projects/maltyweb/`

**Requires approval:**
- Creating/editing files in `/home/kluk/projects/maltytask/` (every Edit/Write tool call goes through operator approval)
- Installing packages
- Deleting anything
- Google Sheets/Drive writes (reconcile-master-data, build-sku-bom --live, add-delivery, add-ingredient, bootstrap-review-queue, etc.)
- MySQL writes against canonical maltytask DB (TRUNCATE, bulk INSERT into ref tables)

When the line is fuzzy: ask. The cost of pausing is low; the cost of an unwanted canonical write is high.

## When you're done

Do not declare success unless ALL of:
- Type-check passes (`npx tsc --noEmit -p .`)
- For any MySQL/Sheets write: dry-run shown to operator + explicit approval recorded
- For any destructive op: snapshot written to `data/snapshots/`
- Every write emits `logAudit()` with the right actor/action/entity/before/after/meta
- **Commit-stage reviewer pass completed (separate fresh-context agent) BEFORE the commit — HARD GATE.** Both dimensions covered: (a) coding soundness/correctness, (b) dead-code/redundancy scrapping. Every finding addressed or explicitly accepted. No commit lands without this.
- Architecture-cleanliness check done: anything the change made useless/redundant is cleaned up in-commit, or recorded in the `maltyweb-pm` scrapping/retirement backlog with a gating condition
- `git status` shows only intended files staged (no helper scripts left behind)
- Memory updated if the work surfaced a new convention or anti-pattern (see [[sonnet-agents-for-coding]] for memory hygiene)
- Re-ingest / re-build / re-compute downstream views if the canonical DB was touched

If the bar isn't met, leave the change in a partial branch and surface the gap. Don't claim done.
