<?php
/**
 * all_activity_settings.php
 * Activity Notification Control Center — Admin Page
 * Sits in the same admin folder as all_creators.php
 */
include '../msanii/includes/connect.php';
if (session_status() === PHP_SESSION_NONE) session_start();

if (!isset($_SESSION['user_role']) || !in_array($_SESSION['user_role'], [1, 2])) {
    header("Location: ../login.php");
    exit();
}

$dash_page = $_GET['page'] ?? null;

try {
    // ── Load all rules ──────────────────────────────────────
    $rules = $pdo->query("SELECT * FROM activity_notification_rules ORDER BY sort_order, id")
                 ->fetchAll(PDO::FETCH_ASSOC);

    // ── KPIs ────────────────────────────────────────────────
    $kpi = [];

    // Total creators
    $kpi['total_creators'] = (int)$pdo->query("SELECT COUNT(*) FROM creators")->fetchColumn();

    // Creators with no ticket ever
    $kpi['no_ticket'] = (int)$pdo->query("
        SELECT COUNT(*) FROM creators c
        WHERE NOT EXISTS (SELECT 1 FROM tickets t WHERE t.creator_id = c.id)
    ")->fetchColumn();

    // Creators with no purchase ever (on their tickets)
    $kpi['no_purchase'] = (int)$pdo->query("
        SELECT COUNT(*) FROM creators c
        WHERE NOT EXISTS (
            SELECT 1 FROM ticket_purchases tp
            JOIN tickets t ON t.id = tp.ticket_id
            WHERE t.creator_id = c.id AND tp.status = 'completed'
        )
    ")->fetchColumn();

    // Active rules count
    $kpi['active_rules'] = (int)$pdo->query("SELECT COUNT(*) FROM activity_notification_rules WHERE is_enabled = 1")->fetchColumn();

    // Campaigns total
    $kpi['campaigns'] = (int)$pdo->query("SELECT COUNT(*) FROM activity_campaigns")->fetchColumn();

    // Notifications sent (all time)
    $kpi['notifications_sent'] = (int)$pdo->query("SELECT COUNT(*) FROM activity_notification_log")->fetchColumn();

    // ── Campaigns list ──────────────────────────────────────
    $campaigns = $pdo->query("
        SELECT * FROM activity_campaigns ORDER BY created_at DESC LIMIT 50
    ")->fetchAll(PDO::FETCH_ASSOC);

    // ── Recent log ──────────────────────────────────────────
    $recent_log = $pdo->query("
        SELECT * FROM activity_notification_log ORDER BY sent_at DESC LIMIT 30
    ")->fetchAll(PDO::FETCH_ASSOC);

    // ── Group rules by type ─────────────────────────────────
    $rules_by_type = [];
    foreach ($rules as $r) {
        $rules_by_type[$r['rule_type']][] = $r;
    }

    $error = null;
} catch (PDOException $e) {
    $error = "Database error: " . htmlspecialchars($e->getMessage());
    $rules = $campaigns = $recent_log = [];
    $rules_by_type = [];
    $kpi = ['total_creators'=>0,'no_ticket'=>0,'no_purchase'=>0,'active_rules'=>0,'campaigns'=>0,'notifications_sent'=>0];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Activity Notifications — Keychele Admin</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800;900&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
<style>
/* ══════════════════════════════════════════
   DESIGN TOKENS — matches all_creators.php
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
  --pink:        #db2777;
  --pink-l:      #fce7f3;
  --teal:        #0d9488;
  --teal-l:      #ccfbf1;
  --orange:      #ea580c;
  --orange-l:    #ffedd5;

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
  background: linear-gradient(135deg, var(--violet), var(--blue));
  border-radius: 10px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  font-size: .85rem;
  color: #fff;
  flex-shrink: 0;
}
.pg-header-left p { color: var(--text-2); font-size: .82rem; margin-top: 3px; padding-left: 48px; }
.pg-header-actions { display: flex; gap: 8px; flex-wrap: wrap; }

.btn-primary-ui {
  display: inline-flex; align-items: center; gap: 7px;
  background: var(--blue); color: #fff;
  border: none; border-radius: var(--radius-sm);
  padding: 10px 20px;
  font-family: var(--font); font-size: .85rem; font-weight: 600;
  text-decoration: none; cursor: pointer;
  transition: background .16s, transform .14s, box-shadow .16s;
  box-shadow: 0 2px 10px rgba(37,99,235,.35);
}
.btn-primary-ui:hover { background: var(--blue-h); color:#fff; transform: translateY(-1px); box-shadow: 0 4px 16px rgba(37,99,235,.4); }
.btn-violet-ui {
  display: inline-flex; align-items: center; gap: 7px;
  background: var(--violet); color: #fff;
  border: none; border-radius: var(--radius-sm);
  padding: 10px 20px;
  font-family: var(--font); font-size: .85rem; font-weight: 600;
  text-decoration: none; cursor: pointer;
  transition: all .16s;
  box-shadow: 0 2px 10px rgba(124,58,237,.35);
}
.btn-violet-ui:hover { background: #6d28d9; color:#fff; transform: translateY(-1px); }

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
.kpi-bar { position: absolute; left:0;top:0;bottom:0; width:4px; border-radius: var(--radius) 0 0 var(--radius); }
.kpi.c-blue   .kpi-bar { background: var(--blue); }
.kpi.c-green  .kpi-bar { background: var(--emerald); }
.kpi.c-amber  .kpi-bar { background: var(--amber); }
.kpi.c-violet .kpi-bar { background: var(--violet); }
.kpi.c-sky    .kpi-bar { background: var(--sky); }
.kpi.c-pink   .kpi-bar { background: var(--pink); }

.kpi-icon {
  width: 36px; height: 36px; border-radius: 9px;
  display: flex; align-items: center; justify-content: center;
  font-size: .78rem; margin-bottom: 12px;
}
.kpi.c-blue   .kpi-icon { background: var(--blue-l);    color: var(--blue); }
.kpi.c-green  .kpi-icon { background: var(--emerald-l); color: var(--emerald); }
.kpi.c-amber  .kpi-icon { background: var(--amber-l);   color: var(--amber); }
.kpi.c-violet .kpi-icon { background: var(--violet-l);  color: var(--violet); }
.kpi.c-sky    .kpi-icon { background: var(--sky-l);     color: var(--sky); }
.kpi.c-pink   .kpi-icon { background: var(--pink-l);    color: var(--pink); }

.kpi-label { font-size: .7rem; font-weight: 600; text-transform: uppercase; letter-spacing: .07em; color: var(--text-3); margin-bottom: 4px; }
.kpi-value { font-size: 1.8rem; font-weight: 800; line-height: 1; letter-spacing: -.04em; }
.kpi.c-blue   .kpi-value { color: var(--blue); }
.kpi.c-green  .kpi-value { color: var(--emerald); }
.kpi.c-amber  .kpi-value { color: var(--amber); }
.kpi.c-violet .kpi-value { color: var(--violet); }
.kpi.c-sky    .kpi-value { color: var(--sky); }
.kpi.c-pink   .kpi-value { color: var(--pink); }
.kpi-sub { font-size: .72rem; color: var(--text-3); margin-top: 3px; }

/* ── TABS ── */
.tab-nav {
  display: flex; gap: 2px;
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius-sm);
  padding: 4px;
  width: fit-content;
  margin-bottom: 20px;
  flex-wrap: wrap;
}
.tab-btn {
  display: inline-flex; align-items: center; gap: 7px;
  padding: 8px 16px;
  border: none; background: transparent; border-radius: 6px;
  font-family: var(--font); font-size: .82rem; font-weight: 500;
  color: var(--text-2); cursor: pointer; transition: all .15s; white-space: nowrap;
}
.tab-btn.active { background: var(--blue); color: #fff; font-weight: 600; box-shadow: 0 2px 8px rgba(37,99,235,.3); }
.tab-btn i { font-size: .76rem; }
.tab-pane { display: none; }
.tab-pane.active { display: block; animation: fade-in .2s ease; }
@keyframes fade-in { from { opacity:.4; transform:translateY(5px); } to { opacity:1; transform:none; } }

/* ── CARD ── */
.card {
  background: var(--surface);
  border-radius: var(--radius);
  box-shadow: var(--shadow-sm);
  border: 1px solid var(--border);
  overflow: hidden;
  margin-bottom: 20px;
}
.card-head {
  display: flex; align-items: center; justify-content: space-between;
  gap: 12px; padding: 16px 20px;
  border-bottom: 1px solid var(--border);
  flex-wrap: wrap; background: var(--surface-2);
}
.card-title {
  font-size: .92rem; font-weight: 700; color: var(--navy);
  display: flex; align-items: center; gap: 8px;
}
.card-title i { color: var(--blue); font-size: .8rem; }
.card-body { padding: 20px; }

/* ── RULE CARD ── */
.rule-card {
  background: var(--surface);
  border: 1.5px solid var(--border);
  border-radius: var(--radius);
  overflow: hidden;
  transition: border-color .2s, box-shadow .2s;
  margin-bottom: 14px;
}
.rule-card:hover { border-color: var(--blue); box-shadow: var(--shadow); }
.rule-card.enabled { border-color: var(--emerald); }

.rule-header {
  display: flex; align-items: center; gap: 12px;
  padding: 14px 18px;
  background: var(--surface-2);
  cursor: pointer;
  user-select: none;
}
.rule-icon {
  width: 36px; height: 36px; border-radius: 9px;
  display: flex; align-items: center; justify-content: center;
  font-size: .8rem; flex-shrink: 0;
}
.rule-icon.ticket   { background: var(--sky-l);    color: var(--sky); }
.rule-icon.purchase { background: var(--emerald-l);color: var(--emerald); }
.rule-icon.nopurch  { background: var(--amber-l);  color: var(--amber); }
.rule-icon.milestone{ background: var(--violet-l); color: var(--violet); }

.rule-title-wrap { flex: 1; }
.rule-title { font-size: .9rem; font-weight: 700; color: var(--navy); }
.rule-desc  { font-size: .72rem; color: var(--text-3); margin-top: 2px; }

.rule-meta { display: flex; align-items: center; gap: 10px; }

/* Toggle */
.toggle-track {
  width: 40px; height: 22px; border-radius: 100px;
  background: var(--border-dark); position: relative;
  cursor: pointer; transition: background .2s; flex-shrink: 0;
}
.toggle-track.on { background: var(--emerald); }
.toggle-thumb {
  position: absolute; top: 3px; left: 3px;
  width: 16px; height: 16px; border-radius: 50%;
  background: #fff; box-shadow: 0 1px 4px rgba(0,0,0,.2);
  transition: transform .2s;
}
.toggle-track.on .toggle-thumb { transform: translateX(18px); }

.rule-chevron { color: var(--text-3); font-size: .75rem; transition: transform .2s; }
.rule-card.open .rule-chevron { transform: rotate(180deg); }

.rule-body { display: none; padding: 18px; border-top: 1px solid var(--border); }
.rule-card.open .rule-body { display: block; animation: fade-in .2s; }

/* Rule form grid */
.rule-form-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
  gap: 14px;
  margin-bottom: 16px;
}
.form-row { display: flex; flex-direction: column; gap: 4px; }
.form-label {
  font-size: .78rem; font-weight: 600; color: var(--text);
  display: flex; align-items: center; gap: 5px;
}
.form-label .ph-hint {
  font-size: .67rem; color: var(--text-3); font-weight: 400;
  font-family: var(--mono);
  background: var(--bg); border-radius: 4px; padding: 1px 5px;
}
.form-input, .form-select, .form-textarea {
  border: 1.5px solid var(--border-dark);
  border-radius: var(--radius-sm);
  font-family: var(--font); font-size: .83rem;
  color: var(--text); background: var(--surface);
  outline: none;
  transition: border .16s, box-shadow .16s;
}
.form-input   { height: 38px; padding: 0 12px; width: 100%; }
.form-select  { height: 38px; padding: 0 10px; width: 100%; cursor: pointer; }
.form-textarea { padding: 10px 12px; width: 100%; resize: vertical; min-height: 80px; }
.form-input:focus, .form-select:focus, .form-textarea:focus {
  border-color: var(--blue); box-shadow: 0 0 0 3px rgba(37,99,235,.1);
}
.form-hint { font-size: .7rem; color: var(--text-3); }

/* Channel checkboxes */
.channel-row { display: flex; gap: 10px; flex-wrap: wrap; }
.channel-check {
  display: flex; align-items: center; gap: 7px;
  background: var(--surface-2); border: 1.5px solid var(--border-dark);
  border-radius: var(--radius-sm); padding: 7px 14px;
  cursor: pointer; transition: border .15s, background .15s;
  font-size: .82rem; font-weight: 500; user-select: none;
}
.channel-check input { accent-color: var(--blue); width: 14px; height: 14px; cursor: pointer; }
.channel-check:has(input:checked) { border-color: var(--blue); background: var(--blue-ll); color: var(--blue); }

/* Rule footer */
.rule-footer {
  display: flex; justify-content: space-between; align-items: center;
  padding-top: 14px; border-top: 1px solid var(--border);
  margin-top: 14px; flex-wrap: wrap; gap: 10px;
}
.rule-stats { font-size: .72rem; color: var(--text-3); display: flex; align-items: center; gap: 12px; }
.rule-actions { display: flex; gap: 8px; }

/* Btn sizes */
.btn-sm-ui {
  height: 34px; padding: 0 14px;
  border: none; border-radius: var(--radius-sm);
  font-family: var(--font); font-size: .78rem; font-weight: 600;
  cursor: pointer; display: inline-flex; align-items: center; gap: 6px;
  transition: all .15s;
}
.btn-sm-blue    { background: var(--blue);    color: #fff; }
.btn-sm-blue:hover    { background: var(--blue-h); }
.btn-sm-green   { background: var(--emerald); color: #fff; }
.btn-sm-green:hover   { background: #047857; }
.btn-sm-amber   { background: var(--amber);   color: #fff; }
.btn-sm-amber:hover   { background: #b45309; }
.btn-sm-violet  { background: var(--violet);  color: #fff; }
.btn-sm-violet:hover  { background: #6d28d9; }
.btn-sm-ghost   { background: var(--bg); color: var(--text-2); border: 1px solid var(--border-dark); }
.btn-sm-ghost:hover   { color: var(--blue); border-color: var(--blue); background: var(--blue-ll); }
.btn-sm-red     { background: var(--red); color: #fff; }
.btn-sm-red:hover     { background: #b91c1c; }

/* Rule type section header */
.section-heading {
  display: flex; align-items: center; gap: 10px;
  margin: 24px 0 14px; padding-bottom: 10px;
  border-bottom: 2px solid var(--border);
}
.section-heading .s-icon {
  width: 30px; height: 30px; border-radius: 8px;
  display: flex; align-items: center; justify-content: center; font-size: .75rem;
}
.section-heading h3 { font-size: .95rem; font-weight: 800; color: var(--navy); letter-spacing: -.02em; }
.section-heading p  { font-size: .75rem; color: var(--text-3); margin-top: 1px; }
.section-heading .s-badge {
  margin-left: auto; font-size: .68rem; font-weight: 700;
  background: var(--blue-l); color: var(--blue);
  border-radius: 100px; padding: 3px 10px;
}

/* ── TABLE ── */
.tbl-wrap { overflow-x: auto; }
table { width: 100%; border-collapse: collapse; }
thead th {
  padding: 10px 14px; background: var(--surface-2);
  border-bottom: 1px solid var(--border);
  font-size: .68rem; font-weight: 700; text-transform: uppercase;
  letter-spacing: .08em; color: var(--text-3); white-space: nowrap;
}
tbody tr { border-bottom: 1px solid var(--border); transition: background .1s; }
tbody tr:last-child { border-bottom: none; }
tbody tr:hover { background: var(--blue-ll); }
td { padding: 11px 14px; font-size: .82rem; color: var(--text); vertical-align: middle; }

/* ── BADGES ── */
.badge {
  display: inline-flex; align-items: center; gap: 4px;
  padding: 3px 10px; border-radius: 100px;
  font-size: .67rem; font-weight: 700; white-space: nowrap;
}
.badge-blue     { background: var(--blue-l);    color: #1e40af; }
.badge-green    { background: var(--emerald-l); color: #065f46; }
.badge-amber    { background: var(--amber-l);   color: #92400e; }
.badge-red      { background: var(--red-l);     color: #991b1b; }
.badge-violet   { background: var(--violet-l);  color: #5b21b6; }
.badge-sky      { background: var(--sky-l);     color: #0369a1; }
.badge-gray     { background: var(--bg);        color: var(--text-2); }
.badge-pink     { background: var(--pink-l);    color: #9d174d; }
.badge-teal     { background: var(--teal-l);    color: #0f766e; }
.badge-sent     { background: var(--emerald-l); color: #065f46; }
.badge-failed   { background: var(--red-l);     color: #991b1b; }
.badge-skipped  { background: var(--bg);        color: var(--text-3); }
.badge-pending  { background: var(--amber-l);   color: #92400e; }
.badge-running  { background: var(--sky-l);     color: #0369a1; }
.badge-completed{ background: var(--emerald-l); color: #065f46; }
.badge-draft    { background: var(--bg);        color: var(--text-2); }
.badge-scheduled{ background: var(--violet-l);  color: #5b21b6; }
.badge-cancelled{ background: var(--red-l);     color: #991b1b; }

/* ── EMPTY ── */
.empty { text-align: center; padding: 50px 20px; color: var(--text-3); }
.empty i { font-size: 2rem; opacity: .3; display: block; margin-bottom: 12px; }
.empty p { font-size: .84rem; }

/* ── ALERT ── */
.alert-err {
  background: var(--red-l); border: 1px solid #fca5a5;
  border-radius: var(--radius-sm); padding: 12px 16px;
  color: #991b1b; font-size: .84rem; margin-bottom: 20px;
  display: flex; align-items: center; gap: 8px;
}

/* ── PAGI ── */
.pagi-wrap {
  display: flex; align-items: center; justify-content: space-between;
  padding: 12px 20px; border-top: 1px solid var(--border);
  background: var(--surface-2);
}
.pagi-info { font-size: .74rem; color: var(--text-3); }

/* ── CAMPAIGN PROGRESS BAR ── */
.prog-wrap { background: var(--border); border-radius: 100px; height: 6px; width: 100%; min-width: 80px; }
.prog-bar  { height: 6px; border-radius: 100px; background: var(--emerald); transition: width .3s; }

/* ── MODAL ── */
.modal-overlay {
  display: none; position: fixed; inset: 0;
  background: rgba(13,27,62,.5); backdrop-filter: blur(4px);
  z-index: 9000; align-items: center; justify-content: center;
}
.modal-overlay.open { display: flex; }
.modal-box {
  background: var(--surface); border-radius: 18px;
  box-shadow: var(--shadow-lg); width: 94%; max-width: 580px;
  overflow: hidden; animation: modal-pop .25s cubic-bezier(.16,1,.3,1);
  max-height: 92vh; display: flex; flex-direction: column;
}
.modal-box.wide { max-width: 720px; }
@keyframes modal-pop {
  from { opacity:0; transform:scale(.94) translateY(14px); }
  to   { opacity:1; transform:none; }
}
.modal-head {
  display: flex; align-items: center; justify-content: space-between;
  padding: 18px 22px; border-bottom: 1px solid var(--border); flex-shrink: 0;
}
.modal-head.violet-head { background: linear-gradient(135deg,#3b1a8a,var(--violet)); color:#fff; }
.modal-head.blue-head   { background: linear-gradient(135deg,#1e3a5f,var(--blue));   color:#fff; }
.modal-head.green-head  { background: linear-gradient(135deg,#064e3b,var(--emerald));color:#fff; }
.modal-head h4 { font-weight: 800; font-size: .95rem; margin:0; }
.modal-close { background:none; border:none; color:inherit; font-size:1.1rem; cursor:pointer; opacity:.75; }
.modal-close:hover { opacity:1; }
.modal-scroll { overflow-y: auto; flex: 1; }
.modal-body { padding: 22px; display: flex; flex-direction: column; gap: 16px; }
.modal-foot { padding: 16px 22px; border-top:1px solid var(--border); display:flex; gap:9px; justify-content:flex-end; flex-shrink:0; }
.btn-modal {
  height: 38px; padding: 0 20px;
  border: none; border-radius: var(--radius-sm);
  font-family: var(--font); font-weight: 600; font-size: .82rem;
  cursor: pointer; transition: opacity .15s;
  display: inline-flex; align-items: center; gap: 6px;
}
.btn-modal:disabled { opacity:.55; cursor:not-allowed; }
.btn-cancel-modal  { background: var(--bg); color:var(--text); border:1px solid var(--border-dark); }
.btn-blue-modal    { background: var(--blue);    color:#fff; }
.btn-green-modal   { background: var(--emerald); color:#fff; }
.btn-violet-modal  { background: var(--violet);  color:#fff; }
.btn-red-modal     { background: var(--red);     color:#fff; }
.btn-amber-modal   { background: var(--amber);   color:#fff; }

/* placeholder tag pills */
.ph-pills { display: flex; flex-wrap: wrap; gap: 5px; margin-top: 4px; }
.ph-pill {
  font-size: .68rem; font-family: var(--mono);
  background: var(--violet-l); color: var(--violet);
  border-radius: 5px; padding: 2px 8px; cursor: pointer;
  border: 1px solid #c4b5fd; transition: background .12s;
  user-select: none;
}
.ph-pill:hover { background: #ddd6fe; }

/* Recipient preview list */
.recipient-preview {
  background: var(--surface-2); border: 1px solid var(--border);
  border-radius: var(--radius-sm); padding: 10px 14px;
  font-size: .78rem; max-height: 140px; overflow-y: auto;
}
.recipient-preview .rp-item {
  display: flex; align-items: center; gap: 8px;
  padding: 4px 0; border-bottom: 1px solid var(--border);
}
.recipient-preview .rp-item:last-child { border-bottom: none; }

/* Info banner */
.info-banner {
  background: var(--blue-ll); border: 1px solid #bfdbfe;
  border-radius: var(--radius-sm); padding: 10px 14px;
  font-size: .79rem; color: #1e40af;
  display: flex; align-items: flex-start; gap: 8px;
}
.info-banner i { margin-top: 1px; flex-shrink: 0; }

/* progress stats row */
.stats-row { display: flex; gap: 16px; flex-wrap: wrap; }
.stat-pill {
  display: flex; flex-direction: column; align-items: center;
  background: var(--surface-2); border: 1px solid var(--border);
  border-radius: var(--radius-sm); padding: 10px 18px; min-width: 80px;
}
.stat-pill .sp-val { font-size: 1.3rem; font-weight: 800; line-height: 1; }
.stat-pill .sp-lbl { font-size: .67rem; color: var(--text-3); text-transform: uppercase; letter-spacing:.05em; margin-top: 2px; }

/* SweetAlert */
.swal2-popup  { font-family: var(--font) !important; border-radius: 16px !important; }
.swal2-title  { font-family: var(--font) !important; font-weight: 800 !important; }
.swal2-confirm { background: var(--blue) !important; border-radius: 8px !important; font-family: var(--font) !important; font-weight: 600 !important; }

@media (max-width:680px) {
  .shell { padding: 14px; }
  .rule-form-grid { grid-template-columns: 1fr; }
}
</style>
</head>
<body>
<div class="shell">

  <!-- ── HEADER ── -->
  <div class="pg-header">
    <div class="pg-header-left">
      <h1>
        <span class="icon-wrap"><i class="fas fa-bell-concierge"></i></span>
        Activity Notifications
      </h1>
      <p>Automate and manually send creator notifications · Ticket rules · Purchase milestones · Campaigns</p>
    </div>
    <div class="pg-header-actions">
      <button class="btn-violet-ui" id="openCampaignBtn">
        <i class="fas fa-paper-plane"></i> New Campaign
      </button>
      <button class="btn-primary-ui" id="addMilestoneBtn">
        <i class="fas fa-plus"></i> Add Milestone
      </button>
    </div>
  </div>

  <?php if ($error): ?>
    <div class="alert-err"><i class="fas fa-exclamation-circle"></i> <?= $error ?></div>
  <?php endif; ?>

  <!-- ── KPI STRIP ── -->
  <div class="kpi-grid">
    <div class="kpi c-blue">
      <div class="kpi-bar"></div>
      <div class="kpi-icon"><i class="fas fa-users"></i></div>
      <div class="kpi-label">Total Creators</div>
      <div class="kpi-value"><?= number_format($kpi['total_creators']) ?></div>
      <div class="kpi-sub">registered</div>
    </div>
    <div class="kpi c-amber">
      <div class="kpi-bar"></div>
      <div class="kpi-icon"><i class="fas fa-ticket-simple"></i></div>
      <div class="kpi-label">No Ticket Yet</div>
      <div class="kpi-value"><?= number_format($kpi['no_ticket']) ?></div>
      <div class="kpi-sub">never created one</div>
    </div>
    <div class="kpi c-pink">
      <div class="kpi-bar"></div>
      <div class="kpi-icon"><i class="fas fa-cart-arrow-down"></i></div>
      <div class="kpi-label">No Purchase Yet</div>
      <div class="kpi-value"><?= number_format($kpi['no_purchase']) ?></div>
      <div class="kpi-sub">never received a sale</div>
    </div>
    <div class="kpi c-green">
      <div class="kpi-bar"></div>
      <div class="kpi-icon"><i class="fas fa-bolt"></i></div>
      <div class="kpi-label">Active Rules</div>
      <div class="kpi-value"><?= number_format($kpi['active_rules']) ?></div>
      <div class="kpi-sub">auto-triggered</div>
    </div>
    <div class="kpi c-violet">
      <div class="kpi-bar"></div>
      <div class="kpi-icon"><i class="fas fa-satellite-dish"></i></div>
      <div class="kpi-label">Campaigns</div>
      <div class="kpi-value"><?= number_format($kpi['campaigns']) ?></div>
      <div class="kpi-sub">total created</div>
    </div>
    <div class="kpi c-sky">
      <div class="kpi-bar"></div>
      <div class="kpi-icon"><i class="fas fa-envelope-circle-check"></i></div>
      <div class="kpi-label">Notif. Sent</div>
      <div class="kpi-value"><?= number_format($kpi['notifications_sent']) ?></div>
      <div class="kpi-sub">all time</div>
    </div>
  </div>

  <!-- ── TABS ── -->
  <div class="tab-nav">
    <button class="tab-btn active" data-tab="tab-rules"><i class="fas fa-sliders"></i> Rules</button>
    <button class="tab-btn" data-tab="tab-campaigns"><i class="fas fa-satellite-dish"></i> Campaigns</button>
    <button class="tab-btn" data-tab="tab-log"><i class="fas fa-history"></i> Notification Log</button>
    <button class="tab-btn" data-tab="tab-cron"><i class="fas fa-terminal"></i> Cron Setup</button>
  </div>

  <!-- ══════════════════════════════════════════════════
       TAB 1 — RULES
  ══════════════════════════════════════════════════ -->
  <div class="tab-pane active" id="tab-rules">

    <!-- ── Ticket Rules ── -->
    <div class="section-heading">
      <div class="s-icon" style="background:var(--sky-l);color:var(--sky)"><i class="fas fa-ticket-simple"></i></div>
      <div>
        <h3>Ticket Creation Rules</h3>
        <p>Notify creators based on their ticket-creation activity</p>
      </div>
      <span class="s-badge"><?= count($rules_by_type['ticket'] ?? []) ?> rules</span>
    </div>

    <?php foreach (($rules_by_type['ticket'] ?? []) as $rule): ?>
      <?= renderRuleCard($rule) ?>
    <?php endforeach; ?>
    <?php if (empty($rules_by_type['ticket'])): ?>
      <p style="font-size:.8rem;color:var(--text-3);margin-bottom:20px">No ticket rules found. Run the SQL schema first.</p>
    <?php endif; ?>

    <!-- ── No Purchase Rules ── -->
    <div class="section-heading">
      <div class="s-icon" style="background:var(--amber-l);color:var(--amber)"><i class="fas fa-cart-xmark"></i></div>
      <div>
        <h3>No Purchase / Dry Spell Rules</h3>
        <p>Engage creators who haven't received sales</p>
      </div>
      <span class="s-badge"><?= count($rules_by_type['no_purchase'] ?? []) ?> rules</span>
    </div>

    <?php foreach (($rules_by_type['no_purchase'] ?? []) as $rule): ?>
      <?= renderRuleCard($rule) ?>
    <?php endforeach; ?>
    <?php if (empty($rules_by_type['no_purchase'])): ?>
      <p style="font-size:.8rem;color:var(--text-3);margin-bottom:20px">No rules found.</p>
    <?php endif; ?>

    <!-- ── Purchase Milestone Rules ── -->
    <div class="section-heading">
      <div class="s-icon" style="background:var(--violet-l);color:var(--violet)"><i class="fas fa-trophy"></i></div>
      <div>
        <h3>Purchase Milestone Rules</h3>
        <p>Celebrate creators when they hit purchase milestones</p>
      </div>
      <span class="s-badge"><?= count($rules_by_type['purchase_milestone'] ?? []) ?> milestones</span>
    </div>

    <?php foreach (($rules_by_type['purchase_milestone'] ?? []) as $rule): ?>
      <?= renderRuleCard($rule) ?>
    <?php endforeach; ?>
    <?php if (empty($rules_by_type['purchase_milestone'])): ?>
      <p style="font-size:.8rem;color:var(--text-3);margin-bottom:20px">No milestone rules found.</p>
    <?php endif; ?>

  </div><!-- /tab-rules -->

  <!-- ══════════════════════════════════════════════════
       TAB 2 — CAMPAIGNS
  ══════════════════════════════════════════════════ -->
  <div class="tab-pane" id="tab-campaigns">
    <div class="card">
      <div class="card-head">
        <div class="card-title"><i class="fas fa-satellite-dish"></i> Campaigns</div>
        <button class="btn-sm-ui btn-sm-violet" id="openCampaignBtn2">
          <i class="fas fa-plus"></i> New Campaign
        </button>
      </div>
      <div class="tbl-wrap">
        <table>
          <thead>
            <tr>
              <th>Title</th><th>Target</th><th>Channels</th>
              <th>Recipients</th><th>Progress</th><th>Status</th>
              <th>Scheduled</th><th>Created</th><th style="text-align:right">Actions</th>
            </tr>
          </thead>
          <tbody>
          <?php if (!empty($campaigns)): ?>
            <?php foreach ($campaigns as $camp):
              $pct = $camp['total_recipients'] > 0
                ? round(($camp['sent_count'] / $camp['total_recipients']) * 100)
                : 0;
              $statusBadge = match($camp['status']) {
                'draft'     => 'badge-draft',
                'scheduled' => 'badge-scheduled',
                'running'   => 'badge-running',
                'completed' => 'badge-completed',
                'cancelled' => 'badge-cancelled',
                default     => 'badge-gray',
              };
              $targetLabel = match($camp['target_type']) {
                'all'        => 'All Creators',
                'no_ticket'  => 'No Ticket',
                'no_purchase'=> 'No Purchase',
                'custom'     => 'Custom',
                default      => ucfirst($camp['target_type']),
              };
            ?>
            <tr>
              <td><strong><?= htmlspecialchars($camp['title']) ?></strong></td>
              <td><span class="badge badge-sky"><?= $targetLabel ?></span></td>
              <td>
                <?php if ($camp['channel_email']): ?>
                  <span class="badge badge-blue"><i class="fas fa-envelope"></i> Email</span>
                <?php endif; ?>
                <?php if ($camp['channel_sms']): ?>
                  <span class="badge badge-green"><i class="fas fa-mobile-alt"></i> SMS</span>
                <?php endif; ?>
              </td>
              <td style="text-align:center"><?= number_format($camp['total_recipients']) ?></td>
              <td style="min-width:100px">
                <div class="prog-wrap"><div class="prog-bar" style="width:<?= $pct ?>%"></div></div>
                <div style="font-size:.67rem;color:var(--text-3);margin-top:3px">
                  <?= $camp['sent_count'] ?>/<?= $camp['total_recipients'] ?>
                  <?php if ($camp['failed_count']): ?>
                    · <span style="color:var(--red)"><?= $camp['failed_count'] ?> failed</span>
                  <?php endif; ?>
                </div>
              </td>
              <td><span class="badge <?= $statusBadge ?>"><?= ucfirst($camp['status']) ?></span></td>
              <td style="white-space:nowrap;font-size:.78rem">
                <?= $camp['scheduled_at'] ? date('d M Y H:i', strtotime($camp['scheduled_at'])) : '<span style="color:var(--text-3)">Immediate</span>' ?>
              </td>
              <td style="white-space:nowrap;font-size:.78rem"><?= date('d M Y', strtotime($camp['created_at'])) ?></td>
              <td>
                <div style="display:flex;justify-content:flex-end;gap:5px">
                  <?php if (in_array($camp['status'], ['draft','scheduled'])): ?>
                    <button class="btn-sm-ui btn-sm-green send-campaign-btn"
                            data-id="<?= $camp['id'] ?>" data-title="<?= htmlspecialchars($camp['title']) ?>">
                      <i class="fas fa-paper-plane"></i> Send
                    </button>
                    <button class="btn-sm-ui btn-sm-ghost edit-campaign-btn"
                            data-id="<?= $camp['id'] ?>">
                      <i class="fas fa-edit"></i>
                    </button>
                  <?php elseif ($camp['status'] === 'completed'): ?>
                    <button class="btn-sm-ui btn-sm-ghost view-campaign-btn"
                            data-id="<?= $camp['id'] ?>" data-title="<?= htmlspecialchars($camp['title']) ?>">
                      <i class="fas fa-eye"></i> Report
                    </button>
                  <?php endif; ?>
                  <?php if ($camp['status'] !== 'running'): ?>
                    <button class="btn-sm-ui btn-sm-red delete-campaign-btn"
                            data-id="<?= $camp['id'] ?>" data-title="<?= htmlspecialchars($camp['title']) ?>">
                      <i class="fas fa-trash"></i>
                    </button>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr><td colspan="9">
              <div class="empty">
                <i class="fas fa-satellite-dish"></i>
                <p>No campaigns yet. Create your first campaign!</p>
              </div>
            </td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div><!-- /tab-campaigns -->

  <!-- ══════════════════════════════════════════════════
       TAB 3 — NOTIFICATION LOG
  ══════════════════════════════════════════════════ -->
  <div class="tab-pane" id="tab-log">
    <div class="card">
      <div class="card-head">
        <div class="card-title"><i class="fas fa-history"></i> Notification Log
          <span style="font-weight:400;font-size:.75rem;color:var(--text-3)">(last 30)</span>
        </div>
        <div style="display:flex;gap:8px">
          <select class="form-select" id="logFilter" style="width:160px;height:34px">
            <option value="">All sources</option>
            <option value="rule">Rules (auto)</option>
            <option value="campaign">Campaigns</option>
            <option value="manual">Manual</option>
          </select>
        </div>
      </div>
      <div class="tbl-wrap">
        <table>
          <thead>
            <tr>
              <th>Sent At</th><th>Creator</th><th>Source</th><th>Rule/Campaign</th>
              <th>Email</th><th>SMS</th><th>Preview</th><th>By</th>
            </tr>
          </thead>
          <tbody id="logTbody">
            <?php if (!empty($recent_log)): ?>
              <?php foreach ($recent_log as $log): ?>
              <tr data-source="<?= htmlspecialchars($log['source_type']) ?>">
                <td style="white-space:nowrap">
                  <?= date('d M Y', strtotime($log['sent_at'])) ?>
                  <div style="font-size:.68rem;color:var(--text-3);font-family:var(--mono)">
                    <?= date('H:i', strtotime($log['sent_at'])) ?>
                  </div>
                </td>
                <td>
                  <div style="font-weight:600"><?= htmlspecialchars($log['creator_name'] ?? '—') ?></div>
                  <div style="font-size:.69rem;color:var(--text-3)"><?= htmlspecialchars($log['creator_email'] ?? '') ?></div>
                </td>
                <td>
                  <?php $sc = match($log['source_type']) {
                    'rule'     => 'badge-sky',
                    'campaign' => 'badge-violet',
                    'manual'   => 'badge-amber',
                    default    => 'badge-gray',
                  }; ?>
                  <span class="badge <?= $sc ?>"><?= ucfirst($log['source_type']) ?></span>
                </td>
                <td style="font-size:.76rem;color:var(--text-2)">
                  <?= htmlspecialchars($log['rule_key'] ?? '—') ?>
                </td>
                <td><span class="badge badge-<?= $log['email_status'] ?? 'skipped' ?>"><?= ucfirst($log['email_status'] ?? '—') ?></span></td>
                <td><span class="badge badge-<?= $log['sms_status'] ?? 'skipped' ?>"><?= ucfirst($log['sms_status'] ?? '—') ?></span></td>
                <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:.75rem;color:var(--text-3)">
                  <?= htmlspecialchars($log['message_preview'] ?? '—') ?>
                </td>
                <td>
                  <span class="badge <?= $log['actioned_by'] === 'cron' ? 'badge-teal' : 'badge-blue' ?>">
                    <?= ucfirst(htmlspecialchars($log['actioned_by'])) ?>
                  </span>
                </td>
              </tr>
              <?php endforeach; ?>
            <?php else: ?>
              <tr><td colspan="8">
                <div class="empty"><i class="fas fa-inbox"></i><p>No notifications sent yet.</p></div>
              </td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
      <div class="pagi-wrap">
        <div class="pagi-info">Showing last <?= count($recent_log) ?> entries</div>
        <a href="?page=activity_log_full" class="btn-sm-ui btn-sm-ghost" style="height:30px;font-size:.75rem">View All</a>
      </div>
    </div>
  </div><!-- /tab-log -->

  <!-- ══════════════════════════════════════════════════
       TAB 4 — CRON SETUP
  ══════════════════════════════════════════════════ -->
  <div class="tab-pane" id="tab-cron">
    <div class="card">
      <div class="card-head">
        <div class="card-title"><i class="fas fa-terminal"></i> Cron Job Setup</div>
        <button class="btn-sm-ui btn-sm-green" id="manualCronBtn">
          <i class="fas fa-play"></i> Run Now Manually
        </button>
      </div>
      <div class="card-body">

        <div class="info-banner" style="margin-bottom:16px">
          <i class="fas fa-info-circle"></i>
          <div>
            The cron job checks all active rules daily and fires notifications. Add the entry below to your server's crontab.
            You can also trigger it manually using the button above.
          </div>
        </div>

        <div style="margin-bottom:18px">
          <div style="font-size:.82rem;font-weight:700;color:var(--navy);margin-bottom:8px">
            <i class="fas fa-clock" style="color:var(--blue);margin-right:5px"></i>
            Daily at 8:00 AM (recommended)
          </div>
          <div style="font-family:var(--mono);font-size:.75rem;background:var(--navy);color:#a5f3fc;
                      border-radius:var(--radius-sm);padding:14px 18px;line-height:1.8;word-break:break-all">
            0 8 * * * /usr/bin/php <?= dirname(__DIR__) ?>/admin/activity_notifications_cron.php >> /var/log/activity_notifications_cron.log 2>&1
          </div>
        </div>

        <div style="margin-bottom:18px">
          <div style="font-size:.82rem;font-weight:700;color:var(--navy);margin-bottom:8px">
            <i class="fas fa-clock" style="color:var(--blue);margin-right:5px"></i>
            Campaign scheduler check (every 5 minutes)
          </div>
          <div style="font-family:var(--mono);font-size:.75rem;background:var(--navy);color:#a5f3fc;
                      border-radius:var(--radius-sm);padding:14px 18px;line-height:1.8;word-break:break-all">
            */5 * * * * /usr/bin/php <?= dirname(__DIR__) ?>/admin/activity_notifications_cron.php --campaigns-only >> /var/log/activity_campaigns_cron.log 2>&1
          </div>
        </div>

        <div>
          <div style="font-size:.82rem;font-weight:700;color:var(--navy);margin-bottom:8px">
            <i class="fas fa-database" style="color:var(--blue);margin-right:5px"></i>
            Track purchase count on checkout (add to your payment success handler)
          </div>
          <div style="font-family:var(--mono);font-size:.75rem;background:var(--navy);color:#a5f3fc;
                      border-radius:var(--radius-sm);padding:14px 18px;line-height:1.8;word-break:break-all">
            // After confirming a successful purchase:<br>
            $count = $pdo->prepare("SELECT COUNT(*) FROM ticket_purchases tp JOIN tickets t ON t.id=tp.ticket_id WHERE t.creator_id=? AND tp.status='completed'");<br>
            $count->execute([$creator_id]);<br>
            $total = (int)$count->fetchColumn();<br>
            // The cron will pick up milestone changes automatically.
          </div>
        </div>

      </div>
    </div>
  </div><!-- /tab-cron -->

</div><!-- /shell -->

<!-- ══════════════════════════════════════════════════
     MODALS
══════════════════════════════════════════════════ -->

<!-- ── CAMPAIGN MODAL ── -->
<div class="modal-overlay" id="campaignModal">
  <div class="modal-box wide">
    <div class="modal-head violet-head">
      <h4><i class="fas fa-satellite-dish" style="margin-right:8px"></i><span id="campaignModalTitle">New Campaign</span></h4>
      <button class="modal-close" id="closeCampaignModal"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-scroll">
      <div class="modal-body">
        <input type="hidden" id="campaignId" value="">

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
          <div class="form-row" style="grid-column:1/-1">
            <label class="form-label">Campaign Title <span style="color:var(--red)">*</span></label>
            <input type="text" class="form-input" id="campTitle" placeholder="e.g. January Re-engagement Blast">
          </div>
          <div class="form-row">
            <label class="form-label">Target Audience</label>
            <select class="form-select" id="campTarget">
              <option value="all">All Creators</option>
              <option value="no_ticket">Creators with No Ticket</option>
              <option value="no_purchase">Creators with No Purchase</option>
            </select>
          </div>
          <div class="form-row">
            <label class="form-label">Schedule</label>
            <input type="datetime-local" class="form-input" id="campSchedule">
            <div class="form-hint">Leave blank to send immediately when triggered.</div>
          </div>
        </div>

        <div class="form-row">
          <label class="form-label">Send via</label>
          <div class="channel-row">
            <label class="channel-check">
              <input type="checkbox" id="campEmail" checked>
              <i class="fas fa-envelope" style="color:var(--blue)"></i> Email
            </label>
            <label class="channel-check">
              <input type="checkbox" id="campSms" checked>
              <i class="fas fa-mobile-alt" style="color:var(--blue)"></i> SMS
            </label>
          </div>
        </div>

        <div class="form-row" id="campEmailSection">
          <label class="form-label">Email Subject</label>
          <input type="text" class="form-input" id="campEmailSubject" placeholder="e.g. A message from the Keychele team">
        </div>

        <div class="form-row" id="campEmailBodySection">
          <label class="form-label">
            Email Body (HTML)
            <span class="ph-hint">{name} {platform} {days} {count}</span>
          </label>
          <div class="ph-pills">
            <?php foreach (['{name}','{platform}','{days}','{count}','{date}','{milestone}'] as $ph): ?>
              <span class="ph-pill" onclick="insertPlaceholder('campEmailBody','<?= $ph ?>')"><?= $ph ?></span>
            <?php endforeach; ?>
          </div>
          <textarea class="form-textarea" id="campEmailBody" style="min-height:120px"
            placeholder="<p>Hi {name},</p><p>Your message here...</p>"></textarea>
        </div>

        <div class="form-row" id="campSmsSection">
          <label class="form-label">
            SMS Body
            <span class="ph-hint">max ~320 chars</span>
          </label>
          <div class="ph-pills">
            <?php foreach (['{name}','{platform}','{days}','{count}','{date}'] as $ph): ?>
              <span class="ph-pill" onclick="insertPlaceholder('campSmsBody','<?= $ph ?>')"><?= $ph ?></span>
            <?php endforeach; ?>
          </div>
          <textarea class="form-textarea" id="campSmsBody" style="min-height:60px"
            placeholder="Hi {name}, message from Keychele. Visit keychele.com"></textarea>
          <div class="form-hint" id="campSmsCount">0 / 320 chars</div>
        </div>

        <!-- Recipient preview -->
        <div>
          <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px">
            <div class="form-label">Recipient Preview</div>
            <button class="btn-sm-ui btn-sm-ghost" style="height:28px;font-size:.72rem" id="previewRecipientsBtn">
              <i class="fas fa-eye"></i> Preview
            </button>
          </div>
          <div class="recipient-preview" id="recipientPreview">
            <div style="color:var(--text-3);font-size:.78rem;text-align:center;padding:10px">
              Click "Preview" to see recipients for the selected target.
            </div>
          </div>
        </div>

      </div>
    </div>
    <div class="modal-foot">
      <button class="btn-modal btn-cancel-modal" id="cancelCampaignBtn">Cancel</button>
      <button class="btn-modal btn-cancel-modal" id="saveDraftBtn"><i class="fas fa-save"></i> Save Draft</button>
      <button class="btn-modal btn-violet-modal" id="scheduleCampaignBtn"><i class="fas fa-paper-plane"></i> Save & Send</button>
    </div>
  </div>
</div>

<!-- ── ADD MILESTONE MODAL ── -->
<div class="modal-overlay" id="milestoneModal">
  <div class="modal-box">
    <div class="modal-head green-head">
      <h4><i class="fas fa-trophy" style="margin-right:8px"></i>Add Purchase Milestone</h4>
      <button class="modal-close" id="closeMilestoneModal"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-scroll">
      <div class="modal-body">
        <div class="info-banner">
          <i class="fas fa-info-circle"></i>
          <div>Fires once when a creator's total completed purchases reaches this exact number.</div>
        </div>
        <div class="form-row">
          <label class="form-label">Purchase Count (milestone number) <span style="color:var(--red)">*</span></label>
          <input type="number" class="form-input" id="msValue" min="1" placeholder="e.g. 25, 200, 1000">
        </div>
        <div class="form-row">
          <label class="form-label">Label</label>
          <input type="text" class="form-input" id="msLabel" placeholder="e.g. 200th Purchase Milestone">
        </div>
        <div class="form-row">
          <label class="form-label">Email Subject</label>
          <input type="text" class="form-input" id="msEmailSubject" placeholder="🎉 Congratulations on your {milestone} sale!">
        </div>
        <div class="form-row">
          <label class="form-label">Email Body</label>
          <div class="ph-pills">
            <?php foreach (['{name}','{platform}','{milestone}','{date}'] as $ph): ?>
              <span class="ph-pill" onclick="insertPlaceholder('msEmailBody','<?= $ph ?>')"><?= $ph ?></span>
            <?php endforeach; ?>
          </div>
          <textarea class="form-textarea" id="msEmailBody"
            placeholder="<p>Hi {name},</p><p>You've hit {milestone} purchases on {platform}!</p>"></textarea>
        </div>
        <div class="form-row">
          <label class="form-label">SMS Body</label>
          <textarea class="form-textarea" id="msSmsBody" style="min-height:60px"
            placeholder="Congrats {name}! {milestone} sales on {platform}. keychele.com"></textarea>
        </div>
        <div class="form-row">
          <label class="form-label">Send via</label>
          <div class="channel-row">
            <label class="channel-check">
              <input type="checkbox" id="msEmail" checked>
              <i class="fas fa-envelope" style="color:var(--blue)"></i> Email
            </label>
            <label class="channel-check">
              <input type="checkbox" id="msSms" checked>
              <i class="fas fa-mobile-alt" style="color:var(--blue)"></i> SMS
            </label>
          </div>
        </div>
      </div>
    </div>
    <div class="modal-foot">
      <button class="btn-modal btn-cancel-modal" id="cancelMilestoneBtn">Cancel</button>
      <button class="btn-modal btn-green-modal" id="saveMilestoneBtn"><i class="fas fa-save"></i> Save Milestone</button>
    </div>
  </div>
</div>

<!-- ── MANUAL SEND MODAL ── -->
<div class="modal-overlay" id="manualSendModal">
  <div class="modal-box">
    <div class="modal-head blue-head">
      <h4><i class="fas fa-play" style="margin-right:8px"></i>Manual Rule Trigger</h4>
      <button class="modal-close" id="closeManualModal"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body">
      <div class="info-banner">
        <i class="fas fa-info-circle"></i>
        <div>
          This will scan all creators right now against this rule and send notifications to those who qualify.
          This is a one-time manual trigger — it respects the "once" frequency setting.
        </div>
      </div>
      <div style="background:var(--surface-2);border:1px solid var(--border);border-radius:var(--radius-sm);padding:14px">
        <div style="font-weight:700;color:var(--navy)" id="manualRuleName"></div>
        <div style="font-size:.78rem;color:var(--text-3);margin-top:4px" id="manualRuleDesc"></div>
      </div>
      <div id="manualScanResults" style="display:none">
        <div style="font-size:.82rem;font-weight:600;color:var(--text);margin-bottom:8px">Creators that will receive this notification:</div>
        <div class="recipient-preview" id="manualRecipientList"></div>
        <div style="font-size:.76rem;color:var(--text-3);margin-top:6px" id="manualScanSummary"></div>
      </div>
    </div>
    <div class="modal-foot">
      <button class="btn-modal btn-cancel-modal" id="cancelManualBtn">Cancel</button>
      <button class="btn-modal btn-cancel-modal" id="scanRuleBtn"><i class="fas fa-search"></i> Scan First</button>
      <button class="btn-modal btn-blue-modal" id="confirmManualSend" disabled>
        <i class="fas fa-paper-plane"></i> Send to All Qualifying
      </button>
    </div>
  </div>
</div>

<?php
// ── PHP render helper for rule cards ───────────────────────
function renderRuleCard(array $r): string {
    $isEnabled  = (bool)$r['is_enabled'];
    $iconClass  = match($r['rule_type']) {
        'ticket'             => 'ticket',
        'no_purchase'        => 'nopurch',
        'purchase_milestone' => 'milestone',
        default              => 'ticket',
    };
    $iconFa = match($r['rule_type']) {
        'ticket'             => 'fa-ticket-simple',
        'no_purchase'        => 'fa-cart-xmark',
        'purchase_milestone' => 'fa-trophy',
        default              => 'fa-bell',
    };

    $threshold = $r['threshold_days'] !== null
        ? '<span class="badge badge-sky">' . $r['threshold_days'] . ' days</span>'
        : '';
    $milestone = $r['milestone_value'] !== null
        ? '<span class="badge badge-violet">' . number_format($r['milestone_value']) . ' purchases</span>'
        : '';

    $emailBody    = htmlspecialchars($r['email_body']    ?? '', ENT_QUOTES);
    $smsBody      = htmlspecialchars($r['sms_body']      ?? '', ENT_QUOTES);
    $emailSubject = htmlspecialchars($r['email_subject'] ?? '', ENT_QUOTES);

    $channelEmail = $r['channel_email'] ? 'checked' : '';
    $channelSms   = $r['channel_sms']   ? 'checked' : '';
    $freqRepeat   = ($r['frequency'] === 'repeat') ? 'selected' : '';
    $freqOnce     = ($r['frequency'] === 'once')   ? 'selected' : '';

    $enabledClass = $isEnabled ? 'enabled' : '';
    $toggleClass  = $isEnabled ? 'on' : '';

    ob_start(); ?>
    <div class="rule-card <?= $enabledClass ?>" id="rule-card-<?= $r['id'] ?>">
      <div class="rule-header" onclick="toggleRule(<?= $r['id'] ?>)">
        <div class="rule-icon <?= $iconClass ?>">
          <i class="fas <?= $iconFa ?>"></i>
        </div>
        <div class="rule-title-wrap">
          <div class="rule-title"><?= htmlspecialchars($r['label']) ?></div>
          <div class="rule-desc"><?= htmlspecialchars($r['description'] ?? '') ?></div>
        </div>
        <div class="rule-meta">
          <?= $threshold ?>
          <?= $milestone ?>
          <div class="toggle-track <?= $toggleClass ?>" id="toggle-<?= $r['id'] ?>"
               onclick="event.stopPropagation();toggleRuleEnable(<?= $r['id'] ?>, this)">
            <div class="toggle-thumb"></div>
          </div>
          <i class="fas fa-chevron-down rule-chevron"></i>
        </div>
      </div>
      <div class="rule-body" id="rule-body-<?= $r['id'] ?>">
        <div class="rule-form-grid">

          <?php if ($r['rule_type'] !== 'purchase_milestone'): ?>
          <div class="form-row">
            <label class="form-label">Threshold (days)</label>
            <input type="number" class="form-input" id="threshold_<?= $r['id'] ?>"
                   value="<?= (int)$r['threshold_days'] ?>" min="1" max="365"
                   placeholder="Days">
            <div class="form-hint">Trigger after this many days of inactivity.</div>
          </div>
          <?php else: ?>
          <div class="form-row">
            <label class="form-label">Milestone (purchase count)</label>
            <input type="number" class="form-input" id="milestone_<?= $r['id'] ?>"
                   value="<?= (int)$r['milestone_value'] ?>" min="1"
                   placeholder="e.g. 10">
          </div>
          <?php endif; ?>

          <div class="form-row">
            <label class="form-label">Frequency</label>
            <select class="form-select" id="frequency_<?= $r['id'] ?>">
              <option value="once"   <?= $freqOnce ?>>Send Once Only</option>
              <option value="repeat" <?= $freqRepeat ?>>Repeat Every N Days</option>
            </select>
          </div>

          <div class="form-row" id="repeat_wrap_<?= $r['id'] ?>"
               style="<?= $r['frequency'] === 'repeat' ? '' : 'display:none' ?>">
            <label class="form-label">Repeat Every (days)</label>
            <input type="number" class="form-input" id="repeat_<?= $r['id'] ?>"
                   value="<?= (int)($r['repeat_every_days'] ?? 7) ?>" min="1">
          </div>

          <div class="form-row">
            <label class="form-label">Send via</label>
            <div class="channel-row">
              <label class="channel-check">
                <input type="checkbox" id="ch_email_<?= $r['id'] ?>" <?= $channelEmail ?>>
                <i class="fas fa-envelope" style="color:var(--blue)"></i> Email
              </label>
              <label class="channel-check">
                <input type="checkbox" id="ch_sms_<?= $r['id'] ?>" <?= $channelSms ?>>
                <i class="fas fa-mobile-alt" style="color:var(--blue)"></i> SMS
              </label>
            </div>
          </div>

        </div><!-- /rule-form-grid -->

        <!-- Message Templates -->
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px">
          <div class="form-row">
            <label class="form-label">Email Subject</label>
            <input type="text" class="form-input" id="email_subject_<?= $r['id'] ?>"
                   value="<?= $emailSubject ?>"
                   placeholder="Subject line…">
          </div>
          <div class="form-row"></div>
          <div class="form-row">
            <label class="form-label">
              Email Body
              <span class="ph-hint">{name} {platform} {days} {count} {milestone}</span>
            </label>
            <div class="ph-pills">
              <?php foreach (['{name}','{platform}','{days}','{count}','{date}','{milestone}'] as $ph): ?>
                <span class="ph-pill" onclick="insertPlaceholder('email_body_<?= $r['id'] ?>','<?= $ph ?>')"><?= $ph ?></span>
              <?php endforeach; ?>
            </div>
            <textarea class="form-textarea" id="email_body_<?= $r['id'] ?>"
                      style="min-height:100px"><?= $emailBody ?></textarea>
          </div>
          <div class="form-row">
            <label class="form-label">
              SMS Body
              <span class="ph-hint">max ~320 chars</span>
            </label>
            <div class="ph-pills">
              <?php foreach (['{name}','{platform}','{days}','{count}','{date}'] as $ph): ?>
                <span class="ph-pill" onclick="insertPlaceholder('sms_body_<?= $r['id'] ?>','<?= $ph ?>')"><?= $ph ?></span>
              <?php endforeach; ?>
            </div>
            <textarea class="form-textarea" id="sms_body_<?= $r['id'] ?>"
                      style="min-height:60px"><?= $smsBody ?></textarea>
            <div class="form-hint" id="sms_count_<?= $r['id'] ?>"><?= strlen($r['sms_body'] ?? '') ?> / 320</div>
          </div>
        </div>

        <div class="rule-footer">
          <div class="rule-stats">
            <span><i class="fas fa-clock" style="margin-right:4px"></i>Created <?= date('d M Y', strtotime($r['created_at'])) ?></span>
            <span>Key: <code style="font-family:var(--mono);font-size:.68rem"><?= htmlspecialchars($r['rule_key']) ?></code></span>
          </div>
          <div class="rule-actions">
            <?php if ($r['rule_type'] === 'purchase_milestone'): ?>
              <button class="btn-sm-ui btn-sm-red delete-rule-btn"
                      data-id="<?= $r['id'] ?>" data-label="<?= htmlspecialchars($r['label']) ?>">
                <i class="fas fa-trash"></i> Delete
              </button>
            <?php endif; ?>
            <button class="btn-sm-ui btn-sm-amber manual-trigger-btn"
                    data-id="<?= $r['id'] ?>"
                    data-label="<?= htmlspecialchars($r['label']) ?>"
                    data-desc="<?= htmlspecialchars($r['description'] ?? '') ?>">
              <i class="fas fa-play"></i> Trigger Manually
            </button>
            <button class="btn-sm-ui btn-sm-blue save-rule-btn" data-id="<?= $r['id'] ?>">
              <i class="fas fa-save"></i> Save Rule
            </button>
          </div>
        </div>
      </div><!-- /rule-body -->
    </div>
    <?php
    return ob_get_clean();
}
?>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
<script>
/* ─── TABS ─────────────────────────────────────────────── */
document.querySelectorAll('.tab-btn').forEach(btn => {
  btn.addEventListener('click', () => {
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    document.querySelectorAll('.tab-pane').forEach(p => p.classList.remove('active'));
    btn.classList.add('active');
    document.getElementById(btn.dataset.tab).classList.add('active');
  });
});

/* ─── LOG FILTER ───────────────────────────────────────── */
document.getElementById('logFilter')?.addEventListener('change', function() {
  const val = this.value;
  document.querySelectorAll('#logTbody tr[data-source]').forEach(tr => {
    tr.style.display = (!val || tr.dataset.source === val) ? '' : 'none';
  });
});

/* ─── RULE CARD EXPAND ─────────────────────────────────── */
function toggleRule(id) {
  const card = document.getElementById('rule-card-' + id);
  card.classList.toggle('open');
}

/* ─── TOGGLE ENABLE/DISABLE ────────────────────────────── */
function toggleRuleEnable(id, el) {
  el.classList.toggle('on');
  const enabled = el.classList.contains('on') ? 1 : 0;
  const card = document.getElementById('rule-card-' + id);
  card.classList.toggle('enabled', !!enabled);
  postAction('toggle_rule', { rule_id: id, is_enabled: enabled })
    .then(d => {
      if (!d.success) Swal.fire({ icon:'error', title:'Error', text: d.message });
    });
}

/* ─── FREQUENCY SHOW/HIDE ──────────────────────────────── */
document.addEventListener('change', e => {
  const sel = e.target;
  if (!sel.id || !sel.id.startsWith('frequency_')) return;
  const id = sel.id.replace('frequency_', '');
  document.getElementById('repeat_wrap_' + id).style.display =
    sel.value === 'repeat' ? '' : 'none';
});

/* ─── SMS CHAR COUNT ───────────────────────────────────── */
document.addEventListener('input', e => {
  if (e.target.id && e.target.id.startsWith('sms_body_')) {
    const id = e.target.id.replace('sms_body_', '');
    const cEl = document.getElementById('sms_count_' + id);
    if (cEl) cEl.textContent = e.target.value.length + ' / 320';
  }
  if (e.target.id === 'campSmsBody') {
    document.getElementById('campSmsCount').textContent = e.target.value.length + ' / 320';
  }
});

/* ─── SAVE RULE ────────────────────────────────────────── */
document.addEventListener('click', async e => {
  const btn = e.target.closest('.save-rule-btn');
  if (!btn) return;
  const id = btn.dataset.id;
  btn.disabled = true;
  btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving…';

  const isPurchaseMilestone = document.getElementById('milestone_' + id) !== null;

  const payload = {
    rule_id:           id,
    threshold_days:    document.getElementById('threshold_' + id)?.value || null,
    milestone_value:   isPurchaseMilestone ? document.getElementById('milestone_' + id)?.value : null,
    frequency:         document.getElementById('frequency_' + id)?.value,
    repeat_every_days: document.getElementById('repeat_' + id)?.value || null,
    channel_email:     document.getElementById('ch_email_' + id)?.checked ? 1 : 0,
    channel_sms:       document.getElementById('ch_sms_' + id)?.checked   ? 1 : 0,
    email_subject:     document.getElementById('email_subject_' + id)?.value,
    email_body:        document.getElementById('email_body_' + id)?.value,
    sms_body:          document.getElementById('sms_body_' + id)?.value,
  };

  const d = await postAction('save_rule', payload);
  btn.disabled = false;
  btn.innerHTML = '<i class="fas fa-save"></i> Save Rule';
  Swal.fire({ icon: d.success ? 'success' : 'error', title: d.success ? 'Saved' : 'Error',
              text: d.message, timer: d.success ? 1500 : undefined, showConfirmButton: !d.success });
});

/* ─── DELETE RULE ──────────────────────────────────────── */
document.addEventListener('click', async e => {
  const btn = e.target.closest('.delete-rule-btn');
  if (!btn) return;
  const ok = await Swal.fire({
    icon:'warning', title:'Delete Milestone?',
    text: 'Delete rule "' + btn.dataset.label + '"? This cannot be undone.',
    showCancelButton: true, confirmButtonText: 'Delete'
  });
  if (!ok.isConfirmed) return;
  const d = await postAction('delete_rule', { rule_id: btn.dataset.id });
  if (d.success) {
    document.getElementById('rule-card-' + btn.dataset.id)?.remove();
    Swal.fire({ icon:'success', title:'Deleted', timer:1200, showConfirmButton:false });
  } else {
    Swal.fire({ icon:'error', title:'Error', text: d.message });
  }
});

/* ─── MANUAL TRIGGER ───────────────────────────────────── */
let currentManualRuleId = null;

document.addEventListener('click', e => {
  const btn = e.target.closest('.manual-trigger-btn');
  if (!btn) return;
  currentManualRuleId = btn.dataset.id;
  document.getElementById('manualRuleName').textContent = btn.dataset.label;
  document.getElementById('manualRuleDesc').textContent = btn.dataset.desc;
  document.getElementById('manualScanResults').style.display = 'none';
  document.getElementById('confirmManualSend').disabled = true;
  openModal('manualSendModal');
});

document.getElementById('scanRuleBtn')?.addEventListener('click', async function() {
  if (!currentManualRuleId) return;
  this.disabled = true;
  this.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Scanning…';
  const d = await postAction('scan_rule', { rule_id: currentManualRuleId });
  this.disabled = false;
  this.innerHTML = '<i class="fas fa-search"></i> Scan First';
  if (d.success) {
    const list = document.getElementById('manualRecipientList');
    const summary = document.getElementById('manualScanSummary');
    if (!d.creators || d.creators.length === 0) {
      list.innerHTML = '<div style="color:var(--text-3);text-align:center;padding:12px">No creators currently qualify for this rule.</div>';
      document.getElementById('confirmManualSend').disabled = true;
    } else {
      list.innerHTML = d.creators.map(c =>
        `<div class="rp-item">
          <i class="fas fa-user" style="color:var(--blue);font-size:.72rem"></i>
          <strong style="font-size:.8rem">${esc(c.name)}</strong>
          <span style="font-size:.72rem;color:var(--text-3)">${esc(c.email)}</span>
          ${c.days !== undefined ? '<span class="badge badge-amber" style="margin-left:auto">'+c.days+'d</span>' : ''}
        </div>`
      ).join('');
      summary.textContent = d.creators.length + ' creator(s) will receive this notification.';
      document.getElementById('confirmManualSend').disabled = false;
    }
    document.getElementById('manualScanResults').style.display = 'block';
  } else {
    Swal.fire({ icon:'error', title:'Scan Error', text: d.message });
  }
});

document.getElementById('confirmManualSend')?.addEventListener('click', async function() {
  if (!currentManualRuleId) return;
  this.disabled = true;
  this.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending…';
  const d = await postAction('manual_trigger_rule', { rule_id: currentManualRuleId });
  this.disabled = false;
  this.innerHTML = '<i class="fas fa-paper-plane"></i> Send to All Qualifying';
  closeModal('manualSendModal');
  await Swal.fire({
    icon: d.success ? 'success' : 'error',
    title: d.success ? 'Notifications Sent' : 'Error',
    text: d.message
  });
  if (d.success) location.reload();
});

/* ─── CAMPAIGN MODAL ───────────────────────────────────── */
['openCampaignBtn','openCampaignBtn2'].forEach(id => {
  document.getElementById(id)?.addEventListener('click', () => {
    resetCampaignModal();
    openModal('campaignModal');
  });
});

function resetCampaignModal() {
  document.getElementById('campaignId').value = '';
  document.getElementById('campaignModalTitle').textContent = 'New Campaign';
  document.getElementById('campTitle').value = '';
  document.getElementById('campTarget').value = 'all';
  document.getElementById('campSchedule').value = '';
  document.getElementById('campEmail').checked = true;
  document.getElementById('campSms').checked = true;
  document.getElementById('campEmailSubject').value = '';
  document.getElementById('campEmailBody').value = '';
  document.getElementById('campSmsBody').value = '';
  document.getElementById('campSmsCount').textContent = '0 / 320';
  document.getElementById('recipientPreview').innerHTML =
    '<div style="color:var(--text-3);font-size:.78rem;text-align:center;padding:10px">Click "Preview" to see recipients.</div>';
}

document.getElementById('previewRecipientsBtn')?.addEventListener('click', async function() {
  const target = document.getElementById('campTarget').value;
  this.disabled = true;
  this.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
  const d = await postAction('preview_campaign_recipients', { target_type: target });
  this.disabled = false;
  this.innerHTML = '<i class="fas fa-eye"></i> Preview';
  const box = document.getElementById('recipientPreview');
  if (d.success && d.creators) {
    box.innerHTML = d.creators.length === 0
      ? '<div style="color:var(--text-3);text-align:center;padding:10px">No creators match this filter.</div>'
      : d.creators.map(c =>
          `<div class="rp-item">
            <i class="fas fa-user" style="color:var(--blue);font-size:.68rem"></i>
            <span style="font-size:.78rem"><strong>${esc(c.name)}</strong></span>
            <span style="font-size:.7rem;color:var(--text-3)">${esc(c.email)}</span>
          </div>`
        ).join('') +
        (d.total > d.creators.length
          ? `<div style="text-align:center;font-size:.72rem;color:var(--text-3);padding:6px">…and ${d.total - d.creators.length} more</div>`
          : '') ;
    box.dataset.totalCaption = `${d.total} recipient(s)`;
  } else {
    box.innerHTML = '<div style="color:var(--red);text-align:center;padding:10px">Error loading preview.</div>';
  }
});

async function saveCampaign(status) {
  const title = document.getElementById('campTitle').value.trim();
  if (!title) { Swal.fire({ icon:'warning', title:'Missing Title', text:'Please enter a campaign title.' }); return; }

  const btn = status === 'draft' ? document.getElementById('saveDraftBtn') : document.getElementById('scheduleCampaignBtn');
  btn.disabled = true;
  btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving…';

  const d = await postAction('save_campaign', {
    campaign_id:   document.getElementById('campaignId').value,
    title,
    target_type:   document.getElementById('campTarget').value,
    channel_email: document.getElementById('campEmail').checked ? 1 : 0,
    channel_sms:   document.getElementById('campSms').checked ? 1 : 0,
    email_subject: document.getElementById('campEmailSubject').value,
    email_body:    document.getElementById('campEmailBody').value,
    sms_body:      document.getElementById('campSmsBody').value,
    scheduled_at:  document.getElementById('campSchedule').value,
    status,
  });

  btn.disabled = false;
  btn.innerHTML = status === 'draft'
    ? '<i class="fas fa-save"></i> Save Draft'
    : '<i class="fas fa-paper-plane"></i> Save & Send';

  if (d.success) {
    closeModal('campaignModal');
    await Swal.fire({ icon:'success', title:'Saved', text: d.message, timer:1800, showConfirmButton:false });
    location.reload();
  } else {
    Swal.fire({ icon:'error', title:'Error', text: d.message });
  }
}

document.getElementById('saveDraftBtn')?.addEventListener('click', () => saveCampaign('draft'));
document.getElementById('scheduleCampaignBtn')?.addEventListener('click', () => saveCampaign('scheduled'));

/* ─── SEND CAMPAIGN ────────────────────────────────────── */
document.addEventListener('click', async e => {
  const btn = e.target.closest('.send-campaign-btn');
  if (!btn) return;
  const ok = await Swal.fire({
    icon:'question', title:'Send Campaign?',
    text: 'Send "' + btn.dataset.title + '" now to all qualifying creators?',
    showCancelButton:true, confirmButtonText:'Yes, Send Now'
  });
  if (!ok.isConfirmed) return;
  btn.disabled = true;
  const d = await postAction('send_campaign', { campaign_id: btn.dataset.id });
  btn.disabled = false;
  Swal.fire({ icon: d.success ? 'success' : 'error', title: d.success ? 'Sent' : 'Error', text: d.message })
      .then(() => { if (d.success) location.reload(); });
});

/* ─── DELETE CAMPAIGN ──────────────────────────────────── */
document.addEventListener('click', async e => {
  const btn = e.target.closest('.delete-campaign-btn');
  if (!btn) return;
  const ok = await Swal.fire({
    icon:'warning', title:'Delete Campaign?',
    text: 'Delete "' + btn.dataset.title + '"?',
    showCancelButton:true, confirmButtonText:'Delete'
  });
  if (!ok.isConfirmed) return;
  const d = await postAction('delete_campaign', { campaign_id: btn.dataset.id });
  if (d.success) { location.reload(); }
  else Swal.fire({ icon:'error', title:'Error', text: d.message });
});

/* ─── ADD MILESTONE MODAL ──────────────────────────────── */
document.getElementById('addMilestoneBtn')?.addEventListener('click', () => openModal('milestoneModal'));
document.getElementById('saveMilestoneBtn')?.addEventListener('click', async function() {
  const val = document.getElementById('msValue').value.trim();
  if (!val || parseInt(val) < 1) {
    Swal.fire({ icon:'warning', title:'Invalid', text:'Enter a valid milestone number.' }); return;
  }
  this.disabled = true;
  this.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving…';
  const d = await postAction('add_milestone', {
    milestone_value: val,
    label:           document.getElementById('msLabel').value,
    email_subject:   document.getElementById('msEmailSubject').value,
    email_body:      document.getElementById('msEmailBody').value,
    sms_body:        document.getElementById('msSmsBody').value,
    channel_email:   document.getElementById('msEmail').checked ? 1 : 0,
    channel_sms:     document.getElementById('msSms').checked   ? 1 : 0,
  });
  this.disabled = false;
  this.innerHTML = '<i class="fas fa-save"></i> Save Milestone';
  if (d.success) {
    closeModal('milestoneModal');
    Swal.fire({ icon:'success', title:'Milestone Added', timer:1500, showConfirmButton:false })
        .then(() => location.reload());
  } else {
    Swal.fire({ icon:'error', title:'Error', text: d.message });
  }
});

/* ─── MANUAL CRON RUN ──────────────────────────────────── */
document.getElementById('manualCronBtn')?.addEventListener('click', async function() {
  const ok = await Swal.fire({
    icon:'question', title:'Run Cron Now?',
    text:'This will process all active rules against all creators right now.',
    showCancelButton:true, confirmButtonText:'Run Now'
  });
  if (!ok.isConfirmed) return;
  this.disabled = true;
  this.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Running…';
  const d = await postAction('run_cron', {});
  this.disabled = false;
  this.innerHTML = '<i class="fas fa-play"></i> Run Now Manually';
  Swal.fire({ icon: d.success ? 'success' : 'error', title: d.success ? 'Done' : 'Error', text: d.message });
  if (d.success) setTimeout(() => location.reload(), 2000);
});

/* ─── MODAL HELPERS ────────────────────────────────────── */
const openModal  = id => document.getElementById(id)?.classList.add('open');
const closeModal = id => document.getElementById(id)?.classList.remove('open');

['campaignModal','milestoneModal','manualSendModal'].forEach(id => {
  document.getElementById(id)?.addEventListener('click', e => {
    if (e.target === document.getElementById(id)) closeModal(id);
  });
});
document.getElementById('closeCampaignModal')?.addEventListener('click',  () => closeModal('campaignModal'));
document.getElementById('cancelCampaignBtn')?.addEventListener('click',   () => closeModal('campaignModal'));
document.getElementById('closeMilestoneModal')?.addEventListener('click', () => closeModal('milestoneModal'));
document.getElementById('cancelMilestoneBtn')?.addEventListener('click',  () => closeModal('milestoneModal'));
document.getElementById('closeManualModal')?.addEventListener('click',    () => closeModal('manualSendModal'));
document.getElementById('cancelManualBtn')?.addEventListener('click',     () => closeModal('manualSendModal'));

/* ─── PLACEHOLDER INSERT ───────────────────────────────── */
function insertPlaceholder(fieldId, ph) {
  const el = document.getElementById(fieldId);
  if (!el) return;
  const s = el.selectionStart, end = el.selectionEnd;
  el.value = el.value.substring(0, s) + ph + el.value.substring(end);
  el.focus();
  el.selectionStart = el.selectionEnd = s + ph.length;
}

/* ─── AJAX HELPER ──────────────────────────────────────── */
async function postAction(action, data) {
  const fd = new FormData();
  fd.append('action', action);
  for (const [k,v] of Object.entries(data)) {
    if (v !== null && v !== undefined) fd.append(k, v);
  }
  try {
    const r = await fetch('activity_notifications_action.php', { method:'POST', body:fd });
    return await r.json();
  } catch(e) {
    return { success: false, message: 'Network error: ' + e.message };
  }
}

function esc(s) {
  const d = document.createElement('div');
  d.textContent = s || '';
  return d.innerHTML;
}
</script>
</body>
</html>