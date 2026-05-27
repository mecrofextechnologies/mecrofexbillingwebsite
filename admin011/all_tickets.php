<?php
// all_tickets.php — redesigned to match all_creators.php design system
include '../msanii/includes/connect.php';
if (session_status() === PHP_SESSION_NONE) session_start();

if (!isset($_SESSION['user_role']) || !in_array($_SESSION['user_role'], [1, 2])) {
    header("Location: ../login.php");
    exit();
}

// ── Params ────────────────────────────────────────────────────
$allowed_per_page = [15, 25, 50, 100];
$per_page         = (int)($_GET['per_page'] ?? 15);
if (!in_array($per_page, $allowed_per_page)) $per_page = 15;
$current_page     = max(1, (int)($_GET['p'] ?? 1));
$search_q         = trim($_GET['q'] ?? '');
$status_filter    = trim($_GET['status'] ?? '');

// Preserve dashboard page= param in all URLs
$dash_page = isset($_GET['page']) ? $_GET['page'] : null;

function pgTicketLink(int $p, int $pp, string $q, string $st, ?string $dp): string {
    $params = ['p' => $p, 'per_page' => $pp];
    if ($q  !== '')  $params['q']      = $q;
    if ($st !== '')  $params['status'] = $st;
    if ($dp !== null) $params['page']  = $dp;
    return '?' . http_build_query($params);
}

try {
    // ── KPI — single aggregate query (efficient) ─────────────
    $kpi = $pdo->query("
        SELECT
            COUNT(*)                                                    AS total,
            SUM(status = 'completed')                                   AS completed,
            SUM(status = 'pending')                                     AS pending,
            SUM(status = 'failed')                                      AS failed,
            SUM(CASE WHEN status='completed' THEN amount_paid  ELSE 0 END) AS total_revenue,
            SUM(CASE WHEN status='completed' THEN quantity     ELSE 0 END) AS tickets_sold
        FROM ticket_purchases
    ")->fetch(PDO::FETCH_ASSOC);

    $total_purchases         = (int)$kpi['total'];
    $completed_count         = (int)$kpi['completed'];
    $pending_count           = (int)$kpi['pending'];
    $failed_count            = (int)$kpi['failed'];
    $other_count             = $total_purchases - $completed_count - $pending_count - $failed_count;
    $total_revenue_completed = floatval($kpi['total_revenue']);
    $total_tickets_completed = (int)$kpi['tickets_sold'];
    $success_rate            = $total_purchases > 0
                               ? round(($completed_count / $total_purchases) * 100, 1) : 0;

    // ── Chart: daily trend (last 60 days of completed) ───────
    $daily_rows = $pdo->query("
        SELECT DATE(created_at) AS d,
               SUM(amount_paid) AS rev,
               SUM(quantity)    AS qty
        FROM ticket_purchases
        WHERE status = 'completed'
        GROUP BY DATE(created_at)
        ORDER BY d ASC
        LIMIT 60
    ")->fetchAll(PDO::FETCH_ASSOC);

    $chart_labels = $chart_revenue = $chart_tickets = [];
    foreach ($daily_rows as $r) {
        $chart_labels[]  = date('d M', strtotime($r['d']));
        $chart_revenue[] = round(floatval($r['rev']), 2);
        $chart_tickets[] = intval($r['qty']);
    }

    // ── Chart: category breakdown ─────────────────────────────
    $cat_rows = $pdo->query("
        SELECT COALESCE(t.category, 'Uncategorized') AS cat,
               SUM(tp.amount_paid) AS rev,
               SUM(tp.quantity)    AS qty
        FROM ticket_purchases tp
        LEFT JOIN tickets t ON tp.ticket_id = t.id
        WHERE tp.status = 'completed'
        GROUP BY cat
        ORDER BY rev DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
    $cat_labels  = array_column($cat_rows, 'cat');
    $cat_revenue = array_map('floatval', array_column($cat_rows, 'rev'));

    // ── Chart: top events ─────────────────────────────────────
    $event_rows = $pdo->query("
        SELECT COALESCE(t.title, 'Unknown Event') AS ev,
               SUM(tp.amount_paid) AS rev
        FROM ticket_purchases tp
        LEFT JOIN tickets t ON tp.ticket_id = t.id
        WHERE tp.status = 'completed'
        GROUP BY ev
        ORDER BY rev DESC
        LIMIT 10
    ")->fetchAll(PDO::FETCH_ASSOC);
    $event_labels  = array_column($event_rows, 'ev');
    $event_revenue = array_map('floatval', array_column($event_rows, 'rev'));

    // ── Server-side filtered count ────────────────────────────
    $where_parts = [];
    $bind_params = [];
    if ($search_q !== '') {
        $where_parts[] = "(tp.buyer_name LIKE :q OR tp.buyer_email LIKE :q
                           OR t.title LIKE :q OR tp.transaction_id LIKE :q
                           OR tp.mpesa_receipt LIKE :q)";
        $bind_params[':q'] = '%' . $search_q . '%';
    }
    if ($status_filter !== '') {
        $where_parts[] = "tp.status = :status";
        $bind_params[':status'] = $status_filter;
    }
    $where_sql = $where_parts ? 'WHERE ' . implode(' AND ', $where_parts) : '';

    $cnt_stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM ticket_purchases tp
        LEFT JOIN tickets t ON tp.ticket_id = t.id
        $where_sql
    ");
    $cnt_stmt->execute($bind_params);
    $filtered_total = (int)$cnt_stmt->fetchColumn();
    $total_pages    = max(1, (int)ceil($filtered_total / $per_page));
    $current_page   = min($current_page, $total_pages);
    $offset         = ($current_page - 1) * $per_page;

    // ── Fetch current page ────────────────────────────────────
    $stmt = $pdo->prepare("
        SELECT tp.*, t.title AS ticket_title, t.venue, t.category, t.price
        FROM ticket_purchases tp
        LEFT JOIN tickets t ON tp.ticket_id = t.id
        $where_sql
        ORDER BY tp.created_at DESC
        LIMIT :lim OFFSET :off
    ");
    foreach ($bind_params as $key => $val) {
        $stmt->bindValue($key, $val);
    }
    $stmt->bindValue(':lim', $per_page, PDO::PARAM_INT);
    $stmt->bindValue(':off', $offset,   PDO::PARAM_INT);
    $stmt->execute();
    $purchases = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $error = "Database error: " . htmlspecialchars($e->getMessage());
    $purchases = [];
    $total_purchases = $completed_count = $pending_count = $failed_count = $other_count = 0;
    $total_revenue_completed = $total_tickets_completed = $filtered_total = 0;
    $success_rate = 0.0; $total_pages = 1;
    $chart_labels = $chart_revenue = $chart_tickets = [];
    $cat_labels = $cat_revenue = $event_labels = $event_revenue = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Ticket Purchases — Keychele Admin</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<style>
/* ══════════════════════════════════════════
   DESIGN TOKENS
══════════════════════════════════════════ */
:root {
  --bg:          #f0f2f8;
  --surface:     #ffffff;
  --surface-2:   #f7f8fc;
  --border:      #e4e8f0;
  --border-dark: #d0d6e8;

  --navy:        #0d1b3e;
  --navy-2:      #1a2f5e;
  --blue:        #2563eb;
  --blue-h:      #1d4ed8;
  --blue-l:      #dbeafe;
  --blue-ll:     #eff6ff;

  --emerald:     #059669;
  --emerald-l:   #d1fae5;
  --amber:       #d97706;
  --amber-l:     #fef3c7;
  --red:         #dc2626;
  --red-l:       #fee2e2;
  --sky:         #0284c7;
  --sky-l:       #e0f2fe;
  --violet:      #7c3aed;
  --violet-l:    #ede9fe;
  --indigo:      #4f46e5;
  --indigo-l:    #e0e7ff;

  --text:        #0d1b3e;
  --text-2:      #4b5580;
  --text-3:      #8b95b8;

  --radius:      14px;
  --radius-sm:   9px;
  --shadow-sm:   0 1px 3px rgba(13,27,62,.06), 0 2px 8px rgba(13,27,62,.04);
  --shadow:      0 2px 8px rgba(13,27,62,.08), 0 8px 24px rgba(13,27,62,.06);
  --shadow-lg:   0 8px 32px rgba(13,27,62,.14);

  --font:        'Outfit', sans-serif;
  --mono:        'JetBrains Mono', monospace;
}

*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body {
  background: var(--bg);
  font-family: var(--font);
  color: var(--text);
  font-size: 14px;
  line-height: 1.5;
  -webkit-font-smoothing: antialiased;
}

/* ── SHELL ── */
.shell { padding: 28px; max-width: 1680px; margin: 0 auto; }

/* ── PAGE HEADER ── */
.pg-header {
  display: flex; align-items: flex-start;
  justify-content: space-between;
  margin-bottom: 26px; gap: 16px; flex-wrap: wrap;
}
.pg-header-left h1 {
  font-size: 1.55rem; font-weight: 800;
  letter-spacing: -.03em; color: var(--navy);
  display: flex; align-items: center; gap: 10px;
}
.pg-header-left h1 .icon-wrap {
  width: 38px; height: 38px; background: var(--blue);
  border-radius: 10px; display: inline-flex;
  align-items: center; justify-content: center;
  font-size: .85rem; color: #fff; flex-shrink: 0;
}
.pg-header-left p { color: var(--text-2); font-size: .82rem; margin-top: 3px; padding-left: 48px; }

.btn-primary-ui {
  display: inline-flex; align-items: center; gap: 7px;
  background: var(--blue); color: #fff; border: none;
  border-radius: var(--radius-sm); padding: 10px 20px;
  font-family: var(--font); font-size: .85rem; font-weight: 600;
  text-decoration: none; cursor: pointer;
  transition: background .16s, transform .14s, box-shadow .16s;
  box-shadow: 0 2px 10px rgba(37,99,235,.35);
}
.btn-primary-ui:hover { background: var(--blue-h); color:#fff; transform:translateY(-1px); box-shadow: 0 4px 16px rgba(37,99,235,.4); }

/* ── KPI GRID ── */
.kpi-grid {
  display: grid;
  grid-template-columns: repeat(6, 1fr);
  gap: 14px; margin-bottom: 26px;
}
@media (max-width:1100px) { .kpi-grid { grid-template-columns: repeat(3,1fr); } }
@media (max-width:600px)  { .kpi-grid { grid-template-columns: repeat(2,1fr); } }

.kpi {
  background: var(--surface); border-radius: var(--radius);
  box-shadow: var(--shadow-sm); padding: 18px 20px;
  position: relative; overflow: hidden;
  transition: transform .2s, box-shadow .2s;
  border: 1px solid var(--border);
}
.kpi:hover { transform:translateY(-3px); box-shadow: var(--shadow); }
.kpi-bar {
  position: absolute; left:0; top:0; bottom:0; width:4px;
  border-radius: var(--radius) 0 0 var(--radius);
}
.kpi.c-blue   .kpi-bar { background: var(--blue); }
.kpi.c-green  .kpi-bar { background: var(--emerald); }
.kpi.c-amber  .kpi-bar { background: var(--amber); }
.kpi.c-red    .kpi-bar { background: var(--red); }
.kpi.c-sky    .kpi-bar { background: var(--sky); }
.kpi.c-violet .kpi-bar { background: var(--violet); }
.kpi.c-indigo .kpi-bar { background: var(--indigo); }

.kpi-icon {
  width:36px; height:36px; border-radius:9px;
  display:flex; align-items:center; justify-content:center;
  font-size:.78rem; margin-bottom:12px;
}
.kpi.c-blue   .kpi-icon { background:var(--blue-l);   color:var(--blue); }
.kpi.c-green  .kpi-icon { background:var(--emerald-l);color:var(--emerald); }
.kpi.c-amber  .kpi-icon { background:var(--amber-l);  color:var(--amber); }
.kpi.c-red    .kpi-icon { background:var(--red-l);    color:var(--red); }
.kpi.c-sky    .kpi-icon { background:var(--sky-l);    color:var(--sky); }
.kpi.c-violet .kpi-icon { background:var(--violet-l); color:var(--violet); }
.kpi.c-indigo .kpi-icon { background:var(--indigo-l); color:var(--indigo); }

.kpi-label { font-size:.7rem; font-weight:600; text-transform:uppercase; letter-spacing:.07em; color:var(--text-3); margin-bottom:4px; }
.kpi-value { font-size:1.8rem; font-weight:800; line-height:1; letter-spacing:-.04em; }
.kpi.c-blue   .kpi-value { color:var(--blue); }
.kpi.c-green  .kpi-value { color:var(--emerald); }
.kpi.c-amber  .kpi-value { color:var(--amber); }
.kpi.c-red    .kpi-value { color:var(--red); }
.kpi.c-sky    .kpi-value { color:var(--sky); }
.kpi.c-violet .kpi-value { color:var(--violet); }
.kpi.c-indigo .kpi-value { color:var(--indigo); }
.kpi-sub { font-size:.72rem; color:var(--text-3); margin-top:3px; }

/* ── TABS ── */
.tab-nav {
  display:flex; gap:2px; background:var(--surface);
  border:1px solid var(--border); border-radius:var(--radius-sm);
  padding:4px; width:fit-content; margin-bottom:20px; flex-wrap:wrap;
}
.tab-btn {
  display:inline-flex; align-items:center; gap:7px;
  padding:8px 16px; border:none; background:transparent;
  border-radius:6px; font-family:var(--font); font-size:.82rem;
  font-weight:500; color:var(--text-2); cursor:pointer;
  transition:all .15s; white-space:nowrap;
}
.tab-btn.active { background:var(--blue); color:#fff; font-weight:600; box-shadow:0 2px 8px rgba(37,99,235,.3); }
.tab-btn i { font-size:.76rem; }

.tab-pane { display:none; }
.tab-pane.active { display:block; animation:fade-in .2s ease; }
@keyframes fade-in { from { opacity:.4; transform:translateY(5px); } to { opacity:1; transform:none; } }

/* ── CARD ── */
.card {
  background:var(--surface); border-radius:var(--radius);
  box-shadow:var(--shadow-sm); border:1px solid var(--border); overflow:hidden;
}
.card-head {
  display:flex; align-items:center; justify-content:space-between;
  gap:12px; padding:16px 20px; border-bottom:1px solid var(--border);
  flex-wrap:wrap; background:var(--surface-2);
}
.card-title {
  font-size:.92rem; font-weight:700; color:var(--navy);
  display:flex; align-items:center; gap:8px;
}
.card-title i { color:var(--blue); font-size:.8rem; }

/* ── STATUS STRIP ── */
.status-strip {
  display:grid; grid-template-columns:repeat(4,1fr); gap:14px; margin-bottom:26px;
}
@media (max-width:640px) { .status-strip { grid-template-columns:repeat(2,1fr); } }
.ss-card {
  background:var(--surface); border-radius:var(--radius); border:1px solid var(--border);
  box-shadow:var(--shadow-sm); padding:16px 20px; text-align:center;
  transition:transform .2s, box-shadow .2s;
}
.ss-card:hover { transform:translateY(-2px); box-shadow:var(--shadow); }
.ss-label { font-size:.7rem; font-weight:700; text-transform:uppercase; letter-spacing:.07em; margin-bottom:6px; }
.ss-value { font-size:1.7rem; font-weight:800; line-height:1; }
.ss-card.green .ss-label { color:var(--emerald); } .ss-card.green .ss-value { color:var(--emerald); }
.ss-card.amber .ss-label { color:var(--amber);   } .ss-card.amber .ss-value { color:var(--amber); }
.ss-card.red   .ss-label { color:var(--red);     } .ss-card.red   .ss-value { color:var(--red); }
.ss-card.gray  .ss-label { color:var(--text-3);  } .ss-card.gray  .ss-value { color:var(--text-3); }

/* ── CHARTS GRID ── */
.charts-grid {
  display:grid; grid-template-columns:repeat(2,1fr); gap:20px; margin-bottom:26px;
}
@media (max-width:900px) { .charts-grid { grid-template-columns:1fr; } }
.chart-body { padding:18px 20px; }

/* ── TOOLBAR ── */
.toolbar { display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
.search-wrap { position:relative; }
.search-wrap i { position:absolute; left:11px; top:50%; transform:translateY(-50%); color:var(--text-3); font-size:.75rem; pointer-events:none; }
.search-input {
  height:36px; width:240px; padding:0 12px 0 34px;
  border:1.5px solid var(--border-dark); border-radius:var(--radius-sm);
  font-family:var(--font); font-size:.82rem; color:var(--text);
  background:var(--surface); outline:none;
  transition:border .16s, box-shadow .16s;
}
.search-input:focus { border-color:var(--blue); box-shadow:0 0 0 3px rgba(37,99,235,.1); }
.select-sm {
  height:36px; padding:0 10px;
  border:1.5px solid var(--border-dark); border-radius:var(--radius-sm);
  font-family:var(--font); font-size:.82rem; color:var(--text);
  background:var(--surface); outline:none; cursor:pointer;
  transition:border .16s;
}
.select-sm:focus { border-color:var(--blue); }
.btn-sm-ui {
  height:36px; padding:0 14px; border:none; border-radius:var(--radius-sm);
  font-family:var(--font); font-size:.8rem; font-weight:600;
  cursor:pointer; display:inline-flex; align-items:center; gap:6px;
  transition:all .15s;
}
.btn-sm-blue  { background:var(--blue); color:#fff; }
.btn-sm-blue:hover  { background:var(--blue-h); }
.btn-sm-ghost { background:var(--bg); color:var(--text-2); border:1px solid var(--border-dark); }
.btn-sm-ghost:hover { color:var(--red); border-color:var(--red); background:var(--red-l); }
.btn-sm-amber { background:var(--amber); color:#fff; }
.btn-sm-amber:hover { background:#b45309; }

/* ── TABLE ── */
.tbl-wrap { overflow-x:auto; }
table { width:100%; border-collapse:collapse; }
thead th {
  padding:10px 14px; background:var(--surface-2);
  border-bottom:1px solid var(--border);
  font-size:.68rem; font-weight:700; text-transform:uppercase;
  letter-spacing:.08em; color:var(--text-3); white-space:nowrap; user-select:none;
}
tbody tr { border-bottom:1px solid var(--border); transition:background .1s; }
tbody tr:last-child { border-bottom:none; }
tbody tr:hover { background:var(--blue-ll); }
tbody tr.row-pending { background:#fffdf0; }
tbody tr.row-pending:hover { background:#fff8d6; }
tbody tr.row-failed  { background:#fff5f5; }
tbody tr.row-failed:hover  { background:#ffecec; }
td { padding:12px 14px; font-size:.82rem; color:var(--text); vertical-align:middle; }

/* ── BUYER CELL ── */
.buyer-cell { display:flex; align-items:center; gap:10px; }
.avatar {
  width:34px; height:34px; border-radius:9px;
  background:var(--blue-l); color:var(--blue);
  font-weight:800; font-size:.72rem;
  display:flex; align-items:center; justify-content:center;
  flex-shrink:0; letter-spacing:-.01em;
}
.buyer-name  { font-weight:600; line-height:1.2; }
.buyer-email { font-size:.7rem; color:var(--text-3); font-family:var(--mono); }

/* ── BADGES ── */
.badge {
  display:inline-flex; align-items:center; gap:4px;
  padding:3px 10px; border-radius:100px;
  font-size:.68rem; font-weight:700; letter-spacing:.02em; white-space:nowrap;
}
.badge-completed { background:var(--emerald-l); color:#065f46; }
.badge-pending   { background:var(--amber-l);   color:#92400e; }
.badge-failed    { background:var(--red-l);      color:#991b1b; }
.badge-other     { background:var(--bg);         color:var(--text-2); }
.badge-checked   { background:var(--emerald-l);  color:#065f46; }
.badge-not-in    { background:var(--bg);         color:var(--text-3); }

/* ── ACTIONS ── */
.actions { display:flex; align-items:center; gap:4px; justify-content:flex-end; }
.act {
  width:30px; height:30px; border-radius:7px;
  border:1.5px solid transparent; background:transparent;
  display:inline-flex; align-items:center; justify-content:center;
  font-size:.72rem; cursor:pointer; text-decoration:none;
  transition:all .14s;
}
.act:hover { transform:translateY(-1px); }
.act-view   { border-color:#bae6fd; color:#0284c7; }
.act-view:hover   { background:var(--sky-l); }
.act-remind { border-color:#fde68a; color:var(--amber); }
.act-remind:hover { background:var(--amber-l); }

/* ── PAGINATION ── */
.pagi-wrap {
  display:flex; align-items:center; justify-content:space-between;
  padding:14px 20px; border-top:1px solid var(--border);
  background:var(--surface-2); flex-wrap:wrap; gap:10px;
}
.pagi-info { font-size:.75rem; color:var(--text-3); }
.pagi-links { display:flex; gap:4px; }
.pagi-btn {
  height:32px; min-width:32px; padding:0 9px;
  border:1.5px solid var(--border-dark); border-radius:7px;
  background:var(--surface); font-family:var(--font);
  font-size:.78rem; font-weight:500; color:var(--text-2);
  cursor:pointer; text-decoration:none;
  display:inline-flex; align-items:center; justify-content:center;
  transition:all .14s;
}
.pagi-btn:hover:not(.pagi-active):not(.pagi-off) {
  border-color:var(--blue); color:var(--blue); background:var(--blue-ll);
}
.pagi-active { background:var(--blue); border-color:var(--blue); color:#fff; font-weight:700; }
.pagi-off    { opacity:.35; pointer-events:none; }
.pagi-dots   { border:none; background:none; color:var(--text-3); pointer-events:none; min-width:20px; }

/* ── EMPTY STATE ── */
.empty { text-align:center; padding:60px 20px; color:var(--text-3); }
.empty i { font-size:2rem; opacity:.3; display:block; margin-bottom:12px; }
.empty p { font-size:.85rem; }

/* ── ALERT ── */
.alert-err {
  background:var(--red-l); border:1px solid #fca5a5; border-radius:var(--radius-sm);
  padding:12px 16px; color:#991b1b; font-size:.84rem; margin-bottom:20px;
  display:flex; align-items:center; gap:8px;
}

/* ── MODAL ── */
.modal-overlay {
  display:none; position:fixed; inset:0;
  background:rgba(13,27,62,.5); backdrop-filter:blur(4px);
  z-index:9000; align-items:center; justify-content:center;
}
.modal-overlay.open { display:flex; }
.modal-box {
  background:var(--surface); border-radius:18px;
  box-shadow:var(--shadow-lg); width:94%; max-width:560px;
  overflow:hidden; animation:modal-pop .25s cubic-bezier(.16,1,.3,1);
  max-height:90vh; display:flex; flex-direction:column;
}
@keyframes modal-pop {
  from { opacity:0; transform:scale(.94) translateY(14px); }
  to   { opacity:1; transform:none; }
}
.modal-head {
  display:flex; align-items:center; justify-content:space-between;
  padding:18px 22px; border-bottom:1px solid var(--border);
  background:var(--surface-2); flex-shrink:0;
}
.modal-head h4 { font-weight:800; font-size:.95rem; margin:0; color:var(--navy); }
.modal-close  { background:none; border:none; color:var(--text-2); font-size:1.1rem; cursor:pointer; }
.modal-close:hover { color:var(--red); }
.modal-body  { padding:22px; overflow-y:auto; flex:1; }
.modal-foot  { padding:16px 22px; border-top:1px solid var(--border); display:flex; gap:9px; justify-content:flex-end; flex-shrink:0; }
.btn-modal {
  height:38px; padding:0 20px; border:none; border-radius:var(--radius-sm);
  font-family:var(--font); font-weight:600; font-size:.82rem; cursor:pointer;
}
.btn-cancel-modal { background:var(--bg); color:var(--text); border:1px solid var(--border-dark); }

/* ── DETAIL ROW ── */
.detail-grid { display:grid; grid-template-columns:repeat(2,1fr); gap:16px; }
@media (max-width:480px) { .detail-grid { grid-template-columns:1fr; } }
.detail-item { display:flex; flex-direction:column; gap:3px; }
.detail-label { font-size:.68rem; font-weight:700; text-transform:uppercase; letter-spacing:.07em; color:var(--text-3); }
.detail-value { font-size:.88rem; font-weight:500; color:var(--text); }
.detail-value code { font-family:var(--mono); font-size:.75rem; background:var(--bg); padding:2px 7px; border-radius:5px; }
.detail-full  { grid-column:1/-1; }
.detail-divider { grid-column:1/-1; border:none; border-top:1px solid var(--border); margin:4px 0; }

/* ── SweetAlert overrides ── */
.swal2-popup  { font-family:var(--font) !important; border-radius:16px !important; }
.swal2-title  { font-family:var(--font) !important; font-weight:800 !important; }
.swal2-confirm { background:var(--blue) !important; border-radius:8px !important; font-family:var(--font) !important; font-weight:600 !important; }

@media (max-width:680px) {
  .shell { padding:14px; }
  .search-input { width:100%; }
}
</style>
</head>
<body>
<div class="shell">

  <!-- ── PAGE HEADER ── -->
  <div class="pg-header">
    <div class="pg-header-left">
      <h1>
        <span class="icon-wrap"><i class="fas fa-ticket-alt"></i></span>
        Ticket Purchases
      </h1>
      <p>Revenue &amp; statistics are from <strong>completed</strong> transactions · Server-side search &amp; pagination</p>
    </div>
  </div>

  <?php if (isset($error)): ?>
    <div class="alert-err"><i class="fas fa-exclamation-circle"></i><?= $error ?></div>
  <?php endif; ?>

  <!-- ── KPI STRIP ── -->
  <div class="kpi-grid">
    <div class="kpi c-blue">
      <div class="kpi-bar"></div>
      <div class="kpi-icon"><i class="fas fa-wallet"></i></div>
      <div class="kpi-label">Total Revenue</div>
      <div class="kpi-value" style="font-size:1.35rem">KSh <?= number_format($total_revenue_completed, 0) ?></div>
      <div class="kpi-sub">completed only</div>
    </div>
    <div class="kpi c-green">
      <div class="kpi-bar"></div>
      <div class="kpi-icon"><i class="fas fa-check-circle"></i></div>
      <div class="kpi-label">Completed</div>
      <div class="kpi-value"><?= number_format($completed_count) ?></div>
      <div class="kpi-sub">successful payments</div>
    </div>
    <div class="kpi c-violet">
      <div class="kpi-bar"></div>
      <div class="kpi-icon"><i class="fas fa-ticket-alt"></i></div>
      <div class="kpi-label">Tickets Sold</div>
      <div class="kpi-value"><?= number_format($total_tickets_completed) ?></div>
      <div class="kpi-sub">total units</div>
    </div>
    <div class="kpi c-sky">
      <div class="kpi-bar"></div>
      <div class="kpi-icon"><i class="fas fa-chart-line"></i></div>
      <div class="kpi-label">Success Rate</div>
      <div class="kpi-value"><?= $success_rate ?><span style="font-size:1rem;font-weight:500">%</span></div>
      <div class="kpi-sub">of all purchases</div>
    </div>
    <div class="kpi c-amber">
      <div class="kpi-bar"></div>
      <div class="kpi-icon"><i class="fas fa-clock"></i></div>
      <div class="kpi-label">Pending</div>
      <div class="kpi-value"><?= number_format($pending_count) ?></div>
      <div class="kpi-sub">awaiting payment</div>
    </div>
    <div class="kpi c-red">
      <div class="kpi-bar"></div>
      <div class="kpi-icon"><i class="fas fa-times-circle"></i></div>
      <div class="kpi-label">Failed</div>
      <div class="kpi-value"><?= number_format($failed_count) ?></div>
      <div class="kpi-sub">unsuccessful</div>
    </div>
  </div>

  <!-- ── TABS ── -->
  <div class="tab-nav">
    <button class="tab-btn active" data-tab="tab-purchases"><i class="fas fa-list"></i> Purchases</button>
    <button class="tab-btn" data-tab="tab-analytics"><i class="fas fa-chart-bar"></i> Analytics</button>
  </div>

  <!-- ══════════════════════════════════════════════════════
       TAB 1 — PURCHASES TABLE
  ══════════════════════════════════════════════════════ -->
  <div class="tab-pane active" id="tab-purchases">
    <div class="card">
      <div class="card-head">
        <div class="card-title">
          <i class="fas fa-list"></i>
          All Purchases
          <span style="font-weight:400;font-size:.76rem;color:var(--text-3)">
            (<?= number_format($filtered_total) ?><?= ($search_q || $status_filter) ? ' matching' : ' total' ?>)
          </span>
        </div>
        <!-- Server-side filter form -->
        <form method="GET" id="filterForm" style="display:contents">
          <?php if ($dash_page): ?>
            <input type="hidden" name="page"   value="<?= htmlspecialchars($dash_page) ?>">
          <?php endif; ?>
          <input type="hidden" name="p" value="1" id="pageHidden">
          <div class="toolbar">
            <div class="search-wrap">
              <i class="fas fa-search"></i>
              <input type="text" name="q" class="search-input" id="searchInput"
                     value="<?= htmlspecialchars($search_q) ?>"
                     placeholder="Buyer, email, ticket, receipt…">
            </div>
            <select name="status" class="select-sm" id="statusSel">
              <option value="" <?= $status_filter===''?'selected':'' ?>>All Statuses</option>
              <option value="completed" <?= $status_filter==='completed'?'selected':'' ?>>Completed</option>
              <option value="pending"   <?= $status_filter==='pending'  ?'selected':'' ?>>Pending</option>
              <option value="failed"    <?= $status_filter==='failed'   ?'selected':'' ?>>Failed</option>
            </select>
            <select name="per_page" class="select-sm" id="perPageSel">
              <?php foreach ([15, 25, 50, 100] as $n): ?>
                <option value="<?= $n ?>" <?= $per_page==$n?'selected':'' ?>><?= $n ?> / page</option>
              <?php endforeach; ?>
            </select>
            <button type="submit" class="btn-sm-ui btn-sm-blue"><i class="fas fa-filter"></i> Filter</button>
            <?php if ($search_q || $status_filter || $per_page != 15): ?>
              <a href="<?= htmlspecialchars(pgTicketLink(1, 15, '', '', $dash_page)) ?>"
                 class="btn-sm-ui btn-sm-ghost">
                <i class="fas fa-times"></i> Clear
              </a>
            <?php endif; ?>
          </div>
        </form>
      </div>

      <div class="tbl-wrap">
        <table>
          <thead>
            <tr>
              <th>Date</th>
              <th>Buyer</th>
              <th>Ticket / Event</th>
              <th>Amount</th>
              <th style="text-align:center">Qty</th>
              <th>Status</th>
              <th style="text-align:center">Check-In</th>
              <th style="text-align:right">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!empty($purchases)): ?>
              <?php foreach ($purchases as $row):
                $status_lower = trim(strtolower($row['status'] ?? ''));
                $rc = match($status_lower) {
                    'pending' => 'row-pending',
                    'failed'  => 'row-failed',
                    default   => ''
                };
                $badge_class = match($status_lower) {
                    'completed' => 'badge-completed',
                    'pending'   => 'badge-pending',
                    'failed'    => 'badge-failed',
                    default     => 'badge-other'
                };
                $badge_icon = match($status_lower) {
                    'completed' => '<i class="fas fa-check"></i>',
                    'pending'   => '<i class="fas fa-clock"></i>',
                    'failed'    => '<i class="fas fa-times"></i>',
                    default     => ''
                };
                $parts = explode(' ', trim($row['buyer_name'] ?? ''));
                $init  = strtoupper(substr($parts[0] ?? '?', 0, 1) . substr(end($parts), 0, 1));
              ?>
              <tr class="<?= $rc ?>">
                <td style="white-space:nowrap">
                  <?= date('d M Y', strtotime($row['created_at'])) ?>
                  <div style="font-size:.69rem;color:var(--text-3);font-family:var(--mono)">
                    <?= date('H:i', strtotime($row['created_at'])) ?>
                  </div>
                </td>
                <td>
                  <div class="buyer-cell">
                    <div class="avatar"><?= $init ?></div>
                    <div>
                      <div class="buyer-name"><?= htmlspecialchars($row['buyer_name'] ?? '—') ?></div>
                      <div class="buyer-email"><?= htmlspecialchars($row['buyer_email'] ?? '—') ?></div>
                    </div>
                  </div>
                </td>
                <td>
                  <div style="font-weight:600;line-height:1.2"><?= htmlspecialchars($row['ticket_title'] ?? '—') ?></div>
                  <?php if (!empty($row['venue'])): ?>
                    <div style="font-size:.7rem;color:var(--text-3)"><i class="fas fa-map-marker-alt" style="font-size:.6rem"></i> <?= htmlspecialchars($row['venue']) ?></div>
                  <?php endif; ?>
                </td>
                <td style="white-space:nowrap">
                  <span style="font-weight:700">KSh <?= number_format($row['amount_paid'] ?? 0, 2) ?></span>
                  <?php if (!empty($row['price']) && $row['quantity'] > 1): ?>
                    <div style="font-size:.69rem;color:var(--text-3)">
                      <?= $row['quantity'] ?> × KSh <?= number_format($row['price'], 2) ?>
                    </div>
                  <?php endif; ?>
                </td>
                <td style="text-align:center;font-weight:600"><?= intval($row['quantity'] ?? 1) ?></td>
                <td>
                  <span class="badge <?= $badge_class ?>"><?= $badge_icon ?> <?= ucfirst($status_lower) ?: 'Unknown' ?></span>
                </td>
                <td style="text-align:center">
                  <?php if (!empty($row['is_checked_in'])): ?>
                    <span class="badge badge-checked"><i class="fas fa-check-circle"></i> In</span>
                  <?php else: ?>
                    <span class="badge badge-not-in"><i class="fas fa-minus-circle"></i> No</span>
                  <?php endif; ?>
                </td>
                <td>
                  <div class="actions">
                    <button class="act act-view view-details-btn"
                            data-purchase='<?= htmlspecialchars(json_encode($row, JSON_HEX_APOS | JSON_HEX_QUOT)) ?>'
                            title="View Details">
                      <i class="fas fa-eye"></i>
                    </button>
                    <?php if ($status_lower === 'failed' || $status_lower === 'pending'): ?>
                      <button class="act act-remind send-remind-btn"
                              data-id="<?= $row['id'] ?>"
                              data-name="<?= htmlspecialchars($row['buyer_name'] ?? 'Customer') ?>"
                              title="Send Reminder">
                        <i class="fas fa-envelope"></i>
                      </button>
                    <?php endif; ?>
                  </div>
                </td>
              </tr>
              <?php endforeach; ?>
            <?php else: ?>
              <tr>
                <td colspan="8">
                  <div class="empty">
                    <i class="fas fa-ticket-alt"></i>
                    <p>No purchases found<?= ($search_q || $status_filter) ? ' matching your filters' : '' ?>.</p>
                  </div>
                </td>
              </tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

      <!-- ── PAGINATION ── -->
      <?php if ($total_pages > 1 || $filtered_total > 0):
        $showFrom = $offset + 1;
        $showTo   = min($offset + $per_page, $filtered_total);
      ?>
      <div class="pagi-wrap">
        <div class="pagi-info">
          Showing <?= number_format($showFrom) ?>–<?= number_format($showTo) ?> of <?= number_format($filtered_total) ?> purchases
        </div>
        <div class="pagi-links">
          <!-- Prev -->
          <?php if ($current_page > 1): ?>
            <a href="<?= htmlspecialchars(pgTicketLink($current_page-1,$per_page,$search_q,$status_filter,$dash_page)) ?>" class="pagi-btn">
              <i class="fas fa-chevron-left"></i>
            </a>
          <?php else: ?>
            <span class="pagi-btn pagi-off"><i class="fas fa-chevron-left"></i></span>
          <?php endif; ?>

          <!-- Numbers -->
          <?php
            $win_start = max(1, $current_page - 2);
            $win_end   = min($total_pages, $current_page + 2);
            if ($win_start > 1): ?>
              <a href="<?= htmlspecialchars(pgTicketLink(1,$per_page,$search_q,$status_filter,$dash_page)) ?>" class="pagi-btn">1</a>
              <?php if ($win_start > 2): ?><span class="pagi-btn pagi-dots">…</span><?php endif; ?>
            <?php endif;
            for ($i = $win_start; $i <= $win_end; $i++): ?>
              <a href="<?= htmlspecialchars(pgTicketLink($i,$per_page,$search_q,$status_filter,$dash_page)) ?>"
                 class="pagi-btn <?= $i === $current_page ? 'pagi-active' : '' ?>">
                <?= $i ?>
              </a>
            <?php endfor;
            if ($win_end < $total_pages): ?>
              <?php if ($win_end < $total_pages - 1): ?><span class="pagi-btn pagi-dots">…</span><?php endif; ?>
              <a href="<?= htmlspecialchars(pgTicketLink($total_pages,$per_page,$search_q,$status_filter,$dash_page)) ?>" class="pagi-btn"><?= $total_pages ?></a>
            <?php endif; ?>

          <!-- Next -->
          <?php if ($current_page < $total_pages): ?>
            <a href="<?= htmlspecialchars(pgTicketLink($current_page+1,$per_page,$search_q,$status_filter,$dash_page)) ?>" class="pagi-btn">
              <i class="fas fa-chevron-right"></i>
            </a>
          <?php else: ?>
            <span class="pagi-btn pagi-off"><i class="fas fa-chevron-right"></i></span>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>
    </div>
  </div><!-- /tab-purchases -->

  <!-- ══════════════════════════════════════════════════════
       TAB 2 — ANALYTICS
  ══════════════════════════════════════════════════════ -->
  <div class="tab-pane" id="tab-analytics">

    <!-- Status breakdown strip -->
    <div class="status-strip">
      <div class="ss-card green">
        <div class="ss-label"><i class="fas fa-check-circle" style="margin-right:4px"></i>Completed</div>
        <div class="ss-value"><?= number_format($completed_count) ?></div>
      </div>
      <div class="ss-card amber">
        <div class="ss-label"><i class="fas fa-clock" style="margin-right:4px"></i>Pending</div>
        <div class="ss-value"><?= number_format($pending_count) ?></div>
      </div>
      <div class="ss-card red">
        <div class="ss-label"><i class="fas fa-times-circle" style="margin-right:4px"></i>Failed</div>
        <div class="ss-value"><?= number_format($failed_count) ?></div>
      </div>
      <div class="ss-card gray">
        <div class="ss-label"><i class="fas fa-question-circle" style="margin-right:4px"></i>Other</div>
        <div class="ss-value"><?= number_format($other_count) ?></div>
      </div>
    </div>

    <!-- Charts: trend + category -->
    <div class="charts-grid">
      <div class="card">
        <div class="card-head">
          <div class="card-title"><i class="fas fa-chart-line"></i> Revenue &amp; Tickets Trend</div>
        </div>
        <div class="chart-body">
          <?php if (!empty($chart_labels)): ?>
            <canvas id="trendChart" height="160"></canvas>
          <?php else: ?>
            <div class="empty"><i class="fas fa-chart-line"></i><p>No completed sales data yet.</p></div>
          <?php endif; ?>
        </div>
      </div>
      <div class="card">
        <div class="card-head">
          <div class="card-title"><i class="fas fa-tags"></i> Revenue by Category</div>
        </div>
        <div class="chart-body">
          <?php if (!empty($cat_labels)): ?>
            <canvas id="categoryChart" height="160"></canvas>
          <?php else: ?>
            <div class="empty"><i class="fas fa-tags"></i><p>No category data yet.</p></div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Top events -->
    <div class="card">
      <div class="card-head">
        <div class="card-title"><i class="fas fa-trophy"></i> Top Events by Revenue <span style="font-weight:400;font-size:.75rem;color:var(--text-3)">(top 10 · completed)</span></div>
      </div>
      <div class="chart-body">
        <?php if (!empty($event_labels)): ?>
          <canvas id="eventChart" height="200"></canvas>
        <?php else: ?>
          <div class="empty"><i class="fas fa-ticket-alt"></i><p>No completed sales recorded yet.</p></div>
        <?php endif; ?>
      </div>
    </div>

  </div><!-- /tab-analytics -->

</div><!-- /shell -->

<!-- ── PURCHASE DETAIL MODAL ── -->
<div class="modal-overlay" id="detailModal">
  <div class="modal-box">
    <div class="modal-head">
      <h4><i class="fas fa-receipt" style="margin-right:8px;color:var(--blue)"></i>Purchase Details</h4>
      <button class="modal-close" id="closeDetailModal"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body" id="detailModalBody">
      <!-- populated by JS -->
    </div>
    <div class="modal-foot">
      <button class="btn-modal btn-cancel-modal" id="closeDetailBtn">Close</button>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
<script>
/* ── TABS ── */
document.querySelectorAll('.tab-btn').forEach(btn => {
  btn.addEventListener('click', () => {
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    document.querySelectorAll('.tab-pane').forEach(p => p.classList.remove('active'));
    btn.classList.add('active');
    document.getElementById(btn.dataset.tab).classList.add('active');
  });
});

/* ── AUTO-SUBMIT ON SELECT CHANGE ── */
document.getElementById('perPageSel')?.addEventListener('change', () => {
  document.getElementById('pageHidden').value = '1';
  document.getElementById('filterForm').submit();
});
document.getElementById('statusSel')?.addEventListener('change', () => {
  document.getElementById('pageHidden').value = '1';
  document.getElementById('filterForm').submit();
});

/* ── RESET PAGE ON SEARCH ── */
document.getElementById('searchInput')?.addEventListener('keydown', e => {
  if (e.key === 'Enter') document.getElementById('pageHidden').value = '1';
});

/* ── MODAL HELPERS ── */
const openModal  = id => document.getElementById(id).classList.add('open');
const closeModal = id => document.getElementById(id).classList.remove('open');

document.getElementById('closeDetailModal')?.addEventListener('click', () => closeModal('detailModal'));
document.getElementById('closeDetailBtn')?.addEventListener('click',   () => closeModal('detailModal'));
document.getElementById('detailModal')?.addEventListener('click', e => {
  if (e.target === document.getElementById('detailModal')) closeModal('detailModal');
});

/* ── VIEW DETAILS ── */
document.addEventListener('click', e => {
  const btn = e.target.closest('.view-details-btn');
  if (!btn) return;
  const d = JSON.parse(btn.getAttribute('data-purchase'));

  const statusMap = {
    completed: '<span class="badge badge-completed"><i class="fas fa-check"></i> Completed</span>',
    pending:   '<span class="badge badge-pending"><i class="fas fa-clock"></i> Pending</span>',
    failed:    '<span class="badge badge-failed"><i class="fas fa-times"></i> Failed</span>',
  };
  const statusBadge = statusMap[d.status?.toLowerCase()] || `<span class="badge badge-other">${d.status||'—'}</span>`;

  const checkin = d.is_checked_in
    ? '<span class="badge badge-checked"><i class="fas fa-check-circle"></i> Checked In</span>'
    : '<span class="badge badge-not-in"><i class="fas fa-minus-circle"></i> Not Checked In</span>';

  const failBlock = d.failed_reason
    ? `<div style="grid-column:1/-1;background:var(--red-l);border:1px solid #fca5a5;border-radius:var(--radius-sm);padding:12px 14px">
        <div class="detail-label" style="color:#991b1b;margin-bottom:4px">Failure Reason</div>
        <div class="detail-value" style="color:#991b1b">${esc(d.failed_reason)}</div>
       </div>` : '';

  document.getElementById('detailModalBody').innerHTML = `
    <div class="detail-grid">
      <div class="detail-item">
        <div class="detail-label">Buyer Name</div>
        <div class="detail-value">${esc(d.buyer_name||'—')}</div>
      </div>
      <div class="detail-item">
        <div class="detail-label">Email</div>
        <div class="detail-value">${esc(d.buyer_email||'—')}</div>
      </div>
      <div class="detail-item">
        <div class="detail-label">Phone</div>
        <div class="detail-value">${esc(d.buyer_phone||'—')}</div>
      </div>
      <div class="detail-item">
        <div class="detail-label">Status</div>
        <div class="detail-value">${statusBadge}</div>
      </div>
      <hr class="detail-divider">
      <div class="detail-item">
        <div class="detail-label">Event / Ticket</div>
        <div class="detail-value">${esc(d.ticket_title||'—')}</div>
      </div>
      <div class="detail-item">
        <div class="detail-label">Venue</div>
        <div class="detail-value">${esc(d.venue||'—')}</div>
      </div>
      <div class="detail-item">
        <div class="detail-label">Category</div>
        <div class="detail-value">${esc(d.category||'—')}</div>
      </div>
      <div class="detail-item">
        <div class="detail-label">Check-In</div>
        <div class="detail-value">${checkin}</div>
      </div>
      <hr class="detail-divider">
      <div class="detail-item">
        <div class="detail-label">Amount Paid</div>
        <div class="detail-value" style="font-size:1.1rem;font-weight:700;color:var(--emerald)">
          KSh ${parseFloat(d.amount_paid||0).toLocaleString('en-KE',{minimumFractionDigits:2,maximumFractionDigits:2})}
        </div>
      </div>
      <div class="detail-item">
        <div class="detail-label">Quantity</div>
        <div class="detail-value">${d.quantity||1}</div>
      </div>
      <div class="detail-item">
        <div class="detail-label">Purchase Date</div>
        <div class="detail-value">${new Date(d.created_at).toLocaleString('en-KE')}</div>
      </div>
      <div class="detail-item">
        <div class="detail-label">Unit Price</div>
        <div class="detail-value">KSh ${parseFloat(d.price||0).toLocaleString('en-KE',{minimumFractionDigits:2})}</div>
      </div>
      <hr class="detail-divider">
      <div class="detail-item">
        <div class="detail-label">Transaction ID</div>
        <div class="detail-value"><code>${esc(d.transaction_id||'—')}</code></div>
      </div>
      <div class="detail-item">
        <div class="detail-label">M-Pesa Receipt</div>
        <div class="detail-value"><code>${esc(d.mpesa_receipt||'—')}</code></div>
      </div>
      ${failBlock}
    </div>`;
  openModal('detailModal');
});

/* ── SEND REMINDER ── */
document.addEventListener('click', async e => {
  const btn = e.target.closest('.send-remind-btn');
  if (!btn) return;

  const ok = await Swal.fire({
    icon: 'question',
    title: 'Send Reminder?',
    text: `Send a payment reminder email to ${btn.dataset.name}?`,
    showCancelButton: true,
    confirmButtonText: 'Yes, Send'
  });
  if (!ok.isConfirmed) return;

  const origHtml = btn.innerHTML;
  btn.disabled = true;
  btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';

  try {
    const fd = new FormData();
    fd.append('purchase_id', btn.dataset.id);
    const r = await fetch('send_reminder.php', { method: 'POST', body: fd });
    const d = await r.json();
    if (d.success) {
      await Swal.fire({ icon: 'success', title: 'Sent!', text: 'Reminder email sent successfully.' });
    } else {
      await Swal.fire({ icon: 'error', title: 'Failed', text: d.message || 'Could not send reminder.' });
    }
  } catch {
    await Swal.fire({ icon: 'error', title: 'Network Error', text: 'Request failed. Please try again.' });
  } finally {
    btn.disabled = false;
    btn.innerHTML = origHtml;
  }
});

/* ── ESCAPE HELPER ── */
function esc(s) {
  const d = document.createElement('div');
  d.textContent = s || '';
  return d.innerHTML;
}

/* ═══════════════════════════════════════
   CHART.JS — only init once DOM is ready
═══════════════════════════════════════ */
const FONT   = "'Outfit', sans-serif";
const GRID   = 'rgba(13,27,62,0.06)';
const TICK   = '#8b95b8';

// ── Trend Chart ────────────────────────
const trendCtx = document.getElementById('trendChart')?.getContext('2d');
if (trendCtx) {
  new Chart(trendCtx, {
    type: 'line',
    data: {
      labels: <?= json_encode($chart_labels) ?>,
      datasets: [
        {
          label: 'Revenue (KSh)',
          data: <?= json_encode($chart_revenue) ?>,
          borderColor: '#2563eb',
          backgroundColor: 'rgba(37,99,235,0.08)',
          tension: 0.35, fill: true,
          pointRadius: 3, pointHoverRadius: 5,
          borderWidth: 2
        },
        {
          label: 'Tickets Sold',
          data: <?= json_encode($chart_tickets) ?>,
          borderColor: '#059669',
          backgroundColor: 'rgba(5,150,105,0.06)',
          tension: 0.35, fill: true,
          yAxisID: 'y1',
          pointRadius: 3, pointHoverRadius: 5,
          borderWidth: 2
        }
      ]
    },
    options: {
      responsive: true,
      interaction: { mode: 'index', intersect: false },
      plugins: {
        legend: { labels: { font: { family: FONT, size: 11 }, boxWidth: 10 } },
        tooltip: {
          callbacks: {
            label: ctx => ctx.datasetIndex === 0
              ? 'KSh ' + ctx.parsed.y.toLocaleString('en-KE')
              : ctx.parsed.y + ' tickets'
          }
        }
      },
      scales: {
        x:  { grid: { color: GRID }, ticks: { font: { family: FONT, size: 10 }, color: TICK, maxTicksLimit: 10 } },
        y:  { grid: { color: GRID }, ticks: { font: { family: FONT, size: 10 }, color: TICK,
              callback: v => 'KSh ' + Number(v).toLocaleString('en-KE') } },
        y1: { position: 'right', grid: { drawOnChartArea: false },
              ticks: { font: { family: FONT, size: 10 }, color: TICK } }
      }
    }
  });
}

// ── Category Chart ─────────────────────
const catCtx = document.getElementById('categoryChart')?.getContext('2d');
if (catCtx) {
  const catColors = ['#2563eb','#059669','#7c3aed','#d97706','#0284c7','#dc2626','#4f46e5'];
  new Chart(catCtx, {
    type: 'doughnut',
    data: {
      labels: <?= json_encode($cat_labels) ?>,
      datasets: [{
        data: <?= json_encode($cat_revenue) ?>,
        backgroundColor: catColors,
        borderColor: '#ffffff',
        borderWidth: 3,
        hoverOffset: 8
      }]
    },
    options: {
      responsive: true,
      plugins: {
        legend: {
          position: 'right',
          labels: { font: { family: FONT, size: 11 }, boxWidth: 12, padding: 14 }
        },
        tooltip: {
          callbacks: {
            label: ctx => ' KSh ' + ctx.parsed.toLocaleString('en-KE', { minimumFractionDigits: 2 })
          }
        }
      }
    }
  });
}

// ── Events Chart ───────────────────────
const eventCtx = document.getElementById('eventChart')?.getContext('2d');
if (eventCtx) {
  new Chart(eventCtx, {
    type: 'bar',
    data: {
      labels: <?= json_encode($event_labels) ?>,
      datasets: [{
        label: 'Revenue (KSh)',
        data: <?= json_encode($event_revenue) ?>,
        backgroundColor: 'rgba(124,58,237,0.8)',
        borderColor: '#7c3aed',
        borderWidth: 1,
        borderRadius: 5,
        borderSkipped: false
      }]
    },
    options: {
      responsive: true,
      indexAxis: 'y',
      plugins: {
        legend: { display: false },
        tooltip: {
          callbacks: {
            label: ctx => ' KSh ' + ctx.parsed.x.toLocaleString('en-KE', { minimumFractionDigits: 2 })
          }
        }
      },
      scales: {
        x: {
          grid: { color: GRID },
          ticks: {
            font: { family: FONT, size: 10 }, color: TICK,
            callback: v => 'KSh ' + Number(v).toLocaleString('en-KE')
          }
        },
        y: { grid: { color: 'transparent' }, ticks: { font: { family: FONT, size: 11 }, color: TICK } }
      }
    }
  });
}
</script>
</body>
</html>