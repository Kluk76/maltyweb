<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/db.php';
require_once __DIR__ . '/../../app/tank-simulator.php';
require_once __DIR__ . '/../../app/yeast-eligibility.php';
require_once __DIR__ . '/../../app/planning-eligibility.php';

require_login();
require_page_access('planning');

// Read ?week=YYYY-MM-DD — read with ?? default THEN validate
$weekRaw = isset($_GET['week']) ? (string)$_GET['week'] : '';
$weekStart = null;
if ($weekRaw !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $weekRaw)) {
    $dt = DateTimeImmutable::createFromFormat('Y-m-d', $weekRaw);
    if ($dt !== false) {
        // Snap to Monday
        $dow = (int)$dt->format('N');
        $weekStart = $dt->modify('-' . ($dow - 1) . ' days')->setTime(0,0,0);
    }
}
if ($weekStart === null) {
    $now = new DateTimeImmutable();
    $dow = (int)$now->format('N');
    $weekStart = $now->modify('-' . ($dow - 1) . ' days')->setTime(0,0,0);
}

$pdo = maltytask_pdo();
$eligibility = planning_week_eligibility($pdo, $weekStart);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
echo json_encode($eligibility, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
