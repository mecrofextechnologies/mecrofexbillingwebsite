<?php
date_default_timezone_set('Africa/Nairobi'); // match MySQL server clock — see timeAgo()
include '../msanii/includes/connect.php';
if (session_status() === PHP_SESSION_NONE) session_start();

if (!isset($_SESSION['user_role']) || !in_array($_SESSION['user_role'], [1, 2])) {
    header("Location: ../login.php");
    exit();
}

try {
    // ── KPI: single-pass aggregates ──────────────────────────
    $accountBalance = (float) $pdo->query("
        SELECT COALESCE(amount, 0) FROM keychele_balance ORDER BY id DESC LIMIT 1
    ")->fetchColumn();

    $kpi = $pdo->query("
        SELECT
            COUNT(*)                                                            AS total_creators,
            SUM(date_created >= DATE_SUB(NOW(), INTERVAL 30 DAY))              AS new_30d,
            SUM(must_change_password = 1)                                       AS must_change,
            SUM(inactivity_warning_sent_at IS NOT NULL)                         AS warned,
            SUM(scheduled_delete_date IS NOT NULL
                AND scheduled_delete_date <= CURDATE())                         AS due_deletion
        FROM creators
    ")->fetch(PDO::FETCH_ASSOC);

    $totalCreators    = (int)$kpi['total_creators'];
    $newCreators30d   = (int)$kpi['new_30d'];
    $mustChangePw     = (int)$kpi['must_change'];
    $warnedCount      = (int)$kpi['warned'];
    $dueDeletionCount = (int)$kpi['due_deletion'];

    $wdr = $pdo->query("
        SELECT
            COALESCE(SUM(CASE WHEN status IN ('completed','successful') THEN total_amount ELSE 0 END), 0) AS paid_out,
            SUM(status = 'pending')                                                       AS pending_cnt
        FROM withdrawal_requests
    ")->fetch(PDO::FETCH_ASSOC);
    $totalPaidOut       = (float)$wdr['paid_out'];
    $pendingWithdrawals = (int)$wdr['pending_cnt'];

    // ── Ticket KPIs ──────────────────────────────────────────
    $tkpi = $pdo->query("
        SELECT
            COUNT(*)                                                                AS total_purchases,
            SUM(status = 'completed')                                               AS completed,
            SUM(CASE WHEN status='completed' THEN amount_paid  ELSE 0 END)          AS ticket_revenue,
            SUM(CASE WHEN status='completed' THEN quantity     ELSE 0 END)          AS tickets_sold
        FROM ticket_purchases
    ")->fetch(PDO::FETCH_ASSOC);
    $totalPurchases  = (int)$tkpi['total_purchases'];
    $ticketCompleted = (int)$tkpi['completed'];
    $ticketRevenue   = (float)$tkpi['ticket_revenue'];
    $ticketsSold     = (int)$tkpi['tickets_sold'];

    // ── Web Visit KPIs ───────────────────────────────────────
    $visitKpi = $pdo->query("
        SELECT
            (SELECT COALESCE(total_views, 0) FROM web_visit_total WHERE id = 1)     AS total_views,
            SUM(DATE(last_visit) = CURDATE())                                        AS today,
            SUM(last_visit >= DATE_SUB(NOW(), INTERVAL 7 DAY))                      AS this_week,
            SUM(last_visit >= DATE_SUB(NOW(), INTERVAL 30 DAY))                     AS this_month,
            COUNT(DISTINCT ip_address)                                               AS unique_visitors,
            COUNT(DISTINCT CASE WHEN DATE(last_visit)=CURDATE() THEN ip_address END) AS unique_today
        FROM web_visits
    ")->fetch(PDO::FETCH_ASSOC);

    $visitTotal       = (int)($visitKpi['total_views']     ?? 0);
    $visitToday       = (int)($visitKpi['today']           ?? 0);
    $visitWeek        = (int)($visitKpi['this_week']       ?? 0);
    $visitMonth       = (int)($visitKpi['this_month']      ?? 0);
    $visitUnique      = (int)($visitKpi['unique_visitors'] ?? 0);
    $visitUniqueToday = (int)($visitKpi['unique_today']    ?? 0);

    // Top pages
    $topPages = $pdo->query("
        SELECT page_visited, SUM(visit_count) AS hits
        FROM web_visits
        GROUP BY page_visited
        ORDER BY hits DESC
        LIMIT 5
    ")->fetchAll(PDO::FETCH_ASSOC);

    // Top countries
    $topCountries = $pdo->query("
        SELECT country, COUNT(DISTINCT ip_address) AS uv
        FROM web_visits
        WHERE country != 'Unknown'
        GROUP BY country
        ORDER BY uv DESC
        LIMIT 5
    ")->fetchAll(PDO::FETCH_ASSOC);

    // Top devices
    $topDevices = $pdo->query("
        SELECT device, COUNT(*) AS cnt
        FROM web_visits
        GROUP BY device
        ORDER BY cnt DESC
        LIMIT 5
    ")->fetchAll(PDO::FETCH_ASSOC);

    // Daily visits last 14 days (for mini sparkline data)
    $dailyVisits = $pdo->query("
        SELECT DATE_FORMAT(date_recorded, '%d %b') AS label,
               DATE(date_recorded)                 AS sort_d,
               SUM(visit_count)                    AS hits
        FROM web_visits
        WHERE date_recorded >= DATE_SUB(CURDATE(), INTERVAL 13 DAY)
        GROUP BY DATE(date_recorded), DATE_FORMAT(date_recorded, '%d %b')
        ORDER BY sort_d ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
    $dailyLabels = array_column($dailyVisits, 'label');
    $dailyValues = array_map('intval', array_column($dailyVisits, 'hits'));

    // Recent visitors (last 8)
    $recentVisitors = $pdo->query("
        SELECT ip_address, device, browser, os, country, city,
               page_visited, visit_count, last_visit, isp
        FROM web_visits
        ORDER BY last_visit DESC
        LIMIT 8
    ")->fetchAll(PDO::FETCH_ASSOC);

    // ── Dynamic chart: registrations per month (last 13 months) ─
    $growth_rows = $pdo->query("
        SELECT
            DATE_FORMAT(date_created, '%b %y')  AS label,
            DATE_FORMAT(date_created, '%Y-%m')  AS sort_key,
            COUNT(*)                             AS cnt
        FROM creators
        WHERE date_created >= DATE_SUB(NOW(), INTERVAL 13 MONTH)
        GROUP BY DATE_FORMAT(date_created, '%Y-%m'), DATE_FORMAT(date_created, '%b %y')
        ORDER BY sort_key ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    $growth_labels = array_column($growth_rows, 'label');
    $growth_values = array_map('intval', array_column($growth_rows, 'cnt'));

    // ── Cumulative total line ────────────────────────────────
    $base_stmt = $pdo->prepare("
        SELECT COUNT(*) FROM creators
        WHERE date_created < DATE_SUB(NOW(), INTERVAL 13 MONTH)
    ");
    $base_stmt->execute();
    $cum = (int)$base_stmt->fetchColumn();
    $growth_cumulative = [];
    foreach ($growth_values as $v) { $cum += $v; $growth_cumulative[] = $cum; }

    // ── Revenue trend: last 12 months ────────────────────────
    $rev_rows = $pdo->query("
        SELECT
            DATE_FORMAT(created_at, '%b %y')  AS label,
            DATE_FORMAT(created_at, '%Y-%m')  AS sort_key,
            SUM(amount_paid)                  AS rev
        FROM ticket_purchases
        WHERE status = 'completed'
          AND created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
        GROUP BY DATE_FORMAT(created_at,'%Y-%m'), DATE_FORMAT(created_at,'%b %y')
        ORDER BY sort_key ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
    $rev_labels = array_column($rev_rows, 'label');
    $rev_values = array_map('floatval', array_column($rev_rows, 'rev'));

    // ── Recent purchases (last 8) ────────────────────────────
    $recent_purchases = $pdo->query("
        SELECT tp.id, tp.buyer_name, tp.buyer_email, tp.amount_paid,
               tp.quantity, tp.status, tp.created_at,
               t.title AS ticket_title, t.venue
        FROM ticket_purchases tp
        LEFT JOIN tickets t ON tp.ticket_id = t.id
        ORDER BY tp.created_at DESC
        LIMIT 8
    ")->fetchAll(PDO::FETCH_ASSOC);
    $recent_purchases = array_map(fn($row) => kc_decrypt_row($row, ['buyer_name','buyer_email','buyer_phone']), $recent_purchases);

    // ── Recent creators (last 6) ─────────────────────────────
    $recent_creators = $pdo->query("
        SELECT id, full_name, username, email, date_created, last_login
        FROM creators
        ORDER BY date_created DESC
        LIMIT 6
    ")->fetchAll(PDO::FETCH_ASSOC);
    $recent_creators = array_map(fn($row) => kc_decrypt_row($row), $recent_creators);

    // ── Creator login activity feed (last 10 successful logins) ─
    $login_activity = $pdo->query("
        SELECT cl.creator_id, cl.email, cl.created_at, cl.ip_address,
               c.full_name, c.username
        FROM creator_logs cl
        LEFT JOIN creators c ON c.id = cl.creator_id
        WHERE cl.event_type = 'success'
        ORDER BY cl.created_at DESC
        LIMIT 10
    ")->fetchAll(PDO::FETCH_ASSOC);
    $login_activity = array_map(function($r) { $r['full_name'] = kc_decrypt($r['full_name']); return $r; }, $login_activity);

    // ── Login logs: all events (last 30) ─────────────────────
    $login_logs = $pdo->query("
        SELECT cl.id, cl.creator_id, cl.email, cl.event_type,
               cl.failure_reason, cl.attempt_number,
               cl.ip_address, cl.user_agent, cl.created_at,
               c.full_name, c.username
        FROM creator_logs cl
        LEFT JOIN creators c ON c.id = cl.creator_id
        ORDER BY cl.created_at DESC
        LIMIT 30
    ")->fetchAll(PDO::FETCH_ASSOC);
    $login_logs = array_map(function($r) { $r['full_name'] = kc_decrypt($r['full_name']); return $r; }, $login_logs);

    // ── Log summary counts (for mini-stats) ──────────────────
    $log_kpi = $pdo->query("
        SELECT
            SUM(event_type = 'success')           AS total_success,
            SUM(event_type IN (
                'failed_password','failed_not_found')) AS total_failed,
            SUM(event_type = 'locked_out')         AS total_locked,
            SUM(event_type = 'csrf_mismatch')      AS total_csrf,
            SUM(DATE(created_at) = CURDATE())      AS today_total
        FROM creator_logs
    ")->fetch(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $error_message      = "Database error: " . htmlspecialchars($e->getMessage());
    $accountBalance     = $totalPaidOut = $ticketRevenue = 0.0;
    $totalCreators      = $newCreators30d = $mustChangePw = $warnedCount = 0;
    $dueDeletionCount   = $pendingWithdrawals = $totalPurchases = 0;
    $ticketCompleted    = $ticketsSold = 0;
    $visitTotal = $visitToday = $visitWeek = $visitMonth = $visitUnique = $visitUniqueToday = 0;
    $growth_labels      = $growth_values = $growth_cumulative = [];
    $rev_labels         = $rev_values = [];
    $dailyLabels        = $dailyValues = [];
    $recent_purchases   = $recent_creators = [];
    $login_activity     = $login_logs = [];
    $topPages = $topCountries = $topDevices = $recentVisitors = [];
    $log_kpi            = ['total_success'=>0,'total_failed'=>0,'total_locked'=>0,'total_csrf'=>0,'today_total'=>0];
}

function timeAgo(?string $d): string {
    if (!$d || $d === '0000-00-00 00:00:00') return '—';
    $diff = time() - strtotime($d);
    if ($diff < 60)      return 'Just now';
    if ($diff < 3600)    return floor($diff/60) . 'm ago';
    if ($diff < 86400)   return floor($diff/3600) . 'h ago';
    if ($diff < 604800)  return floor($diff/86400) . 'd ago';
    return date('d M Y', strtotime($d));
}

function initials(string $name): string {
    $parts = explode(' ', trim($name));
    return strtoupper(substr($parts[0] ?? '?', 0, 1) . substr(end($parts), 0, 1));
}

function eventConfig(string $type): array {
    return match($type) {
        'success'              => ['label'=>'Success',         'cls'=>'b-green',  'icon'=>'fa-check-circle',    'dot'=>'#059669'],
        'force_password_change'=> ['label'=>'PW Change',       'cls'=>'b-amber',  'icon'=>'fa-key',             'dot'=>'#d97706'],
        'failed_password'      => ['label'=>'Wrong Password',  'cls'=>'b-red',    'icon'=>'fa-times-circle',    'dot'=>'#dc2626'],
        'failed_not_found'     => ['label'=>'Not Found',       'cls'=>'b-red',    'icon'=>'fa-user-slash',      'dot'=>'#dc2626'],
        'locked_out'           => ['label'=>'Locked Out',      'cls'=>'b-orange', 'icon'=>'fa-lock',            'dot'=>'#ea580c'],
        'csrf_mismatch'        => ['label'=>'CSRF Mismatch',   'cls'=>'b-purple', 'icon'=>'fa-shield-halved',   'dot'=>'#7c3aed'],
        'missing_fields'       => ['label'=>'Missing Fields',  'cls'=>'b-gray',   'icon'=>'fa-exclamation',     'dot'=>'#8b95b8'],
        'invalid_email'        => ['label'=>'Invalid Email',   'cls'=>'b-gray',   'icon'=>'fa-at',              'dot'=>'#8b95b8'],
        default                => ['label'=>ucfirst($type),    'cls'=>'b-gray',   'icon'=>'fa-circle-info',     'dot'=>'#8b95b8'],
    };
}
?>
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
  --blue:        #2563eb;
  --blue-h:      #1d4ed8;
  --blue-l:      #dbeafe;
  --blue-ll:     #eff6ff;

  --emerald:     #059669;
  --emerald-l:   #d1fae5;
  --amber:       #d97706;
  --amber-l:     #fef3c7;
  --orange:      #ea580c;
  --orange-l:    #ffedd5;
  --red:         #dc2626;
  --red-l:       #fee2e2;
  --sky:         #0284c7;
  --sky-l:       #e0f2fe;
  --violet:      #7c3aed;
  --violet-l:    #ede9fe;
  --indigo:      #4f46e5;
  --indigo-l:    #e0e7ff;
  --purple:      #9333ea;
  --purple-l:    #f3e8ff;
  --teal:        #0d9488;
  --teal-l:      #ccfbf1;
  --rose:        #e11d48;
  --rose-l:      #ffe4e6;
  --cyan:        #0891b2;
  --cyan-l:      #cffafe;

  --text:        #0d1b3e;
  --text-2:      #4b5580;
  --text-3:      #8b95b8;

  --radius:      14px;
  --radius-sm:   9px;
  --shadow-sm:   0 1px 3px rgba(13,27,62,.06), 0 2px 8px rgba(13,27,62,.04);
  --shadow:      0 2px 8px rgba(13,27,62,.08), 0 8px 24px rgba(13,27,62,.06);

  --font:        'Outfit', sans-serif;
  --mono:        'JetBrains Mono', monospace;
}

.dash-wrap { padding: 28px; max-width: 1680px; margin: 0 auto; font-family: var(--font); color: var(--text); }

/* ── PAGE HEADER ── */
.dash-header { margin-bottom: 28px; }
.dash-header h1 {
  font-size: 1.55rem; font-weight: 800; letter-spacing: -.03em;
  color: var(--navy); display: flex; align-items: center; gap: 10px;
}
.dash-header h1 .icon-wrap {
  width: 38px; height: 38px; background: var(--blue); border-radius: 10px;
  display: inline-flex; align-items: center; justify-content: center;
  font-size: .85rem; color: #fff; flex-shrink: 0;
}
.dash-header p { color: var(--text-2); font-size: .82rem; margin-top: 3px; padding-left: 48px; }

/* ── SECTION LABEL ── */
.section-label {
  display: flex; align-items: center; gap: 10px;
  font-size: .72rem; font-weight: 800; text-transform: uppercase;
  letter-spacing: .1em; color: var(--text-3); margin-bottom: 14px; margin-top: 6px;
}
.section-label::after {
  content: ''; flex: 1; height: 1px; background: var(--border);
}
.section-label i { color: var(--blue); font-size: .65rem; }

/* ── KPI GRID ── */
.kpi-grid-dash {
  display: grid; grid-template-columns: repeat(4, 1fr);
  gap: 16px; margin-bottom: 26px;
}
@media (max-width:1100px) { .kpi-grid-dash { grid-template-columns: repeat(2, 1fr); } }
@media (max-width:540px)  { .kpi-grid-dash { grid-template-columns: 1fr; } }

.kpi-d {
  border-radius: var(--radius); overflow: hidden;
  box-shadow: var(--shadow-sm); position: relative;
  transition: transform .2s, box-shadow .2s;
  border: 1px solid var(--border); text-decoration: none; display: block;
}
.kpi-d:hover { transform: translateY(-3px); box-shadow: var(--shadow); }
.kpi-d-inner { padding: 20px 22px 16px; background: var(--surface); }
.kpi-d-bar   { height: 4px; }
.kpi-d-top   { display: flex; align-items: flex-start; justify-content: space-between; gap: 10px; margin-bottom: 14px; }
.kpi-d-icon  { width: 42px; height: 42px; border-radius: 11px; display: flex; align-items: center; justify-content: center; font-size: .9rem; flex-shrink: 0; }
.kpi-d-label { font-size: .7rem; font-weight: 700; text-transform: uppercase; letter-spacing: .08em; color: var(--text-3); margin-bottom: 4px; }
.kpi-d-value { font-size: 1.85rem; font-weight: 800; line-height: 1; letter-spacing: -.04em; }
.kpi-d-sub   { font-size: .75rem; color: var(--text-3); margin-top: 6px; }
.kpi-d-link  {
  display: flex; align-items: center; justify-content: space-between;
  padding: 9px 22px; font-size: .75rem; font-weight: 600;
  background: var(--surface-2); border-top: 1px solid var(--border);
  text-decoration: none; transition: background .15s; color: var(--text-2);
}
.kpi-d-link:hover { background: var(--blue-ll); color: var(--blue); }

.kpi-d.c-blue   .kpi-d-bar { background: var(--blue); }
.kpi-d.c-blue   .kpi-d-icon  { background: var(--blue-l);   color: var(--blue); }
.kpi-d.c-blue   .kpi-d-value { color: var(--blue); }
.kpi-d.c-green  .kpi-d-bar { background: var(--emerald); }
.kpi-d.c-green  .kpi-d-icon  { background: var(--emerald-l); color: var(--emerald); }
.kpi-d.c-green  .kpi-d-value { color: var(--emerald); }
.kpi-d.c-amber  .kpi-d-bar { background: var(--amber); }
.kpi-d.c-amber  .kpi-d-icon  { background: var(--amber-l);  color: var(--amber); }
.kpi-d.c-amber  .kpi-d-value { color: var(--amber); }
.kpi-d.c-red    .kpi-d-bar { background: var(--red); }
.kpi-d.c-red    .kpi-d-icon  { background: var(--red-l);    color: var(--red); }
.kpi-d.c-red    .kpi-d-value { color: var(--red); }
.kpi-d.c-violet .kpi-d-bar { background: var(--violet); }
.kpi-d.c-violet .kpi-d-icon  { background: var(--violet-l); color: var(--violet); }
.kpi-d.c-violet .kpi-d-value { color: var(--violet); }
.kpi-d.c-sky    .kpi-d-bar { background: var(--sky); }
.kpi-d.c-sky    .kpi-d-icon  { background: var(--sky-l);    color: var(--sky); }
.kpi-d.c-sky    .kpi-d-value { color: var(--sky); }
.kpi-d.c-indigo .kpi-d-bar { background: var(--indigo); }
.kpi-d.c-indigo .kpi-d-icon  { background: var(--indigo-l); color: var(--indigo); }
.kpi-d.c-indigo .kpi-d-value { color: var(--indigo); }
.kpi-d.c-teal   .kpi-d-bar { background: var(--teal); }
.kpi-d.c-teal   .kpi-d-icon  { background: var(--teal-l);   color: var(--teal); }
.kpi-d.c-teal   .kpi-d-value { color: var(--teal); }
.kpi-d.c-rose   .kpi-d-bar { background: var(--rose); }
.kpi-d.c-rose   .kpi-d-icon  { background: var(--rose-l);   color: var(--rose); }
.kpi-d.c-rose   .kpi-d-value { color: var(--rose); }
.kpi-d.c-cyan   .kpi-d-bar { background: var(--cyan); }
.kpi-d.c-cyan   .kpi-d-icon  { background: var(--cyan-l);   color: var(--cyan); }
.kpi-d.c-cyan   .kpi-d-value { color: var(--cyan); }
.kpi-d.c-orange .kpi-d-bar { background: var(--orange); }
.kpi-d.c-orange .kpi-d-icon  { background: var(--orange-l); color: var(--orange); }
.kpi-d.c-orange .kpi-d-value { color: var(--orange); }

/* ── WEB VISITS KPI GRID (6-col) ── */
.kpi-grid-visits {
  display: grid;
  grid-template-columns: repeat(6, 1fr);
  gap: 14px; margin-bottom: 22px;
}
@media (max-width:1200px) { .kpi-grid-visits { grid-template-columns: repeat(3, 1fr); } }
@media (max-width:700px)  { .kpi-grid-visits { grid-template-columns: repeat(2, 1fr); } }
@media (max-width:420px)  { .kpi-grid-visits { grid-template-columns: 1fr; } }

/* ── VISIT INSIGHT ROW ── */
.visit-insight-row {
  display: grid; grid-template-columns: 1fr 1fr 1fr;
  gap: 18px; margin-bottom: 22px;
}
@media (max-width:960px)  { .visit-insight-row { grid-template-columns: 1fr 1fr; } }
@media (max-width:600px)  { .visit-insight-row { grid-template-columns: 1fr; } }

/* ── MINI STATS ── */
.mini-stats {
  display: grid; grid-template-columns: repeat(4, 1fr);
  gap: 14px; margin-bottom: 26px;
}
@media (max-width:900px) { .mini-stats { grid-template-columns: repeat(2,1fr); } }
.mini-stat {
  background: var(--surface); border: 1px solid var(--border);
  border-radius: var(--radius); box-shadow: var(--shadow-sm);
  padding: 14px 18px; display: flex; align-items: center; gap: 14px;
  transition: transform .18s; cursor: default;
}
.mini-stat:hover { transform: translateY(-2px); }
.mini-dot { width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0; }
.mini-stat-body { flex: 1; min-width: 0; }
.mini-stat-label { font-size: .68rem; font-weight: 700; text-transform: uppercase; letter-spacing: .07em; color: var(--text-3); margin-bottom: 2px; }
.mini-stat-value { font-size: 1.2rem; font-weight: 800; letter-spacing: -.03em; color: var(--text); }

/* ── CARD ── */
.card-ui {
  background: var(--surface); border-radius: var(--radius);
  box-shadow: var(--shadow-sm); border: 1px solid var(--border);
  overflow: hidden; margin-bottom: 22px;
}
.card-head-ui {
  display: flex; align-items: center; justify-content: space-between;
  gap: 12px; padding: 16px 20px; border-bottom: 1px solid var(--border);
  background: var(--surface-2); flex-wrap: wrap;
}
.card-title-ui {
  font-size: .92rem; font-weight: 700; color: var(--navy);
  display: flex; align-items: center; gap: 8px;
}
.card-title-ui i { color: var(--blue); font-size: .8rem; }
.card-body-ui { padding: 20px; }

/* ── CHARTS ROW ── */
.charts-row { display: grid; grid-template-columns: 2fr 1fr; gap: 20px; margin-bottom: 22px; }
@media (max-width:900px) { .charts-row { grid-template-columns: 1fr; } }

/* ── BOTTOM ROW 2-col ── */
.bottom-row { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 22px; }
@media (max-width:900px) { .bottom-row { grid-template-columns: 1fr; } }

/* ── TABLE ── */
.tbl-ui { width: 100%; border-collapse: collapse; }
.tbl-ui thead th {
  padding: 9px 14px; background: var(--surface-2);
  border-bottom: 1px solid var(--border);
  font-size: .67rem; font-weight: 700; text-transform: uppercase;
  letter-spacing: .08em; color: var(--text-3); white-space: nowrap; text-align: left;
}
.tbl-ui tbody tr { border-bottom: 1px solid var(--border); transition: background .1s; }
.tbl-ui tbody tr:last-child { border-bottom: none; }
.tbl-ui tbody tr:hover { background: var(--blue-ll); }
.tbl-ui td { padding: 11px 14px; font-size: .81rem; color: var(--text); vertical-align: middle; }

/* ── AVATAR ── */
.av {
  width: 32px; height: 32px; border-radius: 8px;
  background: var(--blue-l); color: var(--blue);
  font-weight: 800; font-size: .68rem;
  display: inline-flex; align-items: center; justify-content: center;
  flex-shrink: 0; letter-spacing: -.01em;
}
.av.av-sm { width: 26px; height: 26px; font-size: .6rem; border-radius: 6px; }

/* ── BADGES ── */
.badge-ui {
  display: inline-flex; align-items: center; gap: 3px;
  padding: 2px 9px; border-radius: 100px;
  font-size: .66rem; font-weight: 700; letter-spacing: .02em; white-space: nowrap;
}
.b-green  { background: var(--emerald-l); color: #065f46; }
.b-amber  { background: var(--amber-l);  color: #92400e; }
.b-orange { background: var(--orange-l); color: #9a3412; }
.b-red    { background: var(--red-l);    color: #991b1b; }
.b-gray   { background: var(--bg);       color: var(--text-2); }
.b-purple { background: var(--purple-l); color: #6b21a8; }
.b-teal   { background: var(--teal-l);   color: #134e4a; }
.b-sky    { background: var(--sky-l);    color: #075985; }

/* ── VIEW-ALL LINK ── */
.view-all-link {
  display: inline-flex; align-items: center; gap: 5px;
  font-size: .76rem; font-weight: 600; color: var(--blue);
  text-decoration: none; padding: 5px 0;
}
.view-all-link:hover { color: var(--blue-h); text-decoration: underline; }

/* ── ALERT ── */
.alert-err-dash {
  background: var(--red-l); border: 1px solid #fca5a5;
  border-radius: var(--radius-sm); padding: 12px 16px;
  color: #991b1b; font-size: .84rem; margin-bottom: 20px;
  display: flex; align-items: center; gap: 8px; font-family: var(--font);
}

/* ── EMPTY ── */
.empty-row td { text-align: center; padding: 40px 20px; color: var(--text-3); font-size: .82rem; }

/* ── ACTIVITY FEED ── */
.activity-feed { display: flex; flex-direction: column; gap: 0; }
.activity-item {
  display: flex; align-items: center; gap: 12px;
  padding: 12px 20px; border-bottom: 1px solid var(--border);
  transition: background .12s;
}
.activity-item:last-child { border-bottom: none; }
.activity-item:hover { background: var(--blue-ll); }
.activity-av { position: relative; flex-shrink: 0; }
.activity-av .av { width: 36px; height: 36px; font-size: .72rem; border-radius: 10px; }
.activity-online {
  position: absolute; bottom: -2px; right: -2px;
  width: 10px; height: 10px; border-radius: 50%;
  background: var(--emerald); border: 2px solid var(--surface);
}
.activity-body { flex: 1; min-width: 0; }
.activity-name { font-size: .82rem; font-weight: 700; color: var(--navy); }
.activity-name span { font-weight: 400; color: var(--text-2); }
.activity-meta { font-size: .7rem; color: var(--text-3); margin-top: 2px; display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
.activity-meta i { font-size: .6rem; }
.activity-time { font-size: .72rem; font-weight: 600; color: var(--text-3); white-space: nowrap; flex-shrink: 0; }

/* ── LOG STATS STRIP ── */
.log-stats-strip {
  display: grid; grid-template-columns: repeat(5, 1fr);
  gap: 12px; margin-bottom: 18px;
}
@media (max-width: 900px) { .log-stats-strip { grid-template-columns: repeat(3,1fr); } }
@media (max-width: 540px) { .log-stats-strip { grid-template-columns: repeat(2,1fr); } }

.log-stat-pill {
  background: var(--surface-2); border: 1px solid var(--border);
  border-radius: var(--radius-sm); padding: 12px 16px;
  display: flex; flex-direction: column; gap: 4px;
  transition: transform .15s;
}
.log-stat-pill:hover { transform: translateY(-2px); }
.lsp-label { font-size: .65rem; font-weight: 700; text-transform: uppercase; letter-spacing: .08em; color: var(--text-3); }
.lsp-value { font-size: 1.3rem; font-weight: 800; letter-spacing: -.03em; }

/* ── LOG TABLE USER AGENT ── */
.ua-text {
  max-width: 200px; overflow: hidden; text-overflow: ellipsis;
  white-space: nowrap; font-size: .68rem; color: var(--text-3);
  font-family: var(--mono);
}

/* ── RANK LIST ── */
.rank-list { display: flex; flex-direction: column; gap: 8px; }
.rank-item { display: flex; align-items: center; gap: 10px; }
.rank-num {
  width: 22px; height: 22px; border-radius: 6px;
  background: var(--surface-2); border: 1px solid var(--border);
  font-size: .65rem; font-weight: 800; color: var(--text-3);
  display: flex; align-items: center; justify-content: center; flex-shrink: 0;
}
.rank-bar-wrap { flex: 1; }
.rank-label { font-size: .76rem; font-weight: 600; color: var(--text); margin-bottom: 3px;
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 180px; }
.rank-bar-bg { height: 5px; border-radius: 10px; background: var(--border); overflow: hidden; }
.rank-bar-fill { height: 100%; border-radius: 10px; transition: width .6s cubic-bezier(.34,1.56,.64,1); }
.rank-count { font-size: .72rem; font-weight: 700; color: var(--text-2); white-space: nowrap; flex-shrink: 0; }

/* ── LIVE DOT ── */
.live-dot {
  width: 7px; height: 7px; border-radius: 50%; background: var(--emerald);
  display: inline-block; animation: livePulse 1.6s ease-in-out infinite; margin-right: 4px;
}
@keyframes livePulse { 0%,100%{opacity:1;transform:scale(1);} 50%{opacity:.4;transform:scale(.7);} }

@media (max-width:680px) { .dash-wrap { padding: 14px; } }
</style>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<div class="dash-wrap">

  <!-- ── PAGE HEADER ── -->
  <div class="dash-header">
    <h1>
      <span class="icon-wrap"><i class="fas fa-tachometer-alt"></i></span>
      Dashboard
    </h1>
    <p>Welcome back · Platform overview · <?= date('l, d F Y') ?></p>
  </div>

  <?php if (isset($error_message)): ?>
    <div class="alert-err-dash"><i class="fas fa-exclamation-circle"></i><?= $error_message ?></div>
  <?php endif; ?>

  <!-- ══════════════════════════════════════════
       ROW 1 — PRIMARY KPI CARDS
  ══════════════════════════════════════════ -->
  <div class="section-label"><i class="fas fa-layer-group"></i> Platform Overview</div>
  <div class="kpi-grid-dash">

    <div class="kpi-d c-blue">
      <div class="kpi-d-bar"></div>
      <div class="kpi-d-inner">
        <div class="kpi-d-top">
          <div>
            <div class="kpi-d-label">Total Creators</div>
            <div class="kpi-d-value"><?= number_format($totalCreators) ?></div>
            <div class="kpi-d-sub">
              <i class="fas fa-arrow-up" style="color:var(--emerald);font-size:.65rem"></i>
              <?= number_format($newCreators30d) ?> joined in last 30 days
            </div>
          </div>
          <div class="kpi-d-icon"><i class="fas fa-users"></i></div>
        </div>
      </div>
      <a href="index.php?page=all_creators" class="kpi-d-link">View All Creators <i class="fas fa-arrow-right"></i></a>
    </div>

    <div class="kpi-d c-green">
      <div class="kpi-d-bar"></div>
      <div class="kpi-d-inner">
        <div class="kpi-d-top">
          <div>
            <div class="kpi-d-label">Account Balance</div>
            <div class="kpi-d-value" style="font-size:1.4rem">KSh <?= number_format($accountBalance, 2) ?></div>
            <div class="kpi-d-sub">current platform balance</div>
          </div>
          <div class="kpi-d-icon"><i class="fas fa-wallet"></i></div>
        </div>
      </div>
      <a href="#" class="kpi-d-link">Balance History <i class="fas fa-arrow-right"></i></a>
    </div>

    <div class="kpi-d c-sky">
      <div class="kpi-d-bar"></div>
      <div class="kpi-d-inner">
        <div class="kpi-d-top">
          <div>
            <div class="kpi-d-label">Total Paid Out</div>
            <div class="kpi-d-value" style="font-size:1.4rem">KSh <?= number_format($totalPaidOut, 2) ?></div>
            <div class="kpi-d-sub">completed withdrawals</div>
          </div>
          <div class="kpi-d-icon"><i class="fas fa-hand-holding-usd"></i></div>
        </div>
      </div>
      <a href="index.php?page=withdrawals" class="kpi-d-link">Withdrawal History <i class="fas fa-arrow-right"></i></a>
    </div>

    <div class="kpi-d <?= $pendingWithdrawals > 0 ? 'c-amber' : 'c-green' ?>">
      <div class="kpi-d-bar"></div>
      <div class="kpi-d-inner">
        <div class="kpi-d-top">
          <div>
            <div class="kpi-d-label">Pending Withdrawals</div>
            <div class="kpi-d-value"><?= number_format($pendingWithdrawals) ?></div>
            <div class="kpi-d-sub"><?= $pendingWithdrawals > 0 ? 'awaiting review' : 'all clear' ?></div>
          </div>
          <div class="kpi-d-icon"><i class="fas fa-clock"></i></div>
        </div>
      </div>
      <a href="index.php?page=pending_withdrawals" class="kpi-d-link">Review Pending <i class="fas fa-arrow-right"></i></a>
    </div>

  </div>

  <!-- ══════════════════════════════════════════
       ROW 2 — TICKET / SALES KPI CARDS
  ══════════════════════════════════════════ -->
  <div class="section-label"><i class="fas fa-ticket-alt"></i> Tickets & Sales</div>
  <div class="kpi-grid-dash">

    <div class="kpi-d c-violet">
      <div class="kpi-d-bar"></div>
      <div class="kpi-d-inner">
        <div class="kpi-d-top">
          <div>
            <div class="kpi-d-label">Ticket Revenue</div>
            <div class="kpi-d-value" style="font-size:1.4rem">KSh <?= number_format($ticketRevenue, 0) ?></div>
            <div class="kpi-d-sub">completed sales only</div>
          </div>
          <div class="kpi-d-icon"><i class="fas fa-ticket-alt"></i></div>
        </div>
      </div>
      <a href="index.php?page=all_tickets" class="kpi-d-link">View Purchases <i class="fas fa-arrow-right"></i></a>
    </div>

    <div class="kpi-d c-indigo">
      <div class="kpi-d-bar"></div>
      <div class="kpi-d-inner">
        <div class="kpi-d-top">
          <div>
            <div class="kpi-d-label">Tickets Sold</div>
            <div class="kpi-d-value"><?= number_format($ticketsSold) ?></div>
            <div class="kpi-d-sub">total units</div>
          </div>
          <div class="kpi-d-icon"><i class="fas fa-tags"></i></div>
        </div>
      </div>
      <a href="index.php?page=all_tickets" class="kpi-d-link">View All Tickets <i class="fas fa-arrow-right"></i></a>
    </div>

    <div class="kpi-d c-green">
      <div class="kpi-d-bar"></div>
      <div class="kpi-d-inner">
        <div class="kpi-d-top">
          <div>
            <div class="kpi-d-label">Completed Purchases</div>
            <div class="kpi-d-value"><?= number_format($ticketCompleted) ?></div>
            <div class="kpi-d-sub">
              <?= $totalPurchases > 0 ? round(($ticketCompleted/$totalPurchases)*100,1) : 0 ?>% success rate
            </div>
          </div>
          <div class="kpi-d-icon"><i class="fas fa-check-circle"></i></div>
        </div>
      </div>
      <a href="index.php?page=all_tickets" class="kpi-d-link">Sales Report <i class="fas fa-arrow-right"></i></a>
    </div>

    <?php
      $inactiveIssues = $warnedCount + $dueDeletionCount;
      $inactiveClass  = $dueDeletionCount > 0 ? 'c-red' : ($warnedCount > 0 ? 'c-amber' : 'c-green');
    ?>
    <div class="kpi-d <?= $inactiveClass ?>">
      <div class="kpi-d-bar"></div>
      <div class="kpi-d-inner">
        <div class="kpi-d-top">
          <div>
            <div class="kpi-d-label">Inactive Accounts</div>
            <div class="kpi-d-value"><?= number_format($inactiveIssues) ?></div>
            <div class="kpi-d-sub">
              <?= number_format($warnedCount) ?> warned · <?= number_format($dueDeletionCount) ?> due deletion
            </div>
          </div>
          <div class="kpi-d-icon"><i class="fas fa-user-clock"></i></div>
        </div>
      </div>
      <a href="index.php?page=all_creators" class="kpi-d-link">Manage Creators <i class="fas fa-arrow-right"></i></a>
    </div>

  </div>

  <!-- ══════════════════════════════════════════
       ROW 3 — MINI STATS STRIP
  ══════════════════════════════════════════ -->
  <div class="mini-stats">
    <div class="mini-stat">
      <div class="mini-dot" style="background:var(--emerald)"></div>
      <div class="mini-stat-body">
        <div class="mini-stat-label">New (30 days)</div>
        <div class="mini-stat-value" style="color:var(--emerald)"><?= number_format($newCreators30d) ?></div>
      </div>
    </div>
    <div class="mini-stat">
      <div class="mini-dot" style="background:var(--amber)"></div>
      <div class="mini-stat-body">
        <div class="mini-stat-label">Must Change PW</div>
        <div class="mini-stat-value" style="color:var(--amber)"><?= number_format($mustChangePw) ?></div>
      </div>
    </div>
    <div class="mini-stat">
      <div class="mini-dot" style="background:var(--red)"></div>
      <div class="mini-stat-body">
        <div class="mini-stat-label">Due Deletion</div>
        <div class="mini-stat-value" style="color:var(--red)"><?= number_format($dueDeletionCount) ?></div>
      </div>
    </div>
    <div class="mini-stat">
      <div class="mini-dot" style="background:var(--blue)"></div>
      <div class="mini-stat-body">
        <div class="mini-stat-label">Total Purchases</div>
        <div class="mini-stat-value" style="color:var(--blue)"><?= number_format($totalPurchases) ?></div>
      </div>
    </div>
  </div>

  <!-- ══════════════════════════════════════════
       ROW 4 — WEB VISITS KPI CARDS  ← NEW
  ══════════════════════════════════════════ -->
  <div class="section-label"><i class="fas fa-globe"></i> Website Traffic · MecroGuard</div>

  <div class="kpi-grid-visits">

    <!-- Total Views -->
    <div class="kpi-d c-teal" style="margin-bottom:0">
      <div class="kpi-d-bar"></div>
      <div class="kpi-d-inner">
        <div class="kpi-d-top">
          <div>
            <div class="kpi-d-label">Total Views</div>
            <div class="kpi-d-value"><?= number_format($visitTotal) ?></div>
            <div class="kpi-d-sub">all-time page views</div>
          </div>
          <div class="kpi-d-icon"><i class="fas fa-eye"></i></div>
        </div>
      </div>
      <div class="kpi-d-link" style="cursor:default">All Time <i class="fas fa-infinity"></i></div>
    </div>

    <!-- Today -->
    <div class="kpi-d c-cyan" style="margin-bottom:0">
      <div class="kpi-d-bar"></div>
      <div class="kpi-d-inner">
        <div class="kpi-d-top">
          <div>
            <div class="kpi-d-label">Today</div>
            <div class="kpi-d-value"><?= number_format($visitToday) ?></div>
            <div class="kpi-d-sub">
              <span class="live-dot"></span><?= number_format($visitUniqueToday) ?> unique IPs
            </div>
          </div>
          <div class="kpi-d-icon"><i class="fas fa-calendar-day"></i></div>
        </div>
      </div>
      <div class="kpi-d-link" style="cursor:default"><?= date('d M Y') ?> <i class="fas fa-circle-dot" style="color:var(--emerald)"></i></div>
    </div>

    <!-- This Week -->
    <div class="kpi-d c-sky" style="margin-bottom:0">
      <div class="kpi-d-bar"></div>
      <div class="kpi-d-inner">
        <div class="kpi-d-top">
          <div>
            <div class="kpi-d-label">This Week</div>
            <div class="kpi-d-value"><?= number_format($visitWeek) ?></div>
            <div class="kpi-d-sub">last 7 days</div>
          </div>
          <div class="kpi-d-icon"><i class="fas fa-calendar-week"></i></div>
        </div>
      </div>
      <div class="kpi-d-link" style="cursor:default">Rolling 7-day <i class="fas fa-rotate"></i></div>
    </div>

    <!-- This Month -->
    <div class="kpi-d c-indigo" style="margin-bottom:0">
      <div class="kpi-d-bar"></div>
      <div class="kpi-d-inner">
        <div class="kpi-d-top">
          <div>
            <div class="kpi-d-label">This Month</div>
            <div class="kpi-d-value"><?= number_format($visitMonth) ?></div>
            <div class="kpi-d-sub">last 30 days</div>
          </div>
          <div class="kpi-d-icon"><i class="fas fa-calendar-alt"></i></div>
        </div>
      </div>
      <div class="kpi-d-link" style="cursor:default">Rolling 30-day <i class="fas fa-rotate"></i></div>
    </div>

    <!-- Unique Visitors -->
    <div class="kpi-d c-violet" style="margin-bottom:0">
      <div class="kpi-d-bar"></div>
      <div class="kpi-d-inner">
        <div class="kpi-d-top">
          <div>
            <div class="kpi-d-label">Unique Visitors</div>
            <div class="kpi-d-value"><?= number_format($visitUnique) ?></div>
            <div class="kpi-d-sub">distinct IPs ever</div>
          </div>
          <div class="kpi-d-icon"><i class="fas fa-fingerprint"></i></div>
        </div>
      </div>
      <div class="kpi-d-link" style="cursor:default">All Time <i class="fas fa-infinity"></i></div>
    </div>

    <!-- Avg visits/visitor -->
    <?php $avgPerVisitor = $visitUnique > 0 ? round($visitTotal / $visitUnique, 1) : 0; ?>
    <div class="kpi-d c-orange" style="margin-bottom:0">
      <div class="kpi-d-bar"></div>
      <div class="kpi-d-inner">
        <div class="kpi-d-top">
          <div>
            <div class="kpi-d-label">Avg Views/Visitor</div>
            <div class="kpi-d-value"><?= $avgPerVisitor ?></div>
            <div class="kpi-d-sub">pages per unique IP</div>
          </div>
          <div class="kpi-d-icon"><i class="fas fa-chart-bar"></i></div>
        </div>
      </div>
      <div class="kpi-d-link" style="cursor:default">Engagement <i class="fas fa-fire"></i></div>
    </div>

  </div>

  <!-- ══════════════════════════════════════════
       ROW 5 — WEB VISIT INSIGHTS  ← NEW
  ══════════════════════════════════════════ -->
  <div class="visit-insight-row">

    <!-- Daily Traffic Chart (last 14 days) -->
    <div class="card-ui" style="margin-bottom:0;grid-column: span 1">
      <div class="card-head-ui">
        <div class="card-title-ui"><i class="fas fa-chart-area"></i> Daily Traffic</div>
        <span style="font-size:.72rem;color:var(--text-3)">Last 14 days</span>
      </div>
      <div class="card-body-ui">
        <?php if (!empty($dailyLabels)): ?>
          <canvas id="dailyVisitChart" height="160"></canvas>
        <?php else: ?>
          <div style="text-align:center;padding:40px;color:var(--text-3);font-size:.82rem">
            <i class="fas fa-chart-area" style="font-size:1.5rem;opacity:.3;display:block;margin-bottom:8px"></i>
            No visit data yet.
          </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Top Pages -->
    <div class="card-ui" style="margin-bottom:0">
      <div class="card-head-ui">
        <div class="card-title-ui"><i class="fas fa-file-alt"></i> Top Pages</div>
        <span style="font-size:.72rem;color:var(--text-3)">By total visits</span>
      </div>
      <div class="card-body-ui">
        <?php if (!empty($topPages)):
          $maxHits = max(array_column($topPages, 'hits')) ?: 1;
        ?>
          <div class="rank-list">
            <?php foreach ($topPages as $i => $pg):
              $pct = round(($pg['hits'] / $maxHits) * 100);
              $colors = ['var(--blue)','var(--violet)','var(--teal)','var(--orange)','var(--rose)'];
              $col = $colors[$i] ?? 'var(--blue)';
              // trim URL to just path
              $label = parse_url($pg['page_visited'], PHP_URL_PATH) ?: $pg['page_visited'];
              $label = strlen($label) > 40 ? substr($label, 0, 38) . '…' : $label;
            ?>
            <div class="rank-item">
              <div class="rank-num"><?= $i+1 ?></div>
              <div class="rank-bar-wrap">
                <div class="rank-label" title="<?= htmlspecialchars($pg['page_visited']) ?>">
                  <?= htmlspecialchars($label) ?>
                </div>
                <div class="rank-bar-bg">
                  <div class="rank-bar-fill" style="width:<?= $pct ?>%;background:<?= $col ?>"></div>
                </div>
              </div>
              <div class="rank-count"><?= number_format($pg['hits']) ?></div>
            </div>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <div style="text-align:center;padding:30px;color:var(--text-3);font-size:.82rem">No page data yet.</div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Top Countries + Devices stacked -->
    <div style="display:flex;flex-direction:column;gap:18px">

      <div class="card-ui" style="margin-bottom:0;flex:1">
        <div class="card-head-ui">
          <div class="card-title-ui"><i class="fas fa-globe-africa"></i> Top Countries</div>
          <span style="font-size:.72rem;color:var(--text-3)">Unique IPs</span>
        </div>
        <div class="card-body-ui" style="padding:14px 20px">
          <?php if (!empty($topCountries)):
            $maxUV = max(array_column($topCountries, 'uv')) ?: 1;
          ?>
            <div class="rank-list">
              <?php foreach ($topCountries as $i => $ctry):
                $pct = round(($ctry['uv'] / $maxUV) * 100);
                $cols2 = ['var(--teal)','var(--blue)','var(--indigo)','var(--orange)','var(--sky)'];
                $col2 = $cols2[$i] ?? 'var(--blue)';
              ?>
              <div class="rank-item">
                <div class="rank-num"><?= $i+1 ?></div>
                <div class="rank-bar-wrap">
                  <div class="rank-label"><?= htmlspecialchars($ctry['country']) ?></div>
                  <div class="rank-bar-bg">
                    <div class="rank-bar-fill" style="width:<?= $pct ?>%;background:<?= $col2 ?>"></div>
                  </div>
                </div>
                <div class="rank-count"><?= number_format($ctry['uv']) ?></div>
              </div>
              <?php endforeach; ?>
            </div>
          <?php else: ?>
            <div style="text-align:center;padding:20px;color:var(--text-3);font-size:.8rem">No country data yet.</div>
          <?php endif; ?>
        </div>
      </div>

      <div class="card-ui" style="margin-bottom:0;flex:1">
        <div class="card-head-ui">
          <div class="card-title-ui"><i class="fas fa-mobile-alt"></i> Devices</div>
          <span style="font-size:.72rem;color:var(--text-3)">By count</span>
        </div>
        <div class="card-body-ui" style="padding:14px 20px">
          <?php if (!empty($topDevices)):
            $maxDev = max(array_column($topDevices, 'cnt')) ?: 1;
            $devIcons = ['Desktop'=>'fa-desktop','Mobile'=>'fa-mobile-alt','iPhone'=>'fa-apple','Android Phone'=>'fa-android','Android Tablet'=>'fa-tablet-alt','iPad'=>'fa-tablet'];
          ?>
            <div class="rank-list">
              <?php foreach ($topDevices as $i => $dv):
                $pct = round(($dv['cnt'] / $maxDev) * 100);
                $icon = $devIcons[$dv['device']] ?? 'fa-device-mobile';
                $cols3 = ['var(--violet)','var(--emerald)','var(--amber)','var(--sky)','var(--rose)'];
                $col3 = $cols3[$i] ?? 'var(--blue)';
              ?>
              <div class="rank-item">
                <div class="rank-num"><i class="fas <?= $icon ?>" style="font-size:.6rem;color:var(--text-3)"></i></div>
                <div class="rank-bar-wrap">
                  <div class="rank-label"><?= htmlspecialchars($dv['device']) ?></div>
                  <div class="rank-bar-bg">
                    <div class="rank-bar-fill" style="width:<?= $pct ?>%;background:<?= $col3 ?>"></div>
                  </div>
                </div>
                <div class="rank-count"><?= number_format($dv['cnt']) ?></div>
              </div>
              <?php endforeach; ?>
            </div>
          <?php else: ?>
            <div style="text-align:center;padding:20px;color:var(--text-3);font-size:.8rem">No device data yet.</div>
          <?php endif; ?>
        </div>
      </div>

    </div>

  </div>

  <!-- ══════════════════════════════════════════
       ROW 6 — RECENT VISITORS TABLE  ← NEW
  ══════════════════════════════════════════ -->
  <div class="card-ui">
    <div class="card-head-ui">
      <div class="card-title-ui">
        <i class="fas fa-satellite-dish"></i> Recent Visitors
        <span style="font-weight:400;font-size:.73rem;color:var(--text-3)">(last 8 tracked IPs)</span>
      </div>
      <a href="index.php?page=web_visits" class="view-all-link">View all <i class="fas fa-arrow-right"></i></a>
    </div>
    <div style="overflow-x:auto">
      <table class="tbl-ui">
        <thead>
          <tr>
            <th>IP Address</th>
            <th>Location</th>
            <th>Device</th>
            <th>Browser / OS</th>
            <th>ISP</th>
            <th>Last Page</th>
            <th>Visits</th>
            <th>Last Seen</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!empty($recentVisitors)): ?>
            <?php foreach ($recentVisitors as $v):
              $page = parse_url($v['page_visited'], PHP_URL_PATH) ?: $v['page_visited'];
              $page = strlen($page) > 35 ? substr($page,0,33).'…' : $page;
            ?>
            <tr>
              <td>
                <span style="font-family:var(--mono);font-size:.75rem;font-weight:600;color:var(--navy)">
                  <?= htmlspecialchars($v['ip_address']) ?>
                </span>
              </td>
              <td>
                <div style="font-size:.79rem;font-weight:600"><?= htmlspecialchars($v['city'] ?? '—') ?></div>
                <div style="font-size:.67rem;color:var(--text-3)"><?= htmlspecialchars($v['country'] ?? '') ?></div>
              </td>
              <td>
                <span class="badge-ui <?= match(strtolower($v['device'] ?? '')) {
                  'desktop' => 'b-sky',
                  'iphone','android phone','mobile' => 'b-teal',
                  'ipad','android tablet' => 'b-amber',
                  default => 'b-gray'
                } ?>">
                  <i class="fas <?= match(strtolower($v['device'] ?? '')) {
                    'desktop' => 'fa-desktop',
                    'iphone' => 'fa-mobile-alt',
                    'android phone','mobile' => 'fa-mobile-alt',
                    'ipad','android tablet' => 'fa-tablet-alt',
                    default => 'fa-question'
                  } ?>" style="font-size:.55rem"></i>
                  <?= htmlspecialchars($v['device'] ?? '—') ?>
                </span>
              </td>
              <td>
                <div style="font-size:.77rem;font-weight:600"><?= htmlspecialchars($v['browser'] ?? '—') ?></div>
                <div style="font-size:.67rem;color:var(--text-3)"><?= htmlspecialchars($v['os'] ?? '') ?></div>
              </td>
              <td style="font-size:.73rem;color:var(--text-2);max-width:130px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                <?= htmlspecialchars($v['isp'] ?? '—') ?>
              </td>
              <td>
                <div style="font-family:var(--mono);font-size:.7rem;color:var(--text-2);max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"
                     title="<?= htmlspecialchars($v['page_visited']) ?>">
                  <?= htmlspecialchars($page) ?>
                </div>
              </td>
              <td style="text-align:center">
                <span style="font-weight:800;font-size:.85rem;color:var(--blue)"><?= number_format($v['visit_count']) ?></span>
              </td>
              <td style="font-size:.74rem;color:var(--text-3);white-space:nowrap"><?= timeAgo($v['last_visit']) ?></td>
            </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr class="empty-row">
              <td colspan="8">
                <i class="fas fa-satellite-dish" style="opacity:.3;display:block;font-size:1.5rem;margin:0 auto 8px"></i>
                No visitors tracked yet.
              </td>
            </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- ══════════════════════════════════════════
       ROW 7 — CHARTS (Creator Growth + Revenue)
  ══════════════════════════════════════════ -->
  <div class="section-label"><i class="fas fa-chart-line"></i> Growth & Revenue Charts</div>
  <div class="charts-row">

    <div class="card-ui">
      <div class="card-head-ui">
        <div class="card-title-ui">
          <i class="fas fa-chart-line"></i> Creator Registration Growth
          <span style="font-weight:400;font-size:.73rem;color:var(--text-3)">(last 13 months · live data)</span>
        </div>
      </div>
      <div class="card-body-ui">
        <?php if (!empty($growth_labels)): ?>
          <canvas id="creatorGrowthChart" height="120"></canvas>
        <?php else: ?>
          <div style="text-align:center;padding:40px;color:var(--text-3);font-size:.82rem">
            <i class="fas fa-chart-line" style="font-size:1.5rem;opacity:.3;display:block;margin-bottom:8px"></i>
            No registration data yet.
          </div>
        <?php endif; ?>
      </div>
    </div>

    <div class="card-ui">
      <div class="card-head-ui">
        <div class="card-title-ui"><i class="fas fa-chart-pie"></i> Revenue by Month</div>
      </div>
      <div class="card-body-ui">
        <?php if (!empty($rev_labels)): ?>
          <canvas id="revChart" height="180"></canvas>
        <?php else: ?>
          <div style="text-align:center;padding:40px;color:var(--text-3);font-size:.82rem">
            <i class="fas fa-ticket-alt" style="font-size:1.5rem;opacity:.3;display:block;margin-bottom:8px"></i>
            No revenue data yet.
          </div>
        <?php endif; ?>
      </div>
    </div>

  </div>

  <!-- ══════════════════════════════════════════
       ROW 8 — RECENT PURCHASES + RECENTLY JOINED
  ══════════════════════════════════════════ -->
  <div class="section-label"><i class="fas fa-receipt"></i> Recent Activity</div>
  <div class="bottom-row">

    <!-- Recent Purchases -->
    <div class="card-ui" style="margin-bottom:0">
      <div class="card-head-ui">
        <div class="card-title-ui"><i class="fas fa-receipt"></i> Recent Purchases</div>
        <a href="index.php?page=all_tickets" class="view-all-link">View all <i class="fas fa-arrow-right"></i></a>
      </div>
      <div style="overflow-x:auto">
        <table class="tbl-ui">
          <thead>
            <tr>
              <th>Buyer</th><th>Ticket</th><th>Amount</th><th>Status</th><th>Date</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!empty($recent_purchases)): ?>
              <?php foreach ($recent_purchases as $p):
                $sl = strtolower($p['status'] ?? '');
                $bc = match($sl) { 'completed'=>'b-green','pending'=>'b-amber','failed'=>'b-red',default=>'b-gray' };
                $bi = match($sl) { 'completed'=>'fa-check','pending'=>'fa-clock','failed'=>'fa-times',default=>'fa-circle' };
              ?>
              <tr>
                <td>
                  <div style="display:flex;align-items:center;gap:8px">
                    <div class="av"><?= initials($p['buyer_name'] ?? '?') ?></div>
                    <div>
                      <div style="font-weight:600;font-size:.8rem"><?= htmlspecialchars($p['buyer_name'] ?? '—') ?></div>
                      <div style="font-size:.67rem;color:var(--text-3);font-family:var(--mono)"><?= htmlspecialchars($p['buyer_email'] ?? '') ?></div>
                    </div>
                  </div>
                </td>
                <td>
                  <div style="font-weight:500;font-size:.8rem;max-width:130px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                    <?= htmlspecialchars($p['ticket_title'] ?? '—') ?>
                  </div>
                  <?php if (!empty($p['venue'])): ?>
                    <div style="font-size:.67rem;color:var(--text-3)"><?= htmlspecialchars($p['venue']) ?></div>
                  <?php endif; ?>
                </td>
                <td style="white-space:nowrap;font-weight:700;font-size:.8rem">KSh <?= number_format($p['amount_paid'] ?? 0, 2) ?></td>
                <td><span class="badge-ui <?= $bc ?>"><i class="fas <?= $bi ?>" style="font-size:.55rem"></i> <?= ucfirst($sl) ?></span></td>
                <td style="white-space:nowrap;font-size:.76rem;color:var(--text-3)"><?= timeAgo($p['created_at']) ?></td>
              </tr>
              <?php endforeach; ?>
            <?php else: ?>
              <tr class="empty-row"><td colspan="5"><i class="fas fa-receipt" style="opacity:.3;display:block;font-size:1.5rem;margin:0 auto 8px"></i>No purchases yet.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Recently Joined -->
    <div class="card-ui" style="margin-bottom:0">
      <div class="card-head-ui">
        <div class="card-title-ui"><i class="fas fa-user-plus"></i> Recently Joined</div>
        <a href="index.php?page=all_creators" class="view-all-link">View all <i class="fas fa-arrow-right"></i></a>
      </div>
      <div style="overflow-x:auto">
        <table class="tbl-ui">
          <thead>
            <tr><th>Creator</th><th>Joined</th><th>Last Login</th></tr>
          </thead>
          <tbody>
            <?php if (!empty($recent_creators)): ?>
              <?php foreach ($recent_creators as $c):
                $ll    = $c['last_login'] ?? null;
                $llStr = ($ll && $ll !== '0000-00-00 00:00:00')
                  ? timeAgo($ll)
                  : '<span style="color:var(--text-3);font-style:italic;font-size:.73rem">Never</span>';
              ?>
              <tr>
                <td>
                  <div style="display:flex;align-items:center;gap:9px">
                    <div class="av"><?= initials($c['full_name'] ?? '?') ?></div>
                    <div>
                      <div style="font-weight:600;font-size:.8rem"><?= htmlspecialchars($c['full_name'] ?? '—') ?></div>
                      <div style="font-size:.67rem;color:var(--text-3);font-family:var(--mono)">@<?= htmlspecialchars($c['username'] ?? '—') ?></div>
                    </div>
                  </div>
                </td>
                <td style="font-size:.76rem;color:var(--text-3);white-space:nowrap"><?= date('d M Y', strtotime($c['date_created'])) ?></td>
                <td style="font-size:.78rem"><?= $llStr ?></td>
              </tr>
              <?php endforeach; ?>
            <?php else: ?>
              <tr class="empty-row"><td colspan="3"><i class="fas fa-users" style="opacity:.3;display:block;font-size:1.5rem;margin:0 auto 8px"></i>No creators yet.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

  </div>

  <!-- ══════════════════════════════════════════
       ROW 9 — LOGIN ACTIVITY FEED + LOGIN LOGS
  ══════════════════════════════════════════ -->
  <div class="section-label"><i class="fas fa-shield-halved"></i> Security & Login</div>
  <div class="bottom-row" style="margin-bottom:22px">

    <!-- ── Login Activity Feed ── -->
    <div class="card-ui" style="margin-bottom:0">
      <div class="card-head-ui">
        <div class="card-title-ui">
          <i class="fas fa-user-check"></i> Creator Login Activity
        </div>
        <span style="font-size:.72rem;color:var(--text-3);font-weight:500">Last 10 successful logins</span>
      </div>

      <?php if (!empty($login_activity)): ?>
        <div class="activity-feed">
          <?php foreach ($login_activity as $a):
            $name    = $a['full_name'] ?? $a['email'];
            $uname   = !empty($a['username']) ? '@' . htmlspecialchars($a['username']) : htmlspecialchars($a['email']);
            $ago     = timeAgo($a['created_at']);
            $isRecent = (time() - strtotime($a['created_at'])) < 300;
          ?>
          <div class="activity-item">
            <div class="activity-av">
              <div class="av" style="width:36px;height:36px;font-size:.72rem;border-radius:10px">
                <?= initials($name) ?>
              </div>
              <?php if ($isRecent): ?>
                <div class="activity-online"></div>
              <?php endif; ?>
            </div>
            <div class="activity-body">
              <div class="activity-name">
                <?= htmlspecialchars($name) ?>
                <span>logged in</span>
              </div>
              <div class="activity-meta">
                <i class="fas fa-user-tag"></i> <?= $uname ?>
                &nbsp;·&nbsp;
                <i class="fas fa-network-wired"></i>
                <span style="font-family:var(--mono)"><?= htmlspecialchars($a['ip_address']) ?></span>
              </div>
            </div>
            <div class="activity-time">
              <i class="fas fa-clock" style="margin-right:3px;font-size:.6rem"></i><?= $ago ?>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <div style="text-align:center;padding:48px 20px;color:var(--text-3);font-size:.82rem">
          <i class="fas fa-user-check" style="font-size:1.5rem;opacity:.3;display:block;margin-bottom:8px"></i>
          No login activity recorded yet.
        </div>
      <?php endif; ?>
    </div>

    <!-- ── Login Log Summary ── -->
    <div class="card-ui" style="margin-bottom:0">
      <div class="card-head-ui">
        <div class="card-title-ui">
          <i class="fas fa-shield-halved"></i> Login Security Summary
        </div>
        <span style="font-size:.72rem;color:var(--text-3);font-weight:500">All-time · creator_logs</span>
      </div>
      <div class="card-body-ui">

        <div class="log-stats-strip">
          <div class="log-stat-pill">
            <div class="lsp-label">Today</div>
            <div class="lsp-value" style="color:var(--blue)"><?= number_format((int)$log_kpi['today_total']) ?></div>
          </div>
          <div class="log-stat-pill">
            <div class="lsp-label">Successes</div>
            <div class="lsp-value" style="color:var(--emerald)"><?= number_format((int)$log_kpi['total_success']) ?></div>
          </div>
          <div class="log-stat-pill">
            <div class="lsp-label">Failures</div>
            <div class="lsp-value" style="color:var(--red)"><?= number_format((int)$log_kpi['total_failed']) ?></div>
          </div>
          <div class="log-stat-pill">
            <div class="lsp-label">Lockouts</div>
            <div class="lsp-value" style="color:var(--orange)"><?= number_format((int)$log_kpi['total_locked']) ?></div>
          </div>
          <div class="log-stat-pill">
            <div class="lsp-label">CSRF Hits</div>
            <div class="lsp-value" style="color:var(--purple)"><?= number_format((int)$log_kpi['total_csrf']) ?></div>
          </div>
        </div>

        <?php if (!empty($login_logs)): ?>
          <div style="font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--text-3);margin-bottom:10px">
            Most Recent Events
          </div>
          <div style="display:flex;flex-direction:column;gap:6px;max-height:320px;overflow-y:auto;padding-right:4px">
            <?php foreach ($login_logs as $lg):
              $cfg  = eventConfig($lg['event_type']);
              $who  = !empty($lg['full_name']) ? htmlspecialchars($lg['full_name']) : htmlspecialchars($lg['email']);
            ?>
            <div style="display:flex;align-items:center;gap:10px;padding:8px 12px;background:var(--surface-2);border:1px solid var(--border);border-radius:var(--radius-sm);transition:background .12s"
                 onmouseover="this.style.background='var(--blue-ll)'" onmouseout="this.style.background='var(--surface-2)'">
              <div style="width:8px;height:8px;border-radius:50%;background:<?= $cfg['dot'] ?>;flex-shrink:0"></div>
              <div class="av av-sm"><?= initials($lg['full_name'] ?? $lg['email'] ?? '?') ?></div>
              <div style="flex:1;min-width:0">
                <div style="font-size:.78rem;font-weight:700;color:var(--navy);white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
                  <?= $who ?>
                </div>
                <div style="font-size:.67rem;color:var(--text-3);margin-top:1px">
                  <?php if (!empty($lg['failure_reason'])): ?>
                    <?= htmlspecialchars($lg['failure_reason']) ?>
                  <?php else: ?>
                    Login successful
                  <?php endif; ?>
                  <?php if ($lg['attempt_number'] > 0): ?>
                    &nbsp;· attempt #<?= (int)$lg['attempt_number'] ?>
                  <?php endif; ?>
                </div>
              </div>
              <span class="badge-ui <?= $cfg['cls'] ?>" style="flex-shrink:0">
                <i class="fas <?= $cfg['icon'] ?>" style="font-size:.55rem"></i>
                <?= $cfg['label'] ?>
              </span>
              <div style="font-size:.68rem;color:var(--text-3);white-space:nowrap;flex-shrink:0">
                <?= timeAgo($lg['created_at']) ?>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <div style="text-align:center;padding:30px;color:var(--text-3);font-size:.82rem">
            <i class="fas fa-shield-halved" style="font-size:1.4rem;opacity:.3;display:block;margin-bottom:8px"></i>
            No login events recorded yet.
          </div>
        <?php endif; ?>

      </div>
    </div>

  </div>

  <!-- ══════════════════════════════════════════
       ROW 10 — FULL LOGIN LOGS TABLE
  ══════════════════════════════════════════ -->
  <div class="card-ui">
    <div class="card-head-ui">
      <div class="card-title-ui">
        <i class="fas fa-list-check"></i> Login Audit Log
        <span style="font-weight:400;font-size:.73rem;color:var(--text-3)">(last 30 events · all types)</span>
      </div>
    </div>
    <div style="overflow-x:auto">
      <table class="tbl-ui">
        <thead>
          <tr>
            <th>#</th>
            <th>Creator</th>
            <th>Email</th>
            <th>Event</th>
            <th>Reason</th>
            <th>Attempt</th>
            <th>IP Address</th>
            <th>User Agent</th>
            <th>Time</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!empty($login_logs)): ?>
            <?php foreach ($login_logs as $i => $lg):
              $cfg = eventConfig($lg['event_type']);
              $who = !empty($lg['full_name']) ? htmlspecialchars($lg['full_name']) : '—';
            ?>
            <tr>
              <td style="font-size:.7rem;color:var(--text-3);font-family:var(--mono)"><?= $lg['id'] ?></td>
              <td>
                <div style="display:flex;align-items:center;gap:8px">
                  <div class="av av-sm"><?= initials($lg['full_name'] ?? $lg['email'] ?? '?') ?></div>
                  <div>
                    <div style="font-weight:600;font-size:.8rem"><?= $who ?></div>
                    <?php if (!empty($lg['username'])): ?>
                      <div style="font-size:.65rem;color:var(--text-3);font-family:var(--mono)">@<?= htmlspecialchars($lg['username']) ?></div>
                    <?php endif; ?>
                  </div>
                </div>
              </td>
              <td style="font-size:.73rem;font-family:var(--mono);color:var(--text-2)"><?= htmlspecialchars($lg['email']) ?></td>
              <td>
                <span class="badge-ui <?= $cfg['cls'] ?>">
                  <i class="fas <?= $cfg['icon'] ?>" style="font-size:.55rem"></i>
                  <?= $cfg['label'] ?>
                </span>
              </td>
              <td style="font-size:.75rem;color:var(--text-2);max-width:180px">
                <?= !empty($lg['failure_reason']) ? htmlspecialchars($lg['failure_reason']) : '<span style="color:var(--text-3)">—</span>' ?>
              </td>
              <td style="text-align:center;font-size:.8rem;font-family:var(--mono)">
                <?= $lg['attempt_number'] > 0
                  ? '<span style="color:var(--red);font-weight:700">#' . (int)$lg['attempt_number'] . '</span>'
                  : '<span style="color:var(--text-3)">—</span>' ?>
              </td>
              <td style="font-size:.72rem;font-family:var(--mono);color:var(--text-2);white-space:nowrap">
                <?= htmlspecialchars($lg['ip_address']) ?>
              </td>
              <td>
                <div class="ua-text" title="<?= htmlspecialchars($lg['user_agent'] ?? '') ?>">
                  <?= htmlspecialchars($lg['user_agent'] ?? '—') ?>
                </div>
              </td>
              <td style="white-space:nowrap;font-size:.74rem;color:var(--text-3)">
                <div><?= timeAgo($lg['created_at']) ?></div>
                <div style="font-size:.65rem;font-family:var(--mono);margin-top:1px"><?= date('d M y, H:i', strtotime($lg['created_at'])) ?></div>
              </td>
            </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr class="empty-row">
              <td colspan="9">
                <i class="fas fa-list-check" style="opacity:.3;display:block;font-size:1.5rem;margin:0 auto 8px"></i>
                No login events have been recorded yet.
              </td>
            </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

</div><!-- /dash-wrap -->

<script>
(function () {
  const FONT = "'Outfit', sans-serif";
  const GRID = 'rgba(13,27,62,0.06)';
  const TICK = '#8b95b8';

  /* ── Daily Visit Sparkline Chart ── */
  const dvc = document.getElementById('dailyVisitChart')?.getContext('2d');
  if (dvc) {
    new Chart(dvc, {
      type: 'bar',
      data: {
        labels: <?= json_encode($dailyLabels) ?>,
        datasets: [{
          label: 'Page Views',
          data: <?= json_encode($dailyValues) ?>,
          backgroundColor: (ctx) => {
            const last = ctx.chart.data.datasets[0].data.length - 1;
            return ctx.dataIndex === last ? 'rgba(13,148,136,1)' : 'rgba(13,148,136,0.35)';
          },
          borderColor: '#0d9488',
          borderWidth: 1,
          borderRadius: 5,
          borderSkipped: false
        }]
      },
      options: {
        responsive: true,
        plugins: {
          legend: { display: false },
          tooltip: {
            backgroundColor: '#0d1b3e',
            titleFont: { family: FONT, size: 11 },
            bodyFont: { family: FONT, size: 11 },
            padding: 9, cornerRadius: 8,
            callbacks: { label: ctx => ' ' + ctx.parsed.y.toLocaleString() + ' views' }
          }
        },
        scales: {
          x: { grid: { color: 'transparent' }, ticks: { font: { family: FONT, size: 9 }, color: TICK } },
          y: { grid: { color: GRID }, ticks: { font: { family: FONT, size: 9 }, color: TICK, precision: 0 } }
        }
      }
    });
  }

  /* ── Creator Growth Chart ── */
  const gc = document.getElementById('creatorGrowthChart')?.getContext('2d');
  if (gc) {
    new Chart(gc, {
      type: 'line',
      data: {
        labels: <?= json_encode($growth_labels) ?>,
        datasets: [
          {
            label: 'New Registrations',
            data: <?= json_encode($growth_values) ?>,
            borderColor: '#2563eb', backgroundColor: 'rgba(37,99,235,0.08)',
            tension: 0.4, fill: true, pointRadius: 4, pointHoverRadius: 6,
            borderWidth: 2, yAxisID: 'y'
          },
          {
            label: 'Total (Cumulative)',
            data: <?= json_encode($growth_cumulative) ?>,
            borderColor: '#059669', backgroundColor: 'rgba(5,150,105,0.05)',
            tension: 0.4, fill: false, pointRadius: 3, pointHoverRadius: 5,
            borderWidth: 2, borderDash: [5,4], yAxisID: 'y1'
          }
        ]
      },
      options: {
        responsive: true,
        interaction: { mode: 'index', intersect: false },
        plugins: {
          legend: { labels: { font: { family: FONT, size: 11 }, boxWidth: 10, padding: 16 } },
          tooltip: { backgroundColor: '#0d1b3e', titleFont: { family: FONT, size: 12 }, bodyFont: { family: FONT, size: 11 }, padding: 10, cornerRadius: 8 }
        },
        scales: {
          x: { grid: { color: GRID }, ticks: { font: { family: FONT, size: 10 }, color: TICK } },
          y: { position: 'left', grid: { color: GRID }, ticks: { font: { family: FONT, size: 10 }, color: TICK, precision: 0 }, title: { display: true, text: 'New', font: { family: FONT, size: 10 }, color: TICK } },
          y1: { position: 'right', grid: { drawOnChartArea: false }, ticks: { font: { family: FONT, size: 10 }, color: TICK }, title: { display: true, text: 'Total', font: { family: FONT, size: 10 }, color: TICK } }
        }
      }
    });
  }

  /* ── Revenue Bar Chart ── */
  const rc = document.getElementById('revChart')?.getContext('2d');
  if (rc) {
    new Chart(rc, {
      type: 'bar',
      data: {
        labels: <?= json_encode($rev_labels) ?>,
        datasets: [{
          label: 'Revenue (KSh)',
          data: <?= json_encode($rev_values) ?>,
          backgroundColor: 'rgba(124,58,237,0.8)',
          borderColor: '#7c3aed', borderWidth: 1, borderRadius: 6, borderSkipped: false
        }]
      },
      options: {
        responsive: true,
        plugins: {
          legend: { display: false },
          tooltip: {
            backgroundColor: '#0d1b3e', titleFont: { family: FONT, size: 12 },
            bodyFont: { family: FONT, size: 11 }, padding: 10, cornerRadius: 8,
            callbacks: { label: ctx => ' KSh ' + ctx.parsed.y.toLocaleString('en-KE', { minimumFractionDigits: 0 }) }
          }
        },
        scales: {
          x: { grid: { color: 'transparent' }, ticks: { font: { family: FONT, size: 10 }, color: TICK } },
          y: {
            grid: { color: GRID },
            ticks: { font: { family: FONT, size: 10 }, color: TICK, callback: v => 'KSh ' + Number(v).toLocaleString('en-KE') }
          }
        }
      }
    });
  }
})();
</script>