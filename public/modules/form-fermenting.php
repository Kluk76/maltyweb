<?php
declare(strict_types=1);
/**
 * form-fermenting.php — Operator fermentation entry form.
 *
 * Writes to:
 *   bd_fermenting_v2   — one row per event submission.
 *
 * The table uses a ONE-ROW-PER-EVENT model keyed by (event_type, beer_raw,
 * batch, line_idx).  This form writes:
 *
 *   Reads   — gravity/pH/temperature readings + final_comments.
 *   DryHop  — one row per dry-hop addition (line_idx 0…N); each row carries
 *              dh_mi_id_fk/dh_raw_name/dh_qty/dh_unit/dh_lot + readings can
 *              be included on the first DryHop row (line_idx 0).
 *   Purge   — comment_purge + optional Reads on same row.
 *   ColdCrash — comment_cold_crash + optional Reads on same row.
 *
 * Column mapping (live bd_fermenting_v2 schema):
 *   row_hash      — sha256 of canonical content
 *   audit_flags   — 'web_entry'
 *   submitted_at  — datetime(6) UNIQUE per row
 *   email         — operator username
 *   event_date    — date of event (from HTML date input)
 *   event_type    — ENUM('DryHop','Reads','Purge','ColdCrash') — db column
 *   beer_raw      — raw beer identifier (e.g. "ZEP 213")
 *   batch         — batch number string (e.g. "213")
 *   line_idx      — 0 for all non-DryHop events; 0…N for DryHop rows
 *   recipe_id_fk  — INT UNSIGNED FK to ref_recipes.id (nullable)
 *   dh_category   — ENUM('hops_dry','adjunct','mineral','process') — derived from MI category
 *   dh_mi_id_fk   — INT UNSIGNED FK to ref_mi.id (nullable on non-DH rows)
 *   dh_raw_name   — raw hop name as entered (nullable)
 *   dh_qty        — dry-hop quantity DECIMAL(10,3) in dh_unit
 *   dh_unit       — ENUM('kg','g') — always 'g' from this form (operators
 *                   enter grams for dry-hop additions)
 *   dh_lot        — lot number (nullable)
 *   dh_confidence — 'web_entry'
 *   dh_parse_note — 'direct-mi-pick' or 'unresolved-mi-id'
 *   gravity       — DECIMAL(6,3) in °Plato (stored value = °Plato, not SG)
 *   ph            — DECIMAL(4,2)
 *   temperature   — DECIMAL(5,2) in °C
 *   comment_purge — free-text, set on Purge rows
 *   comment_cold_crash — free-text, set on ColdCrash rows
 *   final_comments — general free-text notes (all event types)
 *
 * FLAGGED / NOT WIRED (mock vs schema):
 *   - Mock shows "OG" and "FG" inputs with computed attenuation. The DB
 *     stores only one 'gravity' column — not separate OG/FG. We send
 *     the gravity reading in the 'gravity' column. The operator labels
 *     which reading this is via the event context (Reads at day 0 = OG,
 *     Reads at ColdCrash stage = FG). There is NO separate OG/FG column.
 *     The form therefore has a single "Densité (°Plato)" field — this is
 *     the correct mapping to the live schema. If a separate OG vs FG split
 *     is needed in future, a migration would be required.
 *   - Mock "Purge" section shows CO2 pressure + dissolved CO2 fields.
 *     There are NO such columns in bd_fermenting_v2. These are UNWIRED.
 *     A future migration could add co2_pressure and co2_dissolved columns.
 *   - Mock "ColdCrash" shows target temp / current temp / crash date /
 *     planned duration. The DB has only comment_cold_crash. Only the
 *     temperature reading (shared gravity/ph/temp columns) + comment are
 *     wired. A future migration could add crash-specific columns.
 *   - Mock shows live beer identity context strip (beer/batch/CCT/day).
 *     This requires looking up a live in-fermentation batch. Currently
 *     the form picks beer/batch from pickers; the CCT and day-count are
 *     not exposed — they would require joining bd_brewing_brewday_v2 which
 *     is out of scope for this form pass.
 *   - The 'is_tombstoned' column is never set by the web form (defaults 0).
 *
 * Pattern: mirrors form-brewing.php exactly —
 *   CSRF → coerce → hash → bd_upsert → log_revision → flash → PRG redirect.
 *
 * NOT added to topbar nav — orchestrator will flip the saisies.php card
 * when approved.
 * URL: /modules/form-fermenting.php
 */

require __DIR__ . '/../../app/auth.php';
require __DIR__ . '/../../app/settings.php';
require __DIR__ . '/../../app/settings-helpers.php';
require __DIR__ . '/../../app/db-write-helpers.php';
require_once __DIR__ . '/../../app/tank-simulator.php';

require_login();
$me = current_user();

// ── Allowed enum values ───────────────────────────────────────────────────────
// Mirrors bd_fermenting_v2.event_type ENUM exactly
const FERM_EVENT_TYPES = ['Reads', 'DryHop', 'Purge', 'ColdCrash'];
// Mirrors bd_fermenting_v2.dh_unit ENUM exactly (migration 259 adds 'ml')
const DH_UNITS = ['g', 'kg', 'ml'];

// ── POST handler (P-C: delegated to /api/fermenting-phase-submit.php) ─────────
// The inline POST handler has been extracted to /api/fermenting-phase-submit.php.
// This guard catches any form that still posts here (e.g. cached pages, direct
// form submissions without session_id) and redirects to the new endpoint.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Carry forward form data via a GET redirect — the session endpoint handles PRG.
    // If there's a session_id in the POST, the form should have gone to the endpoint.
    // Redirect back to GET with an informational error.
    flash_set('err', 'Saisie re-routée — rechargez la page et réessayez.');
    $beer  = isset($_POST['beer_select']) ? urlencode(trim((string)$_POST['beer_select'])) : '';
    $batch = isset($_POST['batch'])       ? urlencode(trim((string)$_POST['batch']))       : '';
    $qs = ($beer !== '' ? '?beer=' . $beer : '') . ($batch !== '' ? ($beer !== '' ? '&' : '?') . 'batch=' . $batch : '');
    redirect_to('/modules/form-fermenting.php' . $qs);
}

// ── GET ───────────────────────────────────────────────────────────────────────
header('Content-Type: text/html; charset=utf-8');

try {
    $pdo = maltytask_pdo();

    // ── Role gate for hors-process override ───────────────────────────────────
    $canOverride = manager_can('production', $me);

    // ── Candidate builder for beer-selection cards ─────────────────────────────
    //
    // Base CCT query: latest brew per CCT, active recipes only.
    // Mirrors form-racking.php $candidateBaseSql minus yeast/garde joins.
    $fermBaseSql = "
        SELECT
          bfw.recipe_id_fk,
          bfw.cct                                     AS source_cct,
          bfw.batch,
          bfw.beer,
          r.id                                        AS recipe_id,
          r.name                                      AS recipe_name,
          r.recipe_short_name,
          COALESCE(NULLIF(bfw.beer,''), r.name)       AS beer_display
        FROM bd_brewing_brewday_v2 bfw
        JOIN ref_recipes r ON r.id = bfw.recipe_id_fk
        WHERE bfw.is_tombstoned = 0
          AND bfw.cct IS NOT NULL
          AND r.is_active = 1
          AND NOT EXISTS (
            SELECT 1 FROM bd_brewing_brewday_v2 b2
            WHERE b2.cct = bfw.cct
              AND b2.is_tombstoned = 0
              AND b2.event_date IS NOT NULL
              AND b2.event_date > bfw.event_date
          )";

    // ── TankSimulator: single run — authoritative CCT occupancy filter ─────────
    $fermSimState = (new TankSimulator($pdo))->run(new DateTimeImmutable('today'));

    // Drop candidates whose CCT reports 0/null volume in the simulator.
    // Mirrors form-racking.php $simFilterCct exactly.
    $fermSimFilterCct = function (array $list) use ($fermSimState): array {
        $out = [];
        foreach ($list as $cand) {
            $cctNum    = (int)($cand['source_cct'] ?? 0);
            $tankState = $fermSimState['cct'][$cctNum] ?? null;
            if ($tankState === null) {
                continue; // CCT not in sim (empty or unknown)
            }
            $cand['sim_vol_hl'] = round((float)($tankState['volume_hl'] ?? 0), 2);
            $out[] = $cand;
        }
        return $out;
    };

    // Gate predicates (applied on top of the base SQL, then sim-filtered):
    //
    //   NotColdCrashed — excludes (recipe_id_fk, batch) pairs that already have a
    //     ColdCrash event. Used for both Reads and ColdCrash event types (same set).
    //
    //   DryHop — dry-hop recipe predicate (OR-bridge: canonical ref_recipe_ingredients
    //     classified arm OR observed-history arm) AND not yet dry-hopped this batch.
    //     OR-bridge is intentional: keeps the gate non-empty while recipe classification
    //     is ongoing; remove the f2 sub-query once all recipes are fully classified.
    //
    //   Purge — base set only (all CCT lots; no extra exclusion).

    $andNotColdCrashed = "
          AND NOT EXISTS (
            SELECT 1 FROM bd_fermenting_v2 f
            WHERE f.recipe_id_fk = bfw.recipe_id_fk
              AND f.batch         = bfw.batch
              AND f.event_type    = 'ColdCrash'
              AND f.is_tombstoned = 0
          )";

    $andNotDryHopped = "
          AND NOT EXISTS (
            SELECT 1 FROM bd_fermenting_v2 f
            WHERE f.recipe_id_fk = bfw.recipe_id_fk
              AND f.batch         = bfw.batch
              AND f.event_type    = 'DryHop'
              AND f.is_tombstoned = 0
          )
          AND (
            EXISTS (
              SELECT 1 FROM ref_recipe_ingredients ri
              JOIN ref_mi m ON m.id = ri.mi_id_fk
              WHERE ri.recipe_id              = r.id
                AND m.category_id             = 2
                AND ri.hop_addition_stage     = 'dry_hop'
                AND ri.is_active              = 1
            )
            OR EXISTS (
              SELECT 1 FROM bd_fermenting_v2 f2
              WHERE f2.recipe_id_fk = r.id
                AND f2.event_type   = 'DryHop'
                AND f2.is_tombstoned = 0
            )
          )";

    // Build each gated list and pass through sim filter.
    $fermRowsReads = $fermSimFilterCct(
        $pdo->query($fermBaseSql . $andNotColdCrashed)->fetchAll(PDO::FETCH_ASSOC)
    );
    $fermRowsDryHop = $fermSimFilterCct(
        $pdo->query($fermBaseSql . $andNotDryHopped)->fetchAll(PDO::FETCH_ASSOC)
    );
    $fermRowsPurge = $fermSimFilterCct(
        $pdo->query($fermBaseSql)->fetchAll(PDO::FETCH_ASSOC)
    );
    // ColdCrash = same as Reads (same SQL, same sim filter — share the array)
    $fermCandidates = [
        'Reads'     => $fermRowsReads,
        'ColdCrash' => $fermRowsReads,   // alias — compute once
        'DryHop'    => $fermRowsDryHop,
        'Purge'     => $fermRowsPurge,
    ];

    // ── Hors-process list (admin / manager only): all CCT ∪ all BBT ───────────
    $fermCandidatesHp = [];
    if ($canOverride) {
        // Source A: all CCT lots (no gate), sim-filtered
        $allCctRows = $fermSimFilterCct(
            $pdo->query($fermBaseSql)->fetchAll(PDO::FETCH_ASSOC)
        );
        $seenHp = [];
        foreach ($allCctRows as $cand) {
            $key = 'cct|' . (int)($cand['source_cct'] ?? 0);
            $seenHp[$key] = true;
            $cand['source_tank_type'] = 'CCT';
            $fermCandidatesHp[] = $cand;
        }

        // Source B: latest-per-BBT from bd_racking_v2, sim-filtered.
        // Mirrors racking's Source B exactly (form-racking.php ~L616-688).
        $bbtCandRows = $pdo->query("
            SELECT
              'BBT'                                              AS source_tank_type,
              r.bbt_number                                       AS source_bbt,
              r.neb_beer,
              r.neb_batch,
              r.neb_recipe_id_fk,
              r.contract_beer,
              r.contract_batch,
              r.contract_recipe_id_fk,
              COALESCE(NULLIF(r.neb_beer,''), r.contract_beer)  AS beer_display,
              r.event_date                                       AS brew_date,
              r2.name                                            AS recipe_name
            FROM bd_racking_v2 r
            LEFT JOIN ref_recipes r2
              ON r2.id = COALESCE(r.neb_recipe_id_fk, r.contract_recipe_id_fk)
            WHERE r.racking_destination_type IN ('BBT','CCT')
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
            ORDER BY r.bbt_number ASC
        ")->fetchAll(PDO::FETCH_ASSOC);

        foreach ($bbtCandRows as $bbtRow) {
            $bbtNum  = (int)($bbtRow['source_bbt'] ?? 0);
            $simTank = $fermSimState['bbt'][$bbtNum] ?? null;
            if ($simTank === null) {
                continue; // Empty/expired in sim
            }
            $bbtKey = 'bbt|' . $bbtNum;
            if (isset($seenHp[$bbtKey])) {
                continue;
            }
            $seenHp[$bbtKey] = true;
            $fermCandidatesHp[] = [
                'source_tank_type'      => 'BBT',
                'source_cct'            => null,
                'source_bbt'            => $bbtNum,
                'recipe_id'             => null,
                'recipe_name'           => $bbtRow['recipe_name'] ?? '',
                'beer'                  => $bbtRow['neb_beer'] ?? $bbtRow['contract_beer'] ?? '',
                'batch'                 => $bbtRow['neb_batch'] ?? $bbtRow['contract_batch'] ?? '',
                'beer_display'          => $bbtRow['beer_display'] ?? '',
                'sim_vol_hl'            => round((float)$simTank['volume_hl'], 2),
            ];
        }
    }

    // Recipes (active, for the beer picker)
    $recipes = $pdo->query(
        "SELECT id, name, classification, recipe_short_name
         FROM ref_recipes
         WHERE is_active = 1
         ORDER BY name ASC"
    )->fetchAll();

    // Dry-hop MI catalog: Hops + adjuncts + minerals + process aids (active only).
    // Ordered by category then name so the picker groups naturally.
    $hopsMi = $pdo->query(
        "SELECT m.id, m.mi_id, m.name, m.pricing_unit, c.name AS category
         FROM ref_mi m
         JOIN ref_mi_categories c ON m.category_id = c.id
         WHERE c.name IN ('Hops','Brewing Adjunct','Brewing Mineral','Process Chemical')
           AND m.is_active = 1
         ORDER BY c.name ASC, m.name ASC"
    )->fetchAll();

    // Build compact JS-safe structure (window.FERMENTING_HOPS kept for minimal JS churn)
    $hopsJs = [];
    foreach ($hopsMi as $h) {
        $hopsJs[] = [
            'id'       => (int)$h['id'],
            'mi_id'    => $h['mi_id'],
            'name'     => $h['name'],
            'unit'     => $h['pricing_unit'] ?? 'kg',
            'category' => $h['category'],
        ];
    }

    // Recent fermentation sessions (last 5 open/closed fermenting sessions).
    // For each session, all bd_fermenting_v2 events are loaded in chronological order.
    // Historical rows with NULL session_id_fk are surfaced under a synthetic group.
    //
    // Display-side fallback (Bug C): when s.recipe_id_fk IS NULL (sessions opened before
    // the write-side fix propagated recipe_id_fk), resolve recipe from the session's event
    // rows via a correlated subquery. Uses ANY_VALUE() to satisfy ONLY_FULL_GROUP_BY while
    // picking the first distinct recipe_id_fk from events linked to the session.
    $recentSessionsStmt = $pdo->prepare(
        "SELECT s.id, s.phase, s.status, s.batch,
                COALESCE(s.recipe_id_fk,
                    (SELECT MIN(f.recipe_id_fk)
                       FROM bd_fermenting_v2 f
                      WHERE f.session_id_fk = s.id
                        AND f.recipe_id_fk IS NOT NULL
                        AND f.is_tombstoned = 0)
                ) AS recipe_id_fk,
                s.opened_at, s.closed_at,
                r.name AS recipe_name, r.recipe_short_name
           FROM op_sessions s
           LEFT JOIN ref_recipes r ON r.id = COALESCE(s.recipe_id_fk,
                    (SELECT MIN(f2.recipe_id_fk)
                       FROM bd_fermenting_v2 f2
                      WHERE f2.session_id_fk = s.id
                        AND f2.recipe_id_fk IS NOT NULL
                        AND f2.is_tombstoned = 0))
          WHERE s.form_type     = 'fermenting'
            AND s.is_tombstoned = 0
            AND NOT (
                s.status = 'open'
                AND NOT EXISTS (
                    SELECT 1 FROM bd_fermenting_v2 f3
                    WHERE f3.session_id_fk = s.id
                      AND f3.is_tombstoned = 0
                )
            )
          ORDER BY s.opened_at DESC
          LIMIT 5"
    );
    $recentSessionsStmt->execute();
    $recentSessions = $recentSessionsStmt->fetchAll();

    // Collect session PKs to load their events in one query
    $sessionIds = array_column($recentSessions, 'id');

    // Events grouped by session_id_fk (only for the sessions we loaded above)
    $sessionEvents = [];
    if (!empty($sessionIds)) {
        $inPlaceholders = implode(',', array_fill(0, count($sessionIds), '?'));
        $evtStmt = $pdo->prepare(
            "SELECT id, session_id_fk, event_date, event_type, beer_raw, batch,
                    gravity, ph, temperature, dh_raw_name, dh_qty, dh_unit,
                    comment_purge, comment_cold_crash, final_comments,
                    email, submitted_at
               FROM bd_fermenting_v2
              WHERE session_id_fk IN ({$inPlaceholders})
                AND is_tombstoned = 0
              ORDER BY submitted_at ASC"
        );
        $evtStmt->execute($sessionIds);
        foreach ($evtStmt->fetchAll() as $ev) {
            $sessionEvents[(int)$ev['session_id_fk']][] = $ev;
        }
    }

    // Historical rows: web-entered events with NULL session_id_fk (pre-P-C submissions)
    $historicalStmt = $pdo->prepare(
        "SELECT id, event_date, event_type, beer_raw, batch, gravity, ph, temperature,
                dh_raw_name, dh_qty, dh_unit, email, submitted_at
           FROM bd_fermenting_v2
          WHERE audit_flags   LIKE '%web_entry%'
            AND session_id_fk IS NULL
            AND is_tombstoned = 0
          ORDER BY submitted_at DESC
          LIMIT 20"
    );
    $historicalStmt->execute();
    $recentHistorical = $historicalStmt->fetchAll();

    // Keep $recentFerm for backward compat with any partial still referencing it (empty sentinel)
    $recentFerm = [];

    $loadErr = null;

} catch (Throwable $e) {
    $recipes   = [];
    $hopsJs    = [];
    $recentFerm = [];
    // RULE-2 followup #1 — recap partial expects these on every render path,
    // including the catch path. Without these, partial emits PHP warnings
    // and broken HTML. Empty defaults render the "no sessions" empty-state.
    $recentSessions   = [];
    $sessionEvents    = [];
    $recentHistorical = [];
    // Candidate fallbacks — candidate builder failed; JS will show empty-state.
    $canOverride      = false;
    $fermCandidates   = ['Reads' => [], 'ColdCrash' => [], 'DryHop' => [], 'Purge' => []];
    $fermCandidatesHp = [];
    $loadErr   = $e->getMessage();
}

$csrf          = csrf_token();
$active_module = 'saisies';
$displayFmt    = date_display_format();

// ── Edit-mode detection ───────────────────────────────────────────────────────
// Read with ?? default, then validate — same two-step pattern as form-brewing.php.
$editIdRaw = $_GET['edit'] ?? null;
$editId    = ($editIdRaw !== null && is_numeric($editIdRaw)) ? (int)$editIdRaw : null;
if ($editId !== null && $editId <= 0) $editId = null;

$editMode      = false;
$prefillEdit   = [];   // fields to prefill: event_type, event_date, readings, beer_raw, batch, line_idx
$editBanner    = null; // ['beer','batch','event_type','event_date'] for banner display
$editOrigSubmittedAt = null;  // CRITICAL: original submitted_at to round-trip in hidden field
$prefillDhLines = []; // DryHop sibling lines for edit-mode picker repopulation; empty for non-DryHop

if ($editId !== null) {
    try {
        $pdoEdit = maltytask_pdo();

        $editStmt = $pdoEdit->prepare(
            "SELECT id, submitted_at, event_type, event_date,
                    beer_raw, batch, line_idx, recipe_id_fk,
                    gravity, ph, temperature,
                    comment_purge, purge_pressure_bar, comment_cold_crash, final_comments,
                    dh_mi_id_fk, dh_raw_name, dh_qty, dh_unit, dh_lot,
                    session_id_fk
               FROM bd_fermenting_v2
              WHERE id = ? AND is_tombstoned = 0
              LIMIT 1"
        );
        $editStmt->execute([$editId]);
        $editRow = $editStmt->fetch(PDO::FETCH_ASSOC);

        if ($editRow === false) {
            flash_set('err', "Évènement #{$editId} introuvable ou archivé.");
            // Fall through to blank entry mode
        } else {
            $editMode = true;
            $editOrigSubmittedAt = (string)$editRow['submitted_at'];

            // ── DryHop sibling re-group ───────────────────────────────────────
            // For DryHop events, the "event" is N rows sharing (submitted_at,
            // beer_raw, batch, event_type='DryHop'), differing by line_idx.
            // The operator may click Corriger on ANY sibling row (not necessarily
            // line_idx=0). Re-group all siblings so the picker can be repopulated.
            $prefillDhLines = [];
            if ((string)$editRow['event_type'] === 'DryHop') {
                $sibStmt = $pdoEdit->prepare(
                    "SELECT line_idx, dh_raw_name, dh_qty, dh_unit, dh_lot,
                            temperature, final_comments, recipe_id_fk
                       FROM bd_fermenting_v2
                      WHERE submitted_at = ?
                        AND beer_raw     = ?
                        AND batch        = ?
                        AND event_type   = 'DryHop'
                        AND is_tombstoned = 0
                      ORDER BY line_idx ASC"
                );
                $sibStmt->execute([
                    $editOrigSubmittedAt,
                    (string)($editRow['beer_raw'] ?? ''),
                    (string)($editRow['batch']    ?? ''),
                ]);
                $sibRows = $sibStmt->fetchAll(PDO::FETCH_ASSOC);

                foreach ($sibRows as $sib) {
                    $prefillDhLines[] = [
                        'mi_id' => (string)($sib['dh_raw_name'] ?? ''),
                        'qty'   => $sib['dh_qty']  !== null ? (string)$sib['dh_qty']  : '',
                        'unit'  => (string)($sib['dh_unit'] ?? 'g'),
                        'lot'   => (string)($sib['dh_lot']  ?? ''),
                    ];
                }

                // Authoritative temperature + final_comments + recipe_id_fk come from line_idx=0.
                $sib0 = $sibRows[0] ?? $editRow;
            }

            $prefillEdit = [
                'event_type'        => (string)($editRow['event_type']    ?? 'Reads'),
                'event_date'        => (string)($editRow['event_date']    ?? date('Y-m-d')),
                'beer_raw'          => (string)($editRow['beer_raw']      ?? ''),
                'batch'             => (string)($editRow['batch']         ?? ''),
                'line_idx'          => (int)($editRow['line_idx']         ?? 0),
                'recipe_id_fk'      => $editRow['recipe_id_fk'] !== null ? (int)$editRow['recipe_id_fk'] : null,
                // For DryHop: temperature + final_comments from line_idx=0 sibling.
                // DryHop rows carry no gravity/pH readings (only temperature on line_idx=0).
                'gravity'           => (string)($editRow['event_type'] ?? '') === 'DryHop'
                                           ? null
                                           : $editRow['gravity'],
                'ph'                => (string)($editRow['event_type'] ?? '') === 'DryHop'
                                           ? null
                                           : $editRow['ph'],
                'temperature'       => (string)($editRow['event_type'] ?? '') === 'DryHop'
                                           ? ($sib0['temperature'] ?? null)
                                           : $editRow['temperature'],
                'comment_purge'      => $editRow['comment_purge'],
                'purge_pressure_bar' => $editRow['purge_pressure_bar'],
                'comment_cold_crash' => $editRow['comment_cold_crash'],
                'final_comments'    => (string)($editRow['event_type'] ?? '') === 'DryHop'
                                           ? ($sib0['final_comments'] ?? null)
                                           : $editRow['final_comments'],
                'session_id_fk'     => $editRow['session_id_fk'],
            ];

            $editBanner = [
                'beer'       => (string)($editRow['beer_raw']   ?? ''),
                'batch'      => (string)($editRow['batch']      ?? ''),
                'event_type' => (string)($editRow['event_type'] ?? ''),
                'event_date' => (string)($editRow['event_date'] ?? ''),
            ];
        }
    } catch (Throwable $eEdit) {
        flash_set('err', 'Erreur lors du chargement de l\'évènement : ' . htmlspecialchars($eEdit->getMessage()));
        $editMode = false;
    }
}
?><!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Saisie Fermentation — MaltyTask</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,200;0,9..144,300;0,9..144,400;1,9..144,300;1,9..144,400&family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/css/app.css?v=<?= @filemtime(__DIR__ . '/../css/app.css') ?: time() ?>">
  <link rel="stylesheet" href="/css/form-fermenting.css?v=<?= @filemtime(__DIR__ . '/../css/form-fermenting.css') ?: time() ?>">
</head>
<body class="home op-form-page form-fermenting">

<?php require __DIR__ . '/../../app/partials/sidebar.php' ?>
<?php require __DIR__ . '/../../app/partials/topbar.php' ?>

<main class="main">

  <?php flash_render() ?>

  <?php if ($loadErr !== null): ?>
    <div class="db-flash db-flash--err">
      Erreur de chargement : <?= htmlspecialchars($loadErr) ?>
    </div>
  <?php endif ?>

  <!-- ── Page header ─────────────────────────────────────────────────────── -->
  <div class="op-form__header">
    <div class="op-form__eyebrow">Fermentation · Brewing</div>
    <h1 class="op-form__title">Saisie <em>fermentation</em></h1>
    <p class="op-form__sub">
      Enregistrement des mesures et évènements de fermentation : densités (°Plato),
      pH, température, houblonnage à froid, purge, cold crash. Toutes les valeurs
      sont acceptées sans blocage — saisies marquées <code>web_entry</code> pour l'audit.
    </p>
  </div>

  <!-- ── Phase dispatcher — composes form + recent via partials ────────────── -->
  <?php require __DIR__ . '/partials/session-body-fermenting.php' ?>

</main>

<!-- Server-side data injected for JS — single <script> block per house convention -->
<script>
window.FERMENTING_HOPS     = <?= json_encode($hopsJs, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
// Beer-selection card candidates — shape: { Reads:[…], ColdCrash:[…], DryHop:[…], Purge:[…] }
// Each element: { beer, batch, beer_display, source_cct, recipe_id, recipe_name, sim_vol_hl }
// FERM_CANDIDATES is shown when ff_phase='none' (no beer/batch in URL).
window.FERM_CANDIDATES    = <?= json_encode($fermCandidates,   JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
window.FERM_CANDIDATES_HP = <?= json_encode($fermCandidatesHp, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
window.FERM_CAN_OVERRIDE  = <?= $canOverride ? 'true' : 'false' ?>;
<?php if (!empty($editMode) && ($prefillEdit['event_type'] ?? '') === 'DryHop' && !empty($prefillDhLines)): ?>
// Edit-mode DryHop prefill: array of { mi_id, qty, unit, lot } in line_idx order.
// form-fermenting.js reads this on init to repopulate the picker rows.
window.FERM_EDIT_DH_LINES = <?= json_encode($prefillDhLines, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
<?php else: ?>
window.FERM_EDIT_DH_LINES = null;
<?php endif ?>
</script>

<script src="/js/form-framework.js?v=<?= @filemtime(__DIR__ . '/../js/form-framework.js') ?: time() ?>" defer></script>
<script src="/js/form-fermenting.js?v=<?= @filemtime(__DIR__ . '/../js/form-fermenting.js') ?: time() ?>" defer></script>

</body>
</html>
