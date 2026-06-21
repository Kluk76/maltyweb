<?php
declare(strict_types=1);
/**
 * lookup-panel.php — Reusable collapsible lookup panel partial.
 *
 * Caller must set $lookupConfig before require-ing this file:
 *
 *   $lookupConfig = [
 *     'panel_id'         => 'packaging-lookup',
 *     'api_endpoint'     => '/api/packaging-lookup.php',
 *     'mode_batch_label' => 'Par SKU + lot',
 *     'type'             => 'packaging',   // or 'brewing'
 *     'batch_fields'     => [
 *       ['name'=>'sku_id', 'label'=>'SKU',  'type'=>'select',
 *        'options'=>$skuOptions, 'value_col'=>'id', 'label_col'=>'sku_code'],
 *       ['name'=>'batch',  'label'=>'Lot',   'type'=>'text'],
 *     ],
 *   ];
 */

if (!isset($lookupConfig) || !is_array($lookupConfig)) {
    throw new \RuntimeException('lookup-panel.php requires $lookupConfig to be set before inclusion.');
}

$lpPanelId   = htmlspecialchars((string) ($lookupConfig['panel_id']         ?? 'lookup'), ENT_QUOTES, 'UTF-8');
$lpEndpoint  = htmlspecialchars((string) ($lookupConfig['api_endpoint']      ?? ''),       ENT_QUOTES, 'UTF-8');
$lpBatchLabel = htmlspecialchars((string) ($lookupConfig['mode_batch_label'] ?? 'Par lot'), ENT_QUOTES, 'UTF-8');
$lpType      = htmlspecialchars((string) ($lookupConfig['type']              ?? ''),        ENT_QUOTES, 'UTF-8');
$lpFields    = $lookupConfig['batch_fields'] ?? [];
$lpToday     = date('Y-m-d');
?>
<div id="<?= $lpPanelId ?>"
     class="lookup-panel"
     data-endpoint="<?= $lpEndpoint ?>"
     data-type="<?= $lpType ?>">

  <button type="button"
          class="lp-toggle"
          aria-expanded="false"
          aria-controls="<?= $lpPanelId ?>-body">
    <span class="lp-toggle-label">Consultation</span>
    <span class="lp-toggle-chevron" aria-hidden="true">▸</span>
  </button>

  <div id="<?= $lpPanelId ?>-body" class="lp-body" hidden>

    <!-- Tab bar -->
    <div class="lp-tabs" role="tablist" aria-label="Mode de recherche">
      <button type="button"
              class="lp-tab lp-tab-active"
              role="tab"
              aria-selected="true"
              aria-controls="<?= $lpPanelId ?>-pane-day"
              data-tab="day">Par date</button>
      <button type="button"
              class="lp-tab"
              role="tab"
              aria-selected="false"
              aria-controls="<?= $lpPanelId ?>-pane-batch"
              data-tab="batch"><?= $lpBatchLabel ?></button>
    </div>

    <!-- Tab: Par date -->
    <div id="<?= $lpPanelId ?>-pane-day"
         class="lp-tab-pane"
         role="tabpanel"
         aria-labelledby="<?= $lpPanelId ?>-tab-day">
      <div class="lp-search-row">
        <label class="lp-field-label" for="<?= $lpPanelId ?>-day-date">Date</label>
        <input type="date"
               id="<?= $lpPanelId ?>-day-date"
               class="lp-date-input"
               value="<?= htmlspecialchars($lpToday, ENT_QUOTES, 'UTF-8') ?>"
               name="date">
        <button type="button" class="lp-search-btn" data-mode="day">Rechercher</button>
      </div>
    </div>

    <!-- Tab: Par lot / batch -->
    <div id="<?= $lpPanelId ?>-pane-batch"
         class="lp-tab-pane"
         role="tabpanel"
         aria-labelledby="<?= $lpPanelId ?>-tab-batch"
         hidden>
      <div class="lp-search-row">
        <?php foreach ($lpFields as $field): ?>
          <?php
          $fName     = htmlspecialchars((string) ($field['name']  ?? ''), ENT_QUOTES, 'UTF-8');
          $fLabel    = htmlspecialchars((string) ($field['label'] ?? ''), ENT_QUOTES, 'UTF-8');
          $fType     = (string) ($field['type'] ?? 'text');
          $inputId   = $lpPanelId . '-batch-' . $fName;
          ?>
          <label class="lp-field-label" for="<?= $inputId ?>"><?= $fLabel ?></label>
          <?php if ($fType === 'select'): ?>
            <?php
            $opts       = $field['options']   ?? [];
            $valueCol   = (string) ($field['value_col'] ?? 'id');
            $labelCol   = (string) ($field['label_col'] ?? 'name');
            ?>
            <select id="<?= $inputId ?>"
                    class="lp-select-input"
                    name="<?= $fName ?>">
              <option value="">— choisir —</option>
              <?php foreach ($opts as $opt): ?>
                <option value="<?= htmlspecialchars((string) ($opt[$valueCol] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                  <?= htmlspecialchars((string) ($opt[$labelCol] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                </option>
              <?php endforeach ?>
            </select>
          <?php else: ?>
            <input type="text"
                   id="<?= $inputId ?>"
                   class="lp-text-input"
                   name="<?= $fName ?>"
                   placeholder="<?= $fLabel ?>">
          <?php endif ?>
        <?php endforeach ?>
        <button type="button" class="lp-search-btn" data-mode="batch">Rechercher</button>
      </div>
    </div>

    <!-- Results container -->
    <div id="<?= $lpPanelId ?>-results" class="lp-results" aria-live="polite"></div>

  </div><!-- /.lp-body -->
</div><!-- /.lookup-panel -->
