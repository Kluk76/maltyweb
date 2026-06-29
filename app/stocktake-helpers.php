<?php
declare(strict_types=1);
/**
 * stocktake-helpers.php — Shared helpers for FG stocktake writes.
 * Required by:
 *   - public/modules/expeditions.php (view=stocktake POST handler)
 *   - public/api/stocktake-line-upsert.php (guided per-SKU advance)
 */

function exp_st_scope_allowed(string $scope, string $siteType): bool {
    return match ($scope) {
        'base'   => true,
        'cage'   => in_array($siteType, ['production', 'warehouse'], true),
        'single' => $siteType === 'pos',
        'none'   => false,
        default  => false,
    };
}

function exp_st_snapshot(PDO $pdo, int $locId): void {
    try {
        $stmt = $pdo->prepare(
            'SELECT * FROM inv_fg_stocktake WHERE location_id_fk = ? AND is_active = 1 ORDER BY id'
        );
        $stmt->execute([$locId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $dir = __DIR__ . '/../data/fg-stocktake-snapshots';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $filename = $dir . '/loc' . $locId . '-' . date('Y-m-d\TH-i-s') . '.json';
        file_put_contents($filename, json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        // Keep last 3 snapshots for this location
        $pattern = $dir . '/loc' . $locId . '-*.json';
        $files = glob($pattern);
        if ($files && count($files) > 3) {
            usort($files, fn($a, $b) => strcmp($a, $b));
            foreach (array_slice($files, 0, count($files) - 3) as $old) {
                @unlink($old);
            }
        }
    } catch (Throwable $e) {
        error_log('[exp_st_snapshot] ' . $e->getMessage());
        // Best-effort — never throws
    }
}

/**
 * Returns true when $monthKey has a sealed COGS fiche.
 * Fail-closed: a seal-check error blocks the edit (refuse-don't-corrupt).
 */
function exp_st_month_is_sealed(PDO $pdo, string $monthKey): bool {
    try {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM cogs_fiche_sealed WHERE month_key = ?'
        );
        $stmt->execute([$monthKey]);
        return (int) $stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        error_log('[exp_st_month_is_sealed] ' . $e->getMessage());
        return true; // fail-CLOSED
    }
}

function exp_st_has_month_end_row(PDO $pdo, int $locId, string $countedAt): bool {
    try {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM inv_fg_stocktake
             WHERE location_id_fk = ? AND counted_at = ?
               AND count_type = \'month_end\' AND is_active = 1'
        );
        $stmt->execute([$locId, $countedAt]);
        return (int) $stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        error_log('[exp_st_has_month_end_row] ' . $e->getMessage());
        return false;
    }
}

/**
 * Core upsert for one inv_fg_stocktake row. Caller owns the transaction.
 *
 * SQL is byte-identical to the original batch POST handler in expeditions.php.
 * Note: row_hash is a STORED GENERATED column — MySQL computes it automatically
 * from (sku_id_fk, location_id_fk, counted_at); do NOT include it in the INSERT.
 * The UNIQUE KEY on row_hash is what triggers ON DUPLICATE KEY UPDATE.
 *
 * Returns ['ok'=>true,'pk'=>N] or ['ok'=>false,'error'=>string].
 * Never throws — catches all Throwable internally.
 */
function exp_st_do_upsert(
    PDO    $pdo,
    array  $me,
    int    $locId,
    string $countedAt,
    string $countType,
    int    $skuId,
    float  $qty,
    string $skuCode,
    string $auditNote = 'Saisie inventaire FG multi-site'
): array {
    try {
        $monthClosed = substr($countedAt, 0, 7); // 'YYYY-MM'

        // row_hash = sha256("fgct|{sku_id_fk}|{location_id_fk}|{counted_at}")
        // Used only to look up the before-row for log_revision. MySQL generates the
        // stored column automatically — we must NOT include row_hash in the INSERT list.
        $rowHash = hash('sha256', 'fgct|' . $skuId . '|' . $locId . '|' . $countedAt);

        // Read existing row for log_revision before-snapshot
        $beforeStmt = $pdo->prepare(
            'SELECT * FROM inv_fg_stocktake WHERE row_hash = ? LIMIT 1'
        );
        $beforeStmt->execute([$rowHash]);
        $beforeRow    = $beforeStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        $beforeRowInt = $beforeRow !== null ? (int) $beforeRow['id'] : 0;

        // Exact same INSERT as the original batch POST handler
        $insSt = $pdo->prepare(
            'INSERT INTO inv_fg_stocktake
                (sku, sku_id_fk, source, counted_at, month_closed, qty, submitted_by,
                 source_form_response_id, location_id_fk, is_active, count_type)
             VALUES (?, ?, ?, ?, ?, ?, ?, NULL, ?, 1, ?)
             ON DUPLICATE KEY UPDATE
                qty          = VALUES(qty),
                submitted_by = VALUES(submitted_by),
                counted_at   = VALUES(counted_at),
                count_type   = VALUES(count_type),
                updated_at   = CURRENT_TIMESTAMP'
        );
        $insSt->execute([
            $skuCode,
            $skuId,
            'maltyweb-form',
            $countedAt,
            $monthClosed,
            $qty,
            $me['username'],
            $locId,
            $countType,
        ]);

        // Fetch PK after upsert (lastInsertId() is 0 on UPDATE branch)
        $upsertedPk = (int) $pdo->lastInsertId();
        if ($upsertedPk === 0 && $beforeRowInt > 0) {
            $upsertedPk = $beforeRowInt;
        }

        if ($upsertedPk > 0) {
            log_revision($pdo, $me, 'inv_fg_stocktake', $upsertedPk, $beforeRow,
                [
                    'sku'             => $skuCode,
                    'sku_id_fk'       => $skuId,
                    'location_id_fk'  => $locId,
                    'counted_at'      => $countedAt,
                    'month_closed'    => $monthClosed,
                    'qty'             => $qty,
                    'source'          => 'maltyweb-form',
                    'submitted_by'    => $me['username'],
                    'count_type'      => $countType,
                ],
                'normal',
                $auditNote);
        }

        return ['ok' => true, 'pk' => $upsertedPk];
    } catch (Throwable $e) {
        error_log('[exp_st_do_upsert] sku=' . $skuCode . ' loc=' . $locId . ' ' . $e->getMessage());
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}
