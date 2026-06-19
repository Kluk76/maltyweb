# Fulfilment — Cobra distributor identity: ref_customer_identity billing-alias link (mig 389)

## ✅ §COBRA DISTRIBUTOR IDENTITY — `ref_customer_identity` BILLING-ALIAS LINK — SHIPPED 2026-06-16 (mig 389; applied to prod; NOT git-committed/pushed — left for Kouros review)

**DURABLE ARCHITECTURAL FACT (Cobra distribution model).** La Nébuleuse's big-retail customers are BILLED via distributor **Cobra Traders**. BC books each order under a `Cobra Traders - X` sub-account (the **bill-to**); WeeklyOrders import + operators separately entered the SAME shipment under the geographic store account (the **ship-to**). Two distinct `ref_customers` rows for one economic order → the BC connector's collision-skip (keyed on `customer_id_fk`) never fired → **DUPLICATE orders**. Proven by ledger bill-to test: 100% of `inv_sales_ledger` sales post under Cobra-X accounts; the geographic store accounts carry ZERO direct billing (= pure ship-to shells).

**FIX (PM spec, executed):**
1. **mig 389 `ref_customer_identity`** — NON-destructive link table (NOT a merge): `member_customer_id_fk` UNIQUE → `canonical_customer_id_fk`, `relation` ENUM `'billing_alias'`, both FKs INT UNSIGNED → `ref_customers.id`; `schema_meta` row `table_class='reference'` `corrections_policy='allowed'`. **Both accounts stay `is_active=1`; `sync_bc_customers.py` UNTOUCHED.** A merge tool was REJECTED — it would tombstone one side → perpetual drift-review noise.
   - Seeded **40 links**: 756 ALIGRO ← 316/2414/2415/2416; 755 COOP ← 343/369/578/579 + 2417-2438 (26); 993 MIGROS ← 2439/2440; 760 MANOR ← 97/262/263/277/289/331/646; 934 WITTICH ← 94.
2. **EXCLUSIONS (operator rule + ledger evidence — NOT linked):** Migros Partenaire ×10 (DIRECT delivery, not via Cobra — Kouros's explicit carve-out), Migros Golf 1667 (golf-parc name collision), Cooper SA 323 + Coopérative Le Bled 2305 (other entities), Volg 944/949 (billed direct + no Cobra-VOLG account); Lidl/Aldi/Denner/Otto's have no geographic member accounts.
3. **Connector `scripts/python/ingest_bc_sales_orders.py`** — added `load_customer_identity_map()` + `canon(cid)` collapses BOTH sides of `detect_collisions()` to canonical. Dry-run: ORD210067/68 now SKIP-COLLISION (matched to kept #52/#53). Resurrection verified NOT a risk: `_REFRESH_LINE_STATUSES={entered,confirmed}` → `upsert_order` returns 'skip' for cancelled; UPDATE branch + `write_bc_mirror_fields()` never write status (Ruling-4 intact).
4. **Cleanup:** cancelled the duplicate BC echoes #142/#143 (status='cancelled' + `ord_order_status_events` + `log_revision`, FG-neutral; one-shot script removed local+VPS post-run).

**VERIFIED FINAL:** #52 shipped + #53 bl_printed ACTIVE; #142/#143 cancelled; `ref_customer_identity`=40 rows; mig 389 recorded; FK 40/40 OK.

**🔴 REUSABLE RULE (future brand extension):** confirm Cobra-distribution via the **ledger bill-to test** (do all the brand's sales post under a Cobra-X account, with the geographic account carrying zero direct billing?) **+ operator confirmation** — NEVER guess membership by name prefix. (Same never-guess-a-mapping discipline; geographic name match is a HINT, not proof — Migros Golf / Cooper SA / Le Bled are the name-collision counterexamples.)

**OPEN/WATCH:** NOT committed/pushed. ⚠ Shared-clone entanglement — working tree also dirty with PARALLEL-SESSION edits (expeditions.php +54, expeditions.css +92, a pm-memory md) + the just-shipped per-order departure-site work. **Commit ONLY `db/migrations/389_ref_customer_identity.sql` + `scripts/python/ingest_bc_sales_orders.py`, name files explicitly, never `-A`** (pathspec rule + separate-worktree rec apply).

---
