<?php
if (session_status() === PHP_SESSION_NONE) session_start();

// ── Auth guard ───────────────────────────────────────────────────────────────
if (empty($_SESSION['creator_id'])) {
    header('Location: login.php'); exit;
}

$connect_path = file_exists('msanii/includes/connect.php')
    ? 'msanii/includes/connect.php'
    : 'includes/connect.php';

if (!include_once $connect_path) die("Database connection failed.");

$creator_id = (int)$_SESSION['creator_id'];

// ── Creator info ─────────────────────────────────────────────────────────────
$stmt = $pdo->prepare("SELECT c.username, c.withdrawal_pin, mp.full_name, mp.id AS page_id
                       FROM creators c
                       LEFT JOIN my_page mp ON c.id = mp.creator_id
                       WHERE c.id = ?");
$stmt->execute([$creator_id]);
$creator = $stmt->fetch(PDO::FETCH_ASSOC);

$display_name = $creator['full_name'] ?? $creator['username'] ?? 'Creator';
$initials     = strtoupper(substr($display_name, 0, 1));
$has_page     = !empty($creator['page_id']);
$has_pin      = !empty($creator['withdrawal_pin']);

// ── Time & greeting ──────────────────────────────────────────────────────────
date_default_timezone_set('Africa/Nairobi');
$current_time = date('h:i A');
$current_date = date('l, d F Y');
$current_hour = (int)date('H');

if ($current_hour >= 5 && $current_hour < 12) {
    $greeting = "Good Morning"; $wave = "☀️";
} elseif ($current_hour >= 12 && $current_hour < 17) {
    $greeting = "Good Afternoon"; $wave = "🌤️";
} elseif ($current_hour >= 17 && $current_hour < 21) {
    $greeting = "Good Evening"; $wave = "🌇";
} else {
    $greeting = "Good Night"; $wave = "🌙";
}

// ── Ensure creator_sms table exists ──────────────────────────────────────────
$pdo->exec("CREATE TABLE IF NOT EXISTS creator_sms (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    creator_id     INT UNSIGNED NOT NULL,
    balance        INT NOT NULL DEFAULT 0,
    bonus_given    TINYINT(1) NOT NULL DEFAULT 0,
    total_bought   INT NOT NULL DEFAULT 0,
    total_used     INT NOT NULL DEFAULT 0,
    last_topped_up DATETIME NULL,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_creator (creator_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// ── SMS balance + welcome bonus ───────────────────────────────────────────────
$stmt = $pdo->prepare("SELECT * FROM creator_sms WHERE creator_id = ?");
$stmt->execute([$creator_id]);
$sms_row = $stmt->fetch(PDO::FETCH_ASSOC);

$welcome_bonus    = 10;
$bonus_just_given = false;

if (!$sms_row) {
    $pdo->prepare("INSERT INTO creator_sms (creator_id, balance, bonus_given, total_bought, total_used)
                   VALUES (?, ?, 1, 0, 0)")
        ->execute([$creator_id, $welcome_bonus]);
    $sms_balance      = $welcome_bonus;
    $bonus_just_given = true;
} else {
    $sms_balance = (int)$sms_row['balance'];
    if (!$sms_row['bonus_given'] && $sms_row['total_bought'] == 0) {
        $pdo->prepare("UPDATE creator_sms SET balance = balance + ?, bonus_given = 1 WHERE creator_id = ?")
            ->execute([$welcome_bonus, $creator_id]);
        $sms_balance     += $welcome_bonus;
        $bonus_just_given = true;
    }
}

// ── Stats ─────────────────────────────────────────────────────────────────────
$stmt = $pdo->prepare("SELECT COALESCE(amount, 0) FROM my_balance WHERE creator_id = ?");
$stmt->execute([$creator_id]);
$account_balance = number_format((float)$stmt->fetchColumn(), 2);

$stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM withdrawal_requests WHERE creator_id = ? AND status = 'completed'");
$stmt->execute([$creator_id]);
$withdrawals = number_format((float)$stmt->fetchColumn(), 2);

// ── Referral Earnings Stats ───────────────────────────────────────────────────
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM referral_earnings WHERE referrer_id = ? AND DATE(created_at) = CURDATE()");
    $stmt->execute([$creator_id]);
    $ref_today = (float)$stmt->fetchColumn();
} catch (PDOException $e) { $ref_today = 0.00; }

try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM referral_earnings WHERE referrer_id = ? AND YEARWEEK(created_at, 1) = YEARWEEK(CURDATE(), 1)");
    $stmt->execute([$creator_id]);
    $ref_week = (float)$stmt->fetchColumn();
} catch (PDOException $e) { $ref_week = 0.00; }

try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM referral_earnings WHERE referrer_id = ? AND YEAR(created_at) = YEAR(CURDATE()) AND MONTH(created_at) = MONTH(CURDATE())");
    $stmt->execute([$creator_id]);
    $ref_month = (float)$stmt->fetchColumn();
} catch (PDOException $e) { $ref_month = 0.00; }

try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM referral_earnings WHERE referrer_id = ?");
    $stmt->execute([$creator_id]);
    $ref_total = (float)$stmt->fetchColumn();
} catch (PDOException $e) { $ref_total = 0.00; }

try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM creators WHERE CAST(referred_by AS UNSIGNED) = ?");
    $stmt->execute([$creator_id]);
    $ref_count = (int)$stmt->fetchColumn();
} catch (PDOException $e) { $ref_count = 0; }

try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM referral_earnings WHERE referrer_id = ? AND status = 'pending'");
    $stmt->execute([$creator_id]);
    $ref_pending = (float)$stmt->fetchColumn();
} catch (PDOException $e) { $ref_pending = 0.00; }

// ── Chart data (6 months) ─────────────────────────────────────────────────────
$ticket_labels = []; $ticket_data = []; $ticket_revenue = [];
$support_labels = []; $support_data = [];

for ($i = 5; $i >= 0; $i--) {
    $month = date('Y-m-01', strtotime("-$i months"));
    $lbl   = date('M', strtotime($month));
    $ticket_labels[]  = $lbl;
    $support_labels[] = $lbl;

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(tp.quantity),0), COALESCE(SUM(tp.amount_paid),0)
        FROM ticket_purchases tp
        INNER JOIN events e ON tp.event_id = e.id
        WHERE e.creator_id = ? AND tp.status = 'paid'
          AND tp.created_at >= ? AND tp.created_at < DATE_ADD(?, INTERVAL 1 MONTH)");
    $stmt->execute([$creator_id, $month, $month]);
    $row = $stmt->fetch(PDO::FETCH_NUM);
    $ticket_data[]    = (int)$row[0];
    $ticket_revenue[] = (float)$row[1];

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM support
        WHERE creator_id = ? AND created_at >= ? AND created_at < DATE_ADD(?, INTERVAL 1 MONTH)");
    $stmt->execute([$creator_id, $month, $month]);
    $support_data[] = (float)$stmt->fetchColumn();
}

// ── Recent data ───────────────────────────────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT tp.buyer_name, tp.buyer_email, tp.buyer_phone, tp.ticket_number,
           tp.amount_paid, tp.quantity, tp.status, tp.is_checked_in,
           tp.mpesa_receipt, tp.created_at, e.title AS event_title
    FROM ticket_purchases tp
    INNER JOIN events e ON tp.event_id = e.id
    WHERE e.creator_id = ?
    ORDER BY tp.created_at DESC LIMIT 8");
$stmt->execute([$creator_id]);
$recent_tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare("SELECT supporter_name, amount, mpesa_receipt, phone, status, created_at
    FROM support WHERE creator_id = ? ORDER BY created_at DESC LIMIT 6");
$stmt->execute([$creator_id]);
$recent_support = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare("SELECT method, destination, mpesa_number, amount, charge, total_amount,
    transaction_code, status, requested_at FROM withdrawal_requests
    WHERE creator_id = ? ORDER BY requested_at DESC LIMIT 6");
$stmt->execute([$creator_id]);
$recent_withdrawals = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare("
    SELECT tpv.ip_address, tpv.country, tpv.city, tpv.device, tpv.browser,
           tpv.os, tpv.visited_at, tp.ticket_number, e.title AS event_title
    FROM ticket_page_visits tpv
    INNER JOIN ticket_purchases tp ON tpv.ticket_id = tp.id
    INNER JOIN events e ON tp.event_id = e.id
    WHERE e.creator_id = ?
    ORDER BY tpv.visited_at DESC LIMIT 20");
$stmt->execute([$creator_id]);
$page_visits = $stmt->fetchAll(PDO::FETCH_ASSOC);

$notif_visits  = count($page_visits);
$notif_tickets = count($recent_tickets);
$notif_support = count($recent_support);
$total_notifs  = $notif_visits + $notif_tickets + $notif_support;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>Dashboard — Keychele</title>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>

<style>
/* ── FONTS ── */
@font-face {
  font-family: 'Glacial Indifference';
  src: url('https://db.onlinewebfonts.com/t/74f7b46c5a6a4374c10b2cb3ef8b4fc1.woff2') format('woff2');
  font-weight: 400;
}
@font-face {
  font-family: 'Glacial Indifference';
  src: url('https://db.onlinewebfonts.com/t/2b2f592d11d16f79ccbc60b42a25e9e1.woff2') format('woff2');
  font-weight: 700;
}

/* ── TOKENS ── */
:root {
  --red:     #bf1339;
  --red-d:   #96102e;
  --red-l:   #e8143f;
  --gold:    #fec411;
  --gold-d:  #d4a60a;
  --navy:    #052e64;
  --navy-l:  #0a4494;
  --bg:      #eef1f8;
  --card:    #ffffff;
  --border:  rgba(5,46,100,0.08);
  --text:    #0f1923;
  --muted:   #6b7a99;
  --success: #00b37e;
  --warning: #f59e0b;
  --sms:     #7c3aed;
  --sms-l:   #a78bfa;
  --purple:  #5c1a8a;
  --purple-l:#7c2fb0;
  --r:       14px;
  --r-lg:    20px;
  --sh-sm:   0 2px 8px rgba(5,46,100,0.07);
  --sh:      0 6px 28px rgba(5,46,100,0.11);
  --sh-lg:   0 16px 56px rgba(5,46,100,0.16);
}

*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

body {
  font-family: 'Glacial Indifference', 'Trebuchet MS', sans-serif;
  background: var(--bg);
  color: var(--text);
  -webkit-font-smoothing: antialiased;
  padding-bottom: 80px;
}

h1,h2,h3,h4,h5,.mf { font-family: 'Montserrat', sans-serif; }

/* ════════════════════════════════════════════
   PRELOADER
════════════════════════════════════════════ */
#preloader {
  position: fixed; inset: 0; z-index: 9999;
  background: var(--navy);
  display: flex; align-items: center; justify-content: center;
  flex-direction: column; gap: 1.25rem;
  transition: opacity 0.5s, visibility 0.5s;
}
#preloader.gone { opacity: 0; visibility: hidden; pointer-events: none; }
.pre-wordmark {
  font-family: 'Montserrat', sans-serif;
  font-weight: 900; font-size: 1.8rem; color: white; letter-spacing: -0.05em;
}
.pre-wordmark em { color: var(--gold); font-style: normal; }
.pre-track {
  width: 140px; height: 3px; border-radius: 10px;
  background: rgba(255,255,255,0.12); overflow: hidden;
}
.pre-fill {
  height: 100%; border-radius: 10px;
  background: linear-gradient(90deg, var(--red), var(--gold));
  animation: load-fill 1.5s cubic-bezier(0.4,0,0.2,1) forwards;
}
@keyframes load-fill { from{width:0} to{width:100%} }

/* ════════════════════════════════════════════
   KEYFRAMES
════════════════════════════════════════════ */
@keyframes slide-up  { from{opacity:0;transform:translateY(22px)} to{opacity:1;transform:translateY(0)} }
@keyframes pop       { from{opacity:0;transform:translateY(16px) scale(0.97)} to{opacity:1;transform:translateY(0) scale(1)} }
@keyframes pulse-dot { 0%,100%{opacity:1;transform:scale(1)} 50%{opacity:0.4;transform:scale(0.7)} }
@keyframes glow-pulse { 0%,100%{box-shadow:0 0 0 0 rgba(254,196,17,0.5)} 50%{box-shadow:0 0 0 5px rgba(254,196,17,0)} }
@keyframes fade-in   { from{opacity:0} to{opacity:1} }
@keyframes shimmer   { 0%{background-position:-200% center} 100%{background-position:200% center} }

/* ════════════════════════════════════════════
   ALERT BANNERS
════════════════════════════════════════════ */
.dash-alert {
  position: relative; overflow: hidden;
  border-radius: var(--r-lg);
  display: flex; align-items: center; gap: 1.25rem;
  padding: 1.35rem 1.75rem; margin-bottom: 1rem;
  animation: slide-up 0.5s cubic-bezier(0.16,1,0.3,1) both;
}
.dash-alert::before {
  content: ''; position: absolute; top: 0; left: 0; bottom: 0; width: 5px;
  border-radius: var(--r-lg) 0 0 var(--r-lg);
}
.alert-page {
  background: linear-gradient(120deg,#fffbea,#fff8e1);
  border: 1.5px solid rgba(254,196,17,0.4);
  box-shadow: 0 4px 24px rgba(254,196,17,0.12);
}
.alert-page::before { background: linear-gradient(180deg,var(--gold),var(--red)); }
.alert-pin {
  background: linear-gradient(120deg,#fff1f3,#fff5f7);
  border: 1.5px solid rgba(191,19,57,0.25);
  box-shadow: 0 4px 24px rgba(191,19,57,0.10);
}
.alert-pin::before { background: linear-gradient(180deg,var(--red-l),var(--red-d)); }
.alert-icon {
  width: 52px; height: 52px; border-radius: 14px;
  display: flex; align-items: center; justify-content: center;
  font-size: 1.3rem; flex-shrink: 0;
  box-shadow: 0 4px 14px rgba(0,0,0,0.12);
}
.alert-page .alert-icon { background: linear-gradient(135deg,var(--gold),#ffe168); color: var(--navy); }
.alert-pin  .alert-icon { background: linear-gradient(135deg,var(--red-l),var(--red-d)); color: #fff; }
.alert-body { flex: 1; min-width: 0; }
.alert-title {
  font-family: 'Montserrat', sans-serif; font-weight: 800; font-size: 0.9rem;
  letter-spacing: -0.02em; margin-bottom: 0.3rem;
}
.alert-page .alert-title { color: var(--navy); }
.alert-pin  .alert-title { color: var(--red-d); }
.alert-text { font-size: 0.78rem; color: var(--muted); line-height: 1.55; }
.pin-steps  { display: flex; gap: 0.6rem; flex-wrap: wrap; margin-top: 0.65rem; }
.pin-step {
  display: inline-flex; align-items: center; gap: 5px;
  background: rgba(191,19,57,0.07); border: 1px solid rgba(191,19,57,0.15);
  border-radius: 8px; padding: 4px 10px;
  font-family: 'Montserrat', sans-serif; font-size: 0.62rem; font-weight: 700; color: var(--red-d);
}
.alert-actions { display: flex; flex-direction: column; align-items: flex-end; gap: 6px; flex-shrink: 0; }
.alert-btn {
  display: inline-flex; align-items: center; gap: 7px;
  font-family: 'Montserrat', sans-serif; font-weight: 700; font-size: 0.75rem;
  letter-spacing: 0.02em; padding: 10px 18px; border-radius: 10px;
  white-space: nowrap; text-decoration: none;
  transition: transform 0.2s, box-shadow 0.2s;
}
.alert-btn:hover { transform: translateY(-2px); }
.alert-page .alert-btn { background: linear-gradient(135deg,var(--navy),var(--navy-l)); color: white; box-shadow: 0 4px 14px rgba(5,46,100,0.28); }
.alert-pin  .alert-btn { background: linear-gradient(135deg,var(--red-l),var(--red-d)); color: white; box-shadow: 0 4px 14px rgba(191,19,57,0.28); }
.alert-dismiss {
  background: none; border: none; cursor: pointer;
  font-size: 0.8rem; padding: 4px 6px; border-radius: 6px;
  transition: color 0.2s, background 0.2s;
}
.alert-page .alert-dismiss { color: var(--muted); }
.alert-page .alert-dismiss:hover { color: var(--text); background: rgba(5,46,100,0.06); }
.alert-pin  .alert-dismiss { color: rgba(191,19,57,0.4); }
.alert-pin  .alert-dismiss:hover { color: var(--red); background: rgba(191,19,57,0.08); }
.pin-urgency {
  display: inline-flex; align-items: center; gap: 5px;
  background: rgba(191,19,57,0.1); border: 1px solid rgba(191,19,57,0.2);
  border-radius: 100px; padding: 3px 10px;
  font-family: 'Montserrat', sans-serif; font-size: 0.6rem; font-weight: 800;
  color: var(--red); letter-spacing: 0.06em; text-transform: uppercase;
}
.pin-urgency::before {
  content: ''; width: 5px; height: 5px; border-radius: 50%;
  background: var(--red); animation: pulse-dot 1.5s infinite;
}
@media (max-width:640px) {
  .dash-alert { flex-wrap: wrap; padding: 1.15rem 1.2rem 1.15rem 1.5rem; gap: 0.9rem; }
  .alert-actions { flex-direction: row; align-items: center; width: 100%; }
  .alert-btn { flex: 1; justify-content: center; }
}

/* ════════════════════════════════════════════
   WELCOME CARD
════════════════════════════════════════════ */
.welcome-card {
  position: relative; overflow: hidden; border-radius: var(--r-lg);
  background: linear-gradient(120deg,var(--navy) 0%,#093576 50%,#0f4faa 100%);
  box-shadow: var(--sh-lg), 0 0 0 1px rgba(255,255,255,0.05) inset;
  margin-bottom: 1.75rem;
  animation: slide-up 0.55s cubic-bezier(0.16,1,0.3,1) both;
}
.wc-grid {
  position: absolute; inset: 0; pointer-events: none;
  background-image:
    linear-gradient(rgba(255,255,255,0.03) 1px, transparent 1px),
    linear-gradient(90deg, rgba(255,255,255,0.03) 1px, transparent 1px);
  background-size: 36px 36px;
}
.wc-orb   { position: absolute; border-radius: 50%; pointer-events: none; }
.wc-orb-1 { width:380px;height:380px;top:-140px;right:-80px;background:radial-gradient(circle,rgba(254,196,17,0.15) 0%,transparent 65%); }
.wc-orb-2 { width:200px;height:200px;bottom:-80px;left:20%;background:radial-gradient(circle,rgba(191,19,57,0.18) 0%,transparent 65%); }
.wc-body {
  position: relative; z-index: 2;
  display: grid; grid-template-columns: 1fr auto;
  gap: 1.5rem; align-items: center; padding: 2rem 2.25rem;
}
@media (max-width:640px) { .wc-body { grid-template-columns:1fr; padding:1.5rem; } .wc-right{display:none!important} }
.wc-tag {
  display: inline-flex; align-items: center; gap: 6px;
  background: rgba(254,196,17,0.14); border: 1px solid rgba(254,196,17,0.28);
  border-radius: 100px; padding: 4px 13px; margin-bottom: 0.9rem;
  font-family: 'Montserrat', sans-serif; font-size: 0.65rem; font-weight: 700;
  letter-spacing: 0.09em; text-transform: uppercase; color: var(--gold);
}
.wc-tag::before { content:''; width:6px;height:6px;border-radius:50%;background:var(--gold);animation:glow-pulse 2s infinite; }
.wc-name {
  font-family: 'Montserrat', sans-serif; font-weight: 900;
  font-size: clamp(1.5rem,3.5vw,2.3rem); letter-spacing: -0.04em; line-height: 1.1;
  color: white; margin-bottom: 0.5rem;
}
.wc-name .hi  { color: rgba(255,255,255,0.55); font-weight: 600; }
.wc-name .hl  { background:linear-gradient(120deg,#fec411,#ffe068);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text; }
.wc-date      { font-size: 0.75rem; color: rgba(255,255,255,0.38); margin-bottom: 1.4rem; margin-top: 0.4rem; }
.wc-chips     { display: flex; flex-wrap: wrap; gap: 0.6rem; }
.wc-chip {
  display: flex; align-items: center; gap: 10px;
  background: rgba(255,255,255,0.07); border: 1px solid rgba(255,255,255,0.1);
  border-radius: 12px; padding: 9px 14px; backdrop-filter: blur(8px);
  transition: background 0.2s; text-decoration: none;
}
.wc-chip:hover { background: rgba(255,255,255,0.13); }
.chip-ico { width:30px;height:30px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:0.78rem;flex-shrink:0; }
.chip-lbl { display:block;font-size:0.62rem;color:rgba(255,255,255,0.45);letter-spacing:0.04em;text-transform:uppercase; }
.chip-val { display:block;font-family:'Montserrat',sans-serif;font-weight:700;font-size:0.9rem;color:white;letter-spacing:-0.02em; }
.wc-right { display:flex;flex-direction:column;align-items:flex-end;gap:6px; }
.wc-avatar {
  width:54px;height:54px;border-radius:50%;
  background:linear-gradient(135deg,var(--red),#f04060);
  display:flex;align-items:center;justify-content:center;
  font-family:'Montserrat',sans-serif;font-weight:800;font-size:1.3rem;color:white;
  border:3px solid rgba(255,255,255,0.18);
  box-shadow:0 0 0 5px rgba(191,19,57,0.22);
  align-self:flex-end;margin-bottom:6px;
}
.wc-clock { font-family:'Montserrat',sans-serif;font-weight:900;font-size:clamp(2.2rem,4vw,3.2rem);color:white;letter-spacing:-0.06em;line-height:1; }
.wc-tz    { font-size:0.68rem;letter-spacing:0.1em;text-transform:uppercase;color:rgba(255,255,255,0.3); }

/* ════════════════════════════════════════════
   PAGE WRAPPER & SECTION HEADER
════════════════════════════════════════════ */
.kc-dash { padding: 1.5rem; }
@media (max-width:600px) { .kc-dash { padding: 1rem 0.9rem; } }

.sec-hd { display:flex;align-items:center;justify-content:space-between;margin-bottom:0.9rem; }
.sec-title {
  font-family:'Montserrat',sans-serif;font-weight:700;font-size:0.72rem;
  text-transform:uppercase;letter-spacing:0.1em;color:var(--muted);
  display:flex;align-items:center;gap:8px;
}
.sec-title::before { content:'';width:3px;height:14px;border-radius:3px;background:linear-gradient(180deg,var(--red),var(--gold)); }
.sec-title.purple::before { background:linear-gradient(180deg,var(--purple),var(--gold)); }
.view-all {
  font-family:'Montserrat',sans-serif;font-size:0.7rem;font-weight:700;
  color:var(--red);text-decoration:none;display:flex;align-items:center;gap:4px;transition:gap 0.2s;
}
.view-all:hover { gap:8px; }
.view-all.purple { color: var(--purple-l); }
.view-all i { font-size:0.55rem; }

/* ════════════════════════════════════════════
   STAT CARDS
════════════════════════════════════════════ */
.stats-grid { display:grid;grid-template-columns:repeat(2,1fr);gap:1rem;margin-bottom:1.75rem; }
@media (min-width:900px) { .stats-grid { grid-template-columns:repeat(3,1fr); } }
.sc.sv { grid-column: span 2; }
@media (min-width:900px) { .sc.sv { grid-column: span 1; } }

.sc {
  background:var(--card);border-radius:var(--r);padding:1.3rem 1.35rem 1.15rem;
  border:1px solid var(--border);box-shadow:var(--sh-sm);
  position:relative;overflow:hidden;
  transition:transform 0.22s,box-shadow 0.22s;
  animation:pop 0.5s cubic-bezier(0.16,1,0.3,1) both;
}
.sc:nth-child(1){animation-delay:.05s}.sc:nth-child(2){animation-delay:.10s}.sc:nth-child(3){animation-delay:.15s}
.sc:hover { transform:translateY(-3px);box-shadow:var(--sh); }
.sc::after  { content:'';position:absolute;bottom:-24px;right:-24px;width:80px;height:80px;border-radius:50%;pointer-events:none;opacity:0.14; }
.sc::before { content:'';position:absolute;top:0;left:0;right:0;height:3px;border-radius:var(--r) var(--r) 0 0; }
.sc.sn::after  { background:var(--navy); }
.sc.ss::after  { background:var(--success); }
.sc.sv::after  { background:var(--sms); }
.sc.sn::before { background:var(--navy); }
.sc.ss::before { background:var(--success); }
.sc.sv::before { background:linear-gradient(90deg,var(--sms),var(--sms-l)); }
.sc-top    { display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:0.9rem; }
.sc-ico    { width:40px;height:40px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:1rem; }
.sc.sn .sc-ico { background:rgba(5,46,100,0.1);color:var(--navy); }
.sc.ss .sc-ico { background:rgba(0,179,126,0.1);color:var(--success); }
.sc.sv .sc-ico { background:rgba(124,58,237,0.1);color:var(--sms); }
.sc-badge  { font-family:'Montserrat',sans-serif;font-size:0.6rem;font-weight:700;padding:2px 8px;border-radius:100px; }
.na        { background:rgba(107,122,153,0.1);color:var(--muted); }
.live      { background:rgba(254,196,17,0.15);color:var(--gold-d); }
.bonus-badge { background:rgba(124,58,237,0.12);color:var(--sms); }
.sc-label  { font-size:0.73rem;color:var(--muted);margin-bottom:0.25rem; }
.sc-val    { font-family:'Montserrat',sans-serif;font-weight:800;font-size:clamp(1.05rem,2vw,1.4rem);letter-spacing:-0.03em;color:var(--text);line-height:1; }
.sc-sub    { font-size:0.67rem;color:var(--muted);margin-top:5px; }

/* SMS extras */
.sms-bonus-strip {
  display:flex;align-items:center;gap:6px;
  background:linear-gradient(120deg,rgba(124,58,237,0.08),rgba(167,139,250,0.06));
  border:1px solid rgba(124,58,237,0.18);
  border-radius:8px;padding:6px 10px;margin-top:10px;
}
.sms-bonus-strip i   { color:var(--sms-l);font-size:0.75rem; }
.sms-bonus-strip span { font-family:'Montserrat',sans-serif;font-size:0.62rem;font-weight:700;color:var(--sms); }
.sms-topup-btn {
  display:inline-flex;align-items:center;gap:5px;
  background:linear-gradient(135deg,var(--sms),#6d28d9);color:white;
  font-family:'Montserrat',sans-serif;font-size:0.62rem;font-weight:700;
  padding:6px 12px;border-radius:7px;text-decoration:none;margin-top:8px;
  box-shadow:0 3px 10px rgba(124,58,237,0.3);
  transition:transform 0.2s,box-shadow 0.2s;
}
.sms-topup-btn:hover { transform:translateY(-1px);box-shadow:0 5px 16px rgba(124,58,237,0.4); }

/* ════════════════════════════════════════════
   REFERRAL EARNINGS SECTION
════════════════════════════════════════════ */

/* Hero banner */
.ref-earn-hero {
  position: relative; overflow: hidden; border-radius: var(--r-lg);
  background: linear-gradient(120deg,#3a0a5c 0%,#5c1a8a 48%,#7c2fb0 100%);
  padding: 1.75rem 2rem; margin-bottom: 1.25rem;
  box-shadow: var(--sh-lg), 0 0 0 1px rgba(255,255,255,0.04) inset;
  animation: pop 0.55s cubic-bezier(0.16,1,0.3,1) 0.18s both;
}
.ref-earn-hero::before {
  content:''; position:absolute; inset:0; pointer-events:none;
  background-image:linear-gradient(rgba(255,255,255,0.03) 1px,transparent 1px),
                   linear-gradient(90deg,rgba(255,255,255,0.03) 1px,transparent 1px);
  background-size:30px 30px;
}
.reh-orb1 { position:absolute;width:280px;height:280px;border-radius:50%;top:-100px;right:-60px;background:radial-gradient(circle,rgba(254,196,17,0.16) 0%,transparent 65%);pointer-events:none; }
.reh-orb2 { position:absolute;width:180px;height:180px;border-radius:50%;bottom:-70px;left:15%;background:radial-gradient(circle,rgba(191,19,57,0.2) 0%,transparent 65%);pointer-events:none; }
.reh-inner { position:relative;z-index:1;display:flex;align-items:center;justify-content:space-between;gap:1.25rem;flex-wrap:wrap; }
.reh-left { display:flex;align-items:center;gap:16px; }
.reh-icon {
  width:56px;height:56px;border-radius:16px;flex-shrink:0;
  background:rgba(254,196,17,0.15);border:1.5px solid rgba(254,196,17,0.3);
  display:flex;align-items:center;justify-content:center;font-size:1.3rem;color:var(--gold);
  box-shadow:0 4px 16px rgba(254,196,17,0.2);
}
.reh-tag {
  display:inline-flex;align-items:center;gap:5px;
  background:rgba(254,196,17,0.14);border:1px solid rgba(254,196,17,0.28);
  border-radius:100px;padding:3px 10px;margin-bottom:5px;
  font-family:'Montserrat',sans-serif;font-size:0.6rem;font-weight:700;
  letter-spacing:0.09em;text-transform:uppercase;color:var(--gold);
}
.reh-title {
  font-family:'Montserrat',sans-serif;font-weight:900;font-size:1.15rem;
  color:white;letter-spacing:-0.03em;line-height:1.1;
}
.reh-sub { font-size:0.75rem;color:rgba(255,255,255,0.45);margin-top:3px; }
.reh-right { display:flex;align-items:center;gap:10px;flex-wrap:wrap; }
.reh-refer-btn {
  display:inline-flex;align-items:center;gap:8px;
  background:linear-gradient(135deg,var(--gold-d),var(--gold));
  color:#1a0a00;border:none;border-radius:11px;
  padding:11px 20px;font-family:'Montserrat',sans-serif;
  font-weight:800;font-size:0.78rem;cursor:pointer;text-decoration:none;
  box-shadow:0 4px 16px rgba(254,196,17,0.35);
  transition:transform 0.18s,box-shadow 0.18s;white-space:nowrap;
}
.reh-refer-btn:hover { transform:translateY(-2px);box-shadow:0 8px 24px rgba(254,196,17,0.5); }
.reh-creator-pill {
  display:inline-flex;align-items:center;gap:7px;
  background:rgba(255,255,255,0.08);border:1px solid rgba(255,255,255,0.14);
  border-radius:100px;padding:8px 14px;
  font-family:'Montserrat',sans-serif;font-size:0.72rem;font-weight:700;color:white;
  white-space:nowrap;
}
.reh-creator-pill i { color:var(--gold); }

/* Earnings grid */
.ref-earn-grid {
  display:grid;grid-template-columns:repeat(4,1fr);gap:1rem;margin-bottom:1rem;
}
@media(max-width:860px) { .ref-earn-grid { grid-template-columns:repeat(2,1fr); } }
@media(max-width:480px) { .ref-earn-grid { grid-template-columns:1fr; } }

.re-card {
  background:var(--card);border-radius:var(--r);
  border:1px solid var(--border);box-shadow:var(--sh-sm);
  padding:1.2rem 1.3rem;position:relative;overflow:hidden;
  transition:transform 0.22s,box-shadow 0.22s;
  animation:pop 0.5s cubic-bezier(0.16,1,0.3,1) both;
}
.re-card:nth-child(1){animation-delay:.22s}
.re-card:nth-child(2){animation-delay:.27s}
.re-card:nth-child(3){animation-delay:.32s}
.re-card:nth-child(4){animation-delay:.37s}
.re-card:hover { transform:translateY(-3px);box-shadow:var(--sh); }
.re-card::before {
  content:'';position:absolute;top:0;left:0;right:0;height:3px;
  border-radius:var(--r) var(--r) 0 0;
}
.re-card::after {
  content:'';position:absolute;bottom:-20px;right:-20px;
  width:70px;height:70px;border-radius:50%;opacity:0.1;pointer-events:none;
}

/* Today */
.re-today::before  { background:linear-gradient(90deg,#f59e0b,#fcd34d); }
.re-today::after   { background:#f59e0b; }
.re-today .re-ico  { background:rgba(245,158,11,0.1);color:#d97706; }
.re-today .re-val  { color:#d97706; }

/* This Week */
.re-week::before   { background:linear-gradient(90deg,var(--purple),var(--purple-l)); }
.re-week::after    { background:var(--purple); }
.re-week .re-ico   { background:rgba(92,26,138,0.1);color:var(--purple); }
.re-week .re-val   { color:var(--purple); }

/* This Month */
.re-month::before  { background:linear-gradient(90deg,var(--navy),var(--navy-l)); }
.re-month::after   { background:var(--navy); }
.re-month .re-ico  { background:rgba(5,46,100,0.1);color:var(--navy); }
.re-month .re-val  { color:var(--navy); }

/* Total */
.re-total::before  { background:linear-gradient(90deg,var(--success),#34d399); }
.re-total::after   { background:var(--success); }
.re-total .re-ico  { background:rgba(0,179,126,0.1);color:var(--success); }
.re-total .re-val  { color:var(--success); }

.re-top   { display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:0.8rem; }
.re-ico   { width:38px;height:38px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:0.9rem; }
.re-period {
  font-family:'Montserrat',sans-serif;font-size:0.58rem;font-weight:700;
  text-transform:uppercase;letter-spacing:0.08em;color:var(--muted);
  background:rgba(5,46,100,0.06);border:1px solid var(--border);
  border-radius:100px;padding:2px 8px;
}
.re-label { font-size:0.71rem;color:var(--muted);margin-bottom:0.25rem; }
.re-val   { font-family:'Montserrat',sans-serif;font-weight:900;font-size:clamp(0.95rem,2vw,1.3rem);letter-spacing:-0.03em;line-height:1; }
.re-sub   { font-size:0.65rem;color:var(--muted);margin-top:5px; }

/* Wallet note strip */
.ref-wallet-note {
  display:flex;align-items:flex-start;gap:13px;
  background:linear-gradient(120deg,rgba(5,46,100,0.05),rgba(5,46,100,0.02));
  border:1.5px solid rgba(5,46,100,0.12);
  border-radius:var(--r);padding:1rem 1.25rem;margin-bottom:1.75rem;
  animation:pop 0.5s cubic-bezier(0.16,1,0.3,1) 0.4s both;
}
.rwn-icon {
  width:38px;height:38px;flex-shrink:0;border-radius:10px;
  background:linear-gradient(135deg,var(--purple),var(--purple-l));
  display:flex;align-items:center;justify-content:center;
  font-size:0.88rem;color:white;
  box-shadow:0 3px 10px rgba(92,26,138,0.3);
}
.rwn-title {
  font-family:'Montserrat',sans-serif;font-weight:800;font-size:0.8rem;
  color:var(--navy);margin-bottom:3px;
}
.rwn-text { font-size:0.75rem;color:var(--muted);line-height:1.65; }
.rwn-text strong { font-family:'Montserrat',sans-serif;font-weight:700;color:var(--navy); }
.rwn-pills { display:flex;gap:6px;flex-wrap:wrap;margin-top:8px; }
.rwn-pill {
  display:inline-flex;align-items:center;gap:5px;
  border-radius:8px;padding:4px 10px;
  font-family:'Montserrat',sans-serif;font-size:0.62rem;font-weight:700;
}
.rwn-pill-g { background:rgba(0,179,126,0.1);color:var(--success);border:1px solid rgba(0,179,126,0.2); }
.rwn-pill-p { background:rgba(92,26,138,0.08);color:var(--purple);border:1px solid rgba(92,26,138,0.18); }

/* ════════════════════════════════════════════
   CHARTS
════════════════════════════════════════════ */
.charts-grid {
  display:grid;grid-template-columns:1fr 1fr;
  gap:1rem;margin-bottom:1.75rem;
}
@media (max-width:700px) { .charts-grid { grid-template-columns:1fr; } }

.chart-card {
  background:var(--card);border:1px solid var(--border);
  border-radius:var(--r);box-shadow:var(--sh-sm);
  padding:1.25rem 1.35rem;
  animation:pop 0.55s cubic-bezier(0.16,1,0.3,1) .2s both;
}
.chart-hd   { display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:1rem; }
.chart-title { font-family:'Montserrat',sans-serif;font-weight:700;font-size:0.85rem;color:var(--text); }
.chart-sub  { font-size:0.67rem;color:var(--muted);margin-top:2px; }
.chart-ico  {
  width:34px;height:34px;border-radius:9px;
  display:flex;align-items:center;justify-content:center;font-size:0.85rem;flex-shrink:0;
}
.chart-wrap { position:relative;height:210px; }

/* ════════════════════════════════════════════
   NOTIFICATIONS CARD
════════════════════════════════════════════ */
.notif-card {
  background:var(--card);border-radius:var(--r);
  border:1px solid var(--border);box-shadow:var(--sh-sm);
  overflow:hidden;margin-bottom:1.75rem;
  animation:pop 0.55s cubic-bezier(0.16,1,0.3,1) .25s both;
}
.notif-hd {
  display:flex;align-items:center;justify-content:space-between;
  padding:1rem 1.35rem;border-bottom:1px solid var(--border);background:#fafbfd;
}
.notif-title-group { display:flex;align-items:center;gap:10px; }
.notif-title { font-family:'Montserrat',sans-serif;font-weight:700;font-size:0.9rem;color:var(--text); }
.notif-count-badge {
  background:var(--red);color:white;
  font-family:'Montserrat',sans-serif;font-size:0.6rem;font-weight:800;
  padding:2px 7px;border-radius:100px;letter-spacing:0.04em;
}

/* Tabs */
.notif-tabs {
  display:flex;gap:0;border-bottom:1px solid var(--border);
  padding:0 1.35rem;background:white;overflow-x:auto;
  scrollbar-width:none;
}
.notif-tabs::-webkit-scrollbar { display:none; }
.ntab {
  display:flex;align-items:center;gap:7px;
  padding:0.85rem 1.1rem;
  font-family:'Montserrat',sans-serif;font-size:0.72rem;font-weight:700;
  color:var(--muted);cursor:pointer;border:none;background:none;
  border-bottom:2.5px solid transparent;margin-bottom:-1px;
  white-space:nowrap;transition:color 0.18s,border-color 0.18s;
}
.ntab:hover { color:var(--text); }
.ntab.active { color:var(--navy);border-bottom-color:var(--red); }
.ntab .ntab-ct {
  display:inline-flex;align-items:center;justify-content:center;
  min-width:18px;height:18px;border-radius:9px;padding:0 5px;
  font-size:0.58rem;font-weight:800;
  background:rgba(5,46,100,0.08);color:var(--muted);
  transition:background 0.18s,color 0.18s;
}
.ntab.active .ntab-ct { background:var(--red);color:white; }
.ntab i { font-size:0.82rem; }

/* Panels */
.notif-panel { display:none;animation:fade-in 0.25s ease both; }
.notif-panel.active { display:block; }

/* ════════════════════════════════════════════
   TABLE STYLES
════════════════════════════════════════════ */
.tbl-scroll { overflow-x:auto;-webkit-overflow-scrolling:touch; }
.tbl-scroll table { width:100%;border-collapse:collapse;font-size:0.82rem; }
.tbl-scroll thead tr { background:#fafbfd; }
.tbl-scroll th {
  padding:0.7rem 1rem;text-align:left;
  font-family:'Montserrat',sans-serif;font-size:0.62rem;font-weight:700;
  text-transform:uppercase;letter-spacing:0.07em;color:var(--muted);
  border-bottom:1px solid var(--border);white-space:nowrap;
}
.tbl-scroll td {
  padding:0.9rem 1rem;
  border-bottom:1px solid rgba(5,46,100,0.05);
  vertical-align:middle;
}
.tbl-scroll tbody tr:last-child td { border-bottom:none; }
.tbl-scroll tbody tr { transition:background 0.15s; }
.tbl-scroll tbody tr:hover { background:rgba(5,46,100,0.025); }

/* Cell utilities */
.tp  { font-size:0.82rem;color:var(--text);font-weight:500;line-height:1.3; }
.ts  { font-size:0.7rem;color:var(--muted);margin-top:2px; }
.tm  {
  font-family:'Montserrat',sans-serif;font-weight:700;font-size:0.72rem;color:var(--navy);
  background:rgba(5,46,100,0.06);border:1px solid rgba(5,46,100,0.1);
  border-radius:6px;padding:3px 8px;letter-spacing:0.02em;
  white-space:nowrap;display:inline-block;
}
.ta  { font-family:'Montserrat',sans-serif;font-weight:700;font-size:0.85rem;white-space:nowrap; }

/* Status badges */
.bdg {
  display:inline-flex;align-items:center;gap:5px;
  font-family:'Montserrat',sans-serif;font-size:0.62rem;font-weight:700;
  letter-spacing:0.04em;text-transform:uppercase;
  padding:3px 9px;border-radius:100px;white-space:nowrap;
}
.bdg::before { content:'';width:5px;height:5px;border-radius:50%;flex-shrink:0; }

.b-paid       { background:rgba(0,179,126,0.1);color:var(--success);border:1px solid rgba(0,179,126,0.2); }
.b-paid::before { background:var(--success); }

.b-completed  { background:rgba(0,179,126,0.1);color:var(--success);border:1px solid rgba(0,179,126,0.2); }
.b-completed::before { background:var(--success); }

.b-pending    { background:rgba(245,158,11,0.1);color:var(--warning);border:1px solid rgba(245,158,11,0.2); }
.b-pending::before { background:var(--warning);animation:pulse-dot 1.5s infinite; }

.b-processing { background:rgba(10,68,148,0.08);color:var(--navy-l);border:1px solid rgba(10,68,148,0.18); }
.b-processing::before { background:var(--navy-l);animation:pulse-dot 1.2s infinite; }

.b-failed     { background:rgba(191,19,57,0.08);color:var(--red);border:1px solid rgba(191,19,57,0.2); }
.b-failed::before { background:var(--red); }

/* Checked-in tag */
.in-tag {
  display:inline-flex;align-items:center;gap:4px;
  background:rgba(5,46,100,0.07);color:var(--navy);
  font-family:'Montserrat',sans-serif;font-size:0.58rem;font-weight:700;
  letter-spacing:0.04em;padding:2px 7px;border-radius:100px;
  margin-top:4px;border:1px solid rgba(5,46,100,0.12);
}

/* Device icon */
.dev-ico {
  width:28px;height:28px;border-radius:7px;
  background:rgba(5,46,100,0.06);color:var(--navy);
  display:inline-flex;align-items:center;justify-content:center;
  font-size:0.7rem;flex-shrink:0;
}

/* Empty state */
.empty {
  display:flex;flex-direction:column;align-items:center;
  justify-content:center;padding:3.5rem 1rem;text-align:center;gap:0.75rem;
}
.empty-ico {
  width:56px;height:56px;border-radius:16px;
  background:rgba(5,46,100,0.05);border:1px solid var(--border);
  display:flex;align-items:center;justify-content:center;
  font-size:1.3rem;color:var(--muted);
}
.empty p { font-size:0.82rem;color:var(--muted); }

@media (max-width:600px) {
  .tbl-scroll th, .tbl-scroll td { padding:0.65rem 0.75rem; }
}

/* ════════════════════════════════════════════
   MOBILE NAV
════════════════════════════════════════════ */
.mob-nav {
  position:fixed;bottom:0;left:0;right:0;height:62px;
  background:var(--card);border-top:1px solid var(--border);
  box-shadow:0 -4px 20px rgba(5,46,100,0.08);
  z-index:100;display:flex;align-items:center;justify-content:space-around;
}
@media (min-width:768px) { .mob-nav { display:none; } }
.mn-item {
  display:flex;flex-direction:column;align-items:center;gap:2px;
  text-decoration:none;color:var(--muted);
  font-family:'Montserrat',sans-serif;font-size:0.53rem;font-weight:700;
  letter-spacing:0.04em;text-transform:uppercase;transition:color 0.2s;
}
.mn-item.active,.mn-item:hover { color:var(--red); }
.mn-item i { font-size:1.05rem; }
.mn-fab {
  width:50px;height:50px;border-radius:50%;
  background:linear-gradient(135deg,var(--red),#f04060);
  display:flex;align-items:center;justify-content:center;
  color:white;font-size:1.15rem;margin-top:-14px;
  box-shadow:0 4px 18px rgba(191,19,57,0.45);
  text-decoration:none;transition:transform 0.2s,box-shadow 0.2s;
}
.mn-fab:hover { transform:scale(1.08) translateY(-2px);box-shadow:0 8px 24px rgba(191,19,57,0.5); }
</style>
</head>
<body>

<!-- ══ PRELOADER ══ -->
<div id="preloader">
  <div class="pre-wordmark">Key<em>chele</em></div>
  <div class="pre-track"><div class="pre-fill"></div></div>
</div>

<div class="kc-dash">

  <!-- ══ NO-PAGE BANNER ══ -->
  <?php if (!$has_page): ?>
  <div class="dash-alert alert-page" id="noPageBanner">
    <div class="alert-icon"><i class="fa-solid fa-store"></i></div>
    <div class="alert-body">
      <div class="alert-title">Your creator page isn't set up yet</div>
      <div class="alert-text">To start receiving support and selling tickets on Keychele, create your public page first.</div>
    </div>
    <div class="alert-actions">
      <a href="https://keychele.co.ke/msanii/index.php?page=create_page" class="alert-btn">
        <i class="fa-solid fa-plus"></i> Create My Page
      </a>
      <button class="alert-dismiss" onclick="this.closest('.dash-alert').remove()" title="Dismiss">
        <i class="fa-solid fa-xmark"></i>
      </button>
    </div>
  </div>
  <?php endif; ?>

  <!-- ══ NO-PIN BANNER ══ -->
  <?php if (!$has_pin): ?>
  <div class="dash-alert alert-pin" id="noPinBanner" style="animation-delay:.08s">
    <div class="alert-icon"><i class="fa-solid fa-shield-halved"></i></div>
    <div class="alert-body">
      <div style="display:flex;align-items:center;gap:8px;margin-bottom:0.35rem;flex-wrap:wrap">
        <div class="alert-title" style="margin-bottom:0">Withdrawal PIN Not Set</div>
        <span class="pin-urgency">Action Required</span>
      </div>
      <div class="alert-text">Your funds are unprotected. Set a 4–6 digit withdrawal PIN to secure every payout with two-factor authentication.</div>
      <div class="pin-steps">
        <span class="pin-step"><i class="fa-solid fa-key"></i> Create your PIN</span>
        <span class="pin-step"><i class="fa-solid fa-mobile-screen-button"></i> SMS OTP on withdrawal</span>
        <span class="pin-step"><i class="fa-solid fa-lock"></i> Funds secured</span>
      </div>
    </div>
    <div class="alert-actions">
      <a href="https://keychele.co.ke/msanii/index.php?page=withdrawal2factorauth" class="alert-btn">
        <i class="fa-solid fa-shield-halved"></i> Set PIN Now
      </a>
      <button class="alert-dismiss" onclick="this.closest('.dash-alert').remove()" title="Dismiss">
        <i class="fa-solid fa-xmark"></i>
      </button>
    </div>
  </div>
  <?php endif; ?>

  <!-- ══ WELCOME CARD ══ -->
  <div class="welcome-card">
    <div class="wc-grid"></div>
    <div class="wc-orb wc-orb-1"></div>
    <div class="wc-orb wc-orb-2"></div>
    <div class="wc-body">
      <div>
        <div class="wc-tag"><?= $wave ?> Creator Dashboard</div>
        <h1 class="wc-name">
          <span class="hi"><?= $greeting ?>, </span><br>
          <span class="hl"><?= htmlspecialchars($display_name) ?></span>
        </h1>
        <div class="wc-date"><?= $current_date ?></div>
        <div class="wc-chips">
          <div class="wc-chip">
            <div class="chip-ico" style="background:rgba(0,179,126,0.2)">
              <i class="fa-solid fa-vault" style="color:#2ecc9a"></i>
            </div>
            <div>
              <span class="chip-lbl">Balance</span>
              <span class="chip-val">KSh <?= $account_balance ?></span>
            </div>
          </div>
          <a href="https://keychele.co.ke/msanii/index.php?page=sms_topup.php" class="wc-chip">
            <div class="chip-ico" style="background:rgba(124,58,237,0.2)">
              <i class="fa-solid fa-message" style="color:var(--sms-l)"></i>
            </div>
            <div>
              <span class="chip-lbl">SMS Credits</span>
              <span class="chip-val"><?= $sms_balance ?> SMS</span>
            </div>
          </a>
          <div class="wc-chip">
            <div class="chip-ico" style="background:rgba(124,47,176,0.25)">
              <i class="fa-solid fa-users" style="color:#c084fc"></i>
            </div>
            <div>
              <span class="chip-lbl">Referrals</span>
              <span class="chip-val"><?= $ref_count ?> Creators</span>
            </div>
          </div>
        </div>
      </div>
      <div class="wc-right">
        <div class="wc-avatar"><?= $initials ?></div>
        <div class="wc-clock" id="liveClock"><?= $current_time ?></div>
        <div class="wc-tz">Nairobi · EAT</div>
      </div>
    </div>
  </div>

  <!-- ══ STAT CARDS ══ -->
  <div class="sec-hd"><div class="sec-title">Overview</div></div>
  <div class="stats-grid">

    <div class="sc sn">
      <div class="sc-top">
        <div class="sc-ico"><i class="fa-solid fa-money-bill-transfer"></i></div>
        <span class="sc-badge na">Lifetime</span>
      </div>
      <div class="sc-label">Total Withdrawn</div>
      <div class="sc-val">KSh <?= $withdrawals ?></div>
      <div class="sc-sub">Completed payouts only</div>
    </div>

    <div class="sc ss">
      <div class="sc-top">
        <div class="sc-ico"><i class="fa-solid fa-vault"></i></div>
        <span class="sc-badge live">Live</span>
      </div>
      <div class="sc-label">Account Balance</div>
      <div class="sc-val">KSh <?= $account_balance ?></div>
      <div class="sc-sub">Available to withdraw</div>
    </div>

    <div class="sc sv">
      <div class="sc-top">
        <div class="sc-ico"><i class="fa-solid fa-message"></i></div>
        <span class="sc-badge bonus-badge"><i class="fa-solid fa-gift" style="font-size:.55rem"></i>&nbsp;SMS</span>
      </div>
      <div class="sc-label">SMS Balance</div>
      <div class="sc-val"><?= $sms_balance ?> <span style="font-size:0.7rem;font-weight:500;color:var(--muted)">credits</span></div>
      <div class="sc-sub">Each SMS = 1 credit &nbsp;·&nbsp; OTPs &amp; buyer notifications</div>
      <?php if ($bonus_just_given): ?>
      <div class="sms-bonus-strip">
        <i class="fa-solid fa-gift"></i>
        <span>🎉 Welcome bonus! <?= $welcome_bonus ?> free credits added.</span>
      </div>
      <?php endif; ?>
      <a href="https://keychele.co.ke/msanii/index.php?page=sms_topup" class="sms-topup-btn">
        <i class="fa-solid fa-plus"></i> Top Up SMS
      </a>
    </div>

  </div>

  <!-- ══ ════════════════════════════════ ══
       REFERRAL EARNINGS SECTION
  ══ ════════════════════════════════ ══ -->
  <div class="sec-hd">
    <div class="sec-title purple">
      <i class="fa-solid fa-user-plus" style="font-size:0.75rem;color:var(--purple-l)"></i>
      Referral Earnings
    </div>
    <a href="https://keychele.co.ke/msanii/index.php?page=refer_creator" class="view-all purple">
      View Full Report <i class="fa-solid fa-chevron-right"></i>
    </a>
  </div>

  <!-- Referral Hero Banner -->
  <div class="ref-earn-hero">
    <div class="reh-orb1"></div>
    <div class="reh-orb2"></div>
    <div class="reh-inner">
      <div class="reh-left">
        <div class="reh-icon"><i class="fa-solid fa-people-arrows"></i></div>
        <div>
          <div class="reh-tag"><i class="fa-solid fa-percent" style="font-size:0.6rem"></i> 1% Per Ticket Sold</div>
          <div class="reh-title">Refer &amp; Earn</div>
          <div class="reh-sub">Earn 1% of every ticket sold by creators you refer — for life, no cap</div>
        </div>
      </div>
      <div class="reh-right">
        <div class="reh-creator-pill">
          <i class="fa-solid fa-users"></i>
          <?= $ref_count ?> Creator<?= $ref_count !== 1 ? 's' : '' ?> Referred
        </div>
        <a href="https://keychele.co.ke/msanii/index.php?page=refer_creator" class="reh-refer-btn">
          <i class="fa-solid fa-share-nodes"></i> Share My Link
        </a>
      </div>
    </div>
  </div>

  <!-- Earnings Cards -->
  <div class="ref-earn-grid">

    <!-- Today -->
    <div class="re-card re-today">
      <div class="re-top">
        <div class="re-ico"><i class="fa-solid fa-sun"></i></div>
        <span class="re-period">Today</span>
      </div>
      <div class="re-label">Earnings Today</div>
      <div class="re-val">KSh <?= number_format($ref_today, 2) ?></div>
      <div class="re-sub"><?= date('d M Y') ?></div>
    </div>

    <!-- This Week -->
    <div class="re-card re-week">
      <div class="re-top">
        <div class="re-ico"><i class="fa-solid fa-calendar-week"></i></div>
        <span class="re-period">This Week</span>
      </div>
      <div class="re-label">Earnings This Week</div>
      <div class="re-val">KSh <?= number_format($ref_week, 2) ?></div>
      <div class="re-sub"><?= date('d M', strtotime('monday this week')) ?> – <?= date('d M') ?></div>
    </div>

    <!-- This Month -->
    <div class="re-card re-month">
      <div class="re-top">
        <div class="re-ico"><i class="fa-solid fa-calendar-days"></i></div>
        <span class="re-period">This Month</span>
      </div>
      <div class="re-label">Earnings This Month</div>
      <div class="re-val">KSh <?= number_format($ref_month, 2) ?></div>
      <div class="re-sub"><?= date('F Y') ?></div>
    </div>

    <!-- Total Lifetime -->
    <div class="re-card re-total">
      <div class="re-top">
        <div class="re-ico"><i class="fa-solid fa-trophy"></i></div>
        <span class="re-period">Lifetime</span>
      </div>
      <div class="re-label">Total Referral Earned</div>
      <div class="re-val">KSh <?= number_format($ref_total, 2) ?></div>
      <div class="re-sub">Since you joined Keychele</div>
    </div>

  </div>

  <!-- Wallet Note -->
  <div class="ref-wallet-note">
    <div class="rwn-icon"><i class="fa-solid fa-vault"></i></div>
    <div>
      <div class="rwn-title">Referral Earnings Are Added Directly to Your Wallet</div>
      <div class="rwn-text">
        Every shilling you earn from referrals is <strong>automatically credited to your Keychele wallet balance</strong>
        the moment a referred creator sells a ticket. You can withdraw your combined earnings — ticket revenue, support, and referral commissions — at any time from your wallet.
      </div>
      <div class="rwn-pills">
        <span class="rwn-pill rwn-pill-g"><i class="fa-solid fa-circle-check"></i> Auto-credited instantly</span>
        <span class="rwn-pill rwn-pill-p"><i class="fa-solid fa-wallet"></i> Included in your withdrawable balance</span>
        <?php if ($ref_pending > 0): ?>
        <span class="rwn-pill" style="background:rgba(245,158,11,0.1);color:#d97706;border:1px solid rgba(245,158,11,0.25)">
          <i class="fa-solid fa-hourglass-half"></i> KSh <?= number_format($ref_pending, 2) ?> pending disbursement
        </span>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- ══ CHARTS ══ -->
  <div class="sec-hd"><div class="sec-title">Analytics</div></div>
  <div class="charts-grid">

    <div class="chart-card">
      <div class="chart-hd">
        <div>
          <div class="chart-title">Ticket Sales</div>
          <div class="chart-sub">Last 6 months — quantity &amp; revenue</div>
        </div>
        <div class="chart-ico" style="background:rgba(191,19,57,0.08)">
          <i class="fa-solid fa-ticket" style="color:var(--red)"></i>
        </div>
      </div>
      <div class="chart-wrap">
        <canvas id="ticketChart"></canvas>
      </div>
    </div>

    <div class="chart-card">
      <div class="chart-hd">
        <div>
          <div class="chart-title">Support Received</div>
          <div class="chart-sub">Last 6 months — KSh total</div>
        </div>
        <div class="chart-ico" style="background:rgba(5,46,100,0.08)">
          <i class="fa-solid fa-heart" style="color:var(--navy)"></i>
        </div>
      </div>
      <div class="chart-wrap">
        <canvas id="supportChart"></canvas>
      </div>
    </div>

  </div>

  <!-- ══ NOTIFICATIONS ══ -->
  <div class="sec-hd"><div class="sec-title">Notifications</div></div>
  <div class="notif-card">
    <div class="notif-hd">
      <div class="notif-title-group">
        <div class="notif-title">Activity Feed</div>
        <?php if ($total_notifs > 0): ?>
        <span class="notif-count-badge"><?= $total_notifs ?> new</span>
        <?php endif; ?>
      </div>
    </div>

    <!-- Tabs -->
    <div class="notif-tabs">
      <button class="ntab active" onclick="switchTab('visits',this)">
        <i class="fa-solid fa-ticket"></i> Ticket Visits
        <span class="ntab-ct"><?= $notif_visits ?></span>
      </button>
      <button class="ntab" onclick="switchTab('tickets',this)">
        <i class="fa-solid fa-bag-shopping"></i> Ticket Sales
        <span class="ntab-ct"><?= $notif_tickets ?></span>
      </button>
      <button class="ntab" onclick="switchTab('support',this)">
        <i class="fa-solid fa-heart"></i> Support
        <span class="ntab-ct"><?= $notif_support ?></span>
      </button>
      <button class="ntab" onclick="switchTab('withdrawals',this)">
        <i class="fa-solid fa-money-bill-transfer"></i> Withdrawals
        <span class="ntab-ct"><?= count($recent_withdrawals) ?></span>
      </button>
    </div>

    <!-- ── Tab: Ticket Visits ── -->
    <div class="notif-panel active" id="tab-visits">
      <div class="tbl-scroll">
        <?php if (empty($page_visits)): ?>
          <div class="empty">
            <div class="empty-ico"><i class="fa-solid fa-eye-slash"></i></div>
            <p>No page visits recorded yet</p>
          </div>
        <?php else: ?>
        <table>
          <thead>
            <tr>
              <th>Ticket #</th><th>Event</th><th>Location</th>
              <th>Device</th><th>Browser / OS</th><th>IP</th><th>Visited</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($page_visits as $v): ?>
            <tr>
              <td><span class="tm"><?= htmlspecialchars($v['ticket_number'] ?? '—') ?></span></td>
              <td>
                <div class="tp" style="max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                  <?= htmlspecialchars($v['event_title'] ?? '—') ?>
                </div>
              </td>
              <td>
                <div class="tp" style="font-size:.76rem">
                  <?= htmlspecialchars($v['city'] ?? '') ?>
                  <?= (!empty($v['city']) && !empty($v['country'])) ? ', ' : '' ?>
                  <?= htmlspecialchars($v['country'] ?? '—') ?>
                </div>
              </td>
              <td>
                <div style="display:flex;align-items:center;gap:6px">
                  <span class="dev-ico">
                    <?php
                      $dev = strtolower($v['device'] ?? '');
                      $ico = (str_contains($dev,'mobile') || str_contains($dev,'phone'))
                           ? 'mobile-screen-button'
                           : (str_contains($dev,'tablet') ? 'tablet-screen-button' : 'desktop');
                    ?>
                    <i class="fa-solid fa-<?= $ico ?>"></i>
                  </span>
                  <span class="ts"><?= htmlspecialchars($v['device'] ?? '—') ?></span>
                </div>
              </td>
              <td>
                <div class="tp" style="font-size:.74rem"><?= htmlspecialchars($v['browser'] ?? '—') ?></div>
                <div class="ts"><?= htmlspecialchars($v['os'] ?? '') ?></div>
              </td>
              <td><span class="tm"><?= htmlspecialchars($v['ip_address'] ?? '—') ?></span></td>
              <td style="color:var(--muted);font-size:.7rem;white-space:nowrap"><?= date('d M, H:i', strtotime($v['visited_at'])) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </div>
    </div>

    <!-- ── Tab: Ticket Sales ── -->
    <div class="notif-panel" id="tab-tickets">
      <div class="tbl-scroll">
        <?php if (empty($recent_tickets)): ?>
          <div class="empty">
            <div class="empty-ico"><i class="fa-solid fa-ticket-simple"></i></div>
            <p>No ticket purchases yet</p>
          </div>
        <?php else: ?>
        <table>
          <thead>
            <tr>
              <th>Buyer</th><th>Event</th><th>Ticket #</th>
              <th>Qty</th><th>Amount</th><th>Receipt</th><th>Status</th><th>Date</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($recent_tickets as $t):
              $sc = match(strtolower($t['status'])) {
                'paid'    => 'b-paid',
                'pending' => 'b-pending',
                'failed'  => 'b-failed',
                default   => 'b-pending'
              };
            ?>
            <tr>
              <td>
                <div class="tp"><?= htmlspecialchars($t['buyer_name']) ?></div>
                <div class="ts"><?= htmlspecialchars($t['buyer_phone'] ?? '') ?></div>
              </td>
              <td>
                <div class="tp" style="max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:.76rem">
                  <?= htmlspecialchars($t['event_title'] ?? '—') ?>
                </div>
              </td>
              <td><span class="tm"><?= htmlspecialchars($t['ticket_number']) ?></span></td>
              <td style="text-align:center">
                <span style="font-family:'Montserrat',sans-serif;font-weight:700;font-size:0.85rem">
                  <?= (int)$t['quantity'] ?>
                </span>
              </td>
              <td class="ta" style="color:var(--red)">KSh <?= number_format($t['amount_paid'], 0) ?></td>
              <td><span class="tm"><?= htmlspecialchars($t['mpesa_receipt'] ?? '—') ?></span></td>
              <td>
                <span class="bdg <?= $sc ?>"><?= ucfirst($t['status']) ?></span>
                <?php if ($t['is_checked_in']): ?>
                  <br><span class="in-tag"><i class="fa-solid fa-check" style="font-size:.5rem"></i> In</span>
                <?php endif; ?>
              </td>
              <td style="color:var(--muted);font-size:.7rem;white-space:nowrap"><?= date('d M, H:i', strtotime($t['created_at'])) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </div>
    </div>

    <!-- ── Tab: Support ── -->
    <div class="notif-panel" id="tab-support">
      <div class="tbl-scroll">
        <?php if (empty($recent_support)): ?>
          <div class="empty">
            <div class="empty-ico"><i class="fa-solid fa-heart"></i></div>
            <p>No support received yet</p>
          </div>
        <?php else: ?>
        <table>
          <thead>
            <tr><th>Supporter</th><th>Amount</th><th>Receipt</th><th>Status</th><th>Date</th></tr>
          </thead>
          <tbody>
            <?php foreach ($recent_support as $s):
              $sc = match(strtolower($s['status'])) {
                'completed','success' => 'b-completed',
                'pending'             => 'b-pending',
                'failed'              => 'b-failed',
                default               => 'b-pending'
              };
            ?>
            <tr>
              <td>
                <div class="tp"><?= htmlspecialchars($s['supporter_name'] ?? 'Anonymous') ?></div>
                <div class="ts"><?= htmlspecialchars($s['phone'] ?? '') ?></div>
              </td>
              <td class="ta" style="color:var(--gold-d)">KSh <?= number_format($s['amount'], 0) ?></td>
              <td><span class="tm"><?= htmlspecialchars($s['mpesa_receipt'] ?? '—') ?></span></td>
              <td><span class="bdg <?= $sc ?>"><?= ucfirst($s['status']) ?></span></td>
              <td style="color:var(--muted);font-size:.7rem;white-space:nowrap"><?= date('d M, H:i', strtotime($s['created_at'])) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </div>
    </div>

    <!-- ── Tab: Withdrawals ── -->
    <div class="notif-panel" id="tab-withdrawals">
      <div class="tbl-scroll">
        <?php if (empty($recent_withdrawals)): ?>
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
            <?php foreach ($recent_withdrawals as $w):
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
                  <span style="width:28px;height:28px;border-radius:7px;background:rgba(5,46,100,0.08);display:inline-flex;align-items:center;justify-content:center;color:var(--navy);font-size:.72rem">
                    <i class="fa-solid fa-<?= strtolower($w['method'] ?? '') === 'mpesa' ? 'mobile-screen-button' : 'building-columns' ?>"></i>
                  </span>
                  <span class="tp" style="font-size:.76rem"><?= htmlspecialchars(strtoupper($w['method'] ?? '—')) ?></span>
                </div>
              </td>
              <td><span class="tm"><?= htmlspecialchars($w['destination'] ?? $w['mpesa_number'] ?? '—') ?></span></td>
              <td class="ta" style="color:var(--navy)">KSh <?= number_format($w['amount'], 0) ?></td>
              <td style="color:var(--muted);font-size:.74rem">KSh <?= number_format($w['charge'] ?? 0, 0) ?></td>
              <td class="ta" style="color:var(--red)">KSh <?= number_format($w['total_amount'], 0) ?></td>
              <td><span class="tm"><?= htmlspecialchars($w['transaction_code'] ?? '—') ?></span></td>
              <td><span class="bdg <?= $sc ?>"><?= ucfirst($w['status']) ?></span></td>
              <td style="color:var(--muted);font-size:.7rem;white-space:nowrap"><?= date('d M Y', strtotime($w['requested_at'])) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </div>
    </div>

  </div><!-- /notif-card -->

</div><!-- /kc-dash -->

<!-- ══ MOBILE NAV ══ -->
<nav class="mob-nav">
  <a href="https://keychele.co.ke/msanii/index.php?page=dashboard.php" class="mn-item active">
    <i class="fa-solid fa-house"></i>Home
  </a>
  <a href="https://keychele.co.ke/msanii/index.php?page=wallet.php" class="mn-item">
    <i class="fa-solid fa-wallet"></i>Wallet
  </a>
  <a href="https://keychele.co.ke/msanii/index.php?page=create_event.php" class="mn-fab">
    <i class="fa-solid fa-plus"></i>
  </a>
  <a href="https://keychele.co.ke/msanii/index.php?page=sms_topup.php" class="mn-item">
    <i class="fa-solid fa-message"></i>SMS
  </a>
  <a href="https://keychele.co.ke/msanii/index.php?page=profile.php" class="mn-item">
    <i class="fa-solid fa-user"></i>Profile
  </a>
</nav>

<script>
// ── Preloader ──
window.addEventListener('load', () => {
  setTimeout(() => document.getElementById('preloader').classList.add('gone'), 1600);
});

// ── Live clock ──
function tick() {
  const n  = new Date();
  const h  = n.getHours() % 12 || 12;
  const m  = String(n.getMinutes()).padStart(2, '0');
  const ap = n.getHours() >= 12 ? 'PM' : 'AM';
  const el = document.getElementById('liveClock');
  if (el) el.textContent = `${h}:${m} ${ap}`;
}
setInterval(tick, 1000);

// ── Tab switcher ──
function switchTab(id, btn) {
  document.querySelectorAll('.notif-panel').forEach(p => p.classList.remove('active'));
  document.querySelectorAll('.ntab').forEach(b => b.classList.remove('active'));
  document.getElementById('tab-' + id).classList.add('active');
  btn.classList.add('active');
}

// ── Charts ──
Chart.defaults.font.family = "'Glacial Indifference', 'Trebuchet MS', sans-serif";
Chart.defaults.color = '#6b7a99';
const gridColor = 'rgba(5,46,100,0.05)';

// Ticket chart
new Chart(document.getElementById('ticketChart'), {
  data: {
    labels: <?= json_encode($ticket_labels) ?>,
    datasets: [
      {
        type: 'bar',
        label: 'Tickets Sold',
        data: <?= json_encode($ticket_data) ?>,
        backgroundColor: ctx => {
          const g = ctx.chart.ctx.createLinearGradient(0,0,0,220);
          g.addColorStop(0,'rgba(191,19,57,0.85)');
          g.addColorStop(1,'rgba(191,19,57,0.15)');
          return g;
        },
        borderRadius: 7,
        borderSkipped: false,
        yAxisID: 'y'
      },
      {
        type: 'line',
        label: 'Revenue (KSh)',
        data: <?= json_encode($ticket_revenue) ?>,
        borderColor: '#fec411',
        backgroundColor: 'transparent',
        borderWidth: 2.5,
        pointBackgroundColor: '#fec411',
        pointBorderColor: '#fff',
        pointBorderWidth: 2,
        pointRadius: 5,
        tension: 0.4,
        yAxisID: 'y1'
      }
    ]
  },
  options: {
    responsive: true,
    maintainAspectRatio: false,
    interaction: { mode: 'index', intersect: false },
    plugins: {
      legend: {
        position: 'bottom',
        labels: { font:{size:11}, boxWidth:10, boxHeight:10, borderRadius:3, useBorderRadius:true, padding:12 }
      },
      tooltip: { padding:10, cornerRadius:8 }
    },
    scales: {
      x:  { grid:{display:false}, ticks:{font:{size:11}} },
      y:  { grid:{color:gridColor}, ticks:{font:{size:10}}, beginAtZero:true },
      y1: {
        position: 'right', grid:{display:false},
        ticks:{ font:{size:10}, color:'#c9a300', callback: v => 'KSh ' + v.toLocaleString() },
        beginAtZero: true
      }
    }
  }
});

// Support chart
new Chart(document.getElementById('supportChart'), {
  type: 'line',
  data: {
    labels: <?= json_encode($support_labels) ?>,
    datasets: [{
      label: 'Support (KSh)',
      data: <?= json_encode($support_data) ?>,
      borderColor: '#052e64',
      backgroundColor: ctx => {
        const g = ctx.chart.ctx.createLinearGradient(0,0,0,200);
        g.addColorStop(0,'rgba(5,46,100,0.18)');
        g.addColorStop(1,'rgba(5,46,100,0.01)');
        return g;
      },
      fill: true, tension: 0.42, borderWidth: 2.5,
      pointBackgroundColor: '#052e64',
      pointBorderColor: '#fff',
      pointBorderWidth: 2,
      pointRadius: 5,
      pointHoverRadius: 7
    }]
  },
  options: {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
      legend: { display: false },
      tooltip: {
        padding:10, cornerRadius:8,
        callbacks: { label: ctx => ' KSh ' + ctx.parsed.y.toLocaleString() }
      }
    },
    scales: {
      x: { grid:{display:false}, ticks:{font:{size:11}} },
      y: {
        grid:{color:gridColor},
        ticks:{ font:{size:10}, callback: v => 'KSh ' + v.toLocaleString() },
        beginAtZero: true
      }
    }
  }
});
</script>
</body>
</html>