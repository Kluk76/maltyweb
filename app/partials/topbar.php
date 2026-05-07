<?php
declare(strict_types=1);
// $crumbs — array of breadcrumb label strings, e.g. ["Accueil", "Wort Production"]
// $me     — current_user() array, must be set by including page
$crumbs = $crumbs ?? ["Accueil"];
$me     = $me     ?? [];
?>
<header class="top">
  <div class="top__crumbs">
    <?php
    $last = count($crumbs) - 1;
    foreach ($crumbs as $i => $crumb):
      if ($i < $last): ?>
        <a class="top__crumb" href="/"><?= htmlspecialchars($crumb) ?></a>
        <span class="top__sep" aria-hidden="true"> / </span>
      <?php else: ?>
        <span class="top__here"><?= htmlspecialchars($crumb) ?></span>
      <?php endif;
    endforeach ?>
  </div>
  <div class="top__user">
    <span class="user__name"><?= htmlspecialchars($me["display_name"] ?? "") ?></span>
    <span class="user__sep">·</span>
    <span class="user__role"><?= htmlspecialchars($me["role"] ?? "") ?></span>
    <span class="user__sep">·</span>
    <a class="user__out" href="/logout.php">Déconnexion</a>
  </div>
</header>
