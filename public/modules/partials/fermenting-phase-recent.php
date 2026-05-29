<?php
declare(strict_types=1);
/**
 * fermenting-phase-recent.php — Recent submissions table for fermenting.
 *
 * Rendered OUTSIDE the <form> element by session-body-fermenting.php,
 * matching the form-fermenting.php structure where the recent table follows
 * the closing </form> tag. Mirrors racking-phase-recent.php shape exactly.
 *
 * Inherits scope: $recentFerm (array of last 10 bd_fermenting_v2 web rows)
 */
?>

<!-- ── Recent submissions ─────────────────────────────────────────────────── -->
<div class="op-form__recent">
  <div class="op-form__recent-title">— saisies récentes (web)</div>
  <?php if (empty($recentFerm)): ?>
    <p class="op-form__muted ferm-empty-note">Aucune saisie web pour le moment.</p>
  <?php else: ?>
    <table class="op-form__recent-table">
      <thead>
        <tr>
          <th>Date</th>
          <th>Type</th>
          <th>Bière</th>
          <th>Brassin</th>
          <th>Densité °P</th>
          <th>pH</th>
          <th>Temp °C</th>
          <th>Opérateur</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($recentFerm as $rf): ?>
          <tr>
            <td class="op-form__mono"><?= htmlspecialchars($rf['event_date'] ?? '') ?></td>
            <td>
              <span class="ferm-event-badge ferm-event-badge--<?= htmlspecialchars(strtolower($rf['event_type'] ?? '')) ?>">
                <?= htmlspecialchars($rf['event_type'] ?? '') ?>
              </span>
            </td>
            <td><?= htmlspecialchars($rf['beer_raw'] ?? '') ?></td>
            <td class="op-form__mono"><?= htmlspecialchars($rf['batch'] ?? '') ?></td>
            <td class="op-form__mono"><?= $rf['gravity'] !== null ? htmlspecialchars($rf['gravity']) . '°P' : '—' ?></td>
            <td class="op-form__mono"><?= $rf['ph']      !== null ? htmlspecialchars($rf['ph'])      : '—' ?></td>
            <td class="op-form__mono"><?= $rf['temperature'] !== null ? htmlspecialchars($rf['temperature']) . '°C' : '—' ?></td>
            <td class="op-form__mono"><?= htmlspecialchars($rf['email'] ?? '') ?></td>
          </tr>
        <?php endforeach ?>
      </tbody>
    </table>
  <?php endif ?>
</div>
