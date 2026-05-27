<?php
declare(strict_types=1);
/**
 * cip-events.php — Shared CIP infrastructure (parser / writer / reader).
 *
 * Used by all three operator forms: form-racking, form-brewing, form-packaging.
 * Zero BSF writes. MySQL only (bd_cip_events + ref_cip_types).
 *
 * ══════════════════════════════════════════════════════════════════════════
 * CIP FIELD-NAME CONTRACT (identical across all three forms)
 * ══════════════════════════════════════════════════════════════════════════
 *
 * Machine CIP fields (racking + packaging; brewing has no machines):
 *
 *   cip_inline_combine         = "1" → simultané mode: only the cip_combined_* fields
 *                                are read for centri + kze; their individual fields are ignored.
 *                                "0" or absent → individual mode (below).
 *
 * INDIVIDUAL mode (cip_inline_combine != "1"):
 *   cip_machine_centri         = "1" when centri CIP was performed
 *   cip_machine_centri_type_id = ref_cip_types.id (INT, required when centri=1)
 *   cip_machine_centri_date    = VARCHAR date (required when centri=1)
 *   cip_machine_centri_start   = HH:MM time start (required when centri=1)
 *   cip_machine_centri_end     = HH:MM time end   (required when centri=1)
 *
 *   cip_machine_kze            = "1" when KZE CIP was performed
 *   cip_machine_kze_type_id    = ref_cip_types.id
 *   cip_machine_kze_date       = VARCHAR date
 *   cip_machine_kze_start      = HH:MM
 *   cip_machine_kze_end        = HH:MM
 *
 * SIMULTANÉ mode (cip_inline_combine = "1"):
 *   cip_combined_type_id       = ref_cip_types.id (shared type for both centri + kze)
 *   cip_combined_date          = VARCHAR date
 *   cip_combined_start         = HH:MM
 *   cip_combined_end           = HH:MM
 *   → emits TWO events: target_code=centri and target_code=kze, both with inline_group=1
 *     and identical date/type/start/end values. Individual centri/kze fields are NOT read.
 *
 * Pump is always parsed individually regardless of cip_inline_combine:
 *   cip_machine_pump           = "1" when pump CIP was performed
 *   cip_machine_pump_type_id   = ref_cip_types.id
 *   cip_machine_pump_date      = VARCHAR date
 *   cip_machine_pump_start     = HH:MM
 *   cip_machine_pump_end       = HH:MM
 *
 * Vessel CIP fields (dynamic: racking has one destination vessel; brewing/packaging may
 * show multiple rows but each uses an index suffix _0, _1, …):
 *
 *   cip_vessel_count           = INT (how many vessel rows the form submits; default 1)
 *
 * For vessel index N (0-based):
 *   cip_vessel_{N}_code        = ENUM: cct|yt|bbt|tank
 *   cip_vessel_{N}_number      = INT (tank number; nullable for yt)
 *   cip_vessel_{N}_type_id     = ref_cip_types.id
 *   cip_vessel_{N}_date        = VARCHAR date
 *   cip_vessel_{N}_start       = HH:MM
 *   cip_vessel_{N}_end         = HH:MM
 *   cip_vessel_{N}_done        = "1" when vessel CIP was performed (gate; if absent, skip)
 *
 * Notes field (shared, optional):
 *   cip_notes                  = VARCHAR(255) free text
 *
 * ══════════════════════════════════════════════════════════════════════════
 *
 * Public API:
 *   cip_parse_post(array $post, string $sourceForm): array   → events[]
 *   cip_upsert(PDO $pdo, string $sourceForm, int $parentId,
 *              array $events, array $meta): void
 *   cip_events_for(PDO $pdo, string $sourceForm, int $parentId): array
 *   cip_type_options(PDO $pdo): array
 *   cip_dest_required(float|string|null $residual): bool
 *
 * Dependencies: app/db-write-helpers.php (bd_row_hash, log_revision), app/auth.php.
 */

require_once __DIR__ . '/db-write-helpers.php';  // bd_row_hash, log_revision
require_once __DIR__ . '/auth.php';               // current_user

// ─── Constants ────────────────────────────────────────────────────────────────

/** Valid machine target_codes. */
const CIP_MACHINE_CODES = ['centri', 'kze', 'pump', 'unspecified'];

/** Valid vessel target_codes. */
const CIP_VESSEL_CODES  = ['cct', 'yt', 'bbt', 'tank'];

/** Valid source_form values (mirrors bd_cip_events ENUM). */
const CIP_SOURCE_FORMS  = ['racking', 'brewing', 'packaging'];

// ─── 1. Parser ────────────────────────────────────────────────────────────────

/**
 * cip_parse_post — Turn raw $_POST data into a normalised events[] array.
 *
 * Each element of the returned array is one CIP event record:
 *   [
 *     'target_kind'   => 'machine'|'vessel',
 *     'target_code'   => string (from CIP_MACHINE_CODES | CIP_VESSEL_CODES),
 *     'target_number' => int|null,
 *     'cip_type_id'   => int|null,
 *     'cip_date'      => string|null,
 *     'cip_started_at'=> string|null  (HH:MM:SS),
 *     'cip_ended_at'  => string|null  (HH:MM:SS),
 *     'inline_group'  => int|null,    (shared id for centri+kze inline pair)
 *     'notes'         => string|null,
 *   ]
 *
 * Rules:
 *   - Machine rows whose "CIP done" checkbox is off are silently skipped.
 *   - Vessel rows with cip_vessel_{N}_done != "1" are skipped.
 *   - cip_inline_combine = "1": centri + kze events share inline_group = 1.
 *   - Times are normalised to HH:MM:SS (or null when absent).
 *   - sourceForm is validated against CIP_SOURCE_FORMS.
 *
 * @param array  $post       Raw POST array (pass $_POST or a slice thereof)
 * @param string $sourceForm 'racking'|'brewing'|'packaging'
 * @return array             Event records (may be empty)
 * @throws InvalidArgumentException on invalid sourceForm
 */
function cip_parse_post(array $post, string $sourceForm): array
{
    if (!in_array($sourceForm, CIP_SOURCE_FORMS, true)) {
        throw new InvalidArgumentException("cip_parse_post: unknown sourceForm '{$sourceForm}'");
    }

    $events = [];
    $notes  = isset($post['cip_notes']) ? _cip_trim($post['cip_notes']) : null;

    // ── Machine rows (centri / kze / pump) ───────────────────────────────────
    // Only emitted by racking + packaging forms; brewing form passes no machine fields.
    $inlineCombine = (($post['cip_inline_combine'] ?? '0') === '1');

    if ($inlineCombine) {
        // ── SIMULTANÉ mode ───────────────────────────────────────────────────
        // Read the single combined block. Emit TWO events (centri + kze) sharing
        // inline_group=1 and identical date/type/start/end values.
        // Individual cip_machine_centri_* / cip_machine_kze_* fields are NOT read —
        // the UI hides and clears them when simultané is checked.
        $combinedTypeId = _cip_int($post['cip_combined_type_id'] ?? null);
        $combinedDate   = _cip_trim($post['cip_combined_date'] ?? null);
        $combinedStart  = _cip_time($post['cip_combined_start'] ?? null);
        $combinedEnd    = _cip_time($post['cip_combined_end'] ?? null);

        foreach (['centri', 'kze'] as $code) {
            $events[] = [
                'target_kind'    => 'machine',
                'target_code'    => $code,
                'target_number'  => null,
                'cip_type_id'    => $combinedTypeId,
                'cip_date'       => $combinedDate ?: null,
                'cip_started_at' => $combinedStart,
                'cip_ended_at'   => $combinedEnd,
                'inline_group'   => 1,
                'notes'          => $notes,
            ];
        }

        // Pump is always independent — parsed regardless of simultané mode.
        if (($post['cip_machine_pump'] ?? '0') === '1') {
            $events[] = [
                'target_kind'    => 'machine',
                'target_code'    => 'pump',
                'target_number'  => null,
                'cip_type_id'    => _cip_int($post['cip_machine_pump_type_id'] ?? null),
                'cip_date'       => _cip_trim($post['cip_machine_pump_date'] ?? null) ?: null,
                'cip_started_at' => _cip_time($post['cip_machine_pump_start'] ?? null),
                'cip_ended_at'   => _cip_time($post['cip_machine_pump_end'] ?? null),
                'inline_group'   => null,
                'notes'          => $notes,
            ];
        }
    } else {
        // ── INDIVIDUAL mode ──────────────────────────────────────────────────
        // Parse centri / kze / pump independently; inline_group stays NULL.
        foreach (['centri', 'kze', 'pump'] as $code) {
            if (($post["cip_machine_{$code}"] ?? '0') !== '1') {
                continue;
            }

            $events[] = [
                'target_kind'    => 'machine',
                'target_code'    => $code,
                'target_number'  => null,
                'cip_type_id'    => _cip_int($post["cip_machine_{$code}_type_id"] ?? null),
                'cip_date'       => _cip_trim($post["cip_machine_{$code}_date"] ?? null) ?: null,
                'cip_started_at' => _cip_time($post["cip_machine_{$code}_start"] ?? null),
                'cip_ended_at'   => _cip_time($post["cip_machine_{$code}_end"] ?? null),
                'inline_group'   => null,
                'notes'          => $notes,
            ];
        }
    }

    // ── Vessel rows ──────────────────────────────────────────────────────────
    $vestCount = max(0, (int)($post['cip_vessel_count'] ?? 0));

    for ($i = 0; $i < $vestCount; $i++) {
        if (($post["cip_vessel_{$i}_done"] ?? '0') !== '1') {
            continue;
        }

        $code   = _cip_trim($post["cip_vessel_{$i}_code"] ?? null);
        if ($code === null || !in_array($code, CIP_VESSEL_CODES, true)) {
            continue; // skip malformed vessel rows silently
        }

        $number = _cip_int($post["cip_vessel_{$i}_number"] ?? null);
        $typeId = _cip_int($post["cip_vessel_{$i}_type_id"] ?? null);
        $date   = _cip_trim($post["cip_vessel_{$i}_date"] ?? null);
        $start  = _cip_time($post["cip_vessel_{$i}_start"] ?? null);
        $end    = _cip_time($post["cip_vessel_{$i}_end"] ?? null);

        $events[] = [
            'target_kind'    => 'vessel',
            'target_code'    => $code,
            'target_number'  => $number,
            'cip_type_id'    => $typeId,
            'cip_date'       => $date ?: null,
            'cip_started_at' => $start,
            'cip_ended_at'   => $end,
            'inline_group'   => null,
            'notes'          => $notes,
        ];
    }

    return $events;
}

// ─── 2. Writer ────────────────────────────────────────────────────────────────

/**
 * cip_upsert — Write CIP events for a parent row.
 *
 * Re-submit semantics (tombstone-resync):
 *   1. SET is_tombstoned = 1 on all LIVE events for this (source_form, parentId).
 *   2. INSERT each new event with a fresh row_hash.
 *   3. Row-hash uniqueness means identical re-submits are idempotent: the INSERT
 *      hits uq_row_hash and falls through ON DUPLICATE KEY UPDATE (no-op, since
 *      we only update is_tombstoned back to 0 if it had been tombstoned).
 *
 * Row-hash discriminator ensures distinct events never collide:
 *   sha256( sourceForm | parentId | submitted_at | event_index | target_code
 *           | target_number | inline_group )
 *
 * @param PDO    $pdo        Live connection
 * @param string $sourceForm 'racking'|'brewing'|'packaging'
 * @param int    $parentId   PK in bd_racking_v2 / bd_brewing_brewday_v2 / bd_packaging_v2
 * @param array  $events     Output of cip_parse_post()
 * @param array  $meta       ['submitted_at' => string, 'email' => string]
 * @throws InvalidArgumentException on bad sourceForm or kind↔code violation
 * @throws RuntimeException on SQL error
 */
function cip_upsert(
    PDO    $pdo,
    string $sourceForm,
    int    $parentId,
    array  $events,
    array  $meta
): void {
    if (!in_array($sourceForm, CIP_SOURCE_FORMS, true)) {
        throw new InvalidArgumentException("cip_upsert: unknown sourceForm '{$sourceForm}'");
    }

    // Validate each event's kind↔code constraint BEFORE touching the DB.
    foreach ($events as $idx => $ev) {
        _cip_assert_kind_code($ev['target_kind'], $ev['target_code'], $idx);
    }

    $submittedAt = $meta['submitted_at'] ?? date('Y-m-d H:i:s');
    $email       = $meta['email'] ?? null;

    // ── Parent FK columns ─────────────────────────────────────────────────
    $rackingId   = ($sourceForm === 'racking')   ? $parentId : null;
    $brewingId   = ($sourceForm === 'brewing')   ? $parentId : null;
    $packagingId = ($sourceForm === 'packaging') ? $parentId : null;

    // ── Transaction guard: wrap tombstone + insert atomically ─────────────
    // The calling form POST handler may already be inside a transaction.
    // Use a SAVEPOINT when nested to avoid PDO "there is already an active
    // transaction" exception.
    $ownTransaction = !$pdo->inTransaction();
    $savepoint = 'cip_upsert_sp_' . uniqid('', true);

    if ($ownTransaction) {
        $pdo->beginTransaction();
    } else {
        $pdo->exec("SAVEPOINT `{$savepoint}`");
    }

    try {
        // ── Step 1: tombstone prior live events for this parent ───────────
        $tsStmt = $pdo->prepare(
            "UPDATE bd_cip_events
                SET is_tombstoned = 1
              WHERE source_form = ?
                AND " . _cip_parent_col($sourceForm) . " = ?
                AND is_tombstoned = 0"
        );
        $tsStmt->execute([$sourceForm, $parentId]);
        $tombstonedCount = $tsStmt->rowCount();

        if (empty($events)) {
            // No events to insert; tombstoning was the entire operation.
            if ($ownTransaction) {
                $pdo->commit();
            } else {
                $pdo->exec("RELEASE SAVEPOINT `{$savepoint}`");
            }
            _cip_log_audit($pdo, $sourceForm, $parentId, $tombstonedCount, 0, $submittedAt);
            return;
        }

        // ── Step 2: insert new events ─────────────────────────────────────
        $insertStmt = $pdo->prepare(
            "INSERT INTO bd_cip_events
               (source_form, racking_id, brewing_id, packaging_id,
                target_kind, target_code, target_number,
                cip_type_id_fk, cip_date, cip_started_at, cip_ended_at,
                inline_group, notes, row_hash, submitted_at, email,
                is_tombstoned, imported_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, NOW())
             ON DUPLICATE KEY UPDATE
               is_tombstoned = 0,
               cip_type_id_fk  = VALUES(cip_type_id_fk),
               cip_date         = VALUES(cip_date),
               cip_started_at   = VALUES(cip_started_at),
               cip_ended_at     = VALUES(cip_ended_at),
               inline_group     = VALUES(inline_group),
               notes            = VALUES(notes)"
        );

        $insertedCount = 0;
        foreach ($events as $idx => $ev) {
            $hash = _cip_row_hash(
                $sourceForm,
                $parentId,
                $submittedAt,
                $idx,
                $ev['target_code'],
                $ev['target_number'],
                $ev['inline_group']
            );

            $insertStmt->execute([
                $sourceForm,
                $rackingId,
                $brewingId,
                $packagingId,
                $ev['target_kind'],
                $ev['target_code'],
                $ev['target_number'],
                $ev['cip_type_id'],
                $ev['cip_date'],
                $ev['cip_started_at'],
                $ev['cip_ended_at'],
                $ev['inline_group'],
                $ev['notes'],
                $hash,
                $submittedAt,
                $email,
            ]);
            $insertedCount++;
        }

        if ($ownTransaction) {
            $pdo->commit();
        } else {
            $pdo->exec("RELEASE SAVEPOINT `{$savepoint}`");
        }

    } catch (Throwable $e) {
        if ($ownTransaction) {
            $pdo->rollBack();
        } else {
            $pdo->exec("ROLLBACK TO SAVEPOINT `{$savepoint}`");
        }
        throw $e;
    }

    // ── Step 3: audit log ─────────────────────────────────────────────────
    _cip_log_audit($pdo, $sourceForm, $parentId, $tombstonedCount, $insertedCount, $submittedAt);
}

// ─── 3. Reader ────────────────────────────────────────────────────────────────

/**
 * cip_events_for — Read live CIP events for a parent, grouped for rendering.
 *
 * Returns:
 *   [
 *     'machines' => [
 *       'centri' => event_row|null,
 *       'kze'    => event_row|null,
 *       'pump'   => event_row|null,
 *     ],
 *     'inline_groups' => [ group_id => [event_row, ...], ... ],
 *     'vessels' => [ event_row, ... ],   (ordered by id ASC)
 *     'inline_combine' => bool,          (true if any events share an inline_group)
 *   ]
 *
 * Each event_row has all columns from bd_cip_events.
 *
 * @param PDO    $pdo
 * @param string $sourceForm
 * @param int    $parentId
 * @return array
 */
function cip_events_for(PDO $pdo, string $sourceForm, int $parentId): array
{
    $parentCol = _cip_parent_col($sourceForm);

    $stmt = $pdo->prepare(
        "SELECT ce.id, ce.target_kind, ce.target_code, ce.target_number,
                ce.cip_type_id_fk, ct.name AS cip_type_name,
                ce.cip_date, ce.cip_started_at, ce.cip_ended_at,
                ce.inline_group, ce.notes, ce.row_hash, ce.submitted_at, ce.email
           FROM bd_cip_events ce
           LEFT JOIN ref_cip_types ct ON ct.id = ce.cip_type_id_fk
          WHERE ce.source_form = ?
            AND ce.{$parentCol} = ?
            AND ce.is_tombstoned = 0
          ORDER BY ce.id ASC"
    );
    $stmt->execute([$sourceForm, $parentId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $machines = ['centri' => null, 'kze' => null, 'pump' => null];
    $vessels  = [];
    $inlineGroups = [];

    foreach ($rows as $row) {
        if ($row['target_kind'] === 'machine') {
            $code = $row['target_code'];
            if (array_key_exists($code, $machines)) {
                $machines[$code] = $row;
            }
            if ($row['inline_group'] !== null) {
                $g = (int)$row['inline_group'];
                $inlineGroups[$g][] = $row;
            }
        } else {
            $vessels[] = $row;
        }
    }

    $inlineCombine = !empty($inlineGroups);

    // Surface submission-level notes (shared across all events in a submission).
    // Take from the first live row; they all carry the same value.
    $notes = null;
    if (!empty($rows)) {
        $notes = $rows[0]['notes'];
    }

    return [
        'machines'      => $machines,
        'inline_groups' => $inlineGroups,
        'vessels'       => $vessels,
        'inline_combine'=> $inlineCombine,
        'notes'         => $notes,
    ];
}

// ─── 4. Dropdown options ──────────────────────────────────────────────────────

/**
 * cip_type_options — Return active CIP types for <select> rendering.
 *
 * @param PDO $pdo
 * @return array  [ ['id' => int, 'name' => string], ... ]
 */
function cip_type_options(PDO $pdo): array
{
    $stmt = $pdo->query(
        "SELECT id, name FROM ref_cip_types WHERE is_active = 1 ORDER BY sort_order, name"
    );
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ─── 5. Conditional dest-CIP predicate ───────────────────────────────────────

/**
 * cip_dest_bbt_is_clean — Event-sourced BBT clean-state resolver.
 *
 * A BBT is CLEAN if, since its last fill, the most recent relevant event is:
 *   (a) a bd_cip_events row with target_kind='vessel', target_code='bbt', target_number=$bbtNum
 *       (an actual CIP was performed), OR
 *   (b) a bd_racking_v2 row with interrupted_flag=1, racked_vol_hl=0, dest_bbt_still_clean=1
 *       (an interrupted-before-transfer attests the BBT was not contaminated).
 *
 * A BBT is DIRTY if its last relevant event is:
 *   (a) a bd_racking_v2 row with racked_vol_hl > 0 (a successful fill occurred, no CIP since), OR
 *   (b) a bd_racking_v2 row with interrupted_flag=1, racked_vol_hl=0, dest_bbt_still_clean=0, OR
 *   (c) no relevant events exist at all (conservative: treat as unknown=dirty).
 *
 * "Last fill" anchor: the latest bd_racking_v2 row with bbt_number=$bbtNum AND
 *   (racked_vol_hl > 0 OR (interrupted_flag=1 AND racked_vol_hl=0 AND dest_bbt_still_clean IS NOT NULL)).
 * We look only at events after that anchor.
 *
 * Event-sourced: NO stored clean/dirty column on any BBT table.
 * Returns: 'clean'|'dirty'|'unknown' (unknown = no relevant events for this BBT)
 *
 * @param PDO $pdo
 * @param int $bbtNum  The BBT number (from ref_bbt.number).
 * @return string 'clean'|'dirty'|'unknown'
 */
function cip_dest_bbt_is_clean(PDO $pdo, int $bbtNum): string
{
    // Determine if a BBT is clean by examining the most recent anchor event.
    //
    // PHYSICAL SEMANTICS (simplified by the form architecture):
    //   In this system, a CIP for a BBT is always recorded AS PART OF the racking form
    //   that fills the BBT. There is no standalone "CIP only" form for BBTs. Therefore:
    //
    //   • After a real fill (racked_vol > 0): the BBT contains beer → DIRTY for the next fill.
    //     The CIP included in that same form submission cleaned the BBT BEFORE the fill.
    //     That CIP has no bearing on the BBT's clean-state AFTER the fill.
    //
    //   • An interrupted-zero-transfer (interrupted_flag=1, racked_vol=0) is the ONLY event
    //     that can attest "the BBT is still clean after nothing was put into it":
    //       dest_bbt_still_clean = 1 → CLEAN (operator attests no contamination occurred).
    //       dest_bbt_still_clean = 0 → DIRTY (something happened that dirtied it anyway).
    //       dest_bbt_still_clean IS NULL → not captured in this event; treat as DIRTY (conservative).
    //
    //   • No relevant events at all → UNKNOWN (conservative: treat as dirty by callers).
    //
    // Algorithm: find the most recent anchor event (real fill OR interrupted-zero-attestation)
    // ordered by id DESC (BIGINT autoincrement = reliable insertion order regardless of submitted_at).

    $lastAnchorStmt = $pdo->prepare(
        "SELECT id, racked_vol_hl, interrupted_flag, dest_bbt_still_clean
           FROM bd_racking_v2
          WHERE bbt_number = ?
            AND is_tombstoned = 0
            AND (
              (racked_vol_hl IS NOT NULL AND CAST(racked_vol_hl AS DECIMAL(8,3)) > 0)
              OR (interrupted_flag = 1
                  AND (racked_vol_hl IS NULL OR CAST(racked_vol_hl AS DECIMAL(8,3)) = 0))
            )
          ORDER BY id DESC
          LIMIT 1"
    );
    $lastAnchorStmt->execute([$bbtNum]);
    $lastAnchor = $lastAnchorStmt->fetch(PDO::FETCH_ASSOC);

    if ($lastAnchor === false) {
        // No fill or interrupted event for this BBT on record.
        return 'unknown';
    }

    // Real fill: the BBT has beer in it → dirty.
    if ((float)($lastAnchor['racked_vol_hl'] ?? 0) > 0.0) {
        return 'dirty';
    }

    // Interrupted-zero: use the dest_bbt_still_clean attestation if set.
    if ((int)$lastAnchor['interrupted_flag'] === 1) {
        if ($lastAnchor['dest_bbt_still_clean'] === null) {
            return 'dirty';  // conservative: attestation absent
        }
        return ((int)$lastAnchor['dest_bbt_still_clean'] === 1) ? 'clean' : 'dirty';
    }

    // Fallback (should not be reached given the WHERE clause): conservative.
    return 'dirty';
}

/**
 * cip_dest_required — TRUE when the destination vessel requires a CIP.
 *
 * Composition: dest-CIP required = (residual == 0) AND NOT(destination BBT is currently clean).
 *   - residual > 0: a blend means liquid is already in the destination vessel (same beer,
 *     vessel not empty) → CIP is optional regardless of clean-state.
 *   - residual = 0 AND BBT is clean (recent CIP or interrupted-zero-attested): CIP NOT required.
 *   - residual = 0 AND BBT is dirty (fill occurred since last CIP): CIP IS required.
 *   - residual = 0 AND BBT state is unknown (no history): CIP IS required (conservative).
 *
 * When $pdo and $bbtNum are both provided, the event-sourced clean-state is consulted.
 * When either is absent (legacy call path / non-BBT destination), the old logic applies:
 *   required iff residual == 0.
 *
 * Used by form-racking.php; $pdo + $bbtNum are optional for backwards compatibility
 * (forms that never needed the clean-state gate can keep passing only $residual).
 *
 * @param float|string|null $residual  blend_hl or similar residual volume
 * @param PDO|null          $pdo       DB connection (optional; enables clean-state check)
 * @param int|null          $bbtNum    BBT number (optional; needed for clean-state check)
 * @return bool
 */
function cip_dest_required(float|string|null $residual, ?PDO $pdo = null, ?int $bbtNum = null): bool
{
    if ($residual === null || $residual === '' || $residual === '0' || $residual === '0.0') {
        $residualIsZero = true;
    } else {
        $v = (float)$residual;
        $residualIsZero = !($v > 0);
    }

    // Residual > 0: blend case — dest CIP always optional.
    if (!$residualIsZero) {
        return false;
    }

    // Residual = 0: check clean-state when we have the info.
    if ($pdo !== null && $bbtNum !== null) {
        $cleanState = cip_dest_bbt_is_clean($pdo, $bbtNum);
        // clean → CIP NOT required. dirty|unknown → required.
        return ($cleanState !== 'clean');
    }

    // Fallback: no clean-state info → required when residual = 0 (conservative).
    return true;
}

// ─── Private helpers ──────────────────────────────────────────────────────────

/**
 * Compute the per-event row_hash.
 * Discriminator: sourceForm|parentId|submittedAt|eventIndex|targetCode|targetNumber|inlineGroup
 * The submitted_at + eventIndex pair ensures distinct events under one parent never collide,
 * while re-inserting the SAME event (same submission) is idempotent (same hash → uq_row_hash
 * triggers ON DUPLICATE KEY UPDATE).
 */
function _cip_row_hash(
    string $sourceForm,
    int    $parentId,
    string $submittedAt,
    int    $eventIdx,
    string $targetCode,
    ?int   $targetNumber,
    ?int   $inlineGroup
): string {
    $parts = [
        $sourceForm,
        (string)$parentId,
        $submittedAt,
        (string)$eventIdx,
        $targetCode,
        $targetNumber !== null ? (string)$targetNumber : '',
        $inlineGroup  !== null ? (string)$inlineGroup  : '',
    ];
    return hash('sha256', implode('|', $parts));
}

/**
 * Assert that a target_kind / target_code combination satisfies the DB CHECK constraint.
 * Throws InvalidArgumentException loudly — don't let the DB CHECK be the only guard.
 */
function _cip_assert_kind_code(string $kind, string $code, int $idx): void
{
    if ($kind === 'machine' && !in_array($code, CIP_MACHINE_CODES, true)) {
        throw new InvalidArgumentException(
            "cip_upsert: event[{$idx}] kind=machine but code='{$code}' is not in " .
            implode('/', CIP_MACHINE_CODES)
        );
    }
    if ($kind === 'vessel' && !in_array($code, CIP_VESSEL_CODES, true)) {
        throw new InvalidArgumentException(
            "cip_upsert: event[{$idx}] kind=vessel but code='{$code}' is not in " .
            implode('/', CIP_VESSEL_CODES)
        );
    }
    if (!in_array($kind, ['machine', 'vessel'], true)) {
        throw new InvalidArgumentException(
            "cip_upsert: event[{$idx}] invalid kind='{$kind}'"
        );
    }
}

/**
 * Return the FK column name for this source form.
 */
function _cip_parent_col(string $sourceForm): string
{
    return match ($sourceForm) {
        'racking'   => 'racking_id',
        'brewing'   => 'brewing_id',
        'packaging' => 'packaging_id',
        default     => throw new InvalidArgumentException("_cip_parent_col: unknown form '{$sourceForm}'"),
    };
}

/**
 * Trim a string value; return null when empty.
 */
function _cip_trim(?string $v): ?string
{
    if ($v === null) {
        return null;
    }
    $t = trim($v);
    return $t === '' ? null : $t;
}

/**
 * Cast a nullable string to int; return null when absent or non-numeric.
 */
function _cip_int(?string $v): ?int
{
    if ($v === null || $v === '') {
        return null;
    }
    return is_numeric($v) ? (int)$v : null;
}

/**
 * Normalise an HH:MM or HH:MM:SS time string to HH:MM:SS.
 * Returns null when the input is absent or not a recognisable time.
 */
function _cip_time(?string $v): ?string
{
    if ($v === null || $v === '') {
        return null;
    }
    $t = trim($v);
    // Already HH:MM:SS
    if (preg_match('/^\d{1,2}:\d{2}:\d{2}$/', $t)) {
        return $t;
    }
    // HH:MM — append :00
    if (preg_match('/^\d{1,2}:\d{2}$/', $t)) {
        return $t . ':00';
    }
    return null;
}

/**
 * Write an audit_row_revisions entry for a cip_upsert call.
 * Uses log_revision() from db-write-helpers.php.
 * We write against bd_cip_events (no single PK — use 0 to mean "bulk").
 */
function _cip_log_audit(
    PDO    $pdo,
    string $sourceForm,
    int    $parentId,
    int    $tombstonedCount,
    int    $insertedCount,
    string $submittedAt
): void {
    // Retrieve current_user() for audit metadata
    $me = current_user();
    if ($me === null) {
        $me = ['id' => 0, 'username' => 'system'];
    }

    $after = [
        'source_form'      => $sourceForm,
        'parent_id'        => $parentId,
        'tombstoned_count' => $tombstonedCount,
        'inserted_count'   => $insertedCount,
        'submitted_at'     => $submittedAt,
    ];

    log_revision(
        $pdo,
        $me,
        'bd_cip_events',
        $parentId,   // use parentId as the logical "PK" for this bulk operation
        null,        // before: previous events were tombstoned, not captured inline
        $after,
        'normal',    // CIP writes don't have QC flags
        null
    );
}
