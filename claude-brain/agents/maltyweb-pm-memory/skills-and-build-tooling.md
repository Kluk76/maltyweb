# Skills inventory + build tooling (PM-stewarded)

> Read when: equipping a build agent, planning a post-deploy smoke suite, doing skill authoring/vetting work, or when an arc activates that has a noted tooling gap (2FA, PWA, cron-email, Shopify, dataviz-heavy dashboard).
> Last updated: 2026-06-07 (added SKILL-FIRING MATRIX + PM standing behaviors, operator standing order).

## 🎯 SKILL-FIRING MATRIX (operator standing order 2026-06-07 — "PM names the skills, Kouros never reminds again")
Frontmatter-verified against `~/.claude/skills/*/SKILL.md` 2026-06-07. WORK TYPE → SKILLS TO EQUIP:

| Work type | Equip | Notes |
|---|---|---|
| maltyweb PHP/backend logic, handlers, cron, deploy, scripts/* automation, TS migration | **coder** (+ **sql** whenever any query/migration is in scope — nearly always) | coder covers everything OUTSIDE `lib/invoice-parsers/` |
| SQL DDL/DML, migrations `db/migrations/`, backfills, report queries, PHP-module queries, ad-hoc verification SQL | **sql** — layers WITH coder or parser-coder, NEVER alone for code work | 26+ anti-patterns, migration pre-flight, write discipline live here |
| Operator-facing rendering: pages, modules, modals, charts, dashboards, CSS `/public/css`, JS `/public/js`, PHP→window.JSON→JS hydration | **ui** + **coder** + **sql** (the data-layer query feeding the view) | `frontend-design` aesthetic discipline is already FOLDED INTO ui for greenfield visuals — do not equip separately |
| Invoice/document parsers, dispatcher, OCR (`lib/ocr-core.js`), ingest pipeline, `parse_ingredient_blob` | **parser-coder** (+ **sql** when DB writes in scope) | NOT coder — parser-coder replaces it inside `lib/invoice-parsers/` |
| Verifying a SHIPPED/DEPLOYED surface in the browser: smoke tests, screenshots, post-deploy checks, "does it render" | **webapp-testing** | READ-ONLY against prod (GET+assert only); any form POST needs operator approval + self-cleaning fixtures; see its `references/maltyweb-testing.md` |
| Auth/session/2FA/security-sensitive PHP | **coder** AND name **`coder/references/php-security.md`** explicitly in the brief | designated prep for the 2FA + Tailscale-gate arc |
| Chart/dataviz DESIGN decisions (encoding choice, color, chart type) | **ui** AND name **`ui/references/dataviz.md`** | incl. the --hop/--ember deuteranopia flag |
| InnoDB perf/locking/online-DDL/replication depth | **sql** AND name its **`references/innodb/`** layer | precedence: maltytask conventions WIN on conflict |
| Spreadsheet file as primary input/output (RawDB-normalized.xlsx, budget xlsx, csv/tsv) | **xlsx** | deliverable must be a spreadsheet file |
| Evaluating/installing ANY third-party skill/plugin | **skill-vetting** FIRST, always | before anything lands under ~/.claude/skills or runs |
| Authoring/improving OUR OWN skills (ui/sql/coder/mw-*) | **skill-creator** | PM charter: invoke it before any skill edit |
| Memory/index compaction (PM memory, project MEMORY.md, oversized topic file) | **memory-hygiene** | protects harness-flat vs agent-subdir distinction |

## 📌 PM STANDING BEHAVIORS (self-binding, operator-ordered 2026-06-07)
1. **Every build brief / dispatch guidance / resume recap I issue ENDS with an `EQUIP:` line** naming the exact skills (and the named references — php-security.md / dataviz.md / innodb/ — when applicable). No brief leaves the PM without it.
2. **Deployed-page verification ⇒ webapp-testing smoke step by default** — when a build's verification touches a live page, the brief includes a READ-ONLY webapp-testing smoke step (title / zero console errors / nav-200s / chart-svg-nonempty) without being asked.
3. **Proactive flagging** — when I see work dispatched (by the orchestrator or anyone) without the right skills equipped, I flag it immediately and call for re-launch; I do not wait to be asked.

## Bespoke skills (PM-stewarded — standing edit permission)
- `~/.claude/skills/ui/` — maltyweb UI/rendering; design system, UX-quality battery, rendering-bug catalog. **NEW 2026-06-07: `references/dataviz.md`** — Cleveland-McGill encoding hierarchy, channel-by-data-type, Shneiderman overview→zoom→detail mapped to `kpi-charts.js`, SVG/Canvas thresholds, **colorblind flag: the house --hop/--ember green-orange pair is a deuteranopia danger zone — needs a second channel (shape/label/pattern) whenever those two are the only series discriminator.** Plus WCAG 2.2 target-size 2.5.8 + template-group audit sampling folded into `references/ux-quality-rules.md`. **UPGRADE 2026-06-07 (mined emilkowalski + pbakaus/impeccable + Leonxlnx/taste-skill, vetted):** P0–P3 audit severity (§11), persona-based review (§12: power-operator/a11y/stress/floor-tablet), cognitive-load ≤4 working-memory items (§10), eight-interactive-states checklist, `references/motion.md` animation craft (frequency gate, custom ease tokens `--ease-out/--ease-in-out/--ease-drawer` — TO BE ADDED to app.css :root, not yet present as of 2026-06-07), design-consistency locks (tinted shadows never raw black on kraft, corner-radius lock, theme-family lock, accents <80% sat), perf hard-bans (no scroll listeners for visuals, no backdrop-filter on scrolling containers — NB app.css family has 27 backdrop-filter occurrences to triage), French error-message formulas, redesign priority order (font→color→states→layout→components→loading/empty/error→polish). First consumer = the UI/UX quality rework arc → ui-ux-quality-rework-arc.md.
- `~/.claude/skills/sql/` — MySQL craft, 29 anti-patterns, migration discipline. **NEW 2026-06-07: `references/innodb/` — 18 PlanetScale files vendored @b156f4c** (deadlocks, row-locking, online-DDL, replication-lag, EXPLAIN, indexing, data-types, isolation…), wired into SKILL.md as a read-on-demand layer. **PRECEDENCE RULE: on any conflict, maltytask write-discipline/conventions WIN** (collation `_unicode_ci`, DECIMAL(14,4), PK sizing, deadlock retry on 1213+1205). Deliberately NOT installed as a separate competing skill (trigger-collision risk).
- `~/.claude/skills/coder/` — general maltytask/maltyweb coding. **NEW 2026-06-07: `references/php-security.md`** — OWASP-2025 sessions/auth checklist (entropy, session-ID regenerate on privilege change, cookie flags, `hash_equals`, Argon2id), TOTP/2FA implementation notes (RFC 6238, encrypted secrets, rate-limit, hashed backup codes), dynamic-identifier-whitelist rule for PDO, 15-point review checklist. **Designated prep for the 2FA + admin-Tailscale-gate arc** (user-auth backlog, RESUME POINT #4) — equip coder and read this file when that arc dispatches.
- `parser-coder`, `skill-vetting` — hand-curated, NOT PM-editable (out of scope per charter).
- `mw-*` namespace reserved for PM-created skills — none created yet.

## Third-party skills installed (vetted via skill-vetting first — sweep of 2026-06-07)
- **`webapp-testing`** (anthropics/skills@da20c92) — Playwright (Python sync) browser automation: smoke tests, screenshots, console logs. **Augmented with `references/maltyweb-testing.md`:** Playwright golden rules (NO `wait_for_timeout`, web-first auto-retrying assertions, role locators, storage-state auth) + maltyweb specifics: app.maltytask.ch is REMOTE (no `with_server.py`); login = GET form → parse CSRF → POST, creds from env NEVER hardcoded; **READ-ONLY smoke discipline against prod — GET+assert only; any form POST needs operator approval + self-cleaning fixtures**; smoke assertions = title / zero console errors / nav-200s / chart-svg-nonempty. **DESIGNATED VEHICLE for the post-deploy smoke suite** (closes PM gap #2: no repeatable smoke for the ~18 `ref_pages` pages; also serves the Atom-11 operator-smoke gate context). Candidate next build when mother-shell resumes or after any multi-page-touching deploy. **⚠️ NETWORK GOTCHA (2026-06-07, hit during UI/UX arc Phase 2):** from the WSL box, app.maltytask.ch resolves to Tailscale `100.125.142.25` — curl traverses the tailnet but headless Chromium TIMES OUT. Workaround for static/`_design` pages: curl to /tmp, screenshot via `file://` URL. Authenticated-page smokes from this box need the same treatment or must run ON the VPS. Every webapp-testing dispatch from this box must plan around this.
- **`xlsx`** — spreadsheet read/write/clean; for RawDB-normalized.xlsx and budget-sheet work.

## Gap-consultation ledger (PM gaps of 2026-06-07 → resolution)
| Gap | Resolution |
|---|---|
| #1 auth security (2FA arc) | `coder/references/php-security.md` (in-house, OWASP-sourced) |
| #2 post-deploy smoke | `webapp-testing` skill + maltyweb-testing.md reference — suite to be BUILT on it |
| #3 PWA | nothing worthwhile in ecosystem → build in-house when arc activates; mining target noted: secondsky manifest/SW skeleton |
| #4 cron-email | nothing worthwhile → in-house (P3 KPI recap arc) |
| #5/#8 SQL perf + ops | `sql/references/innodb/` vendored layer (precedence rule above) |
| #6 Shopify | nothing worthwhile → in-house when Stage-2/pickup-flag feed starts |
| #7 dataviz | `ui/references/dataviz.md` |

## Queued skill fold-ins (PM to apply via `skill-creator` at next skill-touch)
- **`sql` skill — ENUM ordering convention (decided 2026-06-07, mig 276 whirlpool):** new ENUM values are APPENDED LAST (index safety); any ordering/sorting over ENUM-typed columns uses an explicit `FIELD()`/rank map, NEVER ENUM declaration order. Real case: `hop_addition_stage` process order (mash→first_wort→boil→whirlpool→hop_stand→dry_hop) ≠ append order.
- **`sql`/`coder` skill — v1↔v2 id-join trap (F2 inc#2, 2026-06-07):** a `source_id`-style column pointing into a v1 table must NEVER be joined against the v2 sibling's PK — same family as the broken sheet_row_index bridge; consider folding as a generalized "provenance ids join only against their declared source table" rule.

## Vetting outcomes worth remembering
- **snapsynapse a11y-audit: HELD** (supply-chain WARN — runtime unpinned `npm install`). Its template-group sampling pattern was MINED into `ui/references/ux-quality-rules.md` instead. Do not install without re-vetting a pinned version.
- House policy confirmed in practice: mine techniques into bespoke skills rather than installing generic third-party ones; install only when the tool itself (not just its knowledge) is the value (webapp-testing's Playwright harness, xlsx's recipes).
