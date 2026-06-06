<?php
declare(strict_types=1);
/**
 * reconcile-pages.php — Read-only drift detector between the filesystem and ref_pages.
 *
 * Purpose:
 *   Catches two failure modes that corrupt the nav registry:
 *     (a) A PHP page added to public/modules/ or public/admin/ without a matching ref_pages row.
 *     (b) A ref_pages row whose href points at a file that no longer exists on disk.
 *   Also surfaces lower-priority sanity anomalies (active placeholders, inactive files).
 *
 *   THIS SCRIPT NEVER WRITES. Registration is a human/admin action via:
 *     reglages-generaux.php?sec=access  (admin UI)
 *   or a direct INSERT into ref_pages with correct sort/domain/min_role values.
 *
 * Usage (on VPS):
 *   sudo php /var/www/maltytask/scripts/reconcile-pages.php [--quiet]
 *
 * Exit codes:
 *   0  — no UNREGISTERED or DANGLING drift found (informational rows may still be printed)
 *   1  — at least one UNREGISTERED or DANGLING item detected
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
    "SELECT page_key, href, is_active
       FROM ref_pages
      ORDER BY sort"
);
$refPages = $stmt->fetchAll();  // PDO::FETCH_ASSOC set globally in maltytask_pdo()

// ── 2. Build lookup structures ───────────────────────────────────────────────

// Registered hrefs (non-placeholder), keyed by href string for O(1) lookup.
// href='#' rows are intentional placeholders — excluded from DANGLING check.
$registeredHrefs = [];   // href => ['page_key' => …, 'is_active' => …]
foreach ($refPages as $row) {
    if ($row['href'] !== '#') {
        $registeredHrefs[$row['href']] = [
            'page_key'  => $row['page_key'],
            'is_active' => (bool) $row['is_active'],
        ];
    }
}

// ── 3. Scan filesystem ───────────────────────────────────────────────────────

// Only direct *.php files in the two nav directories — no recursion.
// Subdirectories (e.g. public/admin/ingest/, public/admin/settings/) hold
// assets/partials, not nav pages.
$scanDirs = [
    '/modules' => $root . '/public/modules',
    '/admin'   => $root . '/public/admin',
];

$diskFiles = [];   // href-relative path (e.g. /modules/warehouse.php) => absolute path
foreach ($scanDirs as $hrefPrefix => $absDir) {
    if (!is_dir($absDir)) {
        fwrite(STDERR, "Warning: scan directory not found: {$absDir}\n");
        continue;
    }
    foreach (glob($absDir . '/*.php') ?: [] as $absPath) {
        $hrefPath            = $hrefPrefix . '/' . basename($absPath);
        $diskFiles[$hrefPath] = $absPath;
    }
}

// ── 4. Classify drift ────────────────────────────────────────────────────────

// UNREGISTERED: on disk, no matching ref_pages.href
$unregistered = [];
foreach ($diskFiles as $hrefPath => $absPath) {
    if (!array_key_exists($hrefPath, $registeredHrefs)) {
        $unregistered[] = $hrefPath;
    }
}
sort($unregistered);

// DANGLING: ref_pages.href (non-'#') points at a file that does not exist on disk
$dangling = [];
foreach ($registeredHrefs as $href => $meta) {
    // href is relative to web root — resolve against $root/public
    $absPath = $root . '/public' . $href;
    if (!file_exists($absPath)) {
        $dangling[] = ['href' => $href, 'page_key' => $meta['page_key']];
    }
}
usort($dangling, fn($a, $b) => strcmp($a['href'], $b['href']));

// INFORMATIONAL: sanity anomalies (not counted as drift for exit code)
$infoRows = [];

// Active placeholder: is_active=1 but href='#'
foreach ($refPages as $row) {
    if ($row['href'] === '#' && (bool) $row['is_active'] === true) {
        $infoRows[] = [
            'class' => 'ACTIVE-PLACEHOLDER',
            'msg'   => sprintf(
                "page_key='%s' is is_active=1 but href='#' (placeholder still marked active)",
                $row['page_key']
            ),
        ];
    }
}

// Inactive-with-file: is_active=0, href!='#', file exists on disk
foreach ($registeredHrefs as $href => $meta) {
    if (!$meta['is_active']) {
        $absPath = $root . '/public' . $href;
        if (file_exists($absPath)) {
            $infoRows[] = [
                'class' => 'INACTIVE-WITH-FILE',
                'msg'   => sprintf(
                    "page_key='%s' href='%s' is is_active=0 but file exists (built but hidden)",
                    $meta['page_key'],
                    $href
                ),
            ];
        }
    }
}

// ── 5. Output ────────────────────────────────────────────────────────────────

$hasDrift = count($unregistered) > 0 || count($dangling) > 0;

if (!$quiet) {
    $line = str_repeat('─', 72);

    // UNREGISTERED
    echo "\n{$line}\n";
    echo "UNREGISTERED  (file on disk, no ref_pages row)\n";
    echo "{$line}\n";
    if (count($unregistered) === 0) {
        echo "  (none)\n";
    } else {
        foreach ($unregistered as $href) {
            echo "  [UNREGISTERED]  {$href}\n";
        }
    }

    // DANGLING
    echo "\n{$line}\n";
    echo "DANGLING  (ref_pages.href points at missing file)\n";
    echo "{$line}\n";
    if (count($dangling) === 0) {
        echo "  (none)\n";
    } else {
        foreach ($dangling as $item) {
            echo "  [DANGLING]  href='{$item['href']}'  page_key='{$item['page_key']}'\n";
        }
    }

    // INFORMATIONAL
    echo "\n{$line}\n";
    echo "INFORMATIONAL  (sanity anomalies — not counted as drift)\n";
    echo "{$line}\n";
    if (count($infoRows) === 0) {
        echo "  (none)\n";
    } else {
        foreach ($infoRows as $item) {
            echo "  [{$item['class']}]  {$item['msg']}\n";
        }
    }

    echo "\n";
}

// Summary line (always printed, even with --quiet)
$uCount = count($unregistered);
$dCount = count($dangling);
$iCount = count($infoRows);
$status = $hasDrift ? 'DRIFT DETECTED' : 'OK';
printf(
    "reconcile-pages: %s  |  unregistered=%d  dangling=%d  informational=%d\n",
    $status,
    $uCount,
    $dCount,
    $iCount
);

exit($hasDrift ? 1 : 0);
