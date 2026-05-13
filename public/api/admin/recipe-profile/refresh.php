<?php
declare(strict_types=1);

/**
 * POST /api/admin/recipe-profile/refresh.php — trigger a synchronous recipe-profile rebuild.
 *
 * Flow:
 *   1. Auth check (require_login)
 *   2. Role gate (admin or manager only)
 *   3. Method gate (POST only)
 *   4. CSRF verify
 *   5. exec parse_bd_ingredients.py --apply
 *   6. exec refresh_recipe_profile.py --apply
 *   7. Return JSON result
 *
 * Both scripts run synchronously under sudo -u maltytask.
 * Time limit: 120 s (scripts complete in seconds under normal load).
 */

require __DIR__ . '/../../../../app/auth.php';
require __DIR__ . '/../../../../app/csrf.php';

require_login();

$me = current_user();
if (!in_array($me['role'] ?? '', ['admin', 'manager'], true)) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'Accès réservé aux comptes admin et manager.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'Méthode non autorisée.']);
    exit;
}

if (!csrf_verify($_POST['csrf'] ?? null)) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'Token CSRF invalide.']);
    exit;
}

set_time_limit(120);

$base_cmd = 'sudo -u maltytask python3 /var/www/maltytask/scripts/python/%s --apply 2>&1';

$t_start = hrtime(true);

// ── Step 1: parse_bd_ingredients.py ──────────────────────────────────────────
exec(sprintf($base_cmd, 'parse_bd_ingredients.py'), $parse_out, $parse_rc);
$parse_output = implode("\n", $parse_out);

if ($parse_rc !== 0) {
    $elapsed = (int) round((hrtime(true) - $t_start) / 1_000_000);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok'            => false,
        'error'         => 'parse_bd_ingredients.py a échoué (code ' . $parse_rc . ').',
        'step'          => 'parse',
        'parse_output'  => $parse_output,
        'elapsed_ms'    => $elapsed,
    ]);
    exit;
}

// ── Step 2: refresh_recipe_profile.py ────────────────────────────────────────
exec(sprintf($base_cmd, 'refresh_recipe_profile.py'), $refresh_out, $refresh_rc);
$refresh_output = implode("\n", $refresh_out);

$elapsed = (int) round((hrtime(true) - $t_start) / 1_000_000);

if ($refresh_rc !== 0) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok'             => false,
        'error'          => 'refresh_recipe_profile.py a échoué (code ' . $refresh_rc . ').',
        'step'           => 'refresh',
        'parse_output'   => $parse_output,
        'refresh_output' => $refresh_output,
        'elapsed_ms'     => $elapsed,
    ]);
    exit;
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'ok'             => true,
    'parse_output'   => $parse_output,
    'refresh_output' => $refresh_output,
    'elapsed_ms'     => $elapsed,
]);
