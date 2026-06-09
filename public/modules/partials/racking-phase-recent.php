<?php
declare(strict_types=1);
/**
 * racking-phase-recent.php — Recent submissions table for racking.
 *
 * Rendered OUTSIDE the <form> element, matching the form-racking.php structure
 * where the recent table follows the closing </form> tag.
 * Inherits scope: $recentRackings.
 */
?>

<!-- ── Recent submissions ─────────────────────────────────────────────────── -->
<div class="op-form__recent">
  <div class="op-form__recent-title">— saisies récentes (web)</div>
  <?php if (empty($recentRackings)): ?>
    <p class="op-form__muted" style="font-size:0.82rem;">Aucune saisie web pour le moment.</p>
  <?php else: ?>
    <table class="op-form__recent-table">
      <thead>
        <tr>
          <th>Date</th>
          <th>Bière</th>
          <th>Brassin</th>
          <th>Type</th>
          <th>Destination</th>
          <th>Vol (HL)</th>
          <th>QC</th>
          <th>Opérateur</th>
          <th>Override</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($recentRackings as $r): ?>
          <?php
            $beerLabel  = ($r['neb_beer'] ?? '') !== '' ? $r['neb_beer'] : ($r['contract_beer'] ?? '—');
            $batchLabel = ($r['neb_batch'] ?? '') !== '' ? $r['neb_batch'] : ($r['contract_batch'] ?? '—');
            $flags      = $r['audit_flags'] ?? '';
            $isHorsProc = str_contains($flags, 'hors_process_override');
            $isOutlier  = str_contains($flags, 'qc_outlier');
            $isElevated = str_contains($flags, 'qc_elevated');
            $qc         = $isOutlier ? 'outlier' : ($isElevated ? 'elevated' : 'normal');
            $hpFlag     = (bool)($r['hors_process_flag'] ?? false);
          ?>
          <tr>
            <td class="op-form__mono"><?= htmlspecialchars($r['event_date'] ?? '') ?></td>
            <td><?= htmlspecialchars($beerLabel) ?></td>
            <td class="op-form__mono"><?= htmlspecialchars($batchLabel) ?></td>
            <td><?= htmlspecialchars($r['rack_type'] ?? '—') ?></td>
            <td class="op-form__mono"><?= htmlspecialchars($r['target_tank_raw'] ?? '—') ?></td>
            <td class="op-form__mono"><?= $r['racked_vol_hl'] !== null ? htmlspecialchars((string)$r['racked_vol_hl']) : '—' ?></td>
            <td><span class="op-form__qc-badge op-form__qc-badge--<?= $qc ?>"><?= $qc ?></span></td>
            <td class="op-form__mono"><?= htmlspecialchars($r['operator_display'] ?? $r['email'] ?? '') ?></td>
            <td>
              <?php if ($hpFlag || $isHorsProc): ?>
                <span class="rf-hp-badge" title="<?= htmlspecialchars($r['hors_process_reason'] ?? '') ?>">HORS PROCESS</span>
              <?php else: ?>
                <span class="rf-hp-badge rf-hp-badge--normal">—</span>
              <?php endif ?>
            </td>
          </tr>
        <?php endforeach ?>
      </tbody>
    </table>
  <?php endif ?>
</div>
