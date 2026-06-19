# SDC Recettes — Ingredients sub-surface (official vs observed drift)

> DESIGN CONSULT 2026-05-31. Design-only, no build yet; awaiting Kouros review of the 3-phase proposal + 6 open Qs. Honors the locked precedence model (observed > ref_recipe > hardcode). ALL schema facts below LIVE-VERIFIED via VPS probe 2026-05-31.

## Kouros's intent (verbatim)
"Ingredients are inputable and saveable [official/theoretical recipe]. maltyweb then tracks operator inputs during brewdays and dry hops and shows the drift / added / removed ingredients. Differentiate the theoretical/official recipe with the reality [observed]." -> two planes side-by-side per recipe (official-editable | observed-derived) + drift view (added / removed / qty-drift).

## Live-verified schema

### Official store -- ref_recipe_ingredients (CORRECT, ready) -- VERIFIED
- id int PK; recipe_id int unsigned -> ref_recipes.id (FK-clean, join verified -- 19 distinct recipe_id, 163 active rows); mi_id_fk int unsigned -> ref_mi.id (house INT-FK convention OK)
- qty_per_hl DECIMAL(14,6) -- canonical per-HL basis. Sample recipe 6: MALT_PILSENER 4.67 kg, HOPS_MOSAIC 333.3 g, PROC_PHOSPHORIQUE 23.3 ml -- all per-HL.
- unit varchar(8) -- 4 units live: g, kg, l, ml (mass AND volume -- drift normalization must handle BOTH dimensions, not just kg<->g)
- is_active tinyint(1) -- all 163 active today
- effective_from/effective_until DATE indexed -- PRESENT but DORMANT (0 rows non-null) -- SCD2 v1 dormant, confirmed
- created_at/updated_at
- Grain = recipe_id (single row), NOT recipe x vintage. Edit official = edit active rows where recipe_id = the operative (newest-vintage) ref_recipes.id (same row the SDC list surfaces).
- COVERAGE GAP: only 19 of 61 recipes have ref_recipe_ingredients rows. The other ~42 have NO official recipe stored. Editable-official UI must handle empty official plane (create-from-scratch); drift for those recipes is observed-only (all "added", nothing "removed") -> render "pas de recette officielle saisie".

### Observed store -- bd_brewing_ingredients_parsed (SINGLE unified source -- key finding) -- VERIFIED
- Covers BOTH planes, 100% MI-resolved at parsed layer:
  - brewday: source_table='bd_brewing_ingredients' (1550 rows), category IN ('malt'[807],'hops_kettle'[743])
  - dry hops: source_table='bd_fermenting' (278 rows), category='hops_dry'
- category ENUM('malt','hops_kettle','hops_dry') -- structurally cannot carry minerals/process-aids (= the observed gap, by design)
- mi_id_fk int unsigned NULLABLE but 0 NULL across all 1828 rows -- parsed layer fully resolved MI (parser did the work; PHP does NO fuzzy match)
- qty DECIMAL(10,3) per-brew ABSOLUTE; unit ENUM('kg','g') -- observed only kg/g (mass), while official also has l/ml. Volume-unit MIs appear in OFFICIAL but observed table cannot represent them -> they are observed-GAP -> reference-only (see drift).
- lot; beer+batch+event_date+source_id+source_table = identity. NO recipe_id_fk on the parsed table.

### Observed -> recipe LINK (load-bearing build detail -- TWO DIFFERENT PATHS, verified)
- brewday branch (malt/hops_kettle): parsed.source_id -> bd_brewing_ingredients.id, and bd_brewing_ingredients.ing_beer_recipe_id int unsigned carries the recipe id. VERIFIED: 1550 rows, 45 NULL recipe (2.9%) -> data-quality notice, never guessed.
- dry-hop branch (hops_dry): parsed.source_id -> bd_fermenting.id, BUT bd_fermenting has NO clean recipe_id column -- identity is in free-text cols (beers_to_read/beers_to_dry_hop/beer_dh = "PREFIX BATCH" text, per bd_fermenting-text-parsing memory). The parsed row's beer+batch were derived by the upstream text-parser. For dry hops the only recipe link is via parsed.beer -> recipe resolution. THIS IS THE ONE FUZZY-ADJACENT JOIN; verify it resolves deterministically; surface unresolved, never guess.
- NET: brewday observed = clean FK chain; dry-hop observed = parser-derived beer string needing a recipe-resolution map. Build must treat the two branches with different link logic. Do NOT assume a uniform recipe_id_fk.

### Brew-HL (normalization denominator) -- VERIFIED
- bd_brewing_brewday_v2 has NO volume/HL column (recipe_id_fk + cct + batch + event_date + yeast, no volume).
- Per-brew volume lives on bd_brewing_cooling.cool_final_volume_hl (cooling = post-boil wort volume). Join cooling->brew by beer+batch+event_date (confirm batch col on cooling at build). Denominator: observed_per_hl = observed_qty / cool_final_volume_hl.
- Per recipe-seed-uses-per-hl memory the COGS loader uses actual_brew_hl as multiplier. Drift view must use the SAME volume basis so drift % is apples-to-apples. Verify which volume the COGS loader uses and match it.

### Identity -- ref_recipes (verified)
- identity = (name, classification, client_id); vintage; subtype ENUM(Core/EPH/CollabIn/CollabOut/WhiteLabel/Archive); sku_prefix. 61 recipes; operative = newest vintage.

## Precedence reconciliation (locked model)
3-layer observed (bd_*) > ref_recipe_ingredients > hardcode. For malt/hops (categories observed CAN represent) COGS/COP read OBSERVED as canonical -> ref_recipe_ingredients IGNORED for cost. For minerals/process-aids/finings/yeast-vit (l/ml + retrofit class absent from observed) ref_recipe_ingredients IS the gap-fill the cost loader uses. This split drives drift-suppression AND cost-safety.

## Drift computation -- SQL view v_recipe_ingredient_drift (recommended, mirrors live v_recipe_lifecycle)
Per MI, per operative recipe:
1. official = qty_per_hl (active, operative recipe_id), canonicalized within its DIMENSION (mass->g, volume->ml)
2. observed per-HL per brew = observed_qty / cool_final_volume_hl, canonicalized to g (observed is mass-only)
3. aggregate across brews = median of last-N (rec; resists outlier, stays current; surface brew count + min/max dispersion) -- Kouros Q1
4. classify per MI:
   - matched: both, |drift| <= tolerance
   - qty-drift: both, |drift| > tolerance, signed % = (obs_med - official)/official
   - added: observed-only
   - removed: official-only AND category in {malt,hops}
   - reference-only: official-only AND (volume-unit OR category not in {malt,hops}) -> neutral "reference uniquement (non suivi en production)", NEVER flagged. Suppressing the false "removed" alarm on minerals/process-aids is architecturally critical for operator trust (observed-malt-hops-canonical-no-gapfill).
- tolerance from commissioning_settings (no-hardcode; heartbeat mig216 / stale_heel mig218 pattern) -- Kouros Q2
- category mapping: official has NO category col -- derive malt/hops vs gap-fill from ref_mi category (MALT_*/HOPS_* prefix or ref_mi_categories) so the view applies the suppression rule. Confirm at build.
- Prefer a VIEW (consistency w/ v_recipe_lifecycle, reusable, normalization in one auditable place). PHP only hydrates+renders. maltytask_app HAS CREATE VIEW since 2026-05-26.

## Unit / identity discipline
- Join on mi_id_fk INT, never raw_name. Observed parsed is 0-NULL mi_id_fk -- clean; NULL risk = the 45 NULL-recipe brewday rows + unresolved dry-hop beers, handled as notices.
- TWO dimensions: mass (kg<->g) AND volume (l<->ml). 1000x phantom drift is #1 risk on each axis. Canonicalize each MI within its dimension before subtracting; never cross dimensions. Observed is mass-only so volume MIs never reach the subtraction path (reference-only) -- but official plane render must show l/ml correctly.
- Plato gravity irrelevant here. PHP reads ref_mi directly for names; resolve-mi.ts (Node) out of scope.

## Save path + COST-SAFETY ruling (load-bearing for Kouros)
- Write path: dedicated PHP save handler -> require_login + role gate (master-data = admin/brewer) + csrf_verify + snapshot-before-write + log_revision (audit_row_revisions, action ENUM('insert','update') -- NO 'delete', soft-retire via is_active=0) + PRG. Writes ref_recipe_ingredients (INSERT line / UPDATE qty_per_hl,unit / is_active=0). refuse-don't-NULL on unresolved MI. Query schema_meta/corrections_policy before write.
- COST-SAFETY (the explicit safe/inert answer):
  - Editing a malt/hops line = cost-INERT (observed wins; official is reference/target only, COGS/COP never read it for those).
  - Editing a minerals/process-aid/finings/yeast-vit line (l/ml + retrofit gap-fill class) = cost-AFFECTING (ref_recipe_ingredients IS what COP/COGS multiplies by actual_brew_hl for those -- gate4 parity proved +4158 CHF/month came from this class; diagnostic maltyweb/scripts/php/gate4_parity_test.php).
  - Save UI MUST visually distinguish ("reference -- n'affecte pas les couts" vs "AFFECTE les couts COP/COGS"). Cost-affecting edit = never-guess-COGS surface -> confirmation gate + log_revision.
- SCD2: leave DORMANT v1 -- do NOT let this UI trigger effective-dating. In-place edit + audit gives history/rollback now; SCD2 retrofits cleanly later (cols exist). Kouros Q4.

## Build sequencing (skills ui+sql+coder; RULE-1 + RULE-2 gated; NO parser-coder)
- R1 -- Data-wiring foundation [FIRST, no mig]. Server-inject real ref_recipe_ingredients (+ profile) for ALL 61 recipes via PHP->window.JSON->JS. DELETE the 9-Core hardcoded INGREDIENTS/PROFILES JS literals @ public/modules/salle-de-controle.php ~L3104+ in the SAME atom (RULE-1: parallel store; literals cover only 9, real data covers all). Handle ~42-recipe empty-official case. Prerequisite for R2/R3.
- R2 -- Drift read layer [mig: view + tolerance seed]. v_recipe_ingredient_drift (brewday FK chain + dry-hop beer-resolution + cool_final_volume_hl denominator + dual-dimension canon + removed-suppression + ref_mi category derivation). Render official|observed|drift read-only. Validate vs real data. Mig AFTER SDC arc 219/220/221 -- re-check --status.
- R3 -- Editable official + save handler [write]. On R1 data. Full write discipline; cost-class warning UI; refuse-don't-NULL; empty-official create path. SCD2 dormant v1. Advances gate 5 of hybrid-ingredient cutover -- note in project_hybrid_ingredient_sources_no_deploy on land; this IS the maltyweb-native recipe-entry future-state the operator described.

### Gating
- NOT hard-gated on mother-shell arc (different surface; parallelizable). Operator mid-strain-classification IN SDC now + mother-shell paused on operator atom-11 smoke -> R1 now is coherent. Sequence = Kouros Q5.
- Hardcoded-literal kill IS the R1 foundation. Building drift/edit on literals = sand. Log literals to scrapping-backlog, gate "removed when panes route to ref_recipe_ingredients"; remove in R1.

## OPEN QUESTIONS for Kouros
1. Drift aggregation mean/median/latest/last-N? (rec: median of last-N + dispersion)
2. Drift tolerance matched-vs-qty-drift? (rec: +-10% rel default, in commissioning_settings)
3. Editing cost-affecting gap-fill lines inline+confirm or heavier gate? (rec: allow + "affecte les couts" confirm + log_revision)
4. SCD2 now or in-place+audit for v1? (rec: in-place+audit)
5. Sequence: SDC ingredients now parallel to paused mother-shell, or finish mother-shell smoke first?
6. Only 19/61 recipes have official ingredients -- fill the other ~42 part of THIS arc or later? (rec: ship editable UI, operator backfills at leisure; drift handles empty-official gracefully)

## Build-start VERIFY (agent checks live, not assume)
- dry-hop beer->recipe resolution map resolves deterministically (the fuzzy-adjacent join); surface unresolved.
- cool_final_volume_hl join key (beer+batch+event_date) + matches the COGS loader's actual_brew_hl basis.
- ref_mi category that marks malt/hops (for removed-suppression).
- dual-dimension canonicalization (kg<->g AND l<->ml) against real rows.
- ref_recipe_ingredients corrections_policy via schema_meta before save handler writes.
- migrate.php --status before assigning R2 mig number.

---

## §CONSOLIDATED 4-ITEM PLAN (2026-05-31) — R1 approved + 3 added by Kouros same breath

> Kouros approved building R1 (the ingredients data-wiring foundation, = §Build sequencing R1 above) and added B/C/D. All four collide in `public/modules/salle-de-controle.php` + the recipe-edit surface → SEQUENTIAL atoms, NOT parallel agents. NEXT FREE MIG = 222 (215–221 ALL applied live; the SDC 3-list arc SHIPPED; `v_recipe_lifecycle` LIVE).

### Decisions LOCKED with Kouros (this session)
- Drift aggregation = **VOLUME-WEIGHTED TRAILING AVERAGE** (DECIDED by Kouros 2026-06-07; the prior "median of last 5 brews" Q1 answer is **SUPERSEDED**). Definition: window = last ~5–8 brews OR trailing 12 months, whichever ≥3 brews; weight each brew's per-HL by its batch HL; reject ~2 MAD from median; recompute monthly. ⚠️ **COUPLING — this is the SAME statistic that feeds `compile_sku_bom_liquid` (the BOM-liquid cost basis, sku-bom-validation-walkthrough §RULE #1).** ONE aggregator, TWO consumers — the drift view and the BOM cost MUST read the same statistic (a parallel/divergent basis = a one-fact-two-stores smell). So "what we cost at" = "what we measure drift against." Any drift-view build reuses the F2 aggregator, never a re-implemented one.
- **Both ingredient tiers editable**: malt/hops reference + minerals/process-aids cost-affecting (Q3 closed — allow both; cost-affecting keeps the "AFFECTE les coûts" confirm + log_revision gate).
- KPI framing = **Recipe Fidelity %** + **swap-pairs detection** + **substitution frequency**; qty-drift = secondary. (This is an R2/drift-view concern, NOT R1.)
- Yeast-pane regression (passées/contrats placeholder) already fixed + deployed.

### LIVE-VERIFIED FACTS 2026-05-31 (reshape the answers — verify again at build)
1. **ref_recipes has NO `style` column.** Full col list: id, name(varchar128), classification ENUM(Neb/Contract), subtype ENUM(Core/EPH/CollabIn/CollabOut/WhiteLabel/Archive) NULLable, client_id, recipe_short_name(varchar64), vintage(varchar8 def ''), sku_prefix(varchar8) NULLable, is_active, notes, revision_date, created_at, updated_at, last_modified_by ENUM(ingest/web), uses_branded_scotch, yeast_strain_id_fk, garde_days_min_override, ferm_temp_min/max_override, co2_target/tolerance, racked_vol_warn/outlier_lo/hi. "Beer style" today = recipe_short_name (free text) + subtype ENUM. Contracts: subtype='' empty.
2. **`created_at` is USELESS as a history signal** — all 76 rows = identical `2026-05-07 15:11:12` (bulk import). NO column distinguishes pre-2021 historical from genuinely-new. → D-option-2 (data signal) is DEAD.
3. **TM-ST = id 55**: name='TM-ST', recipe_short_name='ST', classification=Contract, client_id=7, vintage='', sku_prefix='', is_active=1.
4. **76 raw recipe rows = 34 Neb + 42 Contract.** 61 = the collapsed (newest-vintage) count.
5. **ref_yeast_strains = 30 rows.** Cols ONLY: id, name(uni), is_active, supplier, type ENUM(ale/lager/wild/hybrid/unknown) def 'unknown', family ENUM(ale/lager/non_alcool/spontane/mixte) NULLable, notes. **NO flocculation/attenuation/temp columns.** 11 family-NULL, ALL 11 orphan (no recipe points at them) = the exact unclassified set; 18 attached all classified; 1 classified-but-unattached.
6. ref_skus joins recipe by `recipe_id` INT.

### A — R1 ingredients data-wiring [confirmed, no mig]
Scope UNCHANGED from §Build sequencing R1. Inject real ref_recipe_ingredients (all 61) via PHP→window.JSON→JS; DELETE 9-Core hardcoded INGREDIENTS/PROFILES literals (~L3104+) in the SAME atom (RULE-1). Handle ~42 empty-official recipes ("pas de recette officielle saisie"). KPI/Fidelity framing is R2, not here. Skills ui+sql+coder.

### B — edit contract name + beer style → DB [mig only if option-ii]
- **Display name the list shows = `ref_recipes.name`** (v_recipe_lifecycle display uses name; collapse key does NOT — see below). recipe_short_name is the short/secondary label.
- **Canonical editable cols = `name` + `recipe_short_name` + `subtype`.**
- **"Beer style" → OPERATOR BUSINESS CALL:** no `style` column exists. Either (i) repurpose `recipe_short_name` as the style field (no mig, but it's currently the short-name) OR (ii) add `ref_recipes.style VARCHAR(64)` (mig 222+). Don't silently pick.
- **Collapse-identity risk verdict: SAFE to edit name.** Collapse key (per recipe-lifecycle §3) = `COALESCE(NULLIF(sku_prefix,''),CONCAT('id:',id))` — keyed on sku_prefix/id, NOT name. Contracts all have sku_prefix='' → each is its own `id:NN` bucket → editing name cannot merge/split buckets. v_recipe_lifecycle grouping unaffected.
- **⚠️ Downstream name-join risk = the real one.** Anything that JOINs on ref_recipes.name (rather than id) would break on rename. **Build-start MUST grep** for name-based joins/lookups (bd_* text-parser recipe maps, sku resolution, reports) before B ships. If a name-join exists, that's the divergence flag (string copy not FK) — fix it to id-join or block the rename of that field.
- Save path: POST action in salle-de-controle.php → require_login + role gate (admin/brewer) + csrf_verify + snapshot-before-write + log_revision (audit_row_revisions action='update', before/after JSON) + PRG. ref_recipes corrections_policy='allowed' (verified). Skills ui+sql+coder.

### C — Biochimie full yeast-strain catalog [mig only if new attribute cols wanted]
- Current Biochimie = ref_yeast_family_defaults (family-LEVEL defaults). ADD a per-strain catalog listing ALL 30 ref_yeast_strains with editable classification, so operator classifies the 11 orphan family-NULL strains independently of recipes.
- **Classifiable attributes that EXIST = `family` + `type` (both ENUMs).** Reuse the existing ref_yeast_strains UPDATE path (already writes .family per the strain-classification arc); EXTEND it to also write .type. Same handler, no new table.
- **flocculation / attenuation / temp range / etc. DO NOT EXIST as columns → OPERATOR BUSINESS CALL.** If operator wants to record/edit them, that's a mig adding columns (e.g. flocculation ENUM(low/med/high), attenuation_pct_min/max DECIMAL, temp_min/max DECIMAL) BEFORE C can surface them. Otherwise C ships family+type classification only. Surface; don't invent the columns.
- Skills ui+sql+coder. RULE-1: if family_defaults page becomes redundant with per-strain catalog, log to scrapping-backlog.

### D — TM-ST wrongly 'upcoming' [mig 222, NEW view, pure SQL]
- **Problem:** TM-ST (Contract, never brewed in bd_brewing_brewday_v2, no stock) → v_recipe_lifecycle CASE derives `upcoming` → "production à venir" chip. Operator: it's pre-2021 historical, never to be rebrewed → should be `passee`. The pure-derived model conflates (i) genuinely new/upcoming vs (ii) historical-before-our-data-window. NOT derivable from brew+stock alone; created_at dead (fact 2).
- **PM DECISIVE RECOMMENDATION = option 3, the clean RULE: Contracts NEVER get `upcoming`.** Rationale: a contract is brewed-on-demand for a client, never a Nébuleuse production pipeline — "upcoming" (production à venir, a forward Neb intent) is semantically wrong for ANY contract. For `classification='Contract'`, never-brewed-no-stock = `passee`, not `upcoming`. This stays FULLY DERIVED (no stored flag, no per-recipe override) — honors operator's fully-derived insistence and the §OVERRIDE ruling. Fixes TM-ST and every other never-brewed contract in one rule.
- **Implement = mig 222 rewriting v_recipe_lifecycle CASE.** Do NOT edit applied 220 — new mig that `CREATE OR REPLACE VIEW`. Change: gate the `upcoming` arm on `classification='Neb'` (i.e. `WHEN NOT ever_brewed AND classification='Neb' THEN 'upcoming'` … and Contract-never-brewed falls through to `passee`). Lists: Contrats list is classification-based + independent of lifecycle, so TM-ST still shows in Contrats — the FIX is the chip/state label going passee not upcoming. Skills sql+coder (+ui only if a label/legend changes).
- **OPERATOR CONFIRM (the one business call):** is "contracts never upcoming" universally correct, OR could a genuinely-new contract recipe (signed, not yet first-brewed) legitimately read as upcoming? If operator says it COULD → fall back to **option 1**: a minimal stored hint (e.g. `ref_recipes.lifecycle_hint ENUM('auto','historical') def 'auto'`) consulted ONLY to resolve the upcoming-vs-historical ambiguity — the ONE genuinely underivable case, a justified narrow exception to fully-derived (does not reintroduce the rejected full production_status flag). Put this Q to Kouros; default to option 3 unless he says new-contracts-can-be-upcoming.

### ORDERED BUILD SEQUENCE (sequential atoms, no parallel agents, no file collision)
1. **D first** — mig 222 view rewrite. Smallest, pure SQL, no salle-de-controle.php edit (just the view + maybe a label). Unblocks correct lifecycle state that A/B render against. Validate TM-ST → passee.
2. **A (R1)** — ingredients data-wiring foundation; kills the hardcoded literals. The big salle-de-controle.php edit.
3. **B** — name/style edit handler on A's wired recipe-edit surface (same file, builds on A's hydration).
4. **C** — Biochimie strain catalog. Most independent logic but still touches salle-de-controle.php → last to avoid merge churn.
Each = its own atom, RULE-1 + RULE-2 hard-gated, no self-commit. Skills per item above (all ui+sql+coder except D = sql+coder).

## RECIPE ALIASING + PRG-RESTORE RULINGS (2026-05-31, after Atom B migs 219-225 commit 5e1e1c1; LIVE-VERIFIED VPS)

### VERIFIED FACTS
- **`ref_recipe_aliases` EXISTS, mature, REUSED — NO new table, NO mig for the table itself.** Cols: `id`, **`alias VARCHAR(128) NOT NULL UNIQUE (uniq_alias)`**, **`recipe_id INT UNSIGNED` FK->ref_recipes.id ON DELETE CASCADE** (col is `recipe_id` not `recipe_id_fk`; alias col is `alias` not `alias_name`), `notes TEXT`, `created_at`. 89 rows (migs 026b/065/085/217 populated it: SKU prefixes, racking abbrevs, EPH trade names, cold-crash codes, DIG/QDG). AUTO_INCREMENT 103.
- **A CANONICAL RESOLVER ALREADY RESOLVES THROUGH IT — `app/recipe-resolver.php` `resolve_recipe_id($pdo,$raw)`** precedence: canonical name -> **alias** -> strip-trailing-batch -> normalized -> null (NO fuzzy, per CLAUDE.md no-speculation). `resolve_recipe_ids_batch()` + `canonical_to_short_code()` companions. **Callers today: `app/tank-simulator.php`, `public/modules/tanks.php`, `public/modules/tank-simulator.php`** (plus Python ingest `scripts/python/*.py`). ==> Inserting the old names as aliases makes EVERY resolver consumer resolve them with ZERO code change. This is the clean win.
- **Rename audit is authoritative + chronological (target_pk = recipe id).** TWO-HOP for Toccalmatto: pk53 TM-BLO->Toccalmatto - Blonde->**Brasserie 28 - Blonde**; pk54 TM-IPA->...->**Brasserie 28 - IPA**; pk56 TM-TR->...->**Brasserie 28 - Triple**; pk55 TM-ST->**Toccalmatto - Stria** (single hop); pk46 NYL->**Nylo**. Alias set per recipe = every distinct historical before-name != current, INCLUDING intermediate hops. (pk27 Diversion Gose->Qrew - Diversion Gose already aliased via mig217; EPH1-4 audit rows are non-name field edits.) Source = audit before_json.name/after_json.name; NEVER guess. action ENUM = insert/update only.
- **`ref_recipes.style` COLUMN NOW EXISTS** (Atom B) — live SDC `$_POST['style']` handler; supersedes the old "no style col" note.
- **SDC PRG bug uniform:** `salle-de-controle.php` has 16x `redirect_to('/modules/salle-de-controle.php?sec=recettes')`, none carry recipe/subtab; every save reverts to top. Every save handler already has `$_POST['recipe_id']` in hand.

### RULING #1 - ALIASING (skills: sql + coder; NO ui)
1. Reuse `ref_recipe_aliases`. Seed old names via a NEW mig (VERIFIED via schema_migrations: 225_ref_recipes_style.sql is highest applied, so NEXT FREE = mig 226 (re-confirm at build-start)). `INSERT IGNORE` (uniq_alias = idempotent), one row per (distinct historical before-name incl. intermediate hops, recipe_id), notes='rename 2026-05; was X now Y'.
2. Source the list from the `update_recipe_name` audit rows — confirmed, NEVER guess. If any rename was done by direct SQL (no audit row), operator must state old->new explicitly; that statement is canonical.
3. **Aliasing is NOT display-only but is ALSO not a big wire-job here** — the existing `resolve_recipe_id()` already does alias fallback, so its 3 maltyweb consumers (tanks/tank-simulator) auto-fix. THE ONE REMAINING GAP: the maltytask **`recipe-ingredients-loader.php`** COGS gap-fill (Node/BSF-era, in the maltytask repo, NOT on the VPS) joins `ref_recipes.name = beer_name` COLLATE with NO alias fallback -> historical bd_packaging/bd_brewing rows holding old contract strings silently drop from COGS gap-fill. Teach THAT join an alias fallback (or, cleaner, route it through a port of resolve_recipe_id). This is a maltytask-repo change, separate artifact from the seed.
4. Build-start: grep all `ref_recipes.name =`/COLLATE name-joins repo-wide; any that DON'T go through resolve_recipe_id AND touch contract recipes need alias fallback (tanks.php already uses the resolver). Two artifacts: (a) alias seed mig, (b) maltytask loader resolver change.

### RULING #2 - PRG RESTORE (skills: ui + coder; NO sql, NO mig)
- Change all 16 `?sec=recettes` redirects to carry `&recipe=<recipe_id>&sub=<subtab>` (recipe_id already in POST). On load JS reads params, runs selectRecipe(id) AFTER the async list renders (hook render-complete, NOT DOMContentLoaded), restores subtab, scrollIntoView the row.
- Per-handler sub: name/style/subtype->default pane; yeast->yeast; qc->qc; format activate/deactivate/binding->formats; update_yeast_strain (Biochimie)->`&sub=biochem`, scroll to strain not recipe (confirm w/ operator whether biochem restores a recipe at all).
- Restore by **id never name** (name-edit changes label, id stable — this is WHY #1 matters). Flash (session) + querystring coexist fine, orthogonal.

### OPERATOR CALLS
- (a) Confirm rename set = {pk53/54/56 Brasserie 28; pk55 Toccalmatto - Stria; pk46 Nylo}; whether to alias Neb/core renames too (none in this batch).
- (b) Intermediate-hop aliases (Toccalmatto - Blonde/IPA/Triple) included — cheap, prevents half-window miss. Confirm.
- (c) Biochem subtab restore semantics (per-strain vs per-recipe).

---

## FERMENTING-SELECTOR + HOP-STAGE — as-built scoping record (compiled out of index 2026-06-01; build SHIPPED, see index §FERMENTING STATE-GATED SELECTOR)
**Status:** SHIPPED + LIVE, commits `c38d924`/`8cc1c73`/`e81358e`, mig 253, committed-NOT-pushed. This section retains the pre-dispatch scoping/trap analysis the build honored.

### LIVE FILE TREE (orchestrator's original plan had WRONG paths — these are correct)
`public/modules/form-fermenting.php` (GET load + recipes/hops/recent queries; POST re-routes to API) → requires `public/modules/partials/session-body-fermenting.php` (computes `$ff_phase` none/start/in_progress/end; opens/looks-up op_session; loads CCT-firewall) → dispatches to `partials/fermenting-phase-{start,in-progress,end,recent}.php`. LIVE JS = `public/js/form-fermenting.js`; LIVE CSS = `public/css/form-fermenting.css` (ferm-* ns); submit endpoint = `public/api/fermenting-phase-submit.php`. **NB a stale `public/modules/form-fermenting.{js,css}` pair also exists — the LIVE ones are under `public/js/` + `public/css/`; never edit the modules/ copies.**

### PHASE MODEL (confirmed)
`session-body-fermenting.php` L56-94 reads `$_GET['beer']`/`['batch']`/`['recipe_id']`; both beer+batch present → phase from `bd_fermenting_v2` (ColdCrash exists ⇒ end; any event ⇒ in_progress; none ⇒ start); neither ⇒ `none` (selector).

### 🔴 IDENTITY-STRING TRAP (the one mandatory fix — HONORED in the shipped build)
Card value must be raw brewday `bfw.beer`, NOT `recipe_short_name ?: name`. Phase-detection matches `bd_fermenting_v2.beer_raw = ?` and CCT-firewall matches `bd_brewing_brewday_v2.beer = ?` — both exact-match the raw brewday/event STRING. Mismatching contract/collab recipes: ids 37/38 (Le Traquenard, brewday `"Le Traquenard - Pale Ale"`/`"... - Session IPA"` vs short `"Pale Ale"`/`"Session IPA"`), 41/42/43 (MeltingPote-*), 47 (Obrist - Grape Ale). The other 8 (Alternative/Docks/DoubleOat/DrunkBeard/Embuscade/Speakeasy/Stirling/EPH1/EPH2) happen to match. Fix = mirror racking exactly: `data-beer = bfw.beer` (GET identity), `beer_display = COALESCE(NULLIF(bfw.beer,''), r.name)` (LABEL only) — racking `form-racking.php` L507 + `racking-form.js` selectCard L719.

### PM ANSWERS to the 4 pre-build questions
(1) Carry event_type through GET + pre-select on return = FINE (display-routing; phase-detection keys on beer+batch only). (2) NO — match value is the raw brewday/`beer_raw` string, not recipe_short_name (trap above). (3) Reads+ColdCrash share the identical set — compute once, alias. (4) SDC Recettes collision low (additive panel) but SDC arc was operator-paused mid-strain-classification — verify no uncommitted WIP on salle-de-controle.php before dispatch.

### Occupancy-model ruling honored
Single TankSimulator run + shared `$simFilter` — ONE occupancy model, lift the helper, do not re-implement (racking §OCCUPANCY-MODEL ruling, saisie-transferts-and-yeast-family.md, applies verbatim).

## §HOP-STAGE WHIRLPOOL + ORDERING + RECETTE-UX CONVENTIONS (2026-06-07, mig 276 + commit `2a803d4`)
- **`hop_addition_stage` ENUM now includes `'whirlpool'` — APPENDED LAST** (index safety; never insert mid-ENUM). **CONVENTION (durable): any stage ORDERING in SQL/PHP/JS uses an explicit FIELD()/rank map, NEVER ENUM declaration order.** Canonical process order: mash → first_wort → boil (60→0 desc) → whirlpool → hop_stand → dry_hop.
- **Semantics:** whirlpool = flameout addition, NO cooling, ≈100°C. hop_stand = wort COOLED to 80°C first. Distinct stages, never collapse.
- **`hop_boil_time_min`** = minutes of boil REMAINING at addition (60=start of boil, 0=flameout). UI-gated to stage='boil'; non-boil stages force NULL.
- **Operator is actively re-attributing hop stages in SDC** (started 2026-06-07; EMB live: whirlpool Cascade + first_wort Herkules). Stage data is in flux — F2 apply-gate 14 (staleness re-run) covers the coupling.
- **SDC recette UX batch (IN FLIGHT 2026-06-07, same dispatch as F2 — repo single-writer):** (a) hops ALWAYS render in g — kill the g→kg auto-promote for hops; kg = inventory-only (operator rule); (b) hop bill rendered in process order via the explicit order map; (c) **qty INPUT per BRASSIN, not per HL** (operator: "We never talk per hl in recette") — input ÷ brassin → `qty_per_hl` storage UNCHANGED; brassin comes from the existing scaleLabel/commissioning source. ⚠️ PM flag: ÷-brassin MUST use the recipe's EFFECTIVE brassin incl. the per-recipe Capacités override, not the global default — wrong denominator silently corrupts qty_per_hl (cost-affecting via non-malt/hops gap-fill).

## §FULL RECIPE MODIFICATION-REQUEST FLOW — DESIGN-CONSULT RULED 2026-06-19 (NOT built; awaiting Kouros Phase-0 answers)
> Kouros wants managers to request FULL recipe modifications (not just hop adds) via the existing "modification-request" mechanism. Trigger "modification request"/"recipe change request"/"soumis pour approbation"/"demandes recette"/"manager edit recipe"/"recipe_change_requests" → READ THIS SECTION.

**LIVE-VERIFIED CURRENT STATE (disk + DB, 2026-06-19):** the "modification-request flow" is a **UI MOCK — NO request table, NO approval queue, NO persistence exists** (DB: zero %request%/%approv% tables; only the `ref_recipe_*` set). Single surface `public/modules/salle-de-controle.php` (~5997 L, `?sec=recettes`). **POST handler L127-131 hard-gates `is_admin($me)` ONLY** — every recipe write rejected for non-admins. "Hop addition modification" = REAL direct admin-only JSON-API writes `add_hop_addition`/`delete_hop_addition`/`set_hop_stage` (L1869-2174) to `ref_recipe_ingredients` (qty_per_hl/unit/hop_addition_stage/hop_boil_time_min, tombstone-on-delete is_active=0 + log_revision) — NOT requests, they execute immediately. The mock approval path (L5782-5895 `onFieldBlur`/`onStyleBlur`/`onNameBlur`/`createRecipe`): for a MANAGER → reverts field + `showToast('… soumis pour approbation')`, **persists NOTHING, notifies NOBODY**; for ADMIN → confirm modal `applyModal` (L5804) fires a real direct POST (self-approve). JS `canEdit=admin||manager` (L5327+) but managers' edits evaporate. 6 active managers — real demand. `SDC_ROLE=$me['role']` (L4973).

**RULING (architecture):** GENERALIZE — ONE canonical request envelope, NOT "hop-add v2". New tables: **`recipe_change_requests`** (id, recipe_id_fk→ref_recipes.id, requested_by_fk→users.id, requested_at, status ENUM(pending/approved/rejected/withdrawn), decided_by_fk NULL, decided_at, decision_note, change_kind ENUM(ingredient_add/ingredient_update/ingredient_remove/recipe_field/qc_target/yeast)) + **`recipe_change_request_lines`** (request_id_fk CASCADE, target_table ENUM(ref_recipes/ref_recipe_ingredients), target_pk NULL, mi_id_fk NULL, field, old_value, new_value, is_cost_affecting TINYINT precomputed). 2 schema_meta rows; FK types exact (INT UNSIGNED). **Approve REPLAYS the delta through the EXISTING admin write functions (refactor inline bodies → shared callable helpers; one writer, no parallel path — RULE-1).** Requester=manager (new `submit_change_request` action, relax the is_admin wall for THAT action only); approver=admin (`?sec=recettes&tab=demandes` review panel: old→new diff + cost-class badge + approve/reject/withdraw). **NO new recipe VERSION per edit — edit operative row in place, SCD2 stays DORMANT** (a version-per-edit reverses mig 221/263 collapse = parallel-store smell).

**RISKS/FLAGS:** (1) parallel write path = #1 smell → shared helpers only; (2) malt/hops cost-INERT (observed wins) but minerals/finings/process-aids cost-AFFECTING (gap-fill→COP/COGS) → precompute is_cost_affecting + reviewer badge + never-guess-COGS confirm; (3) **format-activation/BOM-binding EXCLUDED from generic flow** — capability-gated (filler×container×format) + COGS-bearing via ref_sku_bom; stays admin-direct; (4) name rename → downstream name-joins (route via resolve_recipe_id + ref_recipe_aliases; restore by id); (5) per-brassin ÷ EFFECTIVE brassin (incl. Capacités override) → qty_per_hl; (6) refuse-don't-NULL on unresolved MI; (7) managers' JS edit affordances already live-but-inert — verify role-gate change only opens submit_change_request, not the direct-write actions.

**PHASE-0 OPERATOR Qs (BLOCK before build):** (a) admins route through queue too or keep direct+self-approve? (rec: keep direct); (b) notify = in-app pending badge v1 or email? (rec: badge v1); (c) confirm format/BOM excluded (rec: yes, exclude). **SEQUENCE:** P1 schema (sql+coder; re-check migrate.php --status, head≥406, 397 pending-parallel DON'T TOUCH) → P2 request write + manager gate (ui+coder+sql) → P3 admin review panel + shared-helper approval replay (ui+coder+sql+webapp-testing) → P4 badge/PRG/tour. SINGLE-WRITER file salle-de-controle.php — sequential atoms, no parallel agents. **EQUIP: ui + coder + sql + webapp-testing** (P1 = sql+coder only).
