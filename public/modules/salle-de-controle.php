<?php
declare(strict_types=1);
/**
 * modules/salle-de-controle.php — Salle de contrôle (Qualité)
 *
 * Le Zeppelin family · Sections: Recettes, Biochimie, Conditionnement.
 * Recettes + Biochimie: data-wired for Formats subtab (activate/deactivate SKU formats,
 *   manage placeholder bindings label/can/sticker/holder/outer_tray/scotch).
 * Conditionnement: LIVE — reads/writes commissioning_settings (section='packaging').
 *
 * Auth: require_login() — all logged-in users can view; edit gated to is_admin().
 * POST handlers:
 *   update_min_days     — Conditionnement settings (admin only)
 *   update_yeast_family — Biochimie yeast-family defaults (admin only)
 *   activate_format     — upsert ref_skus row for (recipe_id, format_id) (admin only)
 *   deactivate_format   — soft-deactivate ref_skus row (admin only)
 *   set_binding         — upsert ref_recipe_packaging_bindings row (admin only)
 */

require __DIR__ . '/../../app/auth.php';
require __DIR__ . '/../../app/csrf.php';
require __DIR__ . '/../../app/settings-helpers.php';
require __DIR__ . '/../../app/db-write-helpers.php';
require_once __DIR__ . '/../../app/sku-bom-compile.php';
require_once __DIR__ . '/../../app/yeast-eligibility.php';
require_once __DIR__ . '/../../app/qc-thresholds.php';

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
function sdc_recompile_recipe_packaging(PDO $pdo, int $recipeId): array
{
    $stmt = $pdo->prepare(
        "SELECT id FROM ref_skus WHERE recipe_id = ? AND is_active = 1"
    );
    $stmt->execute([$recipeId]);
    $skuIds = array_map('intval', array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'id'));
    if (empty($skuIds)) {
        return [
            'dry_run'            => false,
            'skus'               => [],
            'total_pkg_deleted'  => 0,
            'total_pkg_inserted' => 0,
            'total_rq_emitted'   => 0,
            'parity_violations'  => 0,
            'errors'             => 0,
        ];
    }
    return compile_sku_bom_packaging($pdo, $skuIds, false, true);
}

// ─────────────────────────────────────────────────────────────────────────────
// HELPER: set the flash message after a BOM recompile attempt.
// saveMsg = success label for the preceding write (e.g. "Format «ZEPF» activé.").
// r       = result array from sdc_recompile_recipe_packaging().
// Caller wraps the recompile + this call in try/catch; the catch block calls
// flash_set('ok', $saveMsg . " · BOM recompilation échouée …") directly.
// Never rethrows — a recompile failure keeps the saved record and flashes a note.
// ─────────────────────────────────────────────────────────────────────────────
function sdc_flash_bom_result(string $saveMsg, array $r): void
{
    if ($r['parity_violations'] > 0 || $r['errors'] > 0) {
        flash_set('err', $saveMsg
            . " · BOM recompilé avec avertissements"
            . " ({$r['parity_violations']} violation(s) parité, {$r['errors']} erreur(s))."
            . " La sauvegarde est conservée.");
    } else {
        flash_set('ok', $saveMsg
            . " · BOM recompilé ({$r['total_pkg_inserted']} lignes, {$r['total_rq_emitted']} en file).");
    }
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

    // Redirect target depends on action
    $redirectSec = in_array($action, ['activate_format','deactivate_format','set_binding','update_recipe_yeast','update_recipe_qc'], true)
        ? 'recettes'
        : ($action === 'update_yeast_family' ? 'biochem'
        : (in_array($action, ['cip_type_add','cip_type_update','cip_type_deactivate','cip_type_reactivate'], true)
            ? 'cip' : 'conditionnement'));

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
                redirect_to('/modules/salle-de-controle.php?sec=recettes');
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

        // ── update_recipe_qc ─────────────────────────────────────────────────
        // Editor for per-recipe CO₂ target/tolerance and optional racked_vol overrides.
        // Role gate: admin or manager (mirrors update_recipe_yeast).
        // Empty inputs → store NULL (falls back to history band / global).
        // Negative values → rejected.
        } elseif ($action === 'update_recipe_qc') {
            if (!is_admin($me) && !is_manager($me)) {
                flash_set('err', 'Modification réservée aux administrateurs et managers.');
                redirect_to('/modules/salle-de-controle.php?sec=recettes');
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

        } else {
            throw new RuntimeException('Action inconnue.');
        }
    } catch (Throwable $e) {
        flash_set('err', pdo_friendly_error($e, 'salle-de-controle'));
    }

    redirect_to('/modules/salle-de-controle.php?sec=' . $redirectSec);
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

    // Activatable recipes (sku_prefix NOT NULL/empty)
    $activatableRecs = $pdo->query(
        "SELECT id, name, classification, subtype, sku_prefix
           FROM ref_recipes
          WHERE sku_prefix IS NOT NULL AND sku_prefix<>'' AND is_active=1
          ORDER BY FIELD(subtype,'Core','EPH','CollabIn','Archive'), name"
    )->fetchAll(PDO::FETCH_ASSOC);

    // NULL-prefix recipes (read-only list)
    $noPrefix = $pdo->query(
        "SELECT id, name, classification, subtype
           FROM ref_recipes
          WHERE (sku_prefix IS NULL OR sku_prefix='') AND is_active=1
          ORDER BY name"
    )->fetchAll(PDO::FETCH_ASSOC);

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

// --- Yeast strains (for per-recipe assignment panel) -------------------------
$yeastStrains    = [];
$yeastLoadErr    = null;
try {
    $pdo = maltytask_pdo();
    $ysStmt = $pdo->query(
        "SELECT id, name, family, type, is_active
           FROM ref_yeast_strains
          WHERE is_active = 1
          ORDER BY name"
    );
    $yeastStrains = $ysStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $yeastLoadErr = $e->getMessage();
}

// --- Per-recipe yeast resolutions (for all activatable recipes) --------------
// Keyed by recipe id. Silently skips on DB error (panel shows "no data" state).
$recipeYeastData  = [];
$recipeYeastError = null;
try {
    $pdo = maltytask_pdo();
    if (!empty($activatableRecs ?? [])) {
        $allRecIds = array_column($activatableRecs, 'id');
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
// Keyed by recipe id. Used to pre-populate the QC editor panel.
$recipeQcData  = [];
$recipeQcError = null;
try {
    $pdo = maltytask_pdo();
    if (!empty($activatableRecs ?? [])) {
        $allRecIds3 = array_column($activatableRecs, 'id');
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

$minDaysSetting = $settingsByKey['min_days_after_racking'] ?? null;
$minDaysCurrent = $minDaysSetting !== null
    ? (float) ($minDaysSetting['value_num'] ?? $minDaysSetting['default_num'] ?? 1)
    : 1.0;
$minDaysInt = (int) round($minDaysCurrent);

$csrf = csrf_token();

// Active section from query string (for PRG redirect after save)
$sec = $_GET['sec'] ?? '';
$initialSec = in_array($sec, ['recettes', 'biochem', 'conditionnement', 'cip'], true)
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
        'id'     => (int)    $s['id'],
        'name'   => (string) $s['name'],
        'family' => $s['family'],
        'type'   => (string) $s['type'],
    ], $yeastStrains),
    JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP
) ?>;
window.SDC_RECIPE_YEAST = <?= json_encode($recipeYeastData, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
window.SDC_RECIPE_QC = <?= json_encode($recipeQcData, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
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

        <!-- recipe list column -->
        <div class="recipe-list-col">
          <div class="col-header">
            <div class="col-title">Recettes <em>actives</em></div>
            <div class="col-subtitle">Nébuleuse · core + EPH</div>
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
              <div class="rdh-title" id="rdh-title">Sélectionner <em>une recette</em></div>
              <div class="rdh-style-row">
                <input class="rdh-style-input" id="rdh-style" type="text" placeholder="style — à renseigner" maxlength="80"
                  onblur="onStyleBlur(this)" autocomplete="off"
                  <?= !is_admin($me) && !is_manager($me) ? 'readonly' : '' ?>>
                <span class="new-field-tag">nouveau champ · à câbler</span>
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
                <button class="ist-btn active" id="istBrassin" onclick="setIngrScale('brassin')">Par brassin · 30 hl</button>
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
      </div>
    </div><!-- /sec-cip -->

  </div><!-- /content-area -->
</div><!-- /sdc-stage -->

<!-- CONFIRM MODAL (client-side, mockup interactions only — TODO: data-wiring) -->
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
   DATA — baked from live DB (ref_recipe_profile rolling_12mo)
   Recettes + Biochimie are presentational this pass.
   TODO: data-wiring phase — replace with server-injected window.SDC_* JSON.
   ═══════════════════════════════════════════ */
const RECIPES = [
  {id:57,name:"Zepp",sku:"ZEP",subtype:"Core",vintage:""},
  {id:32,name:"Embuscade",sku:"EMB",subtype:"Core",vintage:""},
  {id:44,name:"Moonshine",sku:"MOO",subtype:"Core",vintage:""},
  {id:51,name:"Speakeasy",sku:"SPY",subtype:"Core",vintage:""},
  {id:52,name:"Stirling",sku:"STI",subtype:"Core",vintage:""},
  {id:30,name:"Double Oat",sku:"DOA",subtype:"Core",vintage:""},
  {id:25,name:"Diversion",sku:"DIV",subtype:"Core",vintage:""},
  {id:26,name:"Diversion Blanche",sku:"DIB",subtype:"Core",vintage:""},
  {id:6,name:"Alternative",sku:"ALT",subtype:"Core",vintage:""},
];

const INGREDIENTS = {
  57:[{mi:"MALT_PILSENER",name:"Pilsener",cat:"Malt",qty:15.833,unit:"kg"},{mi:"HOPS_HERKULES",name:"Herkules",cat:"Hops",qty:13.333,unit:"g"},{mi:"HOPS_SAAZER",name:"Saazer",cat:"Hops",qty:83.333,unit:"g"},{mi:"HOPS_SPALTER_SELECT",name:"Spalter Select",cat:"Hops",qty:83.333,unit:"g"},{mi:"PROC_PHOSPHORIQUE",name:"Phosphorique",cat:"Proc/Chem",qty:23.333,unit:"ml"},{mi:"PROC_YEASTVIT",name:"Yeastvit",cat:"Proc/Chem",qty:8.0,unit:"g"},{mi:"MIN_CACL2",name:"Calcium Chloride",cat:"Minéraux",qty:5.833,unit:"g"},{mi:"MIN_MGCL2",name:"Magnesium Chloride",cat:"Minéraux",qty:5.833,unit:"g"},{mi:"MIN_MGSO4",name:"Magnesium Sulphate",cat:"Minéraux",qty:3.5,unit:"g"},{mi:"MIN_NACL",name:"Sodium Chloride",cat:"Minéraux",qty:4.667,unit:"g"}],
  32:[{mi:"MALT_MUNICH",name:"Munich",cat:"Malt",qty:8.0,unit:"kg"},{mi:"MALT_PILSENER",name:"Pilsener",cat:"Malt",qty:14.833,unit:"kg"},{mi:"HOPS_AMARILLO",name:"Amarillo",cat:"Hops",qty:333.333,unit:"g"},{mi:"HOPS_CASCADE",name:"Cascade",cat:"Hops",qty:166.667,unit:"g"},{mi:"HOPS_HERKULES",name:"Herkules",cat:"Hops",qty:33.333,unit:"g"},{mi:"HOPS_SIMCOE",name:"Simcoe",cat:"Hops",qty:166.667,unit:"g"},{mi:"PROC_PHOSPHORIQUE",name:"Phosphorique",cat:"Proc/Chem",qty:45.0,unit:"ml"},{mi:"PROC_YEASTVIT",name:"Yeastvit",cat:"Proc/Chem",qty:8.0,unit:"g"},{mi:"MIN_CACL2",name:"Calcium Chloride",cat:"Minéraux",qty:10.0,unit:"g"},{mi:"MIN_CASO4",name:"Calcium Sulphate",cat:"Minéraux",qty:6.667,unit:"g"}],
  44:[{mi:"MALT_PILSENER",name:"Pilsener",cat:"Malt",qty:10.333,unit:"kg"},{mi:"MALT_WHEAT",name:"Wheat",cat:"Malt",qty:8.333,unit:"kg"},{mi:"HOPS_CASCADE",name:"Cascade",cat:"Hops",qty:166.667,unit:"g"},{mi:"ADJ_CORIANDER",name:"Coriander",cat:"Adjunct",qty:73.333,unit:"g"},{mi:"ADJ_ORANGE_PEEL",name:"Orange Peel",cat:"Adjunct",qty:110.0,unit:"g"},{mi:"PROC_PHOSPHORIQUE",name:"Phosphorique",cat:"Proc/Chem",qty:26.667,unit:"ml"},{mi:"PROC_YEASTVIT",name:"Yeastvit",cat:"Proc/Chem",qty:8.0,unit:"g"},{mi:"MIN_CACL2",name:"Calcium Chloride",cat:"Minéraux",qty:5.933,unit:"g"},{mi:"MIN_CASO4",name:"Calcium Sulphate",cat:"Minéraux",qty:5.933,unit:"g"},{mi:"MIN_MGCL2",name:"Magnesium Chloride",cat:"Minéraux",qty:3.567,unit:"g"}],
  51:[{mi:"MALT_OAT_FLAKES",name:"Oat Flakes",cat:"Malt",qty:2.0,unit:"kg"},{mi:"MALT_PILSENER",name:"Pilsener",cat:"Malt",qty:8.667,unit:"kg"},{mi:"MALT_WHEAT",name:"Wheat",cat:"Malt",qty:4.667,unit:"kg"},{mi:"HOPS_C_INCOGNITO",name:"Citra Incognito",cat:"Hops",qty:66.667,unit:"g"},{mi:"HOPS_EL_DORADO",name:"El Dorado",cat:"Hops",qty:166.667,unit:"g"},{mi:"HOPS_MOSAIC",name:"Mosaic",cat:"Hops",qty:333.333,unit:"g"},{mi:"PROC_PHOSPHORIQUE",name:"Phosphorique",cat:"Proc/Chem",qty:28.333,unit:"ml"},{mi:"PROC_YEASTVIT",name:"Yeastvit",cat:"Proc/Chem",qty:8.0,unit:"g"},{mi:"MIN_CACL2",name:"Calcium Chloride",cat:"Minéraux",qty:19.667,unit:"g"}],
  52:[{mi:"MALT_CRYSTAL",name:"Cara Crystal",cat:"Malt",qty:0.277,unit:"kg"},{mi:"MALT_MUNICH",name:"Munich",cat:"Malt",qty:4.167,unit:"kg"},{mi:"MALT_PILSENER",name:"Pilsener",cat:"Malt",qty:13.667,unit:"kg"},{mi:"HOPS_CASCADE",name:"Cascade",cat:"Hops",qty:166.667,unit:"g"},{mi:"HOPS_HERKULES",name:"Herkules",cat:"Hops",qty:16.667,unit:"g"},{mi:"HOPS_SIMCOE",name:"Simcoe",cat:"Hops",qty:266.667,unit:"g"},{mi:"PROC_DEHAZE",name:"Dehaze",cat:"Proc/Chem",qty:5.0,unit:"ml"},{mi:"PROC_PHOSPHORIQUE",name:"Phosphorique",cat:"Proc/Chem",qty:40.0,unit:"ml"},{mi:"PROC_YEASTVIT",name:"Yeastvit",cat:"Proc/Chem",qty:8.0,unit:"g"},{mi:"MIN_CACL2",name:"Calcium Chloride",cat:"Minéraux",qty:4.867,unit:"g"},{mi:"MIN_CASO4",name:"Calcium Sulphate",cat:"Minéraux",qty:2.433,unit:"g"},{mi:"MIN_MGSO4",name:"Magnesium Sulphate",cat:"Minéraux",qty:4.867,unit:"g"},{mi:"MIN_NACL",name:"Sodium Chloride",cat:"Minéraux",qty:6.083,unit:"g"}],
  30:[{mi:"MALT_OAT_FLAKES",name:"Oat Flakes",cat:"Malt",qty:4.667,unit:"kg"},{mi:"MALT_PILSENER",name:"Pilsener",cat:"Malt",qty:25.0,unit:"kg"},{mi:"MALT_WHEAT",name:"Wheat",cat:"Malt",qty:3.333,unit:"kg"},{mi:"HOPS_MOSAIC",name:"Mosaic",cat:"Hops",qty:500.0,unit:"g"},{mi:"HOPS_M_INCOGNITO",name:"Mosaic Incognito",cat:"Hops",qty:66.667,unit:"g"},{mi:"PROC_PHOSPHORIQUE",name:"Phosphorique",cat:"Proc/Chem",qty:53.333,unit:"ml"},{mi:"PROC_YEASTVIT",name:"Yeastvit",cat:"Proc/Chem",qty:8.0,unit:"g"},{mi:"MIN_CACL2",name:"Calcium Chloride",cat:"Minéraux",qty:8.867,unit:"g"},{mi:"MIN_CASO4",name:"Calcium Sulphate",cat:"Minéraux",qty:34.2,unit:"g"},{mi:"MIN_MGSO4",name:"Magnesium Sulphate",cat:"Minéraux",qty:12.667,unit:"g"}],
  25:[{mi:"MALT_MUNICH",name:"Munich",cat:"Malt",qty:1.667,unit:"kg"},{mi:"MALT_PILSENER",name:"Pilsener",cat:"Malt",qty:6.333,unit:"kg"},{mi:"HOPS_MOSAIC",name:"Mosaic",cat:"Hops",qty:166.667,unit:"g"},{mi:"HOPS_M_INCOGNITO",name:"Mosaic Incognito",cat:"Hops",qty:66.667,unit:"g"},{mi:"PROC_NAGARDO",name:"Nagardo",cat:"Proc/Chem",qty:2.0,unit:"g"},{mi:"PROC_PHOSPHORIQUE",name:"Phosphorique",cat:"Proc/Chem",qty:41.667,unit:"ml"},{mi:"MIN_CACL2",name:"Calcium Chloride",cat:"Minéraux",qty:4.867,unit:"g"},{mi:"MIN_CASO4",name:"Calcium Sulphate",cat:"Minéraux",qty:2.433,unit:"g"},{mi:"MIN_MGSO4",name:"Magnesium Sulphate",cat:"Minéraux",qty:4.867,unit:"g"}],
  26:[{mi:"MALT_PILSENER",name:"Pilsener",cat:"Malt",qty:5.667,unit:"kg"},{mi:"MALT_WHEAT",name:"Wheat",cat:"Malt",qty:5.333,unit:"kg"},{mi:"HOPS_CASCADE",name:"Cascade",cat:"Hops",qty:40.0,unit:"g"},{mi:"ADJ_PEACH_TEA",name:"Peach Tea",cat:"Adjunct",qty:133.333,unit:"g"},{mi:"PROC_ISYENHANCE",name:"IsyEnhance",cat:"Proc/Chem",qty:40.0,unit:"g"},{mi:"PROC_NAGARDO",name:"Nagardo",cat:"Proc/Chem",qty:2.0,unit:"g"},{mi:"PROC_PHOSPHORIQUE",name:"Phosphorique",cat:"Proc/Chem",qty:43.333,unit:"ml"}],
  6:[{mi:"MALT_MUNICH",name:"Munich",cat:"Malt",qty:1.833,unit:"kg"},{mi:"MALT_OAT_FLAKES",name:"Oat Flakes",cat:"Malt",qty:0.667,unit:"kg"},{mi:"MALT_PILSENER",name:"Pilsener",cat:"Malt",qty:4.667,unit:"kg"},{mi:"MALT_WHEAT",name:"Wheat",cat:"Malt",qty:1.0,unit:"kg"},{mi:"HOPS_C_INCOGNITO",name:"Citra Incognito",cat:"Hops",qty:66.667,unit:"g"},{mi:"HOPS_MOSAIC",name:"Mosaic",cat:"Hops",qty:333.333,unit:"g"},{mi:"PROC_PHOSPHORIQUE",name:"Phosphorique",cat:"Proc/Chem",qty:23.333,unit:"ml"}],
};

const PROFILES = {
  57:{og:10.09,fg:2.08,atten:79.37,phCool:4.98,phFerm:4.41,fermDays:13.43,ccDays:28.25,gardeDays:null,co2:null,batches:37},
  32:{og:14.10,fg:2.20,atten:84.40,phCool:4.99,phFerm:4.59,fermDays:12.70,ccDays:25.58,gardeDays:null,co2:null,batches:21},
  44:{og:12.24,fg:2.25,atten:81.56,phCool:5.04,phFerm:4.19,fermDays:7.84,ccDays:35.50,gardeDays:null,co2:null,batches:20},
  51:{og:9.07,fg:1.74,atten:80.70,phCool:4.96,phFerm:4.04,fermDays:6.92,ccDays:10.23,gardeDays:null,co2:null,batches:14},
  52:{og:11.84,fg:2.75,atten:76.83,phCool:5.02,phFerm:4.39,fermDays:8.89,ccDays:17.11,gardeDays:null,co2:null,batches:11},
  30:{og:17.06,fg:3.62,atten:78.75,phCool:5.05,phFerm:4.45,fermDays:13.75,ccDays:13.13,gardeDays:null,co2:null,batches:9},
  25:{og:5.10,fg:4.54,atten:8.89,phCool:4.60,phFerm:4.31,fermDays:2.57,ccDays:42.90,gardeDays:null,co2:null,batches:14},
  26:{og:7.05,fg:5.20,atten:22.30,phCool:4.65,phFerm:4.37,fermDays:3.75,ccDays:20.0,gardeDays:null,co2:null,batches:4},
  6:{og:6.07,fg:1.26,atten:78.23,phCool:4.86,phFerm:4.06,fermDays:7.50,ccDays:19.67,gardeDays:null,co2:null,batches:7},
};

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
const BRASSIN_HL     = 30;
const RECIPE_STYLES  = {};

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
  const label=document.createElement('div');
  label.className='recipe-group-label';
  label.textContent='Core — Nébuleuse';
  scroll.appendChild(label);
  RECIPES.forEach(r=>{
    const p=PROFILES[r.id];
    const abv=p?calcAbv(p.og,p.fg):null;
    const div=document.createElement('div');
    div.className='recipe-item';
    div.dataset.id=r.id;
    const dotClass={Core:'core',EPH:'eph',CollabIn:'collab',Archive:'archive'}[r.subtype]||'archive';
    div.innerHTML=`<span class="ri-dot ${escHtml(dotClass)}"></span>
      <div class="ri-body">
        <div class="ri-name">${escHtml(r.name)}</div>
        <div class="ri-meta">${p?p.batches+' brassin(s) · OG '+p.og+'°P':'—'}</div>
      </div>
      <div>
        ${r.sku?`<div class="ri-sku">${escHtml(r.sku)}</div>`:''}
        ${abv?`<div style="font-family:'JetBrains Mono',monospace;font-size:9px;color:var(--lab);text-align:right;margin-top:3px;">${escHtml(abv)}%</div>`:''}
      </div>`;
    div.addEventListener('click',()=>selectRecipe(r.id));
    scroll.appendChild(div);
  });
}

function selectRecipe(id){
  selectedRecipeId=id;
  document.querySelectorAll('.recipe-item').forEach(el=>el.classList.toggle('sel',+el.dataset.id===id));
  const rec=RECIPES.find(r=>r.id===id);
  const p=PROFILES[id];
  const abv=p?calcAbv(p.og,p.fg):null;
  document.getElementById('rdh-title').innerHTML=`<em>${escHtml(rec.name)}</em>`;
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
const CAT_COLORS={Malt:'#a07a48',Hops:'#9eb060',Adjunct:'#9b7cc8','Proc/Chem':'#6593b8',Minéraux:'#4a8c78'};
const CAT_ORDER=['Malt','Hops','Adjunct','Proc/Chem','Minéraux'];

function scaledQty(qty,unit){
  const factor=ingrScale==='brassin'?BRASSIN_HL:1;
  const scaled=qty*factor;
  if(unit==='kg'){return scaled>=1?{val:scaled,unit:'kg'}:{val:scaled*1000,unit:'g'};}
  if(unit==='g'){return scaled>=1000?{val:scaled/1000,unit:'kg'}:{val:scaled,unit:'g'};}
  if(unit==='ml'){return scaled>=1000?{val:scaled/1000,unit:'L'}:{val:scaled,unit:'ml'};}
  return{val:scaled,unit};
}
function fmtVal(v,unit){
  if(unit==='kg') return v<1?v.toFixed(3):v.toFixed(2);
  if(unit==='g') return v<10?v.toFixed(1):Math.round(v);
  if(unit==='L') return v.toFixed(2);
  return v%1===0?v:v.toFixed(1);
}
function setIngrScale(scale){
  ingrScale=scale;
  document.getElementById('istBrassin').classList.toggle('active',scale==='brassin');
  document.getElementById('istHl').classList.toggle('active',scale==='hl');
  if(selectedRecipeId!==null) renderIngrPane(selectedRecipeId,PROFILES[selectedRecipeId]);
}
function renderIngrPane(id,profile){
  const container=document.getElementById('ingrPaneContent');
  if(!container) return;
  const items=INGREDIENTS[id]||[];
  if(!items.length){container.innerHTML='<div style="padding:40px;text-align:center;color:var(--ink-faint);">Aucun ingrédient.</div>';return;}
  const groups={};
  items.forEach(it=>{const c=it.cat;if(!groups[c])groups[c]=[];groups[c].push(it);});
  const toGRaw=(qty,unit)=>unit==='kg'?qty*1000:qty;
  const maxPerCat={};
  Object.entries(groups).forEach(([c,rows])=>{maxPerCat[c]=Math.max(...rows.map(r=>toGRaw(r.qty,r.unit)));});
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
    rows.forEach(it=>{
      const pct=toGRaw(it.qty,it.unit)/maxPerCat[cat]*100;
      const s=scaledQty(it.qty,it.unit);
      html+=`<div class="ingr-row"><div class="ingr-name">${escHtml(it.name)}</div><div class="ingr-mid">${escHtml(it.mi)}</div><div class="ingr-qty">${escHtml(String(fmtVal(s.val,s.unit)))}</div><div class="ingr-unit">${escHtml(s.unit)}</div><div class="ingr-bar-wrap"><div class="ingr-bar" style="width:${pct}%;background:${color};opacity:.7;"></div></div></div>`;
    });
  });
  container.innerHTML=html;
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
  if(pendingModal){
    pendingModal.inp.defaultValue=pendingModal.newVal;
    if(pendingModal.isStyle&&selectedRecipeId!==null){RECIPE_STYLES[selectedRecipeId]=pendingModal.newVal;}
    showToast('Enregistré (mock): '+pendingModal.field+' = '+pendingModal.newVal);
  }
  pendingModal=null;
  document.getElementById('modalOverlay').classList.remove('open');
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
  if(SDC_ROLE==='admin'){
    pendingModal={inp,oldVal,newVal,field:'style',id:selectedRecipeId,isStyle:true};
    document.getElementById('modalContext').textContent='style · recette #'+selectedRecipeId;
    document.getElementById('modalOld').textContent=oldVal||'(vide)';
    document.getElementById('modalNew').textContent=newVal||'(vide)';
    document.getElementById('modalOverlay').classList.add('open');
  } else {inp.value=oldVal;showToast('Style soumis pour approbation');}
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
  RECIPES.push(newRec);INGREDIENTS[newId]=[];PROFILES[newId]=null;RECIPE_YEAST[newId]=yeast;
  if(style) RECIPE_STYLES[newId]=style;
  const badge=document.querySelector('.nav-badge');
  if(badge) badge.textContent=RECIPES.length;
  closeNewRecipeModal();buildRecipeList();selectRecipe(newId);
  showToast('Recette créée (mock): '+name+(style?' · '+style:''));
}

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
if(SDC_INITIAL==='recettes') selectRecipe(RECIPES[0].id);
</script>
<script src="/js/salle-de-controle.js?v=<?= @filemtime(__DIR__ . '/../js/salle-de-controle.js') ?: time() ?>"></script>
</body>
</html>
