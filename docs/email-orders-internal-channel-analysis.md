# Internal free-text rep channel — analysis & proposal

**Status:** analysis only — NOT built. Decision-ready for Kouros's return.
**Why deferred:** this is the highest-volume remaining order source, but auto-parsing it requires a coverage-policy decision and touches revenue-mapping judgment, so it should not be shipped unsupervised.

---

## The finding

External structured orders (distributor PDFs) are fully covered. The remaining `no_match` volume is **internal staff emailing orders in free text** — HORECA reps (John, Megan, Nathan, Olivier…) placing orders on behalf of customers. Classifying the 66 internal `no_match` rows (qty × *active* `ref_skus.sku_code`, quoted-line ratio, subject keywords):

| Bucket | Count | Parseable as an order? |
|---|---|---|
| **Clean-code orders** (`2×DOAB`, `3×DIVF 1×DIBB`, `36×ZEPF`) | ~22 rows | **Yes** — but ≈ 8–9 *distinct* orders (many are reply-duplicates of the same thread) |
| Thread replies, no codes ("Re: Manque une grille?", "Tout bon") | ~22 | No — conversation |
| Client-admin ("créer ce nouveau client" + sometimes an order) | ~7 | Partial — order lines yes, client-creation is a separate action |
| Conversation / question | ~1 | No |
| Freetext / non-order noise (already-done externals, EXPED shipping notices, Heineken print-data) | ~14 | No / already handled |

**The tractable target: the ~22 clean-code rows.** They share a precise, high-signal shape:
- order lines as `<qty> <SKUCODE>` where SKUCODE is an active `ref_skus.sku_code` (DOAB, DIVF, SPYF, ZEPF, ZEPB, DOAF, EMBF, DIBB…);
- the **subject carries the customer number** — "3152 La Vaudaire", "3660 Simplon", "1008 Le Romandie", "3136 Big-t". Those numbers look like BC/customer numbers → a strong customer-resolution hint.

## Proposed parser (conservative, Model B)

A new `email_parsers/internal_rep.py`, registered **before** `generic_vocab`:
- **Matches:** sender is internal (`@lanebuleuse.ch`, `original_sender` is None) — the rep channel.
- **Parses only the top-post:** strip quoted lines (`>`…) and everything below reply separators ("Le … a écrit :", "De :", "From:", signature) so a reply that quotes an earlier order does NOT re-emit stale lines.
- **Extracts** `<qty> <SKUCODE>` where SKUCODE ∈ active sku_codes → `ParsedLine{qty, sku_hint=code, raw=fragment}`. Hints only; the operator still confirms every FK at the card.
- **customer_hint:** the subject's leading number + name ("3152 La Vaudaire").
- **Declines** (→ stays `no_match`, manual) when it finds no `qty × active-code` pair — so conversations/questions/non-orders fall through untouched.

Precision is high because the code must be a *real active* sku_code; false-positives on conversational mail are unlikely.

## Decisions needed from you (the reason this wasn't auto-built)

1. **Coverage policy.** Our parser protocol is "100%-coverage-or-refuse." Free-text can't satisfy that — an internal email may contain prose around the lines. Do you accept a parser that extracts the recognizable `qty × code` lines and emits them as hints (operator completes/edits), *without* a full-coverage guarantee? (This is the core policy call.)
2. **Customer-number resolution.** Are the subject numbers (3152, 3660, 1008, 3136) BC `Sell_to_Customer_No` / a `ref_customers` key? If yes, the card could pre-suggest the customer from the number (still operator-confirmed). Confirm the mapping so we don't guess.
3. **Thread duplication.** The same order recurs across reply rows (e.g. "3152 La Vaudaire" appears in ids 25/39/42). Top-post-only parsing + the existing BC dedup + operator review handle most of it, but do you want an explicit same-customer+date dedup on the review page, or is per-card review fine?
4. **Client-admin emails** (L'Arcade: "créer ce nouveau client" + a first order). Parse the order lines and leave client-creation to the operator, or skip these entirely until a client exists?

## Recommendation

Build it as proposed (conservative, decline-if-no-codes, top-post-only), **register it but keep the whole pipeline disarmed** so it only feeds the review queue — never auto-creates. Ship after you answer (1) (the coverage policy) and confirm (2) (the customer-number mapping). Estimated: one focused parser build + a dry-run measurement pass over these ~22 rows to confirm precision before registering.

**Concrete sample (id 73, John Penman):** *"Pour ce client, il faudrait 3 EPH2B26 et 2 DOAB s'il vous plait."* → would parse to `[{qty:3, sku_hint:'EPH2B26'}, {qty:2, sku_hint:'DOAB'}]`, customer_hint "3152 La Vaudaire", operator resolves customer + confirms the two SKUs.
