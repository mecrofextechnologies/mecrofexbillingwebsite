<?php
// ticket_purchases.php
// ────────────────────────────────────────────────
// Start output buffering IMMEDIATELY
// ────────────────────────────────────────────────
ob_start();

// DEBUG MODE (turn off in production later)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/ticket_purchases_debug.log');

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$connect_path = file_exists('msanii/includes/connect.php')
    ? 'msanii/includes/connect.php'
    : 'includes/connect.php';

if (!file_exists($connect_path)) {
    die("Database connection file missing.");
}

require_once $connect_path;
require_once '../vendor/autoload.php'; // PHPMailer

if (file_exists('vendor/fpdf.php')) {
    require_once 'vendor/fpdf.php';
}

// Session & login check
if (!isset($_SESSION['creator_id']) || !is_numeric($_SESSION['creator_id'])) {
    if (isset($_POST['action']) && $_POST['action'] === 'toggle_checkin') {
        while (ob_get_level() > 0) ob_end_clean();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'Not logged in']);
        exit;
    }
    header("Location: login.php");
    exit;
}

$creator_id = (int)$_SESSION['creator_id'];

// ────────────────────────────────────────────────
// AJAX: Toggle check-in
// ────────────────────────────────────────────────
if (isset($_POST['action']) && $_POST['action'] === 'toggle_checkin') {
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');

    $purchase_id = (int)($_POST['purchase_id'] ?? 0);
    $new_value   = (int)($_POST['new_value'] ?? 0);

    if ($purchase_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid ID']);
        exit;
    }

    $stmt = $pdo->prepare("
        SELECT is_checked_in
        FROM ticket_purchases
        WHERE id = ? AND ticket_id IN (SELECT id FROM tickets WHERE creator_id = ?)
    ");
    $stmt->execute([$purchase_id, $creator_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        echo json_encode(['success' => false, 'message' => 'Ticket not found']);
        exit;
    }

    $was = (int)$row['is_checked_in'];
    if ($was !== $new_value) {
        $stmt = $pdo->prepare("UPDATE ticket_purchases SET is_checked_in = ?, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$new_value, $purchase_id]);
    }

    echo json_encode(['success' => true]);
    exit;
}

// ────────────────────────────────────────────────
// Fetch events + stats
// ────────────────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT
        t.id AS event_id,
        t.title AS event_title,
        t.start_date,
        COUNT(tp.id) AS total_tickets_sold,
        SUM(CASE WHEN tp.status = 'completed' THEN tp.amount_paid ELSE 0 END) AS revenue_completed,
        COUNT(CASE WHEN tp.status = 'completed' THEN 1 END) AS completed_count,
        COUNT(CASE WHEN tp.status = 'pending' THEN 1 END) AS pending_count,
        COUNT(CASE WHEN tp.is_checked_in = 1 THEN 1 END) AS checked_in_count
    FROM tickets t
    LEFT JOIN ticket_purchases tp ON tp.ticket_id = t.id
    WHERE t.creator_id = ?
    GROUP BY t.id, t.title, t.start_date
    ORDER BY COALESCE(t.start_date, '9999-12-31') DESC, t.title ASC
");
$stmt->execute([$creator_id]);
$events = $stmt->fetchAll(PDO::FETCH_ASSOC);

$total_revenue    = array_sum(array_column($events, 'revenue_completed'));
$total_completed  = array_sum(array_column($events, 'completed_count'));
$total_checked_in = array_sum(array_column($events, 'checked_in_count'));
$total_pending    = array_sum(array_column($events, 'pending_count'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>My Ticket Sales & Check-in | Keychele</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

  <style>
    /* ── Design Tokens ── */
    :root {
      --navy:       #032d5d;
      --crimson:    #b81236;
      --navy-light: #0a3d7a;
      --surface:    #ffffff;
      --bg:         #f0f4fa;
      --border:     #e2e8f0;
      --text-pri:   #0f172a;
      --text-sec:   #475569;
      --text-muted: #94a3b8;

      --green:      #059669;
      --green-bg:   #d1fae5;
      --blue:       #2563eb;
      --blue-bg:    #dbeafe;
      --indigo:     #4f46e5;
      --indigo-bg:  #e0e7ff;
      --purple:     #7c3aed;
      --purple-bg:  #ede9fe;
      --orange:     #d97706;
      --orange-bg:  #fef3c7;

      --radius-sm:  8px;
      --radius-md:  14px;
      --radius-lg:  20px;
      --shadow-sm:  0 1px 4px rgba(3,45,93,.07);
      --shadow-md:  0 4px 20px rgba(3,45,93,.10);
      --shadow-lg:  0 12px 40px rgba(3,45,93,.14);
      --transition: all .22s cubic-bezier(.4,0,.2,1);
    }

    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    body {
      font-family: 'Sora', sans-serif;
      background: var(--bg);
      color: var(--text-pri);
      -webkit-font-smoothing: antialiased;
      min-height: 100vh;
    }

    /* ── Preloader ── */
    #preloader {
      position: fixed; inset: 0; z-index: 9999;
      display: flex; align-items: center; justify-content: center;
      background: rgba(3,45,93,.55);
      backdrop-filter: blur(6px);
      transition: opacity .4s ease;
    }
    .preloader-box {
      background: #fff;
      border-radius: var(--radius-lg);
      padding: 28px 40px;
      display: flex; align-items: center; gap: 14px;
      box-shadow: var(--shadow-lg);
    }
    .spin {
      width: 22px; height: 22px;
      border: 3px solid var(--border);
      border-top-color: var(--crimson);
      border-radius: 50%;
      animation: spin .75s linear infinite;
    }
    @keyframes spin { to { transform: rotate(360deg); } }
    .preloader-box span { font-size: .9rem; font-weight: 600; color: var(--text-sec); letter-spacing: .02em; }

    /* ── Layout ── */
    .page-wrap {
      max-width: 1280px;
      margin: 0 auto;
      padding: 24px 20px 96px;
    }
    @media (min-width: 768px) { .page-wrap { padding: 32px 32px 48px; } }

    /* ── Header Banner ── */
    .hero {
      position: relative;
      background: linear-gradient(130deg, var(--navy) 0%, #0a3d7a 45%, var(--crimson) 100%);
      border-radius: var(--radius-lg);
      padding: 32px 36px;
      margin-bottom: 28px;
      overflow: hidden;
      box-shadow: var(--shadow-lg);
    }
    .hero::before {
      content: '';
      position: absolute; inset: 0;
      background: url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%23ffffff' fill-opacity='0.04'%3E%3Ccircle cx='30' cy='30' r='20'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E") repeat;
      pointer-events: none;
    }
    .hero-inner { position: relative; z-index: 1; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 16px; }
    .hero-text h2 {
      font-size: clamp(1.3rem, 3vw, 1.75rem);
      font-weight: 800;
      color: #fff;
      letter-spacing: -.02em;
      line-height: 1.2;
    }
    .hero-text p { color: rgba(255,255,255,.75); font-size: .9rem; margin-top: 6px; font-weight: 400; }
    .hero-badge {
      background: rgba(255,255,255,.15);
      border: 1px solid rgba(255,255,255,.25);
      color: #fff;
      font-size: .8rem;
      font-weight: 600;
      padding: 6px 16px;
      border-radius: 999px;
      backdrop-filter: blur(8px);
      letter-spacing: .05em;
      text-transform: uppercase;
    }

    /* ── Stat Cards ── */
    .stats-grid {
      display: grid;
      grid-template-columns: repeat(2, 1fr);
      gap: 16px;
      margin-bottom: 28px;
    }
    @media (min-width: 640px)  { .stats-grid { grid-template-columns: repeat(3, 1fr); } }
    @media (min-width: 1024px) { .stats-grid { grid-template-columns: repeat(5, 1fr); } }

    .stat-card {
      background: var(--surface);
      border-radius: var(--radius-md);
      padding: 20px 18px 18px;
      box-shadow: var(--shadow-sm);
      border: 1px solid var(--border);
      display: flex; flex-direction: column;
      position: relative; overflow: hidden;
      transition: var(--transition);
    }
    .stat-card:hover { transform: translateY(-3px); box-shadow: var(--shadow-md); }
    .stat-card::before {
      content: '';
      position: absolute; top: 0; left: 0; right: 0; height: 3px;
      border-radius: var(--radius-md) var(--radius-md) 0 0;
    }
    .stat-card.green::before  { background: var(--green); }
    .stat-card.blue::before   { background: var(--blue); }
    .stat-card.indigo::before { background: var(--indigo); }
    .stat-card.purple::before { background: var(--purple); }
    .stat-card.orange::before { background: var(--orange); }

    .stat-label {
      font-size: .72rem; font-weight: 600;
      text-transform: uppercase; letter-spacing: .07em;
      color: var(--text-muted); margin-bottom: 10px;
    }
    .stat-value {
      font-size: 1.6rem; font-weight: 800;
      letter-spacing: -.03em; color: var(--text-pri);
      line-height: 1;
    }
    .stat-sub { font-size: .72rem; color: var(--text-muted); margin-top: 4px; font-weight: 500; }
    .stat-icon-wrap {
      margin-top: 14px;
      width: 38px; height: 38px;
      border-radius: 10px;
      display: flex; align-items: center; justify-content: center;
      font-size: 1rem;
    }
    .stat-card.green  .stat-icon-wrap { background: var(--green-bg);  color: var(--green);  }
    .stat-card.blue   .stat-icon-wrap { background: var(--blue-bg);   color: var(--blue);   }
    .stat-card.indigo .stat-icon-wrap { background: var(--indigo-bg); color: var(--indigo); }
    .stat-card.purple .stat-icon-wrap { background: var(--purple-bg); color: var(--purple); }
    .stat-card.orange .stat-icon-wrap { background: var(--orange-bg); color: var(--orange); }

    /* ── Main Panel ── */
    .panel {
      background: var(--surface);
      border-radius: var(--radius-lg);
      border: 1px solid var(--border);
      box-shadow: var(--shadow-md);
      overflow: hidden;
    }
    .panel-header {
      display: flex; align-items: center; justify-content: space-between;
      flex-wrap: wrap; gap: 12px;
      padding: 22px 28px;
      border-bottom: 1px solid var(--border);
      background: #fafbfe;
    }
    .panel-title {
      font-size: 1rem; font-weight: 700; color: var(--text-pri);
      display: flex; align-items: center; gap: 10px;
    }
    .panel-title i { color: var(--navy); font-size: .9rem; }
    .event-count {
      background: var(--blue-bg); color: var(--blue);
      font-size: .72rem; font-weight: 700;
      padding: 3px 10px; border-radius: 999px;
    }

    /* ── Table ── */
    .table-scroll { overflow-x: auto; -webkit-overflow-scrolling: touch; }
    table { width: 100%; border-collapse: collapse; min-width: 820px; }
    thead th {
      padding: 13px 18px;
      font-size: .72rem; font-weight: 700;
      text-transform: uppercase; letter-spacing: .07em;
      color: var(--text-muted);
      background: #f8fafc;
      border-bottom: 1px solid var(--border);
      white-space: nowrap;
    }
    thead th:first-child { padding-left: 28px; }
    thead th:last-child  { padding-right: 28px; }

    tbody tr {
      border-bottom: 1px solid #f1f5f9;
      transition: background .15s ease;
    }
    tbody tr:last-child { border-bottom: none; }
    tbody tr:hover { background: #f8faff; }

    tbody td {
      padding: 16px 18px;
      font-size: .875rem;
      color: var(--text-sec);
      vertical-align: middle;
    }
    tbody td:first-child { padding-left: 28px; }
    tbody td:last-child  { padding-right: 28px; }

    .event-name { font-weight: 600; color: var(--text-pri); font-size: .9rem; }
    .event-date { font-size: .8rem; color: var(--text-muted); margin-top: 2px; }

    .pill {
      display: inline-block;
      padding: 3px 11px;
      border-radius: 999px;
      font-size: .72rem; font-weight: 700;
      letter-spacing: .03em;
    }
    .pill-num { background: #f1f5f9; color: var(--text-pri); }
    .pill-done { background: var(--green-bg); color: var(--green); }
    .pill-checkin { background: var(--blue-bg); color: var(--blue); }

    .revenue-cell {
      font-family: 'JetBrains Mono', monospace;
      font-size: .85rem; font-weight: 600;
      color: var(--green);
    }

    .btn-details {
      display: inline-flex; align-items: center; gap: 7px;
      background: linear-gradient(135deg, var(--navy), var(--navy-light));
      color: #fff;
      font-size: .75rem; font-weight: 600;
      padding: 7px 16px;
      border-radius: 8px;
      text-decoration: none;
      letter-spacing: .02em;
      transition: var(--transition);
      white-space: nowrap;
    }
    .btn-details:hover { opacity: .88; transform: translateY(-1px); box-shadow: 0 4px 14px rgba(3,45,93,.3); }
    .btn-details i { font-size: .65rem; }

    /* ── Empty State ── */
    .empty-state {
      text-align: center; padding: 64px 24px;
    }
    .empty-icon {
      width: 64px; height: 64px;
      background: var(--blue-bg);
      border-radius: 18px;
      display: flex; align-items: center; justify-content: center;
      font-size: 1.5rem; color: var(--blue);
      margin: 0 auto 16px;
    }
    .empty-state h4 { font-size: 1rem; font-weight: 700; color: var(--text-pri); margin-bottom: 6px; }
    .empty-state p  { font-size: .875rem; color: var(--text-muted); }

    /* ── Mobile Event Cards ── */
    .mobile-cards { padding: 16px; display: flex; flex-direction: column; gap: 14px; }

    .m-card {
      background: var(--surface);
      border: 1px solid var(--border);
      border-radius: var(--radius-md);
      padding: 18px;
      transition: var(--transition);
      position: relative;
      overflow: hidden;
    }
    .m-card::before {
      content: '';
      position: absolute; top: 0; left: 0; bottom: 0; width: 4px;
      background: linear-gradient(180deg, var(--navy), var(--crimson));
      border-radius: 4px 0 0 4px;
    }
    .m-card:hover { box-shadow: var(--shadow-md); }

    .m-card-head { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 4px; }
    .m-card-title { font-size: .95rem; font-weight: 700; color: var(--text-pri); padding-right: 12px; line-height: 1.3; }
    .m-card-date { font-size: .78rem; color: var(--text-muted); margin-bottom: 14px; display: flex; align-items: center; gap: 5px; }

    .m-stats { display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px; margin-bottom: 14px; }
    .m-stat { background: #f8fafc; border-radius: 8px; padding: 10px 12px; }
    .m-stat-label { font-size: .65rem; font-weight: 600; text-transform: uppercase; letter-spacing: .06em; color: var(--text-muted); margin-bottom: 4px; }
    .m-stat-value { font-size: 1.05rem; font-weight: 700; color: var(--text-pri); }
    .m-stat.revenue .m-stat-value { color: var(--green); font-family: 'JetBrains Mono', monospace; font-size: .92rem; }

    .btn-details-full {
      display: flex; align-items: center; justify-content: center; gap: 8px;
      background: linear-gradient(135deg, var(--navy), var(--navy-light));
      color: #fff;
      font-size: .8rem; font-weight: 600;
      padding: 11px;
      border-radius: 10px;
      text-decoration: none;
      transition: var(--transition);
    }
    .btn-details-full:hover { opacity: .88; }

    /* ── Bottom Nav ── */
    .bottom-nav {
      position: fixed; bottom: 0; left: 0; right: 0;
      background: rgba(255,255,255,.95);
      backdrop-filter: blur(12px);
      border-top: 1px solid var(--border);
      display: flex; justify-content: space-around; align-items: center;
      padding: 10px 0 12px;
      z-index: 50;
    }
    @media (min-width: 768px) { .bottom-nav { display: none; } }

    .nav-item {
      display: flex; flex-direction: column; align-items: center;
      gap: 4px; text-decoration: none;
      color: var(--text-muted);
      font-size: .65rem; font-weight: 600;
      text-transform: uppercase; letter-spacing: .05em;
      transition: color .2s ease;
      padding: 4px 14px;
    }
    .nav-item i { font-size: 1.2rem; }
    .nav-item.active { color: var(--navy); }
    .nav-item:hover  { color: var(--navy); }

    /* ── Helpers ── */
    .text-center { text-align: center; }
    .text-right  { text-align: right; }
    .hidden-md   { display: block; }
    .show-md     { display: none; }
    @media (min-width: 768px) { .hidden-md { display: none; } .show-md { display: block; } }

    /* ── Search Bar ── */
    .search-wrap {
      padding: 16px 28px;
      border-bottom: 1px solid var(--border);
      background: #fafbfe;
      display: flex; align-items: center; gap: 12px;
      flex-wrap: wrap;
    }
    .search-box {
      position: relative; flex: 1; min-width: 220px;
    }
    .search-box i {
      position: absolute; left: 14px; top: 50%; transform: translateY(-50%);
      color: var(--text-muted); font-size: .85rem; pointer-events: none;
    }
    .search-input {
      width: 100%;
      padding: 10px 14px 10px 38px;
      border: 1.5px solid var(--border);
      border-radius: 10px;
      font-family: 'Sora', sans-serif;
      font-size: .85rem;
      color: var(--text-pri);
      background: var(--surface);
      outline: none;
      transition: border-color .2s ease, box-shadow .2s ease;
    }
    .search-input:focus {
      border-color: var(--navy);
      box-shadow: 0 0 0 3px rgba(3,45,93,.08);
    }
    .search-input::placeholder { color: var(--text-muted); }
    .search-meta {
      font-size: .78rem; color: var(--text-muted); white-space: nowrap; font-weight: 500;
    }
    #clearSearch {
      background: none; border: none; cursor: pointer;
      color: var(--text-muted); font-size: .8rem; padding: 0 4px;
      transition: color .2s; display: none;
    }
    #clearSearch:hover { color: var(--crimson); }

    /* ── SN column ── */
    .sn-cell {
      font-family: 'JetBrains Mono', monospace;
      font-size: .75rem; font-weight: 600;
      color: var(--text-muted);
      min-width: 42px;
    }
    .m-sn {
      font-family: 'JetBrains Mono', monospace;
      font-size: .68rem; font-weight: 700;
      color: var(--text-muted);
      background: #f1f5f9;
      border-radius: 5px;
      padding: 2px 7px;
      display: inline-block;
      margin-bottom: 6px;
    }

    /* ── No results ── */
    #noResults {
      display: none; text-align: center; padding: 48px 24px;
    }
    #noResults i { font-size: 2rem; color: var(--text-muted); margin-bottom: 12px; display: block; }
    #noResults p { color: var(--text-muted); font-size: .875rem; }

    /* ── Pagination ── */
    .pagination-wrap {
      display: flex; align-items: center; justify-content: space-between;
      flex-wrap: wrap; gap: 12px;
      padding: 18px 28px;
      border-top: 1px solid var(--border);
      background: #fafbfe;
    }
    .page-info { font-size: .78rem; color: var(--text-muted); font-weight: 500; }
    .page-info strong { color: var(--text-pri); }
    .page-btns { display: flex; align-items: center; gap: 6px; }
    .page-btn {
      display: inline-flex; align-items: center; justify-content: center;
      min-width: 34px; height: 34px; padding: 0 10px;
      border-radius: 8px;
      border: 1.5px solid var(--border);
      background: var(--surface);
      font-family: 'Sora', sans-serif;
      font-size: .78rem; font-weight: 600;
      color: var(--text-sec);
      cursor: pointer; transition: var(--transition);
    }
    .page-btn:hover:not(:disabled) { border-color: var(--navy); color: var(--navy); background: #f0f4fa; }
    .page-btn.active { background: var(--navy); color: #fff; border-color: var(--navy); }
    .page-btn:disabled { opacity: .35; cursor: not-allowed; }
    .per-page-select {
      padding: 6px 10px;
      border: 1.5px solid var(--border);
      border-radius: 8px;
      font-family: 'Sora', sans-serif;
      font-size: .78rem; font-weight: 600;
      color: var(--text-sec);
      background: var(--surface);
      outline: none; cursor: pointer;
    }
    .per-page-select:focus { border-color: var(--navy); }

    /* ── Fade-in animation ── */
    @keyframes fadeUp { from { opacity: 0; transform: translateY(14px); } to { opacity: 1; transform: none; } }
    .stat-card { animation: fadeUp .4s ease both; }
    .stat-card:nth-child(1) { animation-delay: .05s; }
    .stat-card:nth-child(2) { animation-delay: .10s; }
    .stat-card:nth-child(3) { animation-delay: .15s; }
    .stat-card:nth-child(4) { animation-delay: .20s; }
    .stat-card:nth-child(5) { animation-delay: .25s; }
  </style>
</head>
<body>

  <!-- Preloader -->
  <div id="preloader">
    <div class="preloader-box">
      <div class="spin"></div>
      <span>Loading Tickets…</span>
    </div>
  </div>

  <div class="page-wrap">

    <!-- ── Hero Header ── -->
    <div class="hero">
      <div class="hero-inner">
        <div class="hero-text">
          <h2><i class="fas fa-ticket" style="margin-right:10px;opacity:.85"></i>Ticket Sales &amp; Check-in</h2>
          <p>Track buyers, check-ins and revenue across all your events</p>
        </div>
        <span class="hero-badge"><i class="fas fa-chart-line" style="margin-right:6px"></i>Dashboard</span>
      </div>
    </div>

    <!-- ── Stat Cards ── -->
    <div class="stats-grid">

      <div class="stat-card green">
        <div class="stat-label">Total Revenue</div>
        <div class="stat-value" style="font-family:'JetBrains Mono',monospace;font-size:1.3rem">
          KSh <?= number_format($total_revenue) ?>
        </div>
        <div class="stat-sub">Completed payments only</div>
        <div class="stat-icon-wrap"><i class="fas fa-coins"></i></div>
      </div>

      <div class="stat-card blue">
        <div class="stat-label">Tickets Sold</div>
        <div class="stat-value"><?= number_format($total_completed) ?></div>
        <div class="stat-sub">Completed tickets</div>
        <div class="stat-icon-wrap"><i class="fas fa-ticket"></i></div>
      </div>

      <div class="stat-card indigo">
        <div class="stat-label">Checked In</div>
        <div class="stat-value" id="checkedInCount"><?= number_format($total_checked_in) ?></div>
        <div class="stat-sub">Attendees scanned</div>
        <div class="stat-icon-wrap"><i class="fas fa-check-circle"></i></div>
      </div>

      <div class="stat-card purple">
        <div class="stat-label">Completed</div>
        <div class="stat-value"><?= number_format($total_completed) ?></div>
        <div class="stat-sub">Verified purchases</div>
        <div class="stat-icon-wrap"><i class="fas fa-check-double"></i></div>
      </div>

      <div class="stat-card orange">
        <div class="stat-label">Pending</div>
        <div class="stat-value"><?= number_format($total_pending) ?></div>
        <div class="stat-sub">Awaiting payment</div>
        <div class="stat-icon-wrap"><i class="fas fa-clock"></i></div>
      </div>

    </div>

    <!-- ── Events Panel ── -->
    <div class="panel">
      <div class="panel-header">
        <div class="panel-title">
          <i class="fas fa-calendar-alt"></i>
          Your Events &amp; Ticket Summary
        </div>
        <?php if (!empty($events)): ?>
          <span class="event-count" id="eventCountBadge"><?= count($events) ?> Event<?= count($events) !== 1 ? 's' : '' ?></span>
        <?php endif; ?>
      </div>

      <?php if (!empty($events)): ?>
      <!-- Search Bar -->
      <div class="search-wrap">
        <div class="search-box">
          <i class="fas fa-search"></i>
          <input type="text" id="searchInput" class="search-input" placeholder="Search events by name…" autocomplete="off">
        </div>
        <button id="clearSearch" title="Clear search"><i class="fas fa-times"></i> Clear</button>
        <span class="search-meta" id="searchMeta"></span>
        <div style="display:flex;align-items:center;gap:8px;margin-left:auto">
          <label style="font-size:.75rem;color:var(--text-muted);font-weight:600">Per page:</label>
          <select class="per-page-select" id="perPageSelect">
            <option value="5">5</option>
            <option value="10" selected>10</option>
            <option value="25">25</option>
            <option value="50">50</option>
          </select>
        </div>
      </div>
      <?php endif; ?>

      <?php if (empty($events)): ?>
        <div class="empty-state">
          <div class="empty-icon"><i class="fas fa-calendar-xmark"></i></div>
          <h4>No Events Yet</h4>
          <p>Create your first event to start selling tickets and tracking check-ins.</p>
        </div>

      <?php else: ?>

        <!-- Desktop Table -->
        <div class="show-md table-scroll">
          <table>
            <thead>
              <tr>
                <th class="sn-cell">#</th>
                <th>Event</th>
                <th>Date</th>
                <th class="text-center">Total Sold</th>
                <th class="text-center">Completed</th>
                <th class="text-center">Checked In</th>
                <th class="text-right">Revenue</th>
                <th class="text-center">Actions</th>
              </tr>
            </thead>
            <tbody id="eventsTableBody">
              <?php foreach ($events as $i => $e): ?>
                <tr class="event-row"
                    data-name="<?= htmlspecialchars(strtolower($e['event_title'] ?? ''), ENT_QUOTES) ?>"
                    data-index="<?= $i ?>">
                  <td class="sn-cell"><?= $i + 1 ?></td>
                  <td>
                    <div class="event-name"><?= htmlspecialchars($e['event_title'] ?? 'Untitled Event') ?></div>
                    <div class="event-date">
                      <i class="fas fa-hashtag" style="font-size:.65rem;opacity:.5"></i>
                      Event ID <?= (int)$e['event_id'] ?>
                    </div>
                  </td>
                  <td>
                    <?php if ($e['start_date']): ?>
                      <span style="font-weight:600;color:var(--text-pri)"><?= date('d M', strtotime($e['start_date'])) ?></span>
                      <span style="color:var(--text-muted);font-size:.8rem"> <?= date('Y', strtotime($e['start_date'])) ?></span>
                    <?php else: ?>
                      <span style="color:var(--text-muted)">—</span>
                    <?php endif; ?>
                  </td>
                  <td class="text-center"><span class="pill pill-num"><?= number_format($e['total_tickets_sold']) ?></span></td>
                  <td class="text-center"><span class="pill pill-done"><?= number_format($e['completed_count']) ?></span></td>
                  <td class="text-center"><span class="pill pill-checkin"><?= number_format($e['checked_in_count']) ?></span></td>
                  <td class="text-right"><div class="revenue-cell">KSh <?= number_format($e['revenue_completed']) ?></div></td>
                  <td class="text-center">
                    <a href="index.php?page=view_ticket_details&event_id=<?= (int)$e['event_id'] ?>" class="btn-details">
                      View Buyers <i class="fas fa-arrow-right"></i>
                    </a>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          <div id="noResults" style="display:none;text-align:center;padding:48px 24px;">
            <i class="fas fa-search-minus" style="font-size:2rem;color:var(--text-muted);margin-bottom:12px;display:block"></i>
            <p style="color:var(--text-muted);font-size:.875rem">No events match your search. Try a different keyword.</p>
          </div>
        </div>

        <!-- Mobile Cards -->
        <div class="hidden-md mobile-cards" id="mobileCards">
          <?php foreach ($events as $i => $e): ?>
            <div class="m-card event-row"
                 data-name="<?= htmlspecialchars(strtolower($e['event_title'] ?? ''), ENT_QUOTES) ?>"
                 data-index="<?= $i ?>">
              <span class="m-sn"># <?= $i + 1 ?></span>
              <div class="m-card-head">
                <div class="m-card-title"><?= htmlspecialchars($e['event_title'] ?? 'Untitled Event') ?></div>
              </div>
              <div class="m-card-date">
                <i class="fas fa-calendar-day"></i>
                <?= $e['start_date'] ? date('d M Y', strtotime($e['start_date'])) : 'Date TBA' ?>
              </div>
              <div class="m-stats">
                <div class="m-stat"><div class="m-stat-label">Sold</div><div class="m-stat-value"><?= number_format($e['total_tickets_sold']) ?></div></div>
                <div class="m-stat"><div class="m-stat-label">Completed</div><div class="m-stat-value"><?= number_format($e['completed_count']) ?></div></div>
                <div class="m-stat"><div class="m-stat-label">Checked In</div><div class="m-stat-value"><?= number_format($e['checked_in_count']) ?></div></div>
                <div class="m-stat revenue"><div class="m-stat-label">Revenue</div><div class="m-stat-value">KSh <?= number_format($e['revenue_completed']) ?></div></div>
              </div>
              <a href="index.php?page=view_ticket_details&event_id=<?= (int)$e['event_id'] ?>" class="btn-details-full">
                View Buyers &amp; Check-in <i class="fas fa-arrow-right"></i>
              </a>
            </div>
          <?php endforeach; ?>
          <div id="noResultsMobile" style="display:none;text-align:center;padding:40px 24px;">
            <i class="fas fa-search-minus" style="font-size:2rem;color:var(--text-muted);margin-bottom:10px;display:block"></i>
            <p style="color:var(--text-muted);font-size:.875rem">No events match your search.</p>
          </div>
        </div>

        <!-- Pagination Footer -->
        <div class="pagination-wrap" id="paginationWrap">
          <div class="page-info" id="pageInfo"></div>
          <div class="page-btns" id="pageBtns"></div>
        </div>

      <?php endif; ?>
    </div><!-- /panel -->

  </div><!-- /page-wrap -->

  <!-- Mobile Bottom Nav -->
  <nav class="bottom-nav">
    <a href="dashboard.php" class="nav-item">
      <i class="fas fa-home"></i>Home
    </a>
    <a href="index.php?page=ticket_purchases" class="nav-item active">
      <i class="fas fa-ticket"></i>Tickets
    </a>
    <a href="transactions.php" class="nav-item">
      <i class="fas fa-receipt"></i>Trans
    </a>
    <a href="my_wallet.php" class="nav-item">
      <i class="fas fa-wallet"></i>Wallet
    </a>
  </nav>

  <script>
    // ── Preloader ──
    window.addEventListener('load', () => {
      const p = document.getElementById('preloader');
      p.style.opacity = '0';
      setTimeout(() => p.style.display = 'none', 400);
    });

    // ── Search + Pagination Engine ──
    (function () {
      const searchInput   = document.getElementById('searchInput');
      const clearBtn      = document.getElementById('clearSearch');
      const searchMeta    = document.getElementById('searchMeta');
      const perPageSelect = document.getElementById('perPageSelect');
      const pageInfo      = document.getElementById('pageInfo');
      const pageBtns      = document.getElementById('pageBtns');
      const noResults     = document.getElementById('noResults');
      const noResultsMob  = document.getElementById('noResultsMobile');
      const countBadge    = document.getElementById('eventCountBadge');

      // Collect all rows (table rows + mobile cards share .event-row)
      const allRows = Array.from(document.querySelectorAll('.event-row'));
      const total   = allRows.length;

      let filtered  = allRows.slice();   // rows passing search
      let perPage   = parseInt(perPageSelect?.value || 10);
      let curPage   = 1;

      // ── helpers ──
      function setVisible(row, show) {
        row.style.display = show ? '' : 'none';
      }

      function updateSNs(visibleRows) {
        // Re-number displayed rows sequentially within the current page
        visibleRows.forEach((row, i) => {
          const snCell = row.querySelector('.sn-cell, .m-sn');
          if (snCell) {
            const globalN = (curPage - 1) * perPage + i + 1;
            snCell.textContent = snCell.classList.contains('m-sn')
              ? '# ' + globalN
              : globalN;
          }
        });
      }

      function render() {
        const totalPages = Math.max(1, Math.ceil(filtered.length / perPage));
        curPage = Math.min(curPage, totalPages);

        const start = (curPage - 1) * perPage;
        const end   = start + perPage;
        const pageRows = filtered.slice(start, end);
        const pageSet  = new Set(pageRows);

        // Show/hide rows
        allRows.forEach(row => setVisible(row, pageSet.has(row)));

        // Update SN for visible rows
        updateSNs(pageRows);

        // No-results state
        const empty = filtered.length === 0;
        if (noResults)    noResults.style.display    = empty ? 'block' : 'none';
        if (noResultsMob) noResultsMob.style.display = empty ? 'block' : 'none';

        // Badge count
        if (countBadge) {
          const n = filtered.length;
          countBadge.textContent = n + ' Event' + (n !== 1 ? 's' : '');
        }

        // Search meta
        if (searchMeta) {
          const q = searchInput ? searchInput.value.trim() : '';
          searchMeta.textContent = q
            ? filtered.length + ' of ' + total + ' result' + (filtered.length !== 1 ? 's' : '')
            : '';
        }

        // Pagination info
        if (pageInfo) {
          if (filtered.length === 0) {
            pageInfo.innerHTML = 'No results';
          } else {
            const s = start + 1, e = Math.min(end, filtered.length);
            pageInfo.innerHTML =
              'Showing <strong>' + s + '–' + e + '</strong> of <strong>' + filtered.length + '</strong> event' +
              (filtered.length !== 1 ? 's' : '');
          }
        }

        // Pagination buttons
        if (pageBtns) {
          pageBtns.innerHTML = '';
          if (totalPages <= 1) return;

          const mkBtn = (label, page, isActive, disabled) => {
            const b = document.createElement('button');
            b.className = 'page-btn' + (isActive ? ' active' : '');
            b.innerHTML = label;
            b.disabled = disabled;
            if (!disabled && !isActive) b.addEventListener('click', () => { curPage = page; render(); });
            return b;
          };

          pageBtns.appendChild(mkBtn('<i class="fas fa-chevron-left"></i>', curPage - 1, false, curPage === 1));

          // Smart page range
          let pages = [];
          if (totalPages <= 7) {
            pages = Array.from({length: totalPages}, (_, i) => i + 1);
          } else {
            pages = [1];
            if (curPage > 3) pages.push('…');
            for (let p = Math.max(2, curPage - 1); p <= Math.min(totalPages - 1, curPage + 1); p++) pages.push(p);
            if (curPage < totalPages - 2) pages.push('…');
            pages.push(totalPages);
          }

          pages.forEach(p => {
            if (p === '…') {
              const dots = document.createElement('span');
              dots.textContent = '…';
              dots.style.cssText = 'padding:0 4px;color:var(--text-muted);font-size:.8rem;line-height:34px';
              pageBtns.appendChild(dots);
            } else {
              pageBtns.appendChild(mkBtn(p, p, p === curPage, false));
            }
          });

          pageBtns.appendChild(mkBtn('<i class="fas fa-chevron-right"></i>', curPage + 1, false, curPage === totalPages));
        }
      }

      // ── Search ──
      function doSearch() {
        const q = (searchInput ? searchInput.value : '').trim().toLowerCase();
        filtered = q
          ? allRows.filter(r => r.dataset.name && r.dataset.name.includes(q))
          : allRows.slice();
        curPage = 1;
        if (clearBtn) clearBtn.style.display = q ? 'inline-flex' : 'none';
        render();
      }

      if (searchInput) {
        searchInput.addEventListener('input', doSearch);
        searchInput.addEventListener('keydown', e => { if (e.key === 'Escape') { searchInput.value = ''; doSearch(); } });
      }
      if (clearBtn) {
        clearBtn.addEventListener('click', () => { if (searchInput) searchInput.value = ''; doSearch(); searchInput && searchInput.focus(); });
      }
      if (perPageSelect) {
        perPageSelect.addEventListener('change', () => { perPage = parseInt(perPageSelect.value); curPage = 1; render(); });
      }

      // Initial render
      render();
    })();
  </script>
</body>
</html>
<?php ob_end_flush(); ?>