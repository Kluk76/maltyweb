<?php
declare(strict_types=1);

/**
 * triage_mi_create_modal.php — MI creation panel for triage.
 *
 * Rendered as a full-panel replacement for the detail panel when the operator
 * clicks "Créer nouveau MI" on an unresolved invoice line.
 *
 * Route: /modules/triage.php?tab=docs&rq_id=N&action=create&line=I
 *
 * This file is included by triage.php when action=create is detected.
 * It uses $rqRow, $rqInv, $pdo, $me already set in parent scope.
 * Additional GET params: line=I (line_index, 0-based).
 */

require_once __DIR__ . '/../../app/services/mi_propose.php';
require_once __DIR__ . '/../../app/services/triage_actions.php';
require_once __DIR__ . '/../../app/csrf.php';

$lineIndex = isset($_GET['line']) ? (int)$_GET['line'] : 0;

// ── Load the unresolved line ──────────────────────────────────────────────────
$ctx          = triage_parse_context((string)($rqRow['context'] ?? ''));
$rawLineText  = '';
$lineParsed   = null;

if (isset($ctx['unresolved'][$lineIndex])) {
    $lineParsed  = ta_parse_unresolved_line($ctx['unresolved'][$lineIndex]);
    $rawLineText = $lineParsed['raw'] ?? '';
}

// ── Proposition ───────────────────────────────────────────────────────────────
// Look up supplier_id from context supplier name
$supplierId = null;
if (!empty($ctx['supplier'])) {
    $supStmt = $pdo->prepare(
        "SELECT id FROM ref_suppliers WHERE name = ? AND is_active = 1 LIMIT 1"
    );
    $supStmt->execute([$ctx['supplier']]);
    $supplierId = $supStmt->fetchColumn() ?: null;
}

$proposal = proposeMi($rawLineText, $supplierId !== false ? $supplierId : null, $pdo);

// ── Load categories for dropdown ──────────────────────────────────────────────
$catsStmt = $pdo->query("SELECT id, name FROM ref_mi_categories ORDER BY name");
$allCats  = $catsStmt->fetchAll();

// ── Load subcategories (keyed by category_id) ─────────────────────────────────
$subcatStmt = $pdo->query(
    "SELECT id, name, category_id, gl_account FROM ref_mi_subcategories ORDER BY name"
);
$allSubcats = $subcatStmt->fetchAll();
$subcatByCat = [];
foreach ($allSubcats as $sc) {
    $subcatByCat[(int)$sc['category_id']][] = $sc;
}

// ── Load distinct GL accounts ─────────────────────────────────────────────────
$acctStmt = $pdo->query(
    "SELECT DISTINCT gl_account FROM ref_mi WHERE gl_account IS NOT NULL AND gl_account <> ''
     UNION
     SELECT DISTINCT gl_account FROM ref_mi_subcategories WHERE gl_account IS NOT NULL AND gl_account <> ''
     ORDER BY gl_account"
);
$allAccounts = $acctStmt->fetchAll(PDO::FETCH_COLUMN);

// ── Load all active MIs for the "alias instead" chip list ─────────────────────
$miListStmt = $pdo->query(
    "SELECT mi_id, name FROM ref_mi WHERE is_active = 1 ORDER BY mi_id"
);
$allMis = $miListStmt->fetchAll();

// ── Pre-fill values: proposed values when available, else empty ───────────────
$fillId     = $proposal['proposed_mi_id']       ?? '';
$fillCat    = $proposal['proposed_category']     ?? '';
$fillSubcat = $proposal['proposed_subcategory']  ?? '';
$fillAcct   = $proposal['proposed_account']      ?? '';
$fillName   = $proposal['proposed_name']         ?? '';
$conf       = $proposal['proposition_confidence'];
$similar    = $proposal['similar_mi_ids'];
$reasoning  = $proposal['reasoning'];

$confClass  = $conf >= 0.70 ? 'conf--high' : ($conf >= 0.50 ? 'conf--mid' : 'conf--low');

// ── Back URL ──────────────────────────────────────────────────────────────────
$backUrl = '/modules/triage.php?tab=docs&rq_id=' . (int)$rqRow['id'];

?>
<div class="mi-create-modal">

  <!-- Header -->
  <div class="mi-modal-header">
    <a class="mi-modal-back" href="<?= htmlspecialchars($backUrl) ?>">← Retour</a>
    <h2 class="mi-modal-title">Créer un nouvel ingrédient</h2>
    <span class="mi-modal-rqid">Ligne <?= $lineIndex + 1 ?> · #<?= (int)$rqRow['id'] ?></span>
  </div>

  <!-- Context strip -->
  <div class="mi-modal-context">
    <div class="mi-modal-context__label">Ligne facture brute</div>
    <div class="mi-modal-context__raw"><?= htmlspecialchars($rawLineText ?: '(non disponible)') ?></div>
    <?php if ($lineParsed !== null): ?>
      <div class="mi-modal-context__meta">
        <?php if ($lineParsed['qty'] !== null): ?>
          <span>qté&nbsp;<?= htmlspecialchars((string)$lineParsed['qty']) ?></span>
        <?php endif ?>
        <?php if ($lineParsed['unitPrice'] !== null): ?>
          <span>PU&nbsp;<?= number_format($lineParsed['unitPrice'], 2, '.', "'") ?></span>
        <?php endif ?>
        <?php if ($lineParsed['lineTotal'] !== null): ?>
          <span>total&nbsp;<?= number_format($lineParsed['lineTotal'], 2, '.', "'") ?></span>
        <?php endif ?>
      </div>
    <?php endif ?>
    <?php if (!empty($ctx['supplier'])): ?>
      <div class="mi-modal-context__supplier">
        Fournisseur : <strong><?= htmlspecialchars($ctx['supplier']) ?></strong>
        <?php if (!empty($ctx['ref'])): ?>
          · Réf : <?= htmlspecialchars($ctx['ref']) ?>
        <?php endif ?>
      </div>
    <?php endif ?>
  </div>

  <!-- Similar MIs — alias shortcut chips -->
  <?php
  // Pre-resolve qty/price from context for delivery materialization in chips + all-MIs list
  $modalQtyVal   = $lineParsed !== null && $lineParsed['qty']       !== null ? (string)$lineParsed['qty']       : '';
  $modalPriceVal = $lineParsed !== null && $lineParsed['unitPrice']  !== null ? (string)$lineParsed['unitPrice'] : '';
  $modalBothKnown = $modalQtyVal !== '' && $modalPriceVal !== '';
  ?>
  <?php if (!empty($similar)): ?>
    <div class="mi-modal-similar">
      <div class="mi-modal-similar__label">MI similaires — cliquez pour créer un alias plutôt</div>
      <?php if (!$modalBothKnown): ?>
        <p class="mi-modal-similar__hint">
          OCR n'a pas extrait qty/prix — saisissez-les ci-dessous avant de choisir un MI,
          ou cochez «&nbsp;Alias seul&nbsp;» (pas de ligne Livraisons).
        </p>
        <div class="mi-modal-similar__qp-row">
          <label class="mi-modal-similar__qp-label">Qté&nbsp;*
            <input id="modal-chip-qty"   type="number" step="any" min="0.0001" placeholder="Qté"
                   class="mi-modal-similar__qp-input" required>
          </label>
          <label class="mi-modal-similar__qp-label">Prix unit.&nbsp;*
            <input id="modal-chip-price" type="number" step="any" min="0" placeholder="PU"
                   class="mi-modal-similar__qp-input" required>
          </label>
          <label class="mi-modal-similar__skip-label">
            <input id="modal-chip-skip" type="checkbox" class="modal-chip-skip-cb">
            Alias seul
          </label>
        </div>
      <?php endif ?>
      <div class="mi-modal-similar__chips">
        <?php foreach ($similar as $simId): ?>
          <form class="mi-modal-similar__chip-form" method="post"
                action="/api/triage/alias.php">
            <input type="hidden" name="csrf"         value="<?= htmlspecialchars(csrf_token()) ?>">
            <input type="hidden" name="rq_id"        value="<?= (int)$rqRow['id'] ?>">
            <input type="hidden" name="line_index"   value="<?= $lineIndex ?>">
            <input type="hidden" name="alias_text"   value="<?= htmlspecialchars($rawLineText) ?>">
            <input type="hidden" name="target_mi_id" value="<?= htmlspecialchars($simId) ?>">
            <?php if ($modalBothKnown): ?>
              <input type="hidden" name="qty"        value="<?= htmlspecialchars($modalQtyVal) ?>">
              <input type="hidden" name="unit_price" value="<?= htmlspecialchars($modalPriceVal) ?>">
            <?php else: ?>
              <!-- Filled by JS from #modal-chip-qty / #modal-chip-price -->
              <input type="hidden" name="qty"        class="chip-qty-hidden">
              <input type="hidden" name="unit_price" class="chip-price-hidden">
              <input type="hidden" name="skip_delivery" class="chip-skip-hidden" value="">
            <?php endif ?>
            <button type="submit" class="mi-modal-chip">
              <?= htmlspecialchars($simId) ?>
            </button>
          </form>
        <?php endforeach ?>
      </div>
    </div>
  <?php endif ?>

  <!-- Confidence + reasoning -->
  <div class="mi-modal-conf">
    <span class="mi-modal-conf__bar <?= $confClass ?>">
      Confiance <?= number_format($conf * 100, 0) ?>&nbsp;%
    </span>
    <details class="mi-modal-reasoning">
      <summary class="mi-modal-reasoning__toggle">Trace du moteur de proposition</summary>
      <ol class="mi-modal-reasoning__list">
        <?php foreach ($reasoning as $step): ?>
          <li><?= htmlspecialchars($step) ?></li>
        <?php endforeach ?>
      </ol>
    </details>
  </div>

  <!-- Create form -->
  <form class="mi-modal-form" method="post" action="/api/triage/create.php"
        autocomplete="off">
    <input type="hidden" name="csrf"       value="<?= htmlspecialchars(csrf_token()) ?>">
    <input type="hidden" name="rq_id"      value="<?= (int)$rqRow['id'] ?>">
    <input type="hidden" name="line_index" value="<?= $lineIndex ?>">
    <input type="hidden" name="raw_line_text" value="<?= htmlspecialchars($rawLineText) ?>">

    <!-- Hidden proposition fields for audit -->
    <input type="hidden" name="proposed_mi_id"         value="<?= htmlspecialchars($proposal['proposed_mi_id'] ?? '') ?>">
    <input type="hidden" name="proposed_category"      value="<?= htmlspecialchars($proposal['proposed_category'] ?? '') ?>">
    <input type="hidden" name="proposed_subcategory"   value="<?= htmlspecialchars($proposal['proposed_subcategory'] ?? '') ?>">
    <input type="hidden" name="proposed_account"       value="<?= htmlspecialchars($proposal['proposed_account'] ?? '') ?>">
    <input type="hidden" name="proposed_name"          value="<?= htmlspecialchars($proposal['proposed_name'] ?? '') ?>">
    <input type="hidden" name="proposition_confidence" value="<?= htmlspecialchars((string)$conf) ?>">
    <input type="hidden" name="similar_mi_ids_json"
           value="<?= htmlspecialchars(json_encode($similar, JSON_UNESCAPED_UNICODE)) ?>">

    <!-- Delivery qty / price / skip ─────────────────────────────────────── -->
    <div class="mi-modal-delivery-row">
      <label class="mi-modal-label" for="mi_create_qty">
        Qté<?= !$modalBothKnown ? ' <span class="mi-modal-req">*</span>' : '' ?>
        <?php if (!$modalBothKnown): ?>
          <span class="mi-modal-hint">OCR n'a pas extrait — saisir manuellement</span>
        <?php endif ?>
      </label>
      <input class="mi-modal-input mi-modal-input--num"
             type="number" id="mi_create_qty" name="qty" step="any" min="0.0001"
             value="<?= htmlspecialchars($modalQtyVal) ?>"
             placeholder="Qté"
             <?= !$modalBothKnown ? 'required id="mi_create_qty"' : '' ?>>

      <label class="mi-modal-label" for="mi_create_price">
        Prix unitaire<?= !$modalBothKnown ? ' <span class="mi-modal-req">*</span>' : '' ?>
      </label>
      <input class="mi-modal-input mi-modal-input--num"
             type="number" id="mi_create_price" name="unit_price" step="any" min="0"
             value="<?= htmlspecialchars($modalPriceVal) ?>"
             placeholder="PU"
             <?= !$modalBothKnown ? 'required' : '' ?>>

      <label class="mi-modal-delivery-skip">
        <input type="checkbox" name="skip_delivery" value="1"
               class="create-skip-delivery-cb" id="mi_create_skip">
        Ne pas créer de ligne Livraisons
        <span class="mi-modal-hint">(alias seul — immobilisation, TVA récupérable, taproom)</span>
      </label>
    </div>

    <div class="mi-modal-fields">

      <!-- MI_ID -->
      <div class="mi-modal-field mi-modal-field--full">
        <label class="mi-modal-label" for="mi_create_id">
          MI_ID <span class="mi-modal-req">*</span>
          <span class="mi-modal-hint">Majuscules, chiffres, underscores</span>
        </label>
        <input class="mi-modal-input"
               type="text" id="mi_create_id" name="mi_id"
               value="<?= htmlspecialchars($fillId) ?>"
               pattern="[A-Z0-9_]{2,64}"
               required
               placeholder="ex. HOPS_CITRA ou PKG_BOT_33CL_AMBER">
      </div>

      <!-- Name -->
      <div class="mi-modal-field mi-modal-field--full">
        <label class="mi-modal-label" for="mi_create_name">
          Nom <span class="mi-modal-req">*</span>
        </label>
        <input class="mi-modal-input"
               type="text" id="mi_create_name" name="name"
               value="<?= htmlspecialchars($fillName) ?>"
               required maxlength="255"
               placeholder="Nom lisible par l'opérateur">
      </div>

      <!-- Category -->
      <div class="mi-modal-field">
        <label class="mi-modal-label" for="mi_create_cat">
          Catégorie <span class="mi-modal-req">*</span>
        </label>
        <select class="mi-modal-select" id="mi_create_cat" name="category" required>
          <option value="">— choisir —</option>
          <?php foreach ($allCats as $cat): ?>
            <option value="<?= htmlspecialchars($cat['name']) ?>"
                    <?= $fillCat === $cat['name'] ? 'selected' : '' ?>>
              <?= htmlspecialchars($cat['name']) ?>
            </option>
          <?php endforeach ?>
          <option value="__NEW__">+ Nouvelle catégorie…</option>
        </select>
        <!-- Revealed when operator picks "+ Nouvelle catégorie" -->
        <input class="mi-modal-input mi-modal-new-cat-input" style="display:none;margin-top:6px"
               type="text" id="mi_create_cat_new" name="category_new"
               maxlength="64"
               placeholder="Nom de la nouvelle catégorie (ex. Maintenance)">
        <span class="mi-modal-hint" style="display:none" id="mi_create_cat_new_hint">
          Sera créée automatiquement. Utilisez des majuscules initiales.
        </span>
      </div>

      <!-- Subcategory -->
      <div class="mi-modal-field">
        <label class="mi-modal-label" for="mi_create_subcat">
          Sous-catégorie <span class="mi-modal-req">*</span>
          <span class="mi-modal-hint">Valeur libre si nouvelle</span>
        </label>
        <input class="mi-modal-input" list="mi_subcat_list"
               type="text" id="mi_create_subcat" name="subcategory"
               value="<?= htmlspecialchars($fillSubcat) ?>"
               required maxlength="64"
               placeholder="ex. Aroma ou Bottle">
        <datalist id="mi_subcat_list">
          <?php foreach ($allSubcats as $sc): ?>
            <option value="<?= htmlspecialchars($sc['name']) ?>">
          <?php endforeach ?>
        </datalist>
      </div>

      <!-- GL Account -->
      <div class="mi-modal-field">
        <label class="mi-modal-label" for="mi_create_acct">
          Compte GL <span class="mi-modal-req">*</span>
        </label>
        <input class="mi-modal-input" list="mi_acct_list"
               type="text" id="mi_create_acct" name="account"
               value="<?= htmlspecialchars($fillAcct) ?>"
               required maxlength="8"
               placeholder="ex. 4102">
        <datalist id="mi_acct_list">
          <?php foreach ($allAccounts as $acct): ?>
            <option value="<?= htmlspecialchars((string)$acct) ?>">
          <?php endforeach ?>
        </datalist>
      </div>

      <!-- Notes -->
      <div class="mi-modal-field mi-modal-field--full">
        <label class="mi-modal-label" for="mi_create_notes">
          Notes <span class="mi-modal-hint">(optionnel — justification si override)</span>
        </label>
        <input class="mi-modal-input"
               type="text" id="mi_create_notes" name="notes"
               maxlength="500"
               placeholder="ex. Identifié d'après la description TEA sur facture Univerre">
      </div>

    </div><!-- /mi-modal-fields -->

    <!-- Action buttons -->
    <div class="mi-modal-actions">
      <?php if ($proposal['proposed_mi_id'] !== null): ?>
        <!-- "As proposed" button — submit with a hidden flag; form JS-free: same values, just a label -->
        <button type="submit" name="_submit_mode" value="proposed"
                class="detail-btn detail-btn--create">
          Valider tel que proposé
        </button>
      <?php endif ?>
      <button type="submit" name="_submit_mode" value="modified"
              class="detail-btn detail-btn--create">
        Sauvegarder avec modifs
      </button>
      <a class="detail-btn detail-btn--cancel" href="<?= htmlspecialchars($backUrl) ?>">
        Annuler
      </a>
    </div>

  </form>

  <!-- All MIs for alias fallback (full list, read-only reference) -->
  <details class="mi-modal-milist">
    <summary class="mi-modal-milist__toggle">
      Tous les MIs actifs (<?= count($allMis) ?>) — pour alias manuel
    </summary>
    <?php if (!$modalBothKnown): ?>
      <div class="mi-modal-milist__qp-row">
        <label>Qté *
          <input id="milist-qty"   type="number" step="any" min="0.0001"
                 class="mi-modal-milist__qp-input" placeholder="Qté" required>
        </label>
        <label>Prix unit. *
          <input id="milist-price" type="number" step="any" min="0"
                 class="mi-modal-milist__qp-input" placeholder="PU" required>
        </label>
        <label class="mi-modal-milist__skip-label">
          <input id="milist-skip" type="checkbox" class="milist-skip-cb">
          Alias seul
        </label>
      </div>
    <?php endif ?>
    <div class="mi-modal-milist__body">
      <?php foreach ($allMis as $mi): ?>
        <form class="mi-modal-milist__row-form" method="post"
              action="/api/triage/alias.php">
          <input type="hidden" name="csrf"         value="<?= htmlspecialchars(csrf_token()) ?>">
          <input type="hidden" name="rq_id"        value="<?= (int)$rqRow['id'] ?>">
          <input type="hidden" name="line_index"   value="<?= $lineIndex ?>">
          <input type="hidden" name="alias_text"   value="<?= htmlspecialchars($rawLineText) ?>">
          <input type="hidden" name="target_mi_id" value="<?= htmlspecialchars($mi['mi_id']) ?>">
          <?php if ($modalBothKnown): ?>
            <input type="hidden" name="qty"        value="<?= htmlspecialchars($modalQtyVal) ?>">
            <input type="hidden" name="unit_price" value="<?= htmlspecialchars($modalPriceVal) ?>">
          <?php else: ?>
            <input type="hidden" name="qty"        class="milist-qty-hidden">
            <input type="hidden" name="unit_price" class="milist-price-hidden">
            <input type="hidden" name="skip_delivery" class="milist-skip-hidden" value="">
          <?php endif ?>
          <button type="submit" class="mi-modal-milist__btn">
            <span class="mi-modal-milist__id"><?= htmlspecialchars($mi['mi_id']) ?></span>
            <span class="mi-modal-milist__name"><?= htmlspecialchars((string)$mi['name']) ?></span>
          </button>
        </form>
      <?php endforeach ?>
    </div>
  </details>

</div><!-- /mi-create-modal -->
