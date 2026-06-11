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
- submit button label: "Se connecter"

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
    page.get_by_role("button", name="Se connecter").click()
    page.wait_for_load_state("networkidle")
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

### Canonical smoke account + reaching role-gated pages

There is a permanent, operator-blessed smoke account — use it, don't invent one:

- **`users.id=16`**, username **`smoketest`**, `display_name='Smoke Test (bot)'`, **`role='viewer'`**, no `user_page_access` grants, `access_preset_id_fk=NULL`.
- Credentials live in **`/home/kluk/projects/maltytask/secrets/maltyweb-smoketest.env`**
  (the **maltytask** repo, not maltyweb), keys `MALTYWEB_USER` / `MALTYWEB_PASS`, mode 600, gitignored.

**Page access is role-floor gated.** `app/auth.php::user_can_access()` order: admin bypass → per-user `user_page_access` override → role-floor (`_role_rank(user) >= _role_rank(page.min_role)` from `ref_pages`) → preset membership → **fallback: NULL preset + role-floor passed ⇒ allow**. Since all current users (incl. id=16) have `access_preset_id_fk=NULL` and no override rows, **role alone decides** for them. Ranks: viewer 0, operator 1, manager 2, admin 3.

So the viewer smoke account can VIEW any `min_role='viewer'` / login-only page, but is redirected off operator-gated pages (e.g. `form-racking.php`, `tanks.php` = operator floor). To smoke an **operator-gated** page read-only, temporarily elevate and revert in the same run — one reversible UPDATE, no grant rows needed:

```sql
UPDATE users SET role='operator' WHERE id=16;   -- elevate
--   ... run the read-only Playwright smoke ...
UPDATE users SET role='viewer' WHERE id=16;      -- ALWAYS revert, even if smoke fails
```

Flag the elevation to the operator (it touches an account), and have the **parent** — not the smoke subagent — own the revert so it happens regardless of smoke outcome.

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
