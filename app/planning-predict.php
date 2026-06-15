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
 *   app/fg-stock.php          (fg_stock_compute)
 *   app/planning-eligibility.php (planning_week_eligibility)
 *   app/settings.php          (system_setting)
 *   app/db-write-helpers.php  (log_revision)
 *   app/db.php                (maltytask_pdo — transitively via the above)
 */

require_once __DIR__ . '/fg-stock.php';
require_once __DIR__ . '/planning-eligibility.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/db-write-helpers.php';

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

// ── Main entry point ──────────────────────────────────────────────────────────

/**
 * Generate predictive plan suggestions for the given week.
 *
 * Algorithm:
 *   1. Read plan_suggest_target_weeks from system_settings (default 3.0).
 *   2. Read fg_stock_compute() per-SKU coverage.
 *   3. Aggregate to recipe level: track best_semaines (highest), worst_physique,
 *      format (from best-coverage SKU), velocity, beer display name.
 *   4. Filter: recipe needs replenishment when best_semaines < target OR
 *      (best_semaines === null AND worst_physique <= 0).
 *   5. Load planning_week_eligibility for the week.
 *   6. Dedup: skip if active item for same recipe+section already exists this week.
 *   7. For each needing recipe:
 *      a. If packaging-eligible → propose packaging on earliest eligible day.
 *      b. Else → propose brewing (section=wort, wort_process=brewing) on Monday.
 *   8. INSERT with source='predictive', status='proposed'. log_revision on each.
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

    // ── Step 0: Config ────────────────────────────────────────────────────────
    $targetWeeks    = (float) system_setting('plan_suggest_target_weeks', 'stock', 3.0);
    $activePkgTypes = predict_load_active_pkg_types($pdo);
    $me             = predict_load_user($pdo, $createdByUserId);

    // ── Step 1: FG stock coverage ─────────────────────────────────────────────
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

    // ── Step 2: Aggregate to recipe level ─────────────────────────────────────
    // Per recipe: track the best (highest) semaines_stock and worst (lowest) physique
    // across all its SKUs. We use 'format' and 'velocity' from the best-coverage SKU.
    $byRecipe = []; // recipe_id (int) => [recipe_id, beer_name, best_semaines, worst_physique, format, velocity]
    foreach ($fgRows as $row) {
        $skuId = (int)($row['sku_id'] ?? 0);
        $rid   = $skuRecipeMap[$skuId] ?? null;
        if ($rid === null) {
            continue; // cannot suggest without recipe FK
        }
        $rid = (int) $rid;

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

    // ── Step 3: Filter needing replenishment ──────────────────────────────────
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

    // ── Step 4: Eligibility for the week ──────────────────────────────────────
    $eligibility = planning_week_eligibility($pdo, $weekStart);

    // Build packaging-eligible recipe → earliest {day, bbt_number, beer_name, batch}
    $packagingByRecipe = [];
    foreach ($eligibility as $dayStr => $dayElig) {
        foreach ($dayElig['packaging'] ?? [] as $slot) {
            $rid = isset($slot['recipe_id']) ? (int)$slot['recipe_id'] : null;
            if ($rid === null) {
                continue;
            }
            // Keep only the earliest day for each recipe
            if (!isset($packagingByRecipe[$rid])) {
                $packagingByRecipe[$rid] = [
                    'day'        => $dayStr,
                    'bbt_number' => (int)($slot['bbt_number'] ?? 0),
                    'beer_name'  => (string)($slot['beer'] ?? ''),
                    'batch'      => (string)($slot['batch'] ?? ''),
                ];
            }
        }
    }

    // Build the set of CCTs already in conflict on Monday (from eligibility + existing planned items)
    $brewDayConflicts = array_merge(
        $eligibility[$weekStartStr]['brewing']['cct_conflicts'] ?? [],
        []
    );
    // $allocatedCcts tracks CCTs assigned by THIS generator run (to avoid duplicates within the run)
    $allocatedCcts = [];

    // ── Step 5: Dedup check — existing active items for the week ──────────────
    $existStmt = $pdo->prepare(
        "SELECT section, recipe_id_fk
           FROM pl_plan_items
          WHERE plan_date BETWEEN ? AND ?
            AND is_active = 1
            AND section IN ('wort','packaging')"
    );
    $existStmt->execute([$weekStartStr, $weekEndStr]);

    $dedupSet = [];
    foreach ($existStmt->fetchAll(PDO::FETCH_ASSOC) as $ei) {
        if (!empty($ei['recipe_id_fk'])) {
            $dedupSet[$ei['section'] . ':rid:' . (int)$ei['recipe_id_fk']] = true;
        }
    }

    // ── Step 6: Build and insert proposals ────────────────────────────────────
    $inserted     = 0;
    $skippedDedup = 0;
    $rows         = [];
    $decisions    = [];

    foreach ($needingRecipes as $rid => $rd) {
        $pkgSlot = $packagingByRecipe[$rid] ?? null;

        if ($pkgSlot !== null) {
            // ── Packaging proposal ────────────────────────────────────────────
            $planDate  = $pkgSlot['day'];
            $dedupKey  = 'packaging:rid:' . $rid;

            if (isset($dedupSet[$dedupKey])) {
                $skippedDedup++;
                $decisions[] = [
                    'beer'    => $rd['beer_name'],
                    'decision'=> 'packaging',
                    'day'     => $planDate,
                    'skipped' => 'dedup',
                ];
                continue;
            }

            // Determine pkg_type from format → active fillers
            $formatPkgType = predict_format_to_pkg_type($rd['format']);
            $pkgType = null;
            if ($formatPkgType !== null && in_array($formatPkgType, $activePkgTypes, true)) {
                $pkgType = $formatPkgType;
            } elseif (!empty($activePkgTypes)) {
                // Fallback: first active pkg_type
                $pkgType = $activePkgTypes[0];
            }

            if ($pkgType === null) {
                $decisions[] = [
                    'beer'    => $rd['beer_name'],
                    'decision'=> 'packaging_skipped',
                    'reason'  => 'no_active_filler',
                ];
                continue;
            }

            $bbtNum        = $pkgSlot['bbt_number'] > 0 ? $pkgSlot['bbt_number'] : null;
            $batchVal      = $pkgSlot['batch'] !== '' ? $pkgSlot['batch'] : null;
            $suggestReason = substr(
                sprintf('%s — BBT %s', $rd['reason'], $bbtNum !== null ? (string)$bbtNum : '?'),
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

            // Insert proposal
            $insStmt = $pdo->prepare(
                "INSERT INTO pl_plan_items
                   (plan_date, section, seq, pkg_type, recipe_id_fk, batch, bbt_number,
                    hors_process, suggest_reason, source, status, created_by_user_id_fk)
                 VALUES (?, 'packaging', ?, ?, ?, ?, ?, 0, ?, 'predictive', 'proposed', ?)"
            );
            $insStmt->execute([
                $planDate,
                $nextSeq,
                $pkgType,
                $rid,
                $batchVal,
                $bbtNum,
                $suggestReason,
                $createdByUserId,
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
                'suggest_reason'        => $suggestReason,
                'source'                => 'predictive',
                'status'                => 'proposed',
                'created_by_user_id_fk' => $createdByUserId,
            ];
            log_revision($pdo, $me, 'pl_plan_items', $newId, null, $afterData, 'normal', null);

            $dedupSet[$dedupKey] = true;
            $inserted++;
            $rows[]      = array_merge($afterData, ['id' => $newId]);
            $decisions[] = [
                'beer'    => $rd['beer_name'],
                'decision'=> 'packaging',
                'day'     => $planDate,
                'bbt'     => $bbtNum,
                'pkg_type'=> $pkgType,
                'semaines'=> $rd['best_semaines'],
            ];

        } else {
            // ── Brewing proposal ──────────────────────────────────────────────
            $planDate  = $weekStartStr; // suggest Monday
            $dedupKey  = 'wort:rid:' . $rid;

            // Pick a free CCT not in conflicts AND not already allocated this run
            $freeCct = null;
            for ($cctNum = 1; $cctNum <= 10; $cctNum++) {
                if (!in_array($cctNum, $brewDayConflicts, true)
                    && !in_array($cctNum, $allocatedCcts, true)) {
                    $freeCct = $cctNum;
                    break;
                }
            }
            if ($freeCct === null) {
                $decisions[] = [
                    'beer'    => $rd['beer_name'],
                    'decision'=> 'brewing_skipped',
                    'reason'  => 'no_free_cct',
                ];
                continue;
            }

            if (isset($dedupSet[$dedupKey])) {
                $skippedDedup++;
                $decisions[] = [
                    'beer'    => $rd['beer_name'],
                    'decision'=> 'brewing',
                    'day'     => $planDate,
                    'skipped' => 'dedup',
                ];
                continue;
            }

            $suggestReason = substr(
                sprintf('%s — pas de liquid éligible cette semaine', $rd['reason']),
                0, 255
            );

            // Get next seq
            $seqStmt = $pdo->prepare(
                "SELECT COALESCE(MAX(seq), 0) + 1 AS next_seq
                   FROM pl_plan_items
                  WHERE plan_date = ? AND section = 'wort' AND is_active = 1"
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

            // Insert brewing proposal
            $insStmt = $pdo->prepare(
                "INSERT INTO pl_plan_items
                   (plan_date, section, seq, wort_process, recipe_id_fk, cct_number,
                    hors_process, suggest_reason, source, status, created_by_user_id_fk)
                 VALUES (?, 'wort', ?, 'brewing', ?, ?, 0, ?, 'predictive', 'proposed', ?)"
            );
            $insStmt->execute([
                $planDate,
                $nextSeq,
                $rid,
                $freeCct,
                $suggestReason,
                $createdByUserId,
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
            $allocatedCcts[] = $freeCct;
            $inserted++;
            $rows[]      = array_merge($afterData, ['id' => $newId]);
            $decisions[] = [
                'beer'    => $rd['beer_name'],
                'decision'=> 'brewing',
                'day'     => $planDate,
                'cct'     => $freeCct,
                'semaines'=> $rd['best_semaines'],
            ];
        }
    }

    return [
        'inserted'      => $inserted,
        'skipped_dedup' => $skippedDedup,
        'rows'          => $rows,
        'decisions'     => $decisions,
    ];
}
