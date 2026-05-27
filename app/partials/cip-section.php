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
 */
$cipTypeSelect = function (string $name, ?int $selectedId = null, bool $required = false) use ($cipTypes): void {
    // $required param intentionally ignored — JS owns required state; see Bug 1/2 fix.
    echo '<select name="' . htmlspecialchars($name) . '" class="op-form__select cip-section__select">';
    echo '<option value="">— type CIP —</option>';
    foreach ($cipTypes as $t) {
        $sel = ((int)$t['id'] === $selectedId) ? ' selected' : '';
        echo '<option value="' . (int)$t['id'] . '"' . $sel . '>'
            . htmlspecialchars($t['name'])
            . '</option>';
    }
    echo '</select>';
};

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

    <?php if ($cipShowInlineCombine && in_array('centri', $cipMachines, true) && in_array('kze', $cipMachines, true)): ?>
    <div class="cip-section__inline-toggle">
      <label class="cip-section__checkbox-row">
        <input type="checkbox"
               name="cip_inline_combine"
               value="1"
               id="cip_inline_combine"
               class="cip-section__checkbox"
               <?= $exInline ? 'checked' : '' ?>>
        <span class="cip-section__checkbox-label">Centri + KZE — CIP simultané</span>
        <span class="cip-section__badge">inline</span>
      </label>
    </div>
    <?php else: ?>
    <input type="hidden" name="cip_inline_combine" value="0">
    <?php endif ?>

    <?php foreach ($cipMachines as $mc):
      $mc = htmlspecialchars($mc);
      // Re-display: existing event for this machine code
      $exEv = $exMachines[$mc] ?? null;
      $isDone    = $exEv !== null;
      $exTypeId  = $exEv ? (int)$exEv['cip_type_id_fk'] : null;
      $exDate    = $exEv ? htmlspecialchars($exEv['cip_date'] ?? '') : '';
      $exStart   = $exEv ? htmlspecialchars(substr($exEv['cip_started_at'] ?? '', 0, 5)) : '';
      $exEnd     = $exEv ? htmlspecialchars(substr($exEv['cip_ended_at']   ?? '', 0, 5)) : '';
      $label = match($mc) {
          'centri' => 'Centrifugeuse',
          'kze'    => 'KZE',
          'pump'   => 'Pompe',
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
   * inside a CIP field-group div.
   *
   * Rule: required is set IFF the CIP-done checkbox is checked AND the fields
   * div is visible. This prevents browser constraint validation from firing on
   * hidden inputs (the hidden-required deadlock) while still blocking submission
   * when the operator ticks the box but leaves a mandatory field empty.
   *
   * @param {HTMLElement} fieldsEl  The .cip-section__*-fields div
   * @param {boolean}     checked   Whether the governing CIP-done checkbox is checked
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
    // Also cover the type <select> (no data-cip-required, but should be required when visible).
    fieldsEl.querySelectorAll('select.cip-section__select').forEach(function (el) {
      if (checked) {
        el.setAttribute('required', '');
      } else {
        el.removeAttribute('required');
      }
    });
  }

  // ── Machine toggles ─────────────────────────────────────────────────────
  document.querySelectorAll('.cip-machine-toggle').forEach(function (cb) {
    var fields = document.getElementById('cip-machine-' + cb.dataset.machine + '-fields');
    // Initial sync on page load (handles both fresh form and re-display).
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
      // Also update the hidden code/number fields for this vessel row
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
