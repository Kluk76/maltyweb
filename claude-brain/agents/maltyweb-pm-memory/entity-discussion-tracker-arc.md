# Entity Discussion Tracker — mail + document timeline on supplier AND customer cards

> Read when touching: a chronological mail/document thread on a supplier or customer fiche; `comm_threads`/`comm_messages`/`comm_message_docs`; linking `kouros@lanebuleuse.ch` (or any personal/shared Workspace mailbox) into maltyweb; the customer-fiche surface (does not exist yet); matching an inbound/outbound email to an entity. Companions: [email-order-ingestion-arc.md](email-order-ingestion-arc.md) (Gmail DWD substrate — REUSE the auth, NOT the order tables), [sales-commercial-surface/README.md](sales-commercial-surface/README.md) (ref_customers canonical), [ingestion-validation-gate-arc.md](ingestion-validation-gate-arc.md) (doc_files model).
>
> **STATUS: SUPPLIER-SIDE TWO-WAY HUB COMPLETE + DEPLOYED — P0/P1a/P1b/P2/P2.5/P3-SEND all landed on `/var/www/maltytask` 2026-06-19. NOT git-committed (commit by PATHSPEC pending Kouros go).** Per-file rsync (shared dirty tree, parallel sessions active). **gmail.send grant GRANTED + verified; real self-send proven.** Only OPEN on supplier side: Kouros's authed click-through (real drag-drop + a real send to a supplier). Customer side (P3-cust) still deferred (no customer fiche). The PROPOSED-MODEL / OPEN-QUESTIONS / SEQUENCING sections below are the as-designed record; §AS-BUILT + §P3-SEND AS-BUILT are the authority on what shipped.
>
> **⚠️ MIGRATION NUMBER = 420, NOT 414.** The send-schema delta planned as "mig 414+" landed as **mig 420** `420_comm_send_outbound.sql` — parallel sessions had taken 414-419. (Reinforces the standing rule: re-`migrate.php --status` at build-start; the number leads.)
>
> **AS-BUILT decisions held:** mailbox = `kouros@lanebuleuse.ch` ONLY (v1); capture + manual-notes, NO outbound compose; 180-day backfill (`newer_than:180d`, not 6mo-as-days — same intent); SUPPLIER-FIRST (grafted onto salle-fournisseurs `#sf-fiche`); manual call-notes in the same timeline = YES; NO LLM; privacy = persist ONLY external-counterparty mail that resolves OR lands in the review bucket, drop internal-only + bulk (List-Unsubscribe).

## §AS-BUILT (2026-06-19) — what actually shipped
**P0 (auth gate) — RESOLVED, ZERO operator action.** Probe confirmed the EXISTING Gmail DWD service account (`config/gmail-sa.json`, the same SA the order reader uses) CAN already impersonate `kouros@lanebuleuse.ch` with `gmail.readonly` under the CURRENT domain-wide grant — 201 msgs/7d returned. **No Google Admin change needed; the operator-gate flagged at design time was a non-issue.** (Durable: this SA's DWD grant is domain-wide for gmail.readonly — any @lanebuleuse.ch mailbox is impersonable without a per-user authorization step.)

**P1a — Migration 413** `db/migrations/413_comm_threads_entity_tracker.sql` APPLIED cleanly (was only-pending; clean migrate.php). Tables live + SHOW-CREATE-verified (CHECKs + FKs present):
- `comm_threads` — supplier/customer **XOR CHECK that PERMITS both-NULL** (both-NULL = the review bucket; this DEVIATES from the proposed strict exactly-one — intentional, the review bucket needs an entity-less thread); `gmail_thread_id` UNIQUE. `customer_id_fk` present-but-UNUSED (forward-compat for P3).
- `comm_messages` — `sent_at` = timeline key; `message_id` UNIQUE = idempotency; `source` ENUM('gmail','manual'); `source_email_id_fk` → doc_email_messages **ON DELETE SET NULL**.
- `comm_message_docs` — `doc_file_id_fk` → doc_files.id (BIGINT).
- `comm_address_pins` — `email` UNIQUE → supplier/customer XOR; **both-NULL FORBIDDEN here** (a pin must name an entity, unlike a thread). This is the SOLE email→entity map (see KEY FINDING below).
- 4 `schema_meta` rows (3× source, 1× reference [comm_address_pins]), all corrections_policy=allowed.

**P1b — Connector** `scripts/python/ingest_email_comm.py` (NEW, sibling to ingest_email_orders.py; **order connector + gmail.env UNTOUCHED**). New config `config/gmail-comm.env` on VPS (kouros@ subject, `newer_than:180d`), `config/gmail-comm.env.example` in repo. Reuses the DWD auth. Message-ID dedup. Direction from @lanebuleuse.ch. Thread grouping by Gmail threadId. **Attachments: CLOSES the order-connector byte-fetch gap** — downloads REAL bytes → doc_files (file_hash dedup, row_hash idempotency, real files never symlinks); comm_message_docs references the BIGINT id. NO LLM. `--dry-run` default, `--apply`, `--limit`, `--query`. NO cron (operator enables).
- **Entity resolution = pin → exact → review.** 🔴 **KEY FINDING (durable): `ref_suppliers` has NO email column and `ref_supplier_aliases` has no email — so until `comm_address_pins` is populated, EVERY thread routes to the review bucket. `comm_address_pins` is the SOLE email→entity map.** The triage-assign action seeds pins; the pin set bootstraps over time. (Customer side later CAN exact-match `ref_customers.email`, but supplier side is pin-only by construction.)
- **Privacy filter:** only external-counterparty mail persisted; internal-only + List-Unsubscribe bulk DROPPED (dry-run: 16/20 dropped, 4 to review). Test `--apply` inserted 1 review-bucket thread + 1 message (counterparty `gianmarco@get-thelunchclub.website`).

**P2 — Supplier timeline UI.** New API `public/api/sf-comm-thread.php` (mirrors sf-supplier-evaluation/sf-cert-link: require_login, maltytask_pdo, csrf_verify, log_revision, {ok} JSON):
- GET `?supplier_id` → merged timeline ORDER BY sent_at; GET `?review=1` → admin review-bucket triage list.
- POST `add_note` → manual msg (source=manual, direction=out). POST `assign_thread` → admin: sets supplier_id_fk + `INSERT…ON DUPLICATE` into comm_address_pins so future mail from that address auto-resolves.
- Edited `salle-fournisseurs.js` (`renderDiscussionSection` after renderEvalSection, called in openFiche ~line 584) + `salle-fournisseurs.css` (appended scoped `.sf-disc-*` block, dark aged-oak). Merged render = ONE ordered list, docs inline beneath their message (chips → `/api/document.php?file_id=UUID`), manual-note compose box, admin review-triage strip with supplier picker, **45s live-refresh** (interval cleared on fiche switch — honors live-visibility default).
- **🔴 HTML-email XSS safety: bodies rendered as ESCAPED PLAIN TEXT** (server-side `strip_tags` for html bodies + `escHtml`) — raw HTML NEVER injected. Rich-HTML allowlist DEFERRED.
- Deployed per-file; PHP syntax clean; GET → 302 login redirect (no 500).

## §P3-SEND AS-BUILT (2026-06-19) — outbound reply + attach SHIPPED + DEPLOYED (NOT committed)
**Migration 420** `420_comm_send_outbound.sql` APPLIED: `comm_messages.source` ENUM += `'sent'`; + `send_status ENUM('sent','failed')`, + `send_error VARCHAR(512)`, + `sent_by_user_id INT UNSIGNED` FK→users(id). (Planned as 414; landed as 420 — parallel sessions took 414-419.)

**gmail.send scope GRANTED + verified.** Requesting `[readonly, send]` TOGETHER mints a token impersonating a real operator (alex@→alex@, kouros@→kouros@) → per-user "reply as yourself" send CONFIRMED working. (Durable: the SAME DWD client now carries both readonly + send; request the pair together.)

**Python sender** `scripts/python/send_email_comm.py`: impersonates the operator's OWN @lanebuleuse.ch address; builds multipart/mixed MIME; sets own deterministic Message-ID; In-Reply-To/References from the parent inbound message; **DROPS Gmail threadId (cross-mailbox)** per Ruling A; `--dry-run`; JSON stdin/stdout. Real self-send proven (kouros@→kouros@, returned Message-ID + gmail id, delivered).

**API** `sf-comm-thread.php` gained: GET `?supplier_docs=<id>` (UNION certs + thread-docs + invoices/DNs, deduped); POST `action=send_reply` (admin|manager SERVER-enforced, re-validates current_user email @lanebuleuse.ch, recipient = most-recent inbound from_address, subject Re:, confirm-then-send, writes comm_messages row ONLY on Gmail 2xx with source='sent'/send_status/sent_by_user_id, comm_message_docs direction='out'; NO row on failure, error returned to UI); GET supplier timeline now returns `sent_by` display name → "Envoyé par X".

**Composer UI** in salle-fournisseurs.js/.css: "Répondre par e-mail" box in the conversation pane (DISTINCT from internal-note box — blue `--bbt` vs green `--hop`), gated on (inbound exists AND admin|manager AND @lanebuleuse.ch email; `window.SF_USER_EMAIL` hydrated in salle-fournisseurs.php); read-only recipient display; body textarea; attachments zone; confirm-then-send; errors surfaced; outbound bubbles show "par <name>".

**🔴 ATTACH-PATH FIX + DURABLE LESSON.** A first build of the composer was BROKEN: it routed desktop uploads through `upload-document.php`'s **doc_uploads INGESTION pipeline** + polled `?supplier_docs=` for the UUID — which can NEVER resolve for a generic attachment. REPLACED with a synchronous **`action=attach_upload`** on sf-comm-thread.php: writes real bytes to `/var/www/maltytask/data/email-attachments/YYYY-MM/`, sha256 file_hash dedup, INSERT doc_files (source_folder='email-comm-attach', row_hash, file_size_bytes), returns the **BIGINT doc_file_id immediately**; JS attaches the BIGINT directly (no poll). Verified: real bytes (not symlink), dedup, BIGINT returned, test data cleaned. **DURABLE: email attachments must write STRAIGHT to doc_files, NOT through the doc_uploads/upload-document.php invoice-ingestion pipeline. The supplier-docs PICKER path (returns real BIGINTs) was always fine.** (NB: this SUPERSEDES the §SCOPE-EXPANSION "(1) Fresh desktop uploads → REUSE upload-document.php" plan — that reuse was the bug; the fix is the dedicated attach_upload action.)

**🔴 GIT — 413 SWEPT BY A PARALLEL SESSION (cautionary).** A parallel session ran `git add -A` and swept migration **413 into its commit `bce4e6b "style(expeditions)…"`** — 413 is now in history under a WRONG message. The remaining P3-send files (420, ingest_email_comm.py, send_email_comm.py, gmail-comm.env.example, sf-comm-thread.php, salle-fournisseurs.js/.css/.php) are STILL UNCOMMITTED and at risk of the same sweep. Awaiting Kouros's go to commit by explicit pathspec. (Live proof of the standing "commit by PATHSPEC, never `git add -A`" rule — a foreign session's `-A` ate our migration.)

### §P3-SEND still OPEN (as-built)
- Kouros's full authed click-through (real in-session drag-drop + a real send to a SUPPLIER — backend self-send already proven).
- Commit by PATHSPEC (pending Kouros go) — name the 8 files; never `-A`.
- **Bounces invisible under write-on-2xx** (known v1 gap — 2xx = accepted-for-send, not delivered).
- 2 users with blank emails can't send (UI gated + server enforced — by design).
- Customer side (P3-cust) still deferred (no customer fiche).

## §SCOPE EXPANSION (2026-06-19) — TWO-WAY COMMS HUB (Kouros wants SEND + drag-drop attach)
Kouros looked at the layout options and, rather than pick one, ADDED a requirement: **operators reply to suppliers by email FROM within maltyweb**, with **drag-and-drop documents to attach** (especially docs we previously generated FOR the supplier). This forces a real composer + attachment zone → forces **Layout = Option A (tabbed fiche: Fiche / Évaluation / Discussions, two-pane inbox inside Discussions).** PM CONFIRMED Option A (only layout with composer room). Planning only; not yet built.

**Layout — CONFIRMED Option A, tabbed fiche.** Per the frontend-design skill: TabStrip→Panel nesting with state-class visibility (`.is-active`, NOT `[hidden]`). Refactor `openFiche` in salle-fournisseurs.js to render 3 tabs; fold the EXISTING governance/GL block into "Fiche", the just-built supplier-eval block into "Évaluation" (no logic change — pure re-parent into a panel), Discussions tab holds a two-pane inbox (thread list left, conversation+composer right). New scoped component `.sf-disc-*` reusing existing dark-oak tokens. ⚠️ salle-fournisseurs.* currently CLEAN at HEAD — tabbing the eval block risks the supplier-eval arc's parallel session; verify clean at build-start, single-owner the two shared files.

**SEND ARCHITECTURE — DECISIONS LOCKED (Kouros, 2026-06-19). Per-user own-identity send.**
- **🔒 Send-as identity = the LOGGED-IN OPERATOR'S OWN @lanebuleuse.ch address.** "Whoever is connected responds with his own email." NOT a shared/role box. → the send path sets the Gmail DWD `subject` = `current_user()['email']` (impersonate the connected operator), NOT kouros@. Read side STAYS on kouros@ (the captured mailbox); only SEND uses the operator's own identity. The DWD grant is domain-wide for gmail.send, so the SAME SA impersonates any @lanebuleuse.ch user for send — no per-user grant.
  - **`users.email` is the send identity source** (PM-verified: column EXISTS; 14/16 users have @lanebuleuse.ch, 2 BLANK). **GATE: send UI must gracefully DISABLE/HIDE for an operator whose `users.email` is blank or non-@lanebuleuse.ch, with a clear reason** ("Pas d'adresse @lanebuleuse.ch configurée — contactez un admin"), never an obscure 403/500. Validate domain server-side in the send handler too (defense in depth — never trust the client).
- **🔒 Who can send: ADMINS + MANAGERS** (not operators). Send handler role-gates admin|manager; capture/read stays as-built (admin-triage + manager-read).
- **🔒 Confirm-then-send:** write the comm_messages row ONLY on Gmail 2xx; failures surface to the UI ([[feedback_ingest_failures_must_surface]] applies to SEND).
- **Scope gate (the ONE Google-Admin action):** current DWD grant is `gmail.readonly` ONLY. Sending needs **`https://www.googleapis.com/auth/gmail.send`** appended to the SAME domain-wide-delegation client (Security → API controls → Domain-wide delegation → edit client ID for SA `maltytask-gmail-reader@kouros-infrastructure...` → append `gmail.send` to the comma-sep scope list; same client, same SA, no new SA). Domain-wide ⇒ covers impersonating ANY @lanebuleuse.ch user for send (per-user OWN-identity send works with zero per-user grant). **Verify-probe from VPS:** impersonate a real operator address (e.g. alex@) with scope `gmail.send` and call `users.getProfile` (or send a trivial self-addressed message) — 403 `unauthorized_client`/"not authorized for any of the scopes" = grant NOT live; success = granted. Probe is sent to Kouros; awaiting his grant + verify before P3-send build.
- **Send path:** Gmail API `users.messages.send`, `subject`=operator email. PHP cannot call the Python Gmail client directly → PM lean = a Python sender `scripts/python/send_email_comm.py` (sibling to ingest_email_comm.py), called by sf-comm-thread.php's new `send_reply` action via shell-exec with ABSOLUTE python path (empty-PATH rule). The operator email is passed as the impersonation subject arg.
- **Reply threading — RFC-headers, DROP cross-mailbox threadId (see Ruling A):** set outbound MIME `In-Reply-To:` = original Message-ID and chain `References:`. **Do NOT pass Gmail `threadId` on cross-mailbox sends** (the stored `gmail_thread_id` is a KOUROS@-mailbox id; it does not exist in the operator's mailbox → 404/wrong-thread). Set threadId ONLY in the degenerate case sender==captured-mailbox. Our own timeline threads by `thread_id_fk` (INSERT the out row into the same comm_threads row) — unaffected by the dropped param. Supplier-side nesting is carried by In-Reply-To/References, which works regardless of sending mailbox.
- **Capturing the sent copy (see Ruling B):** write-on-2xx is the SINGLE source of the outbound comm_messages row. The sent mail lands in the OPERATOR's Sent folder, NOT kouros@'s mailbox capture → no Sent-folder re-read in v1. `message_id` UNIQUE protects against any future double-capture if more mailboxes are read.
- **SCHEMA DELTA = mig 414+ (re-`--status`; next-free is whatever --status shows):** add `'sent'` to comm_messages.source ENUM (`('gmail','manual')` → `('gmail','manual','sent')`); add `send_status ENUM('sent','failed') NULL` + `send_error VARCHAR(512) NULL` for failure visibility; add **`sent_by_user_id INT UNSIGNED NULL` → users.id** (who sent it — required now that send identity is per-operator; for audit + showing "Envoyé par X" in the timeline) and persist the operator's `from_address` on the row. Minimal — no new table. (`direction='out'` already exists.)
- **Direct-send vs draft-for-review:** LOCKED confirm-then-send (no manager-approval draft state).

**DRAG-DROP DOCUMENT ATTACH — sources LOCKED = ALL FOUR (Kouros, 2026-06-19):**
fresh desktop uploads + supplier certs/spec sheets + supplier invoices/DNs + docs from this supplier's prior threads. (The "docs-generated-FOR-supplier" corpus does NOT exist — confirmed + surfaced to Kouros; not in scope.)
- **(1) Fresh desktop uploads (drag-drop):** REUSE `public/api/upload-document.php` (require_login + csrf_verify + shared tmp-validation → doc_files with file_hash dedup + real bytes). Drag-drop zone POSTs multipart → upload-document.php → doc_files.id → attach.
- **(2) Supplier certs/spec sheets:** `supplier_cert_documents.doc_file_id_fk` by supplier_id.
- **(3) Supplier invoices/DNs:** doc_files tied to the supplier via inv_deliveries→doc_files.
- **(4) Docs from this supplier's prior threads:** doc_files referenced by comm_message_docs on that supplier's comm_threads.
- → Build a "Documents du fournisseur" picker that UNIONs (2)+(3)+(4) by supplier_id, alongside the drag-drop zone for (1). Picker + dropzone feed one selected-attachments list that the send call materializes.
- **(b) New upload / drag-drop from desktop:** REUSE `public/api/upload-document.php` (verified: require_login + csrf_verify + shared tmp-validation step returning {tmp_path, mime, ext, orig_name, byte_size}, then writes doc_files with file_hash dedup + real bytes). Drag-drop zone POSTs multipart → upload-document.php → doc_files.id → attach to the outbound message → on send, INSERT comm_message_docs direction='out'.
- **Gmail attachment mechanics:** the send call builds a MIME **multipart/mixed** — text/html body part + one base64 part per attachment (read doc_files.local_path bytes on the VPS) — then `users.messages.send` with the raw RFC822. The Python sender reads each doc_files row's local_path and appends the part.

**REVISED SEQUENCING (capture v1 is live):**
- **P2.5 — tabbed-fiche layout refactor — 🔵 BUILDING NOW (background Sonnet agent, pure UI, NO new scope).** Convert openFiche to TabStrip→Panel, fold Fiche/Évaluation/Discussions, move the existing `.sf-disc-*` timeline into the Discussions tab's two-pane shell (still read+note only). Safe to ship WHILE the gmail.send grant is being processed — zero send capability. ⚠️ touches the two shared files (salle-fournisseurs.js/.css) — single-owner during this build vs the supplier-eval parallel session.
- **gmail.send scope:** ✅ GRANTED + verified (see §P3-SEND AS-BUILT).
- **P3-send — ✅ SHIPPED + DEPLOYED 2026-06-19 (mig 420, send_email_comm.py, sf-comm-thread.php send_reply + attach_upload, composer UI). See §P3-SEND AS-BUILT above for the authority record + the attach-path fix.** Note: the "drag-drop = reuse upload-document.php" plan below was the BUG — the fix is a dedicated `attach_upload` action writing straight to doc_files.
- Customer side (P3-cust, the original P3) still the bigger lift, still deferred.

**OPERATOR DECISIONS — ALL LOCKED (Kouros, 2026-06-19):**
1. **Send-as identity** — 🔒 the LOGGED-IN OPERATOR'S OWN @lanebuleuse.ch address (per-user, off `users.email`). NOT a role box.
2. **Direct-send vs draft-for-review** — 🔒 confirm-then-send (row on 2xx).
3. **Drag-drop doc sources** — 🔒 all four (desktop upload + certs + invoices/DNs + prior-thread docs); generated-for-supplier corpus confirmed nonexistent.
4. **Who can send** — 🔒 admins + managers.
5. **Which mailbox sends** — 🔒 each sender's OWN mailbox (read still kouros@-only). Cross-mailbox threading handled via RFC headers (Ruling A).

### Still OPEN / deferred (as-built)
- **NOT git-committed** — Opus commits at milestone; parallel session shares the dirty tree → commit by PATHSPEC (the four new files: `db/migrations/413_*.sql`, `scripts/python/ingest_email_comm.py`, `config/gmail-comm.env.example`, `public/api/sf-comm-thread.php`; plus the two edited shared files `public/js/salle-fournisseurs.js` + `public/css/salle-fournisseurs.css` — name them, don't `-A`).
- **Authed UI click-through needs KOUROS** (headless can't drive the logged-in Tailscale-gated session): open a supplier fiche → Discussions section; add-note; review-triage assign (test thread id=1 → pick supplier → Rattacher → verify a comm_address_pins row was created); confirm the 45s poll.
- **Cron DISABLED** — operator enables `ingest_email_comm.py --apply` when ready. First real runs fill the review bucket (no pins exist yet); triage builds the pin set.
- **Deferred:** outbound send; rich-HTML rendering (allowlist); LLM topic summary; multi-mailbox (achats@/info@/production@/commandes@); >6mo backfill.
- **Customer side = P3** (schema forward-compatible — customer_id_fk + XOR already in place; needs a NEW customer fiche surface, the bigger lift — see §P3 in SEQUENCING).

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
