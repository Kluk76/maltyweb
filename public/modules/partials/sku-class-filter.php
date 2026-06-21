<?php
declare(strict_types=1);
/**
 * Partial: SKU classification segmented filter control.
 *
 * Caller contract:
 *   Set $skuClassFilterValue ('all'|'Neb'|'Contract') before requiring this file.
 *   The JS in sku-class-filter.js reads [data-skuf-value] on the root .skuf element
 *   and applies the initial filter on DOMContentLoaded.
 *
 * CSRF: this partial is self-contained — it owns its own window.SKUF_CSRF global
 * so it works on any page without the caller defining a page-specific CSRF global.
 */
require_once __DIR__ . '/../../../app/csrf.php';

if (!isset($skuClassFilterValue) || !in_array($skuClassFilterValue, ['all', 'Neb', 'Contract'], true)) {
    $skuClassFilterValue = 'Neb';
}
?>
<div class="skuf" data-skuf-key="sku_class_filter" data-skuf-value="<?= htmlspecialchars($skuClassFilterValue, ENT_QUOTES | ENT_HTML5) ?>">
  <span class="skuf-label">Affichage</span>
  <div class="skuf-seg" role="group" aria-label="Filtrer les SKU">
    <button type="button"
            class="skuf-btn<?= $skuClassFilterValue === 'all' ? ' skuf-btn-active' : '' ?>"
            data-skuf-val="all"
            aria-pressed="<?= $skuClassFilterValue === 'all' ? 'true' : 'false' ?>">Toutes</button>
    <button type="button"
            class="skuf-btn<?= $skuClassFilterValue === 'Neb' ? ' skuf-btn-active' : '' ?>"
            data-skuf-val="Neb"
            aria-pressed="<?= $skuClassFilterValue === 'Neb' ? 'true' : 'false' ?>">Nébuleuse</button>
    <button type="button"
            class="skuf-btn<?= $skuClassFilterValue === 'Contract' ? ' skuf-btn-active' : '' ?>"
            data-skuf-val="Contract"
            aria-pressed="<?= $skuClassFilterValue === 'Contract' ? 'true' : 'false' ?>">Contract</button>
  </div>
</div>
<?php if (!defined('SKUF_CSRF_EMITTED')) {
    define('SKUF_CSRF_EMITTED', true); ?>
<script>window.SKUF_CSRF = <?= json_encode(csrf_token(), JSON_HEX_TAG | JSON_HEX_AMP) ?>;</script>
<?php } ?>
