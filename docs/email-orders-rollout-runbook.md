# Email-orders pipeline — rollout runbook

**Audience:** Kouros (operator/admin), day-1 back from holiday.
**Status as written (2026-06-22):** everything below is BUILT, COMMITTED, and DEPLOYED to the VPS, but **DORMANT** — the live operator methodology is unchanged (logistics still create orders in BC; maltytask retrieves). Nothing auto-creates orders; BC push is OFF; the connector cron is OFF; no parsed orders have been pushed into the operator queue.

The vision (ratified): read inbound order emails → check if the order already exists in BC → if not, raise a validation card → operator validates → maltytask creates the order in BC. We are at the "everything built, not yet switched on" stage.

---

## What shipped this week (all on `main`, deployed)

| Commit | What |
|---|---|
| `fe8266c` | 4 single-order PDF parsers — Nausikraft, Petite Cave, Amstein, Alloboissons |
| `ec8c42f` | Boissons GDS multi-order parser + connector multi-shape (`_kind:parsed_order_hints_multi`) |
| `47ceea4` | Multi-order review UI + atomic promote (`email_order_promote_multi`) |
| `b97c7a6` | Operator UI polish — suggestion chips, human SKU labels, progress, sticky validate, live counts |
| `58267cc` | Reader `/tmp` CSV crash fix (already safe to leave running) |
| `a129987` | Multi-order BC dedup gate (`bc_order_match --order-index`) — makes multi safely armable |

Parser coverage of **external structured orders is complete**: Nausikraft, Petite Cave, Amstein, Alloboissons, Boissons GDS (multi) built; Blavignac declines permanently (scanned image + handwritten quantities — not machine-parseable; operator handles manually).

## Current dormant state (verify before rollout)

- `system_settings` `email_order_bc_push_mode` (section `logistics`) = **`off`**. No BC writes happen on promote.
- Connector cron **not installed** (`db/cron/maltytask-email-orders.cron` exists but the schedule line is commented).
- 5 real orders sit in **Non parsé** in the DB (ids 38/47/63/77/98) — they parse correctly but were ingested before the parsers existed, so they stay `no_match` until a one-time `--force` re-run.
- Multi-order path: promote works; BC push for multi is intentionally **excluded** in v1 (`validate_multi` does not push). The dedup gate (`a129987`) is in place, so this exclusion can be removed at rollout.
- UI polish IS live on the page, but with no new orders flowing it changes nothing about the team's current task.

---

## ROLLOUT SEQUENCE (do in order; each step is reversible)

### Step 0 — Pre-flight
```bash
ssh maltyweb
cd /var/www/maltytask
# confirm disarmed
mysql ... -e "SELECT value_text FROM system_settings WHERE section='logistics' AND setting_key='email_order_bc_push_mode'"   # expect: off
# confirm parsers deployed
sudo -u maltytask .venv/bin/python -c "import sys; sys.path.insert(0,'scripts/python'); from email_parsers import REGISTRY; print([p.name for p in REGISTRY])"
```
Expect the registry to include nausikraft, petitecave, amstein, alloboissons, boissons_gds (before generic_vocab).

### Step 1 — Surface the 5 stuck orders (still no BC writes)
Flip the pre-existing no_match orders into the **À valider** queue with a one-time forced re-parse. This is local-DB only, idempotent, BC stays off.
```bash
# targeted: re-OCR/re-parse just these emails from the live mailbox
sudo -u maltytask .venv/bin/python scripts/python/ingest_email_orders.py --force --apply
# (or run against the saved fixtures if still present under data/email-order-samples/)
```
Verify in the DB: rows 38/47/63/77 → `parsed`, row 98 → `parsed` (multi). Then open the **Commandes e-mail** page — they appear as review cards (98 as a 2-sub-order card).

### Step 2 — Operator UI smoke (the one thing not yet browser-verified)
On your tailnet, open `https://app.maltytask.ch/modules/email-orders.php` as a logistics manager and confirm on a real card:
- human SKU labels show (e.g. "Zeppelin — Fût 20 L"), not raw codes;
- clicking a suggestion chip resolves the line; "Autre…" reveals manual search;
- **Validate stays disabled until customer + every line is clicked** (the guardrail);
- the multi card (row 98) shows 2 sub-orders + one "Valider les 2 commandes".
This is the verification that could only be done by code inspection while you were away — do it before relying on the chips.

### Step 3 — Arm single-order BC create (supervised)
Only when you're ready to switch methodology for single-order senders:
```
Données générales → email_order_bc_push_mode → armed
```
Then do ONE supervised end-to-end test (mirror the original ORD210160 arm-test): validate one real single-order card, confirm it creates the BC SalesOrder with `Your_Reference='mt:<id>'`, the local row rekeys to `bc:<No>` in place, and the `*/15` reader echo-leg makes **no** duplicate. Roll back to `off` instantly if anything looks wrong.

### Step 4 — Enable multi-order BC create (after Step 3 proven)
The dedup gate (`a129987`) is in place, so multi is now safe to push. Remove the v1 exclusion in `public/modules/email-orders.php` (the `validate_multi` block that deliberately skips the BC push — marked with a comment referencing this runbook), redeploy that one file surgically, and do a supervised multi arm-test (validate row 98 → 2 BC orders, each `mt:<id>` → `bc:<No>`, no duplicates). The per-sub-order dedup interstitial (Archiver / Tout créer quand même) will fire if a sub-order already exists in BC.

### Step 5 — Enable the connector cron (auto-ingest goes live)
This is the final switch — inbound emails start landing in the queue automatically.
```bash
# in repo: uncomment the schedule line in db/cron/maltytask-email-orders.cron
sudo touch /var/log/maltytask/email-orders.log
sudo chown maltytask:www-data /var/log/maltytask/email-orders.log
sudo install -m 0644 db/cron/maltytask-email-orders.cron /etc/cron.d/maltytask-email-orders
```
Watch `/var/log/maltytask/email-orders.log` for the first ticks.

### Step 6 — Monitor
- New orders appear in **À valider**; operators validate; armed orders flow to BC.
- The `*/15` BC reader echo-leg keeps local ↔ BC keys in sync (its `/tmp` crash is fixed — `58267cc`).

---

## Rollback at any step
- Step 3/4: set `email_order_bc_push_mode` → `off`. Promotes stop reaching BC immediately.
- Step 5: `sudo rm /etc/cron.d/maltytask-email-orders` — auto-ingest stops; manual runs still possible.
- Promoted-but-wrong local order: it starts `status='entered'` and depletes **zero** FG until set to `shipped`, so a mistaken promote is safe to correct before shipping.

## Open items / decisions still pending
- **Internal free-text rep channel** (≈ the biggest remaining order volume — HORECA reps emailing `2×DOAB` etc.): NOT built. It needs a coverage-policy decision and is documented in `docs/email-orders-internal-channel-analysis.md` — a ~5-minute decision then a build.
- **Cobra `force_create` gate** is a ~60-line copy-paste of the validate gate — refactor to a shared helper (cosmetic; debt only).
