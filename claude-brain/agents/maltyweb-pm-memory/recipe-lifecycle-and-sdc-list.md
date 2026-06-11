# Recipe lifecycle & SDC "Recettes" 3-list redesign

> Load when touching SDC Recettes UI, recipe lifecycle/status, the 3-list (Contrats / Actives / Passées) redesign, recipe vintage collapse, or `v_recipe_lifecycle`. Ruled 2026-05-31; **stored-flag ruling OVERRIDDEN to FULLY DERIVED 2026-05-31 (see §OVERRIDE).**

## The surface
SDC (Salle de Contrôle) "Recettes" page lists brewery recipes for the operator. Redesign = 3 lists: **Contrats** (contract-brew recipes), **Recettes actives**, **Recettes passées**.

## ✅ FINAL MODEL — FULLY DERIVED, NO STORED FLAG (operator override, 2026-05-31)
Lifecycle state is **derived at read time** from brew + stock signals. NO migration, NO `production_status` column, NO operator declaration. State self-updates as signals change. Rule for ALL recipes (operator verbatim: "EPH will move back and forth from active to discontinued and back depending if brewed, in stock, neither, or new recipe created and so upcoming again. basically that should be the default rule for all"):

- **never brewed (ever)** → `upcoming` (production à venir)
- **brewed within recency window W** → `active` (produite)
- **NOT within W but in stock** → `plus_produite` ("plus produite" / discontinued) — STAYS in Recettes actives
- **neither (not within W, no stock)** → `passee` → Recettes passées
- **`is_active=0`** → forced to `passee` (Recettes passées) regardless of signals

Off-season seasonal flip (EPH goes `plus_produite` in off-season, back to `active` when rebrewed) is **intended behavior, operator pre-accepted** — NOT a bug.

### Lists
- **Contrats** = `classification='Contract'` (independent of lifecycle).
- **Recettes actives** = Neb & state ∈ {upcoming, active, plus_produite}.
- **Recettes passées** = Neb & state = passee (incl. is_active=0).
- Newest-vintage collapse applies to all three (fixes EPH oldest-vintage bug).

## Decisions locked with operator (2026-05-31)
1. **One entry per recipe** — collapse vintage rows (EPH1|2025, EPH1|2026 → one EPH1), show newest vintage.
2. **In-stock test** = qty>0 at the LATEST inventory period (monthly `inv_fg_stocktake` now, weekly soon — read "latest period" generically, MAX(month_closed)).
3. **upcoming→active is read-derived** (brewed-ever → not-upcoming).
4. **is_active=0 → Recettes passées.**

## §OVERRIDE — why the stored flag was dropped
Earlier ruling was HYBRID: store `production_status ENUM('upcoming','active','discontinued')` on ref_recipes because "still in rotation" is a business decision. **Operator overrode**: the four-way state IS fully derivable from observable signals (ever-brewed / brewed-in-W / in-stock), so a stored flag is redundant AND stale-prone (operator would have to keep flipping it as seasons turn). Derivation makes the state self-maintaining. **PM verdict: BLESS the derived model — no real failure mode beyond the off-season flip, which is pre-accepted.** Migration DROPPED.

## §RULING DETAIL (PM, 2026-05-31)

### 1. Window W
- **Default W = 12 months.** Only knob. MUST live as a central system setting (Données générales / `commissioning_settings`, per house no-hardcode rule), NOT hardcoded. Proposed: `commissioning_settings.section='recipe_lifecycle' key='active_window_months' value_num=12`.
- **Measure W relative to CURDATE()** (real calendar time), NOT relative to the latest brew event in the system. Calendar-relative is what makes a recipe genuinely "go quiet" — operator's intent ("depending if brewed... recently"). Latest-brew-relative would keep the whole fleet "active" forever as long as ANY brew happens. `event_date >= DATE_SUB(CURDATE(), INTERVAL :W MONTH)`.

### 2. Derivation shape = SQL VIEW `v_recipe_lifecycle`
Reusable view (drill-in, future commercial surfaces, KPIs all reuse), NOT inline-in-PHP. View computes one row per recipe identity with the collapsed newest-vintage id + derived `lifecycle_state`. **Window W is read by the PHP/caller and passed as a bound param** — a VIEW can't read `commissioning_settings` per-call cleanly, so: view exposes `last_brew_date` + `in_stock` + `ever_brewed` + `classification` + `is_active` + collapse identity, and the caller derives `lifecycle_state` from those + W; OR the view bakes a `CASE` using a scalar subselect on `commissioning_settings`. **PM pick: view exposes the raw signals (last_brew_date, in_stock_latest, ever_brewed) and the collapse key/newest id; the lifecycle CASE lives in ONE shared SQL fragment/PHP helper that injects W from the setting.** Keeps W single-sourced and the view stable. (If the coder prefers a self-contained view, bake W via `(SELECT value_num FROM commissioning_settings WHERE section='recipe_lifecycle' AND key_name='active_window_months')` scalar subselect — acceptable, but the helper-injection path is cleaner for "weekly soon" period changes.)

### Canonical signal joins (ALL VERIFIED LIVE 2026-05-31 via SHOW COLUMNS — bind directly)
- **brew signal:** `bd_brewing_brewday_v2 b` on `b.recipe_id_fk = <recipe id>` (col is `recipe_id_fk` INT UNSIGNED), `b.is_tombstoned=0`, aggregate `MAX(b.event_date)` = last_brew_date (`event_date` DATE; NO `brewday` column); `ever_brewed = MAX(event_date) IS NOT NULL`. (`MAX(event_date)` live = 2026-05-22.)
- **stock signal:** `inv_fg_stocktake f` → `f.sku_id_fk = ref_skus.id`, **`ref_skus.recipe_id`** (col is `recipe_id`, NOT `recipe_id_fk`; `ref_skus.format_id` is the all-NULL one) `= <recipe id>`, `f.is_active=1`, `f.month_closed = (SELECT MAX(month_closed) FROM inv_fg_stocktake)` (latest period, generic; live = '2026-04'), `SUM(f.qty) > 0` (col is `qty` DECIMAL(10,4)) = in_stock. ⚠️ COLLATION: `bd_*` = utf8mb4_0900_ai_ci, `ref_*`/`inv_*` = utf8mb4_unicode_ci — these joins are all on INT FK columns so no collation issue, but if any name-join sneaks in, add explicit COLLATE.
- **contract:** `ref_recipes.classification = 'Contract'` (ENUM('Neb','Contract')).
- **is_active:** `ref_recipes.is_active` TINYINT.
- **identity collapse:** `ref_recipes.sku_prefix` VARCHAR(8) (verified present); newest vintage via `ref_recipes.vintage` VARCHAR(8) (verified present); `recipe_short_name` VARCHAR(64) is the fallback identity.

### 3. Identity / collapse key
- **Group vintage rows by `sku_prefix`** (e.g. all EPH1 vintages share sku_prefix). This is the recipe-identity that survives vintage. Do NOT group by `name` (carries vintage suffix) — that's exactly what breaks EPH today. `sku_prefix` VERIFIED present on ref_recipes live.
- **Brews + stock AGGREGATE ACROSS ALL VINTAGE ROWS of the identity** — a 2026 brew of EPH1 must light up the collapsed EPH1 row even if the displayed/newest id is a different vintage row. So: collapse partition = sku_prefix; signals summed over every recipe id sharing that sku_prefix; the DISPLAY id = the newest-vintage row (resolve newest via `ref_recipes.vintage`, fallback recipe id DESC — see reference_recipe_vintage_column_g_authoritative).
- ⚠️ Edge: `sku_prefix` is NULLABLE. Rows with NULL/empty sku_prefix CANNOT collapse-group safely — treat each as its own identity (group key = COALESCE(NULLIF(sku_prefix,''), CONCAT('id:',id))). The current SDC gate already drops NULL-prefix rows into a separate `$noPrefix` read-only list (L1394-1399) — preserve that behavior so a NULL-prefix recipe isn't silently merged with another.

### 4. Build sequencing (NO migration)
1. **System setting** — seed `recipe_lifecycle.active_window_months=12` (INSERT IGNORE, idempotent; this is data not a schema change, but still a migration FILE for traceability — next free mig number, re-check `migrate.php --status`). Surface it on Données générales as an editable knob (or at minimum a seeded row; UI exposure can be a fast-follow).
2. **View `v_recipe_lifecycle`** + the shared lifecycle-state helper/fragment (W injected).
3. **UI rewrite** of SDC Recettes → 3 lists consuming the view + helper.
4. **Validation pass** against the verified data context (see §EXPECTED below) — confirm each recipe lands in the right bucket.
- Skills for the Sonnet coder: `sql` (view + signal joins + setting seed) + `ui` (3-list SDC page, kraft palette) + `coder` (PHP list query, helper, deploy). RULE-2 gated, no self-commit.

### §EXPECTED bucketing (validation oracle, live 2026-05-31, latest FG period = 2026-04, W=12mo from CURDATE 2026-05-31 → cutoff ~2025-05-31)
- 9 Core: all brewed 2026 + in stock → **active**.
- EST (Archive): last brew 2025-11 (within W), 48 in stock → **active** (brewed within W; in-stock is moot). [prompt said "plus produite" but 2025-11 IS within a 12mo window from 2026-05 → active. FLAG: if operator wants EST as plus_produite, W is shorter than 12mo OR EST is is_active=0. RESOLVE with operator during validation.]
- BLO (Archive): last 2022, no stock → **passee**.
- EPH2: last 2026-05, no stock → **active** (brewed within W).
- EPH3: last 2024-08, no stock → **passee**.
- EPH4: 173 in stock, (brew date?) → **active** if brewed within W else **plus_produite**.
- EPH1: 22 in stock → **active**/**plus_produite** by last-brew-vs-W.
- Collabs: DGD 2026-03/208 stock → active; DOC 2025-11/27 stock → active; DIG 2025-02/no stock → **passee** (2025-02 is >12mo before 2026-05? no — 2025-02 to 2026-05 = 15mo → outside W, no stock → passee. ✓).
- 42 Contract → **Contrats** list.
- **⚠️ The EST discrepancy (prompt labels it "plus produite" but 2025-11 is inside a 12mo window) is the one thing to reconcile with operator: either W < ~6mo, or EST is is_active=0, or the prompt's "plus produite" expectation predates fixing W. This validates the window choice — surface it, don't guess.**

## §EPH sku_prefix backfill (RULED 2026-05-31) — mig 221, scope (a)
**Gap (VERIFIED LIVE 2026-05-31):** EPH = one ref_recipes row per vintage; `sku_prefix` populated ONLY on OLDEST vintage (EPH1→id58 v2022 / EPH2→id63 v2021 / EPH3→id68 v2021 / EPH4→id72 v2021); newer rows NULL. Operative (newest) = 62 / 76 / 71 / 75 → NULL prefix → SDC activate_format hits "Préfixe SKU manquant" guard (`salle-de-controle.php` L199). Lifecycle list collapses to operative row → EPH unactivatable.

**RULING = scope (a): backfill the OPERATIVE/newest row ONLY (62←'EPH1', 76←'EPH2', 71←'EPH3', 75←'EPH4'). Do NOT propagate to all siblings.**

**Why (a), not (b) — the collision IS real and (a) sidesteps it cleanly:**
- Activation path (L179-235): requires sku_prefix on the passed `$recipeId`; computes `$skuCode = $prefix . format_code` (e.g. EPH2+F=`EPH2F`); the existing-row check is keyed `(recipe_id, format_id)` (L214); BUT there is ALSO a hard collision guard `WHERE sku_code = ? AND NOT (recipe_id=? AND format_id=?)` (L222) backed by **`uniq_sku_code` HARD UNIQUE** on `ref_skus.sku_code`.
- sku_code derives from PREFIX ALONE (+ format_code), NOT from recipe id/vintage. So if TWO vintage rows of EPH2 both carry `sku_prefix='EPH2'`, activating format F on each mints the SAME `EPH2F`. The existing `EPH2F` row (id=33) is bound to recipe_id=63 (oldest). Operative 76 activating F → `(76, fmt15)` has no row → tries INSERT `EPH2F` → L222 collision guard fires ("Ce code SKU est déjà rattaché à une autre recette") OR if guard missed, `uniq_sku_code` HARD 1062. Either way EPH2 is STILL unactivatable from the operative row, and now BOTH vintages claim the prefix = parallel-ownership divergence. (b) does not even fix the problem.
- (a) makes only the operative row prefix-bearing → one minter per prefix → no dual-claim. The oldest row's existing skus keep their old prefix value implicitly via their already-stored `sku_code` (sku_code is a stored column, not recomputed).

**REQUIRED in the SAME migration — re-point the already-bound EPH ref_skus from oldest→operative so activation finds the existing row instead of colliding:** all EPH skus currently point at oldest (58/63/68/72). The `(recipe_id, format_id)` existing-row check (L214) must resolve against the OPERATIVE id or every format re-mints and trips L222. Repoint every EPH sku row's `recipe_id`: 58→62, 63→76, 68→71, 72→75 (35 rows total per the live dump). This is the load-bearing half — without it, scope (a) alone still collides because the bound rows sit under the oldest recipe_id. After repoint: operative activation finds the existing `(operative_id, fmt)` row → reactivate path, no new INSERT, no collision.
  - ⚠️ The 2 `format_id=NULL` EPH skus (EPH24P id=306, and one other) — repoint recipe_id too, but they won't match the `(recipe_id, format_id)` existing check (NULL fmt). Low risk (composite/legacy); flag in migration comment, don't special-case.

**Form = tracked migration `221_eph_sku_prefix_backfill.sql`:**
- `schema_migrations` PK is **`filename`** (VARCHAR(128)), there is **NO `version` column** — migrate.php idempotency = filename match. That's the "different PK column" you hit. Just name the file `221_*.sql`; no id/version to set.
- MAX applied filename = `218_*` live; 219 (W-window seed) + 220 (v_recipe_lifecycle view) are YOURS uncommitted → **221 is free**. Re-run `migrate.php --status` at apply.
- Idempotent guarded UPDATEs: `UPDATE ref_recipes SET sku_prefix='EPH2' WHERE id=76 AND sku_prefix IS NULL;` (×4) + `UPDATE ref_skus SET recipe_id=76 WHERE recipe_id=63 AND sku_code LIKE 'EPH2%';` (×4, guard on old recipe_id). NO bare SELECT in the file (migrate.php exec() leaves result sets open — use comments).
- `ref_recipes`/`ref_skus` corrections_policy = **`allowed`** (verified) → writes permitted. NO schema_meta row (no new table). NOT a tombstone (UPDATE not delete).
- **Audit:** ref_recipes + ref_skus are gated tables; per discipline emit `audit_row_revisions` rows (action='update', before/after JSON, user_id=0 system actor) for each touched id. A bare UPDATE without audit on a gated table is the gap the policy exists to close. (Note: audit_row_revisions.action ENUM('insert','update') — 'update' is correct here, no tombstone needed.)
- Apply order 219→220→221; 221 is order-independent of the view (touches ref_recipes/ref_skus, not v_recipe_lifecycle).
- Skills: `sql`+`coder` (NO `ui` — pure backfill). RULE-2 gated, no self-commit.

**Lifecycle-list interaction:** v_recipe_lifecycle collapses by sku_prefix (per §3). Today only oldest EPH rows carry sku_prefix → the collapse identity for EPH already works via the oldest row's prefix; but the DISPLAY/operative id (newest vintage) is what activation acts on. After this backfill the operative row carries the prefix too — confirm the collapse helper's `COALESCE(NULLIF(sku_prefix,''),CONCAT('id:',id))` still groups all 5 EPH1 vintages into ONE bucket (it will: now ids 58 AND 62 both ='EPH1', 59/60/61 fall to per-id buckets UNLESS the view aggregates across name too). ⚠️ FLAG: with only oldest+newest prefixed, the 3 MIDDLE vintages (59/60/61) still have NULL prefix → they'd land in separate `id:NN` buckets in the collapse. This does NOT break activation (operative is prefixed) but means the lifecycle view should group EPH by the prefix present on ANY vintage row of the name-line, OR the backfill should set prefix on ALL active vintages for collapse-correctness EVEN THOUGH activation only needs the operative one. RESOLVE: this is the ONE place (b) has merit — for COLLAPSE not for activation. Recommend: backfill operative-only for activation (this mig), and have v_recipe_lifecycle group by name-derived identity (not raw sku_prefix) for EPH-style multi-vintage lines, OR a follow-up that propagates prefix to all-active-vintages purely for grouping. Surface to operator; don't silently choose.

## §UPDATE 2026-05-31 — arc SHIPPED + D fix (contracts-never-upcoming)
- **migs 219/220/221 ALL APPLIED LIVE** (PM-verified `schema_migrations`): the 3-list arc shipped; `v_recipe_lifecycle` + `v_recipe_vol_band` are LIVE views. NEXT FREE MIG = 222.
- **TM-ST = id 55** (Contract, client_id=7, vintage='', sku_prefix='', is_active=1, never brewed, no stock) → view derives `upcoming` → wrong; operator says pre-2021 historical → `passee`.
- **`created_at` is dead as a history signal** — all 76 rows = identical bulk-import ts `2026-05-07 15:11:12`. No vintage/created_at column distinguishes pre-2021 from new.
- **D RULING (mig 222, NEW `CREATE OR REPLACE VIEW`, do NOT edit applied 220):** gate the `upcoming` arm on `classification='Neb'` — **Contracts never get `upcoming`** (brewed-on-demand for a client, never a Neb forward pipeline; never-brewed-no-stock Contract = `passee`). Stays fully-derived, no stored flag. Operator-confirm: only if a genuinely-new contract could legitimately read upcoming → fall back to a minimal `lifecycle_hint ENUM('auto','historical')` for that one underivable case. Full plan → recipe-ingredients-drift-surface.md §CONSOLIDATED 4-ITEM PLAN.

## §EPH UPSTREAM-EVENT CONSOLIDATION — ✅ SHIPPED + LIVE + PRODUCTION-PROVEN 2026-06-04 (mig **263**, NOT 261)
> **AS-BUILT (verified live by PM 2026-06-04):** mig `263_eph_consolidation.sql` tracked in schema_migrations @ 2026-06-04 15:07:38 (261/262 were taken by `users_manager_scope`+`user_invites` from the parallel user-auth arc — agent ran `--status`, found the drift, used next-free 263; my pre-ruling "mig 261" was STALE). Companion `scripts/python/mig263_eph_hash_recompute.py` (Python — needed to match the normalizer's hash scheme; migrate.php is .sql-only) + resolver fix `app/recipe-resolver.php`. **COLLAPSE executed exactly as ruled:** keepers EPH1→62 / EPH2→76 / EPH3→71 / EPH4→75 all `is_active=1` with strains (62=2, 76=38 ale garde7, 71=3, 75=1); **15 stub rows (58/59/60/61/63/64/65/66/67/68/69/70/72/73/74) tombstoned `is_active=0`, never deleted**; aliases moved to keepers (EPH0221/Chela/Baies-Tises/Malt Capone preserved). **PM-verified residual stub FK counts = 0** across brewday/ferment/racking(neb)/packaging/op_sessions (all repointed; op_sessions id=28 EPH2/26 → 76). row_hash recomputed per the matching scheme.
> **🔑 HASH-SCHEME DISCOVERY (now a standing fact — see conventions):** the `bd_*_v2` BULK tables were ingested by the PYTHON normalizer using `sha256(json.dumps(canonical,sort_keys=True,default=str))`, NOT the PHP `bd_row_hash` (`sha256(implode('|', …))`). Only LIVE-FORM rows (e.g. fermenting id=13433) use the PHP hash. **Two hash schemes coexist in these tables by SOURCE.** The recompute matched the scheme per row with a self-check gate (recompute-with-old-id == stored). → codified to `conventions-and-helpers.md` + flag for any future row_hash-touching migration.
> **RESOLVER FIX:** `_recipe_resolver_load` SELECT `ORDER BY id` → `WHERE is_active=1 ORDER BY vintage DESC, id DESC` (both load paths). Vintage-less "EPH2" now resolves to keeper 76. This kills the lowest-id-wins landmine for ALL future multi-vintage recipes.
> **🟢 PRODUCTION PROOF:** operator (kouros) racked EPH2/26 via the NORMAL gated form (hors_process_flag=0) at 15:09:30 — ~2 min after mig 263 unblocked it (racking id=419, BBT1, 30.1HL; operator flagged a high-O2-pickup QC note himself). End-to-end, zero hand-holding. The split-brain is closed.
>
> ### RATIFICATION #1 — ref_recipe_profile* DELETE (not tombstone) — ✅ RATIFIED
> Agent DELETED (not tombstoned) 222 rows from `ref_recipe_profile`(30)/`ref_recipe_profile_hops`(94)/`ref_recipe_profile_malt`(98) — the stub-vintage rows. **PM-verified live: 0 stub rows remain; keepers carry 8/23/27 rows.** **RATIFIED — correct despite the "never delete" rule.** These are DERIVED ANALYTICS (materialized avg_*/median_*/batch_count/computed_at), regenerable, NO soft-delete column (tombstone structurally impossible), recipe_id in NO row_hash, before_json captured (reversible), scoped 30/30 with 0 collateral. The rule's intent (no SOURCE-data loss) holds; derived stats are not source. Repointing would have piled stale duplicate stats on keepers — DELETE was the clean move.
> **STALENESS CLOSE-OUT — the queued recompute is BELT-AND-BRACES, not load-bearing (PM verified the builder):** canonical builder = **`scripts/python/refresh_recipe_profile.py`** (`--apply`, default dry-run; `--recipe "EPH1"` filter; idempotent UPSERT; DELETE-then-INSERT child rows per (recipe,window)). **The builder is NAME-KEYED end-to-end** — every event query filters `cool_beer/beer/recipe_name/neb_beer = <recipe_name>` and the malt/hops queries filter `p.beer = <recipe_name>`; `recipe_id` is ONLY the OUTPUT partition key + the `revision_date` window floor, NEVER a JOIN key on the event tables. Recipes are selected via `SELECT id,name,revision_date FROM ref_recipes WHERE is_active=1` → keepers are the only active 'EPHn' rows, so the builder already aggregates ALL same-named brews onto the keeper id REGARDLESS of the repoint. **PM-verified: keeper profiles were rebuilt 2026-06-04 03:00 by the nightly cron with batch_count matching the repointed brews (76 all_time n=6 = brewday rows now under 76).** So the keepers are NOT meaningfully stale (the cron + name-keying already self-corrected). **The cron IS installed + active on the VPS** (`/etc/cron.d/maltytask-recipe-profile`, dated Jun 4 15:16 — deployed despite the repo source comment still saying "DISABLED"; chained `parse_bd_ingredients.py --apply && refresh_recipe_profile.py --apply` nightly 03:00 UTC). **RULING: a one-shot `refresh_recipe_profile.py --apply --recipe EPH1/EPH2/EPH3/EPH4` (or a full `--apply`) is a cheap, correct, optional belt-and-braces refresh to force keeper profiles current immediately rather than waiting for tonight's 03:00 run — point the recompute there. It is NOT a correctness gate (name-keying already did the work).** The nightly cron keeps them current going forward. ⚠️ Two side-notes for the orchestrator's radar (not blockers): (a) the repo cron file's "DISABLED" header is now STALE vs the installed crontab — reconcile on next deploy touch so a future dev isn't misled; (b) `parse_bd_ingredients.py`/`refresh_recipe_profile.py` use a name→prefix map (RECIPE_TO_PREFIX) that covers EPH1-4 by name — keepers carry the right names, so no map edit needed.
>
> ### RATIFICATION #2 — companion script in repo — ✅ RATIFIED (keep)
> `scripts/python/mig263_eph_hash_recompute.py` is the tracked, reproducible HASH-RECOMPUTE companion to `263_eph_consolidation.sql`. migrate.php is .sql-only and the recompute genuinely needed Python to match the normalizer's `json.dumps` hash scheme — it could NOT have been a SQL UPDATE. **RATIFIED as the migration's reproducible record — KEEP it. This is NOT the "agents leave helper scripts" anti-pattern.** That rule forbids EPHEMERAL one-off dispatchers/probes left as litter (use /tmp, delete). A migration companion that (a) is named to its migration, (b) encodes irreproducible-in-SQL logic, (c) is the audit record of HOW the data half ran, is legitimate tracked infrastructure — same class as the existing `mig263`-style normalizer scripts and the `parse_bd_ingredients.py` cron companions. **CONVENTION SETTLED: a migration may ship a same-named `scripts/python/mig<NNN>_*.py` companion when the work needs logic migrate.php's .sql exec() can't express (hashing, multi-pass normalization, Python-only libs). It stays tracked. Ephemeral helpers still go to /tmp + delete.** → codify to conventions-and-helpers.md.
>
> ### Pre-existing stale hashes (NOT a mig263 regression — flagged for radar)
> ~21 rows (16 packaging, 5 racking — incl. retro-link + qc-modified rows like operator's own id=419 lineage) had stored hashes matching NEITHER scheme BEFORE mig263 (audit_flags mutated post-ingest). The agent soft-skipped strict self-check on those + wrote current-state-basis hashes (now self-consistent). **Pre-existing tech debt, not a regression.** Root cause = post-ingest mutation of audit_flags without a hash recompute → the upsert idempotency key drifts from the stored value. Standing radar item: any handler that mutates a `bd_*_v2` row's hashed columns after insert must recompute row_hash in lockstep (ties to the saisie-forms hashCols discipline). Not urgent (rows are self-consistent now); surface on the next bd-hash touch.
>
> ### (RULING ARCHIVE — the pre-build ruling, now SHIPPED above) mig 261→263, finishes mig 221's job
**The split-brain:** mig 221 repointed EPH **SKUs** (commercial/downstream) oldest→operative (verified live 2026-06-04: `ref_skus.recipe_id` = 0 rows on old EPH ids 58/63/68/72). But mig 221 did NOT touch the **upstream observed-event tables** — brewing/ferment/racking/packaging/op_sessions ALL still point at the bare oldest rows. → one fact ("which row is EPH2") answered two ways. Symptom: EPH2/26 (CCT8, cold-crashed 16d) missing from racking candidate list because its data resolves to bare oldest row 63 (no `yeast_strain_id_fk`) → `effective_garde IS NULL` → gated out by `form-racking.php` ~L550.

**VERIFIED LIVE 2026-06-04 — operative (newest, strain-bearing) ids + strain/garde:**
- EPH1 → **62** (v2026, strain 2). Old siblings: 58(v2022,prefix EPH1)/59/60/61.
- EPH2 → **76** (v2026, strain **38 = family `ale`, garde 7**). Old: 63(v2021,prefix EPH2)/64/65/66/67.
- EPH3 → **71** (v2024, strain 3). Old: 68(v2021,prefix EPH3)/69/70.
- EPH4 → **75** (v2025, strain 1). Old: 72(v2021,prefix EPH4)/73/74.

**RULING = COLLAPSE to operative row (QDG mig-217 / EPH-SKU mig-221 precedent), NOT per-vintage SCD2.** Operator "last vintage is the main one" = what the system already does downstream. Per-vintage SCD2 would reverse 221 + lifecycle-collapse and require curating strain/bindings/BOM on every vintage row = parallel-store anti-pattern. Keeper = operative row; **tombstone (is_active=0) the per-year rows, do NOT DELETE** (mig 217/254 tombstone-and-relink).

**Blast radius = SMALL + SAFE — cost/tax/BOM are NAME-keyed, not recipe-id-keyed (verified live):** `inv_consumption.beer_name` (COP), `ref_beer_types.beer_name`/`sku_prefix`/`sku_code` (beer-tax), `ref_sku_bom`/SKU activation (already operative via 221), `build-sales-cogs.js` (SKU side operative). NONE key on the event recipe_id_fk → repoint is inert for ledgers. Tank-sim keys tank/beer/date (verify no recipe_id_fk JOIN). 

**FK surface to repoint (all → operative id, atomically; verified counts 2026-06-04):**
- `bd_brewing_brewday_v2.recipe_id_fk` (58=5,63=6,68=4,72=4)
- `bd_fermenting_v2.recipe_id_fk` (58=44,63=39,68=19,72=26)
- `bd_racking_v2.**neb_recipe_id_fk**` (58=3,63=2,68=1,72=1) — split neb/contract cols; contract side EMPTY for EPH; there is NO single `recipe_id_fk` on racking.
- `bd_packaging_v2.recipe_id_fk` (58=10,63=10,68=9,72=9)
- **`op_sessions` id=28 = OPEN EPH2 batch-26 session on 63** (FK-constrained) → 63→76. Live in-flight; repoint in same txn or orphan it.
- `ref_recipe_aliases`: EPH aliases are VINTAGE-SCATTERED + real identity (EPH0221→63, Chela→67, Beer des Rosses→62, Baies-Tises→71, EPH0xYY date-coded) → MOVE onto operative ids (case-insensitive dedup, no-op on existing keeper alias), never destroy.

**Resolver fix (ships SAME arc, code half of the fact):** `app/recipe-resolver.php` `_recipe_resolver_load` (+ `resolve_recipe_ids_batch`) do `SELECT … FROM ref_recipes ORDER BY id` then Step-1 returns FIRST same-name match = lowest id = oldest. FIX: (1) primary — filter `WHERE is_active=1` so tombstoned bare rows vanish → operative becomes the only match (verify no consumer needs tombstoned rows); (2) defensive — Step-1 tie-break prefers newest vintage / highest id among same-name. Both load paths. Lowest-id-wins is a latent landmine for ANY future multi-vintage recipe — fix the CODE not just the data.

**Sequencing: ONE migration 261 = full consolidation** (uniform op, full id map verified). Blocks: repoint all event FKs+op_sessions (58→62/63→76/68→71/72→75) → move aliases → **verify old-id FK counts = 0** → tombstone non-operative rows → audit row per touch (`action='update'`, `comment LIKE 'mig261_%'`) → `START TRANSACTION…COMMIT`. EPH2 block first so operator unblocks immediately (strain 38 ale garde 7, 16d ≥ 7 ✓). Resolver PHP in same commit/deploy. **Mig 261 free (last applied 260; re-check `migrate.php --status`).** Skills: `sql`+`coder` (NO ui — racking gate is data-driven, self-corrects once FK resolves). RULE-2 gated, dry-run→0 err, no self-commit.

## Earlier ruling (SUPERSEDED)
HYBRID stored-flag model: `production_status ENUM('upcoming','active','discontinued')` on ref_recipes. Dropped per operator override above — DO NOT re-recommend a stored flag.
