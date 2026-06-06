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

            redirect_to('/modules/bom-review.php?sec=drill&recipe=' . max(0, $recipeIdForCompile)
                . '#sku-' . $skuId);
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

            // Redirect back to feed view (or drill-down if recipe param present)
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
$validSec = ['feeds', 'drill'];
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
<div class="br-sku-tabs" style="margin-bottom:1.5rem;">
    <a href="/modules/bom-review.php?sec=feeds"
       class="br-sku-tab <?= $sec === 'feeds' ? 'br-sku-tab--active' : '' ?>">
       Défauts détectés
    </a>
    <a href="/modules/bom-review.php?sec=drill<?= $selectedRecipeId > 0 ? '&recipe=' . $selectedRecipeId : '' ?>"
       class="br-sku-tab <?= $sec === 'drill' ? 'br-sku-tab--active' : '' ?>">
       Drill-down recette
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
                    if ($isBrewing) {
                        $tier     = 'brewing';
                        $tierBadge = 'brewing';
                        $tierLabel = 'Brassage (obs.)';
                    } else {
                        // Check Tier-1 choices: slot_name is stored in ingredient_raw for packaging lines
                        // We check drillChoices to know the tier
                        $slotKey = $bl['ingredient_raw'] ?? '';
                        if (isset($drillChoices[$dSkuId])) {
                            $foundInChoice = false;
                            foreach ($drillChoices[$dSkuId] as $cs => $cv) {
                                if ($cv['mi_id_fk'] == $miIdInt) {
                                    $foundInChoice = true;
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

</div><!-- /.br-page -->
</main>

<script defer src="/js/bom-review.js?v=<?= @filemtime(__DIR__ . '/../../public/js/bom-review.js') ?: time() ?>"></script>
</body>
</html>
