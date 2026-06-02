<?php
declare(strict_types=1);
/**
 * form-racking.php — Operator transfer (racking) entry form (bd_racking_v2).
 *
 * Deliverable A: renamed from "Soutirage" → "Transferts" in all user-facing labels.
 *   File name, route (?m=racking / URL /modules/form-racking.php), and DB table
 *   names are intentionally UNCHANGED.
 *
 * Deliverable B: Beer selection replaced from ungated dropdown to selectable
 *   eligibility cards. A beer is eligible iff:
 *     - It is currently in a CCT per TankSimulator (the authoritative state engine).
 *       SQL is a coarse pre-filter only; the sim is the truth (mirrors form-packaging.php).
 *     - Its latest ColdCrash event (bd_fermenting_v2) is ≥ effective_garde days ago.
 *     - effective_garde comes from yeast-eligibility.php (COALESCE override→family).
 *     - NULL effective_garde → NOT eligible (surfaces only under hors-process).
 *
 * Deliverable C: "Choix Hors Process" toggle (manager/admin only). Expands
 *   candidate set to ALL beers physically in a CCT or BBT ignoring the time gate.
 *   Server-side role enforcement: hors_process=1 from a non-manager is silently ignored.
 *   Writes hors_process_flag + hors_process_reason to bd_racking_v2 (migration 174).
 *
 * Round-2 changes (2026-05-27):
 *   #1  Hors-process ALSO includes BBT-occupied lots (from bd_racking_v2 latest-per-tank
 *       WHERE racking_destination_type IN ('BBT','CCT'), TankSimulator-filtered).
 *       These surface ONLY in the override (hors-process) list. CCT and BBT lots are
 *       keyed by source tank to avoid double-listing a CCT→BBT lot.
 *   #2  event_date input has no min/max lock — back-dating works. Verified.
 *   #3  client free-text input REMOVED. bd_racking_v2.client now resolved server-side
 *       from the selected recipe FK: ref_recipes.client_id → ref_clients.name
 *       (NULL client_id ⇒ "Nébuleuse"). The column is still written.
 *   #4  Destination: CCT label → "CCT N°"; YT dropdown added (ref_yt.number);
 *       yt_number written via migration 179. JS show/hide for BBT/CCT/YT selects.
 *   #5  "Volume blend" relabelled → "Volume résiduel en cuve" (column stays blend_hl).
 *       Semantics: volume already in the DESTINATION tank at transfer. Available for
 *       all dest types. Derived "Volume résultant en cuve" shown read-only in UI (JS sum).
 *   #6  CO₂/O₂ labels ("CO₂ BBT"/"O₂ BBT") swap client-side by dest type.
 *   #7  "Vitesse moyenne" input REMOVED. avg_speed column kept for historical data.
 *   #8  CIP section: flat CIP cards replaced by shared partial (cip-section.php) as the
 *       FIRST section. Old flat CIP columns (last_cip_date/cip_type/cip_bbt_*) no longer
 *       written by the web form. Removed from bd_row_hash to avoid hash drift.
 *   #9  Dest CIP label is dynamic (partial's dynamic_label=true handles it).
 *  #10  CIP type dropdown from ref_cip_types (via cip_type_options). Old free-text gone.
 *
 * Conditional dest-CIP: cip_dest_required($blend_hl) — residual>0 makes dest CIP
 *   optional (blend into same beer; vessel not empty). Enforced server-side + client-side.
 *
 * Writes to: bd_racking_v2 + bd_cip_events + audit_row_revisions
 * Natural key: (submitted_at, neb_beer, neb_batch, contract_beer, contract_batch, seq)
 * URL: /modules/form-racking.php  (route unchanged)
 */

require __DIR__ . '/../../app/auth.php';
require __DIR__ . '/../../app/settings-helpers.php';
require __DIR__ . '/../../app/db-write-helpers.php';
require_once __DIR__ . '/../../app/yeast-eligibility.php';
require_once __DIR__ . '/../../app/tank-simulator.php';
require_once __DIR__ . '/../../app/cip-events.php';
require_once __DIR__ . '/../../app/qc-thresholds.php';

require_login();
$me = current_user();

// ── Allowed enum values ───────────────────────────────────────────────────
const RACK_TYPES        = ['Centri', 'KZE', 'Pump', 'Centri+KZE'];
const DEST_TYPES        = ['BBT', 'CCT', 'YT'];
const CENTRI_RINSED_YN  = ['Oui', 'Non'];

// ── POST ──────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!csrf_verify($_POST['csrf'] ?? null)) {
        flash_set('err', 'Session expirée — recharge la page.');
        redirect_to('/modules/form-racking.php');
    }

    try {
        $pdo = maltytask_pdo();

        // ── 1. Coerce + validate inputs ──────────────────────────────────

        // ── Override: Choix Hors Process (manager/admin only) ───────────
        $horsProcessRequested = (post_int('hors_process') === 1);
        $horsProcessAllowed   = (is_admin($me) || is_manager($me));
        $horsProcessFlag      = ($horsProcessRequested && $horsProcessAllowed) ? 1 : 0;
        $horsProcessReason    = ($horsProcessFlag === 1) ? post_str('hors_process_reason') : null;

        // Beer identity — populated by JS from card selection (hidden fields)
        $nebBeer      = post_str('neb_beer')     ?? '';
        $nebBatch     = post_str('neb_batch')    ?? '';
        $contractBeer  = post_str('contract_beer')  ?? '';
        $contractBatch = post_str('contract_batch') ?? '';
        $sourceCct     = post_int('source_cct_number');

        if ($nebBeer === '' && $contractBeer === '') {
            throw new RuntimeException("Au moins une bière (Nébuleuse ou contrat) est requise.");
        }

        $nebRecipeId      = post_int('neb_recipe_id_fk');
        $contractRecipeId = post_int('contract_recipe_id_fk');
        // rack_type is derived server-side from CIP machine events after cip_parse_post().
        // (Removed: post_str('rack_type') + must_be_one_of guard — now set below.)

        $destType   = post_str('racking_destination_type');
        if ($destType !== null) must_be_one_of('racking_destination_type', $destType, DEST_TYPES);

        $bbtNumber = post_int('bbt_number');
        $cctNumber = post_int('cct_number');
        $ytNumber  = post_int('yt_number');   // #4 — new YT field

        // Build target_tank_raw from parsed destination
        $targetTankRaw = null;
        if ($destType === 'BBT' && $bbtNumber !== null) {
            $targetTankRaw = "BBT {$bbtNumber}";
        } elseif ($destType === 'CCT' && $cctNumber !== null) {
            $targetTankRaw = "CCT {$cctNumber}";
        } elseif ($destType === 'YT' && $ytNumber !== null) {
            $targetTankRaw = "YT {$ytNumber}";
        } elseif ($destType === 'YT') {
            $targetTankRaw = 'YT';
        }

        // #3 — Resolve client SERVER-SIDE from the recipe FK chain.
        // Selected recipe → ref_recipes.client_id → ref_clients.name
        // (NULL client_id ⇒ "Nébuleuse"). Never use operator free-text for client.
        $resolvedRecipeId = $nebRecipeId ?? $contractRecipeId;
        $client = null;
        if ($resolvedRecipeId !== null) {
            $clientStmt = $pdo->prepare(
                "SELECT rc.name
                   FROM ref_recipes rr
                   LEFT JOIN ref_clients rc ON rc.id = rr.client_id
                  WHERE rr.id = ?
                  LIMIT 1"
            );
            $clientStmt->execute([$resolvedRecipeId]);
            $clientRow = $clientStmt->fetch(PDO::FETCH_ASSOC);
            $client = ($clientRow !== false && $clientRow['name'] !== null)
                ? $clientRow['name']
                : 'Nébuleuse';
        }

        $startTimeRaw = post_str('start_time');
        $endTimeRaw   = post_str('end_time');
        $eventDateRaw = post_str('event_date');
        // Combine event_date + times for start_time/end_time DATETIME columns
        $startTime = ($eventDateRaw && $startTimeRaw) ? "{$eventDateRaw} {$startTimeRaw}:00" : null;
        $endTime   = ($eventDateRaw && $endTimeRaw)   ? "{$eventDateRaw} {$endTimeRaw}:00"   : null;

        $bbtCo2      = post_decimal('bbt_co2');
        $bbtO2       = post_decimal('bbt_o2');
        $rackedVolHl = post_decimal('racked_vol_hl');
        $flowStart   = post_decimal('flowmeter_start_hl');
        $flowEnd     = post_decimal('flowmeter_end_hl');
        // Flowmeter derive: when both readings present, end must be >= start.
        if ($flowStart !== null && $flowEnd !== null) {
            if ((float)$flowEnd < (float)$flowStart) {
                throw new RuntimeException(
                    "Relevé compteur fin ({$flowEnd}) < début ({$flowStart}) — vérifiez les relevés."
                );
            }
            $rackedVolHl = number_format((float)$flowEnd - (float)$flowStart, 1, '.', '');
        }
        // #5 — blend_hl = residual volume in destination tank at transfer time
        $blendHl     = post_decimal('blend_hl');
        $avgTurbidity = post_decimal('avg_turbidity');
        // #7 — avg_speed removed from form; column kept for historical data
        $bbtPressure = post_decimal('bbt_pressure');

        $centriRinsed = post_str('centri_rinsed');
        if ($centriRinsed !== null) must_be_one_of('centri_rinsed', $centriRinsed, CENTRI_RINSED_YN);

        $safetyCipDone = post_str('safety_cip_done');
        if ($safetyCipDone !== null) must_be_one_of('safety_cip_done', $safetyCipDone, CENTRI_RINSED_YN);

        // KZE pasteurisation data — only written when KZE is in the CIP set.
        // post_decimal returns null when the field is empty (section was hidden → no input).
        // Coerce and validate: value must be a non-negative decimal when provided.
        $kzeTargetPu = post_decimal('kze_target_pu');
        if ($kzeTargetPu !== null && (float)$kzeTargetPu < 0) {
            throw new RuntimeException("kze_target_pu doit être ≥ 0.");
        }
        $kzeAvgPu = post_decimal('kze_avg_pu');
        if ($kzeAvgPu !== null && (float)$kzeAvgPu < 0) {
            throw new RuntimeException("kze_avg_pu doit être ≥ 0.");
        }

        // #8 — CIP events via shared parser (old flat fields no longer read from POST)
        $cipEvents = cip_parse_post($_POST, 'racking');

        // Derive rack_type from the machine CIP events.
        // Centri+KZE (simultané) → two machine events, both present → 'Centri+KZE'.
        // Pump is a fallback when neither centri nor kze was CIP'd.
        // No machine event at all → null (column accepts NULL; hash uses '').
        $machineCodes = [];
        foreach ($cipEvents as $ev) {
            if ($ev['target_kind'] === 'machine') {
                $machineCodes[] = $ev['target_code'];
            }
        }
        $hasCentri = in_array('centri', $machineCodes, true);
        $hasKze    = in_array('kze',    $machineCodes, true);
        $hasPump   = in_array('pump',   $machineCodes, true);
        if ($hasCentri && $hasKze) {
            $rackType = 'Centri+KZE';
        } elseif ($hasCentri) {
            $rackType = 'Centri';
        } elseif ($hasKze) {
            $rackType = 'KZE';
        } elseif ($hasPump) {
            $rackType = 'Pump';
        } else {
            $rackType = null;
        }
        // Defensive guard — derived values are all in RACK_TYPES, but verify.
        if ($rackType !== null) must_be_one_of('rack_type', $rackType, RACK_TYPES);

        // ── Mandatory machine CIP validation ─────────────────────────────
        // At least one machine CIP (centri / KZE / pompe) must be present.
        // This both guarantees rack_type is always derived (never NULL) and
        // enforces the operational rule that every racking goes through equipment.
        if ($rackType === null) {
            throw new RuntimeException(
                "Au moins un équipement CIP (centri / KZE / pompe) doit être renseigné " .
                "— il détermine le type de transfert."
            );
        }

        // ── Conditional dest-CIP validation ─────────────────────────────
        // When residual (blend_hl) > 0, dest CIP is optional (blend into same beer).
        // When residual = 0 or null, dest CIP is required UNLESS the BBT is clean
        // (post-CIP or attested clean via an interrupted-zero-transfer event).
        // cip_dest_required() now accepts $pdo + $bbtNum for the event-sourced check.
        $destCipRequired = cip_dest_required($blendHl, $pdo, $bbtNumber);
        if ($destCipRequired) {
            // Check if any vessel CIP event was submitted for this dest
            $hasDestCip = false;
            foreach ($cipEvents as $ev) {
                if ($ev['target_kind'] === 'vessel') {
                    $hasDestCip = true;
                    break;
                }
            }
            if (!$hasDestCip) {
                throw new RuntimeException(
                    "CIP cuve destination requis (résiduel = 0). " .
                    "Saisir le CIP destination ou indiquer un volume résiduel > 0."
                );
            }
        }

        $comments   = post_str('comments');
        $fwComment  = post_str('fw_comment');

        // ── Transfert interrompu (C4) ────────────────────────────────────
        // interrupted_flag: 1 = the transfer was not completed normally.
        // interrupted_reason: required-while-interrupted; trimmed, capped 500 chars.
        // dest_bbt_still_clean: only captured when interrupted=1 AND racked_vol=0.
        //   1 → BBT stays CLEAN in the system (no CIP required for next fill).
        //   0 → BBT is dirty  → its next fill requires a CIP.
        //   NULL in all other cases (interrupted=0, or racked_vol > 0).
        $interruptedFlag = (post_int('interrupted_flag') === 1) ? 1 : 0;
        $interruptedReason = null;
        $destBbtStillClean = null;

        if ($interruptedFlag === 1) {
            $interruptedReason = post_str('interrupted_reason');
            if ($interruptedReason !== null) {
                $interruptedReason = trim($interruptedReason);
                if (mb_strlen($interruptedReason) > 500) {
                    $interruptedReason = mb_substr($interruptedReason, 0, 500);
                }
            }
            if ($interruptedReason === '' || $interruptedReason === null) {
                throw new RuntimeException(
                    "Un transfert interrompu nécessite une raison. " .
                    "Renseigner le champ « Raison de l'interruption »."
                );
            }
            // dest_bbt_still_clean: captured only when racked_vol_hl = 0 (nothing transferred)
            // Peek at racked_vol before the main read below (harmless duplicate post_decimal call).
            $rackedVolPeek = post_decimal('racked_vol_hl');
            $rackedVolIsZero = ($rackedVolPeek === null || (float)$rackedVolPeek === 0.0);
            if ($rackedVolIsZero) {
                $cleanRaw = post_str('dest_bbt_still_clean');
                // Accept '1' (Oui) or '0' (Non); anything else → require explicit choice
                if ($cleanRaw !== '1' && $cleanRaw !== '0') {
                    throw new RuntimeException(
                        "Volume transféré = 0 et transfert interrompu : indiquer si la BBT est " .
                        "encore propre (Oui / Non)."
                    );
                }
                $destBbtStillClean = (int)$cleanRaw;
            }
            // racked_vol > 0 → dest_bbt_still_clean stays NULL (semantics: N/A)
        }
        // interrupted_flag = 0 → reason and clean-state must be NULL (force regardless of submitted garbage)

        // ── Pertes (C3) ─────────────────────────────────────────────────
        // Read with read-then-validate discipline: empty → NULL; volumes
        // cast to float ≥ 0; loss_cause must be in the ENUM domain or NULL;
        // loss_note trimmed and capped at 500 chars.
        //
        // Server-enforce: if a loss volume > 0 then loss_cause must be non-null.
        $lossSourceHl = post_decimal('loss_source_hl');
        $lossDestHl   = post_decimal('loss_dest_hl');
        $lossCause    = post_str('loss_cause');
        $lossNote     = post_str('loss_note');

        // Non-negative volumes
        if ($lossSourceHl !== null && (float)$lossSourceHl < 0) {
            throw new RuntimeException("loss_source_hl doit être ≥ 0.");
        }
        if ($lossDestHl !== null && (float)$lossDestHl < 0) {
            throw new RuntimeException("loss_dest_hl doit être ≥ 0.");
        }

        // loss_cause domain check
        $lossCauseAllowed = ['produit', 'machine', 'humain'];
        if ($lossCause !== null) {
            must_be_one_of('loss_cause', $lossCause, $lossCauseAllowed);
        }

        // loss_note capped at 500 chars
        if ($lossNote !== null && mb_strlen($lossNote) > 500) {
            $lossNote = mb_substr($lossNote, 0, 500);
        }

        // Server-side required: any volume > 0 must have a cause
        $hasLossVol = (
            ($lossSourceHl !== null && (float)$lossSourceHl > 0) ||
            ($lossDestHl   !== null && (float)$lossDestHl   > 0)
        );
        if ($hasLossVol && $lossCause === null) {
            throw new RuntimeException(
                "Une perte de volume a été saisie mais la cause est absente. " .
                "Sélectionner une cause (Produit / Machine / Humain)."
            );
        }

        // Toggle off (or both volumes 0/null) → store all four as NULL
        $perteToggle = post_str('perte_toggle');
        if ($perteToggle !== '1' || !$hasLossVol) {
            $lossSourceHl = null;
            $lossDestHl   = null;
            $lossCause    = null;
            $lossNote     = null;
        }

        // ── 2. QC flag ───────────────────────────────────────────────────
        $measurements = array_filter([
            'bbt_co2'      => $bbtCo2,
            'bbt_o2'       => $bbtO2,
            'racked_vol_hl'=> $rackedVolHl,
            'bbt_pressure' => $bbtPressure,
        ], fn($v) => $v !== null);
        $qcFlag = bd_qc_flag($measurements);

        // ── 3. Build submitted_at ────────────────────────────────────────
        $submittedAt = date('Y-m-d H:i:s.u');
        $eventDate   = $eventDateRaw ?? date('Y-m-d');

        $auditTokens = ['web_entry'];
        if ($qcFlag !== 'normal') $auditTokens[] = "qc_{$qcFlag}";
        if ($horsProcessFlag === 1) $auditTokens[] = 'hors_process_override';
        $auditFlags = implode(',', $auditTokens);

        // ── 4. Canonical row for hash (exclude meta cols + removed fields) ───
        // #8 CRITICAL: flat CIP fields (last_cip_date/cip_type/cip_bbt_*)
        //   are no longer part of the hash — they are not written from the web
        //   form any more (CIP now goes to bd_cip_events via cip_upsert).
        //   Leaving them in the hash after stopping to populate them would cause
        //   hash drift → silent row re-insertion. They are absent here.
        // #7: avg_speed removed from hash (field removed from form).
        // #3: client is now server-resolved, still included so it varies correctly.
        $hashCols = [
            $nebBeer, $nebBatch, $nebRecipeId ?? '',
            $contractBeer, $contractBatch, $contractRecipeId ?? '',
            $rackType ?? '', $client ?? '',
            $startTime ?? '', $endTime ?? '',
            $destType ?? '', $bbtNumber ?? '', $cctNumber ?? '', $ytNumber ?? '',
            $targetTankRaw ?? '',
            $bbtCo2 ?? '', $bbtO2 ?? '', $rackedVolHl ?? '', $flowStart ?? '', $flowEnd ?? '', $blendHl ?? '',
            $avgTurbidity ?? '', $bbtPressure ?? '',
            $centriRinsed ?? '', $safetyCipDone ?? '',
            $kzeTargetPu ?? '', $kzeAvgPu ?? '',
            $comments ?? '',
            $horsProcessFlag,
            // C3 — Pertes section
            $lossSourceHl ?? '', $lossDestHl ?? '', $lossCause ?? '', $lossNote ?? '',
            // C4 — Transfert interrompu
            $interruptedFlag,
            $interruptedReason ?? '',
            $destBbtStillClean ?? '',
        ];
        $rowHash = bd_row_hash($hashCols);

        // ── 5. Build row array ───────────────────────────────────────────
        // Note: last_cip_date / cip_type / cip_bbt_done / cip_bbt_type / cip_bbt_date
        //   are intentionally OMITTED — the web form no longer writes them.
        //   Historical rows from the ingest path retain their values.
        $row = [
            'row_hash'                 => $rowHash,
            'audit_flags'              => $auditFlags,
            'submitted_at'             => $submittedAt,
            'email'                    => $me['username'],
            'event_date'               => $eventDate,
            'seq'                      => 0,
            'neb_beer'                 => $nebBeer,
            'neb_batch'                => $nebBatch,
            'neb_recipe_id_fk'         => $nebRecipeId,
            'contract_beer'            => $contractBeer,
            'contract_batch'           => $contractBatch,
            'contract_recipe_id_fk'    => $contractRecipeId,
            'rack_type'                => $rackType,
            'client'                   => $client,  // #3 — FK-resolved, never free-text
            'start_time'               => $startTime,
            'end_time'                 => $endTime,
            'racking_destination_type' => $destType,
            'bbt_number'               => $bbtNumber,
            'cct_number'               => $cctNumber,
            'yt_number'                => $ytNumber,  // #4 — migration 179
            'target_tank_raw'          => $targetTankRaw,
            'bbt_co2'                  => $bbtCo2,
            'bbt_o2'                   => $bbtO2,
            'racked_vol_hl'            => $rackedVolHl,
            'flowmeter_start_hl'       => $flowStart,
            'flowmeter_end_hl'         => $flowEnd,
            'blend_hl'                 => $blendHl,  // #5 — residual in dest tank
            'avg_turbidity'            => $avgTurbidity,
            // avg_speed intentionally absent — column kept, form removed (#7)
            'bbt_pressure'             => $bbtPressure,
            'centri_rinsed'            => $centriRinsed,
            'safety_cip_done'          => $safetyCipDone,
            'kze_target_pu'            => $kzeTargetPu,
            'kze_avg_pu'               => $kzeAvgPu,
            'comments'                 => $comments,
            'hors_process_flag'        => $horsProcessFlag,
            'hors_process_reason'      => $horsProcessReason,
            // C3 — Pertes section
            'loss_source_hl'           => $lossSourceHl,
            'loss_dest_hl'             => $lossDestHl,
            'loss_cause'               => $lossCause,
            'loss_note'                => $lossNote,
            // C4 — Transfert interrompu
            'interrupted_flag'         => $interruptedFlag,
            'interrupted_reason'       => $interruptedReason,
            'dest_bbt_still_clean'     => $destBbtStillClean,
        ];

        $nkCols = ['submitted_at', 'neb_beer', 'neb_batch', 'contract_beer', 'contract_batch', 'seq'];

        // ── 6. Before-snapshot (web-form inserts always new submitted_at) ─
        $beforeSnapshot = null;

        // ── 7. UPSERT ────────────────────────────────────────────────────
        $result = bd_upsert($pdo, 'bd_racking_v2', $row, $nkCols);
        $rackingId = (int)$result['id'];

        // ── 8. CIP events ─────────────────────────────────────────────────
        // #8 — Write CIP events to bd_cip_events via the shared infra.
        $cipMeta = ['submitted_at' => $submittedAt, 'email' => $me['username']];
        cip_upsert($pdo, 'racking', $rackingId, $cipEvents, $cipMeta);

        // ── 9. Audit revision ─────────────────────────────────────────────
        log_revision(
            $pdo,
            $me,
            'bd_racking_v2',
            $rackingId,
            $beforeSnapshot,
            $row,
            $qcFlag,
            $fwComment ?: null
        );

        // ── 10. Success response ───────────────────────────────────────────
        $qcLabel = match($qcFlag) {
            'elevated' => ' — ⚠ valeurs à vérifier',
            'outlier'  => ' — 🔴 outlier enregistré',
            default    => '',
        };
        $beerLabel = $nebBeer !== '' ? $nebBeer : $contractBeer;
        $hpLabel   = $horsProcessFlag ? ' [HORS PROCESS]' : '';
        flash_set('ok', "Transfert enregistré : {$beerLabel}{$qcLabel}{$hpLabel}");

    } catch (Throwable $e) {
        flash_set('err', pdo_friendly_error($e, 'form-racking'));
    }

    redirect_to('/modules/form-racking.php');
}

// ── GET ───────────────────────────────────────────────────────────────────
header('Content-Type: text/html; charset=utf-8');

try {
    $pdo = maltytask_pdo();

    // ── Current user role for override capability ─────────────────────────
    $canOverride = (is_admin($me) || is_manager($me));

    // ── Candidate lots (CCT-based gated list) ────────────────────────────
    //
    // Eligibility derivation (unchanged from Round-1):
    //   1. Cold-crash day = latest bd_fermenting_v2.event_date WHERE event_type='ColdCrash'.
    //   2. Source CCT = bd_brewing_brewday_v2.cct.
    //   3. SQL occupancy guard (COARSE PRE-FILTER): no later brew into the same CCT.
    //   4. Eligible = DATEDIFF(CURDATE(), cold_crash_date) >= effective_garde.

    $candidateBaseSql = "
        SELECT
          bfw.recipe_id_fk,
          bfw.cct                                                      AS source_cct,
          bfw.batch,
          bfw.beer,
          bfw.event_date                                               AS brew_date,
          r.id                                                         AS recipe_id,
          r.name                                                       AS recipe_name,
          COALESCE(NULLIF(bfw.beer,''), r.name)                        AS beer_display,
          MAX(f.event_date)                                            AS cold_crash_date,
          DATEDIFF(CURDATE(), MAX(f.event_date))                       AS days_since_cold_crash,
          " . implode(",\n          ", yeast_eligibility_select_expressions()) . "
        FROM bd_brewing_brewday_v2 bfw
        JOIN ref_recipes r ON r.id = bfw.recipe_id_fk
        LEFT JOIN bd_fermenting_v2 f
             ON f.recipe_id_fk = bfw.recipe_id_fk
            AND f.batch = bfw.batch
            AND f.event_type = 'ColdCrash'
            AND f.is_tombstoned = 0
        " . yeast_eligibility_join_fragment() . "
        WHERE bfw.is_tombstoned = 0
          AND bfw.cct IS NOT NULL
          AND r.is_active = 1
          AND NOT EXISTS (
            SELECT 1 FROM bd_brewing_brewday_v2 b2
            WHERE b2.cct = bfw.cct
              AND b2.is_tombstoned = 0
              AND b2.event_date IS NOT NULL
              AND b2.event_date > bfw.event_date
          )
        GROUP BY
          bfw.recipe_id_fk, bfw.cct, bfw.batch, bfw.beer, bfw.event_date,
          r.id, r.name,
          r.yeast_strain_id_fk, r.garde_days_min_override,
          r.ferm_temp_min_override, r.ferm_temp_max_override,
          ys.name, ys.family,
          yfd.garde_days_min, yfd.ferm_temp_min, yfd.ferm_temp_max";

    // Normal (gated) query — effective_garde met + not yet racked.
    $candStmt = $pdo->prepare(
        $candidateBaseSql .
        " HAVING effective_garde IS NOT NULL
               AND days_since_cold_crash >= effective_garde
               AND NOT EXISTS (
                     SELECT 1 FROM bd_racking_v2 rk
                     WHERE rk.neb_recipe_id_fk = bfw.recipe_id_fk
                       AND rk.neb_batch = bfw.batch
                       AND rk.is_tombstoned = 0
                       AND rk.interrupted_flag = 0
                   )
          ORDER BY days_since_cold_crash DESC"
    );
    $candStmt->execute();
    $candidates = $candStmt->fetchAll(PDO::FETCH_ASSOC);

    // ── TankSimulator: authoritative CCT occupancy filter ─────────────────
    $simState = (new TankSimulator($pdo))->run(new DateTimeImmutable('today'));

    /**
     * Drop CCT candidates whose source CCT is null/empty in the TankSimulator.
     */
    $simFilterCct = function (array $list, array $simState): array {
        $out = [];
        foreach ($list as $cand) {
            $cctNum    = (int)($cand['source_cct'] ?? 0);
            $tankState = $simState['cct'][$cctNum] ?? null;
            if ($tankState === null) {
                continue;
            }
            // Expose CCT volume so JS can show the source-overrun soft warning
            // (loss_source_hl > sim_vol_hl) introduced in C3 Pertes section.
            $cand['sim_vol_hl'] = round((float)($tankState['volume_hl'] ?? 0), 2);
            $out[] = $cand;
        }
        return $out;
    };

    $candidates = $simFilterCct($candidates, $simState);

    // ── Override (hors-process) candidate list ───────────────────────────
    //
    // #1 — BBT-occupied lots added to the hors-process list.
    //
    // Source A: CCT-occupying lots (all, no time gate) — same as Round-1.
    // Source B: BBT-occupied lots from bd_racking_v2 (latest racking per BBT
    //   WHERE racking_destination_type IN ('BBT','CCT')), then sim-filtered.
    //   This is BBT occupancy from racking DESTINATIONS, not CCT source occupancy.
    //   Legitimate: identifies beers currently in a BBT awaiting packaging.
    //
    // Keying: Source A rows are keyed by ('cct', source_cct) and Source B rows
    //   by ('bbt', bbt_number) — prevents a CCT→BBT lot from appearing twice.
    //
    // Source B rows are marked with 'source_tank_type'='BBT' so JS can display
    //   them with appropriate labels (they don't have a cold-crash / garde context).
    $candidatesOverride = [];
    if ($canOverride) {
        // Source A: All CCT-occupying lots (no time gate)
        $overrideStmt = $pdo->prepare(
            $candidateBaseSql .
            " ORDER BY bfw.event_date DESC"
        );
        $overrideStmt->execute();
        $candidatesOverrideCct = $overrideStmt->fetchAll(PDO::FETCH_ASSOC);
        $candidatesOverrideCct = $simFilterCct($candidatesOverrideCct, $simState);

        // Key by ('cct', source_cct) to dedup against BBT source
        $seenTanks = [];
        foreach ($candidatesOverrideCct as $cand) {
            $key = 'cct|' . (int)($cand['source_cct'] ?? 0);
            $seenTanks[$key] = true;
            $cand['source_tank_type'] = 'CCT';
            $candidatesOverride[] = $cand;
        }

        // Source B: BBT-occupied lots — latest racking per BBT tank
        // Query: latest-per-BBT-destination racking row (mirrors form-packaging.php pattern).
        // This is the racking DESTINATION (BBT), NOT the source CCT.
        $bbtCandSql = "
            SELECT
              r.id                                                     AS racking_id,
              'BBT'                                                    AS source_tank_type,
              r.bbt_number                                             AS source_bbt,
              r.neb_beer,
              r.neb_batch,
              r.neb_recipe_id_fk,
              r.contract_beer,
              r.contract_batch,
              r.contract_recipe_id_fk,
              COALESCE(NULLIF(r.neb_beer,''), r.contract_beer)        AS beer_display,
              r.racked_vol_hl,
              r.event_date                                             AS brew_date,
              r2.name                                                  AS recipe_name
            FROM bd_racking_v2 r
            LEFT JOIN ref_recipes r2
              ON r2.id = COALESCE(r.neb_recipe_id_fk, r.contract_recipe_id_fk)
            WHERE r.racking_destination_type IN ('BBT', 'CCT')
              AND r.bbt_number IS NOT NULL
              AND r.is_tombstoned = 0
              AND r.id = (
                SELECT id FROM bd_racking_v2 r2i
                WHERE r2i.bbt_number = r.bbt_number
                  AND r2i.racking_destination_type IN ('BBT','CCT')
                  AND r2i.is_tombstoned = 0
                ORDER BY r2i.submitted_at DESC
                LIMIT 1
              )
            ORDER BY r.bbt_number ASC";

        $bbtCandRows = $pdo->query($bbtCandSql)->fetchAll(PDO::FETCH_ASSOC);

        // Sim-filter: keep only BBTs that are occupied in TankSimulator
        foreach ($bbtCandRows as $bbtRow) {
            $bbtNum    = (int)($bbtRow['source_bbt'] ?? 0);
            $simTank   = $simState['bbt'][$bbtNum] ?? null;
            if ($simTank === null) {
                continue; // Empty/expired in sim — lot is gone
            }

            // Dedup: skip if this BBT already appeared as a CCT-lot destination
            // (a CCT→BBT lot will appear in Source A as a CCT lot; keying prevents
            // double listing only the same physical tank, not the same beer)
            $bbtKey = 'bbt|' . $bbtNum;
            if (isset($seenTanks[$bbtKey])) {
                continue;
            }
            $seenTanks[$bbtKey] = true;

            // Construct a shape compatible with the card renderer
            $candidatesOverride[] = [
                'source_tank_type'     => 'BBT',
                'source_cct'           => null,
                'source_bbt'           => $bbtNum,
                'recipe_id'            => null,  // recipe_id for PHP card use
                'recipe_name'          => $bbtRow['recipe_name'] ?? '',
                'beer'                 => $bbtRow['neb_beer'] ?? $bbtRow['contract_beer'] ?? '',
                'batch'                => $bbtRow['neb_batch'] ?? $bbtRow['contract_batch'] ?? '',
                'beer_display'         => $bbtRow['beer_display'] ?? '',
                'neb_beer'             => $bbtRow['neb_beer'] ?? '',
                'neb_batch'            => $bbtRow['neb_batch'] ?? '',
                'neb_recipe_id_fk'     => $bbtRow['neb_recipe_id_fk'],
                'contract_beer'        => $bbtRow['contract_beer'] ?? '',
                'contract_batch'       => $bbtRow['contract_batch'] ?? '',
                'contract_recipe_id_fk'=> $bbtRow['contract_recipe_id_fk'],
                'cold_crash_date'      => null,
                'days_since_cold_crash'=> null,
                'effective_garde'      => null,
                'brew_date'            => $bbtRow['brew_date'] ?? null,
                'sim_vol_hl'           => round((float)$simTank['volume_hl'], 2),
            ];
        }
    }

    // Ref data for destination dropdowns
    $bbts = $pdo->query("SELECT number FROM ref_bbt ORDER BY number ASC")->fetchAll();
    $ccts = $pdo->query("SELECT number FROM ref_cct ORDER BY number ASC")->fetchAll();
    // #4 — YT numbers
    $yts  = $pdo->query("SELECT number FROM ref_yt WHERE status='active' ORDER BY number ASC")->fetchAll();

    // C4 — BBT clean-state map: bbtNumber → 'clean'|'dirty'|'unknown'
    // Exposed to JS so updateDestCipRequired() can compose (residual OR clean OR explicit).
    // Derived event-sourced from bd_cip_events + bd_racking_v2 via cip_dest_bbt_is_clean().
    $bbtCleanStates = [];
    foreach ($bbts as $bbtRow) {
        $bbtNum = (int)$bbtRow['number'];
        $bbtCleanStates[$bbtNum] = cip_dest_bbt_is_clean($pdo, $bbtNum);
    }

    // CIP infra
    $cipTypes = cip_type_options($pdo);

    // Recent submissions (last 10 racking entries from web)
    $recentRows = $pdo->prepare(
        "SELECT id, event_date, neb_beer, neb_batch, contract_beer, contract_batch,
                rack_type, target_tank_raw, racked_vol_hl, audit_flags,
                email, submitted_at, hors_process_flag, hors_process_reason
         FROM bd_racking_v2
         WHERE audit_flags LIKE '%web_entry%'
           AND is_tombstoned = 0
         ORDER BY submitted_at DESC LIMIT 10"
    );
    $recentRows->execute();
    $recentRackings = $recentRows->fetchAll();

    $loadErr = null;
} catch (Throwable $e) {
    $candidates         = [];
    $candidatesOverride = [];
    $bbts               = [];
    $ccts               = [];
    $yts                = [];
    $cipTypes           = [];
    $recentRackings     = [];
    $bbtCleanStates     = [];
    $canOverride        = false;
    $loadErr = $e->getMessage();
}

$csrf = csrf_token();
$active_module = 'racking';

// ── C5 — BBT blend candidates (per-beer, per-BBT lot composition) ─────────────
// Built from tank_bbt_composition() which derives composition purely from $simState
// (already computed above). Indexed by beer name for O(1) JS lookup.
// Shape: { "BeerName": [ {bbt, beer, total_hl, lots:[{batch, vol_hl, pct}]}, … ] }
// Beer names are canonical (TankSimulator internal) — human-readable, never DB codes.
$bbtBlendCandidates = [];
try {
    $bbtComposition = tank_bbt_composition($simState);
    foreach ($bbtComposition as $entry) {
        $beer = $entry['beer'];
        if (!isset($bbtBlendCandidates[$beer])) {
            $bbtBlendCandidates[$beer] = [];
        }
        $bbtBlendCandidates[$beer][] = $entry;
    }
} catch (Throwable $bbtCompErr) {
    // Non-fatal — JS treats missing key as "no blend candidates" and shows the
    // hors-process direction message. Never let a composition error block the form.
    $bbtBlendCandidates = [];
}
$bbtBlendCandidatesJson = json_encode($bbtBlendCandidates, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);

// Inject server-side data for JS
$candidatesJson         = json_encode($candidates,         JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
$candidatesOverrideJson = json_encode($candidatesOverride, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);

// ── Per-recipe QC thresholds for JS ──────────────────────────────────────────
// Collect all recipe ids that appear on any eligible-beer card (both normal and
// override lists), resolve per-recipe bands via the committed resolver, then
// transform to a field-name-keyed map (metric key → form field name) so JS can
// index directly by the form field name without any extra mapping.
//
// Metric key → form field name:
//   racked_vol_hl → racked_vol_hl   (identity)
//   co2           → bbt_co2
//   o2            → bbt_o2
//   pressure      → bbt_pressure
//
// A "__global" entry is included as a fallback (used when no card is selected
// or when the selected card's recipe id has no per-recipe entry).
$qcThresholdsJson = 'null';
try {
    $allQcRecipeIds = [];
    foreach ($candidates as $c) {
        $rid = (int) ($c['recipe_id'] ?? 0);
        if ($rid > 0) $allQcRecipeIds[] = $rid;
    }
    foreach ($candidatesOverride as $c) {
        // Override cards may carry recipe_id (CCT source) or neb/contract recipe id fks
        $rid = (int) ($c['recipe_id'] ?? $c['neb_recipe_id_fk'] ?? $c['contract_recipe_id_fk'] ?? 0);
        if ($rid > 0) $allQcRecipeIds[] = $rid;
    }
    $allQcRecipeIds = array_values(array_unique(array_filter($allQcRecipeIds)));

    // Transform one metric_map entry to a field-name-keyed entry
    $metricToField = static function (array $metricMap): array {
        return [
            'racked_vol_hl' => $metricMap['racked_vol_hl'],
            'bbt_co2'       => $metricMap['co2'],
            'bbt_o2'        => $metricMap['o2'],
            'bbt_pressure'  => $metricMap['pressure'],
        ];
    };

    $qcThresholds = [];

    // Global fallback (always present)
    $globalBands   = qc_global_bands($pdo);
    $globalMetric  = [
        'racked_vol_hl' => [
            'label'   => 'Volume transféré',
            'unit'    => ' HL',
            'warn'    => $globalBands['vol']['warn'],
            'outlier' => $globalBands['vol']['outlier'],
        ],
        'co2'      => [
            'label'   => 'CO₂ destination',
            'unit'    => ' g/L',
            'warn'    => $globalBands['co2']['warn'],
            'outlier' => $globalBands['co2']['outlier'],
        ],
        'o2'       => [
            'label'   => 'O₂ destination',
            'unit'    => ' ppb',
            'warn'    => $globalBands['o2']['warn'],
            'outlier' => $globalBands['o2']['outlier'],
        ],
        'pressure' => [
            'label'   => 'Pression destination',
            'unit'    => ' bar',
            'warn'    => $globalBands['pressure']['warn'],
            'outlier' => $globalBands['pressure']['outlier'],
        ],
    ];
    $qcThresholds['__global'] = $metricToField($globalMetric);

    // Per-recipe entries
    if (!empty($allQcRecipeIds)) {
        $perRecipe = qc_thresholds_for_recipes($pdo, $allQcRecipeIds);
        foreach ($perRecipe as $recipeId => $metricMap) {
            $qcThresholds[(string) $recipeId] = $metricToField($metricMap);
        }
    }

    $qcThresholdsJson = json_encode($qcThresholds, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
} catch (Throwable $qcErr) {
    // Non-fatal: fall back to hardcoded thresholds in the JS init block.
    $qcThresholdsJson = 'null';
}

// ── C3 — Pertes config for JS ─────────────────────────────────────────────
// Read pertes_rack_warn_pct from commissioning_settings so the JS palier warning
// uses the live operator-configurable threshold (default 2 %).
// Non-fatal: hardcoded fallback when the row is absent.
$pertesConfig = ['rack_warn_pct' => 2.0];
try {
    $pertesStmt = $pdo->prepare(
        "SELECT key_name, value_num
           FROM commissioning_settings
          WHERE section = 'pertes'
            AND key_name = 'pertes_rack_warn_pct'
            AND is_active = 1
          LIMIT 1"
    );
    $pertesStmt->execute();
    $pertesRow = $pertesStmt->fetch(PDO::FETCH_ASSOC);
    if ($pertesRow !== false && $pertesRow['value_num'] !== null) {
        $pertesConfig['rack_warn_pct'] = (float)$pertesRow['value_num'];
    }
} catch (Throwable $pertesErr) {
    // Non-fatal — JS uses the default 2 % above.
}
$pertesConfigJson = json_encode($pertesConfig, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);

// #8 — CIP partial config (new submission: no existing events)
$cipConfig = [
    'machines'           => ['centri', 'kze', 'pump'],
    'show_inline_combine'=> true,
    'vessels'            => [
        [
            'code'          => 'bbt',   // default; JS updates via cipUpdateVesselLabel
            'number'        => null,    // JS updates via cipUpdateVesselLabel
            'label'         => 'CIP BBT',
            'dynamic_label' => true,
            'required'      => true,    // JS re-evaluates when residual changes
        ],
    ],
    'cip_types'          => $cipTypes,
    'existing'           => null,  // new submission
];
?><!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Saisie Transferts — MaltyTask</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,200;0,9..144,300;0,9..144,400;1,9..144,300;1,9..144,400&family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/css/app.css?v=<?= @filemtime(__DIR__ . '/../css/app.css') ?: time() ?>">
  <link rel="stylesheet" href="/css/cip-section.css?v=<?= @filemtime(__DIR__ . '/../css/cip-section.css') ?: time() ?>">
  <link rel="stylesheet" href="/css/racking-form.css?v=<?= @filemtime(__DIR__ . '/../css/racking-form.css') ?: time() ?>">
</head>
<body class="home op-form-page op-form-racking">

<?php require __DIR__ . '/../../app/partials/sidebar.php' ?>
<?php require __DIR__ . '/../../app/partials/topbar.php' ?>

<main class="main">

  <?php flash_render() ?>

  <?php if ($loadErr): ?>
    <div class="db-flash db-flash--err">⚠ Erreur de chargement : <?= htmlspecialchars($loadErr) ?></div>
  <?php endif ?>

  <!-- Header -->
  <div class="op-form__header">
    <div class="op-form__eyebrow">Transferts · Racking</div>
    <h1 class="op-form__title">Saisie <em>transferts</em></h1>
    <p class="op-form__sub">
      Transfert CCT → BBT (ou CCT / YT). Sélectionner un lot éligible (cold crash ≥ garde minimum).
      Toutes les mesures sont acceptées — des avertissements sont affichés si une valeur
      est hors plage typique, jamais bloquants.
    </p>
  </div>

  <!-- ── FORM ─────────────────────────────────────────────────────────── -->
  <form id="racking-form" method="post" action="/modules/form-racking.php" novalidate>
    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">

    <!-- Hidden fields populated by JS from card selection -->
    <input type="hidden" id="neb_beer"              name="neb_beer"              value="">
    <input type="hidden" id="neb_batch"             name="neb_batch"             value="">
    <input type="hidden" id="neb_recipe_id_fk"      name="neb_recipe_id_fk"      value="">
    <input type="hidden" id="contract_beer"         name="contract_beer"         value="">
    <input type="hidden" id="contract_batch"        name="contract_batch"        value="">
    <input type="hidden" id="contract_recipe_id_fk" name="contract_recipe_id_fk" value="">
    <input type="hidden" id="source_cct_number"     name="source_cct_number"     value="">
    <!-- hors_process: set by JS to 1 when override checkbox is checked.
         Server enforces role: if not manager/admin, this field is silently ignored. -->
    <input type="hidden" id="hors_process" name="hors_process" value="0">

    <!-- Warning panel (populated by form-framework.js) -->
    <div id="racking-warnings" class="op-form__warnings" hidden aria-live="polite"></div>

    <!-- ── Section: CIP (FIRST — as per Round-2 #8) ─────────────────── -->
    <?php require __DIR__ . '/../../app/partials/cip-section.php' ?>

    <!-- ── Section: Pasteurisation flash (KZE) ──────────────────────── -->
    <!-- Hidden by default. JS shows it when KZE is in the CIP set:
         cip_machine_kze checked OR cip_inline_combine checked.
         IMPORTANT: no static `required` on the inputs — JS drives required
         only while this section is visible (hidden-required deadlock prevention). -->
    <div class="op-form__card rf-kze-pu-section" id="rf-kze-pu-section" hidden>
      <div class="op-form__card-title">— pasteurisation flash (KZE)</div>
      <div class="op-form__grid">

        <div class="op-form__field">
          <label class="op-form__label" for="kze_target_pu">
            Target PU <span class="op-form__unit">PU</span>
          </label>
          <input id="kze_target_pu" name="kze_target_pu" type="text" inputmode="decimal"
                 class="op-form__input" placeholder="ex. 25"
                 data-pu-required="1">
        </div>

        <div class="op-form__field">
          <label class="op-form__label" for="kze_avg_pu">
            Moyenne PU <span class="op-form__unit">PU</span>
            <span class="op-form__opt">(réalisé)</span>
          </label>
          <input id="kze_avg_pu" name="kze_avg_pu" type="text" inputmode="decimal"
                 class="op-form__input" placeholder="ex. 26.1"
                 data-pu-required="1">
        </div>

      </div>
    </div>

    <!-- ── Section: Sélection lot source (CCT) ───────────────────────── -->
    <div class="op-form__card">
      <div class="op-form__card-title">— sélection lot source (CCT)</div>

      <?php if ($canOverride): ?>
      <!-- Choix Hors Process — MANAGER / ADMIN ONLY.
           Operators never see this block (PHP-gated, not just CSS-hidden).
           Server will silently ignore hors_process=1 if the role gate fails anyway. -->
      <div class="rf-override-block" id="rf-override-block">
        <label class="rf-override-label">
          <input type="checkbox" id="rf-override-checkbox" class="rf-override-checkbox"
                 aria-describedby="rf-override-desc">
          <span class="rf-override-text">Choix Hors Process</span>
          <span class="rf-override-badge">Manager / Admin</span>
        </label>
        <p class="rf-override-desc" id="rf-override-desc">
          Bypasse la garde minimum (jours depuis cold crash). Affiche tous les lots
          actuellement occupant une CCT ou BBT, quelle que soit leur date de cold crash
          ou leur classification levure. Toute saisie créée via cet override sera marquée
          <code>hors_process_flag = 1</code> dans <code>bd_racking_v2</code>.
        </p>
        <div class="rf-override-reason-row" id="rf-override-reason-row" hidden>
          <label class="op-form__label rf-override-reason-label" for="hors_process_reason">
            Justification <span class="op-form__opt">(recommandé)</span>
          </label>
          <input id="hors_process_reason" name="hors_process_reason" type="text"
                 class="op-form__input rf-override-reason-input"
                 placeholder="ex. Transfert urgent — CCT8 nécessaire pour brassage suivant">
        </div>
      </div>
      <?php endif ?>

      <!-- Normal candidate cards (gated: cold crash ≥ effective_garde) -->
      <div id="rf-normal-candidates">
        <?php if (empty($candidates)): ?>
          <div class="rf-empty-state">
            <strong>Aucun lot éligible.</strong><br>
            Un lot est éligible lorsqu'il est en CCT et que son cold crash date de plus
            longtemps que la garde minimum de sa levure (COALESCE override recette →
            défaut famille). Les recettes sans levure classifiée ne sont pas éligibles
            (levure non liée ou famille sans garde définie → hors process uniquement).
            <?php if ($canOverride): ?>
              Utiliser <strong>Choix Hors Process</strong> ci-dessus pour accéder à tous
              les lots en CCT/BBT indépendamment de la garde.
            <?php endif ?>
          </div>
        <?php else: ?>
          <div class="rf-cand-grid" id="rf-cand-grid-normal">
            <?php foreach ($candidates as $cand): ?>
              <?php
                $beerDisp  = htmlspecialchars($cand['beer_display'] ?? $cand['beer'] ?? '—');
                $batchDisp = htmlspecialchars($cand['batch'] ?? '—');
                $cctNum    = (int)($cand['source_cct'] ?? 0);
                $ccDate    = htmlspecialchars($cand['cold_crash_date'] ?? '—');
                $daysCold  = (int)($cand['days_since_cold_crash'] ?? 0);
                $effGarde  = $cand['effective_garde'] !== null ? (int)$cand['effective_garde'] : null;
                $recipeName= htmlspecialchars($cand['recipe_name'] ?? '');
                $recipeId  = (int)($cand['recipe_id'] ?? 0);
                $nebBeerVal = htmlspecialchars($cand['beer'] ?? '');
                $nebBatchVal= htmlspecialchars($cand['batch'] ?? '');
              ?>
              <?php $simVolHl = round((float)($cand['sim_vol_hl'] ?? 0), 2); ?>
              <button type="button"
                      class="rf-cand-card"
                      data-neb-beer="<?= $nebBeerVal ?>"
                      data-neb-batch="<?= $nebBatchVal ?>"
                      data-recipe-id="<?= $recipeId ?>"
                      data-source-cct="<?= $cctNum ?>"
                      data-sim-vol-hl="<?= $simVolHl ?>"
                      data-hors-process="0">
                <div class="rf-cand-card__label">CCT <?= $cctNum ?></div>
                <div class="rf-cand-card__beer"><?= $beerDisp ?></div>
                <div class="rf-cand-card__batch">Brassin <?= $batchDisp ?></div>
                <div class="rf-cand-card__cc-date">Cold crash : <?= $ccDate ?> (<?= $daysCold ?>j)</div>
                <?php if ($effGarde !== null): ?>
                  <div class="rf-cand-card__garde">Garde : <?= $effGarde ?>j minimum</div>
                <?php endif ?>
              </button>
            <?php endforeach ?>
          </div>
        <?php endif ?>
      </div>

      <!-- Override candidate cards (hors-process: ALL CCT + BBT-occupying beers) -->
      <?php if ($canOverride): ?>
      <div id="rf-override-candidates" hidden>
        <?php if (empty($candidatesOverride)): ?>
          <div class="rf-empty-state">
            Aucun lot en CCT ou BBT actuellement.
          </div>
        <?php else: ?>
          <div class="rf-cand-grid" id="rf-cand-grid-override">
            <?php foreach ($candidatesOverride as $cand): ?>
              <?php
                $srcType   = $cand['source_tank_type'] ?? 'CCT';
                $beerDisp  = htmlspecialchars($cand['beer_display'] ?? $cand['beer'] ?? '—');
                $batchDisp = htmlspecialchars($cand['batch'] ?? '—');

                // Label: CCT lots show source_cct; BBT lots show source_bbt
                if ($srcType === 'BBT') {
                    $tankLabel = 'BBT ' . (int)($cand['source_bbt'] ?? 0);
                    $cctNum    = 0;
                } else {
                    $cctNum    = (int)($cand['source_cct'] ?? 0);
                    $tankLabel = 'CCT ' . $cctNum;
                }

                $ccDate    = $cand['cold_crash_date'] !== null
                               ? htmlspecialchars($cand['cold_crash_date'])
                               : 'pas encore';
                $daysCold  = $cand['days_since_cold_crash'] !== null
                               ? (int)$cand['days_since_cold_crash'] . 'j'
                               : '—';
                $effGarde  = $cand['effective_garde'] !== null ? (int)$cand['effective_garde'] : null;
                $recipeId  = (int)($cand['recipe_id'] ?? $cand['neb_recipe_id_fk'] ?? $cand['contract_recipe_id_fk'] ?? 0);
                $nebBeerVal = htmlspecialchars($cand['neb_beer'] ?? $cand['beer'] ?? '');
                $nebBatchVal= htmlspecialchars($cand['neb_batch'] ?? $cand['batch'] ?? '');
              ?>
              <?php $simVolHlOvr = round((float)($cand['sim_vol_hl'] ?? 0), 2); ?>
              <button type="button"
                      class="rf-cand-card rf-cand-card--hors-process"
                      data-neb-beer="<?= $nebBeerVal ?>"
                      data-neb-batch="<?= $nebBatchVal ?>"
                      data-recipe-id="<?= $recipeId ?>"
                      data-source-cct="<?= $cctNum ?>"
                      data-source-bbt="<?= $srcType === 'BBT' ? (int)($cand['source_bbt'] ?? 0) : 0 ?>"
                      data-source-type="<?= htmlspecialchars($srcType) ?>"
                      data-sim-vol-hl="<?= $simVolHlOvr ?>"
                      data-hors-process="1">
                <div class="rf-cand-card__label"><?= htmlspecialchars($tankLabel) ?></div>
                <div class="rf-cand-card__beer"><?= $beerDisp ?></div>
                <div class="rf-cand-card__batch">Brassin <?= $batchDisp ?></div>
                <?php if ($srcType === 'CCT'): ?>
                  <div class="rf-cand-card__cc-date">Cold crash : <?= $ccDate ?> (<?= $daysCold ?>)</div>
                  <?php if ($effGarde !== null): ?>
                    <div class="rf-cand-card__garde">Garde : <?= $effGarde ?>j (non atteinte)</div>
                  <?php else: ?>
                    <div class="rf-cand-card__garde" style="color:var(--ink-mute)">Garde : non définie</div>
                  <?php endif ?>
                <?php else: ?>
                  <div class="rf-cand-card__cc-date">En BBT (post-transfert)</div>
                <?php endif ?>
                <div class="rf-cand-card__badge-hp">HORS PROCESS</div>
              </button>
            <?php endforeach ?>
          </div>
        <?php endif ?>
      </div>
      <?php endif ?>

      <!-- Selected lot summary strip -->
      <div id="rf-selected-lot" class="rf-selected-lot" hidden>
        <span class="rf-selected-lot__label">Lot sélectionné :</span>
        <span id="rf-selected-summary" class="rf-selected-lot__summary"></span>
        <button type="button" id="rf-deselect" class="rf-selected-lot__clear">✕ changer</button>
      </div>
    </div><!-- card lot source -->

    <!-- ── Section: Opération ─────────────────────────────────────────── -->
    <div class="op-form__card">
      <div class="op-form__card-title">— opération de transfert</div>
      <div class="op-form__grid">

        <!-- Date de transfert (#2 — verified: no min/max attribute; back-dating works) -->
        <div class="op-form__field">
          <label class="op-form__label" for="event_date">Date transfert</label>
          <input id="event_date" name="event_date" type="date" class="op-form__input"
                 value="<?= htmlspecialchars(date('Y-m-d')) ?>" required>
        </div>

        <!-- rack_type removed from form — derived server-side from CIP machine events -->

        <!-- Start time -->
        <div class="op-form__field">
          <label class="op-form__label" for="start_time">Heure début <span class="op-form__unit">HH:MM</span></label>
          <input id="start_time" name="start_time" type="time" class="op-form__input">
        </div>

        <!-- End time -->
        <div class="op-form__field">
          <label class="op-form__label" for="end_time">Heure fin <span class="op-form__unit">HH:MM</span></label>
          <input id="end_time" name="end_time" type="time" class="op-form__input">
        </div>

        <!-- #3 — client input REMOVED. Resolved server-side from recipe FK. -->

      </div>
    </div>

    <!-- ── Section: Destination tank ─────────────────────────────────── -->
    <div class="op-form__card">
      <div class="op-form__card-title">— tank destination</div>
      <div class="op-form__grid--3 op-form__grid">

        <!-- Destination type -->
        <div class="op-form__field">
          <label class="op-form__label" for="racking_destination_type">Type destination</label>
          <select id="racking_destination_type" name="racking_destination_type" class="op-form__select">
            <option value="">— sélectionner —</option>
            <?php foreach (DEST_TYPES as $dt): ?>
              <option value="<?= htmlspecialchars($dt) ?>"><?= htmlspecialchars($dt) ?></option>
            <?php endforeach ?>
          </select>
        </div>

        <!-- BBT N° (#4 — relabelled "BBT N°") -->
        <div class="op-form__field" id="bbt-field" style="display:none">
          <label class="op-form__label" for="bbt_number">BBT N°</label>
          <select id="bbt_number" name="bbt_number" class="op-form__select">
            <option value="">—</option>
            <?php foreach ($bbts as $b): ?>
              <option value="<?= (int)$b['number'] ?>">BBT <?= (int)$b['number'] ?></option>
            <?php endforeach ?>
          </select>
        </div>

        <!-- CCT N° (#4 — relabelled "CCT N°") -->
        <div class="op-form__field" id="cct-field" style="display:none">
          <label class="op-form__label" for="cct_number">CCT N°</label>
          <select id="cct_number" name="cct_number" class="op-form__select">
            <option value="">—</option>
            <?php foreach ($ccts as $c): ?>
              <option value="<?= (int)$c['number'] ?>">CCT <?= (int)$c['number'] ?></option>
            <?php endforeach ?>
          </select>
        </div>

        <!-- YT N° (#4 — new YT dropdown, sourced from ref_yt) -->
        <div class="op-form__field" id="yt-field" style="display:none">
          <label class="op-form__label" for="yt_number">YT N°</label>
          <select id="yt_number" name="yt_number" class="op-form__select">
            <option value="">—</option>
            <?php foreach ($yts as $y): ?>
              <option value="<?= (int)$y['number'] ?>">YT <?= (int)$y['number'] ?></option>
            <?php endforeach ?>
          </select>
        </div>

      </div>

      <!-- C5 — BBT blend-candidate cards (shown only when type=BBT + source beer selected).
           Populated entirely by JS — nothing emitted from PHP here.
           Hidden by default; JS shows/hides this block and injects the cards. -->
      <div id="rf-bbt-blend-section" hidden>
        <div class="rf-bbt-blend-label">
          Blending — même bière en BBT :
        </div>
        <!-- Candidate cards injected by JS -->
        <div class="rf-cand-grid rf-bbt-blend-grid" id="rf-bbt-blend-grid"></div>
        <!-- Message when no same-beer BBT is found -->
        <div id="rf-bbt-blend-none" class="rf-bbt-blend-none" hidden>
          Aucune BBT contenant cette bière actuellement. Pour transférer vers une BBT vide
          ou une BBT avec une autre bière, utiliser <strong>Choix Hors Process</strong>.
        </div>
      </div>

    </div>

    <!-- ── Section: Mesures ───────────────────────────────────────────── -->
    <div class="op-form__card">
      <div class="op-form__card-title">— mesures</div>
      <div class="op-form__grid">

        <div class="op-form__field">
          <label class="op-form__label" for="flowmeter_start_hl">
            Relevé compteur — début <span class="op-form__unit">HL</span>
          </label>
          <input id="flowmeter_start_hl" name="flowmeter_start_hl" type="text" inputmode="decimal"
                 class="op-form__input" placeholder="ex. 12345.6">
        </div>

        <div class="op-form__field">
          <label class="op-form__label" for="flowmeter_end_hl">
            Relevé compteur — fin <span class="op-form__unit">HL</span>
          </label>
          <input id="flowmeter_end_hl" name="flowmeter_end_hl" type="text" inputmode="decimal"
                 class="op-form__input" placeholder="ex. 12375.1">
        </div>

        <div class="op-form__field">
          <label class="op-form__label" for="racked_vol_hl">
            Volume transféré <span class="op-form__unit">HL</span>
            <span id="rf-vol-calculé-hint" class="op-form__opt" hidden>(calculé depuis le compteur)</span>
          </label>
          <input id="racked_vol_hl" name="racked_vol_hl" type="text" inputmode="decimal"
                 class="op-form__input" placeholder="ex. 29.5">
          <div id="rf-flowmeter-error" class="op-form__inline-error" hidden></div>
        </div>

        <!-- #5 — "Volume blend" → "Volume résiduel en cuve" (column stays blend_hl).
             Semantics: volume already in the DESTINATION tank at transfer time
             (blend into same beer; usually BBT, rarely CCT/YT, can be 0).
             Available for all destination types. -->
        <div class="op-form__field">
          <label class="op-form__label" for="blend_hl">
            Volume résiduel en cuve <span class="op-form__unit">HL</span>
          </label>
          <input id="blend_hl" name="blend_hl" type="text" inputmode="decimal"
                 class="op-form__input" placeholder="0">
        </div>

        <!-- #5 — Derived "Volume résultant en cuve" display (pure JS; nothing persisted).
             Shown read-only; TankSimulator is the only system that computes this authoritatively. -->
        <div class="op-form__field" id="rf-resultant-field">
          <label class="op-form__label">
            Volume résultant en cuve <span class="op-form__unit">HL</span>
            <span class="op-form__opt">(calculé)</span>
          </label>
          <div id="rf-resultant-display" class="op-form__readout" aria-live="polite">—</div>
        </div>

        <!-- #6 — CO₂ label dynamic by dest type. Default label "CO₂ BBT" swaps JS-side. -->
        <div class="op-form__field">
          <label class="op-form__label" for="bbt_co2">
            <span id="lbl-co2">CO₂ BBT</span> <span class="op-form__unit">g/L</span>
          </label>
          <input id="bbt_co2" name="bbt_co2" type="text" inputmode="decimal"
                 class="op-form__input" placeholder="ex. 4.2">
        </div>

        <!-- #6 — O₂ label dynamic by dest type. -->
        <div class="op-form__field">
          <label class="op-form__label" for="bbt_o2">
            <span id="lbl-o2">O₂ BBT</span> <span class="op-form__unit">ppb</span>
          </label>
          <input id="bbt_o2" name="bbt_o2" type="text" inputmode="decimal"
                 class="op-form__input" placeholder="ex. 18">
        </div>

        <div class="op-form__field">
          <label class="op-form__label" for="bbt_pressure">
            Pression destination <span class="op-form__unit">bar</span>
          </label>
          <input id="bbt_pressure" name="bbt_pressure" type="text" inputmode="decimal"
                 class="op-form__input" placeholder="ex. 1.2">
        </div>

        <div class="op-form__field">
          <label class="op-form__label" for="avg_turbidity">
            Turbidité moy. <span class="op-form__unit">NTU</span>
          </label>
          <input id="avg_turbidity" name="avg_turbidity" type="text" inputmode="decimal"
                 class="op-form__input" placeholder="ex. 0.5">
        </div>

        <!-- #7 — "Vitesse moyenne" input REMOVED.
             avg_speed column kept in DB (historical data; future derivation from start/end times). -->

        <div class="op-form__field">
          <label class="op-form__label" for="centri_rinsed">Centri rincée ?</label>
          <select id="centri_rinsed" name="centri_rinsed" class="op-form__select">
            <option value="">—</option>
            <?php foreach (CENTRI_RINSED_YN as $yn): ?>
              <option value="<?= htmlspecialchars($yn) ?>"><?= htmlspecialchars($yn) ?></option>
            <?php endforeach ?>
          </select>
        </div>

        <div class="op-form__field">
          <label class="op-form__label" for="safety_cip_done">Safety CIP effectué ?</label>
          <select id="safety_cip_done" name="safety_cip_done" class="op-form__select">
            <option value="">—</option>
            <?php foreach (CENTRI_RINSED_YN as $yn): ?>
              <option value="<?= htmlspecialchars($yn) ?>"><?= htmlspecialchars($yn) ?></option>
            <?php endforeach ?>
          </select>
        </div>

      </div>
    </div>

    <!-- ── Section: Commentaires ──────────────────────────────────────── -->
    <div class="op-form__card">
      <div class="op-form__card-title">— commentaires</div>
      <div class="op-form__grid--1 op-form__grid">
        <div class="op-form__field op-form__field--full">
          <label class="op-form__label" for="comments">Commentaires libres</label>
          <textarea id="comments" name="comments" class="op-form__textarea" rows="3"
                    placeholder="Observations, problèmes rencontrés…"></textarea>
        </div>
      </div>
    </div>

    <!-- ── Section: Pertes (C3) ──────────────────────────────────────── -->
    <!-- Hidden section revealed by the toggle checkbox below.
         NO static `required` on any field here — the form's established discipline.
         JS drives required-while-visible for loss_cause when a volume is entered. -->
    <div class="op-form__card rf-pertes-card">
      <div class="op-form__card-title">— pertes</div>

      <!-- Reveal toggle — matches the KZE PU section pattern: checkbox label + hidden section -->
      <label class="rf-pertes-toggle-label">
        <input type="checkbox" id="rf-perte-toggle" name="perte_toggle" value="1"
               class="rf-pertes-toggle-checkbox">
        <span class="rf-pertes-toggle-text">Des pertes à signaler ?</span>
      </label>

      <!-- Collapsible loss fields — hidden until toggle is checked -->
      <div id="rf-pertes-fields" hidden>
        <div class="op-form__grid">

          <div class="op-form__field">
            <label class="op-form__label" for="loss_source_hl">
              Perte cuve départ <span class="op-form__unit">HL</span>
              <span class="op-form__opt">(facultatif)</span>
            </label>
            <input id="loss_source_hl" name="loss_source_hl" type="number"
                   inputmode="decimal" step="0.001" min="0"
                   class="op-form__input" placeholder="0.000">
          </div>

          <div class="op-form__field">
            <label class="op-form__label" for="loss_dest_hl">
              Perte cuve arrivée <span class="op-form__unit">HL</span>
              <span class="op-form__opt">(facultatif)</span>
            </label>
            <input id="loss_dest_hl" name="loss_dest_hl" type="number"
                   inputmode="decimal" step="0.001" min="0"
                   class="op-form__input" placeholder="0.000">
          </div>

          <div class="op-form__field">
            <label class="op-form__label" for="loss_cause">
              Cause
            </label>
            <!-- No static `required` — JS adds it when a volume > 0 is entered -->
            <select id="loss_cause" name="loss_cause" class="op-form__select">
              <option value="">— sélectionner —</option>
              <option value="produit">Produit</option>
              <option value="machine">Machine</option>
              <option value="humain">Humain</option>
            </select>
          </div>

          <!-- Volume balance preview (read-only, JS-computed) -->
          <div class="op-form__field" id="rf-loss-balance-field">
            <label class="op-form__label">
              Bilan volumes <span class="op-form__opt">(calculé)</span>
            </label>
            <div id="rf-loss-balance" class="op-form__readout rf-loss-balance" aria-live="polite">—</div>
          </div>

        </div>

        <div class="op-form__grid--1 op-form__grid" style="margin-top:0.5rem">
          <div class="op-form__field op-form__field--full">
            <label class="op-form__label" for="loss_note">
              Détails / explication <span class="op-form__opt">(facultatif)</span>
            </label>
            <textarea id="loss_note" name="loss_note" class="op-form__textarea" rows="2"
                      maxlength="500"
                      placeholder="Cause, contexte, lot concerné…"></textarea>
          </div>
        </div>
      </div>
    </div>

    <!-- ── Section: Transfert interrompu (C4) ───────────────────────── -->
    <!-- Hidden section revealed by the toggle checkbox.
         NO static `required` on any field here — established form discipline.
         JS adds required to interrupted_reason while revealed + to dest_bbt_still_clean
         when also racked_vol_hl == 0/empty. -->
    <div class="op-form__card rf-interrupted-card">
      <div class="op-form__card-title">— transfert interrompu</div>

      <!-- Toggle — matches the Pertes section reveal pattern -->
      <label class="rf-interrupted-toggle-label">
        <input type="checkbox" id="rf-interrupted-toggle" name="interrupted_flag" value="1"
               class="rf-interrupted-toggle-checkbox">
        <span class="rf-interrupted-toggle-text">Le transfert a été interrompu</span>
      </label>

      <!-- Collapsible fields — hidden until toggle is checked -->
      <div id="rf-interrupted-fields" hidden>

        <!-- interrupted_reason — required while visible -->
        <div class="op-form__grid--1 op-form__grid" style="margin-top:0.75rem">
          <div class="op-form__field op-form__field--full">
            <label class="op-form__label" for="interrupted_reason">
              Raison de l'interruption
              <!-- No static required — JS adds it while section is visible -->
            </label>
            <textarea id="interrupted_reason" name="interrupted_reason"
                      class="op-form__textarea" rows="2" maxlength="500"
                      placeholder="Décris l'événement : cause, état de la cuve, suite prévue…"></textarea>
          </div>
        </div>

        <!-- BBT encore propre ? — shown ONLY when racked_vol_hl == 0 / empty.
             Semantics: if nothing was transferred and the BBT was not contaminated,
             it stays clean in the system — no CIP required for the next fill.
             NO static required — JS adds required when this sub-section is visible. -->
        <div id="rf-bbt-propre-row" class="op-form__grid--1 op-form__grid" style="margin-top:0.5rem" hidden>
          <div class="op-form__field op-form__field--full">
            <label class="op-form__label">BBT encore propre ?</label>
            <div class="rf-bbt-propre-radios">
              <label class="rf-radio-label">
                <input type="radio" name="dest_bbt_still_clean" value="1"
                       id="dest_bbt_clean_oui" class="rf-bbt-propre-radio">
                Oui — BBT reste propre
              </label>
              <label class="rf-radio-label">
                <input type="radio" name="dest_bbt_still_clean" value="0"
                       id="dest_bbt_clean_non" class="rf-bbt-propre-radio">
                Non — BBT à nettoyer avant le prochain transfert
              </label>
            </div>
          </div>
        </div>

      </div>
    </div>

    <!-- Submit bar -->
    <div class="op-form__submit-bar">
      <button type="button" class="op-form__btn op-form__btn--secondary"
              onclick="if(confirm('Effacer le brouillon ?')){localStorage.removeItem('racking-draft');location.reload();}">
        Effacer brouillon
      </button>
      <button type="submit" id="rf-submit" class="op-form__btn op-form__btn--primary">
        Enregistrer le transfert →
      </button>
    </div>

  </form>

  <!-- ── Recent submissions ─────────────────────────────────────────── -->
  <div class="op-form__recent">
    <div class="op-form__recent-title">— saisies récentes (web)</div>
    <?php if (empty($recentRackings)): ?>
      <p class="op-form__muted" style="font-size:0.82rem;">Aucune saisie web pour le moment.</p>
    <?php else: ?>
      <table class="op-form__recent-table">
        <thead>
          <tr>
            <th>Date</th>
            <th>Bière</th>
            <th>Brassin</th>
            <th>Type</th>
            <th>Destination</th>
            <th>Vol (HL)</th>
            <th>QC</th>
            <th>Opérateur</th>
            <th>Override</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($recentRackings as $r): ?>
            <?php
              $beerLabel  = ($r['neb_beer'] ?? '') !== '' ? $r['neb_beer'] : ($r['contract_beer'] ?? '—');
              $batchLabel = ($r['neb_batch'] ?? '') !== '' ? $r['neb_batch'] : ($r['contract_batch'] ?? '—');
              $flags      = $r['audit_flags'] ?? '';
              $isHorsProc = str_contains($flags, 'hors_process_override');
              $isOutlier  = str_contains($flags, 'qc_outlier');
              $isElevated = str_contains($flags, 'qc_elevated');
              $qc         = $isOutlier ? 'outlier' : ($isElevated ? 'elevated' : 'normal');
              $hpFlag     = (bool)($r['hors_process_flag'] ?? false);
            ?>
            <tr>
              <td class="op-form__mono"><?= htmlspecialchars($r['event_date'] ?? '') ?></td>
              <td><?= htmlspecialchars($beerLabel) ?></td>
              <td class="op-form__mono"><?= htmlspecialchars($batchLabel) ?></td>
              <td><?= htmlspecialchars($r['rack_type'] ?? '—') ?></td>
              <td class="op-form__mono"><?= htmlspecialchars($r['target_tank_raw'] ?? '—') ?></td>
              <td class="op-form__mono"><?= $r['racked_vol_hl'] !== null ? htmlspecialchars((string)$r['racked_vol_hl']) : '—' ?></td>
              <td><span class="op-form__qc-badge op-form__qc-badge--<?= $qc ?>"><?= $qc ?></span></td>
              <td class="op-form__mono"><?= htmlspecialchars($r['email'] ?? '') ?></td>
              <td>
                <?php if ($hpFlag || $isHorsProc): ?>
                  <span class="rf-hp-badge" title="<?= htmlspecialchars($r['hors_process_reason'] ?? '') ?>">HORS PROCESS</span>
                <?php else: ?>
                  <span class="rf-hp-badge rf-hp-badge--normal">—</span>
                <?php endif ?>
              </td>
            </tr>
          <?php endforeach ?>
        </tbody>
      </table>
    <?php endif ?>
  </div>

</main>

<!-- ── Inject server-side data for JS ────────────────────────────────────── -->
<script>
window.RF_CANDIDATES          = <?= $candidatesJson ?>;
window.RF_CANDIDATES_OVERRIDE = <?= $candidatesOverrideJson ?>;
window.RF_CAN_OVERRIDE        = <?= $canOverride ? 'true' : 'false' ?>;
// Per-recipe QC threshold bands (field-name-keyed). "__global" is the fallback.
// null means the resolver threw — JS falls back to the static init() thresholds.
window.QC_THRESHOLDS = <?= $qcThresholdsJson ?>;
// C3 — Pertes advisory thresholds from commissioning_settings section='pertes'.
// rack_warn_pct: loss % of racked vol that triggers the rack-stage palier warning.
window.PERTES_CONFIG = <?= $pertesConfigJson ?>;
// C4 — BBT clean-state map (event-sourced): bbtNumber(int) → 'clean'|'dirty'|'unknown'.
// Consumed by updateDestCipRequired() to compose (residual OR clean OR explicit).
window.BBT_CLEAN_STATES = <?= json_encode($bbtCleanStates, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
// C5 — BBT blend candidates indexed by canonical beer name.
// Shape: { "BeerName": [{bbt, beer, total_hl, lots:[{batch, vol_hl, pct}]}, …] }
// Used by the destination UX to surface same-beer non-empty BBTs as blend candidates
// when type=BBT is selected after a source card picks a beer.
window.BBT_BLEND_CANDIDATES = <?= $bbtBlendCandidatesJson ?>;
</script>

<script src="/js/form-framework.js?v=<?= @filemtime(__DIR__ . '/../js/form-framework.js') ?: time() ?>" defer></script>
<script src="/js/racking-form.js?v=<?= @filemtime(__DIR__ . '/../js/racking-form.js') ?: time() ?>" defer></script>

</body>
</html>
