<?php
declare(strict_types=1);

/**
 * app/yeast-eligibility.php — Single home for lagering-threshold resolution logic.
 *
 * PRECEDENCE (operator-confirmed):
 *   recipe override → strain's family default → unresolved (NULL)
 *
 * COALESCE chain:
 *   COALESCE(ref_recipes.garde_days_min_override, ref_yeast_family_defaults.garde_days_min)
 *   via: ref_recipes → yeast_strain_id_fk → ref_yeast_strains.family → ref_yeast_family_defaults.family
 *
 * UNRESOLVED SEMANTICS (read this before consuming garde_days_min):
 *   When garde_days_min is NULL, the beer is NOT time-gate-eligible for the
 *   racking/transfer form. NULL means "unknown, not auto-eligible". The form
 *   gate must only surface such beers under the hors-process override.
 *   This module NEVER invents a numeric fallback — NULL propagates intentionally.
 *
 * Consumers:
 *   - salle-de-controle.php  (per-recipe yeast assignment editor)
 *   - form-racking.php       (transfer-form eligibility gate — FUTURE)
 *
 * For set-based queries (e.g. the racking form candidate list), use
 *   yeast_eligibility_join_fragment()  +  yeast_eligibility_select_expressions()
 * so the COALESCE/JOIN definition lives in exactly one place.
 */

require_once __DIR__ . '/db.php';

/* ── ENUM constants (mirrors ref_yeast_strains.family and ref_yeast_family_defaults.family) ── */
const YEAST_FAMILIES = ['ale', 'lager', 'non_alcool', 'spontane', 'mixte'];

/** French labels for the yeast family ENUM values. */
const YEAST_FAMILY_LABELS = [
    'ale'       => 'Ale',
    'lager'     => 'Lager',
    'non_alcool'=> 'Non-alcool',
    'spontane'  => 'Spontanée',
    'mixte'     => 'Mixte',
];

/* ═══════════════════════════════════════════════════════════════════════════
   PER-RECIPE RESOLVER
   ═══════════════════════════════════════════════════════════════════════════ */

/**
 * Resolve all yeast / garde / temperature fields for a single recipe.
 *
 * Returns an array with keys:
 *   yeast_strain_id  ?int    — ref_yeast_strains.id (null if no strain assigned)
 *   strain_name      ?string — ref_yeast_strains.name
 *   family           ?string — ref_yeast_strains.family (ENUM value; null if strain unclassified)
 *   family_label     ?string — French label for the family (from YEAST_FAMILY_LABELS)
 *
 *   garde_days_min   ?int    — COALESCE(recipe override, family default). NULL = unresolved.
 *   garde_source     'override'|'family'|null
 *                              'override' when ref_recipes.garde_days_min_override is non-NULL
 *                              'family'   when garde comes from ref_yeast_family_defaults
 *                              null       when garde_days_min is NULL (unresolved)
 *
 *   ferm_temp_min    ?float  — COALESCE(recipe override, family default). °C.
 *   ferm_temp_max    ?float  — COALESCE(recipe override, family default). °C.
 *   temp_source      'override'|'family'|null
 *                              'override' when BOTH ref_recipes.ferm_temp_min_override AND
 *                                         ref_recipes.ferm_temp_max_override are non-NULL
 *                              'family'   when temps come from ref_yeast_family_defaults
 *                              null       when temps are unresolved (no strain or no family defaults)
 *
 * Throws RuntimeException if the recipe does not exist.
 */
function resolve_recipe_yeast(PDO $pdo, int $recipeId): array
{
    $stmt = $pdo->prepare(
        "SELECT
             r.yeast_strain_id_fk,
             r.garde_days_min_override,
             r.ferm_temp_min_override,
             r.ferm_temp_max_override,
             s.name          AS strain_name,
             s.family        AS strain_family,
             fd.garde_days_min  AS family_garde,
             fd.ferm_temp_min   AS family_temp_min,
             fd.ferm_temp_max   AS family_temp_max
           FROM ref_recipes r
           LEFT JOIN ref_yeast_strains s
             ON s.id = r.yeast_strain_id_fk
           LEFT JOIN ref_yeast_family_defaults fd
             ON fd.family = s.family
          WHERE r.id = ?
          LIMIT 1"
    );
    $stmt->execute([$recipeId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row === false) {
        throw new RuntimeException("Recette introuvable : id={$recipeId}");
    }

    /* ── Garde resolution ────────────────────────────────────────────────── */
    $gardeOverride = $row['garde_days_min_override'] !== null
        ? (int) $row['garde_days_min_override']
        : null;
    $gardeFamily = $row['family_garde'] !== null
        ? (int) $row['family_garde']
        : null;

    if ($gardeOverride !== null) {
        $gardeDaysMin = $gardeOverride;
        $gardeSource  = 'override';
    } elseif ($gardeFamily !== null) {
        $gardeDaysMin = $gardeFamily;
        $gardeSource  = 'family';
    } else {
        $gardeDaysMin = null;
        $gardeSource  = null;
    }

    /* ── Temperature resolution ──────────────────────────────────────────── */
    $tempMinOverride = $row['ferm_temp_min_override'] !== null
        ? (float) $row['ferm_temp_min_override']
        : null;
    $tempMaxOverride = $row['ferm_temp_max_override'] !== null
        ? (float) $row['ferm_temp_max_override']
        : null;
    $tempMinFamily = $row['family_temp_min'] !== null
        ? (float) $row['family_temp_min']
        : null;
    $tempMaxFamily = $row['family_temp_max'] !== null
        ? (float) $row['family_temp_max']
        : null;

    // Both min AND max must be overridden together to count as 'override' source.
    if ($tempMinOverride !== null && $tempMaxOverride !== null) {
        $fermTempMin = $tempMinOverride;
        $fermTempMax = $tempMaxOverride;
        $tempSource  = 'override';
    } elseif ($tempMinFamily !== null || $tempMaxFamily !== null) {
        // At least one family value exists; COALESCE per-side
        $fermTempMin = $tempMinOverride ?? $tempMinFamily;
        $fermTempMax = $tempMaxOverride ?? $tempMaxFamily;
        $tempSource  = 'family';
    } else {
        $fermTempMin = null;
        $fermTempMax = null;
        $tempSource  = null;
    }

    /* ── Family label ────────────────────────────────────────────────────── */
    $family      = $row['strain_family'] !== null ? (string) $row['strain_family'] : null;
    $familyLabel = $family !== null ? (YEAST_FAMILY_LABELS[$family] ?? $family) : null;

    return [
        'yeast_strain_id' => $row['yeast_strain_id_fk'] !== null ? (int) $row['yeast_strain_id_fk'] : null,
        'strain_name'     => $row['strain_name'] !== null ? (string) $row['strain_name'] : null,
        'family'          => $family,
        'family_label'    => $familyLabel,
        'garde_days_min'  => $gardeDaysMin,
        'garde_source'    => $gardeSource,
        'ferm_temp_min'   => $fermTempMin,
        'ferm_temp_max'   => $fermTempMax,
        'temp_source'     => $tempSource,
    ];
}

/* ═══════════════════════════════════════════════════════════════════════════
   SET-BASED SQL FRAGMENTS
   (for queries over many recipes — the transfer-form candidate list, etc.)
   ═══════════════════════════════════════════════════════════════════════════ */

/**
 * Returns the LEFT JOIN clause string needed to bring yeast strain + family
 * defaults into a query rooted at ref_recipes (aliased as `r`).
 *
 * Usage:
 *   $sql = "SELECT r.id, r.name, " . implode(', ', yeast_eligibility_select_expressions())
 *        . " FROM ref_recipes r"
 *        . " " . yeast_eligibility_join_fragment()
 *        . " WHERE r.is_active = 1";
 *
 * Aliases introduced:
 *   ys   → ref_yeast_strains
 *   yfd  → ref_yeast_family_defaults
 */
function yeast_eligibility_join_fragment(): string
{
    return
        "LEFT JOIN ref_yeast_strains ys"
        . "  ON ys.id = r.yeast_strain_id_fk"
        . " LEFT JOIN ref_yeast_family_defaults yfd"
        . "  ON yfd.family = ys.family";
}

/**
 * Returns an array of SELECT expressions (no trailing comma) that resolve
 * garde_days_min and temperature fields using the COALESCE precedence:
 *   recipe override → family default → NULL
 *
 * The expressions reference table aliases `r` (ref_recipes), `ys`
 * (ref_yeast_strains), and `yfd` (ref_yeast_family_defaults) — use
 * yeast_eligibility_join_fragment() to introduce these aliases.
 *
 * Columns emitted:
 *   yeast_strain_id  — r.yeast_strain_id_fk
 *   strain_name      — ys.name
 *   strain_family    — ys.family
 *   effective_garde  — COALESCE(override, family default)  NULL = unresolved
 *   effective_temp_min
 *   effective_temp_max
 *   garde_source     — 'override' | 'family' | NULL
 *   temp_source      — 'override' | 'family' | NULL
 */
function yeast_eligibility_select_expressions(): array
{
    return [
        'r.yeast_strain_id_fk AS yeast_strain_id',
        'ys.name AS strain_name',
        'ys.family AS strain_family',
        // garde: COALESCE(recipe override, family default)
        'COALESCE(r.garde_days_min_override, yfd.garde_days_min) AS effective_garde',
        // temps: COALESCE per-side
        'COALESCE(r.ferm_temp_min_override, yfd.ferm_temp_min) AS effective_temp_min',
        'COALESCE(r.ferm_temp_max_override, yfd.ferm_temp_max) AS effective_temp_max',
        // source badges
        "CASE"
        . "  WHEN r.garde_days_min_override IS NOT NULL THEN 'override'"
        . "  WHEN yfd.garde_days_min IS NOT NULL THEN 'family'"
        . "  ELSE NULL"
        . " END AS garde_source",
        "CASE"
        . "  WHEN r.ferm_temp_min_override IS NOT NULL AND r.ferm_temp_max_override IS NOT NULL THEN 'override'"
        . "  WHEN yfd.ferm_temp_min IS NOT NULL OR yfd.ferm_temp_max IS NOT NULL THEN 'family'"
        . "  ELSE NULL"
        . " END AS temp_source",
    ];
}
