<?php
declare(strict_types=1);
/**
 * app/kpi-email-render.php — Email-safe KPI tile renderer for send-kpi-recap.php
 *
 * Email HTML: inline CSS is REQUIRED here — the no-inline-CSS house rule does NOT
 * apply to email bodies (external stylesheets are stripped by mail clients).
 *
 * DIVERGENCE GUARD: This module MUST NEVER query the DB, recompute any value,
 * or accept a $pdo argument. It renders $result as-returned by kpi_dispatch().
 * If a value isn't in $result, it is not shown — never computed here.
 */

/**
 * Render an email-safe HTML tile for one KPI tracker result.
 *
 * @param array $tracker  Row from ref_kpi_trackers (slug, label, viz_type, …)
 * @param array $result   Return value of kpi_dispatch($tracker, $pdo)
 * @return string         Email-safe HTML string (inline CSS, table-based layout)
 */
function kpi_email_render_viz(array $tracker, array $result): string
{
    $vizType = $tracker['viz_type'] ?? 'scalar';

    // ── Recap viz: sectioned digest with metric strip + per-beer/run bars ─────
    if ($vizType === 'recap') {
        $breakdown = $result['breakdown'] ?? [];
        $meta      = $result['meta'] ?? [];
        $sections  = $meta['sections'] ?? [];

        // Graceful degrade: if no breakdown with usable rows or no sections → scalar card
        $hasBreakdown = !empty($breakdown) && !empty($sections);
        if (!$hasBreakdown) {
            // Fall through to default scalar rendering below
        } else {
            return _kpi_render_recap_sectioned($tracker, $result);
        }
    }

    // ── Dispatch to typed renderers ──────────────────────────────────────────
    switch ($vizType) {
        case 'sparkline':    return _kpi_render_sparkline($tracker, $result);
        case 'line':         return _kpi_render_line($tracker, $result);
        case 'bar':          return _kpi_render_bar($tracker, $result);
        case 'grouped_bar':      return _kpi_render_grouped_bar($tracker, $result);
        case 'stacked_bar':      return _kpi_render_stacked_bar($tracker, $result);
        case 'stacked_columns':  return _kpi_render_stacked_columns($tracker, $result);
        case 'donut':        return _kpi_render_donut($tracker, $result);
        case 'flag':         return _kpi_render_flag($tracker, $result);
        case 'table':        return _kpi_render_table($tracker, $result);
        case 'waterfall':    return _kpi_render_waterfall($tracker, $result);
    }

    // ── Default scalar card ───────────────────────────────────────────────────
    return _kpi_render_scalar($tracker, $result);
}

// ── Internal: sectioned digest renderer ──────────────────────────────────────

function _kpi_render_recap_sectioned(array $tracker, array $result): string
{
    $meta        = $result['meta'] ?? [];
    $sections    = $meta['sections'] ?? [];
    $breakdown   = $result['breakdown'] ?? [];
    $periodLabel = $meta['period_label'] ?? null;
    $label       = htmlspecialchars($tracker['label'] ?? '', ENT_QUOTES, 'UTF-8');

    // ── 1. Domain title row ───────────────────────────────────────────────────
    $periodSpan = '';
    if ($periodLabel !== null && $periodLabel !== '') {
        $pl = htmlspecialchars((string) $periodLabel, ENT_QUOTES, 'UTF-8');
        $periodSpan = '<td align="right" style="font-family:\'DM Sans\',Arial,sans-serif;font-size:11px;color:#9a8f82;white-space:nowrap;">' . $pl . '</td>';
    }

    $titleRow = '
    <table cellpadding="0" cellspacing="0" border="0" width="100%">
      <tr>
        <td style="background:#2c2414;padding:10px 16px;">
          <table cellpadding="0" cellspacing="0" border="0" width="100%">
            <tr>
              <td style="font-family:\'JetBrains Mono\',\'Courier New\',monospace;font-size:11px;letter-spacing:.08em;text-transform:uppercase;color:#f1e8d4;">'
                  . $label .
              '</td>'
              . $periodSpan .
            '</tr>
          </table>
        </td>
      </tr>
    </table>';

    // ── 2. Metric strip ───────────────────────────────────────────────────────
    $chipCount = count($sections);
    $chipCells = '';
    foreach ($sections as $i => $section) {
        $sLabel = htmlspecialchars((string) ($section['label'] ?? ''), ENT_QUOTES, 'UTF-8');
        $sUnit  = htmlspecialchars((string) ($section['unit'] ?? ''), ENT_QUOTES, 'UTF-8');
        $sVal   = $section['value'] ?? null;
        $sTint  = $section['tint'] ?? 'neutral';

        $tintColor = match ($sTint) {
            'green'  => '#5a8a5a',
            'amber'  => '#a07020',
            'red'    => '#a04040',
            default  => '#2c2414',
        };

        // Loss metrics need 2-decimal precision (e.g. "0,05 %" not "0,1 %")
        $isLossMetric = (bool) preg_match('/perte/i', $section['label'] ?? '');

        if ($sVal === null) {
            $valHtml = '<span style="color:#9a8f82;">—</span>';
        } elseif (is_float($sVal)) {
            $decimals = $isLossMetric ? 2 : 1;
            $valHtml = htmlspecialchars(number_format($sVal, $decimals, ',', ' '), ENT_QUOTES, 'UTF-8');
        } elseif (is_int($sVal)) {
            $valHtml = htmlspecialchars(number_format($sVal, 0, ',', ' '), ENT_QUOTES, 'UTF-8');
        } else {
            $valHtml = htmlspecialchars((string) $sVal, ENT_QUOTES, 'UTF-8');
        }

        $unitHtml = $sUnit !== '' ? '<span style="font-size:10px;color:#9a8f82;margin-left:3px;">' . $sUnit . '</span>' : '';

        $isLast       = ($i === $chipCount - 1);
        $borderRight  = $isLast ? '' : 'border-right:1px solid #e8dcc6;';
        $chipWidth    = $chipCount > 0 ? (int) round(100 / $chipCount) . '%' : '25%';

        $chipCells .= '<td width="' . $chipWidth . '" style="padding:8px 6px;text-align:center;' . $borderRight . '">
          <div style="font-family:\'DM Sans\',Arial,sans-serif;font-size:10px;text-transform:uppercase;letter-spacing:.06em;color:#9a8f82;margin-bottom:3px;">' . $sLabel . '</div>
          <div style="font-family:\'JetBrains Mono\',\'Courier New\',monospace;font-size:14px;color:' . $tintColor . ';">' . $valHtml . $unitHtml . '</div>
        </td>';
    }

    $metricStrip = '
    <table cellpadding="0" cellspacing="0" border="0" width="100%" bgcolor="#fdf7ee" style="background:#fdf7ee;border-top:1px solid #d8cbb8;border-bottom:1px solid #d8cbb8;">
      <tr>' . $chipCells . '</tr>
    </table>';

    // ── 3. Granular breakdown groups ──────────────────────────────────────────

    // Detect domain
    $hasRunType = false;
    $hasType    = false;
    foreach ($breakdown as $row) {
        $rowMeta = $row['meta'] ?? [];
        if (isset($rowMeta['run_type'])) { $hasRunType = true; }
        if (isset($rowMeta['type']))     { $hasType    = true; }
    }

    // Filter: skip material rows (no run_type AND no type — mat_ rows)
    $renderRows = array_filter($breakdown, function (array $row) {
        $rowMeta = $row['meta'] ?? [];
        return isset($rowMeta['run_type']) || isset($rowMeta['type']);
    });
    $renderRows = array_values($renderRows);

    // Cap at 20 render rows (prevents runaway emails on high-volume recap periods)
    $renderExtra = count($renderRows) > 20 ? count($renderRows) - 20 : 0;
    if ($renderExtra > 0) {
        $renderRows = array_slice($renderRows, 0, 20);
    }

    // Compute domainMax (for bar scaling) across all render rows
    $domainMax = 0;
    foreach ($renderRows as $row) {
        $v = $row['value'] ?? null;
        if (is_numeric($v)) {
            $domainMax = max($domainMax, (float) $v);
        }
    }
    if ($domainMax <= 0) { $domainMax = 1; }

    // Group rows
    if ($hasRunType) {
        // PACKAGING grouping
        $groupOrder  = ['keg', 'cuv', 'bot', 'cage', 'can'];
        $groupLabels = ['keg' => 'Fût', 'cuv' => 'Cuve', 'bot' => 'Bouteille', 'cage' => 'Cage', 'can' => 'Canette'];
        $groups      = [];
        foreach ($renderRows as $row) {
            $rt = $row['meta']['run_type'] ?? 'autre';
            if (!in_array($rt, $groupOrder, true)) { $rt = 'autre'; }
            $groups[$rt][] = $row;
        }
        // Sort within each group desc by value
        foreach ($groups as &$grpRows) {
            usort($grpRows, fn($a, $b) => ($b['value'] ?? 0) <=> ($a['value'] ?? 0));
        }
        unset($grpRows);

        // Build ordered group list
        $orderedGroups = [];
        foreach ($groupOrder as $gk) {
            if (isset($groups[$gk])) {
                $orderedGroups[$gk] = ['label' => $groupLabels[$gk], 'rows' => $groups[$gk]];
            }
        }
        if (isset($groups['autre'])) {
            $orderedGroups['autre'] = ['label' => 'Autre', 'rows' => $groups['autre']];
        }
    } else {
        // PRODUCTION grouping
        $groupOrder  = ['brew', 'rack', 'dryhop', 'coldcrash', 'quality'];
        $groupLabels = [
            'brew'      => 'Brassins',
            'rack'      => 'Transferts',
            'dryhop'    => 'Dry-hop',
            'coldcrash' => 'Cold-crash',
            'quality'   => 'Qualité moût',
        ];
        $groups = [];
        foreach ($renderRows as $row) {
            $tp = $row['meta']['type'] ?? 'autre';
            if (!in_array($tp, $groupOrder, true)) { $tp = 'autre'; }
            $groups[$tp][] = $row;
        }
        // Sort within each group desc by value (nulls last)
        foreach ($groups as &$grpRows) {
            usort($grpRows, function ($a, $b) {
                $av = $a['value'] ?? null;
                $bv = $b['value'] ?? null;
                if ($av === null && $bv === null) return 0;
                if ($av === null) return 1;
                if ($bv === null) return -1;
                return $bv <=> $av;
            });
        }
        unset($grpRows);

        $orderedGroups = [];
        foreach ($groupOrder as $gk) {
            if (isset($groups[$gk])) {
                $orderedGroups[$gk] = ['label' => $groupLabels[$gk], 'rows' => $groups[$gk]];
            }
        }
        if (isset($groups['autre'])) {
            $orderedGroups['autre'] = ['label' => 'Autre', 'rows' => $groups['autre']];
        }
    }

    // Render groups HTML
    $groupsHtml = '';
    $firstGroup = true;
    foreach ($orderedGroups as $gk => $grp) {
        $borderTop   = $firstGroup ? '' : 'border-top:1px solid #e8dcc6;margin-top:4px;';
        $firstGroup  = false;
        $groupHeader = '<div style="font-family:\'DM Sans\',Arial,sans-serif;font-size:10px;letter-spacing:.1em;text-transform:uppercase;color:#9a8f82;padding:6px 0 3px;' . $borderTop . '">'
            . htmlspecialchars($grp['label'], ENT_QUOTES, 'UTF-8') . '</div>';

        $rowsHtml = '';
        foreach ($grp['rows'] as $row) {
            $rowMeta   = $row['meta'] ?? [];
            $rowLabel  = htmlspecialchars((string) ($row['label'] ?? ''), ENT_QUOTES, 'UTF-8');
            $rowValue  = $row['value'] ?? null;
            $rowUnit   = htmlspecialchars((string) ($row['unit'] ?? ''), ENT_QUOTES, 'UTF-8');

            // Value display
            if ($rowValue === null) {
                $rowValueHtml = '<span style="color:#9a8f82;">—</span>';
                $barPct       = 0;
            } elseif (is_float($rowValue)) {
                $rowValueHtml = htmlspecialchars(number_format($rowValue, 1, ',', ' '), ENT_QUOTES, 'UTF-8');
                $barPct = max(1, (int) round((float) $rowValue / $domainMax * 100));
            } elseif (is_int($rowValue)) {
                $rowValueHtml = htmlspecialchars(number_format($rowValue, 0, ',', ' '), ENT_QUOTES, 'UTF-8');
                $barPct = max(1, (int) round((float) $rowValue / $domainMax * 100));
            } else {
                $rowValueHtml = htmlspecialchars((string) $rowValue, ENT_QUOTES, 'UTF-8');
                $barPct = 0;
            }
            $barPct = min(100, $barPct);

            // Bar HTML
            if ($barPct <= 0) {
                $barHtml = '<table cellpadding="0" cellspacing="0" border="0" width="100%"><tr><td bgcolor="#e8dcc6" style="background:#e8dcc6;height:8px;" width="100%"></td></tr></table>';
            } elseif ($barPct >= 100) {
                $barHtml = '<table cellpadding="0" cellspacing="0" border="0" width="100%"><tr><td bgcolor="#9eb060" style="background:#9eb060;height:8px;border-radius:2px 0 0 2px;" width="100%"></td></tr></table>';
            } else {
                $remPct  = 100 - $barPct;
                $barHtml = '<table cellpadding="0" cellspacing="0" border="0" width="100%"><tr>'
                    . '<td bgcolor="#9eb060" style="background:#9eb060;height:8px;border-radius:2px 0 0 2px;" width="' . $barPct . '%"></td>'
                    . '<td bgcolor="#e8dcc6" style="background:#e8dcc6;height:8px;" width="' . $remPct . '%"></td>'
                    . '</tr></table>';
            }

            // Context suffix line
            $contextHtml = '';
            if ($hasRunType) {
                // PACKAGING context
                $batch    = isset($rowMeta['batch']) ? htmlspecialchars((string) $rowMeta['batch'], ENT_QUOTES, 'UTF-8') : '—';
                $reachPct = $rowMeta['reach_pct'] ?? null;
                $lossPct  = $rowMeta['loss_pct'] ?? null;
                $ctx      = 'lot ' . $batch;
                if ($reachPct !== null) {
                    $ctx .= ' · → ' . htmlspecialchars(number_format((float) $reachPct, 1, ',', ' '), ENT_QUOTES, 'UTF-8') . '% obj';
                }
                if ($lossPct !== null && (float) $lossPct > 0) {
                    $ctx .= ' · ' . htmlspecialchars(number_format((float) $lossPct, 1, ',', ' '), ENT_QUOTES, 'UTF-8') . '% perte';
                }
                $contextHtml = '<div style="font-family:\'DM Sans\',Arial,sans-serif;font-size:11px;color:#9a8f82;padding-left:2px;margin-top:1px;">' . $ctx . '</div>';
            } else {
                // PRODUCTION context
                $rowType = $rowMeta['type'] ?? '';
                if ($rowType === 'quality') {
                    $ogPlato   = $rowMeta['og_plato'] ?? null;
                    $phMout    = $rowMeta['ph_mout'] ?? null;
                    $durationH = $rowMeta['duration_h'] ?? null;
                    $parts     = [];
                    if ($ogPlato !== null) {
                        $parts[] = 'OG: ' . htmlspecialchars(number_format((float) $ogPlato, 1, ',', ' '), ENT_QUOTES, 'UTF-8') . '°P';
                    }
                    if ($phMout !== null) {
                        $parts[] = 'pH: ' . htmlspecialchars(number_format((float) $phMout, 1, ',', ' '), ENT_QUOTES, 'UTF-8');
                    }
                    if ($durationH !== null) {
                        $parts[] = htmlspecialchars(number_format((float) $durationH, 1, ',', ' '), ENT_QUOTES, 'UTF-8') . 'h';
                    }
                    if (!empty($parts)) {
                        $contextHtml = '<div style="font-family:\'DM Sans\',Arial,sans-serif;font-size:11px;color:#9a8f82;padding-left:2px;margin-top:1px;">' . implode(' · ', $parts) . '</div>';
                    }
                } else {
                    $batch          = isset($rowMeta['batch']) ? htmlspecialchars((string) $rowMeta['batch'], ENT_QUOTES, 'UTF-8') : '—';
                    $classification = isset($rowMeta['classification']) ? htmlspecialchars((string) $rowMeta['classification'], ENT_QUOTES, 'UTF-8') : null;
                    $ctx            = 'lot ' . $batch;
                    if ($classification !== null) {
                        $ctx .= ' · ' . $classification;
                    }
                    $contextHtml = '<div style="font-family:\'DM Sans\',Arial,sans-serif;font-size:11px;color:#9a8f82;padding-left:2px;margin-top:1px;">' . $ctx . '</div>';
                }
            }

            $rowsHtml .= '
            <table cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-bottom:4px;">
              <tr>
                <td width="35%" style="font-family:\'DM Sans\',Arial,sans-serif;font-size:13px;color:#2c2414;white-space:nowrap;">' . $rowLabel . '</td>
                <td width="45%" style="padding:0 6px;">' . $barHtml . '</td>
                <td width="20%" style="font-family:\'JetBrains Mono\',\'Courier New\',monospace;font-size:12px;color:#2c2414;text-align:right;white-space:nowrap;">'
                    . $rowValueHtml
                    . ($rowUnit !== '' ? ' <span style="font-size:10px;color:#9a8f82;">' . $rowUnit . '</span>' : '')
                . '</td>
              </tr>
            </table>'
            . $contextHtml;
        }

        $groupsHtml .= $groupHeader . $rowsHtml;
    }

    if ($renderExtra > 0) {
        $groupsHtml .= '<div style="font-family:\'DM Sans\',Arial,sans-serif;font-size:10px;color:#9a8f82;margin-top:4px;">+ ' . $renderExtra . ' autres runs…</div>';
    }

    $breakdownSection = '
    <table cellpadding="0" cellspacing="0" border="0" width="100%">
      <tr>
        <td style="padding:8px 16px 12px;">' . $groupsHtml . '</td>
      </tr>
    </table>';

    // ── Assemble full recap tile ──────────────────────────────────────────────
    return '
<table cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-bottom:10px;background:#f9f3e8;border:1px solid #d8cbb8;border-radius:6px;overflow:hidden;">
  <tr><td>'
    . $titleRow
    . $metricStrip
    . $breakdownSection
  . '</td></tr>
</table>';
}

// ── Internal: default scalar card renderer ────────────────────────────────────

function _kpi_render_scalar(array $tracker, array $result): string
{
    $label = htmlspecialchars($tracker['label'] ?? '', ENT_QUOTES, 'UTF-8');
    $value = $result['value'] ?? null;
    $unit  = htmlspecialchars((string) ($result['unit'] ?? ''), ENT_QUOTES, 'UTF-8');

    // Format value
    if ($value === null) {
        $valueDisplay = '<span style="color:#9a8f82;">—</span>';
    } elseif (is_float($value)) {
        $valueDisplay = htmlspecialchars(number_format($value, 1, ',', ' '), ENT_QUOTES, 'UTF-8');
    } elseif (is_int($value)) {
        $valueDisplay = htmlspecialchars(number_format($value, 0, ',', ' '), ENT_QUOTES, 'UTF-8');
    } else {
        $valueDisplay = htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }

    // Delta
    $delta     = $result['delta'] ?? null;
    $deltaHtml = '';
    if ($delta !== null) {
        $sign  = $delta >= 0 ? '+' : '';
        $color = match ($result['tint'] ?? 'neutral') {
            'green'  => '#5a8a5a',
            'red'    => '#a04040',
            'amber'  => '#a07020',
            default  => '#9a8f82',
        };
        $deltaLabel = htmlspecialchars((string) ($result['delta_label'] ?? ''), ENT_QUOTES, 'UTF-8');
        $deltaHtml  = '<div style="font-size:11px;color:' . $color . ';margin-top:2px;">'
                    . htmlspecialchars($sign . number_format((float) $delta, 1, ',', ' '), ENT_QUOTES, 'UTF-8')
                    . ' ' . $deltaLabel . '</div>';
    }

    // No inline sparkline in the scalar card — SVG is not email-safe (Outlook drops it).
    // KPIs with series data use viz_type='sparkline' which has its own CSS-bar renderer.
    $sparkHtml = '';

    return '
        <table cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-bottom:10px;background:#f9f3e8;border:1px solid #d8cbb8;border-radius:6px;">
          <tr>
            <td style="padding:12px 16px;">
              <div style="font-family:\'DM Sans\',Arial,sans-serif;font-size:11px;letter-spacing:.08em;text-transform:uppercase;color:#9a8f82;margin-bottom:4px;">' . $label . '</div>
              <div style="font-family:\'JetBrains Mono\',\'Courier New\',monospace;font-size:22px;font-weight:500;color:#2c2414;line-height:1;">'
                  . $valueDisplay . '<span style="font-size:13px;color:#9a8f82;margin-left:4px;">' . $unit . '</span>'
              . '</div>'
              . $deltaHtml
              . $sparkHtml
              . '</td>
          </tr>
        </table>';
}

// ═══════════════════════════════════════════════════════════════════════════════
// ── Shared helper functions ──────────────────────────────────────────────────
// ═══════════════════════════════════════════════════════════════════════════════

/** Emit a 12×12 px color swatch table cell */
function _kpi_swatch(string $color): string
{
    $c = htmlspecialchars($color, ENT_QUOTES, 'UTF-8');
    return '<td width="12" style="padding:0 4px 0 0;vertical-align:middle;">'
         . '<table cellpadding="0" cellspacing="0" border="0"><tr>'
         . '<td bgcolor="' . $c . '" style="background:' . $c . ';width:12px;height:12px;border-radius:2px;font-size:1px;line-height:1px;">&nbsp;</td>'
         . '</tr></table>'
         . '</td>';
}

/**
 * Format a numeric value in FR locale.
 * NULL → muted dash. Loss metrics ($isLoss) get 2dp forced.
 */
function _kpi_fmt_val(mixed $val, int $dp = 1, bool $isLoss = false): string
{
    if ($val === null) {
        return '<span style="color:#9a8f82;">—</span>';
    }
    if (!is_numeric($val)) {
        return htmlspecialchars((string) $val, ENT_QUOTES, 'UTF-8');
    }
    $decimals = $isLoss ? max($dp, 2) : $dp;
    return htmlspecialchars(number_format((float) $val, $decimals, ',', ' '), ENT_QUOTES, 'UTF-8');
}

/**
 * Render a horizontal bar row: label | bar | value (right).
 * $barPct: 0-100, $height: px, $valHtml: pre-escaped value HTML.
 */
function _kpi_hbar_row(string $label, int $barPct, string $color, int $height, string $valHtml): string
{
    $barPct   = max(0, min(100, $barPct));
    $remPct   = 100 - $barPct;
    $h        = htmlspecialchars((string) $height, ENT_QUOTES, 'UTF-8');
    $c        = htmlspecialchars($color, ENT_QUOTES, 'UTF-8');
    $labelEsc = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');

    if ($barPct <= 0) {
        $barCells = '<td bgcolor="#e8dcc6" style="background:#e8dcc6;height:' . $h . 'px;" width="100%"></td>';
    } elseif ($barPct >= 100) {
        $barCells = '<td bgcolor="' . $c . '" style="background:' . $c . ';height:' . $h . 'px;border-radius:2px;" width="100%"></td>';
    } else {
        $barCells = '<td bgcolor="' . $c . '" style="background:' . $c . ';height:' . $h . 'px;border-radius:2px 0 0 2px;" width="' . $barPct . '%"></td>'
                  . '<td bgcolor="#e8dcc6" style="background:#e8dcc6;height:' . $h . 'px;" width="' . $remPct . '%"></td>';
    }

    return '
    <table cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-bottom:3px;">
      <tr>
        <td width="80" style="font-family:\'DM Sans\',Arial,sans-serif;font-size:12px;color:#2c2414;white-space:nowrap;padding-right:6px;">' . $labelEsc . '</td>
        <td style="padding:0 6px;">
          <table cellpadding="0" cellspacing="0" border="0" width="100%"><tr>' . $barCells . '</tr></table>
        </td>
        <td width="80" style="font-family:\'JetBrains Mono\',\'Courier New\',monospace;font-size:12px;color:#2c2414;text-align:right;white-space:nowrap;">' . $valHtml . '</td>
      </tr>
    </table>';
}

/**
 * Render an inline delta string: "▲ +3,2% vs N-1" or "▼ −1,1%"
 */
function _kpi_delta_inline(mixed $delta, ?string $deltaLabel, string $tint = 'neutral'): string
{
    if ($delta === null) {
        return '';
    }
    $color = match ($tint) {
        'green'  => '#5a8a5a',
        'red'    => '#a04040',
        'amber'  => '#a07020',
        default  => '#9a8f82',
    };
    $fDelta = (float) $delta;
    $arrow  = $fDelta >= 0 ? '▲' : '▼';
    $sign   = $fDelta >= 0 ? '+' : '';
    $fmtD   = htmlspecialchars($sign . number_format($fDelta, 1, ',', ' '), ENT_QUOTES, 'UTF-8');
    $lbl    = $deltaLabel !== null ? ' ' . htmlspecialchars($deltaLabel, ENT_QUOTES, 'UTF-8') : '';
    return '<span style="font-family:\'DM Sans\',Arial,sans-serif;font-size:11px;color:' . $color . ';">'
         . $arrow . ' ' . $fmtD . $lbl
         . '</span>';
}

/**
 * Render a colored delta chip for grouped_bar: "▲ +12,2%" green / "▼ −2,5%" red / "nouveau" amber.
 * Returns a <span> with background + white text.
 */
function _kpi_delta_chip(mixed $delta, ?string $tint = null, ?float $priorYear = null): string
{
    if ($priorYear === null || $priorYear == 0) {
        // No prior year data → "nouveau" amber chip
        $bg = '#a07020';
        return '<span style="font-family:\'DM Sans\',Arial,sans-serif;font-size:10px;background:' . $bg . ';color:#fff;padding:1px 5px;border-radius:3px;">nouveau</span>';
    }
    if ($delta === null) {
        return '';
    }
    $fDelta = (float) $delta;
    if ($tint === null) {
        $tint = $fDelta >= 0 ? 'green' : 'red';
    }
    $bg = match ($tint) {
        'green'  => '#5a8a5a',
        'red'    => '#a04040',
        'amber'  => '#a07020',
        default  => '#9a8f82',
    };
    $arrow = $fDelta >= 0 ? '▲' : '▼';
    $sign  = $fDelta >= 0 ? '+' : '';
    $fmtD  = htmlspecialchars($sign . number_format($fDelta, 1, ',', ' '), ENT_QUOTES, 'UTF-8');
    return '<span style="font-family:\'DM Sans\',Arial,sans-serif;font-size:10px;background:' . $bg . ';color:#fff;padding:1px 5px;border-radius:3px;">'
         . $arrow . ' ' . $fmtD
         . '</span>';
}

/** Standard dark-header tile wrapper */
function _kpi_tile_open(string $label): string
{
    $l = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
    return '
<table cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-bottom:10px;background:#f9f3e8;border:1px solid #d8cbb8;border-radius:6px;overflow:hidden;">
  <tr><td>
    <table cellpadding="0" cellspacing="0" border="0" width="100%">
      <tr><td style="background:#2c2414;padding:10px 16px;">
        <span style="font-family:\'JetBrains Mono\',\'Courier New\',monospace;font-size:11px;letter-spacing:.08em;text-transform:uppercase;color:#f1e8d4;">' . $l . '</span>
      </td></tr>
    </table>
    <table cellpadding="0" cellspacing="0" border="0" width="100%">
      <tr><td style="padding:10px 16px 12px;">';
}

function _kpi_tile_close(): string
{
    return '      </td></tr>
    </table>
  </td></tr>
</table>';
}

/** FR month abbreviations for period labels */
function _kpi_months_fr(): array
{
    return [
        '01' => 'Jan', '02' => 'Fév', '03' => 'Mar', '04' => 'Avr',
        '05' => 'Mai', '06' => 'Jun', '07' => 'Jul', '08' => 'Aoû',
        '09' => 'Sep', '10' => 'Oct', '11' => 'Nov', '12' => 'Déc',
    ];
}

/** Extract last 2 chars of a period string as the month key */
function _kpi_period_month(string $period): string
{
    return substr($period, -2);
}

// ═══════════════════════════════════════════════════════════════════════════════
// ── Viz renderers ────────────────────────────────────────────────────────────
// ═══════════════════════════════════════════════════════════════════════════════

// ── sparkline ────────────────────────────────────────────────────────────────
// 12 paired monthly columns (curr hop-green + ghost N-1), padding-top baseline.
// Legend row, then value+delta below.
function _kpi_render_sparkline(array $tracker, array $result): string
{
    $series = $result['series'] ?? [];
    if (empty($series)) {
        return _kpi_render_scalar($tracker, $result);
    }

    $series     = array_slice($series, -12);
    $meta       = $result['meta'] ?? [];
    $prevSeries = $meta['prev_series'] ?? [];
    $label      = $tracker['label'] ?? '';
    $value      = $result['value'] ?? null;
    $unit       = $result['unit'] ?? '';
    $delta      = $result['delta'] ?? null;
    $deltaLabel = $result['delta_label'] ?? null;
    $tint       = $result['tint'] ?? 'neutral';

    $monthsFr  = _kpi_months_fr();
    $maxBarPx  = 40;
    $outerH    = 44; // fixed outer td height

    // Build prev_series lookup by period
    $prevByPeriod = [];
    foreach ($prevSeries as $ps) {
        $k = _kpi_period_month((string) ($ps['period'] ?? ''));
        $prevByPeriod[$k] = $ps['value'] ?? null;
    }

    // Compute combined domain max
    $allVals = [];
    foreach ($series as $pt) {
        if (is_numeric($pt['value'] ?? null)) { $allVals[] = (float) $pt['value']; }
    }
    foreach ($prevSeries as $pt) {
        if (is_numeric($pt['value'] ?? null)) { $allVals[] = (float) $pt['value']; }
    }
    $domainMax = !empty($allVals) ? max($allVals) : 1;
    if ($domainMax <= 0) { $domainMax = 1; }

    // Build column cells
    $cols    = '';
    $labels  = '';
    foreach ($series as $pt) {
        $period  = (string) ($pt['period'] ?? '');
        $mKey    = _kpi_period_month($period);
        $mLabel  = htmlspecialchars($monthsFr[$mKey] ?? $mKey, ENT_QUOTES, 'UTF-8');
        $currVal = is_numeric($pt['value'] ?? null) ? (float) $pt['value'] : 0;
        $prevVal = is_numeric($prevByPeriod[$mKey] ?? null) ? (float) $prevByPeriod[$mKey] : 0;

        $currPx = (int) round($currVal / $domainMax * $maxBarPx);
        $prevPx = (int) round($prevVal / $domainMax * $maxBarPx);
        $currPt = $maxBarPx - $currPx;  // padding-top
        $prevPt = $maxBarPx - $prevPx;

        $cols .= '<td style="padding:0 1px;vertical-align:top;">
          <table cellpadding="0" cellspacing="0" border="0"><tr>
            <td style="padding-top:' . $currPt . 'px;vertical-align:top;">
              <table cellpadding="0" cellspacing="0" border="0"><tr>
                <td bgcolor="#9eb060" style="background:#9eb060;width:7px;height:' . $currPx . 'px;font-size:1px;line-height:1px;display:block;">&nbsp;</td>
              </tr></table>
            </td>
            <td style="padding-top:' . $prevPt . 'px;vertical-align:top;padding-left:1px;">
              <table cellpadding="0" cellspacing="0" border="0"><tr>
                <td bgcolor="#c9d6a3" style="background:#c9d6a3;width:7px;height:' . $prevPx . 'px;font-size:1px;line-height:1px;display:block;">&nbsp;</td>
              </tr></table>
            </td>
          </tr></table>
        </td>';
        $labels .= '<td style="font-family:\'DM Sans\',Arial,sans-serif;font-size:9px;color:#9a8f82;text-align:center;padding:2px 1px 0;">' . $mLabel . '</td>';
    }

    // Legend row
    $legend = '
    <table cellpadding="0" cellspacing="0" border="0" style="margin-top:6px;">
      <tr>'
      . _kpi_swatch('#9eb060')
      . '<td style="font-family:\'DM Sans\',Arial,sans-serif;font-size:10px;color:#9a8f82;padding-right:10px;">Cette année</td>'
      . _kpi_swatch('#c9d6a3')
      . '<td style="font-family:\'DM Sans\',Arial,sans-serif;font-size:10px;color:#9a8f82;">N−1</td>'
      . '</tr>
    </table>';

    // Value + delta row
    $isLoss   = (bool) preg_match('/perte/i', $label);
    $valHtml  = _kpi_fmt_val($value, 1, $isLoss);
    $unitEsc  = htmlspecialchars((string) $unit, ENT_QUOTES, 'UTF-8');
    $unitSpan = $unitEsc !== '' ? ' <span style="font-size:11px;color:#9a8f82;">' . $unitEsc . '</span>' : '';
    $deltaHtml = $delta !== null ? '&nbsp;&nbsp;' . _kpi_delta_inline($delta, $deltaLabel, $tint) : '';

    $content = '
    <table cellpadding="0" cellspacing="0" border="0" style="height:' . $outerH . 'px;"><tr>' . $cols . '</tr></table>
    <table cellpadding="0" cellspacing="0" border="0" width="100%"><tr>' . $labels . '</tr></table>'
    . $legend
    . '<div style="margin-top:6px;font-family:\'JetBrains Mono\',\'Courier New\',monospace;font-size:16px;color:#2c2414;">'
    . $valHtml . $unitSpan . $deltaHtml
    . '</div>';

    return _kpi_tile_open($label) . $content . _kpi_tile_close();
}

// ── line ─────────────────────────────────────────────────────────────────────
// Single-series mini-bar chart, 28px wide bars, taller (50px max height).
function _kpi_render_line(array $tracker, array $result): string
{
    $series = $result['series'] ?? [];
    if (empty($series)) {
        return _kpi_render_scalar($tracker, $result);
    }

    $series    = array_slice($series, -12);
    $label     = $tracker['label'] ?? '';
    $value     = $result['value'] ?? null;
    $unit      = $result['unit'] ?? '';
    $delta     = $result['delta'] ?? null;
    $deltaLabel = $result['delta_label'] ?? null;
    $tint      = $result['tint'] ?? 'neutral';

    $monthsFr = _kpi_months_fr();
    $maxBarPx = 50;
    $outerH   = 54;

    $vals = array_map(fn($pt) => is_numeric($pt['value'] ?? null) ? (float) $pt['value'] : 0, $series);
    $domainMax = !empty($vals) ? max($vals) : 1;
    if ($domainMax <= 0) { $domainMax = 1; }

    $cols   = '';
    $labels = '';
    foreach ($series as $pt) {
        $period = (string) ($pt['period'] ?? '');
        $mKey   = _kpi_period_month($period);
        $mLabel = htmlspecialchars($monthsFr[$mKey] ?? $mKey, ENT_QUOTES, 'UTF-8');
        $v      = is_numeric($pt['value'] ?? null) ? (float) $pt['value'] : 0;
        $barPx  = (int) round($v / $domainMax * $maxBarPx);
        $pt2    = $maxBarPx - $barPx;

        $cols .= '<td style="padding:0 2px;vertical-align:top;">
          <table cellpadding="0" cellspacing="0" border="0"><tr>
            <td style="padding-top:' . $pt2 . 'px;vertical-align:top;">
              <table cellpadding="0" cellspacing="0" border="0"><tr>
                <td bgcolor="#9eb060" style="background:#9eb060;width:14px;height:' . $barPx . 'px;font-size:1px;line-height:1px;">&nbsp;</td>
              </tr></table>
            </td>
          </tr></table>
        </td>';
        $labels .= '<td style="font-family:\'DM Sans\',Arial,sans-serif;font-size:9px;color:#9a8f82;text-align:center;padding:2px 2px 0;">' . $mLabel . '</td>';
    }

    $isLoss    = (bool) preg_match('/perte/i', $label);
    $valHtml   = _kpi_fmt_val($value, 1, $isLoss);
    $unitEsc   = htmlspecialchars((string) $unit, ENT_QUOTES, 'UTF-8');
    $unitSpan  = $unitEsc !== '' ? ' <span style="font-size:11px;color:#9a8f82;">' . $unitEsc . '</span>' : '';
    $deltaHtml = $delta !== null ? '&nbsp;&nbsp;' . _kpi_delta_inline($delta, $deltaLabel, $tint) : '';

    $content = '
    <table cellpadding="0" cellspacing="0" border="0" style="height:' . $outerH . 'px;"><tr>' . $cols . '</tr></table>
    <table cellpadding="0" cellspacing="0" border="0" width="100%"><tr>' . $labels . '</tr></table>
    <div style="margin-top:8px;font-family:\'JetBrains Mono\',\'Courier New\',monospace;font-size:16px;color:#2c2414;">'
    . $valHtml . $unitSpan . $deltaHtml
    . '</div>';

    return _kpi_tile_open($label) . $content . _kpi_tile_close();
}

// ── bar ──────────────────────────────────────────────────────────────────────
// Horizontal CSS bars: label 80px | bar fills middle | value 80px right.
function _kpi_render_bar(array $tracker, array $result): string
{
    // Some bar handlers return named items in 'series' rather than 'breakdown'
    $breakdown = $result['breakdown'] ?? $result['series'] ?? [];
    if (empty($breakdown)) {
        return _kpi_render_scalar($tracker, $result);
    }

    $label = $tracker['label'] ?? '';
    $unit  = htmlspecialchars((string) ($result['unit'] ?? ''), ENT_QUOTES, 'UTF-8');

    $vals = array_filter(array_column($breakdown, 'value'), 'is_numeric');
    $maxV = !empty($vals) ? max(array_map('floatval', $vals)) : 1;
    if ($maxV <= 0) { $maxV = 1; }

    $rows = '';
    $cap  = array_slice($breakdown, 0, 12);
    $rem  = count($breakdown) - count($cap);
    foreach ($cap as $item) {
        $iLabel  = (string) ($item['label'] ?? '');
        $iVal    = $item['value'] ?? null;
        $isLoss  = (bool) preg_match('/perte/i', $iLabel);
        $barPct  = is_numeric($iVal) ? (int) round((float) $iVal / $maxV * 100) : 0;
        $valHtml = _kpi_fmt_val($iVal, 1, $isLoss);
        if ($unit !== '') {
            $valHtml .= ' <span style="font-size:10px;color:#9a8f82;">' . $unit . '</span>';
        }
        $rows .= _kpi_hbar_row($iLabel, $barPct, '#9eb060', 14, $valHtml);
    }
    if ($rem > 0) {
        $rows .= '<div style="font-family:\'DM Sans\',Arial,sans-serif;font-size:10px;color:#9a8f82;padding:2px 0;">+' . $rem . ' autres…</div>';
    }

    return _kpi_tile_open($label) . $rows . _kpi_tile_close();
}

// ── grouped_bar ───────────────────────────────────────────────────────────────
// Per row: current bar (hop-green) + 4px spacer + prior-year ghost bar.
// Delta chip floated right. 120px label column.
function _kpi_render_grouped_bar(array $tracker, array $result): string
{
    $breakdown = $result['breakdown'] ?? [];
    if (empty($breakdown)) {
        return _kpi_render_scalar($tracker, $result);
    }

    $label      = $tracker['label'] ?? '';
    $value      = $result['value'] ?? null;
    $unit       = htmlspecialchars((string) ($result['unit'] ?? ''), ENT_QUOTES, 'UTF-8');
    $delta      = $result['delta'] ?? null;
    $deltaLabel = $result['delta_label'] ?? null;
    $tint       = $result['tint'] ?? 'neutral';

    $cap = array_slice($breakdown, 0, 12);
    $rem = count($breakdown) - count($cap);

    // Domain max across both curr and prior-year
    $allVals = [];
    foreach ($cap as $item) {
        if (is_numeric($item['value'] ?? null))                  { $allVals[] = (float) $item['value']; }
        if (is_numeric($item['meta']['prior_year'] ?? null))     { $allVals[] = (float) $item['meta']['prior_year']; }
    }
    $domainMax = !empty($allVals) ? max($allVals) : 1;
    if ($domainMax <= 0) { $domainMax = 1; }

    $rows = '';
    foreach ($cap as $item) {
        $iLabel    = (string) ($item['label'] ?? '');
        $iVal      = $item['value'] ?? null;
        $iPrior    = $item['meta']['prior_year'] ?? null;
        $iDelta    = $item['delta'] ?? null;
        $iTint     = $item['tint'] ?? null;

        $currPct  = is_numeric($iVal)   ? (int) round((float) $iVal   / $domainMax * 100) : 0;
        $priorPct = is_numeric($iPrior) ? (int) round((float) $iPrior / $domainMax * 100) : 0;
        $remCurr  = max(0, 100 - $currPct);
        $remPrior = max(0, 100 - $priorPct);

        $isLoss  = (bool) preg_match('/perte/i', $iLabel);
        $valHtml = _kpi_fmt_val($iVal, 1, $isLoss)
                 . ($unit !== '' ? ' <span style="font-size:10px;color:#9a8f82;">' . $unit . '</span>' : '');

        $chip = _kpi_delta_chip($iDelta, $iTint, is_numeric($iPrior) ? (float) $iPrior : null);

        $labelEsc = htmlspecialchars($iLabel, ENT_QUOTES, 'UTF-8');

        // Current bar row
        $currBar = $currPct >= 100
            ? '<td bgcolor="#9eb060" style="background:#9eb060;height:12px;border-radius:2px;" width="100%"></td>'
            : '<td bgcolor="#9eb060" style="background:#9eb060;height:12px;border-radius:2px 0 0 2px;" width="' . $currPct . '%"></td>'
              . '<td bgcolor="#e8dcc6" style="background:#e8dcc6;height:12px;" width="' . $remCurr . '%"></td>';

        // Prior-year bar row
        $priorBar = $priorPct >= 100
            ? '<td bgcolor="#c9d6a3" style="background:#c9d6a3;height:12px;border-radius:2px;" width="100%"></td>'
            : '<td bgcolor="#c9d6a3" style="background:#c9d6a3;height:12px;border-radius:2px 0 0 2px;" width="' . $priorPct . '%"></td>'
              . '<td bgcolor="#e8dcc6" style="background:#e8dcc6;height:12px;" width="' . $remPrior . '%"></td>';

        $rows .= '
        <table cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-bottom:6px;">
          <tr>
            <td width="120" rowspan="3" style="font-family:\'DM Sans\',Arial,sans-serif;font-size:12px;color:#2c2414;vertical-align:middle;white-space:nowrap;padding-right:8px;">'
              . $labelEsc . '</td>
            <td style="padding-bottom:0;">
              <table cellpadding="0" cellspacing="0" border="0" width="100%"><tr>' . $currBar . '</tr></table>
            </td>
            <td width="60" rowspan="3" style="text-align:right;vertical-align:middle;padding-left:6px;">'
              . $valHtml . '<br>' . $chip . '</td>
          </tr>
          <tr><td style="height:4px;"></td></tr>
          <tr>
            <td>
              <table cellpadding="0" cellspacing="0" border="0" width="100%"><tr>' . $priorBar . '</tr></table>
            </td>
          </tr>
        </table>';
    }

    if ($rem > 0) {
        $rows .= '<div style="font-family:\'DM Sans\',Arial,sans-serif;font-size:10px;color:#9a8f82;padding:2px 0;">+' . $rem . ' autres…</div>';
    }

    // Legend
    $legend = '
    <table cellpadding="0" cellspacing="0" border="0" style="margin-bottom:8px;">'
      . '<tr>' . _kpi_swatch('#9eb060') . '<td style="font-family:\'DM Sans\',Arial,sans-serif;font-size:10px;color:#9a8f82;padding-right:10px;">Cette année</td>'
      . _kpi_swatch('#c9d6a3') . '<td style="font-family:\'DM Sans\',Arial,sans-serif;font-size:10px;color:#9a8f82;">N−1</td></tr>
    </table>';

    $isLoss    = (bool) preg_match('/perte/i', $tracker['label'] ?? '');
    $totHtml   = _kpi_fmt_val($value, 1, $isLoss)
               . ($unit !== '' ? ' <span style="font-size:11px;color:#9a8f82;">' . $unit . '</span>' : '');
    $deltaHtml = $delta !== null ? '&nbsp;&nbsp;' . _kpi_delta_inline($delta, $deltaLabel, $tint) : '';

    $footer = '<div style="margin-top:6px;padding-top:6px;border-top:1px solid #e8dcc6;font-family:\'JetBrains Mono\',\'Courier New\',monospace;font-size:15px;color:#2c2414;">'
            . $totHtml . $deltaHtml . '</div>';

    return _kpi_tile_open($label) . $legend . $rows . $footer . _kpi_tile_close();
}

// ── stacked_bar ───────────────────────────────────────────────────────────────
// ONE full-width segmented proportion bar 20px + legend table + total+delta.
function _kpi_render_stacked_bar(array $tracker, array $result): string
{
    $breakdown = $result['breakdown'] ?? [];
    if (empty($breakdown)) {
        return _kpi_render_scalar($tracker, $result);
    }

    $label      = $tracker['label'] ?? '';
    $value      = $result['value'] ?? null;
    $unit       = htmlspecialchars((string) ($result['unit'] ?? ''), ENT_QUOTES, 'UTF-8');
    $delta      = $result['delta'] ?? null;
    $deltaLabel = $result['delta_label'] ?? null;
    $tint       = $result['tint'] ?? 'neutral';

    $palette = ['#9eb060', '#a07020', '#6e8b4e', '#c9b352', '#b08d57', '#5a8a5a'];

    $cap   = array_slice($breakdown, 0, 12);
    $extra = count($breakdown) - count($cap);

    // Total for proportions
    $total = array_sum(array_filter(array_column($cap, 'value'), 'is_numeric'));
    if ($total <= 0) { $total = 1; }

    // Build segmented bar
    $segs = '';
    foreach ($cap as $i => $item) {
        $v    = is_numeric($item['value'] ?? null) ? (float) $item['value'] : 0;
        $pct  = max(0, (float) round($v / $total * 100, 1));
        $c    = $palette[$i % count($palette)];
        $cEsc = htmlspecialchars($c, ENT_QUOTES, 'UTF-8');
        if ($pct <= 0) { continue; }
        $segs .= '<td bgcolor="' . $cEsc . '" style="background:' . $cEsc . ';height:20px;" width="' . $pct . '%"></td>';
    }
    $segBar = '
    <table cellpadding="0" cellspacing="0" border="0" width="100%" style="border-radius:3px;overflow:hidden;">
      <tr>' . $segs . '</tr>
    </table>';

    // Legend table
    $legendRows = '';
    foreach ($cap as $i => $item) {
        $iLabel = htmlspecialchars((string) ($item['label'] ?? ''), ENT_QUOTES, 'UTF-8');
        $iVal   = $item['value'] ?? null;
        $v      = is_numeric($iVal) ? (float) $iVal : 0;
        $pct    = $total > 0 ? round($v / $total * 100, 1) : 0;
        $c      = $palette[$i % count($palette)];
        $isLoss = (bool) preg_match('/perte/i', (string) ($item['label'] ?? ''));
        $valFmt = _kpi_fmt_val($iVal, 1, $isLoss)
                . ($unit !== '' ? ' <span style="font-size:10px;color:#9a8f82;">' . $unit . '</span>' : '');
        $pctFmt = htmlspecialchars(number_format($pct, 1, ',', ' '), ENT_QUOTES, 'UTF-8') . ' %';

        $legendRows .= '<tr>
          ' . _kpi_swatch($c) . '
          <td style="font-family:\'DM Sans\',Arial,sans-serif;font-size:12px;color:#2c2414;padding:1px 0;">' . $iLabel . '</td>
          <td style="font-family:\'JetBrains Mono\',\'Courier New\',monospace;font-size:11px;color:#2c2414;text-align:right;padding-left:8px;white-space:nowrap;">' . $valFmt . '</td>
          <td style="font-family:\'JetBrains Mono\',\'Courier New\',monospace;font-size:11px;color:#9a8f82;text-align:right;padding-left:8px;white-space:nowrap;">' . $pctFmt . '</td>
        </tr>';
    }
    if ($extra > 0) {
        $legendRows .= '<tr><td colspan="4" style="font-family:\'DM Sans\',Arial,sans-serif;font-size:10px;color:#9a8f82;padding-top:2px;">+' . $extra . ' autres…</td></tr>';
    }

    $legendTable = '
    <table cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-top:8px;">' . $legendRows . '</table>';

    $isLoss    = (bool) preg_match('/perte/i', $tracker['label'] ?? '');
    $totHtml   = _kpi_fmt_val($value, 1, $isLoss)
               . ($unit !== '' ? ' <span style="font-size:11px;color:#9a8f82;">' . $unit . '</span>' : '');
    $deltaHtml = $delta !== null ? '&nbsp;&nbsp;' . _kpi_delta_inline($delta, $deltaLabel, $tint) : '';

    $footer = '<div style="margin-top:8px;padding-top:6px;border-top:1px solid #e8dcc6;font-family:\'JetBrains Mono\',\'Courier New\',monospace;font-size:15px;color:#2c2414;">'
            . $totHtml . $deltaHtml . '</div>';

    return _kpi_tile_open($label) . $segBar . $legendTable . $footer . _kpi_tile_close();
}

// ── stacked_columns ───────────────────────────────────────────────────────────
// 12-month horizontal stacked bars (one row per month), width proportional to
// column.total vs maxTotal, each bar segmented by category. Pure projection —
// NO $pdo, NO SELECT.
function _kpi_render_stacked_columns(array $tracker, array $result): string
{
    $columns  = $result['meta']['columns']  ?? [];
    $breakdown = $result['breakdown'] ?? [];

    if (empty($columns)) {
        return _kpi_render_scalar($tracker, $result);
    }

    $label       = $tracker['label'] ?? '';
    $unit        = htmlspecialchars((string) ($result['unit'] ?? ''), ENT_QUOTES, 'UTF-8');
    $periodLabel = htmlspecialchars((string) ($result['meta']['period_label'] ?? ''), ENT_QUOTES, 'UTF-8');
    $delta       = $result['delta'] ?? null;
    $deltaLabel  = $result['delta_label'] ?? null;
    $tint        = $result['tint'] ?? 'neutral';

    $palette = ['#9eb060', '#a07020', '#6e8b4e', '#c9b352', '#b08d57', '#5a8a5a', '#c9d6a3', '#d4a868'];

    // Max total for proportional scaling
    $totals    = array_column($columns, 'total');
    $maxTotal  = $totals ? max(array_map('floatval', $totals)) : 0;
    if ($maxTotal <= 0) { $maxTotal = 1; }

    // Build legend index: key → palette index
    $legendIndex = [];
    $capLegend   = min(8, count($breakdown));
    for ($i = 0; $i < $capLegend; $i++) {
        $legendIndex[$breakdown[$i]['key']] = $i;
    }

    // Month rows
    $rows = '';
    foreach ($columns as $col) {
        $period   = $col['period'] ?? '';
        $colTotal = (float) ($col['total'] ?? 0);
        $segments = $col['segments'] ?? [];

        // Format period as mm/YY
        $parts   = explode('-', $period);
        $mmYY    = isset($parts[0], $parts[1]) ? $parts[1] . '/' . substr($parts[0], 2) : $period;
        $mmYYEsc = htmlspecialchars($mmYY, ENT_QUOTES, 'UTF-8');

        // Bar width as % of table (proportional to maxTotal)
        $barWidthPct = round($colTotal / $maxTotal * 100, 1);

        // Build segments — track $sumSegW to compute remainder without rounding drift
        $segCells = '';
        $sumSegW  = 0.0;
        foreach ($segments as $seg) {
            $key    = $seg['key'] ?? '';
            $segVal = (float) ($seg['value'] ?? 0);
            if ($segVal <= 0 || $colTotal <= 0) { continue; }
            $idx  = $legendIndex[$key] ?? (count($legendIndex) % count($palette));
            $c    = $palette[$idx % count($palette)];
            $cEsc = htmlspecialchars($c, ENT_QUOTES, 'UTF-8');
            $segW = round($segVal / $colTotal * $barWidthPct, 1);
            if ($segW <= 0) { continue; }
            $sumSegW  += $segW;
            $segCells .= '<td bgcolor="' . $cEsc . '" style="background:' . $cEsc . ';height:14px;" width="' . $segW . '%"></td>';
        }
        // Remainder is computed from actual rendered segment widths, not barWidthPct alone,
        // preventing per-segment rounding drift from pushing sum(segW)+remainder above 100%.
        $remainderPct  = max(0, round(100 - $sumSegW, 1));
        $remainderCell = $remainderPct > 0
            ? '<td bgcolor="#e8dcc6" style="background:#e8dcc6;height:14px;" width="' . $remainderPct . '%"></td>'
            : '';

        $valEsc = htmlspecialchars(number_format($colTotal, 1, ',', ' '), ENT_QUOTES, 'UTF-8');

        $rows .= '
        <tr>
          <td style="font-family:\'JetBrains Mono\',\'Courier New\',monospace;font-size:10px;color:#9a8f82;white-space:nowrap;padding-right:6px;vertical-align:middle;width:40px;">' . $mmYYEsc . '</td>
          <td style="vertical-align:middle;">
            <table cellpadding="0" cellspacing="0" border="0" width="100%" style="border-radius:2px;overflow:hidden;"><tr>'
              . $segCells . $remainderCell .
            '</tr></table>
          </td>
          <td style="font-family:\'JetBrains Mono\',\'Courier New\',monospace;font-size:10px;color:#2c2414;white-space:nowrap;padding-left:6px;text-align:right;vertical-align:middle;width:52px;">'
            . $valEsc . ($unit !== '' ? '<span style="font-size:9px;color:#9a8f82;"> ' . $unit . '</span>' : '') .
          '</td>
        </tr>';
    }

    $monthTable = '
    <table cellpadding="0" cellspacing="4" border="0" width="100%">'
        . ($periodLabel !== '' ? '<tr><td colspan="3" style="font-family:\'DM Sans\',Arial,sans-serif;font-size:10px;color:#9a8f82;padding-bottom:4px;">' . $periodLabel . '</td></tr>' : '')
        . $rows .
    '</table>';

    // Legend
    $legendRows = '';
    $extraLeg   = count($breakdown) - $capLegend;
    for ($i = 0; $i < $capLegend; $i++) {
        $b      = $breakdown[$i];
        $bLabel = htmlspecialchars((string) ($b['label'] ?? ''), ENT_QUOTES, 'UTF-8');
        $c      = $palette[$i % count($palette)];
        $legendRows .= '<tr>' . _kpi_swatch($c) . '<td style="font-family:\'DM Sans\',Arial,sans-serif;font-size:11px;color:#2c2414;padding:1px 0;">' . $bLabel . '</td></tr>';
    }
    if ($extraLeg > 0) {
        $legendRows .= '<tr><td colspan="2" style="font-family:\'DM Sans\',Arial,sans-serif;font-size:10px;color:#9a8f82;padding-top:2px;">+' . $extraLeg . ' autres…</td></tr>';
    }
    $legendTable = $legendRows !== ''
        ? '<table cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-top:8px;">' . $legendRows . '</table>'
        : '';

    // Footer: total (latest month) + delta
    $latestCol   = end($columns);
    $latestTotal = (float) ($latestCol['total'] ?? 0);
    $totHtml     = _kpi_fmt_val($latestTotal, 1, false)
                 . ($unit !== '' ? ' <span style="font-size:11px;color:#9a8f82;">' . $unit . '</span>' : '');
    $deltaHtml   = $delta !== null ? '&nbsp;&nbsp;' . _kpi_delta_inline($delta, $deltaLabel, $tint) : '';
    $footer      = '<div style="margin-top:8px;padding-top:6px;border-top:1px solid #e8dcc6;font-family:\'JetBrains Mono\',\'Courier New\',monospace;font-size:15px;color:#2c2414;">'
                 . $totHtml . $deltaHtml . '</div>';

    return _kpi_tile_open($label) . $monthTable . $legendTable . $footer . _kpi_tile_close();
}

// ── donut ─────────────────────────────────────────────────────────────────────
// NO segmented bar. Ranked legend: swatch | label | value | bold large %.
// Sorted desc by value. Total at bottom.
function _kpi_render_donut(array $tracker, array $result): string
{
    // Some donut handlers return items in 'series' rather than 'breakdown'
    $breakdown = $result['breakdown'] ?? $result['series'] ?? [];
    if (empty($breakdown)) {
        return _kpi_render_scalar($tracker, $result);
    }

    $label   = $tracker['label'] ?? '';
    $value   = $result['value'] ?? null;
    $unit    = htmlspecialchars((string) ($result['unit'] ?? ''), ENT_QUOTES, 'UTF-8');
    $palette = ['#9eb060', '#a07020', '#6e8b4e', '#c9b352', '#b08d57', '#5a8a5a'];

    // Sort desc by value
    $sorted = $breakdown;
    usort($sorted, fn($a, $b) => ($b['value'] ?? 0) <=> ($a['value'] ?? 0));
    $cap   = array_slice($sorted, 0, 12);
    $extra = count($sorted) - count($cap);

    $total = array_sum(array_filter(array_column($cap, 'value'), 'is_numeric'));
    if ($total <= 0) { $total = 1; }

    $rows = '';
    foreach ($cap as $i => $item) {
        $iLabel = htmlspecialchars((string) ($item['label'] ?? ''), ENT_QUOTES, 'UTF-8');
        $iVal   = $item['value'] ?? null;
        $v      = is_numeric($iVal) ? (float) $iVal : 0;
        $pct    = round($v / $total * 100, 1);
        $c      = $palette[$i % count($palette)];
        $isLoss = (bool) preg_match('/perte/i', (string) ($item['label'] ?? ''));
        $valFmt = _kpi_fmt_val($iVal, 1, $isLoss)
                . ($unit !== '' ? ' <span style="font-size:10px;color:#9a8f82;">' . $unit . '</span>' : '');
        $pctFmt = htmlspecialchars(number_format($pct, 1, ',', ' '), ENT_QUOTES, 'UTF-8') . ' %';

        $rows .= '<tr>
          ' . _kpi_swatch($c) . '
          <td style="font-family:\'DM Sans\',Arial,sans-serif;font-size:12px;color:#2c2414;padding:2px 0;">' . $iLabel . '</td>
          <td style="font-family:\'JetBrains Mono\',\'Courier New\',monospace;font-size:11px;color:#2c2414;text-align:right;padding-left:8px;white-space:nowrap;">' . $valFmt . '</td>
          <td style="font-family:\'JetBrains Mono\',\'Courier New\',monospace;font-size:13px;font-weight:bold;color:#2c2414;text-align:right;padding-left:8px;white-space:nowrap;">' . $pctFmt . '</td>
        </tr>';
    }
    if ($extra > 0) {
        $rows .= '<tr><td colspan="4" style="font-family:\'DM Sans\',Arial,sans-serif;font-size:10px;color:#9a8f82;padding-top:2px;">+' . $extra . ' autres…</td></tr>';
    }

    // Total row
    $isLoss  = (bool) preg_match('/perte/i', $tracker['label'] ?? '');
    $totFmt  = _kpi_fmt_val($value, 1, $isLoss)
             . ($unit !== '' ? ' <span style="font-size:10px;color:#9a8f82;">' . $unit . '</span>' : '');
    $rows .= '<tr style="border-top:1px solid #e8dcc6;">
      <td colspan="2" style="font-family:\'DM Sans\',Arial,sans-serif;font-size:12px;color:#2c2414;font-weight:bold;padding-top:4px;">Total</td>
      <td style="font-family:\'JetBrains Mono\',\'Courier New\',monospace;font-size:12px;color:#2c2414;text-align:right;padding-left:8px;padding-top:4px;white-space:nowrap;">' . $totFmt . '</td>
      <td style="font-family:\'JetBrains Mono\',\'Courier New\',monospace;font-size:13px;font-weight:bold;color:#2c2414;text-align:right;padding-left:8px;padding-top:4px;">100 %</td>
    </tr>';

    $content = '
    <table cellpadding="0" cellspacing="0" border="0" width="100%">' . $rows . '</table>';

    return _kpi_tile_open($label) . $content . _kpi_tile_close();
}

// ── flag ──────────────────────────────────────────────────────────────────────
// Colored chip block: amber/red/green background, white text, big value, small-caps label.
function _kpi_render_flag(array $tracker, array $result): string
{
    $label = $tracker['label'] ?? '';
    $value = $result['value'] ?? null;
    $unit  = htmlspecialchars((string) ($result['unit'] ?? ''), ENT_QUOTES, 'UTF-8');
    $tint  = $result['tint'] ?? 'neutral';

    $bg = match ($tint) {
        'amber'  => '#a07020',
        'red'    => '#a04040',
        'green'  => '#5a8a5a',
        default  => ($value == 0 ? '#5a8a5a' : '#a07020'),
    };

    $isLoss   = (bool) preg_match('/perte/i', $label);
    $valHtml  = _kpi_fmt_val($value, 1, $isLoss);
    $unitSpan = $unit !== '' ? '<span style="font-size:12px;opacity:.8;margin-left:4px;">' . $unit . '</span>' : '';
    $labelEsc = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
    $bgEsc    = htmlspecialchars($bg, ENT_QUOTES, 'UTF-8');

    $chip = '
    <table cellpadding="0" cellspacing="0" border="0" width="100%">
      <tr>
        <td bgcolor="' . $bgEsc . '" style="background:' . $bgEsc . ';padding:14px 20px;border-radius:4px;text-align:center;">
          <div style="font-family:\'DM Sans\',Arial,sans-serif;font-size:10px;letter-spacing:.1em;text-transform:small-caps;color:#fff;opacity:.85;margin-bottom:4px;">' . $labelEsc . '</div>
          <div style="font-family:\'JetBrains Mono\',\'Courier New\',monospace;font-size:28px;font-weight:600;color:#fff;line-height:1;">'
               . $valHtml . $unitSpan
          . '</div>
        </td>
      </tr>
    </table>';

    return _kpi_tile_open($label) . $chip . _kpi_tile_close();
}

// ── table ─────────────────────────────────────────────────────────────────────
// Dark header row, alternating rows, 12-row cap, optional total.
function _kpi_render_table(array $tracker, array $result): string
{
    $breakdown = $result['breakdown'] ?? $result['series'] ?? [];
    if (empty($breakdown)) {
        return _kpi_render_scalar($tracker, $result);
    }

    $label = $tracker['label'] ?? '';
    $value = $result['value'] ?? null;
    $unit  = htmlspecialchars((string) ($result['unit'] ?? ''), ENT_QUOTES, 'UTF-8');

    $cap   = array_slice($breakdown, 0, 12);
    $extra = count($breakdown) - count($cap);

    $rows = '';
    foreach ($cap as $i => $item) {
        $bg     = $i % 2 === 0 ? '#faf5ec' : '#f9f3e8';
        $bgEsc  = htmlspecialchars($bg, ENT_QUOTES, 'UTF-8');

        // Support both series {period,value} and breakdown {label,value}
        $iLabel = htmlspecialchars((string) ($item['label'] ?? $item['period'] ?? ''), ENT_QUOTES, 'UTF-8');
        $iVal   = $item['value'] ?? null;
        $isLoss = (bool) preg_match('/perte/i', (string) ($item['label'] ?? ''));
        $valFmt = _kpi_fmt_val($iVal, 1, $isLoss)
                . ($unit !== '' ? ' <span style="font-size:10px;color:#9a8f82;">' . $unit . '</span>' : '');

        $rows .= '<tr>
          <td bgcolor="' . $bgEsc . '" style="background:' . $bgEsc . ';padding:4px 10px;font-family:\'DM Sans\',Arial,sans-serif;font-size:12px;color:#2c2414;">' . $iLabel . '</td>
          <td bgcolor="' . $bgEsc . '" style="background:' . $bgEsc . ';padding:4px 10px;font-family:\'JetBrains Mono\',\'Courier New\',monospace;font-size:12px;color:#2c2414;text-align:right;white-space:nowrap;">' . $valFmt . '</td>
        </tr>';
    }

    if ($extra > 0) {
        $rows .= '<tr>
          <td colspan="2" style="padding:3px 10px;font-family:\'DM Sans\',Arial,sans-serif;font-size:10px;color:#9a8f82;">+' . $extra . ' lignes…</td>
        </tr>';
    }

    if ($value !== null) {
        $isLoss = (bool) preg_match('/perte/i', $tracker['label'] ?? '');
        $totFmt = _kpi_fmt_val($value, 1, $isLoss)
                . ($unit !== '' ? ' <span style="font-size:10px;color:#9a8f82;">' . $unit . '</span>' : '');
        $rows .= '<tr>
          <td bgcolor="#2c2414" style="background:#2c2414;padding:5px 10px;font-family:\'DM Sans\',Arial,sans-serif;font-size:12px;color:#f1e8d4;font-weight:bold;">Total</td>
          <td bgcolor="#2c2414" style="background:#2c2414;padding:5px 10px;font-family:\'JetBrains Mono\',\'Courier New\',monospace;font-size:12px;color:#f1e8d4;text-align:right;white-space:nowrap;">' . $totFmt . '</td>
        </tr>';
    }

    $tbl = '
    <table cellpadding="0" cellspacing="0" border="0" width="100%" style="border-radius:3px;overflow:hidden;">
      <tr>
        <td colspan="2" bgcolor="#2c2414" style="background:#2c2414;padding:5px 10px;font-family:\'DM Sans\',Arial,sans-serif;font-size:10px;letter-spacing:.08em;text-transform:uppercase;color:#f1e8d4;">Détail</td>
      </tr>'
      . $rows .
    '</table>';

    return _kpi_tile_open($label) . $tbl . _kpi_tile_close();
}

// ── waterfall ─────────────────────────────────────────────────────────────────
// Signed-contribution rows: positive → right hop-green, negative → left amber.
// Caption "(décomposition — cumul non figuré)". Total at bottom.
function _kpi_render_waterfall(array $tracker, array $result): string
{
    $breakdown = $result['breakdown'] ?? [];
    if (empty($breakdown)) {
        return _kpi_render_scalar($tracker, $result);
    }

    $label = $tracker['label'] ?? '';
    $value = $result['value'] ?? null;
    $unit  = htmlspecialchars((string) ($result['unit'] ?? ''), ENT_QUOTES, 'UTF-8');

    // Cap at 12 rows
    $allRows  = $breakdown;
    $cap      = array_slice($allRows, 0, 12);
    $extra    = count($allRows) - count($cap);

    // Domain max across abs values
    $absVals = array_filter(
        array_map(fn($item) => is_numeric($item['value'] ?? null) ? abs((float) $item['value']) : 0, $cap),
        fn($v) => $v > 0
    );
    $domainMax = !empty($absVals) ? max($absVals) : 1;

    $rows = '';
    foreach ($cap as $item) {
        $iLabel  = (string) ($item['label'] ?? '');
        $iVal    = $item['value'] ?? null;
        $v       = is_numeric($iVal) ? (float) $iVal : 0;
        $absV    = abs($v);
        $pct     = (int) round($absV / $domainMax * 100);
        $pct     = min(100, $pct);
        $remPct  = 100 - $pct;
        $isLoss  = (bool) preg_match('/perte/i', $iLabel);
        $labelEsc = htmlspecialchars($iLabel, ENT_QUOTES, 'UTF-8');
        $sign    = $v >= 0 ? '+' : '−';
        $color   = $v >= 0 ? '#2c2414' : '#a07020';
        $valFmt  = '<span style="color:' . $color . ';">'
                 . $sign . _kpi_fmt_val($absV, 1, $isLoss)
                 . ($unit !== '' ? ' <span style="font-size:10px;color:#9a8f82;">' . $unit . '</span>' : '')
                 . '</span>';

        if ($v >= 0) {
            // Positive: bar grows RIGHT from left
            $barCell = $pct >= 100
                ? '<td bgcolor="#9eb060" style="background:#9eb060;height:12px;border-radius:2px;" width="100%"></td>'
                : '<td bgcolor="#9eb060" style="background:#9eb060;height:12px;border-radius:2px 0 0 2px;" width="' . $pct . '%"></td>'
                  . '<td bgcolor="#e8dcc6" style="background:#e8dcc6;height:12px;" width="' . $remPct . '%"></td>';
        } else {
            // Negative: bar grows LEFT from right (spacer | amber bar)
            $barCell = $pct >= 100
                ? '<td bgcolor="#a07020" style="background:#a07020;height:12px;border-radius:2px;" width="100%"></td>'
                : '<td bgcolor="#e8dcc6" style="background:#e8dcc6;height:12px;" width="' . $remPct . '%"></td>'
                  . '<td bgcolor="#a07020" style="background:#a07020;height:12px;border-radius:0 2px 2px 0;" width="' . $pct . '%"></td>';
        }

        $rows .= '
        <table cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-bottom:4px;">
          <tr>
            <td width="100" style="font-family:\'DM Sans\',Arial,sans-serif;font-size:12px;color:#2c2414;white-space:nowrap;padding-right:6px;">' . $labelEsc . '</td>
            <td style="padding:0 6px;">
              <table cellpadding="0" cellspacing="0" border="0" width="100%"><tr>' . $barCell . '</tr></table>
            </td>
            <td width="80" style="font-family:\'JetBrains Mono\',\'Courier New\',monospace;font-size:12px;text-align:right;white-space:nowrap;">' . $valFmt . '</td>
          </tr>
        </table>';
    }

    // Overflow row
    if ($extra > 0) {
        $rows .= '<div style="font-family:\'DM Sans\',Arial,sans-serif;font-size:10px;color:#9a8f82;margin-top:2px;margin-bottom:2px;">+ ' . $extra . ' autres…</div>';
    }

    // Caption
    $caption = '<div style="font-family:\'DM Sans\',Arial,sans-serif;font-size:10px;color:#9a8f82;font-style:italic;margin-bottom:6px;">'
             . '(décomposition — cumul non figuré)</div>';

    // Total
    $isLoss  = (bool) preg_match('/perte/i', $tracker['label'] ?? '');
    $totHtml = '';
    if ($value !== null) {
        $totFmt  = _kpi_fmt_val($value, 1, $isLoss)
                 . ($unit !== '' ? ' <span style="font-size:11px;color:#9a8f82;">' . $unit . '</span>' : '');
        $totHtml = '<div style="margin-top:6px;padding-top:6px;border-top:1px solid #e8dcc6;font-family:\'JetBrains Mono\',\'Courier New\',monospace;font-size:14px;color:#2c2414;">'
                 . '<span style="font-family:\'DM Sans\',Arial,sans-serif;font-size:11px;color:#9a8f82;margin-right:6px;">Total</span>' . $totFmt . '</div>';
    }

    return _kpi_tile_open($label) . $caption . $rows . $totHtml . _kpi_tile_close();
}
