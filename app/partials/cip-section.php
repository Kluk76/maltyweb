<?php
declare(strict_types=1);
/**
 * cip-section.php — Shared CIP capture section partial.
 *
 * Included by form-racking, form-brewing, form-packaging (Wave 3).
 * MUST be the first section included inside the form's <form> element.
 *
 * ══════════════════════════════════════════════════════════════════════════
 * $cipConfig interface (passed by the including form):
 * ══════════════════════════════════════════════════════════════════════════
 *
 * $cipConfig = [
 *
 *   // Which machines to show. Racking + packaging: ['centri','kze','pump'].
 *   // Brewing: [] (no machines).
 *   'machines' => string[],           // subset of ['centri','kze','pump']
 *
 *   // Whether to show the "centri + KZE inline" combine checkbox.
 *   // Only meaningful when both centri AND kze are in $machines.
 *   'show_inline_combine' => bool,    // default false
 *
 *   // Vessel rows to render. Each element:
 *   //   [
 *   //     'code'         => string  (cct|yt|bbt|tank),
 *   //     'number'       => int|null,
 *   //     'label'        => string  (human label, e.g. "CIP BBT 3"),
 *   //     'dynamic_label'=> bool,   // when true, label is driven client-side by
 *   //                               // a destination-type select (racking only)
 *   //     'required'     => bool,   // mark as required when cip_done is checked
 *   //   ]
 *   'vessels' => array[],
 *
 *   // ref_cip_types rows: [['id' => int, 'name' => string], ...]
 *   // From cip_type_options($pdo).
 *   'cip_types' => array[],
 *
 *   // Existing events for re-display on edit (from cip_events_for()).
 *   // NULL = new submission; array = re-display mode.
 *   // Format: output of cip_events_for() — keys: machines, inline_groups, vessels, inline_combine.
 *   'existing' => array|null,
 *
 * ]
 *
 * Field-name contract: see app/cip-events.php header for the complete spec.
 * This partial emits exactly those field names. Do NOT rename them here.
 * ══════════════════════════════════════════════════════════════════════════
 *
 * CSS: /css/cip-section.css (scoped under .cip-section; included via <link> in page head).
 * JS: inline minimal JS (toggle logic only — no external dependency).
 *     Dynamic vessel label swap is driven by the including form's racking_destination_type select.
 * Dependencies: app/cip-events.php must be required by the including form.
 */

if (!isset($cipConfig) || !is_array($cipConfig)) {
    throw new RuntimeException('cip-section.php: $cipConfig must be set before including this partial.');
}

// ── Destructure config with safe defaults ──────────────────────────────────

/** @var string[] */
$cipMachines = $cipConfig['machines'] ?? [];

/** @var bool */
$cipShowInlineCombine = (bool)($cipConfig['show_inline_combine'] ?? false);

/** @var array[] */
$cipVessels = $cipConfig['vessels'] ?? [];

/** @var array[] */
$cipTypes = $cipConfig['cip_types'] ?? [];

/** @var array|null */
$cipExisting = $cipConfig['existing'] ?? null;

/**
 * The two machine codes that form the inline-combine pair.
 * Defaults to ['centri','kze'] so racking/brewing render identically when
 * combine_pair is absent from their $cipConfig.
 * Packaging passes ['filler','kze'].
 *
 * @var string[]
 */
$cipCombinePair = $cipConfig['combine_pair'] ?? ['centri', 'kze'];

/**
 * When set, the named machine is the "anchor": it renders as a standalone machine
 * row and the other pair member appears only as an inline-combine addon attached to
 * the anchor block.  The partner cannot be submitted without the anchor.
 *
 * When null (default), both pair members render as independent symmetric peer rows
 * + the simultané toggle — identical to the pre-anchor behaviour.
 *
 * @var string|null
 */
$cipAnchor = $cipConfig['combine_anchor'] ?? null;

// Convenience: extract existing machine/vessel data for re-display
$exMachines  = $cipExisting['machines']       ?? [];
$exVessels   = $cipExisting['vessels']        ?? [];
$exInline    = (bool)($cipExisting['inline_combine'] ?? false);

/**
 * Emit a <select> for cip_type with the given field name, pre-selected when
 * $selectedId matches a type id.
 *
 * Note: never pass required=true here — required is managed exclusively by JS
 * (cipSyncRequired) based on each CIP-done checkbox state. Static required on
 * inputs inside a hidden field-group blocks form submission with no visible error.
 *
 * @param string      $name        POST field name
 * @param int|null    $selectedId  Pre-selected ref_cip_types.id
 * @param bool        $required    Ignored — JS owns required state (kept for call-site compat)
 * @param string|null $extraClass  Additional CSS class(es) for the <select>
 */
$cipTypeSelect = function (string $name, ?int $selectedId = null, bool $required = false, ?string $extraClass = null) use ($cipTypes): void {
    // $required param intentionally ignored — JS owns required state; see Bug 1/2 fix.
    $cls = 'op-form__select cip-section__select' . ($extraClass ? ' ' . $extraClass : '');
    echo '<select name="' . htmlspecialchars($name) . '" class="' . $cls . '">';
    echo '<option value="">— type CIP —</option>';
    foreach ($cipTypes as $t) {
        $sel = ((int)$t['id'] === $selectedId) ? ' selected' : '';
        echo '<option value="' . (int)$t['id'] . '"' . $sel . '>'
            . htmlspecialchars($t['name'])
            . '</option>';
    }
    echo '</select>';
};

// For re-display of combined block: pick values from the first event in inline_group 1
// (centri and kze share identical date/type/times when inline_combine is active).
$exInlineGroup = $cipExisting['inline_groups'][1] ?? [];
$exCombinedEvent = $exInlineGroup[0] ?? null;
$exCombinedTypeId = $exCombinedEvent ? (int)$exCombinedEvent['cip_type_id_fk'] : null;
$exCombinedDate   = $exCombinedEvent ? htmlspecialchars($exCombinedEvent['cip_date'] ?? '') : '';
$exCombinedStart  = $exCombinedEvent ? htmlspecialchars(substr($exCombinedEvent['cip_started_at'] ?? '', 0, 5)) : '';
$exCombinedEnd    = $exCombinedEvent ? htmlspecialchars(substr($exCombinedEvent['cip_ended_at']   ?? '', 0, 5)) : '';

// Total vessel count for the hidden field (cip_parse_post reads cip_vessel_count)
$vesselCount = count($cipVessels);
?>

<!-- ══════════════════════════════════════════════════════════════════════ -->
<!-- CIP Section — shared across racking / brewing / packaging forms       -->
<!-- ══════════════════════════════════════════════════════════════════════ -->
<div class="cip-section op-form__card" id="cip-section">
  <div class="op-form__card-title">— nettoyage en place (CIP)</div>

  <!-- Hidden: vessel count for cip_parse_post -->
  <input type="hidden" name="cip_vessel_count" value="<?= $vesselCount ?>">

  <?php if (!empty($cipMachines)): ?>
  <!-- ── Machine CIP block ──────────────────────────────────────────── -->
  <div class="cip-section__block cip-section__machines" id="cip-machines-block">

    <?php
    // Build a human label for each code in the pair (used in both branches).
    $cipPairLabels = array_map(function (string $code): string {
        return match($code) {
            'centri' => 'Centri',
            'kze'    => 'KZE',
            'pump'   => 'Pompe',
            'filler' => 'Soutireuse',
            default  => ucfirst($code),
        };
    }, $cipCombinePair);
    $cipPairLabel = implode(' + ', $cipPairLabels);
    ?>

    <?php if ($cipAnchor !== null):
      // ══════════════════════════════════════════════════════════════════
      // ANCHOR BRANCH — config-driven; activated when combine_anchor is set.
      //
      // The anchor machine is always present in the form.  The partner
      // (the OTHER member of combine_pair) appears only as an addon toggle
      // on the anchor block — there is no independent partner row, so KZE
      // cannot be submitted without the anchor (Soutireuse).
      //
      // POST semantics:
      //   • Anchor alone:          cip_inline_combine=0 + cip_machine_{anchor}_* fields.
      //   • Anchor + partner:      cip_inline_combine=1 + cip_combined_* fields
      //                             (shared type/date/time for both; same as symmetric simultané).
      //   • Partner without anchor: impossible — no DOM path emits cip_inline_combine=1
      //                             unless the anchor CIP-done checkbox is checked first.
      //
      // Re-open: $exInline=true → anchor+partner saved → render addon ticked + combined values.
      //          $exInline=false, $exMachines[$anchor] set → anchor alone → individual values.
      // ══════════════════════════════════════════════════════════════════

      $cipPartner = null;
      foreach ($cipCombinePair as $pairCode) {
          if ($pairCode !== $cipAnchor) {
              $cipPartner = $pairCode;
              break;
          }
      }
      $anchorLabel  = match($cipAnchor) {
          'centri' => 'Centrifugeuse',
          'kze'    => 'KZE',
          'pump'   => 'Pompe',
          'filler' => 'Soutireuse',
          default  => ucfirst($cipAnchor),
      };
      $partnerLabel = $cipPartner !== null ? match($cipPartner) {
          'centri' => 'Centrifugeuse',
          'kze'    => 'KZE',
          'pump'   => 'Pompe',
          'filler' => 'Soutireuse',
          default  => ucfirst($cipPartner),
      } : '';

      // Re-open state: anchor alone vs anchor+partner.
      $exAnchorEv  = $exMachines[$cipAnchor] ?? null;
      // Anchor is "done" when: (a) anchor event present individually, or (b) inline pair saved.
      $anchorIsDone  = ($exAnchorEv !== null) || $exInline;
      // Anchor individual fields: used when $exInline=false.
      $exAnchorTypeId = ($exAnchorEv && !$exInline) ? (int)$exAnchorEv['cip_type_id_fk'] : null;
      $exAnchorDate   = ($exAnchorEv && !$exInline) ? htmlspecialchars($exAnchorEv['cip_date'] ?? '') : '';
      $exAnchorStart  = ($exAnchorEv && !$exInline) ? htmlspecialchars(substr($exAnchorEv['cip_started_at'] ?? '', 0, 5)) : '';
      $exAnchorEnd    = ($exAnchorEv && !$exInline) ? htmlspecialchars(substr($exAnchorEv['cip_ended_at']   ?? '', 0, 5)) : '';
      // Addon ticked state: true when a combined (inline) pair was previously saved.
      $addonTicked = $exInline;
    ?>

    <!-- ── Anchor machine row ─────────────────────────────────────── -->
    <div class="cip-section__machine-row" id="cip-machine-<?= htmlspecialchars($cipAnchor) ?>-row">
      <div class="cip-section__machine-header">
        <label class="cip-section__checkbox-row">
          <input type="checkbox"
                 name="cip_machine_<?= htmlspecialchars($cipAnchor) ?>"
                 value="1"
                 id="cip_machine_<?= htmlspecialchars($cipAnchor) ?>"
                 class="cip-section__checkbox cip-anchor-toggle"
                 <?= $anchorIsDone ? 'checked' : '' ?>>
          <span class="cip-section__machine-label"><?= htmlspecialchars($anchorLabel) ?> — CIP effectué</span>
        </label>
      </div>

      <!-- Fields container: shown when anchor checkbox is ticked -->
      <div class="cip-section__machine-fields cip-section__anchor-fields"
           id="cip-anchor-fields"
           <?= $anchorIsDone ? '' : 'hidden' ?>>

        <!-- ── INDIVIDUAL fields (anchor alone; hidden when addon is ticked) ── -->
        <div id="cip-anchor-individual-fields"
             <?= $addonTicked ? 'hidden' : '' ?>>
          <div class="op-form__grid--3 op-form__grid">

            <div class="op-form__field">
              <label class="op-form__label" for="cip_machine_<?= htmlspecialchars($cipAnchor) ?>_type_id">Type CIP</label>
              <?php ($cipTypeSelect)(
                  "cip_machine_{$cipAnchor}_type_id",
                  $exAnchorTypeId,
                  true
              ) ?>
            </div>

            <div class="op-form__field">
              <label class="op-form__label" for="cip_machine_<?= htmlspecialchars($cipAnchor) ?>_date">Date CIP</label>
              <input id="cip_machine_<?= htmlspecialchars($cipAnchor) ?>_date"
                     name="cip_machine_<?= htmlspecialchars($cipAnchor) ?>_date"
                     type="date"
                     class="op-form__input"
                     value="<?= $exAnchorDate ?>"
                     data-cip-required="1">
            </div>

            <div class="op-form__field">
              <label class="op-form__label" for="cip_machine_<?= htmlspecialchars($cipAnchor) ?>_start">
                Heure début <span class="op-form__unit">HH:MM</span>
              </label>
              <input id="cip_machine_<?= htmlspecialchars($cipAnchor) ?>_start"
                     name="cip_machine_<?= htmlspecialchars($cipAnchor) ?>_start"
                     type="time"
                     class="op-form__input"
                     value="<?= $exAnchorStart ?>"
                     data-cip-required="1">
            </div>

            <div class="op-form__field">
              <label class="op-form__label" for="cip_machine_<?= htmlspecialchars($cipAnchor) ?>_end">
                Heure fin <span class="op-form__unit">HH:MM</span>
              </label>
              <input id="cip_machine_<?= htmlspecialchars($cipAnchor) ?>_end"
                     name="cip_machine_<?= htmlspecialchars($cipAnchor) ?>_end"
                     type="time"
                     class="op-form__input"
                     value="<?= $exAnchorEnd ?>"
                     data-cip-required="1">
            </div>

          </div>
        </div><!-- #cip-anchor-individual-fields -->

        <?php if ($cipPartner !== null): ?>
        <!-- ── Partner addon toggle ────────────────────────────────── -->
        <div class="cip-section__inline-toggle cip-section__addon-toggle">
          <label class="cip-section__checkbox-row">
            <input type="checkbox"
                   name="cip_inline_combine"
                   value="1"
                   id="cip_inline_combine"
                   class="cip-section__checkbox cip-addon-toggle"
                   <?= $addonTicked ? 'checked' : '' ?>>
            <span class="cip-section__checkbox-label">+ <?= htmlspecialchars($partnerLabel) ?> combiné</span>
            <span class="cip-section__badge">inline</span>
          </label>
        </div>

        <!-- ── Combined block (anchor + partner shared fields) ─────── -->
        <div class="cip-section__combined-block" id="cip-combined-block"
             <?= $addonTicked ? '' : 'hidden' ?>>
          <div class="cip-section__combined-header">
            <span class="cip-section__machine-label"><?= htmlspecialchars($cipPairLabel) ?> — CIP simultané</span>
          </div>
          <div class="cip-section__combined-fields" id="cip-combined-fields">
            <div class="op-form__grid--3 op-form__grid">

              <div class="op-form__field">
                <label class="op-form__label" for="cip_combined_type_id">Type CIP</label>
                <?php ($cipTypeSelect)(
                    'cip_combined_type_id',
                    $exCombinedTypeId,
                    true
                ) ?>
              </div>

              <div class="op-form__field">
                <label class="op-form__label" for="cip_combined_date">Date CIP</label>
                <input id="cip_combined_date"
                       name="cip_combined_date"
                       type="date"
                       class="op-form__input"
                       value="<?= $exCombinedDate ?>"
                       data-cip-required="1">
              </div>

              <div class="op-form__field">
                <label class="op-form__label" for="cip_combined_start">
                  Heure début <span class="op-form__unit">HH:MM</span>
                </label>
                <input id="cip_combined_start"
                       name="cip_combined_start"
                       type="time"
                       class="op-form__input"
                       value="<?= $exCombinedStart ?>"
                       data-cip-required="1">
              </div>

              <div class="op-form__field">
                <label class="op-form__label" for="cip_combined_end">
                  Heure fin <span class="op-form__unit">HH:MM</span>
                </label>
                <input id="cip_combined_end"
                       name="cip_combined_end"
                       type="time"
                       class="op-form__input"
                       value="<?= $exCombinedEnd ?>"
                       data-cip-required="1">
              </div>

            </div>
          </div>
        </div><!-- .cip-section__combined-block -->
        <?php else: ?>
        <!-- No partner in pair — no addon toggle needed; always individual mode. -->
        <input type="hidden" name="cip_inline_combine" value="0">
        <?php endif ?>

      </div><!-- #cip-anchor-fields -->
    </div><!-- anchor machine row -->

    <?php
      // Render any non-pair machines (e.g. pump) as normal independent rows.
      $cipPairSet = array_flip($cipCombinePair);
      foreach ($cipMachines as $mc):
          if (isset($cipPairSet[$mc])) {
              continue;  // pair members handled above (anchor/partner)
          }
          $mc = htmlspecialchars($mc);
          $exEv   = $exMachines[$mc] ?? null;
          $isDone = $exEv !== null;
          $exTypeId = $isDone ? (int)$exEv['cip_type_id_fk'] : null;
          $exDate   = $isDone ? htmlspecialchars($exEv['cip_date'] ?? '') : '';
          $exStart  = $isDone ? htmlspecialchars(substr($exEv['cip_started_at'] ?? '', 0, 5)) : '';
          $exEnd    = $isDone ? htmlspecialchars(substr($exEv['cip_ended_at']   ?? '', 0, 5)) : '';
          $label = match($mc) {
              'centri' => 'Centrifugeuse',
              'kze'    => 'KZE',
              'pump'   => 'Pompe',
              'filler' => 'Soutireuse',
              default  => ucfirst($mc),
          };
    ?>
    <div class="cip-section__machine-row" id="cip-machine-<?= $mc ?>-row">
      <div class="cip-section__machine-header">
        <label class="cip-section__checkbox-row">
          <input type="checkbox"
                 name="cip_machine_<?= $mc ?>"
                 value="1"
                 id="cip_machine_<?= $mc ?>"
                 class="cip-section__checkbox cip-machine-toggle"
                 data-machine="<?= $mc ?>"
                 <?= $isDone ? 'checked' : '' ?>>
          <span class="cip-section__machine-label"><?= $label ?> — CIP effectué</span>
        </label>
      </div>
      <div class="cip-section__machine-fields" id="cip-machine-<?= $mc ?>-fields"
           <?= $isDone ? '' : 'hidden' ?>>
        <div class="op-form__grid--3 op-form__grid">
          <div class="op-form__field">
            <label class="op-form__label" for="cip_machine_<?= $mc ?>_type_id">Type CIP</label>
            <?php ($cipTypeSelect)("cip_machine_{$mc}_type_id", $exTypeId, true) ?>
          </div>
          <div class="op-form__field">
            <label class="op-form__label" for="cip_machine_<?= $mc ?>_date">Date CIP</label>
            <input id="cip_machine_<?= $mc ?>_date" name="cip_machine_<?= $mc ?>_date"
                   type="date" class="op-form__input" value="<?= $exDate ?>" data-cip-required="1">
          </div>
          <div class="op-form__field">
            <label class="op-form__label" for="cip_machine_<?= $mc ?>_start">
              Heure début <span class="op-form__unit">HH:MM</span>
            </label>
            <input id="cip_machine_<?= $mc ?>_start" name="cip_machine_<?= $mc ?>_start"
                   type="time" class="op-form__input" value="<?= $exStart ?>" data-cip-required="1">
          </div>
          <div class="op-form__field">
            <label class="op-form__label" for="cip_machine_<?= $mc ?>_end">
              Heure fin <span class="op-form__unit">HH:MM</span>
            </label>
            <input id="cip_machine_<?= $mc ?>_end" name="cip_machine_<?= $mc ?>_end"
                   type="time" class="op-form__input" value="<?= $exEnd ?>" data-cip-required="1">
          </div>
        </div>
      </div>
    </div><!-- machine row: <?= $mc ?> -->
    <?php endforeach ?>

    <?php else:
      // ══════════════════════════════════════════════════════════════════
      // SYMMETRIC PEER BRANCH — default when combine_anchor is absent.
      // Both pair members render as independent rows + the simultané toggle.
      // Byte-identical to the pre-anchor behaviour for racking/brewing.
      // ══════════════════════════════════════════════════════════════════

      // Show the simultané toggle only when both codes in the combine pair are present.
      $cipShowSimultane = $cipShowInlineCombine
          && in_array($cipCombinePair[0], $cipMachines, true)
          && in_array($cipCombinePair[1], $cipMachines, true);
    ?>

    <?php if ($cipShowSimultane): ?>
    <!-- ── Simultané toggle checkbox ──────────────────────────────── -->
    <div class="cip-section__inline-toggle">
      <label class="cip-section__checkbox-row">
        <input type="checkbox"
               name="cip_inline_combine"
               value="1"
               id="cip_inline_combine"
               class="cip-section__checkbox"
               <?= $exInline ? 'checked' : '' ?>>
        <span class="cip-section__checkbox-label"><?= htmlspecialchars($cipPairLabel) ?> — CIP simultané</span>
        <span class="cip-section__badge">inline</span>
      </label>
    </div>

    <!-- ── Combined block (shown when simultané is checked) ───────── -->
    <div class="cip-section__combined-block" id="cip-combined-block"
         <?= $exInline ? '' : 'hidden' ?>>
      <div class="cip-section__combined-header">
        <span class="cip-section__machine-label"><?= htmlspecialchars($cipPairLabel) ?> — CIP simultané</span>
      </div>
      <div class="cip-section__combined-fields" id="cip-combined-fields">
        <div class="op-form__grid--3 op-form__grid">

          <div class="op-form__field">
            <label class="op-form__label" for="cip_combined_type_id">Type CIP</label>
            <?php ($cipTypeSelect)(
                'cip_combined_type_id',
                $exCombinedTypeId,
                true
            ) ?>
          </div>

          <div class="op-form__field">
            <label class="op-form__label" for="cip_combined_date">Date CIP</label>
            <input id="cip_combined_date"
                   name="cip_combined_date"
                   type="date"
                   class="op-form__input"
                   value="<?= $exCombinedDate ?>"
                   data-cip-required="1">
          </div>

          <div class="op-form__field">
            <label class="op-form__label" for="cip_combined_start">
              Heure début <span class="op-form__unit">HH:MM</span>
            </label>
            <input id="cip_combined_start"
                   name="cip_combined_start"
                   type="time"
                   class="op-form__input"
                   value="<?= $exCombinedStart ?>"
                   data-cip-required="1">
          </div>

          <div class="op-form__field">
            <label class="op-form__label" for="cip_combined_end">
              Heure fin <span class="op-form__unit">HH:MM</span>
            </label>
            <input id="cip_combined_end"
                   name="cip_combined_end"
                   type="time"
                   class="op-form__input"
                   value="<?= $exCombinedEnd ?>"
                   data-cip-required="1">
          </div>

        </div>
      </div>
    </div><!-- .cip-section__combined-block -->

    <?php else: ?>
    <input type="hidden" name="cip_inline_combine" value="0">
    <?php endif ?>

    <?php foreach ($cipMachines as $mc):
      $mc = htmlspecialchars($mc);
      // Re-display: existing event for this machine code
      $exEv = $exMachines[$mc] ?? null;
      // When inline_combine is active, the pair codes exist in the DB as inline events.
      // In that case their individual rows must start hidden; the combined block shows their data.
      $isInlineMachine = $cipShowSimultane && in_array($mc, $cipCombinePair, true);
      $isDone    = $exEv !== null && !$exInline;
      $exTypeId  = ($exEv && !$exInline) ? (int)$exEv['cip_type_id_fk'] : null;
      $exDate    = ($exEv && !$exInline) ? htmlspecialchars($exEv['cip_date'] ?? '') : '';
      $exStart   = ($exEv && !$exInline) ? htmlspecialchars(substr($exEv['cip_started_at'] ?? '', 0, 5)) : '';
      $exEnd     = ($exEv && !$exInline) ? htmlspecialchars(substr($exEv['cip_ended_at']   ?? '', 0, 5)) : '';
      $label = match($mc) {
          'centri' => 'Centrifugeuse',
          'kze'    => 'KZE',
          'pump'   => 'Pompe',
          'filler' => 'Soutireuse',
          default  => ucfirst($mc),
      };
      // Pair-code rows are hidden when simultané is active.
      $rowHidden = ($isInlineMachine && $exInline);
    ?>
    <div class="cip-section__machine-row<?= $isInlineMachine ? ' cip-section__machine-row--inlineable' : '' ?>"
         id="cip-machine-<?= $mc ?>-row"
         <?= $rowHidden ? 'hidden' : '' ?>>
      <div class="cip-section__machine-header">
        <label class="cip-section__checkbox-row">
          <input type="checkbox"
                 name="cip_machine_<?= $mc ?>"
                 value="1"
                 id="cip_machine_<?= $mc ?>"
                 class="cip-section__checkbox cip-machine-toggle"
                 data-machine="<?= $mc ?>"
                 <?= $isDone ? 'checked' : '' ?>>
          <span class="cip-section__machine-label"><?= $label ?> — CIP effectué</span>
        </label>
      </div>
      <div class="cip-section__machine-fields" id="cip-machine-<?= $mc ?>-fields"
           <?= $isDone ? '' : 'hidden' ?>>
        <div class="op-form__grid--3 op-form__grid">

          <div class="op-form__field">
            <label class="op-form__label" for="cip_machine_<?= $mc ?>_type_id">Type CIP</label>
            <?php ($cipTypeSelect)(
                "cip_machine_{$mc}_type_id",
                $exTypeId,
                true
            ) ?>
          </div>

          <div class="op-form__field">
            <label class="op-form__label" for="cip_machine_<?= $mc ?>_date">Date CIP</label>
            <input id="cip_machine_<?= $mc ?>_date"
                   name="cip_machine_<?= $mc ?>_date"
                   type="date"
                   class="op-form__input"
                   value="<?= $exDate ?>"
                   data-cip-required="1">
          </div>

          <div class="op-form__field">
            <label class="op-form__label" for="cip_machine_<?= $mc ?>_start">
              Heure début <span class="op-form__unit">HH:MM</span>
            </label>
            <input id="cip_machine_<?= $mc ?>_start"
                   name="cip_machine_<?= $mc ?>_start"
                   type="time"
                   class="op-form__input"
                   value="<?= $exStart ?>"
                   data-cip-required="1">
          </div>

          <div class="op-form__field">
            <label class="op-form__label" for="cip_machine_<?= $mc ?>_end">
              Heure fin <span class="op-form__unit">HH:MM</span>
            </label>
            <input id="cip_machine_<?= $mc ?>_end"
                   name="cip_machine_<?= $mc ?>_end"
                   type="time"
                   class="op-form__input"
                   value="<?= $exEnd ?>"
                   data-cip-required="1">
          </div>

        </div>
      </div>
    </div><!-- machine row: <?= $mc ?> -->
    <?php endforeach ?>

    <?php endif ?><!-- end anchor/symmetric branch -->
  </div><!-- .cip-section__machines -->
  <?php else: ?>
  <!-- No machine CIP for this form (brewing) -->
  <input type="hidden" name="cip_inline_combine" value="0">
  <?php endif ?>

  <?php if (!empty($cipVessels)): ?>
  <!-- ── Vessel CIP block ───────────────────────────────────────────── -->
  <div class="cip-section__block cip-section__vessels" id="cip-vessels-block">
    <?php foreach ($cipVessels as $vIdx => $vest):
      // Re-display: match by vessel index
      $exVess    = $exVessels[$vIdx] ?? null;
      $exVDone   = $exVess !== null;
      $exVTypeId = $exVess ? (int)$exVess['cip_type_id_fk'] : null;
      $exVCode   = $exVess ? htmlspecialchars($exVess['target_code'] ?? $vest['code']) : htmlspecialchars($vest['code']);
      $exVNum    = $exVess ? (int)$exVess['target_number'] : ($vest['number'] ?? null);
      $exVDate   = $exVess ? htmlspecialchars($exVess['cip_date'] ?? '') : '';
      $exVStart  = $exVess ? htmlspecialchars(substr($exVess['cip_started_at'] ?? '', 0, 5)) : '';
      $exVEnd    = $exVess ? htmlspecialchars(substr($exVess['cip_ended_at']   ?? '', 0, 5)) : '';

      $isDynamic = (bool)($vest['dynamic_label'] ?? false);
      $vLabel    = htmlspecialchars($vest['label'] ?? 'Cuve');
      $vRequired = (bool)($vest['required'] ?? true);
      $vCode     = htmlspecialchars($vest['code']);
    ?>
    <div class="cip-section__vessel-row" id="cip-vessel-<?= $vIdx ?>-row">
      <!-- Hidden fields: code + number always submitted (the "done" checkbox gates writing) -->
      <input type="hidden" name="cip_vessel_<?= $vIdx ?>_code"   value="<?= $exVCode ?>">
      <input type="hidden" name="cip_vessel_<?= $vIdx ?>_number" value="<?= $exVNum !== null ? (int)$exVNum : '' ?>">

      <div class="cip-section__vessel-header">
        <label class="cip-section__checkbox-row">
          <input type="checkbox"
                 name="cip_vessel_<?= $vIdx ?>_done"
                 value="1"
                 id="cip_vessel_<?= $vIdx ?>_done"
                 class="cip-section__checkbox cip-vessel-toggle"
                 data-vessel="<?= $vIdx ?>"
                 <?= $exVDone ? 'checked' : '' ?>>
          <span class="cip-section__vessel-label<?= $isDynamic ? ' cip-vessel-dynamic-label' : '' ?>"
                id="cip-vessel-<?= $vIdx ?>-label"
                data-vessel-idx="<?= $vIdx ?>"><?= $vLabel ?></span>
          — CIP effectué
        </label>
      </div>

      <div class="cip-section__vessel-fields" id="cip-vessel-<?= $vIdx ?>-fields"
           <?= $exVDone ? '' : 'hidden' ?>>
        <div class="op-form__grid--3 op-form__grid">

          <div class="op-form__field">
            <label class="op-form__label" for="cip_vessel_<?= $vIdx ?>_type_id">Type CIP</label>
            <?php ($cipTypeSelect)(
                "cip_vessel_{$vIdx}_type_id",
                $exVTypeId,
                $vRequired
            ) ?>
          </div>

          <div class="op-form__field">
            <label class="op-form__label" for="cip_vessel_<?= $vIdx ?>_date">Date CIP</label>
            <input id="cip_vessel_<?= $vIdx ?>_date"
                   name="cip_vessel_<?= $vIdx ?>_date"
                   type="date"
                   class="op-form__input"
                   value="<?= $exVDate ?>"
                   <?= $vRequired ? 'data-cip-required="1"' : '' ?>>
          </div>

          <div class="op-form__field">
            <label class="op-form__label" for="cip_vessel_<?= $vIdx ?>_start">
              Heure début <span class="op-form__unit">HH:MM</span>
            </label>
            <input id="cip_vessel_<?= $vIdx ?>_start"
                   name="cip_vessel_<?= $vIdx ?>_start"
                   type="time"
                   class="op-form__input"
                   value="<?= $exVStart ?>"
                   <?= $vRequired ? 'data-cip-required="1"' : '' ?>>
          </div>

          <div class="op-form__field">
            <label class="op-form__label" for="cip_vessel_<?= $vIdx ?>_end">
              Heure fin <span class="op-form__unit">HH:MM</span>
            </label>
            <input id="cip_vessel_<?= $vIdx ?>_end"
                   name="cip_vessel_<?= $vIdx ?>_end"
                   type="time"
                   class="op-form__input"
                   value="<?= $exVEnd ?>"
                   <?= $vRequired ? 'data-cip-required="1"' : '' ?>>
          </div>

        </div>
      </div>
    </div><!-- vessel row: <?= $vIdx ?> -->
    <?php endforeach ?>
  </div><!-- .cip-section__vessels -->
  <?php endif ?>

  <!-- ── Shared notes ───────────────────────────────────────────────── -->
  <div class="cip-section__notes-row op-form__grid op-form__grid--1">
    <div class="op-form__field op-form__field--full">
      <label class="op-form__label" for="cip_notes">
        Notes CIP <span class="op-form__opt">(optionnel)</span>
      </label>
      <input id="cip_notes"
             name="cip_notes"
             type="text"
             class="op-form__input"
             placeholder="Observations, produit utilisé, concentration…"
             value="<?= isset($cipExisting) ? htmlspecialchars($cipExisting['notes'] ?? '') : '' ?>"
             maxlength="255">
    </div>
  </div>

</div><!-- .cip-section -->

<!-- ══ Inline JS: toggle machine/vessel field-groups on checkbox change ══ -->
<script>
(function () {
  'use strict';

  /**
   * Sync the `required` attribute on inputs that carry `data-cip-required="1"`
   * inside a CIP field-group div, and toggle the div's visibility.
   *
   * Rule: required is set IFF the governing checkbox is checked AND the fields
   * div is visible. This prevents browser constraint validation from firing on
   * hidden inputs (the hidden-required deadlock) while still blocking submission
   * when the operator ticks the box but leaves a mandatory field empty.
   *
   * @param {HTMLElement} fieldsEl  The .cip-section__*-fields div (or combined block)
   * @param {boolean}     checked   Whether the governing checkbox is checked
   */
  function cipSyncRequired(fieldsEl, checked) {
    if (!fieldsEl) return;
    fieldsEl.hidden = !checked;
    fieldsEl.querySelectorAll('[data-cip-required]').forEach(function (el) {
      if (checked) {
        el.setAttribute('required', '');
      } else {
        el.removeAttribute('required');
      }
    });
    // Also cover type <select> elements (no data-cip-required attr, but required when visible).
    fieldsEl.querySelectorAll('select.cip-section__select').forEach(function (el) {
      if (checked) {
        el.setAttribute('required', '');
      } else {
        el.removeAttribute('required');
      }
    });
  }

  /**
   * Force-clear and disable all cip-required inputs inside a hidden row.
   * Prevents stale values in the individual centri/kze rows from being
   * submitted when simultané is active and those rows are hidden.
   */
  function cipClearRowInputs(rowEl) {
    if (!rowEl) return;
    rowEl.querySelectorAll('input[type="checkbox"].cip-machine-toggle').forEach(function (cb) {
      cb.checked = false;
    });
    rowEl.querySelectorAll('input[type="date"], input[type="time"]').forEach(function (inp) {
      inp.value = '';
      inp.removeAttribute('required');
    });
    rowEl.querySelectorAll('select.cip-section__select').forEach(function (sel) {
      sel.value = '';
      sel.removeAttribute('required');
    });
    // Also hide the machine fields sub-div so cipSyncRequired starts fresh if re-shown.
    var fieldsEl = rowEl.querySelector('.cip-section__machine-fields');
    if (fieldsEl) {
      fieldsEl.hidden = true;
    }
  }

  /**
   * Clear and hide date/time/select inputs inside a container WITHOUT hiding the
   * container itself.  Used when switching between individual and combined modes
   * in the anchor branch — we hide the individual fields div but leave the outer
   * anchor-fields container visible.
   */
  function cipClearFieldInputs(containerEl) {
    if (!containerEl) return;
    containerEl.querySelectorAll('input[type="date"], input[type="time"]').forEach(function (inp) {
      inp.value = '';
      inp.removeAttribute('required');
    });
    containerEl.querySelectorAll('select.cip-section__select').forEach(function (sel) {
      sel.value = '';
      sel.removeAttribute('required');
    });
  }

  // ── ANCHOR BRANCH: anchor toggle + addon toggle ─────────────────────────
  //
  // Present when the PHP rendered a combine_anchor config — identified by the
  // .cip-anchor-toggle class (absent in the symmetric/peer branch).
  //
  // Interaction model:
  //   anchor checked + addon unchecked → individual fields visible; combined block hidden.
  //   anchor checked + addon checked   → individual fields hidden; combined block visible.
  //   anchor unchecked                 → all inner fields hidden; addon reset to unchecked.
  //
  // DOM nodes:
  //   #cip-anchor-fields          — outer container (shown when anchor is checked)
  //   #cip-anchor-individual-fields — inner individual-mode fields div
  //   #cip_inline_combine         — addon toggle (partner checkbox)
  //   #cip-combined-block         — combined mode fields
  //   #cip-combined-fields        — inner combined fields (for cipSyncRequired)

  var anchorCb = document.querySelector('.cip-anchor-toggle');
  if (anchorCb) {
    var anchorFields     = document.getElementById('cip-anchor-fields');
    var individualFields = document.getElementById('cip-anchor-individual-fields');
    var addonCb          = document.getElementById('cip_inline_combine');
    var combinedBlock    = document.getElementById('cip-combined-block');
    var combinedFields   = document.getElementById('cip-combined-fields');

    function cipApplyAnchorAddon(addonActive) {
      // Switch between individual and combined mode within the anchor block.
      if (individualFields) {
        individualFields.hidden = addonActive;
        // Sync required on the individual fields inputs.
        individualFields.querySelectorAll('[data-cip-required]').forEach(function (el) {
          if (!addonActive) {
            el.setAttribute('required', '');
          } else {
            el.removeAttribute('required');
          }
        });
        individualFields.querySelectorAll('select.cip-section__select').forEach(function (el) {
          if (!addonActive) {
            el.setAttribute('required', '');
          } else {
            el.removeAttribute('required');
          }
        });
        // Clear stale values from the mode being hidden.
        if (addonActive) {
          cipClearFieldInputs(individualFields);
        }
      }
      // Show/hide combined block and sync its required inputs.
      if (combinedBlock) {
        combinedBlock.hidden = !addonActive;
      }
      if (combinedFields) {
        cipSyncRequired(combinedFields, addonActive);
        if (!addonActive) {
          cipClearFieldInputs(combinedFields);
        }
      }
    }

    function cipApplyAnchor(anchorChecked) {
      if (anchorFields) {
        anchorFields.hidden = !anchorChecked;
      }
      if (!anchorChecked) {
        // Anchor unchecked: reset addon, clear all inner fields.
        if (addonCb) {
          addonCb.checked = false;
        }
        cipApplyAnchorAddon(false);
        // Also clear combined fields when resetting anchor.
        if (combinedFields) {
          cipClearFieldInputs(combinedFields);
        }
      } else {
        // Anchor checked: apply current addon state.
        cipApplyAnchorAddon(addonCb ? addonCb.checked : false);
      }
    }

    // Initial sync on page load.
    cipApplyAnchor(anchorCb.checked);

    anchorCb.addEventListener('change', function () {
      cipApplyAnchor(anchorCb.checked);
    });

    if (addonCb) {
      addonCb.addEventListener('change', function () {
        cipApplyAnchorAddon(addonCb.checked);
      });
    }
  }

  // ── SYMMETRIC BRANCH: simultané (inline combine) toggle ────────────────
  //
  // Present when the PHP rendered the symmetric peer layout (no anchor).
  // Identified by .cip-section__machine-row--inlineable rows.
  // The anchor branch has no such rows, so this block is a no-op in that context.

  var inlineCb = document.getElementById('cip_inline_combine');
  var inlineableRows = Array.prototype.slice.call(
    document.querySelectorAll('.cip-section__machine-row--inlineable')
  );
  // Only wire this block when operating in the symmetric branch (inlineable rows present).
  if (inlineCb && inlineableRows.length > 0) {
    var symCombinedBlock  = document.getElementById('cip-combined-block');
    var symCombinedFields = document.getElementById('cip-combined-fields');

    function cipApplySimultane(active) {
      // Show/hide combined block and sync its required inputs.
      if (symCombinedBlock) {
        symCombinedBlock.hidden = !active;
      }
      if (symCombinedFields) {
        cipSyncRequired(symCombinedFields, active);
      }

      // Show/hide individual centri + kze rows and clear them when hiding.
      inlineableRows.forEach(function (row) {
        if (active) {
          // Hide individual row and clear it so no stray POST values are submitted.
          row.hidden = true;
          cipClearRowInputs(row);
        } else {
          // Reveal individual row; required state starts empty (no checkbox ticked).
          row.hidden = false;
          // cipSyncRequired will be triggered by the machine toggles on their next change;
          // on reveal with unchecked box the fields div should stay hidden.
          var machineFields = row.querySelector('.cip-section__machine-fields');
          if (machineFields) {
            cipSyncRequired(machineFields, false);
          }
        }
      });
    }

    // Initial sync on page load.
    cipApplySimultane(inlineCb.checked);

    inlineCb.addEventListener('change', function () {
      cipApplySimultane(inlineCb.checked);
    });
  }

  // ── Machine toggles (independent machines: pump etc.) ───────────────────
  // Handles .cip-machine-toggle checkboxes — used by non-pair machines in both
  // branches (pump in packaging) and by all pair-member machines in the symmetric
  // branch.  The anchor branch uses .cip-anchor-toggle instead (separate handler
  // above), so the anchor checkbox is NOT in this selector.
  document.querySelectorAll('.cip-machine-toggle').forEach(function (cb) {
    var fields = document.getElementById('cip-machine-' + cb.dataset.machine + '-fields');
    // Initial sync on page load (handles both fresh form and re-display).
    // Note: when simultané is active, these rows are hidden so this is a no-op on visible state,
    // but we still init required correctly for when the row is later revealed.
    cipSyncRequired(fields, cb.checked);
    cb.addEventListener('change', function () {
      cipSyncRequired(fields, cb.checked);
    });
  });

  // ── Vessel toggles ──────────────────────────────────────────────────────
  document.querySelectorAll('.cip-vessel-toggle').forEach(function (cb) {
    var fields = document.getElementById('cip-vessel-' + cb.dataset.vessel + '-fields');
    // Initial sync on page load.
    cipSyncRequired(fields, cb.checked);
    cb.addEventListener('change', function () {
      cipSyncRequired(fields, cb.checked);
    });
  });

  // Dynamic vessel label swap (racking): driven by #racking_destination_type.
  // The including form calls window.cipUpdateVesselLabel(code, number) when the
  // destination-type + number selects change.
  window.cipUpdateVesselLabel = function (code, number) {
    document.querySelectorAll('.cip-vessel-dynamic-label').forEach(function (el) {
      var label = _cipVesselLabel(code, number);
      el.textContent = label;
      // Also update the hidden code/number fields for this vessel row.
      var idx     = el.dataset.vesselIdx;
      var codeIn  = document.querySelector('[name="cip_vessel_' + idx + '_code"]');
      var numIn   = document.querySelector('[name="cip_vessel_' + idx + '_number"]');
      if (codeIn)  codeIn.value  = code   || '';
      if (numIn)   numIn.value   = number  !== undefined ? String(number) : '';
    });
  };

  function _cipVesselLabel(code, number) {
    var labels = { bbt: 'BBT', cct: 'CCT', yt: 'YT', tank: 'Tank' };
    var base = labels[code] || (code ? code.toUpperCase() : 'Cuve');
    if (number) return 'CIP ' + base + ' ' + number;
    return 'CIP ' + base;
  }
}());
</script>
