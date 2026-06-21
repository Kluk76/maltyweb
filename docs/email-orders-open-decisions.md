# Email-orders — OPEN DECISIONS (validate together)

**This is the single place we walk through what's open.** One row = one thing that needs your call or our joint check. Each has a recommendation and a pointer to the detail. Tick as we go. Nothing here is live yet — the whole pipeline is dormant (BC push off, cron off, no orders flipped).

Detail lives in: `docs/email-orders-rollout-runbook.md` (rollout) · `docs/email-orders-internal-channel-analysis.md` (internal channel).

---

## A. Verify together (do these first, with you present)

- [ ] **A1 — Operator UI browser smoke.** The chip-click → resolves line → Validate-gate-enables interaction was only code-verified (the write form needs your logistics-manager login). Open `app.maltytask.ch/modules/email-orders.php`, confirm: human SKU labels show, chips resolve, **Validate stays disabled until every line is clicked**, multi card shows 2 sub-orders + one button. *Runbook step 2.*

## B. Rollout go / no-go (each is reversible; do in order)

- [ ] **B1 — Flip the 5 stuck orders into the queue.** One-time `--force` re-parse; local DB only, BC stays off. Makes the new UI show real orders. *Recommendation: yes, right after A1.* *Runbook step 1.*
- [ ] **B2 — Arm single-order BC create.** Set `email_order_bc_push_mode=armed`, then one supervised arm-test. This is the moment the methodology changes for single-order senders. *Recommendation: only when you're ready to switch; do the supervised test.* *Runbook step 3.*
- [ ] **B3 — Arm multi-order BC create.** After B2 proven: remove the v1 "multi never pushes" line, supervised multi arm-test. Dedup gate is already built. *Runbook step 4.*
- [ ] **B4 — Enable the connector cron (auto-ingest live).** The final switch — inbound emails land in the queue automatically. *Recommendation: last, after B1–B3 stable.* *Runbook step 5.*

## C. Internal free-text rep channel (decide, then I build)

The ~22 clean-code internal orders (`2×DOAB`, customer # in subject) — biggest remaining volume. I need 4 answers before building:

- [ ] **C1 — Coverage policy.** Accept a parser that extracts the recognizable `qty × code` lines as hints (operator completes), *without* the usual 100%-coverage-or-refuse guarantee? *(This is the core call.)*
- [ ] **C2 — Customer-number mapping.** Are subject numbers (3152, 3660, 1008, 3136) the BC `Sell_to_Customer_No` / a `ref_customers` key? *I will not guess a revenue mapping — needs your confirm.*
- [ ] **C3 — Thread duplication.** Same order recurs across reply rows. Top-post-only + existing dedup, or add an explicit same-customer+date dedup on the page?
- [ ] **C4 — Client-admin emails** ("créer ce nouveau client" + an order). Parse the order lines + leave client-creation manual, or skip until the client exists?

*Detail + recommendation: `docs/email-orders-internal-channel-analysis.md`.*

## D. Debt / nice-to-have (no decision needed; flag if you want it done)

- [ ] **D1 — Cobra `force_create` gate** is a ~60-line copy-paste of the validate gate → refactor to a shared helper. Cosmetic.

---

### Already closed this period (no action — FYI)
- External structured-order parser coverage **complete** (5 built + Blavignac declined; edu-vd & Stardrinks confirmed non-orders).
- Reader `/tmp` crash **fixed** (`58267cc`). Multi-order BC dedup gate **built** (`a129987`). Rollout runbook + dormant cron **written**.
