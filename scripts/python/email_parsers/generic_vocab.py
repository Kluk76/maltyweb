"""
generic_vocab.py — Layer-2 generic vocabulary body parser.

Registered LAST in the dispatcher registry.  Fires when:
  • No per-sender parser matched (universal last-resort), OR
  • A per-sender parser matched but declined (returned None).

Design principles
─────────────────
• Deterministic.  NO LLM — ever.  Same input always produces the same output.
• Vocabulary is a LIVE read from canonical tables (ref_skus, ref_recipes,
  ref_beer_types, ref_recipe_aliases, ref_sku_aliases) loaded ONCE per run by
  the poller and passed in via ParserEnv.  No hardcoded beer-name list — that
  would drift from the SKU master.  (canonical-list-call-not-copy rule.)
• Matching: normalise token sets, then difflib-based token-sort ratio ≥ 0.85.
  rapidfuzz is not installed on this stack; difflib.SequenceMatcher is stdlib.
• Conservative confidence gate: emit a ParsedOrder ONLY for lines with a known
  product AND an unambiguous quantity.  Ambiguous fragments go to notes.
  If zero confident lines → return None (decline → no_match → review bucket).

Vocabulary sources (load_vocab)
───────────────────────────────
  ref_skus.sku_code          → sku_code hint
  ref_skus.beer_raw          → beer_raw hint (more human-readable)
  ref_recipes.name           → canonical recipe name
  ref_recipes.recipe_short_name → short name where set
  ref_beer_types.beer_name   → canonical beer type name
  ref_beer_types.aliases     → pipe-separated alias list
  ref_recipe_aliases.alias   → recipe alias
  ref_sku_aliases.alias      → SKU alias

Unit grammar (FR/DE)
────────────────────
  keg/fût:       fût, fûts, fut, futs, keg, kegs, fass, fässer
  carton:        carton, cartons, karton, kartons
  bottle:        bouteille, bouteilles, flasche, flaschen, bot, bots
  can:           canette, canettes, cannette, cannettes, can, cans, dose, dosen
  pack:          pack, packs, 6-pack, 4-pack, pack6, pack4
  palette:       palette, paletten, pallet

Date grammar (FR, day-first by default)
────────────────────────────────────────
  FR weekday: lundi, mardi, mercredi, jeudi, vendredi
              + prochain / prochaine / qui suit
  dd/mm or dd.mm (env.dayfirst True = day first)
  Relative: "cette semaine", "semaine prochaine" → None (no date committed)
"""

from __future__ import annotations

import difflib
import re
import unicodedata
from dataclasses import dataclass
from datetime import date, timedelta
from typing import Any

import pymysql
import pymysql.cursors

from .base import (
    EmailContext,
    ParsedLine,
    ParsedOrder,
    ParserEnv,
    SenderParser,
    VocabIndex,
)

# ── Fuzzy matching threshold ───────────────────────────────────────────────────

_FUZZY_THRESHOLD = 0.85  # token-sort ratio; must be ≥ this to count as a match


# ── Text normalisation helpers ─────────────────────────────────────────────────

def _normalise(text: str) -> str:
    """
    Normalise text for fuzzy matching:
      1. NFKC Unicode normalisation (folds ligatures, nbsps, composed accents).
      2. Lowercase.
      3. Strip accents (é→e, ü→u, etc.) so FR/DE spellings collapse.
      4. Collapse whitespace.
    """
    # NFKC first: collapses ligatures, full-width chars, decomposes accents
    text = unicodedata.normalize("NFKC", text)
    text = text.lower()
    # Strip combining marks (accents) after decomposition
    text = unicodedata.normalize("NFD", text)
    text = "".join(c for c in text if unicodedata.category(c) != "Mn")
    text = unicodedata.normalize("NFC", text)
    # Collapse punctuation/hyphens to space
    text = re.sub(r"[-_/|]+", " ", text)
    # Collapse whitespace
    text = re.sub(r"\s+", " ", text).strip()
    return text


def _token_sort_ratio(a: str, b: str) -> float:
    """
    Compute a token-sort similarity ratio in [0, 1] using stdlib difflib.

    Token-sort = sort the tokens of each string alphabetically, then measure
    similarity.  This handles word-order variation (e.g. "Zepp Blonde" vs
    "Blonde Zepp") which is common in informal email orders.

    Both strings should already be normalised (lowercase, stripped accents).
    """
    a_sorted = " ".join(sorted(a.split()))
    b_sorted = " ".join(sorted(b.split()))
    return difflib.SequenceMatcher(None, a_sorted, b_sorted).ratio()


def _best_match(query: str, vocab: VocabIndex) -> tuple[str, float] | None:
    """
    Find the best-matching canonical hint for a normalised query string.

    Returns (canonical_hint, ratio) if ratio ≥ _FUZZY_THRESHOLD, else None.
    Uses exact lookup first (O(1)), then falls through to fuzzy scan.
    """
    norm_query = _normalise(query)
    if not norm_query:
        return None

    # Exact lookup first
    if norm_query in vocab.lookup:
        return (vocab.lookup[norm_query], 1.0)

    # Fuzzy scan
    best_hint: str | None = None
    best_ratio: float = 0.0
    for norm_term, hint in vocab.terms:
        ratio = _token_sort_ratio(norm_query, norm_term)
        if ratio > best_ratio:
            best_ratio = ratio
            best_hint = hint

    if best_ratio >= _FUZZY_THRESHOLD and best_hint is not None:
        return (best_hint, best_ratio)
    return None


# ── Vocabulary loader ──────────────────────────────────────────────────────────

def load_vocab(conn: Any) -> VocabIndex:
    """
    Build a VocabIndex from canonical product tables.

    Called ONCE per run by the poller (ingest_email_orders.py) using the DB
    connection.  Result stored in ParserEnv.vocab and passed to every parse call.

    Tables read (READ-ONLY — no writes):
      ref_skus       : sku_code, beer_raw (active SKUs)
      ref_recipes    : name, recipe_short_name (active recipes)
      ref_beer_types : beer_name, aliases (all types)
      ref_recipe_aliases : alias (all)
      ref_sku_aliases    : alias (all, joined to sku_code)
    """
    terms: list[tuple[str, str]] = []   # (normalised_term, canonical_hint)
    seen_norms: set[str] = set()

    def _add(raw: str, canonical: str) -> None:
        """Add one term→hint pair, deduplicating by normalised term."""
        if not raw or not canonical:
            return
        norm = _normalise(raw)
        if not norm or norm in seen_norms:
            return
        seen_norms.add(norm)
        terms.append((norm, canonical))

    with conn.cursor(pymysql.cursors.DictCursor) as c:

        # ref_skus: sku_code is the canonical hint for active SKUs
        c.execute(
            "SELECT sku_code, beer_raw FROM ref_skus WHERE is_active = 1"
        )
        for row in c.fetchall():
            sku_code = (row.get("sku_code") or "").strip()
            beer_raw = (row.get("beer_raw") or "").strip()
            if sku_code:
                _add(sku_code, sku_code)   # exact code
            if beer_raw and sku_code:
                _add(beer_raw, sku_code)   # human name → sku_code hint

        # ref_recipes: recipe name → name as canonical hint
        c.execute(
            "SELECT name, recipe_short_name FROM ref_recipes WHERE is_active = 1"
        )
        for row in c.fetchall():
            name  = (row.get("name") or "").strip()
            short = (row.get("recipe_short_name") or "").strip()
            if name:
                _add(name, name)
            if short and name:
                _add(short, name)   # short name → full name as hint

        # ref_beer_types: beer_name + pipe-separated aliases
        c.execute("SELECT beer_name, aliases FROM ref_beer_types")
        for row in c.fetchall():
            beer_name = (row.get("beer_name") or "").strip()
            aliases_raw = (row.get("aliases") or "").strip()
            if beer_name:
                _add(beer_name, beer_name)
                if aliases_raw:
                    for alias in aliases_raw.split("|"):
                        alias = alias.strip()
                        if alias:
                            _add(alias, beer_name)

        # ref_recipe_aliases: alias → recipe name (via JOIN)
        c.execute(
            """
            SELECT ra.alias, r.name AS recipe_name
              FROM ref_recipe_aliases ra
              JOIN ref_recipes r ON r.id = ra.recipe_id
             WHERE r.is_active = 1
            """
        )
        for row in c.fetchall():
            alias = (row.get("alias") or "").strip()
            recipe_name = (row.get("recipe_name") or "").strip()
            if alias and recipe_name:
                _add(alias, recipe_name)

        # ref_sku_aliases: alias → sku_code (via JOIN)
        c.execute(
            """
            SELECT sa.alias, rs.sku_code
              FROM ref_sku_aliases sa
              JOIN ref_skus rs ON rs.id = sa.canonical_sku_id
             WHERE rs.is_active = 1
            """
        )
        for row in c.fetchall():
            alias    = (row.get("alias") or "").strip()
            sku_code = (row.get("sku_code") or "").strip()
            if alias and sku_code:
                _add(alias, sku_code)

    # Build the exact lookup dict from the accumulated terms
    lookup = {norm: hint for norm, hint in terms}
    return VocabIndex(terms=terms, lookup=lookup)


# ── Unit patterns ──────────────────────────────────────────────────────────────

# Maps normalised unit token → canonical unit label stored in ParsedLine.sku_hint
# (appended for context; not a resolved unit — hint only)
_UNIT_CANON: dict[str, str] = {
    # keg / fût
    "fut":      "fût",
    "futs":     "fût",
    "fût":      "fût",
    "fûts":     "fût",
    "keg":      "fût",
    "kegs":     "fût",
    "fass":     "fût",
    "fässer":   "fût",
    # carton / case
    "carton":   "carton",
    "cartons":  "carton",
    "karton":   "carton",
    "kartons":  "carton",
    # bottle
    "bouteille":  "bouteille",
    "bouteilles": "bouteille",
    "flasche":    "bouteille",
    "flaschen":   "bouteille",
    "bot":        "bouteille",
    "bots":       "bouteille",
    # can
    "canette":    "can",
    "canettes":   "can",
    "cannette":   "can",
    "cannettes":  "can",
    "can":        "can",
    "cans":       "can",
    "dose":       "can",
    "dosen":      "can",
    # pack
    "pack":       "pack",
    "packs":      "pack",
    "6-pack":     "pack",
    "4-pack":     "pack",
    "pack6":      "pack",
    "pack4":      "pack",
    # palette
    "palette":    "palette",
    "paletten":   "palette",
    "pallet":     "palette",
}

# Regex: unit keywords (order matters — longer before shorter to prevent partial)
_UNIT_PATTERN = re.compile(
    r"\b("
    + "|".join(re.escape(k) for k in sorted(_UNIT_CANON, key=len, reverse=True))
    + r")\b",
    re.IGNORECASE,
)

# Regex: a number (integer or decimal with period or comma)
_NUM_RE = re.compile(r"\b(\d+(?:[.,]\d+)?)\b")

# Regex: volume suffix like 20L / 30HL / 5HL (often after SKU code in email)
_VOL_RE = re.compile(r"\b(\d+)\s*(?:HL|L)\b", re.IGNORECASE)


# ── Date parsing ───────────────────────────────────────────────────────────────

# French weekday names → Python weekday int (0=Monday)
_FR_WEEKDAY: dict[str, int] = {
    "lundi":    0,
    "mardi":    1,
    "mercredi": 2,
    "jeudi":    3,
    "vendredi": 4,
    "samedi":   5,
    "dimanche": 6,
}

_FR_WEEKDAY_RE = re.compile(
    r"\b(" + "|".join(_FR_WEEKDAY) + r")\s*(?:prochain|prochaine|qui suit)?\b",
    re.IGNORECASE,
)

# dd/mm or dd.mm patterns (no year — resolve relative to received_at month)
_DATE_DDMM_RE = re.compile(
    r"\b(\d{1,2})[./](\d{1,2})(?:[./](\d{2,4}))?\b"
)

# Relative phrases that mean "this week" or "next week" → ambiguous, return None
_RELATIVE_WEEK_RE = re.compile(
    r"\bcette\s+semaine\b|\bsemaine\s+prochaine\b",
    re.IGNORECASE,
)


def _parse_date(text: str, reference: date | None, dayfirst: bool) -> date | None:
    """
    Extract a requested-delivery date from email body text.

    Priority order:
      1. dd/mm or dd.mm or dd/mm/yyyy pattern
      2. French weekday (lundi, jeudi prochain, etc.) → resolved relative to
         `reference` date (email received_at or today)
      3. Relative week phrases → return None (ambiguous, don't commit)
      4. No date found → return None

    dayfirst=True (Swiss default): dd/mm — first number is day.
    """
    today = reference or date.today()

    # Try explicit date pattern dd/mm or dd.mm[/yyyy]
    m = _DATE_DDMM_RE.search(text)
    if m:
        n1, n2 = int(m.group(1)), int(m.group(2))
        yr_raw = m.group(3)
        if yr_raw:
            yr = int(yr_raw)
            if yr < 100:
                yr += 2000
        else:
            yr = today.year
        try:
            if dayfirst:
                d = date(yr, n2, n1)  # dd/mm
            else:
                d = date(yr, n1, n2)  # mm/dd
            return d
        except ValueError:
            # Swap and retry (handles ambiguous dates like 06/05)
            try:
                if dayfirst:
                    d = date(yr, n1, n2)
                else:
                    d = date(yr, n2, n1)
                return d
            except ValueError:
                pass

    # Try French weekday
    wm = _FR_WEEKDAY_RE.search(text)
    if wm:
        target_wd = _FR_WEEKDAY[wm.group(1).lower()]
        prochain_words = ["prochain", "prochaine", "qui suit"]
        is_next = any(w in (wm.group(0) or "").lower() for w in prochain_words)
        # Calculate next occurrence of target weekday from today
        current_wd = today.weekday()
        delta = (target_wd - current_wd) % 7
        if delta == 0:
            delta = 7  # "jeudi" when today is Thursday → next Thursday
        if is_next and delta <= 7:
            delta += 7  # "jeudi prochain" → the Thursday AFTER the coming one
        return today + timedelta(days=delta)

    # Relative week → ambiguous
    if _RELATIVE_WEEK_RE.search(text):
        return None

    return None


# ── Line extraction ────────────────────────────────────────────────────────────

def _extract_num(s: str) -> float | None:
    """Extract a number from a string, handling FR decimal comma."""
    s = s.strip().replace(",", ".")
    try:
        return float(s)
    except ValueError:
        return None


# Patterns for "Nqty unit [de/von/of] product" or "product : Nqty" or "Nqty x product"
# We scan the body for unit keywords and look for an adjacent qty + product token.

def _scan_lines(body: str, vocab: VocabIndex) -> tuple[list[ParsedLine], list[str]]:
    """
    Scan the email body for product + quantity mentions.

    Returns:
      confident_lines : list[ParsedLine] — lines with product AND qty resolved
      dropped_frags   : list[str]        — ambiguous fragments (product seen but
                        qty missing/ambiguous, or vice versa) for notes
    """
    confident: list[ParsedLine] = []
    dropped:   list[str] = []

    # Split body into logical sentences/clauses — split on newline, '.', ';', ','
    # but NOT on decimal points (protected by the num regex below).
    # Strategy: work sentence by sentence to keep product+qty co-located.
    # Also process the full body for patterns that span punctuation.

    # We'll try two passes:
    #   Pass 1: line-by-line (most reliable for structured orders)
    #   Pass 2: full body for "product: qty" patterns spanning punctuation

    already_matched: set[str] = set()  # avoid double-counting

    def _try_line(segment: str) -> None:
        """Try to extract a product + qty from one text segment."""
        norm_seg = _normalise(segment)
        if not norm_seg:
            return

        # Find unit keyword(s) in segment
        unit_matches = list(_UNIT_PATTERN.finditer(segment))
        # Find numbers in segment
        num_matches  = list(_NUM_RE.finditer(segment))

        # Find all candidate product spans in this segment via fuzzy vocab match
        # We slide a window over the segment words and look for matches.
        words = norm_seg.split()

        best_product: str | None = None
        best_ratio: float = 0.0

        # Try 1, 2, 3-word ngrams against vocab
        for n in (3, 2, 1):
            for i in range(len(words) - n + 1):
                candidate = " ".join(words[i:i+n])
                m = _best_match(candidate, vocab)
                if m and m[1] > best_ratio:
                    best_ratio = m[1]
                    best_product = m[0]

        if best_product is None:
            return  # No recognisable product in this segment

        dedup_key = best_product  # dedup on product name: first confident match wins
        if dedup_key in already_matched:
            return

        # Find the qty:
        # Primary: look for a number adjacent to a unit keyword
        qty: float | None = None
        raw_qty: str = ""

        if unit_matches and num_matches:
            # Find num closest (in character position) to any unit keyword
            for um in unit_matches:
                u_pos = um.start()
                best_dist = float("inf")
                for nm in num_matches:
                    dist = abs(nm.start() - u_pos)
                    if dist < best_dist:
                        best_dist = dist
                        raw_qty = nm.group(0)
                qty = _extract_num(raw_qty)
                if qty is not None:
                    break
        elif num_matches:
            # No unit found — use the first/only number if there's exactly one
            # (multiple numbers without a unit = ambiguous)
            if len(num_matches) == 1:
                raw_qty = num_matches[0].group(0)
                qty = _extract_num(raw_qty)

        if qty is None or qty <= 0:
            # Product found but qty is missing/ambiguous.
            # Only report as a dropped fragment when the segment contains a unit
            # keyword (suggests a real order line) — prevents signature/greeting
            # lines from polluting the notes.
            if unit_matches:
                dropped.append(f"[no qty] {segment[:80].strip()}")
            return

        # Determine unit label for the hint (optional context)
        unit_label = ""
        if unit_matches:
            raw_unit = unit_matches[0].group(0).lower()
            # normalise unit token for lookup
            unit_norm = _normalise(raw_unit)
            unit_label = _UNIT_CANON.get(unit_norm, "") or _UNIT_CANON.get(raw_unit, "")

        sku_hint = best_product
        if unit_label:
            sku_hint = f"{best_product} [{unit_label}]"

        confident.append(ParsedLine(
            sku_hint=sku_hint,
            qty=qty,
            raw=segment.strip()[:120],
        ))
        already_matched.add(dedup_key)  # product-level dedup: skip later occurrences

    # Pass 1: process line by line
    for line in body.splitlines():
        line = line.strip()
        if line:
            _try_line(line)

    # Pass 2: also scan comma/semicolon-delimited clauses for cross-punctuation patterns
    # like "2 fûts de Zepp, 3 cartons de Diversion"
    for clause in re.split(r"[,;]+", body):
        clause = clause.strip()
        if clause and len(clause) > 5:
            _try_line(clause)

    return confident, dropped


# ── Parser class ───────────────────────────────────────────────────────────────

class GenericVocabParser(SenderParser):
    """
    Layer-2 generic vocabulary body parser.

    Always registered LAST.  Fires when no per-sender parser matched or when
    a per-sender parser declined (returned None from parse()).

    matches():
      - Returns True  when env.vocab is available (we have a product vocabulary).
      - Returns False when env.vocab is None (offline / DB unreachable) → the
        message lands as no_match, which is the correct graceful degradation.

    parse():
      - Scans the email body for product tokens from the live vocabulary.
      - For each product token found, extracts the adjacent quantity + unit via
        deterministic grammar patterns (FR/DE unit keywords).
      - Returns a ParsedOrder with HINTS if ≥1 confident (product + qty) line found.
      - Returns None (DECLINE) if no confident lines found → no_match → review.
      - Ambiguous fragments (product found, qty missing) go into notes so the
        operator sees what was in the email.

    NO LLM — ever.
    Vocabulary is from live DB, loaded once per run — no hardcoded beer names.
    """

    name = "generic_vocab"

    def matches(self, ctx: EmailContext, env: ParserEnv) -> bool:
        """
        Match when vocab is available.  When env.vocab is None (DB unreachable),
        return False so the message gracefully lands as no_match.
        """
        return env.vocab is not None

    def parse(self, ctx: EmailContext, env: ParserEnv) -> ParsedOrder | None:
        """
        Extract order hints from the email body using the canonical product vocab.

        Returns ParsedOrder with ≥1 confident line, or None (decline) if the body
        contains no recognisable product+qty pairs.
        """
        if env.vocab is None:
            return None  # should not reach here (matches() guards), but be safe

        # Prefer plain text; fall back to HTML stripped of tags
        body = ctx.body_text
        if not body and ctx.body_html:
            body = re.sub(r"<[^>]+>", " ", ctx.body_html)
            body = re.sub(r"\s+", " ", body)
        body = body or ""

        if not body.strip():
            return None

        # Extract date from body
        reference: date | None = None
        if ctx.received_at is not None:
            try:
                reference = ctx.received_at.date()  # type: ignore[attr-defined]
            except AttributeError:
                try:
                    reference = date.fromisoformat(str(ctx.received_at)[:10])
                except Exception:
                    reference = None

        requested_date = _parse_date(body, reference, env.dayfirst)

        # Extract confident lines and dropped fragments
        confident_lines, dropped_frags = _scan_lines(body, env.vocab)

        if not confident_lines:
            # No product+qty pairs found → decline → no_match
            return None

        # Customer hint: From display name or address
        customer_hint = ctx.from_address or ""

        # Compose notes: dropped fragments + any instructional text
        notes_parts: list[str] = []
        if dropped_frags:
            notes_parts.append(
                "Fragments avec produit identifié mais quantité manquante :\n"
                + "\n".join(f"  • {f}" for f in dropped_frags)
            )

        return ParsedOrder(
            customer_hint=customer_hint,
            requested_date=requested_date,
            lines=confident_lines,
            notes="\n".join(notes_parts),
        )
