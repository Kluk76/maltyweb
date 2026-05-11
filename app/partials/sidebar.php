<?php
declare(strict_types=1);
// $active_module — string identifying the current module (e.g. "wort", "home")
// Passed by the including page before this partial is required.
$active_module = $active_module ?? "";
$me            = $me ?? current_user() ?? [];

$modules = [
    ["01", "Procurement",     "Sourcing & receiving",        "#",                 "procurement"],
    ["02", "Wort Production", "Brewhouse & cooling",         "/modules/wort.php", "wort"],
    ["03", "Fermentation",    "CCT, BBT, dry-hop, racking",  "/modules/tanks.php", "fermentation"],
    ["04", "Packaging",       "Bottle, can, keg, cuv",       "#",                 "packaging"],
    ["05", "Fulfilment",      "Logistics & dispatch",        "#",                 "fulfilment"],
    ["06", "QA / QC",         "Lab, sensory, audit",         "#",                 "qa"],
    ["07", "SKU Costs",      "Coût par HL & BOM",           "/modules/sku-costs.php", "sku-costs"],
];

// Admin entries — gated per-user so the block disappears entirely for
// non-admin/non-manager (no empty container in the DOM).
$showAdminBlock = is_admin($me) || is_manager($me);
$adminEntries   = [];
if ($showAdminBlock) {
    $adminEntries[] = ["S",  "Paramètres", "Recettes, SKU, cuves, suppliers", "/admin/settings.php",   "settings"];
}
if (is_admin($me)) {
    $adminEntries[] = ["DB", "DB Browser", "Inspection lecture seule",        "/admin/db-browser.php", "db-browser"];
}
?>
<aside class="side" aria-label="Navigation principale">
  <div class="side__brand">
    <span class="mark">M<span class="mark__t">T</span></span>
    <span class="mark__sub">MaltyTask</span>
  </div>

  <nav class="nav" aria-label="Modules">
    <div class="nav__label">— modules</div>
    <ul>
      <?php foreach ($modules as [$idx, $name, $sub, $href, $key]): ?>
        <?php $isActive = ($key === $active_module); ?>
        <li>
          <a class="nav__item<?= $isActive ? ' nav__item--active' : '' ?>"
             href="<?= htmlspecialchars($href) ?>"
             <?= $isActive ? 'aria-current="page"' : '' ?>>
            <span class="nav__idx"><?= htmlspecialchars($idx) ?></span>
            <span class="nav__body">
              <span class="nav__name"><?= htmlspecialchars($name) ?></span>
              <span class="nav__sub"><?= htmlspecialchars($sub) ?></span>
            </span>
            <span class="nav__chev" aria-hidden="true">→</span>
          </a>
        </li>
      <?php endforeach ?>
    </ul>
  </nav>

  <?php if ($showAdminBlock): ?>
    <nav class="nav nav--admin" aria-label="Admin">
      <div class="nav__label">— admin</div>
      <ul>
        <?php foreach ($adminEntries as [$idx, $name, $sub, $href, $key]): ?>
          <?php $isActive = ($key === $active_module); ?>
          <li>
            <a class="nav__item nav__item--admin<?= $isActive ? ' nav__item--active' : '' ?>"
               href="<?= htmlspecialchars($href) ?>"
               <?= $isActive ? 'aria-current="page"' : '' ?>>
              <span class="nav__idx nav__idx--admin"><?= htmlspecialchars($idx) ?></span>
              <span class="nav__body">
                <span class="nav__name"><?= htmlspecialchars($name) ?></span>
                <span class="nav__sub"><?= htmlspecialchars($sub) ?></span>
              </span>
              <span class="nav__chev" aria-hidden="true">→</span>
            </a>
          </li>
        <?php endforeach ?>
      </ul>
    </nav>
  <?php endif ?>

  <footer class="side__foot">
    <span class="side__org">la nébuleuse</span>
    <span class="side__ver">v0.1 · 2026</span>
  </footer>
</aside>
