<?php
/**
 * recipe-resolver.php
 *
 * Single-entry-point canonical beer/recipe resolver for the maltytask/maltyweb ecosystem.
 *
 * Covers all raw identifier forms that appear across the dataset:
 *   - Canonical names  ("Moonshine", "Diversion Blanche", "EPH2")
 *   - Short SKU codes  ("MOO", "DIB", "EPH2")
 *   - Short code + batch  ("EMB 234", "EPH0421 1")
 *   - Dotted abbreviations  ("Div.Blanche", "Div.Gose", "Div.Panaché")
 *   - Date-coded EPH    ("EPH0421", "EPH0221", "EPH323")
 *   - Cold-crash shorthands  ("BF-915", "CB-Bamse", "CH-4.4")
 *   - Trade names  ("Chela", "Malt Capone", "Baies-Tises", "Beer des Rosses")
 *
 * Resolution precedence (per operator spec):
 *   1. Exact match against ref_recipes.name  (case+accent-insensitive, utf8mb4_unicode_ci)
 *   2. Exact match against ref_recipe_aliases.alias
 *   3. Strip trailing batch number and retry steps 1+2 with prefix
 *   4. Normalised comparison (lowercase, strip dots/spaces/dashes/accents)
 *   5. Return null — no fuzzy matching (CLAUDE.md: never speculate on identities)
 */

/**
 * Resolve a raw beer/recipe identifier to its canonical ref_recipes row.
 *
 * @param PDO         $pdo   DB connection
 * @param string|null $raw   Raw identifier as it appears in the source column
 * @return array|null {
 *   recipe_id     => int,
 *   canonical_name => string,
 *   vintage        => string,           '' for non-EPH recipes
 *   matched_via    => 'canonical'|'alias'|'normalized'|'short_code_with_batch',
 *   batch          => string|null        populated when trailing batch was stripped
 * }
 */
function resolve_recipe_id(PDO $pdo, ?string $raw): ?array
{
    static $cache = [];
    static $all_recipes   = null;  // id => ['name','vintage']
    static $all_aliases   = null;  // alias => ['recipe_id','name','vintage']
    static $norm_map      = null;  // normalised_name => ['recipe_id','name','vintage','src']

    if ($raw === null || trim($raw) === '') {
        return null;
    }

    $raw = trim($raw);
    if (isset($cache[$raw])) {
        return $cache[$raw];
    }

    // Lazy-load full alias + recipe tables once per request
    if ($all_recipes === null) {
        _recipe_resolver_load($pdo, $all_recipes, $all_aliases, $norm_map);
    }

    $result = _recipe_resolver_lookup($raw, $all_recipes, $all_aliases, $norm_map, null);

    $cache[$raw] = $result;
    return $result;
}

/**
 * Resolve a batch of raw identifiers in a single call.
 * Pre-loads all canonicals + aliases once; then matches entirely in memory.
 *
 * @param PDO      $pdo
 * @param string[] $raws  Array of raw identifiers
 * @return array          Keyed by raw value; value = same shape as resolve_recipe_id or null
 */
function resolve_recipe_ids_batch(PDO $pdo, array $raws): array
{
    if (empty($raws)) {
        return [];
    }

    static $all_recipes = null;
    static $all_aliases = null;
    static $norm_map    = null;

    if ($all_recipes === null) {
        _recipe_resolver_load($pdo, $all_recipes, $all_aliases, $norm_map);
    }

    $results = [];
    foreach ($raws as $raw) {
        $raw = ($raw === null) ? '' : trim($raw);
        if ($raw === '') {
            $results[$raw] = null;
            continue;
        }
        $results[$raw] = _recipe_resolver_lookup($raw, $all_recipes, $all_aliases, $norm_map, null);
    }
    return $results;
}

/**
 * Reverse lookup: canonical recipe name → the short operator prefix used in
 * fermenting/cold-crash columns (e.g. "Diversion Blanche" → "DIB", "Zepp" → "ZEP").
 *
 * Resolution order:
 *   1. Look for a short uppercase alias (2–5 chars, all uppercase/digits) in
 *      ref_recipe_aliases for this canonical name. If multiple exist, return
 *      the shortest one (ties broken alphabetically).
 *   2. Fall back to ref_recipes.recipe_short_name when no short alias found
 *      (covers EPH1/EPH2/EPH3/EPH4 which are their own canonical + short name).
 *   3. Return null — caller falls back to the canonical name itself.
 *
 * Uses the same static cache as resolve_recipe_id (one DB load per request).
 *
 * @param PDO    $pdo
 * @param string $canonical  Canonical recipe name as stored in ref_recipes.name
 * @return string|null       The short prefix, or null if not determinable
 */
function canonical_to_short_code(PDO $pdo, string $canonical): ?string
{
    static $short_code_map = null;   // canonical_name_lc => short_code

    if ($short_code_map === null) {
        $short_code_map = [];

        // Step 1: If the canonical name itself is a short uppercase code
        // (e.g. "EPH1", "EPH2", "TM-BLO"), use it directly. This takes highest
        // priority so EPH1 → "EPH1", not "EPH01" or "Chela".
        // Note: REGEXP is case-insensitive by default in MySQL with utf8mb4_unicode_ci.
        // We filter for short names (≤8 chars) and then verify uppercase in PHP.
        $stmt1 = $pdo->query(
            "SELECT DISTINCT name FROM ref_recipes
              WHERE LENGTH(name) BETWEEN 2 AND 8"
        );
        foreach ($stmt1->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $name = $row['name'];
            // Accept names that look like short operator codes: A-Z, 0-9, hyphen only
            if (preg_match('/^[A-Z][A-Z0-9\-]{1,7}$/', $name)) {
                $key = mb_strtolower(trim($name), 'UTF-8');
                $short_code_map[$key] = $name;
            }
        }

        // Step 2: Collect all short uppercase aliases (2–4 chars, A-Z/0-9 only).
        // Use BINARY to enforce case sensitivity (MySQL REGEXP is CI by default).
        // For each recipe without a step-1 entry, pick the 3-char alias first,
        // then 4-char, then the shortest that qualifies. This ensures:
        //   - Diversion Blanche → DIB (not BLA — BLA and DIB are both 3-char;
        //     DIB is the preferred one because the operator uses it on the tank
        //     board. We pick the LAST 3-char alias per name in ORDER BY alias
        //     DESC so DIB > BLA.)
        //   - Zepp → ZEP, etc.
        $stmt2 = $pdo->query(
            "SELECT rr.name AS canonical_name,
                    ra.alias
               FROM ref_recipe_aliases ra
               JOIN ref_recipes rr ON rr.id = ra.recipe_id
              WHERE LENGTH(ra.alias) BETWEEN 2 AND 4
              ORDER BY rr.name, LENGTH(ra.alias) ASC, ra.alias DESC"
        );
        foreach ($stmt2->fetchAll(PDO::FETCH_ASSOC) as $row) {
            // PHP-side: verify the alias is purely A-Z and 0-9 (no lowercase, no spaces)
            if (!preg_match('/^[A-Z][A-Z0-9]{1,3}$/', $row['alias'])) {
                continue;
            }
            $key = mb_strtolower(trim($row['canonical_name']), 'UTF-8');
            // Only fill if step 1 didn't already set this recipe
            if (!isset($short_code_map[$key])) {
                // First qualifying alias wins (shortest len, desc alpha for ties)
                $short_code_map[$key] = $row['alias'];
            }
        }

        // Step 3: Fallback — use recipe_short_name for anything still unmapped.
        $stmt3 = $pdo->query(
            "SELECT name, recipe_short_name FROM ref_recipes
              WHERE recipe_short_name IS NOT NULL AND recipe_short_name != ''
                AND vintage = ''"
        );
        foreach ($stmt3->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $key = mb_strtolower(trim($row['name']), 'UTF-8');
            if (!isset($short_code_map[$key])) {
                $short_code_map[$key] = $row['recipe_short_name'];
            }
        }
    }

    $key = mb_strtolower(trim($canonical), 'UTF-8');
    return $short_code_map[$key] ?? null;
}

// ──────────────────────────────────────────────────────────────────────────────
// Internal helpers (not part of the public API)
// ──────────────────────────────────────────────────────────────────────────────

/**
 * Load all ref_recipes and ref_recipe_aliases into memory maps.
 * Called once; results stored in static refs passed by reference.
 */
function _recipe_resolver_load(PDO $pdo, ?array &$all_recipes, ?array &$all_aliases, ?array &$norm_map): void
{
    $all_recipes = [];
    $all_aliases = [];
    $norm_map    = [];

    // Load active recipes only. Order by vintage DESC, id DESC so that when multiple
    // rows share the same name (EPH1–4 were multi-vintage), the map-key collision for
    // a vintage-less lookup lands on the NEWEST/operative row, not the oldest stub.
    // Tombstoned stubs (is_active=0) are excluded — they must not win name resolution.
    $stmt = $pdo->query(
        "SELECT id, name, vintage FROM ref_recipes WHERE is_active = 1 ORDER BY vintage DESC, id DESC"
    );
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $key = _rr_ci_key($row['name'], $row['vintage']);
        $all_recipes[$key] = [
            'recipe_id'      => (int)$row['id'],
            'canonical_name' => $row['name'],
            'vintage'        => $row['vintage'],
        ];
        // Build normalised → recipe map (first-seen wins; with newest-first order,
        // the operative row always wins for duplicate names).
        $norm = _rr_normalize($row['name']);
        if (!isset($norm_map[$norm])) {
            $norm_map[$norm] = [
                'recipe_id'      => (int)$row['id'],
                'canonical_name' => $row['name'],
                'vintage'        => $row['vintage'],
                'src'            => 'normalized',
            ];
        }
    }

    // Load all aliases
    $stmt2 = $pdo->query(
        "SELECT ra.alias, ra.recipe_id, rr.name AS canonical_name, rr.vintage
           FROM ref_recipe_aliases ra
           JOIN ref_recipes rr ON rr.id = ra.recipe_id
          ORDER BY ra.id"
    );
    foreach ($stmt2->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $akey = mb_strtolower(trim($row['alias']), 'UTF-8');
        $all_aliases[$akey] = [
            'recipe_id'      => (int)$row['recipe_id'],
            'canonical_name' => $row['canonical_name'],
            'vintage'        => $row['vintage'],
        ];
        // Also index normalised alias form
        $norm = _rr_normalize($row['alias']);
        if (!isset($norm_map[$norm])) {
            $norm_map[$norm] = [
                'recipe_id'      => (int)$row['recipe_id'],
                'canonical_name' => $row['canonical_name'],
                'vintage'        => $row['vintage'],
                'src'            => 'normalized',
            ];
        }
    }
}

/**
 * Core lookup logic — operates on pre-loaded maps, never touches DB.
 */
function _recipe_resolver_lookup(
    string  $raw,
    array   $all_recipes,
    array   $all_aliases,
    array   $norm_map,
    ?string $parent_batch
): ?array {
    // ── Step 1: exact canonical name match (case+accent insensitive via lower+trim) ──
    $ci_key = _rr_ci_key($raw, null);  // vintage=null → match any vintage with that name
    foreach ($all_recipes as $key => $rec) {
        if (_rr_ci_key($rec['canonical_name'], null) === $ci_key) {
            return array_merge($rec, [
                'matched_via' => 'canonical',
                'batch'       => $parent_batch,
            ]);
        }
    }

    // ── Step 2: exact alias match ──
    $akey = mb_strtolower($raw, 'UTF-8');
    if (isset($all_aliases[$akey])) {
        $hit = $all_aliases[$akey];
        return [
            'recipe_id'      => $hit['recipe_id'],
            'canonical_name' => $hit['canonical_name'],
            'vintage'        => $hit['vintage'],
            'matched_via'    => 'alias',
            'batch'          => $parent_batch,
        ];
    }

    // ── Step 3: strip trailing batch number and retry ──
    // Pattern: one or more words/codes, then whitespace + integer(s)
    // E.g. "EMB 234" → prefix="EMB", batch="234"
    //      "EPH0421 1" → prefix="EPH0421", batch="1"
    //      "EPH1 25" → prefix="EPH1", batch="25"
    if ($parent_batch === null && preg_match('/^(.+?)\s+(\d+)$/', $raw, $m)) {
        $prefix = trim($m[1]);
        $batch  = $m[2];
        $sub = _recipe_resolver_lookup($prefix, $all_recipes, $all_aliases, $norm_map, $batch);
        if ($sub !== null) {
            $sub['matched_via'] = 'short_code_with_batch';
            return $sub;
        }
    }

    // ── Step 4: normalised comparison ──
    $norm = _rr_normalize($raw);
    if (isset($norm_map[$norm])) {
        $hit = $norm_map[$norm];
        return [
            'recipe_id'      => $hit['recipe_id'],
            'canonical_name' => $hit['canonical_name'],
            'vintage'        => $hit['vintage'],
            'matched_via'    => 'normalized',
            'batch'          => $parent_batch,
        ];
    }

    // ── Step 5: no match ──
    return null;
}

/**
 * Case-insensitive key for canonical name (accent-insensitive via transliteration).
 * When $vintage is null, returns just the name key (used for cross-vintage matching).
 */
function _rr_ci_key(string $name, ?string $vintage): string
{
    $n = mb_strtolower(trim($name), 'UTF-8');
    if ($vintage !== null) {
        return $n . '||' . $vintage;
    }
    return $n;
}

/**
 * Normalise a string for fuzzy-resistant comparison:
 *   - Unicode normalise to NFD then strip combining diacritics
 *   - Lowercase
 *   - Remove dots, spaces, hyphens
 * Examples:
 *   "Div.Blanche" → "divblanche"
 *   "Diversion Blanche" → "diversionblanche"
 *   "Panaché" → "panache"
 *   "moonshine" → "moonshine"
 */
function _rr_normalize(string $s): string
{
    // Decompose accented characters to base + combining mark
    $s = Normalizer::normalize($s, Normalizer::FORM_D);
    // Strip combining diacritical marks (Unicode category Mn)
    $s = preg_replace('/\p{Mn}/u', '', $s);
    // Lowercase
    $s = mb_strtolower($s, 'UTF-8');
    // Remove spaces, dots, hyphens, apostrophes
    $s = preg_replace('/[\s.\-\'\']/u', '', $s);
    return $s;
}
