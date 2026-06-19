# Entity Discussion Tracker — mail + document timeline on supplier AND customer cards

> Read when touching: a chronological mail/document thread on a supplier or customer fiche; `comm_threads`/`comm_messages`/`comm_message_docs`; linking `kouros@lanebuleuse.ch` (or any personal/shared Workspace mailbox) into maltyweb; the customer-fiche surface (does not exist yet); matching an inbound/outbound email to an entity. Companions: [email-order-ingestion-arc.md](email-order-ingestion-arc.md) (Gmail DWD substrate — REUSE the auth, NOT the order tables), [sales-commercial-surface/README.md](sales-commercial-surface/README.md) (ref_customers canonical), [ingestion-validation-gate-arc.md](ingestion-validation-gate-arc.md) (doc_files model).
>
> **STATUS: LOCKED — BUILD-READY (PM, 2026-06-19). Kouros answered all 7 sizing Qs; plan locked, nothing built yet.** DECISIONS: (1) mailbox = `kouros@lanebuleuse.ch` ONLY (v1); (2) capture + manual-notes, NO outbound compose; (3) 6-month backfill on first run; (4) SUPPLIER-FIRST (graft onto salle-fournisseurs `#sf-fiche`), customer card DEFERRED to P3; manual call-notes in same timeline = YES; NO LLM summary (raw thread); privacy = persist ONLY messages that resolve to an entity OR land in the review bucket, drop the rest unstored.
> **VERIFIED LIVE 2026-06-19:** MIG HEAD = **412** (all applied incl. parallel `412_recipe_change_requests`; 0 pending) → **next-free = 413** (re-`--status` at build start, parallel sessions lead). FK PK types: `ref_suppliers.id`=INT UNSIGNED, `ref_customers.id`=INT UNSIGNED, `doc_files.id`=BIGINT UNSIGNED, `doc_email_messages.id`=BIGINT UNSIGNED. CHECK precedent confirmed: `ord_orders_chk_exactly_one_party` (clone its shape for `comm_threads_chk_one_party`). Gmail DWD: SA `maltytask-gmail-reader@…` client_id `114068789146830012608`; order connector `ingest_email_orders.py` uses `Credentials.from_service_account_file(sa_keyfile, scopes=["…/gmail.readonly"], subject=GMAIL_DELEGATED_USER)` — currently impersonates `production@` (commandes@ distribution-list member). `config/gmail.env` keys = `GMAIL_DELEGATED_USER` / `GMAIL_SA_KEYFILE` / `GMAIL_QUERY` (640 maltytask:www-data). **OPERATOR-GATE: kouros@ must be authorized as an impersonation subject under the SAME DWD client-id grant** (Workspace Admin → Security → API controls → Domain-wide delegation; the client-id is already listed for the order arc — kouros@ is reachable only if the grant is domain-wide for that scope, OR a fresh per-scope entry is confirmed). Attachment byte-fetch is a CONNECTOR GAP (order connector stores `content:None` metadata-only) — comm connector MUST add `messages.attachments.get` byte download → doc_files.
> **LOCKED EXECUTION ORDER: P0 (auth+raw spike) → P1 (schema+connector+matcher) → P2 (supplier timeline panel). P3 (customer card) deferred.** Within-phase parallelism noted in the plan. EQUIP: P0 coder+sql; P1 sql+coder+parser-coder(light envelope/header); P2 ui+coder+sql+webapp-testing.

## Verbatim intent (Kouros, 2026-06-19)
Link `kouros@lanebuleuse.ch` (Google Workspace) to maltytask. Build a **discussion tracker** on **supplier cards AND client cards**: track mail discussions AND document exchanges **chronologically** on each card; precise "what is being discussed" + "what documents are sent"; **ONE merged thread** — text messages chronological, documents **interleaved between texts** chronologically (NOT a separate tab).

## Ground-truth facts (PM-verified LIVE on VPS 2026-06-19)
- **Supplier card surface EXISTS:** `public/modules/salle-fournisseurs.php` — Salle des Machines governance fiche. Registry list (left) + AJAX-loaded `#sf-fiche` panel (right), rendered by `public/js/salle-fournisseurs.js` from api `public/api/sf-supplier-evaluation.php` / `sf-validate-supplier.php`. The fiche is ALREADY tab/section-bearing (governance + evaluation). `require_page_access('approvisionnement')`; admin = full, manager = read+propose, opérateur redirected. **This is where the supplier discussion thread goes (a new fiche section).**
- **Customer card surface DOES NOT EXIST.** No customer/client fiche page in `ref_pages`; no `ref_customers`-fiche module in code. So the client-side tracker needs a NEW fiche surface to be created (or a thread panel grafted onto wherever customer detail is shown — currently nowhere as a card). This is the bigger build of the two.
- **Customer canonical table = `ref_customers`** (id INT UNSIGNED PK, name, bc_customer_no UNIQUE, email, phone, address, trade_channel, sale_class, FK targets for transporter/site, is_active, notes). **`ref_clients` = thin legacy (id/name/notes only) — IGNORE, do not extend it.** All customer FKs in the app point to ref_customers.
- **Supplier canonical = `ref_suppliers`** (id INT UNSIGNED PK, supplier_id, name, gl, vat, commissioning_state, criticality). No email column on ref_suppliers today — supplier emails live in invoices/aliases, not a pinned field.
- **Gmail DWD is ALREADY LIVE** (email-order arc P0, PM-verified 2026-06-19): SA `maltytask-gmail-reader@kouros-infrastructure.iam.gserviceaccount.com` with domain-wide delegation; `config/gmail.env` + `config/gmail-sa.json` on VPS (maltytask:www-data); connector `scripts/python/ingest_email_orders.py` reads real messages with `gmail.readonly`-class scope impersonating `production@`. **The auth substrate to read a mailbox is solved — REUSE it.** Linking `kouros@` = impersonate a different user OR add it to the DWD scope set (same SA, same grant pattern; may need the new impersonated user authorized).
- **`doc_email_messages` EXISTS but is ORDER-SCOPED** (rows=0): cols message_id UNIQUE / from_address / to_address / subject / received_at / body_format / raw_body / attachments_json / parser_matched / `parse_status enum('unparsed','parsed','no_match','error','order_created')` / parsed_json. The ENUM + parsed_json are entirely about order extraction. **DO NOT hijack this table for discussions** — that would be a parallel-purpose store on one table (two facts, one table = divergence smell). A discussion message is a different fact (correspondence, not a parseable order). Keep it separate; a thread message MAY reference a doc_email_messages row if the same email also produced an order (optional cross-link, not a merge).
- **doc_files model:** `doc_files` (id BIGINT UNSIGNED PK, file_id VARCHAR UUID UNIQUE, file_name, local_path, file_hash, mime_type, source_folder, is_active). Canonical document store. FK cols named `*_file_id_fk` reference `doc_files.id` (the BIGINT), NEVER the UUID — see [[feedback_doc_files_pk_vs_uuid]].
- **Doc-tied-to-entity PRECEDENT already in the codebase:** `supplier_cert_documents` (supplier_id_fk INT→ref_suppliers.id, **`doc_file_id_fk` BIGINT→doc_files.id**, doc_type enum, issued_on, expires_on, is_active). This is EXACTLY the shape to clone for "a document attached to an entity". Email attachments → store bytes in doc_files (real bytes on VPS, never symlinks — [[feedback_ingest_real_bytes_not_symlinks]]) → reference from the timeline.
- **Per-entity event + child-rows PRECEDENT:** `supplier_evaluations` (supplier_id_fk, event-type enum, child `supplier_evaluation_criteria`, surfaced as a fiche tab). Confirms the house pattern: event table FK to the entity, rendered as a fiche section.
- **No timeline/discussion/conversation/interaction table exists** anywhere on the live DB (verified by table-name scan). Greenfield.

## Where this sits in the derivation tree
**OUTSIDE the Le Zeppelin SOT/fiscal chain entirely.** This is a NON-FISCAL observation/CRM layer hanging off two identity roots: `ref_suppliers` (a Salle-des-Machines family) and `ref_customers` (a Le Cockpit family). It DERIVES from those identity tables by FK; nothing fiscal derives from it. **CARDINAL: never feeds COGS/COP/WAC/BOM/beer-tax/inventory** — same discipline as planning, supplier-evaluation, returns-disposition observation layers. schema_meta class = source (ingested mail) / reference (manual notes); corrections_policy = allowed.

## PROPOSED DATA MODEL (PM lean — ratify at P0)
Three tables, polymorphic-by-XOR entity link (the house pattern: nullable FK per entity type + a CHECK enforcing exactly-one, mirroring `ord_orders_chk_exactly_one_party`).

1. **`comm_threads`** — one row per conversation thread on one entity.
   - `id` BIGINT UNSIGNED PK
   - `supplier_id_fk` INT UNSIGNED NULL → ref_suppliers.id
   - `customer_id_fk` INT UNSIGNED NULL → ref_customers.id
   - CHECK `comm_threads_chk_one_party`: exactly one of supplier_id_fk / customer_id_fk non-NULL
   - `subject` VARCHAR(998) (thread subject, from first message / normalized Re:/Fwd:-stripped)
   - `gmail_thread_id` VARCHAR(255) NULL UNIQUE-per-mailbox (Gmail's threadId — the native thread grouping; the cleanest idempotent grouping key)
   - `last_message_at` DATETIME (denormalized for sort; maintained on insert)
   - `is_active` TINYINT, `created_at`, `updated_at`
   - schema_meta: source

2. **`comm_messages`** — one row per message (the TEXT entries of the timeline).
   - `id` BIGINT UNSIGNED PK
   - `thread_id_fk` BIGINT UNSIGNED → comm_threads.id
   - `direction` ENUM('in','out') — inbound (from entity) vs outbound (from us)
   - `from_address` VARCHAR(320), `to_address` VARCHAR(998) (may be multi-recipient), `cc_address` VARCHAR(998) NULL
   - `subject` VARCHAR(998), `body_format` ENUM('text','html'), `body` MEDIUMTEXT (sanitized for render — strip scripts), `body_snippet` VARCHAR(512) (plain-text preview)
   - `sent_at` DATETIME (the email Date: header — THE timeline ordering key)
   - `message_id` VARCHAR(512) UNIQUE (RFC822 Message-ID — idempotency, mirrors doc_email_messages)
   - `gmail_message_id` VARCHAR(255) NULL (Gmail API id)
   - `source` ENUM('gmail','manual') — manual = an operator-typed note/log entry (call notes etc.); both render in the same timeline
   - `source_email_id_fk` BIGINT UNSIGNED NULL → doc_email_messages.id (OPTIONAL cross-link when the same email ALSO produced an order; NOT a dependency)
   - `created_by_user_id` INT UNSIGNED NULL (who logged a manual entry), `created_at`, `updated_at`
   - schema_meta: source

3. **`comm_message_docs`** — documents attached to a message (the DOC entries interleaved in the timeline). Clone of supplier_cert_documents shape.
   - `id` BIGINT UNSIGNED PK
   - `message_id_fk` BIGINT UNSIGNED → comm_messages.id (so a doc inherits the message's `sent_at` for interleaving — KEY to the "documents between texts chronologically" requirement)
   - `doc_file_id_fk` BIGINT UNSIGNED → doc_files.id (the byte store)
   - `attachment_filename` VARCHAR(512), `mime_type` VARCHAR(128), `direction` ENUM('in','out') (inherited convenience)
   - `created_at`
   - schema_meta: source

**Why this satisfies the "one merged thread, docs interleaved between texts" requirement:** the timeline is a single ORDER BY over `sent_at` across `comm_messages`, with each message's `comm_message_docs` rendered inline beneath/within it (a doc shares its message's timestamp). If Kouros wants a doc to appear as its OWN standalone timeline node (a doc sent with no covering text), that still works — it's a comm_messages row (possibly body-empty) carrying the attachment. So the render is: SELECT messages for thread ORDER BY sent_at; per message, render text then its docs. Chronology is exact because docs key off the message timestamp. **No separate tab** — one panel, one ordered list.

**Reconciliation with doc_files / the existing document-log model:** email attachments are just documents. Store the bytes in `doc_files` (source_folder e.g. 'email-comm'), exactly like invoices/DNs/certs. `comm_message_docs.doc_file_id_fk` → doc_files.id. This means a single document could in principle be referenced from both an invoice ingest AND a comm thread (same file_hash dedup applies). **Do NOT create a second document store** — reuse doc_files (one fact, one canonical table).

## EMAIL INGESTION APPROACH
- **Auth: REUSE the live Gmail DWD substrate** (do not stand up IMAP). Same SA + DWD grant pattern as email-order P0. To read `kouros@lanebuleuse.ch`, impersonate that user via the SA (the DWD grant is domain-wide, but the impersonated subject must be authorized — confirm `kouros@` is allowed under the existing client-id scope grant, or extend it). Scopes: `gmail.readonly` is enough for reading + attachments; add `gmail.modify` ONLY if we want to label-processed (belt-and-suspenders dedup) — message_id UNIQUE already gives idempotency, so readonly may suffice for v1.
- **Connector: a NEW Python poller** `scripts/python/ingest_email_comm.py` (sibling to ingest_email_orders.py; same config/env model, same cron pattern, `--dry-run` default, DISABLED cron until operator enables — strangler: maltyweb cron + MySQL, NOT a new Node script, NOT the maltytask Node pipeline). It is SEPARATE from the order poller because the entity-resolution target and the destination tables differ (orders→ord_orders; comm→comm_*). They MAY later share a fetch layer, but v1 = a clean separate process.
- **Entity matching (the crux — revenue/identity-adjacent → never guess):** for each message, resolve from_address (inbound) or to_address (outbound) → an entity.
  - **Precedence: pinned alias → exact email match → review.** Build a small pin table `comm_address_pins` (email VARCHAR(320) UNIQUE → supplier_id_fk XOR customer_id_fk) — analogous to ref_mi_aliases / sender-pins. First, look up the pin. Else, exact-match the address against `ref_customers.email` and (a derived) supplier email set. **Domain-only matching is DANGEROUS** (gmail.com/hotmail.com shared by many; one supplier domain may host several entities) — domain match is a SUGGESTION for the review queue, NEVER an auto-link.
  - **Unmatched / ambiguous → a review bucket** (a thread with entity NULL pending operator assignment, OR a doc_review_queue-style row). Refuse-don't-guess: a thread NEVER auto-links to an entity FK below the exact-or-pinned bar. The operator assigns it (which also creates the pin for next time).
  - **Direction:** message where from is an @lanebuleuse.ch / our-mailbox address = 'out'; where to is our address = 'in'. (Forwarded chains: same forwarded-envelope hazard as the order arc — but for discussions the forwarder fidelity matters less; v1 can key on the envelope and let the operator re-home.)
- **Thread grouping:** use Gmail's native `threadId` → `comm_threads.gmail_thread_id`. Each message in that thread = a comm_messages row. New thread = new comm_threads row (entity resolved from the first/any resolvable message in the thread).
- **Attachments:** the connector must FETCH bytes (the order connector currently captures metadata only — same gap noted in the email-order arc). Gmail API `messages.attachments.get`; persist to doc_files (real bytes on VPS), then comm_message_docs.
- **NO LLM** — this is correspondence capture + deterministic address matching, no extraction/parsing of order content needed. (If Kouros later wants "what is being discussed" auto-summarized, that's a separate, explicitly-gated decision — default v1 shows the raw thread, operator reads it.)

## SCOPE NOTE — which mailbox(es)?
Kouros said link `kouros@`. But supplier/customer correspondence likely flows through multiple mailboxes (`production@`, `commandes@`, personal `kouros@`, maybe `info@`/`achats@`). **OPEN QUESTION below.** Architecturally the connector should take a configurable set of impersonated mailboxes + a query filter, and de-dup across them by Message-ID (the same email seen in two mailboxes = one comm_messages row). Direction is computed per-message from from/to, independent of which mailbox it was read from.

## SEQUENCING / ROADMAP
- **P0 — Auth + raw capture spike (gated on Kouros answers).** Authorize `kouros@` (+ any other agreed mailbox) under the existing DWD SA; new poller reads + caches RAW messages (no entity link, no UI) into a staging form; prove fetch + attachment-byte download works. Deliverable: "N messages, from/to/subject/threadId, M attachments fetched". EQUIP: coder + sql. Disarm: readonly, dry-run default, cron disabled.
- **P1 — Schema + entity matcher + ingest.** Migrations for comm_threads/comm_messages/comm_message_docs/comm_address_pins (+ schema_meta rows, CHECK constraints, FK types exact). Connector resolves entity by pin→exact→review; writes threads/messages/docs; attachments→doc_files. Unmatched→review bucket. EQUIP: sql + coder + parser-coder (envelope/header parsing — light). Disarm: cron disabled; matching never auto-links below exact/pin.
- **P2 — Supplier timeline panel (smaller — surface exists).** New section on `salle-fournisseurs.php` `#sf-fiche`: the merged chronological thread (texts + interleaved docs), auto-refresh/live per the live-visibility default. Doc nodes link to the doc_files viewer. Manual-note entry (comm_messages source='manual') so operators can log calls. + the review-queue triage to assign unmatched threads → entity (creates a pin). EQUIP: ui + coder + sql + webapp-testing. RULE 3: salle-fournisseurs is not currently in ref_pages/topbar — confirm tour-card obligation when it gets a page row.
- **P3 — Customer fiche + timeline panel (bigger — surface must be BUILT).** Create the customer fiche surface (a new `ref_pages` row + module, OR a panel grafted into expeditions/sales). Same timeline component as P2, parameterized by entity type (build the timeline render ONCE, reuse for both — call the helper, never re-inline). Access-preset grant + tour card (RULE 3). EQUIP: ui + coder + sql + webapp-testing.
- **Minimal first slice if Kouros wants to see value fast:** P0+P1+P2 (supplier side only — surface exists, smaller). Customer side (P3) is the larger lift because there is no customer card today.

## DIVERGENCE FLAGS to watch
- **Don't hijack `doc_email_messages`** for discussions — order-scoped, different fact. Separate tables; optional cross-link only.
- **Don't extend `ref_clients`** (thin legacy) — customer identity = ref_customers.
- **Don't create a second document store** — email attachments go in doc_files (one fact, one table).
- **Never auto-link a thread to an entity FK on a fuzzy/domain match** — revenue/identity-adjacent; exact-or-pin or → review. Domain match = suggestion only.
- **FK types exact:** supplier FKs INT UNSIGNED→ref_suppliers.id; customer FKs INT UNSIGNED→ref_customers.id; doc FK BIGINT UNSIGNED→doc_files.id (NEVER the UUID).
- **Non-fiscal:** never wire any comm_* read into a COGS/COP/WAC/BOM/beer-tax path; schema_meta + code comments enforce.
- **CSS/JS separated** (/public/css, /public/js); sanitize HTML email bodies before render (XSS — emails are untrusted input). HTML email render across the timeline = the `ui` skill's HTML-email-rendering territory (but here we DISPLAY received HTML, so sanitize hard).
- **Idempotency = Message-ID-based**, not content-hash (replies/edits false-merge otherwise) — same ruling as the order arc.

## OVERLAPS with existing arcs (so we don't duplicate)
- **email-order-ingestion-arc** — shares the Gmail DWD auth + the Python-connector + the per-message dedup discipline. REUSE the auth; the ORDER tables/parsers are NOT reused (different fact). When building P0, read that arc for the exact env/SA/impersonation mechanics.
- **supplier-evaluation-arc (EF-01, now ~shipped)** — provides the fiche-tab + entity-event + doc-tied-to-supplier precedents (supplier_evaluations, supplier_cert_documents). Clone these shapes. (NB: the index supplier-evaluation line is stale — those tables now EXIST; correction recorded in the index header.)
- **ingestion-validation-gate-arc / doc_files model** — the document store + the doc_files PK-vs-UUID discipline.
- **sales-commercial-surface** — ref_customers canonical; the customer side currently has no card (P3 must build it).
- **RE1 (BC sales ledger) / RE2 (Shopify fulfilment)** — NOT overlapping; those are order/fiscal flows. This is a CRM/correspondence layer beside them.

## OPEN QUESTIONS for Kouros (put these before P0)
1. **Which mailbox(es)?** Just `kouros@`, or also `production@` / `commandes@` / `info@` / `achats@`? (The connector can read a set; de-dup by Message-ID. Most supplier/customer mail probably isn't in kouros@ personal.)
2. **Read-only, or also SEND from maltyweb?** v1 PM lean = read + capture + manual-note only (no outbound send from the app). Sending email from maltyweb is a much bigger surface (compose UI, deliverability, From-identity). Confirm v1 = capture-only.
3. **History depth:** import the last N days/months on first run, or only go-forward from activation? (Gmail query `newer_than:` — order arc uses 30d.)
4. **Customer card:** OK to create a brand-new customer fiche page (P3), or should the customer timeline live grafted into an existing surface (e.g. a panel on expeditions/sales)? This decides the size of the client side.
5. **Manual log entries:** do you want operators to add call-notes / non-email entries into the same timeline (source='manual')? (Recommended — makes it a real interaction log, not just mail.)
6. **Auto-summary of "what is discussed":** raw thread only (v1, no LLM), or eventually a summarized "topic" per thread? (Default raw; summary = separate gated decision.)
7. **Privacy/scope:** kouros@ is a PERSONAL mailbox — it will contain non-supplier/non-customer mail (personal, internal, newsletters). The matcher routes only entity-resolvable mail into cards; everything else is ignored (not stored, or stored unlinked-and-pruned?). Confirm we DON'T want personal mail persisted. PM lean: only persist messages that resolve (or land in the review bucket for a plausible entity); drop the rest unstored.

## RECOMMENDATION (short)
Build supplier-side first (P0→P1→P2) — the fiche surface already exists, it's the cheaper proof. Reuse the live Gmail DWD substrate; new separate poller + comm_* tables (don't touch doc_email_messages); attachments → doc_files; entity match = pin→exact→review, never fuzzy-auto-link. Render = one ordered timeline keyed on sent_at with docs interleaved via their parent message's timestamp. Customer side (P3) is a follow-on that requires building a customer fiche first. Get the 7 OPEN QUESTIONS answered before P0 — especially #1 (which mailboxes) and #4 (customer card surface), which size the whole build.
