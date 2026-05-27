<?php
include 'check_user_page.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$connect_path = file_exists('msanii/includes/connect.php')
    ? 'msanii/includes/connect.php'
    : 'includes/connect.php';
require_once $connect_path;

if (!isset($_SESSION['creator_id'])) {
    header("Location: login.php");
    exit;
}
$creator_id = $_SESSION['creator_id'];

// Wallet Summary
$stmt = $pdo->prepare("SELECT COALESCE(amount, 0) FROM my_balance WHERE creator_id = ?");
$stmt->execute([$creator_id]);
$wallet_balance = number_format((float)$stmt->fetchColumn(), 2);

$stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM withdrawals WHERE creator_id = ? AND status = 'completed'");
$stmt->execute([$creator_id]);
$withdrawn = number_format((float)$stmt->fetchColumn(), 2);

$stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM withdrawals WHERE creator_id = ? AND status = 'pending'");
$stmt->execute([$creator_id]);
$pending = number_format((float)$stmt->fetchColumn(), 2);

$stmt = $pdo->prepare("SELECT COUNT(*) FROM support WHERE creator_id = ?");
$stmt->execute([$creator_id]);
$supporters_count = $stmt->fetchColumn();

// Filters
$status_filter = $_GET['status'] ?? 'all';
$search_query  = trim($_GET['search'] ?? '');

// Build query
$sql = "SELECT supporter_name, phone, amount, mpesa_receipt,
               COALESCE(transaction_date, created_at) AS display_date
        FROM support WHERE creator_id = ?";
$params = [$creator_id];

if ($status_filter === 'success') {
    $sql .= " AND mpesa_receipt IS NOT NULL AND TRIM(mpesa_receipt) != ''";
} elseif ($status_filter === 'failed') {
    $sql .= " AND (mpesa_receipt IS NULL OR TRIM(mpesa_receipt) = '')";
}

if ($search_query !== '') {
    $sql .= " AND (supporter_name LIKE ? OR phone LIKE ? OR mpesa_receipt LIKE ?)";
    $like = "%$search_query%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$sql .= " ORDER BY display_date DESC, id DESC LIMIT 300";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Phone masking helper — e.g. 0715****47
function maskPhone(string $phone): string {
    $phone = trim($phone);
    $len   = strlen($phone);
    if ($len < 6) return str_repeat('*', $len);
    $show_start = 4;
    $show_end   = 2;
    $masked_len = $len - $show_start - $show_end;
    if ($masked_len <= 0) return $phone;
    return substr($phone, 0, $show_start) . str_repeat('*', $masked_len) . substr($phone, -$show_end);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>My Transactions | Keychele</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <style>
    :root {
      --navy:        #032d5d;
      --navy-light:  #0a3d7a;
      --crimson:     #b81236;
      --surface:     #ffffff;
      --bg:          #f0f4fa;
      --border:      #e2e8f0;
      --text-pri:    #0f172a;
      --text-sec:    #475569;
      --text-muted:  #94a3b8;
      --green:       #059669;
      --green-bg:    #d1fae5;
      --blue:        #2563eb;
      --blue-bg:     #dbeafe;
      --indigo:      #4f46e5;
      --indigo-bg:   #e0e7ff;
      --amber:       #d97706;
      --amber-bg:    #fef3c7;
      --red:         #dc2626;
      --red-bg:      #fee2e2;
      --sky:         #0284c7;
      --sky-bg:      #e0f2fe;
      --radius-sm:   8px;
      --radius-md:   14px;
      --radius-lg:   20px;
      --shadow-sm:   0 1px 4px rgba(3,45,93,.07);
      --shadow-md:   0 4px 20px rgba(3,45,93,.10);
      --shadow-lg:   0 12px 40px rgba(3,45,93,.14);
      --transition:  all .22s cubic-bezier(.4,0,.2,1);
    }

    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    body {
      font-family: 'Sora', sans-serif;
      background: var(--bg);
      color: var(--text-pri);
      -webkit-font-smoothing: antialiased;
      min-height: 100vh;
    }

    /* Preloader */
    #preloader {
      position: fixed; inset: 0; z-index: 9999;
      display: flex; align-items: center; justify-content: center;
      background: rgba(3,45,93,.55); backdrop-filter: blur(6px);
      transition: opacity .4s ease;
    }
    .preloader-box {
      background: #fff; border-radius: var(--radius-lg);
      padding: 28px 40px; display: flex; align-items: center; gap: 14px;
      box-shadow: var(--shadow-lg);
    }
    .spin {
      width: 22px; height: 22px; border: 3px solid var(--border);
      border-top-color: var(--crimson); border-radius: 50%;
      animation: spin .75s linear infinite; flex-shrink: 0;
    }
    @keyframes spin { to { transform: rotate(360deg); } }
    .preloader-box span { font-size: .9rem; font-weight: 600; color: var(--text-sec); }

    /* Topbar */
    .topbar {
      background: rgba(255,255,255,.97);
      backdrop-filter: blur(12px);
      border-bottom: 1px solid var(--border);
      padding: 12px 24px;
      display: flex; align-items: center; justify-content: space-between;
      position: sticky; top: 0; z-index: 40;
      box-shadow: var(--shadow-sm);
      gap: 12px; flex-wrap: wrap;
    }
    .back-btn {
      display: inline-flex; align-items: center; gap: 8px;
      color: var(--text-sec); text-decoration: none; font-size: .85rem; font-weight: 600;
      transition: var(--transition);
    }
    .back-btn:hover { color: var(--navy); }
    .back-icon {
      width: 34px; height: 34px; border-radius: 10px;
      background: #f1f5f9; display: flex; align-items: center; justify-content: center;
      font-size: .75rem; transition: var(--transition);
    }
    .back-btn:hover .back-icon { background: #e2e8f0; }
    .topbar-actions { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
    .action-btn {
      display: inline-flex; align-items: center; gap: 7px;
      padding: 9px 16px; border-radius: var(--radius-sm);
      font-family: 'Sora', sans-serif; font-size: .78rem; font-weight: 600;
      cursor: pointer; border: none; text-decoration: none;
      transition: var(--transition); white-space: nowrap; letter-spacing: .01em;
    }
    .action-btn i { font-size: .7rem; }
    .btn-navy  { background: var(--navy);  color: #fff; }
    .btn-navy:hover  { background: var(--navy-light); box-shadow: 0 4px 14px rgba(3,45,93,.3); transform: translateY(-1px); }
    .btn-green { background: var(--green); color: #fff; }
    .btn-green:hover { background: #047857; box-shadow: 0 4px 14px rgba(5,150,105,.3); transform: translateY(-1px); }

    /* Page */
    .page-wrap { max-width: 1280px; margin: 0 auto; padding: 24px 20px 96px; }
    @media (min-width: 768px) { .page-wrap { padding: 28px 28px 48px; } }

    /* Hero */
    .hero {
      position: relative; overflow: hidden;
      background: linear-gradient(130deg, var(--navy) 0%, #0a3d7a 45%, var(--crimson) 100%);
      border-radius: var(--radius-lg); padding: 32px 36px; margin-bottom: 24px;
      box-shadow: var(--shadow-lg);
    }
    .hero::before {
      content: '';
      position: absolute; inset: 0;
      background: url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%23ffffff' fill-opacity='0.04'%3E%3Ccircle cx='30' cy='30' r='20'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E") repeat;
      pointer-events: none;
    }
    .hero-inner { position: relative; z-index: 1; display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 16px; }
    .hero-eyebrow {
      display: inline-flex; align-items: center; gap: 7px;
      background: rgba(255,255,255,.15); border: 1px solid rgba(255,255,255,.2);
      backdrop-filter: blur(8px); border-radius: 999px;
      padding: 4px 14px; font-size: .72rem; font-weight: 600;
      color: #fff; letter-spacing: .05em; text-transform: uppercase; margin-bottom: 10px;
    }
    .hero-title { font-size: clamp(1.25rem, 3vw, 2rem); font-weight: 800; color: #fff; letter-spacing: -.02em; line-height: 1.2; }
    .hero-sub   { font-size: .83rem; color: rgba(255,255,255,.7); margin-top: 6px; }
    .hero-balance { text-align: right; }
    .hero-balance-val { font-family: 'JetBrains Mono', monospace; font-size: 2rem; font-weight: 700; color: #fff; line-height: 1; }
    .hero-balance-label { font-size: .75rem; color: rgba(255,255,255,.6); margin-top: 4px; }

    /* Stats Grid */
    .stats-grid {
      display: grid; grid-template-columns: repeat(2, 1fr);
      gap: 14px; margin-bottom: 24px;
    }
    @media (min-width: 640px)  { .stats-grid { grid-template-columns: repeat(4, 1fr); } }

    .stat-card {
      background: var(--surface); border-radius: var(--radius-md);
      padding: 18px 16px; border: 1px solid var(--border);
      box-shadow: var(--shadow-sm); position: relative; overflow: hidden;
      transition: var(--transition);
    }
    .stat-card:hover { transform: translateY(-3px); box-shadow: var(--shadow-md); }
    .stat-card::before {
      content: ''; position: absolute; top: 0; left: 0; right: 0; height: 3px;
      border-radius: var(--radius-md) var(--radius-md) 0 0;
    }
    .stat-card.sc-green::before  { background: var(--green); }
    .stat-card.sc-sky::before    { background: var(--sky); }
    .stat-card.sc-amber::before  { background: var(--amber); }
    .stat-card.sc-red::before    { background: var(--red); }
    .stat-label { font-size: .68rem; font-weight: 700; text-transform: uppercase; letter-spacing: .07em; color: var(--text-muted); margin-bottom: 8px; }
    .stat-value { font-family: 'JetBrains Mono', monospace; font-size: 1.15rem; font-weight: 800; letter-spacing: -.02em; color: var(--text-pri); line-height: 1; }
    .stat-value.count { font-family: 'Sora', sans-serif; font-size: 1.6rem; }
    .stat-icon-chip {
      margin-top: 12px; width: 34px; height: 34px; border-radius: 9px;
      display: flex; align-items: center; justify-content: center; font-size: .85rem;
    }
    .stat-card.sc-green .stat-icon-chip  { background: var(--green-bg);  color: var(--green); }
    .stat-card.sc-sky   .stat-icon-chip  { background: var(--sky-bg);    color: var(--sky); }
    .stat-card.sc-amber .stat-icon-chip  { background: var(--amber-bg);  color: var(--amber); }
    .stat-card.sc-red   .stat-icon-chip  { background: var(--red-bg);    color: var(--red); }
    @keyframes fadeUp { from { opacity: 0; transform: translateY(12px); } to { opacity: 1; transform: none; } }
    .stat-card { animation: fadeUp .4s ease both; }
    .stat-card:nth-child(1) { animation-delay: .05s; }
    .stat-card:nth-child(2) { animation-delay: .10s; }
    .stat-card:nth-child(3) { animation-delay: .15s; }
    .stat-card:nth-child(4) { animation-delay: .20s; }

    /* Panel */
    .panel { background: var(--surface); border-radius: var(--radius-lg); border: 1px solid var(--border); box-shadow: var(--shadow-md); overflow: hidden; }
    .panel-header {
      padding: 20px 28px; border-bottom: 1px solid var(--border);
      background: #fafbfe; display: flex; align-items: center;
      justify-content: space-between; flex-wrap: wrap; gap: 14px;
    }
    .panel-title { font-size: 1rem; font-weight: 700; color: var(--text-pri); display: flex; align-items: center; gap: 10px; }
    .panel-title i { color: var(--navy); }
    .record-badge { background: var(--blue-bg); color: var(--blue); font-size: .72rem; font-weight: 700; padding: 3px 10px; border-radius: 999px; }

    /* Toolbar */
    .toolbar { padding: 14px 28px; border-bottom: 1px solid var(--border); background: #fafbfe; display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
    .search-box { position: relative; flex: 1; min-width: 220px; }
    .search-box i { position: absolute; left: 13px; top: 50%; transform: translateY(-50%); color: var(--text-muted); font-size: .82rem; pointer-events: none; }
    .search-input {
      width: 100%; padding: 10px 14px 10px 36px;
      border: 1.5px solid var(--border); border-radius: var(--radius-sm);
      font-family: 'Sora', sans-serif; font-size: .83rem; color: var(--text-pri);
      background: var(--surface); outline: none; transition: border-color .2s, box-shadow .2s;
    }
    .search-input:focus { border-color: var(--navy); box-shadow: 0 0 0 3px rgba(3,45,93,.08); }
    .search-input::placeholder { color: var(--text-muted); }

    /* Filter tabs */
    .filter-tabs { display: flex; gap: 6px; flex-wrap: wrap; }
    .filter-tab {
      display: inline-flex; align-items: center; gap: 6px;
      padding: 7px 16px; border-radius: var(--radius-sm);
      font-family: 'Sora', sans-serif; font-size: .76rem; font-weight: 600;
      cursor: pointer; border: 1.5px solid var(--border);
      background: var(--surface); color: var(--text-sec);
      transition: var(--transition); text-decoration: none;
    }
    .filter-tab:hover { border-color: var(--navy); color: var(--navy); background: #f0f4fa; }
    .filter-tab.active-all    { background: var(--navy);  color: #fff; border-color: var(--navy); }
    .filter-tab.active-success{ background: var(--green); color: #fff; border-color: var(--green); }
    .filter-tab.active-failed { background: var(--red);   color: #fff; border-color: var(--red); }

    /* Table */
    .table-scroll { overflow-x: auto; -webkit-overflow-scrolling: touch; max-height: 560px; overflow-y: auto; }
    .table-scroll::-webkit-scrollbar { width: 5px; height: 5px; }
    .table-scroll::-webkit-scrollbar-track { background: #f1f5f9; border-radius: 99px; }
    .table-scroll::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 99px; }

    table { width: 100%; border-collapse: collapse; min-width: 720px; }
    thead th {
      padding: 12px 16px; font-size: .68rem; font-weight: 700;
      text-transform: uppercase; letter-spacing: .07em; color: var(--text-muted);
      background: #f8fafc; border-bottom: 1px solid var(--border); white-space: nowrap;
      position: sticky; top: 0; z-index: 2;
    }
    thead th:first-child { padding-left: 24px; }
    thead th:last-child  { padding-right: 24px; }
    tbody tr { border-bottom: 1px solid #f1f5f9; transition: background .15s; }
    tbody tr:last-child { border-bottom: none; }
    tbody tr:hover { background: #f8faff; }
    tbody td { padding: 14px 16px; font-size: .83rem; color: var(--text-sec); vertical-align: middle; }
    tbody td:first-child { padding-left: 24px; }
    tbody td:last-child  { padding-right: 24px; }

    .date-cell  { font-size: .72rem; color: var(--text-muted); white-space: nowrap; }
    .buyer-cell { font-weight: 600; color: var(--text-pri); }
    .amount-cell{ font-family: 'JetBrains Mono', monospace; font-weight: 700; color: var(--green); font-size: .82rem; text-align: right; }
    .phone-cell { font-family: 'JetBrains Mono', monospace; font-size: .78rem; }
    .receipt-cell { font-family: 'JetBrains Mono', monospace; font-size: .75rem; color: var(--text-sec); }
    .privacy-masked { font-family: 'JetBrains Mono', monospace; font-size: .78rem; color: var(--text-muted); letter-spacing: .02em; }
    .privacy-tip {
      display: inline-flex; align-items: center; gap: 5px;
      font-size: .65rem; color: var(--indigo); font-weight: 600;
      background: var(--indigo-bg); border-radius: 5px;
      padding: 2px 7px; margin-left: 4px; cursor: default; white-space: nowrap;
    }
    .privacy-tip i { font-size: .6rem; }
    .privacy-notice {
      background: linear-gradient(135deg, #ede9fe, #e0e7ff);
      border: 1.5px solid rgba(99,102,241,.25);
      border-radius: var(--radius-md);
      padding: 14px 18px;
      display: flex; align-items: flex-start; gap: 12px;
      margin-bottom: 20px;
      animation: fadeUp .4s ease both;
    }
    .privacy-notice-icon {
      width: 36px; height: 36px; border-radius: 10px;
      background: rgba(99,102,241,.15); border: 1.5px solid rgba(99,102,241,.2);
      display: flex; align-items: center; justify-content: center;
      font-size: .95rem; color: var(--indigo); flex-shrink: 0;
    }
    .privacy-notice h5 { font-size: .82rem; font-weight: 700; color: #3730a3; margin-bottom: 3px; }
    .privacy-notice p  { font-size: .74rem; color: #4338ca; line-height: 1.5; }
    .privacy-notice a  { color: var(--crimson); font-weight: 700; text-decoration: none; }
    .privacy-notice a:hover { text-decoration: underline; }

    .badge { display: inline-block; padding: 3px 11px; border-radius: 999px; font-size: .7rem; font-weight: 700; letter-spacing: .03em; white-space: nowrap; }
    .badge-success { background: var(--green-bg); color: #14532d; }
    .badge-failed  { background: var(--red-bg);   color: #7f1d1d; }

    /* Mobile Cards */
    .mobile-cards { padding: 14px; display: flex; flex-direction: column; gap: 12px; }
    .m-card {
      background: var(--surface); border: 1px solid var(--border);
      border-radius: var(--radius-md); padding: 16px;
      position: relative; overflow: hidden; transition: var(--transition);
    }
    .m-card::before { content: ''; position: absolute; top: 0; left: 0; bottom: 0; width: 4px; border-radius: 4px 0 0 4px; }
    .m-card.success::before { background: var(--green); }
    .m-card.failed::before  { background: var(--red); }
    .m-card:hover { box-shadow: var(--shadow-md); }
    .m-card-top { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 3px; }
    .m-buyer  { font-weight: 700; color: var(--text-pri); font-size: .9rem; }
    .m-amount { font-family: 'JetBrains Mono', monospace; font-weight: 700; color: var(--green); font-size: .85rem; }
    .m-date   { font-size: .72rem; color: var(--text-muted); margin-bottom: 10px; }
    .m-grid   { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-bottom: 10px; }
    .m-field  { background: #f8fafc; border-radius: 8px; padding: 8px 10px; }
    .m-field-label { font-size: .62rem; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; color: var(--text-muted); margin-bottom: 3px; }
    .m-field-val   { font-size: .78rem; font-weight: 600; color: var(--text-pri); font-family: 'JetBrains Mono', monospace; }

    /* Empty */
    .empty-state { text-align: center; padding: 64px 24px; }
    .empty-icon { width: 60px; height: 60px; border-radius: 16px; background: var(--blue-bg); display: flex; align-items: center; justify-content: center; font-size: 1.4rem; color: var(--blue); margin: 0 auto 14px; }

    /* Pagination */
    .pagination-wrap {
      display: flex; align-items: center; justify-content: space-between;
      flex-wrap: wrap; gap: 12px; padding: 16px 28px;
      border-top: 1px solid var(--border); background: #fafbfe;
    }
    .page-info { font-size: .75rem; color: var(--text-muted); font-weight: 500; }
    .page-info strong { color: var(--text-pri); }
    .page-btns { display: flex; align-items: center; gap: 5px; }
    .page-btn {
      display: inline-flex; align-items: center; justify-content: center;
      min-width: 32px; height: 32px; padding: 0 8px;
      border-radius: 8px; border: 1.5px solid var(--border);
      background: var(--surface); font-family: 'Sora', sans-serif;
      font-size: .75rem; font-weight: 600; color: var(--text-sec);
      cursor: pointer; transition: var(--transition);
    }
    .page-btn:hover:not(:disabled) { border-color: var(--navy); color: var(--navy); background: #f0f4fa; }
    .page-btn.active { background: var(--navy); color: #fff; border-color: var(--navy); }
    .page-btn:disabled { opacity: .35; cursor: not-allowed; }
    .per-page-select {
      padding: 8px 10px; border: 1.5px solid var(--border); border-radius: var(--radius-sm);
      font-family: 'Sora', sans-serif; font-size: .78rem; font-weight: 600;
      color: var(--text-sec); background: var(--surface); outline: none; cursor: pointer;
    }
    .per-page-select:focus { border-color: var(--navy); }

    /* Highlight */
    .highlight { background: #fde68a; border-radius: 3px; padding: 0 2px; }

    /* Helpers */
    .desk-only { display: none; } @media (min-width: 768px) { .desk-only { display: block; } }
    .mob-only  { display: block; } @media (min-width: 768px) { .mob-only  { display: none;  } }

    /* Bottom Nav */
    .bottom-nav {
      position: fixed; bottom: 0; left: 0; right: 0;
      background: rgba(255,255,255,.96); backdrop-filter: blur(12px);
      border-top: 1px solid var(--border);
      display: flex; justify-content: space-around; align-items: center;
      padding: 8px 0 12px; z-index: 50;
    }
    @media (min-width: 768px) { .bottom-nav { display: none; } }
    .nav-item { display: flex; flex-direction: column; align-items: center; gap: 3px; text-decoration: none; color: var(--text-muted); font-size: .62rem; font-weight: 600; text-transform: uppercase; letter-spacing: .05em; transition: color .2s; padding: 4px 12px; }
    .nav-item i { font-size: 1.15rem; }
    .nav-item.active { color: var(--navy); }
    .nav-item:hover  { color: var(--navy); }
  </style>
</head>
<body>

<!-- Preloader -->
<div id="preloader">
  <div class="preloader-box">
    <div class="spin"></div>
    <span>Loading transactions…</span>
  </div>
</div>

<!-- Topbar -->
<div class="topbar">
  <a href="dashboard.php" class="back-btn">
    <span class="back-icon"><i class="fas fa-arrow-left"></i></span>
    <span>Back to Dashboard</span>
  </a>
  <div class="topbar-actions">
    <a href="my_wallet.php" class="action-btn btn-navy">
      <i class="fas fa-wallet"></i>
      <span>My Wallet</span>
    </a>
    <a href="export_transactions.php" class="action-btn btn-green">
      <i class="fas fa-file-excel"></i>
      <span class="desk-only" style="display:inline">Export Excel</span>
      <span class="mob-only"  style="display:inline">Export</span>
    </a>
  </div>
</div>

<div class="page-wrap">

  <!-- Privacy Notice -->
  <div class="privacy-notice">
    <div class="privacy-notice-icon"><i class="fas fa-shield-halved"></i></div>
    <div>
      <h5><i class="fas fa-lock" style="margin-right:5px;font-size:.75rem"></i>Privacy Policy — Communication Authority of Kenya</h5>
      <p>In compliance with the <strong>Kenya Data Protection Act</strong> and directives from the <strong>Communication Authority of Kenya (CA)</strong>, supporter phone numbers are partially masked for privacy protection. Full contact details are restricted.<br>
      To access unmasked phone numbers, please <a href="mailto:support@keychele.co.ke">contact Keychele Support</a> with a valid reason.</p>
    </div>
  </div>

  <!-- Hero -->
  <div class="hero" style="margin-bottom:24px">
    <div class="hero-inner">
      <div>
        <div class="hero-eyebrow">
          <i class="fas fa-receipt" style="color:#fbbf24"></i>
          Financial Overview
        </div>
        <div class="hero-title">My Transactions</div>
        <div class="hero-sub">All support payments from your fans</div>
      </div>
      <div class="hero-balance desk-only">
        <div class="hero-balance-val">KSh <?= $wallet_balance ?></div>
        <div class="hero-balance-label">Available Balance</div>
      </div>
    </div>
  </div>

  <!-- Stats Grid -->
  <div class="stats-grid">
    <div class="stat-card sc-green">
      <div class="stat-label">Available Balance</div>
      <div class="stat-value">KSh <?= $wallet_balance ?></div>
      <div class="stat-icon-chip"><i class="fas fa-coins"></i></div>
    </div>
    <div class="stat-card sc-sky">
      <div class="stat-label">Total Withdrawn</div>
      <div class="stat-value">KSh <?= $withdrawn ?></div>
      <div class="stat-icon-chip"><i class="fas fa-hand-holding-dollar"></i></div>
    </div>
    <div class="stat-card sc-amber">
      <div class="stat-label">Pending</div>
      <div class="stat-value">KSh <?= $pending ?></div>
      <div class="stat-icon-chip"><i class="fas fa-hourglass-half"></i></div>
    </div>
    <div class="stat-card sc-red">
      <div class="stat-label">Supporters</div>
      <div class="stat-value count"><?= $supporters_count ?></div>
      <div class="stat-icon-chip"><i class="fas fa-heart"></i></div>
    </div>
  </div>

  <!-- Transactions Panel -->
  <div class="panel">

    <!-- Panel Header -->
    <div class="panel-header">
      <div class="panel-title">
        <i class="fas fa-table-list"></i>
        Transaction History
      </div>
      <span class="record-badge" id="recordBadge">
        <?= count($transactions) ?> record<?= count($transactions) !== 1 ? 's' : '' ?>
      </span>
    </div>

    <!-- Toolbar -->
    <div class="toolbar">
      <div class="search-box">
        <i class="fas fa-search"></i>
        <input type="text" id="searchInput" class="search-input"
               placeholder="Search name, phone, receipt…"
               value="<?= htmlspecialchars($search_query) ?>"
               autocomplete="off">
      </div>

      <div class="filter-tabs">
        <?php
          $tabs = ['all' => ['All', 'fa-list'], 'success' => ['Success', 'fa-circle-check'], 'failed' => ['Failed', 'fa-circle-xmark']];
          foreach ($tabs as $key => [$label, $icon]):
            $active = ($status_filter === $key) ? "active-$key" : '';
            $url = '?status=' . $key . ($search_query ? '&search=' . urlencode($search_query) : '');
        ?>
          <a href="<?= $url ?>" class="filter-tab <?= $active ?>">
            <i class="fas <?= $icon ?>"></i> <?= $label ?>
          </a>
        <?php endforeach; ?>
      </div>

      <div style="display:flex;align-items:center;gap:8px;margin-left:auto">
        <label style="font-size:.72rem;color:var(--text-muted);font-weight:600">Per page:</label>
        <select class="per-page-select" id="perPageSelect">
          <option value="10" selected>10</option>
          <option value="25">25</option>
          <option value="50">50</option>
          <option value="100">100</option>
        </select>
      </div>
    </div>

    <?php if (empty($transactions)): ?>
      <div class="empty-state">
        <div class="empty-icon"><i class="fas fa-receipt"></i></div>
        <h4 style="font-size:1rem;font-weight:700;margin-bottom:6px">No Transactions Found</h4>
        <p style="font-size:.875rem;color:var(--text-muted)">Support payments from your fans will appear here.</p>
      </div>

    <?php else: ?>

      <!-- Desktop Table -->
      <div class="desk-only table-scroll" style="display:block">
        <table id="txnTable">
          <thead>
            <tr>
              <th>#</th>
              <th>Date</th>
              <th>Supporter</th>
              <th>
                Phone
                <span style="font-size:.6rem;font-weight:600;color:var(--indigo);background:var(--indigo-bg);border-radius:4px;padding:1px 6px;margin-left:4px;vertical-align:middle">
                  <i class="fas fa-shield-halved"></i> Masked
                </span>
              </th>
              <th>M-Pesa Receipt</th>
              <th>Status</th>
              <th style="text-align:right">Amount</th>
            </tr>
          </thead>
          <tbody id="txnTableBody">
            <?php foreach ($transactions as $i => $t):
              $hasReceipt   = !empty(trim($t['mpesa_receipt'] ?? ''));
              $statusKey    = $hasReceipt ? 'success' : 'failed';
              $date         = $t['display_date'] ?? null;
              $dateShow     = ($date && $date !== '0000-00-00 00:00:00')
                                ? date('M j, Y', strtotime($date)) . '<br><span style="font-size:.68rem;color:var(--text-muted)">' . date('g:i A', strtotime($date)) . '</span>'
                                : '<span style="color:var(--text-muted)">Pending</span>';
              $maskedPhone  = !empty($t['phone']) ? maskPhone($t['phone']) : '—';
            ?>
            <tr class="search-row"
                data-name="<?= htmlspecialchars(strtolower($t['supporter_name'] ?? ''), ENT_QUOTES) ?>"
                data-phone="<?= htmlspecialchars(strtolower($t['phone'] ?? ''), ENT_QUOTES) ?>"
                data-receipt="<?= htmlspecialchars(strtolower($t['mpesa_receipt'] ?? ''), ENT_QUOTES) ?>">
              <td style="font-family:'JetBrains Mono',monospace;font-size:.72rem;font-weight:600;color:var(--text-muted)"><?= $i + 1 ?></td>
              <td class="date-cell"><?= $dateShow ?></td>
              <td class="buyer-cell searchable-name"><?= htmlspecialchars($t['supporter_name'] ?? 'Anonymous') ?></td>
              <td>
                <span class="privacy-masked"><?= htmlspecialchars($maskedPhone) ?></span>
                <span class="privacy-tip" title="Hidden per CA Kenya regulations"><i class="fas fa-shield-halved"></i> CA Policy</span>
              </td>
              <td class="receipt-cell searchable-receipt">
                <?= $hasReceipt ? htmlspecialchars($t['mpesa_receipt']) : '<span style="color:var(--text-muted)">—</span>' ?>
              </td>
              <td><span class="badge badge-<?= $statusKey ?>"><?= ucfirst($statusKey) ?></span></td>
              <td class="amount-cell">KSh <?= number_format((float)$t['amount'], 2) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <div id="noResultsDesk" style="display:none;text-align:center;padding:48px 24px">
          <i class="fas fa-search-minus" style="font-size:2rem;color:var(--text-muted);display:block;margin-bottom:10px"></i>
          <p style="color:var(--text-muted);font-size:.875rem">No transactions match your search.</p>
        </div>
      </div>

      <!-- Mobile Cards -->
      <div class="mob-only mobile-cards" id="mobileCards" style="display:flex">
        <?php foreach ($transactions as $i => $t):
          $hasReceipt  = !empty(trim($t['mpesa_receipt'] ?? ''));
          $statusKey   = $hasReceipt ? 'success' : 'failed';
          $date        = $t['display_date'] ?? null;
          $dateShow    = ($date && $date !== '0000-00-00 00:00:00')
                          ? date('M j, Y g:i A', strtotime($date))
                          : 'Pending';
          $maskedPhone = !empty($t['phone']) ? maskPhone($t['phone']) : '—';
        ?>
        <div class="m-card <?= $statusKey ?> search-row"
             data-name="<?= htmlspecialchars(strtolower($t['supporter_name'] ?? ''), ENT_QUOTES) ?>"
             data-phone="<?= htmlspecialchars(strtolower($t['phone'] ?? ''), ENT_QUOTES) ?>"
             data-receipt="<?= htmlspecialchars(strtolower($t['mpesa_receipt'] ?? ''), ENT_QUOTES) ?>">
          <div class="m-card-top">
            <div class="m-buyer searchable-name"><?= htmlspecialchars($t['supporter_name'] ?? 'Anonymous') ?></div>
            <div class="m-amount">KSh <?= number_format((float)$t['amount'], 2) ?></div>
          </div>
          <div class="m-date"><?= $dateShow ?></div>
          <div class="m-grid">
            <div class="m-field">
              <div class="m-field-label">Phone</div>
              <div class="m-field-val">
                <span class="privacy-masked"><?= htmlspecialchars($maskedPhone) ?></span>
                <span class="privacy-tip" title="Hidden per CA Kenya regulations"><i class="fas fa-shield-halved"></i> CA</span>
              </div>
            </div>
            <div class="m-field">
              <div class="m-field-label">Status</div>
              <div class="m-field-val" style="font-family:'Sora',sans-serif">
                <span class="badge badge-<?= $statusKey ?>"><?= ucfirst($statusKey) ?></span>
              </div>
            </div>
            <div class="m-field" style="grid-column:1/-1">
              <div class="m-field-label">M-Pesa Receipt</div>
              <div class="m-field-val searchable-receipt">
                <?= $hasReceipt ? htmlspecialchars($t['mpesa_receipt']) : '—' ?>
              </div>
            </div>
          </div>
        </div>
        <?php endforeach; ?>

        <div id="noResultsMob" style="display:none;text-align:center;padding:40px 24px">
          <i class="fas fa-search-minus" style="font-size:2rem;color:var(--text-muted);display:block;margin-bottom:10px"></i>
          <p style="color:var(--text-muted);font-size:.875rem">No transactions match your search.</p>
        </div>
      </div>

      <!-- Pagination -->
      <div class="pagination-wrap" id="paginationWrap">
        <div class="page-info" id="pageInfo"></div>
        <div class="page-btns" id="pageBtns"></div>
      </div>

    <?php endif; ?>
  </div><!-- /panel -->
</div><!-- /page-wrap -->

<!-- Mobile Bottom Nav -->
<nav class="bottom-nav">
  <a href="dashboard.php" class="nav-item"><i class="fas fa-home"></i>Home</a>
  <a href="transactions.php" class="nav-item active"><i class="fas fa-receipt"></i>Trans</a>
  <a href="my_wallet.php" class="nav-item"><i class="fas fa-wallet"></i>Wallet</a>
  <a href="profile.php" class="nav-item"><i class="fas fa-user"></i>Profile</a>
</nav>

<script>
window.addEventListener('load', () => {
  const p = document.getElementById('preloader');
  p.style.opacity = '0';
  setTimeout(() => p.style.display = 'none', 400);
});

(function () {
  const searchInput = document.getElementById('searchInput');
  const perPageSel  = document.getElementById('perPageSelect');
  const pageInfo    = document.getElementById('pageInfo');
  const pageBtns    = document.getElementById('pageBtns');
  const noResultsD  = document.getElementById('noResultsDesk');
  const noResultsM  = document.getElementById('noResultsMob');
  const recordBadge = document.getElementById('recordBadge');

  const allRows = Array.from(document.querySelectorAll('.search-row'));
  const total   = allRows.length;
  let filtered  = allRows.slice();
  let perPage   = 10;
  let curPage   = 1;

  function render() {
    const totalPages = Math.max(1, Math.ceil(filtered.length / perPage));
    curPage = Math.min(curPage, totalPages);
    const start   = (curPage - 1) * perPage;
    const end     = start + perPage;
    const visible = new Set(filtered.slice(start, end));

    allRows.forEach(r => r.style.display = visible.has(r) ? '' : 'none');

    // Re-number desktop SN cells
    let n = start + 1;
    filtered.slice(start, end).forEach(row => {
      const td = row.querySelector('td:first-child');
      if (td && !td.querySelector('.badge')) td.textContent = n;
      n++;
    });

    const empty = filtered.length === 0;
    if (noResultsD) noResultsD.style.display = empty ? 'block' : 'none';
    if (noResultsM) noResultsM.style.display = empty ? 'block' : 'none';
    if (recordBadge) {
      const c = filtered.length;
      recordBadge.textContent = c + ' record' + (c !== 1 ? 's' : '');
    }
    if (pageInfo) {
      if (!filtered.length) { pageInfo.innerHTML = 'No results'; return; }
      const s = start + 1, e = Math.min(end, filtered.length);
      pageInfo.innerHTML = 'Showing <strong>' + s + '–' + e + '</strong> of <strong>' + filtered.length + '</strong>';
    }
    if (pageBtns) {
      pageBtns.innerHTML = '';
      if (totalPages <= 1) return;
      const mk = (lbl, pg, active, dis) => {
        const b = document.createElement('button');
        b.className = 'page-btn' + (active ? ' active' : '');
        b.innerHTML = lbl; b.disabled = dis;
        if (!dis && !active) b.onclick = () => { curPage = pg; render(); };
        return b;
      };
      pageBtns.appendChild(mk('<i class="fas fa-chevron-left"></i>', curPage - 1, false, curPage === 1));
      let pages = [];
      if (totalPages <= 7) pages = Array.from({length: totalPages}, (_, i) => i + 1);
      else {
        pages = [1];
        if (curPage > 3) pages.push('…');
        for (let p = Math.max(2, curPage - 1); p <= Math.min(totalPages - 1, curPage + 1); p++) pages.push(p);
        if (curPage < totalPages - 2) pages.push('…');
        pages.push(totalPages);
      }
      pages.forEach(p => {
        if (p === '…') {
          const d = document.createElement('span');
          d.textContent = '…'; d.style.cssText = 'padding:0 4px;color:var(--text-muted);font-size:.8rem;line-height:32px';
          pageBtns.appendChild(d);
        } else pageBtns.appendChild(mk(p, p, p === curPage, false));
      });
      pageBtns.appendChild(mk('<i class="fas fa-chevron-right"></i>', curPage + 1, false, curPage === totalPages));
    }
  }

  function doSearch() {
    const q = (searchInput?.value || '').trim().toLowerCase();
    filtered = q
      ? allRows.filter(r => [r.dataset.name, r.dataset.phone, r.dataset.receipt].some(v => v && v.includes(q)))
      : allRows.slice();
    curPage = 1;

    document.querySelectorAll('.highlight').forEach(h => { h.outerHTML = h.innerHTML; });

    if (q) {
      filtered.forEach(row => {
        ['.searchable-name', '.searchable-phone', '.searchable-receipt'].forEach(sel => {
          row.querySelectorAll(sel).forEach(cell => {
            if (cell.textContent.toLowerCase().includes(q)) {
              const re = new RegExp(`(${q.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')})`, 'gi');
              cell.innerHTML = cell.innerHTML.replace(re, '<span class="highlight">$1</span>');
            }
          });
        });
      });
    }
    render();
  }

  if (searchInput) {
    let timeout;
    searchInput.addEventListener('input', function () {
      clearTimeout(timeout);
      timeout = setTimeout(doSearch, 300);
    });
    searchInput.addEventListener('keydown', e => { if (e.key === 'Escape') { searchInput.value = ''; doSearch(); } });
  }
  if (perPageSel) perPageSel.addEventListener('change', () => { perPage = parseInt(perPageSel.value); curPage = 1; render(); });

  render();
})();
</script>
</body>
</html>