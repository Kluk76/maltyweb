<?php
declare(strict_types=1);
/**
 * tour-gap-check.php — Read-only drift detector between ref_pages and the Visite guidée
 * content maps in public/modules/visite-guidee.php.
 *
 * Purpose:
 *   Catches missing tour content for active non-admin pages by checking the three
 *   content maps that make up each step card:
 *     CRITICAL  — page absent from $PAGE_DESCRIPTIONS (step has only generic fallback boilerplate)
 *     MINOR     — page present in descriptions but absent from $PAGE_ICONS (falls back to _default)
 *     INFO      — page absent from vg_vignette_for() cases (falls back to default vignette)
 *     LATENT    — inactive page absent from $PAGE_DESCRIPTIONS (pre-write suggestion, not a failure)
 *
 *   'saisies' is excluded: it is special-cased in the tour (section opener + form chapters,
 *   no $PAGE_DESCRIPTIONS entry by design).
 *   Admin-domain pages are excluded: they are never shown in the tour.
 *
 *   THIS SCRIPT NEVER WRITES. It only reads the DB and parses visite-guidee.php as text.
 *   (The file cannot be include()d — it renders the full page and has login side effects.)
 *
 * Usage (on VPS):
 *   sudo php /var/www/maltytask/scripts/tour-gap-check.php [--quiet]
 *
 * Exit codes:
 *   0  — no CRITICAL or MINOR gap found (INFO/LATENT are advisory only)
 *   1  — at least one CRITICAL or MINOR gap detected
 *
 * Flags:
 *   --quiet   Print only the one-line summary (no per-item detail).
 */

$root  = dirname(__DIR__);
$quiet = in_array('--quiet', $argv ?? [], true);

require_once $root . '/app/db.php';

// ── 1. Load ref_pages from DB ────────────────────────────────────────────────

$pdo  = maltytask_pdo();
$stmt = $pdo->query(
    "SELECT page_key, label, domain, min_role, is_active
       FROM ref_pages
      ORDER BY sort"
);
$refPages = $stmt->fetchAll();  // PDO::FETCH_ASSOC set globally in maltytask_pdo()

// ── 2. Parse visite-guidee.php as text ──────────────────────────────────────
//
// The parse is intentionally text-based. The file cannot be include()d because
// it renders the full page (require_login(), DB writes for tour_seen_at, HTML output)
// and has authentication side effects that would be fatal from a CLI script.
// We extract keys by scoping each regex to its specific block.

$vgPath = $root . '/public/modules/visite-guidee.php';
if (!file_exists($vgPath)) {
    fwrite(STDERR, "Error: visite-guidee.php not found at {$vgPath}\n");
    exit(2);
}
$vgText = file_get_contents($vgPath);

/**
 * Extract quoted keys from a PHP associative array block.
 * Anchors on the line containing "$arrayName = [", then scans forward
 * to the first line that is exactly "];" (the block terminator).
 * Only keys in the form 'key' => are captured within that block.
 *
 * @return string[]  list of key strings (without quotes)
 */
function extract_array_keys(string $text, string $arrayName): array
{
    // Match from the assignment line to the first standalone "];" line.
    // Use a non-greedy match across lines, anchored at the assignment.
    $escaped = preg_quote($arrayName, '/');
    $pattern = '/\$' . $escaped . '\s*=\s*\[.*?\n(\];)/s';
    if (!preg_match($pattern, $text, $blockMatch, PREG_OFFSET_CAPTURE)) {
        return [];
    }

    $blockStart = $blockMatch[0][1];
    // Find the end of the block: first occurrence of "];" on its own line
    // after the assignment line.
    $assignEnd = strpos($text, "\n", $blockStart) + 1;
    $blockEnd  = strpos($text, '];', $assignEnd);
    if ($blockEnd === false) {
        return [];
    }
    $block = substr($text, $assignEnd, $blockEnd - $assignEnd);

    // Extract every 'key' => pattern within this block only.
    preg_match_all("/['\"]([^'\"]+)['\"]\s*=>/", $block, $keyMatches);
    return $keyMatches[1] ?? [];
}

/**
 * Extract keys from vg_vignette_for()'s switch cases.
 * Scopes to the function body and pulls every case 'key': entry.
 *
 * @return string[]  list of key strings (without quotes)
 */
function extract_vignette_cases(string $text): array
{
    // Anchor on the function declaration line, capture up to its closing brace.
    // The function ends at the first top-level closing brace after the opening.
    $funcStart = strpos($text, 'function vg_vignette_for(');
    if ($funcStart === false) {
        return [];
    }
    $braceOpen = strpos($text, '{', $funcStart);
    if ($braceOpen === false) {
        return [];
    }

    // Walk forward tracking brace depth to find the matching closing brace.
    $depth = 0;
    $len   = strlen($text);
    $i     = $braceOpen;
    while ($i < $len) {
        if ($text[$i] === '{') {
            $depth++;
        } elseif ($text[$i] === '}') {
            $depth--;
            if ($depth === 0) {
                break;
            }
        }
        $i++;
    }
    $funcBody = substr($text, $braceOpen, $i - $braceOpen + 1);

    // Extract every case 'key': within the function body.
    preg_match_all("/case\s+['\"]([^'\"]+)['\"]\s*:/", $funcBody, $caseMatches);
    return $caseMatches[1] ?? [];
}

$descriptionKeys  = extract_array_keys($vgText, 'PAGE_DESCRIPTIONS');
$iconKeys         = extract_array_keys($vgText, 'PAGE_ICONS');
$vignetteKeys     = extract_vignette_cases($vgText);

// Build O(1) lookup sets. Exclude the sentinel '_default' from icon coverage
// (it's a fallback key, not a real page_key).
$descSet     = array_flip($descriptionKeys);
$iconSet     = array_flip(array_filter($iconKeys, fn($k) => $k !== '_default'));
$vignetteSet = array_flip($vignetteKeys);

// ── 3. Classify pages ────────────────────────────────────────────────────────

$criticals = [];   // active non-admin, missing description
$minors    = [];   // active non-admin, has description, missing icon
$infos     = [];   // active non-admin, missing vignette case (advisory)
$latents   = [];   // inactive non-admin, missing description (pre-write suggestion)

foreach ($refPages as $row) {
    $key    = $row['page_key'];
    $domain = $row['domain'];
    $active = (bool) $row['is_active'];

    // Exclusions: admin-domain pages never appear in the tour.
    if ($domain === 'admin') {
        continue;
    }
    // 'saisies' is special-cased in the tour (section opener + form chapters,
    // no $PAGE_DESCRIPTIONS entry by design) — exclude from all gap checks.
    if ($key === 'saisies') {
        continue;
    }

    if ($active) {
        // CRITICAL: no description card at all.
        if (!isset($descSet[$key])) {
            $criticals[] = ['key' => $key, 'label' => $row['label']];
            continue;
        }
        // MINOR: description present, but icon missing (falls back to _default).
        if (!isset($iconSet[$key])) {
            $minors[] = ['key' => $key, 'label' => $row['label']];
        }
        // INFO: vignette case missing (purely advisory — default vignette renders fine).
        if (!isset($vignetteSet[$key])) {
            $infos[] = ['key' => $key, 'label' => $row['label']];
        }
    } else {
        // LATENT: inactive page that lacks a description (pre-write suggestion).
        if (!isset($descSet[$key])) {
            $latents[] = ['key' => $key, 'label' => $row['label']];
        }
    }
}

// ── 4. Output ────────────────────────────────────────────────────────────────

$hasBlockingGap = count($criticals) > 0 || count($minors) > 0;

if (!$quiet) {
    $line = str_repeat('─', 72);

    // CRITICAL
    echo "\n{$line}\n";
    echo "CRITICAL  (active page with no \$PAGE_DESCRIPTIONS entry — generic fallback)\n";
    echo "{$line}\n";
    if (count($criticals) === 0) {
        echo "  (none)\n";
    } else {
        foreach ($criticals as $item) {
            echo "  [CRITICAL]  page_key='{$item['key']}'  label='{$item['label']}'\n";
        }
    }

    // MINOR
    echo "\n{$line}\n";
    echo "MINOR  (description present, but no \$PAGE_ICONS entry — uses _default icon)\n";
    echo "{$line}\n";
    if (count($minors) === 0) {
        echo "  (none)\n";
    } else {
        foreach ($minors as $item) {
            echo "  [MINOR]  page_key='{$item['key']}'  label='{$item['label']}'\n";
        }
    }

    // INFO
    echo "\n{$line}\n";
    echo "INFO  (no vg_vignette_for() case — uses default vignette; advisory only)\n";
    echo "{$line}\n";
    if (count($infos) === 0) {
        echo "  (none)\n";
    } else {
        foreach ($infos as $item) {
            echo "  [INFO]  page_key='{$item['key']}'  label='{$item['label']}'\n";
        }
    }

    // LATENT
    echo "\n{$line}\n";
    echo "LATENT  (inactive page, no description yet — pre-write suggestion, not a failure)\n";
    echo "{$line}\n";
    if (count($latents) === 0) {
        echo "  (none)\n";
    } else {
        foreach ($latents as $item) {
            echo "  [LATENT]  page_key='{$item['key']}'  label='{$item['label']}'\n";
        }
    }

    echo "\n";
}

// Summary line (always printed, even with --quiet)
$cCount = count($criticals);
$mCount = count($minors);
$iCount = count($infos);
$lCount = count($latents);
$status = $hasBlockingGap ? 'GAP DETECTED' : 'OK';
printf(
    "tour-gap-check: %s  |  critical=%d  minor=%d  info=%d  latent=%d\n",
    $status,
    $cCount,
    $mCount,
    $iCount,
    $lCount
);

exit($hasBlockingGap ? 1 : 0);
