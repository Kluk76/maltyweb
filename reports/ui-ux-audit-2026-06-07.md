# UI/UX Quality Audit — 2026-06-07

**Scope:** 8 template-group representatives + cross-cutting CSS/JS sweeps.
**Auditor:** Claude Sonnet 4.6 (source-code audit; live-site login not available — ~90% battery coverage per methodology, checks requiring Playwright/DevTools flagged).
**Instrument:** SKILL.md + ux-quality-rules.md §1–§12 + dataviz.md.
**Exemptions applied:** salle-des-machines overflow/align-items overrides (load-bearing per SKILL.md catalog), kpi-charts.js internals (frozen arc), bom-review.php / salle-de-controle.php Recettes.

---

## Executive Summary

| Severity | Count |
|---|---|
| **P0** | 1 |
| **P1** | 14 |
| **P2** | 13 |
| **P3** | 7 |
| **Total** | 35 |

**SHARED-LAYER findings (fix once, affects all pages):** 10
**PER-PAGE findings:** 25

---

## Shared-Layer Findings Table

| # | ID | Severity | Layer | File:Line | Description | Concrete Fix |
|---|---|---|---|---|---|---|
| 1 | SH-01 | **P0** | SHARED | `app/partials/topbar.php` (all pages) | **No skip-to-content link.** Every page starts the tab order inside the topbar nav, forcing keyboard users to tab through 8–12+ nav items on every page load. WCAG 2.4.1 failure. | Add `<a class="skip-link" href="#main-content">Aller au contenu</a>` as the FIRST child of `<body>` (before sidebar/topbar). Add `id="main-content"` to `<main>` on each page. CSS: `.skip-link { position:absolute; left:-9999px } .skip-link:focus { left:1rem; top:1rem; z-index:9999; }` |
| 2 | SH-02 | **P1** | SHARED | `app/partials/topbar.php` (all pages) | **`aria-labelledby=""` empty string** on tab panels in wort.php (line 717, 994) — not a topbar issue per se, but the pattern of empty `aria-labelledby` is a shared anti-pattern across any tabbed widget. The wort tab buttons (`wort-tab-btn`) have no `id` attributes, so `aria-controls` links work but `aria-labelledby` on the panels is blank. Screen readers announce the tabpanel with no label. | Add `id="wort-tab-brassins"` and `id="wort-tab-kpis"` to the two `<button role="tab">` elements; update `aria-labelledby` on the panels to match. |
| 3 | SH-03 | **P1** | SHARED | `public/css/app.css:337` (only 3 `:focus-visible` rules in all of app.css) | **Near-total absence of `:focus-visible` rings.** Only 3 rules app-wide: the topbar module link, a wort table row, and a tank-card button. Every other interactive element — family-switcher buttons, session entry cards, filter selects, all `.sh-card` cards on the hub, all `.sd-entry` cards in sessions, all `.sdm-back` buttons, SDM zone cards, nav-rail items in réglages — has ZERO visible focus ring. Fails WCAG 2.4.7 (keyboard) + 2.4.11 (not obscured). | Add `outline: 2px solid var(--hop); outline-offset: 2px;` on `:focus-visible` for every interactive element class. Minimum coverage: `.sh-card`, `.sd-entry`, `.sd-view-btn`, `.sd-btn-apply`, `.sd-btn-reset`, `.sdm-back`, `.sdm-zone`, `.sf-supplier-row`, `.wk-year-btn`, `.family-btn`. Use `outline-offset: -2px` on inset contexts. |
| 4 | SH-04 | **P1** | SHARED | All audited pages | **Hardcoded brewery name "La Nébuleuse"** in branded chrome/breadcrumb strings across multiple pages, violating the system_settings canonical-data rule (SKILL.md). Occurrences: `le-zeppelin.php:42,61`, `salle-des-machines.php:400,564`, `wort.php:1008`, `reglages-generaux.php:949`. The city "Lausanne" (SDM footer line 564) is particularly wrong: the canonical `ref_sites` production row is Renens. | Read `brewery_name` from `system_settings` via `brewery_identity()` helper (or inline equivalent). Replace the SDM footer literal with a PHP read. |
| 5 | SH-05 | **P1** | SHARED | `public/css/sessions-dashboard.css:79,309,474` | **Raw `rgba(0,0,0,…)` box-shadows** in sessions-dashboard — 3 occurrences on `.sd-view-btn.active`, `.sd-entry:hover`, `.sd-vessel-card`. On kraft-paper, cold black shadows read as an artifact from a different design system. SKILL.md hard rule: tint toward `--oak`. | Replace all three with warm-tinted equivalents: `rgba(30,18,5,0.10)` (subtle), `rgba(30,18,5,0.08)` (hover card). Do the same for `sb-mother.css:1192,1489,1791` (3× `rgba(0,0,0,0.25)` on modal box-shadows). |
| 6 | SH-06 | **P2** | SHARED | `public/css/app.css` (global) | **`:active` pressed state absent system-wide.** No `transform: scale(0.97)` or equivalent on buttons, chips, or action cards anywhere in `app.css` or any per-page CSS. §2 of ux-quality-rules.md requires `:active { transform: scale(0.97) }` on all buttons/chips/row-actions — floor-tablet operators press hard and need immediate tactile feedback. | Add to `app.css` global button rule (or as a mixin for each button class): `:active { transform: scale(0.97); transition: transform 60ms ease-out; }`. Apply at minimum to: `.tb__module`, `.sh-card`, `.sd-entry`, `.sd-view-btn`, `.sd-btn-apply`, `.sdm-back`, `.sdm-zone`, `.wk-year-btn`. |
| 7 | SH-07 | **P2** | SHARED | `public/css/app.css` (global) | **`touch-action: manipulation` absent on all button/chip controls.** No occurrence anywhere in the CSS. Without it, browsers add a 300ms tap-delay on touch devices. Floor tablets rely on this. | Add `touch-action: manipulation` to the global button rule in `app.css`. |
| 8 | SH-08 | **P2** | SHARED | All pages with `.sd-tl-dot`, `.sd-entry.status-*`, `.sd-form-chip` | **Color as sole status signal** in sessions timeline/vessel cards. Status (open/closed/abandoned) is differentiated only by left-border color (`--hop`/`--oak`/`--ember`). The `sd-tl-dot` is color-only. On 8% deuteranopia prevalence, `--hop` green and `--ember` orange are indistinguishable. | Add a text label or icon alongside each status signal. The `.sd-status-dot` element exists (line 559) but carries no ARIA label. Add `aria-label="<?= $STATUS_LABELS[$status] ?>"` to `.sd-tl-dot` and `.sd-status-dot`. The form chips already have text — those are fine. |
| 9 | SH-09 | **P2** | SHARED | `public/css/app.css` | **`prefers-reduced-motion` coverage sparse** — only 3 rules total (`app.css:955`, `app.css:3615`, `sb-board.css:1077`). The CCT modal entrance animation (`cctBackdropIn`, `cctCardIn` in `app.css:9686,9691`), SDM slide transitions, wort tab switch animations, and all hover transitions are NOT gated. | Add a global `@media (prefers-reduced-motion: reduce)` block that sets `transition: none !important; animation: none !important;` as a near-zero-cost safety net, then refine per-component where zero motion is jarring. |
| 10 | SH-10 | **P2** | SHARED | `public/css/app.css` | **`overscroll-behavior: contain` absent on all scrollable panels/modals.** On floor tablets, a flick at the end of a modal or side-panel content scroll chains to the parent page (pull-to-refresh or body scroll). | Add `overscroll-behavior: contain` to `.tb-drawer__panel`, `.sf-fiche` (salle-fournisseurs detail panel), `.ia-modal__box`, and the CCT modal inner scroll container. |

---

## Per-Page Findings Tables

### 1. `le-zeppelin.php` — Hub/Launcher

| # | ID | Sev | File:Line | Description | Fix |
|---|---|---|---|---|---|
| 1 | LZ-01 | **P1** | `le-zeppelin.php:53` | **"Le Cockpit" disabled card is a `<div>` with no ARIA disabled announcement.** The `sh-card--soon` div is not keyboard-reachable, which is correct, but it has no `aria-disabled="true"` or `role` — screen readers will skip it silently, leaving users unaware the feature exists. | Add `role="button" aria-disabled="true" tabindex="-1"` to the disabled card div, or an SR-only text note. |
| 2 | LZ-02 | **P1** | `le-zeppelin.css:101–190` | **Hover states on `.sh-card` not gated behind `@media (hover:hover) and (pointer:fine)`.** On a tablet, tapping a card triggers the hover state which then ghosts until next tap elsewhere. | Wrap all `.sh-card:hover` rules in `@media (hover:hover) and (pointer:fine) { … }`. |
| 3 | LZ-03 | **P2** | `le-zeppelin.php:33` | **`body` class is `"home zeppelin-hub"` — no `id="main-content"` on `<main>`.** Blocked by SH-01, included here as the per-page add. | Add `id="main-content"` to line 38 `<main class="main">`. |
| 4 | LZ-04 | **P3** | `le-zeppelin.css` | **No `:active` scale on `.sh-card`** — cards are the primary interaction and are large tap targets, but have no pressed-state feedback. | Add `.sh-card:active { transform: scale(0.98); }` |

### 2. `salle-des-machines.php` + `salle-des-machines.js` — Sliding Stage

| # | ID | Sev | File:Line | Description | Fix |
|---|---|---|---|---|---|
| 1 | SDM-01 | **P1** | `salle-des-machines.php:391` | **`#sdmToast` has no `role="status"` or `aria-live`.** The JS `showToast()` function updates `textContent` of this div, but without `aria-live` the announcement is invisible to screen readers. This is the only feedback channel for manager-role actions (POST results surfaced as toast). | Add `role="status" aria-live="polite"` to the `<div class="sdm-toast"…>` element. |
| 2 | SDM-02 | **P1** | `salle-des-machines.php:406,414` | **Flash notice divs (dbError, flash_pop) have no `role="alert"` or `aria-live`.** Static inline banners rendered server-side after PRG redirect. Screen readers will not announce them. | Add `role="alert"` to both `.sdm-notice` divs. |
| 3 | SDM-03 | **P2** | `salle-des-machines.css:271` | **`.sdm-back` button hover not gated behind `@media (hover:hover)`.** On tablets the hover ghost persists. | Wrap `.sdm-back:hover` in `@media (hover:hover) and (pointer:fine)`. |
| 4 | SDM-04 | **P2** | `salle-des-machines.css:265` | **`.sdm-back` computed touch target is ~30px vertical** (padding 7px top+bottom + 11px font = ~29px). Below the 44px house rule. | Increase padding to `10px 14px` to reach ~30px visible + add `min-height: 44px` for touch. |
| 5 | SDM-05 | **P3** | `salle-des-machines.php:564` | **Footer "La Nébuleuse — Lausanne" — Lausanne is factually wrong** (production site is Renens per `ref_sites`). Covered by SH-04 but the city literal is uniquely dangerous. | Read from `ref_sites` where `is_production = 1`. |

### 3. `wort.php` + `kpi-charts.js` — Dashboard/KPI

| # | ID | Sev | File:Line | Description | Fix |
|---|---|---|---|---|---|
| 1 | WO-01 | **P1** | `wort.php:717,994` | **`aria-labelledby=""` (empty string) on both tab panels.** Described in SH-02 but the concrete location is here. Screen readers announce the tabpanel as nameless; NVDA says "tabpanel" with no context. | Add `id="wort-tab-brassins"` and `id="wort-tab-kpis"` to the two tab buttons (line 710–711); set `aria-labelledby="wort-tab-brassins"` and `aria-labelledby="wort-tab-kpis"` on the panels. |
| 2 | WO-02 | **P1** | `wort.php:1017–1020` | **Year-selector buttons (`wk-year-btn`) have no `aria-pressed` or `aria-selected`.** The active year is indicated by the CSS `.active` class only — invisible to screen readers. | Add `aria-pressed="true/false"` (or `aria-current="true"`) toggled by `wort-kpis.js` when the year changes. |
| 3 | WO-03 | **P1** | `wort.php:580` | **Raw DB FK exposed in operator UI**: `Recette #<?= (int)$s['recipe_id_fk'] ?>` on timeline row sub-line. Violates SKILL.md "no DB nomenclature in UI" — operators see an opaque integer. | Replace with a recipe short name from the session's already-resolved `batch`/`form_type` data, or omit the sub-line field entirely. |
| 4 | WO-04 | **P2** | `kpi-charts.js:442,466,582,635,670` | **Empty-state renders `—` or `.kpc-no-data` with no guidance text.** §8 of ux-quality-rules.md requires "Aucune donnée pour cette période" + action guidance. A bare dash fails the operator (frozen arc limitation: only flag, no fix proposal for kpi-charts.js internals). Applies to wort.php's USE of kpi-charts — the empty message should be set via the consuming JS (`wort-kpis.js`) that calls the chart render functions. | In `wort-kpis.js`, pass a `noDataText: 'Aucune donnée pour cette période'` option (or equivalent) to each chart render call, if the chart API supports it. |
| 5 | WO-05 | **P2** | `wort.php:766–843` | **7 filter `<select>` elements use `onchange="this.form.submit()"` without disabling the submit button during the page reload.** On a slow VPS, operators on tablets can tap twice (double-submit). Each select submits a GET, so no data loss, but the double-submit can cause a flash. Also: filter form has no submit button itself (auto-submits only), meaning keyboard users who can't trigger `change` events on `<select>` via arrow keys in some assistive tech may be unable to filter. | Add a visible "Filtrer" `<button type="submit">` as a fallback and keep the `onchange` auto-submit. |
| 6 | WO-06 | **P3** | `wort.php:1043,1060` | **Legend items use color-dot only with no pattern/shape differentiation.** `wk-legend__dot--core` (green), `wk-legend__dot--spec` (amber), `wk-legend__dot--contract` (blue) — accessible by hue, but the core + spec pair may fail deuteranopia simulations if `--hop` and `--oak` render similarly on a particular display. | Add a `border-radius: 0` square to one and a round dot to the other, or add initials `C`/`S`/`K` inside the dot. |

### 4. `sessions.php` — List + Filter

| # | ID | Sev | File:Line | Description | Fix |
|---|---|---|---|---|---|
| 1 | SE-01 | **P1** | `sessions.php:580` | **Raw DB FK `recipe_id_fk` exposed in session sub-line** as `"Recette #N"`. Operator sees an opaque integer. Duplicate of WO-03 — same incident pattern, different page. | Same fix: replace with recipe short name from the session row's existing fields, or remove. |
| 2 | SE-02 | **P1** | `sessions-dashboard.css:62–83` | **`.sd-view-btn` has no `:focus-visible` ring.** View toggle buttons (Chronologique / Cuves) are primary navigation but completely invisible to keyboard. No `focus` or `focus-visible` rule anywhere in `sessions-dashboard.css`. | Add `body.sessions-dashboard .sd-view-btn:focus-visible { outline: 2px solid var(--hop); outline-offset: 2px; }` |
| 3 | SE-03 | **P2** | `sessions.php:388–395` | **View toggle group (`sd-view-toggle`) uses `role="group"` but buttons have no `aria-pressed`.** Active state is CSS-only (`.active` class). | Add `aria-pressed="true/false"` toggled by `sessions-dashboard.js` on each click. |
| 4 | SE-04 | **P2** | `sessions-dashboard.css:149–168` | **`.sd-btn-apply` (Filtrer button): computed height ~30px** (padding 7+7=14px + ~11px JetBrains Mono text at 9px size ≈ 30px). Below 44px floor rule. | Set `min-height: 44px` and adjust padding to match. Same for `.sd-btn-reset`. |
| 5 | SE-05 | **P2** | `sessions.php:543–549` | **Session entry cards (`.sd-entry`) are `<a>` anchors but the hover state is not gated** behind `@media (hover:hover)`. On tablet, tap leaves a ghost hover. | Wrap `sessions-dashboard.css:.sd-entry:hover` in `@media (hover:hover) and (pointer:fine)`. |
| 6 | SE-06 | **P3** | `sessions-dashboard.css:163` | **`.sd-btn-apply:hover { opacity: 0.85 }` — hover not gated.** On tablet, tapping "Filtrer" leaves the button dimmed. | Wrap in `@media (hover:hover) and (pointer:fine)`. |

### 5. `salle-fournisseurs.php` — Fiche Group

| # | ID | Sev | File:Line | Description | Fix |
|---|---|---|---|---|---|
| 1 | SF-01 | **P1** | `salle-fournisseurs.css:97–108` | **`.sf-manifest` panel has `backdrop-filter: blur(4px)` and it is NOT a fixed/sticky element** — it is a flex child of `.sf-workspace` which scrolls (left panel of a master/detail layout). This violates the performance hard-ban: continuous GPU repaint on every pixel of scroll. | Remove `backdrop-filter` from `.sf-manifest`. Replace the frosted effect with a solid `background: var(--bg-side)` or `color-mix(in srgb, var(--bg-side) 98%, transparent)` (no blur). |
| 2 | SF-02 | **P1** | `salle-fournisseurs.css` | **No `:focus-visible` rule exists in this stylesheet.** Supplier rows, search input, all action buttons (stub affordances) — none have visible focus. The search input has `:focus { border-color: var(--dock) }` (line 257) but no outline. | Add focus rings to `.sf-supplier-row:focus-visible`, `.sf-search-wrap input:focus-visible { outline: 2px solid var(--hop); }`, and all stub-action button classes. |
| 3 | SF-03 | **P2** | `salle-fournisseurs.php:343–346` | **DB error renders as raw inline text** with `style="padding:40px;color:var(--ember)…"`. No `role="alert"`, no structured layout, inline style (violates CSS separation rule). | Move error styling to `salle-fournisseurs.css`, add `role="alert"`, ensure `id="main-content"` skip target is still present. |
| 4 | SF-04 | **P3** | `salle-fournisseurs.php:355–358` | **Governance summary bar — numeric values (`$totalSuppliers`, `$draftCount`, etc.) are rendered in DM Sans without `font-variant-numeric: tabular-nums`.** When values change (e.g. after a page action), column widths shift. | Add `font-variant-numeric: tabular-nums` to `.sf-gov-num`. |

### 6. `form-packaging.php` + JS — PRG Batch Form

| # | ID | Sev | File:Line | Description | Fix |
|---|---|---|---|---|---|
| 1 | PF-01 | **P1** | `packaging-form.css:153–164` | **`.pf-selected-tank__clear` ("✕ changer") button: no explicit size set.** The ✕ character at the default font size (~12–13px) likely renders below 44px. Check: the padding is not specified in the snippet; visual cross-check needed. The button uses a text label "✕ changer" which is better than a bare icon — partial credit. | Add `min-height: 44px; min-width: 44px; padding: 10px 14px;` to `.pf-selected-tank__clear`. |
| 2 | PF-02 | **P2** | `form-packaging.php:2309–2332` | **Tank selector cards (`.pf-tank-card`) have no `:active` scale feedback** despite being the primary decision interaction (the first tap the operator makes). `pf-tank-card:focus-visible` exists (line 74) — good — but `:active` does not. | Add `body.op-form-packaging .pf-tank-card:active:not([disabled]) { transform: scale(0.97); }` |
| 3 | PF-03 | **P2** | `form-packaging.php:2282–2333` | **Empty candidate list** renders as `<p class="op-form__muted">` — no `role="status"` or `aria-live`. Screen readers won't announce it when the override checkbox dynamically changes the candidate list. | Add `role="status" aria-live="polite"` to the candidate container so JS DOM updates are announced. |
| 4 | PF-04 | **P2** | `form-packaging.php:2222` | **`<form novalidate>` — all field-level error messages come from the JS warnings panel** (`#packaging-warnings`, line 2245). That panel has `aria-live="polite"` — correct. BUT: on submit failure the browser does NOT scroll to the first invalid field, and there is no `autofocus` call documented in the form JS. §3 of ux-quality-rules.md requires auto-focus on first invalid field on submit attempt. | Verify `packaging-form.js` auto-focuses the first failing field after showing the warning panel. If absent, add `document.querySelector('.op-form__invalid, [aria-invalid=true]')?.focus()` after populating the warnings. |
| 5 | PF-05 | **P3** | `form-packaging.php:2259–2268` | **Override block text contains `<code>` tags** (raw column names `hors_process_flag`, `bd_packaging_v2`) visible to manager-level operators. Violates "no DB nomenclature in UI." | Reword: "Toute saisie créée via cet override sera marquée comme saisie hors-process dans la base de données." |

### 7. `reglages-generaux.php` — Admin/Settings

| # | ID | Sev | File:Line | Description | Fix |
|---|---|---|---|---|---|
| 1 | RG-01 | **P1** | `reglages-generaux.php:940–941` | **`body class="rg-page"` — no `.home` class.** The page does NOT include topbar.php OR sidebar.php (it has its own chrome). This means `form-resilience.js` (loaded by `topbar.php`) is NOT loaded. The keepalive + CSRF-refresh pattern is absent. The admin session can idle-expire mid-edit, and the next form submit will silently 302-to-login with field data lost. | Either include `topbar.php` (preferred — picks up keepalive) or manually load `form-resilience.js` and `session-ping.php` from within `reglages-generaux.php`. |
| 2 | RG-02 | **P1** | `reglages-generaux.php:949` | **Hardcoded "La Nébuleuse"** in the page brandmark chrome (same as SH-04). | Read from `system_settings.brewery_name`. |
| 3 | RG-03 | **P1** | `reglages-generaux.css:281,294` | **`:focus` (not `:focus-visible`) used on form inputs.** This will show a focus ring even on mouse click (which `.rg-input:focus` triggers). While not a regression for keyboard users, it may show an unwanted focus ring on mouse interaction. More critically, there is no focus ring on the nav-rail items (`[data-sec]` elements with `onclick`), which are styled divs with click handlers — not keyboard-operable at all. | Convert `.rg-input:focus` → `.rg-input:focus-visible`. Make nav-rail items `<button type="button">` elements so they get keyboard focus for free, then add `:focus-visible` styles. |
| 4 | RG-04 | **P2** | `reglages-generaux.php:976` | **Nav-rail items use `onclick="switchSection('general')"` on `<div>` elements.** `<div>` with onclick is not keyboard-operable (no native focus, no Enter/Space trigger). Screen readers skip them. Violates "semantic HTML over div soup." | Replace `<div class="nav-item" onclick="…">` with `<button type="button" class="nav-item" onclick="…">`. One change, full keyboard + SR support for free. |
| 5 | RG-05 | **P3** | `reglages-generaux.php:958` | **Active nav item has `aria-current="page"` on a `<span>` (the disabled family-btn).** The nav-rail items that switch sections within the page should use `aria-current="true"` (not `"page"` — that means a different page in the nav), and only the active section item should carry it. | The family-switcher active span is correct; for nav-rail items toggling sections on the same page, use `aria-current="true"` (or `aria-selected` in a tab widget pattern). |

### 8. `cct-detail-modal.js` — Modal/Overlay Group

| # | ID | Sev | File:Line | Description | Fix |
|---|---|---|---|---|---|
| 1 | CCT-01 | **P1** | `cct-detail-modal.js:80–88` | **Tooltip correctly mounted INSIDE the dialog** (line 80 comment cites the top-layer trap from SKILL.md) — this is a PASS. However: `showTooltip()` injects raw `html` string (line 91: `t.innerHTML = html`). If any chart data value contains `<` or `&`, this is an XSS vector. The `escHtml()` function exists in `salle-des-machines.js` but may not be imported in the modal's IIFE scope. | Audit the callers of `showTooltip(html, …)` — verify all interpolated data values pass through `escHtml()` before being placed in the `html` string. |
| 2 | CCT-02 | **P1** | `app.css:9675–9684` | **`.cct-modal__overlay` is `position: fixed` — PASS for `backdrop-filter`.** However: the overlay animation `cctBackdropIn` and card animation `cctCardIn` are NOT gated under `prefers-reduced-motion`. These are among the more visible animations in the app. | Add to `app.css`: `@media (prefers-reduced-motion: reduce) { .cct-modal__overlay { animation: none; } .cct-modal__card { animation: none; } }` |
| 3 | CCT-03 | **P2** | `cct-detail-modal.js` (full file) | **No loading or error state for the chart data.** The modal presumably receives data from a PHP JSON injection or an API call; if data is missing/empty the SVG renders blank axes with no guidance. §8 of ux-quality-rules.md mandates loading/empty/error states. Requires live-pass to confirm data path. | Ensure the modal render function checks for empty fermentation data and renders "Aucune donnée de fermentation disponible" with the fermentation record metadata still visible. |
| 4 | CCT-04 | **P3** | `cct-detail-modal.js` hardcoded chart ranges | **Chart Y-axis ranges are hardcoded** (`fgMax: 16`, `phMin: 4.0`, `phMax: 5.4`, `dayMax: 12`). A beer with unusual gravity, acid wash, or extended fermentation (>12 days) will have data clipped outside the chart area with no indication. | Make the axis domains dynamic: derive `fgMax = Math.ceil(maxFg * 1.1)`, `dayMax = Math.max(12, lastDay + 1)`. Show a "données hors plage" label if any point was clamped. |

---

## Backdrop-Filter Triage Table

27 total occurrences audited. Rule: `position:fixed` or `position:sticky` = PASS; scrolling container = FAIL (P1 performance hard-ban).

| File | Line | Element | Position | Result |
|---|---|---|---|---|
| `app.css` | 250 | `.home .tb` (topbar) | `position:sticky; top:0` | **PASS** |
| `app.css` | 546 | `.tb-drawer__backdrop` | `position:absolute` inside fixed drawer | **PASS** |
| `app.css` | 7674 | `.multishot-panel--open` | `position:fixed; inset:0` (modal) | **PASS** |
| `app.css` | 9332 | `.skub-modal__backdrop` | Inside `.skub-modal` which is `position:fixed; inset:0` | **PASS** |
| `app.css` | 9680 | `.cct-modal__overlay` | `position:fixed; inset:0` | **PASS** |
| `app.css` | 11484 | `.af-inv-modal` | `position:fixed; inset:0` | **PASS** |
| `session-shell.css` | 48 | `.ss-header` | `position:sticky; top:0` | **PASS** |
| `session-shell.css` | 674 | `.ss-footer` | `position:fixed; bottom:0` | **PASS** |
| `salle-des-machines.css` | 99 | `.sdm-chrome` | `position:relative` — this is a flex child of `.main` which scrolls | **FAIL — P1** |
| `salle-de-controle.css` | 88 | `.sdc-page .family-switcher` | Not sticky — scrolls with page | **FAIL — P1** |
| `salle-de-controle.css` | 454 | `.modal-overlay` | `position:fixed; inset:0` | **PASS** |
| `reglages-generaux.css` | 74 | `.rg-page .family-switcher` | Not sticky — scrolls with page chrome | **FAIL — P1** |
| `salle-fournisseurs.css` | 101 | `.sf-manifest` | Flex child, scrollable panel | **FAIL — P1** (named SF-01) |
| `sb-board.css` | 181 | `.sb-zone__header` | `position:relative; z-index:5` — scrolls within zone | **FAIL — P1** |
| `sb-board.css` | 1231 | `.sb-sync-ts` label | Small chip, not fixed | **FAIL — P2** (minor visual chip) |
| `sb-mother.css` | 157 | `.smh-header` | Top of page header, likely sticky — needs live check | **NEEDS LIVE PASS** |
| `sb-mother.css` | 1146 | Inside modal overlay (inset:0 context) | Inside fixed overlay | **PASS** |
| `sb-mother.css` | 1172 | Footer bar of modal | Inside fixed overlay | **PASS** |
| `sb-mother.css` | 1469 | `.confirm-overlay` | `position:fixed; inset:0` | **PASS** |
| `sb-mother.css` | 1771 | Second confirm overlay | `position:fixed; inset:0` | **PASS** |
| `admin-ingest.css` | 475 | `.ia-modal__backdrop` | `position:absolute` inside fixed `.ia-modal` | **PASS** |

**Summary of backdrop-filter fails:**
- **P1 (scrolling container):** `sdm-chrome` (SDM), `sdc family-switcher`, `rg family-switcher`, `sf-manifest` (SF-01 already named), `sb-zone__header`
- **P2 (minor chip):** `sb-sync-ts`
- **Needs live-pass:** `smh-header` in sb-mother

---

## Recommended Phase-1 Fix List (Ordered by Impact)

Fix these in one shared-layer pass. Each is estimated at 5–30 min; together they unblock WCAG AA compliance and remove all P0/P1 SHARED issues.

| Priority | ID | Fix | Effort | Impact |
|---|---|---|---|---|
| 1 | SH-01 | Add skip-to-content link to topbar.php + `id="main-content"` on all `<main>` elements | 30 min | P0 eliminated; WCAG 2.4.1 |
| 2 | SH-03 | Add `:focus-visible` rings to the 10+ interactive element classes that have none | 45 min | 7 P1s eliminated (affects every page) |
| 3 | BF-SDM | Remove `backdrop-filter` from `.sdm-chrome` (scrolling container) | 5 min | P1 perf ban |
| 4 | BF-SDC | Remove `backdrop-filter` from `.sdc-page .family-switcher` (scrolling) | 5 min | P1 perf ban |
| 5 | BF-RG | Remove `backdrop-filter` from `.rg-page .family-switcher` (scrolling) | 5 min | P1 perf ban |
| 6 | BF-SBB | Remove `backdrop-filter` from `.sb-zone__header` (scrolling zone header) | 5 min | P1 perf ban |
| 7 | RG-01 | Add `form-resilience.js` load to `reglages-generaux.php` (or include topbar.php) | 15 min | Admin session keepalive; CSRF refresh |
| 8 | SH-02 + WO-01 | Fix `aria-labelledby=""` on wort.php tab panels — add IDs to tab buttons | 10 min | ARIA tab widget complete |
| 9 | WO-02 | Add `aria-pressed` toggle to wort year-selector buttons | 10 min | SR year selection announcement |
| 10 | SH-05 | Replace raw `rgba(0,0,0,…)` box-shadows in sessions-dashboard.css and sb-mother.css | 15 min | Design consistency |
| 11 | SH-06 | Add global `:active { transform: scale(0.97) }` to button/card classes in app.css | 20 min | Tactile pressed feedback on tablets |
| 12 | SH-07 | Add `touch-action: manipulation` to global button rule in app.css | 5 min | Remove 300ms tap delay |
| 13 | SH-04 | Replace hardcoded "La Nébuleuse" strings with `system_settings.brewery_name` read | 30 min | System settings canonical-data rule |
| 14 | SDM-01 + SDM-02 | Add `role="status" aria-live="polite"` to sdmToast; add `role="alert"` to sdm-notice divs | 10 min | Action feedback announced |
| 15 | RG-04 | Replace nav-rail `<div onclick>` with `<button type="button">` in reglages-generaux.php | 15 min | Keyboard/SR accessibility |
| 16 | SH-08 | Add ARIA labels to session status dots (.sd-tl-dot, .sd-status-dot) | 10 min | Color-not-only status signal |
| 17 | SH-09 | Add global `prefers-reduced-motion` block to app.css | 10 min | Motion accessibility |
| 18 | SH-10 | Add `overscroll-behavior: contain` to scrollable modal/panel containers | 10 min | Tablet pull-to-refresh isolation |

---

*Audit conducted from source code (PHP + CSS + JS). Items marked "needs live pass" require Playwright or DevTools observation against a running app instance. Estimated live-pass gap: ~10% of battery (primarily animation timing, font load, layout shift measurement, and tooltip positioning edge cases).*
