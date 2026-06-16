# maltyweb Testing Reference
# For use with the webapp-testing skill (Python sync_playwright)

## PART A — Playwright Golden Rules

Source: rules mined from testdino-hq/playwright-skill (SKILL.md + core/locators.md + core/authentication.md,
fetched 2026-06-07). lackeyjb/playwright-skill returned 404; throwaway-script rule derived from established
Playwright community practice.

### 1. Never `page.wait_for_timeout()` — use auto-retrying waits

```python
# BAD — arbitrary sleep, fails in variable network conditions
page.wait_for_timeout(3000)

# GOOD — auto-retrying, resilient
expect(page.get_by_role("heading", name="Dashboard")).to_be_visible()
page.wait_for_url("**/dashboard")
page.wait_for_load_state("networkidle")
```

### 2. Web-first assertions — let the locator auto-retry

```python
# BAD — resolves immediately, no retry, races against rendering
assert await page.locator("h1").text_content() == "Welcome"

# GOOD — assertion waits/retries until the locator state is met
expect(page.locator("h1")).to_have_text("Welcome")
```

### 3. Locator priority: role/label first, CSS/XPath last

| Preferred (resilient)                              | Avoid (fragile)                        |
|----------------------------------------------------|----------------------------------------|
| `page.get_by_role("button", name="Enregistrer")`  | `page.locator(".btn-primary")`         |
| `page.get_by_label("Email")`                      | `page.locator("#submit-btn")`          |
| `page.get_by_text("Tableau de bord")`             | `page.locator("div > span:nth-child(2)")` |
| `page.get_by_placeholder("Recherche...")`         | `page.locator("xpath=//div[2]/input")` |

Role-based locators mirror assistive-technology semantics and survive CSS renames and DOM restructures.

### 4. Auth via storage state — log in once, reuse everywhere

```python
from playwright.sync_api import sync_playwright
import json, os

STORAGE_STATE_PATH = "/tmp/maltyweb_auth_state.json"

def save_auth_state(username: str, password: str) -> None:
    """Run once to capture session cookies."""
    with sync_playwright() as p:
        browser = p.chromium.launch(headless=True)
        context = browser.new_context()
        page = context.new_page()
        _login(page, username, password)          # see Part B for CSRF flow
        context.storage_state(path=STORAGE_STATE_PATH)
        browser.close()

def make_authenticated_page(p):
    """Reuse saved session — no re-login overhead."""
    context = p.chromium.launch(headless=True).new_context(
        storage_state=STORAGE_STATE_PATH
    )
    return context.new_page()
```

Never commit the storage-state file — it contains live session tokens.

### 5. Throwaway scripts go to /tmp — never into the skill or project directory

One-off diagnostic/smoke scripts are written to `/tmp/test_<purpose>.py` and
executed there. They are not committed, not placed inside the skill directory,
and not placed inside the project (`/home/kluk/projects/maltyweb/`). The skill's
`scripts/` and `examples/` directories hold only reusable templates.

---

## PART B — maltyweb Specifics

### Target

- URL: `https://app.maltytask.ch`
- Stack: server-rendered PHP on a remote VPS (Ubuntu, nginx)
- `scripts/with_server.py` is for LOCAL servers — do NOT use it for maltyweb.
  Drive the live URL directly; there is nothing to start.

### NETWORK GOTCHA — WSL → app.maltytask.ch TLS handshake (verified 2026-06-07)

Headless Chromium and Node.js CANNOT complete a TLS handshake to the app from
WSL (direct or via the Tailscale IP `100.125.142.25`) — TCP connects, the
handshake stalls. `curl`/`openssl s_client` work fine (different TLS stack).

**Working pipeline (verified — login page 200 via Playwright):**
1. SSH local forward: `ssh -N -L 8443:100.125.142.25:443 maltyweb` (background it)
2. Minimal Node HTTP CONNECT proxy (~30 lines, write to `/tmp/maltyweb_proxy.js`)
   that intercepts CONNECT requests for `app.maltytask.ch:443` and pipes them
   to `127.0.0.1:8443`. Listen on e.g. `127.0.0.1:9876`.
3. Launch Chromium with `--proxy-server=http://127.0.0.1:9876`.

SNI/Host stay `app.maltytask.ch`, so the cert validates normally.

```python
with sync_playwright() as p:
    browser = p.chromium.launch(headless=True)
    page = browser.new_page()
    page.goto("https://app.maltytask.ch")          # live prod, no wrapper
    page.wait_for_load_state("networkidle")
    # ... assertions
    browser.close()
```

### Login flow — CSRF-aware, credential-safe

maltyweb uses session-based auth with a CSRF token embedded in the login form.
Verified field names (form at `/login.php`, confirmed 2026-06-10):

- hidden CSRF input is `name="csrf"` (NOT `csrf_token` — that name does not exist)
- `name="username"` is a **text** input — login is by **username**, not email
- `name="password"`, and `name="remember_me"` (checkbox, value `1`)
- submit button label: **"Connexion"** (re-verified 2026-06-16 — NOT "Se connecter")

```python
import os
from playwright.sync_api import sync_playwright

def login(page, username: str, password: str) -> None:
    """GET login page, parse CSRF token, POST credentials."""
    page.goto("https://app.maltytask.ch/login.php")   # actual path (nginx rewrites / -> /login.php)
    page.wait_for_load_state("networkidle")
    csrf = page.locator("input[name='csrf']").get_attribute("value")  # field is 'csrf'
    page.locator("input[name='username']").fill(username)             # username, not email
    page.locator("input[name='password']").fill(password)
    # Submit is "Connexion". The post-submit navigation can be slow over the
    # SSH-tunnel+proxy — don't let click() block on it; bump timeouts.
    try:
        with page.expect_navigation(timeout=60000):
            page.get_by_role("button", name="Connexion").click(no_wait_after=True)
    except Exception:
        pass
    try:
        page.wait_for_load_state("networkidle", timeout=30000)
    except Exception:
        pass
    # Post-login does NOT land on /dashboard. A fresh/never-classified account
    # redirects to /modules/classification-appareil.php?next=/modules/mon-tableau.php
    # (first-login device-classification interstitial). It does NOT block direct
    # GET navigation — goto() your target module straight after login (returns 200);
    # no need to complete the interstitial for read-only smoke.

# NEVER hardcode credentials. Read from env or prompt the operator.
username = os.environ.get("MALTYWEB_USER") or input("Username: ")
password = os.environ.get("MALTYWEB_PASS") or input("Password: ")
```

Save state after login so subsequent scripts reuse the session (see Part A §4).

### Canonical smoke accounts — viewer bot (default) + manager bot (manager-tier widgets only)

> **CREDENTIAL SAFETY — NON-NEGOTIABLE**
>
> Only the two bot accounts below may be used by webapp-testing agents. **NEVER read,
> set, or restore any real user's `password_hash`** for the purpose of running a smoke
> test. That is a production security incident regardless of intent.
>
> **Default account for ALL smoke tests: `smoketest` (viewer bot, id=16).** Use it
> for pages, viewer-tier widgets, and any test that does not specifically require
> manager-tier KPI rendering.
>
> **Manager bot (`smoketest_mgr`, id=23): ONLY for rendering manager-tier KPI widgets**
> (sales, COGS/financier widgets on Mon-tableau, and the financier page). Strictly
> read-only — NEVER submit forms, NEVER mutate prod data, NEVER seal/approve.
>
> If a page is inaccessible to `smoketest`, that is a **scoping bug** — fix it by adding
> the page_key to the `smoke_viewer` access preset via a new migration
> (`db/migrations/NNN_smoketest_add_page.sql`). Do NOT escalate by touching another
> user's credentials. The "temp-password capture/restore" pattern below is reserved for
> verifying a SPECIFIC real user's render — not for bypassing the smoke accounts.

#### Viewer bot (safe default)

- **`users.id=16`**, username **`smoketest`**, `display_name='Smoke Test (bot)'`, **`role='viewer'`**, `access_preset_id_fk=11` (`smoke_viewer` preset, 12 pages granted, mig 385).
- 8 viewer-tier KPI trackers in `user_kpi_selections` (positions 1–8, mig 388): ids 1,2,3,8,39,49,50,52 — all `min_role='viewer'`, all `data_ready=1`. Mon-tableau renders widgets fully.
- Credentials: **`/home/kluk/projects/maltytask/secrets/maltyweb-smoketest.env`** (maltytask repo), keys `MALTYWEB_USER` / `MALTYWEB_PASS`, mode 600, gitignored.

**Pages accessible to smoketest (via `smoke_viewer` preset, mig 385):**
`mon-tableau`, `sb-board`, `sb-guerre`, `journal-saisies` (viewer-floor) +
`zeppelin`, `qa`, `approvisionnement`, `expeditions`, `warehouse`, `planning`, `rm-comparison` (operator-floor) +
`financier` (manager-floor) — the preset bypasses the role-floor for granted pages.

#### Manager bot (manager-tier KPI rendering only)

- **`users.id=23`**, username **`smoketest_mgr`**, `display_name='Smoke Test Manager (bot)'`, **`role='manager'`**, `manager_scope=NULL`, `access_preset_id_fk=NULL` (role floor grants all non-admin pages), `is_active=1`. Created by mig 388.
- `manager_scope=NULL` design: role=manager rank passes all `min_role <= manager` checks, so ALL manager-tier KPI widgets render. However `manager_can(scope)` always returns `false` when scope is NULL — so scope-gated write controls (COGS "Sceller", finance actions gated by `manager_can('finance')`) are absent. Confirmed by smoke 2026-06-16: seal button not in DOM, 0 visible "Sceller" buttons.
- 8 KPI trackers spanning tiers (positions 1–8, mig 388): ids 1 (viewer/wort), 13 (operator/tanks), 85,86,92,72,168,172 (manager/sales+fg_stock+cogs). The two sales target widgets **units_sold_sku (86)** and **top_skus_volume_revenue (92)** are confirmed rendering with data.
- Credentials: **`/home/kluk/projects/maltytask/secrets/maltyweb-smoketest-manager.env`** (maltytask repo), keys `MALTYWEB_USER` / `MALTYWEB_PASS`, mode 600, gitignored.
- **NEVER read or modify any other user's `password_hash`.** Never set `manager_scope` for this account. Never test write paths as this user.

**Page access is role-floor gated.** `app/auth.php::user_can_access()` order: admin bypass → per-user `user_page_access` override → role-floor (`_role_rank(user) >= _role_rank(page.min_role)` from `ref_pages`) → preset membership → **fallback: NULL preset + role-floor passed ⇒ allow**. A preset grant bypasses the role-floor — that is how smoketest (viewer) reaches operator- and manager-floor pages. The manager bot reaches all non-admin pages via role-floor directly.

**Admin-only pages are HARD-gated in PHP code** (`is_admin()`/`require_admin()` — e.g. `ingest.php`, `charges-bc.php`, `db-browser.php`). These cannot be granted via presets or role. Do not attempt to smoke them as either bot.

If a page added after mig 385 needs smoking, add a new migration:
```sql
-- NNN_smoketest_add_<page>.sql
INSERT IGNORE INTO ref_access_preset_pages (preset_id_fk, page_id_fk)
SELECT p.id, rp.id FROM ref_access_presets p JOIN ref_pages rp
  ON rp.page_key = '<new-page-key>'
WHERE p.preset_key = 'smoke_viewer';
```

### Smoking as a SPECIFIC existing user (role/preset matters) — temp-password capture/restore

The viewer smoke account (id=16) has `access_preset_id_fk=NULL`, so it does NOT reflect a
real user's **preset** (page-grant set) or **manager_scope** (within-page write gating). To
verify what a *particular* user actually sees — e.g. a new CFO/finance_viewer role, a sales
manager, an operator with a custom preset — you must log in AS that user. There is **no
impersonation feature** (`grep -ri impersonate app/ public/` = none; login is hard
`password_verify`). The safe, non-destructive pattern: capture their current `password_hash`,
set a temp one for the smoke, then **restore the exact original hash** so their real password
keeps working. Verified live 2026-06-16 (CFO smoke).

Discipline:
- **DB access needs `www-data`** — `config/db.env` is mode 640, unreadable by `ubuntu`. Run
  `ssh maltyweb 'sudo -u www-data php -r "require \"/var/www/maltytask/app/db.php\"; ... maltytask_pdo() ..."'`.
- **Argon2 hashes contain `$`** — they get mangled by shell/PHP double-quote interpolation.
  **base64-encode the hash** to move it between shells, `base64_decode()` inside PHP. (Same
  trick the password-restore needs.) Never paste a raw `$argon2id$...` string into a
  double-quoted `php -r`.
- **Capture → set temp → smoke → restore**, and the **parent** owns the restore (run it even
  if the smoke fails), exactly like the role-elevation revert above. Confirm `restored hash ===
  original` before declaring done.

```bash
# 1. CAPTURE (prints the original hash — keep it)
ssh maltyweb 'sudo -u www-data php -r "
  require \"/var/www/maltytask/app/db.php\"; \$p=maltytask_pdo();
  \$h=\$p->query(\"SELECT password_hash FROM users WHERE id=22\")->fetchColumn();
  echo \$h;"'

# 2. SET TEMP
ssh maltyweb 'sudo -u www-data php -r "
  require \"/var/www/maltytask/app/db.php\"; \$p=maltytask_pdo();
  \$t=password_hash(\"SmokeTmp!pw\", PASSWORD_ARGON2ID);
  \$p->prepare(\"UPDATE users SET password_hash=? WHERE id=22\")->execute([\$t]);"'

#    ... run the read-only Playwright smoke as that user ...

# 3. RESTORE — base64 the captured hash so $ survives the round-trip
HASH='$argon2id$v=19$m=...'                       # the value from step 1
B64=$(printf '%s' "$HASH" | base64 -w0)
ssh maltyweb "sudo -u www-data php -r '
  require \"/var/www/maltytask/app/db.php\"; \$p=maltytask_pdo();
  \$o=base64_decode(\"'$B64'\");
  \$p->prepare(\"UPDATE users SET password_hash=? WHERE id=22\")->execute([\$o]);
  \$c=\$p->query(\"SELECT password_hash FROM users WHERE id=22\")->fetchColumn();
  echo \$c===\$o?\"restored OK\":\"MISMATCH\";'"
```

Reusing `manager_can()` / a custom gate helper in a PHP harness (no browser) is a cheaper way
to assert *write* gating per user — `require_once app/auth.php; echo can_write_x($me)?...` —
but only a real browser login confirms the **render** (a write button hidden vs merely
API-gated). Both matter: the data can be API-protected while the button still shows.

### Read-only discipline for smoke tests against production

maltyweb writes to a live brewery database (deliveries, recipe sessions, audit log).
Smoke tests MUST be read-only:

- Only navigate (GET) and assert rendering
- Never submit a form, click a destructive action, or trigger a POST
- The only POST permitted during a smoke run is the initial login
- Form-submission tests (approvisionnement.php, triage, ingest) require explicit
  operator approval and self-cleaning fixtures (test rows deleted same session)

### Useful smoke assertions

```python
# Page shell rendered
expect(page).to_have_title(re.compile(r"MaltyTask|MaltyWeb", re.IGNORECASE))

# No JS console errors
errors = []
page.on("console", lambda msg: errors.append(msg) if msg.type == "error" else None)
# ... navigate ...
assert len(errors) == 0, f"Console errors: {errors}"

# Key nav links respond (check for 200 by navigating and asserting shell)
for path in ["/dashboard", "/modules/approvisionnement.php", "/modules/triage.php"]:
    page.goto(f"https://app.maltytask.ch{path}")
    page.wait_for_load_state("networkidle")
    expect(page.locator("body")).to_be_visible()

# Charts present and non-empty (Canvas or SVG)
chart = page.locator("canvas, svg").first
expect(chart).to_be_visible()
bbox = chart.bounding_box()
assert bbox["width"] > 0 and bbox["height"] > 0, "Chart has zero dimensions"
```

### Screenshots

maltyweb uses a dark theme — always capture full-page screenshots and verify that
key UI elements (text, badges, chart labels) are legible (non-zero contrast).
Save to `/tmp`, never to the project or skill directories.

```python
page.screenshot(path="/tmp/maltyweb_smoke.png", full_page=True)
# Inspect: open /tmp/maltyweb_smoke.png to confirm dark-theme contrast is correct
# before locking in any colour-dependent assertions.
```

### Reconnaissance-then-action (from parent SKILL.md)

For any new page or modal, always screenshot + inspect DOM before writing selectors:

```python
page.screenshot(path="/tmp/recon.png", full_page=True)
content = page.content()                          # dump HTML for selector hunting
buttons = page.get_by_role("button").all()        # list all buttons
print([b.text_content() for b in buttons])
```

Only write hard selectors after seeing the rendered state.
