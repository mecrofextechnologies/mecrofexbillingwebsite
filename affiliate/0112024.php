<?php
// ==================== AFFILIATE DASHBOARD (0112024.php) ====================
if (session_status() === PHP_SESSION_NONE) session_start();

$connect_paths = [
    __DIR__ . '/includes/connect.php',
    __DIR__ . '/../includes/connect.php',
    __DIR__ . '/../../msanii/includes/connect.php',
    dirname(__DIR__) . '/includes/connect.php',
    dirname(__DIR__) . '/msanii/includes/connect.php',
];
$connected = false;
foreach ($connect_paths as $path) {
    if (file_exists($path)) { require_once $path; $connected = true; break; }
}
if (!$connected) die('Database connection failed.');

if (empty($_SESSION['affiliate_id'])) {
    header("Location: ../login.php"); exit;
}

$affiliate_id = (int)$_SESSION['affiliate_id'];

date_default_timezone_set('Africa/Nairobi');
$current_hour = (int)date('H');
if ($current_hour >= 5 && $current_hour < 12)       { $greeting = "Good Morning";   $wave = "☀️"; }
elseif ($current_hour >= 12 && $current_hour < 17)  { $greeting = "Good Afternoon"; $wave = "🌤️"; }
elseif ($current_hour >= 17 && $current_hour < 21)  { $greeting = "Good Evening";   $wave = "🌇"; }
else                                                  { $greeting = "Good Night";     $wave = "🌙"; }

$current_date = date('l, d F Y');
$current_time = date('h:i A');

// ── Affiliate info ─────────────────────────────────────────────
$affiliate_name   = $_SESSION['affiliate_name']    ?? 'Affiliate';
$creator_name     = $_SESSION['affiliate_creator'] ?? 'Your Creator';
$commission_type  = 'percentage';
$commission_value = 10;
$affiliate_token  = '';

try {
    $stmt = $pdo->prepare("
        SELECT a.full_name, a.email, a.token, a.created_by,
               c.full_name AS creator_full_name,
               COALESCE(aset.commission_type,'percentage') AS commission_type,
               COALESCE(aset.commission_value,10)         AS commission_value
        FROM affiliates a
        LEFT JOIN creators c    ON c.id = a.created_by
        LEFT JOIN affiliate_settings aset ON aset.creator_id = a.created_by
        WHERE a.id = ? LIMIT 1
    ");
    $stmt->execute([$affiliate_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $affiliate_name   = $row['full_name'];
        $creator_name     = $row['creator_full_name'] ?? 'Your Creator';
        $commission_type  = $row['commission_type'];
        $commission_value = (float)$row['commission_value'];
        $affiliate_token  = $row['token'] ?? '';
        $_SESSION['affiliate_creator_id'] = (int)$row['created_by'];
    }
} catch (Exception $e) {}

$parts    = array_filter(explode(' ', trim($affiliate_name)));
$initials = count($parts) >= 2
    ? strtoupper(mb_substr($parts[0],0,1) . mb_substr(end($parts),0,1))
    : strtoupper(mb_substr($affiliate_name,0,2));

$commission_label = $commission_type === 'percentage'
    ? number_format($commission_value,0) . '%'
    : 'KSh ' . number_format($commission_value,0);

// ── STATS ──────────────────────────────────────────────────────

// Total purchases via affiliate link (all time)
$total_purchases = 0;
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM ticket_purchases WHERE affiliate_id = ?");
    $stmt->execute([$affiliate_id]);
    $total_purchases = (int)$stmt->fetchColumn();
} catch (Exception $e) {}

// Purchases this week
$week_start = date('Y-m-d 00:00:00', strtotime('-7 days'));
$week_purchases = 0;
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM ticket_purchases WHERE affiliate_id = ? AND created_at >= ?");
    $stmt->execute([$affiliate_id, $week_start]);
    $week_purchases = (int)$stmt->fetchColumn();
} catch (Exception $e) {}

// Purchases this month
$month_start = date('Y-m-01 00:00:00');
$month_purchases = 0;
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM ticket_purchases WHERE affiliate_id = ? AND created_at >= ?");
    $stmt->execute([$affiliate_id, $month_start]);
    $month_purchases = (int)$stmt->fetchColumn();
} catch (Exception $e) {}

// Earnings this week (paid commissions)
$earnings_week = 0;
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM affiliate_earnings WHERE affiliate_id = ? AND created_at >= ?");
    $stmt->execute([$affiliate_id, $week_start]);
    $earnings_week = (float)$stmt->fetchColumn();
} catch (Exception $e) {}

// Earnings this month
$earnings_month = 0;
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM affiliate_earnings WHERE affiliate_id = ? AND created_at >= ?");
    $stmt->execute([$affiliate_id, $month_start]);
    $earnings_month = (float)$stmt->fetchColumn();
} catch (Exception $e) {}

// Total withdrawn (completed withdrawals)
$total_withdrawn = 0;
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM affiliate_withdrawals WHERE affiliate_id = ? AND status = 'completed'");
    $stmt->execute([$affiliate_id]);
    $total_withdrawn = (float)$stmt->fetchColumn();
} catch (Exception $e) {}

// Account balance
$account_balance = 0;
try {
    $stmt = $pdo->prepare("SELECT COALESCE(balance,0) FROM affiliate_balances WHERE affiliate_id = ? LIMIT 1");
    $stmt->execute([$affiliate_id]);
    $account_balance = (float)$stmt->fetchColumn();
} catch (Exception $e) {}

// Pending commissions count
$pending_commissions = 0;
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM affiliate_earnings WHERE affiliate_id = ? AND status = 'pending'");
    $stmt->execute([$affiliate_id]);
    $pending_commissions = (int)$stmt->fetchColumn();
} catch (Exception $e) {}

// ── CHART DATA (last 6 months) ─────────────────────────────────
$chart_labels = []; $chart_purchases = []; $chart_earnings = [];
for ($i = 5; $i >= 0; $i--) {
    $m_start = date('Y-m-01', strtotime("-$i months"));
    $m_end   = date('Y-m-01', strtotime("-" . ($i-1) . " months"));
    $chart_labels[] = date('M', strtotime($m_start));

    $cnt = 0;
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM ticket_purchases WHERE affiliate_id=? AND created_at>=? AND created_at<?");
        $stmt->execute([$affiliate_id, $m_start, $m_end]);
        $cnt = (int)$stmt->fetchColumn();
    } catch (Exception $e) {}
    $chart_purchases[] = $cnt;

    $earn = 0;
    try {
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM affiliate_earnings WHERE affiliate_id=? AND created_at>=? AND created_at<?");
        $stmt->execute([$affiliate_id, $m_start, $m_end]);
        $earn = (float)$stmt->fetchColumn();
    } catch (Exception $e) {}
    $chart_earnings[] = $earn;
}

// ── RECENT PURCHASES via link ──────────────────────────────────
$recent_purchases = [];
try {
    $stmt = $pdo->prepare("
        SELECT tp.buyer_name, tp.buyer_phone, tp.ticket_number, tp.amount_paid,
               tp.quantity, tp.payment_status, tp.created_at, e.title AS event_title,
               ae.amount AS commission_earned, ae.status AS commission_status
        FROM ticket_purchases tp
        INNER JOIN events e ON e.id = tp.event_id
        LEFT JOIN affiliate_earnings ae ON ae.purchase_id = tp.id AND ae.affiliate_id = tp.affiliate_id
        WHERE tp.affiliate_id = ?
        ORDER BY tp.created_at DESC LIMIT 10
    ");
    $stmt->execute([$affiliate_id]);
    $recent_purchases = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// ── RECENT EARNINGS ────────────────────────────────────────────
$recent_earnings = [];
try {
    $stmt = $pdo->prepare("
        SELECT ae.amount, ae.status, ae.created_at, e.title AS event_title, tp.buyer_name
        FROM affiliate_earnings ae
        LEFT JOIN ticket_purchases tp ON tp.id = ae.purchase_id
        LEFT JOIN events e ON e.id = tp.event_id
        WHERE ae.affiliate_id = ?
        ORDER BY ae.created_at DESC LIMIT 6
    ");
    $stmt->execute([$affiliate_id]);
    $recent_earnings = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// ── RECENT WITHDRAWALS ─────────────────────────────────────────
$recent_withdrawals = [];
try {
    $stmt = $pdo->prepare("
        SELECT method, destination, amount, charge, total_amount,
               transaction_code, status, requested_at
        FROM affiliate_withdrawals
        WHERE affiliate_id = ?
        ORDER BY requested_at DESC LIMIT 5
    ");
    $stmt->execute([$affiliate_id]);
    $recent_withdrawals = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>Dashboard — Affiliate Portal · Keychele</title>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Outfit:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>

<style>
/* ══════════════════════════════════════════
   CSS VARIABLES — Green Affiliate Theme
══════════════════════════════════════════ */
:root {
  --green:      #0e9f6e;
  --green-d:    #065f46;
  --green-l:    #d1fae5;
  --green-xl:   #ecfdf5;
  --teal:       #0d9488;
  --teal-l:     #ccfbf1;
  --gold:       #d97706;
  --gold-l:     #fef3c7;
  --red:        #dc2626;
  --red-l:      #fee2e2;
  --warn:       #f59e0b;
  --warn-l:     #fef3c7;
  --navy:       #1e3a5f;
  --bg:         #f0fdf4;
  --bg2:        #dcfce7;
  --card:       #ffffff;
  --border:     #d1fae5;
  --border2:    #a7f3d0;
  --text:       #0d1f0d;
  --muted:      #6b7280;
  --r:          14px;
  --r-lg:       20px;
  --sh-sm:      0 2px 8px rgba(14,159,110,0.07);
  --sh:         0 6px 28px rgba(14,159,110,0.12);
  --sh-lg:      0 16px 56px rgba(14,159,110,0.18);
  --trn:        0.25s cubic-bezier(0.4,0,0.2,1);
  --af-w:       256px;
}

*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

body {
  font-family: 'Plus Jakarta Sans', sans-serif;
  background: var(--bg);
  color: var(--text);
  -webkit-font-smoothing: antialiased;
  min-height: 100vh;
}

/* ══════════════════════════════════════════
   LAYOUT WRAPPER (accounts for sidebar)
══════════════════════════════════════════ */
.af-content {
  margin-left: var(--af-w);
  padding: 1.75rem 1.75rem 5rem;
  transition: margin-left var(--trn);
  min-height: 100vh;
}
@media (max-width: 991px) {
  .af-content { margin-left: 0 !important; padding: 1rem 1rem 100px; }
}

/* ══════════════════════════════════════════
   WELCOME CARD
══════════════════════════════════════════ */
.welcome-card {
  position: relative; overflow: hidden;
  border-radius: var(--r-lg);
  background: linear-gradient(135deg, #064e3b 0%, #065f46 40%, #047857 70%, #059669 100%);
  box-shadow: var(--sh-lg), 0 0 0 1px rgba(255,255,255,0.06) inset;
  margin-bottom: 1.75rem;
  animation: slideUp .55s cubic-bezier(.16,1,.3,1) both;
}
@keyframes slideUp {
  from { opacity:0; transform: translateY(22px); }
  to   { opacity:1; transform: translateY(0); }
}

/* Grid texture */
.wc-grid {
  position: absolute; inset: 0; pointer-events: none;
  background-image:
    linear-gradient(rgba(255,255,255,0.03) 1px, transparent 1px),
    linear-gradient(90deg, rgba(255,255,255,0.03) 1px, transparent 1px);
  background-size: 38px 38px;
}

/* Orb glows */
.wc-orb { position: absolute; border-radius: 50%; pointer-events: none; }
.wc-orb-1 {
  width: 420px; height: 420px; top: -170px; right: -100px;
  background: radial-gradient(circle, rgba(110,231,183,.18) 0%, transparent 60%);
}
.wc-orb-2 {
  width: 240px; height: 240px; bottom: -100px; left: 15%;
  background: radial-gradient(circle, rgba(13,148,136,.22) 0%, transparent 60%);
}
.wc-orb-3 {
  width: 160px; height: 160px; top: 10px; left: 40%;
  background: radial-gradient(circle, rgba(217,119,6,.12) 0%, transparent 60%);
}

.wc-body {
  position: relative; z-index: 2;
  display: grid; grid-template-columns: 1fr auto;
  gap: 1.5rem; align-items: center;
  padding: 2rem 2.25rem;
}
@media (max-width: 640px) {
  .wc-body { grid-template-columns: 1fr; padding: 1.5rem; }
  .wc-right { display: none !important; }
}

.wc-tag {
  display: inline-flex; align-items: center; gap: 6px;
  background: rgba(110,231,183,0.15);
  border: 1px solid rgba(110,231,183,0.3);
  border-radius: 100px; padding: 4px 14px;
  font-family: 'Outfit', sans-serif;
  font-size: .65rem; font-weight: 700;
  letter-spacing: .09em; text-transform: uppercase;
  color: #6ee7b7; margin-bottom: .9rem;
}
.wc-tag::before {
  content: ''; width: 6px; height: 6px; border-radius: 50%;
  background: #6ee7b7; animation: gpulse 2s infinite;
}
@keyframes gpulse {
  0%,100% { box-shadow: 0 0 0 0 rgba(110,231,183,0.5); }
  50%      { box-shadow: 0 0 0 5px rgba(110,231,183,0); }
}

.wc-name {
  font-family: 'Outfit', sans-serif;
  font-weight: 900;
  font-size: clamp(1.5rem, 3.5vw, 2.3rem);
  letter-spacing: -0.04em; line-height: 1.1;
  color: white; margin-bottom: .4rem;
}
.wc-name .hi { color: rgba(255,255,255,.5); font-weight: 600; }
.wc-name .hl {
  background: linear-gradient(120deg, #6ee7b7, #a7f3d0, #34d399);
  -webkit-background-clip: text; -webkit-text-fill-color: transparent;
  background-clip: text;
}

.wc-date { font-size: .8rem; color: rgba(255,255,255,.4); margin-bottom: 1.4rem; }

.wc-chips { display: flex; flex-wrap: wrap; gap: .65rem; }

.wc-chip {
  display: flex; align-items: center; gap: 10px;
  background: rgba(255,255,255,.08);
  border: 1px solid rgba(255,255,255,.12);
  border-radius: 12px; padding: 9px 14px;
  backdrop-filter: blur(8px);
  transition: background .2s; cursor: default;
}
.wc-chip:hover { background: rgba(255,255,255,.14); }

.chip-ico {
  width: 30px; height: 30px; border-radius: 8px;
  display: flex; align-items: center; justify-content: center;
  font-size: .78rem; flex-shrink: 0;
}
.chip-lbl {
  display: block; font-size: .6rem; color: rgba(255,255,255,.45);
  letter-spacing: .05em; text-transform: uppercase; margin-bottom: 1px;
}
.chip-val {
  display: block;
  font-family: 'Outfit', sans-serif;
  font-weight: 700; font-size: .88rem;
  color: white; letter-spacing: -.02em;
}

/* ── Right side: avatar + clock ── */
.wc-right {
  display: flex; flex-direction: column; align-items: flex-end; gap: 6px;
}
.wc-avatar {
  width: 54px; height: 54px; border-radius: 50%;
  background: linear-gradient(135deg, #0e9f6e, #0d9488);
  display: flex; align-items: center; justify-content: center;
  font-family: 'Outfit', sans-serif;
  font-weight: 800; font-size: 1.3rem; color: white;
  border: 3px solid rgba(255,255,255,.2);
  box-shadow: 0 0 0 5px rgba(14,159,110,.25);
  align-self: flex-end; margin-bottom: 6px;
}
.wc-clock {
  font-family: 'Outfit', sans-serif;
  font-weight: 900;
  font-size: clamp(2.2rem, 4vw, 3.1rem);
  color: white; letter-spacing: -.06em; line-height: 1;
}
.wc-tz {
  font-size: .68rem; letter-spacing: .1em;
  text-transform: uppercase; color: rgba(255,255,255,.3);
}

/* ── Commission banner strip ── */
.comm-strip {
  position: relative; z-index: 2;
  margin: 0 1.5rem 1.5rem; padding: 10px 16px;
  background: rgba(255,255,255,.1);
  border: 1px solid rgba(255,255,255,.15);
  border-radius: 10px;
  display: flex; align-items: center; gap: 12px;
  backdrop-filter: blur(8px);
}
.comm-strip-ico {
  width: 32px; height: 32px; border-radius: 8px;
  background: rgba(217,119,6,.25);
  display: flex; align-items: center; justify-content: center;
  color: #fbbf24; font-size: .8rem; flex-shrink: 0;
}
.comm-strip-text {
  font-family: 'Outfit', sans-serif;
  font-size: .78rem; color: rgba(255,255,255,.7);
}
.comm-strip-text strong {
  color: #fbbf24; font-size: 1rem; font-weight: 800;
}
.comm-strip-pill {
  margin-left: auto;
  background: rgba(217,119,6,.25); border: 1px solid rgba(251,191,36,.3);
  border-radius: 100px; padding: 3px 10px;
  font-family: 'Outfit', sans-serif;
  font-size: .62rem; font-weight: 800;
  letter-spacing: .08em; text-transform: uppercase;
  color: #fbbf24;
}

/* ══════════════════════════════════════════
   SECTION HEADER
══════════════════════════════════════════ */
.sec-hd {
  display: flex; align-items: center; justify-content: space-between;
  margin-bottom: .9rem;
}
.sec-title {
  font-family: 'Outfit', sans-serif;
  font-weight: 700; font-size: .72rem;
  text-transform: uppercase; letter-spacing: .12em;
  color: var(--muted);
  display: flex; align-items: center; gap: 8px;
}
.sec-title::before {
  content: ''; width: 3px; height: 14px; border-radius: 3px;
  background: linear-gradient(180deg, var(--green), var(--teal));
}
.view-all {
  font-family: 'Outfit', sans-serif; font-size: .7rem; font-weight: 700;
  color: var(--green); text-decoration: none;
  display: flex; align-items: center; gap: 4px;
  transition: gap .2s;
}
.view-all:hover { gap: 8px; }
.view-all i { font-size: .55rem; }

/* ══════════════════════════════════════════
   STAT CARDS
══════════════════════════════════════════ */
.stats-grid {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 1rem; margin-bottom: 1.75rem;
}
@media (min-width: 900px) { .stats-grid { grid-template-columns: repeat(4, 1fr); } }

.sc {
  background: var(--card); border-radius: var(--r);
  padding: 1.3rem 1.35rem 1.15rem;
  border: 1.5px solid var(--border); box-shadow: var(--sh-sm);
  position: relative; overflow: hidden;
  transition: transform .22s, box-shadow .22s;
  cursor: default;
  animation: pop .5s cubic-bezier(.16,1,.3,1) both;
}
.sc:nth-child(1){animation-delay:.05s}
.sc:nth-child(2){animation-delay:.1s}
.sc:nth-child(3){animation-delay:.15s}
.sc:nth-child(4){animation-delay:.2s}
.sc:nth-child(5){animation-delay:.25s}
.sc:nth-child(6){animation-delay:.3s}

@keyframes pop {
  from { opacity:0; transform: translateY(16px) scale(0.97); }
  to   { opacity:1; transform: translateY(0) scale(1); }
}
.sc:hover { transform: translateY(-3px); box-shadow: var(--sh); }

.sc::before {
  content: ''; position: absolute;
  top: 0; left: 0; right: 0; height: 3px;
  border-radius: var(--r) var(--r) 0 0;
}
.sc.s-green::before  { background: linear-gradient(90deg, var(--green), var(--teal)); }
.sc.s-teal::before   { background: linear-gradient(90deg, var(--teal), #0891b2); }
.sc.s-gold::before   { background: linear-gradient(90deg, var(--gold), #f59e0b); }
.sc.s-navy::before   { background: linear-gradient(90deg, var(--navy), #1d4ed8); }
.sc.s-red::before    { background: linear-gradient(90deg, #e11d48, #f43f5e); }
.sc.s-purple::before { background: linear-gradient(90deg, #7c3aed, #a855f7); }

.sc::after {
  content: ''; position: absolute;
  bottom: -28px; right: -28px;
  width: 90px; height: 90px; border-radius: 50%;
  pointer-events: none; opacity: .1;
}
.sc.s-green::after  { background: var(--green); }
.sc.s-teal::after   { background: var(--teal); }
.sc.s-gold::after   { background: var(--gold); }
.sc.s-navy::after   { background: var(--navy); }
.sc.s-red::after    { background: var(--red); }
.sc.s-purple::after { background: #7c3aed; }

.sc-top {
  display: flex; align-items: flex-start;
  justify-content: space-between; margin-bottom: .9rem;
}
.sc-ico {
  width: 40px; height: 40px; border-radius: 10px;
  display: flex; align-items: center; justify-content: center;
  font-size: 1rem;
}
.s-green .sc-ico  { background: rgba(14,159,110,.1); color: var(--green); }
.s-teal .sc-ico   { background: rgba(13,148,136,.1); color: var(--teal); }
.s-gold .sc-ico   { background: rgba(217,119,6,.12); color: var(--gold); }
.s-navy .sc-ico   { background: rgba(30,58,95,.1); color: var(--navy); }
.s-red .sc-ico    { background: rgba(220,38,38,.1); color: var(--red); }
.s-purple .sc-ico { background: rgba(124,58,237,.1); color: #7c3aed; }

.sc-badge {
  font-family: 'Outfit', sans-serif;
  font-size: .58rem; font-weight: 700; letter-spacing: .03em;
  padding: 2px 9px; border-radius: 100px;
}
.bdg-up      { background: rgba(14,159,110,.1); color: var(--green-d); }
.bdg-neutral { background: rgba(107,114,128,.1); color: var(--muted); }
.bdg-live    { background: rgba(13,148,136,.1); color: var(--teal); }
.bdg-warn    { background: rgba(245,158,11,.1); color: #92400e; }

.sc-label {
  font-size: .72rem; color: var(--muted); margin-bottom: .25rem;
}
.sc-val {
  font-family: 'Outfit', sans-serif;
  font-weight: 800;
  font-size: clamp(1.05rem, 2vw, 1.45rem);
  letter-spacing: -.03em; color: var(--text); line-height: 1;
}
.sc-sub { font-size: .67rem; color: var(--muted); margin-top: 5px; }

/* ══════════════════════════════════════════
   CHARTS
══════════════════════════════════════════ */
.charts-row {
  display: grid; grid-template-columns: 1fr;
  gap: 1rem; margin-bottom: 1.75rem;
}
@media (min-width: 768px) { .charts-row { grid-template-columns: 1.35fr .65fr; } }

.chart-card {
  background: var(--card); border-radius: var(--r);
  border: 1.5px solid var(--border); box-shadow: var(--sh-sm); overflow: hidden;
}
.chart-top {
  display: flex; align-items: flex-start;
  justify-content: space-between; padding: 1.2rem 1.4rem 0;
}
.chart-ttl {
  font-family: 'Outfit', sans-serif;
  font-weight: 700; font-size: .85rem; color: var(--text);
}
.chart-sub { font-size: .7rem; color: var(--muted); margin-top: 2px; }
.chart-pill {
  display: flex; align-items: center; gap: 5px;
  background: var(--green-xl); border: 1px solid var(--border);
  border-radius: 8px; padding: 5px 10px;
  font-family: 'Outfit', sans-serif;
  font-size: .63rem; font-weight: 700; color: var(--green);
}
.chart-wrap { padding: 1rem 1.25rem 1.25rem; height: 230px; }

/* ══════════════════════════════════════════
   REFERRAL LINK CARD
══════════════════════════════════════════ */
.link-card {
  background: linear-gradient(135deg, var(--green-xl), #f0fdf4);
  border: 1.5px solid var(--border2);
  border-radius: var(--r);
  padding: 1.25rem 1.35rem;
  margin-bottom: 1.75rem;
  box-shadow: var(--sh-sm);
  animation: slideUp .6s cubic-bezier(.16,1,.3,1) .25s both;
}
.link-card-top {
  display: flex; align-items: center; justify-content: space-between;
  margin-bottom: 1rem;
}
.link-card-ttl {
  font-family: 'Outfit', sans-serif;
  font-weight: 700; font-size: .82rem; color: var(--green-d);
  display: flex; align-items: center; gap: 8px;
}
.link-card-ttl i { color: var(--green); }
.link-pill {
  background: var(--green-l); border: 1px solid var(--border2);
  border-radius: 100px; padding: 2px 10px;
  font-family: 'Outfit', sans-serif;
  font-size: .6rem; font-weight: 700;
  color: var(--green-d);
}

.link-row {
  display: flex; align-items: center; gap: .6rem;
  background: white; border: 1.5px solid var(--border);
  border-radius: 10px; padding: 10px 14px;
  margin-bottom: .7rem;
}
.link-row-icon {
  width: 30px; height: 30px; border-radius: 7px;
  background: var(--green-l); color: var(--green);
  display: flex; align-items: center; justify-content: center;
  font-size: .75rem; flex-shrink: 0;
}
.link-url {
  flex: 1; min-width: 0;
  font-size: .75rem; color: var(--muted);
  overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
  font-family: 'Courier New', monospace;
}
.copy-btn {
  display: inline-flex; align-items: center; gap: 6px;
  background: var(--green); color: white;
  border: none; border-radius: 8px; padding: 7px 14px;
  font-family: 'Outfit', sans-serif; font-size: .72rem; font-weight: 700;
  cursor: pointer; transition: all .2s; flex-shrink: 0;
}
.copy-btn:hover { background: var(--green-d); }
.copy-btn.copied { background: var(--teal); }
.link-hint {
  font-size: .7rem; color: var(--muted);
  display: flex; align-items: center; gap: 5px;
}
.link-hint i { color: var(--green); }

/* ══════════════════════════════════════════
   TABLES
══════════════════════════════════════════ */
.tables-row {
  display: grid; grid-template-columns: 1fr;
  gap: 1rem; margin-bottom: 1.25rem;
}
@media (min-width: 1024px) { .tables-row { grid-template-columns: 1.7fr 1fr; } }

.tbl-card {
  background: var(--card); border-radius: var(--r);
  border: 1.5px solid var(--border); box-shadow: var(--sh-sm); overflow: hidden;
}
.tbl-hd {
  display: flex; align-items: center; justify-content: space-between;
  padding: 1rem 1.35rem;
  border-bottom: 1px solid var(--border); background: var(--green-xl);
}
.tbl-ttl {
  display: flex; align-items: center; gap: 8px;
  font-family: 'Outfit', sans-serif;
  font-weight: 700; font-size: .8rem; color: var(--green-d);
}
.tbl-ico {
  width: 28px; height: 28px; border-radius: 7px;
  display: flex; align-items: center; justify-content: center; font-size: .7rem;
}
.tbl-scroll { overflow-x: auto; }

table { width: 100%; border-collapse: collapse; font-size: .79rem; }
thead th {
  padding: .6rem 1rem; text-align: left;
  font-family: 'Outfit', sans-serif;
  font-size: .6rem; font-weight: 700;
  text-transform: uppercase; letter-spacing: .08em;
  color: var(--muted); background: #f9fefb;
  border-bottom: 1px solid var(--border); white-space: nowrap;
}
tbody tr { border-bottom: 1px solid rgba(14,159,110,.06); transition: background .12s; }
tbody tr:last-child { border-bottom: none; }
tbody tr:hover { background: #f0fdf4; }
tbody td { padding: .76rem 1rem; vertical-align: middle; white-space: nowrap; }

.tp { font-weight: 600; font-size: .8rem; color: var(--text); }
.ts { font-size: .68rem; color: var(--muted); margin-top: 1px; }
.tm { font-size: .7rem; color: var(--muted); font-family: 'Courier New', monospace; }
.ta { font-family: 'Outfit', sans-serif; font-weight: 700; font-size: .82rem; letter-spacing: -.02em; }

/* Status badges */
.bdg {
  display: inline-flex; align-items: center; gap: 4px;
  padding: 3px 9px; border-radius: 100px;
  font-family: 'Outfit', sans-serif;
  font-size: .58rem; font-weight: 700;
  letter-spacing: .04em; text-transform: uppercase;
}
.bdg::before {
  content: ''; width: 4px; height: 4px;
  border-radius: 50%; flex-shrink: 0;
}
.b-paid,.b-completed,.b-success { background:rgba(14,159,110,.12); color:#065f46; }
.b-paid::before,.b-completed::before,.b-success::before { background:var(--green); }
.b-pending { background:rgba(245,158,11,.1); color:#92400e; }
.b-pending::before { background:var(--warn); }
.b-failed,.b-rejected { background:rgba(220,38,38,.1); color:var(--red); }
.b-failed::before,.b-rejected::before { background:var(--red); }
.b-processing { background:rgba(30,58,95,.1); color:var(--navy); }
.b-processing::before { background:var(--navy); }

.empty { padding: 2.5rem 1rem; text-align: center; }
.empty-ico {
  width: 50px; height: 50px; border-radius: 12px;
  background: var(--green-xl); margin: 0 auto .7rem;
  display: flex; align-items: center; justify-content: center;
  font-size: 1.3rem; color: var(--green); opacity: .7;
}
.empty p { font-size: .8rem; color: var(--muted); }

/* ══════════════════════════════════════════
   QUICK ACTIONS BAR
══════════════════════════════════════════ */
.quick-actions {
  display: flex; flex-wrap: wrap; gap: .75rem;
  margin-bottom: 1.75rem;
}
.qa-btn {
  display: inline-flex; align-items: center; gap: 8px;
  border-radius: 10px; padding: 10px 18px;
  font-family: 'Outfit', sans-serif; font-size: .78rem; font-weight: 700;
  text-decoration: none; border: none; cursor: pointer;
  transition: transform .18s, box-shadow .18s;
  white-space: nowrap;
}
.qa-btn:hover { transform: translateY(-2px); box-shadow: var(--sh); }
.qa-primary {
  background: linear-gradient(135deg, var(--green), var(--teal));
  color: white;
  box-shadow: 0 4px 14px rgba(14,159,110,.3);
}
.qa-secondary {
  background: white;
  border: 1.5px solid var(--border);
  color: var(--green-d);
  box-shadow: var(--sh-sm);
}
.qa-gold {
  background: linear-gradient(135deg, #d97706, #f59e0b);
  color: white;
  box-shadow: 0 4px 14px rgba(217,119,6,.3);
}

/* ══════════════════════════════════════════
   WITHDRAWAL CARD
══════════════════════════════════════════ */
.w-card {
  background: var(--card); border-radius: var(--r);
  border: 1.5px solid var(--border); box-shadow: var(--sh-sm);
  overflow: hidden; margin-bottom: 1.5rem;
}

/* ══════════════════════════════════════════
   FULL 6-COL STATS GRID (2nd row)
══════════════════════════════════════════ */
.stats-grid-3 {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 1rem; margin-bottom: 1.75rem;
}
@media (min-width: 900px) { .stats-grid-3 { grid-template-columns: repeat(3, 1fr); } }
</style>
</head>
<body>

<?php
// Sidebar is included once by index.php (the router).
// Do NOT include it again here — af_active() would be declared twice.
?>

<div class="af-content">

  <!-- ══ WELCOME CARD ══ -->
  <div class="welcome-card">
    <div class="wc-grid"></div>
    <div class="wc-orb wc-orb-1"></div>
    <div class="wc-orb wc-orb-2"></div>
    <div class="wc-orb wc-orb-3"></div>

    <div class="wc-body">
      <div>
        <div class="wc-tag"><?= $wave ?> Affiliate Dashboard</div>
        <h1 class="wc-name">
          <span class="hi"><?= $greeting ?>,</span><br>
          <span class="hl"><?= htmlspecialchars($affiliate_name) ?></span>
        </h1>
        <div class="wc-date"><?= $current_date ?></div>

        <div class="wc-chips">
          <div class="wc-chip">
            <div class="chip-ico" style="background:rgba(110,231,183,.2)">
              <i class="fa-solid fa-bolt" style="color:#6ee7b7"></i>
            </div>
            <div>
              <span class="chip-lbl">Week Earnings</span>
              <span class="chip-val">KSh <?= number_format($earnings_week,2) ?></span>
            </div>
          </div>

          <div class="wc-chip">
            <div class="chip-ico" style="background:rgba(251,191,36,.2)">
              <i class="fa-solid fa-fire" style="color:#fbbf24"></i>
            </div>
            <div>
              <span class="chip-lbl">Month Earnings</span>
              <span class="chip-val">KSh <?= number_format($earnings_month,2) ?></span>
            </div>
          </div>

          <div class="wc-chip">
            <div class="chip-ico" style="background:rgba(34,197,94,.2)">
              <i class="fa-solid fa-vault" style="color:#4ade80"></i>
            </div>
            <div>
              <span class="chip-lbl">Balance</span>
              <span class="chip-val">KSh <?= number_format($account_balance,2) ?></span>
            </div>
          </div>
        </div>
      </div>

      <div class="wc-right">
        <div class="wc-avatar"><?= htmlspecialchars($initials) ?></div>
        <div class="wc-clock" id="liveClock"><?= $current_time ?></div>
        <div class="wc-tz">Nairobi · EAT</div>
      </div>
    </div>

    <!-- Commission strip -->
    <div class="comm-strip">
      <div class="comm-strip-ico"><i class="fa-solid fa-handshake"></i></div>
      <div class="comm-strip-text">
        Your commission rate: <strong><?= htmlspecialchars($commission_label) ?></strong> per sale
        &nbsp;·&nbsp; Partner of <strong style="color:rgba(255,255,255,.8)"><?= htmlspecialchars($creator_name) ?></strong>
      </div>
      <div class="comm-strip-pill"><?= $commission_type === 'percentage' ? '% Rate' : 'Fixed' ?></div>
    </div>
  </div>

  <!-- ══ QUICK ACTIONS ══ -->
  <div class="quick-actions">
    <a href="index.php?page=my_links" class="qa-btn qa-primary">
      <i class="fa-solid fa-link"></i> My Referral Links
    </a>
    <a href="index.php?page=request_withdrawal" class="qa-btn qa-gold">
      <i class="fa-solid fa-circle-dollar-to-slot"></i> Withdraw Funds
    </a>
    <a href="index.php?page=earnings" class="qa-btn qa-secondary">
      <i class="fa-solid fa-sack-dollar"></i> View Earnings
    </a>
    <a href="index.php?page=ticket_purchases" class="qa-btn qa-secondary">
      <i class="fa-solid fa-cart-shopping"></i> All Purchases
    </a>
  </div>

  <!-- ══ MAIN STAT CARDS ══ -->
  <div class="sec-hd">
    <div class="sec-title">Performance Overview</div>
  </div>
  <div class="stats-grid">

    <div class="sc s-green">
      <div class="sc-top">
        <div class="sc-ico"><i class="fa-solid fa-cart-shopping"></i></div>
        <span class="sc-badge bdg-neutral">All Time</span>
      </div>
      <div class="sc-label">Total Purchases via My Link</div>
      <div class="sc-val"><?= number_format($total_purchases) ?></div>
      <div class="sc-sub">Tickets sold through your referral</div>
    </div>

    <div class="sc s-gold">
      <div class="sc-top">
        <div class="sc-ico"><i class="fa-solid fa-arrow-trend-up"></i></div>
        <span class="sc-badge bdg-up">↑ 7 days</span>
      </div>
      <div class="sc-label">Earnings This Week</div>
      <div class="sc-val">KSh <?= number_format($earnings_week,2) ?></div>
      <div class="sc-sub">Rolling last 7 days</div>
    </div>

    <div class="sc s-teal">
      <div class="sc-top">
        <div class="sc-ico"><i class="fa-solid fa-calendar-day"></i></div>
        <span class="sc-badge bdg-up">↑ Month</span>
      </div>
      <div class="sc-label">Earnings This Month</div>
      <div class="sc-val">KSh <?= number_format($earnings_month,2) ?></div>
      <div class="sc-sub"><?= date('F Y') ?></div>
    </div>

    <div class="sc s-navy">
      <div class="sc-top">
        <div class="sc-ico"><i class="fa-solid fa-money-bill-transfer"></i></div>
        <span class="sc-badge bdg-neutral">Lifetime</span>
      </div>
      <div class="sc-label">Total Withdrawn</div>
      <div class="sc-val">KSh <?= number_format($total_withdrawn,2) ?></div>
      <div class="sc-sub">Completed withdrawals only</div>
    </div>

  </div>

  <!-- Secondary stats row -->
  <div class="stats-grid-3">

    <div class="sc s-green">
      <div class="sc-top">
        <div class="sc-ico"><i class="fa-solid fa-vault"></i></div>
        <span class="sc-badge bdg-live">Live</span>
      </div>
      <div class="sc-label">Account Balance</div>
      <div class="sc-val">KSh <?= number_format($account_balance,2) ?></div>
      <div class="sc-sub">Available to withdraw</div>
    </div>

    <div class="sc s-teal">
      <div class="sc-top">
        <div class="sc-ico"><i class="fa-solid fa-ticket"></i></div>
        <span class="sc-badge bdg-up">↑ 7 days</span>
      </div>
      <div class="sc-label">Purchases This Week</div>
      <div class="sc-val"><?= number_format($week_purchases) ?></div>
      <div class="sc-sub">Sales via link (last 7 days)</div>
    </div>

    <div class="sc s-gold">
      <div class="sc-top">
        <div class="sc-ico"><i class="fa-solid fa-clock-rotate-left"></i></div>
        <?php if ($pending_commissions > 0): ?>
          <span class="sc-badge bdg-warn"><?= $pending_commissions ?> pending</span>
        <?php else: ?>
          <span class="sc-badge bdg-neutral">Commissions</span>
        <?php endif; ?>
      </div>
      <div class="sc-label">Pending Commissions</div>
      <div class="sc-val"><?= number_format($pending_commissions) ?></div>
      <div class="sc-sub">Awaiting creator approval</div>
    </div>

  </div>

  <!-- ══ REFERRAL LINK CARD ══ -->
  <?php if ($affiliate_token): ?>
  <div class="link-card">
    <div class="link-card-top">
      <div class="link-card-ttl">
        <i class="fa-solid fa-link"></i> Your General Referral Link
      </div>
      <span class="link-pill">Share this anywhere</span>
    </div>
    <?php $ref_url = "https://keychele.co.ke/?ref=" . htmlspecialchars($affiliate_token); ?>
    <div class="link-row">
      <div class="link-row-icon"><i class="fa-solid fa-globe"></i></div>
      <div class="link-url" id="refLinkUrl"><?= $ref_url ?></div>
      <button class="copy-btn" id="copyRefBtn" onclick="copyLink('refLinkUrl','copyRefBtn')">
        <i class="fa-solid fa-copy"></i> Copy
      </button>
    </div>
    <div class="link-hint">
      <i class="fa-solid fa-circle-info"></i>
      Share event-specific links from <a href="index.php?page=my_links" style="color:var(--green);font-weight:700">My Referral Links</a> for better tracking per event.
    </div>
  </div>
  <?php endif; ?>

  <!-- ══ CHARTS ══ -->
  <div class="sec-hd">
    <div class="sec-title">Analytics</div>
    <span style="font-family:'Outfit',sans-serif;font-size:.68rem;color:var(--muted)">Last 6 months</span>
  </div>
  <div class="charts-row">

    <div class="chart-card">
      <div class="chart-top">
        <div>
          <div class="chart-ttl">Purchases via My Link</div>
          <div class="chart-sub">Ticket count &amp; commission earned per month</div>
        </div>
        <div class="chart-pill"><i class="fa-solid fa-ticket"></i> Tickets</div>
      </div>
      <div class="chart-wrap"><canvas id="purchaseChart"></canvas></div>
    </div>

    <div class="chart-card">
      <div class="chart-top">
        <div>
          <div class="chart-ttl">Monthly Earnings</div>
          <div class="chart-sub">Commission income trend</div>
        </div>
        <div class="chart-pill"><i class="fa-solid fa-sack-dollar"></i> KSh</div>
      </div>
      <div class="chart-wrap"><canvas id="earningsChart"></canvas></div>
    </div>

  </div>

  <!-- ══ RECENT ACTIVITY ══ -->
  <div class="sec-hd">
    <div class="sec-title">Recent Activity</div>
    <a href="index.php?page=ticket_purchases" class="view-all">View all <i class="fa-solid fa-arrow-right"></i></a>
  </div>
  <div class="tables-row">

    <!-- Recent Purchases -->
    <div class="tbl-card">
      <div class="tbl-hd">
        <div class="tbl-ttl">
          <div class="tbl-ico" style="background:rgba(14,159,110,.12);color:var(--green)">
            <i class="fa-solid fa-cart-shopping"></i>
          </div>
          Purchases via My Link
        </div>
        <a href="index.php?page=ticket_purchases" class="view-all">All <i class="fa-solid fa-arrow-right"></i></a>
      </div>
      <div class="tbl-scroll">
        <?php if(empty($recent_purchases)): ?>
          <div class="empty">
            <div class="empty-ico"><i class="fa-solid fa-cart-shopping"></i></div>
            <p>No purchases via your link yet. Start sharing!</p>
          </div>
        <?php else: ?>
        <table>
          <thead>
            <tr>
              <th>Buyer</th><th>Event</th><th>Ticket #</th>
              <th>Qty</th><th>Amount</th><th>Commission</th>
              <th>Status</th><th>Date</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach($recent_purchases as $p):
              $ps = match(strtolower($p['payment_status'] ?? 'pending')) {
                'paid','success'  => 'b-paid',
                'pending'         => 'b-pending',
                'failed'          => 'b-failed',
                default           => 'b-pending'
              };
              $cs = match(strtolower($p['commission_status'] ?? 'pending')) {
                'paid','completed' => 'b-completed',
                'pending'          => 'b-pending',
                default            => 'b-pending'
              };
            ?>
            <tr>
              <td>
                <div class="tp"><?= htmlspecialchars($p['buyer_name']) ?></div>
                <div class="ts"><?= htmlspecialchars($p['buyer_phone'] ?? '') ?></div>
              </td>
              <td style="max-width:110px;overflow:hidden;text-overflow:ellipsis;">
                <div class="tp" style="font-size:.76rem"><?= htmlspecialchars($p['event_title'] ?? '—') ?></div>
              </td>
              <td><span class="tm"><?= htmlspecialchars($p['ticket_number']) ?></span></td>
              <td style="text-align:center;font-family:'Outfit',sans-serif;font-weight:700">
                <?= (int)$p['quantity'] ?>
              </td>
              <td class="ta" style="color:var(--green-d)">KSh <?= number_format($p['amount_paid'],0) ?></td>
              <td class="ta" style="color:var(--gold)">
                <?php if ($p['commission_earned']): ?>
                  KSh <?= number_format($p['commission_earned'],0) ?>
                  <br><span class="bdg <?= $cs ?>" style="font-size:.5rem;margin-top:2px"><?= ucfirst($p['commission_status'] ?? 'pending') ?></span>
                <?php else: ?>
                  <span style="color:var(--muted);font-size:.72rem">—</span>
                <?php endif; ?>
              </td>
              <td><span class="bdg <?= $ps ?>"><?= ucfirst($p['payment_status'] ?? 'pending') ?></span></td>
              <td style="color:var(--muted);font-size:.7rem"><?= date('d M', strtotime($p['created_at'])) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </div>
    </div>

    <!-- Recent Earnings -->
    <div class="tbl-card">
      <div class="tbl-hd">
        <div class="tbl-ttl">
          <div class="tbl-ico" style="background:rgba(217,119,6,.12);color:var(--gold)">
            <i class="fa-solid fa-sack-dollar"></i>
          </div>
          Recent Earnings
        </div>
        <a href="index.php?page=earnings" class="view-all">All <i class="fa-solid fa-arrow-right"></i></a>
      </div>
      <div class="tbl-scroll">
        <?php if(empty($recent_earnings)): ?>
          <div class="empty">
            <div class="empty-ico"><i class="fa-solid fa-sack-dollar"></i></div>
            <p>No commissions earned yet</p>
          </div>
        <?php else: ?>
        <table>
          <thead>
            <tr><th>Event</th><th>Buyer</th><th>Commission</th><th>Status</th><th>Date</th></tr>
          </thead>
          <tbody>
            <?php foreach($recent_earnings as $e):
              $sc = match(strtolower($e['status'])) {
                'paid','completed' => 'b-completed',
                'pending'          => 'b-pending',
                default            => 'b-pending'
              };
            ?>
            <tr>
              <td>
                <div class="tp" style="font-size:.76rem;max-width:130px;overflow:hidden;text-overflow:ellipsis">
                  <?= htmlspecialchars($e['event_title'] ?? '—') ?>
                </div>
              </td>
              <td><div class="ts"><?= htmlspecialchars($e['buyer_name'] ?? 'Customer') ?></div></td>
              <td class="ta" style="color:var(--gold)">KSh <?= number_format($e['amount'],0) ?></td>
              <td><span class="bdg <?= $sc ?>"><?= ucfirst($e['status']) ?></span></td>
              <td style="color:var(--muted);font-size:.7rem"><?= date('d M', strtotime($e['created_at'])) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </div>
    </div>

  </div>

  <!-- ══ WITHDRAWALS ══ -->
  <div class="sec-hd">
    <div class="sec-title">Withdrawals</div>
    <a href="index.php?page=my_withdrawals" class="view-all">View all <i class="fa-solid fa-arrow-right"></i></a>
  </div>
  <div class="w-card">
    <div class="tbl-hd">
      <div class="tbl-ttl">
        <div class="tbl-ico" style="background:rgba(30,58,95,.1);color:var(--navy)">
          <i class="fa-solid fa-money-bill-transfer"></i>
        </div>
        Withdrawal Requests
      </div>
      <a href="index.php?page=request_withdrawal" class="qa-btn qa-gold" style="padding:6px 14px;font-size:.72rem">
        <i class="fa-solid fa-plus"></i> New
      </a>
    </div>
    <div class="tbl-scroll">
      <?php if(empty($recent_withdrawals)): ?>
        <div class="empty">
          <div class="empty-ico"><i class="fa-solid fa-hand-holding-dollar"></i></div>
          <p>No withdrawal requests yet</p>
        </div>
      <?php else: ?>
      <table>
        <thead>
          <tr>
            <th>Method</th><th>Destination</th><th>Amount</th>
            <th>Charge</th><th>Total</th><th>Code</th><th>Status</th><th>Requested</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach($recent_withdrawals as $w):
            $sc = match(strtolower($w['status'])) {
              'completed'         => 'b-completed',
              'pending'           => 'b-pending',
              'processing'        => 'b-processing',
              'rejected','failed' => 'b-failed',
              default             => 'b-pending'
            };
          ?>
          <tr>
            <td>
              <div style="display:inline-flex;align-items:center;gap:7px">
                <span style="width:26px;height:26px;border-radius:7px;background:var(--green-xl);display:inline-flex;align-items:center;justify-content:center;color:var(--green);font-size:.7rem">
                  <i class="fa-solid fa-<?= strtolower($w['method'] ?? '') === 'mpesa' ? 'mobile-screen-button' : 'building-columns' ?>"></i>
                </span>
                <span class="tp" style="font-size:.76rem"><?= htmlspecialchars(strtoupper($w['method'] ?? '—')) ?></span>
              </div>
            </td>
            <td><span class="tm"><?= htmlspecialchars($w['destination'] ?? '—') ?></span></td>
            <td class="ta" style="color:var(--green-d)">KSh <?= number_format($w['amount'],0) ?></td>
            <td style="color:var(--muted);font-size:.74rem">KSh <?= number_format($w['charge'] ?? 0, 0) ?></td>
            <td class="ta" style="color:var(--gold)">KSh <?= number_format($w['total_amount'],0) ?></td>
            <td><span class="tm"><?= htmlspecialchars($w['transaction_code'] ?? '—') ?></span></td>
            <td><span class="bdg <?= $sc ?>"><?= ucfirst($w['status']) ?></span></td>
            <td style="color:var(--muted);font-size:.7rem"><?= date('d M Y', strtotime($w['requested_at'])) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>
  </div>

</div><!-- /af-content -->

<script>
// ── Live Clock ──
function tick() {
  const n = new Date();
  let h = n.getHours() % 12 || 12;
  const m = String(n.getMinutes()).padStart(2,'0');
  const ap = n.getHours() >= 12 ? 'PM' : 'AM';
  const el = document.getElementById('liveClock');
  if (el) el.textContent = `${h}:${m} ${ap}`;
}
setInterval(tick, 1000);

// ── Copy Link ──
function copyLink(idUrl, idBtn) {
  const url = document.getElementById(idUrl)?.textContent;
  if (!url) return;
  navigator.clipboard.writeText(url).then(() => {
    const btn = document.getElementById(idBtn);
    if (!btn) return;
    btn.innerHTML = '<i class="fa-solid fa-check"></i> Copied!';
    btn.classList.add('copied');
    setTimeout(() => {
      btn.innerHTML = '<i class="fa-solid fa-copy"></i> Copy';
      btn.classList.remove('copied');
    }, 2200);
  });
}

// ── Chart defaults ──
Chart.defaults.font.family = "'Plus Jakarta Sans', sans-serif";
Chart.defaults.color = '#6b7280';
const grid = 'rgba(14,159,110,0.07)';

// ── Purchase Chart ──
new Chart(document.getElementById('purchaseChart'), {
  data: {
    labels: <?= json_encode($chart_labels) ?>,
    datasets: [
      {
        type: 'bar',
        label: 'Tickets Sold',
        data: <?= json_encode($chart_purchases) ?>,
        backgroundColor: ctx => {
          const g = ctx.chart.ctx.createLinearGradient(0, 0, 0, 220);
          g.addColorStop(0, 'rgba(14,159,110,0.88)');
          g.addColorStop(1, 'rgba(14,159,110,0.18)');
          return g;
        },
        borderRadius: 7,
        borderSkipped: false,
        yAxisID: 'y'
      },
      {
        type: 'line',
        label: 'Commission (KSh)',
        data: <?= json_encode($chart_earnings) ?>,
        borderColor: '#d97706',
        backgroundColor: 'transparent',
        borderWidth: 2.5,
        pointBackgroundColor: '#d97706',
        pointBorderColor: '#fff',
        pointBorderWidth: 2,
        pointRadius: 5,
        tension: 0.4,
        yAxisID: 'y1'
      }
    ]
  },
  options: {
    responsive: true, maintainAspectRatio: false,
    interaction: { mode: 'index', intersect: false },
    plugins: {
      legend: {
        position: 'bottom',
        labels: { font: { size: 11 }, boxWidth: 10, boxHeight: 10,
                  borderRadius: 3, useBorderRadius: true, padding: 12 }
      },
      tooltip: { padding: 10, cornerRadius: 8 }
    },
    scales: {
      x: { grid: { display: false }, ticks: { font: { size: 11 } } },
      y: { grid: { color: grid, drawBorder: false },
           ticks: { font: { size: 10 } }, beginAtZero: true },
      y1: {
        position: 'right', grid: { display: false },
        ticks: { font: { size: 10 }, color: '#c9a300',
                 callback: v => 'KSh ' + v.toLocaleString() },
        beginAtZero: true
      }
    }
  }
});

// ── Earnings Chart ──
new Chart(document.getElementById('earningsChart'), {
  type: 'line',
  data: {
    labels: <?= json_encode($chart_labels) ?>,
    datasets: [{
      label: 'Commission (KSh)',
      data: <?= json_encode($chart_earnings) ?>,
      borderColor: '#0e9f6e',
      backgroundColor: ctx => {
        const g = ctx.chart.ctx.createLinearGradient(0, 0, 0, 200);
        g.addColorStop(0, 'rgba(14,159,110,0.22)');
        g.addColorStop(1, 'rgba(14,159,110,0.01)');
        return g;
      },
      fill: true, tension: 0.42, borderWidth: 2.5,
      pointBackgroundColor: '#0e9f6e',
      pointBorderColor: '#fff',
      pointBorderWidth: 2,
      pointRadius: 5, pointHoverRadius: 7
    }]
  },
  options: {
    responsive: true, maintainAspectRatio: false,
    plugins: {
      legend: { display: false },
      tooltip: {
        padding: 10, cornerRadius: 8,
        callbacks: { label: ctx => ' KSh ' + ctx.parsed.y.toLocaleString() }
      }
    },
    scales: {
      x: { grid: { display: false }, ticks: { font: { size: 11 } } },
      y: {
        grid: { color: grid, drawBorder: false },
        ticks: { font: { size: 10 }, callback: v => 'KSh ' + v.toLocaleString() },
        beginAtZero: true
      }
    }
  }
});
</script>
</body>
</html>