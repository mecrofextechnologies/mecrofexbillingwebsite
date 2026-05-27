<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
include 'includes/connect.php';

$creator_id = $_SESSION['creator_id'] ?? null;
if (!$creator_id) {
    header("Location: login.php");
    exit();
}

$message = '';
$message_type = 'success';

if (isset($_GET['msg']) && isset($_GET['type'])) {
    $message      = urldecode($_GET['msg']);
    $message_type = $_GET['type'] === 'success' ? 'success' : 'error';
}

date_default_timezone_set('Africa/Nairobi');
$now = new DateTime('now', new DateTimeZone('Africa/Nairobi'));

$stmt = $pdo->prepare("
    SELECT * FROM tickets 
    WHERE creator_id = ? 
    ORDER BY start_date DESC, start_time DESC
");
$stmt->execute([$creator_id]);
$tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);

$total = count($tickets);
$upcoming = 0; $past = 0;
foreach ($tickets as $t) {
    try {
        $edt = new DateTime($t['start_date'].' '.$t['start_time'], new DateTimeZone('Africa/Nairobi'));
        if ($edt < $now) $past++; else $upcoming++;
    } catch(Exception $e) { $upcoming++; }
}

// ── Fetch view counts per ticket from total_ticketpageviews ──────────────────
$ticket_ids      = array_column($tickets, 'id');
$views_by_ticket = [];
$total_views     = 0;

if (!empty($ticket_ids)) {
    $placeholders = implode(',', array_fill(0, count($ticket_ids), '?'));
    $vstmt = $pdo->prepare("
        SELECT ticket_id, total_views
        FROM total_ticketpageviews
        WHERE ticket_id IN ($placeholders)
          AND creator_id = ?
    ");
    $vstmt->execute([...$ticket_ids, $creator_id]);
    foreach ($vstmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $views_by_ticket[$row['ticket_id']] = (int)$row['total_views'];
        $total_views += (int)$row['total_views'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>My Tickets — Keychele</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <style>
    /* ── Design Tokens ── */
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
    .btn-gold    { background: #fec411; color: var(--navy); }
    .btn-gold:hover    { background: #d9a70a; box-shadow: 0 4px 14px rgba(254,196,17,.4); transform: translateY(-1px); }

    /* ── Page Wrap ── */
    .page-wrap { max-width: 1280px; margin: 0 auto; padding: 24px 20px 96px; }
    @media (min-width: 768px) { .page-wrap { padding: 28px 28px 48px; } }

    /* ── Alert banner ── */
    .alert-banner {
      border-radius: var(--radius-md); padding: 14px 20px;
      display: flex; align-items: center; gap: 12px;
      margin-bottom: 20px; font-size: .85rem; font-weight: 600;
      animation: fadeUp .35s ease both;
    }
    .alert-banner.success { background: var(--green-bg);  color: #065f46; border: 1px solid #a7f3d0; }
    .alert-banner.error   { background: var(--red-bg);    color: #7f1d1d; border: 1px solid #fecaca; }

    /* ── Hero ── */
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
    .hero-count-val   { font-family: 'JetBrains Mono', monospace; font-size: 2rem; font-weight: 700; color: #fff; line-height: 1; }
    .hero-count-label { font-size: .75rem; color: rgba(255,255,255,.6); margin-top: 4px; }

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
    .stat-card.sc-purple::before { background: var(--purple); }
    .stat-label { font-size: .68rem; font-weight: 700; text-transform: uppercase; letter-spacing: .07em; color: var(--text-muted); margin-bottom: 8px; }
    .stat-value { font-size: 1.6rem; font-weight: 800; letter-spacing: -.03em; color: var(--text-pri); line-height: 1; }
    .stat-icon-chip {
      margin-top: 12px; width: 34px; height: 34px; border-radius: 9px;
      display: flex; align-items: center; justify-content: center; font-size: .85rem;
    }
    .stat-card.sc-blue   .stat-icon-chip { background: var(--blue-bg);   color: var(--blue); }
    .stat-card.sc-green  .stat-icon-chip { background: var(--green-bg);  color: var(--green); }
    .stat-card.sc-amber  .stat-icon-chip { background: var(--amber-bg);  color: var(--amber); }
    .stat-card.sc-purple .stat-icon-chip { background: var(--purple-bg); color: var(--purple); }

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

    /* ── Toolbar / Search ── */
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

    table { width: 100%; border-collapse: collapse; min-width: 860px; }
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

    /* ── Event thumb ── */
    .event-thumb {
      width: 46px; height: 46px; border-radius: 10px;
      object-fit: cover; border: 1px solid var(--border); flex-shrink: 0;
    }
    .thumb-placeholder {
      width: 46px; height: 46px; border-radius: 10px;
      background: var(--blue-bg); display: flex; align-items: center;
      justify-content: center; color: var(--blue); flex-shrink: 0; font-size: .9rem;
    }
    .event-title-cell { font-weight: 700; color: var(--text-pri); font-size: .87rem; }
    .event-sub-cell   { font-size: .73rem; color: var(--text-muted); margin-top: 2px; }

    /* ── Date cell ── */
    .date-val  { font-weight: 600; color: var(--text-pri); font-size: .82rem; }
    .date-time { font-size: .72rem; color: var(--text-muted); margin-top: 2px; }

    /* ── Status Badges ── */
    .badge { display: inline-block; padding: 3px 11px; border-radius: 999px; font-size: .7rem; font-weight: 700; letter-spacing: .03em; white-space: nowrap; }
    .badge-upcoming { background: var(--green-bg); color: #14532d; }
    .badge-past     { background: #f1f5f9;         color: var(--text-muted); }

    /* ── Views chip ── */
    .views-chip {
      display: inline-flex; align-items: center; gap: 5px;
      font-family: 'JetBrains Mono', monospace; font-weight: 700;
      font-size: .75rem; color: var(--purple);
      background: var(--purple-bg); border-radius: 7px;
      padding: 4px 9px;
    }
    .views-chip i { font-size: .65rem; }
    .views-chip.zero { color: var(--text-muted); background: #f1f5f9; }

    /* ── Tickets count chip ── */
    .count-chip {
      font-family: 'JetBrains Mono', monospace; font-weight: 700;
      font-size: .78rem; color: var(--navy);
      background: var(--blue-bg); border-radius: 7px;
      padding: 3px 9px; display: inline-block;
    }

    /* ── Row icon actions ── */
    .row-actions { display: flex; align-items: center; justify-content: center; gap: 6px; }
    .icon-btn {
      width: 32px; height: 32px; border-radius: 8px; border: none; cursor: pointer;
      display: flex; align-items: center; justify-content: center; font-size: .72rem;
      transition: var(--transition); text-decoration: none;
    }
    .icon-btn-blue   { background: var(--blue-bg);   color: var(--blue); }
    .icon-btn-blue:hover   { background: #bfdbfe; transform: scale(1.1); }
    .icon-btn-green  { background: var(--green-bg);  color: var(--green); }
    .icon-btn-green:hover  { background: #a7f3d0; transform: scale(1.1); }
    .icon-btn-gold   { background: #fef3c7;           color: var(--amber); }
    .icon-btn-gold:hover   { background: #fde68a; transform: scale(1.1); }
    .icon-btn-red    { background: var(--red-bg);    color: var(--red); }
    .icon-btn-red:hover    { background: #fecaca; transform: scale(1.1); }
    .icon-btn.copied { background: var(--indigo-bg); color: var(--indigo); }

    /* ── No results ── */
    #noResults { display: none; text-align: center; padding: 48px 24px; }
    #noResults i { font-size: 2rem; color: var(--text-muted); display: block; margin-bottom: 10px; }
    #noResults p { color: var(--text-muted); font-size: .875rem; }

    /* ── Empty state ── */
    .empty-state { text-align: center; padding: 64px 24px; }
    .empty-icon { width: 64px; height: 64px; border-radius: 18px; background: var(--blue-bg); display: flex; align-items: center; justify-content: center; font-size: 1.5rem; color: var(--blue); margin: 0 auto 16px; }

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
    .m-card.upcoming::before { background: var(--green); }
    .m-card.past::before     { background: var(--text-muted); }
    .m-card:hover { box-shadow: var(--shadow-md); }
    .m-card-top { display: flex; align-items: flex-start; gap: 12px; margin-bottom: 10px; }
    .m-card-info { flex: 1; min-width: 0; }
    .m-title { font-weight: 700; color: var(--text-pri); font-size: .9rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .m-venue { font-size: .73rem; color: var(--text-muted); margin-top: 2px; }
    .m-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-bottom: 12px; }
    .m-field { background: #f8fafc; border-radius: 8px; padding: 8px 10px; }
    .m-field-label { font-size: .62rem; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; color: var(--text-muted); margin-bottom: 3px; }
    .m-field-val   { font-size: .8rem; font-weight: 600; color: var(--text-pri); }
    .m-actions { display: flex; gap: 8px; }
    .m-btn { flex: 1; display: flex; align-items: center; justify-content: center; gap: 6px; padding: 9px; border-radius: 9px; font-family: 'Sora', sans-serif; font-size: .75rem; font-weight: 600; border: none; cursor: pointer; transition: var(--transition); text-decoration: none; }
    .m-btn-navy   { background: var(--navy);    color: #fff; }
    .m-btn-navy:hover   { background: var(--navy-light); }
    .m-btn-green  { background: var(--green-bg); color: var(--green); }
    .m-btn-green:hover  { background: #a7f3d0; }
    .m-btn-gold   { background: #fef3c7; color: var(--amber); }
    .m-btn-gold:hover   { background: #fde68a; }
    .m-btn-red    { background: var(--red-bg);  color: var(--red); }
    .m-btn-red:hover    { background: #fecaca; }

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
    .nav-fab {
      display: flex; flex-direction: column; align-items: center; gap: 3px;
      text-decoration: none; font-size: .62rem; font-weight: 600; letter-spacing: .05em;
    }
    .nav-fab-icon {
      width: 46px; height: 46px; border-radius: 14px; margin-top: -18px;
      background: var(--navy); color: #fec411;
      display: flex; align-items: center; justify-content: center;
      font-size: 1.1rem; box-shadow: 0 4px 16px rgba(3,45,93,.35);
    }
    .nav-fab span { color: var(--navy); }

    /* ── Show/hide helpers ── */
    .desk-only { display: none; } @media (min-width: 768px) { .desk-only { display: block; } }
    .mob-only  { display: block; } @media (min-width: 768px) { .mob-only  { display: none;  } }

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
</head>
<body>

<!-- Preloader -->
<div id="preloader">
  <div class="preloader-box">
    <div class="spin"></div>
    <span>Loading your events…</span>
  </div>
</div>

<!-- ── Topbar ── -->
<div class="topbar">
  <a href="index.php?page=dashboard" class="topbar-brand">
    <span class="topbar-brand-icon"><i class="fas fa-ticket-alt"></i></span>
    Keychele
  </a>

  <div class="topbar-actions">
    <a href="?page=create_ticket" class="action-btn btn-crimson">
      <i class="fas fa-plus"></i>
      <span>Create Event</span>
    </a>
    <a href="index.php?page=my_wallet" class="action-btn btn-navy" data-tip="View your wallet">
      <i class="fas fa-wallet"></i>
      <span class="desk-only" style="display:inline">Wallet</span>
    </a>
    <a href="index.php?page=dashboard" class="action-btn btn-navy" data-tip="Dashboard">
      <i class="fas fa-home"></i>
      <span class="desk-only" style="display:inline">Dashboard</span>
    </a>
  </div>
</div>

<div class="page-wrap">

  <?php if ($message): ?>
  <div class="alert-banner <?= $message_type ?>">
    <i class="fas <?= $message_type === 'success' ? 'fa-circle-check' : 'fa-circle-xmark' ?>"></i>
    <?= htmlspecialchars($message) ?>
  </div>
  <?php endif; ?>

  <!-- ── Hero ── -->
  <div class="hero">
    <div class="hero-inner">
      <div>
        <div class="hero-eyebrow">
          <i class="fas fa-ticket-alt" style="color:#fbbf24"></i>
          Event Manager
        </div>
        <div class="hero-title">My Tickets</div>
        <div class="hero-sub">Manage, share and track all your events in one place</div>
      </div>
      <div class="hero-right desk-only">
        <div class="hero-count-val"><?= $total ?></div>
        <div class="hero-count-label">Total Events</div>
      </div>
    </div>
  </div>

  <!-- ── Stats ── -->
  <div class="stats-grid">
    <div class="stat-card sc-blue">
      <div class="stat-label">Total Events</div>
      <div class="stat-value"><?= $total ?></div>
      <div class="stat-icon-chip"><i class="fas fa-calendar-alt"></i></div>
    </div>
    <div class="stat-card sc-green">
      <div class="stat-label">Upcoming</div>
      <div class="stat-value"><?= $upcoming ?></div>
      <div class="stat-icon-chip"><i class="fas fa-calendar-check"></i></div>
    </div>
    <div class="stat-card sc-amber">
      <div class="stat-label">Past</div>
      <div class="stat-value"><?= $past ?></div>
      <div class="stat-icon-chip"><i class="fas fa-calendar-xmark"></i></div>
    </div>
    <div class="stat-card sc-purple">
      <div class="stat-label">Total Views</div>
      <div class="stat-value"><?= number_format($total_views) ?></div>
      <div class="stat-icon-chip"><i class="fas fa-eye"></i></div>
    </div>
  </div>

  <!-- ── Main Panel ── -->
  <div class="panel">

    <!-- Panel Header -->
    <div class="panel-header">
      <div class="panel-title">
        <i class="fas fa-calendar-star"></i>
        All Events
      </div>
      <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
        <span class="record-badge" id="recordBadge"><?= $total ?> event<?= $total !== 1 ? 's' : '' ?></span>
        <a href="?page=create_ticket" class="action-btn btn-crimson" style="padding:7px 14px;font-size:.75rem">
          <i class="fas fa-plus"></i> New Event
        </a>
      </div>
    </div>

    <?php if (empty($tickets)): ?>
      <div class="empty-state">
        <div class="empty-icon"><i class="fas fa-ticket-alt"></i></div>
        <h4 style="font-size:1rem;font-weight:700;margin-bottom:6px">No Events Yet</h4>
        <p style="font-size:.875rem;color:var(--text-muted);margin-bottom:20px">Create your first event and start selling tickets!</p>
        <a href="?page=create_ticket" class="action-btn btn-crimson">
          <i class="fas fa-plus"></i> Create First Event
        </a>
      </div>

    <?php else: ?>

      <!-- Toolbar -->
      <div class="toolbar">
        <div class="search-box">
          <i class="fas fa-search"></i>
          <input type="text" id="searchInput" class="search-input"
                 placeholder="Search events by name or venue…" autocomplete="off">
        </div>
        <button id="clearSearchBtn" title="Clear"><i class="fas fa-times"></i> Clear</button>
        <select class="filter-select" id="filterStatus">
          <option value="all">All Events</option>
          <option value="upcoming">Upcoming</option>
          <option value="past">Past</option>
        </select>
      </div>

      <!-- Desktop Table -->
      <div class="desk-only table-scroll" style="display:block">
        <table>
          <thead>
            <tr>
              <th>#</th>
              <th>Event</th>
              <th>Date &amp; Time</th>
              <th>Venue</th>
              <th style="text-align:center">Views</th>
              <th style="text-align:center">Status</th>
              <th style="text-align:center">Actions</th>
            </tr>
          </thead>
          <tbody id="ticketsTableBody">
            <?php foreach ($tickets as $i => $ticket):
              $event_dt = null;
              $is_past  = false;
              try {
                $event_dt = new DateTime($ticket['start_date'].' '.$ticket['start_time'], new DateTimeZone('Africa/Nairobi'));
                $is_past  = $event_dt < $now;
              } catch(Exception $e) {}
              $ticket_url   = "https://keychele.co.ke/ticket/" . $ticket['id'];
              $status_class = $is_past ? 'past' : 'upcoming';
              $vc           = $views_by_ticket[$ticket['id']] ?? 0;
            ?>
            <tr class="event-row"
                data-title="<?= htmlspecialchars(strtolower($ticket['title'] ?? ''), ENT_QUOTES) ?>"
                data-venue="<?= htmlspecialchars(strtolower($ticket['venue'] ?? ''), ENT_QUOTES) ?>"
                data-status="<?= $status_class ?>">
              <td style="font-family:'JetBrains Mono',monospace;font-size:.72rem;font-weight:600;color:var(--text-muted)"><?= $i + 1 ?></td>
              <td>
                <div style="display:flex;align-items:center;gap:12px">
                  <?php if (!empty($ticket['banner'])): ?>
                    <img src="uploads/banners/<?= htmlspecialchars($ticket['banner']) ?>"
                         class="event-thumb" alt="">
                  <?php else: ?>
                    <div class="thumb-placeholder"><i class="fas fa-calendar-star"></i></div>
                  <?php endif; ?>
                  <div>
                    <div class="event-title-cell"><?= htmlspecialchars($ticket['title'] ?? 'Untitled') ?></div>
                    <div class="event-sub-cell">
                      <i class="fas fa-map-marker-alt" style="font-size:.6rem;margin-right:3px"></i>
                      <?= htmlspecialchars($ticket['venue'] ?? 'Venue TBA') ?>
                    </div>
                  </div>
                </div>
              </td>
              <td>
                <div class="date-val"><?= $ticket['start_date'] ? date('d M Y', strtotime($ticket['start_date'])) : 'TBD' ?></div>
                <div class="date-time">
                  <?= $ticket['start_time'] ? date('g:i A', strtotime($ticket['start_time'])) : '—' ?>
                  <?= $ticket['end_time']   ? ' – ' . date('g:i A', strtotime($ticket['end_time'])) : '' ?>
                </div>
              </td>
              <td style="font-size:.8rem;color:var(--text-sec)"><?= htmlspecialchars($ticket['venue'] ?? '—') ?></td>
              <td style="text-align:center">
                <span class="views-chip <?= $vc === 0 ? 'zero' : '' ?>">
                  <i class="fas fa-eye"></i>
                  <?= number_format($vc) ?>
                </span>
              </td>
              <td style="text-align:center">
                <span class="badge badge-<?= $status_class ?>">
                  <?= $is_past ? 'Past' : 'Upcoming' ?>
                </span>
              </td>
              <td>
                <div class="row-actions">
                  <!-- View Buyers -->
                  <a href="view_ticket_details.php?event_id=<?= $ticket['id'] ?>"
                     class="icon-btn" style="background:var(--navy);color:#fec411"
                     data-tip="View ticket buyers &amp; check-in">
                    <i class="fas fa-users"></i>
                  </a>
                  <!-- Edit -->
                  <a href="?page=edit_ticket&id=<?= $ticket['id'] ?>"
                     class="icon-btn icon-btn-blue" data-tip="Edit event">
                    <i class="fas fa-pen-to-square"></i>
                  </a>
                  <!-- Copy link -->
                  <button class="icon-btn icon-btn-green share-btn"
                          data-url="<?= htmlspecialchars($ticket_url, ENT_QUOTES) ?>"
                          data-tip="Copy ticket link">
                    <i class="fas fa-link"></i>
                  </button>
                  <!-- Delete -->
                  <a href="delete_ticket.php?id=<?= $ticket['id'] ?>"
                     class="icon-btn icon-btn-red delete-btn"
                     data-tip="Delete event"
                     data-title="<?= htmlspecialchars($ticket['title'] ?? 'this event', ENT_QUOTES) ?>">
                    <i class="fas fa-trash-can"></i>
                  </a>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <div id="noResults">
          <i class="fas fa-search-minus"></i>
          <p>No events match your search or filter.</p>
        </div>
      </div>

      <!-- Mobile Cards -->
      <div class="mob-only mobile-cards" id="mobileCards">
        <?php foreach ($tickets as $ticket):
          $event_dt = null;
          $is_past  = false;
          try {
            $event_dt = new DateTime($ticket['start_date'].' '.$ticket['start_time'], new DateTimeZone('Africa/Nairobi'));
            $is_past  = $event_dt < $now;
          } catch(Exception $e) {}
          $ticket_url   = "https://keychele.co.ke/ticket/" . $ticket['id'];
          $status_class = $is_past ? 'past' : 'upcoming';
          $vc           = $views_by_ticket[$ticket['id']] ?? 0;
        ?>
        <div class="m-card <?= $status_class ?>"
             data-title="<?= htmlspecialchars(strtolower($ticket['title'] ?? ''), ENT_QUOTES) ?>"
             data-venue="<?= htmlspecialchars(strtolower($ticket['venue'] ?? ''), ENT_QUOTES) ?>"
             data-status="<?= $status_class ?>">
          <div class="m-card-top">
            <?php if (!empty($ticket['banner'])): ?>
              <img src="uploads/banners/<?= htmlspecialchars($ticket['banner']) ?>"
                   class="event-thumb" alt="">
            <?php else: ?>
              <div class="thumb-placeholder"><i class="fas fa-calendar-star"></i></div>
            <?php endif; ?>
            <div class="m-card-info">
              <div class="m-title"><?= htmlspecialchars($ticket['title'] ?? 'Untitled') ?></div>
              <div class="m-venue">
                <i class="fas fa-map-marker-alt" style="font-size:.6rem;margin-right:2px"></i>
                <?= htmlspecialchars($ticket['venue'] ?? 'Venue TBA') ?>
              </div>
            </div>
            <span class="badge badge-<?= $status_class ?>"><?= $is_past ? 'Past' : 'Upcoming' ?></span>
          </div>
          <div class="m-grid">
            <div class="m-field">
              <div class="m-field-label">Date</div>
              <div class="m-field-val"><?= $ticket['start_date'] ? date('d M Y', strtotime($ticket['start_date'])) : 'TBD' ?></div>
            </div>
            <div class="m-field">
              <div class="m-field-label">Time</div>
              <div class="m-field-val"><?= $ticket['start_time'] ? date('g:i A', strtotime($ticket['start_time'])) : '—' ?></div>
            </div>
            <div class="m-field">
              <div class="m-field-label">Views</div>
              <div class="m-field-val" style="color:var(--purple)">
                <i class="fas fa-eye" style="font-size:.7rem;margin-right:3px"></i>
                <?= number_format($vc) ?>
              </div>
            </div>
            <div class="m-field">
              <div class="m-field-label">Status</div>
              <div class="m-field-val"><?= $is_past ? 'Past' : 'Upcoming' ?></div>
            </div>
          </div>
          <div class="m-actions">
            <a href="view_ticket_details.php?event_id=<?= $ticket['id'] ?>"
               class="m-btn m-btn-navy">
              <i class="fas fa-users"></i> Buyers
            </a>
            <a href="?page=edit_ticket&id=<?= $ticket['id'] ?>"
               class="m-btn m-btn-gold">
              <i class="fas fa-pen-to-square"></i> Edit
            </a>
            <button class="m-btn m-btn-green share-btn"
                    data-url="<?= htmlspecialchars($ticket_url, ENT_QUOTES) ?>">
              <i class="fas fa-link"></i> Copy
            </button>
            <a href="delete_ticket.php?id=<?= $ticket['id'] ?>"
               class="m-btn m-btn-red delete-btn"
               data-title="<?= htmlspecialchars($ticket['title'] ?? 'this event', ENT_QUOTES) ?>">
              <i class="fas fa-trash-can"></i>
            </a>
          </div>
        </div>
        <?php endforeach; ?>
        <div id="noResultsMob" style="display:none;text-align:center;padding:40px 16px;color:var(--text-muted);font-size:.875rem">
          <i class="fas fa-search-minus" style="font-size:1.8rem;display:block;margin-bottom:8px"></i>
          No events match your search or filter.
        </div>
      </div>

      <!-- Panel Footer -->
      <div class="panel-footer">
        <span id="footerCount">
          <strong><?= $total ?></strong> event<?= $total !== 1 ? 's' : '' ?> &nbsp;·&nbsp;
          <strong><?= $upcoming ?></strong> upcoming &nbsp;·&nbsp;
          <strong><?= $past ?></strong> past &nbsp;·&nbsp;
          <i class="fas fa-eye" style="font-size:.7rem;margin-right:2px;color:var(--purple)"></i>
          <strong><?= number_format($total_views) ?></strong> total views
        </span>
      </div>

    <?php endif; ?>
  </div><!-- /panel -->
</div><!-- /page-wrap -->

<!-- Mobile Bottom Nav -->
<nav class="bottom-nav">
  <a href="index.php?page=dashboard" class="nav-item">
    <i class="fas fa-home"></i>Home
  </a>
  <a href="index.php?page=ticket_purchases" class="nav-item active">
    <i class="fas fa-ticket-alt"></i>Tickets
  </a>
  <a href="?page=create_ticket" class="nav-fab">
    <div class="nav-fab-icon"><i class="fas fa-plus"></i></div>
    <span>Create</span>
  </a>
  <a href="index.php?page=my_wallet" class="nav-item">
    <i class="fas fa-wallet"></i>Wallet
  </a>
  <a href="index.php?page=profile" class="nav-item">
    <i class="fas fa-user"></i>Profile
  </a>
</nav>

<script>
// ── Preloader
window.addEventListener('load', function () {
  var p = document.getElementById('preloader');
  p.style.opacity = '0';
  setTimeout(function () { p.style.display = 'none'; }, 400);
});

// ── Search + Filter (desktop rows + mobile cards)
(function () {
  var searchInput = document.getElementById('searchInput');
  var clearBtn    = document.getElementById('clearSearchBtn');
  var filterSel   = document.getElementById('filterStatus');
  var noResultsD  = document.getElementById('noResults');
  var noResultsM  = document.getElementById('noResultsMob');
  var recordBadge = document.getElementById('recordBadge');

  var allRows  = Array.from(document.querySelectorAll('tr.event-row'));
  var allCards = Array.from(document.querySelectorAll('.m-card'));

  function applyFilters() {
    var q  = searchInput ? searchInput.value.trim().toLowerCase() : '';
    var st = filterSel   ? filterSel.value : 'all';
    var visible = 0;

    function filterItem(el) {
      var matchQ  = !q || el.dataset.title.includes(q) || el.dataset.venue.includes(q);
      var matchSt = st === 'all' || el.dataset.status === st;
      var show    = matchQ && matchSt;
      el.style.display = show ? '' : 'none';
      if (show) visible++;
    }

    allRows.forEach(filterItem);
    var visibleCards = 0;
    allCards.forEach(function(el) {
      var matchQ  = !q || el.dataset.title.includes(q) || el.dataset.venue.includes(q);
      var matchSt = st === 'all' || el.dataset.status === st;
      var show    = matchQ && matchSt;
      el.style.display = show ? '' : 'none';
      if (show) visibleCards++;
    });

    if (clearBtn)    clearBtn.style.display = q ? 'inline-flex' : 'none';
    if (noResultsD)  noResultsD.style.display  = visible === 0      ? 'block' : 'none';
    if (noResultsM)  noResultsM.style.display  = visibleCards === 0 ? 'block' : 'none';
    if (recordBadge) recordBadge.textContent = visible + ' event' + (visible !== 1 ? 's' : '');
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

// ── Copy Link
document.querySelectorAll('.share-btn').forEach(function (btn) {
  btn.addEventListener('click', function () {
    var url  = this.dataset.url;
    var icon = this.querySelector('i');
    var self = this;

    function onSuccess() {
      if (icon) icon.className = 'fas fa-check';
      self.classList.add('copied');
      Swal.fire({
        icon: 'success', title: 'Link Copied!',
        text: 'Ticket link has been copied to your clipboard.',
        toast: true, position: 'top-end', timer: 2500, showConfirmButton: false,
        background: '#f0fdf4'
      });
      setTimeout(function () {
        if (icon) icon.className = 'fas fa-link';
        self.classList.remove('copied');
      }, 2500);
    }

    function onFail() {
      Swal.fire({ icon: 'error', title: 'Copy Failed', text: 'Please copy the link manually: ' + url, confirmButtonColor: '#b81236' });
    }

    function fallback() {
      try {
        var ta = document.createElement('textarea');
        ta.value = url; ta.setAttribute('readonly', '');
        ta.style.cssText = 'position:fixed;top:-9999px;left:-9999px;opacity:0;';
        document.body.appendChild(ta);
        ta.focus(); ta.select(); ta.setSelectionRange(0, ta.value.length);
        var ok = document.execCommand('copy');
        document.body.removeChild(ta);
        if (ok) onSuccess(); else onFail();
      } catch (err) { onFail(); }
    }

    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(url).then(onSuccess).catch(fallback);
    } else { fallback(); }
  });
});

// ── Delete Confirm
document.querySelectorAll('.delete-btn').forEach(function (btn) {
  btn.addEventListener('click', function (e) {
    e.preventDefault();
    var href  = this.href;
    var title = this.dataset.title || 'this event';
    Swal.fire({
      icon: 'warning',
      title: 'Delete Event?',
      html: 'You are about to permanently delete <strong>' + title + '</strong>.<br>This cannot be undone.',
      showCancelButton: true,
      confirmButtonText: '<i class="fas fa-trash-can"></i> Yes, Delete',
      cancelButtonText: 'Cancel',
      confirmButtonColor: '#dc2626',
      cancelButtonColor: '#475569',
      reverseButtons: true,
      focusCancel: true,
    }).then(function (result) {
      if (result.isConfirmed) window.location.href = href;
    });
  });
});

<?php if ($message): ?>
// ── Flash message toast
window.addEventListener('load', function () {
  Swal.fire({
    icon: '<?= $message_type === 'success' ? 'success' : 'error' ?>',
    title: <?= json_encode($message) ?>,
    toast: true, position: 'top-end', timer: 3500, showConfirmButton: false,
    background: '<?= $message_type === 'success' ? '#f0fdf4' : '#fef2f2' ?>'
  });
});
<?php endif; ?>
</script>
</body>
</html>