<?php
declare(strict_types=1);
/**
 * fermenting-phase-recent.php — Per-session grouped recap for fermenting (P-C).
 *
 * Rendered OUTSIDE the <form> element by session-body-fermenting.php,
 * matching the form-fermenting.php structure where the recent block follows
 * the closing </form> tag.
 *
 * Inherits scope (from form-fermenting.php GET path):
 *   $recentSessions   — array of last 5 op_sessions (form_type='fermenting')
 *   $sessionEvents    — array keyed by session_id_fk → array of bd_fermenting_v2 rows
 *   $recentHistorical — array of last 20 bd_fermenting_v2 rows with session_id_fk IS NULL
 *
 * Sessions are ordered by opened_at DESC.  Within each session, events are ordered
 * chronologically (submitted_at ASC) so the operator sees the fermentation arc
 * from first Reads to ColdCrash.  Historical rows (pre-P-C, no session FK) are
 * grouped under a "Sessions historiques" section rendered after the sessions list.
 */

// Helper: format event_date for display (Y-m-d → d.m.Y)
function _ferm_recent_fmt_date(string $d): string {
    $ts = strtotime($d);
    return $ts !== false ? date('d.m.Y', $ts) : htmlspecialchars($d);
}

// Badge CSS suffix from event_type
function _ferm_recent_badge_class(string $type): string {
    return match ($type) {
        'Reads'     => 'reads',
        'DryHop'    => 'dryhop',
        'Purge'     => 'purge',
        'ColdCrash' => 'coldcrash',
        default     => 'reads',
    };
}

// Status pill CSS suffix
function _ferm_recent_status_class(string $phase, string $status): string {
    if ($status === 'closed')    return 'closed';
    if ($status === 'abandoned') return 'abandoned';
    if ($phase  === 'end')       return 'end';
    if ($phase  === 'in_progress') return 'inprog';
    return 'start';
}

function _ferm_recent_status_label(string $phase, string $status): string {
    if ($status === 'closed')    return 'Clôturée';
    if ($status === 'abandoned') return 'Abandonnée';
    return match ($phase) {
        'end'         => 'Cold Crash ✓',
        'in_progress' => 'En cours',
        'start'       => 'Ouverte',
        default       => $phase,
    };
}
?>

<!-- ── Recent fermentation sessions ──────────────────────────────────────── -->
<div class="op-form__recent ferm-recent">
  <div class="op-form__recent-title">— sessions récentes (fermentation)</div>

  <?php if (empty($recentSessions) && empty($recentHistorical)): ?>
    <p class="op-form__muted ferm-empty-note">Aucune saisie web pour le moment.</p>
  <?php else: ?>

    <!-- ── Per-session grouped list ───────────────────────────────────────── -->
    <?php if (!empty($recentSessions)): ?>
      <div class="ferm-session-list">
        <?php foreach ($recentSessions as $sess):
          $sid      = (int)$sess['id'];
          $phase    = (string)($sess['phase']  ?? 'start');
          $status   = (string)($sess['status'] ?? 'open');
          $batch    = (string)($sess['batch']  ?? '');
          $recName  = (string)($sess['recipe_name'] ?? '');
          $recShort = (string)($sess['recipe_short_name'] ?? '');
          $beerLabel = $recShort !== '' ? $recShort : $recName;
          $events   = $sessionEvents[$sid] ?? [];
          $statusCls  = _ferm_recent_status_class($phase, $status);
          $statusLabel = _ferm_recent_status_label($phase, $status);

          // Determine first event date and last event date from events
          $firstDate = !empty($events) ? _ferm_recent_fmt_date((string)($events[0]['event_date']   ?? '')) : '—';
          $lastDate  = !empty($events) ? _ferm_recent_fmt_date((string)(end($events)['event_date'] ?? '')) : '—';
          $eventCount = count($events);
        ?>
          <details class="ferm-session-group" open>
            <summary class="ferm-session-summary">
              <div class="ferm-session-meta">
                <span class="ferm-session-beer">
                  <?php if ($beerLabel !== ''): ?>
                    <strong><?= htmlspecialchars($beerLabel) ?></strong>
                  <?php else: ?>
                    <span class="ferm-muted">Recette inconnue</span>
                  <?php endif ?>
                  <?php if ($batch !== ''): ?>
                    <span class="ferm-session-batch">Brassin <?= htmlspecialchars($batch) ?></span>
                  <?php endif ?>
                </span>
                <span class="ferm-session-dates">
                  <?= htmlspecialchars($firstDate) ?><?= ($firstDate !== $lastDate) ? ' → ' . htmlspecialchars($lastDate) : '' ?>
                </span>
                <span class="ferm-status-pill ferm-status-pill--<?= $statusCls ?>">
                  <?= htmlspecialchars($statusLabel) ?>
                </span>
                <span class="ferm-session-count"><?= $eventCount ?> évènement<?= $eventCount !== 1 ? 's' : '' ?></span>
              </div>
            </summary>

            <?php if (!empty($events)): ?>
              <table class="op-form__recent-table ferm-session-table">
                <thead>
                  <tr>
                    <th>Date</th>
                    <th>Type</th>
                    <th>Densité °P</th>
                    <th>pH</th>
                    <th>Temp °C</th>
                    <th>Houblon / Note</th>
                    <th>Opérateur</th>
                    <th></th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($events as $ev):
                    $evType  = (string)($ev['event_type']  ?? '');
                    $evDate  = (string)($ev['event_date']  ?? '');
                    $dhNote  = '';
                    if ($evType === 'DryHop' && ($ev['dh_raw_name'] ?? '') !== '') {
                        $qty  = $ev['dh_qty']      ?? '';
                        $unit = $ev['dh_unit']     ?? '';
                        $dhNote = $ev['dh_raw_name'] . ($qty !== '' ? " ({$qty}{$unit})" : '');
                    } elseif ($evType === 'Purge' && ($ev['comment_purge'] ?? '') !== '') {
                        $dhNote = $ev['comment_purge'];
                    } elseif ($evType === 'ColdCrash' && ($ev['comment_cold_crash'] ?? '') !== '') {
                        $dhNote = $ev['comment_cold_crash'];
                    } elseif (($ev['final_comments'] ?? '') !== '') {
                        $dhNote = $ev['final_comments'];
                    }
                  ?>
                    <tr>
                      <td class="op-form__mono"><?= htmlspecialchars($evDate !== '' ? _ferm_recent_fmt_date($evDate) : '—') ?></td>
                      <td>
                        <span class="ferm-event-badge ferm-event-badge--<?= htmlspecialchars(_ferm_recent_badge_class($evType)) ?>">
                          <?= htmlspecialchars($evType) ?>
                        </span>
                      </td>
                      <td class="op-form__mono"><?= ($ev['gravity']     !== null) ? htmlspecialchars((string)$ev['gravity'])     . '°P' : '—' ?></td>
                      <td class="op-form__mono"><?= ($ev['ph']          !== null) ? htmlspecialchars((string)$ev['ph'])          : '—' ?></td>
                      <td class="op-form__mono"><?= ($ev['temperature'] !== null) ? htmlspecialchars((string)$ev['temperature']) . '°C' : '—' ?></td>
                      <td class="ferm-recent-note"><?= htmlspecialchars(mb_strimwidth($dhNote, 0, 60, '…')) ?></td>
                      <td class="op-form__mono ferm-recent-email"><?= htmlspecialchars($ev['operator_display'] ?? $ev['email'] ?? '') ?></td>
                      <td class="ferm-recent-action">
                        <a href="/modules/form-fermenting.php?edit=<?= (int)$ev['id'] ?>"
                           class="ferm-recent-corriger">Corriger</a>
                      </td>
                    </tr>
                  <?php endforeach ?>
                </tbody>
              </table>
            <?php else: ?>
              <p class="ferm-session-empty">Aucun évènement enregistré dans cette session.</p>
            <?php endif ?>
          </details>
        <?php endforeach ?>
      </div>
    <?php endif ?>

    <!-- ── Historical rows (pre-P-C, no session_id_fk) ───────────────────── -->
    <?php if (!empty($recentHistorical)): ?>
      <details class="ferm-session-group ferm-session-group--historical">
        <summary class="ferm-session-summary">
          <div class="ferm-session-meta">
            <span class="ferm-session-beer"><strong>Sessions historiques</strong></span>
            <span class="ferm-session-dates">avant P-C · sans session liée</span>
            <span class="ferm-status-pill ferm-status-pill--historical">Héritage</span>
            <span class="ferm-session-count"><?= count($recentHistorical) ?> entrée<?= count($recentHistorical) !== 1 ? 's' : '' ?></span>
          </div>
        </summary>
        <table class="op-form__recent-table ferm-session-table">
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
              <th></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($recentHistorical as $rf):
              $rfType = (string)($rf['event_type'] ?? '');
            ?>
              <tr>
                <td class="op-form__mono"><?= htmlspecialchars(_ferm_recent_fmt_date((string)($rf['event_date'] ?? ''))) ?></td>
                <td>
                  <span class="ferm-event-badge ferm-event-badge--<?= htmlspecialchars(_ferm_recent_badge_class($rfType)) ?>">
                    <?= htmlspecialchars($rfType) ?>
                  </span>
                </td>
                <td><?= htmlspecialchars($rf['beer_raw'] ?? '') ?></td>
                <td class="op-form__mono"><?= htmlspecialchars($rf['batch'] ?? '') ?></td>
                <td class="op-form__mono"><?= ($rf['gravity']     !== null) ? htmlspecialchars((string)$rf['gravity'])     . '°P' : '—' ?></td>
                <td class="op-form__mono"><?= ($rf['ph']          !== null) ? htmlspecialchars((string)$rf['ph'])          : '—' ?></td>
                <td class="op-form__mono"><?= ($rf['temperature'] !== null) ? htmlspecialchars((string)$rf['temperature']) . '°C' : '—' ?></td>
                <td class="op-form__mono ferm-recent-email"><?= htmlspecialchars($rf['operator_display'] ?? $rf['email'] ?? '') ?></td>
                <td class="ferm-recent-action">
                  <a href="/modules/form-fermenting.php?edit=<?= (int)$rf['id'] ?>"
                     class="ferm-recent-corriger">Corriger</a>
                </td>
              </tr>
            <?php endforeach ?>
          </tbody>
        </table>
      </details>
    <?php endif ?>

  <?php endif ?>
</div>
