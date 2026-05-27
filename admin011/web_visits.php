<?php
include '../msanii/includes/connect.php';
if (session_status() === PHP_SESSION_NONE) session_start();

if (!isset($_SESSION['user_role']) || !in_array($_SESSION['user_role'], [1, 2])) {
    header("Location: ../login.php");
    exit();
}

// ── Pagination ───────────────────────────────────────
$perPage  = (int)($_GET['per_page'] ?? 25);
$perPage  = in_array($perPage, [10, 25, 50, 100]) ? $perPage : 25;
$page     = max(1, (int)($_GET['p'] ?? 1));
$offset   = ($page - 1) * $perPage;

// ── Filters ──────────────────────────────────────────
$filterCountry = trim($_GET['country'] ?? '');
$filterDevice  = trim($_GET['device']  ?? '');
$filterBrowser = trim($_GET['browser'] ?? '');
$filterSearch  = trim($_GET['q']       ?? '');
$filterRange   = $_GET['range']        ?? '30';   // days; 0 = all time

$rangeOptions  = ['1'=>'Today','7'=>'7 Days','30'=>'30 Days','90'=>'90 Days','0'=>'All Time'];
$filterRange   = array_key_exists($filterRange, $rangeOptions) ? $filterRange : '30';

// ── Build WHERE clause ───────────────────────────────
$where  = ['1=1'];
$params = [];

if ($filterRange !== '0') {
    $where[]   = 'last_visit >= DATE_SUB(NOW(), INTERVAL :days DAY)';
    $params[':days'] = (int)$filterRange;
}
if ($filterCountry !== '') {
    $where[]   = 'country = :country';
    $params[':country'] = $filterCountry;
}
if ($filterDevice !== '') {
    $where[]   = 'device = :device';
    $params[':device'] = $filterDevice;
}
if ($filterBrowser !== '') {
    $where[]   = 'browser LIKE :browser';
    $params[':browser'] = $filterBrowser . '%';
}
if ($filterSearch !== '') {
    $where[]   = '(ip_address LIKE :q OR city LIKE :q2 OR country LIKE :q3 OR isp LIKE :q4 OR page_visited LIKE :q5)';
    $params[':q']  = '%' . $filterSearch . '%';
    $params[':q2'] = '%' . $filterSearch . '%';
    $params[':q3'] = '%' . $filterSearch . '%';
    $params[':q4'] = '%' . $filterSearch . '%';
    $params[':q5'] = '%' . $filterSearch . '%';
}

$whereSQL = implode(' AND ', $where);

try {
    // ── KPI aggregates (filtered) ────────────────────
    $kpiStmt = $pdo->prepare("
        SELECT
            COUNT(*)                             AS total_rows,
            SUM(visit_count)                     AS total_views,
            COUNT(DISTINCT ip_address)           AS unique_ips,
            AVG(time_spent)                      AS avg_time,
            SUM(visit_count) / NULLIF(COUNT(DISTINCT ip_address),0) AS avg_views_per_ip,
            COUNT(DISTINCT country)              AS countries_count,
            COUNT(DISTINCT device)               AS device_types,
            SUM(CASE WHEN DATE(last_visit)=CURDATE() THEN visit_count ELSE 0 END) AS today_views,
            SUM(CASE WHEN last_visit >= DATE_SUB(NOW(),INTERVAL 7 DAY)  THEN visit_count ELSE 0 END) AS week_views,
            SUM(CASE WHEN last_visit >= DATE_SUB(NOW(),INTERVAL 30 DAY) THEN visit_count ELSE 0 END) AS month_views
        FROM web_visits
        WHERE $whereSQL
    ");
    $kpiStmt->execute($params);
    $kpi = $kpiStmt->fetch(PDO::FETCH_ASSOC);

    // ── Global total from singleton ──────────────────
    $globalTotal = (int)$pdo->query("SELECT COALESCE(total_views,0) FROM web_visit_total WHERE id=1")->fetchColumn();

    // ── Total count for pagination ───────────────────
    $cntStmt = $pdo->prepare("SELECT COUNT(*) FROM web_visits WHERE $whereSQL");
    $cntStmt->execute($params);
    $totalRows = (int)$cntStmt->fetchColumn();
    $totalPages = max(1, ceil($totalRows / $perPage));

    // ── Paginated records ────────────────────────────
    $dataStmt = $pdo->prepare("
        SELECT id, ip_address, device, os, browser, isp,
               location, city, region, country,
               page_visited, time_spent, visit_count,
               first_visit, last_visit, date_recorded
        FROM web_visits
        WHERE $whereSQL
        ORDER BY last_visit DESC
        LIMIT :limit OFFSET :offset
    ");
    foreach ($params as $k => $v) $dataStmt->bindValue($k, $v);
    $dataStmt->bindValue(':limit',  $perPage, PDO::PARAM_INT);
    $dataStmt->bindValue(':offset', $offset,  PDO::PARAM_INT);
    $dataStmt->execute();
    $rows = $dataStmt->fetchAll(PDO::FETCH_ASSOC);

    // ── Chart: daily views last 30 days ─────────────
    $dailyStmt = $pdo->prepare("
        SELECT DATE_FORMAT(date_recorded,'%d %b') AS label,
               DATE(date_recorded)                AS sort_d,
               SUM(visit_count)                   AS hits,
               COUNT(DISTINCT ip_address)         AS uv
        FROM web_visits
        WHERE date_recorded >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)
        GROUP BY DATE(date_recorded), DATE_FORMAT(date_recorded,'%d %b')
        ORDER BY sort_d ASC
    ");
    $dailyStmt->execute();
    $daily = $dailyStmt->fetchAll(PDO::FETCH_ASSOC);
    $dailyLabels = array_column($daily, 'label');
    $dailyViews  = array_map('intval', array_column($daily, 'hits'));
    $dailyUV     = array_map('intval', array_column($daily, 'uv'));

    // ── Top Pages ────────────────────────────────────
    $topPagesStmt = $pdo->prepare("
        SELECT page_visited, SUM(visit_count) AS hits, COUNT(DISTINCT ip_address) AS uv
        FROM web_visits WHERE $whereSQL
        GROUP BY page_visited ORDER BY hits DESC LIMIT 10
    ");
    $topPagesStmt->execute($params);
    $topPages = $topPagesStmt->fetchAll(PDO::FETCH_ASSOC);

    // ── Top Countries ────────────────────────────────
    $topCountriesStmt = $pdo->prepare("
        SELECT country, COUNT(DISTINCT ip_address) AS uv, SUM(visit_count) AS hits
        FROM web_visits WHERE $whereSQL AND country != 'Unknown'
        GROUP BY country ORDER BY uv DESC LIMIT 10
    ");
    $topCountriesStmt->execute($params);
    $topCountries = $topCountriesStmt->fetchAll(PDO::FETCH_ASSOC);

    // ── Device breakdown ────────────────────────────
    $devStmt = $pdo->prepare("
        SELECT device, COUNT(*) AS cnt, SUM(visit_count) AS views
        FROM web_visits WHERE $whereSQL
        GROUP BY device ORDER BY cnt DESC
    ");
    $devStmt->execute($params);
    $devices = $devStmt->fetchAll(PDO::FETCH_ASSOC);
    $devLabels = array_column($devices, 'device');
    $devCounts = array_map('intval', array_column($devices, 'cnt'));

    // ── Browser breakdown ────────────────────────────
    $brStmt = $pdo->prepare("
        SELECT
            SUBSTRING_INDEX(browser, ' ', 1) AS browser_name,
            COUNT(*) AS cnt
        FROM web_visits WHERE $whereSQL
        GROUP BY browser_name ORDER BY cnt DESC LIMIT 8
    ");
    $brStmt->execute($params);
    $browsers = $brStmt->fetchAll(PDO::FETCH_ASSOC);
    $brLabels = array_column($browsers, 'browser_name');
    $brCounts = array_map('intval', array_column($browsers, 'cnt'));

    // ── OS breakdown ─────────────────────────────────
    $osStmt = $pdo->prepare("
        SELECT
            SUBSTRING_INDEX(os, ' ', 1) AS os_name,
            COUNT(*) AS cnt
        FROM web_visits WHERE $whereSQL
        GROUP BY os_name ORDER BY cnt DESC LIMIT 8
    ");
    $osStmt->execute($params);
    $osList = $osStmt->fetchAll(PDO::FETCH_ASSOC);

    // ── Filter dropdown options ──────────────────────
    $countries = $pdo->query("SELECT DISTINCT country FROM web_visits WHERE country != 'Unknown' ORDER BY country")->fetchAll(PDO::FETCH_COLUMN);
    $devOpts   = $pdo->query("SELECT DISTINCT device  FROM web_visits ORDER BY device")->fetchAll(PDO::FETCH_COLUMN);
    $brOpts    = $pdo->query("SELECT DISTINCT SUBSTRING_INDEX(browser,' ',1) AS b FROM web_visits ORDER BY b")->fetchAll(PDO::FETCH_COLUMN);

    // ── Hourly heatmap (hour of day, day of week) ────
    $heatStmt = $pdo->query("
        SELECT HOUR(last_visit) AS hr, DAYOFWEEK(last_visit)-1 AS dow, SUM(visit_count) AS hits
        FROM web_visits
        WHERE last_visit >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        GROUP BY hr, dow
    ");
    $heatRaw = $heatStmt->fetchAll(PDO::FETCH_ASSOC);
    // Build 7×24 matrix [dow][hr]
    $heatMatrix = array_fill(0, 7, array_fill(0, 24, 0));
    foreach ($heatRaw as $h) {
        $heatMatrix[(int)$h['dow']][(int)$h['hr']] = (int)$h['hits'];
    }
    $heatMax = max(1, max(array_map('max', $heatMatrix)));

} catch (PDOException $e) {
    $error_message = "Database error: " . htmlspecialchars($e->getMessage());
    $kpi = ['total_rows'=>0,'total_views'=>0,'unique_ips'=>0,'avg_time'=>0,'avg_views_per_ip'=>0,
            'countries_count'=>0,'device_types'=>0,'today_views'=>0,'week_views'=>0,'month_views'=>0];
    $globalTotal = 0; $totalRows = 0; $totalPages = 1; $rows = [];
    $dailyLabels = $dailyViews = $dailyUV = [];
    $topPages = $topCountries = $devices = $browsers = $osList = [];
    $devLabels = $devCounts = $brLabels = $brCounts = [];
    $countries = $devOpts = $brOpts = [];
    $heatMatrix = array_fill(0, 7, array_fill(0, 24, 0));
    $heatMax = 1;
}

// ── Helpers ──────────────────────────────────────────
function timeAgo(?string $d): string {
    if (!$d || $d === '0000-00-00 00:00:00') return '—';
    $diff = time() - strtotime($d);
    if ($diff < 60)     return 'Just now';
    if ($diff < 3600)   return floor($diff/60)    . 'm ago';
    if ($diff < 86400)  return floor($diff/3600)  . 'h ago';
    if ($diff < 604800) return floor($diff/86400) . 'd ago';
    return date('d M Y', strtotime($d));
}

function fmtTime(int $secs): string {
    if ($secs < 60)   return $secs . 's';
    if ($secs < 3600) return floor($secs/60) . 'm ' . ($secs%60) . 's';
    return floor($secs/3600) . 'h ' . floor(($secs%3600)/60) . 'm';
}

function shortUrl(string $url, int $len = 45): string {
    $path = parse_url($url, PHP_URL_PATH) ?: $url;
    $qs   = parse_url($url, PHP_URL_QUERY);
    $full = $path . ($qs ? '?'.$qs : '');
    return strlen($full) > $len ? substr($full, 0, $len-1) . '…' : $full;
}

$dowLabels = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Web Visits — Keychele Admin</title>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<style>
/* ══════════════════════════════════════════
   DESIGN TOKENS — identical to dashboard
══════════════════════════════════════════ */
:root {
  --bg:          #f0f2f8;
  --surface:     #ffffff;
  --surface-2:   #f7f8fc;
  --border:      #e4e8f0;

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
  --teal:        #0d9488;
  --teal-l:      #ccfbf1;
  --rose:        #e11d48;
  --rose-l:      #ffe4e6;
  --cyan:        #0891b2;
  --cyan-l:      #cffafe;
  --purple:      #9333ea;
  --purple-l:    #f3e8ff;

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

*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body { background: var(--bg); font-family: var(--font); color: var(--text); }

/* ── WRAP ── */
.wv-wrap { padding: 28px; max-width: 1680px; margin: 0 auto; }

/* ── PAGE HEADER ── */
.wv-header { margin-bottom: 28px; display: flex; align-items: flex-start; justify-content: space-between; flex-wrap: wrap; gap: 14px; }
.wv-header-left h1 {
  font-size: 1.55rem; font-weight: 800; letter-spacing: -.03em;
  color: var(--navy); display: flex; align-items: center; gap: 10px;
}
.wv-header-left h1 .icon-wrap {
  width: 38px; height: 38px; background: var(--teal); border-radius: 10px;
  display: inline-flex; align-items: center; justify-content: center;
  font-size: .85rem; color: #fff; flex-shrink: 0;
}
.wv-header-left p { color: var(--text-2); font-size: .82rem; margin-top: 3px; padding-left: 48px; }
.wv-back-btn {
  display: inline-flex; align-items: center; gap: 7px;
  padding: 9px 18px; background: var(--surface); border: 1px solid var(--border);
  border-radius: var(--radius-sm); font-size: .8rem; font-weight: 600;
  color: var(--text-2); text-decoration: none; transition: all .18s;
  font-family: var(--font);
}
.wv-back-btn:hover { background: var(--blue-ll); color: var(--blue); border-color: var(--blue-l); }

/* ── SECTION LABEL ── */
.section-label {
  display: flex; align-items: center; gap: 10px;
  font-size: .72rem; font-weight: 800; text-transform: uppercase;
  letter-spacing: .1em; color: var(--text-3); margin-bottom: 14px; margin-top: 6px;
}
.section-label::after { content: ''; flex: 1; height: 1px; background: var(--border); }
.section-label i { color: var(--teal); font-size: .65rem; }

/* ── KPI CARDS ── */
.kpi-grid-4 { display: grid; grid-template-columns: repeat(4,1fr); gap: 16px; margin-bottom: 16px; }
.kpi-grid-4b{ display: grid; grid-template-columns: repeat(4,1fr); gap: 16px; margin-bottom: 26px; }
@media(max-width:1100px){ .kpi-grid-4,.kpi-grid-4b { grid-template-columns: repeat(2,1fr); } }
@media(max-width:540px) { .kpi-grid-4,.kpi-grid-4b { grid-template-columns: 1fr; } }

.kpi-d { border-radius: var(--radius); overflow: hidden; box-shadow: var(--shadow-sm);
  border: 1px solid var(--border); transition: transform .2s, box-shadow .2s; }
.kpi-d:hover { transform: translateY(-3px); box-shadow: var(--shadow); }
.kpi-d-bar  { height: 4px; }
.kpi-d-inner{ padding: 20px 22px 18px; background: var(--surface); }
.kpi-d-top  { display: flex; align-items: flex-start; justify-content: space-between; gap: 10px; }
.kpi-d-icon { width: 42px; height: 42px; border-radius: 11px; display: flex; align-items: center; justify-content: center; font-size: .9rem; flex-shrink: 0; }
.kpi-d-label{ font-size: .7rem; font-weight: 700; text-transform: uppercase; letter-spacing: .08em; color: var(--text-3); margin-bottom: 4px; }
.kpi-d-value{ font-size: 1.9rem; font-weight: 800; line-height: 1; letter-spacing: -.04em; }
.kpi-d-sub  { font-size: .73rem; color: var(--text-3); margin-top: 6px; }

.c-teal   .kpi-d-bar{background:var(--teal);}    .c-teal   .kpi-d-icon{background:var(--teal-l);color:var(--teal);}    .c-teal   .kpi-d-value{color:var(--teal);}
.c-cyan   .kpi-d-bar{background:var(--cyan);}    .c-cyan   .kpi-d-icon{background:var(--cyan-l);color:var(--cyan);}    .c-cyan   .kpi-d-value{color:var(--cyan);}
.c-blue   .kpi-d-bar{background:var(--blue);}    .c-blue   .kpi-d-icon{background:var(--blue-l);color:var(--blue);}    .c-blue   .kpi-d-value{color:var(--blue);}
.c-indigo .kpi-d-bar{background:var(--indigo);}  .c-indigo .kpi-d-icon{background:var(--indigo-l);color:var(--indigo);}.c-indigo .kpi-d-value{color:var(--indigo);}
.c-violet .kpi-d-bar{background:var(--violet);}  .c-violet .kpi-d-icon{background:var(--violet-l);color:var(--violet);}.c-violet .kpi-d-value{color:var(--violet);}
.c-emerald.kpi-d-bar{background:var(--emerald);} .c-emerald.kpi-d-icon{background:var(--emerald-l);color:var(--emerald);}.c-emerald.kpi-d-value{color:var(--emerald);}
.c-amber  .kpi-d-bar{background:var(--amber);}   .c-amber  .kpi-d-icon{background:var(--amber-l);color:var(--amber);}  .c-amber  .kpi-d-value{color:var(--amber);}
.c-orange .kpi-d-bar{background:var(--orange);}  .c-orange .kpi-d-icon{background:var(--orange-l);color:var(--orange);}.c-orange .kpi-d-value{color:var(--orange);}

/* ── CARD ── */
.card-ui { background: var(--surface); border-radius: var(--radius); box-shadow: var(--shadow-sm); border: 1px solid var(--border); overflow: hidden; margin-bottom: 22px; }
.card-head-ui { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 16px 20px; border-bottom: 1px solid var(--border); background: var(--surface-2); flex-wrap: wrap; }
.card-title-ui { font-size: .92rem; font-weight: 700; color: var(--navy); display: flex; align-items: center; gap: 8px; }
.card-title-ui i { color: var(--teal); font-size: .8rem; }
.card-body-ui { padding: 20px; }

/* ── FILTER BAR ── */
.filter-bar {
  display: flex; align-items: center; gap: 10px; flex-wrap: wrap;
  background: var(--surface); border: 1px solid var(--border);
  border-radius: var(--radius); padding: 14px 18px; margin-bottom: 22px;
  box-shadow: var(--shadow-sm);
}
.filter-bar select,
.filter-bar input[type=text] {
  height: 36px; padding: 0 12px; border: 1px solid var(--border);
  border-radius: var(--radius-sm); font-family: var(--font); font-size: .8rem;
  color: var(--text); background: var(--surface-2); outline: none;
  transition: border-color .15s, box-shadow .15s;
}
.filter-bar select:focus,
.filter-bar input[type=text]:focus { border-color: var(--teal); box-shadow: 0 0 0 3px rgba(13,148,136,.1); }
.filter-bar .search-wrap { position: relative; flex: 1; min-width: 180px; }
.filter-bar .search-wrap i { position: absolute; left: 10px; top: 50%; transform: translateY(-50%); color: var(--text-3); font-size: .75rem; pointer-events: none; }
.filter-bar .search-wrap input { width: 100%; padding-left: 30px; }
.filter-btn {
  height: 36px; padding: 0 18px; border-radius: var(--radius-sm);
  font-family: var(--font); font-size: .8rem; font-weight: 600;
  cursor: pointer; border: none; transition: all .15s;
}
.filter-btn.apply { background: var(--teal); color: #fff; }
.filter-btn.apply:hover { background: #0f766e; }
.filter-btn.reset { background: var(--surface-2); color: var(--text-2); border: 1px solid var(--border); }
.filter-btn.reset:hover { background: var(--red-l); color: var(--red); border-color: var(--red); }
.filter-label { font-size: .72rem; font-weight: 700; color: var(--text-3); white-space: nowrap; text-transform: uppercase; letter-spacing: .06em; }

/* ── RANGE TABS ── */
.range-tabs { display: flex; gap: 4px; background: var(--surface-2); border: 1px solid var(--border); border-radius: var(--radius-sm); padding: 3px; }
.range-tab {
  padding: 5px 14px; border-radius: 7px; font-size: .76rem; font-weight: 600;
  cursor: pointer; color: var(--text-3); text-decoration: none; transition: all .15s;
  white-space: nowrap;
}
.range-tab.active { background: var(--teal); color: #fff; box-shadow: 0 1px 4px rgba(13,148,136,.3); }
.range-tab:hover:not(.active) { background: var(--border); color: var(--text); }

/* ── TWO-COL LAYOUT ── */
.two-col { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 22px; }
.three-col { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 20px; margin-bottom: 22px; }
@media(max-width:960px) { .two-col,.three-col { grid-template-columns: 1fr; } }

/* ── TABLE ── */
.tbl-ui { width: 100%; border-collapse: collapse; }
.tbl-ui thead th {
  padding: 9px 14px; background: var(--surface-2); border-bottom: 1px solid var(--border);
  font-size: .67rem; font-weight: 700; text-transform: uppercase; letter-spacing: .08em;
  color: var(--text-3); white-space: nowrap; text-align: left;
}
.tbl-ui tbody tr { border-bottom: 1px solid var(--border); transition: background .1s; }
.tbl-ui tbody tr:last-child { border-bottom: none; }
.tbl-ui tbody tr:hover { background: var(--blue-ll); }
.tbl-ui td { padding: 10px 14px; font-size: .81rem; color: var(--text); vertical-align: middle; }

/* ── BADGES ── */
.badge-ui { display: inline-flex; align-items: center; gap: 3px; padding: 2px 9px; border-radius: 100px; font-size: .66rem; font-weight: 700; letter-spacing: .02em; white-space: nowrap; }
.b-teal   { background: var(--teal-l);   color: #134e4a; }
.b-cyan   { background: var(--cyan-l);   color: #075985; }
.b-sky    { background: var(--sky-l);    color: #075985; }
.b-blue   { background: var(--blue-l);   color: #1e3a8a; }
.b-indigo { background: var(--indigo-l); color: #312e81; }
.b-violet { background: var(--violet-l); color: #4c1d95; }
.b-green  { background: var(--emerald-l);color: #065f46; }
.b-amber  { background: var(--amber-l);  color: #92400e; }
.b-orange { background: var(--orange-l); color: #9a3412; }
.b-gray   { background: var(--bg);       color: var(--text-2); }
.b-rose   { background: var(--rose-l);   color: #9f1239; }

/* ── RANK LIST ── */
.rank-list { display: flex; flex-direction: column; gap: 10px; }
.rank-item { display: flex; align-items: center; gap: 10px; }
.rank-num { width: 22px; height: 22px; border-radius: 6px; background: var(--surface-2); border: 1px solid var(--border); font-size: .65rem; font-weight: 800; color: var(--text-3); display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.rank-bar-wrap { flex: 1; min-width: 0; }
.rank-label { font-size: .76rem; font-weight: 600; color: var(--text); margin-bottom: 3px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 100%; }
.rank-sub   { font-size: .64rem; color: var(--text-3); margin-top: 1px; }
.rank-bar-bg { height: 5px; border-radius: 10px; background: var(--border); overflow: hidden; }
.rank-bar-fill { height: 100%; border-radius: 10px; transition: width .7s cubic-bezier(.34,1.56,.64,1); }
.rank-count { font-size: .72rem; font-weight: 700; color: var(--text-2); white-space: nowrap; flex-shrink: 0; text-align: right; min-width: 42px; }

/* ── PAGINATION ── */
.pagination { display: flex; align-items: center; gap: 4px; flex-wrap: wrap; }
.pg-btn {
  height: 32px; min-width: 32px; padding: 0 10px;
  border-radius: var(--radius-sm); border: 1px solid var(--border);
  background: var(--surface); font-family: var(--font); font-size: .78rem;
  font-weight: 600; color: var(--text-2); cursor: pointer; text-decoration: none;
  display: inline-flex; align-items: center; justify-content: center;
  transition: all .15s;
}
.pg-btn:hover   { background: var(--blue-ll); color: var(--blue); border-color: var(--blue-l); }
.pg-btn.active  { background: var(--teal); color: #fff; border-color: var(--teal); }
.pg-btn.disabled{ opacity: .4; pointer-events: none; }

/* ── HEATMAP ── */
.heatmap-wrap { overflow-x: auto; }
.heatmap-grid { display: grid; grid-template-columns: 38px repeat(24, 1fr); gap: 3px; min-width: 700px; }
.hm-hour-labels { display: grid; grid-template-columns: 38px repeat(24, 1fr); gap: 3px; margin-bottom: 4px; min-width: 700px; }
.hm-hour-label { font-size: .6rem; color: var(--text-3); text-align: center; font-weight: 600; font-family: var(--mono); }
.hm-dow { font-size: .68rem; font-weight: 700; color: var(--text-3); display: flex; align-items: center; height: 20px; }
.hm-cell { height: 20px; border-radius: 3px; transition: transform .1s; cursor: default; }
.hm-cell:hover { transform: scale(1.2); z-index: 2; position: relative; }

/* ── ALERT ── */
.alert-err { background: var(--red-l); border: 1px solid #fca5a5; border-radius: var(--radius-sm); padding: 12px 16px; color: #991b1b; font-size: .84rem; margin-bottom: 20px; display: flex; align-items: center; gap: 8px; }

/* ── LIVE DOT ── */
.live-dot { width: 7px; height: 7px; border-radius: 50%; background: var(--emerald); display: inline-block; animation: livePulse 1.6s ease-in-out infinite; margin-right: 4px; }
@keyframes livePulse { 0%,100%{opacity:1;transform:scale(1);} 50%{opacity:.4;transform:scale(.7);} }

/* ── EMPTY ── */
.empty-row td { text-align: center; padding: 50px 20px; color: var(--text-3); font-size: .82rem; }

/* ── STAT CHIP ── */
.stat-chip { display: inline-flex; align-items: center; gap: 5px; padding: 4px 12px; border-radius: 100px; background: var(--surface-2); border: 1px solid var(--border); font-size: .72rem; font-weight: 600; color: var(--text-2); }

/* ── DONUT LEGEND ── */
.donut-legend { display: flex; flex-direction: column; gap: 7px; flex: 1; }
.dl-item { display: flex; align-items: center; gap: 8px; }
.dl-dot  { width: 10px; height: 10px; border-radius: 3px; flex-shrink: 0; }
.dl-label{ font-size: .76rem; font-weight: 600; color: var(--text); flex: 1; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.dl-val  { font-size: .72rem; font-weight: 700; color: var(--text-2); }

/* ── SCROLL TABLE ── */
.tbl-scroll { overflow-x: auto; }

/* ── TOOLTIPPED ── */
[data-tip] { position: relative; cursor: help; }
[data-tip]:hover::after {
  content: attr(data-tip);
  position: absolute; bottom: calc(100% + 6px); left: 50%; transform: translateX(-50%);
  background: var(--navy); color: #fff; font-size: .68rem; font-family: var(--font);
  padding: 4px 10px; border-radius: 6px; white-space: nowrap; z-index: 100;
  pointer-events: none;
}

@media(max-width:680px) { .wv-wrap { padding: 14px; } }
</style>
</head>
<body>
<div class="wv-wrap">

  <!-- ── PAGE HEADER ── -->
  <div class="wv-header">
    <div class="wv-header-left">
      <h1>
        <span class="icon-wrap"><i class="fas fa-satellite-dish"></i></span>
        Web Visits
      </h1>
      <p>
        MecroGuard traffic analytics · <?= date('l, d F Y') ?>
        &nbsp;·&nbsp;
        <span class="live-dot"></span>
        <span style="font-size:.78rem;color:var(--teal);font-weight:600"><?= number_format($globalTotal) ?> total all-time views</span>
      </p>
    </div>
    <a href="index.php" class="wv-back-btn"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
  </div>

  <?php if (isset($error_message)): ?>
    <div class="alert-err"><i class="fas fa-exclamation-circle"></i><?= $error_message ?></div>
  <?php endif; ?>

  <!-- ── RANGE TABS ── -->
  <div style="display:flex;align-items:center;gap:12px;margin-bottom:18px;flex-wrap:wrap">
    <span class="filter-label"><i class="fas fa-clock" style="color:var(--teal)"></i> Period</span>
    <div class="range-tabs">
      <?php foreach ($rangeOptions as $val => $lbl): ?>
        <a href="?page=web_visits&range=<?= $val ?>&country=<?= urlencode($filterCountry) ?>&device=<?= urlencode($filterDevice) ?>&browser=<?= urlencode($filterBrowser) ?>&q=<?= urlencode($filterSearch) ?>"
           class="range-tab <?= $filterRange === $val ? 'active' : '' ?>">
          <?= $lbl ?>
        </a>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- ── FILTER BAR ── -->
  <form method="GET" action="">
    <input type="hidden" name="page"  value="web_visits">
    <input type="hidden" name="range" value="<?= htmlspecialchars($filterRange) ?>">
    <div class="filter-bar">
      <span class="filter-label"><i class="fas fa-filter"></i> Filter</span>

      <div class="search-wrap">
        <i class="fas fa-search"></i>
        <input type="text" name="q" placeholder="IP, city, country, ISP, page…" value="<?= htmlspecialchars($filterSearch) ?>">
      </div>

      <select name="country">
        <option value="">All Countries</option>
        <?php foreach ($countries as $c): ?>
          <option value="<?= htmlspecialchars($c) ?>" <?= $filterCountry===$c?'selected':'' ?>><?= htmlspecialchars($c) ?></option>
        <?php endforeach; ?>
      </select>

      <select name="device">
        <option value="">All Devices</option>
        <?php foreach ($devOpts as $d): ?>
          <option value="<?= htmlspecialchars($d) ?>" <?= $filterDevice===$d?'selected':'' ?>><?= htmlspecialchars($d) ?></option>
        <?php endforeach; ?>
      </select>

      <select name="browser">
        <option value="">All Browsers</option>
        <?php foreach ($brOpts as $b): ?>
          <option value="<?= htmlspecialchars($b) ?>" <?= $filterBrowser===$b?'selected':'' ?>><?= htmlspecialchars($b) ?></option>
        <?php endforeach; ?>
      </select>

      <select name="per_page">
        <?php foreach ([10,25,50,100] as $n): ?>
          <option value="<?= $n ?>" <?= $perPage===$n?'selected':'' ?>><?= $n ?> / page</option>
        <?php endforeach; ?>
      </select>

      <button type="submit" class="filter-btn apply"><i class="fas fa-search"></i> Apply</button>
      <a href="?page=web_visits" class="filter-btn reset"><i class="fas fa-times"></i> Reset</a>
    </div>
  </form>

  <!-- ══════════════════════════════════════════
       ROW 1 — PRIMARY KPIs
  ══════════════════════════════════════════ -->
  <div class="section-label"><i class="fas fa-eye"></i> Traffic Summary</div>
  <div class="kpi-grid-4" style="margin-bottom:16px">

    <div class="kpi-d c-teal">
      <div class="kpi-d-bar"></div>
      <div class="kpi-d-inner">
        <div class="kpi-d-top">
          <div>
            <div class="kpi-d-label">Total Page Views</div>
            <div class="kpi-d-value"><?= number_format((int)$kpi['total_views']) ?></div>
            <div class="kpi-d-sub"><?= number_format($totalRows) ?> tracked IP rows</div>
          </div>
          <div class="kpi-d-icon"><i class="fas fa-eye"></i></div>
        </div>
      </div>
    </div>

    <div class="kpi-d c-cyan">
      <div class="kpi-d-bar"></div>
      <div class="kpi-d-inner">
        <div class="kpi-d-top">
          <div>
            <div class="kpi-d-label">Today</div>
            <div class="kpi-d-value"><?= number_format((int)$kpi['today_views']) ?></div>
            <div class="kpi-d-sub"><span class="live-dot"></span>live count</div>
          </div>
          <div class="kpi-d-icon"><i class="fas fa-calendar-day"></i></div>
        </div>
      </div>
    </div>

    <div class="kpi-d c-blue">
      <div class="kpi-d-bar"></div>
      <div class="kpi-d-inner">
        <div class="kpi-d-top">
          <div>
            <div class="kpi-d-label">This Week</div>
            <div class="kpi-d-value"><?= number_format((int)$kpi['week_views']) ?></div>
            <div class="kpi-d-sub">rolling 7 days</div>
          </div>
          <div class="kpi-d-icon"><i class="fas fa-calendar-week"></i></div>
        </div>
      </div>
    </div>

    <div class="kpi-d c-indigo">
      <div class="kpi-d-bar"></div>
      <div class="kpi-d-inner">
        <div class="kpi-d-top">
          <div>
            <div class="kpi-d-label">This Month</div>
            <div class="kpi-d-value"><?= number_format((int)$kpi['month_views']) ?></div>
            <div class="kpi-d-sub">rolling 30 days</div>
          </div>
          <div class="kpi-d-icon"><i class="fas fa-calendar-alt"></i></div>
        </div>
      </div>
    </div>

  </div>
  <div class="kpi-grid-4b">

    <div class="kpi-d c-violet">
      <div class="kpi-d-bar"></div>
      <div class="kpi-d-inner">
        <div class="kpi-d-top">
          <div>
            <div class="kpi-d-label">Unique Visitors</div>
            <div class="kpi-d-value"><?= number_format((int)$kpi['unique_ips']) ?></div>
            <div class="kpi-d-sub">distinct IP addresses</div>
          </div>
          <div class="kpi-d-icon"><i class="fas fa-fingerprint"></i></div>
        </div>
      </div>
    </div>

    <div class="kpi-d c-emerald">
      <div class="kpi-d-bar"></div>
      <div class="kpi-d-inner">
        <div class="kpi-d-top">
          <div>
            <div class="kpi-d-label">Avg Views / IP</div>
            <div class="kpi-d-value"><?= round((float)$kpi['avg_views_per_ip'], 1) ?></div>
            <div class="kpi-d-sub">engagement depth</div>
          </div>
          <div class="kpi-d-icon"><i class="fas fa-chart-bar"></i></div>
        </div>
      </div>
    </div>

    <div class="kpi-d c-amber">
      <div class="kpi-d-bar"></div>
      <div class="kpi-d-inner">
        <div class="kpi-d-top">
          <div>
            <div class="kpi-d-label">Avg Time on Site</div>
            <div class="kpi-d-value" style="font-size:1.4rem"><?= fmtTime((int)round((float)$kpi['avg_time'])) ?></div>
            <div class="kpi-d-sub">per session</div>
          </div>
          <div class="kpi-d-icon"><i class="fas fa-hourglass-half"></i></div>
        </div>
      </div>
    </div>

    <div class="kpi-d c-orange">
      <div class="kpi-d-bar"></div>
      <div class="kpi-d-inner">
        <div class="kpi-d-top">
          <div>
            <div class="kpi-d-label">Countries Reached</div>
            <div class="kpi-d-value"><?= number_format((int)$kpi['countries_count']) ?></div>
            <div class="kpi-d-sub"><?= number_format((int)$kpi['device_types']) ?> device types</div>
          </div>
          <div class="kpi-d-icon"><i class="fas fa-globe"></i></div>
        </div>
      </div>
    </div>

  </div>

  <!-- ══════════════════════════════════════════
       ROW 2 — DAILY TRAFFIC CHART (full width)
  ══════════════════════════════════════════ -->
  <div class="section-label"><i class="fas fa-chart-area"></i> Daily Traffic — Last 30 Days</div>
  <div class="card-ui">
    <div class="card-head-ui">
      <div class="card-title-ui"><i class="fas fa-chart-area"></i> Page Views & Unique Visitors</div>
      <div style="display:flex;gap:14px;align-items:center;flex-wrap:wrap">
        <span class="stat-chip" style="background:rgba(13,148,136,.08);border-color:rgba(13,148,136,.2);color:var(--teal)"><i class="fas fa-square" style="font-size:.5rem"></i> Page Views</span>
        <span class="stat-chip" style="background:rgba(8,145,178,.08);border-color:rgba(8,145,178,.2);color:var(--cyan)"><i class="fas fa-circle" style="font-size:.5rem"></i> Unique IPs</span>
      </div>
    </div>
    <div class="card-body-ui">
      <?php if (!empty($dailyLabels)): ?>
        <canvas id="dailyChart" height="80"></canvas>
      <?php else: ?>
        <div style="text-align:center;padding:60px;color:var(--text-3);font-size:.85rem">
          <i class="fas fa-chart-area" style="font-size:2rem;opacity:.2;display:block;margin-bottom:10px"></i>No traffic data yet.
        </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- ══════════════════════════════════════════
       ROW 3 — TOP PAGES + TOP COUNTRIES
  ══════════════════════════════════════════ -->
  <div class="section-label"><i class="fas fa-list-ol"></i> Top Pages & Countries</div>
  <div class="two-col">

    <!-- Top Pages -->
    <div class="card-ui" style="margin-bottom:0">
      <div class="card-head-ui">
        <div class="card-title-ui"><i class="fas fa-file-alt"></i> Top Pages</div>
        <span style="font-size:.72rem;color:var(--text-3)">By total views</span>
      </div>
      <div class="card-body-ui">
        <?php if (!empty($topPages)):
          $maxH = max(array_column($topPages,'hits')) ?: 1;
          $pColors = ['var(--teal)','var(--blue)','var(--violet)','var(--cyan)','var(--orange)','var(--indigo)','var(--emerald)','var(--amber)','var(--rose)','var(--sky)'];
        ?>
          <div class="rank-list">
            <?php foreach ($topPages as $i => $pg):
              $col  = $pColors[$i % count($pColors)];
              $pct  = round(($pg['hits'] / $maxH) * 100);
              $lbl  = shortUrl($pg['page_visited'], 50);
            ?>
            <div class="rank-item">
              <div class="rank-num"><?= $i+1 ?></div>
              <div class="rank-bar-wrap">
                <div class="rank-label" title="<?= htmlspecialchars($pg['page_visited']) ?>"><?= htmlspecialchars($lbl) ?></div>
                <div style="display:flex;align-items:center;gap:6px;margin-top:2px">
                  <div class="rank-bar-bg" style="flex:1"><div class="rank-bar-fill" style="width:<?= $pct ?>%;background:<?= $col ?>"></div></div>
                </div>
                <div class="rank-sub"><?= number_format($pg['uv']) ?> unique IPs</div>
              </div>
              <div class="rank-count" style="color:<?= $col ?>"><?= number_format($pg['hits']) ?></div>
            </div>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <div style="text-align:center;padding:40px;color:var(--text-3);font-size:.82rem">No page data.</div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Top Countries -->
    <div class="card-ui" style="margin-bottom:0">
      <div class="card-head-ui">
        <div class="card-title-ui"><i class="fas fa-globe-africa"></i> Top Countries</div>
        <span style="font-size:.72rem;color:var(--text-3)">Unique IPs</span>
      </div>
      <div class="card-body-ui">
        <?php if (!empty($topCountries)):
          $maxC = max(array_column($topCountries,'uv')) ?: 1;
          $cColors = ['var(--teal)','var(--cyan)','var(--sky)','var(--blue)','var(--indigo)','var(--violet)','var(--orange)','var(--amber)','var(--rose)','var(--emerald)'];
        ?>
          <div class="rank-list">
            <?php foreach ($topCountries as $i => $ct):
              $col = $cColors[$i % count($cColors)];
              $pct = round(($ct['uv'] / $maxC) * 100);
            ?>
            <div class="rank-item">
              <div class="rank-num"><?= $i+1 ?></div>
              <div class="rank-bar-wrap">
                <div class="rank-label"><?= htmlspecialchars($ct['country']) ?></div>
                <div class="rank-bar-bg"><div class="rank-bar-fill" style="width:<?= $pct ?>%;background:<?= $col ?>"></div></div>
                <div class="rank-sub"><?= number_format($ct['hits']) ?> total views</div>
              </div>
              <div class="rank-count" style="color:<?= $col ?>"><?= number_format($ct['uv']) ?> IPs</div>
            </div>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <div style="text-align:center;padding:40px;color:var(--text-3);font-size:.82rem">No country data.</div>
        <?php endif; ?>
      </div>
    </div>

  </div>

  <!-- ══════════════════════════════════════════
       ROW 4 — DEVICE + BROWSER + OS DONUTS
  ══════════════════════════════════════════ -->
  <div class="section-label"><i class="fas fa-mobile-alt"></i> Device, Browser & OS Breakdown</div>
  <div class="three-col">

    <!-- Device Donut -->
    <div class="card-ui" style="margin-bottom:0">
      <div class="card-head-ui">
        <div class="card-title-ui"><i class="fas fa-mobile-screen-button"></i> Devices</div>
      </div>
      <div class="card-body-ui" style="display:flex;align-items:center;gap:18px;flex-wrap:wrap">
        <div style="width:130px;height:130px;flex-shrink:0;position:relative">
          <canvas id="devDonut"></canvas>
        </div>
        <div class="donut-legend">
          <?php
          $devPalette = ['#0d9488','#0891b2','#4f46e5','#d97706','#e11d48','#059669','#7c3aed','#ea580c'];
          foreach ($devices as $i => $dv):
            $col = $devPalette[$i % count($devPalette)];
          ?>
          <div class="dl-item">
            <div class="dl-dot" style="background:<?= $col ?>"></div>
            <div class="dl-label"><?= htmlspecialchars($dv['device']) ?></div>
            <div class="dl-val"><?= number_format($dv['cnt']) ?></div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <!-- Browser Donut -->
    <div class="card-ui" style="margin-bottom:0">
      <div class="card-head-ui">
        <div class="card-title-ui"><i class="fas fa-globe"></i> Browsers</div>
      </div>
      <div class="card-body-ui" style="display:flex;align-items:center;gap:18px;flex-wrap:wrap">
        <div style="width:130px;height:130px;flex-shrink:0;position:relative">
          <canvas id="brDonut"></canvas>
        </div>
        <div class="donut-legend">
          <?php
          $brPalette = ['#2563eb','#0d9488','#7c3aed','#ea580c','#059669','#d97706','#0891b2','#e11d48'];
          foreach ($browsers as $i => $br):
            $col = $brPalette[$i % count($brPalette)];
          ?>
          <div class="dl-item">
            <div class="dl-dot" style="background:<?= $col ?>"></div>
            <div class="dl-label"><?= htmlspecialchars($br['browser_name']) ?></div>
            <div class="dl-val"><?= number_format($br['cnt']) ?></div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <!-- OS Donut -->
    <div class="card-ui" style="margin-bottom:0">
      <div class="card-head-ui">
        <div class="card-title-ui"><i class="fas fa-laptop-code"></i> Operating Systems</div>
      </div>
      <div class="card-body-ui" style="display:flex;align-items:center;gap:18px;flex-wrap:wrap">
        <div style="width:130px;height:130px;flex-shrink:0;position:relative">
          <canvas id="osDonut"></canvas>
        </div>
        <div class="donut-legend">
          <?php
          $osPalette = ['#4f46e5','#0d9488','#ea580c','#d97706','#2563eb','#059669','#7c3aed','#0891b2'];
          foreach ($osList as $i => $os):
            $col = $osPalette[$i % count($osPalette)];
          ?>
          <div class="dl-item">
            <div class="dl-dot" style="background:<?= $col ?>"></div>
            <div class="dl-label"><?= htmlspecialchars($os['os_name']) ?></div>
            <div class="dl-val"><?= number_format($os['cnt']) ?></div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

  </div>

  <!-- ══════════════════════════════════════════
       ROW 5 — HOURLY HEATMAP
  ══════════════════════════════════════════ -->
  <div class="section-label"><i class="fas fa-th"></i> Visit Heatmap — Hour of Day × Day of Week (Last 30 Days)</div>
  <div class="card-ui">
    <div class="card-head-ui">
      <div class="card-title-ui"><i class="fas fa-fire-flame-curved"></i> Traffic Intensity by Hour</div>
      <span style="font-size:.72rem;color:var(--text-3)">Darker = more visits</span>
    </div>
    <div class="card-body-ui">
      <div class="heatmap-wrap">
        <!-- Hour labels -->
        <div class="hm-hour-labels">
          <div></div>
          <?php for ($h = 0; $h < 24; $h++): ?>
            <div class="hm-hour-label"><?= str_pad($h, 2, '0', STR_PAD_LEFT) ?></div>
          <?php endfor; ?>
        </div>
        <!-- Grid -->
        <div class="heatmap-grid">
          <?php foreach ($dowLabels as $di => $dlbl): ?>
            <div class="hm-dow"><?= $dlbl ?></div>
            <?php for ($h = 0; $h < 24; $h++):
              $val = $heatMatrix[$di][$h];
              $intensity = $val > 0 ? max(0.08, min(1, $val / $heatMax)) : 0;
              $alpha = round($intensity * 255);
              $bg = $val > 0
                ? 'rgba(13,148,136,' . round($intensity, 2) . ')'
                : 'rgba(228,232,240,0.5)';
              $tip = "{$dlbl} {$h}:00 — {$val} visits";
            ?>
              <div class="hm-cell" style="background:<?= $bg ?>" data-tip="<?= $tip ?>"></div>
            <?php endfor; ?>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>

  <!-- ══════════════════════════════════════════
       ROW 6 — FULL DATA TABLE
  ══════════════════════════════════════════ -->
  <div class="section-label"><i class="fas fa-table"></i> All Visits Log</div>
  <div class="card-ui">
    <div class="card-head-ui">
      <div class="card-title-ui">
        <i class="fas fa-database"></i> Visitor Records
        <span style="font-weight:400;font-size:.73rem;color:var(--text-3)">
          (<?= number_format($totalRows) ?> rows · page <?= $page ?> of <?= $totalPages ?>)
        </span>
      </div>
      <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
        <span class="stat-chip"><i class="fas fa-filter" style="font-size:.6rem;color:var(--teal)"></i>
          <?= $filterRange === '0' ? 'All Time' : $rangeOptions[$filterRange] ?>
          <?= $filterCountry ? ' · '.$filterCountry : '' ?>
          <?= $filterDevice  ? ' · '.$filterDevice  : '' ?>
          <?= $filterSearch  ? ' · "'.$filterSearch.'"' : '' ?>
        </span>
      </div>
    </div>

    <div class="tbl-scroll">
      <table class="tbl-ui">
        <thead>
          <tr>
            <th>#</th>
            <th>IP Address</th>
            <th>Location</th>
            <th>Device</th>
            <th>Browser</th>
            <th>OS</th>
            <th>ISP</th>
            <th>Last Page Visited</th>
            <th style="text-align:center">Visits</th>
            <th style="text-align:center">Time Spent</th>
            <th>First Visit</th>
            <th>Last Visit</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!empty($rows)):
            $rowNum = $offset + 1;
            foreach ($rows as $r):
              $page_lbl = shortUrl($r['page_visited'], 42);
              $devBadge = match(strtolower($r['device'] ?? '')) {
                'desktop'                     => ['b-sky',   'fa-desktop',    'Desktop'],
                'iphone','mobile'             => ['b-teal',  'fa-mobile-alt', $r['device']],
                'android phone'               => ['b-teal',  'fa-mobile-alt', 'Android Phone'],
                'android tablet','ipad'       => ['b-amber', 'fa-tablet-alt', $r['device']],
                default                       => ['b-gray',  'fa-question',   $r['device'] ?: '—']
              };
          ?>
          <tr>
            <td style="font-size:.7rem;color:var(--text-3);font-family:var(--mono)"><?= $rowNum++ ?></td>
            <td>
              <span style="font-family:var(--mono);font-size:.75rem;font-weight:700;color:var(--navy)">
                <?= htmlspecialchars($r['ip_address']) ?>
              </span>
            </td>
            <td>
              <div style="font-weight:600;font-size:.8rem"><?= htmlspecialchars($r['city'] ?? '—') ?></div>
              <div style="font-size:.67rem;color:var(--text-3)"><?= htmlspecialchars($r['region'] ?? '') ?><?= !empty($r['region']) && !empty($r['country']) ? ', ' : '' ?><?= htmlspecialchars($r['country'] ?? '') ?></div>
            </td>
            <td>
              <span class="badge-ui <?= $devBadge[0] ?>">
                <i class="fas <?= $devBadge[1] ?>" style="font-size:.55rem"></i>
                <?= htmlspecialchars($devBadge[2]) ?>
              </span>
            </td>
            <td style="font-size:.78rem;font-weight:600"><?= htmlspecialchars($r['browser'] ?? '—') ?></td>
            <td style="font-size:.75rem;color:var(--text-2)"><?= htmlspecialchars($r['os'] ?? '—') ?></td>
            <td style="font-size:.72rem;color:var(--text-2);max-width:130px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
              <span title="<?= htmlspecialchars($r['isp'] ?? '') ?>"><?= htmlspecialchars($r['isp'] ?? '—') ?></span>
            </td>
            <td>
              <div style="font-family:var(--mono);font-size:.7rem;color:var(--text-2);max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"
                   title="<?= htmlspecialchars($r['page_visited']) ?>">
                <?= htmlspecialchars($page_lbl) ?>
              </div>
            </td>
            <td style="text-align:center">
              <span style="font-weight:800;font-size:.88rem;color:var(--teal)"><?= number_format((int)$r['visit_count']) ?></span>
            </td>
            <td style="text-align:center;font-size:.76rem;font-family:var(--mono);color:var(--text-2)">
              <?= fmtTime((int)$r['time_spent']) ?>
            </td>
            <td style="font-size:.72rem;color:var(--text-3);white-space:nowrap">
              <?= $r['first_visit'] && $r['first_visit'] !== '0000-00-00 00:00:00'
                ? date('d M y, H:i', strtotime($r['first_visit']))
                : '—' ?>
            </td>
            <td style="white-space:nowrap">
              <div style="font-size:.76rem;font-weight:600;color:var(--text)"><?= timeAgo($r['last_visit']) ?></div>
              <div style="font-size:.64rem;color:var(--text-3);font-family:var(--mono);margin-top:1px">
                <?= date('d M y, H:i', strtotime($r['last_visit'])) ?>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php else: ?>
          <tr class="empty-row">
            <td colspan="12">
              <i class="fas fa-satellite-dish" style="opacity:.2;display:block;font-size:2rem;margin:0 auto 10px"></i>
              No visits found matching the current filters.
            </td>
          </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
    <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;padding:14px 20px;border-top:1px solid var(--border);background:var(--surface-2);flex-wrap:wrap">
      <div style="font-size:.76rem;color:var(--text-3)">
        Showing <strong style="color:var(--text)"><?= number_format($offset+1) ?>–<?= number_format(min($offset+$perPage,$totalRows)) ?></strong>
        of <strong style="color:var(--text)"><?= number_format($totalRows) ?></strong> records
      </div>
      <div class="pagination">
        <?php
          $baseUrl = '?page=web_visits&range='.$filterRange.'&country='.urlencode($filterCountry).'&device='.urlencode($filterDevice).'&browser='.urlencode($filterBrowser).'&q='.urlencode($filterSearch).'&per_page='.$perPage;
          // Prev
          if ($page > 1):
        ?>
          <a href="<?= $baseUrl ?>&p=<?= $page-1 ?>" class="pg-btn"><i class="fas fa-chevron-left" style="font-size:.6rem"></i></a>
        <?php else: ?>
          <span class="pg-btn disabled"><i class="fas fa-chevron-left" style="font-size:.6rem"></i></span>
        <?php endif; ?>

        <?php
          // Page numbers
          $start = max(1, $page - 2);
          $end   = min($totalPages, $page + 2);
          if ($start > 1): ?>
            <a href="<?= $baseUrl ?>&p=1" class="pg-btn">1</a>
            <?php if ($start > 2): ?><span class="pg-btn disabled" style="border:none;background:none">…</span><?php endif; ?>
          <?php endif;
          for ($i = $start; $i <= $end; $i++): ?>
            <a href="<?= $baseUrl ?>&p=<?= $i ?>" class="pg-btn <?= $i===$page?'active':'' ?>"><?= $i ?></a>
          <?php endfor;
          if ($end < $totalPages):
            if ($end < $totalPages - 1): ?><span class="pg-btn disabled" style="border:none;background:none">…</span><?php endif; ?>
            <a href="<?= $baseUrl ?>&p=<?= $totalPages ?>" class="pg-btn"><?= $totalPages ?></a>
          <?php endif; ?>

        <?php if ($page < $totalPages): ?>
          <a href="<?= $baseUrl ?>&p=<?= $page+1 ?>" class="pg-btn"><i class="fas fa-chevron-right" style="font-size:.6rem"></i></a>
        <?php else: ?>
          <span class="pg-btn disabled"><i class="fas fa-chevron-right" style="font-size:.6rem"></i></span>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

  </div><!-- /card-ui -->

</div><!-- /wv-wrap -->

<script>
(function(){
  const FONT = "'Outfit', sans-serif";
  const GRID = 'rgba(13,27,62,0.06)';
  const TICK = '#8b95b8';

  /* ── Daily Traffic Chart ── */
  const dc = document.getElementById('dailyChart')?.getContext('2d');
  if (dc) {
    new Chart(dc, {
      type: 'bar',
      data: {
        labels: <?= json_encode($dailyLabels) ?>,
        datasets: [
          {
            label: 'Page Views',
            data: <?= json_encode($dailyViews) ?>,
            backgroundColor: 'rgba(13,148,136,0.75)',
            borderColor: '#0d9488',
            borderWidth: 1, borderRadius: 5, borderSkipped: false,
            yAxisID: 'y'
          },
          {
            label: 'Unique IPs',
            data: <?= json_encode($dailyUV) ?>,
            type: 'line',
            borderColor: '#0891b2',
            backgroundColor: 'rgba(8,145,178,0.08)',
            tension: 0.4, fill: true,
            pointRadius: 3, pointHoverRadius: 5,
            borderWidth: 2,
            yAxisID: 'y1'
          }
        ]
      },
      options: {
        responsive: true,
        interaction: { mode: 'index', intersect: false },
        plugins: {
          legend: { labels: { font: { family: FONT, size: 11 }, boxWidth: 10, padding: 16 } },
          tooltip: {
            backgroundColor: '#0d1b3e',
            titleFont: { family: FONT, size: 12 },
            bodyFont: { family: FONT, size: 11 },
            padding: 10, cornerRadius: 8
          }
        },
        scales: {
          x: { grid: { color: 'transparent' }, ticks: { font: { family: FONT, size: 9 }, color: TICK, maxRotation: 45 } },
          y:  { position: 'left',  grid: { color: GRID }, ticks: { font: { family: FONT, size: 10 }, color: TICK, precision: 0 }, title: { display: true, text: 'Views', font: { family: FONT, size: 10 }, color: TICK } },
          y1: { position: 'right', grid: { drawOnChartArea: false }, ticks: { font: { family: FONT, size: 10 }, color: TICK, precision: 0 }, title: { display: true, text: 'Unique IPs', font: { family: FONT, size: 10 }, color: TICK } }
        }
      }
    });
  }

  /* ── Donut helper ── */
  function donut(id, labels, data, colors) {
    const ctx = document.getElementById(id)?.getContext('2d');
    if (!ctx || !data.length) return;
    new Chart(ctx, {
      type: 'doughnut',
      data: {
        labels,
        datasets: [{ data, backgroundColor: colors, borderWidth: 2, borderColor: '#fff', hoverOffset: 6 }]
      },
      options: {
        cutout: '68%',
        plugins: {
          legend: { display: false },
          tooltip: {
            backgroundColor: '#0d1b3e',
            titleFont: { family: FONT, size: 11 },
            bodyFont:  { family: FONT, size: 11 },
            padding: 8, cornerRadius: 8
          }
        }
      }
    });
  }

  donut('devDonut',
    <?= json_encode($devLabels) ?>,
    <?= json_encode($devCounts) ?>,
    ['#0d9488','#0891b2','#4f46e5','#d97706','#e11d48','#059669','#7c3aed','#ea580c']
  );

  donut('brDonut',
    <?= json_encode($brLabels) ?>,
    <?= json_encode($brCounts) ?>,
    ['#2563eb','#0d9488','#7c3aed','#ea580c','#059669','#d97706','#0891b2','#e11d48']
  );

  donut('osDonut',
    <?= json_encode(array_column($osList,'os_name')) ?>,
    <?= json_encode(array_map('intval',array_column($osList,'cnt'))) ?>,
    ['#4f46e5','#0d9488','#ea580c','#d97706','#2563eb','#059669','#7c3aed','#0891b2']
  );

})();
</script>
</body>
</html>