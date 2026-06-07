<?php
declare(strict_types=1);
/**
 * modules/bom-review.php — BOM Review (admin correction surface)
 *
 * Lets the admin manually review and correct the packaging Bill of Materials
 * for every beer/SKU at once, landing directly on what is wrong, and editing
 * at the correct altitude:
 *   - Recipe binding (ref_recipe_packaging_bindings) — wrong for ALL SKUs of a recipe
 *   - Per-SKU choice (ref_sku_packaging_choices) — wrong for ONE SKU only
 *   - MI price (ref_mi.price) — wrong/missing price on a packaging ingredient
 *
 * HARD INVARIANTS:
 *   1. NEVER write ref_sku_bom directly (corrections_policy='blocked_with_redirect').
 *   2. After any binding/choice/price edit, immediately recompile via
 *      sdc_recompile_recipe_packaging() (already exists in sku-bom-compile.php).
 *   3. Brewing rows (source='Brewing') are READ-ONLY in v1.
 *   4. Refuse, don't NULL — if a correction can't be resolved, surface as flag.
 *
 * Auth: require_page_access('bom-review') — admin only (min_role set in ref_pages).
 * POST: csrf_verify → validate → write → log_revision → PRG redirect.
 *
 * TODO (v2): Allow editing mineral/process-aid gap-fill bindings once the
 *            operator has confirmed which ref_recipe rows are authoritative.
 *            Malt/hops remain read-only because observed data is intentionally
 *            canonical (recipe drift is real, not a defect).
 */

require __DIR__ . '/../../app/auth.php';
require __DIR__ . '/../../app/csrf.php';
require __DIR__ . '/../../app/settings-helpers.php';
require __DIR__ . '/../../app/settings.php';
require __DIR__ . '/../../app/db-write-helpers.php';
require_once __DIR__ . '/../../app/sku-bom-compile.php';

require_page_access('bom-review');
$me = current_user();

header('Content-Type: text/html; charset=utf-8');
$active_module = 'bom-review';

// ─────────────────────────────────────────────────────────────────────────────
// Allowed sets built HERE (in the POST path scope) so they're available both
// for GET render and POST validation (anti-pattern: never build only in GET).
// ─────────────────────────────────────────────────────────────────────────────

$VALID_BINDING_ROLES = ['label', 'can', 'sticker', 'holder', 'outer_tray', 'scotch'];
$VALID_CURRENCIES    = ['CHF', 'EUR'];
$validSlots          = [
    'label', 'can', 'sticker', 'holder', 'outer_tray', 'scotch',
    'bottle', 'crown_caps', 'can_lids', 'outer_box', 'intercal',
    'verre', 'scotch_eshop',
];

// ─────────────────────────────────────────────────────────────────────────────
// POST handler
// ─────────────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Admin gate (belt-and-suspenders — require_page_access already checked)
    if (!is_admin($me)) {
        flash_set('err', 'Modification réservée aux administrateurs.');
        redirect_to('/modules/bom-review.php');
    }

    // CSRF
    if (!csrf_verify($_POST['csrf'] ?? null)) {
        flash_set('err', 'Session expirée — recharge la page.');
        redirect_to('/modules/bom-review.php');
    }

    $action = post_str('action') ?? '';

    try {
        $pdo = maltytask_pdo();

        // ── Action: set_recipe_binding ──────────────────────────────────────
        // Correct a recipe-level packaging binding (wrong for ALL SKUs of this recipe).
        // Mirrors the salle-de-controle.php set_binding handler exactly.
        if ($action === 'set_recipe_binding') {
            $recipeId = post_int('recipe_id') ?? 0;
            $miIdFk   = post_int('mi_id_fk')  ?? 0;
            $role     = post_str('role')       ?? '';

            if ($recipeId <= 0 || $miIdFk <= 0 || $role === '') {
                throw new RuntimeException('recipe_id, role et mi_id_fk sont requis.');
            }

            $role = must_be_one_of('role', $role, $VALID_BINDING_ROLES);

            // Validate recipe exists
            $recStmt = $pdo->prepare(
                'SELECT id, sku_prefix, name FROM ref_recipes WHERE id = ? AND is_active = 1 LIMIT 1'
            );
            $recStmt->execute([$recipeId]);
            $recipe = $recStmt->fetch(PDO::FETCH_ASSOC);
            if (!$recipe || empty($recipe['sku_prefix'])) {
                throw new RuntimeException('Recette introuvable ou sans préfixe SKU.');
            }
            $prefix = (string) $recipe['sku_prefix'];

            // Validate MI exists
            $miStmt = $pdo->prepare('SELECT id, mi_id FROM ref_mi WHERE id = ? LIMIT 1');
            $miStmt->execute([$miIdFk]);
            $miRow = $miStmt->fetch(PDO::FETCH_ASSOC);
            if (!$miRow) {
                throw new RuntimeException('Ingrédient (MI) introuvable.');
            }

            // Validate MI matches pattern for this role
            $patternStmt = $pdo->prepare(
                'SELECT mi_filter_pattern FROM ref_packaging_items
                  WHERE slot_name = ? AND mi_filter_pattern LIKE \'%{beer}%\' LIMIT 1'
            );
            $patternStmt->execute([$role]);
            $rawPattern = $patternStmt->fetchColumn();
            if ($rawPattern) {
                $rawPatternStr = (string) $rawPattern;
                if (str_contains($rawPatternStr, '(TRANSP|{beer})')) {
                    $brandedPattern = 'PKG_SCOTCH_' . $prefix . '%';
                    $chk = $pdo->prepare(
                        'SELECT COUNT(*) FROM ref_mi
                          WHERE id = ? AND (mi_id LIKE \'PKG_SCOTCH_TRANSP%\' OR mi_id LIKE ?)'
                    );
                    $chk->execute([$miIdFk, $brandedPattern]);
                } else {
                    $resolved = str_replace('{beer}', $prefix, $rawPatternStr);
                    $chk = $pdo->prepare('SELECT COUNT(*) FROM ref_mi WHERE id = ? AND mi_id LIKE ?');
                    $chk->execute([$miIdFk, $resolved]);
                }
                if ((int) $chk->fetchColumn() === 0) {
                    $displayPattern = str_contains($rawPatternStr, '(TRANSP|{beer})')
                        ? "PKG_SCOTCH_TRANSP% OU PKG_SCOTCH_{$prefix}%"
                        : str_replace('{beer}', $prefix, $rawPatternStr);
                    throw new RuntimeException(
                        "L'ingrédient sélectionné ne correspond pas au pattern attendu "
                        . "pour le rôle «{$role}» (pattern: {$displayPattern})."
                    );
                }
            }

            $pdo->beginTransaction();
            try {
                // Expire current active binding for same (recipe, role)
                $pdo->prepare(
                    'UPDATE ref_recipe_packaging_bindings
                        SET effective_until = CURDATE()
                      WHERE recipe_id = ? AND role = ?
                        AND (effective_until IS NULL OR effective_until >= CURDATE())'
                )->execute([$recipeId, $role]);

                $todayStr = (new DateTimeImmutable())->format('Y-m-d');
                $ins = $pdo->prepare(
                    'INSERT INTO ref_recipe_packaging_bindings
                        (recipe_id, role, mi_id_fk, effective_from, effective_until, notes)
                     VALUES (?, ?, ?, ?, NULL, ?)'
                );
                $notes = "Défini via BOM Review · recette #{$recipeId}";
                $ins->execute([$recipeId, $role, $miIdFk, $todayStr, $notes]);
                $newId = (int) $pdo->lastInsertId();

                log_revision(
                    $pdo, $me, 'ref_recipe_packaging_bindings', $newId, null,
                    ['recipe_id' => $recipeId, 'role' => $role, 'mi_id_fk' => $miIdFk,
                     'effective_from' => $todayStr],
                    'normal',
                    "BOM Review: liaison recipe={$recipe['name']} role={$role} MI={$miRow['mi_id']}"
                );
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }

            $saveMsg = "Liaison «{$role}» pour «{$recipe['name']}» enregistrée.";
            try {
                $r = sdc_recompile_recipe_packaging($pdo, $recipeId);
                sdc_flash_bom_result($saveMsg, $r);
            } catch (Throwable $bomErr) {
                flash_set('ok', $saveMsg . ' · BOM recompilation échouée (la sauvegarde est conservée).');
            }

            // Return to browse tab if the edit came from there
            $postSec = post_str('sec') ?? 'drill';
            if ($postSec === 'browse') {
                redirect_to('/modules/bom-review.php?sec=browse');
            }
            redirect_to('/modules/bom-review.php?sec=drill&recipe=' . $recipeId);
        }

        // ── Action: set_sku_choice ──────────────────────────────────────────
        // Upsert a per-SKU packaging choice (Tier-1, overrides recipe binding).
        // This is exactly how DIV33C was fixed: id=11, sku_id=14, slot_name='can', mi_id_fk=617.
        if ($action === 'set_sku_choice') {
            $skuId    = post_int('sku_id')    ?? 0;
            $miIdFk   = post_int('mi_id_fk')  ?? 0;
            $slotName = post_str('slot_name') ?? '';

            if ($skuId <= 0 || $miIdFk <= 0 || $slotName === '') {
                throw new RuntimeException('sku_id, slot_name et mi_id_fk sont requis.');
            }

            // Validate slot_name against known slots (use global list, built before POST/GET split)
            $slotName = must_be_one_of('slot_name', $slotName, $validSlots);

            // Validate SKU exists
            $skuStmt = $pdo->prepare(
                'SELECT id, sku_code, recipe_id FROM ref_skus WHERE id = ? AND is_active = 1 LIMIT 1'
            );
            $skuStmt->execute([$skuId]);
            $skuRow = $skuStmt->fetch(PDO::FETCH_ASSOC);
            if (!$skuRow) {
                throw new RuntimeException('SKU introuvable.');
            }

            // Validate MI exists
            $miStmt = $pdo->prepare('SELECT id, mi_id FROM ref_mi WHERE id = ? LIMIT 1');
            $miStmt->execute([$miIdFk]);
            $miRow = $miStmt->fetch(PDO::FETCH_ASSOC);
            if (!$miRow) {
                throw new RuntimeException('Ingrédient (MI) introuvable.');
            }

            $today    = (new DateTimeImmutable())->format('Y-m-d');
            $qtyPerUnit = 1.0000; // default; the template qty is canonical

            // Fetch before-state for audit
            $beforeStmt = $pdo->prepare(
                'SELECT id, mi_id_fk, slot_name FROM ref_sku_packaging_choices
                  WHERE sku_id = ? AND slot_name = ? AND is_checked = 1
                    AND (effective_until IS NULL OR effective_until >= CURDATE())
                  LIMIT 1'
            );
            $beforeStmt->execute([$skuId, $slotName]);
            $beforeRow = $beforeStmt->fetch(PDO::FETCH_ASSOC) ?: null;
            $beforeState = $beforeRow
                ? ['mi_id_fk' => $beforeRow['mi_id_fk'], 'slot_name' => $beforeRow['slot_name']]
                : null;

            $pdo->beginTransaction();
            try {
                // Expire any existing active choice for same (sku_id, slot_name)
                $pdo->prepare(
                    'UPDATE ref_sku_packaging_choices
                        SET effective_until = CURDATE()
                      WHERE sku_id = ? AND slot_name = ? AND is_checked = 1
                        AND (effective_until IS NULL OR effective_until >= CURDATE())'
                )->execute([$skuId, $slotName]);

                // Insert new choice
                $ins = $pdo->prepare(
                    'INSERT INTO ref_sku_packaging_choices
                        (sku_id, slot_name, mi_id_fk, qty_per_unit, is_checked, effective_from)
                     VALUES (?, ?, ?, ?, 1, ?)'
                );
                $ins->execute([$skuId, $slotName, $miIdFk, $qtyPerUnit, $today]);
                $newChoiceId = (int) $pdo->lastInsertId();

                log_revision(
                    $pdo, $me, 'ref_sku_packaging_choices', $newChoiceId,
                    $beforeState,
                    ['sku_id' => $skuId, 'slot_name' => $slotName, 'mi_id_fk' => $miIdFk,
                     'effective_from' => $today],
                    'normal',
                    "BOM Review: choix SKU={$skuRow['sku_code']} slot={$slotName} MI={$miRow['mi_id']}"
                );
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }

            $saveMsg = "Choix SKU «{$skuRow['sku_code']}» / slot «{$slotName}» enregistré.";
            // Recompile: use the recipe_id of the SKU (may be NULL for composites/collabs)
            $recipeIdForCompile = (int) ($skuRow['recipe_id'] ?? 0);
            try {
                if ($recipeIdForCompile > 0) {
                    $r = sdc_recompile_recipe_packaging($pdo, $recipeIdForCompile);
                } else {
                    // Composite: recompile the single SKU directly
                    $r = compile_sku_bom_packaging($pdo, [$skuId], false, true);
                }
                sdc_flash_bom_result($saveMsg, $r);
            } catch (Throwable $bomErr) {
                flash_set('ok', $saveMsg . ' · BOM recompilation échouée (la sauvegarde est conservée).');
            }

            // Return to browse tab if the edit came from there
            $postSec2 = post_str('sec') ?? 'drill';
            if ($postSec2 === 'browse') {
                redirect_to('/modules/bom-review.php?sec=browse#browse-sku-' . $skuId);
            }
            redirect_to('/modules/bom-review.php?sec=drill&recipe=' . max(0, $recipeIdForCompile)
                . '#sku-' . $skuId);
        }

        // ── Action: clear_sku_choice ────────────────────────────────────────
        // Expire the active Tier-1 choice for a (sku_id, slot_name) pair.
        // The BOM is recompiled immediately after — the slot falls back to T2/T3.
        if ($action === 'clear_sku_choice') {
            $skuId    = post_int('sku_id')    ?? 0;
            $slotName = post_str('slot_name') ?? '';

            if ($skuId <= 0 || $slotName === '') {
                throw new RuntimeException('sku_id et slot_name sont requis.');
            }

            $slotName = must_be_one_of('slot_name', $slotName, $validSlots);

            // Validate SKU exists
            $skuStmt = $pdo->prepare(
                'SELECT id, sku_code, recipe_id FROM ref_skus WHERE id = ? LIMIT 1'
            );
            $skuStmt->execute([$skuId]);
            $skuRow = $skuStmt->fetch(PDO::FETCH_ASSOC);
            if (!$skuRow) {
                throw new RuntimeException('SKU introuvable.');
            }

            // Find the active choice to expire (may be absent if already cleared)
            $choiceStmt = $pdo->prepare(
                'SELECT id, mi_id_fk, slot_name FROM ref_sku_packaging_choices
                  WHERE sku_id = ? AND slot_name = ? AND is_checked = 1
                    AND (effective_until IS NULL OR effective_until > CURDATE())
                  LIMIT 1'
            );
            $choiceStmt->execute([$skuId, $slotName]);
            $choiceRow = $choiceStmt->fetch(PDO::FETCH_ASSOC);

            if (!$choiceRow) {
                flash_set('ok', "Aucun choix actif trouvé pour «{$skuRow['sku_code']}» / slot «{$slotName}».");
                $postSec3 = post_str('sec') ?? 'drill';
                $backRec3 = post_int('back_recipe') ?? 0;
                if ($postSec3 === 'browse') {
                    redirect_to('/modules/bom-review.php?sec=browse#browse-sku-' . $skuId);
                }
                redirect_to('/modules/bom-review.php?sec=drill&recipe=' . max(0, $backRec3) . '#sku-' . $skuId);
            }

            $choiceId    = (int) $choiceRow['id'];
            $beforeState = ['mi_id_fk' => $choiceRow['mi_id_fk'], 'slot_name' => $choiceRow['slot_name'],
                            'effective_until' => null];
            $today = (new DateTimeImmutable())->format('Y-m-d');

            $pdo->prepare(
                'UPDATE ref_sku_packaging_choices SET effective_until = ? WHERE id = ?'
            )->execute([$today, $choiceId]);

            log_revision(
                $pdo, $me, 'ref_sku_packaging_choices', $choiceId,
                $beforeState,
                ['effective_until' => $today],
                'normal',
                "BOM Review: retrait choix SKU={$skuRow['sku_code']} slot={$slotName}"
            );

            $saveMsg = "Choix Tier-1 «{$slotName}» retiré pour «{$skuRow['sku_code']}».";
            $recipeIdForClear = (int) ($skuRow['recipe_id'] ?? 0);
            try {
                if ($recipeIdForClear > 0) {
                    $r = sdc_recompile_recipe_packaging($pdo, $recipeIdForClear);
                } else {
                    $r = compile_sku_bom_packaging($pdo, [$skuId], false, true);
                }
                sdc_flash_bom_result($saveMsg, $r);
            } catch (Throwable $bomErr) {
                flash_set('ok', $saveMsg . ' · BOM recompilation échouée (le retrait est conservé).');
            }

            $postSec3 = post_str('sec') ?? 'drill';
            if ($postSec3 === 'browse') {
                redirect_to('/modules/bom-review.php?sec=browse#browse-sku-' . $skuId);
            }
            redirect_to('/modules/bom-review.php?sec=drill&recipe=' . max(0, $recipeIdForClear) . '#sku-' . $skuId);
        }

        // ── Action: set_mi_price ────────────────────────────────────────────
        // Set/update the price on a packaging MI.
        // NEVER auto-derived — operator data-entry only.
        if ($action === 'set_mi_price') {
            $miId     = post_int('mi_id')    ?? 0;
            $priceStr = post_decimal('price') ?? null;
            $currency = post_str('currency') ?? 'CHF';

            if ($miId <= 0) {
                throw new RuntimeException('mi_id requis.');
            }
            if ($priceStr === null) {
                throw new RuntimeException('Prix requis.');
            }
            $price = (float) $priceStr;
            if ($price < 0) {
                throw new RuntimeException('Le prix ne peut pas être négatif.');
            }
            $currency = must_be_one_of('currency', $currency, $VALID_CURRENCIES);

            // Validate MI exists
            $miStmt = $pdo->prepare('SELECT id, mi_id, name, price, currency FROM ref_mi WHERE id = ? LIMIT 1');
            $miStmt->execute([$miId]);
            $mi = $miStmt->fetch(PDO::FETCH_ASSOC);
            if (!$mi) {
                throw new RuntimeException('Ingrédient (MI) introuvable.');
            }

            $before = ['price' => $mi['price'], 'currency' => $mi['currency']];
            $after  = ['price' => $price, 'currency' => $currency, 'last_modified_by' => 'web'];

            $pdo->prepare(
                'UPDATE ref_mi SET price = ?, currency = ?, last_modified_by = \'web\' WHERE id = ?'
            )->execute([$price, $currency, $miId]);

            log_revision(
                $pdo, $me, 'ref_mi', $miId, $before, $after, 'normal',
                "BOM Review: prix MI={$mi['mi_id']} ({$mi['name']}) = {$price} {$currency}"
            );

            $saveMsg = "Prix de «{$mi['mi_id']}» mis à jour ({$price} {$currency}).";

            // Recompile ALL recipes that have this MI in their packaging BOM
            $affectedSkuIds = $pdo->prepare(
                'SELECT DISTINCT b.sku_id FROM ref_sku_bom b WHERE b.mi_id = ? AND b.source = \'Packaging\''
            );
            $affectedSkuIds->execute([$miId]);
            $skuIdList = array_map('intval', $affectedSkuIds->fetchAll(PDO::FETCH_COLUMN));

            $totalRecompiled = 0;
            $recompileErrors = 0;
            if (!empty($skuIdList)) {
                // Get distinct recipe_ids
                $recipeIdPlaceholders = implode(',', array_fill(0, count($skuIdList), '?'));
                $recipeStmt = $pdo->prepare(
                    "SELECT DISTINCT recipe_id FROM ref_skus
                      WHERE id IN ({$recipeIdPlaceholders})
                        AND recipe_id IS NOT NULL"
                );
                $recipeStmt->execute($skuIdList);
                $recipeIds = array_map('intval', $recipeStmt->fetchAll(PDO::FETCH_COLUMN));

                foreach ($recipeIds as $rid) {
                    try {
                        $r = sdc_recompile_recipe_packaging($pdo, $rid);
                        $totalRecompiled++;
                        if ($r['errors'] > 0 || $r['parity_violations'] > 0) {
                            $recompileErrors++;
                        }
                    } catch (Throwable $e) {
                        $recompileErrors++;
                    }
                }

                // Also recompile composite SKUs (recipe_id IS NULL) directly
                $compositeStmt = $pdo->prepare(
                    "SELECT id FROM ref_skus WHERE id IN ({$recipeIdPlaceholders}) AND recipe_id IS NULL"
                );
                $compositeStmt->execute($skuIdList);
                $compositeIds = array_map('intval', $compositeStmt->fetchAll(PDO::FETCH_COLUMN));
                if (!empty($compositeIds)) {
                    try {
                        $rc = compile_sku_bom_packaging($pdo, $compositeIds, false, true);
                        $totalRecompiled++;
                        if ($rc['errors'] > 0) $recompileErrors++;
                    } catch (Throwable $e) {
                        $recompileErrors++;
                    }
                }
            }

            if ($recompileErrors > 0) {
                flash_set('ok', $saveMsg . " · BOM recompilé avec {$recompileErrors} erreur(s).");
            } else {
                flash_set('ok', $saveMsg . " · BOM recompilé ({$totalRecompiled} recette(s)).");
            }

            // Redirect back to browse, drill, or feeds depending on call origin
            $postSecMi = post_str('sec') ?? '';
            if ($postSecMi === 'browse') {
                redirect_to('/modules/bom-review.php?sec=browse');
            }
            $backRecipe = post_int('back_recipe') ?? 0;
            if ($backRecipe > 0) {
                redirect_to('/modules/bom-review.php?sec=drill&recipe=' . $backRecipe);
            }
            redirect_to('/modules/bom-review.php?sec=feeds');
        }

        // Unknown action
        flash_set('err', "Action inconnue : " . htmlspecialchars($action));
        redirect_to('/modules/bom-review.php');

    } catch (Throwable $e) {
        flash_set('err', pdo_friendly_error($e, 'BOM Review'));
        redirect_to('/modules/bom-review.php?' . http_build_query([
            'sec'    => post_str('sec')    ?? 'feeds',
            'recipe' => post_int('recipe') ?? 0,
        ]));
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// GET — data loading
// ─────────────────────────────────────────────────────────────────────────────

$sec      = isset($_GET['sec']) ? (string) $_GET['sec'] : 'feeds';
$validSec = ['feeds', 'drill', 'browse'];
if (!in_array($sec, $validSec, true)) $sec = 'feeds';

// Recipe selection for drill-down
$selectedRecipeId = isset($_GET['recipe']) ? (int) $_GET['recipe'] : 0;

$dbError = null;

// ── Always-needed data ────────────────────────────────────────────────────────
$feed1Count        = 0;  // open sku-bom-unresolved RQ rows
$feed2Items        = []; // unpriced packaging MIs that are in BOM
$feed3Skus         = []; // SKUs with anomaly flags
$feedDivergenceItems = []; // WAC-priced but no catalog price — BOM compiler divergence set
$recipes           = []; // all active recipes with sku_prefix (for picker + drill)
$allMis            = []; // packaging MIs (id → {mi_id, name, price, currency}) for edit dropdowns

// Browse tab data
$browseSkus           = []; // all active SKUs with classification/format/BOM cost — browse tab
$browseBomBySkuId     = []; // sku_id → [bom lines] for expanded rows in browse
$browseBindings       = []; // recipe_id → role → mi binding info
$browseChoices        = []; // sku_id → slot_name → choice info
$browseFilterFmt      = isset($_GET['bfmt'])  ? (string) $_GET['bfmt']  : '';
$browseFilterCls      = isset($_GET['bcls'])  ? (string) $_GET['bcls']  : '';
$browseFilterSub      = isset($_GET['bsub'])  ? (string) $_GET['bsub']  : '';

// All valid classification / subtype values (from ENUM — hardcoded is safe: these are schema ENUMs)
$allClassifications = ['Neb', 'Contract'];
$allSubtypes        = ['Core', 'EPH', 'CollabIn', 'CollabOut', 'WhiteLabel', 'Archive'];
// All distinct format display_names (including the pseudo-bucket for NULL format_id)
$browseAllFormats   = [];

try {
    $pdo = maltytask_pdo();

    // ── Feed 1: open sku-bom-unresolved RQ rows ───────────────────────────
    $f1Stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM doc_review_queue
          WHERE type = 'sku-bom-unresolved' AND status IN ('open','in_progress')"
    );
    $f1Stmt->execute();
    $feed1Count = (int) $f1Stmt->fetchColumn();

    // ── Feed 2: unpriced packaging MIs in BOM ─────────────────────────────
    // Join via mi_id INT FK (ref_sku_bom.mi_id → ref_mi.id)
    $f2Stmt = $pdo->query(
        "SELECT
            m.id, m.mi_id, m.name, m.price, m.currency, m.pricing_unit,
            COUNT(DISTINCT b.sku_id) AS sku_count
         FROM ref_mi m
         JOIN ref_sku_bom b ON b.mi_id = m.id AND b.source = 'Packaging'
         WHERE (m.price IS NULL OR m.price = 0)
           AND m.is_active = 1
         GROUP BY m.id, m.mi_id, m.name, m.price, m.currency, m.pricing_unit
         ORDER BY sku_count DESC, m.mi_id ASC"
    );
    $feed2Items = $f2Stmt->fetchAll(PDO::FETCH_ASSOC);

    // ── Feed 3: SKUs with unpriced packaging lines (anomaly) ──────────────
    // Mirrors sku-cost-detail.php anomaly detection: packaging cost = 0 due to NULL prices
    $f3Stmt = $pdo->query(
        "SELECT
            s.id        AS sku_id,
            s.sku_code,
            s.format,
            r.name      AS recipe_name,
            r.id        AS recipe_id,
            ROUND(SUM(b.cost), 3)   AS total_pkg_cost,
            COUNT(b.id) AS total_pkg_lines,
            SUM(CASE WHEN (b.cost IS NULL OR b.cost = 0) THEN 1 ELSE 0 END) AS unpriced_lines
         FROM ref_skus s
         JOIN ref_sku_bom b  ON b.sku_id = s.id AND b.source = 'Packaging'
         LEFT JOIN ref_recipes r ON r.id = s.recipe_id
         WHERE s.is_active = 1
         GROUP BY s.id, s.sku_code, s.format, r.name, r.id
         HAVING unpriced_lines > 0
         ORDER BY unpriced_lines DESC, s.sku_code ASC"
    );
    $feed3Skus = $f3Stmt->fetchAll(PDO::FETCH_ASSOC);

    // ── Feed Divergence: WAC-priced but no catalog price, in packaging BOM ──
    // Signals the gap between the PHP BOM compiler (ref_mi.price) and the
    // canonical v_mi_cost view (which values WAC-priced MIs at cost_chf).
    // Expected to be 0 today; lights up automatically when the set becomes real.
    $fDivStmt = $pdo->query(
        "SELECT
            m.id, m.mi_id, m.name, m.price, m.currency, m.pricing_unit,
            vc.cost_chf AS wac_cost_chf,
            COUNT(DISTINCT b.sku_id) AS sku_count
         FROM ref_mi m
         JOIN ref_sku_bom b ON b.mi_id = m.id AND b.source = 'Packaging'
         JOIN ref_skus s    ON s.id = b.sku_id AND s.is_active = 1
         JOIN v_mi_cost vc  ON vc.mi_id_fk = m.id AND vc.cost_basis = 'wac'
         WHERE (m.price IS NULL OR m.price = 0)
           AND m.is_active = 1
         GROUP BY m.id, m.mi_id, m.name, m.price, m.currency, m.pricing_unit, vc.cost_chf
         ORDER BY sku_count DESC, m.mi_id ASC"
    );
    $feedDivergenceItems = $fDivStmt->fetchAll(PDO::FETCH_ASSOC);

    // ── Recipes for picker ────────────────────────────────────────────────
    $recStmt = $pdo->query(
        "SELECT r.id, r.name, r.sku_prefix,
                COUNT(DISTINCT s.id) AS sku_count
           FROM ref_recipes r
           LEFT JOIN ref_skus s ON s.recipe_id = r.id AND s.is_active = 1
          WHERE r.is_active = 1
            AND r.sku_prefix IS NOT NULL
          GROUP BY r.id, r.name, r.sku_prefix
          ORDER BY r.name"
    );
    $recipes = $recStmt->fetchAll(PDO::FETCH_ASSOC);

    // ── Packaging MIs for edit dropdowns ─────────────────────────────────
    $miStmt = $pdo->query(
        "SELECT m.id, m.mi_id, m.name, m.price, m.currency, m.pricing_unit,
                c.name AS category_name
           FROM ref_mi m
           JOIN ref_mi_categories c ON c.id = m.category_id
          WHERE m.is_active = 1
            AND c.name = 'Packaging'
          ORDER BY m.mi_id"
    );
    foreach ($miStmt->fetchAll(PDO::FETCH_ASSOC) as $mRow) {
        $allMis[(int) $mRow['id']] = $mRow;
    }

    // ── Drill-down data (recipe-specific BOM) ─────────────────────────────
    $drillSkus       = [];  // [sku_id => {sku_code, format, ...}]
    $drillBomBySkuId = [];  // sku_id → [bom lines]
    $drillBindings   = [];  // (recipe_id, role) → mi_id info
    $drillChoices    = [];  // sku_id → slot_name → {mi_id_fk, mi_id_str}
    $drillRecipe     = null;

    if ($sec === 'drill' && $selectedRecipeId > 0) {
        // Recipe info
        $recInfoStmt = $pdo->prepare(
            "SELECT id, name, sku_prefix, uses_branded_scotch
               FROM ref_recipes WHERE id = ? AND is_active = 1 LIMIT 1"
        );
        $recInfoStmt->execute([$selectedRecipeId]);
        $drillRecipe = $recInfoStmt->fetch(PDO::FETCH_ASSOC) ?: null;

        if ($drillRecipe) {
            // All active SKUs for this recipe
            $skuListStmt = $pdo->prepare(
                "SELECT s.id, s.sku_code, s.format, s.hl_per_unit,
                        f.format_code, f.run_type
                   FROM ref_skus s
                   LEFT JOIN ref_packaging_formats f ON f.id = s.format_id
                  WHERE s.recipe_id = ? AND s.is_active = 1
                  ORDER BY s.sku_code"
            );
            $skuListStmt->execute([$selectedRecipeId]);
            foreach ($skuListStmt->fetchAll(PDO::FETCH_ASSOC) as $sk) {
                $drillSkus[(int) $sk['id']] = $sk;
            }

            // BOM rows for all these SKUs
            if (!empty($drillSkus)) {
                $skuIdPlaceholders = implode(',', array_fill(0, count($drillSkus), '?'));
                $bomStmt = $pdo->prepare(
                    "SELECT
                        b.sku_id, b.source, b.category_raw,
                        b.ingredient_raw,
                        b.qty_per_unit, b.ing_unit, b.pricing_unit, b.price, b.currency,
                        b.cost, b.mi_id AS mi_id_int, b.resolution, b.bom_source,
                        b.compiled_at,
                        m.mi_id AS mi_id_str, m.name AS mi_name,
                        m.price AS mi_price_live, m.currency AS mi_currency_live
                     FROM ref_sku_bom b
                     LEFT JOIN ref_mi m ON m.id = b.mi_id
                     WHERE b.sku_id IN ({$skuIdPlaceholders})
                     ORDER BY b.sku_id,
                        CASE b.source WHEN 'Brewing' THEN 1 WHEN 'Packaging' THEN 2 ELSE 3 END,
                        COALESCE(b.cost, 0) DESC"
                );
                $bomStmt->execute(array_keys($drillSkus));
                foreach ($bomStmt->fetchAll(PDO::FETCH_ASSOC) as $bLine) {
                    $drillBomBySkuId[(int) $bLine['sku_id']][] = $bLine;
                }
            }

            // Current recipe bindings
            $bindStmt = $pdo->prepare(
                "SELECT rpb.id, rpb.role, rpb.mi_id_fk, m.mi_id AS mi_id_str, m.name AS mi_name
                   FROM ref_recipe_packaging_bindings rpb
                   JOIN ref_mi m ON m.id = rpb.mi_id_fk
                  WHERE rpb.recipe_id = ?
                    AND (rpb.effective_until IS NULL OR rpb.effective_until > CURDATE())"
            );
            $bindStmt->execute([$selectedRecipeId]);
            foreach ($bindStmt->fetchAll(PDO::FETCH_ASSOC) as $bind) {
                $drillBindings[$bind['role']] = $bind;
            }

            // Per-SKU packaging choices (Tier-1 overrides)
            if (!empty($drillSkus)) {
                $cSkuIds = array_keys($drillSkus);
                $cPlaceholders = implode(',', array_fill(0, count($cSkuIds), '?'));
                $choiceStmt = $pdo->prepare(
                    "SELECT rspc.id, rspc.sku_id, rspc.slot_name, rspc.mi_id_fk,
                            m.mi_id AS mi_id_str, m.name AS mi_name
                       FROM ref_sku_packaging_choices rspc
                       LEFT JOIN ref_mi m ON m.id = rspc.mi_id_fk
                      WHERE rspc.sku_id IN ({$cPlaceholders})
                        AND rspc.is_checked = 1
                        AND (rspc.effective_until IS NULL OR rspc.effective_until > CURDATE())"
                );
                $choiceStmt->execute($cSkuIds);
                foreach ($choiceStmt->fetchAll(PDO::FETCH_ASSOC) as $ch) {
                    $drillChoices[(int) $ch['sku_id']][$ch['slot_name']] = $ch;
                }
            }
        }
    }

    // ── Browse tab data loading ────────────────────────────────────────────
    if ($sec === 'browse') {
        // Full SKU list with classification/format/BOM cost aggregates (LEFT JOIN — keeps composites)
        $browseStmt = $pdo->query(
            "SELECT
                s.id             AS sku_id,
                s.sku_code,
                s.format,
                s.format_id,
                COALESCE(f.display_name, '(format non assigné)') AS format_display,
                r.id             AS recipe_id,
                r.name           AS recipe_name,
                r.classification,
                r.subtype,
                ROUND(SUM(CASE WHEN b.source = 'Packaging' THEN COALESCE(b.cost, 0) ELSE 0 END), 4) AS pkg_cost,
                ROUND(SUM(COALESCE(b.cost, 0)), 4) AS total_bom_cost,
                COUNT(CASE WHEN b.source = 'Packaging' THEN 1 END) AS pkg_lines
             FROM ref_skus s
             LEFT JOIN ref_packaging_formats f ON f.id = s.format_id
             LEFT JOIN ref_recipes r            ON r.id = s.recipe_id
             LEFT JOIN ref_sku_bom b            ON b.sku_id = s.id
             WHERE s.is_active = 1
             GROUP BY s.id, s.sku_code, s.format, s.format_id,
                      f.display_name, r.id, r.name, r.classification, r.subtype
             ORDER BY
                CASE WHEN r.classification IS NULL THEN 'ZZZ' ELSE r.classification END,
                CASE WHEN r.subtype       IS NULL THEN 'ZZZ' ELSE r.subtype        END,
                COALESCE(r.name, s.sku_code),
                s.sku_code"
        );
        $browseSkus = $browseStmt->fetchAll(PDO::FETCH_ASSOC);

        // Collect distinct format labels for the filter chips
        $fmtSeen = [];
        foreach ($browseSkus as $bs) {
            $fmtSeen[$bs['format_display']] = true;
        }
        $browseAllFormats = array_keys($fmtSeen);
        sort($browseAllFormats);

        // Gather all relevant recipe_ids and sku_ids for BOM + bindings + choices
        $allBrowseSkuIds    = array_map(fn($r) => (int) $r['sku_id'], $browseSkus);
        $allBrowseRecipeIds = array_filter(array_unique(
            array_map(fn($r) => $r['recipe_id'] !== null ? (int) $r['recipe_id'] : null, $browseSkus)
        ), fn($v) => $v !== null);

        if (!empty($allBrowseSkuIds)) {
            $skuPh = implode(',', array_fill(0, count($allBrowseSkuIds), '?'));

            // BOM lines for ALL browse SKUs
            $bomBrStmt = $pdo->prepare(
                "SELECT
                    b.sku_id, b.source, b.ingredient_raw,
                    b.qty_per_unit, b.ing_unit, b.price, b.currency, b.cost,
                    b.mi_id AS mi_id_int, b.bom_source,
                    m.mi_id AS mi_id_str, m.name AS mi_name
                 FROM ref_sku_bom b
                 LEFT JOIN ref_mi m ON m.id = b.mi_id
                 WHERE b.sku_id IN ({$skuPh})
                 ORDER BY b.sku_id,
                    CASE b.source WHEN 'Brewing' THEN 1 WHEN 'Packaging' THEN 2 ELSE 3 END,
                    COALESCE(b.cost, 0) DESC"
            );
            $bomBrStmt->execute($allBrowseSkuIds);
            foreach ($bomBrStmt->fetchAll(PDO::FETCH_ASSOC) as $bLine) {
                $browseBomBySkuId[(int) $bLine['sku_id']][] = $bLine;
            }

            // Per-SKU packaging choices
            $chBrStmt = $pdo->prepare(
                "SELECT rspc.sku_id, rspc.slot_name, rspc.mi_id_fk,
                        m.mi_id AS mi_id_str, m.name AS mi_name
                   FROM ref_sku_packaging_choices rspc
                   LEFT JOIN ref_mi m ON m.id = rspc.mi_id_fk
                  WHERE rspc.sku_id IN ({$skuPh})
                    AND rspc.is_checked = 1
                    AND (rspc.effective_until IS NULL OR rspc.effective_until > CURDATE())"
            );
            $chBrStmt->execute($allBrowseSkuIds);
            foreach ($chBrStmt->fetchAll(PDO::FETCH_ASSOC) as $ch) {
                $browseChoices[(int) $ch['sku_id']][$ch['slot_name']] = $ch;
            }
        }

        if (!empty($allBrowseRecipeIds)) {
            $recPh = implode(',', array_fill(0, count($allBrowseRecipeIds), '?'));

            // Recipe bindings (all active recipes present in browse)
            $bindBrStmt = $pdo->prepare(
                "SELECT rpb.recipe_id, rpb.role, rpb.mi_id_fk, m.mi_id AS mi_id_str, m.name AS mi_name
                   FROM ref_recipe_packaging_bindings rpb
                   JOIN ref_mi m ON m.id = rpb.mi_id_fk
                  WHERE rpb.recipe_id IN ({$recPh})
                    AND (rpb.effective_until IS NULL OR rpb.effective_until > CURDATE())"
            );
            $bindBrStmt->execute(array_values($allBrowseRecipeIds));
            foreach ($bindBrStmt->fetchAll(PDO::FETCH_ASSOC) as $bind) {
                $browseBindings[(int) $bind['recipe_id']][$bind['role']] = $bind;
            }
        }
    }

} catch (Throwable $e) {
    $dbError = $e->getMessage();
}

// Helper: determine tier for a slot in the drill-down BOM view
// Returns 't1' | 't2' | 't3' | 'brewing'
function bom_tier(string $source, ?string $bomSource): string
{
    if ($source === 'Brewing') return 'brewing';
    // For packaging lines, tier is not explicitly stored in ref_sku_bom.
    // We infer from the data loaded into $drillChoices / $drillBindings at render time.
    return 'pkg'; // caller resolves further
}

// Helper: format price for display
function fmt_price(?string $price, ?string $currency): string
{
    if ($price === null || (float) $price === 0.0) {
        return '—';
    }
    return number_format((float) $price, 4, '.', "'") . ' ' . ($currency ?? 'CHF');
}

$csrfToken = csrf_token();
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>BOM Review — MaltyTask</title>
    <link rel="stylesheet" href="/css/app.css?v=<?= @filemtime(__DIR__ . '/../../public/css/app.css') ?: time() ?>">
    <link rel="stylesheet" href="/css/bom-review.css?v=<?= @filemtime(__DIR__ . '/../../public/css/bom-review.css') ?: time() ?>">
</head>
<body class="home bom-review">

<?php require __DIR__ . '/../../app/partials/topbar.php'; ?>

<main class="main" id="main">
<div class="br-page">

<?php flash_render(); ?>

<?php if ($dbError): ?>
<div class="db-flash db-flash--err">⚠ Erreur DB : <?= htmlspecialchars($dbError) ?></div>
<?php endif; ?>

<!-- ── Page header ──────────────────────────────────────────────────────── -->
<div class="br-header">
    <h1>Révision BOM</h1>
    <span class="br-subtitle">Revue et correction du Bill of Materials packaging · Admin</span>
</div>

<!-- ── Navigation tabs ──────────────────────────────────────────────────── -->
<div class="br-sku-tabs br-nav-tabs" style="margin-bottom:1.5rem;">
    <a href="/modules/bom-review.php?sec=feeds"
       class="br-sku-tab <?= $sec === 'feeds' ? 'br-sku-tab--active' : '' ?>">
       Défauts détectés
    </a>
    <a href="/modules/bom-review.php?sec=drill<?= $selectedRecipeId > 0 ? '&recipe=' . $selectedRecipeId : '' ?>"
       class="br-sku-tab <?= $sec === 'drill' ? 'br-sku-tab--active' : '' ?>">
       Drill-down recette
    </a>
    <a href="/modules/bom-review.php?sec=browse"
       class="br-sku-tab <?= $sec === 'browse' ? 'br-sku-tab--active' : '' ?>">
       Parcourir les BOM
    </a>
</div>

<?php if ($sec === 'feeds'): ?>
<!-- ══════════════════════════════════════════════════════════════════════════
     FEEDS VIEW — what is broken across all beers
     ══════════════════════════════════════════════════════════════════════════ -->

<!-- ── Feed summary cards ─────────────────────────────────────────────── -->
<div class="br-feeds">
    <div class="br-feed-card">
        <div class="br-feed-card__label">Feed 1 — Slots BOM non résolus</div>
        <div class="br-feed-card__count <?= $feed1Count > 0 ? 'br-feed-card__count--alert' : 'br-feed-card__count--ok' ?>">
            <?= $feed1Count ?>
        </div>
        <div class="br-feed-card__desc">
            Lignes en file d'attente (type&nbsp;<code>sku-bom-unresolved</code>)
            dans <code>doc_review_queue</code>.
        </div>
    </div>

    <div class="br-feed-card">
        <div class="br-feed-card__label">Feed 2 — MI packaging sans prix</div>
        <div class="br-feed-card__count <?= count($feed2Items) > 0 ? 'br-feed-card__count--alert' : 'br-feed-card__count--ok' ?>">
            <?= count($feed2Items) ?>
        </div>
        <div class="br-feed-card__desc">
            Ingrédients packaging référencés dans un BOM mais dont le prix
            est NULL ou 0 dans <code>ref_mi</code>.
        </div>
    </div>

    <div class="br-feed-card">
        <div class="br-feed-card__label">Feed 3 — SKUs avec coût pkg incomplet</div>
        <div class="br-feed-card__count <?= count($feed3Skus) > 0 ? 'br-feed-card__count--alert' : 'br-feed-card__count--ok' ?>">
            <?= count($feed3Skus) ?>
        </div>
        <div class="br-feed-card__desc">
            SKUs actifs dont au moins une ligne packaging a un coût nul/manquant.
            Impact direct sur le COGS.
        </div>
    </div>

    <div class="br-feed-card br-feed-card--divergence">
        <div class="br-feed-card__label">Divergence — WAC sans prix catalogue</div>
        <div class="br-feed-card__count <?= count($feedDivergenceItems) > 0 ? 'br-feed-card__count--alert' : 'br-feed-card__count--ok' ?>">
            <?= count($feedDivergenceItems) ?>
        </div>
        <div class="br-feed-card__desc">
            MI packaging avec un WAC (<code>v_mi_cost</code>) mais sans prix catalogue
            (<code>ref_mi.price</code>). Le compilateur BOM PHP utilise <code>ref_mi.price</code>
            — ces MI sont valorisés à 0 dans le BOM compilé.
        </div>
    </div>
</div>

<!-- ── Feed 2: Unpriced MIs ───────────────────────────────────────────── -->
<div class="br-section-title">Feed 2 — MI packaging sans prix</div>

<?php if (empty($feed2Items)): ?>
<div class="br-empty">
    <span class="br-empty__icon">✓</span>
    Tous les MI packaging référencés dans un BOM ont un prix.
</div>
<?php else: ?>
<p style="font-family:'DM Sans',sans-serif;font-size:0.82rem;color:var(--ink-soft);margin-bottom:0.75rem;">
    Définissez un prix pour chaque MI ci-dessous. Ce prix sera automatiquement
    répercuté dans le BOM (recompile immédiate). Ne déduisez pas le prix —
    saisissez uniquement des valeurs vérifiées.
</p>
<div class="br-table-wrap">
    <table class="br-table">
        <thead>
            <tr>
                <th>Code MI</th>
                <th>Nom</th>
                <th>SKUs affectés</th>
                <th>Unité de prix</th>
                <th>Définir le prix</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($feed2Items as $fi): ?>
            <tr>
                <td><code><?= htmlspecialchars($fi['mi_id']) ?></code></td>
                <td><?= htmlspecialchars($fi['name']) ?></td>
                <td>
                    <span class="br-badge br-badge--unresolved"><?= (int) $fi['sku_count'] ?> SKU<?= (int) $fi['sku_count'] > 1 ? 's' : '' ?></span>
                    <a href="/modules/bom-review.php?sec=feeds#f2-<?= (int) $fi['id'] ?>"
                       style="font-size:0.75rem;color:var(--cold);margin-left:0.4rem;">↓ voir</a>
                </td>
                <td><?= htmlspecialchars((string) ($fi['pricing_unit'] ?? '—')) ?></td>
                <td>
                    <button type="button"
                            class="br-btn br-btn--price br-price-toggle"
                            data-toggle-target="f2-price-<?= (int) $fi['id'] ?>">
                        Définir le prix
                    </button>
                    <div id="f2-price-<?= (int) $fi['id'] ?>" hidden style="margin-top:0.5rem;">
                        <form method="post" action="/modules/bom-review.php">
                            <input type="hidden" name="csrf"    value="<?= htmlspecialchars($csrfToken) ?>">
                            <input type="hidden" name="action"  value="set_mi_price">
                            <input type="hidden" name="mi_id"   value="<?= (int) $fi['id'] ?>">
                            <input type="hidden" name="sec"     value="feeds">
                            <div class="br-price-form">
                                <label style="font-family:'DM Sans',sans-serif;font-size:0.8rem;color:var(--ink-soft);">
                                    Prix
                                    <input type="number" name="price" step="0.0001" min="0"
                                           placeholder="0.0000"
                                           required>
                                </label>
                                <label style="font-family:'DM Sans',sans-serif;font-size:0.8rem;color:var(--ink-soft);">
                                    Devise
                                    <select name="currency">
                                        <option value="CHF">CHF</option>
                                        <option value="EUR">EUR</option>
                                    </select>
                                </label>
                                <button type="submit" class="br-btn">Enregistrer</button>
                            </div>
                        </form>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<!-- ── Feed 3: SKUs with incomplete packaging cost ───────────────────── -->
<div class="br-section-title">Feed 3 — SKUs avec coût packaging incomplet</div>

<?php if (empty($feed3Skus)): ?>
<div class="br-empty">
    <span class="br-empty__icon">✓</span>
    Tous les SKUs actifs ont des lignes packaging correctement valorisées.
</div>
<?php else: ?>
<div class="br-table-wrap">
    <table class="br-table">
        <thead>
            <tr>
                <th>SKU</th>
                <th>Format</th>
                <th>Recette</th>
                <th>Lignes pkg non-pricées</th>
                <th>Coût pkg total</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($feed3Skus as $f3s): ?>
            <tr>
                <td><code><?= htmlspecialchars($f3s['sku_code']) ?></code></td>
                <td><?= htmlspecialchars((string) ($f3s['format'] ?? '—')) ?></td>
                <td><?= htmlspecialchars((string) ($f3s['recipe_name'] ?? '—')) ?></td>
                <td>
                    <span class="br-badge br-badge--nopriced">
                        <?= (int) $f3s['unpriced_lines'] ?> / <?= (int) $f3s['total_pkg_lines'] ?>
                    </span>
                </td>
                <td><?= number_format((float) ($f3s['total_pkg_cost'] ?? 0), 3, '.', "'") ?> CHF</td>
                <td>
                    <?php if ((int) ($f3s['recipe_id'] ?? 0) > 0): ?>
                    <a href="/modules/bom-review.php?sec=drill&recipe=<?= (int) $f3s['recipe_id'] ?>#sku-<?= (int) $f3s['sku_id'] ?>"
                       class="br-btn br-btn--secondary" style="font-size:0.78rem;padding:0.25rem 0.6rem;">
                        ↗ Drill-down
                    </a>
                    <?php else: ?>
                    <span style="color:var(--ink-mute);font-size:0.78rem;">Composite / Collab</span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<!-- ── Divergence panel: WAC but no catalog price ────────────────────── -->
<div class="br-section-title">Divergence — MI avec WAC mais sans prix catalogue</div>

<div class="br-divergence-notice">
    <strong>Contexte :</strong> le compilateur BOM PHP (<code>sku-bom-compile.php</code>)
    valorise les MI packaging depuis <code>ref_mi.price</code> (prix catalogue, devise native).
    La vue canonique <code>v_mi_cost</code> utilise en priorité le WAC
    (<code>cost_basis='wac'</code>). Tant que ces deux sources divergent, le BOM compilé
    sous-évalue ces MI à 0. Résolution : définir le prix catalogue ci-dessous,
    ou attendre la migration du compilateur vers <code>v_mi_cost</code>.
</div>

<?php if (empty($feedDivergenceItems)): ?>
<div class="br-empty">
    <span class="br-empty__icon">✓</span>
    Aucune divergence — tous les MI packaging avec WAC ont également un prix catalogue.
</div>
<?php else: ?>
<p style="font-family:'DM Sans',sans-serif;font-size:0.82rem;color:var(--ink-soft);margin-bottom:0.75rem;">
    Ces MI ont un coût WAC dans <code>v_mi_cost</code> mais aucun prix catalogue dans
    <code>ref_mi</code>. Définissez le prix catalogue pour aligner le compilateur BOM.
</p>
<div class="br-table-wrap">
    <table class="br-table">
        <thead>
            <tr>
                <th>Code MI</th>
                <th>Nom</th>
                <th>SKUs actifs affectés</th>
                <th>WAC actuel</th>
                <th>Unité de prix</th>
                <th>Définir le prix catalogue</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($feedDivergenceItems as $di): ?>
            <tr>
                <td><code><?= htmlspecialchars($di['mi_id']) ?></code></td>
                <td><?= htmlspecialchars($di['name']) ?></td>
                <td>
                    <span class="br-badge br-badge--divergence"><?= (int) $di['sku_count'] ?> SKU<?= (int) $di['sku_count'] > 1 ? 's' : '' ?></span>
                </td>
                <td style="white-space:nowrap;">
                    <?= number_format((float) $di['wac_cost_chf'], 4, '.', "'") ?> CHF
                </td>
                <td><?= htmlspecialchars((string) ($di['pricing_unit'] ?? '—')) ?></td>
                <td>
                    <button type="button"
                            class="br-btn br-btn--price br-price-toggle"
                            data-toggle-target="div-price-<?= (int) $di['id'] ?>">
                        Définir le prix
                    </button>
                    <div id="div-price-<?= (int) $di['id'] ?>" hidden style="margin-top:0.5rem;">
                        <form method="post" action="/modules/bom-review.php">
                            <input type="hidden" name="csrf"    value="<?= htmlspecialchars($csrfToken) ?>">
                            <input type="hidden" name="action"  value="set_mi_price">
                            <input type="hidden" name="mi_id"   value="<?= (int) $di['id'] ?>">
                            <input type="hidden" name="sec"     value="feeds">
                            <div class="br-price-form">
                                <label style="font-family:'DM Sans',sans-serif;font-size:0.8rem;color:var(--ink-soft);">
                                    Prix catalogue
                                    <input type="number" name="price" step="0.0001" min="0"
                                           placeholder="<?= number_format((float) $di['wac_cost_chf'], 4, '.', '') ?>"
                                           required>
                                </label>
                                <label style="font-family:'DM Sans',sans-serif;font-size:0.8rem;color:var(--ink-soft);">
                                    Devise
                                    <select name="currency">
                                        <option value="CHF">CHF</option>
                                        <option value="EUR">EUR</option>
                                    </select>
                                </label>
                                <button type="submit" class="br-btn">Enregistrer</button>
                            </div>
                        </form>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<!-- ── Feed 1 detail (if any open RQ) ───────────────────────────────── -->
<?php if ($feed1Count > 0): ?>
<div class="br-section-title">Feed 1 — Détail file de révision BOM</div>
<?php
    try {
        $pdo2 = maltytask_pdo();
        $rqStmt = $pdo2->query(
            "SELECT queue_id, value, context, dedup_key, created_at
               FROM doc_review_queue
              WHERE type = 'sku-bom-unresolved'
                AND status IN ('open','in_progress')
              ORDER BY created_at DESC
              LIMIT 50"
        );
        $rqRows = $rqStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $rqRows = [];
    }
?>
<div class="br-table-wrap">
    <table class="br-table">
        <thead>
            <tr>
                <th>Valeur</th>
                <th>Clé de dédup</th>
                <th>Créé le</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rqRows as $rqRow): ?>
            <tr>
                <td><?= htmlspecialchars($rqRow['value']) ?></td>
                <td><code style="font-size:0.72rem;"><?= htmlspecialchars($rqRow['dedup_key'] ?? '—') ?></code></td>
                <td style="white-space:nowrap;"><?= htmlspecialchars((string) ($rqRow['created_at'] ?? '')) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?php elseif ($sec === 'drill'): ?>
<!-- ══════════════════════════════════════════════════════════════════════════
     DRILL-DOWN VIEW — per-recipe BOM with provenance and edit controls
     ══════════════════════════════════════════════════════════════════════════ -->

<!-- ── Recipe picker ───────────────────────────────────────────────── -->
<div class="br-picker-bar">
    <label for="recipe-picker">Recette :</label>
    <form method="get" action="/modules/bom-review.php" style="display:contents;">
        <input type="hidden" name="sec" value="drill">
        <select name="recipe" id="recipe-picker" onchange="this.form.submit()">
            <option value="0">— choisir une recette —</option>
            <?php foreach ($recipes as $rec): ?>
            <option value="<?= (int) $rec['id'] ?>"
                <?= (int) $rec['id'] === $selectedRecipeId ? 'selected' : '' ?>>
                <?= htmlspecialchars($rec['name']) ?>
                (<?= htmlspecialchars($rec['sku_prefix']) ?>)
                · <?= (int) $rec['sku_count'] ?> SKU<?= (int) $rec['sku_count'] > 1 ? 's' : '' ?>
            </option>
            <?php endforeach; ?>
        </select>
    </form>
</div>

<?php if ($selectedRecipeId <= 0 || !$drillRecipe): ?>
<div class="br-empty">
    <span class="br-empty__icon">☰</span>
    Sélectionne une recette pour afficher son BOM packaging.
</div>

<?php else: ?>

<!-- ── Recipe bindings summary ──────────────────────────────────────── -->
<div class="br-section-title">Liaisons recette — <?= htmlspecialchars($drillRecipe['name']) ?></div>
<p class="br-provenance-note">
    Les liaisons recette (Tier-2) s'appliquent à TOUS les SKUs de cette recette.
    Pour corriger un seul SKU, utilisez le choix SKU (Tier-1) dans le détail ci-dessous.
</p>

<div class="br-table-wrap" style="margin-bottom:1rem;">
    <table class="br-table">
        <thead>
            <tr>
                <th>Rôle</th>
                <th>MI lié (Tier-2)</th>
                <th>Prix actuel</th>
                <th>Modifier</th>
            </tr>
        </thead>
        <tbody>
        <?php
        $roleLabels = [
            'label'     => 'Étiquette (label)',
            'can'       => 'Canette (can)',
            'sticker'   => 'Autocollant (sticker)',
            'holder'    => 'Holder 4-pack',
            'outer_tray'=> 'Plateau extérieur',
            'scotch'    => 'Scotch',
        ];
        foreach ($VALID_BINDING_ROLES as $bRole):
            $bound = $drillBindings[$bRole] ?? null;
        ?>
            <tr>
                <td><?= htmlspecialchars($roleLabels[$bRole] ?? $bRole) ?></td>
                <td>
                    <?php if ($bound): ?>
                        <code><?= htmlspecialchars($bound['mi_id_str']) ?></code>
                        <span style="color:var(--ink-mute);font-size:0.8rem;">
                            — <?= htmlspecialchars($bound['mi_name']) ?>
                        </span>
                    <?php else: ?>
                        <span style="color:var(--ink-mute);">Non lié</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($bound && isset($allMis[(int) $bound['mi_id_fk']])): ?>
                        <?= htmlspecialchars(fmt_price(
                            $allMis[(int) $bound['mi_id_fk']]['price'] ?? null,
                            $allMis[(int) $bound['mi_id_fk']]['currency'] ?? null
                        )) ?>
                    <?php else: ?>
                        —
                    <?php endif; ?>
                </td>
                <td>
                    <button type="button"
                            class="br-btn br-btn--secondary br-price-toggle"
                            style="font-size:0.78rem;padding:0.25rem 0.6rem;"
                            data-toggle-target="binding-form-<?= htmlspecialchars($bRole) ?>">
                        Modifier liaison
                    </button>
                    <div id="binding-form-<?= htmlspecialchars($bRole) ?>" hidden style="margin-top:0.5rem;">
                        <form method="post" action="/modules/bom-review.php">
                            <input type="hidden" name="csrf"      value="<?= htmlspecialchars($csrfToken) ?>">
                            <input type="hidden" name="action"    value="set_recipe_binding">
                            <input type="hidden" name="recipe_id" value="<?= (int) $selectedRecipeId ?>">
                            <input type="hidden" name="role"      value="<?= htmlspecialchars($bRole) ?>">
                            <input type="hidden" name="sec"       value="drill">
                            <div class="br-edit-row__fields">
                                <label>
                                    Ingrédient (MI Packaging)
                                    <select name="mi_id_fk" required>
                                        <option value="">— choisir —</option>
                                        <?php foreach ($allMis as $mId => $mData): ?>
                                        <option value="<?= $mId ?>"
                                            <?= ($bound && (int) $bound['mi_id_fk'] === $mId) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($mData['mi_id']) ?>
                                            — <?= htmlspecialchars($mData['name']) ?>
                                            <?= $mData['price'] ? '(' . htmlspecialchars((string) $mData['price']) . ' ' . htmlspecialchars((string) $mData['currency']) . ')' : '(sans prix)' ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                <button type="submit" class="br-btn">Enregistrer + Recompiler</button>
                            </div>
                        </form>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- ── SKU tabs ─────────────────────────────────────────────────────── -->
<div class="br-section-title">BOM par SKU</div>

<?php if (empty($drillSkus)): ?>
<div class="br-empty">
    <span class="br-empty__icon">—</span>
    Aucun SKU actif pour cette recette.
</div>
<?php else: ?>
<div class="br-sku-tabs">
<?php foreach ($drillSkus as $dSkuId => $dSku): ?>
    <?php
        // Check if this SKU has any issue (unpriced lines or choices)
        $hasIssue = false;
        foreach (($drillBomBySkuId[$dSkuId] ?? []) as $bl) {
            if ($bl['source'] === 'Packaging' && (empty($bl['cost']) && (float)($bl['cost']??0) === 0.0)) {
                $hasIssue = true;
                break;
            }
        }
    ?>
    <a href="#sku-<?= $dSkuId ?>"
       class="br-sku-tab<?= $hasIssue ? ' br-sku-tab--has-issue' : '' ?>"
       data-sku-id="<?= $dSkuId ?>">
        <?= htmlspecialchars($dSku['sku_code']) ?>
        <?= $hasIssue ? ' ⚠' : '' ?>
    </a>
<?php endforeach; ?>
</div>

<?php foreach ($drillSkus as $dSkuId => $dSku): ?>
<div class="br-sku-panel" data-sku-id="<?= $dSkuId ?>" id="sku-<?= $dSkuId ?>" hidden>

    <div style="display:flex;align-items:center;gap:0.75rem;margin-bottom:0.75rem;flex-wrap:wrap;">
        <strong style="font-family:'JetBrains Mono',monospace;font-size:0.9rem;">
            <?= htmlspecialchars($dSku['sku_code']) ?>
        </strong>
        <span class="br-badge br-badge--readonly">
            <?= htmlspecialchars((string) ($dSku['format'] ?? $dSku['format_code'] ?? '?')) ?>
        </span>
        <?php if (($dSku['run_type'] ?? '') === 'can' || ($dSku['run_type'] ?? '') === 'can33'): ?>
            <span class="br-badge br-badge--pkg">Can</span>
        <?php elseif (($dSku['run_type'] ?? '') === 'bot'): ?>
            <span class="br-badge br-badge--pkg">Bot</span>
        <?php elseif (($dSku['run_type'] ?? '') === 'keg'): ?>
            <span class="br-badge br-badge--pkg">Keg</span>
        <?php elseif (($dSku['run_type'] ?? '') === 'cuv'): ?>
            <span class="br-badge br-badge--pkg">Cuv</span>
        <?php endif; ?>
        <?php if (!empty($dSku['hl_per_unit'])): ?>
            <span style="font-family:'DM Sans',sans-serif;font-size:0.78rem;color:var(--ink-mute);">
                <?= number_format((float) $dSku['hl_per_unit'], 4, '.', "'") ?> HL/u
            </span>
        <?php endif; ?>
    </div>

    <!-- Per-SKU Tier-1 choices summary -->
    <?php if (!empty($drillChoices[$dSkuId])): ?>
    <div style="background:var(--bg);border:1px solid var(--hop);border-radius:4px;padding:0.5rem 0.75rem;margin-bottom:0.75rem;font-family:'DM Sans',sans-serif;font-size:0.8rem;">
        <strong style="color:var(--hop);">Choix Tier-1 actifs (remplacent la liaison recette) :</strong>
        <?php foreach ($drillChoices[$dSkuId] as $slot => $ch): ?>
            <span style="margin-left:0.5rem;">
                <code><?= htmlspecialchars($slot) ?></code> →
                <code><?= htmlspecialchars($ch['mi_id_str'] ?? '?') ?></code>
            </span>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- BOM lines table -->
    <?php $bomLines = $drillBomBySkuId[$dSkuId] ?? []; ?>
    <?php if (empty($bomLines)): ?>
        <div class="br-empty" style="padding:1rem 0;">
            <span>Aucune ligne BOM pour ce SKU.</span>
        </div>
    <?php else: ?>
    <div class="br-table-wrap">
        <table class="br-table">
            <thead>
                <tr>
                    <th>Source</th>
                    <th>Provenance (Tier)</th>
                    <th>Code MI</th>
                    <th>Nom</th>
                    <th>Qté/u</th>
                    <th>Prix</th>
                    <th>Coût</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($bomLines as $bl): ?>
                <?php
                    $isBrewing = $bl['source'] === 'Brewing';
                    $miIdInt   = (int) ($bl['mi_id_int'] ?? 0);

                    // Determine Tier for packaging rows
                    $t1SlotName = null; // slot_name of the active T1 choice (when $tierBadge==='t1')
                    if ($isBrewing) {
                        $tier     = 'brewing';
                        $tierBadge = 'brewing';
                        $tierLabel = 'Brassage (obs.)';
                    } else {
                        // Check Tier-1 choices; capture the slot_name for the "retirer" button.
                        $slotKey = $bl['ingredient_raw'] ?? '';
                        if (isset($drillChoices[$dSkuId])) {
                            $foundInChoice = false;
                            foreach ($drillChoices[$dSkuId] as $cs => $cv) {
                                if ($cv['mi_id_fk'] == $miIdInt) {
                                    $foundInChoice = true;
                                    $t1SlotName = $cs;
                                    break;
                                }
                            }
                            if ($foundInChoice) {
                                $tierBadge = 't1';
                                $tierLabel = 'Choix SKU (T1)';
                            } else {
                                // Check binding
                                $foundInBinding = false;
                                foreach ($drillBindings as $role => $bnd) {
                                    if ((int) $bnd['mi_id_fk'] === $miIdInt) {
                                        $foundInBinding = true;
                                        break;
                                    }
                                }
                                if ($foundInBinding) {
                                    $tierBadge = 't2';
                                    $tierLabel = 'Liaison recette (T2)';
                                } else {
                                    $tierBadge = 't3';
                                    $tierLabel = 'Défaut template (T3)';
                                }
                            }
                        } else {
                            $tierBadge = 't3';
                            $tierLabel = 'Défaut template (T3)';
                        }
                    }

                    $hasCost    = !empty($bl['cost']) || (float) ($bl['cost'] ?? 0) > 0;
                    $costFmt    = $hasCost
                        ? number_format((float) $bl['cost'], 4, '.', "'") . ' CHF'
                        : '<span class="br-badge br-badge--nopriced">manquant</span>';
                    $priceFmt   = fmt_price($bl['price'] ?? null, $bl['currency'] ?? null);
                ?>
                <tr>
                    <td>
                        <span class="br-badge br-badge--<?= $isBrewing ? 'brewing' : 'pkg' ?>">
                            <?= $isBrewing ? 'Brassage' : 'Packaging' ?>
                        </span>
                    </td>
                    <td>
                        <span class="br-badge br-badge--<?= htmlspecialchars($tierBadge) ?>">
                            <?= htmlspecialchars($tierLabel) ?>
                        </span>
                    </td>
                    <td>
                        <code style="font-size:0.78rem;">
                            <?= htmlspecialchars($bl['mi_id_str'] ?? $bl['ingredient_raw'] ?? '?') ?>
                        </code>
                    </td>
                    <td><?= htmlspecialchars($bl['mi_name'] ?? $bl['ingredient_raw'] ?? '?') ?></td>
                    <td style="white-space:nowrap;">
                        <?= htmlspecialchars(number_format((float) ($bl['qty_per_unit'] ?? 0), 4, '.', "'")) ?>
                        <?= htmlspecialchars($bl['ing_unit'] ?? '') ?>
                    </td>
                    <td style="white-space:nowrap;"><?= $priceFmt ?></td>
                    <td style="white-space:nowrap;"><?= $costFmt ?></td>
                    <td>
                        <?php if ($isBrewing): ?>
                            <span class="br-readonly-note" title="Les données brassage observées sont canoniques en v1. Modifier uniquement la source (bd_brewing_ingredients_parsed).">
                                🔒 Lecture seule
                            </span>
                        <?php else: ?>
                            <!-- Packaging: offer Tier-1 SKU choice if not already T1, or price edit -->
                            <?php if ($tierBadge !== 't1'): ?>
                            <button type="button"
                                    class="br-btn br-btn--secondary br-price-toggle"
                                    style="font-size:0.75rem;padding:0.2rem 0.5rem;"
                                    data-toggle-target="sku-choice-<?= $dSkuId ?>-<?= $miIdInt ?>">
                                Choix SKU (T1)
                            </button>
                            <div id="sku-choice-<?= $dSkuId ?>-<?= $miIdInt ?>" hidden style="margin-top:0.4rem;">
                                <form method="post" action="/modules/bom-review.php">
                                    <input type="hidden" name="csrf"      value="<?= htmlspecialchars($csrfToken) ?>">
                                    <input type="hidden" name="action"    value="set_sku_choice">
                                    <input type="hidden" name="sku_id"    value="<?= $dSkuId ?>">
                                    <input type="hidden" name="sec"       value="drill">
                                    <?php
                                        // Determine slot_name from ingredient_raw: packaging MIs
                                        // ingredient_raw holds mi_id (code), not slot_name.
                                        // We need to pass a valid slot_name. The slot can be inferred
                                        // from the existing Tier-2 binding role or from pattern matching.
                                        // Best approach: offer a slot selector (the admin knows the slot).
                                    ?>
                                    <div class="br-edit-row__fields">
                                        <label>
                                            Slot
                                            <select name="slot_name" required>
                                                <option value="">— slot —</option>
                                                <?php foreach ($validSlots as $vs): ?>
                                                <option value="<?= htmlspecialchars($vs) ?>"><?= htmlspecialchars($vs) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </label>
                                        <label>
                                            MI de remplacement
                                            <select name="mi_id_fk" required>
                                                <option value="">— MI —</option>
                                                <?php foreach ($allMis as $mId => $mData): ?>
                                                <option value="<?= $mId ?>"
                                                    <?= $mId === $miIdInt ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($mData['mi_id']) ?>
                                                    — <?= htmlspecialchars($mData['name']) ?>
                                                    <?= $mData['price'] ? '(' . htmlspecialchars((string) $mData['price']) . ')' : '' ?>
                                                </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </label>
                                        <button type="submit" class="br-btn">Enregistrer + Recompiler</button>
                                    </div>
                                </form>
                            </div>
                            <?php endif; ?>

                            <?php if ($tierBadge === 't1' && $t1SlotName !== null): ?>
                            <form method="post" action="/modules/bom-review.php"
                                  class="br-clear-choice-form"
                                  onsubmit="return confirm('Retirer le choix Tier-1 pour le slot «<?= htmlspecialchars($t1SlotName, ENT_QUOTES) ?>» ?\nLe BOM sera recompilé immédiatement.');">
                                <input type="hidden" name="csrf"        value="<?= htmlspecialchars($csrfToken) ?>">
                                <input type="hidden" name="action"      value="clear_sku_choice">
                                <input type="hidden" name="sku_id"      value="<?= $dSkuId ?>">
                                <input type="hidden" name="slot_name"   value="<?= htmlspecialchars($t1SlotName) ?>">
                                <input type="hidden" name="sec"         value="drill">
                                <input type="hidden" name="back_recipe" value="<?= $selectedRecipeId ?>">
                                <button type="submit" class="br-btn br-btn--clear-choice">
                                    retirer
                                </button>
                            </form>
                            <?php endif; ?>

                            <?php if (!$hasCost && $miIdInt > 0): ?>
                            <button type="button"
                                    class="br-btn br-btn--price br-price-toggle"
                                    style="font-size:0.75rem;padding:0.2rem 0.5rem;margin-left:0.3rem;"
                                    data-toggle-target="price-set-<?= $dSkuId ?>-<?= $miIdInt ?>">
                                Prix MI
                            </button>
                            <div id="price-set-<?= $dSkuId ?>-<?= $miIdInt ?>" hidden style="margin-top:0.4rem;">
                                <form method="post" action="/modules/bom-review.php">
                                    <input type="hidden" name="csrf"        value="<?= htmlspecialchars($csrfToken) ?>">
                                    <input type="hidden" name="action"      value="set_mi_price">
                                    <input type="hidden" name="mi_id"       value="<?= $miIdInt ?>">
                                    <input type="hidden" name="back_recipe" value="<?= $selectedRecipeId ?>">
                                    <div class="br-price-form">
                                        <input type="number" name="price" step="0.0001" min="0"
                                               placeholder="0.0000" required>
                                        <select name="currency">
                                            <option value="CHF">CHF</option>
                                            <option value="EUR">EUR</option>
                                        </select>
                                        <button type="submit" class="br-btn">Enregistrer</button>
                                    </div>
                                </form>
                            </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

</div><!-- /.br-sku-panel -->
<?php endforeach; ?>

<?php endif; // empty($drillSkus) ?>
<?php endif; // $selectedRecipeId && $drillRecipe ?>

<?php endif; // $sec === 'drill' ?>

<?php if ($sec === 'browse'): ?>
<!-- ══════════════════════════════════════════════════════════════════════════
     BROWSE VIEW — all active SKUs with full packaging BOM, filterable
     ══════════════════════════════════════════════════════════════════════════ -->

<?php
// ── Build the grouped structure ─────────────────────────────────────────────
// Groups: [classification|'(composite)'] → [subtype|'(composite)'] → [sku_id → row]
// Classification + subtype buckets are always shown even when empty.
// Applied filter: filter browseSkus BEFORE grouping but show ALL group headers.

function browse_matches_filter(array $row, string $filterFmt, string $filterCls, string $filterSub): bool
{
    if ($filterCls !== '' && $filterSub !== '') {
        $cls = $row['classification'] ?? '(composite)';
        $sub = $row['subtype'] ?? '(composite)';
        if ($cls !== $filterCls || $sub !== $filterSub) return false;
    } elseif ($filterCls !== '') {
        if (($row['classification'] ?? '(composite)') !== $filterCls) return false;
    } elseif ($filterSub !== '') {
        if (($row['subtype'] ?? '(composite)') !== $filterSub) return false;
    }
    if ($filterFmt !== '') {
        if (($row['format_display'] ?? '(format non assigné)') !== $filterFmt) return false;
    }
    return true;
}

$filteredBrowseSkus = array_filter(
    $browseSkus,
    fn($r) => browse_matches_filter($r, $browseFilterFmt, $browseFilterCls, $browseFilterSub)
);

// Count for display
$browseTotal    = count($browseSkus);
$browseFiltered = count($filteredBrowseSkus);

// Human labels for classification and subtype
$clsLabels = [
    'Neb'      => 'Nebuleuse',
    'Contract' => 'Contract',
];
$subLabels = [
    'Core'       => 'Core',
    'EPH'        => 'Éphémère (EPH)',
    'CollabIn'   => 'Collaboration — achetée',
    'CollabOut'  => 'Collaboration — vendue',
    'WhiteLabel' => 'White Label',
    'Archive'    => 'Archive',
];

?>

<!-- ── Closed-month COGS warning ───────────────────────────────────────── -->
<div class="br-browse-cogs-warn">
    <strong>Attention :</strong> toute modification va-et-vient BOM est appliquée immédiatement.
    Elle n'affecte pas rétroactivement les mois COGS déjà clôturés, mais prend effet
    sur les runs futurs. Utilisez avec discernement.
</div>

<!-- ── Filter chips ────────────────────────────────────────────────────── -->
<form method="get" action="/modules/bom-review.php" id="browse-filter-form">
    <input type="hidden" name="sec" value="browse">
    <div class="br-browse-filters">
        <div class="br-browse-filter-group">
            <span class="br-browse-filter-label">Classification :</span>
            <div class="br-browse-chips">
                <a href="<?= '/modules/bom-review.php?sec=browse'
                    . ($browseFilterFmt !== '' ? '&bfmt=' . urlencode($browseFilterFmt) : '')
                    . ($browseFilterSub !== '' ? '&bsub=' . urlencode($browseFilterSub) : '') ?>"
                   class="br-chip <?= $browseFilterCls === '' ? 'br-chip--active' : '' ?>">
                   Toutes
                </a>
                <?php foreach ($allClassifications as $cls): ?>
                <a href="<?= '/modules/bom-review.php?sec=browse&bcls=' . urlencode($cls)
                    . ($browseFilterFmt !== '' ? '&bfmt=' . urlencode($browseFilterFmt) : '')
                    . ($browseFilterSub !== '' ? '&bsub=' . urlencode($browseFilterSub) : '') ?>"
                   class="br-chip <?= $browseFilterCls === $cls ? 'br-chip--active' : '' ?>">
                   <?= htmlspecialchars($clsLabels[$cls] ?? $cls) ?>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="br-browse-filter-group">
            <span class="br-browse-filter-label">Sous-type :</span>
            <div class="br-browse-chips">
                <a href="<?= '/modules/bom-review.php?sec=browse'
                    . ($browseFilterFmt !== '' ? '&bfmt=' . urlencode($browseFilterFmt) : '')
                    . ($browseFilterCls !== '' ? '&bcls=' . urlencode($browseFilterCls) : '') ?>"
                   class="br-chip <?= $browseFilterSub === '' ? 'br-chip--active' : '' ?>">
                   Tous
                </a>
                <?php foreach ($allSubtypes as $sub): ?>
                <a href="<?= '/modules/bom-review.php?sec=browse&bsub=' . urlencode($sub)
                    . ($browseFilterFmt !== '' ? '&bfmt=' . urlencode($browseFilterFmt) : '')
                    . ($browseFilterCls !== '' ? '&bcls=' . urlencode($browseFilterCls) : '') ?>"
                   class="br-chip <?= $browseFilterSub === $sub ? 'br-chip--active' : '' ?>">
                   <?= htmlspecialchars($subLabels[$sub] ?? $sub) ?>
                </a>
                <?php endforeach; ?>
                <a href="<?= '/modules/bom-review.php?sec=browse&bsub=(composite)'
                    . ($browseFilterFmt !== '' ? '&bfmt=' . urlencode($browseFilterFmt) : '')
                    . ($browseFilterCls !== '' ? '&bcls=' . urlencode($browseFilterCls) : '') ?>"
                   class="br-chip <?= $browseFilterSub === '(composite)' ? 'br-chip--active' : '' ?>">
                   Composite / multi-recette
                </a>
            </div>
        </div>
        <div class="br-browse-filter-group">
            <span class="br-browse-filter-label">Format :</span>
            <div class="br-browse-chips">
                <a href="<?= '/modules/bom-review.php?sec=browse'
                    . ($browseFilterCls !== '' ? '&bcls=' . urlencode($browseFilterCls) : '')
                    . ($browseFilterSub !== '' ? '&bsub=' . urlencode($browseFilterSub) : '') ?>"
                   class="br-chip <?= $browseFilterFmt === '' ? 'br-chip--active' : '' ?>">
                   Tous
                </a>
                <?php foreach ($browseAllFormats as $fmt): ?>
                <a href="<?= '/modules/bom-review.php?sec=browse&bfmt=' . urlencode($fmt)
                    . ($browseFilterCls !== '' ? '&bcls=' . urlencode($browseFilterCls) : '')
                    . ($browseFilterSub !== '' ? '&bsub=' . urlencode($browseFilterSub) : '') ?>"
                   class="br-chip <?= $browseFilterFmt === $fmt ? 'br-chip--active' : '' ?>">
                   <?= htmlspecialchars($fmt) ?>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</form>

<div class="br-browse-count">
    <?php if ($browseFiltered === $browseTotal): ?>
        <?= $browseTotal ?> SKUs actifs affichés
    <?php else: ?>
        <?= $browseFiltered ?> / <?= $browseTotal ?> SKUs actifs (filtre actif)
        — <a href="/modules/bom-review.php?sec=browse" style="color:var(--cold);">Réinitialiser</a>
    <?php endif; ?>
</div>

<?php if (empty($filteredBrowseSkus)): ?>
<div class="br-empty">
    <span class="br-empty__icon">—</span>
    Aucun SKU ne correspond aux filtres sélectionnés.
</div>
<?php else: ?>

<?php
// ── Render groups ────────────────────────────────────────────────────────────
// Build groups from filtered SKUs; but also show ALL cls/subtype bucket headers
// (so empty buckets are visible — per spec). We render only filtered SKUs inside
// the buckets, but draw all declared cls+subtype headings.

// Build the grouped map from filtered set
$groupedBrowse = [];
foreach ($filteredBrowseSkus as $bs) {
    $cls = $bs['classification'] ?? '(composite)';
    $sub = $bs['subtype']        ?? '(composite)';
    $groupedBrowse[$cls][$sub][] = $bs;
}

// All classification groups to render (Neb, Contract, composite)
$renderCls = $allClassifications;
// Add composite if any composites in filteredBrowseSkus
foreach ($filteredBrowseSkus as $bs) {
    if ($bs['recipe_id'] === null) { $renderCls[] = '(composite)'; break; }
}
$renderCls = array_unique($renderCls);

// Subtype groups within each classification
$subtypesByClass = [
    'Neb'          => $allSubtypes,
    'Contract'     => $allSubtypes,
    '(composite)'  => ['(composite)'],
];
?>

<?php foreach ($renderCls as $cls):
    $clsRows = $groupedBrowse[$cls] ?? [];
    $clsTotal = 0;
    foreach ($clsRows as $subRows) $clsTotal += count($subRows);
?>
<div class="br-browse-cls-group" data-cls="<?= htmlspecialchars($cls) ?>">
<div class="br-section-title br-browse-cls-title">
    <?= htmlspecialchars($clsLabels[$cls] ?? ($cls === '(composite)' ? 'Composite / multi-recette' : $cls)) ?>
    <span class="br-browse-cls-count">(<?= $clsTotal ?> SKU<?= $clsTotal !== 1 ? 's' : '' ?>)</span>
</div>

<?php
    $subtypes = $subtypesByClass[$cls] ?? $allSubtypes;
    foreach ($subtypes as $sub):
        $subRows = $groupedBrowse[$cls][$sub] ?? [];
        $subLabel = ($sub === '(composite)')
            ? 'Composite / multi-recette'
            : ($subLabels[$sub] ?? $sub);
?>
<div class="br-browse-sub-group" data-sub="<?= htmlspecialchars($sub) ?>">
<div class="br-browse-sub-title">
    <?= htmlspecialchars($subLabel) ?>
    <span class="br-browse-sub-count">(<?= count($subRows) ?>)</span>
</div>

<?php if (empty($subRows)): ?>
<div class="br-browse-sub-empty">— aucun SKU actif dans ce groupe —</div>
<?php else: ?>
<div class="br-browse-sku-grid">
<?php foreach ($subRows as $bs):
    $bSkuId    = (int) $bs['sku_id'];
    $bLines    = $browseBomBySkuId[$bSkuId] ?? [];
    $bChoices  = $browseChoices[$bSkuId]    ?? [];
    $bBindings = $browseBindings[(int)($bs['recipe_id'] ?? 0)] ?? [];
    $pkgCost   = (float) ($bs['pkg_cost'] ?? 0);
    $totalCost = (float) ($bs['total_bom_cost'] ?? 0);
    $hasBindings = !empty($bBindings);
?>
<div class="br-browse-sku-card" id="browse-sku-<?= $bSkuId ?>">
    <div class="br-browse-sku-header">
        <div class="br-browse-sku-meta">
            <span class="br-browse-sku-code"><?= htmlspecialchars($bs['sku_code']) ?></span>
            <span class="br-browse-sku-fmt"><?= htmlspecialchars($bs['format_display']) ?></span>
            <?php if ($bs['recipe_name']): ?>
            <span class="br-browse-sku-recipe"><?= htmlspecialchars($bs['recipe_name']) ?></span>
            <?php endif; ?>
        </div>
        <div class="br-browse-sku-costs">
            <span class="br-browse-cost-pkg">
                Pkg <strong><?= number_format($pkgCost, 4, '.', "'") ?></strong> CHF
            </span>
            <span class="br-browse-cost-total">
                Total BOM <strong><?= number_format($totalCost, 4, '.', "'") ?></strong> CHF
            </span>
        </div>
        <button type="button"
                class="br-btn br-btn--secondary br-browse-expand-btn"
                data-target="browse-bom-<?= $bSkuId ?>"
                aria-expanded="false">
            ▶ Voir BOM
        </button>
    </div>

    <div class="br-browse-bom-panel" id="browse-bom-<?= $bSkuId ?>" hidden>

        <?php if (!empty($bChoices)): ?>
        <div class="br-browse-choices-summary">
            <strong>Choix Tier-1 actifs :</strong>
            <?php foreach ($bChoices as $slot => $ch): ?>
            <span><code><?= htmlspecialchars($slot) ?></code> → <code><?= htmlspecialchars($ch['mi_id_str'] ?? '?') ?></code></span>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if (empty($bLines)): ?>
        <div class="br-empty" style="padding:0.75rem 0;">Aucune ligne BOM pour ce SKU.</div>
        <?php else: ?>
        <div class="br-table-wrap" style="margin-bottom:0.75rem;">
        <table class="br-table br-browse-bom-table">
            <thead>
                <tr>
                    <th>Source</th>
                    <th>Tier</th>
                    <th>Code MI</th>
                    <th>Nom</th>
                    <th>Qté/u</th>
                    <th>Prix</th>
                    <th>Coût</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($bLines as $bl):
                $isBrewing = $bl['source'] === 'Brewing';
                $miIdInt   = (int) ($bl['mi_id_int'] ?? 0);

                // Determine tier; capture $t1SlotName for the "retirer" button.
                $t1SlotName = null;
                if ($isBrewing) {
                    $tierBadge = 'brewing'; $tierLabel = 'Brassage (obs.)';
                } else {
                    $foundInChoice = false;
                    foreach ($bChoices as $cs => $cv) {
                        if ((int) $cv['mi_id_fk'] === $miIdInt) {
                            $foundInChoice = true;
                            $t1SlotName = $cs;
                            break;
                        }
                    }
                    if ($foundInChoice) {
                        $tierBadge = 't1'; $tierLabel = 'Choix SKU (T1)';
                    } else {
                        $foundInBinding = false;
                        foreach ($bBindings as $role => $bnd) {
                            if ((int) $bnd['mi_id_fk'] === $miIdInt) { $foundInBinding = true; break; }
                        }
                        $tierBadge = $foundInBinding ? 't2' : 't3';
                        $tierLabel = $foundInBinding ? 'Liaison recette (T2)' : 'Défaut template (T3)';
                    }
                }

                $hasCost  = (float) ($bl['cost'] ?? 0) > 0;
                $costFmt  = $hasCost
                    ? number_format((float) $bl['cost'], 4, '.', "'") . ' CHF'
                    : '<span class="br-badge br-badge--nopriced">manquant</span>';
                $priceFmt = fmt_price($bl['price'] ?? null, $bl['currency'] ?? null);
            ?>
            <tr>
                <td>
                    <span class="br-badge br-badge--<?= $isBrewing ? 'brewing' : 'pkg' ?>">
                        <?= $isBrewing ? 'Brassage' : 'Packaging' ?>
                    </span>
                </td>
                <td>
                    <span class="br-badge br-badge--<?= htmlspecialchars($tierBadge) ?>">
                        <?= htmlspecialchars($tierLabel) ?>
                    </span>
                </td>
                <td><code style="font-size:0.78rem;"><?= htmlspecialchars($bl['mi_id_str'] ?? $bl['ingredient_raw'] ?? '?') ?></code></td>
                <td><?= htmlspecialchars($bl['mi_name'] ?? $bl['ingredient_raw'] ?? '?') ?></td>
                <td style="white-space:nowrap;">
                    <?= htmlspecialchars(number_format((float)($bl['qty_per_unit']??0), 4, '.', "'")) ?>
                    <?= htmlspecialchars($bl['ing_unit'] ?? '') ?>
                </td>
                <td style="white-space:nowrap;"><?= $priceFmt ?></td>
                <td style="white-space:nowrap;"><?= $costFmt ?></td>
                <td>
                    <?php if ($isBrewing): ?>
                        <span class="br-readonly-note" title="Les données brassage observées sont canoniques — modifier la source bd_brewing_ingredients_parsed.">🔒 Lecture seule</span>
                    <?php else: ?>
                        <?php if ($tierBadge !== 't1'): ?>
                        <button type="button"
                                class="br-btn br-btn--secondary br-price-toggle"
                                style="font-size:0.74rem;padding:0.18rem 0.45rem;"
                                data-toggle-target="br-choice-<?= $bSkuId ?>-<?= $miIdInt ?>">
                            Choix SKU (T1)
                        </button>
                        <div id="br-choice-<?= $bSkuId ?>-<?= $miIdInt ?>" hidden style="margin-top:0.35rem;">
                            <!-- Confirm-before-recompile modal trigger -->
                            <form method="post" action="/modules/bom-review.php"
                                  class="br-browse-edit-form"
                                  data-action="set_sku_choice"
                                  data-sku-id="<?= $bSkuId ?>"
                                  data-recipe-id="<?= (int)($bs['recipe_id']??0) ?>"
                                  data-sku-code="<?= htmlspecialchars($bs['sku_code']) ?>">
                                <input type="hidden" name="csrf"   value="<?= htmlspecialchars($csrfToken) ?>">
                                <input type="hidden" name="action" value="set_sku_choice">
                                <input type="hidden" name="sku_id" value="<?= $bSkuId ?>">
                                <input type="hidden" name="sec"    value="browse">
                                <div class="br-edit-row__fields">
                                    <label>Slot
                                        <select name="slot_name" required>
                                            <option value="">— slot —</option>
                                            <?php foreach ($validSlots as $vs): ?>
                                            <option value="<?= htmlspecialchars($vs) ?>"><?= htmlspecialchars($vs) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </label>
                                    <label>MI de remplacement
                                        <select name="mi_id_fk" required>
                                            <option value="">— MI —</option>
                                            <?php foreach ($allMis as $mId => $mData): ?>
                                            <option value="<?= $mId ?>"
                                                <?= $mId === $miIdInt ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($mData['mi_id']) ?>
                                                — <?= htmlspecialchars($mData['name']) ?>
                                                <?= $mData['price'] ? '(' . htmlspecialchars((string)$mData['price']) . ')' : '' ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </label>
                                    <button type="submit" class="br-btn">Enregistrer + Recompiler</button>
                                </div>
                            </form>
                        </div>
                        <?php endif; ?>

                        <?php if ($tierBadge === 't1' && $t1SlotName !== null): ?>
                        <form method="post" action="/modules/bom-review.php"
                              class="br-clear-choice-form"
                              onsubmit="return confirm('Retirer le choix Tier-1 pour le slot «<?= htmlspecialchars($t1SlotName, ENT_QUOTES) ?>» ?\nLe BOM sera recompilé immédiatement.');">
                            <input type="hidden" name="csrf"      value="<?= htmlspecialchars($csrfToken) ?>">
                            <input type="hidden" name="action"    value="clear_sku_choice">
                            <input type="hidden" name="sku_id"    value="<?= $bSkuId ?>">
                            <input type="hidden" name="slot_name" value="<?= htmlspecialchars($t1SlotName) ?>">
                            <input type="hidden" name="sec"       value="browse">
                            <button type="submit" class="br-btn br-btn--clear-choice">
                                retirer
                            </button>
                        </form>
                        <?php endif; ?>

                        <?php if (!$hasCost && $miIdInt > 0): ?>
                        <button type="button"
                                class="br-btn br-btn--price br-price-toggle"
                                style="font-size:0.74rem;padding:0.18rem 0.45rem;margin-left:0.25rem;"
                                data-toggle-target="br-price-<?= $bSkuId ?>-<?= $miIdInt ?>">
                            Prix MI
                        </button>
                        <div id="br-price-<?= $bSkuId ?>-<?= $miIdInt ?>" hidden style="margin-top:0.35rem;">
                            <form method="post" action="/modules/bom-review.php"
                                  class="br-browse-edit-form"
                                  data-action="set_mi_price"
                                  data-sku-id="<?= $bSkuId ?>"
                                  data-recipe-id="<?= (int)($bs['recipe_id']??0) ?>"
                                  data-sku-code="<?= htmlspecialchars($bs['sku_code']) ?>">
                                <input type="hidden" name="csrf"        value="<?= htmlspecialchars($csrfToken) ?>">
                                <input type="hidden" name="action"      value="set_mi_price">
                                <input type="hidden" name="mi_id"       value="<?= $miIdInt ?>">
                                <input type="hidden" name="sec"         value="browse">
                                <div class="br-price-form">
                                    <input type="number" name="price" step="0.0001" min="0"
                                           placeholder="0.0000" required>
                                    <select name="currency">
                                        <option value="CHF">CHF</option>
                                        <option value="EUR">EUR</option>
                                    </select>
                                    <button type="submit" class="br-btn">Enregistrer</button>
                                </div>
                            </form>
                        </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>

        <!-- Recipe binding edit (if recipe-linked SKU) -->
        <?php if ((int)($bs['recipe_id']??0) > 0 && !empty($VALID_BINDING_ROLES)): ?>
        <div class="br-browse-binding-section">
            <div class="br-browse-binding-title">Liaisons recette (Tier-2) — <?= htmlspecialchars($bs['recipe_name'] ?? '') ?></div>
            <div class="br-browse-binding-note">Une modification de liaison recette recompile TOUS les SKUs de cette recette.</div>
            <div class="br-browse-binding-list">
            <?php
            $roleLabels = [
                'label'      => 'Étiquette',
                'can'        => 'Canette',
                'sticker'    => 'Autocollant',
                'holder'     => 'Holder 4-pack',
                'outer_tray' => 'Plateau extérieur',
                'scotch'     => 'Scotch',
            ];
            foreach ($VALID_BINDING_ROLES as $bRole):
                $bound = $bBindings[$bRole] ?? null;
            ?>
            <div class="br-browse-binding-row">
                <span class="br-browse-binding-role"><?= htmlspecialchars($roleLabels[$bRole] ?? $bRole) ?></span>
                <span class="br-browse-binding-mi">
                    <?php if ($bound): ?>
                        <code><?= htmlspecialchars($bound['mi_id_str']) ?></code>
                        <span style="color:var(--ink-mute);font-size:0.78rem;">— <?= htmlspecialchars($bound['mi_name']) ?></span>
                    <?php else: ?>
                        <span style="color:var(--ink-mute);">Non lié</span>
                    <?php endif; ?>
                </span>
                <button type="button"
                        class="br-btn br-btn--secondary br-price-toggle"
                        style="font-size:0.72rem;padding:0.15rem 0.4rem;"
                        data-toggle-target="br-bind-<?= $bSkuId ?>-<?= htmlspecialchars($bRole) ?>">
                    Modifier liaison
                </button>
                <div id="br-bind-<?= $bSkuId ?>-<?= htmlspecialchars($bRole) ?>" hidden style="margin-top:0.35rem;">
                    <form method="post" action="/modules/bom-review.php"
                          class="br-browse-edit-form"
                          data-action="set_recipe_binding"
                          data-recipe-id="<?= (int)$bs['recipe_id'] ?>"
                          data-sku-code="<?= htmlspecialchars($bs['sku_code']) ?>"
                          data-recipe-name="<?= htmlspecialchars($bs['recipe_name'] ?? '') ?>">
                        <input type="hidden" name="csrf"      value="<?= htmlspecialchars($csrfToken) ?>">
                        <input type="hidden" name="action"    value="set_recipe_binding">
                        <input type="hidden" name="recipe_id" value="<?= (int)$bs['recipe_id'] ?>">
                        <input type="hidden" name="role"      value="<?= htmlspecialchars($bRole) ?>">
                        <input type="hidden" name="sec"       value="browse">
                        <div class="br-edit-row__fields">
                            <label>Ingrédient (MI Packaging)
                                <select name="mi_id_fk" required>
                                    <option value="">— choisir —</option>
                                    <?php foreach ($allMis as $mId => $mData): ?>
                                    <option value="<?= $mId ?>"
                                        <?= ($bound && (int)$bound['mi_id_fk'] === $mId) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($mData['mi_id']) ?>
                                        — <?= htmlspecialchars($mData['name']) ?>
                                        <?= $mData['price'] ? '(' . htmlspecialchars((string)$mData['price']) . ' ' . htmlspecialchars((string)$mData['currency']) . ')' : '(sans prix)' ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <button type="submit" class="br-btn">Enregistrer + Recompiler</button>
                        </div>
                    </form>
                </div>
            </div>
            <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

    </div><!-- /.br-browse-bom-panel -->
</div><!-- /.br-browse-sku-card -->
<?php endforeach; // subRows ?>
</div><!-- /.br-browse-sku-grid -->
<?php endif; // empty($subRows) ?>

</div><!-- /.br-browse-sub-group -->
<?php endforeach; // subtypes ?>
</div><!-- /.br-browse-cls-group -->
<?php endforeach; // renderCls ?>

<?php endif; // empty($filteredBrowseSkus) ?>

<!-- ── Confirm-before-recompile modal ────────────────────────────────── -->
<dialog id="br-confirm-modal" class="br-confirm-dialog">
    <div class="br-confirm-dialog__inner">
        <h3 class="br-confirm-dialog__title">Confirmer la modification BOM</h3>
        <p class="br-confirm-dialog__body" id="br-confirm-body"></p>
        <div class="br-confirm-dialog__blast" id="br-confirm-blast"></div>
        <p class="br-confirm-dialog__cogs-warn">
            ⚠ Cette modification prend effet sur les runs futurs.
            Elle n'affecte pas les mois COGS déjà clôturés.
        </p>
        <div class="br-confirm-dialog__actions">
            <button type="button" class="br-btn br-btn--secondary" id="br-confirm-cancel">Annuler</button>
            <button type="button" class="br-btn" id="br-confirm-ok">Confirmer et recompiler</button>
        </div>
    </div>
</dialog>

<?php endif; // $sec === 'browse' ?>

</div><!-- /.br-page -->
</main>

<script defer src="/js/bom-review.js?v=<?= @filemtime(__DIR__ . '/../../public/js/bom-review.js') ?: time() ?>"></script>
</body>
</html>
