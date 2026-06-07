<?php
declare(strict_types=1);
/**
 * modules/salle-de-controle.php — Salle de contrôle (Qualité)
 *
 * Le Zeppelin family · Sections: Recettes, Biochimie, Conditionnement.
 * Recettes + Biochimie: data-wired for Formats subtab (activate/deactivate SKU formats,
 *   manage placeholder bindings label/can/sticker/holder/outer_tray/scotch).
 * Conditionnement: LIVE — reads/writes commissioning_settings (section='packaging').
 * Pertes: LIVE — reads/writes commissioning_settings (section='pertes').
 *
 * Auth: require_login() — all logged-in users can view; edit gated to is_admin().
 * POST handlers:
 *   update_min_days      — Conditionnement settings (admin only)
 *   update_pertes_config — Pertes constants + thresholds (admin/manager only)
 *   update_yeast_family  — Biochimie yeast-family defaults (admin only)
 *   update_yeast_strain  — Biochimie per-strain catalog edit: family/floc/attenuation/temp (admin/manager)
 *   activate_format      — upsert ref_skus row for (recipe_id, format_id) (admin only)
 *   deactivate_format    — soft-deactivate ref_skus row (admin only)
 *   set_binding          — upsert ref_recipe_packaging_bindings row (admin only)
 */

require __DIR__ . '/../../app/auth.php';
require __DIR__ . '/../../app/csrf.php';
require __DIR__ . '/../../app/settings-helpers.php';
require __DIR__ . '/../../app/db-write-helpers.php';
require_once __DIR__ . '/../../app/sku-bom-compile.php';
require_once __DIR__ . '/../../app/yeast-eligibility.php';
require_once __DIR__ . '/../../app/qc-thresholds.php';
require_once __DIR__ . '/../../app/qa-tank-stats.php';

require_login();
$me = current_user();

// ─────────────────────────────────────────────────────────────────────────────
// HELPER: run the gated-format query and return format_ids that pass the gate
// (cartoner gate applied in PHP — safe to call from both POST + GET)
// ─────────────────────────────────────────────────────────────────────────────
function sdc_gated_format_ids(PDO $pdo): array
{
    $cartoner = (int) $pdo->query(
        "SELECT COUNT(*) FROM ref_process_machines
          WHERE machine_type='cartoner' AND is_active=1"
    )->fetchColumn();

    $rows = $pdo->query(
        "SELECT DISTINCT f.id, (t.units_per_format > 1) AS needs_cartoner
         FROM ref_filler_containers fc
         JOIN ref_process_machines m   ON m.id = fc.machine_id  AND m.is_active=1
         JOIN dbc_container_types c    ON c.id = fc.container_id
         JOIN dbc_packaging_format_templates t ON t.container_code = c.container_code
         JOIN ref_packaging_formats f  ON f.catalog_id = t.id
         WHERE fc.is_active=1 AND f.is_active=1 AND f.is_composite=0"
    )->fetchAll(PDO::FETCH_ASSOC);

    $ids = [];
    foreach ($rows as $r) {
        if ($r['needs_cartoner'] && !$cartoner) continue;
        $ids[] = (int) $r['id'];
    }
    return $ids;
}

// ─────────────────────────────────────────────────────────────────────────────
// HELPER: compute bom_template_id from run_type
// bottle→dec_int=0, can/can33→dec_int=1, keg/cuv→dec_int=0
// ─────────────────────────────────────────────────────────────────────────────
function sdc_bom_template_for_format(PDO $pdo, int $formatId): ?int
{
    $fmt = $pdo->prepare(
        "SELECT f.run_type FROM ref_packaging_formats f WHERE f.id = ? LIMIT 1"
    );
    $fmt->execute([$formatId]);
    $runType = $fmt->fetchColumn();
    if (!$runType) return null;

    $decInt = in_array($runType, ['can', 'can33'], true) ? 1 : 0;

    $tpl = $pdo->prepare(
        "SELECT id FROM ref_packaging_bom_templates
          WHERE format_id = ? AND decoration_integral = ? AND supply = 'we_supply'
          LIMIT 1"
    );
    $tpl->execute([$formatId, $decInt]);
    $id = $tpl->fetchColumn();
    return $id !== false ? (int) $id : null;
}

// ─────────────────────────────────────────────────────────────────────────────
// HELPER: recompile packaging BOM for all active SKUs belonging to a recipe.
// Called AFTER commit() so a recompute failure never rolls back the saved binding.
// Returns the compile_sku_bom_packaging result array (or a zero-result stub on
// empty SKU set). Throws on hard PHP errors; the caller wraps in try/catch.
// ─────────────────────────────────────────────────────────────────────────────
// sdc_recompile_recipe_packaging() and sdc_flash_bom_result() are now defined
// in app/sku-bom-compile.php (required_once above) so they are available to any
// page that requires that file. The if(!function_exists) guards there prevent
// double-declaration when both this file and another module include them.

// ─────────────────────────────────────────────────────────────────────────────
// HELPER: build the PRG redirect URL carrying selection state through the round-trip.
//   $sec      — section (recettes|biochem|conditionnement|…)
//   $recipeId — positive int restores the recipe selection on recettes; 0 = no-op
//   $sub      — subtab name (ingr|process|formats|yeast); '' = no-op (stays on ingr)
//   $strainId — positive int scrolls to the strain row on biochem; 0 = no-op
// ─────────────────────────────────────────────────────────────────────────────
function sdc_redirect_url(string $sec, int $recipeId = 0, string $sub = '', int $strainId = 0): string
{
    $url = '/modules/salle-de-controle.php?sec=' . urlencode($sec);
    if ($recipeId > 0) {
        $url .= '&recipe=' . $recipeId;
    }
    if ($sub !== '') {
        $url .= '&sub=' . urlencode($sub);
    }
    if ($strainId > 0) {
        $url .= '&strain=' . $strainId;
    }
    return $url;
}

// ─────────────────────────────────────────────────────────────────────────────
// POST handler
// ─────────────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!is_admin($me)) {
        flash_set('err', 'Modification réservée aux administrateurs.');
        redirect_to('/modules/salle-de-controle.php?sec=recettes');
    }

    if (!csrf_verify($_POST['csrf'] ?? null)) {
        flash_set('err', 'Session expirée — recharge la page.');
        redirect_to('/modules/salle-de-controle.php?sec=recettes');
    }

    $action = post_str('action') ?? '';

    // JSON-API actions respond directly (no PRG redirect)
    $jsonActions = ['set_hop_stage', 'add_hop_addition', 'delete_hop_addition'];
    if (in_array($action, $jsonActions, true)) {
        header('Content-Type: application/json; charset=utf-8');
    }

    // Redirect target depends on action
    $redirectSec = in_array($action, ['activate_format','deactivate_format','set_binding','update_recipe_yeast','update_recipe_qc','update_recipe_style','update_recipe_name'], true)
        ? 'recettes'
        : (in_array($action, ['update_yeast_family','update_yeast_strain'], true) ? 'biochem'
        : ($action === 'update_pertes_config' ? 'pertes'
        : (in_array($action, ['cip_type_add','cip_type_update','cip_type_deactivate','cip_type_reactivate','update_cip_cadence'], true)
            ? 'cip' : 'conditionnement')));

    // Selection state threaded through PRG so the page can restore position on reload.
    $redirectRecipeId = 0; // carried as &recipe=<id> on recettes section
    $redirectSub      = ''; // carried as &sub=<tab> on recettes section
    $redirectStrainId = 0; // carried as &strain=<id> on biochem section

    try {
        $pdo = maltytask_pdo();

        // ── activate_format ──────────────────────────────────────────────────
        if ($action === 'activate_format') {
            $recipeId  = (int) ($_POST['recipe_id']  ?? 0);
            $formatId  = (int) ($_POST['format_id']  ?? 0);
            $bomOverride = isset($_POST['bom_template_id']) && $_POST['bom_template_id'] !== ''
                ? (int) $_POST['bom_template_id'] : null;

            if ($recipeId <= 0 || $formatId <= 0) {
                throw new RuntimeException('recipe_id et format_id requis.');
            }

            // Re-run gate server-side
            $gatedIds = sdc_gated_format_ids($pdo);
            if (!in_array($formatId, $gatedIds, true)) {
                throw new RuntimeException('Format non commissionné — activation refusée.');
            }

            // Fetch recipe, require sku_prefix
            $recStmt = $pdo->prepare(
                "SELECT id, sku_prefix, name FROM ref_recipes WHERE id = ? AND is_active=1 LIMIT 1"
            );
            $recStmt->execute([$recipeId]);
            $recipe = $recStmt->fetch(PDO::FETCH_ASSOC);
            if (!$recipe || empty($recipe['sku_prefix'])) {
                throw new RuntimeException('Préfixe SKU manquant — à définir dans la fiche recette.');
            }
            $prefix = (string) $recipe['sku_prefix'];

            // Fetch format_code and run_type
            $fmtStmt = $pdo->prepare(
                "SELECT format_code, run_type, hl_per_unit FROM ref_packaging_formats WHERE id = ? LIMIT 1"
            );
            $fmtStmt->execute([$formatId]);
            $fmt = $fmtStmt->fetch(PDO::FETCH_ASSOC);
            if (!$fmt) throw new RuntimeException('Format introuvable.');

            // Compute sku_code
            $skuCode = ($fmt['format_code'] === 'X')
                ? $prefix . '-X'
                : $prefix . $fmt['format_code'];

            // run_type → format label
            $runLabel = [
                'bot'   => 'Bot',
                'can'   => 'Can',
                'can33' => 'Can33',
                'keg'   => 'Keg',
                'cuv'   => 'Cuv',
            ][$fmt['run_type']] ?? $fmt['run_type'];

            // Check for existing (recipe_id, format_id) row
            $existStmt = $pdo->prepare(
                "SELECT id, sku_code, is_active FROM ref_skus
                  WHERE recipe_id = ? AND format_id = ? LIMIT 1"
            );
            $existStmt->execute([$recipeId, $formatId]);
            $existRow = $existStmt->fetch(PDO::FETCH_ASSOC);

            // Check for sku_code collision on DIFFERENT (recipe, format)
            $collStmt = $pdo->prepare(
                "SELECT id, recipe_id, format_id FROM ref_skus
                  WHERE sku_code = ?
                    AND NOT (recipe_id = ? AND format_id = ?)
                  LIMIT 1"
            );
            $collStmt->execute([$skuCode, $recipeId, $formatId]);
            $collision = $collStmt->fetch(PDO::FETCH_ASSOC);
            if ($collision) {
                throw new RuntimeException(
                    "Code SKU «{$skuCode}» déjà utilisé par une autre combinaison "
                    . "(recette #{$collision['recipe_id']}, format #{$collision['format_id']}) "
                    . "— anomalie historique à traiter manuellement."
                );
            }

            // BOM template
            $bomTemplateId = $bomOverride ?? sdc_bom_template_for_format($pdo, $formatId);

            $activateMsg = '';
            $pdo->beginTransaction();
            try {
                if ($existRow) {
                    // Re-activate
                    $before = $existRow;
                    $after  = ['is_active' => 1, 'last_modified_by' => 'web',
                               'bom_template_id' => $bomTemplateId];
                    $updStmt = $pdo->prepare(
                        "UPDATE ref_skus SET is_active=1, last_modified_by='web',
                                bom_template_id=?
                          WHERE id=?"
                    );
                    $updStmt->execute([$bomTemplateId, (int) $existRow['id']]);
                    log_revision($pdo, $me, 'ref_skus', (int) $existRow['id'],
                        $before, $after, 'normal',
                        "Salle de contrôle: réactivation format {$fmt['format_code']} / recette {$recipe['name']}");
                    $activateMsg = "Format «{$skuCode}» réactivé.";
                } else {
                    // Insert
                    $rowHash = hash('sha256',
                        implode('|', [(string)$recipeId, (string)$formatId, $skuCode]));
                    $insStmt = $pdo->prepare(
                        "INSERT INTO ref_skus
                            (sku_code, recipe_id, format_id, format, hl_per_unit,
                             is_active, row_hash, bom_template_id,
                             last_modified_by, last_seen_at, imported_at)
                         VALUES (?, ?, ?, ?, ?, 1, ?, ?, 'web', NOW(), NOW())"
                    );
                    $insStmt->execute([
                        $skuCode, $recipeId, $formatId, $runLabel,
                        $fmt['hl_per_unit'], $rowHash, $bomTemplateId,
                    ]);
                    $newId = (int) $pdo->lastInsertId();
                    log_revision($pdo, $me, 'ref_skus', $newId, null,
                        ['sku_code' => $skuCode, 'recipe_id' => $recipeId,
                         'format_id' => $formatId, 'bom_template_id' => $bomTemplateId],
                        'normal',
                        "Salle de contrôle: activation format {$fmt['format_code']} / recette {$recipe['name']}");
                    $activateMsg = "Format «{$skuCode}» activé.";
                }
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
            // Recompute packaging BOM — runs AFTER commit so a failure here never
            // loses the saved activation. compile_sku_bom_packaging runs its own
            // internal transaction and liquid-parity gate.
            try {
                $r = sdc_recompile_recipe_packaging($pdo, $recipeId);
                sdc_flash_bom_result($activateMsg, $r);
            } catch (Throwable $bomErr) {
                // Recompute failed but the format activation is durable (already committed).
                // Warn the operator; the nightly cron will retry.
                flash_set('ok', $activateMsg
                    . " · BOM recompilation échouée (la sauvegarde est conservée — relance en cron).");
            }
            $redirectRecipeId = $recipeId;
            $redirectSub      = 'formats';

        // ── deactivate_format ────────────────────────────────────────────────
        } elseif ($action === 'deactivate_format') {
            $recipeId = (int) ($_POST['recipe_id']  ?? 0);
            $formatId = (int) ($_POST['format_id']  ?? 0);
            if ($recipeId <= 0 || $formatId <= 0) {
                throw new RuntimeException('recipe_id et format_id requis.');
            }

            $existStmt = $pdo->prepare(
                "SELECT id, sku_code, is_active FROM ref_skus
                  WHERE recipe_id = ? AND format_id = ? LIMIT 1"
            );
            $existStmt->execute([$recipeId, $formatId]);
            $existRow = $existStmt->fetch(PDO::FETCH_ASSOC);
            if (!$existRow) {
                throw new RuntimeException('Aucun SKU trouvé pour cette combinaison.');
            }

            $pdo->beginTransaction();
            try {
                $before = $existRow;
                $pdo->prepare("UPDATE ref_skus SET is_active=0, last_modified_by='web' WHERE id=?")
                    ->execute([(int) $existRow['id']]);
                log_revision($pdo, $me, 'ref_skus', (int) $existRow['id'],
                    $before, ['is_active' => 0, 'last_modified_by' => 'web'], 'normal',
                    "Salle de contrôle: désactivation SKU {$existRow['sku_code']}");
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
            $deactivateMsg = "Format «{$existRow['sku_code']}» désactivé.";
            // Recompute packaging BOM — runs AFTER commit so a failure here never
            // loses the saved deactivation.
            try {
                $r = sdc_recompile_recipe_packaging($pdo, $recipeId);
                sdc_flash_bom_result($deactivateMsg, $r);
            } catch (Throwable $bomErr) {
                flash_set('ok', $deactivateMsg
                    . " · BOM recompilation échouée (la sauvegarde est conservée — relance en cron).");
            }
            $redirectRecipeId = $recipeId;
            $redirectSub      = 'formats';

        // ── set_binding ──────────────────────────────────────────────────────
        } elseif ($action === 'set_binding') {
            $recipeId = (int) ($_POST['recipe_id'] ?? 0);
            $miIdFk   = (int) ($_POST['mi_id_fk']  ?? 0);
            $role     = post_str('role') ?? '';
            if ($recipeId <= 0 || $miIdFk <= 0 || $role === '') {
                throw new RuntimeException('recipe_id, role et mi_id_fk requis.');
            }

            $validRoles = ['label','can','sticker','holder','outer_tray','scotch'];
            $role = must_be_one_of('role', $role, $validRoles);

            // Validate MI exists
            $miStmt = $pdo->prepare("SELECT id, mi_id FROM ref_mi WHERE id=? LIMIT 1");
            $miStmt->execute([$miIdFk]);
            $miRow = $miStmt->fetch(PDO::FETCH_ASSOC);
            if (!$miRow) throw new RuntimeException('MI introuvable.');

            // Validate MI matches the {beer} pattern for this role
            $recStmt = $pdo->prepare(
                "SELECT sku_prefix FROM ref_recipes WHERE id=? AND is_active=1 LIMIT 1"
            );
            $recStmt->execute([$recipeId]);
            $prefix = $recStmt->fetchColumn();
            if (!$prefix) throw new RuntimeException('Recette introuvable ou sans préfixe SKU.');

            $patternStmt = $pdo->prepare(
                "SELECT mi_filter_pattern FROM ref_packaging_items
                  WHERE slot_name = ? AND mi_filter_pattern LIKE '%{beer}%' LIMIT 1"
            );
            $patternStmt->execute([$role]);
            $rawPattern = $patternStmt->fetchColumn();
            if ($rawPattern) {
                // The scotch pattern is PKG_SCOTCH_(TRANSP|{beer})% — a LIKE alternation
                // that MySQL cannot evaluate as regex. Detect this form and build two
                // LIKE clauses OR'd: one for TRANSP (always valid), one for the branded MI.
                // Other roles use simple substitution (PKG_LABEL_{beer}%, PKG_STICKER_{beer}%, etc.)
                $rawPatternStr = (string) $rawPattern;
                if (str_contains($rawPatternStr, '(TRANSP|{beer})')) {
                    // Scotch: accept PKG_SCOTCH_TRANSP OR PKG_SCOTCH_{prefix}
                    $brandedPattern = 'PKG_SCOTCH_' . (string) $prefix . '%';
                    $checkStmt = $pdo->prepare(
                        "SELECT COUNT(*) FROM ref_mi
                          WHERE id = ?
                            AND (mi_id LIKE 'PKG_SCOTCH_TRANSP%' OR mi_id LIKE ?)"
                    );
                    $checkStmt->execute([$miIdFk, $brandedPattern]);
                } else {
                    // Standard substitution: PKG_LABEL_{beer}%, PKG_STICKER_{beer}%, etc.
                    $resolved = str_replace('{beer}', (string) $prefix, $rawPatternStr);
                    $checkStmt = $pdo->prepare(
                        "SELECT COUNT(*) FROM ref_mi WHERE id=? AND mi_id LIKE ?"
                    );
                    $checkStmt->execute([$miIdFk, $resolved]);
                }
                if ((int) $checkStmt->fetchColumn() === 0) {
                    $displayPattern = str_contains($rawPatternStr, '(TRANSP|{beer})')
                        ? "PKG_SCOTCH_TRANSP% OR PKG_SCOTCH_{$prefix}%"
                        : str_replace('{beer}', (string) $prefix, $rawPatternStr);
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
                    "UPDATE ref_recipe_packaging_bindings
                        SET effective_until = CURDATE()
                      WHERE recipe_id=? AND role=?
                        AND (effective_until IS NULL OR effective_until >= CURDATE())"
                )->execute([$recipeId, $role]);

                // Insert new binding
                $todayStr = (new DateTimeImmutable())->format('Y-m-d');
                $insStmt = $pdo->prepare(
                    "INSERT INTO ref_recipe_packaging_bindings
                        (recipe_id, role, mi_id_fk, effective_from, effective_until, notes)
                     VALUES (?, ?, ?, ?, NULL, ?)"
                );
                $notes = "Défini via Salle de contrôle · recette #{$recipeId}";
                $insStmt->execute([$recipeId, $role, $miIdFk, $todayStr, $notes]);
                $newId = (int) $pdo->lastInsertId();
                log_revision($pdo, $me, 'ref_recipe_packaging_bindings', $newId, null,
                    ['recipe_id'=>$recipeId,'role'=>$role,'mi_id_fk'=>$miIdFk,
                     'effective_from'=>$todayStr], 'normal',
                    "Liaison packaging: rôle={$role}, recette={$recipeId}, MI={$miRow['mi_id']}");
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
            $bindingMsg = "Liaison «{$role}» enregistrée.";
            // Recompute packaging BOM — runs AFTER commit so a failure here never
            // loses the saved binding.
            try {
                $r = sdc_recompile_recipe_packaging($pdo, $recipeId);
                sdc_flash_bom_result($bindingMsg, $r);
            } catch (Throwable $bomErr) {
                flash_set('ok', $bindingMsg
                    . " · BOM recompilation échouée (la sauvegarde est conservée — relance en cron).");
            }
            $redirectRecipeId = $recipeId;
            $redirectSub      = 'formats';

        // ── update_yeast_family ──────────────────────────────────────────────
        } elseif ($action === 'update_yeast_family') {

            // Step 1 — read with defaults, then validate (two-step pattern)
            $rawFamily = $_POST['family'] ?? '';
            $validFamilies = ['ale', 'lager', 'non_alcool', 'spontane', 'mixte'];
            $family = must_be_one_of('family', $rawFamily, $validFamilies);

            // garde_days_min: int 0–365 or empty → NULL
            $rawGarde = isset($_POST['garde_days_min']) ? trim((string) $_POST['garde_days_min']) : '';
            if ($rawGarde === '') {
                $gardeDays = null;
            } else {
                if (!ctype_digit($rawGarde)) {
                    throw new RuntimeException('garde_days_min doit être un entier positif (0–365) ou vide.');
                }
                $gardeDays = (int) $rawGarde;
                if ($gardeDays > 365) {
                    throw new RuntimeException('garde_days_min doit être entre 0 et 365.');
                }
            }

            // ferm_temp_min / ferm_temp_max: decimal or empty → NULL
            $rawTMin = post_decimal('ferm_temp_min');
            $rawTMax = post_decimal('ferm_temp_max');
            $tempMin = $rawTMin !== null ? (float) $rawTMin : null;
            $tempMax = $rawTMax !== null ? (float) $rawTMax : null;

            if ($tempMin !== null && ($tempMin < 0 || $tempMin > 50)) {
                throw new RuntimeException('ferm_temp_min doit être entre 0 et 50 °C.');
            }
            if ($tempMax !== null && ($tempMax < 0 || $tempMax > 50)) {
                throw new RuntimeException('ferm_temp_max doit être entre 0 et 50 °C.');
            }
            if ($tempMin !== null && $tempMax !== null && $tempMin > $tempMax) {
                throw new RuntimeException('ferm_temp_min ne peut pas être supérieur à ferm_temp_max.');
            }

            // Fetch before-state for audit
            $fetchStmt = $pdo->prepare(
                "SELECT garde_days_min, ferm_temp_min, ferm_temp_max
                   FROM ref_yeast_family_defaults
                  WHERE family = ?
                  LIMIT 1"
            );
            $fetchStmt->execute([$family]);
            $existing = $fetchStmt->fetch(PDO::FETCH_ASSOC);
            if (!$existing) {
                throw new RuntimeException("Famille levurienne introuvable : {$family}");
            }

            $before = [
                'garde_days_min' => $existing['garde_days_min'],
                'ferm_temp_min'  => $existing['ferm_temp_min'],
                'ferm_temp_max'  => $existing['ferm_temp_max'],
            ];
            $after = [
                'garde_days_min' => $gardeDays,
                'ferm_temp_min'  => $tempMin,
                'ferm_temp_max'  => $tempMax,
            ];

            $upStmt = $pdo->prepare(
                "UPDATE ref_yeast_family_defaults
                    SET garde_days_min = ?,
                        ferm_temp_min  = ?,
                        ferm_temp_max  = ?,
                        updated_by     = ?,
                        updated_at     = NOW()
                  WHERE family = ?"
            );
            $upStmt->execute([$gardeDays, $tempMin, $tempMax, $me['username'], $family]);

            log_revision(
                $pdo,
                $me,
                'ref_yeast_family_defaults',
                0,
                $before,
                $after,
                'normal',
                "Salle de contrôle: biochimie.{$family}"
            );

            flash_set('ok', "Paramètres Biochimie mis à jour : famille {$family}.");

        // ── update_min_days ──────────────────────────────────────────────────
        } elseif ($action === 'update_min_days') {
            $rawDays = post_decimal('min_days_after_racking');
            if ($rawDays === null) {
                throw new RuntimeException('Valeur requise pour le délai minimum après soutirage.');
            }
            $days = (float) $rawDays;
            if ($days < 0 || $days > 365) {
                throw new RuntimeException('Valeur invalide : doit être entre 0 et 365 jours.');
            }

            // Fetch before-state for audit
            $fetchStmt = $pdo->prepare(
                "SELECT id, value_num FROM commissioning_settings
                  WHERE section = 'packaging' AND key_name = 'min_days_after_racking'
                    AND is_active = 1
                  LIMIT 1"
            );
            $fetchStmt->execute();
            $existing = $fetchStmt->fetch(PDO::FETCH_ASSOC);

            if (!$existing) {
                throw new RuntimeException(
                    'Paramètre introuvable — la migration 128 doit être appliquée.'
                );
            }

            $before = ['value_num' => $existing['value_num']];
            $after  = ['value_num' => $days];

            $upStmt = $pdo->prepare(
                "UPDATE commissioning_settings
                    SET value_num = ?, updated_by = ?
                  WHERE section = 'packaging' AND key_name = 'min_days_after_racking'
                    AND is_active = 1"
            );
            $upStmt->execute([$days, $me['username']]);

            log_revision(
                $pdo,
                $me,
                'commissioning_settings',
                (int) $existing['id'],
                $before,
                $after,
                'normal',
                'Salle de contrôle: packaging.min_days_after_racking'
            );

            flash_set('ok', "Délai minimum mis à jour : {$days} jour(s).");
        // ── update_recipe_yeast ──────────────────────────────────────────────
        } elseif ($action === 'update_recipe_yeast') {
            // Role gate: admin or manager only (silent reject for operateur)
            if (!is_admin($me) && !is_manager($me)) {
                flash_set('err', 'Modification réservée aux administrateurs et managers.');
                $rid = max(0, (int) ($_POST['recipe_id'] ?? 0));
                redirect_to(sdc_redirect_url('recettes', $rid, 'yeast'));
            }

            // ── Step 1: read with defaults, then validate ────────────────────
            $recipeId = post_int('recipe_id') ?? 0;
            if ($recipeId <= 0) {
                throw new RuntimeException('recipe_id requis.');
            }

            // yeast_strain_id_fk: empty string → NULL, else positive int
            $rawStrainId = post_str('yeast_strain_id_fk');
            $strainIdFk  = ($rawStrainId !== null && $rawStrainId !== '') ? (int) $rawStrainId : null;

            // family for the strain: empty → leave unchanged, else validate ENUM
            $rawFamily  = post_str('strain_family');
            $newFamily  = null; // null means "don't update strain's family"
            if ($rawFamily !== null && $rawFamily !== '') {
                $newFamily = must_be_one_of('strain_family', $rawFamily, YEAST_FAMILIES);
            }

            // garde_days_min_override: empty → NULL, else int 0–365
            $rawGardeOvr = isset($_POST['garde_days_min_override'])
                ? trim((string) $_POST['garde_days_min_override']) : '';
            if ($rawGardeOvr === '') {
                $gardeOverride = null;
            } else {
                if (!ctype_digit($rawGardeOvr)) {
                    throw new RuntimeException('garde_days_min_override doit être un entier 0–365 ou vide.');
                }
                $gardeOverride = (int) $rawGardeOvr;
                if ($gardeOverride > 365) {
                    throw new RuntimeException('garde_days_min_override doit être entre 0 et 365.');
                }
            }

            // ferm_temp_min_override / ferm_temp_max_override: decimal 0–50 or NULL
            $rawTMinOvr = post_decimal('ferm_temp_min_override');
            $rawTMaxOvr = post_decimal('ferm_temp_max_override');
            $tempMinOvr = $rawTMinOvr !== null ? (float) $rawTMinOvr : null;
            $tempMaxOvr = $rawTMaxOvr !== null ? (float) $rawTMaxOvr : null;

            if ($tempMinOvr !== null && ($tempMinOvr < 0 || $tempMinOvr > 50)) {
                throw new RuntimeException('ferm_temp_min_override doit être entre 0 et 50 °C.');
            }
            if ($tempMaxOvr !== null && ($tempMaxOvr < 0 || $tempMaxOvr > 50)) {
                throw new RuntimeException('ferm_temp_max_override doit être entre 0 et 50 °C.');
            }
            if ($tempMinOvr !== null && $tempMaxOvr !== null && $tempMinOvr > $tempMaxOvr) {
                throw new RuntimeException('ferm_temp_min_override ne peut pas être supérieur à ferm_temp_max_override.');
            }

            // ── Step 2: validate recipe exists and is active ─────────────────
            $recStmt = $pdo->prepare(
                "SELECT id, name FROM ref_recipes WHERE id = ? AND is_active = 1 LIMIT 1"
            );
            $recStmt->execute([$recipeId]);
            $recRow = $recStmt->fetch(PDO::FETCH_ASSOC);
            if (!$recRow) {
                throw new RuntimeException("Recette introuvable ou inactive : id={$recipeId}");
            }

            // ── Step 3: validate strain id exists (if provided) ──────────────
            $strainRow = null;
            if ($strainIdFk !== null) {
                $strStmt = $pdo->prepare(
                    "SELECT id, name, family FROM ref_yeast_strains WHERE id = ? LIMIT 1"
                );
                $strStmt->execute([$strainIdFk]);
                $strainRow = $strStmt->fetch(PDO::FETCH_ASSOC);
                if (!$strainRow) {
                    throw new RuntimeException("Souche levurienne introuvable : id={$strainIdFk}");
                }
            }

            // Validate that newFamily applies to the same strain as strainIdFk
            if ($newFamily !== null && $strainIdFk === null) {
                throw new RuntimeException(
                    'Une souche doit être sélectionnée avant de définir sa famille.'
                );
            }

            // ── Step 4: fetch before-states for audit ────────────────────────
            $beforeRecStmt = $pdo->prepare(
                "SELECT yeast_strain_id_fk, garde_days_min_override,
                        ferm_temp_min_override, ferm_temp_max_override
                   FROM ref_recipes WHERE id = ? LIMIT 1"
            );
            $beforeRecStmt->execute([$recipeId]);
            $beforeRec = $beforeRecStmt->fetch(PDO::FETCH_ASSOC);

            $beforeStrain = null;
            if ($strainIdFk !== null && $newFamily !== null) {
                $bsStmt = $pdo->prepare(
                    "SELECT id, family FROM ref_yeast_strains WHERE id = ? LIMIT 1"
                );
                $bsStmt->execute([$strainIdFk]);
                $beforeStrain = $bsStmt->fetch(PDO::FETCH_ASSOC);
            }

            // ── Step 5: write ────────────────────────────────────────────────
            $pdo->beginTransaction();
            try {
                // 5a. Update ref_recipes
                $upRecStmt = $pdo->prepare(
                    "UPDATE ref_recipes
                        SET yeast_strain_id_fk       = ?,
                            garde_days_min_override  = ?,
                            ferm_temp_min_override   = ?,
                            ferm_temp_max_override   = ?,
                            last_modified_by         = 'web'
                      WHERE id = ?"
                );
                $upRecStmt->execute([
                    $strainIdFk,
                    $gardeOverride,
                    $tempMinOvr,
                    $tempMaxOvr,
                    $recipeId,
                ]);

                $afterRec = [
                    'yeast_strain_id_fk'     => $strainIdFk,
                    'garde_days_min_override'=> $gardeOverride,
                    'ferm_temp_min_override' => $tempMinOvr,
                    'ferm_temp_max_override' => $tempMaxOvr,
                    'last_modified_by'       => 'web',
                ];
                log_revision(
                    $pdo, $me, 'ref_recipes', $recipeId,
                    $beforeRec ?: [],
                    $afterRec,
                    'normal',
                    "Salle de contrôle: yeast assignment · recette {$recRow['name']}"
                );

                // 5b. Update ref_yeast_strains.family if a new family was supplied
                if ($newFamily !== null && $strainIdFk !== null) {
                    $upStrainStmt = $pdo->prepare(
                        "UPDATE ref_yeast_strains SET family = ? WHERE id = ?"
                    );
                    $upStrainStmt->execute([$newFamily, $strainIdFk]);

                    log_revision(
                        $pdo, $me, 'ref_yeast_strains', $strainIdFk,
                        $beforeStrain ?: [],
                        ['family' => $newFamily],
                        'normal',
                        "Salle de contrôle: strain family set to '{$newFamily}' for strain id={$strainIdFk}"
                    );
                }

                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }

            $strainLabel = $strainRow ? (string) $strainRow['name'] : 'aucune';
            flash_set('ok',
                "Levure & garde enregistrés — recette «{$recRow['name']}» "
                . "· souche : {$strainLabel}"
                . ($gardeOverride !== null ? " · garde override : {$gardeOverride}j" : '')
                . ($newFamily !== null ? " · famille souche : {$newFamily}" : '')
                . '.'
            );
            $redirectRecipeId = $recipeId;
            $redirectSub      = 'yeast';

        // ── update_yeast_strain ──────────────────────────────────────────────
        // Per-strain catalog editor in the Biochimie section.
        // Writes: family, flocculation, attenuation_min/max, temp_min/max.
        // Note: 'type' column is deprecated (all values='unknown'); the UI
        // field has been removed. The column remains in the DB; DEFAULT='unknown'
        // so omitting it from writes is safe.
        // Role gate: admin or manager (same as update_recipe_yeast).
        // Writes the same ref_yeast_strains.family column as update_recipe_yeast —
        // both paths use the same column and the same audit shape.
        } elseif ($action === 'update_yeast_strain') {
            if (!is_admin($me) && !is_manager($me)) {
                flash_set('err', 'Modification réservée aux administrateurs et managers.');
                $sid = max(0, (int) ($_POST['strain_id'] ?? 0));
                redirect_to(sdc_redirect_url('biochem', 0, '', $sid));
            }

            // Step 1 — read with defaults, then validate (two-step pattern)
            $strainId = post_int('strain_id') ?? 0;
            if ($strainId <= 0) {
                throw new RuntimeException('strain_id requis.');
            }

            // family: empty → NULL, else validate ENUM
            $rawFamily = post_str('family');
            $newFamily = null;
            if ($rawFamily !== null) {
                $newFamily = must_be_one_of('family', $rawFamily, YEAST_FAMILIES);
            }

            // flocculation: empty → NULL, else validate ENUM
            $rawFloc = post_str('flocculation');
            $newFloc = null;
            if ($rawFloc !== null) {
                $newFloc = must_be_one_of('flocculation', $rawFloc, ['low', 'medium', 'high']);
            }

            // attenuation_min: decimal 0–100 or empty → NULL
            $rawAttMin = post_decimal('attenuation_min');
            $attMin = $rawAttMin !== null ? (float) $rawAttMin : null;
            if ($attMin !== null && ($attMin < 0 || $attMin > 100)) {
                throw new RuntimeException('attenuation_min doit être entre 0 et 100 %.');
            }

            // attenuation_max: decimal 0–100 or empty → NULL
            $rawAttMax = post_decimal('attenuation_max');
            $attMax = $rawAttMax !== null ? (float) $rawAttMax : null;
            if ($attMax !== null && ($attMax < 0 || $attMax > 100)) {
                throw new RuntimeException('attenuation_max doit être entre 0 et 100 %.');
            }
            if ($attMin !== null && $attMax !== null && $attMin > $attMax) {
                throw new RuntimeException('attenuation_min ne peut pas être supérieur à attenuation_max.');
            }

            // temp_min: decimal 0–50 or empty → NULL
            $rawTMin = post_decimal('temp_min');
            $tempMin = $rawTMin !== null ? (float) $rawTMin : null;
            if ($tempMin !== null && ($tempMin < 0 || $tempMin > 50)) {
                throw new RuntimeException('temp_min doit être entre 0 et 50 °C.');
            }

            // temp_max: decimal 0–50 or empty → NULL
            $rawTMax = post_decimal('temp_max');
            $tempMax = $rawTMax !== null ? (float) $rawTMax : null;
            if ($tempMax !== null && ($tempMax < 0 || $tempMax > 50)) {
                throw new RuntimeException('temp_max doit être entre 0 et 50 °C.');
            }
            if ($tempMin !== null && $tempMax !== null && $tempMin > $tempMax) {
                throw new RuntimeException('temp_min ne peut pas être supérieur à temp_max.');
            }

            // Step 2 — confirm strain exists and is active
            $strFetchStmt = $pdo->prepare(
                "SELECT id, name FROM ref_yeast_strains WHERE id = ? AND is_active = 1 LIMIT 1"
            );
            $strFetchStmt->execute([$strainId]);
            $strainRow = $strFetchStmt->fetch(PDO::FETCH_ASSOC);
            if (!$strainRow) {
                throw new RuntimeException("Souche levurienne introuvable ou inactive : id={$strainId}");
            }

            // Step 3 — fetch before-state for audit
            $beforeStmt = $pdo->prepare(
                "SELECT family, flocculation, attenuation_min, attenuation_max, temp_min, temp_max
                   FROM ref_yeast_strains
                  WHERE id = ?
                  LIMIT 1"
            );
            $beforeStmt->execute([$strainId]);
            $beforeState = $beforeStmt->fetch(PDO::FETCH_ASSOC);

            // Step 4 — write
            // 'type' column intentionally omitted: deprecated (all=unknown), UI removed,
            // DEFAULT='unknown' so omitting preserves the existing value.
            $upStrainStmt = $pdo->prepare(
                "UPDATE ref_yeast_strains
                    SET family          = ?,
                        flocculation    = ?,
                        attenuation_min = ?,
                        attenuation_max = ?,
                        temp_min        = ?,
                        temp_max        = ?
                  WHERE id = ?"
            );
            $upStrainStmt->execute([
                $newFamily,
                $newFloc,
                $attMin,
                $attMax,
                $tempMin,
                $tempMax,
                $strainId,
            ]);

            $afterState = [
                'family'          => $newFamily,
                'flocculation'    => $newFloc,
                'attenuation_min' => $attMin,
                'attenuation_max' => $attMax,
                'temp_min'        => $tempMin,
                'temp_max'        => $tempMax,
            ];

            log_revision(
                $pdo,
                $me,
                'ref_yeast_strains',
                $strainId,
                $beforeState ?: [],
                $afterState,
                'normal',
                "Salle de contrôle: biochimie catalogue souche · {$strainRow['name']}"
            );

            flash_set('ok', "Souche «{$strainRow['name']}» mise à jour.");
            $redirectStrainId = $strainId;

        // ── update_recipe_qc ─────────────────────────────────────────────────
        // Editor for per-recipe CO₂ target/tolerance and optional racked_vol overrides.
        // Role gate: admin or manager (mirrors update_recipe_yeast).
        // Empty inputs → store NULL (falls back to history band / global).
        // Negative values → rejected.
        } elseif ($action === 'update_recipe_qc') {
            if (!is_admin($me) && !is_manager($me)) {
                flash_set('err', 'Modification réservée aux administrateurs et managers.');
                $rid = max(0, (int) ($_POST['recipe_id'] ?? 0));
                redirect_to(sdc_redirect_url('recettes', $rid, 'yeast'));
            }

            // ── Step 1: read with defaults, then validate ────────────────────
            $recipeId = post_int('recipe_id') ?? 0;
            if ($recipeId <= 0) {
                throw new RuntimeException('recipe_id requis.');
            }

            // co2_target: empty → NULL, else float ≥ 0
            $rawCo2Target    = post_decimal('co2_target');
            $co2Target       = $rawCo2Target !== null ? (float) $rawCo2Target : null;
            if ($co2Target !== null && $co2Target < 0.0) {
                throw new RuntimeException('co2_target doit être ≥ 0.');
            }

            // co2_tolerance: empty → NULL, else float ≥ 0
            $rawCo2Tolerance = post_decimal('co2_tolerance');
            $co2Tolerance    = $rawCo2Tolerance !== null ? (float) $rawCo2Tolerance : null;
            if ($co2Tolerance !== null && $co2Tolerance < 0.0) {
                throw new RuntimeException('co2_tolerance doit être ≥ 0.');
            }

            // CO₂ half-spec guard: require both or neither — a target without a
            // tolerance (or vice versa) cannot form a usable conformance band.
            if (($co2Target === null) !== ($co2Tolerance === null)) {
                throw new RuntimeException(
                    'CO₂ cible et tolérance doivent être renseignées ensemble ou laissées toutes deux vides.'
                );
            }

            // racked_vol override cols: all four must be provided or all must be empty.
            // Partial override is rejected — the resolver requires all four to be non-null.
            $rawVolWarnLo    = post_decimal('racked_vol_warn_lo');
            $rawVolWarnHi    = post_decimal('racked_vol_warn_hi');
            $rawVolOutlierLo = post_decimal('racked_vol_outlier_lo');
            $rawVolOutlierHi = post_decimal('racked_vol_outlier_hi');

            $volProvidedCount = (int)($rawVolWarnLo !== null)
                              + (int)($rawVolWarnHi !== null)
                              + (int)($rawVolOutlierLo !== null)
                              + (int)($rawVolOutlierHi !== null);

            if ($volProvidedCount > 0 && $volProvidedCount < 4) {
                throw new RuntimeException(
                    'Les quatre bornes de volume (warn lo/hi, outlier lo/hi) '
                    . 'doivent toutes être renseignées ou toutes laissées vides.'
                );
            }

            $volWarnLo    = $rawVolWarnLo    !== null ? (float) $rawVolWarnLo    : null;
            $volWarnHi    = $rawVolWarnHi    !== null ? (float) $rawVolWarnHi    : null;
            $volOutlierLo = $rawVolOutlierLo !== null ? (float) $rawVolOutlierLo : null;
            $volOutlierHi = $rawVolOutlierHi !== null ? (float) $rawVolOutlierHi : null;

            // Basic ordering sanity when provided
            if ($volWarnLo !== null) {
                if ($volWarnLo < 0.0) throw new RuntimeException('racked_vol_warn_lo doit être ≥ 0.');
                if ($volWarnHi <= $volWarnLo) throw new RuntimeException('racked_vol_warn_hi doit être > warn_lo.');
                if ($volOutlierLo > $volWarnLo) throw new RuntimeException('racked_vol_outlier_lo doit être ≤ warn_lo.');
                if ($volOutlierHi < $volWarnHi) throw new RuntimeException('racked_vol_outlier_hi doit être ≥ warn_hi.');
            }

            // ── Step 2: validate recipe exists ──────────────────────────────
            $recStmt = $pdo->prepare(
                "SELECT id, name FROM ref_recipes WHERE id = ? AND is_active = 1 LIMIT 1"
            );
            $recStmt->execute([$recipeId]);
            $recRow = $recStmt->fetch(PDO::FETCH_ASSOC);
            if (!$recRow) {
                throw new RuntimeException("Recette introuvable ou inactive : id={$recipeId}");
            }

            // ── Step 3: fetch before-state for audit ─────────────────────────
            $beforeStmt = $pdo->prepare(
                "SELECT co2_target, co2_tolerance,
                        racked_vol_warn_lo, racked_vol_warn_hi,
                        racked_vol_outlier_lo, racked_vol_outlier_hi
                   FROM ref_recipes WHERE id = ? LIMIT 1"
            );
            $beforeStmt->execute([$recipeId]);
            $before = $beforeStmt->fetch(PDO::FETCH_ASSOC) ?: [];

            $after = [
                'co2_target'           => $co2Target,
                'co2_tolerance'        => $co2Tolerance,
                'racked_vol_warn_lo'   => $volWarnLo,
                'racked_vol_warn_hi'   => $volWarnHi,
                'racked_vol_outlier_lo'=> $volOutlierLo,
                'racked_vol_outlier_hi'=> $volOutlierHi,
                'last_modified_by'     => 'web',
            ];

            // ── Step 4: write ─────────────────────────────────────────────────
            $upStmt = $pdo->prepare(
                "UPDATE ref_recipes
                    SET co2_target            = ?,
                        co2_tolerance         = ?,
                        racked_vol_warn_lo    = ?,
                        racked_vol_warn_hi    = ?,
                        racked_vol_outlier_lo = ?,
                        racked_vol_outlier_hi = ?,
                        last_modified_by      = 'web'
                  WHERE id = ?"
            );
            $upStmt->execute([
                $co2Target, $co2Tolerance,
                $volWarnLo, $volWarnHi, $volOutlierLo, $volOutlierHi,
                $recipeId,
            ]);

            log_revision(
                $pdo, $me, 'ref_recipes', $recipeId,
                $before,
                $after,
                'normal',
                "Salle de contrôle: QC seuils · recette {$recRow['name']}"
            );

            $parts = [];
            if ($co2Target !== null) $parts[] = "CO₂ cible {$co2Target} ±{$co2Tolerance} g/L";
            elseif ($co2Target === null) $parts[] = 'CO₂ → global';
            if ($volWarnLo !== null) $parts[] = "vol [{$volWarnLo}–{$volWarnHi} HL]";
            else $parts[] = 'vol → auto';

            flash_set('ok',
                "Seuils QC enregistrés — «{$recRow['name']}» · " . implode(', ', $parts) . '.'
            );
            $redirectRecipeId = $recipeId;
            $redirectSub      = 'yeast';

        // ── cip_type_add ─────────────────────────────────────────────────────
        } elseif ($action === 'cip_type_add') {
            // Step 1: read with defaults, then validate (two-step pattern)
            $rawName = $_POST['cip_name'] ?? '';
            $name    = trim((string) $rawName);
            if ($name === '') {
                throw new RuntimeException('Le nom du type CIP est requis.');
            }
            if (mb_strlen($name) > 64) {
                throw new RuntimeException('Le nom du type CIP ne peut pas dépasser 64 caractères.');
            }

            $rawOrder  = isset($_POST['cip_sort_order']) ? trim((string) $_POST['cip_sort_order']) : '';
            $sortOrder = 0;
            if ($rawOrder !== '') {
                if (!ctype_digit($rawOrder)) {
                    throw new RuntimeException('L\'ordre de tri doit être un entier positif.');
                }
                $sortOrder = (int) $rawOrder;
            }

            $pdo->beginTransaction();
            try {
                $insStmt = $pdo->prepare(
                    "INSERT INTO ref_cip_types (name, sort_order, is_active, updated_by)
                     VALUES (?, ?, 1, ?)"
                );
                $insStmt->execute([$name, $sortOrder, $me['username']]);
                $newId = (int) $pdo->lastInsertId();
                log_revision($pdo, $me, 'ref_cip_types', $newId,
                    null,
                    ['name' => $name, 'sort_order' => $sortOrder, 'is_active' => 1],
                    'normal',
                    "Salle de contrôle: ajout type CIP «{$name}»");
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                // Catch UNIQUE violation for friendly flash
                if (str_contains($e->getMessage(), '1062')) {
                    throw new RuntimeException(
                        "Un type CIP nommé «" . htmlspecialchars($name) . "» existe déjà."
                    );
                }
                throw $e;
            }
            flash_set('ok', "Type CIP «" . htmlspecialchars($name) . "» ajouté.");

        // ── cip_type_update ──────────────────────────────────────────────────
        } elseif ($action === 'cip_type_update') {
            $cipId = (int) ($_POST['cip_id'] ?? 0);
            if ($cipId <= 0) {
                throw new RuntimeException('Identifiant type CIP invalide.');
            }

            $rawName = $_POST['cip_name'] ?? '';
            $name    = trim((string) $rawName);
            if ($name === '') {
                throw new RuntimeException('Le nom du type CIP est requis.');
            }
            if (mb_strlen($name) > 64) {
                throw new RuntimeException('Le nom du type CIP ne peut pas dépasser 64 caractères.');
            }

            $rawOrder  = isset($_POST['cip_sort_order']) ? trim((string) $_POST['cip_sort_order']) : '';
            $sortOrder = 0;
            if ($rawOrder !== '') {
                if (!ctype_digit($rawOrder)) {
                    throw new RuntimeException('L\'ordre de tri doit être un entier positif.');
                }
                $sortOrder = (int) $rawOrder;
            }

            // Fetch before-state
            $beforeStmt = $pdo->prepare(
                "SELECT id, name, sort_order, is_active FROM ref_cip_types WHERE id = ? LIMIT 1"
            );
            $beforeStmt->execute([$cipId]);
            $beforeRow = $beforeStmt->fetch(PDO::FETCH_ASSOC);
            if (!$beforeRow) {
                throw new RuntimeException('Type CIP introuvable.');
            }

            $pdo->beginTransaction();
            try {
                $upStmt = $pdo->prepare(
                    "UPDATE ref_cip_types SET name = ?, sort_order = ?, updated_by = ? WHERE id = ?"
                );
                $upStmt->execute([$name, $sortOrder, $me['username'], $cipId]);
                log_revision($pdo, $me, 'ref_cip_types', $cipId,
                    ['name' => $beforeRow['name'], 'sort_order' => $beforeRow['sort_order']],
                    ['name' => $name, 'sort_order' => $sortOrder],
                    'normal',
                    "Salle de contrôle: modification type CIP id={$cipId}");
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                if (str_contains($e->getMessage(), '1062')) {
                    throw new RuntimeException(
                        "Un type CIP nommé «" . htmlspecialchars($name) . "» existe déjà."
                    );
                }
                throw $e;
            }
            flash_set('ok', "Type CIP «" . htmlspecialchars($name) . "» modifié.");

        // ── cip_type_deactivate ──────────────────────────────────────────────
        } elseif ($action === 'cip_type_deactivate') {
            $cipId = (int) ($_POST['cip_id'] ?? 0);
            if ($cipId <= 0) {
                throw new RuntimeException('Identifiant type CIP invalide.');
            }

            $beforeStmt = $pdo->prepare(
                "SELECT id, name, is_active FROM ref_cip_types WHERE id = ? LIMIT 1"
            );
            $beforeStmt->execute([$cipId]);
            $beforeRow = $beforeStmt->fetch(PDO::FETCH_ASSOC);
            if (!$beforeRow) {
                throw new RuntimeException('Type CIP introuvable.');
            }

            $pdo->beginTransaction();
            try {
                $pdo->prepare(
                    "UPDATE ref_cip_types SET is_active = 0, updated_by = ? WHERE id = ?"
                )->execute([$me['username'], $cipId]);
                log_revision($pdo, $me, 'ref_cip_types', $cipId,
                    ['is_active' => 1],
                    ['is_active' => 0],
                    'normal',
                    "Salle de contrôle: désactivation type CIP «{$beforeRow['name']}»");
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
            flash_set('ok', "Type CIP «" . htmlspecialchars((string) $beforeRow['name']) . "» désactivé.");

        // ── cip_type_reactivate ──────────────────────────────────────────────
        } elseif ($action === 'cip_type_reactivate') {
            $cipId = (int) ($_POST['cip_id'] ?? 0);
            if ($cipId <= 0) {
                throw new RuntimeException('Identifiant type CIP invalide.');
            }

            $beforeStmt = $pdo->prepare(
                "SELECT id, name, is_active FROM ref_cip_types WHERE id = ? LIMIT 1"
            );
            $beforeStmt->execute([$cipId]);
            $beforeRow = $beforeStmt->fetch(PDO::FETCH_ASSOC);
            if (!$beforeRow) {
                throw new RuntimeException('Type CIP introuvable.');
            }

            $pdo->beginTransaction();
            try {
                $pdo->prepare(
                    "UPDATE ref_cip_types SET is_active = 1, updated_by = ? WHERE id = ?"
                )->execute([$me['username'], $cipId]);
                log_revision($pdo, $me, 'ref_cip_types', $cipId,
                    ['is_active' => 0],
                    ['is_active' => 1],
                    'normal',
                    "Salle de contrôle: réactivation type CIP «{$beforeRow['name']}»");
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
            flash_set('ok', "Type CIP «" . htmlspecialchars((string) $beforeRow['name']) . "» réactivé.");

        // ── update_pertes_config ─────────────────────────────────────────────
        // Edits commissioning_settings rows for section='pertes'.
        // Role gate: admin or manager (HL constants are COGS-critical).
        // Input: key_name (validated against allowed list) + value_num (float).
        // Pattern: read-then-validate → UPDATE → log_revision audit (before/after).
        } elseif ($action === 'update_pertes_config') {
            if (!is_admin($me) && !is_manager($me)) {
                flash_set('err', 'Modification réservée aux administrateurs et managers.');
                redirect_to('/modules/salle-de-controle.php?sec=pertes');
            }

            // Allowed keys — whitelist prevents arbitrary key_name injection.
            $allowedKeys = [
                'pertes_racking_loss_hl',
                'pertes_packaging_loss_hl',
                'pertes_rack_warn_pct',
                'pertes_packaging_warn_pct',
                'pertes_brewing_warn_pct',
                'pertes_total_effectif_warn_pct',
                'pertes_total_nominal_warn_pct',
            ];

            // ── Step 1: read with defaults, then validate ────────────────────
            $rawKey = post_str('key_name');
            $keyName = must_be_one_of('key_name', (string) $rawKey, $allowedKeys);

            $rawVal = post_decimal('value_num');
            if ($rawVal === null) {
                throw new RuntimeException('Valeur requise.');
            }
            $newVal = (float) $rawVal;
            if ($newVal < 0.0) {
                throw new RuntimeException('La valeur doit être ≥ 0.');
            }
            // % thresholds: 0–100
            $isPctKey = str_ends_with($keyName, '_pct');
            if ($isPctKey && $newVal > 100.0) {
                throw new RuntimeException('Le seuil en % doit être compris entre 0 et 100.');
            }

            // ── Step 2: fetch current row for before-state ───────────────────
            $fetchStmt = $pdo->prepare(
                "SELECT id, value_num, label_fr
                   FROM commissioning_settings
                  WHERE section = 'pertes' AND key_name = ? AND is_active = 1
                  LIMIT 1"
            );
            $fetchStmt->execute([$keyName]);
            $existing = $fetchStmt->fetch(PDO::FETCH_ASSOC);

            if (!$existing) {
                throw new RuntimeException(
                    'Paramètre introuvable — la migration 184 doit être appliquée.'
                );
            }

            $before = ['value_num' => $existing['value_num']];
            $after  = ['value_num' => $newVal];

            // ── Step 3: UPDATE ───────────────────────────────────────────────
            $upStmt = $pdo->prepare(
                "UPDATE commissioning_settings
                    SET value_num = ?, updated_by = ?
                  WHERE section = 'pertes' AND key_name = ? AND is_active = 1"
            );
            $upStmt->execute([$newVal, $me['username'], $keyName]);

            // ── Step 4: audit ────────────────────────────────────────────────
            log_revision(
                $pdo,
                $me,
                'commissioning_settings',
                (int) $existing['id'],
                $before,
                $after,
                'normal',
                'Salle de contrôle: pertes.' . $keyName
            );

            $label = htmlspecialchars((string) $existing['label_fr']);
            flash_set('ok', "Paramètre « {$label} » mis à jour : {$newVal}.");

        // ── update_cip_cadence ───────────────────────────────────────────────
        // Edits commissioning_settings rows for section='cip_cadence'.
        // Role gate: admin or manager (matches update_pertes_config).
        // Two numeric keys (acid_after, full_after) + two CSV-of-int keys
        // (acid_reset_types, full_reset_types). Each POST saves one key.
        // Pattern: read-then-validate (two-step) → UPDATE → log_revision audit.
        } elseif ($action === 'update_cip_cadence') {
            if (!is_admin($me) && !is_manager($me)) {
                flash_set('err', 'Modification réservée aux administrateurs et managers.');
                redirect_to('/modules/salle-de-controle.php?sec=cip');
            }

            // Allowed keys — whitelist prevents arbitrary key_name injection.
            $allowedNumericKeys = [
                'cip_cadence_acid_after',
                'cip_cadence_full_after',
            ];
            $allowedTextKeys = [
                'cip_cadence_acid_reset_types',
                'cip_cadence_full_reset_types',
            ];
            $allowedKeys = array_merge($allowedNumericKeys, $allowedTextKeys);

            // ── Step 1: read with defaults, then validate ────────────────────
            $rawKey  = post_str('key_name');
            $keyName = must_be_one_of('key_name', (string) $rawKey, $allowedKeys);

            $isNumericKey = in_array($keyName, $allowedNumericKeys, true);

            // ── Step 2: fetch current row for before-state ───────────────────
            $fetchStmt = $pdo->prepare(
                "SELECT id, value_num, value_text, label_fr
                   FROM commissioning_settings
                  WHERE section = 'cip_cadence' AND key_name = ? AND is_active = 1
                  LIMIT 1"
            );
            $fetchStmt->execute([$keyName]);
            $existing = $fetchStmt->fetch(PDO::FETCH_ASSOC);

            if (!$existing) {
                throw new RuntimeException(
                    'Paramètre introuvable — la migration 190 doit être appliquée.'
                );
            }

            if ($isNumericKey) {
                // Numeric: positive integer, min 1
                $rawVal = post_int('value_num');
                if ($rawVal === null || $rawVal < 1) {
                    throw new RuntimeException('La valeur doit être un entier positif (≥ 1).');
                }
                $newNum  = $rawVal;
                $newText = null; // unchanged

                $before = ['value_num' => $existing['value_num']];
                $after  = ['value_num' => $newNum];

                $upStmt = $pdo->prepare(
                    "UPDATE commissioning_settings
                        SET value_num = ?, updated_by = ?
                      WHERE section = 'cip_cadence' AND key_name = ? AND is_active = 1"
                );
                $upStmt->execute([$newNum, $me['username'], $keyName]);

                $displayVal = (string) $newNum . ' rack(s)';
            } else {
                // CSV of ref_cip_types ids: read all valid ids from DB, validate each token.
                // Read the live ref_cip_types ids for validation.
                $validIdsRows = $pdo->query(
                    "SELECT id FROM ref_cip_types WHERE is_active = 1"
                )->fetchAll(PDO::FETCH_COLUMN);
                $validIds = array_map('intval', $validIdsRows);

                // POST sends checkbox values as cip_type_ids[] array.
                $rawIds = isset($_POST['cip_type_ids']) && is_array($_POST['cip_type_ids'])
                    ? $_POST['cip_type_ids']
                    : [];

                $cleaned = [];
                foreach ($rawIds as $rid) {
                    $intId = (int) $rid;
                    if ($intId <= 0 || !in_array($intId, $validIds, true)) {
                        throw new RuntimeException(
                            'Identifiant type CIP invalide : ' . htmlspecialchars((string) $rid)
                        );
                    }
                    $cleaned[] = $intId;
                }
                sort($cleaned);
                $newCsv = implode(',', $cleaned); // '' when no boxes checked

                $before = ['value_text' => $existing['value_text']];
                $after  = ['value_text' => $newCsv];

                $upStmt = $pdo->prepare(
                    "UPDATE commissioning_settings
                        SET value_text = ?, updated_by = ?
                      WHERE section = 'cip_cadence' AND key_name = ? AND is_active = 1"
                );
                $upStmt->execute([$newCsv, $me['username'], $keyName]);

                $displayVal = $newCsv !== '' ? $newCsv : '(aucun)';
            }

            // ── Step 3: audit ────────────────────────────────────────────────
            log_revision(
                $pdo,
                $me,
                'commissioning_settings',
                (int) $existing['id'],
                $before,
                $after,
                'normal',
                'Salle de contrôle: cip_cadence.' . $keyName
            );

            $label = htmlspecialchars((string) $existing['label_fr']);
            flash_set('ok', "Cadence CIP — « {$label} » mis à jour : {$displayVal}.");

        // ── update_recipe_style ──────────────────────────────────────────────
        } elseif ($action === 'update_recipe_style') {
            if (!is_admin($me) && !is_manager($me)) {
                flash_set('err', 'Modification réservée aux administrateurs et managers.');
                $rid = max(0, (int) ($_POST['recipe_id'] ?? 0));
                redirect_to(sdc_redirect_url('recettes', $rid, 'ingr'));
            }

            // Step 1 — read with defaults, then validate
            $recipeId = post_int('recipe_id') ?? 0;
            if ($recipeId <= 0) {
                throw new RuntimeException('recipe_id requis.');
            }

            $rawStyle = post_str('style') ?? '';
            $style    = trim($rawStyle);
            if (mb_strlen($style) > 64) {
                throw new RuntimeException('Style trop long (max 64 caractères).');
            }
            $styleOrNull = $style !== '' ? $style : null;

            // Step 2 — validate recipe exists and is active
            $recStmt = $pdo->prepare(
                "SELECT id, name FROM ref_recipes WHERE id = ? AND is_active = 1 LIMIT 1"
            );
            $recStmt->execute([$recipeId]);
            $recRow = $recStmt->fetch(PDO::FETCH_ASSOC);
            if (!$recRow) {
                throw new RuntimeException("Recette introuvable ou inactive : id={$recipeId}");
            }

            // Step 3 — fetch before-state for audit
            $beforeStmt = $pdo->prepare("SELECT style FROM ref_recipes WHERE id = ? LIMIT 1");
            $beforeStmt->execute([$recipeId]);
            $before = $beforeStmt->fetch(PDO::FETCH_ASSOC) ?: [];

            // Step 4 — write
            $upStmt = $pdo->prepare(
                "UPDATE ref_recipes SET style = ?, last_modified_by = 'web' WHERE id = ?"
            );
            $upStmt->execute([$styleOrNull, $recipeId]);

            $after = ['style' => $styleOrNull, 'last_modified_by' => 'web'];
            log_revision(
                $pdo, $me, 'ref_recipes', $recipeId,
                $before,
                $after,
                'normal',
                "Salle de contrôle: style · recette {$recRow['name']}"
            );

            $styleLabel = $styleOrNull ?? '(vide)';
            flash_set('ok', "Style enregistré — «{$recRow['name']}» · {$styleLabel}.");
            $redirectRecipeId = $recipeId;
            $redirectSub      = 'ingr';

        // ── update_recipe_name ───────────────────────────────────────────────
        } elseif ($action === 'update_recipe_name') {
            if (!is_admin($me) && !is_manager($me)) {
                flash_set('err', 'Modification réservée aux administrateurs et managers.');
                $rid = max(0, (int) ($_POST['recipe_id'] ?? 0));
                redirect_to(sdc_redirect_url('recettes', $rid, 'ingr'));
            }

            // Step 1 — read with defaults, then validate
            $recipeId = post_int('recipe_id') ?? 0;
            if ($recipeId <= 0) {
                throw new RuntimeException('recipe_id requis.');
            }

            $rawName = post_str('name') ?? '';
            $newName = trim($rawName);
            if ($newName === '') {
                throw new RuntimeException('Le nom de la recette ne peut pas être vide.');
            }
            if (mb_strlen($newName) > 128) {
                throw new RuntimeException('Nom trop long (max 128 caractères).');
            }

            // Step 2 — validate recipe exists and is active
            $recStmt = $pdo->prepare(
                "SELECT id, name, classification FROM ref_recipes WHERE id = ? AND is_active = 1 LIMIT 1"
            );
            $recStmt->execute([$recipeId]);
            $recRow = $recStmt->fetch(PDO::FETCH_ASSOC);
            if (!$recRow) {
                throw new RuntimeException("Recette introuvable ou inactive : id={$recipeId}");
            }

            // Rename is restricted to CONTRACT recipes. Nébuleuse recipe names are
            // DB join keys: tanks.php attributes fermentation via ref_recipes.name =
            // f.beer (subtype='Core'), and recipe-ingredients-loader.php gap-fills
            // COGS via ref_recipes.name = beer_name. Renaming a Neb recipe would
            // silently break tank attribution and COGS gap-fill. Contracts are
            // single-vintage, subtype NULL, brew-linked by recipe_id_fk — safe.
            if (($recRow['classification'] ?? '') !== 'Contract') {
                flash_set('err', 'Renommage réservé aux recettes sous contrat — les noms des recettes Nébuleuse sont des clés de jointure (attribution cuves / COGS).');
                redirect_to(sdc_redirect_url('recettes', $recipeId, 'ingr'));
            }

            // Step 3 — fetch before-state for audit
            $beforeStmt = $pdo->prepare("SELECT name FROM ref_recipes WHERE id = ? LIMIT 1");
            $beforeStmt->execute([$recipeId]);
            $before = $beforeStmt->fetch(PDO::FETCH_ASSOC) ?: [];

            // Step 4 — write
            $upStmt = $pdo->prepare(
                "UPDATE ref_recipes SET name = ?, last_modified_by = 'web' WHERE id = ?"
            );
            $upStmt->execute([$newName, $recipeId]);

            $after = ['name' => $newName, 'last_modified_by' => 'web'];
            log_revision(
                $pdo, $me, 'ref_recipes', $recipeId,
                $before,
                $after,
                'normal',
                "Salle de contrôle: renommage · {$before['name']} → {$newName}"
            );

            flash_set('ok', "Recette renommée : «{$before['name']}» → «{$newName}».");
            $redirectRecipeId = $recipeId;
            $redirectSub      = 'ingr';

        // ── set_hop_stage ────────────────────────────────────────────────────
        } elseif ($action === 'set_hop_stage') {
            if (!is_admin($me) && !is_manager($me)) {
                http_response_code(403);
                echo json_encode(['ok' => false, 'error' => 'Modification réservée aux administrateurs et managers.']);
                exit;
            }

            // Step 1 — read with defaults, then validate
            $rriId  = post_int('id') ?? 0;
            $rawStage  = post_str('stage') ?? '';
            $rawBoilMin = isset($_POST['boil_min']) && $_POST['boil_min'] !== '' ? $_POST['boil_min'] : null;

            if ($rriId <= 0) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'id requis.']);
                exit;
            }

            $allowedStages = ['mash', 'first_wort', 'boil', 'hop_stand', 'dry_hop', 'whirlpool'];
            $stageOrNull = ($rawStage !== '' && in_array($rawStage, $allowedStages, true))
                ? $rawStage : null;

            // Enforce CHECK invariant in PHP: boil ↔ minutes non-null; every other stage → minutes NULL
            if ($stageOrNull === 'boil') {
                if ($rawBoilMin === null || !is_numeric($rawBoilMin)) {
                    http_response_code(400);
                    echo json_encode(['ok' => false, 'error' => 'Le temps de contact (minutes) est requis pour le stage "boil".']);
                    exit;
                }
                $boilMin = (int) $rawBoilMin;
                if ($boilMin < 0 || $boilMin > 90) {
                    http_response_code(400);
                    echo json_encode(['ok' => false, 'error' => 'Les minutes de houblonnage doivent être comprises entre 0 (flameout) et 90.']);
                    exit;
                }
            } else {
                // Any stage other than boil (including NULL/unclassified) → minutes must be NULL
                if ($rawBoilMin !== null && is_numeric($rawBoilMin)) {
                    http_response_code(400);
                    echo json_encode(['ok' => false, 'error' => 'Les minutes de houblonnage ne sont valides que pour le stage "boil".']);
                    exit;
                }
                $boilMin = null;
            }

            // Step 2 — validate row exists and is a hop MI (category_id = 2)
            $rriStmt = $pdo->prepare(
                "SELECT ri.id, ri.recipe_id, ri.hop_addition_stage, ri.hop_boil_time_min,
                        m.category_id, m.name AS mi_name, r.name AS recipe_name
                   FROM ref_recipe_ingredients ri
                   JOIN ref_mi m ON m.id = ri.mi_id_fk
                   JOIN ref_recipes r ON r.id = ri.recipe_id
                  WHERE ri.id = ? AND ri.is_active = 1 LIMIT 1"
            );
            $rriStmt->execute([$rriId]);
            $rriRow = $rriStmt->fetch(PDO::FETCH_ASSOC);
            if (!$rriRow) {
                http_response_code(404);
                echo json_encode(['ok' => false, 'error' => "Ligne ingrédient introuvable ou inactive : id={$rriId}"]);
                exit;
            }
            if ((int) $rriRow['category_id'] !== 2) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => "Cet ingrédient n'est pas un houblon — la classification de stage est réservée aux houblons."]);
                exit;
            }

            // Step 3 — before-state for audit
            $before = [
                'hop_addition_stage' => $rriRow['hop_addition_stage'],
                'hop_boil_time_min'  => $rriRow['hop_boil_time_min'],
            ];

            // Step 4 — write
            $upStmt = $pdo->prepare(
                "UPDATE ref_recipe_ingredients
                    SET hop_addition_stage = ?, hop_boil_time_min = ?
                  WHERE id = ?"
            );
            $upStmt->execute([$stageOrNull, $boilMin, $rriId]);

            $after = [
                'hop_addition_stage' => $stageOrNull,
                'hop_boil_time_min'  => $boilMin,
            ];
            log_revision(
                $pdo, $me, 'ref_recipe_ingredients', $rriId,
                $before,
                $after,
                'normal',
                "Salle de contrôle: hop stage · {$rriRow['recipe_name']} · {$rriRow['mi_name']}"
            );

            echo json_encode([
                'ok'       => true,
                'id'       => $rriId,
                'stage'    => $stageOrNull,
                'boil_min' => $boilMin,
            ]);
            exit;

        // ── add_hop_addition ─────────────────────────────────────────────────
        } elseif ($action === 'add_hop_addition') {
            if (!is_admin($me) && !is_manager($me)) {
                http_response_code(403);
                echo json_encode(['ok' => false, 'error' => 'Modification réservée aux administrateurs et managers.']);
                exit;
            }

            // Step 1 — read with defaults, then validate
            $recipeId     = post_int('recipe_id') ?? 0;
            $miIdFk       = post_int('mi_id_fk') ?? 0;
            $unit         = post_str('unit') ?? '';
            $rawStage     = post_str('stage') ?? '';
            $rawBoilMin   = isset($_POST['boil_min']) && $_POST['boil_min'] !== '' ? $_POST['boil_min'] : null;

            // Per-brassin input: client sends qty_per_brassin + brassin_hl, server divides
            $rawQtyBrassin = post_str('qty_per_brassin') ?? '';
            $rawBrassinHl  = post_str('brassin_hl') ?? '';

            if ($recipeId <= 0 || $miIdFk <= 0) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'recipe_id et mi_id_fk requis.']);
                exit;
            }

            // Validate per-brassin qty
            if (!is_numeric($rawQtyBrassin) || (float) $rawQtyBrassin <= 0) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'La quantité par brassin doit être un nombre positif.']);
                exit;
            }
            // Validate brassin_hl — must match server-side canonical value (anti-tamper)
            $bsSrv = $pdo->query(
                "SELECT size_hl FROM ref_brewhouse_size WHERE effective_until IS NULL ORDER BY effective_from DESC LIMIT 1"
            )->fetch(PDO::FETCH_ASSOC);
            $serverBrassinHl = ($bsSrv && $bsSrv['size_hl'] > 0) ? (float) $bsSrv['size_hl'] : 30.0;

            // Client-supplied brassin_hl must match server value (±0.01 tolerance for float)
            $clientBrassinHl = is_numeric($rawBrassinHl) ? (float) $rawBrassinHl : 0.0;
            if (abs($clientBrassinHl - $serverBrassinHl) > 0.01) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'Valeur brassin_hl invalide — recharge la page.']);
                exit;
            }

            $qtyPerHl = (float) $rawQtyBrassin / $serverBrassinHl;
            if ($qtyPerHl <= 0) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'La quantité par brassin doit être un nombre positif.']);
                exit;
            }

            $allowedUnits = ['kg', 'g', 'ml', 'L'];
            if (!in_array($unit, $allowedUnits, true)) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'Unité invalide.']);
                exit;
            }

            $allowedStages = ['mash', 'first_wort', 'boil', 'hop_stand', 'dry_hop', 'whirlpool'];
            $stageOrNull = ($rawStage !== '' && in_array($rawStage, $allowedStages, true))
                ? $rawStage : null;

            // Enforce CHECK invariant
            if ($stageOrNull === 'boil') {
                if ($rawBoilMin === null || !is_numeric($rawBoilMin)) {
                    http_response_code(400);
                    echo json_encode(['ok' => false, 'error' => 'Le temps de contact (minutes) est requis pour le stage "boil".']);
                    exit;
                }
                $boilMin = (int) $rawBoilMin;
                if ($boilMin < 0 || $boilMin > 90) {
                    http_response_code(400);
                    echo json_encode(['ok' => false, 'error' => 'Les minutes de houblonnage doivent être comprises entre 0 (flameout) et 90.']);
                    exit;
                }
            } else {
                $boilMin = null;
            }

            // Step 2 — validate MI exists, is category_id=2, and is already on that recipe
            $miStmt = $pdo->prepare(
                "SELECT m.id, m.mi_id, m.name, m.category_id
                   FROM ref_mi m
                  WHERE m.id = ? AND m.category_id = 2 LIMIT 1"
            );
            $miStmt->execute([$miIdFk]);
            $miRow = $miStmt->fetch(PDO::FETCH_ASSOC);
            if (!$miRow) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => "L'ingrédient n'existe pas ou n'est pas un houblon."]);

                exit;
            }

            $existsStmt = $pdo->prepare(
                "SELECT COUNT(*) FROM ref_recipe_ingredients
                  WHERE recipe_id = ? AND mi_id_fk = ? AND is_active = 1 LIMIT 1"
            );
            $existsStmt->execute([$recipeId, $miIdFk]);
            if ((int) $existsStmt->fetchColumn() === 0) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => "Cet houblon n'est pas encore dans cette recette — ajoutez-le d'abord via la fiche recette."]);
                exit;
            }

            $recStmt2 = $pdo->prepare("SELECT id, name FROM ref_recipes WHERE id = ? AND is_active = 1 LIMIT 1");
            $recStmt2->execute([$recipeId]);
            $recRow2 = $recStmt2->fetch(PDO::FETCH_ASSOC);
            if (!$recRow2) {
                http_response_code(404);
                echo json_encode(['ok' => false, 'error' => "Recette introuvable ou inactive : id={$recipeId}"]);
                exit;
            }

            // Step 3 — INSERT (UNIQUE on (recipe_id, mi_id_fk, stage_key, boil_time_key) catches dupes)
            try {
                $insStmt = $pdo->prepare(
                    "INSERT INTO ref_recipe_ingredients
                        (recipe_id, mi_id_fk, qty_per_hl, unit, hop_addition_stage, hop_boil_time_min, is_active)
                     VALUES (?, ?, ?, ?, ?, ?, 1)"
                );
                $insStmt->execute([$recipeId, $miIdFk, $qtyPerHl, $unit, $stageOrNull, $boilMin]);
                $newId = (int) $pdo->lastInsertId();
            } catch (PDOException $e) {
                // Duplicate stage+time for same (recipe, MI)
                if (str_contains($e->getMessage(), '1062') || str_contains($e->getMessage(), 'Duplicate')) {
                    http_response_code(409);
                    echo json_encode(['ok' => false, 'error' => "Cette combinaison houblon + stage + minutes existe déjà pour cette recette."]);
                    exit;
                }
                throw $e;
            }

            $after = [
                'recipe_id' => $recipeId, 'mi_id_fk' => $miIdFk,
                'qty_per_hl' => $qtyPerHl, 'unit' => $unit,
                'hop_addition_stage' => $stageOrNull, 'hop_boil_time_min' => $boilMin,
                'is_active' => 1,
            ];
            log_revision(
                $pdo, $me, 'ref_recipe_ingredients', $newId,
                null,
                $after,
                'normal',
                "Salle de contrôle: add hop addition · {$recRow2['name']} · {$miRow['name']}"
            );

            echo json_encode([
                'ok'       => true,
                'id'       => $newId,
                'mi'       => (string) $miRow['mi_id'],
                'name'     => (string) $miRow['name'],
                'cat'      => 'Hops',
                'qty'      => $qtyPerHl,
                'unit'     => $unit,
                'is_hop'   => true,
                'stage'    => $stageOrNull,
                'boil_min' => $boilMin,
            ]);
            exit;

        // ── delete_hop_addition ──────────────────────────────────────────────
        } elseif ($action === 'delete_hop_addition') {
            if (!is_admin($me) && !is_manager($me)) {
                http_response_code(403);
                echo json_encode(['ok' => false, 'error' => 'Modification réservée aux administrateurs et managers.']);
                exit;
            }

            // Step 1 — read with defaults, then validate
            $rriId = post_int('id') ?? 0;
            if ($rriId <= 0) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'id requis.']);
                exit;
            }

            // Step 2 — validate row exists, is a hop, and is a STAGE row (not the last/only row for that mi on that recipe)
            $rriStmt2 = $pdo->prepare(
                "SELECT ri.id, ri.recipe_id, ri.mi_id_fk, ri.hop_addition_stage, ri.hop_boil_time_min,
                        m.category_id, m.name AS mi_name, r.name AS recipe_name
                   FROM ref_recipe_ingredients ri
                   JOIN ref_mi m ON m.id = ri.mi_id_fk
                   JOIN ref_recipes r ON r.id = ri.recipe_id
                  WHERE ri.id = ? AND ri.is_active = 1 LIMIT 1"
            );
            $rriStmt2->execute([$rriId]);
            $rriRow2 = $rriStmt2->fetch(PDO::FETCH_ASSOC);
            if (!$rriRow2) {
                http_response_code(404);
                echo json_encode(['ok' => false, 'error' => "Ligne ingrédient introuvable ou inactive : id={$rriId}"]);
                exit;
            }
            if ((int) $rriRow2['category_id'] !== 2) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => "Cet ingrédient n'est pas un houblon."]);
                exit;
            }

            // Count how many active rows this MI has on this recipe
            $countStmt = $pdo->prepare(
                "SELECT COUNT(*) FROM ref_recipe_ingredients
                  WHERE recipe_id = ? AND mi_id_fk = ? AND is_active = 1"
            );
            $countStmt->execute([$rriRow2['recipe_id'], $rriRow2['mi_id_fk']]);
            $rowCount = (int) $countStmt->fetchColumn();
            if ($rowCount <= 1) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => "Impossible de supprimer la seule addition de ce houblon — utilisez la fiche recette pour retirer l'ingrédient."]);
                exit;
            }

            // Step 3 — soft-delete (is_active = 0)
            $before = [
                'is_active'          => 1,
                'hop_addition_stage' => $rriRow2['hop_addition_stage'],
                'hop_boil_time_min'  => $rriRow2['hop_boil_time_min'],
            ];
            $pdo->prepare("UPDATE ref_recipe_ingredients SET is_active = 0 WHERE id = ?")->execute([$rriId]);

            $after = ['is_active' => 0, '_tombstone' => 'deleted_by_sdc'];
            log_revision(
                $pdo, $me, 'ref_recipe_ingredients', $rriId,
                $before,
                $after,
                'normal',
                "Salle de contrôle: delete hop addition · {$rriRow2['recipe_name']} · {$rriRow2['mi_name']}"
            );

            echo json_encode(['ok' => true, 'id' => $rriId]);
            exit;

        } else {
            throw new RuntimeException('Action inconnue.');
        }
    } catch (Throwable $e) {
        flash_set('err', pdo_friendly_error($e, 'salle-de-controle'));
    }

    redirect_to(sdc_redirect_url($redirectSec, $redirectRecipeId, $redirectSub, $redirectStrainId));
}

// ── GET — load data for all sections ──────────────────────────────────────────
header('Content-Type: text/html; charset=utf-8');

// --- Conditionnement settings ------------------------------------------------
try {
    $pdo = maltytask_pdo();

    $settingsStmt = $pdo->prepare(
        "SELECT key_name, label_fr, description_fr, value_num, default_num, unit_fr
           FROM commissioning_settings
          WHERE section = 'packaging' AND is_active = 1
          ORDER BY id ASC"
    );
    $settingsStmt->execute();
    $packagingSettings = $settingsStmt->fetchAll(PDO::FETCH_ASSOC);

    $settingsByKey = [];
    foreach ($packagingSettings as $s) {
        $settingsByKey[$s['key_name']] = $s;
    }
    $migrationApplied = !empty($packagingSettings);
    $loadErr          = null;
} catch (Throwable $e) {
    $packagingSettings = [];
    $settingsByKey     = [];
    $migrationApplied  = false;
    $loadErr           = $e->getMessage();
}

// --- Pertes settings (section='pertes') --------------------------------------
// Key order drives UI display order; fetched by id ASC (insertion order = migration order).
$pertesSettings  = [];
$pertesByKey     = [];
$pertesLoadErr   = null;
try {
    $pdo = maltytask_pdo();
    $pStmt = $pdo->prepare(
        "SELECT key_name, label_fr, description_fr, value_num, default_num, unit_fr
           FROM commissioning_settings
          WHERE section = 'pertes' AND is_active = 1
          ORDER BY id ASC"
    );
    $pStmt->execute();
    $pertesSettings = $pStmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($pertesSettings as $ps) {
        $pertesByKey[$ps['key_name']] = $ps;
    }
} catch (Throwable $e) {
    $pertesLoadErr = $e->getMessage();
}

// --- Biochimie data (ref_yeast_family_defaults) ------------------------------
$yeastFamilies  = [];
$biochemLoadErr = null;
try {
    $pdo = maltytask_pdo();
    $yfStmt = $pdo->query(
        "SELECT family, label_fr, garde_days_min, ferm_temp_min, ferm_temp_max,
                is_produced, is_active, updated_at, updated_by
           FROM ref_yeast_family_defaults
          ORDER BY is_produced DESC, family"
    );
    $yeastFamilies = $yfStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $biochemLoadErr = $e->getMessage();
}

// --- Recettes / Formats data (SDC_FORMATS_DATA) ------------------------------
$formatsData   = null;
$formatsLoadErr = null;
try {
    $pdo = maltytask_pdo();

    // Gated formats (all 14 non-composite, cartoner-gated)
    $cartoner = (int) $pdo->query(
        "SELECT COUNT(*) FROM ref_process_machines
          WHERE machine_type='cartoner' AND is_active=1"
    )->fetchColumn();

    $gatedRows = $pdo->query(
        "SELECT DISTINCT f.id, f.format_code, f.display_name, f.hl_per_unit, f.run_type,
                t.units_per_format, (t.units_per_format > 1) AS needs_cartoner
         FROM ref_filler_containers fc
         JOIN ref_process_machines m   ON m.id = fc.machine_id  AND m.is_active=1
         JOIN dbc_container_types c    ON c.id = fc.container_id
         JOIN dbc_packaging_format_templates t ON t.container_code = c.container_code
         JOIN ref_packaging_formats f  ON f.catalog_id = t.id
         WHERE fc.is_active=1 AND f.is_active=1 AND f.is_composite=0
         ORDER BY f.run_type, f.format_code"
    )->fetchAll(PDO::FETCH_ASSOC);

    // Apply cartoner gate
    $gatedFormats = [];
    foreach ($gatedRows as $r) {
        if ($r['needs_cartoner'] && !$cartoner) continue;
        $gatedFormats[] = [
            'id'           => (int)   $r['id'],
            'format_code'  => (string)$r['format_code'],
            'display_name' => (string)$r['display_name'],
            'hl_per_unit'  => (float) $r['hl_per_unit'],
            'run_type'     => (string)$r['run_type'],
            'units_per_format' => (int)$r['units_per_format'],
        ];
    }
    $gatedFormatIds = array_column($gatedFormats, 'id');

    // BOM templates indexed by format_id
    $bomTplRows = $pdo->query(
        "SELECT id, format_id, decoration_integral, supply FROM ref_packaging_bom_templates"
    )->fetchAll(PDO::FETCH_ASSOC);
    $bomByFormatKey = []; // key = "formatId:decInt:supply"
    $bomByFormatId  = []; // simplest default lookup: format_id → we_supply
    foreach ($bomTplRows as $r) {
        $key = $r['format_id'] . ':' . $r['decoration_integral'] . ':' . $r['supply'];
        $bomByFormatKey[$key] = (int) $r['id'];
        if ($r['supply'] === 'we_supply') {
            $bomByFormatId[(int)$r['format_id']] = (int) $r['id'];
        }
    }

    // Recipe lifecycle: three lists derived from v_recipe_lifecycle.
    // Collapsed to one entry per identity (name, classification, client_id).
    // Operative row = newest vintage. W window from commissioning_settings.
    $lifecycleRows = $pdo->query(
        "SELECT recipe_id AS id, display_name AS name, classification, subtype,
                sku_prefix, vintage, last_brew, in_stock_qty, lifecycle_state,
                list_bucket, flag
           FROM v_recipe_lifecycle
          ORDER BY list_bucket, FIELD(subtype,'Core','EPH','CollabIn','CollabOut','WhiteLabel','Archive'), display_name"
    )->fetchAll(PDO::FETCH_ASSOC);

    $recipesActives  = array_values(array_filter($lifecycleRows, fn($r) => $r['list_bucket'] === 'actives'));
    $recipesPassees  = array_values(array_filter($lifecycleRows, fn($r) => $r['list_bucket'] === 'passees'));
    $recipesContrats = array_values(array_filter($lifecycleRows, fn($r) => $r['list_bucket'] === 'contrats'));

    // $activatableRecs keeps the existing formats/yeast/QC pipeline working.
    // It must carry the same rows as before (sku_prefix non-null, is_active=1).
    // We rebuild it from the lifecycle query: actives with a non-null sku_prefix.
    $activatableRecs = array_values(array_filter($lifecycleRows, function ($r) {
        return $r['list_bucket'] === 'actives'
            && $r['sku_prefix'] !== null
            && $r['sku_prefix'] !== '';
    }));

    // Rebuild $noPrefix for any downstream code that referenced it.
    $noPrefix = array_values(array_filter($lifecycleRows, function ($r) {
        return $r['list_bucket'] !== 'contrats'
            && ($r['sku_prefix'] === null || $r['sku_prefix'] === '');
    }));

    // Full id set across all three lifecycle buckets — used by yeast + QC data builds
    // so that passées/contrats recipes also get yeast assignment and QC data.
    // (Formats/SKU pipeline keeps its own $allRecipeIds gated to $activatableRecs.)
    $allLifecycleIds = array_values(array_unique(array_map('intval', array_column($lifecycleRows, 'id'))));
    if (empty($allLifecycleIds)) {
        $allLifecycleIds = [0];
    }

    // Recipe ingredients — keyed by recipe_id for all lifecycle recipes.
    // DB category names mapped to display labels used by the JS render layer.
    $ingredientsData = [];
    {
        $catMap = [
            'Malt'             => 'Malt',
            'Hops'             => 'Hops',
            'Brewing Adjunct'  => 'Adjunct',
            'Process Chemical' => 'Proc/Chem',
            'Brewing Mineral'  => 'Minéraux',
            'Yeast'            => 'Yeast',
        ];
        $inPlaceAll = implode(',', array_fill(0, count($allLifecycleIds), '?'));
        $ingrStmt = $pdo->prepare(
            "SELECT ri.id,
                    ri.recipe_id,
                    ri.mi_id_fk,
                    m.mi_id        AS mi,
                    m.name         AS name,
                    m.category_id  AS category_id,
                    c.name         AS db_category,
                    ri.qty_per_hl,
                    ri.unit,
                    ri.hop_addition_stage,
                    ri.hop_boil_time_min
               FROM ref_recipe_ingredients ri
               JOIN ref_mi m             ON m.id = ri.mi_id_fk
               JOIN ref_mi_categories c  ON c.id = m.category_id
              WHERE ri.recipe_id IN ({$inPlaceAll})
                AND ri.is_active = 1
              ORDER BY ri.recipe_id, c.name, m.name,
                       CASE WHEN ri.hop_addition_stage IS NULL THEN 99
                            ELSE FIELD(ri.hop_addition_stage,'mash','first_wort','boil','whirlpool','hop_stand','dry_hop')
                       END,
                       COALESCE(ri.hop_boil_time_min,-1) DESC"
        );
        $ingrStmt->execute($allLifecycleIds);
        foreach ($ingrStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rid = (int) $row['recipe_id'];
            if (!isset($ingredientsData[$rid])) {
                $ingredientsData[$rid] = [];
            }
            $ingredientsData[$rid][] = [
                'id'        => (int)    $row['id'],
                'mi'        => (string) $row['mi'],
                'mi_id_fk'  => (int)    $row['mi_id_fk'],
                'name'      => (string) $row['name'],
                'cat'       => $catMap[$row['db_category']] ?? (string) $row['db_category'],
                'qty'       => (float)  $row['qty_per_hl'],
                'unit'      => (string) $row['unit'],
                'is_hop'    => (int)    $row['category_id'] === 2,
                'stage'     => $row['hop_addition_stage'] !== null ? (string) $row['hop_addition_stage'] : null,
                'boil_min'  => $row['hop_boil_time_min'] !== null ? (int) $row['hop_boil_time_min'] : null,
            ];
        }
    }

    // Collect recipe IDs for IN clause
    $allRecipeIds = array_column($activatableRecs, 'id');
    if (empty($allRecipeIds)) {
        $allRecipeIds = [0];
    }
    $inPlace = implode(',', array_fill(0, count($allRecipeIds), '?'));

    // Existing SKUs
    $skuStmt = $pdo->prepare(
        "SELECT id, recipe_id, format_id, sku_code, hl_per_unit, bom_template_id, is_active
           FROM ref_skus
          WHERE recipe_id IN ({$inPlace})"
    );
    $skuStmt->execute($allRecipeIds);
    $skuRows = $skuStmt->fetchAll(PDO::FETCH_ASSOC);

    // Index by recipe_id → format_id
    $skusByRecipe = [];
    foreach ($skuRows as $r) {
        $rid = (int) $r['recipe_id'];
        $fid = (int) $r['format_id'];
        $skusByRecipe[$rid][$fid] = [
            'id'             => (int)    $r['id'],
            'sku_code'       => (string) $r['sku_code'],
            'hl_per_unit'    => (float)  $r['hl_per_unit'],
            'bom_template_id'=> $r['bom_template_id'] !== null ? (int) $r['bom_template_id'] : null,
            'is_active'      => (int)    $r['is_active'],
        ];
    }

    // Existing bindings (active)
    $bindStmt = $pdo->prepare(
        "SELECT b.recipe_id, b.role, b.mi_id_fk, m.mi_id AS mi_code, m.name AS mi_name
           FROM ref_recipe_packaging_bindings b
           JOIN ref_mi m ON m.id = b.mi_id_fk
          WHERE b.recipe_id IN ({$inPlace})
            AND (b.effective_until IS NULL OR b.effective_until >= CURDATE())"
    );
    $bindStmt->execute($allRecipeIds);
    $bindRows = $bindStmt->fetchAll(PDO::FETCH_ASSOC);

    $bindingsByRecipe = [];
    foreach ($bindRows as $r) {
        $rid = (int) $r['recipe_id'];
        $bindingsByRecipe[$rid][$r['role']] = [
            'mi_id_fk' => (int)    $r['mi_id_fk'],
            'mi_code'  => (string) $r['mi_code'],
            'mi_name'  => (string) $r['mi_name'],
        ];
    }

    // Beer-specific slot defs
    $slotRows = $pdo->query(
        "SELECT DISTINCT slot_name AS role, mi_filter_pattern, slot_scope
           FROM ref_packaging_items
          WHERE mi_filter_pattern LIKE '%{beer}%'"
    )->fetchAll(PDO::FETCH_ASSOC);
    $slotDefs = [];
    foreach ($slotRows as $r) {
        $role = $r['role'];
        // Keep first occurrence per role+scope combination
        if (!isset($slotDefs[$role])) {
            $slotDefs[$role] = [
                'role'    => $role,
                'pattern' => $r['mi_filter_pattern'],
                'scope'   => $r['slot_scope'],
            ];
        }
    }

    // Assemble per-recipe data
    $recipeFormatsData = [];
    foreach ($activatableRecs as $rec) {
        $rid    = (int) $rec['id'];
        $prefix = (string) $rec['sku_prefix'];

        // Build candidate MIs per role for this recipe
        $roleCandidates = [];
        foreach ($slotDefs as $roleDef) {
            $rawPat = (string) $roleDef['pattern'];
            if (str_contains($rawPat, '(TRANSP|{beer})')) {
                // Scotch alternation pattern: PKG_SCOTCH_(TRANSP|{beer})%
                // Plain str_replace yields PKG_SCOTCH_(TRANSP|ZEP)% — parens/pipe are
                // NOT LIKE wildcards and match nothing. Build two separate LIKE clauses
                // OR'd so both PKG_SCOTCH_TRANSP and PKG_SCOTCH_{prefix} are returned.
                $brandedPat = 'PKG_SCOTCH_' . $prefix . '%';
                $miCandidateStmt = $pdo->prepare(
                    "SELECT id, mi_id, name FROM ref_mi
                      WHERE mi_id LIKE 'PKG_SCOTCH_TRANSP%' OR mi_id LIKE ?
                      ORDER BY name LIMIT 20"
                );
                $miCandidateStmt->execute([$brandedPat]);
            } else {
                // Standard substitution: PKG_LABEL_{beer}%, PKG_STICKER_{beer}%, etc.
                $pattern = str_replace('{beer}', $prefix, $rawPat);
                $miCandidateStmt = $pdo->prepare(
                    "SELECT id, mi_id, name FROM ref_mi WHERE mi_id LIKE ? ORDER BY name LIMIT 20"
                );
                $miCandidateStmt->execute([$pattern]);
            }
            $candidates = $miCandidateStmt->fetchAll(PDO::FETCH_ASSOC);
            $roleCandidates[$roleDef['role']] = array_map(fn($m) => [
                'id'   => (int)    $m['id'],
                'code' => (string) $m['mi_id'],
                'name' => (string) $m['name'],
            ], $candidates);
        }

        // Compute expected sku_code per gated format for this recipe
        $expectedSkus = [];
        foreach ($gatedFormats as $f) {
            $expectedSkus[$f['id']] = $f['format_code'] === 'X'
                ? $prefix . '-X' : $prefix . $f['format_code'];
        }

        $recipeFormatsData[$rid] = [
            'id'              => $rid,
            'name'            => (string) $rec['name'],
            'subtype'         => (string) $rec['subtype'],
            'sku_prefix'      => $prefix,
            'skus'            => $skusByRecipe[$rid] ?? [],
            'bindings'        => $bindingsByRecipe[$rid] ?? [],
            'role_candidates' => $roleCandidates,
            'expected_skus'   => $expectedSkus,
        ];
    }

    $formatsData = [
        'gated_formats'       => $gatedFormats,
        'gated_format_ids'    => $gatedFormatIds,
        'bom_by_format_id'    => $bomByFormatId,
        'activatable_recipes' => array_map(fn($r) => [
            'id'        => (int)    $r['id'],
            'name'      => (string) $r['name'],
            'subtype'   => (string) $r['subtype'],
            'sku_prefix'=> (string) $r['sku_prefix'],
        ], $activatableRecs),
        'no_prefix_recipes'   => array_map(fn($r) => [
            'id'      => (int)    $r['id'],
            'name'    => (string) $r['name'],
            'subtype' => (string) $r['subtype'],
        ], $noPrefix),
        'slot_defs'           => array_values($slotDefs),
        'recipe_data'         => $recipeFormatsData,
    ];

} catch (Throwable $e) {
    $formatsLoadErr = $e->getMessage();
    $formatsData    = null;
}

// --- Yeast strains (for per-recipe assignment panel + Biochimie catalog) -----
// Includes new strain-science columns (mig 224) for the per-strain catalog.
// $yeastStrains feeds both the recipe-dropdown and the Biochimie catalog table.
$yeastStrains    = [];
$yeastLoadErr    = null;
try {
    $pdo = maltytask_pdo();
    $ysStmt = $pdo->query(
        "SELECT id, name, supplier, family, type, is_active,
                flocculation, attenuation_min, attenuation_max, temp_min, temp_max
           FROM ref_yeast_strains
          WHERE is_active = 1
          ORDER BY name"
    );
    $yeastStrains = $ysStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $yeastLoadErr = $e->getMessage();
}

// --- Per-recipe yeast resolutions (for all lifecycle recipes) ----------------
// Keyed by recipe id. Covers actives + passées + contrats so that archived and
// contract recipes can have their yeast strain classified in the SDC panel.
// Silently skips on DB error (panel shows "no data" state).
$recipeYeastData  = [];
$recipeYeastError = null;
try {
    $pdo = maltytask_pdo();
    if (!empty($allLifecycleIds)) {
        $allRecIds = $allLifecycleIds;
        $inPlace2  = implode(',', array_fill(0, count($allRecIds), '?'));

        // Build all recipe yeast resolutions in one query using the shared fragments.
        // We SELECT r.id plus the eligibility expressions and join via the fragment.
        $selExprs = yeast_eligibility_select_expressions();
        $yeastSql =
            "SELECT r.id AS recipe_id, r.garde_days_min_override, r.ferm_temp_min_override, r.ferm_temp_max_override, "
            . implode(', ', $selExprs)
            . " FROM ref_recipes r "
            . yeast_eligibility_join_fragment()
            . " WHERE r.id IN ({$inPlace2})";
        $yeastStmt = $pdo->prepare($yeastSql);
        $yeastStmt->execute($allRecIds);
        foreach ($yeastStmt->fetchAll(PDO::FETCH_ASSOC) as $yr) {
            $recipeYeastData[(int) $yr['recipe_id']] = $yr;
        }
    }
} catch (Throwable $e) {
    $recipeYeastError = $e->getMessage();
}

// --- Per-recipe QC data (co2_target, co2_tolerance, vol overrides + vol band) -----
// Keyed by recipe id. Covers actives + passées + contrats so archived/contract
// recipes also get QC data in the SDC panel.
$recipeQcData  = [];
$recipeQcError = null;
try {
    $pdo = maltytask_pdo();
    if (!empty($allLifecycleIds)) {
        $allRecIds3 = $allLifecycleIds;
        $inPlace3   = implode(',', array_fill(0, count($allRecIds3), '?'));

        // Load per-recipe QC override columns
        $qcStmt = $pdo->prepare(
            "SELECT id, co2_target, co2_tolerance,
                    racked_vol_warn_lo, racked_vol_warn_hi,
                    racked_vol_outlier_lo, racked_vol_outlier_hi
               FROM ref_recipes
              WHERE id IN ({$inPlace3})"
        );
        $qcStmt->execute($allRecIds3);
        foreach ($qcStmt->fetchAll(PDO::FETCH_ASSOC) as $qcr) {
            $recipeQcData[(int) $qcr['id']] = [
                'co2_target'            => $qcr['co2_target'] !== null ? (float) $qcr['co2_target'] : null,
                'co2_tolerance'         => $qcr['co2_tolerance'] !== null ? (float) $qcr['co2_tolerance'] : null,
                'racked_vol_warn_lo'    => $qcr['racked_vol_warn_lo'] !== null ? (float) $qcr['racked_vol_warn_lo'] : null,
                'racked_vol_warn_hi'    => $qcr['racked_vol_warn_hi'] !== null ? (float) $qcr['racked_vol_warn_hi'] : null,
                'racked_vol_outlier_lo' => $qcr['racked_vol_outlier_lo'] !== null ? (float) $qcr['racked_vol_outlier_lo'] : null,
                'racked_vol_outlier_hi' => $qcr['racked_vol_outlier_hi'] !== null ? (float) $qcr['racked_vol_outlier_hi'] : null,
            ];
        }

        // Load history-derived volume bands (v_recipe_vol_band) — displayed read-only
        $vbStmt = $pdo->prepare(
            "SELECT recipe_id, n, mean_vol, stddev_vol, warn_lo, warn_hi, outlier_lo, outlier_hi
               FROM v_recipe_vol_band
              WHERE recipe_id IN ({$inPlace3})"
        );
        $vbStmt->execute($allRecIds3);
        foreach ($vbStmt->fetchAll(PDO::FETCH_ASSOC) as $vbr) {
            $rid = (int) $vbr['recipe_id'];
            if (!isset($recipeQcData[$rid])) $recipeQcData[$rid] = [];
            $recipeQcData[$rid]['vol_band'] = [
                'n'          => (int)   $vbr['n'],
                'mean_vol'   => (float) $vbr['mean_vol'],
                'stddev_vol' => $vbr['stddev_vol'] !== null ? (float) $vbr['stddev_vol'] : null,
                'warn_lo'    => (float) $vbr['warn_lo'],
                'warn_hi'    => (float) $vbr['warn_hi'],
                'outlier_lo' => (float) $vbr['outlier_lo'],
                'outlier_hi' => (float) $vbr['outlier_hi'],
            ];
        }
    }
} catch (Throwable $e) {
    $recipeQcError = $e->getMessage();
}

// --- Per-recipe style (ref_recipes.style) ------------------------------------
// Keyed by recipe id (int). Only emitted after migration 225 adds the column;
// silently empty if the column is absent or no styles are set.
$recipeStyleData = [];
try {
    $pdo = maltytask_pdo();
    if (!empty($allLifecycleIds)) {
        $inPlaceStyle = implode(',', array_fill(0, count($allLifecycleIds), '?'));
        $styleStmt = $pdo->prepare(
            "SELECT id, style FROM ref_recipes WHERE id IN ({$inPlaceStyle})"
        );
        $styleStmt->execute($allLifecycleIds);
        foreach ($styleStmt->fetchAll(PDO::FETCH_ASSOC) as $sr) {
            if ($sr['style'] !== null) {
                $recipeStyleData[(int) $sr['id']] = (string) $sr['style'];
            }
        }
    }
} catch (Throwable $e) {
    // Column may not exist until migration 225 is applied — silently degrade.
    $recipeStyleData = [];
}

// --- CIP types (ref_cip_types) -----------------------------------------------
$cipTypes    = [];
$cipLoadErr  = null;
try {
    $pdo = maltytask_pdo();
    $cipStmt = $pdo->query(
        "SELECT id, name, sort_order, is_active, notes, updated_at, updated_by
           FROM ref_cip_types
          ORDER BY sort_order ASC, id ASC"
    );
    $cipTypes = $cipStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $cipLoadErr = $e->getMessage();
}

// --- CIP cadence settings (section='cip_cadence') ----------------------------
// Keyed by key_name. Loaded alongside CIP types since the cadence panel lives
// in the same #sec-cip section. Gracefully empty if migration 190 not yet applied.
$cipCadenceByKey = [];
$cipCadenceErr   = null;
try {
    $pdo = maltytask_pdo();
    $cadStmt = $pdo->prepare(
        "SELECT key_name, label_fr, description_fr, value_num, value_text, unit_fr, default_num
           FROM commissioning_settings
          WHERE section = 'cip_cadence' AND is_active = 1
          ORDER BY id ASC"
    );
    $cadStmt->execute();
    foreach ($cadStmt->fetchAll(PDO::FETCH_ASSOC) as $cs) {
        $cipCadenceByKey[$cs['key_name']] = $cs;
    }
} catch (Throwable $e) {
    $cipCadenceErr = $e->getMessage();
}

// --- CO₂/O₂ conformité — in-tank tracker ------------------------------------
$tankBeerList  = [];
$tankSeriesAll = [];
$tankLoadErr   = null;
try {
    $pdoTank       = maltytask_pdo();
    $tankBeerList  = qa_tank_beer_list($pdoTank);
    $tankSeriesAll = qa_tank_series_all($pdoTank);
} catch (Throwable $e) {
    $tankLoadErr = $e->getMessage();
}

$minDaysSetting = $settingsByKey['min_days_after_racking'] ?? null;
$minDaysCurrent = $minDaysSetting !== null
    ? (float) ($minDaysSetting['value_num'] ?? $minDaysSetting['default_num'] ?? 1)
    : 1.0;
$minDaysInt = (int) round($minDaysCurrent);

// --- Brewhouse size (ref_brewhouse_size — canonical brew volume for per-brassin display) ----
$brassinsHl = 30.0; // fallback if table missing
try {
    $bsRow = maltytask_pdo()->query(
        "SELECT size_hl FROM ref_brewhouse_size WHERE effective_until IS NULL ORDER BY effective_from DESC LIMIT 1"
    )->fetch(PDO::FETCH_ASSOC);
    if ($bsRow && $bsRow['size_hl'] > 0) {
        $brassinsHl = (float) $bsRow['size_hl'];
    }
} catch (Throwable $_) { /* use fallback */ }

$csrf = csrf_token();

// Active section from query string (for PRG redirect after save)
$sec = $_GET['sec'] ?? '';
$initialSec = in_array($sec, ['recettes', 'biochem', 'conditionnement', 'cip', 'pertes', 'conformite'], true)
    ? $sec : 'recettes';

?><!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Salle de contrôle — Qualité · MaltyTask</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,300..600;1,9..144,400..500&family=DM+Sans:opsz,wght@9..40,300..600&family=JetBrains+Mono:wght@400;500;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/css/app.css?v=<?= @filemtime(__DIR__ . '/../css/app.css') ?: time() ?>">
<link rel="stylesheet" href="/css/salle-de-controle.css?v=<?= @filemtime(__DIR__ . '/../css/salle-de-controle.css') ?: time() ?>">
<script>
<?php if ($formatsData !== null): ?>
window.SDC_FORMATS_DATA = <?= json_encode($formatsData, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
<?php else: ?>
window.SDC_FORMATS_DATA = null;
window.SDC_FORMATS_ERR  = <?= json_encode($formatsLoadErr ?? 'Erreur inconnue', JSON_HEX_TAG | JSON_HEX_AMP) ?>;
<?php endif ?>
window.SDC_CSRF = <?= json_encode($csrf ?? csrf_token(), JSON_HEX_TAG | JSON_HEX_AMP) ?>;
window.SDC_YEAST_STRAINS = <?= json_encode(
    array_map(fn($s) => [
        'id'              => (int)    $s['id'],
        'name'            => (string) $s['name'],
        'supplier'        => (string) ($s['supplier'] ?? ''),
        'family'          => $s['family'],
        'type'            => (string) $s['type'],
        // strain-science columns added in mig 224
        'flocculation'    => $s['flocculation'],
        'attenuation_min' => $s['attenuation_min'] !== null ? (float) $s['attenuation_min'] : null,
        'attenuation_max' => $s['attenuation_max'] !== null ? (float) $s['attenuation_max'] : null,
        'temp_min'        => $s['temp_min']        !== null ? (float) $s['temp_min']        : null,
        'temp_max'        => $s['temp_max']        !== null ? (float) $s['temp_max']        : null,
    ], $yeastStrains),
    JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP
) ?>;
window.SDC_RECIPE_YEAST  = <?= json_encode($recipeYeastData,  JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
window.SDC_RECIPE_QC     = <?= json_encode($recipeQcData,     JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
window.SDC_RECIPE_STYLES = <?= json_encode($recipeStyleData,  JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
window.SDC_YEAST_FAMILY_LABELS = <?= json_encode(YEAST_FAMILY_LABELS, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
window.SDC_CIP_TYPES = <?= json_encode(
    array_map(fn($ct) => [
        'id'         => (int)    $ct['id'],
        'name'       => (string) $ct['name'],
        'sort_order' => (int)    $ct['sort_order'],
        'is_active'  => (int)    $ct['is_active'],
        'notes'      => $ct['notes'] !== null ? (string) $ct['notes'] : null,
        'updated_by' => $ct['updated_by'] !== null ? (string) $ct['updated_by'] : null,
    ], $cipTypes),
    JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP
) ?>;
window.SDC_BRASSIN_HL  = <?= json_encode($brassinsHl) ?>;
window.SDC_INGREDIENTS = <?= json_encode($ingredientsData, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
window.SDC_PROFILES = {};
window.SDC_TANK_BEERS  = <?= json_encode($tankBeerList,  JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
window.SDC_TANK_SERIES = <?= json_encode($tankSeriesAll, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
<?php if ($tankLoadErr): ?>
window.SDC_TANK_ERR = <?= json_encode($tankLoadErr, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
<?php else: ?>
window.SDC_TANK_ERR = null;
<?php endif ?>
</script>
</head>
<body class="sdc-page" data-role="<?= htmlspecialchars($me['role'] ?? 'operateur') ?>">

<div class="board"><div class="scanlines"></div></div>
<span class="mark tl"></span><span class="mark tr"></span><span class="mark bl"></span><span class="mark br"></span>

<!-- CHROME -->
<div class="chrome">
  <div class="brandmark">La Nébuleuse · Le Zeppelin · <b>Salle de contrôle</b></div>
  <div class="family-switcher">
    <a class="family-btn fam-sdm" href="/modules/salle-des-machines.php" title="Salle des Machines">
      <span class="fam-dot"></span>Machines
    </a>
    <span class="family-btn fam-sdc">
      <span class="fam-dot"></span>Contrôle
    </span>
    <a class="family-btn fam-cockpit" href="/modules/le-zeppelin.php" title="Le Zeppelin — hub">
      <span class="fam-dot"></span>Cockpit
    </a>
  </div>
  <div class="sdc-user-info">
    <span class="sdc-role-pill sdc-role-pill--<?= htmlspecialchars($me['role'] ?? 'operateur') ?>">
      <?= htmlspecialchars(ucfirst($me['role'] ?? 'opérateur')) ?>
    </span>
    <span class="sdc-username"><?= htmlspecialchars($me['username'] ?? '') ?></span>
  </div>
</div>

<div class="toast" id="sdcToast"></div>

<!-- MAIN STAGE -->
<div class="sdc-stage">

  <!-- LEFT NAV -->
  <nav class="nav-rail">
    <div class="nav-section-label">Salle de contrôle</div>

    <div style="padding:0 12px 12px;display:flex;justify-content:center;">
      <svg class="lab-sketch draw-lab" width="160" height="80" viewBox="0 0 160 80">
        <path class="ink" d="M44 14 L44 44 Q44 58 56 60 L72 60 Q84 58 84 44 L84 14"/>
        <path class="ink-2" d="M38 14 L90 14"/>
        <path class="ink-2" d="M44 36 Q64 32 84 36"/>
        <path class="fillx" d="M44 36 Q64 32 84 36 L84 44 Q84 58 72 60 L56 60 Q44 58 44 44 Z"/>
        <path class="band-lab" d="M44 36 Q64 32 84 36"/>
        <path class="ink" d="M110 56 L116 34 L118 18 M122 18 L124 34 L130 56"/>
        <path class="ink-2" d="M113 56 Q120 60 127 56"/>
        <path class="ink-2" d="M116 18 L124 18"/>
        <path class="fillx" d="M116 34 L110 56 Q120 62 130 56 L124 34 Z"/>
        <circle class="ink-2" cx="148" cy="34" r="12"/>
        <path class="band-lab" d="M148 34 L148 28"/>
        <path class="ink-2" d="M142 34 L136 34"/>
        <path class="ink-2" d="M154 34 L160 34"/>
        <path class="shade" d="M146 28 L148 34 L150 28"/>
        <text class="dim-lab" x="143" y="50">pH</text>
      </svg>
    </div>

    <div class="nav-section-label" style="margin-top:4px;">Sections</div>
    <div class="nav-item" data-sec="recettes" onclick="switchSection('recettes')">
      <span class="nav-icon">
        <svg width="16" height="16" viewBox="0 0 16 16" fill="none">
          <rect x="2" y="2" width="12" height="12" rx="2" stroke="currentColor" stroke-width="1.2"/>
          <path d="M5 6h6M5 8.5h4" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/>
        </svg>
      </span>
      Recettes
      <span class="nav-badge">9</span>
    </div>
    <div class="nav-item" data-sec="biochem" onclick="switchSection('biochem')">
      <span class="nav-icon">
        <svg width="16" height="16" viewBox="0 0 16 16" fill="none">
          <path d="M5 2v5L2 13h12L11 7V2" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round"/>
          <path d="M5 2h6" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/>
          <circle cx="8" cy="10" r="1.5" fill="currentColor" opacity=".5"/>
        </svg>
      </span>
      Biochimie
    </div>
    <div class="nav-item" data-sec="conditionnement" onclick="switchSection('conditionnement')">
      <span class="nav-icon">
        <svg width="16" height="16" viewBox="0 0 16 16" fill="none">
          <rect x="2" y="6" width="12" height="8" rx="1.5" stroke="currentColor" stroke-width="1.2"/>
          <path d="M5 6V4a3 3 0 0 1 6 0v2" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/>
          <path d="M8 9v2" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/>
          <circle cx="8" cy="9" r=".8" fill="currentColor"/>
        </svg>
      </span>
      Conditionnement
    </div>
    <div class="nav-item" data-sec="cip" onclick="switchSection('cip')">
      <span class="nav-icon">
        <svg width="16" height="16" viewBox="0 0 16 16" fill="none">
          <circle cx="8" cy="8" r="5.5" stroke="currentColor" stroke-width="1.2"/>
          <path d="M5.5 8c0-.7.3-1.3.8-1.7M10.5 8a2.5 2.5 0 0 1-5 0" stroke="currentColor" stroke-width="1.1" stroke-linecap="round"/>
          <path d="M8 3.5v1M8 11.5v1M3.5 8h-1M13.5 8h-1" stroke="currentColor" stroke-width="1.1" stroke-linecap="round"/>
        </svg>
      </span>
      CIP
    </div>

    <div class="nav-item" data-sec="pertes" onclick="switchSection('pertes')">
      <span class="nav-icon">
        <svg width="16" height="16" viewBox="0 0 16 16" fill="none">
          <path d="M8 2v4M8 2L6 4M8 2l2 2" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round"/>
          <path d="M3 8h10M3 12h10" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" opacity=".5"/>
          <path d="M5 8l3 4 3-4" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
      </span>
      Pertes
    </div>

    <div class="nav-item" data-sec="conformite" onclick="switchSection('conformite')">
      <span class="nav-icon">
        <svg width="16" height="16" viewBox="0 0 16 16" fill="none">
          <circle cx="8" cy="8" r="5.5" stroke="currentColor" stroke-width="1.2"/>
          <path d="M5.5 9.5 L7 11 L10.5 7" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
      </span>
      CO₂/O₂ conformité
    </div>

    <div class="nav-section-label" style="margin-top:16px;">À venir</div>
    <div class="nav-item" style="opacity:.4;pointer-events:none;">
      <span class="nav-icon">
        <svg width="16" height="16" viewBox="0 0 16 16" fill="none">
          <circle cx="8" cy="8" r="6" stroke="currentColor" stroke-width="1.2"/>
          <path d="M8 5v3l2 1.5" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/>
        </svg>
      </span>
      QA / Contrôles
      <span style="margin-left:auto;font-family:'JetBrains Mono',monospace;font-size:8px;letter-spacing:.12em;text-transform:uppercase;color:var(--ink-faint);">Q3</span>
    </div>
    <div class="nav-item" style="opacity:.4;pointer-events:none;">
      <span class="nav-icon">
        <svg width="16" height="16" viewBox="0 0 16 16" fill="none">
          <path d="M3 3h10v10H3z" stroke="currentColor" stroke-width="1.2" stroke-linejoin="round"/>
          <path d="M6 8h4M8 6v4" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/>
        </svg>
      </span>
      Jalons process
      <span style="margin-left:auto;font-family:'JetBrains Mono',monospace;font-size:8px;letter-spacing:.12em;text-transform:uppercase;color:var(--ink-faint);">Q4</span>
    </div>
  </nav>

  <!-- CONTENT AREA -->
  <div class="content-area">

    <!-- ════════════════════════════════ RECETTES SECTION -->
    <div class="section-panel" id="sec-recettes">
      <?php
      // Flash for recettes (format activate/deactivate/binding actions)
      if ($initialSec === 'recettes') {
          $flashMsg = flash_pop();
          if ($flashMsg): ?>
          <div class="sdc-flash sdc-flash--<?= $flashMsg['type'] === 'ok' ? 'ok' : 'err' ?>"
               style="position:absolute;top:76px;left:240px;right:20px;z-index:10;">
            <?= $flashMsg['type'] === 'ok' ? '✓' : '⚠' ?> <?= htmlspecialchars($flashMsg['msg']) ?>
          </div>
          <?php endif;
      }
      ?>
      <div class="recettes-layout">

        <!-- recipe list column — three lifecycle sections -->
        <div class="recipe-list-col">
          <div class="col-header">
            <div class="col-title">Recettes</div>
            <div class="col-subtitle">Nébuleuse · actives · passées · contrats</div>
          </div>
          <div class="recipe-scroll" id="recipeScroll"></div>
          <?php if (is_admin($me) || is_manager($me)): ?>
          <button class="btn-new-recipe" id="btnNewRecipe" onclick="openNewRecipeModal()">
            <span class="btn-plus">+</span>
            <span id="newRecipeBtnLabel"><?= is_admin($me) ? 'Nouvelle recette' : 'Demander nouvelle recette' ?></span>
          </button>
          <?php endif ?>
        </div>

        <!-- recipe detail -->
        <div class="recipe-detail-col" id="recipeDetailCol">
          <div class="recipe-detail-header" id="recipeDetailHeader">
            <div>
              <?php if (is_admin($me) || is_manager($me)): ?>
              <input class="rdh-title-input" id="rdh-title" type="text"
                placeholder="Sélectionner une recette"
                maxlength="128" autocomplete="off"
                onblur="onNameBlur(this)" readonly>
              <?php else: ?>
              <div class="rdh-title" id="rdh-title">Sélectionner <em>une recette</em></div>
              <?php endif ?>
              <div class="rdh-style-row">
                <input class="rdh-style-input" id="rdh-style" type="text" placeholder="style — à renseigner" maxlength="64"
                  onblur="onStyleBlur(this)" autocomplete="off"
                  <?= !is_admin($me) && !is_manager($me) ? 'readonly' : '' ?>>

              </div>
              <div class="rdh-meta" id="rdh-meta">—</div>
            </div>
            <div style="margin-left:auto;text-align:right;">
              <div class="rdh-abv" id="rdh-abv">—</div>
              <div class="rdh-abv-label">ABV estimé</div>
            </div>
          </div>

          <div class="subtabs">
            <div class="subtab active" onclick="switchSubtab('ingr')">Ingrédients</div>
            <div class="subtab" onclick="switchSubtab('process')">Process</div>
            <div class="subtab sdc-formats-tab" onclick="switchSubtab('formats')">Formats</div>
            <div class="subtab sdc-yeast-tab" onclick="switchSubtab('yeast')">Levure &amp; garde</div>
          </div>

          <div class="subtab-pane active" id="pane-ingr" style="flex-direction:column;">
            <div style="display:flex;align-items:center;padding:8px 28px 6px;border-bottom:1px solid var(--hairline);flex:0 0 auto;gap:10px;">
              <span style="font-family:'JetBrains Mono',monospace;font-size:9px;letter-spacing:.2em;text-transform:uppercase;color:var(--ink-faint);">Quantités</span>
              <div class="ingr-scale-toggle">
                <button class="ist-btn active" id="istBrassin" onclick="setIngrScale('brassin')">Par brassin · <?= (int) $brassinsHl ?> hl</button>
                <button class="ist-btn" id="istHl" onclick="setIngrScale('hl')">Par hl</button>
              </div>
            </div>
            <div class="ingr-pane" id="ingrPaneContent" style="flex:1;">
              <div style="padding:40px;text-align:center;color:var(--ink-faint);font-family:'JetBrains Mono',monospace;font-size:11px;letter-spacing:.15em;text-transform:uppercase;">Sélectionner une recette</div>
            </div>
          </div>

          <div class="subtab-pane" id="pane-process">
            <div class="process-pane" id="processPaneContent">
              <div style="padding:40px;text-align:center;color:var(--ink-faint);font-family:'JetBrains Mono',monospace;font-size:11px;letter-spacing:.15em;text-transform:uppercase;">Sélectionner une recette</div>
            </div>
          </div>

          <!-- ── FORMATS SUBTAB (live-wired) ───────────────────────────────── -->
          <div class="subtab-pane sdc-fmt-pane" id="pane-formats">
            <?php if ($formatsLoadErr): ?>
              <div class="fmt-err-banner">
                Erreur chargement Formats : <?= htmlspecialchars($formatsLoadErr) ?>
              </div>
            <?php else: ?>
              <div class="fmt-pane-inner" id="fmtPaneInner">
                <div class="fmt-placeholder">
                  <span>Sélectionner une recette</span>
                </div>
              </div>
            <?php endif ?>
          </div>

          <!-- ── LEVURE & GARDE SUBTAB (live-wired) ────────────────────────── -->
          <div class="subtab-pane sdc-yeast-pane" id="pane-yeast">
            <div class="yg-placeholder" id="ygPlaceholder">
              <span style="padding:40px;display:block;text-align:center;color:var(--ink-faint);font-family:'JetBrains Mono',monospace;font-size:11px;letter-spacing:.15em;text-transform:uppercase;">Sélectionner une recette</span>
            </div>

            <?php if ($yeastLoadErr): ?>
              <div class="sdc-flash sdc-flash--err" style="margin:12px 20px;">
                Erreur chargement souches : <?= htmlspecialchars($yeastLoadErr) ?>
              </div>
            <?php else: ?>

            <!-- The actual panel — shown/hidden by JS; recipe_id set dynamically -->
            <div class="yg-panel" id="ygPanel" style="display:none;">
              <form method="post" action="/modules/salle-de-controle.php"
                    class="yg-form" id="ygForm" novalidate>
                <input type="hidden" name="csrf"      value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="action"    value="update_recipe_yeast">
                <input type="hidden" name="recipe_id" value="" id="ygRecipeId">

                <!-- ── Section 1: Strain picker ─────────────────────────── -->
                <div class="yg-section">
                  <div class="yg-section-head">
                    <span class="yg-section-title">Souche levurienne</span>
                    <span class="yg-section-sub">Liaison à la recette · ref_recipes.yeast_strain_id_fk</span>
                  </div>

                  <?php if (is_admin($me) || is_manager($me)): ?>
                  <div class="yg-field-row">
                    <label class="yg-label" for="ygStrainSelect">Souche active</label>
                    <div class="yg-input-wrap">
                      <select name="yeast_strain_id_fk" id="ygStrainSelect"
                              class="yg-select" onchange="ygOnStrainChange(this.value)">
                        <option value="">— aucune souche —</option>
                        <?php
                        // Group by family for readability
                        $strainsByFamily = [];
                        foreach ($yeastStrains as $s) {
                            $fam = $s['family'] ?? '__unset';
                            $strainsByFamily[$fam][] = $s;
                        }
                        $famOrder = ['ale','lager','non_alcool','spontane','mixte','__unset'];
                        foreach ($famOrder as $fam):
                            if (empty($strainsByFamily[$fam])) continue;
                            $grpLabel = $fam === '__unset'
                                ? 'Famille non classifiée'
                                : (YEAST_FAMILY_LABELS[$fam] ?? $fam);
                        ?>
                        <optgroup label="<?= htmlspecialchars($grpLabel) ?>">
                          <?php foreach ($strainsByFamily[$fam] as $s): ?>
                          <option value="<?= (int) $s['id'] ?>"><?= htmlspecialchars($s['name']) ?></option>
                          <?php endforeach ?>
                        </optgroup>
                        <?php endforeach ?>
                      </select>
                    </div>
                  </div>
                  <?php else: ?>
                  <!-- Read-only for operators -->
                  <div class="yg-field-row">
                    <span class="yg-label">Souche</span>
                    <span class="yg-readval" id="ygStrainReadOnly">—</span>
                  </div>
                  <?php endif ?>
                </div>

                <!-- ── Section 2: Family classifier ─────────────────────── -->
                <div class="yg-section" id="ygFamilySection" style="display:none;">
                  <div class="yg-section-head">
                    <span class="yg-section-title">Famille de la souche</span>
                    <span class="yg-section-sub">
                      Attribut de la souche — s'applique à <em>toutes</em> les recettes utilisant cette souche
                      · ref_yeast_strains.family
                    </span>
                  </div>

                  <?php if (is_admin($me) || is_manager($me)): ?>
                  <div class="yg-field-row">
                    <label class="yg-label" for="ygFamilySelect">Famille</label>
                    <div class="yg-input-wrap yg-family-wrap">
                      <select name="strain_family" id="ygFamilySelect" class="yg-select">
                        <option value="">— ne pas modifier —</option>
                        <?php foreach (YEAST_FAMILIES as $fam): ?>
                        <option value="<?= htmlspecialchars($fam) ?>">
                          <?= htmlspecialchars(YEAST_FAMILY_LABELS[$fam] ?? $fam) ?>
                        </option>
                        <?php endforeach ?>
                      </select>
                      <span class="yg-global-notice">⚠ Modifie la souche globalement</span>
                    </div>
                  </div>
                  <?php else: ?>
                  <div class="yg-field-row">
                    <span class="yg-label">Famille</span>
                    <span class="yg-readval" id="ygFamilyReadOnly">—</span>
                  </div>
                  <?php endif ?>
                </div>

                <!-- ── Section 3: Family defaults (read-only pills) ──────── -->
                <div class="yg-section" id="ygDefaultsSection" style="display:none;">
                  <div class="yg-section-head">
                    <span class="yg-section-title">Défauts famille</span>
                    <span class="yg-section-sub">Lus depuis ref_yeast_family_defaults · non modifiables ici</span>
                  </div>
                  <div class="xp-grid" id="ygDefaultsGrid">
                    <!-- Populated by JS from SDC_RECIPE_YEAST / SDC_YEAST_STRAINS -->
                  </div>
                </div>

                <!-- ── Section 4: Per-recipe overrides ──────────────────── -->
                <div class="yg-section" id="ygOverridesSection">
                  <div class="yg-section-head">
                    <span class="yg-section-title">Overrides recette</span>
                    <span class="yg-section-sub">Vide = hériter de la famille · remplace le défaut famille si renseigné</span>
                  </div>

                  <?php if (is_admin($me) || is_manager($me)): ?>
                  <div class="yg-overrides-grid">
                    <div class="yg-override-field">
                      <label class="yg-label" for="ygGardeOvr">Garde min. (j)</label>
                      <div class="yg-input-row">
                        <input type="number" name="garde_days_min_override" id="ygGardeOvr"
                               class="yg-num-input" min="0" max="365" step="1" placeholder="—">
                        <span class="yg-unit">j</span>
                        <span class="xf-badge" id="ygGardeBadge"></span>
                      </div>
                      <div class="yg-resolved-line" id="ygGardeResolved">—</div>
                    </div>
                    <div class="yg-override-field">
                      <label class="yg-label" for="ygTempMinOvr">Temp. ferm. min (°C)</label>
                      <div class="yg-input-row">
                        <input type="number" name="ferm_temp_min_override" id="ygTempMinOvr"
                               class="yg-num-input" min="0" max="50" step="0.5" placeholder="—">
                        <span class="yg-unit">°C</span>
                        <span class="xf-badge" id="ygTempMinBadge"></span>
                      </div>
                    </div>
                    <div class="yg-override-field">
                      <label class="yg-label" for="ygTempMaxOvr">Temp. ferm. max (°C)</label>
                      <div class="yg-input-row">
                        <input type="number" name="ferm_temp_max_override" id="ygTempMaxOvr"
                               class="yg-num-input" min="0" max="50" step="0.5" placeholder="—">
                        <span class="yg-unit">°C</span>
                        <span class="xf-badge" id="ygTempMaxBadge"></span>
                      </div>
                    </div>
                  </div>

                  <div class="yg-effective-row" id="ygEffectiveRow">
                    <!-- Populated by JS: shows resolved effective values + source badges -->
                  </div>

                  <div class="yg-actions">
                    <button type="submit" class="yg-save-btn">Enregistrer</button>
                    <p class="yg-hint">
                      Vide = NULL = hériter de la famille. Garde min. NULL = hors-process uniquement (souche non classifiée).
                    </p>
                  </div>
                  <?php else: ?>
                  <!-- Operator: read-only resolved values -->
                  <div class="xp-grid" id="ygEffectiveReadOnly">
                    <div style="padding:16px;color:var(--ink-faint);font-family:'JetBrains Mono',monospace;font-size:9px;letter-spacing:.12em;text-transform:uppercase;">Sélectionner une recette pour voir les valeurs effectives.</div>
                  </div>
                  <?php endif ?>
                </div>

              </form>
            </div><!-- /yg-panel -->

            <?php endif ?><!-- /yeastLoadErr -->

            <!-- ── QC THRESHOLDS PANEL (per-recipe CO₂ + vol bands) ─────── -->
            <!-- Shown/hidden by JS alongside the yeast panel. Separate form,
                 separate action (update_recipe_qc). Always inside pane-yeast
                 so it appears in the same subtab view as Levure & garde. -->
            <?php if ($recipeQcError): ?>
              <div class="sdc-flash sdc-flash--err" style="margin:12px 20px;">
                Erreur chargement seuils QC : <?= htmlspecialchars($recipeQcError) ?>
              </div>
            <?php endif ?>

            <div class="yg-panel" id="qcPanel" style="display:none;margin-top:0;border-top:1px solid var(--hairline);">
              <form method="post" action="/modules/salle-de-controle.php"
                    class="yg-form" id="qcForm" novalidate>
                <input type="hidden" name="csrf"      value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="action"    value="update_recipe_qc">
                <input type="hidden" name="recipe_id" value="" id="qcRecipeId">

                <div class="yg-section">
                  <div class="yg-section-head">
                    <span class="yg-section-title">Seuils QC <em>CO₂</em></span>
                    <span class="yg-section-sub">Cible ± tolérance → bandes warn/outlier · ref_recipes.co2_target / co2_tolerance</span>
                  </div>

                  <!-- History-derived vol band: read-only context display -->
                  <div class="yg-section" id="qcVolBandInfo" style="background:var(--bg);border:1px solid var(--hairline);border-radius:6px;padding:10px 14px;margin-bottom:10px;display:none;">
                    <div class="yg-section-head" style="margin-bottom:6px;">
                      <span class="yg-section-title" style="font-size:11px;">Volume historique <span style="font-weight:400;font-style:italic;">(auto-dérivé, lecture seule)</span></span>
                    </div>
                    <div id="qcVolBandDisplay" style="font-family:'JetBrains Mono',monospace;font-size:10px;color:var(--ink-soft);letter-spacing:.04em;">—</div>
                  </div>

                  <?php if (is_admin($me) || is_manager($me)): ?>
                  <div class="yg-overrides-grid">
                    <div class="yg-override-field">
                      <label class="yg-label" for="qcCo2Target">CO₂ cible</label>
                      <div class="yg-input-row">
                        <input type="number" name="co2_target" id="qcCo2Target"
                               class="yg-num-input" min="0" max="15" step="0.01" placeholder="—">
                        <span class="yg-unit">g/L</span>
                      </div>
                      <div class="yg-resolved-line" style="font-size:10px;color:var(--ink-mute);">Vide = bandes globales commissioning</div>
                    </div>
                    <div class="yg-override-field">
                      <label class="yg-label" for="qcCo2Tolerance">Tolérance</label>
                      <div class="yg-input-row">
                        <input type="number" name="co2_tolerance" id="qcCo2Tolerance"
                               class="yg-num-input" min="0" max="5" step="0.01" placeholder="—">
                        <span class="yg-unit">g/L</span>
                      </div>
                      <div class="yg-resolved-line" style="font-size:10px;color:var(--ink-mute);">Warn = ±1×tol · Outlier = ±2×tol</div>
                    </div>
                  </div>

                  <!-- Derived band preview (read-only, updated by JS) -->
                  <div id="qcCo2Preview" class="yg-effective-row" style="display:none;font-family:'JetBrains Mono',monospace;font-size:10px;padding:6px 0 2px;color:var(--ink-soft);"></div>

                  <div class="yg-section-head" style="margin-top:14px;margin-bottom:4px;">
                    <span class="yg-section-title">Seuils volume <em>override</em> <span style="font-weight:400;font-style:italic;font-size:10px;">(laisser vide = auto depuis historique)</span></span>
                    <span class="yg-section-sub">Remplacement de la bande historique · les 4 champs doivent être remplis ou tous laissés vides</span>
                  </div>
                  <div class="yg-overrides-grid" style="grid-template-columns:repeat(4,1fr);">
                    <div class="yg-override-field">
                      <label class="yg-label" for="qcVolWarnLo">Warn lo</label>
                      <div class="yg-input-row">
                        <input type="number" name="racked_vol_warn_lo" id="qcVolWarnLo"
                               class="yg-num-input" min="0" max="500" step="0.5" placeholder="—">
                        <span class="yg-unit">HL</span>
                      </div>
                    </div>
                    <div class="yg-override-field">
                      <label class="yg-label" for="qcVolWarnHi">Warn hi</label>
                      <div class="yg-input-row">
                        <input type="number" name="racked_vol_warn_hi" id="qcVolWarnHi"
                               class="yg-num-input" min="0" max="500" step="0.5" placeholder="—">
                        <span class="yg-unit">HL</span>
                      </div>
                    </div>
                    <div class="yg-override-field">
                      <label class="yg-label" for="qcVolOutlierLo">Outlier lo</label>
                      <div class="yg-input-row">
                        <input type="number" name="racked_vol_outlier_lo" id="qcVolOutlierLo"
                               class="yg-num-input" min="0" max="500" step="0.5" placeholder="—">
                        <span class="yg-unit">HL</span>
                      </div>
                    </div>
                    <div class="yg-override-field">
                      <label class="yg-label" for="qcVolOutlierHi">Outlier hi</label>
                      <div class="yg-input-row">
                        <input type="number" name="racked_vol_outlier_hi" id="qcVolOutlierHi"
                               class="yg-num-input" min="0" max="500" step="0.5" placeholder="—">
                        <span class="yg-unit">HL</span>
                      </div>
                    </div>
                  </div>

                  <div class="yg-actions">
                    <button type="submit" class="yg-save-btn">Enregistrer seuils QC</button>
                    <p class="yg-hint">
                      CO₂ vide = hériter des bandes globales. Volume vide = auto (historique σ si ≥ 3 transferts, sinon global).
                    </p>
                  </div>
                  <?php else: ?>
                  <!-- Operator: read-only resolved values -->
                  <div id="qcReadOnly" style="padding:16px;color:var(--ink-faint);font-family:'JetBrains Mono',monospace;font-size:9px;letter-spacing:.12em;text-transform:uppercase;">Sélectionner une recette pour voir les seuils QC.</div>
                  <?php endif ?>
                </div>

              </form>
            </div><!-- /qcPanel -->

          </div><!-- /pane-yeast -->

        </div><!-- /recipe-detail-col -->
      </div><!-- /recettes-layout -->
    </div><!-- /sec-recettes -->

    <!-- ════════════════════════════════ BIOCHIMIE SECTION (LIVE) -->
    <div class="section-panel" id="sec-biochem">
      <div class="biochem-layout">
        <div class="biochem-header">
          <h2>Paramètres <em>Biochimie</em></h2>
          <div class="bh-sub">Valeurs par défaut par famille levurienne · ref_yeast_family_defaults · source des héritages Process</div>
        </div>

        <?php if ($initialSec === 'biochem'): ?>
          <?php $flashMsg = flash_pop(); if ($flashMsg): ?>
          <div class="sdc-flash sdc-flash--<?= $flashMsg['type'] === 'ok' ? 'ok' : 'err' ?>"
               style="position:absolute;top:76px;left:240px;right:20px;z-index:10;">
            <?= $flashMsg['type'] === 'ok' ? '✓' : '⚠' ?> <?= htmlspecialchars($flashMsg['msg']) ?>
          </div>
          <?php endif ?>
        <?php endif ?>

        <?php if ($biochemLoadErr): ?>
          <div class="sdc-flash sdc-flash--err">Erreur DB Biochimie : <?= htmlspecialchars($biochemLoadErr) ?></div>
        <?php else: ?>

        <div class="yf-cards" id="yfCards">
          <?php foreach ($yeastFamilies as $yf): ?>
          <?php
            $family       = (string) $yf['family'];
            $labelFr      = (string) $yf['label_fr'];
            $gardeDays    = $yf['garde_days_min'] !== null ? (int) $yf['garde_days_min'] : null;
            $tempMin      = $yf['ferm_temp_min']  !== null ? (float) $yf['ferm_temp_min']  : null;
            $tempMax      = $yf['ferm_temp_max']  !== null ? (float) $yf['ferm_temp_max']  : null;
            $isProduced   = (bool) $yf['is_produced'];
            $cardClass    = 'yf-card ' . htmlspecialchars($family) . ($isProduced ? '' : ' yf-card--non-produite');
          ?>
          <div class="<?= $cardClass ?>">
            <div class="yf-card-head">
              <div class="yf-family-name <?= htmlspecialchars($family) ?>">
                <em><?= htmlspecialchars($labelFr) ?></em>
              </div>
              <?php if (!$isProduced): ?>
                <span class="yf-non-produite-badge">non produite</span>
              <?php endif ?>
              <div class="yf-recipe-count" style="margin-left:auto;">
                <?php if ($yf['updated_by']): ?>
                  <span title="Mis à jour par <?= htmlspecialchars($yf['updated_by']) ?>">
                    ref_yeast_family_defaults
                  </span>
                <?php else: ?>
                  ref_yeast_family_defaults
                <?php endif ?>
              </div>
            </div>

            <div class="yf-defaults">
              <!-- garde_days_min — lagering/cold-crash minimum threshold -->
              <div class="yf-def-row">
                <div class="yf-def-k">Garde min.
                  <span style="font-size:7px;letter-spacing:.08em;opacity:.65;">(cold-crash)</span>
                </div>
                <div class="yf-def-v">
                  <?= $gardeDays !== null ? htmlspecialchars((string) $gardeDays) : '<span style="color:var(--ink-faint);">—</span>' ?>
                  <span class="yf-def-unit">j</span>
                </div>
              </div>

              <!-- ferm_temp_min -->
              <div class="yf-def-row">
                <div class="yf-def-k">Temp. ferm. min</div>
                <div class="yf-def-v">
                  <?= $tempMin !== null ? htmlspecialchars(number_format($tempMin, 1)) : '<span style="color:var(--ink-faint);">—</span>' ?>
                  <span class="yf-def-unit">°C</span>
                </div>
              </div>

              <!-- ferm_temp_max -->
              <div class="yf-def-row">
                <div class="yf-def-k">Temp. ferm. max</div>
                <div class="yf-def-v">
                  <?= $tempMax !== null ? htmlspecialchars(number_format($tempMax, 1)) : '<span style="color:var(--ink-faint);">—</span>' ?>
                  <span class="yf-def-unit">°C</span>
                </div>
              </div>

              <?php if ($tempMin !== null && $tempMax !== null): ?>
              <!-- temp range summary -->
              <div class="yf-def-row" style="grid-column:1/-1;">
                <div class="yf-def-k">Plage ferm.</div>
                <div class="yf-def-v" style="font-size:14px;">
                  <?= htmlspecialchars(number_format($tempMin, 1)) ?>–<?= htmlspecialchars(number_format($tempMax, 1)) ?>
                  <span class="yf-def-unit">°C</span>
                </div>
              </div>
              <?php endif ?>
            </div>

            <?php if (is_admin($me)): ?>
            <!-- ADMIN EDIT FORM -->
            <form method="post" action="/modules/salle-de-controle.php" class="yf-edit-form" novalidate>
              <input type="hidden" name="csrf"   value="<?= htmlspecialchars($csrf) ?>">
              <input type="hidden" name="action" value="update_yeast_family">
              <input type="hidden" name="family" value="<?= htmlspecialchars($family) ?>">

              <div class="yf-edit-grid">
                <div class="yf-edit-field">
                  <div class="yf-edit-label">Garde min. (j)</div>
                  <div class="yf-edit-row">
                    <input
                      type="number"
                      name="garde_days_min"
                      class="yf-edit-input"
                      min="0" max="365" step="1"
                      value="<?= $gardeDays !== null ? htmlspecialchars((string) $gardeDays) : '' ?>"
                      placeholder="—"
                    >
                    <span class="yf-edit-unit">j</span>
                  </div>
                </div>
                <div style=""></div><!-- spacer for 2-col grid -->
                <div class="yf-edit-field">
                  <div class="yf-edit-label">Temp. min (°C)</div>
                  <div class="yf-edit-row">
                    <input
                      type="number"
                      name="ferm_temp_min"
                      class="yf-edit-input"
                      min="0" max="50" step="0.5"
                      value="<?= $tempMin !== null ? htmlspecialchars(number_format($tempMin, 1)) : '' ?>"
                      placeholder="—"
                    >
                    <span class="yf-edit-unit">°C</span>
                  </div>
                </div>
                <div class="yf-edit-field">
                  <div class="yf-edit-label">Temp. max (°C)</div>
                  <div class="yf-edit-row">
                    <input
                      type="number"
                      name="ferm_temp_max"
                      class="yf-edit-input"
                      min="0" max="50" step="0.5"
                      value="<?= $tempMax !== null ? htmlspecialchars(number_format($tempMax, 1)) : '' ?>"
                      placeholder="—"
                    >
                    <span class="yf-edit-unit">°C</span>
                  </div>
                </div>
              </div>

              <div class="yf-edit-actions">
                <button type="submit" class="yf-edit-btn">Enregistrer</button>
                <p class="yf-edit-hint">Vide = NULL (non défini). Garde min = jours min après cold-crash avant transfert.</p>
              </div>
            </form>
            <?php else: ?>
            <p class="cond-readonly-note">Modification réservée aux administrateurs.</p>
            <?php endif ?>

          </div>
          <?php endforeach ?>
        </div>

        <?php endif ?><!-- /biochemLoadErr -->

        <!-- ═══ STRAIN CATALOG ════════════════════════════════════════════════ -->
        <?php if ($yeastLoadErr): ?>
          <div class="sdc-flash sdc-flash--err" style="margin-top:20px;">
            Erreur chargement souches : <?= htmlspecialchars($yeastLoadErr) ?>
          </div>
        <?php else: ?>
        <div class="sc-catalog-header">
          <h3>Catalogue des souches</h3>
          <div class="sc-catalog-sub">
            <?= count($yeastStrains) ?> souches actives ·
            <?= count(array_filter($yeastStrains, fn($s) => $s['family'] === null)) ?> sans famille ·
            ref_yeast_strains
          </div>
        </div>

        <div class="sc-catalog-wrap">
          <table class="sc-catalog-table">
            <thead>
              <tr>
                <th class="sc-th sc-th--name">Souche</th>
                <th class="sc-th sc-th--fournisseur">Fournisseur</th>
                <th class="sc-th sc-th--family">Famille</th>
                <th class="sc-th sc-th--floc">Floculation</th>
                <th class="sc-th sc-th--att">Atténuation (%)</th>
                <th class="sc-th sc-th--temp">Temp. ferm. (°C)</th>
                <?php if (is_admin($me) || is_manager($me)): ?>
                <th class="sc-th sc-th--actions"></th>
                <?php endif ?>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($yeastStrains as $ys):
                $sId      = (int) $ys['id'];
                $sName    = (string) $ys['name'];
                $sSupplier= (string) ($ys['supplier'] ?? '');
                $sFamily  = $ys['family'];
                $sFloc    = $ys['flocculation'];
                $sAttMin  = $ys['attenuation_min'] !== null ? (float) $ys['attenuation_min'] : null;
                $sAttMax  = $ys['attenuation_max'] !== null ? (float) $ys['attenuation_max'] : null;
                $sTMin    = $ys['temp_min']        !== null ? (float) $ys['temp_min']        : null;
                $sTMax    = $ys['temp_max']        !== null ? (float) $ys['temp_max']        : null;
                $isOrphan = ($sFamily === null);
                $rowClass = 'sc-row' . ($isOrphan ? ' sc-row--orphan' : '');
                // French labels for display
                $flocLabels = ['low' => 'Faible', 'medium' => 'Moyenne', 'high' => 'Élevée'];
              ?>
              <tr class="<?= $rowClass ?>" data-strain-id="<?= $sId ?>">
                <td class="sc-td sc-td--name">
                  <?= htmlspecialchars($sName) ?>
                  <?php if ($isOrphan): ?>
                    <span class="sc-orphan-badge">non classée</span>
                  <?php endif ?>
                </td>
                <td class="sc-td sc-td--fournisseur">
                  <?= $sSupplier !== '' ? htmlspecialchars($sSupplier) : '<span class="sc-null">—</span>' ?>
                </td>
                <td class="sc-td sc-td--family">
                  <?php if ($sFamily): ?>
                    <span class="sc-family-chip sc-family-chip--<?= htmlspecialchars($sFamily) ?>">
                      <?= htmlspecialchars(YEAST_FAMILY_LABELS[$sFamily] ?? $sFamily) ?>
                    </span>
                  <?php else: ?>
                    <span class="sc-null">—</span>
                  <?php endif ?>
                </td>
                <td class="sc-td sc-td--floc">
                  <?= $sFloc !== null ? htmlspecialchars($flocLabels[$sFloc] ?? $sFloc) : '<span class="sc-null">—</span>' ?>
                </td>
                <td class="sc-td sc-td--att">
                  <?php if ($sAttMin !== null || $sAttMax !== null): ?>
                    <?= $sAttMin !== null ? htmlspecialchars(number_format($sAttMin, 0)) : '?' ?>
                    –
                    <?= $sAttMax !== null ? htmlspecialchars(number_format($sAttMax, 0)) : '?' ?>
                    <span class="sc-unit">%</span>
                  <?php else: ?>
                    <span class="sc-null">—</span>
                  <?php endif ?>
                </td>
                <td class="sc-td sc-td--temp">
                  <?php if ($sTMin !== null || $sTMax !== null): ?>
                    <?= $sTMin !== null ? htmlspecialchars(number_format($sTMin, 1)) : '?' ?>
                    –
                    <?= $sTMax !== null ? htmlspecialchars(number_format($sTMax, 1)) : '?' ?>
                    <span class="sc-unit">°C</span>
                  <?php else: ?>
                    <span class="sc-null">—</span>
                  <?php endif ?>
                </td>
                <?php if (is_admin($me) || is_manager($me)): ?>
                <td class="sc-td sc-td--actions">
                  <button type="button"
                          class="sc-edit-btn"
                          onclick="scOpenEdit(<?= $sId ?>)"
                          aria-label="Modifier <?= htmlspecialchars($sName) ?>">
                    Modifier
                  </button>
                </td>
                <?php endif ?>
              </tr>
              <?php endforeach ?>
            </tbody>
          </table>
        </div>

        <!-- Strain edit modal -->
        <?php if (is_admin($me) || is_manager($me)): ?>
        <dialog id="scEditDialog" class="sc-edit-dialog">
          <div class="sc-edit-wrap">
            <div class="sc-edit-head">
              <div class="sc-edit-title" id="scEditTitle">Modifier la souche</div>
              <button type="button" class="sc-edit-close" onclick="scCloseEdit()" aria-label="Fermer">×</button>
            </div>
            <form method="post" action="/modules/salle-de-controle.php" class="sc-edit-form" novalidate>
              <input type="hidden" name="csrf"     value="<?= htmlspecialchars($csrf) ?>">
              <input type="hidden" name="action"   value="update_yeast_strain">
              <input type="hidden" name="strain_id" id="scEditStrainId" value="">

              <div class="sc-edit-grid">

                <div class="sc-edit-field">
                  <label class="sc-edit-label" for="scEditFamily">Famille</label>
                  <select name="family" id="scEditFamily" class="sc-edit-select">
                    <option value="">— (non définie)</option>
                    <?php foreach (YEAST_FAMILIES as $fam): ?>
                    <option value="<?= htmlspecialchars($fam) ?>">
                      <?= htmlspecialchars(YEAST_FAMILY_LABELS[$fam] ?? $fam) ?>
                    </option>
                    <?php endforeach ?>
                  </select>
                </div>

                <div class="sc-edit-field">
                  <label class="sc-edit-label" for="scEditFloc">Floculation</label>
                  <select name="flocculation" id="scEditFloc" class="sc-edit-select">
                    <option value="">— (non définie)</option>
                    <option value="low">Faible</option>
                    <option value="medium">Moyenne</option>
                    <option value="high">Élevée</option>
                  </select>
                </div>

                <div class="sc-edit-field"><!-- spacer --></div>

                <div class="sc-edit-field">
                  <label class="sc-edit-label" for="scEditAttMin">Atténuation min (%)</label>
                  <div class="sc-edit-input-row">
                    <input type="number" name="attenuation_min" id="scEditAttMin"
                           class="sc-edit-input" min="0" max="100" step="0.5" placeholder="—">
                    <span class="sc-edit-unit">%</span>
                  </div>
                </div>

                <div class="sc-edit-field">
                  <label class="sc-edit-label" for="scEditAttMax">Atténuation max (%)</label>
                  <div class="sc-edit-input-row">
                    <input type="number" name="attenuation_max" id="scEditAttMax"
                           class="sc-edit-input" min="0" max="100" step="0.5" placeholder="—">
                    <span class="sc-edit-unit">%</span>
                  </div>
                </div>

                <div class="sc-edit-field">
                  <label class="sc-edit-label" for="scEditTMin">Temp. min (°C)</label>
                  <div class="sc-edit-input-row">
                    <input type="number" name="temp_min" id="scEditTMin"
                           class="sc-edit-input" min="0" max="50" step="0.5" placeholder="—">
                    <span class="sc-edit-unit">°C</span>
                  </div>
                </div>

                <div class="sc-edit-field">
                  <label class="sc-edit-label" for="scEditTMax">Temp. max (°C)</label>
                  <div class="sc-edit-input-row">
                    <input type="number" name="temp_max" id="scEditTMax"
                           class="sc-edit-input" min="0" max="50" step="0.5" placeholder="—">
                    <span class="sc-edit-unit">°C</span>
                  </div>
                </div>

              </div><!-- /sc-edit-grid -->

              <div class="sc-edit-hint">Vide = NULL (non défini). La famille mise à jour ici est la même colonne que celle câblée dans Recettes → Levure &amp; garde.</div>

              <div class="sc-edit-actions">
                <button type="button" class="sc-edit-cancel" onclick="scCloseEdit()">Annuler</button>
                <button type="submit" class="sc-edit-save">Enregistrer</button>
              </div>
            </form>
          </div>
        </dialog>
        <?php endif ?>

        <?php endif ?><!-- /yeastLoadErr -->

        <!-- Pending items still to wire -->
        <div class="pending-block" style="margin-top:24px;">
          <div class="pending-block-head">Champs encore en attente · à câbler</div>
          <div class="pending-field-list">
            <span class="pf-pill">target_co2_vol (ref_recipe_profile)</span>
          </div>
          <div style="margin-top:8px;font-family:'JetBrains Mono',monospace;font-size:8px;color:var(--lab);letter-spacing:.08em;">
            ✓ yeast_strain_id_fk, garde_days_min_override, ferm_temp_*_override — câblés dans Recettes → Levure &amp; garde
          </div>
        </div>

        <div class="impl-note" style="margin-top:16px;">
          <div class="impl-note-head">Persistance &amp; audit</div>
          <div class="impl-note-body">
            Les valeurs par famille sont lues depuis <code>ref_yeast_family_defaults</code>.
            Chaque modification est journalisée dans <code>audit_row_revisions</code>
            avec horodatage et auteur.
            La garde min. est le seuil KPI : jours minimum après cold-crash avant que le transfert
            soit autorisé par le formulaire de soutirage.
          </div>
        </div>

      </div>
    </div>

    <!-- ════════════════════════════════ CONDITIONNEMENT SECTION (LIVE) -->
    <div class="section-panel" id="sec-conditionnement">
      <div class="cond-layout">
        <div class="cond-header">
          <h2>Paramètres <em>Conditionnement</em></h2>
          <div class="ch-sub">Seuils process · Soutirage → Conditionnement · commissioning_settings</div>
        </div>

        <?php if ($loadErr): ?>
          <div class="sdc-flash sdc-flash--err">Erreur DB : <?= htmlspecialchars($loadErr) ?></div>
        <?php endif ?>

        <?php /* Flash messages from PRG redirect */ ?>
        <?php $flashMsg = flash_pop(); if ($flashMsg): ?>
          <div class="sdc-flash sdc-flash--<?= $flashMsg['type'] === 'ok' ? 'ok' : 'err' ?>">
            <?= $flashMsg['type'] === 'ok' ? '✓' : '⚠' ?> <?= htmlspecialchars($flashMsg['msg']) ?>
          </div>
        <?php endif ?>

        <div class="cond-cards">

          <!-- Délai soutirage → conditionnement — LIVE EDIT CARD -->
          <div class="cond-card">
            <div class="cc-head">
              <div class="cc-icon">
                <svg width="18" height="18" viewBox="0 0 18 18" fill="none">
                  <circle cx="9" cy="9" r="7" stroke="currentColor" stroke-width="1.3"/>
                  <path d="M9 5v4l2.5 2" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/>
                </svg>
              </div>
              <div>
                <div class="cc-title">Délai soutirage</div>
                <div class="cc-sub">Seuil d'éligibilité conditionnement</div>
              </div>
            </div>

            <div class="cond-setting-row">
              <div class="csr-label">
                Conditionnement autorisé
                <small>jours après soutirage (CC)</small>
              </div>
              <div style="display:flex;align-items:baseline;gap:4px;">
                <span class="csr-value" id="csr-days-display"><?= $minDaysInt ?></span><span class="csr-unit">j</span>
                <?php if ($minDaysSetting && $minDaysSetting['default_num'] !== null): ?>
                  <span class="csr-default-note">(défaut: <?= (int) $minDaysSetting['default_num'] ?>)</span>
                <?php endif ?>
              </div>
            </div>

            <?php if ($migrationApplied && $minDaysSetting && $minDaysSetting['description_fr']): ?>
            <p class="cond-note">
              <?= htmlspecialchars($minDaysSetting['description_fr']) ?>
            </p>
            <?php else: ?>
            <div class="cond-note">
              Un lot peut être conditionné dès qu'il a passé <b>au moins <?= $minDaysInt ?> jour(s)</b>
              en CC/BBT après soutirage. Le formulaire n'affiche que les lots dont le délai est atteint.
            </div>
            <?php endif ?>

            <?php if (!$migrationApplied): ?>
              <div class="sdc-flash sdc-flash--err" style="margin-top:12px;">Migration 128 non appliquée — paramètre indisponible.</div>
            <?php elseif (is_admin($me)): ?>
              <!-- LIVE EDIT FORM — admin only -->
              <form method="post" action="/modules/salle-de-controle.php" class="cond-edit-form" novalidate>
                <input type="hidden" name="csrf"   value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="action" value="update_min_days">
                <div class="cond-edit-row">
                  <label class="cond-edit-label" for="min_days_after_racking">
                    Nouveau délai
                    <span class="cond-edit-unit">(<?= htmlspecialchars($minDaysSetting['unit_fr'] ?? 'jours') ?>)</span>
                  </label>
                  <input
                    id="min_days_after_racking"
                    name="min_days_after_racking"
                    type="number"
                    min="0"
                    max="365"
                    step="1"
                    class="cond-edit-input"
                    value="<?= htmlspecialchars((string) $minDaysInt) ?>"
                    required
                  >
                  <button type="submit" class="cond-edit-btn">Enregistrer</button>
                </div>
                <p class="cond-edit-hint">
                  0 = aucune restriction temporelle (tests uniquement).
                  Valeur habituelle : 1 (lot soutiré hier = éligible aujourd'hui).
                  L'override "Choix Hors Process" sur le formulaire permet un bypass ponctuel sans modifier ce seuil global.
                </p>
              </form>
            <?php else: ?>
              <p class="cond-readonly-note">
                Modification réservée aux administrateurs.
              </p>
            <?php endif ?>
          </div>

          <!-- Grille de lecture rapide — Lots éligibles -->
          <div class="cond-card">
            <div class="cc-head">
              <div class="cc-icon">
                <svg width="18" height="18" viewBox="0 0 18 18" fill="none">
                  <path d="M3 5h12M3 9h8M3 13h5" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/>
                  <circle cx="14" cy="13" r="2.5" stroke="currentColor" stroke-width="1.2"/>
                  <path d="M14 12v1l.6.6" stroke="currentColor" stroke-width="1.1" stroke-linecap="round"/>
                </svg>
              </div>
              <div>
                <div class="cc-title">Lots éligibles</div>
                <div class="cc-sub">Logique de gate process</div>
              </div>
            </div>
            <div class="cond-setting-row">
              <div class="csr-label">
                Lots affichés dans le formulaire
                <small>état éligible uniquement</small>
              </div>
              <div>
                <span style="font-family:'JetBrains Mono',monospace;font-size:10px;letter-spacing:.1em;color:var(--lab);background:rgba(74,140,120,.12);border:1px solid rgba(74,140,120,.2);border-radius:6px;padding:4px 10px;">soutirage ≥ seuil</span>
              </div>
            </div>
            <div class="cond-note">
              Le gate compare <b>DATE(submitted_at)</b> du soutirage avec la date courante.
              Seuls les lots ayant passé le seuil sont proposés dans le menu déroulant —
              les autres restent invisibles pour l'opérateur jusqu'à éligibilité.
            </div>
          </div>
        </div><!-- /cond-cards -->

        <!-- Choix Hors Process / override gate -->
        <div class="override-gate-card">
          <div class="ogc-head">
            <span class="ogc-pip"></span>
            <span class="ogc-label">Choix Hors Process — gate d'override</span>
          </div>
          <div class="ogc-body">
            <b>Managers</b> <span class="role-pill mgr">Manager</span> et <b>Admins</b> <span class="role-pill adm">Admin</span>
            peuvent conditionner un lot avant que le délai réglementaire soit atteint
            (urgence commerciale, erreur de planification, test). Ce choix est signalé
            explicitement dans le formulaire et <b>marque le record</b> avec un flag
            <code>hors_process = 1</code>
            persisté en DB pour audit.<br><br>
            <b>Opérateurs</b> <span class="role-pill opr">Opérateur</span> ne voient
            que les lots éligibles — le gate leur est opaque, aucune action hors process
            n'est exposée dans leur interface.
          </div>
        </div>

        <!-- Override / audit note -->
        <div class="impl-note">
          <div class="impl-note-head">Persistance &amp; audit</div>
          <div class="impl-note-body">
            Les seuils sont lus depuis <code>commissioning_settings</code>
            (clé <code>min_days_after_racking</code>, section <code>packaging</code>).
            Chaque modification est journalisée dans <code>audit_row_revisions</code>
            avec horodatage et auteur. Le flag <code>hors_process</code>
            alimente le journal des lots et sera visible dans la future section
            QA / Contrôles (Q3).
          </div>
        </div>
      </div>
    </div><!-- /sec-conditionnement -->

    <!-- ════════════════════════════════ CIP SECTION (LIVE) -->
    <div class="section-panel" id="sec-cip">
      <div class="cip-layout">
        <div class="cip-header">
          <h2>Types <em>CIP</em></h2>
          <div class="cip-header-sub">Liste de référence · Nettoyage En Place · ref_cip_types · utilisée dans les formulaires CIP</div>
        </div>

        <?php if ($initialSec === 'cip'): ?>
          <?php $flashMsg = flash_pop(); if ($flashMsg): ?>
          <div class="sdc-flash sdc-flash--<?= $flashMsg['type'] === 'ok' ? 'ok' : 'err' ?>">
            <?= $flashMsg['type'] === 'ok' ? '✓' : '⚠' ?> <?= htmlspecialchars($flashMsg['msg']) ?>
          </div>
          <?php endif ?>
        <?php endif ?>

        <?php if ($cipLoadErr): ?>
          <div class="sdc-flash sdc-flash--err">Erreur DB CIP : <?= htmlspecialchars($cipLoadErr) ?></div>
        <?php else: ?>

        <!-- ADD FORM (admin only) -->
        <?php if (is_admin($me)): ?>
        <div class="cip-add-card">
          <div class="cip-add-head">
            <span class="cip-add-title">Ajouter un type CIP</span>
          </div>
          <form method="post" action="/modules/salle-de-controle.php" class="cip-add-form" novalidate>
            <input type="hidden" name="csrf"   value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="action" value="cip_type_add">
            <div class="cip-form-row">
              <div class="cip-form-field">
                <label class="cip-form-label" for="cip_name_new">Nom</label>
                <input type="text" name="cip_name" id="cip_name_new"
                       class="cip-form-input" maxlength="64"
                       placeholder="ex. Soude 2%" required autocomplete="off">
              </div>
              <div class="cip-form-field cip-form-field--narrow">
                <label class="cip-form-label" for="cip_sort_new">Ordre</label>
                <input type="number" name="cip_sort_order" id="cip_sort_new"
                       class="cip-form-input" min="0" step="1" placeholder="0">
              </div>
              <button type="submit" class="cip-add-btn">Ajouter</button>
            </div>
          </form>
        </div>
        <?php endif ?>

        <!-- LIST TABLE -->
        <div class="cip-table-card">
          <div class="cip-table-head">
            <span class="cip-table-title">Types enregistrés</span>
            <span class="cip-count"><?= count($cipTypes) ?> type<?= count($cipTypes) !== 1 ? 's' : '' ?></span>
          </div>
          <?php if (empty($cipTypes)): ?>
            <div class="cip-empty">Aucun type CIP enregistré.</div>
          <?php else: ?>
          <table class="cip-table">
            <thead>
              <tr>
                <th class="cip-th cip-th--order">Ordre</th>
                <th class="cip-th">Nom</th>
                <th class="cip-th cip-th--status">État</th>
                <?php if (is_admin($me)): ?><th class="cip-th cip-th--actions">Actions</th><?php endif ?>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($cipTypes as $ct):
                $ctId      = (int)    $ct['id'];
                $ctName    = (string) $ct['name'];
                $ctOrder   = (int)    $ct['sort_order'];
                $ctActive  = (bool)   $ct['is_active'];
                $ctUpdBy   = $ct['updated_by'] !== null ? (string) $ct['updated_by'] : null;
            ?>
              <tr class="cip-row<?= !$ctActive ? ' cip-row--inactive' : '' ?>">
                <td class="cip-td cip-td--order">
                  <span class="cip-order-badge"><?= $ctOrder ?></span>
                </td>
                <td class="cip-td">
                  <span class="cip-name"><?= htmlspecialchars($ctName) ?></span>
                  <?php if ($ctUpdBy): ?>
                    <span class="cip-by" title="Dernière modif par <?= htmlspecialchars($ctUpdBy) ?>">· <?= htmlspecialchars($ctUpdBy) ?></span>
                  <?php endif ?>
                </td>
                <td class="cip-td cip-td--status">
                  <?php if ($ctActive): ?>
                    <span class="cip-badge cip-badge--active">Actif</span>
                  <?php else: ?>
                    <span class="cip-badge cip-badge--inactive">Inactif</span>
                  <?php endif ?>
                </td>
                <?php if (is_admin($me)): ?>
                <td class="cip-td cip-td--actions">
                  <div class="cip-action-group">
                    <!-- Edit inline form -->
                    <details class="cip-edit-details">
                      <summary class="cip-edit-toggle">Modifier</summary>
                      <form method="post" action="/modules/salle-de-controle.php"
                            class="cip-edit-form" novalidate>
                        <input type="hidden" name="csrf"        value="<?= htmlspecialchars($csrf) ?>">
                        <input type="hidden" name="action"      value="cip_type_update">
                        <input type="hidden" name="cip_id"      value="<?= $ctId ?>">
                        <div class="cip-edit-row">
                          <input type="text" name="cip_name" class="cip-form-input cip-form-input--sm"
                                 maxlength="64" value="<?= htmlspecialchars($ctName) ?>" required>
                          <input type="number" name="cip_sort_order" class="cip-form-input cip-form-input--xs"
                                 min="0" step="1" value="<?= $ctOrder ?>">
                          <button type="submit" class="cip-edit-btn">OK</button>
                        </div>
                      </form>
                    </details>
                    <!-- Deactivate / Reactivate -->
                    <?php if ($ctActive): ?>
                    <form method="post" action="/modules/salle-de-controle.php" style="display:inline;">
                      <input type="hidden" name="csrf"   value="<?= htmlspecialchars($csrf) ?>">
                      <input type="hidden" name="action" value="cip_type_deactivate">
                      <input type="hidden" name="cip_id" value="<?= $ctId ?>">
                      <button type="submit" class="cip-deact-btn"
                              onclick="return confirm('Désactiver «<?= htmlspecialchars(addslashes($ctName)) ?>» ?')">
                        Désactiver
                      </button>
                    </form>
                    <?php else: ?>
                    <form method="post" action="/modules/salle-de-controle.php" style="display:inline;">
                      <input type="hidden" name="csrf"   value="<?= htmlspecialchars($csrf) ?>">
                      <input type="hidden" name="action" value="cip_type_reactivate">
                      <input type="hidden" name="cip_id" value="<?= $ctId ?>">
                      <button type="submit" class="cip-react-btn">Réactiver</button>
                    </form>
                    <?php endif ?>
                  </div>
                </td>
                <?php endif ?>
              </tr>
            <?php endforeach ?>
            </tbody>
          </table>
          <?php endif ?>
        </div><!-- /cip-table-card -->

        <div class="impl-note" style="margin-top:20px;">
          <div class="impl-note-head">Persistance &amp; audit</div>
          <div class="impl-note-body">
            Les types CIP sont lus depuis <code>ref_cip_types</code>.
            Les formulaires de saisie CIP (<code>bd_cip_events.cip_type_id_fk</code>) lisent cette
            liste en filtrant <code>is_active = 1</code>. La désactivation est douce (soft) — les
            historiques existants sont préservés par la contrainte FK RESTRICT.
            Chaque modification est journalisée dans <code>audit_row_revisions</code>.
          </div>
        </div>

        <?php endif ?><!-- /cipLoadErr -->

        <!-- ════════ CADENCE CIP BBT — second panel inside #sec-cip ════════ -->
        <div style="margin-top:32px;border-top:2px solid var(--hairline);padding-top:24px;">
          <div class="cip-header">
            <h2 style="margin:0 0 4px;">Cadence <em>CIP BBT</em></h2>
            <div class="cip-header-sub">Politique de planification · commissioning_settings section='cip_cadence' · C6a</div>
          </div>

          <?php if ($cipCadenceErr): ?>
            <div class="sdc-flash sdc-flash--err" style="margin-top:12px;">Erreur DB Cadence : <?= htmlspecialchars($cipCadenceErr) ?></div>
          <?php elseif (empty($cipCadenceByKey)): ?>
            <div class="sdc-flash sdc-flash--err" style="margin-top:12px;">Migration 190 non appliquée — paramètres cadence CIP indisponibles.</div>
          <?php else: ?>

          <?php
          $cadAcidAfter = $cipCadenceByKey['cip_cadence_acid_after'] ?? null;
          $cadFullAfter = $cipCadenceByKey['cip_cadence_full_after'] ?? null;
          $cadAcidTypes = $cipCadenceByKey['cip_cadence_acid_reset_types'] ?? null;
          $cadFullTypes = $cipCadenceByKey['cip_cadence_full_reset_types'] ?? null;

          // Parse CSV values into arrays of int for checkbox pre-fill
          $acidResetIds = [];
          if ($cadAcidTypes && $cadAcidTypes['value_text'] !== null && $cadAcidTypes['value_text'] !== '') {
              $acidResetIds = array_map('intval', explode(',', (string) $cadAcidTypes['value_text']));
          }
          $fullResetIds = [];
          if ($cadFullTypes && $cadFullTypes['value_text'] !== null && $cadFullTypes['value_text'] !== '') {
              $fullResetIds = array_map('intval', explode(',', (string) $cadFullTypes['value_text']));
          }

          $canEdit = is_admin($me) || is_manager($me);
          ?>

          <!-- THRESHOLDS CARD -->
          <div class="cip-table-card" style="margin-top:16px;margin-bottom:16px;">
            <div class="cip-table-head">
              <span class="cip-table-title">Seuils de déclenchement</span>
              <span class="cip-count">2 seuils · informatifs · ne bloquent pas la saisie</span>
            </div>
            <div style="padding:10px 16px 6px;font-size:11px;color:var(--ink-soft);line-height:1.5;border-bottom:1px solid rgba(0,0,0,.05);">
              Cycle : <strong>N racks sans CIP BBT → recommandation acide</strong> ;
              puis <strong>M racks supplémentaires → recommandation CIP complet</strong> ; puis cycle.
              Le compteur est dérivé des événements <code>bd_cip_events</code> + <code>bd_racking_v2</code> (jamais stocké).
            </div>

            <?php foreach ([
              ['cip_cadence_acid_after', $cadAcidAfter, 'Racks avant CIP acide', 'Nombre de soutirages dans un BBT sans CIP acide (ou supérieur) avant alerte.'],
              ['cip_cadence_full_after', $cadFullAfter, 'Racks (après acide) avant CIP complet', 'Nombre de soutirages supplémentaires après le dernier CIP acide avant alerte CIP complet.'],
            ] as [$hk, $cadRow, $shortLabel, $helpText]):
              if ($cadRow === null) continue;
              $curNum = (int) round((float) ($cadRow['value_num'] ?? 6));
              $defNum = $cadRow['default_num'] !== null ? (int) round((float) $cadRow['default_num']) : null;
            ?>
            <div style="padding:14px 16px;border-bottom:1px solid rgba(0,0,0,.05);">
              <div style="display:flex;align-items:baseline;justify-content:space-between;gap:8px;flex-wrap:wrap;margin-bottom:6px;">
                <div>
                  <span style="font-weight:500;color:var(--ink);"><?= htmlspecialchars($shortLabel) ?></span>
                  <?php if ($defNum !== null): ?>
                    <span class="csr-default-note" style="margin-left:6px;">(défaut : <?= $defNum ?> racks)</span>
                  <?php endif ?>
                </div>
                <span style="font-family:'JetBrains Mono',monospace;font-size:15px;font-weight:600;color:var(--hop);">
                  <?= $curNum ?> <span style="font-size:10px;font-weight:400;">racks</span>
                </span>
              </div>
              <p style="font-size:11px;color:var(--ink-soft);margin:0 0 8px;"><?= htmlspecialchars($helpText) ?></p>
              <?php if ($canEdit): ?>
              <form method="post" action="/modules/salle-de-controle.php" class="cond-edit-form" novalidate>
                <input type="hidden" name="csrf"     value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="action"   value="update_cip_cadence">
                <input type="hidden" name="key_name" value="<?= htmlspecialchars($hk) ?>">
                <div class="cond-edit-row">
                  <label class="cond-edit-label" for="cad_val_<?= htmlspecialchars($hk) ?>">
                    Nouveau seuil <span class="cond-edit-unit">(racks)</span>
                  </label>
                  <input
                    id="cad_val_<?= htmlspecialchars($hk) ?>"
                    name="value_num"
                    type="number"
                    min="1"
                    step="1"
                    class="cond-edit-input"
                    value="<?= $curNum ?>"
                    required
                  >
                  <button type="submit" class="cond-edit-btn">Enregistrer</button>
                </div>
              </form>
              <?php else: ?>
              <p class="cond-readonly-note">Modification réservée aux administrateurs et managers.</p>
              <?php endif ?>
            </div>
            <?php endforeach ?>
          </div><!-- /thresholds card -->

          <!-- RESET-CLASS MAPPING CARD -->
          <div class="cip-table-card" style="margin-bottom:16px;">
            <div class="cip-table-head">
              <span class="cip-table-title">Classes de remise à zéro</span>
              <span class="cip-count">par type CIP · politique brasserie</span>
            </div>
            <div style="padding:10px 16px 8px;font-size:11px;color:var(--ink-soft);line-height:1.5;border-bottom:1px solid rgba(0,0,0,.05);">
              Un CIP <strong>acide</strong> remet à zéro le compteur de racks depuis le dernier acide.
              Un CIP <strong>complet</strong> remet à zéro les deux compteurs.
              Un type peut appartenir à au plus une classe (ou aucune — ex. Soude par défaut).
              Stocker dans deux CSVs distincts ; le résolveur vérifie <em>complet</em> en premier.
            </div>

            <?php if (empty($cipTypes)): ?>
              <div class="cip-empty" style="padding:14px 16px;">Aucun type CIP — ajouter d'abord dans la liste ci-dessus.</div>
            <?php else: ?>
            <table class="cip-table">
              <thead>
                <tr>
                  <th class="cip-th">Type CIP</th>
                  <th class="cip-th" style="text-align:center;">Remise à zéro<br><span style="font-weight:400;font-size:10px;">acide</span></th>
                  <th class="cip-th" style="text-align:center;">Remise à zéro<br><span style="font-weight:400;font-size:10px;">complète</span></th>
                </tr>
              </thead>
              <tbody>
              <?php foreach ($cipTypes as $ct):
                $ctId     = (int)    $ct['id'];
                $ctName   = (string) $ct['name'];
                $ctActive = (bool)   $ct['is_active'];
                $inAcid   = in_array($ctId, $acidResetIds, true);
                $inFull   = in_array($ctId, $fullResetIds, true);
              ?>
                <tr class="cip-row<?= !$ctActive ? ' cip-row--inactive' : '' ?>">
                  <td class="cip-td">
                    <span class="cip-name"><?= htmlspecialchars($ctName) ?></span>
                    <?php if (!$ctActive): ?>
                      <span class="cip-badge cip-badge--inactive" style="margin-left:6px;font-size:9px;">Inactif</span>
                    <?php endif ?>
                  </td>
                  <td class="cip-td" style="text-align:center;">
                    <?php if ($inAcid): ?>
                      <span style="color:var(--hop);font-size:13px;" title="Compte comme remise à zéro acide">✓</span>
                    <?php else: ?>
                      <span style="color:var(--ink-faint);font-size:11px;">—</span>
                    <?php endif ?>
                  </td>
                  <td class="cip-td" style="text-align:center;">
                    <?php if ($inFull): ?>
                      <span style="color:var(--ember);font-size:13px;" title="Compte comme remise à zéro complète">✓</span>
                    <?php else: ?>
                      <span style="color:var(--ink-faint);font-size:11px;">—</span>
                    <?php endif ?>
                  </td>
                </tr>
              <?php endforeach ?>
              </tbody>
            </table>

            <?php if ($canEdit): ?>
            <!-- Acid reset edit form -->
            <div style="padding:14px 16px;border-top:1px solid rgba(0,0,0,.06);">
              <div style="font-size:12px;font-weight:500;color:var(--ink);margin-bottom:8px;">
                Modifier — Remise à zéro <em>acide</em>
                <span style="font-size:11px;font-weight:400;color:var(--ink-soft);margin-left:6px;">(cocher les types qui réinitialisent le compteur acide)</span>
              </div>
              <form method="post" action="/modules/salle-de-controle.php" novalidate>
                <input type="hidden" name="csrf"     value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="action"   value="update_cip_cadence">
                <input type="hidden" name="key_name" value="cip_cadence_acid_reset_types">
                <div style="display:flex;flex-wrap:wrap;gap:10px 20px;margin-bottom:10px;">
                  <?php foreach ($cipTypes as $ct): ?>
                    <?php if (!(bool) $ct['is_active']) continue; ?>
                    <label style="display:flex;align-items:center;gap:5px;font-size:12px;color:var(--ink);cursor:pointer;">
                      <input type="checkbox" name="cip_type_ids[]"
                             value="<?= (int) $ct['id'] ?>"
                             <?= in_array((int) $ct['id'], $acidResetIds, true) ? 'checked' : '' ?>
                             style="accent-color:var(--hop);width:14px;height:14px;">
                      <?= htmlspecialchars((string) $ct['name']) ?>
                    </label>
                  <?php endforeach ?>
                </div>
                <button type="submit" class="cond-edit-btn">Enregistrer (acide)</button>
              </form>
            </div>
            <!-- Full reset edit form -->
            <div style="padding:14px 16px;border-top:1px solid rgba(0,0,0,.06);">
              <div style="font-size:12px;font-weight:500;color:var(--ink);margin-bottom:8px;">
                Modifier — Remise à zéro <em>complète</em>
                <span style="font-size:11px;font-weight:400;color:var(--ink-soft);margin-left:6px;">(cocher les types qui réinitialisent les deux compteurs)</span>
              </div>
              <form method="post" action="/modules/salle-de-controle.php" novalidate>
                <input type="hidden" name="csrf"     value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="action"   value="update_cip_cadence">
                <input type="hidden" name="key_name" value="cip_cadence_full_reset_types">
                <div style="display:flex;flex-wrap:wrap;gap:10px 20px;margin-bottom:10px;">
                  <?php foreach ($cipTypes as $ct): ?>
                    <?php if (!(bool) $ct['is_active']) continue; ?>
                    <label style="display:flex;align-items:center;gap:5px;font-size:12px;color:var(--ink);cursor:pointer;">
                      <input type="checkbox" name="cip_type_ids[]"
                             value="<?= (int) $ct['id'] ?>"
                             <?= in_array((int) $ct['id'], $fullResetIds, true) ? 'checked' : '' ?>
                             style="accent-color:var(--ember);width:14px;height:14px;">
                      <?= htmlspecialchars((string) $ct['name']) ?>
                    </label>
                  <?php endforeach ?>
                </div>
                <button type="submit" class="cond-edit-btn">Enregistrer (complet)</button>
              </form>
            </div>
            <?php endif ?>

            <?php endif ?><!-- /empty cipTypes -->
          </div><!-- /reset-class mapping card -->

          <div class="impl-note" style="margin-top:8px;">
            <div class="impl-note-head">Architecture cadence &amp; audit</div>
            <div class="impl-note-body">
              Les compteurs de racks sont <strong>dérivés</strong> des événements
              <code>bd_racking_v2</code> (destination BBT) et <code>bd_cip_events</code>
              (vessel CIP pour ce BBT) — jamais stockés (résolveur C6b, à venir).
              Les seuils et la table de correspondance CIP-type → classe de remise à zéro
              sont stockés dans <code>commissioning_settings section='cip_cadence'</code>
              (migration 190). Chaque modification est journalisée dans
              <code>audit_row_revisions</code>.
            </div>
          </div>

          <?php endif ?><!-- /cipCadenceByKey empty check -->
        </div><!-- /cadence CIP panel -->

      </div>
    </div><!-- /sec-cip -->

    <!-- ════════════════════════════════ PERTES SECTION (LIVE) -->
    <div class="section-panel" id="sec-pertes">
      <div class="cip-layout">
        <div class="cip-header">
          <h2>Constantes <em>Pertes</em></h2>
          <div class="cip-header-sub">Pertes process · TankSimulator · commissioning_settings section='pertes'</div>
        </div>

        <?php if ($initialSec === 'pertes'): ?>
          <?php $flashMsg = flash_pop(); if ($flashMsg): ?>
          <div class="sdc-flash sdc-flash--<?= $flashMsg['type'] === 'ok' ? 'ok' : 'err' ?>">
            <?= $flashMsg['type'] === 'ok' ? '✓' : '⚠' ?> <?= htmlspecialchars($flashMsg['msg']) ?>
          </div>
          <?php endif ?>
        <?php endif ?>

        <?php if ($pertesLoadErr): ?>
          <div class="sdc-flash sdc-flash--err">Erreur DB Pertes : <?= htmlspecialchars($pertesLoadErr) ?></div>
        <?php elseif (empty($pertesSettings)): ?>
          <div class="sdc-flash sdc-flash--err">Migration 184 non appliquée — paramètres Pertes indisponibles.</div>
        <?php else: ?>

        <!-- COGS-CRITICAL BLOCK — HL losses fed into TankSimulator -->
        <div class="cip-table-card" style="margin-bottom:20px;">
          <div class="cip-table-head">
            <span class="cip-table-title">Constantes fixes <abbr title="Ces valeurs alimentent le TankSimulator et affectent directement le calcul WIP et COGS">⚠ COGS/WIP</abbr></span>
            <span class="cip-count">2 paramètres</span>
          </div>
          <div style="padding:12px 16px 4px;font-size:12px;line-height:1.5;color:var(--ember);background:rgba(180,100,40,.07);border-bottom:1px solid rgba(180,100,40,.15);">
            <b>Ces deux valeurs alimentent directement le TankSimulator.</b>
            Toute modification change les volumes en cuve simulés et les coûts COGS/WIP.
            Valider avec le brasseur avant toute modification.
          </div>
          <?php
          $hlKeys = ['pertes_racking_loss_hl', 'pertes_packaging_loss_hl'];
          foreach ($hlKeys as $hk):
            if (!isset($pertesByKey[$hk])) continue;
            $ps = $pertesByKey[$hk];
            $curVal  = (float) $ps['value_num'];
            $defVal  = $ps['default_num'] !== null ? (float) $ps['default_num'] : null;
            $isAdmin = is_admin($me) || is_manager($me);
          ?>
          <div style="padding:14px 16px;border-bottom:1px solid rgba(0,0,0,.06);">
            <div style="display:flex;align-items:baseline;justify-content:space-between;gap:8px;flex-wrap:wrap;margin-bottom:8px;">
              <div>
                <span style="font-weight:600;color:var(--ink);"><?= htmlspecialchars($ps['label_fr']) ?></span>
                <?php if ($defVal !== null): ?>
                  <span class="csr-default-note" style="margin-left:8px;">(défaut : <?= number_format($defVal, 4) ?> <?= htmlspecialchars($ps['unit_fr'] ?? '') ?>)</span>
                <?php endif ?>
              </div>
              <span style="font-family:'JetBrains Mono',monospace;font-size:16px;font-weight:700;color:var(--ember);">
                <?= number_format($curVal, 4) ?> <span style="font-size:11px;font-weight:400;"><?= htmlspecialchars($ps['unit_fr'] ?? '') ?></span>
              </span>
            </div>
            <?php if ($ps['description_fr']): ?>
            <p style="font-size:11px;color:var(--ink-soft);margin:0 0 10px;"><?= htmlspecialchars($ps['description_fr']) ?></p>
            <?php endif ?>
            <?php if ($isAdmin): ?>
            <form method="post" action="/modules/salle-de-controle.php" class="cond-edit-form" novalidate>
              <input type="hidden" name="csrf"       value="<?= htmlspecialchars($csrf) ?>">
              <input type="hidden" name="action"     value="update_pertes_config">
              <input type="hidden" name="key_name"   value="<?= htmlspecialchars($hk) ?>">
              <div class="cond-edit-row">
                <label class="cond-edit-label" for="pertes_val_<?= htmlspecialchars($hk) ?>">
                  Nouvelle valeur
                  <span class="cond-edit-unit">(<?= htmlspecialchars($ps['unit_fr'] ?? '') ?>)</span>
                </label>
                <input
                  id="pertes_val_<?= htmlspecialchars($hk) ?>"
                  name="value_num"
                  type="number"
                  min="0"
                  step="0.0001"
                  class="cond-edit-input"
                  value="<?= htmlspecialchars(number_format($curVal, 4)) ?>"
                  required
                >
                <button type="submit" class="cond-edit-btn"
                        onclick="return confirm('⚠ Modifier une constante COGS/WIP — confirmer ?')">
                  Enregistrer
                </button>
              </div>
            </form>
            <?php else: ?>
            <p class="cond-readonly-note">Modification réservée aux administrateurs et managers.</p>
            <?php endif ?>
          </div>
          <?php endforeach ?>
        </div><!-- /HL loss constants card -->

        <!-- ADVISORY THRESHOLDS BLOCK — % alert thresholds, no COGS impact -->
        <div class="cip-table-card">
          <div class="cip-table-head">
            <span class="cip-table-title">Seuils d'alerte</span>
            <span class="cip-count">5 seuils · informatifs · n'affectent pas COGS</span>
          </div>
          <?php
          $pctKeys = [
              'pertes_rack_warn_pct',
              'pertes_packaging_warn_pct',
              'pertes_brewing_warn_pct',
              'pertes_total_effectif_warn_pct',
              'pertes_total_nominal_warn_pct',
          ];
          foreach ($pctKeys as $pk):
            if (!isset($pertesByKey[$pk])) continue;
            $ps = $pertesByKey[$pk];
            $curVal = (float) $ps['value_num'];
            $defVal = $ps['default_num'] !== null ? (float) $ps['default_num'] : null;
            $isAdmin = is_admin($me) || is_manager($me);
          ?>
          <div style="padding:12px 16px;border-bottom:1px solid rgba(0,0,0,.05);">
            <div style="display:flex;align-items:baseline;justify-content:space-between;gap:8px;flex-wrap:wrap;margin-bottom:6px;">
              <div>
                <span style="font-weight:500;color:var(--ink);"><?= htmlspecialchars($ps['label_fr']) ?></span>
                <?php if ($defVal !== null): ?>
                  <span class="csr-default-note" style="margin-left:6px;">(défaut : <?= number_format($defVal, 1) ?> %)</span>
                <?php endif ?>
              </div>
              <span style="font-family:'JetBrains Mono',monospace;font-size:15px;font-weight:600;color:var(--hop);">
                <?= number_format($curVal, 1) ?> <span style="font-size:10px;font-weight:400;">%</span>
              </span>
            </div>
            <?php if ($isAdmin): ?>
            <form method="post" action="/modules/salle-de-controle.php" class="cond-edit-form" novalidate>
              <input type="hidden" name="csrf"       value="<?= htmlspecialchars($csrf) ?>">
              <input type="hidden" name="action"     value="update_pertes_config">
              <input type="hidden" name="key_name"   value="<?= htmlspecialchars($pk) ?>">
              <div class="cond-edit-row">
                <label class="cond-edit-label" for="pertes_val_<?= htmlspecialchars($pk) ?>">
                  Nouveau seuil <span class="cond-edit-unit">(%)</span>
                </label>
                <input
                  id="pertes_val_<?= htmlspecialchars($pk) ?>"
                  name="value_num"
                  type="number"
                  min="0"
                  max="100"
                  step="0.1"
                  class="cond-edit-input"
                  value="<?= htmlspecialchars(number_format($curVal, 1)) ?>"
                  required
                >
                <button type="submit" class="cond-edit-btn">Enregistrer</button>
              </div>
            </form>
            <?php else: ?>
            <p class="cond-readonly-note">Modification réservée aux administrateurs et managers.</p>
            <?php endif ?>
          </div>
          <?php endforeach ?>
        </div><!-- /advisory thresholds card -->

        <div class="impl-note" style="margin-top:20px;">
          <div class="impl-note-head">Persistance &amp; audit</div>
          <div class="impl-note-body">
            Les constantes HL sont lues par <code>TankSimulator::__construct()</code> à chaque initialisation
            (un seul SELECT au démarrage — aucun hit par événement). En l'absence de ligne active, le
            simulateur retombe sur ses valeurs codées en dur (<code>RACKING_LOSS_HL=0.9</code>,
            <code>PACKAGING_LOSS_HL=0.15</code>). Les seuils % sont purement informatifs.
            Chaque modification est journalisée dans <code>audit_row_revisions</code>.
          </div>
        </div>

        <?php endif ?><!-- /pertesSettings empty check -->
      </div>
    </div><!-- /sec-pertes -->

    <!-- ════════════════════════════════ CO₂/O₂ CONFORMITÉ SECTION -->
    <div class="section-panel" id="sec-conformite">
      <div class="cip-layout">
        <div class="cip-header">
          <h2>CO₂ / O₂ <em>conformité</em></h2>
          <div class="cip-header-sub">Suivi en-cuve · cible CO₂ par recette · bd_tank_readings</div>
        </div>

        <?php if ($tankLoadErr): ?>
          <div class="sdc-flash sdc-flash--err">Erreur DB conformité : <?= htmlspecialchars($tankLoadErr) ?></div>
        <?php elseif (empty($tankBeerList)): ?>
          <div class="sdc-conf-empty">Aucune lecture en-cuve disponible.</div>
        <?php else: ?>

        <div class="sdc-conf-layout">

          <!-- ── Beer selector ───────────────────────────────────────────── -->
          <div class="sdc-conf-selector" id="sdcConfSelector">
            <!-- populated by JS: sdcConf.init() -->
          </div>

          <!-- ── Right panel: summary strip + chart + table ─────────────── -->
          <div class="sdc-conf-panel">

            <!-- Summary strip -->
            <div class="sdc-conf-summary" id="sdcConfSummary">
              <!-- populated by JS -->
            </div>

            <!-- No-spec notice (shown when selected beer has no CO₂ target) -->
            <div class="sdc-conf-nospec" id="sdcConfNospec" style="display:none;">
              <span class="sdc-conf-nospec-icon">○</span>
              <span class="sdc-conf-nospec-msg">Aucune cible CO₂ définie pour cette recette.</span>
              <span class="sdc-conf-nospec-hint">Définir la cible CO₂ dans Recettes → Levure &amp; garde → Seuils QC CO₂.</span>
            </div>

            <!-- Chart area: CO₂ primary + O₂ secondary -->
            <div class="sdc-conf-charts" id="sdcConfCharts">
              <!-- populated by JS -->
            </div>

            <!-- Raw readings table (optional fallback) -->
            <div class="sdc-conf-table-wrap" id="sdcConfTableWrap">
              <!-- populated by JS -->
            </div>

          </div><!-- /sdc-conf-panel -->
        </div><!-- /sdc-conf-layout -->

        <?php endif ?>
      </div><!-- /cip-layout -->
    </div><!-- /sec-conformite -->

  </div><!-- /content-area -->
</div><!-- /sdc-stage -->

<!-- CONFIRM MODAL — wired for update_recipe_style and update_recipe_name -->
<div class="modal-overlay" id="modalOverlay">
  <div class="modal-box">
    <h4>Confirmer la modification</h4>
    <p>Paramètre de <span id="modalContext" style="color:var(--lab);">—</span></p>
    <div class="modal-diff">
      <span class="old" id="modalOld">—</span> → <span class="new" id="modalNew">—</span>
    </div>
    <div class="modal-actions">
      <button class="btn-cancel" onclick="closeModal()">Annuler</button>
      <button class="btn-confirm" onclick="applyModal()">Confirmer</button>
    </div>
  </div>
</div>

<!-- NEW RECIPE MODAL (presentational) -->
<div class="modal-overlay modal-new-recipe" id="newRecipeOverlay">
  <div class="modal-box">
    <h4>Nouvelle <em style="color:var(--lab);">recette</em></h4>
    <p style="margin-bottom:18px;">Création d'une fiche vierge — les ingrédients et paramètres process sont à renseigner.</p>
    <div class="modal-form-row">
      <div class="modal-form-label">Nom de la recette</div>
      <input class="modal-form-input" id="nr-name" type="text" placeholder="ex. Estafette" autocomplete="off">
    </div>
    <div class="modal-form-row">
      <div class="modal-form-label">Style <span class="new-field-tag">nouveau champ · à câbler</span></div>
      <input class="modal-form-input" id="nr-style" type="text" placeholder="ex. West Coast IPA" autocomplete="off">
    </div>
    <div class="modal-form-row">
      <div class="modal-form-label">Famille de levure <span class="new-field-tag">à câbler</span></div>
      <select class="modal-form-select" id="nr-yeast">
        <option value="ale">Ale</option>
        <option value="lager">Lager</option>
        <option value="spontane">Spontané</option>
        <option value="mixte">Mixte</option>
      </select>
    </div>
    <div class="modal-actions">
      <button class="btn-cancel" onclick="closeNewRecipeModal()">Annuler</button>
      <button class="btn-confirm" onclick="createRecipe()">Créer la fiche</button>
    </div>
  </div>
</div>

<?php
// Inject real role for JS
$jsRole = htmlspecialchars(json_encode($me['role'] ?? 'operateur'), ENT_QUOTES | ENT_SUBSTITUTE);
?>
<script>
/* ═══════════════════════════════════════════
   SERVER-INJECTED STATE
   ═══════════════════════════════════════════ */
const SDC_ROLE      = <?= json_encode($me['role'] ?? 'operateur', JSON_HEX_TAG | JSON_HEX_AMP) ?>;
const SDC_INITIAL   = <?= json_encode($initialSec, JSON_HEX_TAG | JSON_HEX_AMP) ?>;

/* ═══════════════════════════════════════════
   DATA — baked from live DB via v_recipe_lifecycle
   Three lifecycle lists: actives / passées / contrats.
   ═══════════════════════════════════════════ */
<?php
function _recipe_js_shape(array $r): array {
    return [
        'id'       => (int)    $r['id'],
        'name'     => (string) $r['name'],
        'sku'      => $r['sku_prefix'],
        'subtype'  => (string) $r['subtype'],
        'vintage'  => (string) $r['vintage'],
        'flag'     => $r['flag'],
        // Rename is contract-only (Neb names are join keys — see update_recipe_name).
        'contract' => (($r['classification'] ?? '') === 'Contract'),
    ];
}
?>
const RECIPES_ACTIVES  = <?= json_encode(array_map('_recipe_js_shape', $recipesActives),  JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
const RECIPES_PASSEES  = <?= json_encode(array_map('_recipe_js_shape', $recipesPassees),  JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
const RECIPES_CONTRATS = <?= json_encode(array_map('_recipe_js_shape', $recipesContrats), JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
// Unified lookup: all recipes across all three buckets.
const RECIPES = [...RECIPES_ACTIVES, ...RECIPES_PASSEES, ...RECIPES_CONTRATS];

const INGREDIENTS = window.SDC_INGREDIENTS || {};

const PROFILES = window.SDC_PROFILES || {};

const YEAST_DEFAULTS = {
  ale:{fermTempMin:18,fermTempMax:22,gardeMin:14,gardeMax:28,typical:"Saccharomyces cerevisiae — ale top-fermenting. Température ambiante, développe esters fruités."},
  lager:{fermTempMin:9,fermTempMax:13,gardeMin:28,gardeMax:56,typical:"Saccharomyces pastorianus — fermentation basse. Cave froide, profil propre, garde longue."},
  spontane:{fermTempMin:14,fermTempMax:20,gardeMin:90,gardeMax:365,typical:"Levures et bactéries sauvages — lambic, gueuze. Fermentation longue et complexe."},
  mixte:{fermTempMin:18,fermTempMax:22,gardeMin:21,gardeMax:60,typical:"Mix Saccharomyces + Brettanomyces / bactéries. Profil fruité-acide, garde variable."},
};

const RECIPE_YEAST = {57:"lager",32:"ale",44:"ale",51:"ale",52:"ale",30:"ale",25:"spontane",26:"ale",6:"ale"};

/* ═══════════════════════════════════════════
   ABV calc — Plato-based (Balling / Terrill)
   ═══════════════════════════════════════════ */
function calcAbv(og, fg){
  if(!og || !fg) return null;
  const sgOG = 1 + og/(258.6 - og/258.2*227.1);
  const sgFG = 1 + fg/(258.6 - fg/258.2*227.1);
  return ((sgOG - sgFG) * 131.25).toFixed(1);
}

/* ═══════════════════════════════════════════
   STATE
   ═══════════════════════════════════════════ */
let selectedRecipeId = null;
let currentSubtab    = 'ingr';
let pendingModal     = null;
let ingrScale        = 'brassin';
const BRASSIN_HL     = window.SDC_BRASSIN_HL || 30;
const RECIPE_STYLES  = window.SDC_RECIPE_STYLES || {};

/* ═══════════════════════════════════════════
   SECTION SWITCHER
   ═══════════════════════════════════════════ */
function switchSection(sec){
  document.querySelectorAll('.section-panel').forEach(p=>p.classList.toggle('active',p.id==='sec-'+sec));
  document.querySelectorAll('.nav-item[data-sec]').forEach(n=>n.classList.toggle('active',n.dataset.sec===sec));
}

/* ═══════════════════════════════════════════
   SUB-TAB SWITCHER
   ═══════════════════════════════════════════ */
function switchSubtab(tab){
  currentSubtab = tab;
  document.querySelectorAll('.subtab').forEach((el,i)=>{
    const tabs=['ingr','process','formats','yeast'];
    el.classList.toggle('active',tabs[i]===tab);
  });
  document.querySelectorAll('.subtab-pane').forEach(p=>p.classList.toggle('active',p.id==='pane-'+tab));
  if(tab==='formats' && selectedRecipeId!==null && window.sdcFormats){
    window.sdcFormats.render(selectedRecipeId);
  }
  if(tab==='yeast' && selectedRecipeId!==null){
    ygRenderPanel(selectedRecipeId);
    if(typeof window.qcRenderPanel==='function') window.qcRenderPanel(selectedRecipeId);
  }
}

/* ═══════════════════════════════════════════
   RECIPE LIST RENDER
   ═══════════════════════════════════════════ */
function escHtml(s){
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function buildRecipeList(){
  const scroll=document.getElementById('recipeScroll');
  if(!scroll) return;
  scroll.innerHTML='';

  const FLAG_LABELS={'production_a_venir':'production à venir','plus_produite':'plus produite'};

  function appendRecipeItem(r){
    INGREDIENTS[r.id] = INGREDIENTS[r.id] || [];
    const p=PROFILES[r.id];
    const abv=p?calcAbv(p.og,p.fg):null;
    const div=document.createElement('div');
    div.className='recipe-item';
    div.dataset.id=r.id;
    const dotClass={Core:'core',EPH:'eph',CollabIn:'collab',CollabOut:'collab',Archive:'archive'}[r.subtype]||'archive';
    const chipHtml=r.flag&&FLAG_LABELS[r.flag]
      ?`<span class="ri-lifecycle-chip ri-chip--${escHtml(r.flag)}">${escHtml(FLAG_LABELS[r.flag])}</span>`:'';
    div.innerHTML=`<span class="ri-dot ${escHtml(dotClass)}"></span>
      <div class="ri-body">
        <div class="ri-name">${escHtml(r.name)}${chipHtml}</div>
        <div class="ri-meta">${[r.vintage?'Millésime '+escHtml(r.vintage):null, p?p.batches+' brassin(s) · OG '+p.og+'°P':null].filter(Boolean).join(' · ')||'—'}</div>
      </div>
      <div>
        ${r.sku?`<div class="ri-sku">${escHtml(r.sku)}</div>`:''}
        ${abv?`<div style="font-family:'JetBrains Mono',monospace;font-size:9px;color:var(--lab);text-align:right;margin-top:3px;">${escHtml(abv)}%</div>`:''}
      </div>`;
    div.addEventListener('click',()=>selectRecipe(r.id));
    scroll.appendChild(div);
  }

  function appendGroupLabel(text){
    const lbl=document.createElement('div');
    lbl.className='recipe-group-label';
    lbl.textContent=text;
    scroll.appendChild(lbl);
  }

  if(RECIPES_ACTIVES.length){
    appendGroupLabel('Recettes actives — Nébuleuse');
    RECIPES_ACTIVES.forEach(appendRecipeItem);
  }

  if(RECIPES_PASSEES.length){
    appendGroupLabel('Recettes passées');
    RECIPES_PASSEES.forEach(appendRecipeItem);
  }

  if(RECIPES_CONTRATS.length){
    appendGroupLabel('Contrats');
    RECIPES_CONTRATS.forEach(appendRecipeItem);
  }
}

function selectRecipe(id){
  selectedRecipeId=id;
  document.querySelectorAll('.recipe-item').forEach(el=>el.classList.toggle('sel',+el.dataset.id===id));
  const rec=RECIPES.find(r=>r.id===id);
  const p=PROFILES[id];
  const abv=p?calcAbv(p.og,p.fg):null;
  const titleEl=document.getElementById('rdh-title');
  if(titleEl.tagName==='INPUT'){
    titleEl.value=rec.name;titleEl.defaultValue=rec.name;
    // Rename is contract-only — Neb recipe names are DB join keys (tanks/COGS).
    if(rec.contract){titleEl.removeAttribute('readonly');titleEl.title='';}
    else{titleEl.setAttribute('readonly','readonly');titleEl.title='Renommage réservé aux recettes sous contrat';}
  } else {
    titleEl.innerHTML=`<em>${escHtml(rec.name)}</em>`;
  }
  const styleInp=document.getElementById('rdh-style');
  if(styleInp){styleInp.value=RECIPE_STYLES[id]||'';styleInp.defaultValue=styleInp.value;}
  document.getElementById('rdh-meta').textContent=(rec.sku||'—')+' · '+rec.subtype+(p?' · '+p.batches+' brassins':'');
  document.getElementById('rdh-abv').textContent=abv?abv+'%':'—';
  renderIngrPane(id,p);
  renderProcessPane(id,p);
  if(currentSubtab==='formats'&&window.sdcFormats){window.sdcFormats.render(id);}
  if(currentSubtab==='yeast'){
    ygRenderPanel(id);
    if(typeof window.qcRenderPanel==='function') window.qcRenderPanel(id);
  }
}

/* ═══════════════════════════════════════════
   INGRÉDIENTS PANE
   ═══════════════════════════════════════════ */
const CAT_COLORS={Malt:'#a07a48',Hops:'#9eb060',Adjunct:'#9b7cc8','Proc/Chem':'#6593b8',Minéraux:'#4a8c78',Yeast:'#b07a5a'};
const CAT_ORDER=['Malt','Hops','Adjunct','Proc/Chem','Minéraux','Yeast'];

function scaledQty(qty,unit,isHop=false){
  const factor=ingrScale==='brassin'?BRASSIN_HL:1;
  const scaled=qty*factor;
  if(unit==='kg'){return scaled>=1?{val:scaled,unit:'kg'}:{val:scaled*1000,unit:'g'};}
  // Hops always render in grams — never auto-promote to kg
  if(unit==='g'){return isHop?{val:scaled,unit:'g'}:(scaled>=1000?{val:scaled/1000,unit:'kg'}:{val:scaled,unit:'g'});}
  if(unit==='ml'){return scaled>=1000?{val:scaled/1000,unit:'L'}:{val:scaled,unit:'ml'};}
  return{val:scaled,unit};
}
function fmtVal(v,unit){
  if(unit==='kg') return v<1?v.toFixed(3):v.toFixed(2);
  if(unit==='g'){
    const r=v<10?parseFloat(v.toFixed(1)):Math.round(v);
    // thousands separator (fr-CH space style) for readability at ≥ 1000 g
    return r>=1000?r.toLocaleString('fr-CH'):String(r);
  }
  if(unit==='L') return v.toFixed(2);
  return v%1===0?v:v.toFixed(1);
}
function setIngrScale(scale){
  ingrScale=scale;
  document.getElementById('istBrassin').classList.toggle('active',scale==='brassin');
  document.getElementById('istHl').classList.toggle('active',scale==='hl');
  if(selectedRecipeId!==null) renderIngrPane(selectedRecipeId,PROFILES[selectedRecipeId]);
}
/* ── Hop stage helpers ───────────────────────────────────────────────────── */
const HOP_STAGES=[
  {v:'',           label:'— non classé —'},
  {v:'mash',       label:'Mash'},
  {v:'first_wort', label:'First wort'},
  {v:'boil',       label:'Boil'},
  {v:'whirlpool',  label:'Whirlpool'},
  {v:'hop_stand',  label:'Hop stand'},
  {v:'dry_hop',    label:'Dry hop'},
];
function hopStageLabel(v){const s=HOP_STAGES.find(x=>x.v===v);return s?s.label:(v||'—');}

function hopStageSelectHtml(rriId,currentStage,currentBoilMin){
  const sel=currentStage||'';
  let h=`<select class="hop-stage-select" data-rri-id="${rriId}">`;
  HOP_STAGES.forEach(s=>{h+=`<option value="${escHtml(s.v)}"${s.v===sel?' selected':''}>${escHtml(s.label)}</option>`;});
  h+='</select>';
  const showMin=sel==='boil';
  h+=`<input type="number" class="hop-boil-min" data-rri-id="${rriId}" min="0" max="90" placeholder="min" title="Min restantes d'ébullition à l'ajout (60 = début de boil, 0 = flameout)" value="${showMin&&currentBoilMin!=null?currentBoilMin:''}" style="display:${showMin?'inline-block':'none'};">`;
  h+=`<span class="hop-boil-unit" style="display:${showMin?'inline':'none'};">min</span>`;
  return h;
}

function hopAddBtnHtml(recipeId,miIdFk){
  return `<button class="hop-add-btn" data-recipe-id="${recipeId}" data-mi-id-fk="${miIdFk}" title="Ajouter une addition pour ce houblon">+ addition</button>`;
}

function hopDeleteBtnHtml(rriId){
  return `<button class="hop-del-btn" data-rri-id="${rriId}" title="Supprimer cette addition">×</button>`;
}

/* ── Hop API helpers ─────────────────────────────────────────────────────── */
async function hopApiFetch(action,body){
  const resp=await fetch('/modules/salle-de-controle.php',{
    method:'POST',
    headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body:new URLSearchParams({action,...body,csrf:window.SDC_CSRF||''}),
  });
  return resp.json();
}

/* ── Bind hop controls (called after re-render) ──────────────────────────── */
function bindHopControls(container){
  // Stage select: show/hide boil-min, then save on change (non-boil stages save immediately)
  container.querySelectorAll('.hop-stage-select').forEach(sel=>{
    sel.addEventListener('change',async function(){
      const id=this.dataset.rriId;
      const stage=this.value;
      const isBoil=stage==='boil';
      const minInp=container.querySelector(`.hop-boil-min[data-rri-id="${id}"]`);
      const minUnit=container.querySelector(`.hop-boil-unit[data-rri-id="${id}"]`);
      if(minInp){minInp.style.display=isBoil?'inline-block':'none';if(!isBoil)minInp.value='';}
      if(minUnit){minUnit.style.display=isBoil?'inline':'none';}
      if(isBoil) return; // wait for boil-min blur
      const errEl=container.querySelector(`.hop-stage-err[data-rri-id="${id}"]`);
      if(errEl)errEl.textContent='';
      try{
        const data=await hopApiFetch('set_hop_stage',{id,stage,boil_min:''});
        if(!data.ok){if(errEl)errEl.textContent=data.error||'Erreur';return;}
        const arr=INGREDIENTS[selectedRecipeId]||[];
        const row=arr.find(r=>r.id===parseInt(id,10));
        if(row){row.stage=data.stage;row.boil_min=data.boil_min;}
        if(window.showToast)showToast('Stage houblon enregistré.');
      }catch(e){if(errEl)errEl.textContent='Erreur réseau.';}
    });
  });

  // Boil-min input → save on blur
  container.querySelectorAll('.hop-boil-min').forEach(inp=>{
    inp.addEventListener('blur',async function(){
      if(this.style.display==='none') return;
      const id=this.dataset.rriId;
      const sel=container.querySelector(`.hop-stage-select[data-rri-id="${id}"]`);
      const stage=sel?sel.value:'boil';
      if(stage!=='boil') return;
      const boilMin=this.value;
      const errEl=container.querySelector(`.hop-stage-err[data-rri-id="${id}"]`);
      if(errEl)errEl.textContent='';
      if(boilMin===''||isNaN(parseInt(boilMin,10))){
        if(errEl)errEl.textContent='Entrer les minutes (0–90, 0=flameout).';
        return;
      }
      try{
        const data=await hopApiFetch('set_hop_stage',{id,stage,boil_min:boilMin});
        if(!data.ok){if(errEl)errEl.textContent=data.error||'Erreur';return;}
        const arr=INGREDIENTS[selectedRecipeId]||[];
        const row=arr.find(r=>r.id===parseInt(id,10));
        if(row){row.stage=data.stage;row.boil_min=data.boil_min;}
        if(window.showToast)showToast('Stage houblon enregistré.');
      }catch(e){if(errEl)errEl.textContent='Erreur réseau.';}
    });
  });

  // Add-addition button
  container.querySelectorAll('.hop-add-btn').forEach(btn=>{
    btn.addEventListener('click',async function(){
      const recipeId=parseInt(this.dataset.recipeId,10);
      const miIdFk=this.dataset.miIdFk;
      // Ask per-brassin — storage stays per-HL, server converts
      const brassinHl=BRASSIN_HL;
      const qtyBrassin=window.prompt(`Quantité par brassin (${brassinHl} hl) :`);
      if(!qtyBrassin||isNaN(parseFloat(qtyBrassin))||parseFloat(qtyBrassin)<=0){return;}
      const unit=window.prompt('Unité (g, kg, ml, L):','g');
      if(!['g','kg','ml','L'].includes(unit)){window.alert('Unité invalide.');return;}
      const stageIdx=window.prompt('Stage:\n0=non classé\n1=mash\n2=first_wort\n3=boil\n4=whirlpool\n5=hop_stand\n6=dry_hop\n\nEntrer le numéro:','');
      const stageMap=['','mash','first_wort','boil','whirlpool','hop_stand','dry_hop'];
      const stage=stageMap[parseInt(stageIdx,10)]??'';
      let boilMin='';
      if(stage==='boil'){
        const bm=window.prompt('Minutes de houblonnage (0=flameout, max 90):');
        if(!bm||isNaN(parseInt(bm,10))||parseInt(bm,10)<0||parseInt(bm,10)>90){window.alert('Minutes invalides.');return;}
        boilMin=bm;
      }
      try{
        // Send per-brassin qty + brassin_hl — server divides to store qty_per_hl
        const data=await hopApiFetch('add_hop_addition',{recipe_id:recipeId,mi_id_fk:miIdFk,qty_per_brassin:qtyBrassin,brassin_hl:brassinHl,unit,stage,boil_min:boilMin});
        if(!data.ok){window.alert(data.error||'Erreur');return;}
        const arr=INGREDIENTS[recipeId]||(INGREDIENTS[recipeId]=[]);
        arr.push({id:data.id,mi:data.mi,name:data.name,cat:'Hops',qty:data.qty,unit:data.unit,is_hop:true,stage:data.stage,boil_min:data.boil_min});
        renderIngrPane(selectedRecipeId,PROFILES[selectedRecipeId]);
        if(window.showToast)showToast('Addition houblon ajoutée.');
      }catch(e){window.alert('Erreur réseau.');}
    });
  });

  // Delete-addition button
  container.querySelectorAll('.hop-del-btn').forEach(btn=>{
    btn.addEventListener('click',async function(){
      const id=this.dataset.rriId;
      if(!window.confirm('Supprimer cette addition de houblon ?')) return;
      try{
        const data=await hopApiFetch('delete_hop_addition',{id});
        if(!data.ok){window.alert(data.error||'Erreur');return;}
        const arr=INGREDIENTS[selectedRecipeId]||[];
        const idx=arr.findIndex(r=>r.id===parseInt(id,10));
        if(idx!==-1) arr.splice(idx,1);
        renderIngrPane(selectedRecipeId,PROFILES[selectedRecipeId]);
        if(window.showToast)showToast('Addition supprimée.');
      }catch(e){window.alert('Erreur réseau.');}
    });
  });
}

function renderIngrPane(id,profile){
  const container=document.getElementById('ingrPaneContent');
  if(!container) return;
  const items=INGREDIENTS[id]||[];
  if(!items.length){container.innerHTML='<div style="padding:40px;text-align:center;color:var(--ink-faint);">Aucune recette officielle saisie.</div>';return;}
  const canEdit=(SDC_ROLE==='admin'||SDC_ROLE==='manager');

  // Group by category. Hop items grouped by MI name (since same MI may have multiple addition rows).
  const groups={};
  items.forEach(it=>{const c=it.cat;if(!groups[c])groups[c]=[];groups[c].push(it);});
  const toGRaw=(qty,unit)=>unit==='kg'?qty*1000:qty;
  const maxPerCat={};
  // For Hops, max is computed across all addition rows for the same MI (use total per MI)
  Object.entries(groups).forEach(([c,rows])=>{
    if(c==='Hops'){
      const totals={};
      rows.forEach(r=>{totals[r.mi]=(totals[r.mi]||0)+toGRaw(r.qty,r.unit);});
      maxPerCat[c]=Math.max(...Object.values(totals));
    } else {
      maxPerCat[c]=Math.max(...rows.map(r=>toGRaw(r.qty,r.unit)));
    }
  });
  let html='';
  const abv=profile?calcAbv(profile.og,profile.fg):null;
  if(abv){html+=`<div style="margin-bottom:16px;padding:12px 14px;background:rgba(74,140,120,.10);border:1px solid rgba(74,140,120,.22);border-radius:8px;display:flex;align-items:baseline;gap:12px;"><span class="abv-big">${escHtml(abv)}</span><span class="abv-pct">% ABV</span><span class="abv-calc-note" style="margin-left:auto;">Estimé Plato · OG ${profile.og}°P · FG ${profile.fg}°P · ${profile.batches} brassins</span></div>`;}
  const scaleLabel=ingrScale==='brassin'?`/ brassin · ${BRASSIN_HL} hl`:'/ hl';
  CAT_ORDER.forEach(cat=>{
    const rows=groups[cat];if(!rows)return;
    const color=CAT_COLORS[cat]||'var(--oak)';
    const totalKgScaled=rows.filter(r=>r.unit==='kg').reduce((s,r)=>s+r.qty*(ingrScale==='brassin'?BRASSIN_HL:1),0);
    const totalGScaled=rows.filter(r=>r.unit==='g').reduce((s,r)=>s+r.qty*(ingrScale==='brassin'?BRASSIN_HL:1),0);
    let total='';
    if(totalKgScaled>0){total=totalKgScaled>=1?totalKgScaled.toFixed(2)+' kg '+scaleLabel:(totalKgScaled*1000).toFixed(0)+' g '+scaleLabel;}
    else if(totalGScaled>0){total=totalGScaled>=1000?(totalGScaled/1000).toFixed(2)+' kg '+scaleLabel:totalGScaled.toFixed(0)+' g '+scaleLabel;}
    html+=`<div class="ingr-section-head"><span class="ish-dot" style="background:${color}"></span><span class="ish-label">${escHtml(cat)}</span><span class="ish-rule"></span><span class="ish-total">${escHtml(total)}</span></div>`;

    if(cat==='Hops'){
      // Process-order rank for hop additions (mash→first_wort→boil→whirlpool→hop_stand→dry_hop; null=last)
      const HOP_STAGE_RANK={mash:0,first_wort:1,boil:2,whirlpool:3,hop_stand:4,dry_hop:5};
      function hopSortKey(r){
        const rank=r.stage!=null&&HOP_STAGE_RANK[r.stage]!=null?HOP_STAGE_RANK[r.stage]:99;
        // Within boil: higher minutes first (60→0), NULL boil_min after numbered
        const boilMin=r.stage==='boil'?(r.boil_min!=null?-r.boil_min:1):0;
        return[rank,boilMin];
      }
      // Sort all hop rows in process order before grouping — preserves cross-MI order too
      const sortedHopRows=[...rows].sort((a,b)=>{
        const ka=hopSortKey(a),kb=hopSortKey(b);
        return ka[0]!==kb[0]?ka[0]-kb[0]:ka[1]-kb[1];
      });
      // Group hop items by MI preserving sorted order (insertion-order Map)
      const byMiMap=new Map();
      sortedHopRows.forEach(r=>{
        if(!byMiMap.has(r.mi))byMiMap.set(r.mi,{name:r.name,mi:r.mi,rows:[]});
        byMiMap.get(r.mi).rows.push(r);
      });
      const byMi=Object.fromEntries(byMiMap);
      Object.values(byMi).forEach(miGroup=>{
        const totalForMi=miGroup.rows.reduce((s,r)=>s+toGRaw(r.qty,r.unit),0);
        const pct=totalForMi/maxPerCat[cat]*100;
        html+=`<div class="ingr-hop-group">`;
        miGroup.rows.forEach((it,idx)=>{
          const s=scaledQty(it.qty,it.unit,true);
          const isFirst=idx===0;
          const canDelete=miGroup.rows.length>1;
          html+=`<div class="ingr-row ingr-row--hop${it.stage?' ingr-row--staged':''}" data-rri-id="${it.id}">`;
          html+=`<div class="ingr-name">${isFirst?escHtml(it.name):''}</div>`;
          html+=`<div class="ingr-mid">${isFirst?escHtml(it.mi):''}</div>`;
          html+=`<div class="ingr-qty">${escHtml(String(fmtVal(s.val,s.unit)))}</div>`;
          html+=`<div class="ingr-unit">${escHtml(s.unit)}</div>`;
          if(canEdit){
            html+=`<div class="ingr-hop-stage-wrap">${hopStageSelectHtml(it.id,it.stage,it.boil_min)}<span class="hop-stage-err" data-rri-id="${it.id}"></span></div>`;
            if(canDelete){html+=`<div class="ingr-hop-del">${hopDeleteBtnHtml(it.id)}</div>`;}
            else{html+=`<div class="ingr-hop-del"></div>`;}
          } else {
            // Read-only: just show stage label
            html+=`<div class="ingr-hop-stage-ro">${it.stage?`<span class="hop-stage-chip">${escHtml(hopStageLabel(it.stage))}${it.boil_min!=null?' · '+it.boil_min+'min':''}</span>`:'<span class="hop-stage-none">—</span>'}</div>`;
          }
          html+=`<div class="ingr-bar-wrap"><div class="ingr-bar" style="width:${isFirst?pct:0}%;background:${color};opacity:.7;"></div></div>`;
          html+=`</div>`;
        });
        if(canEdit){
          html+=`<div class="ingr-hop-add-row">${hopAddBtnHtml(id,miGroup.rows[0].mi_id_fk||miGroup.rows[0].mi)}</div>`;
        }
        html+=`</div>`;
      });
    } else {
      rows.forEach(it=>{
        const pct=toGRaw(it.qty,it.unit)/maxPerCat[cat]*100;
        const s=scaledQty(it.qty,it.unit);
        html+=`<div class="ingr-row"><div class="ingr-name">${escHtml(it.name)}</div><div class="ingr-mid">${escHtml(it.mi)}</div><div class="ingr-qty">${escHtml(String(fmtVal(s.val,s.unit)))}</div><div class="ingr-unit">${escHtml(s.unit)}</div><div class="ingr-bar-wrap"><div class="ingr-bar" style="width:${pct}%;background:${color};opacity:.7;"></div></div></div>`;
      });
    }
  });
  container.innerHTML=html;
  bindHopControls(container);
}

/* ═══════════════════════════════════════════
   PROCESS PANE
   ═══════════════════════════════════════════ */
function renderProcessPane(id,profile){
  const container=document.getElementById('processPaneContent');
  if(!container) return;
  const rec=RECIPES.find(r=>r.id===id);
  const yf=RECIPE_YEAST[id]||'ale';
  const yd=YEAST_DEFAULTS[yf];
  const abv=profile?calcAbv(profile.og,profile.fg):null;
  const canEdit=(SDC_ROLE==='admin'||SDC_ROLE==='manager');
  let html='';
  if(abv){html+=`<div style="display:flex;align-items:center;gap:10px;margin-bottom:14px;"><div class="abv-display"><span class="abv-big">${escHtml(abv)}</span><span class="abv-pct">% ABV</span></div><div><div class="abv-calc-note">Lié à l'onglet Ingrédients</div><div class="abv-calc-note" style="margin-top:2px;">OG/FG en °Plato → formule Balling/Terrill</div></div></div>`;}
  html+=`<div class="yeast-family-row"><span class="yf-label">Famille levurienne</span><span class="new-field-tag">nouveau champ · à câbler</span><div class="yf-options">${['ale','lager','spontane','mixte'].map(f=>`<button class="yf-btn${f===yf?' active':''}" onclick="changeYeastFamily(${id},'${escHtml(f)}')">${escHtml(f)}</button>`).join('')}</div></div>`;
  if(profile){html+=`<div class="xray-panel"><div class="xp-label">Cibles mesurées · <span style="color:var(--ink-mute);font-weight:normal;">${profile.batches} brassins rolling 12 mois · °Plato</span></div><div class="xp-grid"><div class="xp-field"><div class="xf-k">OG cible</div><div class="xf-v">${profile.og}<span class="xf-unit">°P</span></div><span class="xf-badge badge-inherited">DB réel</span></div><div class="xp-field"><div class="xf-k">FG cible</div><div class="xf-v">${profile.fg}<span class="xf-unit">°P</span></div><span class="xf-badge badge-inherited">DB réel</span></div><div class="xp-field"><div class="xf-k">Atténuation app.</div><div class="xf-v lab-color">${profile.atten}<span class="xf-unit">%</span></div><span class="xf-badge badge-inherited">DB réel</span></div><div class="xp-field"><div class="xf-k">pH refroid.</div><div class="xf-v">${profile.phCool}</div><span class="xf-badge badge-inherited">DB réel</span></div><div class="xp-field"><div class="xf-k">pH post-ferm.</div><div class="xf-v">${profile.phFerm}</div><span class="xf-badge badge-inherited">DB réel</span></div><div class="xp-field"><div class="xf-k">Ferm. (jours)</div><div class="xf-v">${profile.fermDays}<span class="xf-unit">j</span></div><span class="xf-badge badge-inherited">DB réel</span></div><div class="xp-field"><div class="xf-k">CC / garde (jours)</div><div class="xf-v">${profile.ccDays}<span class="xf-unit">j</span></div><span class="xf-badge badge-inherited">DB réel</span></div><div class="xp-field"><div class="xf-k">ABV calculé</div><div class="xf-v lab-color">${escHtml(abv||'—')}<span class="xf-unit">%</span></div><span class="xf-badge badge-inherited">Calculé</span></div></div></div>`;}
  const roAttr=canEdit?'':' readonly';
  html+=`<div class="xray-panel"><div class="xp-label">Héritage famille <em style="color:var(--ink)">${escHtml(yf)}</em><span class="new-field-tag">champs en attente de migration</span></div><div class="xp-grid" id="yf-inherit-grid-${id}"><div class="xp-field inherited"><div class="xf-k">Temp. fermentation</div><div class="xf-v" style="font-size:16px;">${yd.fermTempMin}–${yd.fermTempMax}<span class="xf-unit">°C</span></div><span class="xf-badge badge-inherited">défaut famille</span></div><div class="xp-field inherited"><div class="xf-k">Garde min. (jours)</div><div class="xf-v"><input type="number" id="inp-garde-${id}" value="${yd.gardeMin}"${roAttr} onblur="onFieldBlur(this,'garde_min','${id}')"><span class="xf-unit">j</span></div><span class="xf-badge badge-inherited" id="badge-garde-${id}">défaut famille</span></div><div class="xp-field pending"><div class="xf-k">CO₂ cible BBT</div><div class="xf-v"><input type="number" id="inp-co2-${id}" value="2.5"${roAttr} onblur="onFieldBlur(this,'co2_bbt','${id}')"><span class="xf-unit">vol</span></div><span class="xf-badge badge-pending">à câbler</span></div></div></div>`;
  container.innerHTML=html;
}

/* Biochimie cards are now server-rendered — no JS build needed. */

/* ═══════════════════════════════════════════
   LEVURE & GARDE PANEL
   ═══════════════════════════════════════════ */
(function(){
  // Helper: find yeast strain by id from window.SDC_YEAST_STRAINS
  function strainById(id){
    return (window.SDC_YEAST_STRAINS||[]).find(s=>s.id===id)||null;
  }
  // Helper: find family defaults from window.SDC_RECIPE_YEAST
  // SDC_RECIPE_YEAST is keyed by recipe_id (string keys from PHP json_encode)
  function recipeYeastRow(recipeId){
    const d=window.SDC_RECIPE_YEAST||{};
    return d[recipeId]||d[String(recipeId)]||null;
  }
  function familyLabel(f){
    const m=window.SDC_YEAST_FAMILY_LABELS||{};
    return m[f]||f||'—';
  }
  function badgeHtml(source){
    if(!source) return '';
    const cls=source==='override'?'badge-override':'badge-inherited';
    const txt=source==='override'?'override recette':'défaut famille';
    return `<span class="xf-badge ${escHtml(cls)}">${escHtml(txt)}</span>`;
  }
  function fieldCls(source){
    if(!source) return 'xp-field';
    return source==='override'?'xp-field override':'xp-field inherited';
  }

  window.ygRenderPanel = function(recipeId){
    const placeholder=document.getElementById('ygPlaceholder');
    const panel=document.getElementById('ygPanel');
    if(!panel) return;

    const yr=recipeYeastRow(recipeId);

    // Show placeholder if no data row (recipe not in the activatable list)
    if(!yr){
      if(placeholder) placeholder.style.display='';
      panel.style.display='none';
      return;
    }
    if(placeholder) placeholder.style.display='none';
    panel.style.display='';

    // Set hidden recipe_id
    const ridEl=document.getElementById('ygRecipeId');
    if(ridEl) ridEl.value=recipeId;

    const canEdit=(SDC_ROLE==='admin'||SDC_ROLE==='manager');
    const strainId=yr.yeast_strain_id!=null?parseInt(yr.yeast_strain_id):null;
    const strainName=yr.strain_name||null;
    const strainFamily=yr.strain_family||null;

    // ── Strain select ────────────────────────────────────────────────────────
    const sel=document.getElementById('ygStrainSelect');
    if(sel){
      sel.value=strainId!=null?String(strainId):'';
    }
    const roEl=document.getElementById('ygStrainReadOnly');
    if(roEl) roEl.textContent=strainName||'— aucune souche —';

    // ── Family section ───────────────────────────────────────────────────────
    ygUpdateFamilySection(strainId, strainFamily, canEdit);

    // ── Defaults grid ────────────────────────────────────────────────────────
    ygUpdateDefaultsGrid(yr);

    // ── Override inputs ──────────────────────────────────────────────────────
    function setOvrInput(id, val){
      const el=document.getElementById(id);
      if(el) el.value=(val!==null&&val!==undefined&&val!=='')?val:'';
    }
    setOvrInput('ygGardeOvr',   yr.garde_days_min_override);
    setOvrInput('ygTempMinOvr', yr.ferm_temp_min_override);
    setOvrInput('ygTempMaxOvr', yr.ferm_temp_max_override);

    // ── Override badges ──────────────────────────────────────────────────────
    function setBadge(id, src){
      const el=document.getElementById(id);
      if(!el) return;
      el.className='xf-badge'+(src?' badge-'+(src==='override'?'override':'inherited'):'');
      el.textContent=src?(src==='override'?'override recette':'défaut famille'):'';
    }
    setBadge('ygGardeBadge',   yr.garde_source);
    setBadge('ygTempMinBadge', yr.temp_source);
    setBadge('ygTempMaxBadge', yr.temp_source);

    // ── Effective resolved summary ────────────────────────────────────────────
    ygUpdateEffective(yr);
  };

  window.ygOnStrainChange = function(strainIdStr){
    const strainId=strainIdStr&&strainIdStr!==''?parseInt(strainIdStr):null;
    const strain=strainId!=null?strainById(strainId):null;
    const strainFamily=strain?strain.family:null;
    const canEdit=(SDC_ROLE==='admin'||SDC_ROLE==='manager');
    ygUpdateFamilySection(strainId, strainFamily, canEdit);
  };

  function ygUpdateFamilySection(strainId, strainFamily, canEdit){
    const sec=document.getElementById('ygFamilySection');
    if(!sec) return;

    if(strainId==null){
      sec.style.display='none';
      return;
    }
    sec.style.display='';

    const famSel=document.getElementById('ygFamilySelect');
    const famRo=document.getElementById('ygFamilyReadOnly');

    if(strainFamily){
      // Already has a family — show current; admin can still edit (global change)
      if(famSel){
        famSel.value=strainFamily;
      }
      if(famRo) famRo.textContent=familyLabel(strainFamily);
      // Show a note that it's already set
      const notice=sec.querySelector('.yg-section-sub');
      if(notice && canEdit){
        notice.innerHTML='Famille actuelle&nbsp;: <em>' + escHtml(familyLabel(strainFamily))
          + '</em> · modifier s\'applique à <em>toutes</em> les recettes utilisant cette souche';
      }
    } else {
      // No family — invite classification
      if(famSel) famSel.value='';
      if(famRo) famRo.textContent='— non classifiée —';
      const notice=sec.querySelector('.yg-section-sub');
      if(notice){
        notice.innerHTML='<span style="color:var(--ember)">⚠ Souche non classifiée</span>'
          + ' — sans famille, la garde ne peut pas être résolue automatiquement';
      }
    }
  }

  function ygUpdateDefaultsGrid(yr){
    const grid=document.getElementById('ygDefaultsGrid');
    const defSec=document.getElementById('ygDefaultsSection');
    if(!grid||!defSec) return;

    // Pull family defaults from the yeast row (populated by SQL via eligibility fragments)
    // The effective_* columns reflect COALESCE but we want the PURE family defaults here.
    // We can derive them: if garde_source='family' → effective_garde = family default.
    // But we don't have the raw family default stored separately.
    // For display, show "effective" with source badge — this is the resolved value the operator cares about.
    const gardeEffective = yr.effective_garde;
    const tempMinEffective = yr.effective_temp_min;
    const tempMaxEffective = yr.effective_temp_max;
    const gardeSrc = yr.garde_source;
    const tempSrc  = yr.temp_source;

    if(yr.strain_family==null){
      defSec.style.display='none';
      return;
    }
    defSec.style.display='';

    let html='';
    // Garde
    html+=`<div class="${escHtml(fieldCls(gardeSrc))}">`;
    html+=`<div class="xf-k">Garde min.</div>`;
    html+=`<div class="xf-v">${gardeEffective!=null?escHtml(String(gardeEffective)):'<span style="color:var(--ink-faint);">—</span>'}<span class="xf-unit">j</span></div>`;
    html+=badgeHtml(gardeSrc);
    html+=`</div>`;
    // Temp min
    html+=`<div class="${escHtml(fieldCls(tempSrc))}">`;
    html+=`<div class="xf-k">Temp. ferm. min</div>`;
    html+=`<div class="xf-v">${tempMinEffective!=null?escHtml(String(parseFloat(tempMinEffective).toFixed(1))):'<span style="color:var(--ink-faint);">—</span>'}<span class="xf-unit">°C</span></div>`;
    html+=badgeHtml(tempSrc);
    html+=`</div>`;
    // Temp max
    html+=`<div class="${escHtml(fieldCls(tempSrc))}">`;
    html+=`<div class="xf-k">Temp. ferm. max</div>`;
    html+=`<div class="xf-v">${tempMaxEffective!=null?escHtml(String(parseFloat(tempMaxEffective).toFixed(1))):'<span style="color:var(--ink-faint);">—</span>'}<span class="xf-unit">°C</span></div>`;
    html+=badgeHtml(tempSrc);
    html+=`</div>`;

    grid.innerHTML=html;
  }

  function ygUpdateEffective(yr){
    const row=document.getElementById('ygEffectiveRow');
    const roRow=document.getElementById('ygEffectiveReadOnly');
    const gardeEffective=yr.effective_garde;
    const gardeSrc=yr.garde_source;
    const tempMinEffective=yr.effective_temp_min;
    const tempMaxEffective=yr.effective_temp_max;
    const tempSrc=yr.temp_source;

    function mkCell(label, val, unit, src){
      return `<div class="${escHtml(fieldCls(src))}">
        <div class="xf-k">${escHtml(label)}</div>
        <div class="xf-v">${val!=null?escHtml(String(val)):'<span style="color:var(--ink-faint);">—</span>'}<span class="xf-unit">${escHtml(unit)}</span></div>
        ${badgeHtml(src)}
      </div>`;
    }

    const html=`<div class="xp-grid">
      ${mkCell('Garde effective',gardeEffective,'j',gardeSrc)}
      ${mkCell('Temp. min effective',tempMinEffective!=null?parseFloat(tempMinEffective).toFixed(1):null,'°C',tempSrc)}
      ${mkCell('Temp. max effective',tempMaxEffective!=null?parseFloat(tempMaxEffective).toFixed(1):null,'°C',tempSrc)}
    </div>`;

    if(row) row.innerHTML=html;
    if(roRow) roRow.innerHTML=html;
  }
})();

/* ═══════════════════════════════════════════
   QC PANEL — per-recipe CO₂ target + vol overrides editor
   ═══════════════════════════════════════════ */
(function(){
  const SDC_RECIPE_QC = window.SDC_RECIPE_QC || {};

  // Populate the QC editor panel for the given recipe id.
  // Shows current co2_target / co2_tolerance / vol override values
  // (from window.SDC_RECIPE_QC) plus the history-derived vol band for context.
  window.qcRenderPanel = function(recipeId){
    const panel = document.getElementById('qcPanel');
    if(!panel) return;

    const qc = SDC_RECIPE_QC[String(recipeId)] || null;

    // Always show the panel when a recipe is selected
    panel.style.display = '';

    // Set hidden recipe_id
    const ridEl = document.getElementById('qcRecipeId');
    if(ridEl) ridEl.value = recipeId;

    function setNum(id, val){
      const el = document.getElementById(id);
      if(!el) return;
      el.value = (val !== null && val !== undefined) ? val : '';
    }

    // CO₂ fields
    setNum('qcCo2Target',    qc ? qc.co2_target    : null);
    setNum('qcCo2Tolerance', qc ? qc.co2_tolerance : null);

    // Volume override fields
    setNum('qcVolWarnLo',    qc ? qc.racked_vol_warn_lo    : null);
    setNum('qcVolWarnHi',    qc ? qc.racked_vol_warn_hi    : null);
    setNum('qcVolOutlierLo', qc ? qc.racked_vol_outlier_lo : null);
    setNum('qcVolOutlierHi', qc ? qc.racked_vol_outlier_hi : null);

    // Vol band info (history-derived, read-only)
    const vbInfo = document.getElementById('qcVolBandInfo');
    const vbDisp = document.getElementById('qcVolBandDisplay');
    const vb = (qc && qc.vol_band) ? qc.vol_band : null;
    if(vbInfo && vbDisp){
      if(vb){
        vbInfo.style.display = '';
        const mean    = vb.mean_vol   != null ? parseFloat(vb.mean_vol).toFixed(1)   : '—';
        const stddev  = vb.stddev_vol != null ? parseFloat(vb.stddev_vol).toFixed(1) : '—';
        const warnLo  = vb.warn_lo    != null ? parseFloat(vb.warn_lo).toFixed(1)    : '—';
        const warnHi  = vb.warn_hi    != null ? parseFloat(vb.warn_hi).toFixed(1)    : '—';
        const outLo   = vb.outlier_lo != null ? parseFloat(vb.outlier_lo).toFixed(1) : '—';
        const outHi   = vb.outlier_hi != null ? parseFloat(vb.outlier_hi).toFixed(1) : '—';
        vbDisp.textContent =
          'n=' + vb.n + '  ·  moy=' + mean + ' HL  σ=' + stddev + ' HL'
          + '  ·  warn [' + warnLo + '–' + warnHi + ' HL]'
          + '  outlier [' + outLo + '–' + outHi + ' HL]';
      } else {
        vbInfo.style.display = 'none';
        vbDisp.textContent = '';
      }
    }

    // Live CO₂ band preview
    updateCo2Preview();
  };

  // Recompute the warn/outlier band from current co2_target / co2_tolerance inputs
  // and display it read-only for operator context.
  function updateCo2Preview(){
    const previewEl = document.getElementById('qcCo2Preview');
    if(!previewEl) return;
    const tgt = parseFloat(document.getElementById('qcCo2Target')  ? document.getElementById('qcCo2Target').value  : '');
    const tol = parseFloat(document.getElementById('qcCo2Tolerance')? document.getElementById('qcCo2Tolerance').value: '');
    if(!isNaN(tgt) && !isNaN(tol) && tol >= 0){
      const wLo = (tgt - tol).toFixed(2);
      const wHi = (tgt + tol).toFixed(2);
      const oLo = (tgt - 2*tol).toFixed(2);
      const oHi = (tgt + 2*tol).toFixed(2);
      previewEl.textContent = '→ warn [' + wLo + '–' + wHi + ' g/L]  outlier [' + oLo + '–' + oHi + ' g/L]';
      previewEl.style.display = '';
    } else {
      previewEl.style.display = 'none';
    }
  }

  // Wire live CO₂ preview to the two inputs
  ['qcCo2Target','qcCo2Tolerance'].forEach(function(id){
    const el = document.getElementById(id);
    if(el) el.addEventListener('input', updateCo2Preview);
  });
})();

/* ═══════════════════════════════════════════
   INTERACTIVE — yeast family change
   ═══════════════════════════════════════════ */
function changeYeastFamily(id,family){
  RECIPE_YEAST[id]=family;
  renderProcessPane(id,PROFILES[id]);
  showToast('Famille: '+family+' · champ à câbler en DB');
}

/* ═══════════════════════════════════════════
   INTERACTIVE — field edit (mock, pending DB wiring)
   ═══════════════════════════════════════════ */
function onFieldBlur(inp,field,id){
  if(SDC_ROLE==='operateur') return;
  const oldVal=inp.defaultValue;
  const newVal=inp.value;
  if(oldVal===newVal) return;
  if(SDC_ROLE==='admin'){
    pendingModal={inp,oldVal,newVal,field,id};
    document.getElementById('modalContext').textContent=field+' (recette #'+id+')';
    document.getElementById('modalOld').textContent=oldVal;
    document.getElementById('modalNew').textContent=newVal;
    document.getElementById('modalOverlay').classList.add('open');
  } else {
    inp.value=oldVal;
    showToast('Modification soumise pour approbation');
  }
}

function closeModal(){
  if(pendingModal) pendingModal.inp.value=pendingModal.oldVal;
  pendingModal=null;
  document.getElementById('modalOverlay').classList.remove('open');
}
function applyModal(){
  if(!pendingModal){document.getElementById('modalOverlay').classList.remove('open');return;}
  const pm=pendingModal;
  pendingModal=null;
  document.getElementById('modalOverlay').classList.remove('open');
  if((pm.isStyle||pm.isName)&&pm.recipeId){
    // Build and submit a hidden form for the real POST (PRG — page will reload to sec=recettes)
    const f=document.createElement('form');
    f.method='post';f.action='/modules/salle-de-controle.php';f.style.display='none';
    const add=(n,v)=>{const i=document.createElement('input');i.type='hidden';i.name=n;i.value=v;f.appendChild(i);};
    add('csrf',window.SDC_CSRF||'');
    add('recipe_id',pm.recipeId);
    if(pm.isStyle){add('action','update_recipe_style');add('style',pm.newVal);}
    else{add('action','update_recipe_name');add('name',pm.newVal);}
    document.body.appendChild(f);
    f.submit();
  } else {
    // Fallback for any other pending field (currently none — onFieldBlur is still mock)
    pm.inp.defaultValue=pm.newVal;
  }
}

/* onBiochemBlur removed — biochem edit is now a real POST form. */

/* ═══════════════════════════════════════════
   STYLE FIELD
   ═══════════════════════════════════════════ */
function onStyleBlur(inp){
  if(SDC_ROLE==='operateur') return;
  const oldVal=inp.defaultValue;
  const newVal=inp.value.trim();
  if(oldVal===newVal) return;
  if(!selectedRecipeId){inp.value=oldVal;return;}
  if(SDC_ROLE==='admin'||SDC_ROLE==='manager'){
    pendingModal={inp,oldVal,newVal,field:'style',id:selectedRecipeId,recipeId:selectedRecipeId,isStyle:true};
    document.getElementById('modalContext').textContent='Style · recette #'+selectedRecipeId;
    document.getElementById('modalOld').textContent=oldVal||'(vide)';
    document.getElementById('modalNew').textContent=newVal||'(vide)';
    document.getElementById('modalOverlay').classList.add('open');
  } else {inp.value=oldVal;showToast('Style soumis pour approbation');}
}

/* ═══════════════════════════════════════════
   NAME FIELD
   ═══════════════════════════════════════════ */
function onNameBlur(inp){
  if(SDC_ROLE==='operateur') return;
  const oldVal=inp.defaultValue;
  const newVal=inp.value.trim();
  if(oldVal===newVal||!newVal) {inp.value=oldVal||'';return;}
  if(!selectedRecipeId){inp.value=oldVal;return;}
  if(SDC_ROLE==='admin'||SDC_ROLE==='manager'){
    pendingModal={inp,oldVal,newVal,field:'name',id:selectedRecipeId,recipeId:selectedRecipeId,isName:true};
    document.getElementById('modalContext').textContent='Nom · recette #'+selectedRecipeId;
    document.getElementById('modalOld').textContent=oldVal;
    document.getElementById('modalNew').textContent=newVal;
    document.getElementById('modalOverlay').classList.add('open');
  } else {inp.value=oldVal;showToast('Nom soumis pour approbation');}
}

/* ═══════════════════════════════════════════
   NEW RECIPE MODAL
   ═══════════════════════════════════════════ */
function openNewRecipeModal(){
  if(SDC_ROLE==='operateur') return;
  const el=document.getElementById('newRecipeOverlay');
  if(!el) return;
  document.getElementById('nr-name').value='';
  document.getElementById('nr-style').value='';
  document.getElementById('nr-yeast').value='ale';
  el.classList.add('open');
  setTimeout(()=>document.getElementById('nr-name').focus(),80);
}
function closeNewRecipeModal(){
  const el=document.getElementById('newRecipeOverlay');
  if(el) el.classList.remove('open');
}
function createRecipe(){
  const name=document.getElementById('nr-name').value.trim();
  const style=document.getElementById('nr-style').value.trim();
  const yeast=document.getElementById('nr-yeast').value;
  if(!name){document.getElementById('nr-name').focus();showToast('Nom de recette requis');return;}
  if(SDC_ROLE==='manager'){closeNewRecipeModal();showToast('Demande soumise: '+name+' · en attente admin');return;}
  const newId=Date.now();
  const newRec={id:newId,name,sku:'',subtype:'Core',vintage:''};
  RECIPES_ACTIVES.push(newRec);INGREDIENTS[newId]=[];PROFILES[newId]=null;RECIPE_YEAST[newId]=yeast;
  if(style) RECIPE_STYLES[newId]=style;
  const badge=document.querySelector('.nav-badge');
  if(badge) badge.textContent=RECIPES.length;
  closeNewRecipeModal();buildRecipeList();selectRecipe(newId);
  showToast('Recette créée (mock): '+name+(style?' · '+style:''));
}

/* ═══════════════════════════════════════════
   STRAIN CATALOG EDIT MODAL
   Reads from window.SDC_YEAST_STRAINS to pre-fill the dialog fields.
   Uses the native <dialog> element; avoids display:none lazy-image trap.
   ═══════════════════════════════════════════ */
(function(){
  const dialog = document.getElementById('scEditDialog');
  if (!dialog) return; // manager/admin only — dialog is absent for operateur

  function setOpt(selectEl, value) {
    // Set a <select> to value or '' if value is null/undefined/empty
    const v = (value != null && value !== '') ? String(value) : '';
    selectEl.value = v;
    // fallback: if the value doesn't exist as an option, reset to ''
    if (selectEl.value !== v) selectEl.value = '';
  }

  window.scOpenEdit = function(strainId) {
    const s = (window.SDC_YEAST_STRAINS || []).find(x => x.id === strainId);
    if (!s) return;

    document.getElementById('scEditTitle').textContent = 'Modifier · ' + s.name;
    document.getElementById('scEditStrainId').value = strainId;

    setOpt(document.getElementById('scEditFamily'), s.family);
    setOpt(document.getElementById('scEditFloc'),   s.flocculation);

    const setNum = (elId, val) => {
      document.getElementById(elId).value = (val !== null && val !== undefined) ? val : '';
    };
    setNum('scEditAttMin', s.attenuation_min);
    setNum('scEditAttMax', s.attenuation_max);
    setNum('scEditTMin',   s.temp_min);
    setNum('scEditTMax',   s.temp_max);

    dialog.showModal();
  };

  window.scCloseEdit = function() {
    dialog.close();
  };

  // Close on backdrop click
  dialog.addEventListener('click', function(e) {
    if (e.target === dialog) dialog.close();
  });

  // Close on Escape is native to <dialog> — no extra listener needed
})();

/* ═══════════════════════════════════════════
   TOAST
   ═══════════════════════════════════════════ */
let toastTimer;
function showToast(msg){
  const t=document.getElementById('sdcToast');
  t.textContent=msg;t.classList.add('show');
  clearTimeout(toastTimer);toastTimer=setTimeout(()=>t.classList.remove('show'),2800);
}

/* ═══════════════════════════════════════════
   INIT
   ═══════════════════════════════════════════ */
buildRecipeList();
switchSection(SDC_INITIAL);

// Restore position after a PRG redirect.
// PHP carries &recipe=<id>&sub=<tab> (recipe actions) or &strain=<id> (biochem).
(function(){
  const sp       = new URLSearchParams(location.search);
  const recipeId = sp.has('recipe') ? parseInt(sp.get('recipe'), 10) : null;
  const sub      = sp.get('sub') || null;
  const strainId = sp.has('strain') ? parseInt(sp.get('strain'), 10) : null;

  if(SDC_INITIAL==='recettes' && recipeId && !isNaN(recipeId)){
    // Switch subtab first so selectRecipe triggers the right panel render.
    if(sub && ['ingr','process','formats','yeast'].includes(sub)) switchSubtab(sub);
    selectRecipe(recipeId);
    // Scroll recipe item into view after a short frame to let the list render.
    requestAnimationFrame(()=>{
      const el=document.querySelector('.recipe-item[data-id="'+recipeId+'"]');
      if(el) el.scrollIntoView({block:'center',behavior:'smooth'});
    });
  } else if(SDC_INITIAL==='recettes'){
    // Default: auto-select first active recipe.
    const firstRec=(RECIPES_ACTIVES[0]||RECIPES[0]);
    if(firstRec) selectRecipe(firstRec.id);
  }

  if(SDC_INITIAL==='biochem' && strainId && !isNaN(strainId)){
    // Scroll to the matching strain row in the catalog table.
    requestAnimationFrame(()=>{
      const row=document.querySelector('tr[data-strain-id="'+strainId+'"]');
      if(row) row.scrollIntoView({block:'center',behavior:'smooth'});
    });
  }
})();
</script>
<script src="/js/salle-de-controle.js?v=<?= @filemtime(__DIR__ . '/../js/salle-de-controle.js') ?: time() ?>"></script>
</body>
</html>
