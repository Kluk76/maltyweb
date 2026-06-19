<?php
declare(strict_types=1);
/**
 * app/sdc-apply.php — Pure write-callables extracted from salle-de-controle.php.
 *
 * No flash_set(), no redirect_to(), no exit, no requires.
 * All helpers (log_revision, rri_close_version, must_be_one_of,
 * sdc_gated_format_ids, sdc_bom_template_for_format, sdc_recompile_recipe_packaging,
 * YEAST_FAMILIES) are loaded by salle-de-controle.php before this file is included.
 */

class SdcException extends RuntimeException
{
    public function __construct(string $message, private int $httpCode = 400)
    {
        parent::__construct($message);
    }

    public function getHttpCode(): int
    {
        return $this->httpCode;
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// activate_format
// ─────────────────────────────────────────────────────────────────────────────
function sdc_apply_activate_format(
    PDO $pdo,
    array $me,
    int $recipeId,
    int $formatId,
    ?int $bomOverride = null
): array {
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
    if (!$fmt) {
        throw new RuntimeException('Format introuvable.');
    }

    // Compute sku_code (cage format_code='X' → no hyphen: ZEPX not ZEP-X, mig363)
    $skuCode = ($fmt['format_code'] === 'X')
        ? $prefix . 'X'
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
                implode('|', [(string) $recipeId, (string) $formatId, $skuCode]));
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

    // Recompile packaging BOM — runs AFTER commit so a failure here never loses the activation.
    $bomResult  = null;
    $bomErrMsg  = null;
    try {
        $bomResult = sdc_recompile_recipe_packaging($pdo, $recipeId);
    } catch (Throwable $bomErr) {
        $bomErrMsg = $bomErr->getMessage();
    }

    return [
        'ok'        => true,
        'msg'       => $activateMsg,
        'bom'       => $bomResult,
        'bom_err'   => $bomErrMsg,
        'recipe_id' => $recipeId,
    ];
}

// ─────────────────────────────────────────────────────────────────────────────
// deactivate_format
// ─────────────────────────────────────────────────────────────────────────────
function sdc_apply_deactivate_format(
    PDO $pdo,
    array $me,
    int $recipeId,
    int $formatId
): array {
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

    $bomResult = null;
    $bomErrMsg = null;
    try {
        $bomResult = sdc_recompile_recipe_packaging($pdo, $recipeId);
    } catch (Throwable $bomErr) {
        $bomErrMsg = $bomErr->getMessage();
    }

    return [
        'ok'        => true,
        'msg'       => $deactivateMsg,
        'bom'       => $bomResult,
        'bom_err'   => $bomErrMsg,
        'recipe_id' => $recipeId,
    ];
}

// ─────────────────────────────────────────────────────────────────────────────
// set_binding
// ─────────────────────────────────────────────────────────────────────────────
function sdc_apply_set_binding(
    PDO $pdo,
    array $me,
    int $recipeId,
    string $role,
    int $miIdFk
): array {
    $validRoles = ['label', 'can', 'sticker', 'holder', 'outer_tray', 'scotch'];
    $role = must_be_one_of('role', $role, $validRoles);

    // Validate MI exists
    $miStmt = $pdo->prepare("SELECT id, mi_id FROM ref_mi WHERE id=? LIMIT 1");
    $miStmt->execute([$miIdFk]);
    $miRow = $miStmt->fetch(PDO::FETCH_ASSOC);
    if (!$miRow) {
        throw new RuntimeException('MI introuvable.');
    }

    // Validate MI matches the {beer} pattern for this role
    $recStmt = $pdo->prepare(
        "SELECT sku_prefix FROM ref_recipes WHERE id=? AND is_active=1 LIMIT 1"
    );
    $recStmt->execute([$recipeId]);
    $prefix = $recStmt->fetchColumn();
    if (!$prefix) {
        throw new RuntimeException('Recette introuvable ou sans préfixe SKU.');
    }

    $patternStmt = $pdo->prepare(
        "SELECT mi_filter_pattern FROM ref_packaging_items
          WHERE slot_name = ? AND mi_filter_pattern LIKE '%{beer}%' LIMIT 1"
    );
    $patternStmt->execute([$role]);
    $rawPattern = $patternStmt->fetchColumn();
    if ($rawPattern) {
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
            // Standard substitution
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
            ['recipe_id' => $recipeId, 'role' => $role, 'mi_id_fk' => $miIdFk,
             'effective_from' => $todayStr], 'normal',
            "Liaison packaging: rôle={$role}, recette={$recipeId}, MI={$miRow['mi_id']}");
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    $bindingMsg = "Liaison «{$role}» enregistrée.";

    $bomResult = null;
    $bomErrMsg = null;
    try {
        $bomResult = sdc_recompile_recipe_packaging($pdo, $recipeId);
    } catch (Throwable $bomErr) {
        $bomErrMsg = $bomErr->getMessage();
    }

    return [
        'ok'        => true,
        'msg'       => $bindingMsg,
        'bom'       => $bomResult,
        'bom_err'   => $bomErrMsg,
        'recipe_id' => $recipeId,
    ];
}

// ─────────────────────────────────────────────────────────────────────────────
// update_recipe_yeast
// ─────────────────────────────────────────────────────────────────────────────
function sdc_apply_yeast(
    PDO $pdo,
    array $me,
    int $recipeId,
    array $fields,
    bool $ownTxn = true
): array {
    $strainIdFk  = $fields['strain_id_fk'];
    $newFamily   = $fields['new_family'];
    $gardeOverride = $fields['garde_override'];
    $tempMinOvr  = $fields['temp_min_override'];
    $tempMaxOvr  = $fields['temp_max_override'];

    // Business-logic consistency check
    if ($newFamily !== null && $strainIdFk === null) {
        throw new RuntimeException(
            'Une souche doit être sélectionnée avant de définir sa famille.'
        );
    }

    // Validate recipe exists and is active
    $recStmt = $pdo->prepare(
        "SELECT id, name FROM ref_recipes WHERE id = ? AND is_active = 1 LIMIT 1"
    );
    $recStmt->execute([$recipeId]);
    $recRow = $recStmt->fetch(PDO::FETCH_ASSOC);
    if (!$recRow) {
        throw new RuntimeException("Recette introuvable ou inactive : id={$recipeId}");
    }

    // Validate strain exists if provided
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

    // Fetch before-states for audit
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

    // Write
    if ($ownTxn) $pdo->beginTransaction();
    try {
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
            'yeast_strain_id_fk'      => $strainIdFk,
            'garde_days_min_override' => $gardeOverride,
            'ferm_temp_min_override'  => $tempMinOvr,
            'ferm_temp_max_override'  => $tempMaxOvr,
            'last_modified_by'        => 'web',
        ];
        log_revision(
            $pdo, $me, 'ref_recipes', $recipeId,
            $beforeRec ?: [],
            $afterRec,
            'normal',
            "Salle de contrôle: yeast assignment · recette {$recRow['name']}"
        );

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

        if ($ownTxn) $pdo->commit();
    } catch (Throwable $e) {
        if ($ownTxn && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    $strainLabel = $strainRow ? (string) $strainRow['name'] : 'aucune';
    $flashMsg = "Levure & garde enregistrés — recette «{$recRow['name']}» "
        . "· souche : {$strainLabel}"
        . ($gardeOverride !== null ? " · garde override : {$gardeOverride}j" : '')
        . ($newFamily !== null ? " · famille souche : {$newFamily}" : '')
        . '.';

    return [
        'ok'        => true,
        'msg'       => $flashMsg,
        'recipe_id' => $recipeId,
        'sub'       => 'yeast',
    ];
}

// ─────────────────────────────────────────────────────────────────────────────
// update_yeast_strain
// ─────────────────────────────────────────────────────────────────────────────
function sdc_apply_yeast_strain(
    PDO $pdo,
    array $me,
    int $strainId,
    array $fields
): array {
    // Confirm strain exists and is active
    $strFetchStmt = $pdo->prepare(
        "SELECT id, name FROM ref_yeast_strains WHERE id = ? AND is_active = 1 LIMIT 1"
    );
    $strFetchStmt->execute([$strainId]);
    $strainRow = $strFetchStmt->fetch(PDO::FETCH_ASSOC);
    if (!$strainRow) {
        throw new RuntimeException("Souche levurienne introuvable ou inactive : id={$strainId}");
    }

    // Fetch before-state for audit
    $beforeStmt = $pdo->prepare(
        "SELECT family, flocculation, attenuation_min, attenuation_max, temp_min, temp_max
           FROM ref_yeast_strains
          WHERE id = ?
          LIMIT 1"
    );
    $beforeStmt->execute([$strainId]);
    $beforeState = $beforeStmt->fetch(PDO::FETCH_ASSOC);

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
        $fields['family'],
        $fields['flocculation'],
        $fields['attenuation_min'],
        $fields['attenuation_max'],
        $fields['temp_min'],
        $fields['temp_max'],
        $strainId,
    ]);

    $afterState = [
        'family'          => $fields['family'],
        'flocculation'    => $fields['flocculation'],
        'attenuation_min' => $fields['attenuation_min'],
        'attenuation_max' => $fields['attenuation_max'],
        'temp_min'        => $fields['temp_min'],
        'temp_max'        => $fields['temp_max'],
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

    return [
        'ok'       => true,
        'msg'      => "Souche «{$strainRow['name']}» mise à jour.",
        'strain_id' => $strainId,
    ];
}

// ─────────────────────────────────────────────────────────────────────────────
// update_recipe_qc
// ─────────────────────────────────────────────────────────────────────────────
function sdc_apply_qc_target(
    PDO $pdo,
    array $me,
    int $recipeId,
    array $fields
): array {
    // Validate recipe exists
    $recStmt = $pdo->prepare(
        "SELECT id, name FROM ref_recipes WHERE id = ? AND is_active = 1 LIMIT 1"
    );
    $recStmt->execute([$recipeId]);
    $recRow = $recStmt->fetch(PDO::FETCH_ASSOC);
    if (!$recRow) {
        throw new RuntimeException("Recette introuvable ou inactive : id={$recipeId}");
    }

    // Fetch before-state for audit
    $beforeStmt = $pdo->prepare(
        "SELECT co2_target, co2_tolerance,
                racked_vol_warn_lo, racked_vol_warn_hi,
                racked_vol_outlier_lo, racked_vol_outlier_hi,
                og_target, fg_target, ph_target, abv_target
           FROM ref_recipes WHERE id = ? LIMIT 1"
    );
    $beforeStmt->execute([$recipeId]);
    $before = $beforeStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $after = [
        'co2_target'            => $fields['co2_target'],
        'co2_tolerance'         => $fields['co2_tolerance'],
        'racked_vol_warn_lo'    => $fields['racked_vol_warn_lo'],
        'racked_vol_warn_hi'    => $fields['racked_vol_warn_hi'],
        'racked_vol_outlier_lo' => $fields['racked_vol_outlier_lo'],
        'racked_vol_outlier_hi' => $fields['racked_vol_outlier_hi'],
        'og_target'             => $fields['og_target'],
        'fg_target'             => $fields['fg_target'],
        'ph_target'             => $fields['ph_target'],
        'abv_target'            => $fields['abv_target'],
        'last_modified_by'      => 'web',
    ];

    $upStmt = $pdo->prepare(
        "UPDATE ref_recipes
            SET co2_target            = ?,
                co2_tolerance         = ?,
                racked_vol_warn_lo    = ?,
                racked_vol_warn_hi    = ?,
                racked_vol_outlier_lo = ?,
                racked_vol_outlier_hi = ?,
                og_target             = ?,
                fg_target             = ?,
                ph_target             = ?,
                abv_target            = ?,
                last_modified_by      = 'web'
          WHERE id = ?"
    );
    $upStmt->execute([
        $fields['co2_target'],        $fields['co2_tolerance'],
        $fields['racked_vol_warn_lo'], $fields['racked_vol_warn_hi'],
        $fields['racked_vol_outlier_lo'], $fields['racked_vol_outlier_hi'],
        $fields['og_target'],          $fields['fg_target'],
        $fields['ph_target'],          $fields['abv_target'],
        $recipeId,
    ]);

    log_revision(
        $pdo, $me, 'ref_recipes', $recipeId,
        $before,
        $after,
        'normal',
        "Salle de contrôle: QC seuils · recette {$recRow['name']}"
    );

    $co2Target  = $fields['co2_target'];
    $co2Tolerance = $fields['co2_tolerance'];
    $volWarnLo  = $fields['racked_vol_warn_lo'];
    $volWarnHi  = $fields['racked_vol_warn_hi'];
    $ogTarget   = $fields['og_target'];
    $fgTarget   = $fields['fg_target'];

    $parts = [];
    if ($co2Target !== null) {
        $parts[] = "CO₂ cible {$co2Target} ±{$co2Tolerance} g/L";
    } else {
        $parts[] = 'CO₂ → global';
    }
    if ($volWarnLo !== null) {
        $parts[] = "vol [{$volWarnLo}–{$volWarnHi} HL]";
    } else {
        $parts[] = 'vol → auto';
    }
    if ($ogTarget !== null) {
        $parts[] = "OG {$ogTarget} °P";
    }
    if ($fgTarget !== null) {
        $parts[] = "FG {$fgTarget} °P";
    }

    $flashMsg = "Seuils QC enregistrés — «{$recRow['name']}» · " . implode(', ', $parts) . '.';

    return [
        'ok'        => true,
        'msg'       => $flashMsg,
        'recipe_id' => $recipeId,
        'sub'       => 'yeast',
    ];
}

// ─────────────────────────────────────────────────────────────────────────────
// update_recipe_style
// ─────────────────────────────────────────────────────────────────────────────
function sdc_apply_recipe_style(
    PDO $pdo,
    array $me,
    int $recipeId,
    ?string $styleOrNull,
    bool $ownTxn = true
): array {
    // Validate recipe exists and is active
    $recStmt = $pdo->prepare(
        "SELECT id, name FROM ref_recipes WHERE id = ? AND is_active = 1 LIMIT 1"
    );
    $recStmt->execute([$recipeId]);
    $recRow = $recStmt->fetch(PDO::FETCH_ASSOC);
    if (!$recRow) {
        throw new RuntimeException("Recette introuvable ou inactive : id={$recipeId}");
    }

    // Fetch before-state for audit
    $beforeStmt = $pdo->prepare("SELECT style FROM ref_recipes WHERE id = ? LIMIT 1");
    $beforeStmt->execute([$recipeId]);
    $before = $beforeStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    // Write
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
    $flashMsg   = "Style enregistré — «{$recRow['name']}» · {$styleLabel}.";

    return [
        'ok'        => true,
        'msg'       => $flashMsg,
        'recipe_id' => $recipeId,
        'sub'       => 'ingr',
    ];
}

// ─────────────────────────────────────────────────────────────────────────────
// update_recipe_name
// ─────────────────────────────────────────────────────────────────────────────
function sdc_apply_recipe_name(
    PDO $pdo,
    array $me,
    int $recipeId,
    string $newName,
    bool $ownTxn = true
): array {
    // Validate recipe exists and is active
    $recStmt = $pdo->prepare(
        "SELECT id, name, classification FROM ref_recipes WHERE id = ? AND is_active = 1 LIMIT 1"
    );
    $recStmt->execute([$recipeId]);
    $recRow = $recStmt->fetch(PDO::FETCH_ASSOC);
    if (!$recRow) {
        throw new RuntimeException("Recette introuvable ou inactive : id={$recipeId}");
    }

    // Rename is restricted to CONTRACT recipes.
    if (($recRow['classification'] ?? '') !== 'Contract') {
        throw new RuntimeException(
            'Renommage réservé aux recettes sous contrat — les noms des recettes Nébuleuse sont des clés de jointure (attribution cuves / COGS).'
        );
    }

    // Fetch before-state for audit
    $beforeStmt = $pdo->prepare("SELECT name FROM ref_recipes WHERE id = ? LIMIT 1");
    $beforeStmt->execute([$recipeId]);
    $before = $beforeStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    // Write
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

    $flashMsg = "Recette renommée : «{$before['name']}» → «{$newName}».";

    return [
        'ok'        => true,
        'msg'       => $flashMsg,
        'recipe_id' => $recipeId,
        'sub'       => 'ingr',
    ];
}

// ─────────────────────────────────────────────────────────────────────────────
// set_hop_stage  — SCD2 close-then-insert
// ─────────────────────────────────────────────────────────────────────────────
function sdc_apply_hop_set_stage(
    PDO $pdo,
    array $me,
    int $rriId,
    array $rriRow,
    ?string $stageOrNull,
    ?int $boilMin
): array {
    // Before-state for audit (close event)
    $before = [
        'hop_addition_stage' => $rriRow['hop_addition_stage'],
        'hop_boil_time_min'  => $rriRow['hop_boil_time_min'],
        'effective_until'    => null,
    ];

    $pdo->beginTransaction();
    try {
        // Close old version
        rri_close_version($pdo, $rriId);
        log_revision(
            $pdo, $me, 'ref_recipe_ingredients', $rriId,
            $before,
            ['hop_addition_stage' => $rriRow['hop_addition_stage'],
             'hop_boil_time_min'  => $rriRow['hop_boil_time_min'],
             'effective_until'    => 'CURDATE()'],
            'normal',
            "Salle de contrôle: hop stage (close v) · {$rriRow['recipe_name']} · {$rriRow['mi_name']}"
        );

        // Pre-emptively remove any legacy tombstone that occupies the target slot
        $deadBlockerStmt = $pdo->prepare(
            "SELECT id FROM ref_recipe_ingredients
              WHERE recipe_id = ? AND mi_id_fk = ?
                AND (hop_addition_stage <=> ?)
                AND (hop_boil_time_min  <=> ?)
                AND is_active = 0
              LIMIT 1"
        );
        $deadBlockerStmt->execute([
            $rriRow['recipe_id'], $rriRow['mi_id_fk'], $stageOrNull, $boilMin,
        ]);
        $deadBlocker = $deadBlockerStmt->fetch(PDO::FETCH_ASSOC);
        if ($deadBlocker) {
            $pdo->prepare("DELETE FROM ref_recipe_ingredients WHERE id = ? AND is_active = 0")
                ->execute([(int) $deadBlocker['id']]);
        }

        // Insert new version carrying forward qty/unit
        $insStmt = $pdo->prepare(
            "INSERT INTO ref_recipe_ingredients
                 (recipe_id, mi_id_fk, qty_per_hl, unit,
                  hop_addition_stage, hop_boil_time_min,
                  is_active, effective_from, effective_until)
             VALUES (?, ?, ?, ?, ?, ?, 1, CURDATE(), NULL)"
        );
        $insStmt->execute([
            $rriRow['recipe_id'], $rriRow['mi_id_fk'],
            (float) $rriRow['qty_per_hl'], $rriRow['unit'],
            $stageOrNull, $boilMin,
        ]);
        $newId = (int) $pdo->lastInsertId();

        $after = [
            'recipe_id'          => (int) $rriRow['recipe_id'],
            'mi_id_fk'           => (int) $rriRow['mi_id_fk'],
            'qty_per_hl'         => (float) $rriRow['qty_per_hl'],
            'unit'               => $rriRow['unit'],
            'hop_addition_stage' => $stageOrNull,
            'hop_boil_time_min'  => $boilMin,
            'effective_from'     => 'CURDATE()',
            'effective_until'    => null,
        ];
        log_revision(
            $pdo, $me, 'ref_recipe_ingredients', $newId,
            null,
            $after,
            'normal',
            "Salle de contrôle: hop stage (new v) · {$rriRow['recipe_name']} · {$rriRow['mi_name']}"
        );

        $pdo->commit();
    } catch (Throwable $txErr) {
        $pdo->rollBack();
        throw $txErr;
    }

    return [
        'ok'       => true,
        'id'       => $newId,
        'old_id'   => $rriId,
        'stage'    => $stageOrNull,
        'boil_min' => $boilMin,
    ];
}

// ─────────────────────────────────────────────────────────────────────────────
// add_hop_addition — INSERT with tombstone resurrection
// ─────────────────────────────────────────────────────────────────────────────
function sdc_apply_hop_upsert(
    PDO $pdo,
    array $me,
    int $recipeId,
    int $miIdFk,
    ?string $stageOrNull,
    ?int $boilMin,
    float $qtyPerHl,
    string $unit,
    array $miRow,
    array $recRow
): array {
    try {
        $insStmt = $pdo->prepare(
            "INSERT INTO ref_recipe_ingredients
                (recipe_id, mi_id_fk, qty_per_hl, unit,
                 hop_addition_stage, hop_boil_time_min,
                 is_active, effective_from, effective_until)
             VALUES (?, ?, ?, ?, ?, ?, 1, CURDATE(), NULL)"
        );
        $insStmt->execute([$recipeId, $miIdFk, $qtyPerHl, $unit, $stageOrNull, $boilMin]);
        $newId = (int) $pdo->lastInsertId();
    } catch (PDOException $e) {
        if (!str_contains($e->getMessage(), '1062') && !str_contains($e->getMessage(), 'Duplicate')) {
            throw $e;
        }
        // UNIQUE collision — check whether the occupant is a soft-deleted tombstone
        $tombStmt = $pdo->prepare(
            "SELECT id, qty_per_hl, unit, hop_addition_stage, hop_boil_time_min
               FROM ref_recipe_ingredients
              WHERE recipe_id = ? AND mi_id_fk = ?
                AND (hop_addition_stage <=> ?)
                AND (hop_boil_time_min  <=> ?)
                AND is_active = 0
              LIMIT 1"
        );
        $tombStmt->execute([$recipeId, $miIdFk, $stageOrNull, $boilMin]);
        $tombRow = $tombStmt->fetch(PDO::FETCH_ASSOC);

        if (!$tombRow) {
            // Collision with an ACTIVE row — genuinely duplicate submission
            throw new SdcException(
                'Cette combinaison houblon + stage + minutes existe déjà pour cette recette.',
                409
            );
        }

        // Resurrect: update tombstoned row with new qty/unit and reactivate
        $newId     = (int) $tombRow['id'];
        $beforeRes = [
            'qty_per_hl'         => $tombRow['qty_per_hl'],
            'unit'               => $tombRow['unit'],
            'hop_addition_stage' => $tombRow['hop_addition_stage'],
            'hop_boil_time_min'  => $tombRow['hop_boil_time_min'],
            'is_active'          => 0,
        ];
        $pdo->prepare(
            "UPDATE ref_recipe_ingredients
                SET qty_per_hl = ?, unit = ?, hop_addition_stage = ?, hop_boil_time_min = ?,
                    is_active = 1, effective_from = CURDATE(), effective_until = NULL
              WHERE id = ?"
        )->execute([$qtyPerHl, $unit, $stageOrNull, $boilMin, $newId]);

        $after = [
            'recipe_id'          => $recipeId,  'mi_id_fk' => $miIdFk,
            'qty_per_hl'         => $qtyPerHl,  'unit' => $unit,
            'hop_addition_stage' => $stageOrNull, 'hop_boil_time_min' => $boilMin,
            'is_active'          => 1,
        ];
        log_revision(
            $pdo, $me, 'ref_recipe_ingredients', $newId,
            $beforeRes,
            $after,
            'normal',
            "Salle de contrôle: add hop addition (ressuscité) · {$recRow['name']} · {$miRow['name']}"
        );

        return [
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
        ];
    }

    $after = [
        'recipe_id'          => $recipeId,  'mi_id_fk' => $miIdFk,
        'qty_per_hl'         => $qtyPerHl,  'unit' => $unit,
        'hop_addition_stage' => $stageOrNull, 'hop_boil_time_min' => $boilMin,
        'is_active'          => 1,
        'effective_from'     => 'CURDATE()',
        'effective_until'    => null,
    ];
    log_revision(
        $pdo, $me, 'ref_recipe_ingredients', $newId,
        null,
        $after,
        'normal',
        "Salle de contrôle: add hop addition · {$recRow['name']} · {$miRow['name']}"
    );

    return [
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
    ];
}

// ─────────────────────────────────────────────────────────────────────────────
// delete_hop_addition — SCD2 close
// ─────────────────────────────────────────────────────────────────────────────
function sdc_apply_hop_remove(
    PDO $pdo,
    array $me,
    int $rriId,
    array $rriRow
): array {
    $before = [
        'effective_until'    => null,
        'hop_addition_stage' => $rriRow['hop_addition_stage'],
        'hop_boil_time_min'  => $rriRow['hop_boil_time_min'],
    ];
    $after = [
        'effective_until'    => 'CURDATE()',
        'hop_addition_stage' => $rriRow['hop_addition_stage'],
        'hop_boil_time_min'  => $rriRow['hop_boil_time_min'],
    ];

    $pdo->beginTransaction();
    try {
        rri_close_version($pdo, $rriId);
        log_revision(
            $pdo, $me, 'ref_recipe_ingredients', $rriId,
            $before,
            $after,
            'normal',
            "Salle de contrôle: delete hop addition (close v) · {$rriRow['recipe_name']} · {$rriRow['mi_name']}"
        );
        $pdo->commit();
    } catch (Throwable $txErr) {
        $pdo->rollBack();
        throw $txErr;
    }

    return ['ok' => true, 'id' => $rriId];
}
