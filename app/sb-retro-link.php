<?php
declare(strict_types=1);
/**
 * sb-retro-link.php — One-shot retro-link resolver for orphan daily sessions.
 *
 * Scans op_sessions for daily-shell sessions (brewing/fermenting/racking/packaging)
 * that are open but have no parent_session_id_fk, and proposes mother-link or
 * mother-create actions.
 *
 * Design invariant: this module NEVER mutates unless $apply=true is explicitly
 * passed via sb_retro_link_apply(). Default mode (sb_retro_link_propose) is
 * always a pure read — safe to call repeatedly.
 *
 * Public surface:
 *   sb_retro_link_propose(PDO, array): array   — pure dry-run proposal list
 *   sb_retro_link_apply(PDO, array): array     — mutate; caller must confirm
 *
 * Per PM governance rule: retro-link MUST NOT GUESS. Operator confirmation
 * (POST+apply=1) is mandatory before any mutation lands.
 */

require_once __DIR__ . '/mother-shell.php';

/**
 * System user ID used as opened_by_fk when create_mother is called from the
 * retro-link apply path. Must be a valid users.id with FK RESTRICT satisfied.
 * Use the primary admin account (id=1); the operator has confirmed the apply.
 */
const RETRO_LINK_SYSTEM_USER_ID = 1;

/**
 * Default window (days) within which an orphan session is considered "recent"
 * and eligible for a 'create' proposal. Older orphans get 'skip'.
 */
const RETRO_LINK_DEFAULT_RECENT_DAYS = 90;

/**
 * Scan open daily-shell sessions with no parent link and propose actions.
 *
 * Each proposal:
 *   [
 *     'action'       => 'link'|'create'|'skip',
 *     'session_id'   => int,
 *     'form_type'    => string,
 *     'recipe_id_fk' => int,
 *     'batch'        => string,
 *     'mother_id'    => int|null,   // non-null only for action='link'
 *     'reason'       => string,
 *   ]
 *
 * Eligible orphans:
 *   - form_type IN ('brewing','fermenting','racking','packaging')
 *   - status = 'open'
 *   - parent_session_id_fk IS NULL
 *   - is_tombstoned = 0
 *   - recipe_id_fk IS NOT NULL   (cannot resolve without a recipe)
 *   - batch IS NOT NULL AND batch != ''  (cannot resolve without a batch)
 *
 * Idempotent: re-running with same DB state returns identical proposals.
 *
 * @param array $opts  ['recent_days' => int]  Override the recency window.
 */
function sb_retro_link_propose(PDO $pdo, array $opts = []): array
{
    $recentDays = isset($opts['recent_days']) ? max(1, (int) $opts['recent_days']) : RETRO_LINK_DEFAULT_RECENT_DAYS;

    // Fetch all orphan open daily sessions with sufficient identity columns.
    $stmt = $pdo->prepare(
        "SELECT id, form_type, recipe_id_fk, batch, opened_at
           FROM op_sessions
          WHERE form_type IN ('brewing', 'fermenting', 'racking', 'packaging')
            AND status = 'open'
            AND parent_session_id_fk IS NULL
            AND is_tombstoned = 0
            AND recipe_id_fk IS NOT NULL
            AND batch IS NOT NULL
            AND batch != ''
          ORDER BY opened_at ASC"
    );
    $stmt->execute();
    $orphans = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $proposals = [];
    $now       = new \DateTime();

    foreach ($orphans as $row) {
        $sessionId   = (int) $row['id'];
        $formType    = (string) $row['form_type'];
        $recipeIdFk  = (int) $row['recipe_id_fk'];
        $batch       = (string) $row['batch'];
        $openedAt    = $row['opened_at'];

        // Age of the session in days.
        $openedDt = \DateTime::createFromFormat('Y-m-d H:i:s.u', $openedAt)
                 ?: \DateTime::createFromFormat('Y-m-d H:i:s', $openedAt);
        $daysOld  = ($openedDt instanceof \DateTime)
                  ? (int) $now->diff($openedDt)->days
                  : PHP_INT_MAX;

        // Route through the Phase 1 resolver — no direct SQL writes here.
        try {
            $mother = find_open_mother($pdo, $recipeIdFk, $batch);
        } catch (\InvalidArgumentException $e) {
            // recipe_id_fk <= 0 or batch empty — filtered by WHERE but be defensive.
            $proposals[] = [
                'action'       => 'skip',
                'session_id'   => $sessionId,
                'form_type'    => $formType,
                'recipe_id_fk' => $recipeIdFk,
                'batch'        => $batch,
                'mother_id'    => null,
                'reason'       => 'resolver-error: ' . $e->getMessage(),
            ];
            continue;
        }

        if ($mother !== null) {
            // Open mother exists — propose a link.
            $proposals[] = [
                'action'       => 'link',
                'session_id'   => $sessionId,
                'form_type'    => $formType,
                'recipe_id_fk' => $recipeIdFk,
                'batch'        => $batch,
                'mother_id'    => (int) $mother['id'],
                'reason'       => "open mother exists (id={$mother['id']})",
            ];
        } elseif ($daysOld <= $recentDays) {
            // No mother, but session is recent — propose creation.
            $proposals[] = [
                'action'       => 'create',
                'session_id'   => $sessionId,
                'form_type'    => $formType,
                'recipe_id_fk' => $recipeIdFk,
                'batch'        => $batch,
                'mother_id'    => null,
                'reason'       => "no mother; session recent ({$daysOld} days old); will create then link",
            ];
        } else {
            // No mother and session is old — skip; operator decides manually.
            $proposals[] = [
                'action'       => 'skip',
                'session_id'   => $sessionId,
                'form_type'    => $formType,
                'recipe_id_fk' => $recipeIdFk,
                'batch'        => $batch,
                'mother_id'    => null,
                'reason'       => "no mother; session too old ({$daysOld} days); operator review required",
            ];
        }
    }

    return $proposals;
}

/**
 * Execute proposals from sb_retro_link_propose().
 *
 * Wraps all mutations in a transaction. Per-proposal errors are recorded in
 * errors[] and execution continues (best-effort within the batch). On a
 * transaction-level failure, rolls back and re-throws.
 *
 * action='link'   → link_daily_to_mother()
 * action='create' → create_mother() then link_daily_to_mother()
 *                   If create_mother throws duplicate (race), falls back to link.
 * action='skip'   → counted in skipped, no DB write.
 *
 * @param array $proposals  Output of sb_retro_link_propose().
 * @return array  ['applied' => int, 'skipped' => int, 'errors' => string[]]
 */
function sb_retro_link_apply(PDO $pdo, array $proposals): array
{
    $applied = 0;
    $skipped = 0;
    $errors  = [];

    if (empty($proposals)) {
        return ['applied' => 0, 'skipped' => 0, 'errors' => []];
    }

    $ownTx = !$pdo->inTransaction();
    if ($ownTx) {
        $pdo->beginTransaction();
    }

    try {
        foreach ($proposals as $proposal) {
            $action    = (string) ($proposal['action']       ?? '');
            $sessionId = (int)   ($proposal['session_id']   ?? 0);
            $recipeId  = (int)   ($proposal['recipe_id_fk'] ?? 0);
            $batch     = (string) ($proposal['batch']        ?? '');

            if ($action === 'skip') {
                $skipped++;
                continue;
            }

            if ($sessionId <= 0 || $recipeId <= 0 || $batch === '') {
                $errors[] = "session_id={$sessionId}: invalid proposal data (recipe={$recipeId}, batch='{$batch}')";
                continue;
            }

            // Use a savepoint per proposal so one failure doesn't abort the batch.
            $sp = 'retro_link_' . $sessionId . '_' . uniqid('', true);
            $pdo->exec("SAVEPOINT `{$sp}`");

            try {
                if ($action === 'link') {
                    $linked = link_daily_to_mother($pdo, $sessionId, $recipeId, $batch);
                    if ($linked) {
                        $applied++;
                    } else {
                        // link_daily_to_mother returns false when no open mother exists
                        // (race between propose and apply); record as error.
                        $errors[] = "session_id={$sessionId}: link returned false — no open mother found at apply time (race condition?)";
                    }

                } elseif ($action === 'create') {
                    // Create the mother, then link. If create fails due to a race
                    // (another process created it between propose and apply), fall
                    // back to linking to the now-existing mother.
                    try {
                        create_mother($pdo, $recipeId, $batch, [
                            'opened_by_fk' => RETRO_LINK_SYSTEM_USER_ID,
                        ]);
                    } catch (\RuntimeException $createEx) {
                        // Likely "une mother session ouverte existe déjà" — race.
                        // Fall through to link; find_open_mother will locate it.
                        $errors[] = "session_id={$sessionId}: create_mother threw (race?) — attempting link fallback. Detail: " . $createEx->getMessage();
                    }

                    $linked = link_daily_to_mother($pdo, $sessionId, $recipeId, $batch);
                    if ($linked) {
                        $applied++;
                    } else {
                        $errors[] = "session_id={$sessionId}: create succeeded but link returned false";
                    }

                } else {
                    // Unknown action — skip defensively.
                    $errors[] = "session_id={$sessionId}: unknown action '{$action}'";
                    $skipped++;
                }

                $pdo->exec("RELEASE SAVEPOINT `{$sp}`");

            } catch (\Throwable $e) {
                $pdo->exec("ROLLBACK TO SAVEPOINT `{$sp}`");
                $errors[] = "session_id={$sessionId}: " . $e->getMessage();
            }
        }

        if ($ownTx) {
            $pdo->commit();
        }

    } catch (\Throwable $e) {
        if ($ownTx && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return [
        'applied' => $applied,
        'skipped' => $skipped,
        'errors'  => $errors,
    ];
}
