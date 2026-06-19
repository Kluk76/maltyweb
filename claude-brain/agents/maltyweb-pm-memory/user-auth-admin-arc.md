# USER / AUTH / ADMIN-MANAGEMENT ARC — ✅ COMPLETE + LIVE 2026-06-04 (scope→build→ship→post-ship-corrections + shared-device policy, all in one arc)

Operator created users (1 admin / 3 manager / 6 operator, all active) and wanted 3 changes
before sending login links. PM scoped against live code+DB 2026-06-04; arc then BUILT, SHIPPED, LIVE.
A second pass (2026-06-04) added 3 post-ship corrections + a SHARED-DEVICE policy (mig 264).
A third pass (2026-06-07) = ONBOARDING SELF-TEST → PASSED, 5 defects caught+fixed (§ below).

## 🔑 PAGE-ACCESS / TOPBAR-VISIBILITY RESOLUTION MODEL — canonical reference (recorded 2026-06-09 from a live debug)
**The topbar is fully data-driven from `ref_pages`.** `app/partials/topbar.php` runs `SELECT page_key,label,icon,href,min_role,domain FROM ref_pages WHERE is_active=1 ORDER BY sort`, then for EACH row `if (!user_can_access($row['page_key'], $me)) continue;`. domain='admin' → admin overflow menu; else → main nav. A link shows **iff** the page is active in `ref_pages` AND `user_can_access()` returns true. No hardcoded module list (retired mig 266).
**`user_can_access($page_key,$u)` in `app/auth.php` — resolution ORDER (load-bearing; REORDERED 2026-06-09, commit `aefbaad`):**
  1. admin role → always true (bypass). [unchanged]
  2. page absent from `ref_pages` registry → false.
  3. **explicit `user_page_access` override (granted 0/1) for this (user,page)** — grant=1 OVERRIDES the floor (returns true even if user-rank < min_role); grant=0 ALWAYS denies. [BEFORE the floor — the ONLY floor-bypass]
  4. **ROLE FLOOR: `_role_rank(user) < _role_rank(page.min_role)` → false.** ranks viewer0/operator1/manager2/admin3.
  5. preset membership (`ref_access_preset_pages` via `users.access_preset_id_fk`) — **reached ONLY after the floor passes; a preset CANNOT surface a page above the user's role floor.** [unchanged]
  6. fallback (no preset, floor passed) → true. [unchanged]
🔴 **CARDINAL (re-verified live on the VPS 2026-06-16 — corrects a prior loose framing): the ONLY mechanism that surfaces a page ABOVE a user's `min_role` floor is a per-user `user_page_access` grant (step 3). A PRESET NEVER bypasses the floor** — preset membership is step 5, consulted only AFTER step 4 passes, so a viewer is floored out at step 4 before the preset is ever read. This is why adding a page to a preset is NOT sufficient to grant a below-floor user access; a per-user `user_page_access` grant (or lowering `ref_pages.min_role`) is required. Live `user_can_access()` order confirmed verbatim: admin → registry → per-user override → role floor → preset → fallback. **An earlier agent's attempt to swap steps 4/5 so presets bypass the floor was REVERTED and NOT deployed** — it is a core-auth change affecting all users (out of scope); per-user grants achieve below-floor access without it.
**✅ FOOTGUN RESOLVED 2026-06-09 (commit `aefbaad` `fix(auth): explicit per-user page grant overrides role floor`, on maltyweb main + origin via auto-push hook, deployed via `bin/deploy.sh --apply`, `php -l` clean — CODE-ONLY, no migration, NO SOT/derivation/migration-head impact).** The old step3-before-step4 ordering made a `user_page_access.granted=1` row STRUCTURALLY DEAD for any page whose `min_role` outranked the grantee — the grant was never consulted. Swapped per the intended security model: explicit grant bypasses the floor, explicit deny always denies; admin bypass + preset membership + fallback unchanged. **Regression-clean: enumerated real data → ZERO existing `granted=1` rows sit on a page whose floor outranks the user, so the reorder changed no current real-world outcome (pure latent-footgun fix).** Temp-fixture proof (operator user 19 vs manager-floor page `settings` id 15, fixture deleted after, residual 0): baseline=deny, grant=1→allow, grant=0→deny, post-delete→deny — all 4 invariants pass.
  - **Prior live case 2026-06-09 (Olivier) STILL stands as shipped** — Tap&Shop `ref_pages` id=186 was floor-lowered `min_role` manager→operator (data-only UPDATE, seed oversight: read-only logistics page belongs to the logistics-team operators). With the footgun now fixed, the same situation would ALSO be solvable by an explicit per-user grant; the floor-lower remains the right call here because Tap&Shop is operator-wide, not one user.
**Grant UI** = `public/modules/reglages-generaux.php` `sec=access` (preset picker + per-user tri-state matrix + page-registry admin). **Page-access layers:** `ref_pages.min_role` (floor) + `ref_access_presets`/`ref_access_preset_pages`+`users.access_preset_id_fk` (preset) + `user_page_access`(user,page,granted) (sparse override). All migs 266-268.

## 🤖 WEBAPP-TESTING SMOKE BOTS — TWO-TIER FIXTURE (SHIPPED 2026-06-16, `1408f5b`+`f3c2536`)
Closes a real incident: a webapp-testing agent READ+MODIFIED a real admin's Argon2id password hash in prod during a smoke run. The bots give agents disposable, role-bounded logins so they **NEVER touch a real user's hash again.**
- **Viewer bot** — `users.id=16` `smoketest`, role=viewer. Access preset **`smoke_viewer` (id=11, mig 385)** + **8 per-user `user_page_access` grants (mig 386)** for above-floor pages: zeppelin, qa, approvisionnement, expeditions, warehouse, planning, rm-comparison, financier. 🔑 **WHY both:** mig 385's preset alone did NOT surface the operator/manager-floored pages — preset is step 5, floored out at step 4 for a viewer (see CARDINAL above). The per-user grants (mig 386, step 3) are what actually bypass the floor. **This fixture is the live proof of the preset-cannot-bypass-floor rule.** Re-seeded with 8 viewer-tier `data_ready` KPI trackers in mig 388. Creds: `secrets/maltyweb-smoketest.env` (gitignored, 600).
- **Manager bot** — `users.id=23` `smoketest_mgr`, role=manager, **`manager_scope=NULL` (deliberate)** (mig 388, `388_smoketest_manager_bot.sql`). Role-RANK lets it render manager-tier widgets; `manager_can(scope)` stays FALSE so scope-gated WRITES (COGS "Sceller", finance) remain blocked — smoke-verified no seal button in DOM. Creds: `secrets/maltyweb-smoketest-manager.env`. **Residual:** any `is_manager()`-gated write (not scope-gated) would technically pass — bot is rule-bound read-only.
- **WHY a manager bot was needed:** `mon-tableau.php` render-gates each selected KPI tracker by its own `ref_kpi_trackers.min_role` vs the user's role RANK (NO scope check in the tracker loop). The two sales widgets (`units_sold_sku` id 86, `top_skus_volume_revenue` id 92) are `min_role=manager` → a viewer can't render them. Hence the manager bot.
- **Skill ref:** `~/.claude/skills/webapp-testing/references/maltyweb-testing.md` documents both bots + the **never-touch-another-user's-hash rule.**
- 🔴 **An earlier agent's attempt to REORDER `user_can_access()` (swap floor/preset so presets bypass the floor) was REVERTED + NOT deployed** — core-auth change, all-users blast radius, out of scope. Per-user grants (mig 386) achieved the goal without it. (Same conclusion recorded in the CARDINAL note above.)

## ✅ SALES_MANAGER PRESET (id=9) — APPLIED + VERIFIED ON VPS 2026-06-11 (mig 324, for Louis Maechler)
**Build landed; PM ruling REFINED by operator (overrode parts of the option-B ruling). Final shipped state:**
- **Mig `324_sales_manager_preset.sql` APPLIED + VERIFIED on VPS** (head was 323 → 324 clean; idempotent re-run = no-op). **🟡 Migration file UNTRACKED locally — NOT yet committed** (functional SQL correct; only nit = a comment lists guessed production page_keys `brassage/soutirage/etc.` that are NOT our real keys — cosmetic, fix on commit).
- **New preset `sales_manager` (id=9)**, label "Responsable des ventes", desc "Ventes & marketing — finance + logistique (écriture) + Paramètres; pas de production".
- **Preset page set = 8 pages** (operator NARROWED from PM's fuller 17-page option-B list): `mon-tableau`, `financier`, `settings`, `approvisionnement`, `expeditions`, `tap-shop`, `warehouse`, `rm-comparison`. **Deliberately EXCLUDED:** all pure-production pages (zeppelin/wort/fermentation/packaging/qa) AND the admin trio (charges-bc/ingest/db-browser).
- **Intended Louis config:** `role=manager`, `manager_scope=logistics`, `access_preset_id_fk=9`. All settable from the `reglages-generaux` Utilisateurs create/edit form (UI verified to expose role + manager_scope select + preset select). **`manager_scope='logistics'` is the lever** giving WRITE on logistics/sales (`manager_can('logistics')=true`) while production stays read-only/absent (`manager_can('production')=false`).
- **DECISION TRAIL (do NOT re-litigate):** operator first chose "full admin" → reversed ("no need for Louis to be admin") → FINAL = "sales manager, read-only/no production, logistics yes". The **`require_admin()` blocker on the admin trio (charges-bc/ingest/db-browser) STANDS BY DESIGN** — those 3 pages remain un-grantable to a manager. The deferred **(C) capability refactor** (move charges-bc/ingest/db-browser off `require_admin` onto preset + a read-only capability) remains **BACKLOG, gated on a real recurring need.**
- **Tour-steward NOT dispatched** (no `ref_pages` change). 🟡 **OPEN tour-steward item (UNRELATED to this mig):** the post-apply tour-gap advisory reported **CRITICAL=2 PRE-EXISTING gaps** — 2 live pages lack Visite-guidée cards. Address separately via the steward.

## ✅ CFO `finance_viewer` PRESET (id=10) — BUILT + DEPLOYED + LIVE-VERIFIED 2026-06-16 (mig 378; for Thierry Stierli)
**The pending CFO read-only build is DONE and LIVE on the VPS. As-built (record):**
- **Mig `378_finance_viewer_preset.sql` APPLIED** — NOT 376/377 (those numbers were taken by parallel sessions; agent corrected to 378 at build time, validating the always-`--status`-at-build-start rule). Creates preset `finance_viewer` **id=10**, label **'Financier (lecture)'**, grants **20 pages**. NOTE on the page set: `fermentation` was NOT granted — it is **not** a `ref_pages` entry; the fermentation module is gated under `saisies`, which IS granted. Plus a `user_page_access` per-user override granting **user 22 → `charges-bc`**.
- **Thierry = user id=22 now (PM-verified live 2026-06-16):** `role=manager`, **`manager_scope='finance'`**, `access_preset_id_fk=10`. (Started `NULL` per Kouros ruling 1; set to `'finance'` 2026-06-16 to grant the finance-WRITE capability for the seal/restate gate — see CROSS-ARC RESOLVED below. `manager_scope` ENUM is now `('production','logistics','all','finance')` — 'finance' was added by the parallel financier work.)
- **Code shipped:**
  - `app/auth.php` gained **`can_write_expeditions($u)` = `is_admin() || role==='operator' || manager_can('logistics')`** — the new write-capability gate that closes Finding (A).
  - `public/modules/expeditions.php` — **16 edits**: 6 POST-handler gates + 10 render-hide gates, all now keyed on `can_write_expeditions()`. (Closes the write-leak where every expeditions write gated only on `require_page_access('expeditions')`+CSRF, no `manager_can`.)
  - `public/api/expeditions-line-status.php` — added `require_page_access` + `can_write` 403. **This closed a PRE-EXISTING hole** where ANY logged-in user could POST line-status (it had gated only on `current_user()!==null`+CSRF).
  - `public/modules/charges-bc.php` + `public/api/charges-bc-upload.php` — **`require_admin()` → `require_page_access('charges-bc')`** (mirrors the `bom-review.php` precedent). CFO reaches it via the per-user override; other managers stay role-floor-blocked → **zero blast radius**. **This realizes the deferred (C) capability refactor** that the SALES_MANAGER section (above) parked as backlog — at least for charges-bc; ingest/db-browser still keep `require_admin` (un-grantable, unchanged).
- **Live verification (recorded):** access matrix user 22 → expeditions / charges-bc / financier / approvisionnement / warehouse / rm-comparison / zeppelin = TRUE; ingest / db-browser = FALSE. Zero blast radius proof: Gonzalo Saporito (mgr, production scope) → charges-bc FALSE. Write-leak proof: operator Maxime Montendon + logistics mgr Nathan Aubert → `can_write_expeditions` TRUE. `php -l` clean on all; pages return 301; md5 parity local↔VPS confirmed. **NB the original verification recorded Thierry at scope=NULL → `can_write_expeditions` FALSE; scope is now 'finance', which is STILL `can_write_expeditions` FALSE ('finance' ∉ {all, logistics}; production⊇logistics widening doesn't apply) — operational read-only is unchanged by the finance-scope grant (PM re-verified live 2026-06-16).**
- **✅ GIT STATE — RESOLVED 2026-06-16. All work COMMITTED on maltyweb HEAD `15ac64d` and deployed to the VPS.** The HEAD-broken window is over. As-built commit split (note: NOT a single clean commit — the parallel sessions sharded it): parallel commit **`23c791b`** swept up this build's `expeditions.php` 16 edits (6 POST-handler `manager_can('logistics')` gates + 10 render-hide); commit **`15ac64d`** carries `app/auth.php` (`can_write_expeditions`), `public/modules/charges-bc.php` + `public/api/charges-bc-upload.php`, `public/api/expeditions-line-status.php`, and **mig 378**. The transient HEAD break (committed `expeditions.php` calling undefined `can_write_expeditions` before `auth.php` committed) is RESOLVED now both are in. **🟡 OPEN: commits are LOCAL on the VPS clone — push to origin NOT yet done** (Kouros pushes on request). **🟡 BROWSER SMOKE AS THIERRY — DONE 2026-06-16** (logged in live via temp password, restored after): Financier renders ("lecture seule"); ChargesBC renders with upload form; Approvisionnement read-only; Expéditions fully read-only (after the advance-button fix below); ingest/db-browser denied. All heavy write controls (client merge/validate, movements, giveaway, retours) already correctly hidden. UI confirmation now complete.
- **🔧 COSMETIC READ-ONLY GAP FOUND + FIXED during the smoke (2026-06-16):** the per-line status-advance button (rendered by **`exp_render_chips()`** in `public/modules/expeditions.php`, the `data-action="advance"` button) was **NOT render-gated** — it rendered ENABLED for the read-only CFO. Data was already protected (the API `expeditions-line-status.php` 403s him), so this was visible/clickable but inert. **FIX (one line, ~3259):** `$dis = ($isDone || !$isNext) ? …` → `$dis = ($isDone || !$isNext || !can_write_expeditions()) ? …`. Advance button now renders `disabled` for any non-writer (CFO/viewer); operators + logistics managers keep it enabled. Applied to BOTH local repo AND the VPS via **surgical single-line `str_replace` on the VPS** (NOT full-tree `deploy.sh`) to avoid pushing the parallel session's in-progress financier/retours edits. `php -l` clean; gate line present both sides. **🟡 NOT yet committed** (shared-clone contention) — will be swept by the parallel session's next `add -A`; consistent because `can_write_expeditions` is already in HEAD.
- **📌 LESSON REINFORCED (already in memory):** when gating a page read-only, gate the RENDER of *every* interactive write control, not just the POST handler — this advance button slipped through because it's rendered in a SHARED helper (`exp_render_chips`) far from the other gated controls. Co-locate or audit shared render helpers when sweeping a page for read-only.
- **🔴 INCIDENT (durable lesson) — concurrent edits in the SHARED maltyweb clone:** this build ran while another active session was concurrently editing/committing **financier** (the Dynamic-Financier seal/restate arc: `financier.php` resolver rework, `cogs-fiche-resolve.php`, `cogs-fiche-csv.php`, `financier.css/js`) AND **expeditions retours** (commit `23c791b` "replace retours form with BC-derived disposition queue"). Two consequences: (1) `bin/deploy.sh --apply` is a FULL-TREE rsync (public/ app/ db/ scripts/), so deploying from the shared dirty clone risks pushing another session's in-flight work — live financier happened to be OK because its dep `cogs-fiche-resolve.php` was already present on VPS; (2) the parallel commit swept up my staged `expeditions.php` and broke HEAD as above. **LESSON REINFORCED: concurrent sessions in one clone must use SEPARATE WORKTREES; multi-file maltyweb builds must NOT full-tree-deploy from a contended clone** (same class as the deploy-pushes-the-working-tree + commit-with-a-pathspec checklist rules — this is now a live two-fold proof case).
- **🟢 CROSS-ARC DEPENDENCY RESOLVED 2026-06-16 (PM-verified live):** the financier-arc owner set Thierry's `manager_scope='finance'` (the ENUM already carried 'finance' from the parallel financier work). PM verified live on the VPS: `manager_can('finance')`=**TRUE** for user 22 — driven by `auth.php` l.319 `if ($scope === $domain) return true;` — so the Dynamic-Financier seal/restate gate **`$canSealFiche = is_admin() || manager_can('finance')`** now PASSES. The CFO's "Sceller"/"Restater" button will be ACTIVE when that feature ships in front of him; **no further CFO-side action needed for sealing.** The grant is finance-write ONLY: it does NOT touch `can_write_expeditions` (FALSE) or `manager_can('logistics')` (FALSE), so he stays fully read-only on production/logistics/sales and Finding (A)'s write-leak fix is unaffected (it keys on `manager_can('logistics')`, not on being a manager). **PM end-state confirmed recorded: user 22, role=manager, manager_scope=finance, access_preset_id_fk=10.**
- **Tour:** no new `ref_pages` row → RULE 3 = confirm cards exist for the granted set (financier already has one; charges-bc is domain=admin → tour-EXEMPT by predicate). No steward dispatch owed.

## ⚠️ PARALLEL-DEVELOPER GUARDRAILS — Louis (sales-side) onboarding (recorded 2026-06-11)
A SECOND developer+Claude (Louis, sales side) is being onboarded NOW. The **5 PM-specified guardrails** are going into Louis's onboarding brief: (1) **PM reviews EVERY sales schema change** before it lands; (2) **sales READS cost, never recomputes** it (no divergent COGS/COP/WAC/BOM lane — consume the canonical feeds); (3) **channel attribution lives on the CUSTOMER, never guessed** (off-trade etc. = customer attribute, see off_trade rule); (4) **separate git worktrees per session + surgical file-scoped deploys** (the shared-tree leak class — deploy pushes the working tree, not HEAD); (5) **migration numbers are BROKERED** (re-check `migrate.php --status` + `ls db/migrations` immediately before assigning — VPS can lead local; parallel sessions collide on numbers).

## ✅ ADMIN-EDITABLE USERNAME (Identifiant) — SHIPPED+PUSHED 2026-06-09 (commit `2169a81`, fresh-context RULE-2 GO)
Admins can edit the login username in the `reglages-generaux.php` user-edit form. Shared helper **`rg_sanitize_username()`** used by BOTH `create_user` + `update_user` (parity-locked — same rule both paths). Allows Unicode letters/digits/spaces/apostrophe/hyphen/dot/underscore via regex `/[^\p{L}\p{N} ._\'\x{2019}-]/u` (mb_-safe, strips disallowed chars, 64-char cap). Includes uniqueness pre-check (`id <> ?`), audit before/after, self-rename `$_SESSION` refresh.
- **🔑 username IS the login credential** (collation `utf8mb4_unicode_ci` → login is **accent- AND case-insensitive**; `login.php` trims input). Renaming a user changes their login id but **never locks them out** — sessions/tokens are id-keyed, not username-keyed. **⚠️ SUPERSEDED 2026-06-09: `auth_verify()` no longer matches `WHERE username=?` directly — it routes through the `auth_resolve_user()` robust resolver (see § below). A typed identifier now resolves on full username OR first-name token OR email.**
- **LIVE-PROVEN 2026-06-09:** Olivier renamed to `OlivierBarral` (id 19); his subsequent FG-stocktake edit audit-logged the actor as `OlivierBarral` — rename propagated correctly through to the audit trail. See fulfilment-expeditions-arc/README.md §2026-06-09 GO-LIVE.
- **🟡 NIT to backlog (not urgent, out-of-scope for `2169a81`):** `display_name` in `reglages-generaux.php` is truncated with byte-`substr(…,0,128)` not `mb_substr` — could byte-truncate a 128+ byte accented display name. Log for a cleanup pass; harmless today.

## ✅ ROBUST LOGIN RESOLVER — `auth_resolve_user()` SHIPPED+LIVE+COMMITTED 2026-06-09 (commit `d50ec4d`, pushed; divergence flag CLEARED)
**Trigger:** operator changed ALL `users.username` from first-name → "First Last" (to carry the family name in the Identifiant). This LOCKED OUT already-onboarded users (Gonzalo, Yves, Xavier, Alex…) because `2169a81`'s `auth_verify WHERE username=?` is an EXACT match and they kept typing their FIRST name only. **Diagnosis: pure identifier-string mismatch — fail2ban clean, accounts active, password hashes intact.** Fix approved by Kouros ("robust resolver").
- **What shipped:** new **`auth_resolve_user(PDO, string): ?array`** in **`app/auth.php`**; `auth_verify()` now routes through it instead of matching `username` directly. A typed identifier resolves to a **single active user** if it matches **(a) the full username, OR (b) the first-name token, OR (c) the email** — all **case/accent-insensitive** (`utf8mb4_unicode_ci`) and **internal-whitespace-collapsed**.
- **🔒 SAFETY INVARIANT (the architectural keystone): ambiguous match = FAIL CLOSED.** If ≥2 distinct active users match (e.g. a future first-name collision), the resolver returns `null` — it NEVER picks arbitrarily. Password-verify / `is_active` gate / hash-rehash / `last_login` touch are ALL unchanged. **No `login.php` error-string change → no user enumeration** (the generic-error discipline holds).
- **Verified:** deployed via `bin/deploy.sh --apply`; `php -l` clean on VPS; **13/13 live resolver matrix passes** (first-name, full name, email, accents "Stéphane"/"stephane"→id9, single-word "smoketest"→id16, garbage→null); GROUP-BY first-token collision check = **zero collisions among 12 active users**. Sibling flows unaffected: `reglages-generaux` uniqueness check, set-password token flow, remember-me token rebuild.
- **🟡 DURABLE FOLLOW-UPS (soft auth dependency introduced — flag for the account-admin UI):**
  1. **✅ DONE+COMMITTED 2026-06-09 (commit `1fa4bcb`, pushed).** First-name uniqueness is a SOFT AUTH DEPENDENCY (two future users sharing a first name → first-name-token arm fails closed for BOTH until they use full-name/email). The `reglages-generaux.php` user-create/edit UI now **warns on first-name collision at create/edit time**. Build: new helpers `rg_first_name_token()` (mirrors the `auth_resolve_user` normalization) + `rg_first_name_collisions(PDO,$username,?$excludeUserId)` — queries users where `LOWER(SUBSTRING_INDEX(username,' ',1))` OR email local-part = the new username's first-name token, excludes self on update; **positional `?` params** (EMULATE_PREPARES=false forbids reusing a named placeholder). Wired **NON-BLOCKING** into `create_user` (all 3 flash branches) + `update_user`: appends a French ⚠ warning to the success flash naming the colliding account(s) ("must log in with full name or email"); admin can still proceed (two same-first-name people is legitimate — only the first-name shortcut breaks). Implementation note: flash system has only `ok`/`err` + single-message, so the ⚠ suffix was folded into the `ok` message (NO new `warn` level, no flash_render/CSS change). Verified: `php -l` clean on VPS; deployed via `bin/deploy.sh --apply`; 3/3 logic tests (Gonzalo Lopez→collides id2; Brandon Nouveau→0; update id2 self-excluded→0).
  2. **⏳ PENDING (backlog).** Add a **static validation hint** in the account-admin UI documenting that login accepts **first name / full name / email**. (Documentation-only; non-blocking.)
- **✅ DIVERGENCE FLAG CLEARED 2026-06-09.** Both auth changes are now committed+pushed on origin/main: resolver = `d50ec4d`, collision warning = `1fa4bcb`. The earlier "live-but-uncommitted" smell (same class as the `94d5a0d`/`ca65c22` login.php episodes) is RESOLVED — a clean-checkout deploy is now safe.

## ✅ ONBOARDING SELF-TEST PASSED + 5 DEFECTS FIXED (2026-06-07; maltyweb HEAD now includes `5f1c4fe`)
**Self-test:** temp user **TestOnboard** (users id=13, ghavami.kouros@gmail.com) ran the FULL invite flow
end-to-end: welcome email via noreply relay ✓ · set-password token ✓ · preset-scoped topbar ✓ · direct
logistics URL → "Accès refusé" (server-side `require_page_access`) ✓. **Then ERASED same day:** DELETE
`user_kpi_selections` (7) + `user_invites` (1) + `auth_shared_devices` (1) + `users` row — with
`audit_row_revisions` tombstone (action='update', after_json `_tombstone`, target_table='users', target_pk=13).

**5 defects the self-test caught — ALL FIXED:**
1. **qc_flag 'ok' → ENUM truncation 500** on mon-tableau KPI writes → fixed to `'normal'` (maltyweb `5c31060`).
2. **`set-password.php` auto-login freshUser SELECT missing `manager_scope` + `access_preset_id_fk`** →
   EVERY invited user's FIRST session bypassed the page ACL (full topbar). Fixed + deployed + committed.
   (Same lesson class as the B2 "session payload sync — three sites" rule below: a 4th sync site existed.)
3. **`mon-tableau` page was in NO access preset** — preset-bearing users are HARD-DENIED on non-member
   pages (resolver step 5 returns, no fallback) → all operators/managers would have been locked out of
   their own KPI dashboard + recap cadence UI. Fixed: `ref_pages` id=20 (mon-tableau) INSERTed into
   `ref_access_preset_pages` for presets 1, 2, 3 (direct SQL, INSERT IGNORE).
4. **`topbar.php` brand link hardcoded `/modules/triage.php`** → "Accès refusé" for every non-admin
   clicking the MT logo. Fixed: brand href → `/modules/mon-tableau.php` (personal dashboard = universal
   home). maltyweb `5f1c4fe`, deployed.
5. **`ref_kpi_trackers` id 266 "Jours de couverture stock (MP + PF)" actually returned RM stock CHF**
   (handler `inventory_days_of_supply`, seeded when packaging gap #58 blocked the real computation) →
   relabeled **"Valeur stock MP (CHF)"** via SQL; REAL days-of-supply handler queued for Mon tableau v2
   (gap #58 now fixed). → kpi-tracker-catalog/README.md.

**Known residual — ✅ CLOSED 2026-06-07 evening (`db2da33`), see §SUB-PAGE ACL HARDENING below.**

**Next operator steps:** real welcome-invite clicks in Réglages généraux §Utilisateurs — managers
Yves/Gonzalo/Nathan FIRST → verify one manager's nav scoping → then operators; **SKIP Joelson until
joelson@lanebuleuse.ch mailbox exists**. Recap-email cron flip AFTER Kouros confirms inbox rendering
(uncomment the `db/cron/maltytask-kpi-recap.cron` line → commit → deploy).

## 🗓 2026-06-07 EVENING CLOSE-OUT — interstitial COMMITTED + recap cron LIVE + smoke E2 + viewer floor
**1. Device-classification interstitial `94d5a0d` COMMITTED — as-built MATCHES the PM spec** (debrief done; §FIRST-LOGIN DEVICE-CLASSIFICATION below = the spec, now confirmed): personal=is_shared=0 'Appareil personnel'; shared=labeled is_shared=1 + `rt_revoke_current()` (NEW helper in remember_token.php — cookie-hash revoke, NOT rt_lookup which ROTATES); remember-me DEFERRED via `$_SESSION['pending_remember']` (issue-then-revoke race eliminated, PM ruling held); `device_classified()`+`device_mark_personal()` in device.php; outside ref_pages; ZERO mig (Q1 ruling held). **⚠️ login.php wiring DEPLOYED but UNCOMMITTED** — file shared with the parallel tour session; whoever commits next carries both knowingly.
**2. Recap cron ACTIVATED `1d8672b`** — runs as user **`maltytask` NOT www-data** (www-data can't write /var/log/maltytask → would have silently killed every run). **HOUSE CONVENTION: PHP crons run as `maltytask`.** Operator confirmed inbox rendering — the user-account arc's last pending build is DONE.
**3. Smoke E2 + permanent smoketest account:** users id=16, viewer, permanent; creds `/home/kluk/projects/maltytask/secrets/maltyweb-smoketest.env` (gitignored, 600, operator blessed). WSL network workaround documented in webapp-testing skill reference (ssh -L 8443 + Node CONNECT proxy + --proxy-server; login path = `/login.php`). PASS: login flow, empty-state, recap no-email message, charges-bc admin gate, MT logo→mon-tableau, 390px, wort regression, console clean. FAIL→FIXED: picker 4/8 (stale const → `f7fe151`, → kpi-tracker-catalog/README.md); viewer CSV/form access (item 4). QUEUED: **topbar horizontal overflow at 820px tablet-landscape** (no mid-width breakpoint; hamburger only at mobile) → belongs to the PARALLEL session's topbar a11y arc, do NOT double-edit.
**4. `f7fe151` data: `ref_pages.min_role` operator-floor on 12 pages** — viewer keeps mon-tableau/sb-board/sb-guerre ONLY; smoke showed a preset-less viewer could download warehouse GL CSV + reach the live brewing form. Audit row.
**5. Invites:** Stéphane still not clicked; Joelson still waits on mailbox (unchanged).

## 🗓 2026-06-07 EVENING — SESSION-PAYLOAD DEFECT #3 + SUB-PAGE ACL HARDENING (Mon tableau v2 batch; → kpi-tracker-catalog/README.md §2026-06-07 EVENING for the KPI side)
**1. `2a8f2b5` recap-email guard fix = THIRD member of the SESSION-PAYLOAD DEFECT FAMILY.**
`auth_login()`'s session payload carries NO `email` → the mon-tableau recap-cadence guard read it from
session and refused EVERYONE. Fix = read `users.email` by id at page load — admin-added emails take effect
WITHOUT re-login. Family roster: (1) B2 "three sites" manager_scope sync; (2) set-password freshUser SELECT
missing manager_scope+access_preset_id_fk (self-test defect #2); (3) this. **CONVENTION: a feature gating on
a `users` column must either sync it into ALL session builders (auth_login + remember-me rebuild +
set-password) OR read it from DB at use-time — PREFER the DB-read for low-frequency gates.**
**2. `db2da33` sub-page ACL hardening — 15 sub-pages gated** with `require_page_access()` inheriting the
parent page_key: forms+sessions+session-shell→`saisies`; sb-batch/sb-mother→`sb-board`;
sku-cost-detail→`sku-costs`; warehouse-export→`warehouse`; salle-des-machines→`zeppelin`;
salle-fournisseurs→`approvisionnement` (⚠️ FLAGGED: NO nav link anywhere — `ref_pages` registration QUEUED);
triage partials→`triage` (these had NO auth bootstrap at all on direct access).
**3. Data fix:** users id=9 `'Stphane'`→`'Stéphane'` (typo; audit row written; collation is
accent/case-insensitive so login was forgiving either way).
**4. Invite status 2026-06-07:** **7/8 invites SENT** (status=sent via IONOS); Stéphane's invite NOT yet
clicked; Joelson still waits on mailbox.
**5. Phase 3 deployed smoke** (webapp-testing, read-only vs prod) launched — results land next update.

## ✅ POST-SHIP CORRECTIONS + SHARED-DEVICE POLICY (2026-06-04, SHIPPED+LIVE+COMMITTED on `main`, NOT pushed) — PM-VERIFIED against VPS DB
**1. Post-ship corrections (`97aae6d`):**
- **(a) De-hardcoded brewery identity in email + set-password footers.** They had been HARDCODED to an
  invented "Brasserie artisanale · Neuchâtel" / "Lausanne" — the "Neuchâtel" was a `placeholder=` value
  copied off the settings city input (example text mistaken for data). New `brewery_identity()` helper in
  `app/settings-helpers.php` reads the DB: name from `system_settings.brewery_name`, city/country from the
  `ref_sites` production row (`ORDER BY id` → id=1 "La Nébuleuse - Production", **Renens, CH**). **PM-VERIFIED
  LIVE 2026-06-04:** `brewery_identity()` returns `{name:'La Nébuleuse', city:'Renens', country:'Suisse',
  country_code:'CH'}`. This is the incident that drove the `coder`+`ui` skill anti-pattern below.
- **(b)** `update_user` can now set a user's **email** (was a gap — couldn't fix/add an email post-create).
- **(c)** Onboarding action now branches on `last_login_at`: never-logged-in → "Envoyer l'e-mail de
  bienvenue" (invite/welcome template); logged-in → "Réinitialiser le mot de passe" (reset). So a user's
  FIRST email is ALWAYS the welcome one, never a bare reset.

**2. Coding-skill hardening (PM-authored into BOTH `coder` + `ui` SKILL.md):** anti-pattern = "**never
hardcode operator-configurable data** — read from `system_settings`/`ref_sites` (Zeppelin → Données
générales); **a `placeholder=` is example text, never a data source**", citing the 2026-06-04 Neuchâtel
incident. Future coding agents equip these skills and inherit the rule.

**3. Shared-device policy (`1649fb0` + `2e92de1`, mig 264 `264_auth_shared_devices` — PM-VERIFIED LIVE: table
present, cols `id/device_id char(36)/label/is_shared/registered_by/registered_at/last_seen_at/last_ip/last_ua`).**
Closes a real attribution hole: shared control-room PCs/pads must NOT offer "remember me" (which would
silently keep the PREVIOUS operator logged in → wrong `counted_by`/`created_by` on every form they submit).
- **`app/services/device.php`** — per-browser `mt_device_id` cookie + `mark`/`unmark`/`is_shared`/`list`/`touch`.
- **`login.php`** — SERVER-SIDE refuses remember-me on registered shared devices + suppresses the checkbox
  (defense-in-depth, not just hiding the box). Footer here also de-hardcoded via `brewery_identity()`.
- **`public/admin/settings/devices.php`** — "Cet appareil" panel (ANY user can mark the CURRENT browser
  shared; unmark/relabel is admin-only) + admin central "Postes partagés" list.
- **Topbar** gained **"Changer d'utilisateur"** for fast operator handoff on a shared station.

**3b. Admin un-share of a shared device (2026-06-19, Thierry/CFO "PC Bureau admin").** Resolution: device `515f2f2a-4cb7-4408-becb-7879e9386d2c` (`auth_shared_devices` id=30, label "PC Bureau admin") flipped `is_shared=1 → 0` via the **canonical house writer `device_unmark_shared(string $deviceId, PDO $pdo): void`** (`app/services/device.php:159`), run as the **maltytask** user via a throwaway VPS PHP script (admin UI not reachable from CLI; ran the house writer, NOT raw SQL — correct call). Label and `users` table UNTOUCHED. **Why:** un-sharing the device is the PRECONDITION for "Se souvenir de moi" to be offered — `login.php` server-side SUPPRESSES remember-me on `is_shared=1` devices (the attribution-hole defense), so a shared-flagged device would never let Thierry mint a token. **🔴 STILL OPEN (operator action, not a bug):** Thierry has 0 rows in `user_remember_tokens` — the 90-day token is minted only when he logs in once with "Se souvenir de moi" TICKED. Un-sharing does not back-fill a token. Verified BEFORE/AFTER same row, is_shared 1→0, last_seen_at 2026-06-19 08:46:09. **Reusable: to give an admin a persistent login on a personal-but-historically-shared device → `device_unmark_shared()` (not raw UPDATE), then the user logs in once with remember-me ticked.**

## ✅ VISITE GUIDÉE — FIRST-LOGIN ONBOARDING TOUR (SHIPPED+LIVE 2026-06-07 LATE NIGHT, `ca65c22`, mig 280; PM-verified live)
Kouros's ask: team creates accounts from 2026-06-08; every new account gets a guided visit of THEIR accessible pages (esp. Mon tableau + Saisies forms).
- **Page:** `/modules/visite-guidee.php` — `require_login()` only, deliberately NOT in `ref_pages` (no topbar nav item). Steps built server-side from `ref_pages` (is_active=1, domain≠admin) × `user_can_access()` → each user sees only what their preset grants. French content map keyed by `page_key` with **label-fallback for future pages**; deep-dives: Mon tableau, Saisies hub (section-opener), the 4 production forms (gated on wort/fermentation/packaging access), Inventaire RM, "Bon à savoir" (draft autosave/keepalive/green flash), final step → mon-tableau. Preset 2 renders 17 steps. Mockup kept at `public/_design/visite-guidee.html`.
- **Mig 280** `users.tour_seen_at TIMESTAMP NULL` — marked on first tour LOAD (`UPDATE … WHERE tour_seen_at IS NULL` + audit revision 'Visite guidée : première ouverture') → exactly one auto-redirect, never a nag loop. Live 2026-06-07: 1/11 users toured; the other 10 (incl. Kouros + existing users) get it once on next login — intended.
- **Login flow:** default post-auth landing changed `/` → `/modules/mon-tableau.php` (no more DB-status dev page); `tour_seen_at IS NULL` overrides `next` → tour. set-password: fresh invitees → tour; password RESETS of existing users → mon-tableau (**freshUser SELECT extended with tour_seen_at — same incident class as the manager_scope omission: extend the freshUser SELECT whenever a login-flow-bearing users column is added**). Topbar user panel: "Visite guidée" re-launch link above "Mes appareils". **⚠️ The invite / set-password path is: set password → auto-login → Visite guidée → Mon tableau, with NO device-classification prompt** (that interstitial is a `login.php`-only step — see §FIRST-LOGIN DEVICE-CLASSIFICATION AS-BUILT CORRECTION). set-password auto-login also does NOT stamp `last_login_at` (login.php does) — a fresh invitee shows `tour_seen_at` set but `last_login_at` NULL until their first real login.
- **UI:** `public/css/visite-guidee.css` + `public/js/visite-guidee.js`, kraft tokens only; **grid-overlay step pattern** (all steps grid-row/column 1, inactive `visibility:hidden` — chosen after an overflow-clipping incident with absolute positioning + min-height); inline-SVG vignettes (NO emoji — tofu on emoji-less tablets); self-clipping `overflow-x:hidden` on own body class (inactive steps carry translateX(32px) → widened mobile to 425px, caught at 390px check).
- **Verified end-to-end** with self-cleaned test user (tourtest2, erased w/ audit tombstone): redirect/17 steps/keyboard nav/Ouvrir-cette-page/re-launch/Terminer/second-login all ✓. ⚠️ Heavy Playwright login testing tripped **fail2ban** — a 403 mid-smoke may be the jail, NOT architecture (a verification agent wrongly concluded "Tailscale-only by design").
- **STANDING CONVENTION:** whenever a new `ref_pages` entry ships, add a bespoke French row to the tour content map (fallback renders the label, but bespoke text is better).
- **2026-06-07 FOLLOW-UP (operator-requested, PM-consulted): EXPAND the 4 production-form steps into multi-sub-step chapters** — field-group-level FR explanations: each input, parallel runs ("+ Ajouter un format parallèle", additive qte semantics), and HOW the pertes liquides are computed (canonical semantics = `app/loss-metrics.php` header R3/R4/R5 + packaging-losses-convention.md dispositions). Tour grows to ~25-30 steps for production presets; sub-steps inherit the parent form's access gate; content stays in the PHP map (page_key + sub-index), NO content table, NO mig. PM content rulings delivered in consultation (pertes chain, parallel-run wording, vetoes: no DB nomenclature incl. NOT copying form-packaging.php:2451's prod_total_units helper text; no numeric thresholds; no future-module promises; verify-in-code list: R6 forced-comment surfacing, brewing-loss flag on form, live FR labels, cold-crash UI state).
- **✅ CHAPTER EXPANSION AS-BUILT (SHIPPED+LIVE+PUSHED 2026-06-08, maltyweb main `91f8c82`):** 17 → **25 steps** for production presets; logistics preset unchanged at 11 (no production chapters, no capstone). Chapters: Brassage 3 / Fermentation 2 / Transferts 3 / Conditionnement 3 / capstone "La chaîne des pertes" (production-gated) / Inventaire RM enriched (vide ≠ zéro). Content map stays pure PHP keyed page_key + sub-index — no DB, no migration. PM consultation answers went in essentially verbatim; the 4 verify-in-code flags resolved against deployed code: (a) cold crash = checkbox in the Mesures section (copy adjusted); (b) racking loss labels "Perte cuve départ"/"Perte cuve arrivée", forced comment IS live (outlier when loss_note empty); (c) packaging disposition live labels used verbatim ("Perte liquide sans capsule"/"autre"/"à moitié remplie", "Perte capuchon fût"); (d) "+ Ajouter un format parallèle" confirmed. Vetoes enforced + grep-verified on rendered HTML: zero DB nomenclature (prod_total_units/qte_unites/loss_/bd_/_fk), no inline numeric thresholds ("selon les seuils configurés"), no future promises, no purge-cadence detail. UI: chapter eyebrow + mini-progress badge ("SAISIE · BRASSAGE 1/3"); compact dot mode >20 steps (5px dots, chapter gaps, ::before tap-zone expansion), 390px no-overflow verified; welcome copy "3 minutes" → "5 minutes". Verified live with self-cleaned test users tourx2 (preset 2, 25 steps all visible) + tourx3 (preset 3, 11 steps, no Brassage/capstone) — both tombstoned + deleted same session (audit ids 10860/10861), one login each (fail2ban-aware).
- **⚠️ OPEN POLISH (queued from this build): `form-packaging.php` helper text (~line 2451-2452) shows RAW field names `prod_total_units`/`qte_unites` to operators** — violates the no-DB-nomenclature rule on the FORM itself (the tour worked AROUND it; the form still exposes it). Small ui+coder fix; fold into the next packaging-form or ui-polish dispatch. STILL OPEN as of 2026-06-09 (explicitly held SEPARATE from the tour-steward work below).

## ✅ TOUR-STEWARD SYSTEM — SHIPPED 2026-06-09 (governance for tour coverage; ⚠️ NOT YET COMMITTED; PM RULE 3)
The standing convention "new ref_pages entry → bespoke tour content row" is now an enforced two-layer system + a build agent. Built on PM's ratified terms.
- **TOUR-CARD PREDICATE** (what "this page needs a tour card" means, = visite-guidee.php's own card filter minus saisies): `is_active=1 AND (domain IS NULL OR domain <> 'admin') AND page_key <> 'saisies'`. visite-guidee.php itself queries `WHERE is_active=1 AND (domain IS NULL OR domain != 'admin') ORDER BY sort` then per-user `user_can_access()`; `saisies` is excluded from the predicate because the tour special-cases it into its own opener + form chapters (not a `$PAGE_DESCRIPTIONS` entry). Admin domain excluded.
- **LAYER 1 — DETECTION** (PM's design: NO git hook, NO marker file): `scripts/tour-gap-check.php` — note `scripts/` NOT `bin/` (repo convention = PHP CLI in scripts/, mirrors `scripts/reconcile-pages.php`). Read-only drift detector: live `ref_pages` MINUS the page_keys in all THREE content maps (`$PAGE_DESCRIPTIONS` mandatory, `$PAGE_ICONS` recommended, `vg_vignette_for()` optional). Block-scoped TEXT parse (file can't be include'd — login side effects). Severity tiers: CRITICAL=no description / MINOR=no icon / INFO=no vignette / LATENT=inactive page no card. Exit 1 on CRITICAL or MINOR; INFO/LATENT advisory-only. `--quiet`. Run: `ssh maltyweb "sudo php /var/www/maltytask/scripts/tour-gap-check.php"`.
- **LAYER 1 — AUTO-TRIGGER** (PM's call instead of a git hook): `scripts/migrate.php` post-apply advisory — if any just-applied migration's SQL contains `ref_pages`, it passthru-shells `tour-gap-check.php --quiet` (read-only) and prints a WARNING if a gap exists. NEVER blocks, never changes migrate.php's exit code. So every migration touching ref_pages auto-flags tour gaps at apply time.
- **LAYER 2 — THE AGENT:** `/home/kluk/.claude/agents/maltyweb-tour-steward.md` (model sonnet, EQUIP coder+ui+webapp-testing). Codifies the six mechanical copy rules (house voice / no-DB-nomenclature grep-verified / no-thresholds / no-future-promises / bespoke SVG / optional vignette) AND the four mandatory-ratification carve-outs (COGS-COP-WAC-BOM-beertax · identity/access · ambiguous-purpose · self-flagged-uncertain). **CRITICAL IMPL DETAIL:** a subagent cannot call another subagent, so the steward does NOT consult PM directly — for a sensitive-class page it DRAFTS the copy, STOPS before deploy, and returns it to the orchestrator flagged `PM-RATIFY: <page_key>`; the orchestrator (Opus) routes that draft to PM for ratification and relays the ruling. Non-sensitive pages: steward drafts-to-rules + deploys + smokes autonomously. Includes no-commit + no-dirty-tree-deploy (selective rsync) guards.
- **COVERAGE STATE (live gap-check 2026-06-09):** `critical=0 minor=0 info=8 latent=0`, exit 0. tap-shop (active) + qa (inactive, pre-written) both fully covered. The 8 INFO = active pages with description+icon but the default vignette — purely cosmetic, no action needed. `zeppelin` left as-is per ruling.
- **⚠️ NOT YET COMMITTED (2026-06-09):** three files deployed-live-but-uncommitted in the maltyweb working tree — `public/modules/visite-guidee.php` (tap-shop+qa cards), `scripts/tour-gap-check.php` (NEW), `scripts/migrate.php` (advisory). The agent file is in ~/.claude (separate). Operator deciding whether to commit. LIVE DIRTY-TREE HAZARD (checklist §line 14): a `bin/deploy.sh --apply` from this tree leaks all three (plus any other session's dirty files); commit-before-deploy / `git status` before `--apply`.
- **DEFERRED POLISH:** zeppelin per-family tour depth (deferred, low payoff); the 8 INFO default-vignette pages (cosmetic backlog).
- **PM OWNS dispatching the tour-steward after any `ref_pages`-touching build** — promoted to RULE 3 in the BUILD-LAUNCH CHECKLIST (index memory).

## ✅ FIRST-LOGIN DEVICE-CLASSIFICATION INTERSTITIAL — SHIPPED+COMMITTED (`94d5a0d`, 2026-06-07, ZERO mig; **as-built CONFIRMED vs spec at the evening close-out debrief — see §2026-06-07 EVENING CLOSE-OUT item 1**; login.php wiring COMMITTED via `ca65c22`)
> **🔴 AS-BUILT CORRECTION (operator-verified vs deployed code 2026-06-08 — supersedes any "part of the set-password / invite flow" reading of the spec below):** the device-classification interstitial is a **`login.php`-ONLY step**, NOT part of the set-password / invite sequence.
> - **`public/login.php`** (≈L91-95) is the SOLE trigger: `if (!device_classified($deviceId, $pdo)) { redirect → classification-appareil }`. **`public/set-password.php` does NOT classify the device at all.**
> - So the **invite / set-password path = set password → auto-login → Visite guidée → Mon tableau, with NO device prompt.** The device prompt only appears on the user's **next normal login through login.php** (if the device is still unclassified).
> - **`set-password.php` auto-login does NOT stamp `last_login_at`** (only `login.php` does) → a freshly-invited user who only ever set their password shows `last_login_at = NULL` even though `tour_seen_at` is stamped and the invite is consumed. (Onboarding-status UI branches on `last_login_at` — a never-real-logged-in invitee reads as "never logged in", which is correct.)
> - **Remember-me deferral is `login.php`-scoped:** if "remember me" is ticked but the device is unclassified, the token is DEFERRED (`$_SESSION['pending_remember']`) until after classification. Therefore a user who only ever did set-password (never a login.php login) has an unclassified device AND no persistent remember token until their first real login + classification.
> - Real-world confirmation: Xavier (user id=6, operator, Android) onboarded 2026-06-08 — invite consumed 11:38:50, `tour_seen_at` stamped same time, `last_login_at` still NULL; Kouros confirmed NO device prompt after the guided tour. Matches the corrected flow.
> - **Reconciliation with the spec paragraph below:** the "next= chain wraps the final $next incl. the tour override" language describes the login.php redirect ordering only; it does NOT mean set-password routes through the interstitial. The spec's "after login" = after a login.php auth, not after the invite auto-login.

PM-briefed brief delivered 2026-06-07 night; commit `94d5a0d` landed same night; as-built verified against this spec at the close-out sync. Design: after login, if browser's `mt_device_id` has NO row in `auth_shared_devices` → redirect to a one-time interstitial `modules/classification-appareil.php` (require_login only, NOT in ref_pages — visite-guidee precedent) "Où es-tu connecté ?": (a) personnel → NEW `device_mark_personal()` upsert `is_shared=0` (ZERO migration — is_shared=0 row = personal classification, model-consistent w/ device_unmark_shared keeping rows); (b)/(c)/(d) → `device_mark_shared()` w/ label ('PC Salle de contrôle 1 (gauche)' etc.). **Remember-me race ruling = DEFER ISSUANCE:** login.php on unclassified device does NOT rt_create even if box ticked — stashes `$_SESSION['pending_remember']=1`; interstitial POST (a) → rt_create then; (b/c/d) → discard + NEW `rt_revoke_current()` (hash mt_remember cookie → DELETE row → rt_clear_cookie; also kills PRE-EXISTING tokens e.g. Kouros's old logins on the control-room PCs; do NOT use rt_lookup — it ROTATES). next= chain preserved: classification interstitial wraps the final $next (incl. the tour override) via `?next=rawurlencode($next)` → classification FIRST, then visite-guidee, then destination. ⚠️ `public/login.php` is MODIFIED-UNCOMMITTED by the PARALLEL tour session (tour redirect block L70-77, visite-guidee files untracked) — coordinate commits, don't clobber. devices.php precedent: CSRF yes, log_revision NOT used on device writes (table self-documents via registered_by/registered_at). Admin "Postes partagés" list shows labels already; admin can fix a wrong personal-classification via "Cet appareil" mark-shared upsert (flips is_shared→1). NEXT FREE mig stays 281-after-remediation but THIS BUILD NEEDS ZERO MIGRATION.

## 🧭 ADVISORY DECISIONS — DEVICE / ACCESS / EMAIL STRATEGY (access model OVERRIDDEN 2026-06-08; email/PWA settled 2026-06-04)
- **🔒 CANONICAL ACCESS MODEL = TAILSCALE-ONLY (operator override 2026-06-08, supersedes the 2026-06-04 "public-internet-facing" ruling — the public-facing line is RETIRED; do NOT let any future session reopen the app to the public internet).** App is served ONLY on the Tailscale IPv4 100.125.142.25:443 nginx block (`allow 100.64.0.0/10; deny all;`). Public IPv4 83.228.215.243 AND public IPv6 `[2001:1600:18:203::3b4]` both `return 403`. The 2026-05-13 phaseA config is therefore **CORRECT, not drift** — keep it. Every team device joins the tailnet (install Tailscale + sign in to the kouros@ account, tailnet `tailac5876.ts.net`); VPS node = `maltytask-vps` (100.125.142.25, tailnet IPv6 `fd7a:115c:a1e0::a835:8e1a`). PROGRESS 2026-06-08: both control-room PCs operational; Gonzalo + Yves (managers) set up their accounts via the real Tailscale path.
- **✅ ACCESS-INFRA BLOCKER FULLY CLOSED — phone-CONFIRMED working 2026-06-08 (Xavier, s24-de-xavier, first real phone-over-cellular onboarding).** Two-part durable fix; the access path now resolves correctly for tailnet phones with NO per-device hack and NO hosts-file.
  - **❌ CORRECTION — the old "Tailscale MagicDNS has an IPv4-only override → A 100.125.142.25 for app.maltytask.ch" claim was WRONG (never existed).** Verified via `tailscale dns status` on the VPS: the ONLY split-DNS route was for `ts.net` itself — there was NEVER a MagicDNS override for app.maltytask.ch. The two control-room PCs were working PURELY off their manual Windows `hosts` file entry (`100.125.142.25 app.maltytask.ch`). Phones can't use a hosts file → they resolved app.maltytask.ch → public A `83.228.215.243` → the nginx `return 403` block. The AAAA deletion ALONE did not fix mobile (it only removed the IPv6 403 trap; the IPv4 public-A 403 still bit phones).
  - **Final durable fix = two parts:** (a) **AAAA for app.maltytask.ch DELETED at IONOS** (removed the IPv6 403 trap; public A `83.228.215.243` retained as the IPv4 403 block). (b) **REAL tailnet split-DNS override built** (this is what fixes phones): on the VPS, systemd-resolved given an extra stub listener on the tailnet IP — `/etc/systemd/resolved.conf.d/tailnet-stub.conf` with `DNSStubListenerExtra=100.125.142.25`, plus an `/etc/hosts` line `100.125.142.25 app.maltytask.ch` (backup `/etc/hosts.bak-*`); keeps `127.0.0.53` for the VPS's OWN resolution and forwards non-override names upstream (1.1.1.1 / 8.8.8.8). Then in the Tailscale **admin console: DNS → Nameservers → custom nameserver `100.125.142.25` RESTRICTED to domain `app.maltytask.ch`**. Verified end-to-end: query via the tailnet resolver returns 100.125.142.25, fetch over that path returns 302 (the app), VPS own DNS intact, Xavier's phone reaches the app.
  - **⚠️ GOTCHA that cost a round-trip:** the first admin-console attempt typo'd the restricted domain as `matlytask.ch` (l/t transposed) → silently inert. **ALWAYS verify with `tailscale dns status` (Split DNS Routes section) after editing** — confirm the spelling appears as a route.
  - **Consequences:** the control-room PC `hosts`-trick is now **legacy + removable** (the real split-DNS route supersedes it). **NEW ANDROID-ONBOARDING GOTCHA:** device-level **Private DNS (DoT)** set to a custom provider BYPASSES Tailscale DNS and re-breaks access — must be **Automatic/Off**. Reusable diagnostics: `tailscale ping <device>` (tailnet reachability), `tailscale dns status` (confirm split-route spelling).
- **Email relay reality (settled):** VPS host = Infomaniak (blocks outbound 25); maltytask.ch mail = IONOS;
  lanebuleuse.ch = Google. **Final = Postfix relays via `smtp.ionos.co.uk:587` as `noreply@maltytask.ch`**
  (IONOS Mail Basic mailbox). [Full postconf in §EMAIL TRANSPORT below.]
- **No native smartphone app** — agreed to **PWA-ify the existing responsive forms** instead (per PM
  recommendation): wire the existing manifest+sw into a shared head, repoint `start_url` → `/modules/saisies.php`,
  keep SW passthrough, touch/readability pass. **ON BACKLOG (not built).**

## ✅ COMPLETION STATE (2026-06-04, LIVE-VERIFIED against the VPS DB)
**SHIPPED + LIVE + COMMITTED — 5 clean commits on `main`, NOT pushed; HEAD `993d5ae` (PLUS the 3
post-ship-correction commits `97aae6d`/`1649fb0`/`2e92de1` above + mig 264, also NOT pushed).** Migs 261/262
tracked + applied (live-verified: schema_migrations has `261_users_manager_scope.sql`,
`262_user_invites.sql`; `users.manager_scope` enum present; `user_invites` table present). Scope
distribution live-confirmed: production=Gonzalo,Yves / logistics=Nathan / NULL=7 (incl admin kouros —
admin is 'all' in CODE, the column stays NULL for him). **Email onboarding live-confirmed PENDING: only
1/10 users (kouros) has an email** — admin must add emails + invite the rest.

- **B1 (`7102d3b`, mig 261):** `users.manager_scope ENUM('production','logistics','all') NULL`. Backfill:
  Gonzalo+Yves='production', Nathan='logistics', admin treated 'all' in code, operators NULL.
- **C1 (`d6c4edf`, mig 262):** `user_invites` (sha256 token, 72h, single-use, purpose invite|reset, FK
  INT UNSIGNED) + public `/set-password.php` (Argon2id, TOCTOU consume-gate proven, no enumeration,
  rate-limited, auto-login; reset-purpose calls `rt_revoke_all`).
- **A (`9b02b22`):** `reglages-generaux.php` §users extended — per-user edit (display_name/role/
  manager_scope), activate/deactivate (deactivate ⇒ `rt_revoke_all`), reset-password + (re)send-invite,
  create_user gained email + optional-password→invite. **Last-active-admin lockout guard +
  self-deactivation block.** CSS in `public/css/reglages-generaux.css`.
- **B2 (`a69156f`):** `manager_can($domain,$u)` in auth.php (admin⇒true; manager⇒scope==='all'||
  scope===$domain; PLUS production⊇logistics branch so a production manager keeps supply-chain).
  manager_scope carried through ALL 3 session-sync sites (auth_verify SELECT, auth_login payload,
  remember-me rebuild payload, rt_lookup SELECT). Gated **group A** (form-fermenting/racking/packaging
  override fields → `manager_can('production')`, read-only-if-denied + **SERVER-SIDE submit reject** —
  found+fixed a REAL HOLE where `fermenting-phase-submit.php` trusted the POST `purge_override`
  unconditionally) and **group D** (`sf-update-field.php` proposal-INSERT → `manager_can('logistics')`).
  ⚠️ **The fermenting-side B2 edits (form-fermenting.php + fermenting-phase-submit.php) are deliberately
  UNCOMMITTED**, entangled with the separate in-progress fermenting-correctness arc.
- **C2 (`993d5ae`):** `app/services/mailer.php` (`send_mail` MIME multipart, RFC2047 subject, QP body;
  French invite/reset templates) wired into create/invite/reset actions (emails the set-password link;
  copy-able fallback link retained).

## 📮 EMAIL TRANSPORT (VPS config — NOT in repo; operational facts, record verbatim)
- **Relay-provider pivot:** operator first picked Infomaniak, but PM verified neither domain's mail is at
  Infomaniak (maltytask.ch=IONOS, lanebuleuse.ch=Google) AND Infomaniak blocks outbound port 25.
  Corrected to relay through the From-domain's own host.
- **FINAL = Postfix send-only null-client on the VPS relaying → `smtp.ionos.co.uk:587`** (SASL/STARTTLS,
  `smtp_tls_security_level=encrypt`), sender `noreply@maltytask.ch` (new IONOS "Mail Basic 2GB" mailbox).
  Key postconf: `relayhost=[smtp.ionos.co.uk]:587`, `inet_interfaces=loopback-only`,
  `mydestination=localhost` (was polluted with maltytask.ch by debconf — FIXED), `myorigin=maltytask.ch`,
  `smtputf8_enable=no` (IONOS has no SMTPUTF8 — UTF-8 subjects bounced until disabled),
  `smtp_generic_maps` rewrites local senders → noreply@maltytask.ch. Creds in `/etc/postfix/sasl_passwd`
  (root 600, NEVER committed). DNS: none needed — IONOS already publishes SPF/DKIM/DMARC(p=none) for
  maltytask.ch.
- **Verified:** both raw `sendmail` AND the real app `send_mail()` path delivered status=sent (250
  accepted by IONOS). Awaiting only operator inbox/spam confirmation.

## ✅ ACCESS MODEL RESOLVED — TAILSCALE-ONLY (operator override 2026-06-08; the "nginx drift" framing below is OBSOLETE)
- **RESOLUTION (2026-06-08):** Kouros OVERRODE the 2026-06-04 "public-internet-facing" ruling and chose to KEEP the app **Tailscale-only.** The live nginx config (2026-05-13 phaseA: public IPv4 + public IPv6 → `return 403`; only Tailscale 100.125.142.25:443 serves the app) is therefore **CORRECT, deliberate, KEEP IT** — it was never drift to fix. The whole-app public-facing recommendation is **RETIRED**; do NOT reopen the app to the public internet. Canonical model + IPv6 trap + durable-fix-pending → §ADVISORY DECISIONS above.
- **2026-06-08 AM symptom (now explained, not a bug to fix):** team on public IPs (Gonzalo 185.25.194.123) hit 403 on `set-password` invite links because they weren't yet on the tailnet. Onboarding answer = join the tailnet, NOT open the app. Both control-room PCs + Gonzalo + Yves now operational via Tailscale.
- **Historical mis-call note (kept for the record):** a verification agent twice (`:101`, changelog-2026-06.md:60) saw a transient mid-smoke 403 and wrongly concluded "Tailscale-only by design" — but those were fail2ban/transient, coincidentally landing on the answer that is now the deliberate choice. The 2026-06-08 AM "stale drift" diagnosis was itself superseded by the operator's override the same day.

## ⏳ PENDING (backlog, not blocking)
- **✅ P3 KPI recap emails — SHIPPED + CRON ACTIVATED** (mig 277 `user_kpi_recap_subs` + `send-kpi-recap.php`; cron flipped LIVE 2026-06-07 `1d8672b` after operator confirmed inbox rendering) — **the user-account arc's LAST build is DONE**; everything below is operator workflow or fast-follow. Detail → kpi-tracker-catalog/README.md.
- **2FA (TOTP)** for admin + managers — agreed fast-follow. **🔒 PRIORITY DRIVER (2026-06-09):** operator Olivier (id 19) onboarded on his OWN personal, unmanaged Windows computer (tailnet node `olivier` = 100.115.87.61) — a device we do NOT control now has full ERP access via Tailscale. Tailnet-membership is necessary but not sufficient device-trust; 2FA is the layer that addresses unmanaged-but-tailnet-joined devices. This is the surface the hardening should target (the narrow admin-Tailscale-gate is already moot — whole app is tailnet-gated). As the team grows beyond control-room PCs onto personal machines, treat 2FA as the next real hardening step, not just an admin nicety.
- **~~Admin-tier Tailscale gate~~ — MOOT (operator override 2026-06-08).** The whole app is already tailnet-gated (Tailscale-only, see §ADVISORY DECISIONS + §ACCESS MODEL RESOLVED), so a narrow `/admin/*` tailnet gate adds nothing — every page already requires the tailnet. Dropped from the backlog. **~~Access-infra (IPv6 trap + mobile access)~~ — ✅ FULLY CLOSED + PHONE-CONFIRMED 2026-06-08** (Xavier's phone reaches the app end-to-end). Two-part fix: public AAAA deleted at IONOS + REAL tailnet split-DNS override (systemd-resolved `DNSStubListenerExtra=100.125.142.25` + Tailscale admin-console custom nameserver restricted to `app.maltytask.ch`). The old "MagicDNS IPv4-only override existed" claim was FALSE — corrected; control-room PC hosts-trick now legacy/removable. **NO open access-infra item.** Android gotcha for future onboardings: device Private DNS (DoT) must be Automatic/Off (custom DoT bypasses Tailscale DNS). Detail → §ADVISORY DECISIONS §ACCESS-INFRA BLOCKER.
- **PWA-ify the saisie forms** + touch/readability pass (the "no native app" decision above): shared
  manifest+sw head, `start_url`→`/modules/saisies.php`, SW passthrough. Agreed, not built.
- **B2-followup:** QA/QC `salle-de-controle.php` (~23 `is_admin||is_manager` sites) → `manager_can('production')`,
  same pattern (deferred from B2 by PM ruling, recorded in §ATOM B2 SCOPE RULING below).
- **Onboarding rollout:** SELF-TEST PASSED 2026-06-07 (TestOnboard, §above — 5 defects fixed). PROGRESS: control-room PCs + managers Gonzalo + Yves operational via Tailscale (2026-06-08); **operator Olivier (id 19, `OlivierBarral`, preset=marketing id 8) onboarded + logged in + ran a live FG stocktake 2026-06-09** (on his own personal Windows machine — see §2FA driver above + fulfilment-expeditions-arc/README.md §2026-06-09 GO-LIVE). Remaining = remaining real invites; SKIP Joelson until joelson@lanebuleuse.ch mailbox exists. First email each user gets is the WELCOME one (1c).
- **🟡 NIT (backlog, harmless):** `reglages-generaux.php` `display_name` uses byte-`substr(…,0,128)` not `mb_substr` → could byte-truncate a 128+ byte accented display name. Cleanup pass; see §ADMIN-EDITABLE USERNAME for context.
- **Sub-page ACL hardening** (form-*, sessions, sb-batch, sb-mother, session-shell, sku-cost-detail,
  warehouse-export, salle-des-machines, salle-fournisseurs = `require_login()` only) — queued in the
  Mon tableau v2 batch (§self-test residual above).

---
## (ORIGINAL SCOPING — design record, SHIPPED) ⬇

Operator created users (1 admin / 3 manager / 6 operator, all active) but wanted 3 changes
before sending login links. PM scoped against live code+DB 2026-06-04.

## AS-BUILT AUTH MODEL (verified live 2026-06-04)
- **`users` table** (mig 001 + 022): `id INT UNSIGNED PK`, `username VARCHAR(64) UNIQUE`,
  `email VARCHAR(255) NULL UNIQUE`, `password_hash VARCHAR(255)`, `display_name VARCHAR(128) NULL`,
  `role ENUM('admin','operator','viewer','manager') DEFAULT 'operator'`, `is_active TINYINT(1) DEFAULT 1`,
  `created_at`, `last_login_at NULL`. Collation utf8mb4_unicode_ci.
- **Auth code = `app/auth.php`** — Argon2id (`PASSWORD_ARGON2ID`), `password_needs_rehash` auto-upgrade,
  hardened session (HttpOnly/SameSite=Strict/Secure-auto, name `maltytask_sid`, 30min idle, 15min regen).
  Helpers: `auth_verify()`, `auth_login()`, `current_user()`, `require_login()`, `auth_logout()`,
  `is_admin($u=null)`, `is_manager($u=null)`, `require_admin()`, `require_manager_or_admin()`, `_send_403()`.
  Session payload carries ONLY `{id,username,display_name,role}`.
- **Login = `public/login.php`** — CSRF, generic-error, fail2ban log line to /var/log/maltytask/auth.log,
  open-redirect guard on `next`, remember-me checkbox.
- **Remember-me = `app/services/remember_token.php`** — `user_remember_tokens` table (mig 037),
  sha256 token_hash, 90-day TTL, rotation-on-use, IP/UA bind. Functions `rt_create/rt_lookup/rt_revoke/
  rt_revoke_all/rt_list/rt_clear_cookie`, consts `RT_COOKIE_NAME='mt_remember'`/`RT_TTL_DAYS=90`.
  **THIS IS THE TEMPLATE for the invite-token flow** (same single-use-token + hash-at-rest + expiry shape).
- **Rate-limit = `app/services/rate_limit.php`** (`rl_check_and_log`) + `user_action_log` table (mig 037).
- **CLI create = `scripts/create-user.php`** — interactive password, role allowlist STALE
  (`admin|operator|viewer`, MISSING `manager`). Low priority but flag for fix.

## ROLE TAXONOMY (today)
- 4 roles: admin / manager / operator / viewer.
- **admin** = everything (DB browser, ChargesBC, ingest, settings, direct edits, retro-link).
- **manager** = `require_manager_or_admin()` surfaces (all `/admin/settings/*` CRUD pages) + the
  PROPOSAL path (see below) + form OVERRIDE powers (hors-process, tank-reading override) on
  packaging/racking/fermenting forms via `is_admin($me)||is_manager($me)`.
- **operator** = `require_login()` only. Production saisie forms (brewing/packaging/fermenting/racking/
  rm-stocktake) gate on `require_login()` ALONE — any logged-in user submits; manager/admin only unlock
  override fields. **So there is NO domain-scoping on saisie today.**
- **viewer** = read-only (unused in practice).

## THE MODIFICATION-REQUEST PATTERN ALREADY EXISTS (key finding)
- `ref_supplier_proposals` (mig 125) + `public/api/sf-update-field.php`: **admin → direct UPDATE;
  manager → INSERT proposal row (status=pending) for admin review.** One-field-per-row, full audit
  (proposed_by/reviewed_by FK users.id, status enum pending/approved/rejected). schema_meta class='audit'.
  Also `mi_proposals_audit` (mig 036, MI-specific, corrections_policy='blocked').
  This IS the "manager submits a modification request" mechanism the operator is describing — it exists
  for the SUPPLIER (logistics) domain. Production forms write DIRECTLY (no proposal layer yet).

## DOMAIN MAP (for the prod-vs-logistics split)
Nav families (topbar.php `$modules`) cleave naturally:
- **LOGISTICS / supplychain domain**: Approvisionnement (`approvisionnement.php`), Inventaire RM
  (`form-rm-stocktake.php`), supplier fiches (`salle-fournisseurs.php` + `sf-update-field.php`),
  Bilan MP. → logistics-manager scope = THIS only.
- **PRODUCTION domain**: Conditionnement/packaging, Transferts/racking, Brassage/brewing, Fermentation,
  QA/QC, Recettes (SDC). → production-manager scope = production + logistics (superset).
- Fulfilment (05) = future, deliberately out of scope for now (logistics gets it "later").

## PM-RECOMMENDED MODEL FOR THE SPLIT (blessed direction)
- DO NOT explode the `role` ENUM into prod_manager/log_manager (would shatter every
  `is_manager()` call site + the gate helpers). **Keep role='manager'; add an orthogonal SCOPE column.**
- Add `manager_scope ENUM('production','logistics','all') NULL` (NULL for non-managers; admin treated as 'all').
  Helper `manager_can(string $domain, ?array $u)` in auth.php; tag each saisie/proposal surface with a
  `DOMAIN` constant ('production'|'logistics') and gate the SUBMIT (not the view) by `manager_can()`.
- This is additive, ENUM-extension-free on `role`, reversible, and mirrors the existing scope-by-domain
  intent without a permissions join table (overkill for 2 domains + 1 future).

## EMAIL CAPABILITY = ZERO (verified)
No mailer lib, no composer.json, no `mail()`, no SMTP config anywhere. Invite flow is greenfield.
PM convention: new `user_invites` table (token_hash sha256, user_id FK, expires_at ~72h, consumed_at,
created_by) modelled on `user_remember_tokens`; set-password page `/set-password.php?token=...` verifies
hash+expiry, lets user set password (Argon2id, ≥8 chars, same rules as create-user) + own display_name,
flips is_active=1, consumes token, auto-logs-in. Admin "renvoyer l'invitation" regenerates. For the
actual SEND: pick ONE — (a) PHP `mail()`/sendmail if VPS MTA exists (cheapest, check first), or
(b) SMTP via a tiny lib. AVOID heavy frameworks. Token table + set-password page can ship BEFORE the
send wiring (admin copies the link manually as fallback) — de-risks the email dependency.

## SEQUENCING (PM-blessed)
1. **Admin user-management** (extend `reglages-generaux.php` §users — ALREADY has list + create-user
   action w/ CSRF+log_revision+PRG; add edit/reset-pw/toggle-active/change-role actions). Prereq for nothing else but lowest-risk, immediately useful.
2. **manager_scope split** (migration + auth helper + tag+gate surfaces). Independent of 1; can parallelize.
3. **Email onboarding** LAST (token table + set-password page first, send-wiring after MTA check).
   Depends on 1 (admin creates the user that gets invited; the create-user action becomes
   create-and-invite). When 3 lands, password becomes optional at create (invite sets it).

## ⏩ BUILD PROGRESS — ✅ ALL ATOMS COMPLETE (see §COMPLETION STATE at top for the authoritative as-built)
- **B1 ✅** `7102d3b` mig 261 · **C1 ✅** `d6c4edf` mig 262 · **A ✅** `9b02b22` · **B2 ✅** `a69156f`
  (ferm-side edits UNCOMMITTED, entangled w/ fermenting arc) · **C2 ✅** `993d5ae` (mailer + Postfix relay).
- 5 commits on main, NOT pushed, HEAD `993d5ae`. Email transport live via Postfix→IONOS (details at top).

## ⚖️ ATOM B2 SCOPE RULING (2026-06-04, PM, live-verified against call sites)
**Decision: B2 gates the SUBMIT/WRITE path of the OPERATIONAL OVERRIDE surfaces (group A) + the SUPPLIER-PROPOSAL surface (D/sf-update-field) NOW. Master-data settings CRUD (E) + salle-des-machines tier (C) STAY on the existing coarse `require_manager_or_admin()` / role-label gate and are OUT of the B2 scope split.** Rationale: the operator's framing is "what MODIFICATION REQUESTS each manager can submit (prod vs supplychain)" — that maps to (i) production override powers on saisie forms and (ii) the supplier-proposal path. Editing the SKU/recipe/yeast catalog via /admin/settings/* is master-data CRUD, not a prod-vs-logistics modification-request, and is already admin-or-manager-gated; do NOT shatter it into domain scopes. QA/QC (B, salle-de-controle) = **PRODUCTION domain** but DEFERRED to a B2-followup (it's ~25 inline gates, mechanical but high surface; land A+D first, then sweep B in the same pattern). C (salle-des-machines:33) is a cosmetic role-LABEL only (sets `$bodyRole` string) — no power attached → leave as-is.

**Per-group domain table (hand to coder):**
| Group | Surface | B2 action | Domain |
|---|---|---|---|
| A | form-fermenting :114, session-body-racking :45, form-racking :84/:501, form-packaging :347/:436/:1470 (canOverride / horsProcess / tankReadingOverride) | GATE submit + render override inputs read-only-if-denied | **production** |
| B | salle-de-controle.php ~25 `is_admin||is_manager` gates | DEFER to B2-followup, same pattern | production |
| C | salle-des-machines :33 (`$bodyRole` label) | LEAVE (cosmetic, no power) | n/a |
| D | sf-update-field :44 (manager→proposal), approvisionnement :26, salle-fournisseurs :32 | GATE the proposal-INSERT in sf-update-field by `manager_can('logistics')`; :26/:32 are labels → LEAVE | **logistics** |
| E | /admin/settings/* `require_manager_or_admin()` | LEAVE on coarse gate, OUT of split | n/a (master-data CRUD) |
| F | topbar :30 (`$showAdminBlock`), auth :249 (`require_manager_or_admin` core) | LEAVE; nav visibility unchanged | n/a |

**Helper:** `manager_can(string $domain, ?array $u = null): bool` in auth.php. admin ⇒ true; manager ⇒ `scope==='all' || scope===$domain`; so a `manager_can('logistics')` check passes for BOTH prod+log scopes (production ⊇ logistics by design — prod managers keep supply-chain powers). **Do NOT add `require_manager_scope()`** — `require_manager_or_admin()` stays the coarse page-level gate; `manager_can()` is used INLINE at the override-field + proposal sites only (no domain-aware require_* variant needed at function level).

**Render rule:** gate the WRITE path with `manager_can`; render override INPUT fields READ-ONLY (not hidden) for a manager lacking the domain — matches the existing readonly pattern (salle-de-controle.php:2696). Confirmed.

**Session payload sync — THREE sites (all verified 2026-06-04):** B2 adds `manager_scope` to (1) `auth_verify()` SELECT (app/auth.php:63), (2) `rt_lookup()` user SELECT (app/services/remember_token.php:153 — remember-me rebuild reads role from DB, MUST carry scope too), and (3) BOTH `$_SESSION["user"]` builders — `auth_login()` (auth.php:90) AND the remember-me rebuild in `current_user()` (auth.php ~153). No other reader of session role needs sync (is_admin/is_manager read role only). So `manager_can()` reads scope from session with no DB hit on the hot path.

## CONVENTIONS / GOTCHAS to enforce
- Migration head LIVE = **260** (verified 2026-06-04; my old memory said 257/258 — DRIFT. NEXT FREE = 261,
  re-verify `migrate.php --status` at build start). MySQL 8 (no IF NOT EXISTS on ALTER). schema_meta row
  per new table. FK to users.id must be INT UNSIGNED.
- House helpers to reuse (don't reinvent): `post_str`/`must_be_one_of`/`flash_set`/`redirect_to`/
  `pdo_friendly_error` (`app/settings-helpers.php`), `log_revision` (`app/db-write-helpers.php`),
  `csrf_token`/`csrf_verify` (`app/csrf.php`). PRG on every write. NEVER log password_hash.
- CSS in `/public/css/`, JS in `/public/js/` — `reglages-generaux` already has its own stylesheet.
- PHP query-param: read with `?? default` THEN validate (the silent-NULL trap).
- Reset-password = admin sets a temp OR (better) fires an invite/reset token — do NOT show plaintext.
- Self-lockout guard: an admin must not be able to demote/deactivate the last active admin (count guard).
