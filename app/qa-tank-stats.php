<?php
declare(strict_types=1);
/**
 * app/qa-tank-stats.php — In-tank CO₂/O₂ conformance aggregations for the QA dashboard.
 *
 * Reads bd_tank_readings (source table, ~1615 rows, no is_tombstoned column — no tombstone
 * filter applied here or anywhere in this file) and joins ref_recipes on the NEB lane only.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * CANONICAL RULES (must be preserved in every caller and future revision)
 * ══════════════════════════════════════════════════════════════════════════════
 *
 *   1. TWO LANES, never collapsed.
 *      NEB  rows: recipe_id_fk IS NOT NULL  (neb_batch = lot key; contract_* NULL).
 *      CONTRACT rows: contract_beer IS NOT NULL (recipe_id_fk NULL; contract_batch = lot key).
 *      Row identity: every row belongs to exactly one lane.
 *
 *   2. NO TOMBSTONE FILTER on bd_tank_readings.
 *      The table has no is_tombstoned column. Do NOT add one. The full row set is canonical.
 *
 *   3. SPEC BAND — NEB lane only, from ref_recipes.co2_target / co2_tolerance.
 *      ~10/12 NEB recipes with readings have a spec; the 2 without return null spec.
 *      CONTRACT lane: no spec column → spec always returned as null ("no spec set").
 *      REFUSE-DON'T-NULL: a missing spec returns null — never a guessed/zero target.
 *
 *   4. IN-SPEC evaluation:
 *      A reading is "in spec" when ABS(co2_gl - co2_target) <= co2_tolerance.
 *      Returns null when spec is absent OR co2_gl is NULL on this row.
 *
 *   5. COLLATION — bd_tank_readings = utf8mb4_0900_ai_ci; ref_* = utf8mb4_unicode_ci.
 *      All joins here are integer FK joins (recipe_id_fk INT UNSIGNED) — collation-safe,
 *      no COLLATE clause needed. Any future string join on contract_beer → ref_* MUST add
 *      explicit COLLATE utf8mb4_unicode_ci on the join predicate (anti-pattern #2).
 *      For the MVP the CONTRACT lane needs no ref_recipes join — contract lots have no spec.
 *
 *   6. BEER KEY SCHEME — keys are prefixed to prevent NEB recipe_id vs contract_beer clash:
 *      NEB:      "neb:<recipe_id>"        e.g. "neb:57"
 *      CONTRACT: "con:<contract_beer>"    e.g. "con:Chien Bleu - Bamse"
 *
 *   7. SERIES SHAPE — hydrate-all.
 *      The full dataset is ~1615 rows over 5 years, split across ~53 distinct beer keys.
 *      A single query hydrated into a key→[] map is simpler and cheaper than N+1 per-beer
 *      queries. The UI receives the full map and accesses series[beerKey] directly.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * PUBLIC API
 * ══════════════════════════════════════════════════════════════════════════════
 *
 *   qa_tank_beer_list(PDO $pdo): array
 *     → list<row> — selectable beers that have ≥1 in-tank reading.
 *       Each row: beer_key, display_label, lane, recipe_id, co2_target, co2_tolerance,
 *                 n_readings, n_in_spec, n_out_of_spec, first_read_date, last_read_date,
 *                 latest_co2_gl, latest_o2_ppb.
 *       Sorted: NEB first (by display_label ASC), then CONTRACT (by display_label ASC).
 *
 *   qa_tank_series_all(PDO $pdo): array
 *     → array<string, list<row>> — keyed by beer_key.
 *       Each series row: read_date, co2_gl, o2_ppb, batch, in_spec (bool|null).
 *       Rows within each key are ordered by read_date ASC.
 *
 * All functions:
 *   - Accept a PDO connection (caller supplies from maltytask_pdo()).
 *   - Return plain arrays of associative rows. No HTML, no side effects.
 *   - Are memoized within a single request (static $cache).
 *
 * Dependencies: app/db.php (maltytask_pdo() — caller passes $pdo). Read-only.
 */

require_once __DIR__ . '/db.php';

/* ═══════════════════════════════════════════════════════════════════════════
   Internal helpers
   ═══════════════════════════════════════════════════════════════════════════ */

/**
 * Evaluate in_spec for a single reading.
 *
 * Returns true|false when the spec is set and co2_gl is present.
 * Returns null when either co2_target or co2_gl is absent.
 *
 * @internal
 */
function _qa_in_spec(?float $co2Gl, ?float $co2Target, ?float $co2Tolerance): ?bool
{
    if ($co2Gl === null || $co2Target === null || $co2Tolerance === null) {
        return null;
    }
    return abs($co2Gl - $co2Target) <= $co2Tolerance;
}

/* ═══════════════════════════════════════════════════════════════════════════
   1. qa_tank_beer_list
   ═══════════════════════════════════════════════════════════════════════════ */

/**
 * List of all beers that have ≥1 in-tank reading, with conformance summary.
 *
 * One row per distinct beer (NEB lane = one row per recipe_id_fk; CONTRACT lane = one row
 * per contract_beer string). Sorted NEB-first by display_label, then CONTRACT by display_label.
 *
 * Row shape:
 *   beer_key       string   "neb:<recipe_id>" or "con:<contract_beer>"
 *   display_label  string   ref_recipes.name (NEB) or contract_beer (CONTRACT)
 *   lane           string   "neb" | "contract"
 *   recipe_id      int|null recipe_id_fk for NEB; null for CONTRACT
 *   co2_target     float|null  NEB w/ spec; null otherwise (refuse-don't-NULL)
 *   co2_tolerance  float|null  NEB w/ spec; null otherwise
 *   n_readings     int      total reading rows for this beer
 *   n_in_spec      int      readings where ABS(co2_gl - co2_target) <= co2_tolerance
 *   n_out_of_spec  int      readings where spec set but outside tolerance
 *   first_read_date string  "YYYY-MM-DD"
 *   last_read_date  string  "YYYY-MM-DD"
 *   latest_co2_gl  float|null  co2_gl of most recent reading
 *   latest_o2_ppb  float|null  o2_ppb of most recent reading
 *
 * @param PDO $pdo  Active DB connection.
 * @return list<array>
 */
function qa_tank_beer_list(PDO $pdo): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    // Fetch NEB summary — integer FK join, collation-safe.
    $nebStmt = $pdo->query(
        "SELECT
             t.recipe_id_fk                  AS recipe_id,
             r.name                          AS display_label,
             r.co2_target,
             r.co2_tolerance,
             COUNT(t.id)                     AS n_readings,
             MIN(t.read_date)                AS first_read_date,
             MAX(t.read_date)                AS last_read_date
         FROM bd_tank_readings t
         INNER JOIN ref_recipes r ON r.id = t.recipe_id_fk
         WHERE t.recipe_id_fk IS NOT NULL
         GROUP BY t.recipe_id_fk, r.name, r.co2_target, r.co2_tolerance
         ORDER BY r.name ASC"
    );

    // Fetch CONTRACT summary — no ref_recipes join needed; no spec available.
    $conStmt = $pdo->query(
        "SELECT
             t.contract_beer                 AS display_label,
             COUNT(t.id)                     AS n_readings,
             MIN(t.read_date)                AS first_read_date,
             MAX(t.read_date)                AS last_read_date
         FROM bd_tank_readings t
         WHERE t.contract_beer IS NOT NULL
         GROUP BY t.contract_beer
         ORDER BY t.contract_beer ASC"
    );

    // Fetch latest reading per NEB recipe (for latest_co2/o2).
    // Subquery: latest read_date per recipe_id_fk; join back for co2/o2.
    $nebLatestStmt = $pdo->query(
        "SELECT t.recipe_id_fk, t.co2_gl, t.o2_ppb
         FROM bd_tank_readings t
         INNER JOIN (
             SELECT recipe_id_fk, MAX(read_date) AS max_date
             FROM bd_tank_readings
             WHERE recipe_id_fk IS NOT NULL
             GROUP BY recipe_id_fk
         ) sub ON sub.recipe_id_fk = t.recipe_id_fk AND t.read_date = sub.max_date"
    );

    // Build NEB latest map: recipe_id → {co2_gl, o2_ppb}.
    // If multiple rows share the same max read_date, we take the last one by id — not critical.
    $nebLatest = [];
    foreach ($nebLatestStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $nebLatest[(int)$row['recipe_id_fk']] = [
            'co2_gl'  => $row['co2_gl']  !== null ? (float)$row['co2_gl']  : null,
            'o2_ppb'  => $row['o2_ppb']  !== null ? (float)$row['o2_ppb']  : null,
        ];
    }

    // Fetch latest reading per CONTRACT beer.
    $conLatestStmt = $pdo->query(
        "SELECT t.contract_beer, t.co2_gl, t.o2_ppb
         FROM bd_tank_readings t
         INNER JOIN (
             SELECT contract_beer, MAX(read_date) AS max_date
             FROM bd_tank_readings
             WHERE contract_beer IS NOT NULL
             GROUP BY contract_beer
         ) sub ON sub.contract_beer = t.contract_beer AND t.read_date = sub.max_date"
    );

    $conLatest = [];
    foreach ($conLatestStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $conLatest[$row['contract_beer']] = [
            'co2_gl' => $row['co2_gl'] !== null ? (float)$row['co2_gl'] : null,
            'o2_ppb' => $row['o2_ppb'] !== null ? (float)$row['o2_ppb'] : null,
        ];
    }

    // We need in-spec counts per beer — fetch from series (the full table is small enough).
    // We'll compute these from the raw series to avoid re-doing the in-spec math twice.
    // Defer to qa_tank_series_all which is memoized too.
    $seriesAll = qa_tank_series_all($pdo);

    $rows = [];

    // — NEB rows —
    foreach ($nebStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $recipeId    = (int)$row['recipe_id'];
        $beerKey     = 'neb:' . $recipeId;
        $co2Target   = $row['co2_target']     !== null ? (float)$row['co2_target']   : null;
        $co2Tol      = $row['co2_tolerance']  !== null ? (float)$row['co2_tolerance'] : null;
        $latest      = $nebLatest[$recipeId]  ?? ['co2_gl' => null, 'o2_ppb' => null];

        // Count in/out of spec from the series.
        $nInSpec  = 0;
        $nOutSpec = 0;
        foreach ($seriesAll[$beerKey] ?? [] as $pt) {
            if ($pt['in_spec'] === true)  { $nInSpec++; }
            if ($pt['in_spec'] === false) { $nOutSpec++; }
        }

        $rows[] = [
            'beer_key'        => $beerKey,
            'display_label'   => (string)$row['display_label'],
            'lane'            => 'neb',
            'recipe_id'       => $recipeId,
            'co2_target'      => $co2Target,
            'co2_tolerance'   => $co2Tol,
            'n_readings'      => (int)$row['n_readings'],
            'n_in_spec'       => $nInSpec,
            'n_out_of_spec'   => $nOutSpec,
            'first_read_date' => (string)$row['first_read_date'],
            'last_read_date'  => (string)$row['last_read_date'],
            'latest_co2_gl'   => $latest['co2_gl'],
            'latest_o2_ppb'   => $latest['o2_ppb'],
        ];
    }

    // — CONTRACT rows —
    foreach ($conStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $contractBeer = (string)$row['display_label'];
        $beerKey      = 'con:' . $contractBeer;
        $latest       = $conLatest[$contractBeer] ?? ['co2_gl' => null, 'o2_ppb' => null];

        // CONTRACT has no spec → in_spec is always null; counters always 0.
        $rows[] = [
            'beer_key'        => $beerKey,
            'display_label'   => $contractBeer,
            'lane'            => 'contract',
            'recipe_id'       => null,
            'co2_target'      => null,
            'co2_tolerance'   => null,
            'n_readings'      => (int)$row['n_readings'],
            'n_in_spec'       => 0,
            'n_out_of_spec'   => 0,
            'first_read_date' => (string)$row['first_read_date'],
            'last_read_date'  => (string)$row['last_read_date'],
            'latest_co2_gl'   => $latest['co2_gl'],
            'latest_o2_ppb'   => $latest['o2_ppb'],
        ];
    }

    $cache = $rows;
    return $rows;
}

/* ═══════════════════════════════════════════════════════════════════════════
   2. qa_tank_series_all — hydrate-all time-series
   ═══════════════════════════════════════════════════════════════════════════ */

/**
 * All in-tank readings hydrated into a beer_key → series map.
 *
 * Design choice: hydrate-all (not per-beer on demand).
 * ~1615 rows across ~53 keys — a single query + PHP grouping is cheaper than N+1 round-trips
 * and the UI needs the full map anyway to populate the beer selector AND the initial chart.
 * Mirrors the packaging-stats.php approach of hydrating once and slicing in PHP.
 *
 * Series row shape (per point):
 *   read_date   string      "YYYY-MM-DD"
 *   co2_gl      float|null  CO₂ in g/L
 *   o2_ppb      float|null  O₂ in ppb
 *   batch       string      neb_batch (NEB) or contract_batch (CONTRACT); may be "—" if null
 *   in_spec     bool|null   true/false when spec set + co2_gl present; null otherwise
 *
 * Returned array is keyed by beer_key; inner lists are ordered read_date ASC.
 *
 * @param PDO $pdo  Active DB connection.
 * @return array<string, list<array>>
 */
function qa_tank_series_all(PDO $pdo): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    // Single query: NEB lane with spec columns; ORDER BY beer_key then date for predictable grouping.
    // Integer FK join — collation-safe, no COLLATE needed.
    $stmt = $pdo->query(
        "SELECT
             t.recipe_id_fk,
             t.neb_batch,
             t.contract_beer,
             t.contract_batch,
             t.read_date,
             t.co2_gl,
             t.o2_ppb,
             r.co2_target,
             r.co2_tolerance
         FROM bd_tank_readings t
         LEFT JOIN ref_recipes r ON r.id = t.recipe_id_fk
         ORDER BY
             CASE WHEN t.recipe_id_fk IS NOT NULL THEN 0 ELSE 1 END ASC,
             COALESCE(r.name, t.contract_beer)                       ASC,
             t.read_date                                             ASC"
    );

    $map = [];

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        // Determine lane and beer key.
        if ($row['recipe_id_fk'] !== null) {
            $beerKey   = 'neb:' . (int)$row['recipe_id_fk'];
            $batch     = $row['neb_batch'] !== null ? (string)$row['neb_batch'] : '—';
            $co2Target = $row['co2_target']    !== null ? (float)$row['co2_target']    : null;
            $co2Tol    = $row['co2_tolerance'] !== null ? (float)$row['co2_tolerance'] : null;
        } else {
            $beerKey   = 'con:' . (string)$row['contract_beer'];
            $batch     = $row['contract_batch'] !== null ? (string)$row['contract_batch'] : '—';
            $co2Target = null;
            $co2Tol    = null;
        }

        $co2Gl = $row['co2_gl'] !== null ? (float)$row['co2_gl'] : null;
        $o2Ppb = $row['o2_ppb'] !== null ? (float)$row['o2_ppb'] : null;

        if (!isset($map[$beerKey])) {
            $map[$beerKey] = [];
        }

        $map[$beerKey][] = [
            'read_date' => (string)$row['read_date'],
            'co2_gl'    => $co2Gl,
            'o2_ppb'    => $o2Ppb,
            'batch'     => $batch,
            'in_spec'   => _qa_in_spec($co2Gl, $co2Target, $co2Tol),
        ];
    }

    $cache = $map;
    return $map;
}
