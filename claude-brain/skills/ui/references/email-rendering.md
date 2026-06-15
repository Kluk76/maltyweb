# Email rendering (HTML emails) — maltyweb

The ONE place the house "CSS lives in /public/css, never inline" rule is **correctly inverted**.
Email clients strip `<head>`/`<style>` inconsistently (Gmail) and ignore much CSS (Outlook's
Word engine), so an email body must be **inline-CSS, table-layout, no-JS, no-SVG** HTML.
A reviewer must NOT "fix" inline CSS in an email template — leave a top-of-file comment saying so.

First proven build: the Mon-Tableau **KPI recap email** (2026-06-11). Reference implementation:
- `app/kpi-email-render.php` — the renderer module (see "Projection-module pattern" below)
- `scripts/send-kpi-recap.php` — generator + sender (header/greeting/footer chrome + the tile loop)
- `app/services/mailer.php` → `send_mail($to,$subject,$htmlBody,$textBody=null)` — Postfix → `smtp.ionos.co.uk:587` as `noreply@maltytask.ch`; MIME multipart, has an unused plain-text slot worth filling for deliverability.
- Live cron: `/etc/cron.d/maltytask-kpi-recap` (hourly, user `maltytask`, `--apply`, self-gates on `next_due_at`). **A deploy ships to real inboxes on the next tick — validate before deploying** (dry-run dump + a single `--apply --user <id>` to your own inbox).

## Email-safe constraints (the governing spec — give these verbatim to any email build)

- **Inline CSS only.** Every style on a `style=""` attribute. No `<link>`, no relied-upon `<style>` block.
- **Table-based layout** only — no flexbox/grid. Fixed inner width ~520px; inner cells `width="100%"`. Single column (mobile = desktop).
- **No JavaScript.**
- **No SVG** — Outlook-desktop (Word engine) drops it. So **charts/bars must be pure HTML/CSS**:
  a bar = a `<table cellpadding=0 cellspacing=0>` with a filled `<td>` (`bgcolor="#9eb060"` **and**
  `style="background:#9eb060;height:8px;width:<pct>%"`) + an empty remainder `<td bgcolor="#e8dcc6">`.
  Set BOTH the `bgcolor` attribute AND the CSS `background` — Outlook and dark-mode auto-invert read the attr.
  `pct = round(value / scaleMax * 100)`. Skip server-side PNG charts entirely (needs a render pipeline +
  image hosting + dark-mode inversion headaches — not worth it).
- **Dark-mode safe:** put `bgcolor` on every colored cell; never rely on text color alone for meaning
  (a +/− sign must carry the delta, not just green/red).
- **Escape everything:** `htmlspecialchars($x, ENT_QUOTES, 'UTF-8')` on every interpolated value
  (beer labels, batch, sku, section labels…).
- **FR formatting:** `number_format($v, $dp, ',', ' ')` (comma decimal, space thousands). Null → "—" (#9a8f82).
  Loss/precision: small loss metrics deserve 2 decimals (e.g. 0,05 % not 0,1 %) — target by intent
  (label =~ /perte/i → 2 dp), not by magnitude.
- **Palette (kraft / aged-oak, email build):** page #ede4d3 · card #faf5ec / #f9f3e8 · border #c8bba8 / #d8cbb8 ·
  header bg #2c2414 · ink #2c2414 · mute #9a8f82 · hop-green bars #9eb060 · empty-bar #e8dcc6 ·
  tints green #5a8a5a / amber #a07020 / red #a04040.

## Projection-module pattern (how the email stays in lockstep with the on-screen UI)

The browser tiles render via `public/js/kpi-charts.js` (JS/SVG) — that code **cannot run in an email**.
So you cannot literally reuse the renderer. What both sides MUST share is the **`kpi_dispatch()` result
contract** (`value`/`delta`/`series`/`breakdown[]`/`meta.sections[]`/`viz_type`) — they share the
*computation*, not the rendering code.

The clean model = a **second, email-safe rendering of the same vocabulary**:
`app/kpi-email-render.php` → `kpi_email_render_viz(array $tracker, array $result): string`, switching on
`viz_type`, emitting inline-CSS table HTML per viz. When a new `viz_type` or richer `breakdown[]` lands
on screen, you add ONE case here and the email keeps pace — "the email accommodates any dashboard build."

**DIVERGENCE GUARD (non-negotiable):** the email-render module is a PURE PROJECTION.
- It takes NO `$pdo`. It must contain no `$pdo` / `->prepare` / `->query` / `SELECT` / `maltytask_pdo()`
  (grep to prove it before declaring done).
- It NEVER recomputes/re-derives any number. It renders what the handler returned, full stop.
- If the email needs a value the handler didn't return, the fix is to add it to the handler's `meta` —
  NEVER recompute email-side (a second computation of a fact = the corruption pattern that keeps the
  tiles honest). Same posture as "no parallel store of the same fact."
- The generator (`send-kpi-recap.php`) becomes a thin shell: loop selected trackers → call the module →
  wrap in the page chrome. Keep header/greeting/footer/send-dry-run untouched.

## The "recap" viz — Sectioned-digest layout (the shipped reference)

For a `recap` result (e.g. `kpi_wort_daily_recap`, `kpi_pkg_daily_recap`): domain title + `meta.period_label`;
a **metric strip** from `meta.sections[]` (one `<td>` chip per section: small-caps label over mono value+unit,
tint-colored when `tint`≠neutral); then the **grouped granular breakdown** —
- packaging: group `breakdown[]` by `meta.run_type` → Fût (keg) / Cuve (cuv) / Bouteille (bot) / Canette (can);
- production: group by `meta.type` → Brassins (brew) / Transferts (rack) / Dry-hop / Cold-crash;
- each row = beer label + pure-CSS bar (scaled to the **domain-wide max** so groups are comparable) +
  value+unit + a small context suffix (wort: "lot {batch} · {classification}"; packaging:
  "lot {batch}" + "· → {reach}% obj" when objective set + "· {loss}% perte" when loss > 0).
Default (non-recap) case = the plain scalar card (label + big value+unit + delta + a tiny series bar).

## Per-viz email treatments (full vocabulary — shipped 2026-06-12, maltyweb `36d0d70`)

`kpi_email_render_viz($tracker,$result)` switches on `viz_type`; each is a pure-HTML/CSS projection
of the same `kpi_dispatch` result the on-screen `kpi-charts.js` renderer consumes. The proven set:

- **number** → scalar card: big mono value + unit + delta line.
- **sparkline** / **line** → row of 12 month mini-bars. **Outlook baseline:** do NOT use
  `vertical-align:bottom` (Word engine ignores it → bars top-align). Use a fixed-height outer `<td>`
  + `padding-top:(maxBarPx − barPx)px` so bars share a baseline everywhere. sparkline draws a paired
  ghost (`meta.prev_series`, #c9d6a3) behind current (#9eb060); line is single-series.
- **bar** → horizontal CSS bars, one row per `series` point (label = period/xLabel), scaled to series max.
- **grouped_bar** → per `breakdown` row: solid current + ghost (`meta.prior_year`) bars (scaled to
  domain max) + a ▲/▼/`nouveau` delta chip. (Shared row primitive with recap.)
- **stacked_bar** → ONE full-width segmented proportion bar (colored `<td>` per `breakdown` slice) +
  legend table (swatch · label · value · %).
- **donut** → **ranked legend with bold % emphasis, NO bar** — deliberately differentiated from
  stacked_bar so "composition by category" reads differently from the COP stacked bar. Sorted desc + Total.
- **flag** → a colored chip: amber #a07020 / red #a04040 when `value`>0 (alert), green #5a8a5a when 0/ok.
- **table** → 2-col HTML table (Élément | unit), dark header row, cap 12 + "+N lignes…", optional Total row.
  Reads `breakdown[]` or falls back to `series[]`.
- **waterfall** → signed-contribution rows from a center baseline: + grows RIGHT (hop-green), − grows
  LEFT (ember #a07020), signed value, + an honest caption "(décomposition — cumul non figuré)" since a
  true running-total needs SVG. Degrade-honestly rather than fake the cumulative.
- **recap** → the Sectioned-digest (see above); degrades to the scalar card when `breakdown` is empty.

Multi-segment palette: #9eb060 · #a07020 · #6e8b4e · #c9b352 · #b08d57 · #5a8a5a. Cap every list ~12 with
"+N autres/lignes" (no silent truncation). Verify each against REAL handler output (a /tmp throwaway harness
that dispatches one live tracker per `viz_type` → renders → screenshots), not mock data — handler field
shapes drift from assumptions.

## Verify (email builds — webapp-testing does NOT apply to email)

- `php -l` the module + generator.
- DRY-RUN dump to a file in /tmp (not the repo); load that `file://` HTML in Playwright at ~560px and screenshot — that's the review artifact (no auth needed, it's a local file).
- Grep the module for the divergence guard ($pdo/SELECT/etc → must be empty).
- Before deploy of a LIVE-cron email: one `--apply --user <id>` to your own inbox; confirm recipient scoping
  (the per-user flag must not blast everyone). Resetting `next_due_at=NOW()` lets a manual `--apply` fire.
