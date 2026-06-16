<?php
declare(strict_types=1);
/**
 * POST /api/cogs-fiche-seal.php — Sceller (ou restater) la fiche COGS pour un mois.
 *
 * Role gate: admin OU manager avec scope 'finance'.
 *
 * POST params:
 *   csrf    (string, required)
 *   month   (string, YYYY-MM, required)
 *   note    (string, optional on first seal; REQUIRED on restatement ≥ 1 char)
 *
 * Redirects (PRG): 303 → /modules/financier.php?fiche_month=YYYY-MM
 *   Flash 'ok'  on success.
 *   Flash 'err' on validation/runtime failure.
 *
 * Method: POST only (GET → 405).
 */

require __DIR__ . '/../../app/auth.php';
require __DIR__ . '/../../app/csrf.php';
require_once __DIR__ . '/../../app/settings-helpers.php';
require_once __DIR__ . '/../../app/db-write-helpers.php';
require_once __DIR__ . '/../../app/cogs-fiche-resolve.php';

require_login();

$me = current_user();

// Role gate: admin OR manager with 'finance' scope.
if (!is_admin($me) && !manager_can('finance', $me)) {
    flash_set('err', 'Accès refusé — rôle admin ou finance requis.');
    redirect_to('/modules/financier.php');
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    flash_set('err', 'Méthode non autorisée.');
    redirect_to('/modules/financier.php');
}

// CSRF first — before any param parsing.
if (!csrf_verify($_POST['csrf'] ?? null)) {
    flash_set('err', 'Session expirée — veuillez réessayer.');
    redirect_to('/modules/financier.php');
}

// ── Params ────────────────────────────────────────────────────────────────────

$month = trim($_POST['month'] ?? '');
if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    flash_set('err', 'Mois invalide.');
    redirect_to('/modules/financier.php');
}

$note    = trim($_POST['note'] ?? '');
$baseUrl = '/modules/financier.php';

// ── Check whether this is a restatement (month already sealed) ────────────────

try {
    $pdo      = maltytask_pdo();
    $resolved = cogs_fiche_resolve_month($pdo, $month);
} catch (\Throwable $e) {
    error_log('[cogs-fiche-seal POST] resolve failed: ' . $e->getMessage());
    flash_set('err', 'Erreur DB — ' . pdo_friendly_error($e, 'resolve'));
    redirect_to($baseUrl);
}

$isRestatement = ($resolved['provenance'] === 'sealed');

// On restatement, note is mandatory (operator must document the reason).
if ($isRestatement && mb_strlen($note) === 0) {
    flash_set('err', 'Le motif de restatement est obligatoire.');
    redirect_to($baseUrl);
}

// ── Apply seal ────────────────────────────────────────────────────────────────

$sealedBy = (string)($me['display_name'] ?? $me['username'] ?? 'unknown');

try {
    $firstId = cogs_fiche_seal_month(
        $pdo,
        $month,
        $sealedBy,
        mb_strlen($note) > 0 ? $note : null
    );

    // Audit log
    log_revision(
        $pdo,
        $me,
        'cogs_fiche_sealed',
        $firstId,
        null,
        [
            'month_key'   => $month,
            'sealed_by'   => $sealedBy,
            'restatement' => $isRestatement,
            'note'        => $note ?: null,
        ],
        'normal',
        $note ?: null
    );
} catch (\RuntimeException $e) {
    flash_set('err', $e->getMessage());
    redirect_to($baseUrl);
} catch (\Throwable $e) {
    error_log('[cogs-fiche-seal POST] ' . $e->getMessage());
    flash_set('err', 'Erreur inattendue — ' . pdo_friendly_error($e, 'seal'));
    redirect_to($baseUrl);
}

if ($isRestatement) {
    flash_set('ok', 'Fiche ' . $month . ' restatée et rescellée avec succès.');
} else {
    flash_set('ok', 'Fiche ' . $month . ' scellée définitivement.');
}

redirect_to($baseUrl);
