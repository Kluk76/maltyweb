# email_parsers — Sender-parser registry

Per-sender deterministic parsers for inbound email orders at commandes@lanebuleuse.ch.

## Architecture

Mirrors the `lib/invoice-parsers/index.js` dispatch doctrine:

```
dispatch(ctx: EmailContext) -> ParsedOrder | None
```

* Iterate `REGISTRY` in order; first `matches(ctx)` that returns `True` wins.
* Call its `parse(ctx)` and return the `ParsedOrder`.
* If no parser matches → return `None` → caller records `parse_status='no_match'`.
* If a parser's `parse()` raises → propagate → caller records `parse_status='error'`.

**NO LLM FALLBACK — EVER.** A no-match email goes to the review bucket. A parser
error goes to `parse_status='error'`. Neither path ever calls a language model.
This is a hard architectural constraint. Add a deterministic parser when samples
arrive; do not route through an LLM.

## Adding a sender parser

1. **Copy** `example_template.py` to `<sender_slug>.py` (e.g. `customer_coop.py`).
2. **Set** `name = '<sender_slug>'` (stored in `doc_email_messages.parser_matched`).
3. **Implement `matches()`** — match on `from_address` first (most specific signal).
   Add subject/body guards only when the sender domain is shared by non-order types.
4. **Implement `parse()`** — return `ParsedOrder` with raw HINT strings only.
   Never resolve `customer_hint`/`sku_hint` to database IDs here.
   Raise `ValueError` on unrecoverable parse failure.
5. **Import** your class in `__init__.py` and append an instance to `REGISTRY`.
   Order matters — more specific matchers before more generic ones.
6. **Add a `.eml` fixture** under `tests/fixtures/email_parsers/<sender_slug>/` and
   a corresponding `expected_output.json` with the expected `ParsedOrder` fields.
7. **Verify**:
   ```bash
   python3 -m py_compile scripts/python/email_parsers/<sender_slug>.py
   python3 scripts/python/ingest_email_orders.py --fixtures-dir tests/fixtures/email_parsers/<sender_slug>/ --dry-run
   ```

## Parser discipline

* **Deterministic**: same input must always produce the same output.
* **Hints only**: `customer_hint` and `sku_hint` are raw strings from the email —
  never resolved to `ref_customers.id` / `ref_skus.id` at parse time.
* **No LLM**: any use of language-model inference in a parser file is a critical bug.
* **Raise, don't swallow**: on parse failure, raise `ValueError` with a clear message.
  The caller surfaces it to the operator UI via `parse_error`.
* **False-negative safe**: if in doubt, return `matches()=False` — a false-negative
  lands in `no_match` (review queue); a false-positive silently mis-classifies.

## Files

| File | Purpose |
|---|---|
| `__init__.py` | Registry list + `dispatch()` function |
| `base.py` | `EmailContext`, `ParsedOrder`, `ParsedLine`, `SenderParser` ABC |
| `example_template.py` | Reference template (NOT registered — never matches) |
| `<sender_slug>.py` | Real sender parser (add as needed, once samples arrive) |
