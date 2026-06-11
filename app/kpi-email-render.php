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
        $groupOrder  = ['keg', 'cuv', 'bot', 'can'];
        $groupLabels = ['keg' => 'Fût', 'cuv' => 'Cuve', 'bot' => 'Bouteille', 'can' => 'Canette'];
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

    // Inline sparkline: simple inline-SVG bar chart if series available
    $sparkHtml = '';
    $series    = $result['series'] ?? null;
    if (is_array($series) && count($series) >= 2) {
        $vals = array_column($series, 'value');
        $maxV = max(array_filter($vals, 'is_numeric')) ?: 1;
        $w    = 80;
        $h    = 24;
        $barW = max(2, (int) floor($w / count($vals)) - 1);
        $svgBars = '';
        foreach ($vals as $i => $sv) {
            $sv = is_numeric($sv) ? (float) $sv : 0;
            $bh = max(1, (int) round($sv / $maxV * ($h - 2)));
            $x  = $i * ($barW + 1);
            $y  = $h - $bh;
            $svgBars .= "<rect x=\"{$x}\" y=\"{$y}\" width=\"{$barW}\" height=\"{$bh}\" fill=\"#9eb060\" rx=\"1\"/>";
        }
        $sparkHtml = '<div style="margin-top:6px;">'
            . "<svg width=\"{$w}\" height=\"{$h}\" viewBox=\"0 0 {$w} {$h}\" xmlns=\"http://www.w3.org/2000/svg\" style=\"display:block;\">{$svgBars}</svg>"
            . '</div>';
    }

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
