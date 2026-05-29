<?php
declare(strict_types=1);
/**
 * Mother-shell API — single source of truth for op_sessions mother state.
 *
 * A "mother session" (form_type='batch') is the long-lived production envelope
 * that spans one batch's entire lifecycle (brewing → fermenting → racking →
 * packaging). Daily-shell sessions (fermenting, racking, packaging) link to it
 * via op_sessions.parent_session_id_fk.
 *
 * Phase 1 ships: schema + this resolver API.
 * Phase 2-5 builds the board that reads from it.
 *
 * Rules enforced here (single source of truth):
 *   - Exactly one open mother per (recipe_id_fk, batch) — enforced by DB
 *     UNIQUE index uniq_active_mother; create_mother() asserts via find_open_mother().
 *   - Daily-shell code NEVER writes mother columns (parent_session_id_fk,
 *     merged_into_session_id_fk, blend_share_pct) directly.
 *   - Auto-link failure does NOT block session open (callers catch + log).
 *
 * Requires: app/db.php (maltytask_pdo), app/db-write-helpers.php (log_revision, bd_row_hash).
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/db-write-helpers.php';

// ─── Constants ────────────────────────────────────────────────────────────────

/** Sentinel actor written to audit_row_revisions for mother-shell ops. */
const MOTHER_SHELL_ACTOR = 'mother-shell';

// ─── Public API ───────────────────────────────────────────────────────────────

/**
 * Find the open mother session for (recipe_id_fk, batch).
 *
 * "Open mother" = form_type='batch' AND status='open'
 *                AND merged_into_session_id_fk IS NULL.
 *
 * @return array|null  Full op_sessions row, or null if no open mother exists.
 */
function find_open_mother(PDO $pdo, int $recipe_id_fk, string $batch): ?array
{
    if ($recipe_id_fk <= 0) {
        throw new InvalidArgumentException('find_open_mother: recipe_id_fk doit être > 0.');
    }
    if ($batch === '') {
        throw new InvalidArgumentException('find_open_mother: batch ne peut pas être vide.');
    }

    $stmt = $pdo->prepare(
        "SELECT *
           FROM op_sessions
          WHERE form_type = 'batch'
            AND status = 'open'
            AND merged_into_session_id_fk IS NULL
            AND is_tombstoned = 0
            AND recipe_id_fk = ?
            AND batch = ?
          LIMIT 1"
    );
    $stmt->execute([$recipe_id_fk, $batch]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row !== false ? $row : null;
}

/**
 * Create a new mother session for (recipe_id_fk, batch).
 *
 * Asserts no open mother exists via find_open_mother() first.
 * Intended callers: brewing pilot (pilot 6) or admin tooling.
 *
 * @param array $opts  Optional overrides:
 *                     'opened_by_fk' (int)  — defaults to system user if not provided
 *                     'client_id_fk'  (int|null)
 * @return int  The new op_sessions.id.
 * @throws RuntimeException if an open mother already exists.
 */
function create_mother(PDO $pdo, int $recipe_id_fk, string $batch, array $opts = []): int
{
    if ($recipe_id_fk <= 0) {
        throw new InvalidArgumentException('create_mother: recipe_id_fk doit être > 0.');
    }
    if ($batch === '') {
        throw new InvalidArgumentException('create_mother: batch ne peut pas être vide.');
    }

    // Guard: refuse if an open mother already exists for this (recipe, batch).
    $existing = find_open_mother($pdo, $recipe_id_fk, $batch);
    if ($existing !== null) {
        throw new RuntimeException(
            "create_mother: une mother session ouverte existe déjà pour (recipe={$recipe_id_fk}, batch={$batch}), id={$existing['id']}."
        );
    }

    $openedByFk = isset($opts['opened_by_fk']) ? (int)$opts['opened_by_fk'] : null;
    if ($openedByFk !== null && $openedByFk <= 0) {
        throw new InvalidArgumentException('create_mother: opened_by_fk doit être > 0.');
    }
    $clientIdFk = isset($opts['client_id_fk']) ? (int)$opts['client_id_fk'] : null;
    if ($clientIdFk !== null && $clientIdFk <= 0) {
        throw new InvalidArgumentException('create_mother: client_id_fk doit être > 0.');
    }

    // opened_by_fk is required for the FK constraint; use a sentinel system-user
    // row (id=0 is not valid due to UNSIGNED + FK RESTRICT). Callers should always
    // pass opened_by_fk; if missing, throw so the caller fixes the call-site.
    if ($openedByFk === null) {
        throw new InvalidArgumentException(
            'create_mother: opts[opened_by_fk] requis (pas de session système anonyme).'
        );
    }

    $openedAt = date('Y-m-d H:i:s.u');
    $rowHash  = bd_row_hash(['batch', $recipe_id_fk, $batch, $openedByFk, $openedAt]);

    $ownTx     = !$pdo->inTransaction();
    $savepoint = 'mother_create_' . uniqid('', true);
    $ownTx ? $pdo->beginTransaction() : $pdo->exec("SAVEPOINT `{$savepoint}`");

    try {
        $stmt = $pdo->prepare(
            "INSERT INTO op_sessions
               (form_type, recipe_id_fk, batch, client_id_fk,
                phase, status,
                opened_by_fk, opened_at,
                closed_by_fk, closed_at, abandon_reason,
                parent_session_id_fk, merged_into_session_id_fk, blend_share_pct,
                row_hash, is_tombstoned, audit_flags,
                imported_at, updated_at)
             VALUES
               ('batch', ?, ?, ?,
                'start', 'open',
                ?, ?,
                NULL, NULL, NULL,
                NULL, NULL, NULL,
                ?, 0, NULL,
                CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)"
        );
        $stmt->execute([
            $recipe_id_fk,
            $batch,
            $clientIdFk,
            $openedByFk,
            $openedAt,
            $rowHash,
        ]);
        $motherId = (int)$pdo->lastInsertId();

        // Audit.
        $me = _mother_audit_user($pdo, $openedByFk);
        log_revision(
            $pdo, $me, 'op_sessions', $motherId,
            null,
            ['form_type' => 'batch', 'recipe_id_fk' => $recipe_id_fk, 'batch' => $batch,
             'status' => 'open', 'phase' => 'start', 'opened_by_fk' => $openedByFk],
            'normal',
            'mother-shell: create_mother'
        );

        $ownTx ? $pdo->commit() : $pdo->exec("RELEASE SAVEPOINT `{$savepoint}`");

    } catch (Throwable $e) {
        $ownTx ? $pdo->rollBack() : $pdo->exec("ROLLBACK TO SAVEPOINT `{$savepoint}`");
        throw $e;
    }

    return $motherId;
}

/**
 * Link a daily-shell session to its mother.
 *
 * Idempotent: if parent_session_id_fk is already set on $daily_session_id,
 * no-op (returns true if the link already points to a mother for this
 * (recipe, batch); false if it points elsewhere — caller should log a warning).
 *
 * Returns true  if linked now or already linked correctly.
 * Returns false if no open mother exists for (recipe, batch) — daily opens
 *               UNLINKED (Phase 1 behaviour; board renders as orphan-daily).
 *
 * @throws RuntimeException on DB error.
 */
function link_daily_to_mother(PDO $pdo, int $daily_session_id, int $recipe_id_fk, string $batch): bool
{
    if ($daily_session_id <= 0) {
        throw new InvalidArgumentException('link_daily_to_mother: daily_session_id doit être > 0.');
    }
    if ($recipe_id_fk <= 0) {
        return false; // No recipe known — cannot resolve mother; caller treats as unlinked.
    }
    if ($batch === '') {
        return false; // No batch known — cannot resolve mother.
    }

    // Fetch the daily session to check current parent linkage.
    $dailyStmt = $pdo->prepare(
        "SELECT id, parent_session_id_fk FROM op_sessions WHERE id = ? LIMIT 1"
    );
    $dailyStmt->execute([$daily_session_id]);
    $daily = $dailyStmt->fetch(PDO::FETCH_ASSOC);
    if ($daily === false) {
        throw new RuntimeException(
            "link_daily_to_mother: op_sessions.id={$daily_session_id} introuvable."
        );
    }

    // If already linked, verify it points at a mother for this (recipe, batch).
    if ($daily['parent_session_id_fk'] !== null) {
        $existingParentId = (int)$daily['parent_session_id_fk'];
        $checkStmt = $pdo->prepare(
            "SELECT id FROM op_sessions
              WHERE id = ?
                AND form_type = 'batch'
                AND recipe_id_fk = ?
                AND batch = ?
              LIMIT 1"
        );
        $checkStmt->execute([$existingParentId, $recipe_id_fk, $batch]);
        $parentMatch = $checkStmt->fetch(PDO::FETCH_ASSOC);
        // Already linked to the correct mother (or any mother for this recipe+batch).
        return $parentMatch !== false;
    }

    // Find the open mother.
    $mother = find_open_mother($pdo, $recipe_id_fk, $batch);
    if ($mother === null) {
        // No mother exists yet — daily opens unlinked (Phase 1 behaviour).
        return false;
    }

    $motherId = (int)$mother['id'];

    // Write the link.
    $ownTx     = !$pdo->inTransaction();
    $savepoint = 'mother_link_' . uniqid('', true);
    $ownTx ? $pdo->beginTransaction() : $pdo->exec("SAVEPOINT `{$savepoint}`");

    try {
        $updStmt = $pdo->prepare(
            "UPDATE op_sessions
                SET parent_session_id_fk = ?,
                    updated_at = CURRENT_TIMESTAMP
              WHERE id = ?
                AND parent_session_id_fk IS NULL"
        );
        $updStmt->execute([$motherId, $daily_session_id]);

        if ($updStmt->rowCount() > 0) {
            // Audit the link write.
            // No user context at hook time — use system sentinel.
            $sysMe = ['id' => 0, 'username' => MOTHER_SHELL_ACTOR];
            log_revision(
                $pdo, $sysMe, 'op_sessions', $daily_session_id,
                ['parent_session_id_fk' => null],
                ['parent_session_id_fk' => $motherId],
                'normal',
                "mother-shell: auto-link to mother id={$motherId}"
            );
        }

        $ownTx ? $pdo->commit() : $pdo->exec("RELEASE SAVEPOINT `{$savepoint}`");

    } catch (Throwable $e) {
        $ownTx ? $pdo->rollBack() : $pdo->exec("ROLLBACK TO SAVEPOINT `{$savepoint}`");
        throw $e;
    }

    return true;
}

/**
 * Force-close a mother session.
 *
 * Sets status='closed' + phase='closed' + closed_at=NOW().
 * Typical trigger: "cuve vide" event from packaging (Phase 4+).
 *
 * @param int         $mother_id  op_sessions.id (must be a form_type='batch' row).
 * @param string|null $reason     Optional abandon_reason-style note stored in audit.
 * @throws RuntimeException if not found or not a batch session.
 */
function close_mother(PDO $pdo, int $mother_id, ?string $reason = null): void
{
    if ($mother_id <= 0) {
        throw new InvalidArgumentException('close_mother: mother_id doit être > 0.');
    }

    // Fetch before-state for audit.
    $before = bd_fetch_before($pdo, 'op_sessions', $mother_id);
    if ($before === null) {
        throw new RuntimeException("close_mother: op_sessions.id={$mother_id} introuvable.");
    }
    if ($before['form_type'] !== 'batch') {
        throw new RuntimeException(
            "close_mother: op_sessions.id={$mother_id} n'est pas une mother session (form_type='{$before['form_type']}')."
        );
    }
    if ($before['status'] === 'closed') {
        // Already closed — idempotent no-op.
        return;
    }

    $closedAt = date('Y-m-d H:i:s');

    $ownTx     = !$pdo->inTransaction();
    $savepoint = 'mother_close_' . uniqid('', true);
    $ownTx ? $pdo->beginTransaction() : $pdo->exec("SAVEPOINT `{$savepoint}`");

    try {
        $stmt = $pdo->prepare(
            "UPDATE op_sessions
                SET status = 'closed',
                    phase  = 'closed',
                    closed_at = ?,
                    updated_at = CURRENT_TIMESTAMP
              WHERE id = ?"
        );
        $stmt->execute([$closedAt, $mother_id]);

        $sysMe = ['id' => 0, 'username' => MOTHER_SHELL_ACTOR];
        log_revision(
            $pdo, $sysMe, 'op_sessions', $mother_id,
            ['status' => $before['status'], 'phase' => $before['phase'], 'closed_at' => $before['closed_at']],
            ['status' => 'closed', 'phase' => 'closed', 'closed_at' => $closedAt],
            'normal',
            $reason !== null
                ? 'mother-shell: close_mother — ' . $reason
                : 'mother-shell: close_mother'
        );

        $ownTx ? $pdo->commit() : $pdo->exec("RELEASE SAVEPOINT `{$savepoint}`");

    } catch (Throwable $e) {
        $ownTx ? $pdo->rollBack() : $pdo->exec("ROLLBACK TO SAVEPOINT `{$savepoint}`");
        throw $e;
    }
}

/**
 * Merge mother A (departing) into mother B (surviving).
 *
 * - A.merged_into_session_id_fk = B.id
 * - A.status = 'closed', A.phase = 'closed', A.closed_at = NOW()
 * - A.blend_share_pct = $share_pct (if non-null, e.g. 40.00)
 * - B is left open; caller updates B.blend_share_pct externally if needed.
 *
 * @param int        $departing_mother_id  op_sessions.id of the closing mother.
 * @param int        $surviving_mother_id  op_sessions.id of the surviving mother.
 * @param float|null $share_pct            Blend share of the departing volume (0.00–100.00).
 * @return array  Full op_sessions row of the surviving mother (B).
 * @throws RuntimeException on not-found, wrong form_type, or same-id merge.
 */
function merge_mothers(
    PDO    $pdo,
    int    $departing_mother_id,
    int    $surviving_mother_id,
    ?float $share_pct = null
): array {
    if ($departing_mother_id <= 0 || $surviving_mother_id <= 0) {
        throw new InvalidArgumentException('merge_mothers: les deux IDs doivent être > 0.');
    }
    if ($departing_mother_id === $surviving_mother_id) {
        throw new InvalidArgumentException('merge_mothers: departing et surviving ne peuvent pas être identiques.');
    }
    if ($share_pct !== null && ($share_pct < 0 || $share_pct > 100)) {
        throw new InvalidArgumentException('merge_mothers: share_pct doit être entre 0 et 100.');
    }

    // Fetch both rows.
    $departing = bd_fetch_before($pdo, 'op_sessions', $departing_mother_id);
    if ($departing === null) {
        throw new RuntimeException("merge_mothers: op_sessions.id={$departing_mother_id} (departing) introuvable.");
    }
    if ($departing['form_type'] !== 'batch') {
        throw new RuntimeException(
            "merge_mothers: departing id={$departing_mother_id} n'est pas une mother session."
        );
    }

    $surviving = bd_fetch_before($pdo, 'op_sessions', $surviving_mother_id);
    if ($surviving === null) {
        throw new RuntimeException("merge_mothers: op_sessions.id={$surviving_mother_id} (surviving) introuvable.");
    }
    if ($surviving['form_type'] !== 'batch') {
        throw new RuntimeException(
            "merge_mothers: surviving id={$surviving_mother_id} n'est pas une mother session."
        );
    }

    $closedAt = date('Y-m-d H:i:s');

    $ownTx     = !$pdo->inTransaction();
    $savepoint = 'mother_merge_' . uniqid('', true);
    $ownTx ? $pdo->beginTransaction() : $pdo->exec("SAVEPOINT `{$savepoint}`");

    try {
        // Close and tag the departing mother.
        $stmtDepart = $pdo->prepare(
            "UPDATE op_sessions
                SET merged_into_session_id_fk = ?,
                    blend_share_pct           = ?,
                    status    = 'closed',
                    phase     = 'closed',
                    closed_at = ?,
                    updated_at = CURRENT_TIMESTAMP
              WHERE id = ?"
        );
        $stmtDepart->execute([$surviving_mother_id, $share_pct, $closedAt, $departing_mother_id]);

        $sysMe = ['id' => 0, 'username' => MOTHER_SHELL_ACTOR];

        log_revision(
            $pdo, $sysMe, 'op_sessions', $departing_mother_id,
            ['status' => $departing['status'], 'phase' => $departing['phase'],
             'merged_into_session_id_fk' => null, 'blend_share_pct' => null,
             'closed_at' => $departing['closed_at']],
            ['status' => 'closed', 'phase' => 'closed',
             'merged_into_session_id_fk' => $surviving_mother_id,
             'blend_share_pct' => $share_pct, 'closed_at' => $closedAt],
            'normal',
            "mother-shell: merge_mothers — departing merged into surviving id={$surviving_mother_id}"
        );

        log_revision(
            $pdo, $sysMe, 'op_sessions', $surviving_mother_id,
            ['status' => $surviving['status']],
            ['status' => $surviving['status'], '_note' => "absorbs departing id={$departing_mother_id}"],
            'normal',
            "mother-shell: merge_mothers — surviving absorbs departing id={$departing_mother_id}"
        );

        $ownTx ? $pdo->commit() : $pdo->exec("RELEASE SAVEPOINT `{$savepoint}`");

    } catch (Throwable $e) {
        $ownTx ? $pdo->rollBack() : $pdo->exec("ROLLBACK TO SAVEPOINT `{$savepoint}`");
        throw $e;
    }

    // Return freshly-fetched survivor row.
    $fresh = bd_fetch_before($pdo, 'op_sessions', $surviving_mother_id);
    if ($fresh === null) {
        throw new RuntimeException("merge_mothers: impossible de recharger la surviving row après merge.");
    }
    return $fresh;
}

// ─── Cuve-vide API ────────────────────────────────────────────────────────────

/**
 * cuve_vide_pressed: identify mothers affected by "Cuve vide" on a vessel.
 *
 * Finds all open mothers whose latest non-tombstoned child points at
 * (vessel_kind, vessel_number). Also performs a TankSimulator emptiness check
 * via inv_tank_balances (most recent month_key for this vessel).
 *
 * @return array {
 *   'mothers'       => array of mother summaries (id, recipe_name, batch, pct_packaged),
 *   'tanksim_empty' => bool,
 *   'tanksim_note'  => string,
 * }
 */
function cuve_vide_pressed(PDO $pdo, string $vessel_kind, int $vessel_number): array
{
    if ($vessel_kind === '') {
        return ['mothers' => [], 'tanksim_empty' => true,
                'tanksim_note' => "vessel_kind vide — aucun vessel à vérifier."];
    }
    if ($vessel_number <= 0) {
        return ['mothers' => [], 'tanksim_empty' => true,
                'tanksim_note' => "vessel_number invalide ({$vessel_number})."];
    }

    // Find all open mothers whose current vessel matches.
    // "Current vessel" = most recent non-tombstoned child with a vessel assignment.
    $stmt = $pdo->prepare(
        "SELECT DISTINCT m.id, m.recipe_id_fk, m.batch,
                r.name AS recipe_name,
                m.opened_at
           FROM op_sessions m
           JOIN (
                SELECT parent_session_id_fk, vessel_kind, vessel_number
                  FROM op_sessions
                 WHERE form_type      != 'batch'
                   AND is_tombstoned   = 0
                   AND vessel_kind     = ?
                   AND vessel_number   = ?
                   AND parent_session_id_fk IS NOT NULL
               ) child ON child.parent_session_id_fk = m.id
      LEFT JOIN ref_recipes r ON r.id = m.recipe_id_fk
          WHERE m.form_type = 'batch'
            AND m.status    = 'open'
            AND m.merged_into_session_id_fk IS NULL
            AND m.is_tombstoned = 0"
    );
    $stmt->execute([$vessel_kind, $vessel_number]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // FIX 7: pct_packaged removed — duplicated _sb_pct_packaged (drift risk).
    // Modal v1 shows recipe/batch/heartbeat only; pct_packaged is not actionable at close time.
    $mothers = [];
    foreach ($rows as $row) {
        $mothers[] = [
            'id'          => (int)$row['id'],
            'recipe_name' => $row['recipe_name'] ?? $row['recipe_id_fk'] ?? '—',
            'batch'       => $row['batch'],
            'opened_at'   => $row['opened_at'],
        ];
    }

    // TankSimulator check: query latest month balance for this vessel.
    // inv_tank_balances.tank_type stores uppercase kind ('CCT','BBT',…);
    // tank_id stores the number as a string.
    $tankType = strtoupper($vessel_kind);
    $tankId   = (string)$vessel_number;

    $tbStmt = $pdo->prepare(
        "SELECT volume_hl, month_key
           FROM inv_tank_balances
          WHERE tank_type = ?
            AND tank_id   = ?
          ORDER BY month_key DESC
          LIMIT 1"
    );
    $tbStmt->execute([$tankType, $tankId]);
    $tb = $tbStmt->fetch(PDO::FETCH_ASSOC);

    if ($tb === false) {
        // No TankSimulator record at all — cannot verify; treat as empty with a note.
        $tanksimEmpty = true;
        $tanksimNote  = "Aucune donnée TankSimulator pour {$tankType}-{$vessel_number} — vérification ignorée.";
    } else {
        $volHl        = (float)$tb['volume_hl'];
        $tanksimEmpty = $volHl <= 0;
        $tanksimNote  = $tanksimEmpty
            ? "TankSimulator ({$tb['month_key']}): {$tankType}-{$vessel_number} = {$volHl} HL — cuve vide confirmée."
            : "TankSimulator ({$tb['month_key']}): {$tankType}-{$vessel_number} = {$volHl} HL — cuve non vide selon le système.";
    }

    return [
        'mothers'       => $mothers,
        'tanksim_empty' => $tanksimEmpty,
        'tanksim_note'  => $tanksimNote,
    ];
}

/**
 * cuve_vide_apply: close affected mothers and record the reason.
 *
 * Reasons:
 *   'emballe'      — packaging completed → closes mothers
 *   'jete'         — dumped → closes mothers
 *   'vendu_mout'   — sold as wort → closes mothers
 *   'encore_en_cuve' — vessel NOT actually empty → keeps mothers open, logs step only
 *
 * @param string      $vessel_kind
 * @param int         $vessel_number
 * @param string      $reason  One of the 4 allowed values.
 * @param string|null $note    Optional operator note.
 * @return array {
 *   'closed_mothers' => array of closed mother IDs,
 *   'kept_open'      => array of kept-open mother IDs (encore_en_cuve),
 *   'errors'         => array of error strings,
 * }
 */
function cuve_vide_apply(
    PDO     $pdo,
    string  $vessel_kind,
    int     $vessel_number,
    string  $reason,
    ?string $note = null
): array {
    $allowedReasons = ['emballe', 'jete', 'vendu_mout', 'encore_en_cuve'];
    if (!in_array($reason, $allowedReasons, true)) {
        throw new InvalidArgumentException(
            "cuve_vide_apply: raison invalide '{$reason}'. Valeurs autorisées: " . implode(', ', $allowedReasons)
        );
    }

    $closedMothers = [];
    $keptOpen      = [];
    $errors        = [];

    $sysMe       = ['id' => 0, 'username' => MOTHER_SHELL_ACTOR];
    $auditSuffix = "cuve-vide: {$vessel_kind}-{$vessel_number}, raison={$reason}"
                 . ($note !== null ? ", note={$note}" : '');

    $ownTx     = !$pdo->inTransaction();
    $savepoint = 'cuve_vide_apply_' . uniqid('', true);
    $ownTx ? $pdo->beginTransaction() : $pdo->exec("SAVEPOINT `{$savepoint}`");

    try {
        // FIX 4: cuve_vide_pressed called INSIDE the transaction so the row reads
        // take InnoDB shared locks as part of the transaction, preventing TOCTOU races.
        $preview = cuve_vide_pressed($pdo, $vessel_kind, $vessel_number);
        $mothers  = $preview['mothers'];

        if (empty($mothers)) {
            $ownTx ? $pdo->commit() : $pdo->exec("RELEASE SAVEPOINT `{$savepoint}`");
            return ['closed_mothers' => [], 'kept_open' => [], 'errors' => []];
        }

        foreach ($mothers as $m) {
            $motherId = (int)$m['id'];

            if ($reason === 'encore_en_cuve') {
                // Log the contradiction — vessel reported empty but operator says not.
                // Do NOT close. Record the step via log_revision.
                log_revision(
                    $pdo, $sysMe, 'op_sessions', $motherId,
                    ['status' => 'open'],
                    ['status' => 'open', '_cuve_vide_contradiction' => true],
                    'normal',
                    "mother-shell: cuve_vide_apply — encore_en_cuve, mother {$motherId} gardée ouverte. {$auditSuffix}"
                );
                $keptOpen[] = $motherId;
            } else {
                // Close the mother.
                try {
                    close_mother($pdo, $motherId, $auditSuffix);
                    $closedMothers[] = $motherId;
                } catch (\Throwable $e) {
                    $errors[] = "mother_id={$motherId}: " . $e->getMessage();
                }
            }
        }

        $ownTx ? $pdo->commit() : $pdo->exec("RELEASE SAVEPOINT `{$savepoint}`");

    } catch (\Throwable $e) {
        $ownTx ? $pdo->rollBack() : $pdo->exec("ROLLBACK TO SAVEPOINT `{$savepoint}`");
        throw $e;
    }

    return [
        'closed_mothers' => $closedMothers,
        'kept_open'      => $keptOpen,
        'errors'         => $errors,
    ];
}

// ─── Private helpers ──────────────────────────────────────────────────────────

/**
 * Build a minimal $me array for log_revision calls inside mother-shell ops.
 * Uses the real user row when available, falls back to a system sentinel.
 * The sentinel id=0 is intentional: it signals "system/automated op" in audit logs.
 */
function _mother_audit_user(PDO $pdo, int $userId): array
{
    if ($userId <= 0) {
        return ['id' => 0, 'username' => MOTHER_SHELL_ACTOR];
    }
    $stmt = $pdo->prepare("SELECT id, username FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    // F2: fallback distinguishes stale-but-real userId from system actor.
    // Was: ['username' => MOTHER_SHELL_ACTOR] — conflated stale users with system ops in audit.
    // Now matches _session_audit_user fallback pattern in app/sessions.php.
    return $row !== false
        ? $row
        : ['id' => $userId, 'username' => "user#{$userId}"];
}
