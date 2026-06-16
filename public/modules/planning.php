<?php
declare(strict_types=1);
/**
 * /modules/planning.php — Planning calendar (Phase 1: manual intent only).
 *
 * CARDINAL RULE: Planning is INTENT, not fact. pl_* tables read canonical state
 * to anticipate; they NEVER supply data to COGS/COP/WAC/BOM/beer-tax/inventory.
 *
 * Week view: 7 day columns, prev/next nav via ?week=YYYY-MM-DD.
 * Three section blocks per day: Wort / Packaging / Logistics.
 * Write access: scoped by manager_can('production') for wort+packaging,
 *               manager_can('logistics') for logistics.
 * Operators/viewers: read-only (no add forms shown).
 *
 * POST actions: add_wort, add_packaging, add_logistics, delete_item.
 * PRG pattern throughout.
 *
 * Auth: require_page_access('planning').
 * CSS: /css/planning.css   JS: /js/planning.js
 */

require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/settings-helpers.php';
require_once __DIR__ . '/../../app/db-write-helpers.php';
require_once __DIR__ . '/../../app/csrf.php';
require_once __DIR__ . '/../../app/tank-simulator.php';
require_once __DIR__ . '/../../app/yeast-eligibility.php';
require_once __DIR__ . '/../../app/planning-eligibility.php';

require_page_access('planning');
$me = current_user();
$active_module = 'planning';

// ── Write scope flags ─────────────────────────────────────────────────────────
// Built before POST handler — used in both paths.
$canWort = is_admin($me) || manager_can('production', $me);
$canLog  = is_admin($me) || manager_can('logistics', $me);

// ── Active recipes ────────────────────────────────────────────────────────────
// Loaded before POST handler — needed for validation and GET render.
$pdo = maltytask_pdo();
$recipesStmt = $pdo->prepare('SELECT id, name FROM ref_recipes WHERE is_active = 1 ORDER BY name');
$recipesStmt->execute();
$activeRecipes = $recipesStmt->fetchAll(PDO::FETCH_ASSOC);
$recipeIdSet   = array_column($activeRecipes, 'id');

// ── Active packaging types from ref_process_machines ─────────────────────────
// Map machine_type → pkg_type ENUM value. Only offer types with an active filler.
const PKG_TYPE_MAP = [
    'filler_bottle' => 'bottling',
    'filler_can'    => 'canning',
    'filler_keg'    => 'kegging',
    'filler_cuv'    => 'serving_tank',
];
$machinesStmt = $pdo->prepare(
    "SELECT machine_type FROM ref_process_machines
      WHERE machine_type IN ('filler_bottle','filler_can','filler_keg','filler_cuv')
        AND is_active = 1"
);
$machinesStmt->execute();
$activePkgTypes = [];
foreach ($machinesStmt->fetchAll(PDO::FETCH_ASSOC) as $m) {
    $pt = PKG_TYPE_MAP[$m['machine_type']] ?? null;
    if ($pt !== null) $activePkgTypes[] = $pt;
}

// ── Wort process whitelist ────────────────────────────────────────────────────
const WORT_PROCESSES = ['brewing', 'racking', 'kze', 'dry_hopping'];

// ── French label maps ─────────────────────────────────────────────────────────
const DAY_NAMES_FR   = ['Lundi','Mardi','Mercredi','Jeudi','Vendredi','Samedi','Dimanche'];
const MONTH_NAMES_FR = [
    'janvier','février','mars','avril','mai','juin',
    'juillet','août','septembre','octobre','novembre','décembre',
];
const WORT_PROCESS_LABELS = [
    'brewing'      => 'Brassage',
    'racking'      => 'Soutirage',
    'kze'          => 'KZE',
    'dry_hopping'  => 'Houblonnage à cru',
];
const PKG_TYPE_LABELS = [
    'bottling'      => 'Embouteillage',
    'canning'       => 'Mise en cannette',
    'kegging'       => 'Mise en fût',
    'serving_tank'  => 'Tank de service',
];

// ── POST handler ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Read ?week for redirect preservation — read with ?? default, THEN validate
    $weekParam = isset($_POST['week']) ? (string)$_POST['week'] : '';
    $weekQuery = ($weekParam !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $weekParam))
        ? '?week=' . urlencode($weekParam)
        : '';

    // CSRF gate
    if (!csrf_verify($_POST['csrf'] ?? null)) {
        flash_set('err', 'Session expirée — recharge la page.');
        redirect_to('/modules/planning.php' . $weekQuery);
    }

    $action = (string)($_POST['action'] ?? '');

    // ── delete_item ───────────────────────────────────────────────────────────
    if ($action === 'delete_item') {
        $itemId = (int)($_POST['item_id'] ?? 0);
        if ($itemId < 1) {
            flash_set('err', "Identifiant d'élément invalide.");
            redirect_to('/modules/planning.php' . $weekQuery);
        }

        // Fetch the item (before-state); exclude proposed items — those must be rejected via reject_proposal
        $fetchStmt = $pdo->prepare(
            "SELECT * FROM pl_plan_items WHERE id = ? AND is_active = 1 AND status != 'proposed' LIMIT 1"
        );
        $fetchStmt->execute([$itemId]);
        $item = $fetchStmt->fetch(PDO::FETCH_ASSOC);

        if ($item === false) {
            flash_set('err', 'Élément introuvable ou déjà supprimé.');
            redirect_to('/modules/planning.php' . $weekQuery);
        }

        // Scope gate: wort/packaging → canWort; logistics → canLog
        $section = (string)($item['section'] ?? '');
        if ($section === 'logistics') {
            if (!$canLog) {
                http_response_code(403);
                flash_set('err', 'Accès refusé.');
                redirect_to('/modules/planning.php' . $weekQuery);
            }
        } else {
            if (!$canWort) {
                http_response_code(403);
                flash_set('err', 'Accès refusé.');
                redirect_to('/modules/planning.php' . $weekQuery);
            }
        }

        // Soft-delete
        $delStmt = $pdo->prepare(
            'UPDATE pl_plan_items SET is_active = 0 WHERE id = ? AND is_active = 1'
        );
        $delStmt->execute([$itemId]);

        log_revision(
            $pdo, $me, 'pl_plan_items', $itemId,
            $item,
            ['is_active' => 0],
            'normal',
            null
        );

        flash_set('ok', 'Élément supprimé.');
        redirect_to('/modules/planning.php' . $weekQuery);
    }

    // ── add_wort ──────────────────────────────────────────────────────────────
    if ($action === 'add_wort') {
        if (!$canWort) {
            http_response_code(403);
            flash_set('err', 'Accès refusé.');
            redirect_to('/modules/planning.php' . $weekQuery);
        }

        $planDate          = (string)($_POST['plan_date'] ?? '');
        $wortProcess       = (string)($_POST['wort_process'] ?? '');
        // Merge split selects: brewing uses recipe_id_fk_brew, non-brewing uses recipe_id_fk_nonbrew.
        // Pick the appropriate one based on the submitted wort_process to avoid cross-field pollution.
        if ($wortProcess === 'brewing') {
            $recipeIdRaw = isset($_POST['recipe_id_fk_brew']) && $_POST['recipe_id_fk_brew'] !== ''
                           ? (int)$_POST['recipe_id_fk_brew'] : null;
            $batch       = trim((string)($_POST['batch_brew'] ?? ''));
        } else {
            $recipeIdRaw = isset($_POST['recipe_id_fk_nonbrew']) && $_POST['recipe_id_fk_nonbrew'] !== ''
                           ? (int)$_POST['recipe_id_fk_nonbrew'] : null;
            $batch       = trim((string)($_POST['batch_nonbrew'] ?? ''));
        }
        $beerFreeText      = trim((string)($_POST['beer_free_text'] ?? ''));
        $cctNumber         = isset($_POST['cct_number']) && $_POST['cct_number'] !== ''
                             ? (int)$_POST['cct_number'] : null;
        $horsProcess       = !empty($_POST['hors_process']);
        $horsProcessReason = trim((string)($_POST['hors_process_reason'] ?? ''));

        // Validate date
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $planDate) ||
            !DateTime::createFromFormat('Y-m-d', $planDate)) {
            flash_set('err', 'Date invalide.');
            redirect_to('/modules/planning.php' . $weekQuery);
        }

        // Validate wort_process
        if (!in_array($wortProcess, WORT_PROCESSES, true)) {
            flash_set('err', 'Type de processus invalide.');
            redirect_to('/modules/planning.php' . $weekQuery);
        }

        // Validate beer identification
        if ($wortProcess === 'brewing') {
            // For brewing: need either a free text name OR a recipe
            if ($beerFreeText === '' && !in_array($recipeIdRaw, $recipeIdSet, true)) {
                flash_set('err', 'Indiquez le nom de la bière ou sélectionnez une recette.');
                redirect_to('/modules/planning.php' . $weekQuery);
            }
        } else {
            // Non-brewing: need a recipe OR hors_process
            if (!in_array($recipeIdRaw, $recipeIdSet, true) && !$horsProcess) {
                flash_set('err', 'Sélectionnez une recette ou cochez Hors process.');
                redirect_to('/modules/planning.php' . $weekQuery);
            }
        }

        // Validate hors_process requires reason
        if ($horsProcess && $horsProcessReason === '') {
            flash_set('err', 'La raison hors process est obligatoire.');
            redirect_to('/modules/planning.php' . $weekQuery);
        }

        // Server-side eligibility check for non-brewing wort items
        if ($wortProcess !== 'brewing' && !$horsProcess && $recipeIdRaw !== null) {
            $checkDate = DateTimeImmutable::createFromFormat('Y-m-d', $planDate)->setTime(0,0,0);
            $dow = (int)$checkDate->format('N');
            $checkWeekStart = $checkDate->modify('-' . ($dow - 1) . ' days');
            $checkElig = planning_week_eligibility($pdo, $checkWeekStart);
            $dayElig = $checkElig[$planDate] ?? [];
            $processElig = $dayElig[$wortProcess] ?? [];
            $eligible = false;
            foreach ($processElig as $e) {
                if ((int)($e['recipe_id'] ?? 0) === (int)$recipeIdRaw) { $eligible = true; break; }
            }
            if (!$eligible) {
                flash_set('err', 'Cette bière n\'est pas éligible pour ce processus à cette date. Cochez Hors process si intentionnel.');
                redirect_to('/modules/planning.php' . $weekQuery);
            }
        }

        // Upsert pl_plan_days
        $dayStmt = $pdo->prepare(
            'INSERT INTO pl_plan_days (plan_date, created_by_user_id_fk)
             VALUES (?, ?)
             ON DUPLICATE KEY UPDATE updated_at = CURRENT_TIMESTAMP'
        );
        $dayStmt->execute([$planDate, (int)$me['id']]);

        // Get next seq
        $seqStmt = $pdo->prepare(
            "SELECT COALESCE(MAX(seq), 0) + 1 AS next_seq
               FROM pl_plan_items
              WHERE plan_date = ? AND section = 'wort' AND is_active = 1"
        );
        $seqStmt->execute([$planDate]);
        $nextSeq = (int)($seqStmt->fetchColumn() ?: 1);

        // Insert item
        $insStmt = $pdo->prepare(
            "INSERT INTO pl_plan_items
               (plan_date, section, seq, wort_process, recipe_id_fk, batch,
                beer_free_text, cct_number, hors_process, hors_process_reason,
                source, status, created_by_user_id_fk)
             VALUES (?, 'wort', ?, ?, ?, ?, ?, ?, ?, ?, 'manual', 'planned', ?)"
        );
        $insStmt->execute([
            $planDate,
            $nextSeq,
            $wortProcess,
            $recipeIdRaw,
            $batch !== '' ? $batch : null,
            $beerFreeText !== '' ? $beerFreeText : null,
            $cctNumber,
            $horsProcess ? 1 : 0,
            $horsProcessReason !== '' ? $horsProcessReason : null,
            (int)$me['id'],
        ]);
        $lastId = (int)$pdo->lastInsertId();

        $afterData = [
            'plan_date'           => $planDate,
            'section'             => 'wort',
            'seq'                 => $nextSeq,
            'wort_process'        => $wortProcess,
            'recipe_id_fk'        => $recipeIdRaw,
            'batch'               => $batch !== '' ? $batch : null,
            'beer_free_text'      => $beerFreeText !== '' ? $beerFreeText : null,
            'cct_number'          => $cctNumber,
            'hors_process'        => $horsProcess ? 1 : 0,
            'hors_process_reason' => $horsProcessReason !== '' ? $horsProcessReason : null,
            'source'              => 'manual',
            'status'              => 'planned',
            'created_by_user_id_fk' => (int)$me['id'],
        ];
        log_revision($pdo, $me, 'pl_plan_items', $lastId, null, $afterData, 'normal', null);

        flash_set('ok', 'Étape de brasserie ajoutée.');
        redirect_to('/modules/planning.php' . $weekQuery);
    }

    // ── add_packaging ─────────────────────────────────────────────────────────
    if ($action === 'add_packaging') {
        if (!$canWort) {
            http_response_code(403);
            flash_set('err', 'Accès refusé.');
            redirect_to('/modules/planning.php' . $weekQuery);
        }

        $planDate          = (string)($_POST['plan_date'] ?? '');
        $pkgType           = (string)($_POST['pkg_type'] ?? '');
        $targetVolumeRaw   = isset($_POST['target_volume_hl']) && $_POST['target_volume_hl'] !== ''
                             ? $_POST['target_volume_hl'] : null;
        $targetVolumeHl    = $targetVolumeRaw !== null ? (float)$targetVolumeRaw : null;
        $horsProcess       = !empty($_POST['hors_process']);
        $horsProcessReason = trim((string)($_POST['hors_process_reason'] ?? ''));
        $pkgRecipeIdRaw = isset($_POST['recipe_id_fk']) && $_POST['recipe_id_fk'] !== ''
                          ? (int)$_POST['recipe_id_fk'] : null;
        $pkgBbtNumber   = isset($_POST['bbt_number']) && $_POST['bbt_number'] !== ''
                          ? (int)$_POST['bbt_number'] : null;

        // Validate date
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $planDate) ||
            !DateTime::createFromFormat('Y-m-d', $planDate)) {
            flash_set('err', 'Date invalide.');
            redirect_to('/modules/planning.php' . $weekQuery);
        }

        // Validate pkg_type
        if (!in_array($pkgType, $activePkgTypes, true)) {
            flash_set('err', 'Type de conditionnement invalide ou machine inactive.');
            redirect_to('/modules/planning.php' . $weekQuery);
        }

        // Validate hors_process requires reason
        if ($horsProcess && $horsProcessReason === '') {
            flash_set('err', 'La raison hors process est obligatoire.');
            redirect_to('/modules/planning.php' . $weekQuery);
        }

        // Server-side eligibility check for packaging items
        if (!$horsProcess && $pkgRecipeIdRaw !== null) {
            $checkDate = DateTimeImmutable::createFromFormat('Y-m-d', $planDate)->setTime(0,0,0);
            $dow = (int)$checkDate->format('N');
            $checkWeekStart = $checkDate->modify('-' . ($dow - 1) . ' days');
            $checkElig = planning_week_eligibility($pdo, $checkWeekStart);
            $dayElig = $checkElig[$planDate] ?? [];
            $processElig = $dayElig['packaging'] ?? [];
            $eligible = false;
            foreach ($processElig as $e) {
                if ((int)($e['recipe_id'] ?? 0) === (int)$pkgRecipeIdRaw) { $eligible = true; break; }
            }
            if (!$eligible) {
                flash_set('err', 'Cette bière n\'est pas éligible pour le conditionnement à cette date. Cochez Hors process si intentionnel.');
                redirect_to('/modules/planning.php' . $weekQuery);
            }
        }

        // Upsert pl_plan_days
        $dayStmt = $pdo->prepare(
            'INSERT INTO pl_plan_days (plan_date, created_by_user_id_fk)
             VALUES (?, ?)
             ON DUPLICATE KEY UPDATE updated_at = CURRENT_TIMESTAMP'
        );
        $dayStmt->execute([$planDate, (int)$me['id']]);

        // Get next seq
        $seqStmt = $pdo->prepare(
            "SELECT COALESCE(MAX(seq), 0) + 1 AS next_seq
               FROM pl_plan_items
              WHERE plan_date = ? AND section = 'packaging' AND is_active = 1"
        );
        $seqStmt->execute([$planDate]);
        $nextSeq = (int)($seqStmt->fetchColumn() ?: 1);

        // Insert item
        $insStmt = $pdo->prepare(
            "INSERT INTO pl_plan_items
               (plan_date, section, seq, pkg_type, recipe_id_fk, bbt_number, target_volume_hl,
                hors_process, hors_process_reason,
                source, status, created_by_user_id_fk)
             VALUES (?, 'packaging', ?, ?, ?, ?, ?, ?, ?, 'manual', 'planned', ?)"
        );
        $insStmt->execute([
            $planDate,
            $nextSeq,
            $pkgType,
            $pkgRecipeIdRaw,
            $pkgBbtNumber,
            $targetVolumeHl,
            $horsProcess ? 1 : 0,
            $horsProcessReason !== '' ? $horsProcessReason : null,
            (int)$me['id'],
        ]);
        $lastId = (int)$pdo->lastInsertId();

        $afterData = [
            'plan_date'           => $planDate,
            'section'             => 'packaging',
            'seq'                 => $nextSeq,
            'pkg_type'            => $pkgType,
            'recipe_id_fk'        => $pkgRecipeIdRaw,
            'bbt_number'          => $pkgBbtNumber,
            'target_volume_hl'    => $targetVolumeHl,
            'hors_process'        => $horsProcess ? 1 : 0,
            'hors_process_reason' => $horsProcessReason !== '' ? $horsProcessReason : null,
            'source'              => 'manual',
            'status'              => 'planned',
            'created_by_user_id_fk' => (int)$me['id'],
        ];
        log_revision($pdo, $me, 'pl_plan_items', $lastId, null, $afterData, 'normal', null);

        flash_set('ok', 'Conditionnement ajouté.');
        redirect_to('/modules/planning.php' . $weekQuery);
    }

    // ── add_logistics ─────────────────────────────────────────────────────────
    if ($action === 'add_logistics') {
        if (!$canLog) {
            http_response_code(403);
            flash_set('err', 'Accès refusé.');
            redirect_to('/modules/planning.php' . $weekQuery);
        }

        $planDate          = (string)($_POST['plan_date'] ?? '');
        $logisticsText     = trim((string)($_POST['logistics_text'] ?? ''));
        $horsProcess       = !empty($_POST['hors_process']);
        $horsProcessReason = trim((string)($_POST['hors_process_reason'] ?? ''));

        // Validate date
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $planDate) ||
            !DateTime::createFromFormat('Y-m-d', $planDate)) {
            flash_set('err', 'Date invalide.');
            redirect_to('/modules/planning.php' . $weekQuery);
        }

        // Validate logistics text
        if ($logisticsText === '') {
            flash_set('err', 'Le texte logistique est obligatoire.');
            redirect_to('/modules/planning.php' . $weekQuery);
        }

        // Validate hors_process requires reason
        if ($horsProcess && $horsProcessReason === '') {
            flash_set('err', 'La raison hors process est obligatoire.');
            redirect_to('/modules/planning.php' . $weekQuery);
        }

        // Upsert pl_plan_days
        $dayStmt = $pdo->prepare(
            'INSERT INTO pl_plan_days (plan_date, created_by_user_id_fk)
             VALUES (?, ?)
             ON DUPLICATE KEY UPDATE updated_at = CURRENT_TIMESTAMP'
        );
        $dayStmt->execute([$planDate, (int)$me['id']]);

        // Get next seq
        $seqStmt = $pdo->prepare(
            "SELECT COALESCE(MAX(seq), 0) + 1 AS next_seq
               FROM pl_plan_items
              WHERE plan_date = ? AND section = 'logistics' AND is_active = 1"
        );
        $seqStmt->execute([$planDate]);
        $nextSeq = (int)($seqStmt->fetchColumn() ?: 1);

        // Insert item
        $insStmt = $pdo->prepare(
            "INSERT INTO pl_plan_items
               (plan_date, section, seq, logistics_text,
                hors_process, hors_process_reason,
                source, status, created_by_user_id_fk)
             VALUES (?, 'logistics', ?, ?, ?, ?, 'manual', 'planned', ?)"
        );
        $insStmt->execute([
            $planDate,
            $nextSeq,
            $logisticsText,
            $horsProcess ? 1 : 0,
            $horsProcessReason !== '' ? $horsProcessReason : null,
            (int)$me['id'],
        ]);
        $lastId = (int)$pdo->lastInsertId();

        $afterData = [
            'plan_date'           => $planDate,
            'section'             => 'logistics',
            'seq'                 => $nextSeq,
            'logistics_text'      => $logisticsText,
            'hors_process'        => $horsProcess ? 1 : 0,
            'hors_process_reason' => $horsProcessReason !== '' ? $horsProcessReason : null,
            'source'              => 'manual',
            'status'              => 'planned',
            'created_by_user_id_fk' => (int)$me['id'],
        ];
        log_revision($pdo, $me, 'pl_plan_items', $lastId, null, $afterData, 'normal', null);

        flash_set('ok', 'Entrée logistique ajoutée.');
        redirect_to('/modules/planning.php' . $weekQuery);
    }

    // ── generate_suggestions ─────────────────────────────────────────────────
    if ($action === 'generate_suggestions') {
        if (!$canWort) {
            http_response_code(403);
            flash_set('err', 'Accès refusé.');
            redirect_to('/modules/planning.php' . $weekQuery);
        }

        require_once __DIR__ . '/../../app/planning-predict.php';

        $weekRawPost = isset($_POST['week']) ? (string)$_POST['week'] : '';
        $weekParamDt = null;
        if ($weekRawPost !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $weekRawPost)) {
            $dt = DateTime::createFromFormat('Y-m-d', $weekRawPost);
            if ($dt !== false) {
                $dow = (int)$dt->format('N');
                $dt->modify('-' . ($dow - 1) . ' days');
                $weekParamDt = DateTimeImmutable::createFromMutable($dt)->setTime(0,0,0);
            }
        }
        if ($weekParamDt === null) {
            $now = new DateTime();
            $dow = (int)$now->format('N');
            $now->modify('-' . ($dow - 1) . ' days');
            $weekParamDt = DateTimeImmutable::createFromMutable($now)->setTime(0,0,0);
        }

        $result = planning_generate_suggestions($pdo, $weekParamDt, (int)$me['id']);

        if ($result['inserted'] > 0) {
            flash_set('ok', $result['inserted'] . ' suggestion(s) générée(s). Examinez et acceptez ou rejetez ci-dessous.');
        } elseif ($result['skipped_dedup'] > 0) {
            flash_set('ok', 'Aucune nouvelle suggestion — des propositions existent déjà pour cette semaine.');
        } else {
            flash_set('ok', 'Aucune suggestion à générer — couverture de stock suffisante pour toutes les bières.');
        }
        redirect_to('/modules/planning.php' . $weekQuery);
    }

    // ── accept_proposal ───────────────────────────────────────────────────────
    if ($action === 'accept_proposal') {
        $itemId = (int)($_POST['item_id'] ?? 0);
        if ($itemId < 1) {
            flash_set('err', "Identifiant invalide.");
            redirect_to('/modules/planning.php' . $weekQuery);
        }

        $fetchStmt = $pdo->prepare(
            "SELECT * FROM pl_plan_items WHERE id = ? AND is_active = 1
              AND source = 'predictive' AND status = 'proposed' LIMIT 1"
        );
        $fetchStmt->execute([$itemId]);
        $item = $fetchStmt->fetch(PDO::FETCH_ASSOC);

        if ($item === false) {
            flash_set('err', 'Proposition introuvable ou déjà traitée.');
            redirect_to('/modules/planning.php' . $weekQuery);
        }

        $section = (string)($item['section'] ?? '');
        if ($section === 'logistics') {
            if (!$canLog) {
                http_response_code(403);
                flash_set('err', 'Accès refusé.');
                redirect_to('/modules/planning.php' . $weekQuery);
            }
        } else {
            if (!$canWort) {
                http_response_code(403);
                flash_set('err', 'Accès refusé.');
                redirect_to('/modules/planning.php' . $weekQuery);
            }
        }

        $before = $item;
        $updStmt = $pdo->prepare(
            "UPDATE pl_plan_items SET status = 'planned', updated_at = CURRENT_TIMESTAMP WHERE id = ?"
        );
        $updStmt->execute([$itemId]);

        log_revision($pdo, $me, 'pl_plan_items', $itemId, $before, array_merge($before, ['status' => 'planned']), 'normal', null);
        flash_set('ok', 'Proposition acceptée et ajoutée au plan.');
        redirect_to('/modules/planning.php' . $weekQuery);
    }

    // ── reject_proposal ───────────────────────────────────────────────────────
    if ($action === 'reject_proposal') {
        $itemId = (int)($_POST['item_id'] ?? 0);
        if ($itemId < 1) {
            flash_set('err', "Identifiant invalide.");
            redirect_to('/modules/planning.php' . $weekQuery);
        }

        $fetchStmt = $pdo->prepare(
            "SELECT * FROM pl_plan_items WHERE id = ? AND is_active = 1
              AND source = 'predictive' AND status = 'proposed' LIMIT 1"
        );
        $fetchStmt->execute([$itemId]);
        $item = $fetchStmt->fetch(PDO::FETCH_ASSOC);

        if ($item === false) {
            flash_set('err', 'Proposition introuvable ou déjà traitée.');
            redirect_to('/modules/planning.php' . $weekQuery);
        }

        $section = (string)($item['section'] ?? '');
        if ($section === 'logistics') {
            if (!$canLog) {
                http_response_code(403);
                flash_set('err', 'Accès refusé.');
                redirect_to('/modules/planning.php' . $weekQuery);
            }
        } else {
            if (!$canWort) {
                http_response_code(403);
                flash_set('err', 'Accès refusé.');
                redirect_to('/modules/planning.php' . $weekQuery);
            }
        }

        $before = $item;
        $delStmt = $pdo->prepare(
            'UPDATE pl_plan_items SET is_active = 0, updated_at = CURRENT_TIMESTAMP WHERE id = ?'
        );
        $delStmt->execute([$itemId]);

        log_revision($pdo, $me, 'pl_plan_items', $itemId, $before, array_merge($before, ['is_active' => 0]), 'normal', null);
        flash_set('ok', 'Proposition rejetée.');
        redirect_to('/modules/planning.php' . $weekQuery);
    }

    // Unknown action — redirect silently
    redirect_to('/modules/planning.php' . $weekQuery);
}

// ── Week nav logic (GET) ──────────────────────────────────────────────────────
// Read ?week=YYYY-MM-DD — read with ?? default, THEN validate
$weekRaw   = isset($_GET['week']) ? (string)$_GET['week'] : '';
$weekStart = null;
if ($weekRaw !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $weekRaw)) {
    $dt = DateTime::createFromFormat('Y-m-d', $weekRaw);
    if ($dt !== false) {
        // Snap to Monday of the given date's week
        $dow = (int)$dt->format('N'); // 1=Mon..7=Sun
        $dt->modify('-' . ($dow - 1) . ' days');
        $weekStart = $dt;
    }
}
if ($weekStart === null) {
    // Default to current week's Monday
    $now = new DateTime();
    $dow = (int)$now->format('N');
    $now->modify('-' . ($dow - 1) . ' days');
    $weekStart = $now;
}
$weekEnd     = clone $weekStart;
$weekEnd->modify('+6 days');
$prevWeek    = (clone $weekStart)->modify('-7 days')->format('Y-m-d');
$nextWeek    = (clone $weekStart)->modify('+7 days')->format('Y-m-d');
$weekStartStr = $weekStart->format('Y-m-d');

// Compute eligibility for this week
$_weekStartDt = DateTimeImmutable::createFromFormat('Y-m-d', $weekStartStr);
$weekStartImm = ($_weekStartDt !== false) ? $_weekStartDt->setTime(0,0,0) : new DateTimeImmutable($weekStartStr);
$eligibility = planning_week_eligibility($pdo, $weekStartImm);

// ── Load items for the week ───────────────────────────────────────────────────
$itemsStmt = $pdo->prepare(
    "SELECT i.*, r.name AS recipe_name
       FROM pl_plan_items i
       LEFT JOIN ref_recipes r ON r.id = i.recipe_id_fk
      WHERE i.plan_date BETWEEN ? AND ?
        AND i.is_active = 1
      ORDER BY i.plan_date, i.section, i.seq, i.id"
);
$itemsStmt->execute([$weekStartStr, $weekEnd->format('Y-m-d')]);
$allItems = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

// Group by plan_date → section → items
$itemsByDaySection = [];
foreach ($allItems as $item) {
    $itemsByDaySection[(string)$item['plan_date']][(string)$item['section']][] = $item;
}

// ── Proposed (predictive) items for the week ──────────────────────────────────
$proposedItems = [];
foreach ($allItems as $item) {
    if ((string)($item['source'] ?? '') === 'predictive' && (string)($item['status'] ?? '') === 'proposed') {
        $proposedItems[] = $item;
    }
}
$hasProposals = !empty($proposedItems);

// ── Flash: pop from session (via canonical helper) ────────────────────────────
$flashData = flash_pop();
$flashType = $flashData['type'] ?? null;
$flashMsg  = $flashData['msg']  ?? null;

// ── Build the 7-day list ──────────────────────────────────────────────────────
$todayStr = (new DateTime())->format('Y-m-d');
$days = [];
for ($i = 0; $i < 7; $i++) {
    $d    = clone $weekStart;
    $d->modify("+{$i} days");
    $days[] = $d;
}

// ── French week label ─────────────────────────────────────────────────────────
$weekLabel = 'Semaine du '
    . (int)$weekStart->format('j') . ' '
    . MONTH_NAMES_FR[(int)$weekStart->format('n') - 1]
    . ' au '
    . (int)$weekEnd->format('j') . ' '
    . MONTH_NAMES_FR[(int)$weekEnd->format('n') - 1]
    . ' ' . $weekEnd->format('Y');

?><!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Planning — MaltyTask</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,200;0,9..144,300;0,9..144,400;1,9..144,300;1,9..144,400&family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/css/app.css?v=<?= @filemtime(__DIR__ . '/../css/app.css') ?: time() ?>">
  <link rel="stylesheet" href="/css/planning.css?v=<?= @filemtime(__DIR__ . '/../css/planning.css') ?: time() ?>">
  <script>
    window.PLANNING_CSRF        = <?= json_encode(csrf_token(), JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    window.PLANNING_CAN_WORT    = <?= json_encode($canWort) ?>;
    window.PLANNING_CAN_LOG     = <?= json_encode($canLog) ?>;
    window.PLANNING_ELIGIBILITY = <?= json_encode($eligibility, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
    window.PLANNING_WEEK        = <?= json_encode($weekStartStr, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
  </script>
</head>
<body class="home planning-page">

<?php require __DIR__ . '/../../app/partials/topbar.php' ?>

<main id="main-content" class="main">

  <!-- ── Flash ──────────────────────────────────────────────────────────────── -->
  <?php if ($flashType !== null): ?>
    <div class="pl-flash pl-flash--<?= htmlspecialchars($flashType, ENT_QUOTES | ENT_HTML5) ?>">
      <?= $flashType === 'ok' ? '✓' : '⚠' ?>
      <?= htmlspecialchars((string)$flashMsg, ENT_QUOTES | ENT_HTML5) ?>
    </div>
  <?php endif ?>

  <!-- ── Predictive suggestions button ───────────────────────────────────── -->
  <?php if ($canWort): ?>
    <form method="POST"
          action="/modules/planning.php?week=<?= htmlspecialchars($weekStartStr, ENT_QUOTES | ENT_HTML5) ?>"
          class="pl-suggest-form">
      <input type="hidden" name="csrf"   value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES | ENT_HTML5) ?>">
      <input type="hidden" name="action" value="generate_suggestions">
      <input type="hidden" name="week"   value="<?= htmlspecialchars($weekStartStr, ENT_QUOTES | ENT_HTML5) ?>">
      <button type="submit" class="pl-suggest-btn"
              title="Analyser la couverture de stock et générer des suggestions de planification">
        ✦ Suggérer un plan <span class="pl-suggest-btn__sub">données de vente</span>
      </button>
    </form>
  <?php endif ?>

  <!-- ── Page header ────────────────────────────────────────────────────────── -->
  <div class="pl-header">
    <div class="pl-header__eyebrow">Production · Organisation</div>
    <h1 class="pl-header__title">Plan<em>ning</em></h1>
    <p class="pl-header__sub">Vue hebdomadaire — intention uniquement, hors données fiscales.</p>
  </div>

  <!-- ── Week navigation ────────────────────────────────────────────────────── -->
  <nav class="pl-week-nav" aria-label="Navigation semaine">
    <a href="/modules/planning.php?week=<?= htmlspecialchars($prevWeek, ENT_QUOTES | ENT_HTML5) ?>"
       class="pl-week-nav__arrow pl-week-nav__arrow--prev"
       aria-label="Semaine précédente">&#8592; Préc.</a>
    <span class="pl-week-nav__label"><?= htmlspecialchars($weekLabel, ENT_QUOTES | ENT_HTML5) ?></span>
    <a href="/modules/planning.php?week=<?= htmlspecialchars($nextWeek, ENT_QUOTES | ENT_HTML5) ?>"
       class="pl-week-nav__arrow pl-week-nav__arrow--next"
       aria-label="Semaine suivante">Suiv. &#8594;</a>
  </nav>

  <!-- ── Week grid ──────────────────────────────────────────────────────────── -->
  <div class="pl-week-grid" role="grid" aria-label="Planning de la semaine">

    <?php foreach ($days as $dayIndex => $day): ?>
      <?php
        $dayDateStr  = $day->format('Y-m-d');
        $isToday     = ($dayDateStr === $todayStr);
        $dayName     = DAY_NAMES_FR[$dayIndex];
        $dayNum      = (int)$day->format('j');
        $monthName   = MONTH_NAMES_FR[(int)$day->format('n') - 1];
        $dayItems    = $itemsByDaySection[$dayDateStr] ?? [];
      ?>
      <div class="pl-day-col<?= $isToday ? ' pl-day-col--today' : '' ?>"
           role="gridcell"
           aria-label="<?= htmlspecialchars($dayName . ' ' . $dayNum . ' ' . $monthName, ENT_QUOTES | ENT_HTML5) ?>">

        <!-- Day header -->
        <div class="pl-day-header">
          <span class="pl-day-header__name"><?= htmlspecialchars($dayName, ENT_QUOTES | ENT_HTML5) ?></span>
          <span class="pl-day-header__date"><?= htmlspecialchars($dayNum . ' ' . $monthName, ENT_QUOTES | ENT_HTML5) ?></span>
        </div>

        <!-- ── Section: Wort ─────────────────────────────────────────────────── -->
        <div class="pl-section pl-day-section" data-section="wort">
          <div class="pl-section__label">Brasserie</div>

          <?php foreach ($dayItems['wort'] ?? [] as $item): ?>
            <?php $isProposed = ($item['source'] ?? '') === 'predictive' && ($item['status'] ?? '') === 'proposed'; ?>
            <div class="pl-item-card<?= $isProposed ? ' pl-item-card--proposed' : '' ?><?= $item['hors_process'] ? ' pl-item-card--hors-process' : '' ?>">
              <?php if ($isProposed && $canWort): ?>
                <div class="pl-proposed-badge">Proposé</div>
                <?php if (!empty($item['suggest_reason'])): ?>
                  <div class="pl-proposed-hint"><?= htmlspecialchars($item['suggest_reason'], ENT_QUOTES | ENT_HTML5) ?></div>
                <?php endif ?>
              <?php elseif ($canWort): ?>
                <form method="POST"
                      action="/modules/planning.php?week=<?= htmlspecialchars($weekStartStr, ENT_QUOTES | ENT_HTML5) ?>"
                      class="pl-item-card__del-form">
                  <input type="hidden" name="csrf"      value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES | ENT_HTML5) ?>">
                  <input type="hidden" name="action"    value="delete_item">
                  <input type="hidden" name="item_id"   value="<?= (int)$item['id'] ?>">
                  <input type="hidden" name="week"      value="<?= htmlspecialchars($weekStartStr, ENT_QUOTES | ENT_HTML5) ?>">
                  <button type="submit" class="pl-item-card__del" aria-label="Supprimer">×</button>
                </form>
              <?php endif ?>
              <div class="pl-item-card__type">
                <?= htmlspecialchars(WORT_PROCESS_LABELS[$item['wort_process']] ?? $item['wort_process'], ENT_QUOTES | ENT_HTML5) ?>
              </div>
              <?php if (!empty($item['recipe_name'])): ?>
                <div class="pl-item-card__recipe"><?= htmlspecialchars($item['recipe_name'], ENT_QUOTES | ENT_HTML5) ?></div>
              <?php elseif (!empty($item['beer_free_text'])): ?>
                <div class="pl-item-card__recipe"><?= htmlspecialchars($item['beer_free_text'], ENT_QUOTES | ENT_HTML5) ?></div>
              <?php endif ?>
              <?php if (!empty($item['batch'])): ?>
                <div class="pl-item-card__meta">Brassin : <?= htmlspecialchars($item['batch'], ENT_QUOTES | ENT_HTML5) ?></div>
              <?php endif ?>
              <?php if ($item['cct_number'] !== null): ?>
                <div class="pl-item-card__meta">CCT <?= (int)$item['cct_number'] ?></div>
              <?php endif ?>
              <?php if ($item['bbt_number'] !== null): ?>
                <div class="pl-item-card__meta">BBT <?= (int)$item['bbt_number'] ?></div>
              <?php endif ?>
              <?php if ($item['hors_process']): ?>
                <span class="pl-hors-badge">Hors process</span>
                <?php if (!empty($item['hors_process_reason'])): ?>
                  <div class="pl-item-card__meta pl-item-card__meta--reason">
                    <?= htmlspecialchars($item['hors_process_reason'], ENT_QUOTES | ENT_HTML5) ?>
                  </div>
                <?php endif ?>
              <?php endif ?>
              <?php if ($isProposed && $canWort): ?>
                <div class="pl-proposed-actions">
                  <form method="POST" action="/modules/planning.php?week=<?= htmlspecialchars($weekStartStr, ENT_QUOTES | ENT_HTML5) ?>" class="pl-proposed-action-form">
                    <input type="hidden" name="csrf"    value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES | ENT_HTML5) ?>">
                    <input type="hidden" name="action"  value="accept_proposal">
                    <input type="hidden" name="item_id" value="<?= (int)$item['id'] ?>">
                    <input type="hidden" name="week"    value="<?= htmlspecialchars($weekStartStr, ENT_QUOTES | ENT_HTML5) ?>">
                    <button type="submit" class="pl-proposed-accept">✓ Accepter</button>
                  </form>
                  <form method="POST" action="/modules/planning.php?week=<?= htmlspecialchars($weekStartStr, ENT_QUOTES | ENT_HTML5) ?>" class="pl-proposed-action-form">
                    <input type="hidden" name="csrf"    value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES | ENT_HTML5) ?>">
                    <input type="hidden" name="action"  value="reject_proposal">
                    <input type="hidden" name="item_id" value="<?= (int)$item['id'] ?>">
                    <input type="hidden" name="week"    value="<?= htmlspecialchars($weekStartStr, ENT_QUOTES | ENT_HTML5) ?>">
                    <button type="submit" class="pl-proposed-reject">✕ Rejeter</button>
                  </form>
                </div>
              <?php endif ?>
            </div>
          <?php endforeach ?>

          <?php if (empty($dayItems['wort']) && !$canWort): ?>
            <div class="pl-section__empty">—</div>
          <?php endif ?>

          <?php if ($canWort): ?>
            <button type="button" class="pl-add-trigger" data-section="wort">＋ Ajouter</button>
            <!-- Add wort form -->
            <form method="POST"
                  action="/modules/planning.php?week=<?= htmlspecialchars($weekStartStr, ENT_QUOTES | ENT_HTML5) ?>"
                  class="pl-add-form pl-wort-form"
                  data-plan-date="<?= htmlspecialchars($dayDateStr, ENT_QUOTES | ENT_HTML5) ?>"
                  data-section="wort">
              <input type="hidden" name="csrf"      value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES | ENT_HTML5) ?>">
              <input type="hidden" name="action"    value="add_wort">
              <input type="hidden" name="plan_date" value="<?= htmlspecialchars($dayDateStr, ENT_QUOTES | ENT_HTML5) ?>">
              <input type="hidden" name="week"      value="<?= htmlspecialchars($weekStartStr, ENT_QUOTES | ENT_HTML5) ?>">
              <div class="pl-add-form__day-label"><?= htmlspecialchars($dayName . ' ' . $dayNum . ' ' . $monthName, ENT_QUOTES | ENT_HTML5) ?></div>

              <select name="wort_process" class="pl-input pl-select" required aria-label="Processus">
                <?php foreach (WORT_PROCESSES as $wp): ?>
                  <option value="<?= htmlspecialchars($wp, ENT_QUOTES | ENT_HTML5) ?>">
                    <?= htmlspecialchars(WORT_PROCESS_LABELS[$wp] ?? $wp, ENT_QUOTES | ENT_HTML5) ?>
                  </option>
                <?php endforeach ?>
              </select>

              <!-- Brewing-specific fields (shown when wort_process=brewing) -->
              <!-- Uses distinct name recipe_id_fk_brew to avoid collision with non-brewing select -->
              <div class="pl-brewing-fields" hidden>
                <input type="text" name="beer_free_text" class="pl-input"
                       placeholder="Nom de la bière (texte libre)"
                       maxlength="120" aria-label="Nom de la bière">
                <select name="recipe_id_fk_brew" class="pl-input pl-select" aria-label="Recette (optionnel)">
                  <option value="">— Recette (optionnel) —</option>
                  <?php foreach ($activeRecipes as $r): ?>
                    <option value="<?= (int)$r['id'] ?>">
                      <?= htmlspecialchars($r['name'], ENT_QUOTES | ENT_HTML5) ?>
                    </option>
                  <?php endforeach ?>
                </select>
                <input type="text"   name="batch_brew"  class="pl-input" placeholder="N° brassin" maxlength="32" aria-label="N° brassin">
                <input type="number" name="cct_number" class="pl-input" placeholder="CCT n°" min="1" max="99" step="1" aria-label="CCT n°">
              </div>

              <!-- Non-brewing fields (shown when wort_process != brewing) -->
              <!-- Uses distinct name recipe_id_fk_nonbrew to avoid collision with brewing select -->
              <div class="pl-nonbrewing-fields" hidden>
                <!-- PHP renders all active recipes as no-JS fallback.
                     JS (initWortProcessToggle) clears and repopulates this select
                     with eligibility-filtered options from window.PLANNING_ELIGIBILITY. -->
                <select name="recipe_id_fk_nonbrew" class="pl-input pl-select" aria-label="Recette">
                  <option value="">— Recette —</option>
                  <?php foreach ($activeRecipes as $r): ?>
                    <option value="<?= (int)$r['id'] ?>">
                      <?= htmlspecialchars($r['name'], ENT_QUOTES | ENT_HTML5) ?>
                    </option>
                  <?php endforeach ?>
                </select>
                <input type="text" name="batch_nonbrew" class="pl-input" placeholder="N° brassin" maxlength="32" aria-label="N° brassin">
              </div>

              <label class="pl-checkbox-label">
                <input type="checkbox" name="hors_process" value="1"> Hors process
              </label>
              <div class="pl-reason-wrap" hidden>
                <input type="text" name="hors_process_reason" class="pl-input"
                       placeholder="Raison hors process" maxlength="255">
              </div>

              <button type="submit" class="pl-add-btn">＋ Ajouter</button>
            </form>
          <?php endif ?>
        </div>

        <!-- ── Section: Packaging ────────────────────────────────────────────── -->
        <div class="pl-section pl-day-section" data-section="packaging">
          <div class="pl-section__label">Conditionnement</div>

          <?php foreach ($dayItems['packaging'] ?? [] as $item): ?>
            <?php $isProposed = ($item['source'] ?? '') === 'predictive' && ($item['status'] ?? '') === 'proposed'; ?>
            <div class="pl-item-card<?= $isProposed ? ' pl-item-card--proposed' : '' ?><?= $item['hors_process'] ? ' pl-item-card--hors-process' : '' ?>">
              <?php if ($isProposed && $canWort): ?>
                <div class="pl-proposed-badge">Proposé</div>
                <?php if (!empty($item['suggest_reason'])): ?>
                  <div class="pl-proposed-hint"><?= htmlspecialchars($item['suggest_reason'], ENT_QUOTES | ENT_HTML5) ?></div>
                <?php endif ?>
              <?php elseif ($canWort): ?>
                <form method="POST"
                      action="/modules/planning.php?week=<?= htmlspecialchars($weekStartStr, ENT_QUOTES | ENT_HTML5) ?>"
                      class="pl-item-card__del-form">
                  <input type="hidden" name="csrf"      value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES | ENT_HTML5) ?>">
                  <input type="hidden" name="action"    value="delete_item">
                  <input type="hidden" name="item_id"   value="<?= (int)$item['id'] ?>">
                  <input type="hidden" name="week"      value="<?= htmlspecialchars($weekStartStr, ENT_QUOTES | ENT_HTML5) ?>">
                  <button type="submit" class="pl-item-card__del" aria-label="Supprimer">×</button>
                </form>
              <?php endif ?>
              <div class="pl-item-card__type">
                <?= htmlspecialchars(PKG_TYPE_LABELS[$item['pkg_type']] ?? $item['pkg_type'], ENT_QUOTES | ENT_HTML5) ?>
              </div>
              <?php if (!empty($item['recipe_name'])): ?>
                <div class="pl-item-card__recipe"><?= htmlspecialchars($item['recipe_name'], ENT_QUOTES | ENT_HTML5) ?></div>
              <?php endif ?>
              <?php if (!empty($item['batch'])): ?>
                <div class="pl-item-card__meta">Brassin : <?= htmlspecialchars($item['batch'], ENT_QUOTES | ENT_HTML5) ?></div>
              <?php endif ?>
              <?php if ($item['bbt_number'] !== null): ?>
                <div class="pl-item-card__meta">BBT <?= (int)$item['bbt_number'] ?></div>
              <?php endif ?>
              <?php if ($item['target_volume_hl'] !== null): ?>
                <div class="pl-item-card__meta">
                  <?= htmlspecialchars(number_format((float)$item['target_volume_hl'], 1, '.', ''), ENT_QUOTES | ENT_HTML5) ?> hl
                </div>
              <?php endif ?>
              <?php if ($item['hors_process']): ?>
                <span class="pl-hors-badge">Hors process</span>
                <?php if (!empty($item['hors_process_reason'])): ?>
                  <div class="pl-item-card__meta pl-item-card__meta--reason">
                    <?= htmlspecialchars($item['hors_process_reason'], ENT_QUOTES | ENT_HTML5) ?>
                  </div>
                <?php endif ?>
              <?php endif ?>
              <?php if ($isProposed && $canWort): ?>
                <div class="pl-proposed-actions">
                  <form method="POST" action="/modules/planning.php?week=<?= htmlspecialchars($weekStartStr, ENT_QUOTES | ENT_HTML5) ?>" class="pl-proposed-action-form">
                    <input type="hidden" name="csrf"    value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES | ENT_HTML5) ?>">
                    <input type="hidden" name="action"  value="accept_proposal">
                    <input type="hidden" name="item_id" value="<?= (int)$item['id'] ?>">
                    <input type="hidden" name="week"    value="<?= htmlspecialchars($weekStartStr, ENT_QUOTES | ENT_HTML5) ?>">
                    <button type="submit" class="pl-proposed-accept">✓ Accepter</button>
                  </form>
                  <form method="POST" action="/modules/planning.php?week=<?= htmlspecialchars($weekStartStr, ENT_QUOTES | ENT_HTML5) ?>" class="pl-proposed-action-form">
                    <input type="hidden" name="csrf"    value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES | ENT_HTML5) ?>">
                    <input type="hidden" name="action"  value="reject_proposal">
                    <input type="hidden" name="item_id" value="<?= (int)$item['id'] ?>">
                    <input type="hidden" name="week"    value="<?= htmlspecialchars($weekStartStr, ENT_QUOTES | ENT_HTML5) ?>">
                    <button type="submit" class="pl-proposed-reject">✕ Rejeter</button>
                  </form>
                </div>
              <?php endif ?>
            </div>
          <?php endforeach ?>

          <?php if (empty($dayItems['packaging']) && !$canWort): ?>
            <div class="pl-section__empty">—</div>
          <?php endif ?>

          <?php if ($canWort && !empty($activePkgTypes)): ?>
            <button type="button" class="pl-add-trigger" data-section="packaging">＋ Ajouter</button>
            <!-- Add packaging form -->
            <form method="POST"
                  action="/modules/planning.php?week=<?= htmlspecialchars($weekStartStr, ENT_QUOTES | ENT_HTML5) ?>"
                  class="pl-add-form"
                  data-plan-date="<?= htmlspecialchars($dayDateStr, ENT_QUOTES | ENT_HTML5) ?>"
                  data-section="packaging">
              <input type="hidden" name="csrf"      value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES | ENT_HTML5) ?>">
              <input type="hidden" name="action"    value="add_packaging">
              <input type="hidden" name="plan_date" value="<?= htmlspecialchars($dayDateStr, ENT_QUOTES | ENT_HTML5) ?>">
              <input type="hidden" name="week"      value="<?= htmlspecialchars($weekStartStr, ENT_QUOTES | ENT_HTML5) ?>">
              <div class="pl-add-form__day-label"><?= htmlspecialchars($dayName . ' ' . $dayNum . ' ' . $monthName, ENT_QUOTES | ENT_HTML5) ?></div>

              <select name="recipe_id_fk" class="pl-input pl-select pl-pkg-recipe-select" aria-label="Bière (BBT éligible)">
                <option value="">— Bière en BBT —</option>
              </select>
              <input type="hidden" name="bbt_number" class="pl-pkg-bbt-hidden">
              <select name="pkg_type" class="pl-input pl-select" required aria-label="Type de conditionnement">
                <option value="">— Type —</option>
                <?php foreach ($activePkgTypes as $pt): ?>
                  <option value="<?= htmlspecialchars($pt, ENT_QUOTES | ENT_HTML5) ?>">
                    <?= htmlspecialchars(PKG_TYPE_LABELS[$pt] ?? $pt, ENT_QUOTES | ENT_HTML5) ?>
                  </option>
                <?php endforeach ?>
              </select>

              <input type="number" name="target_volume_hl" class="pl-input"
                     step="0.1" min="0" placeholder="Volume cible (hl)"
                     aria-label="Volume cible en hl">

              <label class="pl-checkbox-label">
                <input type="checkbox" name="hors_process" value="1"> Hors process
              </label>
              <div class="pl-reason-wrap" hidden>
                <input type="text" name="hors_process_reason" class="pl-input"
                       placeholder="Raison hors process" maxlength="255">
              </div>

              <button type="submit" class="pl-add-btn">＋ Ajouter</button>
            </form>
          <?php endif ?>
        </div>

        <!-- ── Section: Logistics ────────────────────────────────────────────── -->
        <div class="pl-section pl-day-section" data-section="logistics">
          <div class="pl-section__label">Logistique</div>

          <?php foreach ($dayItems['logistics'] ?? [] as $item): ?>
            <div class="pl-item-card<?= $item['hors_process'] ? ' pl-item-card--hors-process' : '' ?>">
              <?php if ($canLog): ?>
                <form method="POST"
                      action="/modules/planning.php?week=<?= htmlspecialchars($weekStartStr, ENT_QUOTES | ENT_HTML5) ?>"
                      class="pl-item-card__del-form">
                  <input type="hidden" name="csrf"      value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES | ENT_HTML5) ?>">
                  <input type="hidden" name="action"    value="delete_item">
                  <input type="hidden" name="item_id"   value="<?= (int)$item['id'] ?>">
                  <input type="hidden" name="week"      value="<?= htmlspecialchars($weekStartStr, ENT_QUOTES | ENT_HTML5) ?>">
                  <button type="submit" class="pl-item-card__del" aria-label="Supprimer">×</button>
                </form>
              <?php endif ?>
              <?php if (!empty($item['logistics_text'])): ?>
                <div class="pl-item-card__logistics-text">
                  <?= htmlspecialchars($item['logistics_text'], ENT_QUOTES | ENT_HTML5) ?>
                </div>
              <?php endif ?>
              <?php if ($item['hors_process']): ?>
                <span class="pl-hors-badge">Hors process</span>
                <?php if (!empty($item['hors_process_reason'])): ?>
                  <div class="pl-item-card__meta pl-item-card__meta--reason">
                    <?= htmlspecialchars($item['hors_process_reason'], ENT_QUOTES | ENT_HTML5) ?>
                  </div>
                <?php endif ?>
              <?php endif ?>
            </div>
          <?php endforeach ?>

          <?php if (empty($dayItems['logistics']) && !$canLog): ?>
            <div class="pl-section__empty">—</div>
          <?php endif ?>

          <?php if ($canLog): ?>
            <button type="button" class="pl-add-trigger" data-section="logistics">＋ Ajouter</button>
            <!-- Add logistics form -->
            <form method="POST"
                  action="/modules/planning.php?week=<?= htmlspecialchars($weekStartStr, ENT_QUOTES | ENT_HTML5) ?>"
                  class="pl-add-form"
                  data-plan-date="<?= htmlspecialchars($dayDateStr, ENT_QUOTES | ENT_HTML5) ?>"
                  data-section="logistics">
              <input type="hidden" name="csrf"      value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES | ENT_HTML5) ?>">
              <input type="hidden" name="action"    value="add_logistics">
              <input type="hidden" name="plan_date" value="<?= htmlspecialchars($dayDateStr, ENT_QUOTES | ENT_HTML5) ?>">
              <input type="hidden" name="week"      value="<?= htmlspecialchars($weekStartStr, ENT_QUOTES | ENT_HTML5) ?>">
              <div class="pl-add-form__day-label"><?= htmlspecialchars($dayName . ' ' . $dayNum . ' ' . $monthName, ENT_QUOTES | ENT_HTML5) ?></div>

              <textarea name="logistics_text" class="pl-input pl-textarea"
                        rows="2" required
                        placeholder="Livraison, retrait, transport…"
                        maxlength="1000"
                        aria-label="Texte logistique"></textarea>

              <label class="pl-checkbox-label">
                <input type="checkbox" name="hors_process" value="1"> Hors process
              </label>
              <div class="pl-reason-wrap" hidden>
                <input type="text" name="hors_process_reason" class="pl-input"
                       placeholder="Raison hors process" maxlength="255">
              </div>

              <button type="submit" class="pl-add-btn">＋ Ajouter</button>
            </form>
          <?php endif ?>
        </div>

      </div><!-- /.pl-day-col -->
    <?php endforeach ?>

  </div><!-- /.pl-week-grid -->

</main>

<script src="/js/planning.js?v=<?= @filemtime(__DIR__ . '/../js/planning.js') ?: time() ?>"></script>
</body>
</html>
