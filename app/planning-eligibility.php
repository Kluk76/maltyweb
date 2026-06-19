<?php
declare(strict_types=1);
/**
 * app/planning-eligibility.php — Forward-replay eligibility engine for the Planning page.
 *
 * CARDINAL RULE: Pure read. NEVER writes to bd_*, pl_*, or any canonical table.
 *
 * Public entry point:
 *   planning_week_eligibility(PDO $pdo, DateTimeImmutable $weekStart): array
 *
 * Returns per-day per-process eligible sets for 7 days starting $weekStart.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/tank-simulator.php';
require_once __DIR__ . '/yeast-eligibility.php';

// ── Garde map loader ──────────────────────────────────────────────────────────

/**
 * Load garde_days_min for all active recipes.
 * Returns ['by_rid' => [recipe_id => int|null, ...], 'by_beer' => [name => int|null, ...]]
 *
 * NULL garde = unresolved — beer cannot be racking-eligible via time gate.
 */
function planning_load_garde_map(PDO $pdo): array
{
    $joins = yeast_eligibility_join_fragment();
    $exprs = yeast_eligibility_select_expressions();
    $selectList = implode(', ', $exprs);

    $sql = "SELECT r.id AS recipe_id, r.name AS recipe_name, {$selectList}
              FROM ref_recipes r
              {$joins}
             WHERE r.is_active = 1";

    $stmt = $pdo->query($sql);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $byRid  = [];
    $byBeer = [];
    foreach ($rows as $row) {
        $rid   = (int)$row['recipe_id'];
        $garde = $row['effective_garde'] !== null ? (int)$row['effective_garde'] : null;
        $name  = (string)$row['recipe_name'];

        $byRid[$rid] = $garde;

        // For beer-name fallback: keep the garde from any resolved recipe for this name.
        // If multiple recipes share the same name, the last non-NULL garde wins.
        if (!isset($byBeer[$name]) || ($garde !== null && $byBeer[$name] === null)) {
            $byBeer[$name] = $garde;
        }
    }

    return ['by_rid' => $byRid, 'by_beer' => $byBeer];
}

// ── Date diff helper (days) ───────────────────────────────────────────────────

/**
 * Returns the number of whole days from $from to $to (positive when $to > $from).
 */
function planning_date_diff_days(string $from, DateTimeImmutable $to): int
{
    $fromDt = new DateTimeImmutable($from);
    $diff   = $fromDt->diff($to);
    // diff->days is always positive; use invert to determine sign.
    return $diff->invert ? -$diff->days : $diff->days;
}

// ── Public entry point ────────────────────────────────────────────────────────

/**
 * Compute forward-replay eligibility for 7 days starting $weekStart.
 *
 * Returns:
 * [
 *   'YYYY-MM-DD' => [
 *     'racking'     => [['recipe_id'=>int|null,'beer'=>string,'batch'=>string,'cct_number'=>int,'source'=>'real'], ...],
 *     'kze'         => [['recipe_id'=>int|null,'beer'=>string,'batch'=>string,'cct_number'=>int,'source'=>string], ...],
 *     'dry_hopping' => [['recipe_id'=>int|null,'beer'=>string,'batch'=>string,'cct_number'=>int,'source'=>string], ...],
 *     'packaging'   => [['recipe_id'=>int|null,'beer'=>string,'batch'=>string,'bbt_number'=>int,'racked_on'=>string,'source'=>string], ...],
 *     'brewing'     => ['cct_conflicts' => [int, ...]],
 *   ],
 *   ...
 * ]
 */
function planning_week_eligibility(PDO $pdo, DateTimeImmutable $weekStart): array
{
    // ── Step 1: min_days_after_racking from commissioning_settings ────────────
    $settingStmt = $pdo->prepare(
        "SELECT value_num, default_num FROM commissioning_settings
          WHERE section = 'packaging' AND key_name = 'min_days_after_racking'
            AND is_active = 1 LIMIT 1"
    );
    $settingStmt->execute();
    $settingRow = $settingStmt->fetch(PDO::FETCH_ASSOC);
    $minDays = $settingRow !== false
        ? (int)($settingRow['value_num'] ?? $settingRow['default_num'] ?? 1)
        : 1;

    // ── Step 2: Load garde map ────────────────────────────────────────────────
    $gardeMap = planning_load_garde_map($pdo);

    // ── Step 3: Seed working state from TankSimulator ─────────────────────────
    $sim      = new TankSimulator($pdo);
    $snapshot = $sim->run(new DateTimeImmutable($weekStart->format('Y-m-d')));

    // Build working CCT map: [tankNum => ['beer','batch','recipe_id','cold_crash_date']]
    $workingCct = [];
    foreach ($snapshot['cct'] as $tankNum => $state) {
        if ($state === null) continue;
        $tankNum   = (int)$tankNum;
        $recipeId  = isset($state['recipe_id']) && $state['recipe_id'] !== null
                     ? (int)$state['recipe_id'] : null;
        $ccDate    = null;
        if ($recipeId !== null) {
            $ccStmt = $pdo->prepare(
                "SELECT MAX(event_date) AS cc_date
                   FROM bd_fermenting_v2
                  WHERE recipe_id_fk = ? AND batch = ?
                    AND event_type = 'ColdCrash'
                    AND is_tombstoned = 0"
            );
            $ccStmt->execute([$recipeId, (string)$state['batch']]);
            $ccRow = $ccStmt->fetch(PDO::FETCH_ASSOC);
            if ($ccRow !== false && $ccRow['cc_date'] !== null) {
                $ccDate = (string)$ccRow['cc_date'];
            }
        }
        $workingCct[$tankNum] = [
            'beer'             => (string)$state['beer'],
            'batch'            => (string)$state['batch'],
            'recipe_id'        => $recipeId,
            'cold_crash_date'  => $ccDate,
            'source'           => 'real',
        ];
    }

    // Build working BBT map: [tankNum => ['beer','batch','recipe_id','racked_on','source']]
    $workingBbt = [];
    foreach ($snapshot['bbt'] as $tankNum => $state) {
        if ($state === null) continue;
        $tankNum  = (int)$tankNum;
        $recipeId = isset($state['recipe_id']) && $state['recipe_id'] !== null
                    ? (int)$state['recipe_id'] : null;
        $rackedOn = null;
        $batch    = (string)$state['batch'];
        $beer     = (string)$state['beer'];

        if ($recipeId !== null) {
            $rackStmt = $pdo->prepare(
                "SELECT event_date FROM bd_racking_v2
                  WHERE (neb_recipe_id_fk = ? OR contract_recipe_id_fk = ?)
                    AND COALESCE(NULLIF(neb_batch,''), contract_batch) = ?
                    AND is_tombstoned = 0
                    AND interrupted_flag = 0
                  ORDER BY event_date DESC LIMIT 1"
            );
            $rackStmt->execute([$recipeId, $recipeId, $batch]);
        } else {
            $rackStmt = $pdo->prepare(
                "SELECT event_date FROM bd_racking_v2
                  WHERE COALESCE(NULLIF(neb_beer,''), contract_beer) = ?
                    AND COALESCE(NULLIF(neb_batch,''), contract_batch) = ?
                    AND is_tombstoned = 0
                    AND interrupted_flag = 0
                  ORDER BY event_date DESC LIMIT 1"
            );
            $rackStmt->execute([$beer, $batch]);
        }
        $rackRow = $rackStmt->fetch(PDO::FETCH_ASSOC);
        if ($rackRow !== false && $rackRow['event_date'] !== null) {
            $rackedOn = (string)$rackRow['event_date'];
        }

        $workingBbt[$tankNum] = [
            'beer'      => $beer,
            'batch'     => $batch,
            'recipe_id' => $recipeId,
            'racked_on' => $rackedOn,
            'source'    => 'real',
        ];
    }

    // ── Step 4: Load in-plan items for the period ─────────────────────────────
    $weekEnd     = $weekStart->modify('+6 days');
    $weekEndStr  = $weekEnd->format('Y-m-d');
    // Always replay from the start of the week — the TankSimulator snapshot already
    // captures all historical reality; in-plan items are the delta on top of that.
    $lowerBound  = $weekStart->format('Y-m-d');

    $planStmt = $pdo->prepare(
        "SELECT id, plan_date, section, seq, wort_process, recipe_id_fk, batch,
                beer_free_text, cct_number, bbt_number, hors_process
           FROM pl_plan_items
          WHERE plan_date >= ? AND plan_date <= ?
            AND is_active = 1
            AND section IN ('wort','packaging')
          ORDER BY plan_date, section, seq, id"
    );
    $planStmt->execute([$lowerBound, $weekEndStr]);
    $allPlanItems = $planStmt->fetchAll(PDO::FETCH_ASSOC);

    // Index by date → section → items[]
    $planByDay = [];
    foreach ($allPlanItems as $pi) {
        $planByDay[(string)$pi['plan_date']][(string)$pi['section']][] = $pi;
    }

    // ── Step 5: Forward replay over 7 days ────────────────────────────────────
    $result = [];

    for ($i = 0; $i < 7; $i++) {
        $day    = $weekStart->modify("+{$i} days");
        $dayStr = $day->format('Y-m-d');

        // ── Compute eligibility BEFORE applying today's plan items ────────────

        // -- Racking eligible: CCT entries with cold_crash_date + known garde + garde met
        //    AND not already in BBT (same recipe_id+batch)
        $rackingElig = [];
        foreach ($workingCct as $cctNum => $cctEntry) {
            if ($cctEntry['cold_crash_date'] === null) continue;
            $recipeId = $cctEntry['recipe_id'];
            if ($recipeId === null) continue;
            if (!isset($gardeMap['by_rid'][$recipeId])) continue;
            $garde = $gardeMap['by_rid'][$recipeId];
            if ($garde === null) continue;

            $diffDays = planning_date_diff_days($cctEntry['cold_crash_date'], $day);
            if ($diffDays < $garde) continue;

            // Check not already in BBT with same recipe_id+batch
            $alreadyInBbt = false;
            foreach ($workingBbt as $bbtEntry) {
                if ((int)($bbtEntry['recipe_id'] ?? 0) === $recipeId
                    && $bbtEntry['batch'] === $cctEntry['batch']) {
                    $alreadyInBbt = true;
                    break;
                }
            }
            if ($alreadyInBbt) continue;

            $rackingElig[] = [
                'recipe_id'  => $recipeId,
                'beer'       => $cctEntry['beer'],
                'batch'      => $cctEntry['batch'],
                'cct_number' => $cctNum,
                'garde_met'  => true,
                'source'     => $cctEntry['source'],
            ];
        }

        // -- Packaging eligible: BBT entries with racked_on + min_days met
        $packagingElig = [];
        foreach ($workingBbt as $bbtNum => $bbtEntry) {
            if ($bbtEntry['racked_on'] === null) continue;
            $diffDays = planning_date_diff_days($bbtEntry['racked_on'], $day);
            if ($diffDays < $minDays) continue;

            $packagingElig[] = [
                'recipe_id'  => $bbtEntry['recipe_id'],
                'beer'       => $bbtEntry['beer'],
                'batch'      => $bbtEntry['batch'],
                'bbt_number' => $bbtNum,
                'racked_on'  => $bbtEntry['racked_on'],
                'source'     => $bbtEntry['source'],
            ];
        }

        // -- KZE eligible: ALL BBT entries
        $kzeElig = [];
        foreach ($workingBbt as $bbtNum => $bbtEntry) {
            $kzeElig[] = [
                'recipe_id'  => $bbtEntry['recipe_id'],
                'beer'       => $bbtEntry['beer'],
                'batch'      => $bbtEntry['batch'],
                'bbt_number' => $bbtNum,
                'source'     => $bbtEntry['source'],
            ];
        }

        // -- Dry hopping eligible: ALL CCT entries
        $dryHopElig = [];
        foreach ($workingCct as $cctNum => $cctEntry) {
            $dryHopElig[] = [
                'recipe_id'  => $cctEntry['recipe_id'],
                'beer'       => $cctEntry['beer'],
                'batch'      => $cctEntry['batch'],
                'cct_number' => $cctNum,
                'source'     => $cctEntry['source'],
            ];
        }

        // -- Brewing conflicts: current CCT occupants
        $cctConflicts = array_keys($workingCct);
        sort($cctConflicts);

        $result[$dayStr] = [
            'racking'     => array_values($rackingElig),
            'kze'         => array_values($kzeElig),
            'dry_hopping' => array_values($dryHopElig),
            'packaging'   => array_values($packagingElig),
            'brewing'     => ['cct_conflicts' => $cctConflicts],
            'occupancy'   => [
                'cct_occupied' => array_keys($workingCct),
                'bbt_occupied' => array_keys($workingBbt),
            ],
        ];

        // ── Apply plan items for day D ────────────────────────────────────────
        $dayPlanItems = $planByDay[$dayStr] ?? [];

        // Wort items first (sorted by seq — already sorted from SQL)
        foreach ($dayPlanItems['wort'] ?? [] as $pi) {
            $piProcess  = (string)($pi['wort_process'] ?? '');
            $piRecipeId = isset($pi['recipe_id_fk']) && $pi['recipe_id_fk'] !== null
                          ? (int)$pi['recipe_id_fk'] : null;
            $piBatch    = (string)($pi['batch'] ?? '');
            $piBeer     = (string)($pi['beer_free_text'] ?? '');
            $piCct      = isset($pi['cct_number']) && $pi['cct_number'] !== null
                          ? (int)$pi['cct_number'] : null;
            $piBbt      = isset($pi['bbt_number']) && $pi['bbt_number'] !== null
                          ? (int)$pi['bbt_number'] : null;

            if ($piProcess === 'brewing') {
                // Add to CCT if tank specified
                if ($piCct !== null) {
                    $workingCct[$piCct] = [
                        'beer'            => $piBeer,
                        'batch'           => $piBatch,
                        'recipe_id'       => $piRecipeId,
                        'cold_crash_date' => null,
                        'source'          => 'in_plan',
                    ];
                }
            } elseif ($piProcess === 'racking') {
                // Find and remove from CCT
                $foundCctNum = null;
                if ($piRecipeId !== null) {
                    foreach ($workingCct as $cctNum => $cctEntry) {
                        if ((int)($cctEntry['recipe_id'] ?? 0) === $piRecipeId
                            && $cctEntry['batch'] === $piBatch) {
                            $foundCctNum = $cctNum;
                            break;
                        }
                    }
                } else {
                    foreach ($workingCct as $cctNum => $cctEntry) {
                        if ($cctEntry['beer'] === $piBeer && $cctEntry['batch'] === $piBatch) {
                            $foundCctNum = $cctNum;
                            break;
                        }
                    }
                }

                $beerForBbt  = $foundCctNum !== null ? $workingCct[$foundCctNum]['beer']  : $piBeer;
                $ridForBbt   = $foundCctNum !== null ? $workingCct[$foundCctNum]['recipe_id'] : $piRecipeId;

                if ($foundCctNum !== null) {
                    unset($workingCct[$foundCctNum]);
                }

                // Add to BBT
                if ($piBbt !== null) {
                    $workingBbt[$piBbt] = [
                        'beer'      => $beerForBbt,
                        'batch'     => $piBatch,
                        'recipe_id' => $ridForBbt,
                        'racked_on' => $dayStr,
                        'source'    => 'in_plan',
                    ];
                }
            }
            // kze, dry_hopping: no occupancy change
        }

        // Packaging items
        foreach ($dayPlanItems['packaging'] ?? [] as $pi) {
            $piRecipeId = isset($pi['recipe_id_fk']) && $pi['recipe_id_fk'] !== null
                          ? (int)$pi['recipe_id_fk'] : null;
            $piBeer     = (string)($pi['beer_free_text'] ?? '');

            $piBatch = (string)($pi['batch'] ?? '');
            if ($piRecipeId !== null) {
                // Match on recipe_id + batch to avoid draining the wrong slot when
                // two batches of the same recipe are simultaneously in BBT (B3 fix).
                foreach ($workingBbt as $bbtNum => $bbtEntry) {
                    if ((int)($bbtEntry['recipe_id'] ?? 0) === $piRecipeId
                        && $bbtEntry['batch'] === $piBatch) {
                        unset($workingBbt[$bbtNum]);
                        break;
                    }
                }
            } elseif ($piBeer !== '') {
                foreach ($workingBbt as $bbtNum => $bbtEntry) {
                    if ($bbtEntry['beer'] === $piBeer && $bbtEntry['batch'] === $piBatch) {
                        unset($workingBbt[$bbtNum]);
                        break;
                    }
                }
            }
        }
    }

    return $result;
}
