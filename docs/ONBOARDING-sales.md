# ONBOARDING — Sales / Marketing build track

**Audience:** the Claude Code instance assisting **Louis Maechler** (Head of Sales & Marketing, La Nébuleuse). 
**Purpose:** make you instantly equivalent to the primary build environment, and **keep every build inside scope.** This file is your *scope contract*. Read it in full before your first build, and re-read §2–§4 before any build that touches data.

> **The one rule that subsumes the rest:** the moment work touches **maltyweb**, you **consult the `maltyweb-pm` agent FIRST and keep it in the loop throughout**, and you keep it updated after. PM is the single keeper of architecture, sequencing, the derivation tree, and build-state. If this file and PM ever disagree, **PM wins** (this file can go stale; PM is maintained every session). When in doubt, you ask PM or you ask Kouros — you do **not** improvise.

---

## 0. The shape of the world

| | maltyweb | maltytask |
|---|---|---|
| **What** | The PHP ERP web app (operators, managers, sales). Live at **app.maltytask.ch** | The Node/TypeScript data pipeline (ingest, COGS/COP, parsers) + the master `CLAUDE.md` |
| **Where** | VPS `ubuntu@83.228.215.243` (`ssh maltyweb`), code at `/var/www/maltytask` | local repo; runs against Google Sheets (legacy) + MySQL |
| **Your repo** | `Kluk76/maltyweb` — **this is your working tree** | `Kluk76/maltytask` — clone it **read-only for reference** (architecture, COGS rules) |

**Canonical data store = MySQL `maltytask`** (since 2026-05-12). Google Sheets ("BSF") are **read-only legacy views**. Every reference-data write goes to MySQL first.

Louis's account in the app: role **`manager`**, scope **`logistics`**, access preset **`sales_manager`** (id 9). That preset grants him: Financier, Paramètres, Approvisionnement, Expéditions, Tap&Shop, Warehouse, Bilan MP, home. No production pages, no admin pages. **Your build scope mirrors that footprint.**

---

## 1. Your mission

You build and extend the **sales / marketing / direct-sales / logistics** surface of maltyweb:
- **Tap&Shop** (`public/modules/tap-shop.php`) — direct sales / e-shop packs
- **Expéditions / Fulfilment** (`public/modules/expeditions.php`) — orders, outbound, fulfilment
- **Orders & customers** — `ord_orders`, `ref_customers` (incl. `trade_channel`)
- **Sales dashboards / KPIs / reports** that *read* margin, COGS, channel mix
- **Financier** — read/consume only (you do not author the fiscal engine)

You make these surfaces better, faster, and more useful for the sales team. You do **not** reach upstream into how beer is made, costed, or master-data'd.

---

## 2. SCOPE — the allow-list, the deny-list, the STOP-triggers

### ✅ You MAY build (allow-list)
- New/updated **pages, modules, modals, dashboards** under `public/modules/` for sales/logistics.
- Reads and writes to the **sales/logistics domain tables**: `ord_orders`, `ord_*`, `ref_customers`, and any new `ord_*` / sales-facing table **that PM has approved** against the derivation tree.
- **Reading** cost/margin/COGS data from the canonical feeds (see §3) to display margin, channel profitability, price lists, etc.
- **UI** work to the house design system (§6), CSS in `public/css/`, JS in `public/js/`.
- **Migrations** in `db/migrations/` for sales-domain schema — *after* PM blesses the model and you've brokered the migration number (§5).

### ⛔ You MUST NOT touch (deny-list)
These are **out of scope**. Building here forks the architecture and silently corrupts COGS/tax. If a sales feature seems to need any of these, that is a **STOP** (see below), not a green light.

1. **Production / brewing surfaces & data** — Le Zeppelin, Wort, Fermentation, Packaging, QA/QC, racking, tank simulation; tables `bd_*`, `op_sessions`, `ref_recipes`, `ref_recipe_*`. Sales has no production mandate.
2. **The COGS / COP cost engine** — never *compute* or *re-derive* cost, margin, or beer-tax. You only **read** the canonical outputs. A sales-side margin calc that recomputes cost from prices or BOM is a **divergent COGS lane** — strictly forbidden.
3. **Master-data root** — "Salle des Machines" / commissioning, `ref_mi` (Master Ingredients), `ref_mi_*`, GL account routing, `ref_sku_bom`, `ref_packaging_format_*`, `schema_meta`. These are the trunk of the derivation tree; only the master-data custodian edits them.
4. **The admin trio** — `charges-bc.php` (bookkeeper GL upload), `admin/ingest.php`, `admin/db-browser.php`. These are `require_admin()`-gated; Louis cannot reach them and you must not build into them.
5. **The ingest / OCR / invoice-parser pipeline** — `scripts/ingest-documents.js`, `lib/invoice-parsers/*`, `lib/ocr-core.js`, anything in the maltytask ingest chain.
6. **BSF Google Sheets writes** — the legacy tabs are read-only; never write them.

### 🛑 STOP and ask (PM, then Kouros) — do not proceed on your own
- A sales feature appears to need a **new fact that an existing table already owns** (a second "customer", "order", "price", or "cost" store). → It's almost always a read against the existing canonical table, not a new table.
- You're about to **classify a customer's channel** (off-trade vs on-trade, direct vs distributor) by **name-matching**. → Forbidden. Channel lives on `ref_customers.trade_channel` (manual, becoming automatic). `Baldinger ≠ Aldi`.
- You're about to **assign a SKU↔BOM mapping, a category, a GL, or any COGS/tax-impacting correlation** and there is *any* doubt. → Read the raw source and surface it to Kouros. **Never infer from prices or "what should be there."** A wrong mapping propagates silently through COGS and takes hours to unpick.
- The change touches anything on the **deny-list**, or you can't tell which canonical table owns a fact. → Ask PM.
- You're tempted to **recompute** a number that a feed already provides. → Read the feed.

---

## 3. The derivation tree (sacrosanct) — and where sales sits

There is **one** source-of-original-truth chain. Every computed number derives from it, in this order:

```
commissioning / capacités  →  containers & formats unlock  →  recipe format-activation + bindings
   →  SKU (ref_skus)  →  BOM (ref_sku_bom)  →  COGS / COP  →  invoiced sales (inv_sales_bc)
```

**Sales lives at the DOWNSTREAM end.** You **consume** SKU, price, cost, and sold-quantity facts; you **never mutate** anything upstream of `inv_sales_bc` / `ord_orders`.

**Canonical sources you READ (never recompute):**
| Fact | Read from | Never |
|---|---|---|
| Per-SKU cost / BOM | `ref_sku_bom`, `v_mi_cost` (and the `sku-cost-detail.php` query — reuse it verbatim) | recompute cost from prices/BOM |
| COGS / margin (sold-side, fiscal) | `inv_sales_bc`, `interfaces/sales-cogs-data.json` (maltytask) | build a parallel margin calc |
| Orders | `ord_orders` (+ `ord_*`) | a second order store |
| Customers & channel | `ref_customers` (`.trade_channel`) | name-match the channel |
| SKU catalogue / formats | `ref_skus` (`.format`, `.format_id`) | invent format strings |

**COGS golden rule:** COGS = finished goods sold, valued at the **previous month's** COP. If a sales view needs cost, it reads the canonical per-SKU cost — it does not derive it.

**Offerable packaging formats** (if you ever build a "new product / new pack" flow) are gated by commissioned equipment (`ref_process_machines × ref_filler_containers × ref_packaging_formats`). You derive offerable formats from that gate — you never free-text a format list.

---

## 4. The five parallel-developer guardrails (PM-mandated)

Because there are now **two developers + two Claudes** on the same VPS and repos, these are hard rules:

1. **PM reviews every sales-side schema change** against the derivation tree before it lands — same gate as any schema change. No new sales/CRM table ships without PM sign-off.
2. **Sales reads cost; sales never computes cost.** (See §3.)
3. **Channel/segment attribution lives on the customer record, never name-matched** at query time.
4. **Separate git worktrees + surgical deploys.** You and the primary dev share one VPS and one deploy target. `bin/deploy.sh --apply` pushes the *working tree*, not committed HEAD — so a blanket deploy can ship the other person's uncommitted work. **Deploy surgically** (scp the specific files you changed, then diff-verify), never blanket-deploy a tree that may carry someone else's changes. Use `git add <specific files>`, **never `git add -A`**.
5. **Broker migration numbers.** The VPS migration sequence sometimes leads git. Immediately before allocating a migration number, re-run `php scripts/migrate.php --status` **and** `ls db/migrations/` on the VPS, and confirm the number with Kouros/PM. Never self-INSERT into `schema_migrations` — `migrate.php` owns that.

---

## 5. The build workflow — every single build, no exceptions

1. **Consult `maltyweb-pm` FIRST.** Describe the build; get its ruling on where it fits in the derivation tree, the right sequence, the canonical tables, and the `EQUIP:` skill line. Re-consult during the build if the shape changes. **Update PM after the build lands** (migration number, what shipped, decisions).
2. **Sonnet codes, Opus orchestrates.** The orchestrating model plans/scopes/verifies/commits; delegate the actual edits to Sonnet subagents with self-contained briefs (task + constraints + verification + "report back"). Trivial one-liners can stay inline.
3. **Equip the right skills** (PM's `EQUIP:` line):
   - `coder` — general PHP/JS/TS logic, deploy, ingest, MySQL repo writes
   - `sql` — any SELECT/INSERT/UPDATE/DELETE, migrations, query work in PHP modules
   - `ui` — anything about whether/how something **renders** (pages, modals, charts, previews, layout)
   - `webapp-testing` — smoke-test deployed pages (Playwright)
   - `xlsx` — spreadsheet in/out
   - `parser-coder` — only for invoice/document parsers (**not your scope** — listed for completeness)
   - auth work → the `php-security` reference; dataviz → the `dataviz` reference
4. **Plan → PM sanity-check → build → verify → deploy surgically → smoke-test → PM write-back.**
5. **Migration discipline** (when schema changes): MySQL 8 (no `ADD COLUMN IF NOT EXISTS`; idempotency via `schema_migrations`), **no bare `SELECT` in a migration file** (`migrate.php` uses PDO `exec()` and chokes on open result sets — subqueries inside an INSERT are fine), add a `schema_meta` row for any **new table**, FK type must match the referenced PK (INT vs BIGINT), pre-flight the 6 checks, broker the number (§4.5).
6. **Verify, don't speculate.** A prior session's claim is a *claim* — re-probe git (right repo), the deployed file on the VPS, and `migrate.php --status` before reporting status. Schemas/queries match production byte-for-byte or they're wrong.
7. **Commit only when Kouros asks.** End commit messages with the project's `Co-Authored-By` line. Name files explicitly in `git add`.

---

## 6. UI — the house design system (the `ui` skill)

Equip the `ui` skill for any rendering work; it carries the full system. The non-negotiables:
- **Dark aged-oak theme**; `.home`-scoped variations via body classes.
- Fonts: **Fraunces** (display), **DM Sans** (body), **JetBrains Mono** (mono). Palette via CSS tokens — never hardcode hex.
- **CSS lives in `public/css/`, JS in `public/js/` — never inline** in PHP. PHP links them.
- **PHP → `window.JSON` → JS hydration**: PHP renders data into a `window.*` JSON blob (escaped), JS reads it. Escape with the project's helper; `escHtml` for XSS.
- **Cache-bust** static assets with `?v=filemtime(...)`.
- **No DB nomenclature in the UI** — operators/sales see human labels, never raw column/table names.
- **Preview-first** for greenfield visuals (see the `frontend-design` discipline the `ui` skill references).
- Touch targets sized for floor tablets; validate forms with good UX; respect animation budgets.

---

## 7. Skills & agents you have (use them — don't hand-roll)

**Agents** (invoke via the Agent tool):
- **`maltyweb-pm`** — architecture/sequencing/derivation-tree/build-state keeper. **Consult before & during every build; update after.** (Mandatory.)
- **`maltyweb-tour-steward`** — owns the "Visite guidée" first-login tour. Dispatched (by PM's standing duty) whenever a new page surfaces in the topbar. You don't author tour cards yourself.

**Skills** (invoke via the Skill tool): `coder`, `sql`, `ui`, `webapp-testing`, `xlsx`, `memory-hygiene`, `skill-vetting`, `skill-creator`. (`parser-coder` exists but is out of your scope.)

**Governance:** don't install generic third-party skills — mine useful techniques into the bespoke skills instead, and vet anything external with `skill-vetting` first. Only PM authors/edits the `coder`/`sql`/`ui` skills and creates `mw-*` skills.

---

## 8. Directory map & canonical entry points

**maltyweb (your working tree):**
| Path | What |
|---|---|
| `public/modules/` | The operator/sales/manager pages (tap-shop, expeditions, financier, …) |
| `public/admin/` | Admin pages (`require_admin()` — **out of scope**) |
| `public/api/` | AJAX/JSON endpoints |
| `public/css/`, `public/js/` | All styling and client JS |
| `app/` | Bootstrap, `auth.php` (role floor + presets + `manager_can()`), `db.php` (`maltytask_pdo()`), partials (`topbar.php`) |
| `db/migrations/` | Sequential SQL migrations, applied by `scripts/migrate.php` |
| `config/`, `bin/deploy.sh`, `scripts/` | Config, deploy, ops scripts; `scripts/python/` ingest helpers |
| `docs/` | This file + reference docs |

**maltytask (read-only reference):** `CLAUDE.md` (the bible), `lib/` (+ `lib/repos/*.ts` typed repos), `scripts/` (pipeline), `data/`, `docs/` (cogs-cop.md, pricing.md, production-process.md, maltytask-spec.md).

**Authoritative knowledge entry points, by topic:**
- *Anything architectural / "where does this fit" / sequencing* → **ask `maltyweb-pm`** (it holds the live derivation tree + build-state).
- *Project conventions / scope / data boundary* → `maltytask/CLAUDE.md` + **this file**.
- *COGS/COP rules* → `maltytask/docs/cogs-cop.md`; *pricing/WAC* → `maltytask/docs/pricing.md`.
- *How to render something* → the `ui` skill.
- *SQL/migrations* → the `sql` skill.

---

## 9. Operational reference

- **SSH to VPS:** `ssh maltyweb` (alias → `ubuntu@83.228.215.243`); add `ServerAliveInterval 30` / `ExitOnForwardFailure yes` to your SSH config. Reach the VPS over **Tailscale** (tailnet node `maltytask-vps`, `100.125.142.25`).
- **Apply a migration:** `ssh maltyweb 'sudo php /var/www/maltytask/scripts/migrate.php'` (add `--status` to inspect without applying).
- **DB probes (read-only):** `app/db.php` → `maltytask_pdo()`. The DB env (`db.env`) is **www-data-only** — run probes as `sudo -u www-data php -r '...'`.
- **Deploy:** `bin/deploy.sh` — but **surgically** on a shared tree (see §4.4).
- **Secrets:** live outside the repos (`secrets/`, `~/.config/maltytask/db.env`) and are **gitignored**. Never commit, print, or move secrets. Don't put them on the VPS in plaintext beyond their existing locations.
- **EUR→CHF** conversion rate and other system constants live in central config — never hardcode.
- **Dates** are `jj/mm/aaaa` (day-first) system-wide; read format from system settings, don't assume.

---

## 10. First-day checklist (for Louis's Claude)

1. Read `maltytask/CLAUDE.md`, then this file (§2–§4 especially).
2. Confirm you can reach the VPS (`ssh maltyweb`) and the app (app.maltytask.ch).
3. For your first task: **consult `maltyweb-pm`** with the task, get its ruling + `EQUIP:` line, then proceed via the §5 workflow.
4. Before any schema change: `migrate.php --status` + `ls db/migrations/` + broker the number.
5. When unsure whether something is in scope: re-read §2. If still unsure: ask PM, then Kouros. **Default to asking, not improvising.**

---

*This file is the scope contract for the sales build track. It can go stale — `maltyweb-pm` is the live source of truth. Last authored: 2026-06-11.*
