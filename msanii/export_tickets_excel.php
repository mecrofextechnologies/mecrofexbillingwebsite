<?php
// export_tickets_excel.php
// ────────────────────────────────────────────────
// Pure PHP CSV export — zero composer/library needed
// Opens directly in Excel, Google Sheets, LibreOffice
// ────────────────────────────────────────────────

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

ini_set('display_errors', 1);
error_reporting(E_ALL);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/export_debug.log');

$connect_path = file_exists('msanii/includes/connect.php')
    ? 'msanii/includes/connect.php'
    : 'includes/connect.php';
if (!file_exists($connect_path)) die("Database connection file missing.");
require_once $connect_path;

// ────────────────────────────────────────────────
// Auth
// ────────────────────────────────────────────────
if (!isset($_SESSION['creator_id']) || !is_numeric($_SESSION['creator_id'])) {
    header("Location: login.php");
    exit;
}

$creator_id = (int)$_SESSION['creator_id'];
$event_id   = (int)($_REQUEST['event_id'] ?? 0);
if ($event_id <= 0) die("Invalid event ID.");

// ────────────────────────────────────────────────
// Get event details
// ────────────────────────────────────────────────
$stmt = $pdo->prepare("SELECT title, start_date, start_time, venue FROM tickets WHERE id = ? AND creator_id = ?");
$stmt->execute([$event_id, $creator_id]);
$event = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$event) die("Event not found or access denied.");

// ────────────────────────────────────────────────
// Filter params
// ────────────────────────────────────────────────
$filter_status  = $_REQUEST['filter_status']  ?? 'all';
$filter_checkin = $_REQUEST['filter_checkin'] ?? 'all';
$filter_type    = $_REQUEST['filter_type']    ?? 'all';
$date_from      = $_REQUEST['date_from']      ?? '';
$date_to        = $_REQUEST['date_to']        ?? '';

// ────────────────────────────────────────────────
// Build query
// ────────────────────────────────────────────────
function buildQuery($event_id, $filter_status, $filter_checkin, $filter_type, $date_from, $date_to): array {
    $sql = "
        SELECT
            tp.id, tp.ticket_number, tp.buyer_name, tp.buyer_email, tp.buyer_phone,
            tp.amount_paid, tp.status, tp.created_at, tp.is_checked_in,
            tp.failed_reason,
            ett.type_name AS ticket_type_name
        FROM ticket_purchases tp
        JOIN tickets t ON tp.ticket_id = t.id
        LEFT JOIN event_ticket_types ett ON tp.ticket_type_id = ett.id
        WHERE t.id = :event_id
    ";
    $params = [':event_id' => $event_id];

    if ($filter_status !== 'all') {
        $sql .= " AND tp.status = :status";
        $params[':status'] = $filter_status;
    }
    if ($filter_checkin === '1') {
        $sql .= " AND tp.is_checked_in = 1";
    } elseif ($filter_checkin === '0') {
        $sql .= " AND tp.is_checked_in = 0";
    }
    if ($filter_type !== 'all') {
        $sql .= " AND ett.type_name = :type_name";
        $params[':type_name'] = $filter_type;
    }
    if ($date_from !== '') {
        $sql .= " AND DATE(tp.created_at) >= :date_from";
        $params[':date_from'] = $date_from;
    }
    if ($date_to !== '') {
        $sql .= " AND DATE(tp.created_at) <= :date_to";
        $params[':date_to'] = $date_to;
    }
    $sql .= " ORDER BY tp.created_at DESC";
    return [$sql, $params];
}

[$sql, $params] = buildQuery($event_id, $filter_status, $filter_checkin, $filter_type, $date_from, $date_to);
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$purchases = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Ticket types for filter dropdown
$stmt2 = $pdo->prepare("
    SELECT DISTINCT ett.type_name
    FROM ticket_purchases tp
    JOIN tickets t ON tp.ticket_id = t.id
    LEFT JOIN event_ticket_types ett ON tp.ticket_type_id = ett.id
    WHERE t.id = ? AND ett.type_name IS NOT NULL
    ORDER BY ett.type_name
");
$stmt2->execute([$event_id]);
$ticket_types = $stmt2->fetchAll(PDO::FETCH_COLUMN);

// Stats
$total_count     = count($purchases);
$completed_count = count(array_filter($purchases, fn($p) => $p['status'] === 'completed'));
$checked_count   = count(array_filter($purchases, fn($p) => (int)$p['is_checked_in'] === 1));
$failed_count    = count(array_filter($purchases, fn($p) => $p['status'] === 'failed'));
$total_revenue   = array_sum(array_map(fn($p) => $p['status'] === 'completed' ? (float)$p['amount_paid'] : 0, $purchases));

// ────────────────────────────────────────────────
// CSV EXPORT — pure PHP, zero dependencies
// ────────────────────────────────────────────────
if (isset($_POST['do_export'])) {

    [$sql, $params] = buildQuery(
        $event_id,
        $_POST['filter_status']  ?? 'all',
        $_POST['filter_checkin'] ?? 'all',
        $_POST['filter_type']    ?? 'all',
        $_POST['date_from']      ?? '',
        $_POST['date_to']        ?? ''
    );
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Totals
    $exp_revenue  = array_sum(array_map(fn($r) => $r['status'] === 'completed' ? (float)$r['amount_paid'] : 0, $rows));
    $exp_complete = count(array_filter($rows, fn($r) => $r['status'] === 'completed'));
    $exp_checked  = count(array_filter($rows, fn($r) => (int)$r['is_checked_in'] === 1));
    $exp_pending  = count(array_filter($rows, fn($r) => $r['status'] === 'pending'));
    $exp_failed   = count(array_filter($rows, fn($r) => $r['status'] === 'failed'));

    $eventSlug = preg_replace('/[^a-z0-9]+/', '-', strtolower($event['title']));
    $filename  = "tickets-{$eventSlug}-" . date('Ymd-His') . '.csv';

    // Clear any output buffers so nothing corrupts the file
    while (ob_get_level() > 0) ob_end_clean();

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');

    $out = fopen('php://output', 'w');

    // UTF-8 BOM — makes Excel open without garbled characters
    fputs($out, "\xEF\xBB\xBF");

    // ── Event info block ──────────────────────────
    fputcsv($out, ['EVENT TICKET EXPORT']);
    fputcsv($out, ['Event',       $event['title']]);
    fputcsv($out, ['Date',        $event['start_date'] ? date('d M Y', strtotime($event['start_date'])) : 'N/A']);
    fputcsv($out, ['Time',        $event['start_time'] ? date('g:i A', strtotime($event['start_time'])) : 'N/A']);
    fputcsv($out, ['Venue',       $event['venue'] ?: 'TBA']);
    fputcsv($out, ['Exported On', date('d M Y H:i:s')]);
    fputcsv($out, []);

    // ── Summary block ─────────────────────────────
    fputcsv($out, ['--- SUMMARY ---']);
    fputcsv($out, ['Total Records',         count($rows)]);
    fputcsv($out, ['Completed Tickets',     $exp_complete]);
    fputcsv($out, ['Pending Payments',      $exp_pending]);
    fputcsv($out, ['Failed Payments',       $exp_failed]);
    fputcsv($out, ['Checked In',            $exp_checked]);
    fputcsv($out, ['Total Revenue (KSh)',   number_format($exp_revenue, 2)]);
    fputcsv($out, []);

    // ── Column headers ────────────────────────────
    fputcsv($out, [
        '#',
        'Ticket Number',
        'Buyer Name',
        'Email',
        'Phone',
        'Ticket Type',
        'Amount (KSh)',
        'Status',
        'Checked In',
        'Purchase Date',
        'Failed Reason',
    ]);

    // ── Data rows ─────────────────────────────────
    foreach ($rows as $i => $p) {
        fputcsv($out, [
            $i + 1,
            $p['ticket_number']    ?? '',
            $p['buyer_name']       ?? '',
            $p['buyer_email']      ?? '',
            $p['buyer_phone']      ?? '',
            $p['ticket_type_name'] ?? 'General',
            number_format((float)$p['amount_paid'], 2),
            ucfirst($p['status']   ?? 'pending'),
            (int)$p['is_checked_in'] ? 'Yes' : 'No',
            date('d M Y H:i', strtotime($p['created_at'])),
            $p['failed_reason']    ?? '',
        ]);
    }

    fclose($out);
    exit;
}

// ────────────────────────────────────────────────
// HTML PREVIEW PAGE
// ────────────────────────────────────────────────
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Export Tickets – <?= htmlspecialchars($event['title']) ?> | Keychele</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
  <style>
    body { background: #f0f4f8; -webkit-font-smoothing: antialiased; }

    .page-header {
      background: linear-gradient(135deg, #032d5d 0%, #0f4c8a 50%, #155e9e 100%);
      position: relative; overflow: hidden;
    }
    .page-header::after {
      content:''; position:absolute; right:-60px; top:-60px;
      width:220px; height:220px; border-radius:50%;
      background:rgba(255,255,255,0.05);
    }
    .page-header::before {
      content:''; position:absolute; left:-40px; bottom:-80px;
      width:280px; height:280px; border-radius:50%;
      background:rgba(184,18,54,0.15);
    }

    .csv-icon {
      background: linear-gradient(135deg, #16a34a, #15803d);
      border-radius: 14px; display:flex; align-items:center;
      justify-content:center; color: white;
      box-shadow: 0 4px 16px rgba(22,163,74,0.4);
    }

    .filter-card {
      background: white; border-radius: 16px; padding: 22px;
      box-shadow: 0 2px 12px rgba(0,0,0,0.06);
    }
    .filter-label {
      font-size: 0.72rem; font-weight: 700; color: #64748b;
      text-transform: uppercase; letter-spacing: 0.05em;
      margin-bottom: 6px; display: block;
    }
    .filter-select, .filter-input {
      width: 100%; border: 1.5px solid #e2e8f0; border-radius: 10px;
      padding: 9px 12px; font-size: 0.875rem; background: #f8fafc;
      transition: all 0.2s; color: #1e293b;
    }
    .filter-select:focus, .filter-input:focus {
      outline: none; border-color: #032d5d; background: white;
      box-shadow: 0 0 0 3px rgba(3,45,93,0.08);
    }

    .mini-stat {
      background: white; border-radius: 14px; padding: 14px 18px;
      box-shadow: 0 1px 8px rgba(0,0,0,0.05);
      display: flex; align-items: center; gap: 12px;
      transition: transform 0.15s, box-shadow 0.15s;
    }
    .mini-stat:hover { transform: translateY(-1px); box-shadow: 0 4px 16px rgba(0,0,0,0.08); }
    .stat-icon {
      width: 40px; height: 40px; border-radius: 12px;
      display:flex; align-items:center; justify-content:center;
      font-size:1rem; flex-shrink:0;
    }
    .stat-value { font-size: 1.4rem; font-weight: 800; color: #0f172a; line-height: 1.1; }
    .stat-label { font-size: 0.68rem; font-weight: 600; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.04em; }

    .export-btn {
      background: linear-gradient(135deg, #16a34a, #15803d);
      color: white; border: none; border-radius: 12px;
      padding: 14px 28px; font-size: 0.9375rem; font-weight: 700;
      display: inline-flex; align-items: center; gap: 10px;
      cursor: pointer; transition: all 0.2s; width: 100%; justify-content: center;
      box-shadow: 0 4px 16px rgba(22,163,74,0.35);
    }
    .export-btn:hover:not(:disabled) {
      transform: translateY(-2px); box-shadow: 0 8px 24px rgba(22,163,74,0.45);
    }
    .export-btn:disabled { opacity: 0.55; cursor: not-allowed; }

    .table-wrap { max-height: 500px; overflow: auto; }
    .table-wrap::-webkit-scrollbar { width: 5px; height: 5px; }
    .table-wrap::-webkit-scrollbar-track { background: #f1f5f9; }
    .table-wrap::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 99px; }

    .badge { padding: 3px 10px; border-radius: 999px; font-size: 0.7rem; font-weight: 700; white-space: nowrap; }
    .badge-completed { background: #dcfce7; color: #14532d; }
    .badge-pending   { background: #fef3c7; color: #78350f; }
    .badge-failed    { background: #fee2e2; color: #7f1d1d; }
    .badge-yes       { background: #dbeafe; color: #1e40af; }
    .badge-no        { background: #f1f5f9; color: #64748b; }

    .feature-row { display:flex; align-items:flex-start; gap:10px; padding:8px 0; border-bottom:1px solid #f1f5f9; }
    .feature-row:last-child { border:none; padding-bottom:0; }
  </style>
</head>
<body>

<!-- Header -->
<div class="page-header text-white px-4 md:px-8 py-5 mb-6">
  <div class="relative z-10 max-w-screen-xl mx-auto">
    <div class="flex items-center gap-2 mb-3 text-sm">
      <a href="view_ticket_details.php?event_id=<?= $event_id ?>"
         class="text-white/65 hover:text-white flex items-center gap-1.5 transition-colors">
        <i class="fas fa-arrow-left text-xs"></i> Back to Tickets
      </a>
      <span class="text-white/30">/</span>
      <span class="text-white/65">Export</span>
    </div>
    <div class="flex items-center gap-4">
      <div class="csv-icon w-12 h-12 flex-shrink-0" style="font-size:1.4rem;">
        <i class="fas fa-file-csv"></i>
      </div>
      <div>
        <h1 class="text-xl md:text-2xl font-extrabold leading-tight">Export Ticket Data</h1>
        <p class="text-white/65 text-sm mt-0.5">
          <?= htmlspecialchars($event['title']) ?>
          <?php if ($event['start_date']): ?>
            &nbsp;·&nbsp; <?= date('d M Y', strtotime($event['start_date'])) ?>
          <?php endif; ?>
        </p>
      </div>
    </div>
  </div>
</div>

<div class="max-w-screen-xl mx-auto px-4 md:px-6 pb-16">

  <!-- Stats -->
  <div class="grid grid-cols-2 sm:grid-cols-5 gap-3 mb-6">
    <div class="mini-stat col-span-2 sm:col-span-1">
      <div class="stat-icon bg-blue-50 text-blue-600"><i class="fas fa-list-ol"></i></div>
      <div><div class="stat-value"><?= $total_count ?></div><div class="stat-label">Records</div></div>
    </div>
    <div class="mini-stat">
      <div class="stat-icon bg-emerald-50 text-emerald-600"><i class="fas fa-ticket-alt"></i></div>
      <div><div class="stat-value"><?= $completed_count ?></div><div class="stat-label">Sold</div></div>
    </div>
    <div class="mini-stat">
      <div class="stat-icon bg-indigo-50 text-indigo-600"><i class="fas fa-user-check"></i></div>
      <div><div class="stat-value"><?= $checked_count ?></div><div class="stat-label">Checked In</div></div>
    </div>
    <div class="mini-stat">
      <div class="stat-icon bg-red-50 text-red-500"><i class="fas fa-times-circle"></i></div>
      <div><div class="stat-value"><?= $failed_count ?></div><div class="stat-label">Failed</div></div>
    </div>
    <div class="mini-stat col-span-2 sm:col-span-1">
      <div class="stat-icon bg-green-50 text-green-700"><i class="fas fa-coins"></i></div>
      <div>
        <div class="stat-value text-green-700" style="font-size:1.05rem;">
          <?= number_format($total_revenue) ?>
        </div>
        <div class="stat-label">Revenue (KSh)</div>
      </div>
    </div>
  </div>

  <div class="grid lg:grid-cols-3 gap-6">

    <!-- LEFT -->
    <div class="lg:col-span-1 space-y-4">

      <!-- Filters -->
      <div class="filter-card">
        <div class="flex items-center gap-2 mb-5">
          <div class="w-8 h-8 rounded-lg bg-slate-100 flex items-center justify-center">
            <i class="fas fa-sliders-h text-slate-500 text-sm"></i>
          </div>
          <h2 class="font-bold text-gray-800">Filter Records</h2>
        </div>

        <form method="GET" action="" id="filterForm">
          <input type="hidden" name="event_id" value="<?= $event_id ?>">
          <div class="space-y-4">
            <div>
              <label class="filter-label">Payment Status</label>
              <select name="filter_status" class="filter-select">
                <option value="all"       <?= $filter_status==='all'       ?'selected':'' ?>>All Statuses</option>
                <option value="completed" <?= $filter_status==='completed' ?'selected':'' ?>>✅ Completed</option>
                <option value="pending"   <?= $filter_status==='pending'   ?'selected':'' ?>>⏳ Pending</option>
                <option value="failed"    <?= $filter_status==='failed'    ?'selected':'' ?>>❌ Failed</option>
              </select>
            </div>
            <div>
              <label class="filter-label">Check-in Status</label>
              <select name="filter_checkin" class="filter-select">
                <option value="all" <?= $filter_checkin==='all'?'selected':'' ?>>All</option>
                <option value="1"   <?= $filter_checkin==='1'  ?'selected':'' ?>>✅ Checked in</option>
                <option value="0"   <?= $filter_checkin==='0'  ?'selected':'' ?>>❌ Not checked in</option>
              </select>
            </div>
            <?php if (!empty($ticket_types)): ?>
            <div>
              <label class="filter-label">Ticket Type</label>
              <select name="filter_type" class="filter-select">
                <option value="all">All Types</option>
                <?php foreach ($ticket_types as $type): ?>
                  <option value="<?= htmlspecialchars($type) ?>" <?= $filter_type===$type?'selected':'' ?>>
                    <?= htmlspecialchars($type) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <?php endif; ?>
            <div class="grid grid-cols-2 gap-3">
              <div>
                <label class="filter-label">Date From</label>
                <input type="date" name="date_from" class="filter-input" value="<?= htmlspecialchars($date_from) ?>">
              </div>
              <div>
                <label class="filter-label">Date To</label>
                <input type="date" name="date_to" class="filter-input" value="<?= htmlspecialchars($date_to) ?>">
              </div>
            </div>
          </div>
          <div class="flex gap-2 mt-5">
            <button type="submit"
              class="flex-1 bg-[#032d5d] hover:bg-[#043a77] text-white font-semibold py-2.5 rounded-xl text-sm transition-colors flex items-center justify-center gap-2">
              <i class="fas fa-filter text-xs"></i> Apply Filters
            </button>
            <a href="?event_id=<?= $event_id ?>"
               class="w-10 h-10 rounded-xl bg-slate-100 hover:bg-slate-200 flex items-center justify-center text-slate-500 transition-colors"
               title="Clear filters">
              <i class="fas fa-times text-sm"></i>
            </a>
          </div>
        </form>
      </div>

      <!-- Export card -->
      <div class="filter-card">
        <div class="flex items-center gap-3 mb-4">
          <div class="csv-icon w-10 h-10 flex-shrink-0" style="font-size:1.1rem; border-radius:12px;">
            <i class="fas fa-file-csv"></i>
          </div>
          <div>
            <h3 class="font-bold text-gray-800 text-sm">Download as CSV</h3>
            <p class="text-gray-400 text-xs">
              <?= $total_count ?> record<?= $total_count !== 1 ? 's' : '' ?> · Opens in Excel
            </p>
          </div>
        </div>

        <div class="mb-4 bg-slate-50 rounded-xl p-3">
          <p class="text-xs font-bold text-slate-500 uppercase tracking-wide mb-2">What's included</p>
          <div class="feature-row"><i class="fas fa-id-card text-blue-500 text-xs mt-0.5 w-3"></i><p class="text-xs text-gray-600">Ticket #, name, email, phone</p></div>
          <div class="feature-row"><i class="fas fa-tag text-purple-500 text-xs mt-0.5 w-3"></i><p class="text-xs text-gray-600">Ticket type, amount, payment status</p></div>
          <div class="feature-row"><i class="fas fa-clipboard-check text-emerald-500 text-xs mt-0.5 w-3"></i><p class="text-xs text-gray-600">Check-in status & purchase date/time</p></div>
          <div class="feature-row"><i class="fas fa-chart-bar text-orange-500 text-xs mt-0.5 w-3"></i><p class="text-xs text-gray-600">Event summary block with totals</p></div>
          <div class="feature-row"><i class="fas fa-font text-teal-500 text-xs mt-0.5 w-3"></i><p class="text-xs text-gray-600">UTF-8 BOM — no garbled characters</p></div>
        </div>

        <form method="POST" action="?event_id=<?= $event_id ?>" id="exportForm">
          <input type="hidden" name="do_export"      value="1">
          <input type="hidden" name="event_id"       value="<?= $event_id ?>">
          <input type="hidden" name="filter_status"  value="<?= htmlspecialchars($filter_status) ?>">
          <input type="hidden" name="filter_checkin" value="<?= htmlspecialchars($filter_checkin) ?>">
          <input type="hidden" name="filter_type"    value="<?= htmlspecialchars($filter_type) ?>">
          <input type="hidden" name="date_from"      value="<?= htmlspecialchars($date_from) ?>">
          <input type="hidden" name="date_to"        value="<?= htmlspecialchars($date_to) ?>">

          <?php if ($total_count === 0): ?>
            <div class="bg-amber-50 border border-amber-200 rounded-xl p-3 text-center text-amber-700 text-sm font-semibold mb-3">
              <i class="fas fa-exclamation-triangle mr-1"></i>
              No records match your filters
            </div>
          <?php endif; ?>

          <button type="submit" id="exportBtn" class="export-btn"
                  <?= $total_count === 0 ? 'disabled' : '' ?>>
            <i class="fas fa-download text-base"></i>
            <span>Download CSV (Excel)</span>
          </button>
        </form>

        <p class="text-center text-xs text-gray-400 mt-3 flex items-center justify-center gap-1.5">
          <i class="fas fa-shield-alt"></i> Secure · Only visible to you
        </p>
      </div>

      <!-- How to open tip -->
      <div class="bg-blue-50 border border-blue-200 rounded-xl p-4">
        <p class="text-xs font-bold text-blue-800 mb-2 flex items-center gap-1.5">
          <i class="fas fa-lightbulb text-yellow-500"></i> Opening in Excel
        </p>
        <ol class="text-xs text-blue-700 space-y-1.5 list-decimal list-inside leading-relaxed">
          <li>Click <strong>Download CSV</strong> above</li>
          <li>The file downloads to your device</li>
          <li><strong>Double-click</strong> the file to open in Excel</li>
          <li>All columns load automatically ✓</li>
        </ol>
        <p class="text-xs text-blue-500 mt-2 italic">
          Also works in Google Sheets — File → Import → Upload.
        </p>
      </div>

    </div>

    <!-- RIGHT: Preview -->
    <div class="lg:col-span-2">
      <div class="bg-white rounded-2xl shadow-md overflow-hidden">

        <div class="px-5 py-4 border-b border-slate-100 flex items-center justify-between flex-wrap gap-3">
          <div>
            <h3 class="font-bold text-gray-800 flex items-center gap-2">
              <i class="fas fa-eye text-slate-400 text-sm"></i>
              Data Preview
            </h3>
            <p class="text-xs text-gray-400 mt-0.5">
              Showing <?= min($total_count, 100) ?> of <?= $total_count ?> — all <?= $total_count ?> rows exported
            </p>
          </div>
          <div class="flex items-center gap-2">
            <?php if ($filter_status !== 'all' || $filter_checkin !== 'all' || $filter_type !== 'all' || $date_from || $date_to): ?>
              <span class="bg-blue-100 text-blue-700 px-2.5 py-1 rounded-full text-xs font-semibold flex items-center gap-1">
                <i class="fas fa-filter text-xs"></i> Filtered
              </span>
            <?php endif; ?>
            <span class="bg-slate-100 text-slate-600 px-3 py-1 rounded-full text-xs font-semibold">
              <?= $total_count ?> rows
            </span>
          </div>
        </div>

        <?php if (empty($purchases)): ?>
          <div class="text-center py-20">
            <div class="w-14 h-14 rounded-2xl bg-gray-100 flex items-center justify-center mx-auto mb-3">
              <i class="fas fa-inbox text-2xl text-gray-300"></i>
            </div>
            <p class="text-gray-500 font-semibold text-sm">No records match your filters</p>
            <a href="?event_id=<?= $event_id ?>" class="text-blue-600 text-sm mt-2 inline-block hover:underline">
              Clear all filters
            </a>
          </div>
        <?php else: ?>
          <div class="table-wrap">
            <table class="w-full min-w-[720px] text-xs text-left">
              <thead class="bg-slate-50 text-slate-500 sticky top-0 border-b border-slate-100">
                <tr>
                  <th class="px-3 py-3 font-semibold uppercase tracking-wide">#</th>
                  <th class="px-3 py-3 font-semibold uppercase tracking-wide">Ticket #</th>
                  <th class="px-3 py-3 font-semibold uppercase tracking-wide">Buyer</th>
                  <th class="px-3 py-3 font-semibold uppercase tracking-wide">Email</th>
                  <th class="px-3 py-3 font-semibold uppercase tracking-wide">Type</th>
                  <th class="px-3 py-3 font-semibold uppercase tracking-wide">Status</th>
                  <th class="px-3 py-3 font-semibold uppercase tracking-wide text-center">Checked In</th>
                  <th class="px-3 py-3 font-semibold uppercase tracking-wide text-right">KSh</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach (array_slice($purchases, 0, 100) as $i => $p):
                  $st = strtolower($p['status'] ?? 'pending');
                  $badgeCls = match($st) {
                    'completed' => 'badge-completed',
                    'failed'    => 'badge-failed',
                    default     => 'badge-pending',
                  };
                ?>
                  <tr class="border-b border-slate-50 <?= $i%2===0 ? 'bg-white' : 'bg-slate-50/40' ?> hover:bg-blue-50/30 transition-colors">
                    <td class="px-3 py-2.5 text-slate-400 font-mono"><?= $i+1 ?></td>
                    <td class="px-3 py-2.5 font-mono font-bold text-[#b81236]">
                      <?= htmlspecialchars($p['ticket_number'] ?? '—') ?>
                    </td>
                    <td class="px-3 py-2.5 font-semibold text-gray-800 max-w-[120px] truncate">
                      <?= htmlspecialchars($p['buyer_name'] ?? '—') ?>
                    </td>
                    <td class="px-3 py-2.5 text-slate-500 max-w-[160px] truncate">
                      <?= htmlspecialchars($p['buyer_email'] ?? '—') ?>
                    </td>
                    <td class="px-3 py-2.5">
                      <span class="bg-slate-100 text-slate-600 px-2 py-0.5 rounded-full font-medium">
                        <?= htmlspecialchars($p['ticket_type_name'] ?? 'General') ?>
                      </span>
                    </td>
                    <td class="px-3 py-2.5">
                      <span class="badge <?= $badgeCls ?>"><?= ucfirst($st) ?></span>
                    </td>
                    <td class="px-3 py-2.5 text-center">
                      <?php if ((int)$p['is_checked_in']): ?>
                        <span class="badge badge-yes">✓ Yes</span>
                      <?php else: ?>
                        <span class="badge badge-no">No</span>
                      <?php endif; ?>
                    </td>
                    <td class="px-3 py-2.5 text-right font-bold text-emerald-600">
                      <?= number_format((float)$p['amount_paid']) ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>

          <!-- Footer bar -->
          <div class="px-5 py-3 border-t border-slate-100 bg-slate-50 flex items-center justify-between flex-wrap gap-2">
            <?php if ($total_count > 100): ?>
              <p class="text-xs text-amber-700 font-semibold flex items-center gap-1.5">
                <i class="fas fa-info-circle text-amber-500"></i>
                Preview capped at 100 — all <?= $total_count ?> rows in download
              </p>
            <?php else: ?>
              <p class="text-xs text-slate-400">All <?= $total_count ?> records shown</p>
            <?php endif; ?>
            <p class="text-xs font-bold text-gray-700">
              Revenue: <span class="text-emerald-600">KSh <?= number_format($total_revenue) ?></span>
            </p>
          </div>
        <?php endif; ?>

      </div>
    </div>

  </div>
</div>

<script>
document.getElementById('exportForm')?.addEventListener('submit', function() {
  const btn = document.getElementById('exportBtn');
  if (btn && !btn.disabled) {
    btn.disabled = true;
    btn.innerHTML = `
      <span class="inline-block w-4 h-4 border-2 border-white border-t-transparent rounded-full animate-spin"></span>
      <span>Preparing download…</span>
    `;
    setTimeout(() => {
      btn.disabled = false;
      btn.innerHTML = `<i class="fas fa-download text-base"></i><span>Download CSV (Excel)</span>`;
    }, 8000);
  }
});

// Auto-apply on dropdown change
document.querySelectorAll('#filterForm select').forEach(sel => {
  sel.addEventListener('change', () => document.getElementById('filterForm').submit());
});
</script>
</body>
</html>
