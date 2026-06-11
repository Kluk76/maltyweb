# PHP Security Reference — maltyweb stack

> Sources: agamm/claude-code-owasp SKILL.md (Auth & Sessions + Access Control sections, OWASP 2025);
> awesome-skills/code-review-skill reference/php.md (dynamic-identifier whitelist, constructor rule,
> type/boundary distinction, password/token mandates). Mined 2026-06-07.
> Stack: plain PHP 8, no framework, PDO, hand-rolled auth in app/auth.php, session-based login, CSRF tokens.

---

## Sessions & auth

**Session entropy.** PHP's default session ID is sufficient only if `session.entropy_length` + a CSPRNG-backed `session.hash_function` are configured. For manually issued tokens (remember_token, invite tokens), always generate via `random_bytes(32)` then `bin2hex()` → 256-bit hex string. Never use `uniqid()`, `mt_rand()`, or MD5/SHA1 — none are cryptographically secure.

**Session fixation — rotate on privilege change.** Call `session_regenerate_id(true)` immediately after any privilege elevation: successful login, 2FA completion, role upgrade. The `true` argument deletes the old session file so the old ID cannot be reused by an attacker who planted it.

**Session invalidation on logout.** Destroy the session array, regenerate the ID, and expire the cookie:
```php
$_SESSION = [];
session_regenerate_id(true);
setcookie(session_name(), '', time() - 3600, '/', '', true, true);
session_destroy();
```
Skipping any step leaves a usable ghost session.

**Cookie flags — all three are required.**
- `Secure` — cookie only sent over HTTPS; prevents cleartext interception.
- `HttpOnly` — blocks JavaScript from reading the cookie; mitigates XSS session-hijack.
- `SameSite=Lax` (minimum) or `Strict` — blocks CSRF via cross-site cookie send.

Set in `php.ini` or at `session_start()`:
```php
session_start(['cookie_secure' => true, 'cookie_httponly' => true, 'cookie_samesite' => 'Lax']);
```

**Timing-safe compare — use `hash_equals()` for every secret comparison.** String equality (`===`, `==`) returns early on the first differing byte, leaking timing information. Use `hash_equals($known_good, $candidate)` for tokens, CSRF values, and any secret extracted from user input. This applies even to tokens that are "just" invite links — an attacker who can measure response time can brute-force them.

**Password hashing — Argon2id, always via password_hash().** Never store plaintext, never use MD5/SHA1/bcrypt-as-a-string.
```php
$hash = password_hash($password, PASSWORD_ARGON2ID);    // stores algo+params in the hash string
$ok   = password_verify($password, $hash);              // timing-safe internally
```
`PASSWORD_DEFAULT` follows PHP's recommended algorithm over time; `PASSWORD_ARGON2ID` pins Argon2id explicitly — prefer the explicit form for audit clarity. Never pass raw `$_POST['password']` to any function other than `password_verify` / `password_hash`.

**Deny by default.** Every route that is not explicitly public must reject unauthenticated / unauthorised requests before any business logic runs. Call `require_login()` / `require_role()` at the top of every protected PHP file — not after an early-return branch that only some requests hit. Authorization checked = zero cases where a branch bypasses it.

**Rate-limit authentication endpoints.** Login, invite-token redemption, and (critically) TOTP verification endpoints must track failed attempts per IP + per account and slow/block after a threshold. Without this, 6-digit TOTP codes are brute-forceable in under 17 minutes at ~10 req/s.

---

## TOTP/2FA implementation notes

*(Brief — for the upcoming TOTP arc.)*

**RFC 6238 basics.** TOTP = HMAC-SHA1(secret, floor(unix_time / 30)). The 30-second window means you should accept T-1, T, T+1 to cover clock skew — but no wider. The secret is a base32-encoded random byte string (≥ 160 bits / 20 bytes).

**Encrypt stored secrets.** The TOTP secret in the DB must be encrypted at rest (application-layer AES-256-GCM over the base32 string, key from env). A DB dump that leaks the table otherwise gives every user's 2FA to an attacker — instantly compromising accounts even with rotated passwords.

**Rate-limit verification (see above).** Lock the account or introduce exponential backoff after N consecutive TOTP failures (N = 5 is a reasonable start). Log each failure with IP.

**Backup codes.** Generate 8–10 single-use codes at enrolment (`random_bytes(5)` → hex per code). Store as bcrypt/Argon2id hashes, not plaintext. Mark each as used on redemption. Treat a consumed backup code as a privilege-change event → regenerate session ID.

**Don't re-verify a window.** Track the last accepted TOTP counter value per user and reject reuse of the same T-window, even within the clock-skew tolerance — prevents replay within the same 30-second window.

---

## SQL identifier discipline

PDO prepared statements bind **values only** — you cannot bind a table name, column name, or sort direction as a parameter. Trying to do so produces either an error or a quoted string that breaks the query silently.

**Whitelist map pattern** — the only safe way to interpolate dynamic identifiers:

```php
// Define once, close to the query.
$SORT_COLS = [
    'date'     => 'created_at',
    'supplier' => 's.name',
    'amount'   => 'total_chf',
];
$col   = $SORT_COLS[$_GET['sort'] ?? 'date'] ?? $SORT_COLS['date'];  // unknown key falls back
$dir   = $_GET['dir'] === 'asc' ? 'ASC' : 'DESC';                   // two-value whitelist inline

$stmt = $pdo->prepare("SELECT … FROM inv_deliveries ORDER BY {$col} {$dir} LIMIT ?");
$stmt->execute([$limit]);
```

Why this matters: SQL injection via a column name bypasses parameterised queries entirely — `ORDER BY {$_GET['sort']}` with `sort=1 UNION SELECT …` is a textbook exfiltration vector. The whitelist collapses the attacker's input space to your predefined safe tokens.

**Apply this to every interpolated identifier**: table names in dynamic queries, column names in `INSERT INTO … ($cols)`, and sort directions.

---

## Input boundary vs types

These serve different purposes — you need both.

**Type declarations** express the internal contract between functions you control. They catch programming errors at call boundaries inside the codebase.

**Input validation** expresses how much you trust an external source (HTTP params, file uploads, JSON payloads, CLI args). Trust nothing from outside the process.

Pattern:
```php
// 1. Read with safe default — avoids NULL-to-string TypeError in PHP 8 strict mode
$raw = $_POST['quantity'] ?? '';

// 2. Validate at the boundary — reject before the value enters any logic
if (!ctype_digit($raw) || (int)$raw < 1) {
    http_response_code(422);
    exit(json_encode(['ok' => false, 'error' => 'invalid quantity']));
}

// 3. Now pass a typed value to internal functions
$qty = (int)$raw;
```

Never leave type-checking entirely to a runtime `intval()` deep in a helper. The PHP 8 `strict_types=1` mode surfaces this correctly — enable it on new files.

---

## Quick review checklist

1. `random_bytes(32)` for every new token/secret — no `uniqid()`, no `mt_rand()`.
2. `password_hash($p, PASSWORD_ARGON2ID)` + `password_verify()` — no raw SHA1/MD5.
3. `hash_equals($stored, $candidate)` for every secret comparison — no `===` on tokens.
4. `session_regenerate_id(true)` called on login, on 2FA success, on any privilege change.
5. Session cookies carry `Secure`, `HttpOnly`, `SameSite=Lax` — verify `session_start()` options or `php.ini`.
6. On logout: `$_SESSION = []`, regenerate, expire cookie, `session_destroy()` — all four steps.
7. Every protected file calls `require_login()` / `require_role()` before any business logic, not inside a branch.
8. `csrf_verify()` called before any validation or DB write in POST handlers — CSRF check is the first thing.
9. Dynamic SQL identifiers (column/table/sort) go through a whitelist map — never raw `$_GET` interpolation.
10. `must_be_one_of()` / whitelist used for every ENUM-constrained input before INSERT/UPDATE.
11. TOTP verification endpoints: rate-limited, last-accepted T-window tracked, backup codes hashed not plaintext.
12. PHP 8 two-step input reading: `$val = $_POST['x'] ?? ''` first, then validate — avoids NULL TypeError.
13. Constructor holds no HTTP reads, no DB queries, no file I/O — invariants only; inject dependencies.
14. `strict_types=1` declared on new PHP files — surfaces silent coercions that hide bugs.
15. After any column-type migration (VARCHAR → INT), grep PHP consumers for `preg_replace`/`strpos` on that column before merging — PHP 8 strict mode throws TypeError where PHP 7 silently coerced.
