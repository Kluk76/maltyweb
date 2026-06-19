<?php
declare(strict_types=1);

/**
 * supplier-eval-helpers.php
 *
 * Shared scoring engine for supplier evaluations.
 * Pure computation — no echo, no globals, no side effects.
 * Reused by sf-evaluation-save.php and future Wave-4 export/cron.
 */

/**
 * Compute evaluation scores from grid criteria + submitted scores.
 *
 * @param PDO   $pdo        Live DB connection (read-only: loads grid criteria)
 * @param int   $gridId     supplier_evaluation_grids.id
 * @param array $scores     Map: grid_criterion_id (int) → int score OR null (sans objet)
 * @param bool  $explicitKo Operator-flagged food-safety KO (overrides criterion-level)
 *
 * @return array{
 *   pillar_a_score: int,
 *   pillar_b_score: int,
 *   total_pct: float|null,
 *   food_safety_ko: bool,
 *   result: string
 * }
 *
 * @throws InvalidArgumentException on out-of-range score
 * @throws RuntimeException if grid not found
 */
function supplier_eval_compute(PDO $pdo, int $gridId, array $scores, bool $explicitKo): array
{
    // Load grid thresholds
    $gridStmt = $pdo->prepare(
        'SELECT threshold_agree, threshold_surveillance
           FROM supplier_evaluation_grids
          WHERE id = ?
          LIMIT 1'
    );
    $gridStmt->execute([$gridId]);
    $grid = $gridStmt->fetch(PDO::FETCH_ASSOC);
    if (!$grid) {
        throw new RuntimeException("Grid {$gridId} not found.");
    }
    $thresholdAgree = (float) $grid['threshold_agree'];
    $thresholdSurv  = (float) $grid['threshold_surveillance'];

    // Load all criteria for this grid
    $critStmt = $pdo->prepare(
        'SELECT id, pillar, max_score, is_ko_flag
           FROM supplier_evaluation_grid_criteria
          WHERE grid_id_fk = ?
          ORDER BY display_order'
    );
    $critStmt->execute([$gridId]);
    $criteria = $critStmt->fetchAll(PDO::FETCH_ASSOC);

    $pillarAScore = 0;
    $pillarBScore = 0;
    $sumScore     = 0;
    $sumMax       = 0;
    $foodSafetyKo = $explicitKo;

    foreach ($criteria as $crit) {
        $critId   = (int) $crit['id'];
        $maxScore = (int) $crit['max_score'];
        $pillar   = $crit['pillar'];
        $isKo     = (bool) $crit['is_ko_flag'];

        // Submitted score for this criterion (null = sans objet)
        $score = array_key_exists($critId, $scores) ? $scores[$critId] : null;

        if ($score !== null) {
            $score = (int) $score;
            if ($score < 0 || $score > $maxScore) {
                throw new InvalidArgumentException(
                    "Score {$score} out of range 0..{$maxScore} for criterion id={$critId}."
                );
            }

            // Accumulate
            if ($pillar === 'A') {
                $pillarAScore += $score;
            } else {
                $pillarBScore += $score;
            }
            $sumScore += $score;
            $sumMax   += $maxScore;

            // KO: is_ko_flag=1 AND score===0 triggers food_safety_ko
            if ($isKo && $score === 0) {
                $foodSafetyKo = true;
            }
        }
        // null (sans objet): excluded from both numerator AND applicable-max denominator
    }

    // total_pct: null when no criteria were scored (denominator 0)
    $totalPct = ($sumMax > 0)
        ? round(100.0 * $sumScore / $sumMax, 2)
        : null;

    // Result determination (server-side authority)
    if ($foodSafetyKo) {
        $result = 'non_agree';
    } elseif ($totalPct === null) {
        $result = 'draft';
    } elseif ($totalPct >= $thresholdAgree) {
        $result = 'agree';
    } elseif ($totalPct >= $thresholdSurv) {
        $result = 'agree_sous_surveillance';
    } else {
        $result = 'non_agree';
    }

    return [
        'pillar_a_score' => $pillarAScore,
        'pillar_b_score' => $pillarBScore,
        'total_pct'      => $totalPct,
        'food_safety_ko' => $foodSafetyKo,
        'result'         => $result,
    ];
}
