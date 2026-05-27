<?php
// all_creators.php — fixed tabs + manual reminder feature
include '../msanii/includes/connect.php';
if (session_status() === PHP_SESSION_NONE) session_start();

if (!isset($_SESSION['user_role']) || !in_array($_SESSION['user_role'], [1, 2])) {
    header("Location: ../login.php");
    exit();
}

// ── Params ────────────────────────────────────────────────────
$allowed_per_page = [10, 20, 50, 100];
$per_page         = (int)($_GET['per_page'] ?? 20);
if (!in_array($per_page, $allowed_per_page)) $per_page = 20;
$current_page     = max(1, (int)($_GET['p'] ?? 1));
$search_q         = trim($_GET['q'] ?? '');

// Preserve the dashboard page= param in all URLs
$dash_page = isset($_GET['page']) ? $_GET['page'] : null;

function buildUrl(array $params, ?string $dashPage): string {
    if ($dashPage !== null) $params['page'] = $dashPage;
    return '?' . http_build_query($params);
}

try {
    // ── Inactivity settings ──────────────────────────────────
    $settings = $pdo->query("SELECT * FROM inactivity_settings LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$settings) {
        $settings = [
            'inactivity_days'         => 30,
            'grace_period_days'       => 7,
            'auto_delete_enabled'     => 1,
            'talksasa_api_key'        => '',
            'talksasa_sender_id'      => 'KEYCHELE',
            'smtp_from_email'         => 'noreply@keychele.com',
            'smtp_from_name'          => 'Keychele Platform',
            'warning_email_subject'   => 'Action Required: Your account will be deleted',
        ];
    }

    // ── KPI counts (all rows) ────────────────────────────────
    $kpi = $pdo->query("
        SELECT
            COUNT(*) AS total,
            SUM(must_change_password = 1) AS must_change,
            SUM(inactivity_warning_sent_at IS NOT NULL) AS warned,
            SUM(scheduled_delete_date IS NOT NULL AND scheduled_delete_date <= CURDATE()) AS due_deletion
        FROM creators
    ")->fetch(PDO::FETCH_ASSOC);

    $total_creators     = (int)$kpi['total'];
    $must_change_count  = (int)$kpi['must_change'];
    $warned_count       = (int)$kpi['warned'];
    $due_deletion_count = (int)$kpi['due_deletion'];

    // ── Filtered count ───────────────────────────────────────
    if ($search_q !== '') {
        $like = '%' . $search_q . '%';
        $cnt  = $pdo->prepare("SELECT COUNT(*) FROM creators WHERE full_name LIKE :q OR email LIKE :q OR phone LIKE :q OR username LIKE :q");
        $cnt->execute([':q' => $like]);
    } else {
        $cnt = $pdo->query("SELECT COUNT(*) FROM creators");
    }
    $filtered_total = (int)$cnt->fetchColumn();
    $total_pages    = max(1, (int)ceil($filtered_total / $per_page));
    $current_page   = min($current_page, $total_pages);
    $offset         = ($current_page - 1) * $per_page;

    // ── Fetch page ───────────────────────────────────────────
    if ($search_q !== '') {
        $stmt = $pdo->prepare("
            SELECT id, full_name, username, phone, email,
                   date_created, must_change_password, updated_at,
                   last_login, inactivity_warning_sent_at, scheduled_delete_date
            FROM creators
            WHERE full_name LIKE :q OR email LIKE :q OR phone LIKE :q OR username LIKE :q
            ORDER BY date_created DESC
            LIMIT :lim OFFSET :off
        ");
        $stmt->bindValue(':q',   '%' . $search_q . '%');
        $stmt->bindValue(':lim', $per_page, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset,   PDO::PARAM_INT);
        $stmt->execute();
    } else {
        $stmt = $pdo->prepare("
            SELECT id, full_name, username, phone, email,
                   date_created, must_change_password, updated_at,
                   last_login, inactivity_warning_sent_at, scheduled_delete_date
            FROM creators
            ORDER BY date_created DESC
            LIMIT :lim OFFSET :off
        ");
        $stmt->bindValue(':lim', $per_page, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset,   PDO::PARAM_INT);
        $stmt->execute();
    }
    $creators = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ── Audit log ────────────────────────────────────────────
    $recent_log = $pdo->query("SELECT * FROM inactivity_log ORDER BY actioned_at DESC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $error = "Database error: " . htmlspecialchars($e->getMessage());
    $creators = []; $recent_log = []; $total_pages = 1; $filtered_total = 0;
    $total_creators = $must_change_count = $warned_count = $due_deletion_count = 0;
    $settings = [
        'inactivity_days'       => 30,
        'grace_period_days'     => 7,
        'auto_delete_enabled'   => 1,
        'talksasa_api_key'      => '',
        'talksasa_sender_id'    => 'KEYCHELE',
        'smtp_from_email'       => 'noreply@keychele.com',
        'smtp_from_name'        => 'Keychele Platform',
        'warning_email_subject' => 'Action Required: Your account will be deleted',
    ];
}

function timeAgo(?string $d): string {
    if (!$d || $d === '1970-01-01 00:00:00' || $d === '0000-00-00 00:00:00') return '<span class="na">—</span>';
    $ts   = strtotime($d);
    $diff = time() - $ts;
    if ($diff < 0)       return date('d M Y', $ts);
    if ($diff < 60)      return '<span class="fresh">Just now</span>';
    if ($diff < 3600)    return floor($diff/60) . 'm ago';
    if ($diff < 86400)   return floor($diff/3600) . 'h ago';
    if ($diff < 604800)  return floor($diff/86400) . 'd ago';
    if ($diff < 2592000) return floor($diff/604800) . 'w ago';
    return date('d M Y', $ts);
}

function daysInactive(?string $lastLogin): ?int {
    if (!$lastLogin || $lastLogin === '0000-00-00 00:00:00' || $lastLogin === '1970-01-01 00:00:00') return null;
    return (int)floor((time() - strtotime($lastLogin)) / 86400);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Creators — Keychele Admin</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
<style>
/* ══════════════════════════════════════════
   TOKENS
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
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  margin-bottom: 26px;
  gap: 16px;
  flex-wrap: wrap;
}
.pg-header-left h1 {
  font-size: 1.55rem;
  font-weight: 800;
  letter-spacing: -.03em;
  color: var(--navy);
  display: flex;
  align-items: center;
  gap: 10px;
}
.pg-header-left h1 .icon-wrap {
  width: 38px; height: 38px;
  background: var(--blue);
  border-radius: 10px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  font-size: .85rem;
  color: #fff;
  flex-shrink: 0;
}
.pg-header-left p { color: var(--text-2); font-size: .82rem; margin-top: 3px; padding-left: 48px; }

.btn-primary-ui {
  display: inline-flex;
  align-items: center;
  gap: 7px;
  background: var(--blue);
  color: #fff;
  border: none;
  border-radius: var(--radius-sm);
  padding: 10px 20px;
  font-family: var(--font);
  font-size: .85rem;
  font-weight: 600;
  text-decoration: none;
  cursor: pointer;
  transition: background .16s, transform .14s, box-shadow .16s;
  box-shadow: 0 2px 10px rgba(37,99,235,.35);
}
.btn-primary-ui:hover { background: var(--blue-h); color: #fff; transform: translateY(-1px); box-shadow: 0 4px 16px rgba(37,99,235,.4); }

/* ── KPI STRIP ── */
.kpi-grid {
  display: grid;
  grid-template-columns: repeat(6, 1fr);
  gap: 14px;
  margin-bottom: 26px;
}
@media (max-width:1100px) { .kpi-grid { grid-template-columns: repeat(3,1fr); } }
@media (max-width:600px)  { .kpi-grid { grid-template-columns: repeat(2,1fr); } }

.kpi {
  background: var(--surface);
  border-radius: var(--radius);
  box-shadow: var(--shadow-sm);
  padding: 18px 20px;
  position: relative;
  overflow: hidden;
  transition: transform .2s, box-shadow .2s;
  border: 1px solid var(--border);
}
.kpi:hover { transform: translateY(-3px); box-shadow: var(--shadow); }
.kpi-bar {
  position: absolute;
  left: 0; top: 0; bottom: 0;
  width: 4px;
  border-radius: var(--radius) 0 0 var(--radius);
}
.kpi.c-blue   .kpi-bar { background: var(--blue); }
.kpi.c-green  .kpi-bar { background: var(--emerald); }
.kpi.c-amber  .kpi-bar { background: var(--amber); }
.kpi.c-red    .kpi-bar { background: var(--red); }
.kpi.c-sky    .kpi-bar { background: var(--sky); }
.kpi.c-violet .kpi-bar { background: var(--violet); }

.kpi-icon {
  width: 36px; height: 36px;
  border-radius: 9px;
  display: flex; align-items: center; justify-content: center;
  font-size: .78rem;
  margin-bottom: 12px;
}
.kpi.c-blue   .kpi-icon { background: var(--blue-l);    color: var(--blue); }
.kpi.c-green  .kpi-icon { background: var(--emerald-l); color: var(--emerald); }
.kpi.c-amber  .kpi-icon { background: var(--amber-l);   color: var(--amber); }
.kpi.c-red    .kpi-icon { background: var(--red-l);     color: var(--red); }
.kpi.c-sky    .kpi-icon { background: var(--sky-l);     color: var(--sky); }
.kpi.c-violet .kpi-icon { background: var(--violet-l);  color: var(--violet); }

.kpi-label { font-size: .7rem; font-weight: 600; text-transform: uppercase; letter-spacing: .07em; color: var(--text-3); margin-bottom: 4px; }
.kpi-value { font-size: 1.8rem; font-weight: 800; line-height: 1; letter-spacing: -.04em; }
.kpi.c-blue   .kpi-value { color: var(--blue); }
.kpi.c-green  .kpi-value { color: var(--emerald); }
.kpi.c-amber  .kpi-value { color: var(--amber); }
.kpi.c-red    .kpi-value { color: var(--red); }
.kpi.c-sky    .kpi-value { color: var(--sky); }
.kpi.c-violet .kpi-value { color: var(--violet); }
.kpi-sub { font-size: .72rem; color: var(--text-3); margin-top: 3px; }

/* ── TABS ── */
.tab-nav {
  display: flex;
  gap: 2px;
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius-sm);
  padding: 4px;
  width: fit-content;
  margin-bottom: 20px;
  flex-wrap: wrap;
}
.tab-btn {
  display: inline-flex;
  align-items: center;
  gap: 7px;
  padding: 8px 16px;
  border: none;
  background: transparent;
  border-radius: 6px;
  font-family: var(--font);
  font-size: .82rem;
  font-weight: 500;
  color: var(--text-2);
  cursor: pointer;
  transition: all .15s;
  white-space: nowrap;
}
.tab-btn.active { background: var(--blue); color: #fff; font-weight: 600; box-shadow: 0 2px 8px rgba(37,99,235,.3); }
.tab-btn i { font-size: .76rem; }

.tab-pane { display: none; }
.tab-pane.active { display: block; animation: fade-in .2s ease; }
@keyframes fade-in { from { opacity: .4; transform: translateY(5px); } to { opacity: 1; transform: none; } }

/* ── CARD ── */
.card {
  background: var(--surface);
  border-radius: var(--radius);
  box-shadow: var(--shadow-sm);
  border: 1px solid var(--border);
  overflow: hidden;
}
.card-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 16px 20px;
  border-bottom: 1px solid var(--border);
  flex-wrap: wrap;
  background: var(--surface-2);
}
.card-title {
  font-size: .92rem;
  font-weight: 700;
  color: var(--navy);
  display: flex;
  align-items: center;
  gap: 8px;
}
.card-title i { color: var(--blue); font-size: .8rem; }

/* ── TOOLBAR ── */
.toolbar { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.search-wrap { position: relative; }
.search-wrap i {
  position: absolute;
  left: 11px;
  top: 50%;
  transform: translateY(-50%);
  color: var(--text-3);
  font-size: .75rem;
  pointer-events: none;
}
.search-input {
  height: 36px;
  width: 240px;
  padding: 0 12px 0 34px;
  border: 1.5px solid var(--border-dark);
  border-radius: var(--radius-sm);
  font-family: var(--font);
  font-size: .82rem;
  color: var(--text);
  background: var(--surface);
  outline: none;
  transition: border .16s, box-shadow .16s;
}
.search-input:focus { border-color: var(--blue); box-shadow: 0 0 0 3px rgba(37,99,235,.1); }
.select-sm {
  height: 36px;
  padding: 0 10px;
  border: 1.5px solid var(--border-dark);
  border-radius: var(--radius-sm);
  font-family: var(--font);
  font-size: .82rem;
  color: var(--text);
  background: var(--surface);
  outline: none;
  cursor: pointer;
  transition: border .16s;
}
.select-sm:focus { border-color: var(--blue); }
.btn-sm-ui {
  height: 36px;
  padding: 0 14px;
  border: none;
  border-radius: var(--radius-sm);
  font-family: var(--font);
  font-size: .8rem;
  font-weight: 600;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  gap: 6px;
  transition: all .15s;
}
.btn-sm-blue  { background: var(--blue); color: #fff; }
.btn-sm-blue:hover { background: var(--blue-h); }
.btn-sm-ghost { background: var(--bg); color: var(--text-2); border: 1px solid var(--border-dark); }
.btn-sm-ghost:hover { color: var(--red); border-color: var(--red); background: var(--red-l); }

/* ── TABLE ── */
.tbl-wrap { overflow-x: auto; }
table { width: 100%; border-collapse: collapse; }
thead th {
  padding: 10px 14px;
  background: var(--surface-2);
  border-bottom: 1px solid var(--border);
  font-size: .68rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: .08em;
  color: var(--text-3);
  white-space: nowrap;
  user-select: none;
}
tbody tr { border-bottom: 1px solid var(--border); transition: background .1s; }
tbody tr:last-child { border-bottom: none; }
tbody tr:hover { background: var(--blue-ll); }
tbody tr.row-red   { background: #fff5f5; }
tbody tr.row-red:hover   { background: #ffecec; }
tbody tr.row-amber { background: #fffdf0; }
tbody tr.row-amber:hover { background: #fff8d6; }
td { padding: 12px 14px; font-size: .82rem; color: var(--text); vertical-align: middle; }

/* ── CREATOR CELL ── */
.creator-cell { display: flex; align-items: center; gap: 10px; }
.avatar {
  width: 34px; height: 34px;
  border-radius: 9px;
  background: var(--blue-l);
  color: var(--blue);
  font-weight: 800;
  font-size: .72rem;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  letter-spacing: -.01em;
}
.creator-name { font-weight: 600; line-height: 1.2; }
.creator-user { font-size: .7rem; color: var(--text-3); font-family: var(--mono); }

/* ── LOGIN CELL ── */
.login-cell  { display: flex; flex-direction: column; gap: 1px; }
.login-time  { font-weight: 500; }
.login-date  { font-size: .69rem; color: var(--text-3); font-family: var(--mono); }
.fresh       { color: var(--emerald); font-weight: 600; }
.na          { color: var(--text-3); }
.never       { color: var(--text-3); font-style: italic; font-size: .78rem; }

/* ── BADGES ── */
.badge {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 3px 10px;
  border-radius: 100px;
  font-size: .68rem;
  font-weight: 700;
  letter-spacing: .02em;
  white-space: nowrap;
}
.badge-normal  { background: var(--bg);          color: var(--text-2); }
.badge-yes     { background: var(--emerald-l);   color: #065f46; }
.badge-no      { background: var(--bg);          color: var(--text-3); }
.badge-warned  { background: var(--amber-l);     color: #92400e; }
.badge-due     { background: var(--red-l);       color: #991b1b; }
.badge-sched   { background: #fce7f3;            color: #9d174d; }
.badge-cron    { background: var(--sky-l);       color: #0369a1; }
.badge-manual  { background: var(--violet-l);    color: #5b21b6; }
.badge-sent    { background: var(--emerald-l);   color: #065f46; }
.badge-failed  { background: var(--red-l);       color: #991b1b; }
.badge-skipped { background: var(--bg);          color: var(--text-3); }
.badge-reminder { background: #e0f2fe;           color: #0369a1; }

/* ── ACTIONS ── */
.actions { display: flex; align-items: center; gap: 4px; justify-content: flex-end; }
.act {
  width: 30px; height: 30px;
  border-radius: 7px;
  border: 1.5px solid transparent;
  background: transparent;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  font-size: .72rem;
  cursor: pointer;
  text-decoration: none;
  transition: all .14s;
}
.act:hover { transform: translateY(-1px); }
.act-view      { border-color: #bae6fd; color: #0284c7; }
.act-view:hover      { background: var(--sky-l); }
.act-edit      { border-color: var(--border-dark); color: var(--text-2); }
.act-edit:hover      { background: var(--bg); color: var(--text); }
.act-remind    { border-color: #bae6fd; color: var(--sky); }
.act-remind:hover    { background: var(--sky-l); }
.act-warn      { border-color: #fde68a; color: var(--amber); }
.act-warn:hover      { background: var(--amber-l); }
.act-cancel    { border-color: #a7f3d0; color: var(--emerald); }
.act-cancel:hover    { background: var(--emerald-l); }
.act-del       { border-color: #fca5a5; color: var(--red); }
.act-del:hover       { background: var(--red-l); }

/* ── PAGINATION ── */
.pagi-wrap {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 14px 20px;
  border-top: 1px solid var(--border);
  background: var(--surface-2);
  flex-wrap: wrap;
  gap: 10px;
}
.pagi-info { font-size: .75rem; color: var(--text-3); }
.pagi-links { display: flex; gap: 4px; }
.pagi-btn {
  height: 32px;
  min-width: 32px;
  padding: 0 9px;
  border: 1.5px solid var(--border-dark);
  border-radius: 7px;
  background: var(--surface);
  font-family: var(--font);
  font-size: .78rem;
  font-weight: 500;
  color: var(--text-2);
  cursor: pointer;
  text-decoration: none;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  transition: all .14s;
}
.pagi-btn:hover:not(.pagi-active):not(.pagi-off) {
  border-color: var(--blue);
  color: var(--blue);
  background: var(--blue-ll);
}
.pagi-active { background: var(--blue); border-color: var(--blue); color: #fff; font-weight: 700; }
.pagi-off    { opacity: .35; pointer-events: none; }
.pagi-dots   { border: none; background: none; color: var(--text-3); pointer-events: none; min-width: 20px; }

/* ── EMPTY ── */
.empty { text-align: center; padding: 60px 20px; color: var(--text-3); }
.empty i { font-size: 2rem; opacity: .3; display: block; margin-bottom: 12px; }
.empty p { font-size: .85rem; }

/* ── ALERTS ── */
.alert-err {
  background: var(--red-l);
  border: 1px solid #fca5a5;
  border-radius: var(--radius-sm);
  padding: 12px 16px;
  color: #991b1b;
  font-size: .84rem;
  margin-bottom: 20px;
  display: flex;
  align-items: center;
  gap: 8px;
}
.alert-info-box {
  background: var(--blue-ll);
  border: 1px solid #bfdbfe;
  border-radius: var(--radius-sm);
  padding: 11px 14px;
  font-size: .8rem;
  color: #1e40af;
}

/* ── SETTINGS ── */
.settings-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; }
.form-label {
  font-size: .8rem;
  font-weight: 600;
  color: var(--text);
  display: block;
  margin-bottom: 5px;
}
.form-input {
  width: 100%;
  height: 40px;
  padding: 0 12px;
  border: 1.5px solid var(--border-dark);
  border-radius: var(--radius-sm);
  font-family: var(--font);
  font-size: .85rem;
  color: var(--text);
  background: var(--surface);
  outline: none;
  transition: border .16s, box-shadow .16s;
}
.form-input:focus { border-color: var(--blue); box-shadow: 0 0 0 3px rgba(37,99,235,.1); }
.form-hint { font-size: .71rem; color: var(--text-3); margin-top: 4px; }
.form-row  { display: flex; flex-direction: column; gap: 4px; }

/* Toggle */
.toggle-wrap { display: flex; align-items: center; gap: 10px; margin-top: 2px; }
.toggle-track {
  width: 40px; height: 22px;
  border-radius: 100px;
  background: var(--border-dark);
  position: relative;
  cursor: pointer;
  transition: background .2s;
  flex-shrink: 0;
}
.toggle-track.on { background: var(--emerald); }
.toggle-thumb {
  position: absolute;
  top: 3px; left: 3px;
  width: 16px; height: 16px;
  border-radius: 50%;
  background: #fff;
  box-shadow: 0 1px 4px rgba(0,0,0,.2);
  transition: transform .2s;
}
.toggle-track.on .toggle-thumb { transform: translateX(18px); }
.toggle-label { font-size: .82rem; font-weight: 500; color: var(--text); }

/* Calc banner */
.calc-banner {
  background: linear-gradient(135deg, #ede9fe, #e0e7ff);
  border: 1px solid #c7d2fe;
  border-radius: var(--radius-sm);
  padding: 10px 14px;
  font-size: .79rem;
  color: #3730a3;
}

/* Cron code */
.code-block {
  font-family: var(--mono);
  font-size: .75rem;
  background: var(--navy);
  color: #a5f3fc;
  border-radius: var(--radius-sm);
  padding: 12px 16px;
  line-height: 1.7;
  word-break: break-all;
}

/* Log tags */
.log-tag {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 3px 10px;
  border-radius: 100px;
  font-size: .67rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: .05em;
}
.tag-warning_sent       { background: var(--amber-l);   color: #92400e; }
.tag-manual_reminder    { background: var(--sky-l);     color: #0369a1; }
.tag-deleted            { background: var(--red-l);     color: #991b1b; }
.tag-deletion_cancelled { background: var(--emerald-l); color: #065f46; }
.tag-default            { background: var(--bg);        color: var(--text-2); }

/* Preview spinner */
.spin-box { text-align: center; padding: 40px; display: none; }
.spin-box .spin {
  width: 36px; height: 36px;
  border-radius: 50%;
  border: 3px solid var(--blue-l);
  border-top-color: var(--blue);
  animation: spin .7s linear infinite;
  margin: 0 auto 12px;
}
@keyframes spin { to { transform: rotate(360deg); } }

/* ── MODALS ── */
.modal-overlay {
  display: none;
  position: fixed;
  inset: 0;
  background: rgba(13,27,62,.5);
  backdrop-filter: blur(4px);
  z-index: 9000;
  align-items: center;
  justify-content: center;
}
.modal-overlay.open { display: flex; }
.modal-box {
  background: var(--surface);
  border-radius: 18px;
  box-shadow: var(--shadow-lg);
  width: 94%;
  max-width: 440px;
  overflow: hidden;
  animation: modal-pop .25s cubic-bezier(.16,1,.3,1);
}
.modal-box.wide { max-width: 520px; }
@keyframes modal-pop {
  from { opacity: 0; transform: scale(.94) translateY(14px); }
  to   { opacity: 1; transform: none; }
}
.modal-head {
  display: flex; align-items: center; justify-content: space-between;
  padding: 18px 22px;
  border-bottom: 1px solid var(--border);
}
.modal-head.red-head    { background: linear-gradient(135deg, #7f1d1d, var(--red)); color: #fff; }
.modal-head.amber-head  { background: linear-gradient(135deg, #78350f, var(--amber)); color: #fff; }
.modal-head.blue-head   { background: linear-gradient(135deg, #1e3a5f, var(--blue)); color: #fff; }
.modal-head h4 { font-weight: 800; font-size: .95rem; margin: 0; }
.modal-close { background: none; border: none; color: inherit; font-size: 1.1rem; cursor: pointer; opacity: .75; }
.modal-close:hover { opacity: 1; }
.modal-body { padding: 22px; }
.modal-foot { padding: 16px 22px; border-top: 1px solid var(--border); display: flex; gap: 9px; justify-content: flex-end; }
.btn-modal {
  height: 38px; padding: 0 20px;
  border: none; border-radius: var(--radius-sm);
  font-family: var(--font); font-weight: 600; font-size: .82rem;
  cursor: pointer; transition: opacity .15s;
  display: inline-flex; align-items: center; gap: 6px;
}
.btn-modal:disabled { opacity: .55; cursor: not-allowed; }
.btn-cancel-modal { background: var(--bg); color: var(--text); border: 1px solid var(--border-dark); }
.btn-red-modal    { background: var(--red);   color: #fff; }
.btn-amber-modal  { background: var(--amber); color: #fff; }
.btn-blue-modal   { background: var(--blue);  color: #fff; }

/* Reminder channel checkboxes */
.channel-row {
  display: flex; gap: 12px; flex-wrap: wrap;
}
.channel-check {
  display: flex; align-items: center; gap: 8px;
  background: var(--surface-2);
  border: 1.5px solid var(--border-dark);
  border-radius: var(--radius-sm);
  padding: 8px 14px;
  cursor: pointer;
  transition: border .15s, background .15s;
  user-select: none;
  font-size: .83rem;
  font-weight: 500;
}
.channel-check input { accent-color: var(--blue); width: 15px; height: 15px; cursor: pointer; }
.channel-check:has(input:checked) { border-color: var(--blue); background: var(--blue-ll); color: var(--blue); }

/* Reminder textarea */
.reminder-textarea {
  width: 100%;
  padding: 10px 12px;
  border: 1.5px solid var(--border-dark);
  border-radius: var(--radius-sm);
  font-family: var(--font);
  font-size: .83rem;
  color: var(--text);
  resize: vertical;
  outline: none;
  transition: border .16s, box-shadow .16s;
  min-height: 90px;
}
.reminder-textarea:focus { border-color: var(--blue); box-shadow: 0 0 0 3px rgba(37,99,235,.1); }

/* Recipient info box */
.recipient-info {
  background: var(--blue-ll);
  border: 1px solid #bfdbfe;
  border-radius: var(--radius-sm);
  padding: 10px 14px;
  font-size: .79rem;
  color: #1e40af;
  line-height: 1.9;
}

/* SweetAlert overrides */
.swal2-popup  { font-family: var(--font) !important; border-radius: 16px !important; }
.swal2-title  { font-family: var(--font) !important; font-weight: 800 !important; }
.swal2-confirm { background: var(--blue) !important; border-radius: 8px !important; font-family: var(--font) !important; font-weight: 600 !important; }
.swal2-confirm.swal-red { background: var(--red) !important; }

@media (max-width:680px) {
  .shell { padding: 14px; }
  .search-input { width: 100%; }
  .kpi-grid { grid-template-columns: 1fr 1fr; }
}
</style>
</head>
<body>
<div class="shell">

  <!-- ── HEADER ── -->
  <div class="pg-header">
    <div class="pg-header-left">
      <h1>
        <span class="icon-wrap"><i class="fas fa-users-cog"></i></span>
        All Creators
      </h1>
      <p>Manage accounts · Monitor inactivity · Configure auto-deletion policies</p>
    </div>
    <a href="index.php?page=create_creator" class="btn-primary-ui">
      <i class="fas fa-plus"></i> Add Creator
    </a>
  </div>

  <?php if (isset($error)): ?>
    <div class="alert-err"><i class="fas fa-exclamation-circle"></i><?= $error ?></div>
  <?php endif; ?>

  <!-- ── KPI STRIP ── -->
  <div class="kpi-grid">
    <div class="kpi c-blue">
      <div class="kpi-bar"></div>
      <div class="kpi-icon"><i class="fas fa-users"></i></div>
      <div class="kpi-label">Total Creators</div>
      <div class="kpi-value"><?= number_format($total_creators) ?></div>
      <div class="kpi-sub">registered accounts</div>
    </div>
    <div class="kpi c-green">
      <div class="kpi-bar"></div>
      <div class="kpi-icon"><i class="fas fa-check-circle"></i></div>
      <div class="kpi-label">Active</div>
      <div class="kpi-value"><?= number_format($total_creators - $must_change_count) ?></div>
      <div class="kpi-sub">no action needed</div>
    </div>
    <div class="kpi c-amber">
      <div class="kpi-bar"></div>
      <div class="kpi-icon"><i class="fas fa-key"></i></div>
      <div class="kpi-label">Must Change PW</div>
      <div class="kpi-value"><?= number_format($must_change_count) ?></div>
      <div class="kpi-sub">first-login pending</div>
    </div>
    <div class="kpi c-amber">
      <div class="kpi-bar"></div>
      <div class="kpi-icon"><i class="fas fa-bell"></i></div>
      <div class="kpi-label">Warned</div>
      <div class="kpi-value"><?= number_format($warned_count) ?></div>
      <div class="kpi-sub">inactivity notice sent</div>
    </div>
    <div class="kpi c-red">
      <div class="kpi-bar"></div>
      <div class="kpi-icon"><i class="fas fa-skull-crossbones"></i></div>
      <div class="kpi-label">Due Deletion</div>
      <div class="kpi-value"><?= number_format($due_deletion_count) ?></div>
      <div class="kpi-sub">grace period expired</div>
    </div>
    <div class="kpi c-sky">
      <div class="kpi-bar"></div>
      <div class="kpi-icon"><i class="fas fa-clock"></i></div>
      <div class="kpi-label">Threshold</div>
      <div class="kpi-value"><?= $settings['inactivity_days'] ?><span style="font-size:1rem;font-weight:500">d</span></div>
      <div class="kpi-sub"><?= $settings['grace_period_days'] ?>d grace period</div>
    </div>
  </div>

  <!-- ── TABS ── -->
  <div class="tab-nav">
    <button class="tab-btn active" data-tab="tab-creators"><i class="fas fa-list"></i> Creators</button>
    <button class="tab-btn" data-tab="tab-inactivity"><i class="fas fa-user-clock"></i> Inactivity</button>
    <button class="tab-btn" data-tab="tab-settings"><i class="fas fa-cog"></i> Settings</button>
    <button class="tab-btn" data-tab="tab-log"><i class="fas fa-history"></i> Audit Log</button>
  </div>

  <!-- ══════════════════════════════════════════════════════
       TAB 1 — CREATORS
  ══════════════════════════════════════════════════════ -->
  <div class="tab-pane active" id="tab-creators">
    <div class="card">
      <div class="card-head">
        <div class="card-title">
          <i class="fas fa-users"></i>
          Creators
          <span style="font-weight:400;font-size:.76rem;color:var(--text-3)">
            (<?= number_format($filtered_total) ?><?= $search_q ? ' matching' : ' total' ?>)
          </span>
        </div>
        <form method="GET" id="filterForm" style="display:contents">
          <?php if ($dash_page): ?>
            <input type="hidden" name="page" value="<?= htmlspecialchars($dash_page) ?>">
          <?php endif; ?>
          <input type="hidden" name="p" value="1" id="pageHidden">
          <div class="toolbar">
            <div class="search-wrap">
              <i class="fas fa-search"></i>
              <input type="text" name="q" class="search-input" id="searchInput"
                     value="<?= htmlspecialchars($search_q) ?>"
                     placeholder="Name, email, phone…">
            </div>
            <select name="per_page" class="select-sm" id="perPageSel">
              <?php foreach ([10,20,50,100] as $n): ?>
                <option value="<?= $n ?>" <?= $per_page==$n?'selected':'' ?>><?= $n ?> / page</option>
              <?php endforeach; ?>
            </select>
            <button type="submit" class="btn-sm-ui btn-sm-blue"><i class="fas fa-filter"></i> Filter</button>
            <?php if ($search_q || $per_page != 20): ?>
              <a href="<?= buildUrl([], $dash_page) ?>" class="btn-sm-ui btn-sm-ghost">
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
              <th>Creator</th>
              <th>Phone</th>
              <th>Email</th>
              <th>Joined</th>
              <th>Last Login</th>
              <th>PW Change</th>
              <th>Inactivity</th>
              <th>Inactive For</th>
              <th style="text-align:right">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!empty($creators)): ?>
              <?php foreach ($creators as $row):
                // Row highlight
                $rc = '';
                if ($row['scheduled_delete_date'] && $row['scheduled_delete_date'] <= date('Y-m-d')) $rc = 'row-red';
                elseif ($row['scheduled_delete_date'] || $row['inactivity_warning_sent_at']) $rc = 'row-amber';

                // Inactivity badge
                if ($row['scheduled_delete_date'] && $row['scheduled_delete_date'] <= date('Y-m-d')) {
                    $ib = '<span class="badge badge-due"><i class="fas fa-exclamation-triangle"></i> Due Deletion</span>';
                } elseif ($row['scheduled_delete_date']) {
                    $del = date('d M', strtotime($row['scheduled_delete_date']));
                    $ib  = '<span class="badge badge-sched"><i class="fas fa-clock"></i> Delete '.$del.'</span>';
                } elseif ($row['inactivity_warning_sent_at']) {
                    $ib = '<span class="badge badge-warned"><i class="fas fa-bell"></i> Warned</span>';
                } else {
                    $ib = '<span class="badge badge-normal">Normal</span>';
                }

                // Avatar initials
                $parts = explode(' ', trim($row['full_name'] ?? ''));
                $init  = strtoupper(substr($parts[0] ?? '?', 0, 1) . substr(end($parts), 0, 1));

                // Last login display
                $ll     = $row['last_login'] ?? null;
                $llTime = ($ll && $ll !== '0000-00-00 00:00:00') ? timeAgo($ll) : '<span class="never">Never</span>';
                $llDate = ($ll && $ll !== '0000-00-00 00:00:00')
                          ? '<div class="login-date">'.date('d M Y · H:i', strtotime($ll)).'</div>'
                          : '';
              ?>
              <tr class="<?= $rc ?>">
                <!-- Creator -->
                <td>
                  <div class="creator-cell">
                    <div class="avatar"><?= $init ?></div>
                    <div>
                      <div class="creator-name"><?= htmlspecialchars($row['full_name'] ?? '—') ?></div>
                      <div class="creator-user">@<?= htmlspecialchars($row['username'] ?? '—') ?></div>
                    </div>
                  </div>
                </td>
                <!-- Phone -->
                <td><?= htmlspecialchars($row['phone'] ?? '—') ?></td>
                <!-- Email -->
                <td style="max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                  <?= htmlspecialchars($row['email'] ?? '—') ?>
                </td>
                <!-- Joined -->
                <td style="white-space:nowrap"><?= date('d M Y', strtotime($row['date_created'])) ?></td>
                <!-- Last Login -->
                <td>
                  <div class="login-cell">
                    <div class="login-time"><?= $llTime ?></div>
                    <?= $llDate ?>
                  </div>
                </td>
                <!-- PW Change -->
                <td>
                  <?= $row['must_change_password'] == 1
                    ? '<span class="badge badge-yes"><i class="fas fa-key"></i> Yes</span>'
                    : '<span class="badge badge-no">No</span>' ?>
                </td>
                <!-- Inactivity Status -->
                <td><?= $ib ?></td>
                <!-- Days Inactive -->
                <td>
                  <?php
                    $di = daysInactive($row['last_login']);
                    if ($di === null) {
                        echo '<span class="na" style="font-size:.75rem">Never logged in</span>';
                    } elseif ($di >= 1) {
                        $diColor = $di >= 60 ? 'var(--red)' : ($di >= 30 ? 'var(--amber)' : 'var(--text-2)');
                        echo '<span style="font-weight:700;color:'.$diColor.';font-size:.82rem">'.$di.'d</span>'
                           . '<span style="font-size:.68rem;color:var(--text-3);display:block">inactive</span>';
                    } else {
                        echo '<span style="color:var(--emerald);font-weight:600;font-size:.8rem">Today</span>';
                    }
                  ?>
                </td>
                <!-- Actions -->
                <td>
                  <div class="actions">
                    <!-- View -->
                    <a href="index.php?page=view_creator&id=<?= $row['id'] ?>"
                       class="act act-view" title="View Creator">
                      <i class="fas fa-eye"></i>
                    </a>
                    <!-- Edit -->
                    <a href="index.php?page=edit_creator&id=<?= $row['id'] ?>"
                       class="act act-edit" title="Edit Creator">
                      <i class="fas fa-edit"></i>
                    </a>
                    <!-- Manual Reminder (always visible) -->
                    <button class="act act-remind manual-reminder-btn"
                            data-id="<?= $row['id'] ?>"
                            data-name="<?= htmlspecialchars($row['full_name'] ?? '') ?>"
                            data-email="<?= htmlspecialchars($row['email'] ?? '') ?>"
                            data-phone="<?= htmlspecialchars($row['phone'] ?? '') ?>"
                            title="Send Manual Reminder">
                      <i class="fas fa-paper-plane"></i>
                    </button>
                    <!-- Cancel Deletion OR Force Warn -->
                    <?php if ($row['scheduled_delete_date']): ?>
                      <button class="act act-cancel cancel-deletion-btn"
                              data-id="<?= $row['id'] ?>"
                              data-name="<?= htmlspecialchars($row['full_name']) ?>"
                              title="Cancel Scheduled Deletion">
                        <i class="fas fa-ban"></i>
                      </button>
                    <?php else: ?>
                      <button class="act act-warn force-warn-btn"
                              data-id="<?= $row['id'] ?>"
                              data-name="<?= htmlspecialchars($row['full_name']) ?>"
                              title="Send Inactivity Warning">
                        <i class="fas fa-bell"></i>
                      </button>
                    <?php endif; ?>
                    <!-- Delete -->
                    <button class="act act-del delete-creator-btn"
                            data-id="<?= $row['id'] ?>"
                            data-name="<?= htmlspecialchars($row['full_name'] ?? 'this creator') ?>"
                            title="Delete Creator">
                      <i class="fas fa-trash"></i>
                    </button>
                  </div>
                </td>
              </tr>
              <?php endforeach; ?>
            <?php else: ?>
              <tr>
                <td colspan="9">
                  <div class="empty">
                    <i class="fas fa-users-slash"></i>
                    <p>No creators found<?= $search_q ? ' matching "'.htmlspecialchars($search_q).'"' : '' ?>.</p>
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
          Showing <?= number_format($showFrom) ?>–<?= number_format($showTo) ?> of <?= number_format($filtered_total) ?> creators
        </div>
        <div class="pagi-links">
          <?php
            function pgLink(int $p, int $pp, string $q, ?string $dp): string {
                $params = ['p' => $p, 'per_page' => $pp];
                if ($q !== '') $params['q'] = $q;
                if ($dp)       $params['page'] = $dp;
                return '?' . http_build_query($params);
            }
            $win_start = max(1, $current_page - 2);
            $win_end   = min($total_pages, $current_page + 2);
          ?>
          <!-- Prev -->
          <?php if ($current_page > 1): ?>
            <a href="<?= pgLink($current_page-1,$per_page,$search_q,$dash_page) ?>" class="pagi-btn">
              <i class="fas fa-chevron-left"></i>
            </a>
          <?php else: ?>
            <span class="pagi-btn pagi-off"><i class="fas fa-chevron-left"></i></span>
          <?php endif; ?>

          <!-- Page numbers -->
          <?php if ($win_start > 1): ?>
            <a href="<?= pgLink(1,$per_page,$search_q,$dash_page) ?>" class="pagi-btn">1</a>
            <?php if ($win_start > 2): ?><span class="pagi-btn pagi-dots">…</span><?php endif; ?>
          <?php endif; ?>
          <?php for ($i = $win_start; $i <= $win_end; $i++): ?>
            <a href="<?= pgLink($i,$per_page,$search_q,$dash_page) ?>"
               class="pagi-btn <?= $i === $current_page ? 'pagi-active' : '' ?>">
              <?= $i ?>
            </a>
          <?php endfor; ?>
          <?php if ($win_end < $total_pages): ?>
            <?php if ($win_end < $total_pages - 1): ?><span class="pagi-btn pagi-dots">…</span><?php endif; ?>
            <a href="<?= pgLink($total_pages,$per_page,$search_q,$dash_page) ?>" class="pagi-btn"><?= $total_pages ?></a>
          <?php endif; ?>

          <!-- Next -->
          <?php if ($current_page < $total_pages): ?>
            <a href="<?= pgLink($current_page+1,$per_page,$search_q,$dash_page) ?>" class="pagi-btn">
              <i class="fas fa-chevron-right"></i>
            </a>
          <?php else: ?>
            <span class="pagi-btn pagi-off"><i class="fas fa-chevron-right"></i></span>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>
    </div>
  </div><!-- /tab-creators -->

  <!-- ══════════════════════════════════════════════════════
       TAB 2 — INACTIVITY MANAGER
  ══════════════════════════════════════════════════════ -->
  <div class="tab-pane" id="tab-inactivity">
    <div class="card">
      <div class="card-head">
        <div class="card-title"><i class="fas fa-user-clock"></i> Inactive Creators Preview</div>
        <button class="btn-sm-ui btn-sm-blue" id="runPreviewBtn">
          <i class="fas fa-search"></i> Scan Now
        </button>
      </div>
      <div style="padding:16px 20px 8px">
        <div class="alert-info-box">
          <i class="fas fa-info-circle" style="margin-right:5px"></i>
          Activity = <strong>last login</strong> · last ticket created · last completed sale.
          Warning at <strong><?= $settings['inactivity_days'] - $settings['grace_period_days'] ?> days</strong>,
          deletion at <strong><?= $settings['inactivity_days'] ?> days</strong>.
        </div>
      </div>
      <div class="spin-box" id="previewLoading">
        <div class="spin"></div>
        <div style="font-size:.8rem;color:var(--text-3)">Scanning all creators…</div>
      </div>
      <div id="previewResults" style="display:none">
        <div class="tbl-wrap">
          <table>
            <thead>
              <tr>
                <th>Creator</th><th>Email</th><th>Phone</th><th>Days Inactive</th>
                <th>Last Activity</th><th>Status</th><th>Delete Date</th><th>Actions</th>
              </tr>
            </thead>
            <tbody id="previewTbody"></tbody>
          </table>
        </div>
        <div class="empty" id="previewEmpty" style="display:none">
          <i class="fas fa-check-circle" style="color:var(--emerald);opacity:1"></i>
          <p>No creators are currently inactive beyond the threshold.</p>
        </div>
      </div>
      <div style="height:12px"></div>
    </div>
  </div><!-- /tab-inactivity -->

  <!-- ══════════════════════════════════════════════════════
       TAB 3 — SETTINGS
  ══════════════════════════════════════════════════════ -->
  <div class="tab-pane" id="tab-settings">
    <div class="settings-grid">

      <!-- Inactivity Rules -->
      <div class="card">
        <div class="card-head"><div class="card-title"><i class="fas fa-sliders-h"></i> Inactivity Rules</div></div>
        <div style="padding:22px;display:flex;flex-direction:column;gap:16px">
          <div class="form-row">
            <label class="form-label">Inactivity Threshold (days)</label>
            <input type="number" class="form-input" id="s_inactivity_days" min="1" max="365"
                   value="<?= (int)$settings['inactivity_days'] ?>">
            <div class="form-hint">Account deleted after this many inactive days.</div>
          </div>
          <div class="form-row">
            <label class="form-label">Grace Period (days)</label>
            <input type="number" class="form-input" id="s_grace_period_days" min="1" max="60"
                   value="<?= (int)$settings['grace_period_days'] ?>">
            <div class="form-hint">Warning sent this many days before deletion.</div>
          </div>
          <div class="form-row">
            <label class="form-label">Auto-Deletion</label>
            <div class="toggle-wrap">
              <div class="toggle-track <?= $settings['auto_delete_enabled'] ? 'on' : '' ?>" id="autoDelToggle">
                <div class="toggle-thumb"></div>
              </div>
              <input type="hidden" id="s_auto_delete_enabled" value="<?= $settings['auto_delete_enabled'] ? '1' : '0' ?>">
              <span class="toggle-label" id="autoDelLabel">
                <?= $settings['auto_delete_enabled'] ? 'Enabled' : 'Disabled' ?>
              </span>
            </div>
            <div class="form-hint">If disabled, warnings sent but accounts not auto-deleted.</div>
          </div>
          <div class="calc-banner">
            <i class="fas fa-calculator" style="margin-right:5px"></i>
            Warning at day <strong id="warnDay"><?= $settings['inactivity_days'] - $settings['grace_period_days'] ?></strong>,
            deletion at day <strong id="deleteDay"><?= $settings['inactivity_days'] ?></strong>.
          </div>
        </div>
      </div>

      <!-- Notification Settings -->
      <div class="card">
        <div class="card-head"><div class="card-title"><i class="fas fa-bell"></i> Notification Settings</div></div>
        <div style="padding:22px;display:flex;flex-direction:column;gap:14px">
          <div style="font-size:.8rem;font-weight:700;color:var(--text-2);display:flex;align-items:center;gap:6px">
            <i class="fas fa-mobile-alt" style="color:var(--blue)"></i> Talk Sasa SMS
          </div>
          <div class="form-row">
            <label class="form-label">API Key</label>
            <input type="password" class="form-input" id="s_talksasa_api_key"
                   value="<?= htmlspecialchars($settings['talksasa_api_key'] ?? '') ?>"
                   placeholder="Talk Sasa API key">
          </div>
          <div class="form-row">
            <label class="form-label">Sender ID</label>
            <input type="text" class="form-input" id="s_talksasa_sender_id"
                   value="<?= htmlspecialchars($settings['talksasa_sender_id'] ?? 'KEYCHELE') ?>"
                   maxlength="11">
            <div class="form-hint">Max 11 chars · Must be registered.</div>
          </div>
          <hr style="border:none;border-top:1px solid var(--border)">
          <div style="font-size:.8rem;font-weight:700;color:var(--text-2);display:flex;align-items:center;gap:6px">
            <i class="fas fa-envelope" style="color:var(--blue)"></i> Email
          </div>
          <div class="form-row">
            <label class="form-label">From Email</label>
            <input type="email" class="form-input" id="s_smtp_from_email"
                   value="<?= htmlspecialchars($settings['smtp_from_email'] ?? '') ?>">
          </div>
          <div class="form-row">
            <label class="form-label">From Name</label>
            <input type="text" class="form-input" id="s_smtp_from_name"
                   value="<?= htmlspecialchars($settings['smtp_from_name'] ?? '') ?>">
          </div>
          <div class="form-row">
            <label class="form-label">Warning Email Subject</label>
            <input type="text" class="form-input" id="s_warning_email_subject"
                   value="<?= htmlspecialchars($settings['warning_email_subject'] ?? '') ?>">
          </div>
        </div>
      </div>

      <!-- Cron + Save -->
      <div class="card" style="grid-column:1/-1">
        <div style="padding:22px;display:flex;justify-content:space-between;align-items:flex-start;gap:24px;flex-wrap:wrap">
          <div style="flex:1;min-width:260px;display:flex;flex-direction:column;gap:10px">
            <div style="font-size:.88rem;font-weight:700;color:var(--navy);display:flex;align-items:center;gap:7px">
              <i class="fas fa-terminal" style="color:var(--blue)"></i> Cron Job Setup
            </div>
            <p style="font-size:.78rem;color:var(--text-3);margin:0">Daily 6 AM cron:</p>
            <div class="code-block">0 6 * * * /usr/bin/php <?= dirname(__DIR__) ?>/admin/cron_inactivity.php >> /var/log/inactivity_cron.log 2>&1</div>
            <p style="font-size:.78rem;color:var(--text-3);margin:0">Track last_login on successful login:</p>
            <div class="code-block">$pdo->prepare("UPDATE creators SET last_login = NOW() WHERE id = ?")->execute([$creator_id]);</div>
          </div>
          <button class="btn-primary-ui" id="saveSettingsBtn" style="height:42px;padding:0 28px;align-self:flex-end">
            <i class="fas fa-save"></i> Save Settings
          </button>
        </div>
      </div>
    </div>
  </div><!-- /tab-settings -->

  <!-- ══════════════════════════════════════════════════════
       TAB 4 — AUDIT LOG
  ══════════════════════════════════════════════════════ -->
  <div class="tab-pane" id="tab-log">
    <div class="card">
      <div class="card-head">
        <div class="card-title">
          <i class="fas fa-history"></i> Inactivity Audit Log
          <span style="font-weight:400;font-size:.75rem;color:var(--text-3)">(last 20)</span>
        </div>
        <a href="?page=inactivity_log_full" class="btn-sm-ui btn-sm-ghost"
           style="border:1.5px solid var(--border-dark)">View All</a>
      </div>
      <div class="tbl-wrap">
        <table>
          <thead>
            <tr>
              <th>Date</th><th>Creator</th><th>Action</th><th>Email</th><th>SMS</th>
              <th>Last Activity</th><th>Days</th><th>Delete Date</th><th>By</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!empty($recent_log)): ?>
              <?php foreach ($recent_log as $log):
                $ac  = $log['action'] ?? 'unknown';
                $tc  = in_array($ac, ['warning_sent','deleted','deletion_cancelled','manual_reminder'])
                     ? 'tag-'.$ac : 'tag-default';
                $lbl = ucwords(str_replace('_', ' ', $ac));
              ?>
              <tr>
                <td style="white-space:nowrap">
                  <?= date('d M Y', strtotime($log['actioned_at'])) ?>
                  <div style="font-size:.68rem;color:var(--text-3);font-family:var(--mono)">
                    <?= date('H:i', strtotime($log['actioned_at'])) ?>
                  </div>
                </td>
                <td>
                  <div style="font-weight:600;font-size:.82rem"><?= htmlspecialchars($log['creator_name']) ?></div>
                  <div style="font-size:.69rem;color:var(--text-3)"><?= htmlspecialchars($log['creator_email']) ?></div>
                </td>
                <td><span class="log-tag <?= $tc ?>"><?= $lbl ?></span></td>
                <td>
                  <span class="badge badge-<?= htmlspecialchars($log['email_status'] ?? 'skipped') ?>">
                    <?= ucfirst($log['email_status'] ?? '—') ?>
                  </span>
                </td>
                <td>
                  <span class="badge badge-<?= htmlspecialchars($log['sms_status'] ?? 'skipped') ?>">
                    <?= ucfirst($log['sms_status'] ?? '—') ?>
                  </span>
                </td>
                <td style="font-size:.79rem">
                  <?= $log['last_activity_date']
                    ? date('d M Y', strtotime($log['last_activity_date']))
                    : '<span class="na">—</span>' ?>
                </td>
                <td style="text-align:center">
                  <?= isset($log['inactivity_days_at_action']) ? $log['inactivity_days_at_action'].'d' : '—' ?>
                </td>
                <td style="font-size:.79rem">
                  <?= $log['scheduled_delete_date']
                    ? date('d M Y', strtotime($log['scheduled_delete_date']))
                    : '<span class="na">—</span>' ?>
                </td>
                <td>
                  <span class="badge badge-<?= $log['actioned_by'] === 'cron' ? 'cron' : 'manual' ?>">
                    <?= ucfirst(htmlspecialchars($log['actioned_by'])) ?>
                  </span>
                </td>
              </tr>
              <?php endforeach; ?>
            <?php else: ?>
              <tr>
                <td colspan="9">
                  <div class="empty">
                    <i class="fas fa-clipboard-list"></i>
                    <p>No audit entries yet.</p>
                  </div>
                </td>
              </tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div><!-- /tab-log -->

</div><!-- /shell -->

<!-- ══════════════════════════════════════════════════════
     MODALS
══════════════════════════════════════════════════════ -->

<!-- ── DELETE MODAL ── -->
<div class="modal-overlay" id="deleteModal">
  <div class="modal-box">
    <div class="modal-head red-head">
      <h4><i class="fas fa-skull-crossbones" style="margin-right:8px"></i>Permanent Deletion</h4>
      <button class="modal-close" id="closeDeleteModal"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body">
      <p style="font-weight:700;color:var(--red);margin-bottom:10px">This action is <u>irreversible</u>.</p>
      <p style="margin-bottom:14px;font-size:.85rem">
        You are about to permanently delete <strong id="deleteCreatorName"></strong> and all their data:
      </p>
      <ul style="font-size:.79rem;color:var(--red);padding-left:18px;line-height:2.1">
        <li>All events &amp; tickets</li>
        <li>Ticket sales &amp; purchase history</li>
        <li>Withdrawals &amp; transactions</li>
        <li>Balance, surveys, support, page data</li>
      </ul>
    </div>
    <div class="modal-foot">
      <button class="btn-modal btn-cancel-modal" id="cancelDeleteBtn">Cancel</button>
      <button class="btn-modal btn-red-modal" id="confirmDelete">
        <i class="fas fa-trash"></i> Delete Permanently
      </button>
    </div>
  </div>
</div>

<!-- ── INACTIVITY WARN MODAL ── -->
<div class="modal-overlay" id="forceWarnModal">
  <div class="modal-box">
    <div class="modal-head amber-head">
      <h4><i class="fas fa-bell" style="margin-right:8px"></i>Send Inactivity Warning</h4>
      <button class="modal-close" id="closeWarnModal"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body">
      <p style="font-size:.88rem">
        Send an inactivity warning via <strong>email + SMS</strong> to <strong id="warnCreatorName"></strong>?
      </p>
      <p style="font-size:.78rem;color:var(--text-3);margin-top:10px">
        This sets their scheduled deletion date to
        <strong><?= $settings['grace_period_days'] ?> days</strong> from today.
      </p>
    </div>
    <div class="modal-foot">
      <button class="btn-modal btn-cancel-modal" id="cancelWarnBtn">Cancel</button>
      <button class="btn-modal btn-amber-modal" id="confirmWarn">
        <i class="fas fa-paper-plane"></i> Send Warning
      </button>
    </div>
  </div>
</div>

<!-- ── MANUAL REMINDER MODAL ── -->
<div class="modal-overlay" id="manualReminderModal">
  <div class="modal-box wide">
    <div class="modal-head blue-head">
      <h4><i class="fas fa-paper-plane" style="margin-right:8px"></i>Send Manual Reminder</h4>
      <button class="modal-close" id="closeReminderModal"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body" style="display:flex;flex-direction:column;gap:16px">

      <!-- Recipient info -->
      <div class="recipient-info" id="reminderRecipientBox">
        <strong style="display:block;margin-bottom:4px">
          <i class="fas fa-user" style="margin-right:5px"></i>
          Sending to: <span id="reminderCreatorName" style="color:var(--blue)"></span>
        </strong>
        <div id="reminderRecipientInfo"></div>
      </div>

      <!-- Channel selection -->
      <div class="form-row">
        <label class="form-label">Send via</label>
        <div class="channel-row">
          <label class="channel-check">
            <input type="checkbox" id="reminderViaEmail" checked>
            <i class="fas fa-envelope" style="color:var(--blue)"></i> Email
          </label>
          <label class="channel-check">
            <input type="checkbox" id="reminderViaSms" checked>
            <i class="fas fa-mobile-alt" style="color:var(--blue)"></i> SMS
          </label>
        </div>
      </div>

      <!-- Custom message -->
      <div class="form-row">
        <label class="form-label">
          Custom Message
          <span style="font-weight:400;color:var(--text-3)">(optional)</span>
        </label>
        <textarea class="reminder-textarea" id="reminderMessage"
          placeholder="Leave blank to use the default inactivity warning template…"></textarea>
        <div class="form-hint">
          <i class="fas fa-info-circle" style="margin-right:3px"></i>
          If left blank, the default warning template from Settings will be used.
        </div>
      </div>

    </div>
    <div class="modal-foot">
      <button class="btn-modal btn-cancel-modal" id="cancelReminderBtn">Cancel</button>
      <button class="btn-modal btn-blue-modal" id="confirmReminder">
        <i class="fas fa-paper-plane"></i> Send Reminder
      </button>
    </div>
  </div>
</div>

<!-- ══════════════════════════════════════════════════════
     SCRIPTS  — NOTE: NO PHP CODE INSIDE THIS BLOCK
══════════════════════════════════════════════════════ -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
<script>
/* ─────────────────────────────────────────
   TABS  (fixed — stray PHP block removed)
───────────────────────────────────────── */
document.querySelectorAll('.tab-btn').forEach(btn => {
  btn.addEventListener('click', () => {
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    document.querySelectorAll('.tab-pane').forEach(p => p.classList.remove('active'));
    btn.classList.add('active');
    document.getElementById(btn.dataset.tab).classList.add('active');
  });
});

/* ─────────────────────────────────────────
   FILTER FORM HELPERS
───────────────────────────────────────── */
document.getElementById('perPageSel')?.addEventListener('change', () => {
  document.getElementById('pageHidden').value = '1';
  document.getElementById('filterForm').submit();
});
document.getElementById('searchInput')?.addEventListener('keydown', e => {
  if (e.key === 'Enter') document.getElementById('pageHidden').value = '1';
});

/* ─────────────────────────────────────────
   MODAL HELPERS
───────────────────────────────────────── */
const openModal  = id => document.getElementById(id).classList.add('open');
const closeModal = id => document.getElementById(id).classList.remove('open');

// Close on X / Cancel / backdrop click
['deleteModal','forceWarnModal','manualReminderModal'].forEach(id => {
  const el = document.getElementById(id);
  if (!el) return;
  el.addEventListener('click', e => { if (e.target === el) closeModal(id); });
});
document.getElementById('closeDeleteModal')?.addEventListener('click',   () => closeModal('deleteModal'));
document.getElementById('cancelDeleteBtn')?.addEventListener('click',    () => closeModal('deleteModal'));
document.getElementById('closeWarnModal')?.addEventListener('click',     () => closeModal('forceWarnModal'));
document.getElementById('cancelWarnBtn')?.addEventListener('click',      () => closeModal('forceWarnModal'));
document.getElementById('closeReminderModal')?.addEventListener('click', () => closeModal('manualReminderModal'));
document.getElementById('cancelReminderBtn')?.addEventListener('click',  () => closeModal('manualReminderModal'));

/* ─────────────────────────────────────────
   SETTINGS — TOGGLE
───────────────────────────────────────── */
document.getElementById('autoDelToggle')?.addEventListener('click', function () {
  this.classList.toggle('on');
  const v = this.classList.contains('on') ? '1' : '0';
  document.getElementById('s_auto_delete_enabled').value = v;
  document.getElementById('autoDelLabel').textContent = v === '1' ? 'Enabled' : 'Disabled';
});

/* ─────────────────────────────────────────
   SETTINGS — LIVE CALC
───────────────────────────────────────── */
function updateCalc() {
  const d = parseInt(document.getElementById('s_inactivity_days').value)  || 30;
  const g = parseInt(document.getElementById('s_grace_period_days').value) || 7;
  document.getElementById('warnDay').textContent   = d - g;
  document.getElementById('deleteDay').textContent = d;
}
document.getElementById('s_inactivity_days')?.addEventListener('input', updateCalc);
document.getElementById('s_grace_period_days')?.addEventListener('input', updateCalc);

/* ─────────────────────────────────────────
   SETTINGS — SAVE
───────────────────────────────────────── */
document.getElementById('saveSettingsBtn')?.addEventListener('click', async function () {
  const btn = this;
  btn.disabled = true;
  btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving…';
  const fd = new FormData();
  fd.append('action',                'save_settings');
  fd.append('inactivity_days',       document.getElementById('s_inactivity_days').value);
  fd.append('grace_period_days',     document.getElementById('s_grace_period_days').value);
  fd.append('auto_delete_enabled',   document.getElementById('s_auto_delete_enabled').value);
  fd.append('talksasa_api_key',      document.getElementById('s_talksasa_api_key').value);
  fd.append('talksasa_sender_id',    document.getElementById('s_talksasa_sender_id').value);
  fd.append('smtp_from_email',       document.getElementById('s_smtp_from_email').value);
  fd.append('smtp_from_name',        document.getElementById('s_smtp_from_name').value);
  fd.append('warning_email_subject', document.getElementById('s_warning_email_subject').value);
  try {
    const r = await fetch('inactivity_action.php', { method: 'POST', body: fd });
    const d = await r.json();
    Swal.fire({ icon: d.success ? 'success' : 'error', title: d.success ? 'Saved' : 'Error',
                text: d.message, confirmButtonText: 'OK' })
        .then(() => { if (d.success) location.reload(); });
  } catch {
    Swal.fire({ icon: 'error', title: 'Network Error', text: 'Could not save settings.' });
  } finally {
    btn.disabled = false;
    btn.innerHTML = '<i class="fas fa-save"></i> Save Settings';
  }
});

/* ─────────────────────────────────────────
   INACTIVITY SCAN PREVIEW
───────────────────────────────────────── */
document.getElementById('runPreviewBtn')?.addEventListener('click', async function () {
  this.disabled = true;
  document.getElementById('previewLoading').style.display = 'block';
  document.getElementById('previewResults').style.display = 'none';
  try {
    const fd = new FormData();
    fd.append('action', 'preview');
    const r = await fetch('inactivity_action.php', { method: 'POST', body: fd });
    const d = await r.json();
    document.getElementById('previewLoading').style.display = 'none';
    document.getElementById('previewResults').style.display = 'block';
    const tbody = document.getElementById('previewTbody');
    tbody.innerHTML = '';
    if (!d.success || !d.creators || d.creators.length === 0) {
      document.getElementById('previewEmpty').style.display = 'block';
      return;
    }
    document.getElementById('previewEmpty').style.display = 'none';
    const statusMap = {
      warn_pending: '<span class="badge badge-warned">Warn Pending</span>',
      warned:       '<span class="badge" style="background:#fed7aa;color:#9a3412">Warned</span>',
      due_deletion: '<span class="badge badge-due">Due Deletion</span>',
    };
    d.creators.forEach(c => {
      const a = c.status === 'due_deletion'
        ? `<button class="btn-sm-ui btn-sm-blue preview-delete-btn"
             style="background:var(--red)"
             data-id="${c.id}" data-name="${esc(c.name)}">
             <i class="fas fa-trash" style="margin-right:4px"></i>Delete</button>`
        : c.status === 'warned'
          ? `<button class="btn-sm-ui btn-sm-blue preview-cancel-btn"
               style="background:var(--emerald)"
               data-id="${c.id}" data-name="${esc(c.name)}">
               <i class="fas fa-ban" style="margin-right:4px"></i>Cancel</button>`
          : `<button class="btn-sm-ui btn-sm-blue preview-warn-btn"
               style="background:var(--amber)"
               data-id="${c.id}" data-name="${esc(c.name)}">
               <i class="fas fa-bell" style="margin-right:4px"></i>Warn</button>`;
      tbody.innerHTML += `<tr>
        <td><strong>${esc(c.name)}</strong></td>
        <td>${esc(c.email)}</td>
        <td>${esc(c.phone || '—')}</td>
        <td><span class="badge badge-normal">${c.days_inactive}d</span></td>
        <td>${c.last_activity ? new Date(c.last_activity).toLocaleDateString('en-GB') : '—'}</td>
        <td>${statusMap[c.status] || c.status}</td>
        <td>${c.scheduled_delete ? new Date(c.scheduled_delete).toLocaleDateString('en-GB') : '—'}</td>
        <td>${a}</td></tr>`;
    });
  } catch {
    Swal.fire({ icon: 'error', title: 'Error', text: 'Scan failed.' });
    document.getElementById('previewLoading').style.display = 'none';
  } finally {
    this.disabled = false;
  }
});

document.getElementById('previewTbody')?.addEventListener('click', async e => {
  const w = e.target.closest('.preview-warn-btn');
  const c = e.target.closest('.preview-cancel-btn');
  const d = e.target.closest('.preview-delete-btn');
  if (w) await doAction('force_warn',      w.dataset.id, w.dataset.name, w);
  if (c) await doAction('cancel_deletion', c.dataset.id, c.dataset.name, c);
  if (d) {
    const ok = await Swal.fire({
      icon: 'warning', title: 'Permanently Delete?',
      text: `Delete ${d.dataset.name} and all data?`,
      showCancelButton: true, confirmButtonText: 'Delete',
      customClass: { confirmButton: 'swal-red' }
    });
    if (ok.isConfirmed) await doAction('force_delete', d.dataset.id, d.dataset.name, d);
  }
});

/* ─────────────────────────────────────────
   MAIN TABLE — FORCE WARN
───────────────────────────────────────── */
let currentWarnId = null;
document.addEventListener('click', e => {
  const btn = e.target.closest('.force-warn-btn');
  if (!btn) return;
  currentWarnId = btn.dataset.id;
  document.getElementById('warnCreatorName').textContent = btn.dataset.name;
  openModal('forceWarnModal');
});
document.getElementById('confirmWarn')?.addEventListener('click', async function () {
  if (!currentWarnId) return;
  this.disabled = true;
  this.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending…';
  await doAction('force_warn', currentWarnId, '', null);
  closeModal('forceWarnModal');
  location.reload();
});

/* ─────────────────────────────────────────
   MAIN TABLE — CANCEL DELETION
───────────────────────────────────────── */
document.addEventListener('click', async e => {
  const btn = e.target.closest('.cancel-deletion-btn');
  if (!btn) return;
  const ok = await Swal.fire({
    icon: 'question', title: 'Cancel Deletion?',
    text: `Remove scheduled deletion for ${btn.dataset.name}?`,
    showCancelButton: true, confirmButtonText: 'Yes, Cancel It'
  });
  if (ok.isConfirmed) {
    await doAction('cancel_deletion', btn.dataset.id, btn.dataset.name, btn);
    location.reload();
  }
});

/* ─────────────────────────────────────────
   MAIN TABLE — DELETE CREATOR
───────────────────────────────────────── */
let currentDeleteId = null;
document.addEventListener('click', e => {
  const btn = e.target.closest('.delete-creator-btn');
  if (!btn) return;
  currentDeleteId = btn.dataset.id;
  document.getElementById('deleteCreatorName').textContent = btn.dataset.name;
  openModal('deleteModal');
});
document.getElementById('confirmDelete')?.addEventListener('click', async function () {
  if (!currentDeleteId) return;
  const ok = await Swal.fire({
    icon: 'warning', title: 'Final Confirmation',
    text: 'Irreversible. All data permanently erased.',
    showCancelButton: true, confirmButtonText: 'Yes, Delete Everything',
    customClass: { confirmButton: 'swal-red' }
  });
  if (!ok.isConfirmed) return;
  this.disabled = true;
  this.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Deleting…';
  const fd = new FormData();
  fd.append('action', 'force_delete');
  fd.append('creator_id', currentDeleteId);
  try {
    const r = await fetch('inactivity_action.php', { method: 'POST', body: fd });
    const d = await r.json();
    if (d.success) {
      closeModal('deleteModal');
      await Swal.fire({ icon: 'success', title: 'Deleted', text: 'Creator permanently removed.' });
      location.reload();
    } else {
      Swal.fire({ icon: 'error', title: 'Error', text: d.message || 'Unknown error.' });
    }
  } catch {
    Swal.fire({ icon: 'error', title: 'Network Error', text: 'Request failed.' });
  } finally {
    this.disabled = false;
    this.innerHTML = '<i class="fas fa-trash"></i> Delete Permanently';
  }
});

/* ─────────────────────────────────────────
   MANUAL REMINDER
───────────────────────────────────────── */
let currentReminderId = null;

document.addEventListener('click', e => {
  const btn = e.target.closest('.manual-reminder-btn');
  if (!btn) return;

  currentReminderId = btn.dataset.id;
  const name  = btn.dataset.name  || '—';
  const email = btn.dataset.email || '—';
  const phone = btn.dataset.phone || '—';

  // Populate modal
  document.getElementById('reminderCreatorName').textContent = name;
  document.getElementById('reminderRecipientInfo').innerHTML =
    `<span style="font-family:var(--mono);display:block">
       <i class="fas fa-envelope" style="width:14px;margin-right:4px;color:var(--blue)"></i>${esc(email)}
     </span>
     <span style="font-family:var(--mono);display:block">
       <i class="fas fa-mobile-alt" style="width:14px;margin-right:4px;color:var(--blue)"></i>${esc(phone)}
     </span>`;

  // Reset fields
  document.getElementById('reminderViaEmail').checked = true;
  document.getElementById('reminderViaSms').checked   = true;
  document.getElementById('reminderMessage').value    = '';

  openModal('manualReminderModal');
});

document.getElementById('confirmReminder')?.addEventListener('click', async function () {
  if (!currentReminderId) return;

  const viaEmail = document.getElementById('reminderViaEmail').checked;
  const viaSms   = document.getElementById('reminderViaSms').checked;
  const message  = document.getElementById('reminderMessage').value.trim();

  if (!viaEmail && !viaSms) {
    Swal.fire({ icon: 'warning', title: 'Choose a channel', text: 'Select at least Email or SMS.' });
    return;
  }

  this.disabled = true;
  this.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending…';

  const fd = new FormData();
  fd.append('action',      'manual_reminder');
  fd.append('creator_id',  currentReminderId);
  fd.append('via_email',   viaEmail ? '1' : '0');
  fd.append('via_sms',     viaSms   ? '1' : '0');
  fd.append('message',     message);

  try {
    const r = await fetch('inactivity_action.php', { method: 'POST', body: fd });
    const d = await r.json();

    let html = `<p style="font-size:.88rem;margin:0 0 8px;text-align:left">
                  ${d.message || (d.success ? 'Reminder sent successfully.' : 'Unknown error.')}
                </p>`;
    if (d.email_status) {
      const ic = d.email_status === 'sent' ? 'var(--emerald)' : (d.email_status === 'failed' ? 'var(--red)' : 'var(--text-3)');
      html += `<p style="font-size:.8rem;margin:3px 0;text-align:left">
                 <i class="fas fa-envelope" style="color:${ic};margin-right:5px"></i>
                 Email: <strong style="color:${ic}">${d.email_status}</strong>
               </p>`;
    }
    if (d.sms_status) {
      const ic = d.sms_status === 'sent' ? 'var(--emerald)' : (d.sms_status === 'failed' ? 'var(--red)' : 'var(--text-3)');
      html += `<p style="font-size:.8rem;margin:3px 0;text-align:left">
                 <i class="fas fa-mobile-alt" style="color:${ic};margin-right:5px"></i>
                 SMS: <strong style="color:${ic}">${d.sms_status}</strong>
               </p>`;
    }
    if (d.sms_detail && d.sms_status === 'failed') {
      html += `<div style="background:#fef2f2;border:1px solid #fca5a5;border-radius:8px;
               padding:8px 10px;margin-top:8px;font-size:.74rem;font-family:monospace;
               color:#991b1b;word-break:break-all;text-align:left">
               <strong>SMS detail:</strong><br>${esc(d.sms_detail)}</div>`;
    }

    closeModal('manualReminderModal');
    await Swal.fire({
      icon: d.success ? 'success' : 'error',
      title: d.success ? 'Reminder Sent' : 'Error',
      html,
      confirmButtonText: 'OK'
    });
    if (d.success) location.reload();

  } catch {
    Swal.fire({ icon: 'error', title: 'Network Error', text: 'Could not send reminder.' });
  } finally {
    this.disabled = false;
    this.innerHTML = '<i class="fas fa-paper-plane"></i> Send Reminder';
  }
});

/* ─────────────────────────────────────────
   SHARED ACTION HELPER
───────────────────────────────────────── */
async function doAction(action, id, name, btn) {
  if (btn) btn.disabled = true;
  const fd = new FormData();
  fd.append('action', action);
  fd.append('creator_id', id);
  try {
    const r = await fetch('inactivity_action.php', { method: 'POST', body: fd });
    const d = await r.json();
    let html = `<p style="font-size:.88rem;margin:0 0 6px;text-align:left">
                  ${d.message || (d.success ? 'Completed.' : 'Unknown error.')}
                </p>`;
    if (d.sms_detail && d.sms_status === 'failed') {
      html += `<div style="background:#fef2f2;border:1px solid #fca5a5;border-radius:8px;
               padding:10px 12px;margin-top:10px;font-size:.75rem;font-family:monospace;
               color:#991b1b;text-align:left;word-break:break-all">
               <strong>SMS detail:</strong><br>${d.sms_detail}</div>`;
    }
    await Swal.fire({
      icon: d.success ? 'success' : 'error',
      title: d.success ? 'Done' : 'Error',
      html,
      confirmButtonText: 'OK',
      allowOutsideClick: false,
      allowEscapeKey: false
    });
  } catch {
    await Swal.fire({ icon: 'error', title: 'Network Error', text: 'Request failed.' });
  } finally {
    if (btn) btn.disabled = false;
  }
}

/* ─────────────────────────────────────────
   UTILITY
───────────────────────────────────────── */
function esc(s) {
  const d = document.createElement('div');
  d.textContent = s || '';
  return d.innerHTML;
}

/* ─────────────────────────────────────────
   SILENT BACKGROUND CRON TRIGGER
───────────────────────────────────────── */
(async function () {
  try {
    const fd = new FormData();
    fd.append('action', 'run_cron');
    await fetch('inactivity_action.php', { method: 'POST', body: fd });
  } catch (e) { /* silent */ }
})();
</script>
</body>
</html>