<?php
declare(strict_types=1);

require __DIR__ . "/../../app/auth.php";
require __DIR__ . "/../../app/services/document_preview.php";
require __DIR__ . "/../../app/services/triage_actions.php";
require __DIR__ . "/../../app/csrf.php";

require_login();
$me = current_user();

header("Content-Type: text/html; charset=utf-8");

$active_module = "triage";
$crumbs        = ["Accueil", "Triage"];

// ── Flash messages from redirects ─────────────────────────────────────────────
maltytask_session_start();
$triageFlash = null;
if (!empty($_SESSION['triage_flash'])) {
    $triageFlash = $_SESSION['triage_flash'];
    unset($_SESSION['triage_flash']);
}

// ── Action routing (create modal) ─────────────────────────────────────────────
$triageAction = trim($_GET['action'] ?? '');
$triageLineIdx = isset($_GET['line']) ? (int)$_GET['line'] : 0;

// ── Tab routing ───────────────────────────────────────────────────────────────
$allowedTabs = ["docs", "stock"];
if (is_admin($me)) {
    $allowedTabs[] = "form";
}

$activeTab = $_GET["tab"] ?? "docs";
if (!in_array($activeTab, $allowedTabs, true)) {
    $activeTab = "docs";
}

// ── Search query ──────────────────────────────────────────────────────────────
$searchQ = trim($_GET["q"] ?? "");
$searchQ = substr($searchQ, 0, 200); // cap length

// ── Type sets ─────────────────────────────────────────────────────────────────
$docTypes   = ["invoice-line-items-needed", "doc-classify-ambiguous", "invoice-no-dn", "dn-no-invoice", "photonote-audit"];
$stockTypes = ["rm-stale", "rm-negative", "rm-orphan-mi", "dynamic-vs-take-drift"];

// ── Count badges + load docs tab data ────────────────────────────────────────
$countDocs  = 0;
$countStock = 0;
$countForm  = 0;
$dbError    = null;

// Docs tab pagination
const TRIAGE_PAGE_SIZE = 50;
$page   = max(0, (int) ($_GET["page"] ?? 0));
$offset = $page * TRIAGE_PAGE_SIZE;

// Stock tab pagination + filter defaults (initialized before try block — const can't be inside try)
const STOCK_PAGE_SIZE = 50;
$stockFilterType     = "";
$stockFilterPriority = 0;
$stockFilterStatus   = "open";
$stockRows           = [];
$stockTotal          = 0;
$stockKpi            = ["total" => 0, "rm-stale" => 0, "rm-negative" => 0,
                        "rm-orphan-mi" => 0, "dynamic-vs-take-drift" => 0];
$stockPage           = max(0, (int) ($_GET["stock_page"] ?? 0));
$stockOffset         = $stockPage * STOCK_PAGE_SIZE;
$stockLastPage       = 0;

// Selected rq row
$rqId    = isset($_GET["rq_id"]) ? (int) $_GET["rq_id"] : 0;
$rqRow   = null;   // selected doc_review_queue row
$rqFile  = null;   // associated doc_files row
$rqInv   = null;   // associated doc_invoices row (if any)
$docRows = [];     // inbox list
$totalDocRows = 0;

try {
    $pdo = maltytask_pdo();

    // ── Badges ────────────────────────────────────────────────────────────────

    // Documents badge — affected by search
    $docWhere  = "WHERE rq.status = 'open' AND rq.type IN (" . implode(",", array_fill(0, count($docTypes), "?")) . ")";
    $docParams = $docTypes;

    if ($searchQ !== "") {
        $docWhere  .= " AND (rq.value LIKE ? OR rq.context LIKE ?)";
        $docParams[] = "%" . $searchQ . "%";
        $docParams[] = "%" . $searchQ . "%";
    }

    $stmtD = $pdo->prepare("SELECT COUNT(*) FROM doc_review_queue rq $docWhere");
    $stmtD->execute($docParams);
    $countDocs = (int) $stmtD->fetchColumn();

    // Stock badge (no search filtering — always full count)
    $inStock = implode(",", array_fill(0, count($stockTypes), "?"));
    $stmtS   = $pdo->prepare("SELECT COUNT(*) FROM doc_review_queue WHERE status = 'open' AND type IN ($inStock)");
    $stmtS->execute($stockTypes);
    $countStock = (int) $stmtS->fetchColumn();

    // Form badge
    if (is_admin($me)) {
        $stmtF   = $pdo->query("SELECT COUNT(*) FROM ingest_failures WHERE resolved_at IS NULL");
        $countForm = (int) $stmtF->fetchColumn();
    }

    // ── Stock tab: filters + data ─────────────────────────────────────────────
    if ($activeTab === "stock") {
        $stockFilterType     = in_array($_GET["type"] ?? "", array_merge([""], $stockTypes), true)
                               ? ($_GET["type"] ?? "") : "";
        $stockFilterPriority = in_array((int)($_GET["min_priority"] ?? 0), [0, 50, 100], true)
                               ? (int)($_GET["min_priority"] ?? 0) : 0;
        $stockFilterStatus   = in_array($_GET["status"] ?? "open", ["open", "all", "resolved"], true)
                               ? ($_GET["status"] ?? "open") : "open";

        // KPI counts (always over full open set, no type filter)
        $inStk = implode(",", array_fill(0, count($stockTypes), "?"));
        $kpiPerTypeStmt = $pdo->prepare(
            "SELECT type, COUNT(*) AS cnt
               FROM doc_review_queue
              WHERE status = 'open' AND type IN ($inStk)
              GROUP BY type"
        );
        $kpiPerTypeStmt->execute($stockTypes);
        foreach ($kpiPerTypeStmt->fetchAll() as $kr) {
            $t = (string)$kr["type"];
            if (isset($stockKpi[$t])) {
                $stockKpi[$t] = (int)$kr["cnt"];
            }
            $stockKpi["total"] += (int)$kr["cnt"];
        }

        // Build WHERE for filtered query
        $stkWhere  = ["rq.type IN ($inStk)"];
        $stkParams = $stockTypes;

        if ($stockFilterStatus === "open") {
            $stkWhere[] = "rq.status = 'open'";
        } elseif ($stockFilterStatus === "resolved") {
            $stkWhere[] = "rq.status IN ('resolved', 'rejected')";
        }
        // "all" = no status filter

        if ($stockFilterType !== "") {
            $stkWhere[]  = "rq.type = ?";
            $stkParams[] = $stockFilterType;
        }

        if ($stockFilterPriority > 0) {
            $stkWhere[]  = "rq.priority >= ?";
            $stkParams[] = $stockFilterPriority;
        }

        $stkWhereSql = "WHERE " . implode(" AND ", $stkWhere);

        // Count
        $stkCntStmt = $pdo->prepare("SELECT COUNT(*) FROM doc_review_queue rq $stkWhereSql");
        $stkCntStmt->execute($stkParams);
        $stockTotal    = (int) $stkCntStmt->fetchColumn();
        $stockLastPage = $stockTotal > 0 ? (int) floor(($stockTotal - 1) / STOCK_PAGE_SIZE) : 0;

        // Rows
        $stkStmt = $pdo->prepare(
            "SELECT rq.id, rq.type, rq.value, rq.context, rq.priority,
                    rq.created_at, rq.status, rq.decision
               FROM doc_review_queue rq
               $stkWhereSql
              ORDER BY rq.priority DESC, rq.created_at ASC
              LIMIT " . STOCK_PAGE_SIZE . " OFFSET $stockOffset"
        );
        $stkStmt->execute($stkParams);
        $stockRows = $stkStmt->fetchAll();
    }

    // ── Docs tab: inbox list ──────────────────────────────────────────────────
    if ($activeTab === "docs") {

        // Count for pagination
        $cntStmt = $pdo->prepare("SELECT COUNT(*) FROM doc_review_queue rq $docWhere");
        $cntStmt->execute($docParams);
        $totalDocRows = (int) $cntStmt->fetchColumn();

        // Inbox query — JOIN doc_files to get drive file_id and file_name
        $listSql = "
            SELECT rq.id,
                   rq.queue_id,
                   rq.type,
                   rq.value,
                   rq.context,
                   rq.priority,
                   rq.created_at,
                   rq.invoice_ref,
                   rq.file_id_fk,
                   f.file_id    AS drive_file_id,
                   f.file_name  AS file_name
              FROM doc_review_queue rq
         LEFT JOIN doc_files f ON f.id = rq.file_id_fk
               $docWhere
          ORDER BY rq.priority DESC, rq.created_at ASC
             LIMIT " . TRIAGE_PAGE_SIZE . " OFFSET $offset";

        $listStmt = $pdo->prepare($listSql);
        $listStmt->execute($docParams);
        $docRows = $listStmt->fetchAll();

        // Auto-select first row if none specified and on page 0
        if ($rqId <= 0 && !empty($docRows)) {
            $rqId = (int) $docRows[0]["id"];
        }

        // Load selected row detail
        if ($rqId > 0) {
            $detailStmt = $pdo->prepare("
                SELECT rq.id,
                       rq.queue_id,
                       rq.type,
                       rq.value,
                       rq.context,
                       rq.priority,
                       rq.created_at,
                       rq.invoice_ref,
                       rq.file_id_fk,
                       f.file_id    AS drive_file_id,
                       f.file_name  AS file_name
                  FROM doc_review_queue rq
             LEFT JOIN doc_files f ON f.id = rq.file_id_fk
                 WHERE rq.id = ? AND rq.type IN (" . implode(",", array_fill(0, count($docTypes), "?")) . ")
                 LIMIT 1
            ");
            $detailStmt->execute(array_merge([$rqId], $docTypes));
            $rqRow = $detailStmt->fetch() ?: null;

            // Load associated invoice data if available
            if ($rqRow !== null && !empty($rqRow["drive_file_id"])) {
                $invStmt = $pdo->prepare("
                    SELECT i.supplier_name, i.invoice_ref, i.invoice_date,
                           i.total_ht, i.total_ttc, i.total_vat,
                           i.currency, i.parser_name, i.ht_source
                      FROM doc_invoices i
                      JOIN doc_files f ON f.id = i.file_id
                     WHERE f.file_id = ?
                     LIMIT 1
                ");
                $invStmt->execute([$rqRow["drive_file_id"]]);
                $rqInv = $invStmt->fetch() ?: null;
            }
        }
    }

} catch (Throwable $e) {
    $dbError = $e->getMessage();
}

$totalPending = $countDocs + $countStock + $countForm;
$lastPage     = $totalDocRows > 0 ? (int) floor(($totalDocRows - 1) / TRIAGE_PAGE_SIZE) : 0;

// ── Helpers ───────────────────────────────────────────────────────────────────

/**
 * Build URL preserving current tab + search, merging extra params.
 */
function triage_qs(array $extra): string
{
    $base = [];
    foreach (["tab", "q", "page", "rq_id"] as $k) {
        if (isset($_GET[$k]) && $_GET[$k] !== "") {
            $base[$k] = $_GET[$k];
        }
    }
    return "?" . http_build_query(array_merge($base, $extra));
}

/**
 * Parse supplier name and invoice ref out of context text.
 * Defined in triage_actions.php (shared service) — guard against redecl.
 */
if (!function_exists('triage_parse_context')):
function triage_parse_context(string $context): array
{
    $result = ["supplier" => null, "ref" => null, "date" => null,
               "total_ht" => null, "drive_url" => null,
               "unresolved" => [], "ocr_preview" => null,
               "reason" => null, "action" => null];

    foreach (explode("\n", $context) as $line) {
        $line = trim($line);
        if ($line === "") continue;

        if (str_starts_with($line, "Supplier:")) {
            $result["supplier"] = trim(substr($line, 9));
        } elseif (str_starts_with($line, "InvoiceRef:")) {
            $result["ref"] = trim(substr($line, 11));
        } elseif (str_starts_with($line, "Date:")) {
            $result["date"] = trim(substr($line, 5));
        } elseif (str_starts_with($line, "TotalHT:")) {
            $result["total_ht"] = trim(substr($line, 8));
        } elseif (str_starts_with($line, "Drive:")) {
            $result["drive_url"] = trim(substr($line, 6));
        } elseif (str_starts_with($line, "Reason:")) {
            $result["reason"] = trim(substr($line, 7));
        } elseif (str_starts_with($line, "Action:")) {
            $result["action"] = trim(substr($line, 7));
        } elseif (preg_match('/^\s*-\s*"(.+)"\s*\(/', $line, $m)) {
            $result["unresolved"][] = $line;
        } elseif (str_starts_with($line, "OCR preview")) {
            // next line(s) until end are the preview
            $result["ocr_preview"] = ""; // will fill below
        } elseif ($result["ocr_preview"] !== null) {
            $result["ocr_preview"] .= ($result["ocr_preview"] === "" ? "" : "\n") . $line;
        }
    }
    return $result;
}
endif;

/**
 * Type badge glyph — single letter for compact inbox display.
 */
function triage_type_glyph(string $type): string
{
    return match ($type) {
        "invoice-line-items-needed" => "I",
        "doc-classify-ambiguous"   => "A",
        "invoice-no-dn"            => "O",
        "dn-no-invoice"            => "O",
        "photonote-audit"          => "P",
        default                    => "?",
    };
}

/**
 * French label for a queue type.
 */
function triage_type_label(string $type): string
{
    return match ($type) {
        "invoice-line-items-needed" => "Lignes manquantes",
        "doc-classify-ambiguous"   => "Classification ambiguë",
        "invoice-no-dn"            => "Facture sans BL",
        "dn-no-invoice"            => "BL sans facture",
        "photonote-audit"          => "PhotoNote",
        default                    => $type,
    };
}

/**
 * Days since a timestamp string.
 */
function triage_age_days(string $ts): int
{
    return (int) floor((time() - strtotime($ts)) / 86400);
}

/**
 * Type glyph + label for stock alert types.
 * Single letter glyphs to match Documents tab density.
 *   S = rm-Stale
 *   N = rm-Negative
 *   O = rm-Orphan-mi
 *   D = Dynamic-vs-take-Drift
 */
function stock_type_glyph(string $type): string
{
    return match ($type) {
        "rm-stale"               => "S",
        "rm-negative"            => "N",
        "rm-orphan-mi"           => "O",
        "dynamic-vs-take-drift"  => "D",
        default                  => "?",
    };
}

function stock_type_label(string $type): string
{
    return match ($type) {
        "rm-stale"               => "Stock dormant",
        "rm-negative"            => "Stock négatif",
        "rm-orphan-mi"           => "MI orphelin",
        "dynamic-vs-take-drift"  => "Dérive inventaire",
        default                  => $type,
    };
}

/**
 * Parse stock-specific context fields from a Key: Value context string.
 * Returns a string for the "Context" column, gracefully emitting — for missing keys.
 */
function stock_parse_context(string $type, string $context): string
{
    // Helper: extract value for a given key prefix
    $get = static function (string $key, string $ctx): ?string {
        foreach (explode("\n", $ctx) as $line) {
            $line = trim($line);
            if (stripos($line, $key . ":") === 0) {
                return trim(substr($line, strlen($key) + 1));
            }
        }
        return null;
    };

    $dash = "—";

    return match ($type) {
        "rm-stale" => sprintf(
            "Dormant depuis : %s j | Qté : %s",
            $get("DaysSinceLastMovement", $context) ?? $dash,
            $get("LastQty", $context) ?? $dash
        ),

        "rm-negative" => sprintf(
            "Qté actuelle : %s | Dernier mouvement : %s",
            $get("CurrentQty", $context) ?? $dash,
            $get("LastMovement", $context) ?? $dash
        ),

        "rm-orphan-mi" => sprintf(
            "Référencé dans : %s",
            $get("Source", $context) ?? $dash
        ),

        "dynamic-vs-take-drift" => (static function () use ($get, $context, $dash): string {
            $take    = $get("Take", $context) ?? $dash;
            $dynamic = $get("Dynamic", $context) ?? $dash;
            $delta   = $get("DeltaPct", $context) ?? ($get("Delta", $context) ?? $dash);
            return "Inventaire : {$take} | Dynamique : {$dynamic} | Δ : {$delta}";
        })(),

        default => substr($context, 0, 120),
    };
}

/**
 * Build a stock-tab URL, preserving current stock filters.
 */
function stock_qs(array $extra): string
{
    $base = ["tab" => "stock"];
    foreach (["type", "min_priority", "status", "stock_page"] as $k) {
        if (isset($_GET[$k]) && $_GET[$k] !== "") {
            $base[$k] = $_GET[$k];
        }
    }
    return "?" . http_build_query(array_merge($base, $extra));
}

?><!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Triage — MaltyTask</title>
  <meta name="csrf-token" content="<?= htmlspecialchars(csrf_token()) ?>">
  <link rel="manifest" href="/manifest.json">
  <meta name="theme-color" content="#15100a">
  <link rel="apple-touch-icon" href="/img/icon-192.png">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,300;0,9..144,400;0,9..144,500;1,9..144,300;1,9..144,400&family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/css/app.css?v=<?= @filemtime(__DIR__ . '/../css/app.css') ?: time() ?>">
  <script>
    // Register service worker — must be at root scope
    if ('serviceWorker' in navigator) {
      navigator.serviceWorker.register('/sw.js', { scope: '/' })
        .catch(function(e) { /* non-fatal — PWA install just won't work */ });
    }
  </script>
</head>
<body class="home triage">

<?php require __DIR__ . "/../../app/partials/sidebar.php" ?>

<?php require __DIR__ . "/../../app/partials/topbar.php" ?>

<main class="main admin__main">

  <?php if ($triageFlash !== null): ?>
    <div class="db-flash db-flash--<?= $triageFlash['type'] === 'ok' ? 'ok' : 'err' ?>">
      <?= htmlspecialchars((string)$triageFlash['msg']) ?>
    </div>
  <?php endif ?>

  <?php if ($dbError !== null): ?>
    <div class="db-flash db-flash--err">Erreur base de données : <?= htmlspecialchars($dbError) ?></div>
  <?php endif ?>

  <!-- ── Page header ── -->
  <div class="triage-header">
    <h1 class="triage-title">Triage</h1>
    <?php if ($totalPending > 0): ?>
      <span class="triage-total-badge"><?= $totalPending ?> en attente</span>
    <?php endif ?>

    <!-- Cross-tab search -->
    <form class="triage-search-form" method="get" action="">
      <input type="hidden" name="tab" value="<?= htmlspecialchars($activeTab) ?>">
      <input class="triage-search-input"
             type="text" name="q"
             value="<?= htmlspecialchars($searchQ) ?>"
             placeholder="Rechercher (fournisseur, ref…)"
             autocomplete="off">
      <?php if ($searchQ !== ""): ?>
        <a class="triage-search-clear" href="?tab=<?= htmlspecialchars($activeTab) ?>"
           title="Effacer la recherche">×</a>
      <?php endif ?>
    </form>
  </div>

  <!-- ── Upload zone (Documents tab) ─────────────────────────────────────── -->
  <div class="upload-zone" id="upload-zone" aria-label="Zone de dépôt de documents">
    <!-- Hidden file inputs -->
    <input type="file" id="upload-browse"
           multiple
           accept=".pdf,.png,.jpg,.jpeg,.heic,.heif,.webp"
           class="upload-browse-input"
           aria-label="Parcourir les fichiers">

    <!-- Drag-drop label (desktop only, hidden on touch) -->
    <div class="upload-zone__drop-target">
      <span class="upload-zone__icon" aria-hidden="true">↑</span>
      <span class="upload-zone__hint">Déposer ou coller un PDF / image ici</span>
      <span class="upload-zone__sep">—</span>
      <button class="upload-zone__browse-btn" id="upload-browse-btn" type="button">
        Parcourir
      </button>
    </div>

    <!-- Status box — hidden when idle -->
    <div class="upload-status" id="upload-status" hidden aria-live="polite" aria-atomic="true"></div>
  </div>

  <!-- ── Multi-shot camera panel (slides up on mobile) ────────────────────── -->
  <div class="multishot-panel" id="multishot-panel" role="dialog" aria-label="Capturer des pages" aria-modal="true">
    <div class="multishot-panel__inner">
      <div class="multishot-panel__header">
        <span class="multishot-panel__title">
          Scanner un document
          <span class="multishot-panel__count" id="multishot-count"></span>
        </span>
        <button class="multishot-panel__close" id="multishot-cancel" type="button" aria-label="Annuler">×</button>
      </div>

      <!-- Page thumbnail strip -->
      <div class="multishot-thumbstrip" id="thumb-strip" aria-label="Pages capturées"></div>

      <!-- Camera capture inputs (open via JS, not shown) -->
      <input type="file" id="fab-camera-input"
             accept="image/*"
             capture="environment"
             class="upload-browse-input"
             aria-label="Prendre une photo">
      <input type="file" id="add-page-input"
             accept="image/*"
             capture="environment"
             class="upload-browse-input"
             aria-label="Ajouter une page">

      <div class="multishot-panel__actions">
        <button class="multishot-add-btn" id="add-page-btn" type="button" style="display:none">
          + Ajouter une page
        </button>
        <button class="multishot-submit-btn" id="multishot-submit" type="button" disabled>
          Envoyer
        </button>
      </div>
    </div>
  </div>

  <!-- ── Capture FAB (touch devices only, fixed bottom-right) ─────────────── -->
  <button class="capture-fab" id="capture-fab" type="button" aria-label="Nouveau document">
    <span class="capture-fab__icon" aria-hidden="true">+</span>
  </button>

  <!-- ── Tab strip ── -->
  <nav class="triage-tabs" aria-label="Onglets Triage">
    <a class="triage-tab<?= $activeTab === "docs"  ? " triage-tab--active" : "" ?>"
       href="?tab=docs<?= $searchQ !== "" ? "&q=" . rawurlencode($searchQ) : "" ?>"
       <?= $activeTab === "docs" ? 'aria-current="true"' : "" ?>>
      Documents
      <?php if ($countDocs > 0): ?>
        <span class="triage-badge"><?= $countDocs ?></span>
      <?php elseif ($dbError === null): ?>
        <span class="triage-badge triage-badge--zero">0</span>
      <?php endif ?>
    </a>

    <a class="triage-tab<?= $activeTab === "stock" ? " triage-tab--active" : "" ?>"
       href="?tab=stock"
       <?= $activeTab === "stock" ? 'aria-current="true"' : "" ?>>
      Stock
      <?php if ($countStock > 0): ?>
        <span class="triage-badge"><?= $countStock ?></span>
      <?php elseif ($dbError === null): ?>
        <span class="triage-badge triage-badge--zero">0</span>
      <?php endif ?>
    </a>

    <?php if (is_admin($me)): ?>
      <a class="triage-tab<?= $activeTab === "form"  ? " triage-tab--active" : "" ?>"
         href="?tab=form"
         <?= $activeTab === "form" ? 'aria-current="true"' : "" ?>>
        Form-ingest
        <?php if ($countForm > 0): ?>
          <span class="triage-badge triage-badge--warn"><?= $countForm ?></span>
        <?php elseif ($dbError === null): ?>
          <span class="triage-badge triage-badge--zero">0</span>
        <?php endif ?>
      </a>
    <?php endif ?>
  </nav>

  <!-- ── Tab content ── -->
  <div class="triage-content">

    <!-- ═══════════════════════ DOCUMENTS TAB ═══════════════════════ -->
    <?php if ($activeTab === "docs"): ?>

      <?php if (empty($docRows) && $dbError === null): ?>
        <!-- Empty state -->
        <div class="triage-empty">
          <span class="triage-empty__icon">✓</span>
          <p class="triage-empty__headline">Boîte de réception vide</p>
          <p class="triage-empty__sub">Rien à trier<?= $searchQ !== "" ? " pour «&nbsp;" . htmlspecialchars($searchQ) . "&nbsp;»" : "" ?></p>
        </div>

      <?php else: ?>

        <div class="triage-pane">

          <!-- ─── LEFT: Inbox list ─── -->
          <div class="inbox-list">

            <?php if ($searchQ !== ""): ?>
              <div class="inbox-search-hint">
                Résultats pour <em><?= htmlspecialchars($searchQ) ?></em>
                — <a href="?tab=docs">effacer</a>
              </div>
            <?php endif ?>

            <?php foreach ($docRows as $row):
                $ctx   = triage_parse_context((string)($row["context"] ?? ""));
                $age   = triage_age_days((string)$row["created_at"]);
                $prio  = (int)$row["priority"];
                $isActive = ((int)$row["id"] === $rqId);

                // Priority class
                $prioCls = $prio >= 100 ? "prio--high" : ($prio >= 50 ? "prio--mid" : "prio--low");

                // Supplier display: prefer context → invoice_ref fallback → value
                $supplierDisp = $ctx["supplier"]
                    ?? ($row["invoice_ref"] ? null : null)
                    ?? triage_extract_supplier_from_value((string)$row["value"]);

                $refDisp   = $ctx["ref"] ?? (string)($row["invoice_ref"] ?? "");
                $rowUrl    = triage_qs(["tab" => "docs", "rq_id" => $row["id"], "page" => $page]);
            ?>
              <a class="inbox-row<?= $isActive ? " inbox-row--active" : "" ?>"
                 href="<?= htmlspecialchars($rowUrl) ?>">
                <span class="inbox-row__glyph inbox-glyph--<?= htmlspecialchars($row["type"]) ?>">
                  <?= triage_type_glyph((string)$row["type"]) ?>
                </span>
                <span class="inbox-row__body">
                  <span class="inbox-row__supplier"<?= $supplierDisp ? ' title="' . htmlspecialchars($supplierDisp) . '"' : '' ?>>
                    <?= $supplierDisp ? htmlspecialchars($supplierDisp) : '<em class="inbox-row__unknown">—</em>' ?>
                  </span>
                  <?php if ($refDisp !== ""): ?>
                    <span class="inbox-row__ref" title="<?= htmlspecialchars($refDisp) ?>"><?= htmlspecialchars($refDisp) ?></span>
                  <?php endif ?>
                </span>
                <span class="inbox-row__meta">
                  <span class="inbox-row__age"><?= $age ?>j</span>
                  <span class="inbox-row__prio <?= $prioCls ?>"></span>
                </span>
              </a>
            <?php endforeach ?>

            <!-- Pagination -->
            <?php if ($lastPage > 0): ?>
              <nav class="inbox-pagination" aria-label="Pagination inbox">
                <?php if ($page > 0): ?>
                  <a class="inbox-pagination__link"
                     href="<?= htmlspecialchars(triage_qs(["tab" => "docs", "page" => $page - 1])) ?>">←</a>
                <?php else: ?>
                  <span class="inbox-pagination__link inbox-pagination__link--off">←</span>
                <?php endif ?>
                <span class="inbox-pagination__pos"><?= $page + 1 ?>/<?= $lastPage + 1 ?></span>
                <?php if ($page < $lastPage): ?>
                  <a class="inbox-pagination__link"
                     href="<?= htmlspecialchars(triage_qs(["tab" => "docs", "page" => $page + 1])) ?>">→</a>
                <?php else: ?>
                  <span class="inbox-pagination__link inbox-pagination__link--off">→</span>
                <?php endif ?>
              </nav>
            <?php endif ?>
          </div><!-- /inbox-list -->

          <!-- ─── RIGHT: Detail panel ─── -->
          <div class="detail-panel">
            <?php if ($rqRow === null): ?>
              <div class="triage-empty">
                <span class="triage-empty__icon">←</span>
                <p class="triage-empty__headline">Sélectionnez un document</p>
                <p class="triage-empty__sub">Cliquez une ligne dans la liste</p>
              </div>
            <?php else:
                $ctx      = triage_parse_context((string)($rqRow["context"] ?? ""));
                $age      = triage_age_days((string)$rqRow["created_at"]);
                $prio     = (int)$rqRow["priority"];
                $type     = (string)$rqRow["type"];
                $driveId  = (string)($rqRow["drive_file_id"] ?? "");
                $prioCls  = $prio >= 100 ? "prio--high" : ($prio >= 50 ? "prio--mid" : "prio--low");
                $supplierDisp = $ctx["supplier"]
                    ?? ($rqInv ? (string)$rqInv["supplier_name"] : null)
                    ?? triage_extract_supplier_from_value((string)$rqRow["value"]);
                $refDisp  = $ctx["ref"] ?? (string)($rqRow["invoice_ref"] ?? "");
                $driveUrl = $ctx["drive_url"]
                    ?? ($driveId !== "" ? "https://drive.google.com/file/d/" . rawurlencode($driveId) . "/view" : null);

                // ── Create modal shortcut ──────────────────────────────────────
                if ($triageAction === "create" && $type === "invoice-line-items-needed") {
                    include __DIR__ . "/triage_mi_create_modal.php";
                    echo '</div><!-- /detail-panel -->';
                    // skip remainder of detail rendering
                    goto end_detail_panel;
                }
            ?>

              <!-- 1. HEADER -->
              <div class="detail-header">
                <div class="detail-header__left">
                  <span class="detail-type-badge detail-type-badge--<?= htmlspecialchars($type) ?>">
                    <?= htmlspecialchars(triage_type_label($type)) ?>
                  </span>
                  <h2 class="detail-supplier">
                    <?= $supplierDisp ? htmlspecialchars($supplierDisp) : '<em>Fournisseur inconnu</em>' ?>
                  </h2>
                  <?php if ($refDisp !== ""): ?>
                    <span class="detail-ref"><?= htmlspecialchars($refDisp) ?></span>
                  <?php endif ?>
                </div>
                <div class="detail-header__right">
                  <span class="detail-age"><?= $age ?>j</span>
                  <span class="detail-prio <?= $prioCls ?>"><?= $prio ?></span>
                  <?php if ($driveUrl !== null): ?>
                    <a class="detail-drive-link"
                       href="<?= htmlspecialchars($driveUrl) ?>"
                       target="_blank" rel="noopener"
                       title="Ouvrir dans Google Drive">↗ Drive</a>
                  <?php endif ?>
                  <?php if (is_admin($me)): ?>
                    <span class="detail-rqid" title="RQ ID (admin only)">#<?= (int)$rqRow["id"] ?></span>
                  <?php endif ?>
                </div>
              </div>

              <!-- 2. PDF PREVIEW -->
              <?php if ($driveId !== ""): ?>
                <div class="detail-preview">
                  <div class="detail-preview__label">Aperçu document</div>
                  <!-- Desktop: inline PDF iframe -->
                  <iframe class="doc-preview-iframe"
                          src="/api/document.php?file_id=<?= rawurlencode($driveId) ?>"
                          title="Aperçu PDF <?= htmlspecialchars($supplierDisp ?? $driveId) ?>">
                  </iframe>
                  <!-- Mobile: page-1 PNG + link -->
                  <div class="doc-preview-mobile">
                    <img class="doc-preview-png"
                         src="/api/document-preview-png.php?file_id=<?= rawurlencode($driveId) ?>"
                         alt="Page 1 — <?= htmlspecialchars($supplierDisp ?? $driveId) ?>"
                         loading="lazy">
                    <a class="doc-preview-mobile__link"
                       href="/api/document.php?file_id=<?= rawurlencode($driveId) ?>"
                       target="_blank" rel="noopener">
                      Voir le PDF complet ↗
                    </a>
                  </div>
                </div>
              <?php endif ?>

              <!-- 3. TYPE-AWARE DATA SECTION -->
              <div class="detail-section">

                <?php if ($type === "invoice-line-items-needed"): ?>
                  <!-- Invoice header from doc_invoices -->
                  <?php if ($rqInv !== null): ?>
                    <div class="detail-section__head">Facture</div>
                    <dl class="detail-meta">
                      <?php if (!empty($rqInv["supplier_name"])): ?>
                        <div class="detail-meta__row">
                          <dt>Fournisseur</dt>
                          <dd><?= htmlspecialchars((string)$rqInv["supplier_name"]) ?></dd>
                        </div>
                      <?php endif ?>
                      <?php if (!empty($rqInv["invoice_ref"])): ?>
                        <div class="detail-meta__row">
                          <dt>Référence</dt>
                          <dd><?= htmlspecialchars((string)$rqInv["invoice_ref"]) ?></dd>
                        </div>
                      <?php endif ?>
                      <?php if (!empty($rqInv["invoice_date"])): ?>
                        <div class="detail-meta__row">
                          <dt>Date</dt>
                          <dd><?= htmlspecialchars((string)$rqInv["invoice_date"]) ?></dd>
                        </div>
                      <?php endif ?>
                      <?php if ($rqInv["total_ht"] !== null): ?>
                        <div class="detail-meta__row">
                          <dt>Total HT</dt>
                          <dd class="detail-meta__amount">
                            <?= number_format((float)$rqInv["total_ht"], 2, '.', "'") ?>
                            <?= htmlspecialchars((string)($rqInv["currency"] ?? "CHF")) ?>
                          </dd>
                        </div>
                      <?php endif ?>
                      <?php if ($rqInv["total_ttc"] !== null): ?>
                        <div class="detail-meta__row">
                          <dt>Total TTC</dt>
                          <dd class="detail-meta__amount detail-meta__amount--muted">
                            <?= number_format((float)$rqInv["total_ttc"], 2, '.', "'") ?>
                            <?= htmlspecialchars((string)($rqInv["currency"] ?? "CHF")) ?>
                          </dd>
                        </div>
                      <?php endif ?>
                      <?php if (!empty($rqInv["parser_name"])): ?>
                        <div class="detail-meta__row">
                          <dt>Parser</dt>
                          <dd><code class="detail-mono"><?= htmlspecialchars((string)$rqInv["parser_name"]) ?></code></dd>
                        </div>
                      <?php endif ?>
                    </dl>
                  <?php elseif ($ctx["total_ht"] !== null || $ctx["ref"] !== null): ?>
                    <!-- Fallback: from context text -->
                    <div class="detail-section__head">Facture (contexte OCR)</div>
                    <dl class="detail-meta">
                      <?php if ($ctx["ref"] !== null): ?>
                        <div class="detail-meta__row"><dt>Référence</dt><dd><?= htmlspecialchars($ctx["ref"]) ?></dd></div>
                      <?php endif ?>
                      <?php if ($ctx["date"] !== null): ?>
                        <div class="detail-meta__row"><dt>Date</dt><dd><?= htmlspecialchars($ctx["date"]) ?></dd></div>
                      <?php endif ?>
                      <?php if ($ctx["total_ht"] !== null): ?>
                        <div class="detail-meta__row"><dt>Total HT</dt><dd class="detail-meta__amount"><?= htmlspecialchars($ctx["total_ht"]) ?></dd></div>
                      <?php endif ?>
                    </dl>
                  <?php endif ?>

                  <!-- Unresolved line items -->
                  <?php if (empty($ctx["unresolved"])): ?>
                    <div class="detail-section__head detail-section__head--warn">
                      Aucune ligne extraite
                    </div>
                    <p class="detail-no-lines">
                      Le parser a accepté la facture mais n'a pu extraire aucune ligne d'item
                      (fréquent pour les factures utilitaires SIE/SIL ou les scans dégradés).
                      Utilise les actions ci-dessous pour clore ou rejeter cette entrée.
                    </p>
                  <?php else:
                      $openLineCount = ta_count_open_lines($ctx["unresolved"]);
                  ?>
                    <div class="detail-section__head detail-section__head--warn">
                      Lignes non résolues
                      <span class="detail-section__count"><?= $openLineCount ?></span>
                    </div>

                    <!-- Load all active MIs once for the alias dropdowns -->
                    <?php
                    $allMisForAlias = [];
                    try {
                        $misStmt = $pdo->query(
                            "SELECT mi_id, name FROM ref_mi WHERE is_active=1 ORDER BY mi_id"
                        );
                        $allMisForAlias = $misStmt->fetchAll();
                    } catch (Throwable $_e) {}
                    ?>

                    <ul class="detail-lines">
                      <?php foreach ($ctx["unresolved"] as $lineIdx => $rawLine):
                          $lineParsed = ta_parse_unresolved_line($rawLine);
                          $isResolved = $lineParsed["resolved"];
                      ?>
                        <li class="detail-line<?= $isResolved ? " detail-line--resolved" : "" ?>">
                          <div class="detail-line__body">
                            <span class="detail-line__text">
                              <?= htmlspecialchars($lineParsed["raw"] ?? $rawLine) ?>
                            </span>
                            <?php if ($lineParsed["lineTotal"] !== null): ?>
                              <span class="detail-line__amount">
                                <?= number_format($lineParsed["lineTotal"], 2, ".", "'") ?>
                              </span>
                            <?php endif ?>
                            <?php if ($isResolved): ?>
                              <span class="detail-line__status detail-line__status--done">✓ résolu</span>
                            <?php else: ?>
                              <span class="detail-line__status">→ MI manquant</span>
                            <?php endif ?>
                          </div>

                          <?php if (!$isResolved): ?>
                          <!-- Per-line actions -->
                          <div class="unresolved-line-actions">

                            <!-- Alias form (inline) -->
                            <details class="line-alias-details">
                              <summary class="detail-btn detail-btn--alias">Alias MI existant ▾</summary>
                              <form class="line-alias-form" method="post"
                                    action="/api/triage/alias.php">
                                <input type="hidden" name="csrf"       value="<?= htmlspecialchars(csrf_token()) ?>">
                                <input type="hidden" name="rq_id"      value="<?= (int)$rqRow["id"] ?>">
                                <input type="hidden" name="line_index" value="<?= $lineIdx ?>">
                                <div class="line-alias-form__row">
                                  <input class="line-alias-form__text"
                                         type="text" name="alias_text"
                                         value="<?= htmlspecialchars($lineParsed["raw"] ?? "") ?>"
                                         placeholder="Texte alias"
                                         required>
                                  <select class="line-alias-form__select" name="target_mi_id" required>
                                    <option value="">— Choisir MI —</option>
                                    <?php foreach ($allMisForAlias as $mi): ?>
                                      <option value="<?= htmlspecialchars($mi["mi_id"]) ?>">
                                        <?= htmlspecialchars($mi["mi_id"]) ?> — <?= htmlspecialchars((string)$mi["name"]) ?>
                                      </option>
                                    <?php endforeach ?>
                                  </select>
                                  <button type="submit" class="detail-btn detail-btn--alias line-alias-form__btn">
                                    Sauvegarder
                                  </button>
                                </div>
                              </form>
                            </details>

                            <!-- Create modal link -->
                            <a class="detail-btn detail-btn--create"
                               href="<?= htmlspecialchars(triage_qs([
                                   "tab"    => "docs",
                                   "rq_id"  => $rqRow["id"],
                                   "action" => "create",
                                   "line"   => $lineIdx,
                               ])) ?>">
                              Créer nouveau MI
                            </a>

                            <!-- Per-line reject -->
                            <details class="line-reject-details">
                              <summary class="detail-btn detail-btn--reject detail-btn--sm">Ignorer ▾</summary>
                              <form class="line-reject-form" method="post"
                                    action="/api/triage/reject.php">
                                <input type="hidden" name="csrf"       value="<?= htmlspecialchars(csrf_token()) ?>">
                                <input type="hidden" name="rq_id"      value="<?= (int)$rqRow["id"] ?>">
                                <input type="hidden" name="line_index" value="<?= $lineIdx ?>">
                                <div class="line-reject-form__row">
                                  <input class="line-reject-form__text"
                                         type="text" name="reason"
                                         placeholder="Raison (optionnel)">
                                  <button type="submit"
                                          class="detail-btn detail-btn--reject line-reject-form__btn">
                                    Confirmer
                                  </button>
                                </div>
                              </form>
                            </details>

                          </div><!-- /unresolved-line-actions -->
                          <?php endif ?>
                        </li>
                      <?php endforeach ?>
                    </ul>

                    <!-- Resolve & close button when 1+ line was already actioned -->
                    <?php
                    $resolvedCount = count($ctx["unresolved"]) - $openLineCount;
                    if ($resolvedCount > 0 && $openLineCount === 0):
                    ?>
                      <p class="detail-all-resolved">
                        Toutes les lignes sont résolues.
                        Cette entrée sera fermée automatiquement à la prochaine action.
                      </p>
                    <?php endif ?>
                  <?php endif ?>

                  <?php if ($ctx["reason"] !== null): ?>
                    <div class="detail-reason"><?= htmlspecialchars($ctx["reason"]) ?></div>
                  <?php endif ?>
                  <?php if ($ctx["action"] !== null): ?>
                    <div class="detail-action-hint"><?= htmlspecialchars($ctx["action"]) ?></div>
                  <?php endif ?>

                  <!-- ══ Manual line builder — primary action ══════════════════════════════ -->
                  <?php
                  // Prepare MI list for datalist (reuse $allMisForAlias if already loaded,
                  // otherwise load it now — covers the "0 lines extracted" branch)
                  if (!isset($allMisForAlias)) {
                      $allMisForAlias = [];
                      try {
                          $misStmt2 = $pdo->query(
                              "SELECT mi_id, name FROM ref_mi WHERE is_active=1 ORDER BY mi_id"
                          );
                          $allMisForAlias = $misStmt2->fetchAll();
                      } catch (Throwable $_e2) {}
                  }
                  $manualInvTotalHt = $rqInv !== null && $rqInv['total_ht'] !== null
                      ? (float)$rqInv['total_ht'] : null;
                  ?>

                  <div class="manual-lines-section">
                    <div class="detail-section__head">Saisir les lignes manuellement</div>

                    <!-- Invoice total reference -->
                    <p class="manual-ref-line">
                      <?php if ($manualInvTotalHt !== null): ?>
                        Référence facture :
                        <strong><?= number_format($manualInvTotalHt, 2, '.', "'") ?>
                        <?= htmlspecialchars((string)($rqInv['currency'] ?? 'CHF')) ?> HT</strong>
                      <?php else: ?>
                        Référence facture : —
                      <?php endif ?>
                    </p>

                    <!-- One shared datalist for MI typeahead — all 261+ MIs -->
                    <datalist id="mi-options">
                      <?php foreach ($allMisForAlias as $miOpt): ?>
                        <option value="<?= htmlspecialchars($miOpt['mi_id']) ?>">
                          <?= htmlspecialchars($miOpt['mi_id']) ?> — <?= htmlspecialchars((string)$miOpt['name']) ?>
                        </option>
                      <?php endforeach ?>
                    </datalist>

                    <form id="manual-lines-form"
                          method="post"
                          action="/api/triage/manual-lines.php"
                          data-invoice-total-ht="<?= $manualInvTotalHt !== null ? htmlspecialchars((string)$manualInvTotalHt) : '' ?>">
                      <input type="hidden" name="csrf"  value="<?= htmlspecialchars(csrf_token()) ?>">
                      <input type="hidden" name="rq_id" value="<?= (int)$rqRow['id'] ?>">

                      <div class="manual-lines-table-wrap">
                        <table class="manual-lines-table">
                          <thead>
                            <tr>
                              <th class="ml-th ml-th--num">#</th>
                              <th class="ml-th ml-th--desc">Description</th>
                              <th class="ml-th ml-th--mi">MI</th>
                              <th class="ml-th ml-th--qty">Qté</th>
                              <th class="ml-th ml-th--price">Prix unit.</th>
                              <th class="ml-th ml-th--total">Total</th>
                              <th class="ml-th ml-th--remove"></th>
                            </tr>
                          </thead>
                          <tbody id="manual-lines-tbody">
                            <!-- Rows injected by triage-manual-lines.js -->
                          </tbody>
                        </table>
                      </div>

                      <button type="button" id="manual-lines-add"
                              class="manual-add-line">
                        + Ajouter une ligne
                      </button>

                      <div class="manual-totals">
                        <span class="manual-totals__label">Total lignes :</span>
                        <span id="manual-lines-total" class="manual-totals__value">0.00 CHF</span>
                        <span id="manual-lines-delta" class="manual-delta"></span>
                      </div>

                      <div class="manual-submit-row">
                        <button type="submit" id="manual-lines-submit"
                                class="manual-submit" disabled>
                          Sauvegarder
                        </button>
                      </div>
                    </form>
                  </div><!-- /manual-lines-section -->

                  <!-- Row-level escape hatch: always available for invoice-line-items-needed.
                       Use when per-line actions don't apply (SIL/SIE utility invoices with no
                       lines, scans we can't parse, decisions to handle the invoice outside
                       the triage UI entirely). -->
                  <div class="detail-row-footer">
                    <div class="detail-section__head">Action sur la facture entière</div>

                    <div class="row-action-bar">
                      <details class="row-action-details">
                        <summary class="detail-btn detail-btn--accept">Clore — traité hors triage ▾</summary>
                        <form class="row-action-form" method="post" action="/api/triage/accept.php">
                          <input type="hidden" name="csrf"  value="<?= htmlspecialchars(csrf_token()) ?>">
                          <input type="hidden" name="rq_id" value="<?= (int)$rqRow["id"] ?>">
                          <div class="row-action-form__row">
                            <input class="row-action-form__text"
                                   type="text" name="note"
                                   placeholder="Raison (ex: facture utilitaire SIE/SIL → routée vers EnergyData)"
                                   required>
                            <button type="submit" class="detail-btn detail-btn--accept row-action-form__btn">
                              Confirmer
                            </button>
                          </div>
                        </form>
                      </details>

                      <details class="row-action-details">
                        <summary class="detail-btn detail-btn--reject">Rejeter la facture ▾</summary>
                        <form class="row-action-form" method="post" action="/api/triage/reject.php">
                          <input type="hidden" name="csrf"   value="<?= htmlspecialchars(csrf_token()) ?>">
                          <input type="hidden" name="rq_id"  value="<?= (int)$rqRow["id"] ?>">
                          <!-- no line_index → whole-row reject path in reject.php -->
                          <div class="row-action-form__row">
                            <input class="row-action-form__text"
                                   type="text" name="reason"
                                   placeholder="Raison du rejet"
                                   required>
                            <button type="submit" class="detail-btn detail-btn--reject row-action-form__btn">
                              Confirmer
                            </button>
                          </div>
                        </form>
                      </details>
                    </div>
                  </div>

                <?php elseif ($type === "doc-classify-ambiguous"): ?>
                  <div class="detail-section__head">Signaux classifier</div>
                  <?php if ($ctx["reason"] !== null): ?>
                    <div class="detail-reason"><?= htmlspecialchars($ctx["reason"]) ?></div>
                  <?php endif ?>
                  <?php if ($ctx["ocr_preview"] !== null && $ctx["ocr_preview"] !== ""): ?>
                    <div class="detail-section__head">Aperçu OCR</div>
                    <pre class="detail-ocr-preview"><?= htmlspecialchars(substr($ctx["ocr_preview"], 0, 600)) ?></pre>
                  <?php elseif ($driveId !== ""): ?>
                    <?php
                    // Try reading from ocr-cache file
                    $ocrFile = DP_STORAGE_BASE . '/ocr-cache/' . $driveId . '.txt';
                    $ocrText = null;
                    if (is_readable($ocrFile)) {
                        $ocrText = substr((string) file_get_contents($ocrFile), 0, 600);
                    }
                    if ($ocrText !== null && $ocrText !== ""):
                    ?>
                    <div class="detail-section__head">Aperçu OCR</div>
                    <pre class="detail-ocr-preview"><?= htmlspecialchars($ocrText) ?></pre>
                    <?php endif ?>
                  <?php endif ?>

                <?php elseif ($type === "invoice-no-dn" || $type === "dn-no-invoice"): ?>
                  <div class="detail-section__head">
                    <?= $type === "invoice-no-dn" ? "Facture sans bon de livraison" : "Bon de livraison sans facture" ?>
                  </div>
                  <?php if ($ctx["reason"] !== null): ?>
                    <div class="detail-reason"><?= htmlspecialchars($ctx["reason"]) ?></div>
                  <?php endif ?>
                  <dl class="detail-meta">
                    <div class="detail-meta__row">
                      <dt>Âge</dt>
                      <dd><?= $age ?> jours</dd>
                    </div>
                    <?php if ($ctx["ref"] !== null): ?>
                      <div class="detail-meta__row">
                        <dt>Référence</dt>
                        <dd><?= htmlspecialchars($ctx["ref"]) ?></dd>
                      </div>
                    <?php endif ?>
                    <?php if ($ctx["total_ht"] !== null): ?>
                      <div class="detail-meta__row">
                        <dt>Montant HT</dt>
                        <dd class="detail-meta__amount"><?= htmlspecialchars($ctx["total_ht"]) ?></dd>
                      </div>
                    <?php endif ?>
                  </dl>
                  <?php if ($ctx["ocr_preview"] !== null && $ctx["ocr_preview"] !== ""): ?>
                    <div class="detail-section__head">Aperçu OCR</div>
                    <pre class="detail-ocr-preview"><?= htmlspecialchars(substr($ctx["ocr_preview"], 0, 600)) ?></pre>
                  <?php endif ?>

                <?php elseif ($type === "photonote-audit"): ?>
                  <div class="detail-section__head">Audit PhotoNote</div>
                  <?php if ($ctx["reason"] !== null): ?>
                    <div class="detail-reason"><?= htmlspecialchars($ctx["reason"]) ?></div>
                  <?php endif ?>
                  <dl class="detail-meta">
                    <?php if ($ctx["ref"] !== null): ?>
                      <div class="detail-meta__row">
                        <dt>Référence</dt>
                        <dd><?= htmlspecialchars($ctx["ref"]) ?></dd>
                      </div>
                    <?php endif ?>
                    <?php if ($ctx["total_ht"] !== null): ?>
                      <div class="detail-meta__row">
                        <dt>Montant</dt>
                        <dd class="detail-meta__amount"><?= htmlspecialchars($ctx["total_ht"]) ?></dd>
                      </div>
                    <?php endif ?>
                  </dl>

                <?php else: ?>
                  <!-- Fallback for unknown types -->
                  <div class="detail-section__head">Contexte</div>
                  <pre class="detail-ocr-preview"><?= htmlspecialchars(substr((string)($rqRow["context"] ?? ""), 0, 800)) ?></pre>
                <?php endif ?>

              </div><!-- /detail-section -->

              <!-- 4. ACTION FOOTER — type-aware -->
              <?php
              $actionMap  = ta_actions_for_type($type);
              $isPerLine  = $actionMap["per_line"];
              ?>
              <?php if (!$isPerLine): ?>
              <div class="detail-actions">
                <span class="detail-actions__label">Actions</span>
                <?php foreach ($actionMap["actions"] as $act): ?>
                  <?php if ($act["payload_form"] === "none" || $act["payload_form"] === "confirm"): ?>
                    <!-- Simple confirm form -->
                    <form class="detail-action-form" method="post"
                          action="<?= htmlspecialchars($act["endpoint"]) ?>">
                      <input type="hidden" name="csrf"   value="<?= htmlspecialchars(csrf_token()) ?>">
                      <input type="hidden" name="rq_id"  value="<?= (int)$rqRow["id"] ?>">
                      <?php if ($act["key"] === "invoice" || $act["key"] === "dn"): ?>
                        <input type="hidden" name="target" value="<?= htmlspecialchars($act["key"]) ?>">
                      <?php endif ?>
                      <button type="submit" class="detail-btn <?= htmlspecialchars($act["class"]) ?>">
                        <?= htmlspecialchars($act["label"]) ?>
                      </button>
                    </form>
                  <?php elseif ($act["payload_form"] === "inline-note"): ?>
                    <!-- Note form with expandable text field -->
                    <details class="detail-action-details">
                      <summary class="detail-btn <?= htmlspecialchars($act["class"]) ?>">
                        <?= htmlspecialchars($act["label"]) ?> ▾
                      </summary>
                      <form class="detail-note-form" method="post"
                            action="<?= htmlspecialchars($act["endpoint"]) ?>">
                        <input type="hidden" name="csrf"  value="<?= htmlspecialchars(csrf_token()) ?>">
                        <input type="hidden" name="rq_id" value="<?= (int)$rqRow["id"] ?>">
                        <div class="detail-note-form__row">
                          <input class="detail-note-form__input"
                                 type="text"
                                 name="<?= $act["key"] === "accept" ? "note" : "reason" ?>"
                                 placeholder="<?= $act["key"] === "accept" ? "Note (optionnel)" : "Raison (optionnel)" ?>">
                          <button type="submit" class="detail-btn <?= htmlspecialchars($act["class"]) ?>">
                            Confirmer
                          </button>
                        </div>
                      </form>
                    </details>
                  <?php endif ?>
                <?php endforeach ?>
              </div><!-- /detail-actions -->
              <?php endif ?>
              <!-- per_line types have no footer — actions are inline per-line above -->

            <?php endif; // rqRow ?>
          </div><!-- /detail-panel -->
          <?php end_detail_panel: ?>

        </div><!-- /triage-pane -->

      <?php endif; // empty rows ?>

    <!-- ═══════════════════════ STOCK TAB ═══════════════════════ -->
    <?php elseif ($activeTab === "stock"): ?>

      <!-- ── KPI bar ── -->
      <div class="stock-kpi-bar">
        <div class="stock-kpi stock-kpi--<?= $stockKpi['total'] > 0 ? 'alert' : 'ok' ?>">
          <span class="stock-kpi__value"><?= $stockKpi['total'] ?></span>
          <span class="stock-kpi__label">total open</span>
        </div>
        <?php foreach (["rm-stale" => "dormant", "rm-negative" => "négatif",
                         "rm-orphan-mi" => "orphelin", "dynamic-vs-take-drift" => "dérive"] as $t => $lbl): ?>
          <div class="stock-kpi stock-kpi--<?= $stockKpi[$t] > 0 ? 'alert' : 'ok' ?>">
            <span class="stock-kpi__value"><?= $stockKpi[$t] ?></span>
            <span class="stock-kpi__label"><?= $lbl ?></span>
          </div>
        <?php endforeach ?>
      </div>

      <!-- ── Filters ── -->
      <form class="stock-filters" method="get" action="">
        <input type="hidden" name="tab" value="stock">

        <label class="stock-filters__field">
          Type
          <select name="type">
            <option value=""<?= $stockFilterType === "" ? " selected" : "" ?>>— tous —</option>
            <option value="rm-stale"<?=              $stockFilterType === "rm-stale"              ? " selected" : "" ?>>rm-stale</option>
            <option value="rm-negative"<?=           $stockFilterType === "rm-negative"           ? " selected" : "" ?>>rm-negative</option>
            <option value="rm-orphan-mi"<?=          $stockFilterType === "rm-orphan-mi"          ? " selected" : "" ?>>rm-orphan-mi</option>
            <option value="dynamic-vs-take-drift"<?= $stockFilterType === "dynamic-vs-take-drift" ? " selected" : "" ?>>dynamic-vs-take-drift</option>
          </select>
        </label>

        <label class="stock-filters__field">
          Priorité min.
          <select name="min_priority">
            <option value="0"<?=   $stockFilterPriority === 0   ? " selected" : "" ?>>— toutes —</option>
            <option value="50"<?=  $stockFilterPriority === 50  ? " selected" : "" ?>>≥ 50</option>
            <option value="100"<?= $stockFilterPriority === 100 ? " selected" : "" ?>>≥ 100</option>
          </select>
        </label>

        <label class="stock-filters__field">
          Statut
          <select name="status">
            <option value="open"<?=     $stockFilterStatus === "open"     ? " selected" : "" ?>>Ouverts</option>
            <option value="all"<?=      $stockFilterStatus === "all"      ? " selected" : "" ?>>Tous</option>
            <option value="resolved"<?= $stockFilterStatus === "resolved" ? " selected" : "" ?>>Résolus</option>
          </select>
        </label>

        <button type="submit" class="stock-filters__submit">Filtrer</button>
      </form>

      <?php if (empty($stockRows) && $dbError === null): ?>
        <!-- Empty state -->
        <div class="triage-empty">
          <span class="triage-empty__icon">✓</span>
          <p class="triage-empty__headline">Aucune alerte stock — RAS.</p>
          <p class="triage-empty__sub">
            <?php if ($stockFilterType !== "" || $stockFilterPriority > 0 || $stockFilterStatus !== "open"): ?>
              Aucun résultat pour ces filtres.
            <?php else: ?>
              Le pipeline RM n'a signalé aucun écart.
            <?php endif ?>
          </p>
        </div>

      <?php else: ?>

        <div class="alert-table-wrap">
          <table class="alert-table">
            <thead>
              <tr>
                <th>Type</th>
                <th>MI_ID</th>
                <th>Contexte</th>
                <th>Âge</th>
                <th>Priorité</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($stockRows as $sr):
                  $sType    = (string)$sr["type"];
                  $sValue   = (string)$sr["value"];
                  $sCtx     = (string)($sr["context"] ?? "");
                  $sPrio    = (int)$sr["priority"];
                  $sAge     = triage_age_days((string)$sr["created_at"]);
                  $sStatus  = (string)$sr["status"];
                  $sIsOpen  = ($sStatus === "open");
                  $sPrioCls = $sPrio >= 100 ? "stock-prio--high"
                            : ($sPrio >= 50 ? "stock-prio--mid" : "stock-prio--low");
                  $sCtxText = stock_parse_context($sType, $sCtx);
              ?>
                <tr class="<?= $sIsOpen ? "" : "alert-row--resolved" ?>">
                  <td>
                    <span class="stock-glyph stock-glyph--<?= htmlspecialchars($sType) ?>">
                      <?= stock_type_glyph($sType) ?>
                    </span>
                  </td>
                  <td>
                    <span class="stock-mi-id"><?= htmlspecialchars($sValue) ?></span>
                  </td>
                  <td>
                    <span class="stock-context"><?= htmlspecialchars($sCtxText) ?></span>
                  </td>
                  <td>
                    <span class="stock-age"><?= $sAge ?>j</span>
                  </td>
                  <td>
                    <span class="stock-prio <?= $sPrioCls ?>"><?= $sPrio ?></span>
                  </td>
                  <td>
                    <?php if ($sIsOpen): ?>
                      <div class="stock-actions">

                        <!-- Acknowledge button -->
                        <details>
                          <summary class="stock-btn stock-btn--accept">Accuser réception ▾</summary>
                          <div class="stock-note-form">
                            <form method="post" action="/api/triage/accept.php"
                                  style="display:contents">
                              <input type="hidden" name="csrf"  value="<?= htmlspecialchars(csrf_token()) ?>">
                              <input type="hidden" name="rq_id" value="<?= (int)$sr["id"] ?>">
                              <input class="stock-note-input" type="text" name="note"
                                     placeholder="Note (optionnel)">
                              <button type="submit" class="stock-btn stock-btn--accept">
                                Confirmer
                              </button>
                            </form>
                          </div>
                        </details>

                        <!-- Reopen / reject button -->
                        <details>
                          <summary class="stock-btn stock-btn--reject">Rouvrir ▾</summary>
                          <div class="stock-note-form">
                            <form method="post" action="/api/triage/reject.php"
                                  style="display:contents">
                              <input type="hidden" name="csrf"  value="<?= htmlspecialchars(csrf_token()) ?>">
                              <input type="hidden" name="rq_id" value="<?= (int)$sr["id"] ?>">
                              <input class="stock-note-input" type="text" name="reason"
                                     placeholder="Raison (requis)" required>
                              <button type="submit" class="stock-btn stock-btn--reject">
                                Confirmer
                              </button>
                            </form>
                          </div>
                        </details>

                      </div><!-- /stock-actions -->
                    <?php else: ?>
                      <span class="if-action--none">
                        <?= htmlspecialchars($sr["decision"] ?? "—") ?>
                      </span>
                    <?php endif ?>
                  </td>
                </tr>
              <?php endforeach ?>
            </tbody>
          </table>
        </div>

        <!-- Pagination -->
        <?php if ($stockLastPage > 0): ?>
          <nav class="stock-pagination" aria-label="Pagination stock">
            <?php if ($stockPage > 0): ?>
              <a class="stock-pagination__link"
                 href="<?= htmlspecialchars(stock_qs(["stock_page" => $stockPage - 1])) ?>">← précédent</a>
            <?php else: ?>
              <span class="stock-pagination__link stock-pagination__link--off">← précédent</span>
            <?php endif ?>
            <span class="stock-pagination__pos"><?= $stockPage + 1 ?>/<?= $stockLastPage + 1 ?></span>
            <?php if ($stockPage < $stockLastPage): ?>
              <a class="stock-pagination__link"
                 href="<?= htmlspecialchars(stock_qs(["stock_page" => $stockPage + 1])) ?>">suivant →</a>
            <?php else: ?>
              <span class="stock-pagination__link stock-pagination__link--off">suivant →</span>
            <?php endif ?>
          </nav>
        <?php endif ?>

      <?php endif; // empty stockRows ?>

    <!-- ═══════════════════════ FORM-INGEST TAB ═══════════════════════ -->
    <?php elseif ($activeTab === "form" && is_admin($me)): ?>

      <?php require __DIR__ . "/triage_form_ingest_partial.php" ?>

    <?php endif ?>

  </div><!-- /triage-content -->

</main>

<script src="/js/triage-upload.js?v=<?= @filemtime(__DIR__ . '/../js/triage-upload.js') ?: time() ?>"></script>
<script defer src="/js/triage-manual-lines.js?v=<?= @filemtime(__DIR__ . '/../js/triage-manual-lines.js') ?: time() ?>"></script>

</body>
</html>
<?php

// ── Out-of-class helper (must be defined after the require_login block) ────────

/**
 * Extract a readable supplier hint from the RQ value field.
 * Values are like "llm-fallback: 8719 - DASCHER.pdf" or just "DASCHER".
 */
function triage_extract_supplier_from_value(string $value): ?string
{
    // Pattern: "something: NNNN - SUPPLIER.pdf"
    if (preg_match('/\d+\s*-\s*([^.]+)\.pdf$/i', $value, $m)) {
        return trim($m[1]);
    }
    // Plain text after colon
    if (str_contains($value, ":")) {
        $part = trim(substr($value, strrpos($value, ":") + 1));
        if ($part !== "") return $part;
    }
    return $value !== "" ? $value : null;
}
