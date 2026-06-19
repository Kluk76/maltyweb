<?php
declare(strict_types=1);
/**
 * app/planning-predict.php — Phase 3: Predictive planning suggestions producer.
 *
 * CARDINAL RULE: Pure read except for INSERT of proposed pl_plan_items rows.
 * Those INSERTs only happen when explicitly called by a manager.
 * NEVER feeds COGS/COP/WAC/BOM/beer-tax/inventory.
 *
 * Public entry point:
 *   planning_generate_suggestions(PDO $pdo, DateTimeImmutable $weekStart, int $createdByUserId): array
 *
 * Returns ['inserted'=>int, 'skipped_dedup'=>int, 'rows'=>[...pl_plan_items rows...], 'decisions'=>[...debug...]]
 *
 * Dependencies:
 *   app/fg-stock.php              (fg_stock_compute)
 *   app/planning-eligibility.php  (planning_week_eligibility)
 *   app/production-targets.php    (production_targets_compute)
 *   app/settings.php              (system_setting)
 *   app/db-write-helpers.php      (log_revision)
 *   app/db.php                    (maltytask_pdo — transitively via the above)
 */

require_once __DIR__ . '/fg-stock.php';
require_once __DIR__ . '/planning-eligibility.php';
require_once __DIR__ . '/production-targets.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/db-write-helpers.php';

// ── Plant-physics constraints ──────────────────────────────────────────────
// Bottling and canning share the same physical line — they cannot run the same day.
// Kegging and serving_tank are unconstrained (parallel with anything).
// Extensible: add pairs here, never scattered ifs.
const MUTEX_PKG_PAIRS = [['bottling', 'canning']];

// ── Format → pkg_type map ─────────────────────────────────────────────────────

/**
 * Map ref_skus.format → pl_plan_items.pkg_type.
 * Returns null if no mapping exists for the given format.
 * Consistent with PKG_TYPE_MAP in planning.php.
 */
function predict_format_to_pkg_type(string $format): ?string
{
    static $map = [
        'Bot'             => 'bottling',
        'Can'             => 'canning',
        'Can33'           => 'canning',
        'Keg'             => 'kegging',
        'Vrac'            => 'kegging',
        'Cuve de service' => 'serving_tank',
    ];
    return $map[$format] ?? null;
}

// ── Active pkg_types loader ───────────────────────────────────────────────────

/**
 * Load active pkg_types from ref_process_machines.
 * Same machine_type→pkg_type map as PKG_TYPE_MAP in planning.php.
 * Returns array of active pkg_type strings (e.g. ['bottling','canning','kegging']).
 */
function predict_load_active_pkg_types(PDO $pdo): array
{
    static $machineMap = [
        'filler_bottle' => 'bottling',
        'filler_can'    => 'canning',
        'filler_keg'    => 'kegging',
        'filler_cuv'    => 'serving_tank',
    ];
    $stmt = $pdo->prepare(
        "SELECT machine_type FROM ref_process_machines
          WHERE machine_type IN ('filler_bottle','filler_can','filler_keg','filler_cuv')
            AND is_active = 1"
    );
    $stmt->execute();
    $types = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $pt = $machineMap[$row['machine_type']] ?? null;
        if ($pt !== null) $types[$pt] = true;
    }
    return array_keys($types);
}

// ── User loader (for log_revision $me) ───────────────────────────────────────

/**
 * Fetch minimal user data needed for log_revision() from the users table.
 * Returns ['id' => int, 'username' => string].
 * Falls back to username='system' if the user is not found.
 */
function predict_load_user(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare('SELECT id, username FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row !== false) {
        return ['id' => (int)$row['id'], 'username' => (string)$row['username']];
    }
    return ['id' => $userId, 'username' => 'system'];
}

// ── Fleet reads ────────────────────────────────────────────────────────────────

/**
 * Returns sorted int array of active CCT numbers from ref_cct.
 */
function predict_load_active_cct_numbers(PDO $pdo): array
{
    $stmt = $pdo->query("SELECT number FROM ref_cct WHERE status='active' ORDER BY number");
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * Returns sorted int array of active BBT numbers from ref_bbt.
 */
function predict_load_active_bbt_numbers(PDO $pdo): array
{
    $stmt = $pdo->query("SELECT number FROM ref_bbt WHERE status='active' ORDER BY number");
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * Returns count of free (in_house, active) serving tanks.
 */
function predict_load_free_serving_tank_count(PDO $pdo): int
{
    $stmt = $pdo->query("SELECT COUNT(*) FROM ref_serving_tanks WHERE location='in_house' AND status='active'");
    return (int)$stmt->fetchColumn();
}

/**
 * Returns serving-tank client data with cadence and next-fill prediction.
 * Each returned entry: [
 *   'client_id'       => int,
 *   'client_name'     => string,
 *   'recipe_id'       => int,
 *   'recipe_name'     => string,
 *   'last_fill_date'  => string (Y-m-d),
 *   'cadence_days'    => int,
 *   'next_expected'   => string (Y-m-d),
 *   'target_volume_hl'=> float,
 * ]
 * Clients with < 2 fill events are skipped (cadence cannot be derived).
 * Fill events are collapsed per posting_date (one fill = one day even if multi-line).
 * Cadence = MEDIAN interval (days) between consecutive distinct fill dates.
 * Target volume = MEDIAN HL of the last up-to-6 fills.
 * Beer = recipe_id of the client's most recent fill row.
 */
function predict_load_serving_tank_clients(PDO $pdo): array
{
    // Load flagged active clients
    $clientStmt = $pdo->prepare(
        'SELECT id, name,
                 serving_tank_budget_hl,
                 serving_tank_count,
                 serving_tank_size_hl
            FROM ref_customers
           WHERE is_serving_tank_client = 1 AND is_active = 1
           ORDER BY id'
    );
    $clientStmt->execute();
    $clients = $clientStmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($clients)) {
        return [];
    }

    // Load all recipe names once
    $recipeStmt = $pdo->query('SELECT id, name FROM ref_recipes WHERE is_active = 1');
    $recipeNames = []; // id => name
    foreach ($recipeStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $recipeNames[(int)$r['id']] = (string)$r['name'];
    }

    $result = [];

    foreach ($clients as $client) {
        $cid = (int)$client['id'];

        // Load all Cuve de service fills for this client, negative qty only (sales), ordered ascending
        $fillStmt = $pdo->prepare(
            'SELECT l.posting_date, s.recipe_id, SUM(ABS(l.qty_signed)) AS day_qty_litres
               FROM inv_sales_ledger l
               JOIN ref_skus s ON s.id = l.sku_id_fk AND s.format = \'Cuve de service\'
              WHERE l.customer_id_fk = ? AND l.qty_signed < 0
              GROUP BY l.posting_date, s.recipe_id
              ORDER BY l.posting_date ASC'
        );
        $fillStmt->execute([$cid]);
        $fills = $fillStmt->fetchAll(PDO::FETCH_ASSOC); // each row: posting_date, recipe_id, day_qty_litres

        if (count($fills) < 2) {
            // Cannot derive cadence
            continue;
        }

        // Collapse by posting_date: one fill event per day (sum qty, take last recipe_id seen)
        // (already grouped by posting_date in the query above, but handle edge case of same-day different recipe)
        $byDate = []; // Y-m-d => ['recipe_id' => int, 'qty_litres' => float]
        foreach ($fills as $f) {
            $d = (string)$f['posting_date'];
            if (!isset($byDate[$d])) {
                $byDate[$d] = ['recipe_id' => (int)$f['recipe_id'], 'qty_litres' => 0.0];
            }
            $byDate[$d]['qty_litres'] += (float)$f['day_qty_litres'];
            $byDate[$d]['recipe_id'] = (int)$f['recipe_id']; // last recipe wins (same-day multi-recipe edge)
        }
        ksort($byDate); // ensure ascending date order
        $dates = array_keys($byDate);

        if (count($dates) < 2) {
            continue; // need >= 2 distinct fill dates for >= 1 interval
        }

        // Compute intervals (days) between consecutive distinct fill dates
        $intervals = [];
        for ($i = 1; $i < count($dates); $i++) {
            $prev = new DateTimeImmutable($dates[$i - 1]);
            $curr = new DateTimeImmutable($dates[$i]);
            $intervals[] = (int)$prev->diff($curr)->days;
        }

        // Median interval
        sort($intervals);
        $n = count($intervals);
        $cadenceDays = ($n % 2 === 1)
            ? (int)$intervals[($n - 1) / 2]
            : (int)round(($intervals[$n / 2 - 1] + $intervals[$n / 2]) / 2);

        if ($cadenceDays <= 0) {
            continue; // degenerate cadence
        }

        // Last fill date
        $lastFillDate = end($dates);
        // Most recent fill recipe
        $lastRecipeId = $byDate[$lastFillDate]['recipe_id'];

        // Target volume = MEDIAN of last up-to-6 fills' HL
        $lastSixDates = array_slice($dates, -6);
        $hlValues = [];
        foreach ($lastSixDates as $d) {
            $hlValues[] = $byDate[$d]['qty_litres'] / 100.0;
        }
        sort($hlValues);
        $nhl = count($hlValues);
        $targetHl = ($nhl % 2 === 1)
            ? $hlValues[($nhl - 1) / 2]
            : ($hlValues[$nhl / 2 - 1] + $hlValues[$nhl / 2]) / 2.0;
        $targetHl = round($targetHl, 2);

        $nextExpected = (new DateTimeImmutable($lastFillDate))->modify("+{$cadenceDays} days")->format('Y-m-d');

        $result[] = [
            'client_id'        => $cid,
            'client_name'      => (string)$client['name'],
            'recipe_id'        => $lastRecipeId,
            'recipe_name'      => $recipeNames[$lastRecipeId] ?? 'Recette #' . $lastRecipeId,
            'last_fill_date'   => $lastFillDate,
            'cadence_days'     => $cadenceDays,
            'next_expected'    => $nextExpected,
            'target_volume_hl' => $targetHl,
            'budget_hl'        => isset($client['serving_tank_budget_hl']) ? (float)$client['serving_tank_budget_hl'] : null,
            'tank_count'       => isset($client['serving_tank_count']) ? (int)$client['serving_tank_count'] : null,
            'tank_size_hl'     => isset($client['serving_tank_size_hl']) ? (float)$client['serving_tank_size_hl'] : null,
        ];
    }

    return $result;
}

// ── Dry-hop spec helpers ───────────────────────────────────────────────────────

/**
 * Returns recipe_ids (int[]) that have a dry_hop stage in ref_recipe_ingredients.
 * NOTE: column is recipe_id, NOT recipe_id_fk.
 */
function predict_load_dry_hop_recipe_ids(PDO $pdo): array
{
    $stmt = $pdo->query(
        "SELECT DISTINCT recipe_id FROM ref_recipe_ingredients
          WHERE hop_addition_stage='dry_hop' AND is_active=1"
    );
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * Returns recipe_ids (int[]) for STRICT Core beers only:
 * classification='Neb' AND subtype='Core' AND is_active=1.
 * Collabs, contracts, EPH, white-label and archive are excluded.
 */
function predict_load_core_recipe_ids(PDO $pdo): array
{
    $stmt = $pdo->query(
        "SELECT id FROM ref_recipes
          WHERE is_active=1 AND classification='Neb' AND subtype='Core'"
    );
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * Given an array of ['recipe_id'=>int, 'batch'=>string] pairs,
 * returns a set array ['recipe_id:batch' => true] for pairs that already have
 * a DryHop event in bd_fermenting_v2.
 * Returns [] for empty input.
 */
function predict_batches_already_dry_hopped(PDO $pdo, array $recipeBatchPairs): array
{
    if (empty($recipeBatchPairs)) {
        return [];
    }
    $inClauses = [];
    $params    = [];
    foreach ($recipeBatchPairs as $pair) {
        $inClauses[] = '(?,?)';
        $params[]    = (int)$pair['recipe_id'];
        $params[]    = (string)$pair['batch'];
    }
    $sql = "SELECT DISTINCT recipe_id_fk, batch FROM bd_fermenting_v2
             WHERE event_type='DryHop' AND is_tombstoned=0
               AND (recipe_id_fk, batch) IN (" . implode(',', $inClauses) . ")";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $result = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $result[(int)$row['recipe_id_fk'] . ':' . (string)$row['batch']] = true;
    }
    return $result;
}

// ── Main entry point ──────────────────────────────────────────────────────────

/**
 * Generate predictive plan suggestions for the given week.
 *
 * Algorithm:
 *   1.  Config — read plan_suggest_target_weeks; load active fillers, user, fleet.
 *   2.  FG stock — fg_stock_compute(); build sku→recipe map; aggregate to recipe level.
 *   3.  Filter — recipes below coverage threshold or with no stock.
 *   4.  Capacity inputs — production_targets_compute() weekly objectives;
 *       max_brews_per_day + workday_* toggles from system_settings (section='production_targets');
 *       ref_brewhouse_size.size_hl as proxy HL per packaging run (fallback 30.0).
 *   5.  Working days — build $workingDays (DateTimeImmutable[]) from weekStart..+6
 *       restricted to days where workday_<iso> = 1. Fallback: Monday only.
 *   6.  Combined dedup + netting query — single SELECT over pl_plan_items
 *       (plan_date BETWEEN weekStart..weekEnd, is_active=1, section IN wort/packaging).
 *       Builds simultaneously:
 *         • $dedupSet        (section:rid:N → true)
 *         • $existingBrewCount / $existingPkgHl[fmt] (capacity netting)
 *         • $placedBrewsByDate[dateStr] (per-day brew count, seeds cap check)
 *         • $pkgCountByDate[dateStr]   (per-day packaging count, seeds load-balance)
 *   7.  Net weekly budgets:
 *         • $brewWeekBudget  = floor(obj.brews.week) − existing_brew_rows  (≥0)
 *         • $pkgBudgetHl[fmt] = obj.<fmt>_hl.week − existing_pkg_hl[fmt]   (≥0)
 *   8.  Eligibility — planning_week_eligibility(); restrict packaging slots to
 *       working days; build $packagingEligByRecipe[recipe_id] = sorted eligible slots.
 *       Seed occupancy ledgers from eligibility engine output.
 *   9.  Sort — $needingRecipes ascending by worst_semaines (null → −INF first).
 *  10.  Pass 1: Racking (process-driven) — place racking proposals using live BBT fleet.
 *  11.  Pass 2: Packaging (demand-driven) — demand-driven with per-day cap + BBT ledger.
 *  12.  Pass 3: Brewing (demand-driven, round-robin) — CCT from fleet, not hardcoded 1..10.
 *  13.  Pass 4: Dry-hop (process-driven, HARD-GATED) — spec check + bd_fermenting_v2 gate.
 *  14.  Return ['inserted', 'skipped_dedup', 'rows', 'decisions'].
 *
 * @param PDO               $pdo
 * @param DateTimeImmutable $weekStart  Monday of the target week (00:00:00)
 * @param int               $createdByUserId
 * @return array{inserted:int, skipped_dedup:int, rows:array, decisions:array}
 */
function planning_generate_suggestions(PDO $pdo, DateTimeImmutable $weekStart, int $createdByUserId): array
{
    $weekStartStr = $weekStart->format('Y-m-d');
    $weekEndStr   = $weekStart->modify('+6 days')->format('Y-m-d');

    // ── Step 1: Config ────────────────────────────────────────────────────────
    $targetWeeks    = (float) system_setting('plan_suggest_target_weeks', 'stock', 3.0);
    $activePkgTypes = predict_load_active_pkg_types($pdo);
    $me             = predict_load_user($pdo, $createdByUserId);

    $activeCctNumbers      = predict_load_active_cct_numbers($pdo);
    $activeBbtNumbers      = predict_load_active_bbt_numbers($pdo);
    $freeServingTankCount  = predict_load_free_serving_tank_count($pdo);
    $maxPkgRunsPerDay      = max(1, (int) system_setting('max_packaging_runs_per_day', 'production_targets', 4));
    $dryHopRecipeIds       = predict_load_dry_hop_recipe_ids($pdo);
    $coreRecipeIds         = array_flip(predict_load_core_recipe_ids($pdo)); // [recipe_id => true]

    // ── Step 2: FG stock coverage ─────────────────────────────────────────────
    $fgResult = fg_stock_compute($pdo);
    $fgRows   = $fgResult['rows'] ?? [];

    if (empty($fgRows)) {
        return [
            'inserted'      => 0,
            'skipped_dedup' => 0,
            'rows'          => [],
            'decisions'     => [['note' => 'fg_stock_compute returned no rows']],
        ];
    }

    // fg_stock_compute rows do NOT include recipe_id in their output array.
    // Load recipe_id and recipe name from ref_skus/ref_recipes for the sku_ids.
    $skuIds = array_map(fn($r) => (int)$r['sku_id'], $fgRows);
    if (!empty($skuIds)) {
        $inPlaceholders = implode(',', array_fill(0, count($skuIds), '?'));
        $skuRecipeStmt  = $pdo->prepare(
            "SELECT sk.id AS sku_id, sk.recipe_id, r.name AS recipe_name
               FROM ref_skus sk
               LEFT JOIN ref_recipes r ON r.id = sk.recipe_id
              WHERE sk.id IN ({$inPlaceholders}) AND sk.recipe_id IS NOT NULL"
        );
        $skuRecipeStmt->execute($skuIds);
        $skuRecipeMap  = []; // sku_id => recipe_id
        $recipeNameMap = []; // recipe_id => name
        foreach ($skuRecipeStmt->fetchAll(PDO::FETCH_ASSOC) as $sr) {
            $skuRecipeMap[(int)$sr['sku_id']]      = (int)$sr['recipe_id'];
            $recipeNameMap[(int)$sr['recipe_id']] ??= (string)$sr['recipe_name'];
        }
    } else {
        $skuRecipeMap  = [];
        $recipeNameMap = [];
    }

    // ── Step 3: Aggregate to recipe level ─────────────────────────────────────
    // Per recipe: track the best (highest) semaines_stock and worst (lowest) physique
    // across all its SKUs. We use 'format' and 'velocity' from the best-coverage SKU.
    $decisions = []; // Initialize before Step 3 so core-skip audit entries are not lost
    $byRecipe = []; // recipe_id (int) => [recipe_id, beer_name, best_semaines, worst_physique, format, velocity]
    foreach ($fgRows as $row) {
        $skuId = (int)($row['sku_id'] ?? 0);
        $rid   = $skuRecipeMap[$skuId] ?? null;
        if ($rid === null) {
            continue; // cannot suggest without recipe FK
        }
        $rid = (int) $rid;
        if (!isset($coreRecipeIds[$rid])) {
            $decisions[] = [
                'beer'     => $recipeNameMap[$rid] ?? 'Recette #' . $rid,
                'decision' => 'skipped',
                'reason'   => 'hors périmètre core',
            ];
            continue;
        }

        $semaines = $row['semaines_stock']; // float|null
        $physique = (float) ($row['physique'] ?? 0);
        $format   = (string) ($row['format'] ?? '');
        $velocity = $row['velocity_weekly'] ?? null;
        // display_family in fg_stock rows is the packaging family (e.g. 'Bot', 'Keg'),
        // not the beer name. Use recipe name from recipeNameMap instead.
        $beerName = $recipeNameMap[$rid] ?? $row['sku_code'] ?? 'Bière #' . $rid;

        if (!isset($byRecipe[$rid])) {
            $byRecipe[$rid] = [
                'recipe_id'      => $rid,
                'beer_name'      => $beerName,
                'best_semaines'  => $semaines,
                'worst_semaines' => $semaines,
                'worst_physique' => $physique,
                'format'         => $format,
                'velocity'       => $velocity,
            ];
        } else {
            // Track best (highest) semaines — null means no data
            $existBest = $byRecipe[$rid]['best_semaines'];
            if ($semaines !== null) {
                if ($existBest === null || (float)$semaines > (float)$existBest) {
                    $byRecipe[$rid]['best_semaines'] = $semaines;
                    $byRecipe[$rid]['format']        = $format;
                    $byRecipe[$rid]['velocity']      = $velocity ?? $byRecipe[$rid]['velocity'];
                }
            }
            // Track worst (lowest) semaines — null means no data available
            if ($semaines !== null) {
                $existWorst = $byRecipe[$rid]['worst_semaines'];
                if ($existWorst === null || (float)$semaines < (float)$existWorst) {
                    $byRecipe[$rid]['worst_semaines'] = $semaines;
                }
            }
            // Track worst (lowest) physique
            if ($physique < $byRecipe[$rid]['worst_physique']) {
                $byRecipe[$rid]['worst_physique'] = $physique;
            }
        }
    }

    // ── Step 4: Filter needing replenishment ──────────────────────────────────
    $needingRecipes = [];
    foreach ($byRecipe as $rid => $rd) {
        $needs  = false;
        $reason = '';
        if ($rd['worst_semaines'] !== null && (float)$rd['worst_semaines'] < $targetWeeks) {
            $needs  = true;
            $reason = sprintf('Couverture %.1f sem < %.0f', (float)$rd['worst_semaines'], $targetWeeks);
        } elseif ($rd['worst_semaines'] === null && $rd['worst_physique'] <= 0) {
            $needs  = true;
            $reason = 'Stock épuisé / pas de rotation';
        }
        if ($needs) {
            $needingRecipes[$rid] = array_merge($rd, ['reason' => $reason]);
        }
    }

    if (empty($needingRecipes)) {
        return [
            'inserted'      => 0,
            'skipped_dedup' => 0,
            'rows'          => [],
            'decisions'     => [['note' => 'No beers below coverage threshold']],
        ];
    }

    // ── Step 5: Capacity inputs ───────────────────────────────────────────────
    $targets = production_targets_compute($pdo);
    $obj     = $targets['objectives'];

    $maxBrewsPerDay = max(1, (int) system_setting('max_brews_per_day', 'production_targets', 5));

    // Proxy HL per packaging run from brewhouse size
    $proxyHl = 30.0;
    $phStmt  = $pdo->query('SELECT size_hl FROM ref_brewhouse_size ORDER BY id DESC LIMIT 1');
    if ($phStmt && ($phRow = $phStmt->fetch(PDO::FETCH_ASSOC))) {
        $proxyHl = (float)$phRow['size_hl'];
    }

    // ── Step 6: Working days ──────────────────────────────────────────────────
    $isoToKey = [1=>'mon',2=>'tue',3=>'wed',4=>'thu',5=>'fri',6=>'sat',7=>'sun'];
    $defaultEnabled = [1=>1,2=>1,3=>1,4=>1,5=>1,6=>0,7=>0];
    $workingDays = [];

    for ($i = 0; $i < 7; $i++) {
        $d   = $weekStart->modify("+{$i} days");
        $iso = (int)$d->format('N');
        $key = 'workday_' . $isoToKey[$iso];
        $enabled = (int) system_setting($key, 'production_targets', $defaultEnabled[$iso]);
        if ($enabled === 1) {
            $workingDays[] = $d;
        }
    }

    if (empty($workingDays)) {
        $workingDays = [$weekStart]; // fallback: Monday
        $decisions[] = ['note' => 'Aucun jour ouvré configuré, repli sur lundi'];
    }

    $workingDaySet = array_flip(array_map(fn($d) => $d->format('Y-m-d'), $workingDays));

    // ── Step 7: Combined dedup + netting query ────────────────────────────────
    // Single query builds dedupSet + capacity netting counters + per-day seeds.
    $existStmt = $pdo->prepare(
        "SELECT section, recipe_id_fk, pkg_type, target_volume_hl, plan_date, customer_id_fk
           FROM pl_plan_items
          WHERE plan_date BETWEEN ? AND ?
            AND is_active = 1
            AND section IN ('wort','packaging')"
    );
    $existStmt->execute([$weekStartStr, $weekEndStr]);
    $existRows = $existStmt->fetchAll(PDO::FETCH_ASSOC);

    $pkgTypeToBudgetKey = [
        'bottling'     => 'bottle_hl',
        'canning'      => 'can_hl',
        'kegging'      => 'keg_hl',
        'serving_tank' => null,
    ];

    $dedupSet                       = [];
    $existingBrewCount              = 0;
    $existingPkgHl                  = ['bottle_hl' => 0.0, 'can_hl' => 0.0, 'keg_hl' => 0.0];
    $placedBrewsByDate              = []; // dateStr => int (existing brew rows per day)
    $pkgCountByDate                 = []; // dateStr => int (existing packaging rows per day — seeds load-balance)
    $pkgTypeByDate                  = []; // dateStr => [pkg_type => true] (packaging types already on that day)
    $existingServingTankCustomers   = []; // customer_id => true

    foreach ($existRows as $ei) {
        $section  = (string)$ei['section'];
        $eiDate   = (string)$ei['plan_date'];
        $eiRid    = !empty($ei['recipe_id_fk']) ? (int)$ei['recipe_id_fk'] : null;

        // Dedup set
        if ($eiRid !== null) {
            $dedupSet[$section . ':rid:' . $eiRid] = true;
        }

        if ($section === 'wort') {
            $existingBrewCount++;
            $placedBrewsByDate[$eiDate] = ($placedBrewsByDate[$eiDate] ?? 0) + 1;
        } elseif ($section === 'packaging') {
            $pkgCountByDate[$eiDate] = ($pkgCountByDate[$eiDate] ?? 0) + 1;
            $eiPkgType = (string)($ei['pkg_type'] ?? '');
            if ($eiPkgType !== '') {
                $pkgTypeByDate[$eiDate][$eiPkgType] = true;
            }
            $ptKey = $pkgTypeToBudgetKey[$eiPkgType] ?? null;
            if ($ptKey !== null) {
                $hl = $ei['target_volume_hl'] !== null ? (float)$ei['target_volume_hl'] : $proxyHl;
                $existingPkgHl[$ptKey] += $hl;
            }
            // Also track serving_tank customer dedup
            if ($eiPkgType === 'serving_tank' && !empty($ei['customer_id_fk'])) {
                $existingServingTankCustomers[(int)$ei['customer_id_fk']] = true;
            }
        }
    }

    // ── Step 8: Net weekly budgets ────────────────────────────────────────────
    $brewWeekBudget = max(0, (int)floor($obj['brews']['week']) - $existingBrewCount);
    $pkgBudgetHl    = [
        'bottle_hl' => max(0.0, ($obj['bottle_hl']['week'] ?? 0.0) - $existingPkgHl['bottle_hl']),
        'can_hl'    => max(0.0, ($obj['can_hl']['week']    ?? 0.0) - $existingPkgHl['can_hl']),
        'keg_hl'    => max(0.0, ($obj['keg_hl']['week']    ?? 0.0) - $existingPkgHl['keg_hl']),
    ];

    // ── Step 9: Eligibility ───────────────────────────────────────────────────
    $eligibility = planning_week_eligibility($pdo, $weekStart);

    // Build $packagingEligByRecipe: recipe_id => sorted array of eligible working-day slots
    $packagingEligByRecipe = []; // recipe_id => [['day'=>..., 'bbt_number'=>..., 'beer_name'=>..., 'batch'=>...], ...]
    foreach ($eligibility as $dayStr => $dayElig) {
        if (!isset($workingDaySet[$dayStr])) continue; // skip non-working days
        foreach ($dayElig['packaging'] ?? [] as $slot) {
            $rid = isset($slot['recipe_id']) ? (int)$slot['recipe_id'] : null;
            if ($rid === null) continue;
            $packagingEligByRecipe[$rid][] = [
                'day'        => $dayStr,
                'bbt_number' => (int)($slot['bbt_number'] ?? 0),
                'beer_name'  => (string)($slot['beer'] ?? ''),
                'batch'      => (string)($slot['batch'] ?? ''),
            ];
        }
    }
    // Sort each recipe's eligible slots ascending by day
    foreach ($packagingEligByRecipe as $rid => &$slots) {
        usort($slots, fn($a, $b) => strcmp($a['day'], $b['day']));
    }
    unset($slots);

    // Per-day occupied CCT/BBT ledger, seeded from eligibility engine output.
    // As proposals are placed, we mutate this ledger forward from the proposed day.
    // Do NOT re-derive from raw tables — the engine is the only occupancy source.
    $occupiedCctByDate = []; // dateStr => int[] (CCT numbers occupied)
    $occupiedBbtByDate = []; // dateStr => int[] (BBT numbers occupied)
    foreach ($eligibility as $dayStr => $dayData) {
        $occupiedCctByDate[$dayStr] = $dayData['occupancy']['cct_occupied'] ?? [];
        $occupiedBbtByDate[$dayStr] = $dayData['occupancy']['bbt_occupied'] ?? [];
    }

    // ── Step 10: Sort needing recipes by worst_semaines asc (null → −INF first) ─
    uasort($needingRecipes, function($a, $b) {
        $sa = $a['worst_semaines'] !== null ? (float)$a['worst_semaines'] : -INF;
        $sb = $b['worst_semaines'] !== null ? (float)$b['worst_semaines'] : -INF;
        return $sa <=> $sb;
    });

    // ── Step 11: Build and insert proposals (4 ordered passes) ───────────────
    $inserted          = 0;
    $skippedDedup      = 0;
    $rows              = [];

    // Round-robin state for brewing placement
    $brewRoundRobinIdx    = 0;
    $allocatedCctsByDate  = []; // dateStr => int[] (CCTs allocated this run per day)

    // ── Pass 1: Racking (process-driven) ──────────────────────────────────────
    foreach ($eligibility as $dayStr => $dayData) {
        if (!isset($workingDaySet[$dayStr])) continue;

        foreach ($dayData['racking'] ?? [] as $entry) {
            $rid   = isset($entry['recipe_id']) ? (int)$entry['recipe_id'] : null;
            $batch = (string)($entry['batch'] ?? '');
            $cctN  = isset($entry['cct_number']) ? (int)$entry['cct_number'] : null;
            if ($rid === null || $cctN === null) continue;
            if (!isset($coreRecipeIds[$rid])) {
                $decisions[] = [
                    'beer'     => $entry['beer'] ?? '?',
                    'decision' => 'skipped',
                    'reason'   => 'hors périmètre core',
                    'batch'    => $batch,
                ];
                continue;
            }

            $dedupKey = 'wort:rack:' . $rid . ':' . $batch;
            if (isset($dedupSet[$dedupKey])) continue;

            // Check a free BBT exists
            $occupiedBbts = $occupiedBbtByDate[$dayStr] ?? [];
            $freeBbt = null;
            foreach ($activeBbtNumbers as $bbtNum) {
                if (!in_array($bbtNum, $occupiedBbts, true)) {
                    $freeBbt = $bbtNum;
                    break;
                }
            }
            if ($freeBbt === null) {
                $decisions[] = [
                    'beer'     => $entry['beer'] ?? '?',
                    'decision' => 'deferred',
                    'reason'   => 'aucun BBT libre pour transfert',
                    'batch'    => $batch,
                ];
                $dedupSet[$dedupKey] = true; // mark as evaluated — don't re-emit on later days
                continue;
            }

            $suggestReason = substr(
                'Garde atteinte — transfert CCT→BBT (BBT libre)',
                0, 255
            );

            // Get next seq
            $seqStmt = $pdo->prepare(
                "SELECT COALESCE(MAX(seq), 0) + 1 AS next_seq
                   FROM pl_plan_items
                  WHERE plan_date = ? AND section = 'wort' AND is_active = 1"
            );
            $seqStmt->execute([$dayStr]);
            $nextSeq = (int)($seqStmt->fetchColumn() ?: 1);

            // Upsert pl_plan_days
            $dayStmt = $pdo->prepare(
                'INSERT INTO pl_plan_days (plan_date, created_by_user_id_fk)
                 VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE updated_at = CURRENT_TIMESTAMP'
            );
            $dayStmt->execute([$dayStr, $createdByUserId]);

            $insStmt = $pdo->prepare(
                "INSERT INTO pl_plan_items
                   (plan_date, section, seq, wort_process, recipe_id_fk, batch,
                    cct_number, bbt_number, hors_process, suggest_reason, source, status, created_by_user_id_fk)
                 VALUES (?, 'wort', ?, 'racking', ?, ?, ?, ?, 0, ?, 'predictive', 'proposed', ?)"
            );
            $insStmt->execute([
                $dayStr, $nextSeq, $rid, $batch, $cctN, $freeBbt, $suggestReason, $createdByUserId,
            ]);
            $newId = (int)$pdo->lastInsertId();

            $afterData = [
                'plan_date'             => $dayStr,
                'section'               => 'wort',
                'seq'                   => $nextSeq,
                'wort_process'          => 'racking',
                'recipe_id_fk'          => $rid,
                'batch'                 => $batch,
                'cct_number'            => $cctN,
                'bbt_number'            => $freeBbt,
                'hors_process'          => 0,
                'suggest_reason'        => $suggestReason,
                'source'                => 'predictive',
                'status'                => 'proposed',
                'created_by_user_id_fk' => $createdByUserId,
            ];
            log_revision($pdo, $me, 'pl_plan_items', $newId, null, $afterData, 'normal', null);

            // Mutate ledger FORWARD from $dayStr through working week
            foreach ($workingDays as $wd) {
                $wdStr = $wd->format('Y-m-d');
                if ($wdStr >= $dayStr) {
                    // CCT is freed
                    $occupiedCctByDate[$wdStr] = array_values(
                        array_filter($occupiedCctByDate[$wdStr] ?? [], fn($n) => $n !== $cctN)
                    );
                    // BBT is now occupied
                    if (!in_array($freeBbt, $occupiedBbtByDate[$wdStr] ?? [], true)) {
                        $occupiedBbtByDate[$wdStr][] = $freeBbt;
                    }
                }
            }

            $dedupSet[$dedupKey] = true;
            $inserted++;
            $rows[]      = array_merge($afterData, ['id' => $newId]);
            $decisions[] = [
                'beer'     => $entry['beer'] ?? '?',
                'decision' => 'racking',
                'day'      => $dayStr,
                'cct'      => $cctN,
                'bbt'      => $freeBbt,
                'batch'    => $batch,
            ];
        }
    }

    // ── Pass 2: Packaging (demand-driven) ─────────────────────────────────────
    foreach ($needingRecipes as $rid => $rd) {
        $eligSlots = $packagingEligByRecipe[$rid] ?? [];

        $formatPkgType = predict_format_to_pkg_type($rd['format']);
        $pkgType = null;
        if ($formatPkgType !== null && in_array($formatPkgType, $activePkgTypes, true)) {
            $pkgType = $formatPkgType;
        } elseif (!empty($activePkgTypes)) {
            $pkgType = $activePkgTypes[0];
        }

        $budgetKey = ($pkgType !== null) ? ($pkgTypeToBudgetKey[$pkgType] ?? null) : null;

        if (empty($eligSlots) || $pkgType === null) continue;

        $dedupKey = 'packaging:rid:' . $rid;
        if (isset($dedupSet[$dedupKey])) {
            $skippedDedup++;
            $decisions[] = [
                'beer'     => $rd['beer_name'],
                'decision' => 'packaging',
                'day'      => $eligSlots[0]['day'],
                'skipped'  => 'dedup',
            ];
            continue;
        }

        // serving_tank proposals come from Pass 2.5 (client-recurrence), never from coverage
        if ($pkgType === 'serving_tank') {
            continue;
        }
        if ($budgetKey !== null && $pkgBudgetHl[$budgetKey] < $proxyHl) {
            $decisions[] = [
                'beer'     => $rd['beer_name'],
                'decision' => 'deferred',
                'reason'   => 'budget conditionnement hebdo atteint',
                'semaines' => $rd['worst_semaines'],
            ];
            continue;
        }

        // Load-balance: find eligible slot with fewest current pkg items; also check per-day cap
        $pkgSlot  = null;
        $minCount = PHP_INT_MAX;
        foreach ($eligSlots as $slot) {
            $slotDay = $slot['day'];
            if (($pkgCountByDate[$slotDay] ?? 0) >= $maxPkgRunsPerDay) continue;
            // Mutex: bottling+canning cannot share a day
            $mutexBlocked = false;
            if ($pkgType !== null) {
                foreach (MUTEX_PKG_PAIRS as [$typeA, $typeB]) {
                    $conflict = ($pkgType === $typeA) ? $typeB : (($pkgType === $typeB) ? $typeA : null);
                    if ($conflict !== null && isset($pkgTypeByDate[$slotDay][$conflict])) {
                        $mutexBlocked = true;
                        break;
                    }
                }
            }
            if ($mutexBlocked) continue;
            $cnt = $pkgCountByDate[$slotDay] ?? 0;
            if ($cnt < $minCount) {
                $minCount = $cnt;
                $pkgSlot  = $slot;
            }
        }

        if ($pkgSlot === null) {
            // Determine if mutex was the blocking cause (all slots had a mutex conflict)
            $mutexCause = false;
            if ($pkgType !== null && !empty($eligSlots)) {
                foreach (MUTEX_PKG_PAIRS as [$typeA, $typeB]) {
                    $conflict = ($pkgType === $typeA) ? $typeB : (($pkgType === $typeB) ? $typeA : null);
                    if ($conflict !== null) {
                        $allMutexBlocked = true;
                        foreach ($eligSlots as $slot) {
                            if (!isset($pkgTypeByDate[$slot['day']][$conflict])) {
                                $allMutexBlocked = false;
                                break;
                            }
                        }
                        if ($allMutexBlocked) { $mutexCause = true; break; }
                    }
                }
            }
            $deferReason = $mutexCause
                ? 'ligne partagée occupée (embouteillage/canettes)'
                : 'cap journalier conditionnement atteint ou aucun créneau';
            $decisions[] = [
                'beer'     => $rd['beer_name'],
                'decision' => 'deferred',
                'reason'   => $deferReason,
                'semaines' => $rd['worst_semaines'],
            ];
            continue;
        }

        $planDate = $pkgSlot['day'];
        $bbtNum   = $pkgSlot['bbt_number'] > 0 ? $pkgSlot['bbt_number'] : null;
        $batchVal = $pkgSlot['batch'] !== '' ? $pkgSlot['batch'] : null;

        $budgetDisplay = $budgetKey !== null ? $pkgBudgetHl[$budgetKey] : null;
        $budgetSuffix  = $budgetDisplay !== null
            ? sprintf(' (cap %.0fhl/sem)', $budgetDisplay)
            : '';
        $suggestReason = substr(
            sprintf('%s — conditionnement BBT %s%s', $rd['reason'], $bbtNum !== null ? (string)$bbtNum : '?', $budgetSuffix),
            0, 255
        );

        $seqStmt = $pdo->prepare(
            "SELECT COALESCE(MAX(seq), 0) + 1 AS next_seq
               FROM pl_plan_items
              WHERE plan_date = ? AND section = 'packaging' AND is_active = 1"
        );
        $seqStmt->execute([$planDate]);
        $nextSeq = (int)($seqStmt->fetchColumn() ?: 1);

        $dayStmt = $pdo->prepare(
            'INSERT INTO pl_plan_days (plan_date, created_by_user_id_fk)
             VALUES (?, ?)
             ON DUPLICATE KEY UPDATE updated_at = CURRENT_TIMESTAMP'
        );
        $dayStmt->execute([$planDate, $createdByUserId]);

        $insStmt = $pdo->prepare(
            "INSERT INTO pl_plan_items
               (plan_date, section, seq, pkg_type, recipe_id_fk, batch, bbt_number,
                hors_process, target_volume_hl, suggest_reason, source, status, created_by_user_id_fk)
             VALUES (?, 'packaging', ?, ?, ?, ?, ?, 0, ?, ?, 'predictive', 'proposed', ?)"
        );
        $insStmt->execute([
            $planDate, $nextSeq, $pkgType, $rid, $batchVal, $bbtNum,
            $proxyHl, $suggestReason, $createdByUserId,
        ]);
        $newId = (int)$pdo->lastInsertId();

        $afterData = [
            'plan_date'             => $planDate,
            'section'               => 'packaging',
            'seq'                   => $nextSeq,
            'pkg_type'              => $pkgType,
            'recipe_id_fk'          => $rid,
            'batch'                 => $batchVal,
            'bbt_number'            => $bbtNum,
            'hors_process'          => 0,
            'target_volume_hl'      => $proxyHl,
            'suggest_reason'        => $suggestReason,
            'source'                => 'predictive',
            'status'                => 'proposed',
            'created_by_user_id_fk' => $createdByUserId,
        ];
        log_revision($pdo, $me, 'pl_plan_items', $newId, null, $afterData, 'normal', null);

        // Update state
        $dedupSet[$dedupKey] = true;
        if ($budgetKey !== null) {
            $pkgBudgetHl[$budgetKey] -= $proxyHl;
        }
        $pkgCountByDate[$planDate] = ($pkgCountByDate[$planDate] ?? 0) + 1;
        $pkgTypeByDate[$planDate][$pkgType] = true;

        // Mutate ledger: BBT is freed from planDate onwards
        if ($bbtNum !== null) {
            foreach ($workingDays as $wd) {
                $wdStr = $wd->format('Y-m-d');
                if ($wdStr >= $planDate) {
                    $occupiedBbtByDate[$wdStr] = array_values(
                        array_filter($occupiedBbtByDate[$wdStr] ?? [], fn($n) => $n !== $bbtNum)
                    );
                }
            }
        }

        $inserted++;
        $rows[]      = array_merge($afterData, ['id' => $newId]);
        $decisions[] = [
            'beer'     => $rd['beer_name'],
            'decision' => 'packaging',
            'day'      => $planDate,
            'bbt'      => $bbtNum,
            'pkg_type' => $pkgType,
            'semaines' => $rd['best_semaines'],
        ];
    }

    // ── Pass 2.5: Serving-tank proposals (client-recurrence) ─────────────────
    // Propose a cuve fill per recurring client whose next fill is due within the planned week
    // (or overdue). Cadence derived from sales history — cadence column ignored.
    $servingTankClients  = predict_load_serving_tank_clients($pdo);
    $remainingTankSlots  = $freeServingTankCount; // count of free in-house serving tanks (week-level cap)

    foreach ($servingTankClients as $stc) {
        $clientId   = $stc['client_id'];
        $clientName = $stc['client_name'];

        // Skip if client already has a serving_tank proposal/plan this week
        if (isset($existingServingTankCustomers[$clientId])) {
            $decisions[] = [
                'client'   => $clientName,
                'decision' => 'skipped',
                'reason'   => 'déjà planifié cette semaine',
            ];
            continue;
        }

        $nextExpected = $stc['next_expected'];

        // Due test: next_expected must be <= weekEnd (includes overdue)
        if ($nextExpected > $weekEndStr) {
            $decisions[] = [
                'client'   => $clientName,
                'decision' => 'skipped',
                'reason'   => sprintf('pas encore dû (%s)', $nextExpected),
            ];
            continue;
        }

        // Cap: bound by free serving-tank count
        if ($remainingTankSlots <= 0) {
            $decisions[] = [
                'client'   => $clientName,
                'decision' => 'deferred',
                'reason'   => 'aucune cuve de service libre',
            ];
            continue;
        }

        // Determine placement day:
        // - If next_expected is within [weekStart, weekEnd] and is a working day → that day
        // - If overdue (< weekStart) → earliest working day in week
        // - If due-in-week but not a working day → nearest working day within week
        $planDate = null;
        if ($nextExpected < $weekStartStr) {
            // Overdue: earliest working day
            $planDate = $workingDays[0]->format('Y-m-d');
        } elseif (isset($workingDaySet[$nextExpected])) {
            // Due this week on a working day
            $planDate = $nextExpected;
        } else {
            // Due this week but not a working day — find nearest working day in week
            $targetDt = new DateTimeImmutable($nextExpected);
            $nearestDay = null;
            $nearestDiff = PHP_INT_MAX;
            foreach ($workingDays as $wd) {
                $diff = abs((int)(new DateTimeImmutable($wd->format('Y-m-d')))->diff($targetDt)->days);
                if ($diff < $nearestDiff) {
                    $nearestDiff = $diff;
                    $nearestDay  = $wd->format('Y-m-d');
                }
            }
            $planDate = $nearestDay ?? $workingDays[0]->format('Y-m-d');
        }

        // Per-day packaging cap check
        if (($pkgCountByDate[$planDate] ?? 0) >= $maxPkgRunsPerDay) {
            // Try other working days
            $fallbackDate = null;
            foreach ($workingDays as $wd) {
                $wdStr = $wd->format('Y-m-d');
                if (($pkgCountByDate[$wdStr] ?? 0) < $maxPkgRunsPerDay) {
                    $fallbackDate = $wdStr;
                    break;
                }
            }
            if ($fallbackDate === null) {
                $decisions[] = [
                    'client'   => $clientName,
                    'decision' => 'deferred',
                    'reason'   => 'cap journalier conditionnement atteint tous les jours ouvrés',
                ];
                continue;
            }
            $planDate = $fallbackDate;
        }

        // Budget pace-check + capacity cap
        $budgetHl   = $stc['budget_hl'];
        $tankCount  = $stc['tank_count'];
        $tankSizeHl = $stc['tank_size_hl'];
        $targetHl   = $stc['target_volume_hl']; // base from median

        // Capacity cap (only when both count AND size are set and > 0)
        if ($tankCount !== null && $tankSizeHl !== null && $tankCount > 0 && $tankSizeHl > 0) {
            $maxCapacity = $tankCount * $tankSizeHl;
            $targetHl = min($targetHl, $maxCapacity);
        }

        // Budget pace-check (only when budget is set and > 0)
        if ($budgetHl !== null && $budgetHl > 0) {
            // actual_month_hl: real cuve consumption this client had this month in inv_sales_ledger
            $mstart = (new DateTimeImmutable($planDate))->modify('first day of this month')->format('Y-m-d');
            $mnext  = (new DateTimeImmutable($planDate))->modify('first day of next month')->format('Y-m-d');

            $actualStmt = $pdo->prepare(
                'SELECT COALESCE(SUM(ABS(l.qty_signed)), 0) / 100
                   FROM inv_sales_ledger l
                   JOIN ref_skus s ON s.id = l.sku_id_fk AND s.format = \'Cuve de service\'
                  WHERE l.customer_id_fk = ? AND l.qty_signed < 0
                    AND l.posting_date >= ? AND l.posting_date < ?'
            );
            $actualStmt->execute([$clientId, $mstart, $mnext]);
            $actualMonthHl = (float)$actualStmt->fetchColumn();

            // planned_month_hl: active pl_plan_items for this client this month
            $plannedStmt = $pdo->prepare(
                "SELECT COALESCE(SUM(target_volume_hl), 0)
                   FROM pl_plan_items
                  WHERE pkg_type = 'serving_tank'
                    AND customer_id_fk = ?
                    AND plan_date >= ? AND plan_date < ?
                    AND is_active = 1"
            );
            $plannedStmt->execute([$clientId, $mstart, $mnext]);
            $plannedMonthHl = (float)$plannedStmt->fetchColumn();

            $usedHl    = $actualMonthHl + $plannedMonthHl;
            $remaining = $budgetHl - $usedHl;

            if ($remaining <= 0) {
                $decisions[] = [
                    'client'   => $clientName,
                    'decision' => 'skipped',
                    'reason'   => sprintf(
                        'budget mensuel atteint (%.1f/%.1f hl)',
                        $usedHl,
                        $budgetHl
                    ),
                ];
                continue;
            }

            // Cap volume to remaining budget
            $targetHl = min($targetHl, $remaining);
        }

        $targetHl = round($targetHl, 2);

        // Safety: never propose non-positive volume
        if ($targetHl <= 0) {
            $decisions[] = [
                'client'   => $clientName,
                'decision' => 'skipped',
                'reason'   => 'volume calculé nul ou négatif après plafonds',
            ];
            continue;
        }

        $rid        = $stc['recipe_id'];
        $recipeName = $stc['recipe_name'];
        // $targetHl already computed above (after caps applied)

        $paceCtx = '';
        if (isset($budgetHl) && $budgetHl !== null && $budgetHl > 0) {
            $paceCtx = sprintf(' (mois %.1f/%.1f hl)', $usedHl, $budgetHl);
        }

        $suggestReason = substr(
            sprintf(
                'Cuve de service — %s (dû %s, cadence %dj)%s',
                $clientName,
                $nextExpected,
                $stc['cadence_days'],
                $paceCtx
            ),
            0, 255
        );

        // Get next seq
        $seqStmt = $pdo->prepare(
            "SELECT COALESCE(MAX(seq), 0) + 1 AS next_seq
               FROM pl_plan_items
              WHERE plan_date = ? AND section = 'packaging' AND is_active = 1"
        );
        $seqStmt->execute([$planDate]);
        $nextSeq = (int)($seqStmt->fetchColumn() ?: 1);

        // Upsert pl_plan_days
        $dayStmt = $pdo->prepare(
            'INSERT INTO pl_plan_days (plan_date, created_by_user_id_fk)
             VALUES (?, ?)
             ON DUPLICATE KEY UPDATE updated_at = CURRENT_TIMESTAMP'
        );
        $dayStmt->execute([$planDate, $createdByUserId]);

        $insStmt = $pdo->prepare(
            "INSERT INTO pl_plan_items
               (plan_date, section, seq, pkg_type, recipe_id_fk, customer_id_fk,
                target_volume_hl, hors_process, suggest_reason, source, status, created_by_user_id_fk)
             VALUES (?, 'packaging', ?, 'serving_tank', ?, ?, ?, 0, ?, 'predictive', 'proposed', ?)"
        );
        $insStmt->execute([
            $planDate, $nextSeq, $rid, $clientId, $targetHl, $suggestReason, $createdByUserId,
        ]);
        $newId = (int)$pdo->lastInsertId();

        $afterData = [
            'plan_date'             => $planDate,
            'section'               => 'packaging',
            'seq'                   => $nextSeq,
            'pkg_type'              => 'serving_tank',
            'recipe_id_fk'          => $rid,
            'customer_id_fk'        => $clientId,
            'target_volume_hl'      => $targetHl,
            'hors_process'          => 0,
            'suggest_reason'        => $suggestReason,
            'source'                => 'predictive',
            'status'                => 'proposed',
            'created_by_user_id_fk' => $createdByUserId,
        ];
        log_revision($pdo, $me, 'pl_plan_items', $newId, null, $afterData, 'normal', null);

        // Update state
        $existingServingTankCustomers[$clientId] = true;
        $remainingTankSlots--;
        $pkgCountByDate[$planDate] = ($pkgCountByDate[$planDate] ?? 0) + 1;
        $pkgTypeByDate[$planDate]['serving_tank'] = true;

        $inserted++;
        $rows[]      = array_merge($afterData, ['id' => $newId]);
        $decisions[] = [
            'client'    => $clientName,
            'beer'      => $recipeName,
            'decision'  => 'serving_tank',
            'day'       => $planDate,
            'target_hl' => $targetHl,
            'cadence'   => $stc['cadence_days'],
            'next_exp'  => $nextExpected,
        ];
    }

    // ── Pass 3: Brewing (demand-driven, round-robin) ───────────────────────────
    foreach ($needingRecipes as $rid => $rd) {
        // Only brew if no packaging slot was found (or budget exhausted)
        if (!empty($packagingEligByRecipe[$rid] ?? [])) {
            // Packaging was the preferred path — skip brewing unless packaging was skipped
            // (dedup check already happened in pass 2; don't double-count)
            if (isset($dedupSet['packaging:rid:' . $rid])) continue;
            // packaging eligible but not yet placed — brewing not needed
            continue;
        }

        $dedupKey = 'wort:rid:' . $rid;
        if (isset($dedupSet[$dedupKey])) {
            $skippedDedup++;
            $decisions[] = [
                'beer'     => $rd['beer_name'],
                'decision' => 'brewing',
                'day'      => null,
                'skipped'  => 'dedup',
            ];
            continue;
        }

        if ($brewWeekBudget <= 0) {
            $decisions[] = [
                'beer'     => $rd['beer_name'],
                'decision' => 'deferred',
                'reason'   => 'capacité brassins semaine atteinte',
                'semaines' => $rd['worst_semaines'],
            ];
            continue;
        }

        $wdCount      = count($workingDays);
        $freeCct      = null;
        $planDate     = null;
        $chosenDayIdx = null;

        for ($attempt = 0; $attempt < $wdCount; $attempt++) {
            $dayIdx = ($brewRoundRobinIdx + $attempt) % $wdCount;
            $wd     = $workingDays[$dayIdx];
            $dayStr = $wd->format('Y-m-d');

            if (($placedBrewsByDate[$dayStr] ?? 0) >= $maxBrewsPerDay) continue;

            $eligConflicts = $occupiedCctByDate[$dayStr] ?? [];
            $runConflicts  = $allocatedCctsByDate[$dayStr] ?? [];
            $allConflicts  = array_unique(array_merge($eligConflicts, $runConflicts));

            foreach ($activeCctNumbers as $cctNum) {
                if (!in_array($cctNum, $allConflicts, true)) {
                    $freeCct      = $cctNum;
                    $planDate     = $dayStr;
                    $chosenDayIdx = $dayIdx;
                    break 2;
                }
            }
        }

        if ($freeCct === null || $planDate === null) {
            $decisions[] = [
                'beer'     => $rd['beer_name'],
                'decision' => 'deferred',
                'reason'   => 'aucun CCT libre ou capacité journalière atteinte',
                'semaines' => $rd['worst_semaines'],
            ];
            continue;
        }

        $dayOffset     = (int)$weekStart->diff(new DateTimeImmutable($planDate))->days;
        $suggestReason = substr(
            sprintf('%s — brassin J+%d (cap %d/j)', $rd['reason'], $dayOffset, $maxBrewsPerDay),
            0, 255
        );

        $seqStmt = $pdo->prepare(
            "SELECT COALESCE(MAX(seq), 0) + 1 AS next_seq
               FROM pl_plan_items
              WHERE plan_date = ? AND section = 'wort' AND is_active = 1"
        );
        $seqStmt->execute([$planDate]);
        $nextSeq = (int)($seqStmt->fetchColumn() ?: 1);

        $dayStmt = $pdo->prepare(
            'INSERT INTO pl_plan_days (plan_date, created_by_user_id_fk)
             VALUES (?, ?)
             ON DUPLICATE KEY UPDATE updated_at = CURRENT_TIMESTAMP'
        );
        $dayStmt->execute([$planDate, $createdByUserId]);

        $insStmt = $pdo->prepare(
            "INSERT INTO pl_plan_items
               (plan_date, section, seq, wort_process, recipe_id_fk, cct_number,
                hors_process, suggest_reason, source, status, created_by_user_id_fk)
             VALUES (?, 'wort', ?, 'brewing', ?, ?, 0, ?, 'predictive', 'proposed', ?)"
        );
        $insStmt->execute([
            $planDate, $nextSeq, $rid, $freeCct, $suggestReason, $createdByUserId,
        ]);
        $newId = (int)$pdo->lastInsertId();

        $afterData = [
            'plan_date'             => $planDate,
            'section'               => 'wort',
            'seq'                   => $nextSeq,
            'wort_process'          => 'brewing',
            'recipe_id_fk'          => $rid,
            'cct_number'            => $freeCct,
            'hors_process'          => 0,
            'suggest_reason'        => $suggestReason,
            'source'                => 'predictive',
            'status'                => 'proposed',
            'created_by_user_id_fk' => $createdByUserId,
        ];
        log_revision($pdo, $me, 'pl_plan_items', $newId, null, $afterData, 'normal', null);

        $dedupSet[$dedupKey] = true;
        $brewWeekBudget--;
        $placedBrewsByDate[$planDate] = ($placedBrewsByDate[$planDate] ?? 0) + 1;
        $allocatedCctsByDate[$planDate][] = $freeCct;
        // Mutate ledger: CCT is now occupied from planDate forward
        foreach ($workingDays as $wd) {
            $wdStr = $wd->format('Y-m-d');
            if ($wdStr >= $planDate) {
                if (!in_array($freeCct, $occupiedCctByDate[$wdStr] ?? [], true)) {
                    $occupiedCctByDate[$wdStr][] = $freeCct;
                }
            }
        }
        $brewRoundRobinIdx = ($chosenDayIdx + 1) % $wdCount;

        $inserted++;
        $rows[]      = array_merge($afterData, ['id' => $newId]);
        $decisions[] = [
            'beer'     => $rd['beer_name'],
            'decision' => 'brewing',
            'day'      => $planDate,
            'cct'      => $freeCct,
            'semaines' => $rd['best_semaines'],
        ];
    }

    // ── Pass 4: Dry-hop (process-driven, HARD-GATED) ──────────────────────────
    // v1 LIMITATION: serving-tank free count assumes all in-house active tanks are empty at
    // week start. TankSimulator does not model serving-tank occupancy (TODO ~L648 tank-simulator.php).
    // This may over-propose serving-tank packaging. Do not bodge a bd_packaging_v2 occupancy reader.

    $decisions[] = ['note' => 'v1 limitation: serving tank count assumes all in-house tanks empty at week start (TankSimulator gap)'];

    // Collect dry-hop candidates: (recipe_id, batch) pairs from all days' dry_hopping lists
    $dhCandidates = []; // 'rid:batch' => ['recipe_id'=>int, 'batch'=>string, 'cct_number'=>int, 'beer'=>string]
    foreach ($eligibility as $dayStr => $dayData) {
        foreach ($dayData['dry_hopping'] ?? [] as $entry) {
            $rid   = isset($entry['recipe_id']) ? (int)$entry['recipe_id'] : null;
            $batch = (string)($entry['batch'] ?? '');
            $cctN  = isset($entry['cct_number']) ? (int)$entry['cct_number'] : null;
            if ($rid === null || $cctN === null) continue;
            if (!isset($coreRecipeIds[$rid])) continue;
            // Only keep if recipe is in dry-hop spec
            if (!in_array($rid, $dryHopRecipeIds, true)) continue;
            $key = $rid . ':' . $batch;
            if (!isset($dhCandidates[$key])) {
                $dhCandidates[$key] = [
                    'recipe_id'  => $rid,
                    'batch'      => $batch,
                    'cct_number' => $cctN,
                    'beer'       => (string)($entry['beer'] ?? ''),
                ];
            }
        }
    }

    // Check which are already dry-hopped
    $alreadyDryHopped = predict_batches_already_dry_hopped($pdo, array_values($dhCandidates));

    foreach ($dhCandidates as $key => $candidate) {
        $rid   = $candidate['recipe_id'];
        $batch = $candidate['batch'];
        $cctN  = $candidate['cct_number'];

        // Gate: must not already be dry-hopped
        if (isset($alreadyDryHopped[$rid . ':' . $batch])) {
            $decisions[] = [
                'beer'     => $candidate['beer'],
                'decision' => 'deferred',
                'reason'   => 'dry-hop déjà effectué (bd_fermenting_v2)',
                'batch'    => $batch,
            ];
            continue;
        }

        $dedupKey = 'wort:dryhop:' . $rid . ':' . $batch;
        if (isset($dedupSet[$dedupKey])) continue;

        // Find first working day where this CCT is still occupied
        $planDate = null;
        foreach ($workingDays as $wd) {
            $wdStr = $wd->format('Y-m-d');
            if (in_array($cctN, $occupiedCctByDate[$wdStr] ?? [], true)) {
                $planDate = $wdStr;
                break;
            }
        }

        if ($planDate === null) {
            $decisions[] = [
                'beer'     => $candidate['beer'],
                'decision' => 'deferred',
                'reason'   => 'CCT non occupé sur aucun jour ouvré de la semaine',
                'batch'    => $batch,
            ];
            continue;
        }

        $suggestReason = substr(
            'Recette sèche — dry-hop non encore effectué',
            0, 255
        );

        $seqStmt = $pdo->prepare(
            "SELECT COALESCE(MAX(seq), 0) + 1 AS next_seq
               FROM pl_plan_items
              WHERE plan_date = ? AND section = 'wort' AND is_active = 1"
        );
        $seqStmt->execute([$planDate]);
        $nextSeq = (int)($seqStmt->fetchColumn() ?: 1);

        $dayStmt = $pdo->prepare(
            'INSERT INTO pl_plan_days (plan_date, created_by_user_id_fk)
             VALUES (?, ?)
             ON DUPLICATE KEY UPDATE updated_at = CURRENT_TIMESTAMP'
        );
        $dayStmt->execute([$planDate, $createdByUserId]);

        $insStmt = $pdo->prepare(
            "INSERT INTO pl_plan_items
               (plan_date, section, seq, wort_process, recipe_id_fk, batch,
                cct_number, hors_process, suggest_reason, source, status, created_by_user_id_fk)
             VALUES (?, 'wort', ?, 'dry_hopping', ?, ?, ?, 0, ?, 'predictive', 'proposed', ?)"
        );
        $insStmt->execute([
            $planDate, $nextSeq, $rid, $batch, $cctN, $suggestReason, $createdByUserId,
        ]);
        $newId = (int)$pdo->lastInsertId();

        $afterData = [
            'plan_date'             => $planDate,
            'section'               => 'wort',
            'seq'                   => $nextSeq,
            'wort_process'          => 'dry_hopping',
            'recipe_id_fk'          => $rid,
            'batch'                 => $batch,
            'cct_number'            => $cctN,
            'hors_process'          => 0,
            'suggest_reason'        => $suggestReason,
            'source'                => 'predictive',
            'status'                => 'proposed',
            'created_by_user_id_fk' => $createdByUserId,
        ];
        log_revision($pdo, $me, 'pl_plan_items', $newId, null, $afterData, 'normal', null);

        // Dry-hop is occupancy-neutral: do NOT mutate ledger
        $dedupSet[$dedupKey] = true;
        $inserted++;
        $rows[]      = array_merge($afterData, ['id' => $newId]);
        $decisions[] = [
            'beer'     => $candidate['beer'],
            'decision' => 'dry_hopping',
            'day'      => $planDate,
            'cct'      => $cctN,
            'batch'    => $batch,
        ];
    }

    return [
        'inserted'      => $inserted,
        'skipped_dedup' => $skippedDedup,
        'rows'          => $rows,
        'decisions'     => $decisions,
    ];
}
