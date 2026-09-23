<?php
// my_withdrawals.php
include 'includes/connect.php';

$creator_id = $_SESSION['creator_id'] ?? null;
if (!$creator_id) {
    header("Location: login.php");
    exit();
}

$full_name = $email = '';
$is_fundraiser = false;
try {
    $stmt = $pdo->prepare("SELECT full_name, email, account_type FROM creators WHERE id = ?");
    $stmt->execute([$creator_id]);
    $creator = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($creator) {
        $creator = kc_decrypt_row($creator, ['full_name', 'email']);
        $full_name     = $creator['full_name'] ?? 'Unknown';
        $email         = $creator['email']     ?? 'Not set';
        $is_fundraiser = ($creator['account_type'] ?? 'creator') === 'fundraiser';
    }
} catch (PDOException $e) {
    $error = "Error fetching profile: " . $e->getMessage();
}

// Fundraiser accounts only ever request withdrawals through
// withdraw_fundraiser.php (per fundraiser, see that file's INSERT into this
// same withdrawal_requests table with campaign_id set) — so their history
// here is scoped to campaign-linked rows, with the fundraiser title joined in.
$withdrawals = [];
try {
    $sql = "
        SELECT wr.id, wr.amount, wr.mpesa_number, wr.status, wr.transaction_code, wr.requested_at, wr.processed_at,
               wr.campaign_id, fc.title AS campaign_title
        FROM withdrawal_requests wr
        LEFT JOIN fundraiser_campaigns fc ON fc.id = wr.campaign_id
        WHERE wr.creator_id = ?" . ($is_fundraiser ? " AND wr.campaign_id IS NOT NULL" : "") . "
        ORDER BY wr.requested_at DESC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$creator_id]);
    $withdrawals = array_map(fn($w) => kc_decrypt_row($w, ['mpesa_number']), $stmt->fetchAll(PDO::FETCH_ASSOC));
} catch (PDOException $e) {
    $error = "Error fetching withdrawal history: " . $e->getMessage();
}

// Fundraisers don't use the pooled my_balance wallet (see sidebar.php — My
// Wallet / Withdraw Funds are hidden for them) — their available funds live
// per-campaign in wallet_donations, so show the combined total across all
// their fundraisers instead.
$current_balance = 0.00;
try {
    if ($is_fundraiser) {
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM wallet_donations WHERE creator_id = ?");
        $stmt->execute([$creator_id]);
        $current_balance = (float)$stmt->fetchColumn();
    } else {
        $stmt = $pdo->prepare("SELECT amount FROM my_balance WHERE creator_id = ? LIMIT 1");
        $stmt->execute([$creator_id]);
        $balance_row     = $stmt->fetch(PDO::FETCH_ASSOC);
        $current_balance = $balance_row ? (float)$balance_row['amount'] : 0.00;
    }
} catch (PDOException $e) {
    $error = "Error fetching balance: " . $e->getMessage();
}

$total       = count($withdrawals);
$pending     = 0; $completed = 0; $failed = 0;
$total_drawn = 0.00;
foreach ($withdrawals as $w) {
    $s = strtolower($w['status']);
    if ($s === 'pending' || $s === 'processing') $pending++;
    elseif ($s === 'completed' || $s === 'successful') { $completed++; $total_drawn += (float)$w['amount']; }
    elseif ($s === 'failed' || $s === 'cancelled') $failed++;
}
// Fundraiser accounts use the purple theme everywhere else in their dashboard
// (see includes/fundraiser_dashboard.php, my_profile.php, my_portfolio.php) —
// this page's own --purple token already IS that exact shade, so fundraiser
// mode just makes it the primary accent instead of navy.
$mw_navy       = $is_fundraiser ? '#7c2fb0' : '#032d5d';
$mw_navy_light = $is_fundraiser ? '#a855f7' : '#0a3d7a';
$mw_hero_bg    = $is_fundraiser
    ? 'linear-gradient(130deg, #7c2fb0 0%, #6b21a8 45%, #4c1d95 100%)'
    : 'linear-gradient(130deg, #032d5d 0%, #0a3d7a 45%, #4f46e5 100%)';
?>
<?php if (empty($kc_in_router)): ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>My Withdrawals — Keychele</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
<?php endif; ?>
  <style>
    /* ── Design Tokens (identical to my_tickets) ── */
    :root {
      --navy:        <?= $mw_navy ?>;
      --navy-light:  <?= $mw_navy_light ?>;
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
      --purple:      #7c3aed;
      --purple-bg:   #ede9fe;
      --teal:        #0d9488;
      --gold:        #f59e0b;
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

    /* ── Preloader ── */
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

    /* ── Topbar ── */
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
    .topbar-brand {
      display: inline-flex; align-items: center; gap: 10px;
      text-decoration: none; color: var(--navy); font-weight: 800;
      font-size: 1rem; letter-spacing: -.02em;
    }
    .topbar-brand-icon {
      width: 34px; height: 34px; border-radius: 10px;
      background: var(--navy); display: flex; align-items: center; justify-content: center;
      font-size: .75rem; color: #fec411;
    }
    .topbar-actions { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }

    /* ── Action Buttons ── */
    .action-btn {
      display: inline-flex; align-items: center; gap: 7px;
      padding: 9px 16px; border-radius: var(--radius-sm);
      font-family: 'Sora', sans-serif; font-size: .78rem; font-weight: 600;
      cursor: pointer; border: none; text-decoration: none;
      transition: var(--transition); white-space: nowrap; letter-spacing: .01em;
    }
    .action-btn i { font-size: .7rem; }
    .btn-navy    { background: var(--navy);    color: #fff; }
    .btn-navy:hover    { background: var(--navy-light); box-shadow: 0 4px 14px rgba(3,45,93,.3); transform: translateY(-1px); }
    .btn-green   { background: var(--green);   color: #fff; }
    .btn-green:hover   { background: #047857; box-shadow: 0 4px 14px rgba(5,150,105,.3); transform: translateY(-1px); }
    .btn-crimson { background: var(--crimson); color: #fff; }
    .btn-crimson:hover { background: #9f0f2e; box-shadow: 0 4px 14px rgba(184,18,54,.3); transform: translateY(-1px); }
    .btn-purple  { background: var(--purple);  color: #fff; }
    .btn-purple:hover  { background: #6d28d9; box-shadow: 0 4px 14px rgba(124,58,237,.3); transform: translateY(-1px); }
    .btn-gold    { background: #fec411; color: var(--navy); }
    .btn-gold:hover    { background: #d9a70a; box-shadow: 0 4px 14px rgba(254,196,17,.4); transform: translateY(-1px); }

    /* ── Page Wrap ── */
    .page-wrap { max-width: 1280px; margin: 0 auto; padding: 24px 20px 96px; }
    @media (min-width: 768px) { .page-wrap { padding: 28px 28px 48px; } }

    /* ── Hero ── */
    .hero {
      position: relative; overflow: hidden;
      background: <?= $mw_hero_bg ?>;
      border-radius: var(--radius-lg); padding: 32px 36px; margin-bottom: 24px;
      box-shadow: var(--shadow-lg);
    }
    .hero::before {
      content: '';
      position: absolute; inset: 0;
      background: url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%23ffffff' fill-opacity='0.04'%3E%3Ccircle cx='30' cy='30' r='20'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E") repeat;
      pointer-events: none;
    }
    .hero-inner {
      position: relative; z-index: 1;
      display: flex; justify-content: space-between; align-items: flex-start;
      flex-wrap: wrap; gap: 16px;
    }
    .hero-eyebrow {
      display: inline-flex; align-items: center; gap: 7px;
      background: rgba(255,255,255,.15); border: 1px solid rgba(255,255,255,.2);
      backdrop-filter: blur(8px); border-radius: 999px;
      padding: 4px 14px; font-size: .72rem; font-weight: 600;
      color: #fff; letter-spacing: .05em; text-transform: uppercase; margin-bottom: 10px;
    }
    .hero-title { font-size: clamp(1.25rem, 3vw, 2rem); font-weight: 800; color: #fff; letter-spacing: -.02em; line-height: 1.2; }
    .hero-sub   { font-size: .83rem; color: rgba(255,255,255,.7); margin-top: 6px; }
    .hero-right { text-align: right; }
    .hero-balance-label { font-size: .75rem; color: rgba(255,255,255,.6); margin-bottom: 4px; }
    .hero-balance-val   { font-family: 'JetBrains Mono', monospace; font-size: 1.7rem; font-weight: 700; color: #fec411; line-height: 1; }
    .hero-balance-sub   { font-size: .72rem; color: rgba(255,255,255,.5); margin-top: 4px; }

    /* ── Stats Grid ── */
    .stats-grid {
      display: grid; grid-template-columns: repeat(2, 1fr);
      gap: 14px; margin-bottom: 24px;
    }
    @media (min-width: 640px) { .stats-grid { grid-template-columns: repeat(4, 1fr); } }

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
    .stat-card.sc-blue::before   { background: var(--blue); }
    .stat-card.sc-green::before  { background: var(--green); }
    .stat-card.sc-amber::before  { background: var(--amber); }
    .stat-card.sc-red::before    { background: var(--red); }
    .stat-label { font-size: .68rem; font-weight: 700; text-transform: uppercase; letter-spacing: .07em; color: var(--text-muted); margin-bottom: 8px; }
    .stat-value { font-size: 1.6rem; font-weight: 800; letter-spacing: -.03em; color: var(--text-pri); line-height: 1; }
    .stat-value.mono { font-family: 'JetBrains Mono', monospace; font-size: 1.25rem; }
    .stat-icon-chip {
      margin-top: 12px; width: 34px; height: 34px; border-radius: 9px;
      display: flex; align-items: center; justify-content: center; font-size: .85rem;
    }
    .stat-card.sc-blue   .stat-icon-chip { background: var(--blue-bg);   color: var(--blue); }
    .stat-card.sc-green  .stat-icon-chip { background: var(--green-bg);  color: var(--green); }
    .stat-card.sc-amber  .stat-icon-chip { background: var(--amber-bg);  color: var(--amber); }
    .stat-card.sc-red    .stat-icon-chip { background: var(--red-bg);    color: var(--red); }

    /* ── Panel ── */
    .panel { background: var(--surface); border-radius: var(--radius-lg); border: 1px solid var(--border); box-shadow: var(--shadow-md); overflow: hidden; }
    .panel-header {
      padding: 20px 28px; border-bottom: 1px solid var(--border);
      background: #fafbfe; display: flex; align-items: center;
      justify-content: space-between; flex-wrap: wrap; gap: 14px;
    }
    .panel-title { font-size: 1rem; font-weight: 700; color: var(--text-pri); display: flex; align-items: center; gap: 10px; }
    .panel-title i { color: var(--navy); }
    .record-badge { background: var(--blue-bg); color: var(--blue); font-size: .72rem; font-weight: 700; padding: 3px 10px; border-radius: 999px; }

    /* ── Toolbar ── */
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
    #clearSearchBtn { background: none; border: none; cursor: pointer; color: var(--text-muted); font-size: .78rem; padding: 0 4px; display: none; transition: color .2s; }
    #clearSearchBtn:hover { color: var(--crimson); }
    .filter-select {
      padding: 9px 12px; border: 1.5px solid var(--border); border-radius: var(--radius-sm);
      font-family: 'Sora', sans-serif; font-size: .78rem; font-weight: 600;
      color: var(--text-sec); background: var(--surface); outline: none; cursor: pointer;
    }
    .filter-select:focus { border-color: var(--navy); }

    /* ── Table ── */
    .table-scroll { overflow-x: auto; -webkit-overflow-scrolling: touch; }
    .table-scroll::-webkit-scrollbar { height: 5px; }
    .table-scroll::-webkit-scrollbar-track { background: #f1f5f9; border-radius: 99px; }
    .table-scroll::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 99px; }

    table { width: 100%; border-collapse: collapse; min-width: 800px; }
    thead th {
      padding: 12px 16px; font-size: .68rem; font-weight: 700;
      text-transform: uppercase; letter-spacing: .07em; color: var(--text-muted);
      background: #f8fafc; border-bottom: 1px solid var(--border); white-space: nowrap;
    }
    thead th:first-child { padding-left: 24px; }
    thead th:last-child  { padding-right: 24px; }
    tbody tr { border-bottom: 1px solid #f1f5f9; transition: background .15s; }
    tbody tr:last-child { border-bottom: none; }
    tbody tr:hover { background: #f8faff; }
    tbody td { padding: 14px 16px; font-size: .83rem; color: var(--text-sec); vertical-align: middle; }
    tbody td:first-child { padding-left: 24px; }
    tbody td:last-child  { padding-right: 24px; }

    /* ── Status Badges ── */
    .badge { display: inline-block; padding: 3px 11px; border-radius: 999px; font-size: .7rem; font-weight: 700; letter-spacing: .03em; white-space: nowrap; }
    .badge-pending    { background: var(--amber-bg);  color: #92400e; }
    .badge-processing { background: var(--blue-bg);   color: #1e40af; }
    .badge-completed  { background: var(--green-bg);  color: #14532d; }
    .badge-successful { background: var(--green-bg);  color: #14532d; }
    .badge-failed     { background: var(--red-bg);    color: #7f1d1d; }
    .badge-cancelled  { background: #f1f5f9;          color: var(--text-muted); }

    /* ── Amount chip ── */
    .amount-chip {
      font-family: 'JetBrains Mono', monospace; font-weight: 700;
      font-size: .82rem; color: var(--navy);
    }

    /* ── Txn code chip ── */
    .txn-chip {
      font-family: 'JetBrains Mono', monospace; font-size: .73rem; font-weight: 600;
      background: var(--indigo-bg); color: var(--indigo);
      padding: 3px 9px; border-radius: 7px; display: inline-block;
    }

    /* ── Row icon actions ── */
    .row-actions { display: flex; align-items: center; justify-content: center; gap: 6px; }
    .icon-btn {
      width: 32px; height: 32px; border-radius: 8px; border: none; cursor: pointer;
      display: flex; align-items: center; justify-content: center; font-size: .72rem;
      transition: var(--transition); text-decoration: none;
    }
    .icon-btn-purple { background: var(--purple-bg); color: var(--purple); }
    .icon-btn-purple:hover { background: #ddd6fe; transform: scale(1.1); }

    /* ── No results ── */
    #noResults { display: none; text-align: center; padding: 48px 24px; }
    #noResults i { font-size: 2rem; color: var(--text-muted); display: block; margin-bottom: 10px; }
    #noResults p { color: var(--text-muted); font-size: .875rem; }

    /* ── Empty state ── */
    .empty-state { text-align: center; padding: 64px 24px; }
    .empty-icon { width: 64px; height: 64px; border-radius: 18px; background: var(--purple-bg); display: flex; align-items: center; justify-content: center; font-size: 1.5rem; color: var(--purple); margin: 0 auto 16px; }

    /* ── Panel footer ── */
    .panel-footer {
      padding: 13px 28px; border-top: 1px solid var(--border);
      background: #fafbfe; font-size: .74rem; color: var(--text-muted);
      display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px;
    }
    .panel-footer strong { color: var(--text-pri); }

    /* ── Mobile Cards ── */
    .mobile-cards { padding: 14px; display: flex; flex-direction: column; gap: 12px; }
    .m-card {
      background: var(--surface); border: 1px solid var(--border);
      border-radius: var(--radius-md); padding: 16px;
      position: relative; overflow: hidden; transition: var(--transition);
    }
    .m-card::before {
      content: ''; position: absolute; top: 0; left: 0; bottom: 0; width: 4px;
      border-radius: 4px 0 0 4px;
    }
    .m-card.pending::before    { background: var(--amber); }
    .m-card.processing::before { background: var(--blue); }
    .m-card.completed::before  { background: var(--green); }
    .m-card.successful::before { background: var(--green); }
    .m-card.failed::before     { background: var(--red); }
    .m-card.cancelled::before  { background: var(--text-muted); }
    .m-card:hover { box-shadow: var(--shadow-md); }
    .m-card-top { display: flex; align-items: flex-start; justify-content: space-between; margin-bottom: 10px; gap: 10px; }
    .m-amount { font-family: 'JetBrains Mono', monospace; font-size: 1.15rem; font-weight: 700; color: var(--navy); }
    .m-date   { font-size: .72rem; color: var(--text-muted); margin-top: 3px; }
    .m-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-bottom: 12px; }
    .m-field { background: #f8fafc; border-radius: 8px; padding: 8px 10px; }
    .m-field-label { font-size: .62rem; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; color: var(--text-muted); margin-bottom: 3px; }
    .m-field-val   { font-size: .8rem; font-weight: 600; color: var(--text-pri); }

    /* ── Bottom Nav ── */
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
    .nav-fab { display: flex; flex-direction: column; align-items: center; gap: 3px; text-decoration: none; font-size: .62rem; font-weight: 600; letter-spacing: .05em; }
    .nav-fab-icon { width: 46px; height: 46px; border-radius: 14px; margin-top: -18px; background: var(--navy); color: #fec411; display: flex; align-items: center; justify-content: center; font-size: 1.1rem; box-shadow: 0 4px 16px rgba(3,45,93,.35); }
    .nav-fab span { color: var(--navy); }

    /* ── Show/hide helpers ── */
    .desk-only { display: none; } @media (min-width: 768px) { .desk-only { display: block; } }
    .mob-only  { display: block; } @media (min-width: 768px) { .mob-only  { display: none; } }

    /* ── Tooltip ── */
    [data-tip] { position: relative; }
    [data-tip]:hover::after {
      content: attr(data-tip); position: absolute; bottom: 125%; left: 50%;
      transform: translateX(-50%); background: var(--text-pri); color: #fff;
      font-size: .68rem; padding: 4px 8px; border-radius: 6px; white-space: nowrap;
      z-index: 20; pointer-events: none;
    }

    /* ── Animations ── */
    @keyframes fadeUp { from { opacity: 0; transform: translateY(12px); } to { opacity: 1; transform: none; } }
    .stat-card { animation: fadeUp .4s ease both; }
    .stat-card:nth-child(1) { animation-delay: .05s; }
    .stat-card:nth-child(2) { animation-delay: .10s; }
    .stat-card:nth-child(3) { animation-delay: .15s; }
    .stat-card:nth-child(4) { animation-delay: .20s; }
    .hero { animation: fadeUp .35s ease both; }
  </style>
<?php if (empty($kc_in_router)): ?>
</head>
<body>

<!-- Preloader -->
<div id="preloader">
  <div class="preloader-box">
    <div class="spin"></div>
    <span>Loading your withdrawals…</span>
  </div>
</div>

<!-- ── Topbar ── -->
<div class="topbar">
  <a href="index.php?page=dashboard" class="topbar-brand">
    <span class="topbar-brand-icon"><i class="fas fa-ticket-alt"></i></span>
    Keychele
  </a>
  <div class="topbar-actions">
    <?php if ($is_fundraiser): ?>
    <a href="index.php" class="action-btn btn-purple">
      <i class="fas fa-money-bill-transfer"></i>
      <span>Withdraw From a Fundraiser</span>
    </a>
    <?php else: ?>
    <a href="request_withdrawals.php" class="action-btn btn-purple">
      <i class="fas fa-money-bill-transfer"></i>
      <span>New Withdrawal</span>
    </a>
    <a href="index.php?page=my_wallet" class="action-btn btn-navy" data-tip="View your wallet">
      <i class="fas fa-wallet"></i>
      <span class="desk-only" style="display:inline">Wallet</span>
    </a>
    <?php endif; ?>
    <a href="index.php?page=dashboard" class="action-btn btn-navy" data-tip="Dashboard">
      <i class="fas fa-home"></i>
      <span class="desk-only" style="display:inline">Dashboard</span>
    </a>
  </div>
</div>
<?php endif; ?>

<div class="page-wrap">

  <!-- ── Hero ── -->
  <div class="hero">
    <div class="hero-inner">
      <div>
        <div class="hero-eyebrow">
          <i class="fas fa-money-bill-transfer" style="color:#fbbf24"></i>
          Finance
        </div>
        <div class="hero-title">My Withdrawals</div>
        <div class="hero-sub"><?= $is_fundraiser ? 'Track payout requests across all your fundraisers' : 'Track all your payout requests in one place' ?></div>
      </div>
      <div class="hero-right desk-only">
        <div class="hero-balance-label"><?= $is_fundraiser ? 'Combined Available (all fundraisers)' : 'Available Balance' ?></div>
        <div class="hero-balance-val">KES <?= number_format($current_balance, 2) ?></div>
        <div class="hero-balance-sub"><?= $is_fundraiser ? 'Withdraw per fundraiser from the dashboard' : htmlspecialchars($full_name) ?></div>
      </div>
    </div>
  </div>

  <!-- ── Stats ── -->
  <div class="stats-grid">
    <div class="stat-card sc-blue">
      <div class="stat-label">Total Requests</div>
      <div class="stat-value"><?= $total ?></div>
      <div class="stat-icon-chip"><i class="fas fa-list-ul"></i></div>
    </div>
    <div class="stat-card sc-green">
      <div class="stat-label">Completed</div>
      <div class="stat-value"><?= $completed ?></div>
      <div class="stat-icon-chip"><i class="fas fa-circle-check"></i></div>
    </div>
    <div class="stat-card sc-amber">
      <div class="stat-label">Pending</div>
      <div class="stat-value"><?= $pending ?></div>
      <div class="stat-icon-chip"><i class="fas fa-clock"></i></div>
    </div>
    <div class="stat-card sc-red">
      <div class="stat-label">Total Withdrawn</div>
      <div class="stat-value mono">KES <?= number_format($total_drawn, 0) ?></div>
      <div class="stat-icon-chip"><i class="fas fa-coins"></i></div>
    </div>
  </div>

  <!-- ── Main Panel ── -->
  <div class="panel">

    <!-- Panel Header -->
    <div class="panel-header">
      <div class="panel-title">
        <i class="fas fa-clock-rotate-left"></i>
        Withdrawal History
      </div>
      <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
        <span class="record-badge" id="recordBadge"><?= $total ?> request<?= $total !== 1 ? 's' : '' ?></span>
        <?php if (!empty($withdrawals)): ?>
        <button id="exportExcel" class="action-btn btn-green" style="padding:7px 14px;font-size:.75rem">
          <i class="fas fa-file-excel"></i> Export Excel
        </button>
        <?php endif; ?>
        <a href="<?= $is_fundraiser ? 'index.php' : 'request_withdrawals.php' ?>" class="action-btn btn-purple" style="padding:7px 14px;font-size:.75rem">
          <i class="fas fa-plus"></i> New Request
        </a>
      </div>
    </div>

    <?php if (empty($withdrawals)): ?>
      <div class="empty-state">
        <div class="empty-icon"><i class="fas fa-money-bill-transfer"></i></div>
        <h4 style="font-size:1rem;font-weight:700;margin-bottom:6px">No Withdrawals Yet</h4>
        <p style="font-size:.875rem;color:var(--text-muted);margin-bottom:20px"><?= $is_fundraiser ? 'Withdraw from a fundraiser on your dashboard to see history here.' : 'Make your first withdrawal request to see history here.' ?></p>
        <a href="<?= $is_fundraiser ? 'index.php' : 'request_withdrawals.php' ?>" class="action-btn btn-purple">
          <i class="fas fa-plus"></i> <?= $is_fundraiser ? 'Go to My Fundraisers' : 'Request Withdrawal' ?>
        </a>
      </div>

    <?php else: ?>

      <!-- Toolbar -->
      <div class="toolbar">
        <div class="search-box">
          <i class="fas fa-search"></i>
          <input type="text" id="searchInput" class="search-input"
                 placeholder="Search by M-Pesa number or transaction code…" autocomplete="off">
        </div>
        <button id="clearSearchBtn" title="Clear"><i class="fas fa-times"></i> Clear</button>
        <select class="filter-select" id="filterStatus">
          <option value="all">All Statuses</option>
          <option value="pending">Pending</option>
          <option value="processing">Processing</option>
          <option value="completed">Completed</option>
          <option value="successful">Successful</option>
          <option value="failed">Failed</option>
          <option value="cancelled">Cancelled</option>
        </select>
      </div>

      <!-- Desktop Table -->
      <div class="desk-only table-scroll" style="display:block">
        <table id="withdrawalsTable">
          <thead>
            <tr>
              <th>#</th>
              <th>Requested</th>
              <?php if ($is_fundraiser): ?><th>Fundraiser</th><?php endif; ?>
              <th>Amount (KES)</th>
              <th>M-Pesa Number</th>
              <th style="text-align:center">Status</th>
              <th>Transaction Code</th>
              <th>Processed</th>
            </tr>
          </thead>
          <tbody id="withdrawalTableBody">
            <?php foreach ($withdrawals as $i => $wd):
              $status    = strtolower($wd['status']);
              $mpesa_lc  = strtolower($wd['mpesa_number'] ?? '');
              $txn_lc    = strtolower($wd['transaction_code'] ?? '');
            ?>
            <tr class="wd-row"
                data-mpesa="<?= htmlspecialchars($mpesa_lc, ENT_QUOTES) ?>"
                data-txn="<?= htmlspecialchars($txn_lc, ENT_QUOTES) ?>"
                data-status="<?= htmlspecialchars($status, ENT_QUOTES) ?>">
              <td style="font-family:'JetBrains Mono',monospace;font-size:.72rem;font-weight:600;color:var(--text-muted)"><?= $i + 1 ?></td>
              <td>
                <div style="font-weight:600;color:var(--text-pri);font-size:.82rem">
                  <?= date('d M Y', strtotime($wd['requested_at'])) ?>
                </div>
                <div style="font-size:.72rem;color:var(--text-muted)">
                  <?= date('g:i A', strtotime($wd['requested_at'])) ?>
                </div>
              </td>
              <?php if ($is_fundraiser): ?>
              <td style="font-size:.8rem;font-weight:600;color:var(--text-pri);max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                <?= htmlspecialchars($wd['campaign_title'] ?? '—') ?>
              </td>
              <?php endif; ?>
              <td>
                <span class="amount-chip">
                  <?= number_format($wd['amount'], 2) ?>
                </span>
              </td>
              <td style="font-family:'JetBrains Mono',monospace;font-size:.8rem;font-weight:600;color:var(--text-sec)">
                <?= htmlspecialchars($wd['mpesa_number']) ?>
              </td>
              <td style="text-align:center">
                <?php
                  $badge_map = [
                    'pending'    => 'badge-pending',
                    'processing' => 'badge-processing',
                    'completed'  => 'badge-completed',
                    'successful' => 'badge-successful',
                    'failed'     => 'badge-failed',
                    'cancelled'  => 'badge-cancelled',
                  ];
                  $bc = $badge_map[$status] ?? 'badge-cancelled';
                ?>
                <span class="badge <?= $bc ?>"><?= ucfirst($status) ?></span>
              </td>
              <td>
                <?php if ($wd['transaction_code']): ?>
                  <span class="txn-chip"><?= htmlspecialchars($wd['transaction_code']) ?></span>
                <?php else: ?>
                  <span style="color:var(--text-muted);font-size:.8rem">—</span>
                <?php endif; ?>
              </td>
              <td>
                <?php if ($wd['processed_at']): ?>
                  <div style="font-size:.8rem;font-weight:600;color:var(--text-pri)"><?= date('d M Y', strtotime($wd['processed_at'])) ?></div>
                  <div style="font-size:.72rem;color:var(--text-muted)"><?= date('g:i A', strtotime($wd['processed_at'])) ?></div>
                <?php else: ?>
                  <span style="color:var(--text-muted);font-size:.8rem">—</span>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <div id="noResults">
          <i class="fas fa-search-minus"></i>
          <p>No withdrawals match your search or filter.</p>
        </div>
      </div>

      <!-- Mobile Cards -->
      <div class="mob-only mobile-cards" id="mobileCards">
        <?php foreach ($withdrawals as $wd):
          $status = strtolower($wd['status']);
          $mpesa_lc = strtolower($wd['mpesa_number'] ?? '');
          $txn_lc   = strtolower($wd['transaction_code'] ?? '');
          $badge_map = [
            'pending'    => 'badge-pending',
            'processing' => 'badge-processing',
            'completed'  => 'badge-completed',
            'successful' => 'badge-successful',
            'failed'     => 'badge-failed',
            'cancelled'  => 'badge-cancelled',
          ];
          $bc = $badge_map[$status] ?? 'badge-cancelled';
        ?>
        <div class="m-card <?= $status ?>"
             data-mpesa="<?= htmlspecialchars($mpesa_lc, ENT_QUOTES) ?>"
             data-txn="<?= htmlspecialchars($txn_lc, ENT_QUOTES) ?>"
             data-status="<?= htmlspecialchars($status, ENT_QUOTES) ?>">
          <div class="m-card-top">
            <div>
              <div class="m-amount">KES <?= number_format($wd['amount'], 2) ?></div>
              <div class="m-date"><?= date('d M Y • g:i A', strtotime($wd['requested_at'])) ?></div>
            </div>
            <span class="badge <?= $bc ?>"><?= ucfirst($status) ?></span>
          </div>
          <div class="m-grid">
            <?php if ($is_fundraiser): ?>
            <div class="m-field" style="grid-column:1/-1">
              <div class="m-field-label">Fundraiser</div>
              <div class="m-field-val"><?= htmlspecialchars($wd['campaign_title'] ?? '—') ?></div>
            </div>
            <?php endif; ?>
            <div class="m-field">
              <div class="m-field-label">M-Pesa No.</div>
              <div class="m-field-val" style="font-family:'JetBrains Mono',monospace;font-size:.75rem"><?= htmlspecialchars($wd['mpesa_number']) ?></div>
            </div>
            <div class="m-field">
              <div class="m-field-label">Txn Code</div>
              <div class="m-field-val" style="font-family:'JetBrains Mono',monospace;font-size:.75rem;color:var(--indigo)">
                <?= $wd['transaction_code'] ? htmlspecialchars($wd['transaction_code']) : '—' ?>
              </div>
            </div>
            <div class="m-field" style="grid-column:1/-1">
              <div class="m-field-label">Processed At</div>
              <div class="m-field-val">
                <?= $wd['processed_at'] ? date('d M Y • g:i A', strtotime($wd['processed_at'])) : 'Not yet processed' ?>
              </div>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
        <div id="noResultsMob" style="display:none;text-align:center;padding:40px 16px;color:var(--text-muted);font-size:.875rem">
          <i class="fas fa-search-minus" style="font-size:1.8rem;display:block;margin-bottom:8px"></i>
          No withdrawals match your search or filter.
        </div>
      </div>

      <!-- Panel Footer -->
      <div class="panel-footer">
        <span id="footerCount">
          <strong><?= $total ?></strong> request<?= $total !== 1 ? 's' : '' ?> &nbsp;·&nbsp;
          <strong><?= $completed ?></strong> completed &nbsp;·&nbsp;
          <strong><?= $pending ?></strong> pending &nbsp;·&nbsp;
          <i class="fas fa-coins" style="font-size:.7rem;margin-right:2px;color:var(--green)"></i>
          <strong>KES <?= number_format($total_drawn, 2) ?></strong> total withdrawn
        </span>
        <span style="font-size:.72rem">Balance: <strong style="color:var(--navy)">KES <?= number_format($current_balance, 2) ?></strong></span>
      </div>

    <?php endif; ?>
  </div><!-- /panel -->
</div><!-- /page-wrap -->

<!-- Mobile Bottom Nav -->
<nav class="bottom-nav">
  <a href="index.php?page=dashboard" class="nav-item">
    <i class="fas fa-home"></i>Home
  </a>
  <a href="index.php?page=ticket_purchases" class="nav-item">
    <i class="fas fa-ticket-alt"></i>Tickets
  </a>
  <a href="?page=create_ticket" class="nav-fab">
    <div class="nav-fab-icon"><i class="fas fa-plus"></i></div>
    <span>Create</span>
  </a>
  <?php if (!$is_fundraiser): ?>
  <a href="index.php?page=my_wallet" class="nav-item active">
    <i class="fas fa-wallet"></i>Wallet
  </a>
  <?php endif; ?>
  <a href="index.php?page=profile" class="nav-item">
    <i class="fas fa-user"></i>Profile
  </a>
</nav>

<script>
// ── Preloader
window.addEventListener('load', function () {
  var p = document.getElementById('preloader');
  if (!p) return;
  p.style.opacity = '0';
  setTimeout(function () { p.style.display = 'none'; }, 400);
});

// ── Search + Filter
(function () {
  var searchInput = document.getElementById('searchInput');
  var clearBtn    = document.getElementById('clearSearchBtn');
  var filterSel   = document.getElementById('filterStatus');
  var noResultsD  = document.getElementById('noResults');
  var noResultsM  = document.getElementById('noResultsMob');
  var recordBadge = document.getElementById('recordBadge');

  var allRows  = Array.from(document.querySelectorAll('tr.wd-row'));
  var allCards = Array.from(document.querySelectorAll('.m-card'));

  function applyFilters() {
    var q  = searchInput ? searchInput.value.trim().toLowerCase() : '';
    var st = filterSel   ? filterSel.value : 'all';
    var visible = 0; var visibleCards = 0;

    allRows.forEach(function (el) {
      var matchQ  = !q || el.dataset.mpesa.includes(q) || el.dataset.txn.includes(q);
      var matchSt = st === 'all' || el.dataset.status === st;
      var show    = matchQ && matchSt;
      el.style.display = show ? '' : 'none';
      if (show) visible++;
    });

    allCards.forEach(function (el) {
      var matchQ  = !q || el.dataset.mpesa.includes(q) || el.dataset.txn.includes(q);
      var matchSt = st === 'all' || el.dataset.status === st;
      var show    = matchQ && matchSt;
      el.style.display = show ? '' : 'none';
      if (show) visibleCards++;
    });

    if (clearBtn)    clearBtn.style.display = q ? 'inline-flex' : 'none';
    if (noResultsD)  noResultsD.style.display  = visible === 0      ? 'block' : 'none';
    if (noResultsM)  noResultsM.style.display  = visibleCards === 0 ? 'block' : 'none';
    if (recordBadge) recordBadge.textContent = visible + ' request' + (visible !== 1 ? 's' : '');
  }

  if (searchInput) searchInput.addEventListener('input', applyFilters);
  if (searchInput) searchInput.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') { searchInput.value = ''; applyFilters(); }
  });
  if (clearBtn) clearBtn.addEventListener('click', function () {
    if (searchInput) searchInput.value = '';
    applyFilters();
    searchInput && searchInput.focus();
  });
  if (filterSel) filterSel.addEventListener('change', applyFilters);
})();

// ── Excel Export
document.getElementById('exportExcel')?.addEventListener('click', function () {
  var table = document.getElementById('withdrawalsTable');
  if (!table) return;
  var wb    = XLSX.utils.table_to_book(table, { sheet: "Withdrawals" });
  var today = new Date().toISOString().slice(0, 10);
  XLSX.writeFile(wb, 'Keychele_Withdrawals_' + today + '.xlsx');
  Swal.fire({
    icon: 'success', title: 'Exported!',
    text: 'Your withdrawal history has been exported to Excel.',
    toast: true, position: 'top-end', timer: 2500, showConfirmButton: false,
    background: '#f0fdf4'
  });
});
</script>
</body>
</html>