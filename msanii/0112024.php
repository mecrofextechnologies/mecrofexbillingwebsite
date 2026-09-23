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

// Self-healing: dismiss flag for the "connect your WhatsApp" dashboard notice
// (see includes/fundraiser_dashboard.php) — separate from wa_id_instance etc.
// since this is just a UI preference, not a WhatsApp credential.
try {
    $col = $pdo->query("SHOW COLUMNS FROM creators LIKE 'hide_whatsapp_connect_notice'")->fetchAll();
    if (empty($col)) {
        $pdo->exec("ALTER TABLE creators ADD COLUMN hide_whatsapp_connect_notice TINYINT(1) NOT NULL DEFAULT 0");
    }
} catch (Throwable $e) {}

// ── Creator info ─────────────────────────────────────────────────────────────
$stmt = $pdo->prepare("SELECT c.username, c.withdrawal_pin, c.account_type, c.hide_whatsapp_connect_notice, mp.full_name, mp.id AS page_id
                       FROM creators c
                       LEFT JOIN my_page mp ON c.id = mp.creator_id
                       WHERE c.id = ?");
$stmt->execute([$creator_id]);
$creator = $stmt->fetch(PDO::FETCH_ASSOC);
if ($creator) { $creator = kc_decrypt_row($creator, ['full_name']); }

$display_name = $creator['full_name'] ?? $creator['username'] ?? 'Creator';
$initials     = strtoupper(substr($display_name, 0, 1));
$has_page     = !empty($creator['page_id']);
$has_pin      = !empty($creator['withdrawal_pin']);

// account_type is a recent column — existing rows may still read NULL, so
// treat anything other than 'fundraiser' as the 'creator' default.
$account_type = ($creator['account_type'] ?? 'creator') === 'fundraiser' ? 'fundraiser' : 'creator';
$is_fundraiser = $account_type === 'fundraiser';

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

// ── Ensure fundraiser tables exist ────────────────────────────────────────────
$pdo->exec("CREATE TABLE IF NOT EXISTS fundraiser_campaigns (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    creator_id    INT UNSIGNED NOT NULL,
    title         VARCHAR(150) NOT NULL,
    description   TEXT NULL,
    goal_amount   DECIMAL(12,2) NOT NULL DEFAULT 0,
    status        ENUM('active','closed') NOT NULL DEFAULT 'active',
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_creator (creator_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$pdo->exec("CREATE TABLE IF NOT EXISTS donations (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    creator_id     INT UNSIGNED NOT NULL,
    campaign_id    INT UNSIGNED NULL,
    donor_name     VARCHAR(120) NOT NULL,
    donor_phone    VARCHAR(20) NULL,
    donor_email    VARCHAR(150) NULL,
    amount         DECIMAL(12,2) NOT NULL DEFAULT 0,
    message        VARCHAR(500) NULL,
    mpesa_receipt  VARCHAR(50) NULL,
    status         ENUM('pending','completed','failed') NOT NULL DEFAULT 'pending',
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_creator (creator_id),
    INDEX idx_campaign (campaign_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// ── Fundraiser dashboard data ─────────────────────────────────────────────────
$donationsReceived = 0; $amountRaised = 0.0; $totalDonors = 0; $totalCampaigns = 0;
$recentDonations   = []; $donationStatusBreakdown = ['completed' => 0, 'pending' => 0, 'failed' => 0];
$campaignWallets   = [];

if ($is_fundraiser) {
    $stmt = $pdo->prepare("SELECT
            COUNT(*) AS cnt,
            COALESCE(SUM(CASE WHEN status = 'completed' THEN amount ELSE 0 END), 0) AS raised
        FROM donations WHERE creator_id = ?");
    $stmt->execute([$creator_id]);
    $dRow = $stmt->fetch(PDO::FETCH_ASSOC);
    $donationsReceived = (int)$dRow['cnt'];
    $amountRaised      = (float)$dRow['raised'];

    // donor_phone/donor_email/donor_name are encrypted at rest, so a SQL
    // COUNT(DISTINCT COALESCE(...)) can't dedupe repeat donors anymore —
    // decrypt and dedupe in PHP instead, same phone > email > name priority.
    $donorStmt = $pdo->prepare("SELECT donor_phone, donor_email, donor_name FROM donations WHERE creator_id = ?");
    $donorStmt->execute([$creator_id]);
    $donorRows = array_map(fn($d) => kc_decrypt_row($d, ['donor_name', 'donor_phone', 'donor_email']), $donorStmt->fetchAll(PDO::FETCH_ASSOC));
    $totalDonors = count(array_unique(array_map(
        fn($d) => $d['donor_phone'] ?: ($d['donor_email'] ?: $d['donor_name']),
        $donorRows
    )));

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM fundraiser_campaigns WHERE creator_id = ?");
    $stmt->execute([$creator_id]);
    $totalCampaigns = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT status, COUNT(*) AS cnt FROM donations WHERE creator_id = ? GROUP BY status");
    $stmt->execute([$creator_id]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (isset($donationStatusBreakdown[$row['status']])) {
            $donationStatusBreakdown[$row['status']] = (int)$row['cnt'];
        }
    }

    $stmt = $pdo->prepare("
        SELECT d.donor_name, d.donor_phone, d.amount, d.message, d.mpesa_receipt, d.status, d.created_at, fc.title AS campaign_title
        FROM donations d
        LEFT JOIN fundraiser_campaigns fc ON d.campaign_id = fc.id
        WHERE d.creator_id = ?
        ORDER BY d.created_at DESC LIMIT 20");
    $stmt->execute([$creator_id]);
    $recentDonations = array_map(fn($d) => kc_decrypt_row($d, ['donor_name', 'donor_phone']), $stmt->fetchAll(PDO::FETCH_ASSOC));

    // Per-fundraiser wallet — each campaign's donations are credited into
    // their own wallet_donations row (see callback.php) instead of being
    // pooled into my_balance, so a specific fundraiser's funds can be
    // withdrawn separately from the rest of the creator's balance.
    // Starts FROM fundraiser_campaigns (not wallet_donations) so every
    // fundraiser shows here — including brand-new ones with KES 0 raised —
    // since WhatsApp needs to be connectable before the first donation lands.
    try {
        $col = $pdo->query("SHOW COLUMNS FROM fundraiser_campaigns LIKE 'whatsapp_group_url'")->fetchAll();
        if (empty($col)) {
            $pdo->exec("ALTER TABLE fundraiser_campaigns
                ADD COLUMN whatsapp_group_url VARCHAR(255) NULL,
                ADD COLUMN whatsapp_number VARCHAR(20) NULL,
                ADD COLUMN whatsapp_group_chat_id VARCHAR(60) NULL,
                ADD COLUMN whatsapp_group_name VARCHAR(150) NULL");
        }
        $stmt = $pdo->prepare("
            SELECT fc.id AS campaign_id, fc.title, fc.status AS campaign_status,
                   COALESCE(wd.amount, 0) AS amount, COALESCE(wd.donor_count, 0) AS donor_count,
                   (fc.whatsapp_number IS NOT NULL AND fc.whatsapp_group_chat_id IS NOT NULL) AS whatsapp_live
            FROM fundraiser_campaigns fc
            LEFT JOIN wallet_donations wd ON wd.campaign_id = fc.id
            WHERE fc.creator_id = ?
            ORDER BY amount DESC, fc.created_at DESC");
        $stmt->execute([$creator_id]);
        $campaignWallets = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { $campaignWallets = []; }
}

// ── Stats ─────────────────────────────────────────────────────────────────────
$stmt = $pdo->prepare("SELECT COALESCE(amount, 0) FROM my_balance WHERE creator_id = ?");
$stmt->execute([$creator_id]);
$account_balance = number_format((float)$stmt->fetchColumn(), 2);

$stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM withdrawal_requests WHERE creator_id = ? AND status IN ('completed','successful')");
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
        INNER JOIN tickets t ON tp.ticket_id = t.id
        WHERE t.creator_id = ? AND tp.status = 'completed'
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
           tp.mpesa_receipt, tp.created_at, t.title AS event_title
    FROM ticket_purchases tp
    INNER JOIN tickets t ON tp.ticket_id = t.id
    WHERE t.creator_id = ?
    ORDER BY tp.created_at DESC LIMIT 20");
$stmt->execute([$creator_id]);
$recent_tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);
$recent_tickets = array_map(fn($row) => kc_decrypt_row($row, ['buyer_name','buyer_email','buyer_phone']), $recent_tickets);

$stmt = $pdo->prepare("SELECT supporter_name, amount, mpesa_receipt, phone, status, created_at
    FROM support WHERE creator_id = ? ORDER BY created_at DESC LIMIT 6");
$stmt->execute([$creator_id]);
$recent_support = array_map(fn($s) => kc_decrypt_row($s, ['supporter_name', 'phone']), $stmt->fetchAll(PDO::FETCH_ASSOC));

$stmt = $pdo->prepare("SELECT method, destination, mpesa_number, amount, charge, total_amount,
    transaction_code, status, requested_at FROM withdrawal_requests
    WHERE creator_id = ? ORDER BY requested_at DESC LIMIT 6");
$stmt->execute([$creator_id]);
$recent_withdrawals = array_map(fn($w) => kc_decrypt_row($w, ['mpesa_number']), $stmt->fetchAll(PDO::FETCH_ASSOC));

$stmt = $pdo->prepare("
    SELECT tpv.ip_address, tpv.country, tpv.city, tpv.device, tpv.browser,
           tpv.os, tpv.visited_at, tp.ticket_number, t.title AS event_title
    FROM ticket_page_visits tpv
    INNER JOIN ticket_purchases tp ON tpv.ticket_id = tp.id
    INNER JOIN tickets t ON tp.ticket_id = t.id
    WHERE t.creator_id = ?
    ORDER BY tpv.visited_at DESC LIMIT 20");
$stmt->execute([$creator_id]);
$page_visits = $stmt->fetchAll(PDO::FETCH_ASSOC);

$notif_visits  = count($page_visits);
$notif_tickets = count($recent_tickets);
$notif_support = count($recent_support);
$total_notifs  = $notif_visits + $notif_tickets + $notif_support;

// ── Ongoing tickets/events without a location set (notice bar) ───────────────
$eventsNoLocation = [];
try {
    $locCol = $pdo->query("SHOW COLUMNS FROM tickets LIKE 'location_url'")->fetchAll();
    if (empty($locCol)) {
        $pdo->exec("ALTER TABLE tickets ADD COLUMN location_url VARCHAR(1000) DEFAULT NULL
                    COMMENT 'Google Maps share URL for the venue'");
    }
    $stmtLoc = $pdo->prepare("
        SELECT id, title FROM tickets
        WHERE creator_id = ? AND start_date >= CURDATE()
          AND (location_url IS NULL OR location_url = '')
        ORDER BY id DESC
    ");
    $stmtLoc->execute([$creator_id]);
    $eventsNoLocation = $stmtLoc->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { $eventsNoLocation = []; }

// ── Active/upcoming events (share-to-boost-sales notice) ─────────────────────
$activeEventsForShare = [];
try {
    $stmtShare = $pdo->prepare("
        SELECT id, title, venue, start_date, banner
        FROM tickets
        WHERE creator_id = ? AND start_date >= CURDATE()
          AND (status = 'active' OR status IS NULL) AND is_hidden = 0
        ORDER BY start_date ASC
    ");
    $stmtShare->execute([$creator_id]);
    $activeEventsForShare = $stmtShare->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { $activeEventsForShare = []; }

// ── All-time ticket sales (overview card) ─────────────────────────────────────
$COMMISSION_RATE = 0.035;
$allTimeGross        = 0;
$allTimeSold         = 0;
try {
    $stmtAt = $pdo->prepare("
        SELECT COALESCE(SUM(tp.quantity),0)   AS total_sold,
               COALESCE(SUM(tp.amount_paid),0) AS total_gross
        FROM ticket_purchases tp
        INNER JOIN tickets t ON tp.ticket_id = t.id
        WHERE t.creator_id = ? AND tp.status = 'completed'
    ");
    $stmtAt->execute([$creator_id]);
    $rowAt         = $stmtAt->fetch(PDO::FETCH_ASSOC);
    $allTimeSold   = (int)$rowAt['total_sold'];
    $allTimeGross  = (float)$rowAt['total_gross'];
} catch (PDOException $e) { $allTimeGross = 0; $allTimeSold = 0; }
$allTimeNet = $allTimeGross * (1 - $COMMISSION_RATE);

// ── Ticket sales per event (with commission) ──────────────────────────────────
$activeTicketSales = [];
$salesTotalGross   = 0;
try {
    $stmt = $pdo->prepare("
        SELECT t.id, t.title,
               COALESCE(SUM(CASE WHEN tp.status = 'completed' THEN tp.quantity   ELSE 0 END), 0) AS tickets_sold,
               COALESCE(SUM(CASE WHEN tp.status = 'completed' THEN tp.amount_paid ELSE 0 END), 0) AS gross_revenue
        FROM tickets t
        LEFT JOIN ticket_purchases tp ON tp.ticket_id = t.id
        WHERE t.creator_id = ?
        GROUP BY t.id, t.title
        HAVING tickets_sold > 0
        ORDER BY gross_revenue DESC
    ");
    $stmt->execute([$creator_id]);
    $activeTicketSales = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($activeTicketSales as $row) $salesTotalGross += $row['gross_revenue'];
} catch (PDOException $e) { $activeTicketSales = []; }
$salesTotalCommission = $salesTotalGross * $COMMISSION_RATE;
$salesTotalNet        = $salesTotalGross - $salesTotalCommission;
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
.alert-loc {
  background: linear-gradient(120deg,#f0fdf8,#e8faf3);
  border: 1px solid rgba(0,179,126,0.2);
}
.alert-loc::before { background: linear-gradient(180deg,var(--success),#00d68f); }
.alert-loc .alert-icon { background: linear-gradient(135deg,var(--success),#00d68f); color: #fff; }
.alert-loc .alert-title { color: #00714f; }
.alert-loc .alert-btn { background: linear-gradient(135deg,var(--success),#00c97a); color: white; box-shadow: 0 4px 14px rgba(0,179,126,0.28); }
.alert-loc .alert-dismiss { color: rgba(0,179,126,0.4); }
.alert-share {
  background: linear-gradient(120deg,#eef4ff,#e6edfc);
  border: 1px solid rgba(5,46,100,0.18);
}
.alert-share::before { background: linear-gradient(180deg,var(--navy),var(--gold)); }
.alert-share .alert-icon { background: linear-gradient(135deg,var(--navy),var(--navy-l)); color: var(--gold); }
.alert-share .alert-title { color: var(--navy); }
.alert-share .alert-btn { background: linear-gradient(135deg,var(--navy),var(--navy-l)); color: white; box-shadow: 0 4px 14px rgba(5,46,100,0.28); }
.alert-share .alert-dismiss { color: rgba(5,46,100,0.4); }
.alert-share .alert-dismiss:hover { color: var(--navy); background: rgba(5,46,100,0.08); }

/* ── Share Modal ── */
@keyframes shareCardIn { from { opacity: 0; transform: translateY(14px); } to { opacity: 1; transform: translateY(0); } }
.share-overlay { position: fixed; inset: 0; z-index: 99998; background: rgba(5,20,50,.72); backdrop-filter: blur(8px); display: none; align-items: center; justify-content: center; padding: 20px; }
.share-overlay.active { display: flex; }
.share-card { position: relative; background: #fff; border-radius: var(--r-lg); padding: 2.1rem 1.8rem 1.8rem; width: min(420px,92vw); text-align: center; box-shadow: var(--sh-lg); animation: shareCardIn .35s cubic-bezier(.16,1,.3,1) both; }
.share-close { position: absolute; top: 14px; right: 14px; width: 30px; height: 30px; border-radius: 50%; border: none; background: #f4f6fb; color: var(--muted); cursor: pointer; font-size: .85rem; display: flex; align-items: center; justify-content: center; }
.share-close:hover { background: #e2e8f0; }
.share-icon-wrap { width: 58px; height: 58px; border-radius: 50%; background: linear-gradient(135deg,var(--navy),var(--navy-l)); display: flex; align-items: center; justify-content: center; margin: 0 auto 1rem; box-shadow: 0 8px 24px rgba(5,46,100,.35); }
.share-icon-wrap i { font-size: 1.35rem; color: var(--gold); }
.share-title-txt { font-family: 'Montserrat', sans-serif; font-weight: 800; font-size: 1.05rem; color: var(--text); letter-spacing: -.02em; margin-bottom: 1.1rem; }
.share-preview { display: flex; align-items: center; gap: 12px; background: #fafbfe; border: 1px solid var(--border); border-radius: var(--r); padding: .7rem; margin-bottom: 1.2rem; text-align: left; }
.share-preview-img { width: 50px; height: 50px; border-radius: 9px; object-fit: cover; flex-shrink: 0; background: rgba(5,46,100,.08); }
.share-preview-ico { display: flex; align-items: center; justify-content: center; background: linear-gradient(135deg,var(--navy),var(--navy-l)); color: var(--gold); font-size: 1.05rem; }
.share-preview-txt { min-width: 0; }
.share-preview-title { font-family: 'Montserrat', sans-serif; font-weight: 700; font-size: .82rem; color: var(--text); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.share-preview-desc { font-size: .7rem; color: var(--muted); margin-top: 2px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.share-btns { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 8px; margin-bottom: 1rem; }
.share-btn { display: flex; flex-direction: column; align-items: center; gap: 6px; padding: 12px 6px; border-radius: 12px; border: none; font-family: 'Montserrat', sans-serif; font-weight: 700; font-size: .68rem; cursor: pointer; text-decoration: none; transition: transform .15s; }
.share-btn:hover { transform: translateY(-2px); }
.share-btn i { font-size: 1.1rem; }
.share-btn-wa { background: rgba(37,211,102,.1); color: #1da851; }
.share-btn-fb { background: rgba(24,119,242,.1); color: #1877f2; }
.share-btn-copy { background: rgba(5,46,100,.08); color: var(--navy); }
.share-link-box { font-size: .7rem; color: var(--muted); background: #fafbfe; border: 1px solid var(--border); border-radius: 8px; padding: 8px 10px; word-break: break-all; display: none; }
.share-link-box.visible { display: block; }
.alert-loc .alert-dismiss:hover { color: var(--success); background: rgba(0,179,126,0.08); }
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
@media (min-width:900px) { .stats-grid { grid-template-columns:repeat(4,1fr); } }

/* ════════════════════════════════════════════
   TICKET SALES SUMMARY STRIP
════════════════════════════════════════════ */
.ts-summary { display:grid;grid-template-columns:repeat(3,1fr);gap:.85rem;margin-bottom:1.1rem; }
.ts-summary > div { min-width:0; }
.ts-summary .ts-val { min-width:0; }
.ts-summary .fin-wrap { min-width:0; flex-wrap:wrap; justify-content:center; }
.ts-summary .fin-val { word-break:break-word; }
@media (max-width:480px) {
  .ts-summary { grid-template-columns:1fr; }
}
@media (min-width:481px) and (max-width:640px) {
  .ts-summary .ts-val { font-size:1rem; }
}
@media (max-width:340px) {
  .ts-event-grid { grid-template-columns:1fr!important; }
}

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
.sc.st::after  { background:var(--red); }
.sc.sn::before { background:var(--navy); }
.sc.ss::before { background:var(--success); }
.sc.sv::before { background:linear-gradient(90deg,var(--sms),var(--sms-l)); }
.sc.st::before { background:linear-gradient(90deg,var(--red),var(--gold)); }
.sc-top    { display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:0.9rem; }
.sc-ico    { width:40px;height:40px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:1rem; }
.sc.sn .sc-ico { background:rgba(5,46,100,0.1);color:var(--navy); }
.sc.ss .sc-ico { background:rgba(0,179,126,0.1);color:var(--success); }
.sc.sv .sc-ico { background:rgba(124,58,237,0.1);color:var(--sms); }
.sc.st .sc-ico { background:rgba(191,19,57,0.1);color:var(--red); }
.sc-badge  { font-family:'Montserrat',sans-serif;font-size:0.6rem;font-weight:700;padding:2px 8px;border-radius:100px; }
.na        { background:rgba(107,122,153,0.1);color:var(--muted); }
.live      { background:rgba(254,196,17,0.15);color:var(--gold-d); }
.bonus-badge { background:rgba(124,58,237,0.12);color:var(--sms); }
.sc-label  { font-size:0.73rem;color:var(--muted);margin-bottom:0.25rem; }
.sc-val    { font-family:'Montserrat',sans-serif;font-weight:800;font-size:clamp(1.05rem,2vw,1.4rem);letter-spacing:-0.03em;color:var(--text);line-height:1; }
.sc-sub    { font-size:0.67rem;color:var(--muted);margin-top:5px; }

/* ── Show/hide toggle for financial figures ── */
.fin-wrap { display:inline-flex; align-items:center; gap:7px; }
.fin-val  { transition:filter .15s ease; }
.fin-wrap.fin-hidden .fin-val { filter:blur(7px); user-select:none; }
.fin-eye {
    background:none; border:none; cursor:pointer; padding:2px;
    color:var(--muted); opacity:.55; font-size:.82em; flex-shrink:0;
    transition:opacity .15s;
}
.fin-eye:hover { opacity:1; }

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
/* Recent buyers — responsive layout */
.buyers-mobile  { display:block; }
.buyers-desktop { display:none; }
@media (min-width:700px) {
  .buyers-mobile  { display:none; }
  .buyers-desktop { display:block; }
}

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

/* ════════════════════════════════════════════
   ACCOUNT TYPE SWITCH
════════════════════════════════════════════ */
.mode-switch-row { display:flex;justify-content:flex-end;margin-bottom:1.1rem; }
.mode-switch {
  display:inline-flex;align-items:center;gap:2px;
  background:var(--card);border:1px solid var(--border);border-radius:100px;
  padding:4px;box-shadow:var(--sh-sm);position:relative;
}
.mode-switch-opt {
  display:flex;align-items:center;gap:6px;
  padding:8px 16px;border-radius:100px;border:none;background:transparent;
  font-family:'Montserrat',sans-serif;font-weight:700;font-size:0.72rem;
  color:var(--muted);cursor:pointer;transition:all 0.25s cubic-bezier(0.4,0,0.2,1);
  white-space:nowrap;
}
.mode-switch-opt i { font-size:0.75rem; }
.mode-switch-opt.active.mso-creator    { background:linear-gradient(135deg,var(--navy),var(--navy-l));color:#fff;box-shadow:0 3px 12px rgba(5,46,100,0.3); }
.mode-switch-opt.active.mso-fundraiser { background:linear-gradient(135deg,#7c2fb0,#a855f7);color:#fff;box-shadow:0 3px 12px rgba(124,47,176,0.3); }
.mode-switch-opt:disabled { cursor:wait;opacity:0.7; }
@media(max-width:480px) { .mode-switch-opt span.msl { display:none; } }

.ms-loader {
  position:fixed;inset:0;z-index:9998;
  background:rgba(13,13,13,.55);backdrop-filter:blur(3px);
  display:none;align-items:center;justify-content:center;
  flex-direction:column;gap:1.1rem;
  opacity:0;transition:opacity .25s ease;
}
.ms-loader.show { display:flex; }
.ms-loader.visible { opacity:1; }
.ms-loader-spinner {
  width:52px;height:52px;border-radius:50%;
  border:4px solid rgba(255,255,255,.2);
  border-top-color:var(--gold);
  animation:ms-spin .8s linear infinite;
}
@keyframes ms-spin { to { transform:rotate(360deg); } }
.ms-loader-text {
  font-family:'Montserrat',sans-serif;font-weight:600;font-size:.95rem;
  color:#fff;text-align:center;padding:0 1.5rem;
}
.ms-loader-text strong { color:var(--gold); font-weight:800; }

/* ════════════════════════════════════════════
   FUNDRAISER DASHBOARD
════════════════════════════════════════════ */
.fr-stats-grid { display:grid;grid-template-columns:repeat(2,1fr);gap:1rem;margin-bottom:1.75rem; }
@media (min-width:900px) { .fr-stats-grid { grid-template-columns:repeat(4,1fr); } }
.fr-card {
  background:var(--card);border-radius:var(--r);padding:1.3rem 1.35rem 1.15rem;
  border:1px solid var(--border);box-shadow:var(--sh-sm);
  position:relative;overflow:hidden;transition:transform 0.22s,box-shadow 0.22s;
  animation:pop 0.5s cubic-bezier(0.16,1,0.3,1) both;
}
.fr-card:hover { transform:translateY(-3px);box-shadow:var(--sh); }
.fr-card::before { content:'';position:absolute;top:0;left:0;right:0;height:3px;border-radius:var(--r) var(--r) 0 0;background:linear-gradient(90deg,#7c2fb0,#a855f7); }
.fr-card::after { content:'';position:absolute;bottom:-24px;right:-24px;width:80px;height:80px;border-radius:50%;pointer-events:none;opacity:0.12;background:#7c2fb0; }
.fr-top  { display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:0.9rem; }
.fr-ico  { width:40px;height:40px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:1rem;background:rgba(124,47,176,0.1);color:#7c2fb0; }
.fr-label{ font-size:0.73rem;color:var(--muted);margin-bottom:0.25rem; }
.fr-val  { font-family:'Montserrat',sans-serif;font-weight:800;font-size:clamp(1.05rem,2vw,1.4rem);letter-spacing:-0.03em;color:var(--text);line-height:1; }
.fr-sub  { font-size:0.67rem;color:var(--muted);margin-top:5px; }

.fr-pie-legend { display:flex;flex-direction:column;gap:10px;margin-top:0.5rem; }
.fr-pie-legend-item { display:flex;align-items:center;gap:8px;font-size:0.78rem;color:var(--text); }
.fr-pie-dot { width:10px;height:10px;border-radius:50%;flex-shrink:0; }
.fr-pie-legend-item strong { margin-left:auto;font-family:'Montserrat',sans-serif; }
</style>
</head>
<body>

<?php if (empty($kc_in_router)): ?>
<!-- ══ PRELOADER ══ -->
<div id="preloader">
  <div class="pre-wordmark">Key<em>chele</em></div>
  <div class="pre-track"><div class="pre-fill"></div></div>
</div>
<?php endif; ?>

<!-- ══ ACCOUNT TYPE SWITCH LOADER ══ -->
<div id="modeSwitchLoader" class="ms-loader">
  <div class="ms-loader-spinner"></div>
  <div class="ms-loader-text">Please wait, switching you to <strong id="modeSwitchLoaderTarget">Fundraiser</strong>…</div>
</div>

<div class="kc-dash">

  <!-- ══ ACCOUNT TYPE SWITCH ══ -->
  <div class="mode-switch-row">
    <div class="mode-switch" id="modeSwitch">
      <button type="button" class="mode-switch-opt mso-creator <?= !$is_fundraiser ? 'active' : '' ?>" data-type="creator">
        <i class="fa-solid fa-store"></i><span class="msl">Creator</span>
      </button>
      <button type="button" class="mode-switch-opt mso-fundraiser <?= $is_fundraiser ? 'active' : '' ?>" data-type="fundraiser">
        <i class="fa-solid fa-hand-holding-heart"></i><span class="msl">Fundraiser</span>
      </button>
    </div>
  </div>

  <!-- ══ NO-PAGE BANNER ══ -->
  <?php if (!$is_fundraiser && !$has_page): ?>
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

  <!-- ══ NO-LOCATION NOTICE (single) ══ -->
  <?php if (!empty($eventsNoLocation)):
    $locFirst = $eventsNoLocation[0];
    $locCount = count($eventsNoLocation);
    $locExtra = $locCount - 1;
  ?>
  <div class="dash-alert alert-loc" id="locNoticeSingle" style="animation-delay:.12s">
    <div class="alert-icon"><i class="fa-solid fa-map-location-dot"></i></div>
    <div class="alert-body">
      <div class="alert-title">
        "<?= htmlspecialchars($locFirst['title']) ?>" has no location set
        <?php if ($locExtra > 0): ?>
          <span style="font-family:'Montserrat',sans-serif;font-size:.6rem;font-weight:700;background:rgba(0,179,126,.15);color:var(--success);border:1px solid rgba(0,179,126,.25);border-radius:100px;padding:2px 8px;margin-left:6px;vertical-align:middle;">+<?= $locExtra ?> other<?= $locExtra > 1 ? 's' : '' ?></span>
        <?php endif; ?>
      </div>
      <div class="alert-text">Your attendees can't find the venue — add a Google Maps link so no one gets lost.</div>
    </div>
    <div class="alert-actions">
      <?php if ($locExtra > 0): ?>
        <button class="alert-btn" onclick="openLocModal()" style="background:rgba(0,100,60,.12);color:#00714f;border:1.5px solid rgba(0,179,126,.3);box-shadow:none;">
          <i class="fa-solid fa-list"></i> View All (<?= $locCount ?>)
        </button>
      <?php endif; ?>
      <a href="https://keychele.co.ke/msanii/index.php?page=edit_ticket&id=<?= (int)$locFirst['id'] ?>&tab=location" class="alert-btn">
        <i class="fa-solid fa-map-pin"></i> Set Location
      </a>
      <button class="alert-dismiss" onclick="dismissLocAll()" title="Dismiss">
        <i class="fa-solid fa-xmark"></i>
      </button>
    </div>
  </div>

  <!-- ══ NO-LOCATION MODAL ══ -->
  <div id="locModal" style="display:none;position:fixed;inset:0;z-index:99990;background:rgba(5,20,50,.72);backdrop-filter:blur(6px);align-items:center;justify-content:center;padding:16px;" onclick="if(event.target===this)closeLocModal()">
    <div style="background:#fff;border-radius:20px;width:min(480px,100%);max-height:85vh;display:flex;flex-direction:column;box-shadow:0 24px 64px rgba(0,0,0,.35);animation:panelIn .32s cubic-bezier(.16,1,.3,1) both;" onclick="event.stopPropagation()">
      <!-- Modal header -->
      <div style="padding:1.25rem 1.5rem;border-bottom:1px solid rgba(5,46,100,.1);display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-shrink:0;">
        <div style="display:flex;align-items:center;gap:10px;">
          <div style="width:36px;height:36px;border-radius:10px;background:linear-gradient(135deg,var(--success),#00d68f);display:flex;align-items:center;justify-content:center;color:#fff;font-size:.85rem;flex-shrink:0;">
            <i class="fa-solid fa-map-location-dot"></i>
          </div>
          <div>
            <div style="font-family:'Montserrat',sans-serif;font-weight:800;font-size:.9rem;color:var(--text);">Events Without Location</div>
            <div style="font-size:.7rem;color:var(--muted);margin-top:1px;"><?= $locCount ?> event<?= $locCount > 1 ? 's' : '' ?> need<?= $locCount === 1 ? 's' : '' ?> a venue map</div>
          </div>
        </div>
        <button onclick="closeLocModal()" style="background:rgba(5,46,100,.06);border:none;cursor:pointer;width:30px;height:30px;border-radius:8px;display:flex;align-items:center;justify-content:center;color:var(--muted);font-size:.8rem;flex-shrink:0;">
          <i class="fa-solid fa-xmark"></i>
        </button>
      </div>
      <!-- Modal list -->
      <div style="overflow-y:auto;padding:.75rem .9rem;flex:1;">
        <?php foreach ($eventsNoLocation as $i => $ev): ?>
        <div style="display:flex;align-items:center;gap:.85rem;padding:.85rem .65rem;border-bottom:1px solid rgba(5,46,100,.06)<?= $i === $locCount-1 ? ';border-bottom:none' : '' ?>;">
          <div style="flex-shrink:0;width:36px;height:36px;border-radius:10px;background:linear-gradient(135deg,rgba(5,46,100,.08),rgba(5,46,100,.04));border:1px solid rgba(5,46,100,.1);display:flex;align-items:center;justify-content:center;font-size:.75rem;color:var(--muted);">
            <?= $i + 1 ?>
          </div>
          <div style="flex:1;min-width:0;">
            <div style="font-family:'Montserrat',sans-serif;font-weight:700;font-size:.82rem;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
              <?= htmlspecialchars($ev['title']) ?>
            </div>
          </div>
          <a href="https://keychele.co.ke/msanii/index.php?page=edit_ticket&id=<?= (int)$ev['id'] ?>&tab=location"
             style="flex-shrink:0;display:inline-flex;align-items:center;gap:5px;padding:6px 13px;border-radius:8px;background:linear-gradient(135deg,var(--success),#00c97a);color:#fff;font-family:'Montserrat',sans-serif;font-weight:700;font-size:.7rem;text-decoration:none;white-space:nowrap;">
            <i class="fa-solid fa-map-pin" style="font-size:.6rem;"></i> Set
          </a>
        </div>
        <?php endforeach; ?>
      </div>
      <!-- Modal footer -->
      <div style="padding:.85rem 1.5rem;border-top:1px solid rgba(5,46,100,.08);background:#fafbfd;border-radius:0 0 20px 20px;flex-shrink:0;display:flex;justify-content:flex-end;">
        <button onclick="closeLocModal()" style="padding:8px 20px;border-radius:9px;border:1.5px solid rgba(5,46,100,.15);background:#fff;font-family:'Montserrat',sans-serif;font-weight:700;font-size:.78rem;color:var(--muted);cursor:pointer;">
          Close
        </button>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <!-- ══ SHARE-TO-BOOST-SALES NOTICE ══ -->
  <?php if (!empty($activeEventsForShare)):
    $shareFirst = $activeEventsForShare[0];
    $shareCount = count($activeEventsForShare);
    $shareExtra = $shareCount - 1;
    $shareUrl   = 'https://keychele.co.ke/ticket/' . (int)$shareFirst['id'];
    $shareDesc  = '🎟️ Grab your ticket to ' . ($shareFirst['title'] ?: 'this event') . '! '
                . ($shareFirst['start_date'] ? date('d M Y', strtotime($shareFirst['start_date'])) : '')
                . ($shareFirst['venue'] ? ' at ' . $shareFirst['venue'] : '') . '. Purchase yours now!';
    $shareBanner = !empty($shareFirst['banner']) ? 'uploads/banners/' . $shareFirst['banner'] : '';
  ?>
  <div class="dash-alert alert-share" id="shareNoticeSingle" style="animation-delay:.16s">
    <div class="alert-icon"><i class="fa-solid fa-share-nodes"></i></div>
    <div class="alert-body">
      <div class="alert-title">
        "<?= htmlspecialchars($shareFirst['title']) ?>" is live
        <?php if ($shareExtra > 0): ?>
          <span style="font-family:'Montserrat',sans-serif;font-size:.6rem;font-weight:700;background:rgba(5,46,100,.1);color:var(--navy);border:1px solid rgba(5,46,100,.2);border-radius:100px;padding:2px 8px;margin-left:6px;vertical-align:middle;">+<?= $shareExtra ?> other<?= $shareExtra > 1 ? 's' : '' ?></span>
        <?php endif; ?>
      </div>
      <div class="alert-text">Share your ticket link on WhatsApp and Facebook to reach more buyers and boost sales.</div>
    </div>
    <div class="alert-actions">
      <button class="alert-btn"
              data-title="<?= htmlspecialchars($shareFirst['title'] ?? 'Event', ENT_QUOTES) ?>"
              data-desc="<?= htmlspecialchars($shareDesc, ENT_QUOTES) ?>"
              data-banner="<?= htmlspecialchars($shareBanner, ENT_QUOTES) ?>"
              data-url="<?= htmlspecialchars($shareUrl, ENT_QUOTES) ?>"
              onclick="openShareModal(this)">
        <i class="fa-solid fa-share-nodes"></i> Share
      </button>
      <button class="alert-dismiss" onclick="dismissShareAll()" title="Dismiss">
        <i class="fa-solid fa-xmark"></i>
      </button>
    </div>
  </div>

  <!-- ══ SHARE MODAL ══ -->
  <div class="share-overlay" id="shareOverlay" role="dialog" aria-modal="true" aria-label="Share this ticket">
    <div class="share-card">
      <button type="button" class="share-close" onclick="closeShareModal()" aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
      <div class="share-icon-wrap"><i class="fa-solid fa-share-nodes"></i></div>
      <div class="share-title-txt">Share This Event</div>

      <div class="share-preview">
        <img class="share-preview-img" id="sharePrevImg" src="" alt="" style="display:none;">
        <div class="share-preview-img share-preview-ico" id="sharePrevIco"><i class="fa-solid fa-calendar-star"></i></div>
        <div class="share-preview-txt">
          <div class="share-preview-title" id="sharePrevTitle">—</div>
          <div class="share-preview-desc" id="sharePrevDesc"></div>
        </div>
      </div>

      <div class="share-btns">
        <a href="#" id="shareWaBtn" target="_blank" rel="noopener" class="share-btn share-btn-wa"><i class="fa-brands fa-whatsapp"></i> WhatsApp</a>
        <a href="#" id="shareFbBtn" target="_blank" rel="noopener" class="share-btn share-btn-fb"><i class="fa-brands fa-facebook"></i> Facebook</a>
        <button type="button" id="shareCopyBtn" class="share-btn share-btn-copy" onclick="copyShareLink()"><i class="fa-solid fa-link"></i> Copy Link</button>
      </div>

      <div class="share-link-box" id="shareLinkBox"></div>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($is_fundraiser): ?>
  <?php include 'includes/fundraiser_dashboard.php'; ?>
  <?php else: ?>

  <!-- ══ STAT CARDS ══ -->
  <div class="sec-hd"><div class="sec-title">Overview</div></div>
  <div class="stats-grid">

    <div class="sc sn">
      <div class="sc-top">
        <div class="sc-ico"><i class="fa-solid fa-money-bill-transfer"></i></div>
        <span class="sc-badge na">Lifetime</span>
      </div>
      <div class="sc-label">Total Withdrawn</div>
      <div class="sc-val"><span class="fin-wrap"><span class="fin-val">KSh <?= $withdrawals ?></span><button type="button" class="fin-eye" onclick="kcToggleFin(this)" title="Show/hide"><i class="fa-solid fa-eye"></i></button></span></div>
      <div class="sc-sub">Completed payouts only</div>
    </div>

    <div class="sc ss">
      <div class="sc-top">
        <div class="sc-ico"><i class="fa-solid fa-vault"></i></div>
        <span class="sc-badge live">Live</span>
      </div>
      <div class="sc-label">Account Balance</div>
      <div class="sc-val"><span class="fin-wrap"><span class="fin-val">KSh <?= $account_balance ?></span><button type="button" class="fin-eye" onclick="kcToggleFin(this)" title="Show/hide"><i class="fa-solid fa-eye"></i></button></span></div>
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

    <div class="sc st">
      <div class="sc-top">
        <div class="sc-ico"><i class="fa-solid fa-ticket"></i></div>
        <span class="sc-badge na">All-Time</span>
      </div>
      <div class="sc-label">Ticket Sales Earnings</div>
      <div class="sc-val"><span class="fin-wrap"><span class="fin-val">KSh <?= number_format($allTimeNet, 2) ?></span><button type="button" class="fin-eye" onclick="kcToggleFin(this)" title="Show/hide"><i class="fa-solid fa-eye"></i></button></span></div>
      <div class="sc-sub"><?= number_format($allTimeSold) ?> ticket<?= $allTimeSold !== 1 ? 's' : '' ?> sold &nbsp;·&nbsp; after 3.5% commission</div>
    </div>

  </div>

  <!-- ══ TICKET SALES ══ -->
  <div class="sec-hd" style="margin-top:1.75rem">
    <div class="sec-title">
      <i class="fa-solid fa-ticket" style="font-size:.75rem;color:var(--red)"></i>
      Ticket Sales
    </div>
  </div>

  <?php if (empty($activeTicketSales)): ?>
  <div style="background:var(--card);border:1px solid var(--border);border-radius:var(--r);padding:2.5rem 1rem;text-align:center;margin-bottom:1.75rem;">
    <div style="width:52px;height:52px;border-radius:14px;background:rgba(191,19,57,0.06);border:1px solid var(--border);display:flex;align-items:center;justify-content:center;font-size:1.25rem;color:var(--muted);margin:0 auto 0.75rem;">
      <i class="fa-solid fa-ticket-simple"></i>
    </div>
    <p style="font-size:.84rem;color:var(--muted);">No ticket sales yet. Create an event and share it!</p>
  </div>
  <?php else: ?>

  <!-- Summary strip -->
  <div class="ts-summary">
    <div style="background:var(--card);border:1px solid var(--border);border-radius:var(--r);padding:1rem 1.2rem;text-align:center;box-shadow:var(--sh-sm);">
      <div style="font-size:.65rem;color:var(--muted);text-transform:uppercase;letter-spacing:.07em;font-family:'Montserrat',sans-serif;font-weight:700;margin-bottom:.35rem;">Total Revenue</div>
      <div class="ts-val" style="font-family:'Montserrat',sans-serif;font-weight:900;font-size:1.15rem;color:var(--text);display:flex;justify-content:center;"><span class="fin-wrap"><span class="fin-val">KSh <?= number_format($salesTotalGross, 2) ?></span><button type="button" class="fin-eye" onclick="kcToggleFin(this)" title="Show/hide"><i class="fa-solid fa-eye"></i></button></span></div>
    </div>
    <div style="background:#fff5f7;border:1px solid rgba(191,19,57,.18);border-radius:var(--r);padding:1rem 1.2rem;text-align:center;box-shadow:var(--sh-sm);">
      <div style="font-size:.65rem;color:var(--red);text-transform:uppercase;letter-spacing:.07em;font-family:'Montserrat',sans-serif;font-weight:700;margin-bottom:.35rem;">Commission (3.5%)</div>
      <div class="ts-val" style="font-family:'Montserrat',sans-serif;font-weight:900;font-size:1.15rem;color:var(--red);display:flex;justify-content:center;"><span class="fin-wrap"><span class="fin-val">− KSh <?= number_format($salesTotalCommission, 2) ?></span><button type="button" class="fin-eye" onclick="kcToggleFin(this)" title="Show/hide"><i class="fa-solid fa-eye"></i></button></span></div>
    </div>
    <div style="background:linear-gradient(120deg,#052e64,#0a4494);border-radius:var(--r);padding:1rem 1.2rem;text-align:center;box-shadow:var(--sh);">
      <div style="font-size:.65rem;color:rgba(255,255,255,.55);text-transform:uppercase;letter-spacing:.07em;font-family:'Montserrat',sans-serif;font-weight:700;margin-bottom:.35rem;">Your Earnings</div>
      <div class="ts-val" style="font-family:'Montserrat',sans-serif;font-weight:900;font-size:1.15rem;color:#fec411;display:flex;justify-content:center;"><span class="fin-wrap"><span class="fin-val">KSh <?= number_format($salesTotalNet, 2) ?></span><button type="button" class="fin-eye" style="color:rgba(255,255,255,.55);" onclick="kcToggleFin(this)" title="Show/hide"><i class="fa-solid fa-eye"></i></button></span></div>
    </div>
  </div>

  <!-- Per-event cards -->
  <div class="ts-event-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(270px,1fr));gap:1rem;margin-bottom:1.75rem;">
  <?php
    $COMMISSION_RATE = 0.035;
    foreach ($activeTicketSales as $i => $ev):
      $gross      = (float)$ev['gross_revenue'];
      $commission = $gross * $COMMISSION_RATE;
      $net        = $gross - $commission;
      $sold       = (int)$ev['tickets_sold'];
      $total      = 0;
      $pct        = 0;
      $barColor   = $pct >= 100 ? '#00b37e' : ($pct >= 50 ? '#0a4494' : 'var(--red)');
      $delay      = 0.05 + $i * 0.05;
  ?>
  <div style="background:var(--card);border:1px solid var(--border);border-radius:var(--r);padding:1.25rem 1.3rem;box-shadow:var(--sh-sm);position:relative;overflow:hidden;animation:pop .5s cubic-bezier(.16,1,.3,1) <?= $delay ?>s both;">
    <div style="position:absolute;top:0;left:0;right:0;height:3px;background:linear-gradient(90deg,var(--red),var(--gold));border-radius:var(--r) var(--r) 0 0;"></div>

    <!-- Event name -->
    <div style="font-family:'Montserrat',sans-serif;font-weight:800;font-size:.9rem;color:var(--text);margin-bottom:.25rem;line-height:1.3;padding-right:1rem;overflow:hidden;text-overflow:ellipsis;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;">
      <?= htmlspecialchars($ev['title']) ?>
    </div>

    <!-- Tickets sold -->
    <div style="font-size:.7rem;color:var(--muted);margin-bottom:.55rem;">
      <?= $sold ?> ticket<?= $sold !== 1 ? 's' : '' ?> sold<?= $total > 0 ? ' of ' . $total : '' ?>
    </div>

    <!-- Progress bar -->
    <?php if ($total > 0): ?>
    <div style="height:5px;background:rgba(5,46,100,.08);border-radius:10px;margin-bottom:.9rem;overflow:hidden;">
      <div style="height:100%;width:<?= $pct ?>%;background:<?= $barColor ?>;border-radius:10px;transition:width .8s ease;"></div>
    </div>
    <?php endif; ?>

    <!-- Revenue rows -->
    <div style="display:flex;justify-content:space-between;align-items:center;padding:.45rem 0;border-top:1px solid rgba(5,46,100,.06);font-size:.76rem;">
      <span style="color:var(--muted);">Gross Revenue</span>
      <span style="font-family:'Montserrat',sans-serif;font-weight:700;color:var(--text);">KSh <?= number_format($gross, 2) ?></span>
    </div>
    <div style="display:flex;justify-content:space-between;align-items:center;padding:.45rem 0;border-top:1px solid rgba(5,46,100,.06);font-size:.76rem;">
      <span style="color:var(--red);">Commission (3.5%)</span>
      <span style="font-family:'Montserrat',sans-serif;font-weight:700;color:var(--red);">− KSh <?= number_format($commission, 2) ?></span>
    </div>
    <div style="display:flex;justify-content:space-between;align-items:center;padding:.55rem .75rem;margin-top:.5rem;background:linear-gradient(120deg,rgba(5,46,100,.06),rgba(5,46,100,.03));border-radius:8px;border:1px solid rgba(5,46,100,.1);">
      <span style="font-family:'Montserrat',sans-serif;font-weight:800;font-size:.75rem;color:var(--navy);">Your Earnings</span>
      <span style="font-family:'Montserrat',sans-serif;font-weight:900;font-size:.95rem;color:var(--success);">KSh <?= number_format($net, 2) ?></span>
    </div>
  </div>
  <?php endforeach; ?>
  </div>
  <?php endif; ?>

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
      <div class="re-val"><span class="fin-wrap"><span class="fin-val">KSh <?= number_format($ref_today, 2) ?></span><button type="button" class="fin-eye" onclick="kcToggleFin(this)" title="Show/hide"><i class="fa-solid fa-eye"></i></button></span></div>
      <div class="re-sub"><?= date('d M Y') ?></div>
    </div>

    <!-- This Week -->
    <div class="re-card re-week">
      <div class="re-top">
        <div class="re-ico"><i class="fa-solid fa-calendar-week"></i></div>
        <span class="re-period">This Week</span>
      </div>
      <div class="re-label">Earnings This Week</div>
      <div class="re-val"><span class="fin-wrap"><span class="fin-val">KSh <?= number_format($ref_week, 2) ?></span><button type="button" class="fin-eye" onclick="kcToggleFin(this)" title="Show/hide"><i class="fa-solid fa-eye"></i></button></span></div>
      <div class="re-sub"><?= date('d M', strtotime('monday this week')) ?> – <?= date('d M') ?></div>
    </div>

    <!-- This Month -->
    <div class="re-card re-month">
      <div class="re-top">
        <div class="re-ico"><i class="fa-solid fa-calendar-days"></i></div>
        <span class="re-period">This Month</span>
      </div>
      <div class="re-label">Earnings This Month</div>
      <div class="re-val"><span class="fin-wrap"><span class="fin-val">KSh <?= number_format($ref_month, 2) ?></span><button type="button" class="fin-eye" onclick="kcToggleFin(this)" title="Show/hide"><i class="fa-solid fa-eye"></i></button></span></div>
      <div class="re-sub"><?= date('F Y') ?></div>
    </div>

    <!-- Total Lifetime -->
    <div class="re-card re-total">
      <div class="re-top">
        <div class="re-ico"><i class="fa-solid fa-trophy"></i></div>
        <span class="re-period">Lifetime</span>
      </div>
      <div class="re-label">Total Referral Earned</div>
      <div class="re-val"><span class="fin-wrap"><span class="fin-val">KSh <?= number_format($ref_total, 2) ?></span><button type="button" class="fin-eye" onclick="kcToggleFin(this)" title="Show/hide"><i class="fa-solid fa-eye"></i></button></span></div>
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

  <!-- ══ RECENT BUYERS ══ -->
  <div class="sec-hd">
    <div class="sec-title">
      <i class="fa-solid fa-bag-shopping" style="font-size:.75rem;color:var(--red)"></i>
      Recent Ticket Purchases
    </div>
    <?php if (!empty($recent_tickets)): ?>
    <span style="font-family:'Montserrat',sans-serif;font-size:.62rem;font-weight:700;color:var(--muted);background:rgba(5,46,100,.06);padding:3px 10px;border-radius:100px;border:1px solid var(--border);">
      <?= count($recent_tickets) ?> recent
    </span>
    <?php endif; ?>
  </div>

  <div style="background:var(--card);border:1px solid var(--border);border-radius:var(--r);overflow:hidden;margin-bottom:2rem;box-shadow:var(--sh-sm);">
    <?php if (empty($recent_tickets)): ?>
    <div style="padding:3rem 1rem;text-align:center;">
      <div style="width:52px;height:52px;border-radius:14px;background:rgba(191,19,57,.06);border:1px solid var(--border);display:flex;align-items:center;justify-content:center;font-size:1.25rem;color:var(--muted);margin:0 auto .75rem;">
        <i class="fa-solid fa-ticket-simple"></i>
      </div>
      <p style="font-size:.84rem;color:var(--muted);margin:0;">No ticket purchases yet. Share your event link to get started!</p>
    </div>
    <?php else: ?>

    <!-- mobile: card list -->
    <div class="buyers-mobile">
      <?php foreach ($recent_tickets as $i => $t):
        $isPaid   = strtolower($t['status']) === 'paid';
        $isFailed = strtolower($t['status']) === 'failed';
        $dotColor = $isPaid ? 'var(--success)' : ($isFailed ? 'var(--red)' : 'var(--gold)');
        $initials = strtoupper(substr(trim($t['buyer_name']), 0, 1) ?: '?');
        $avatarBg = ['#052e64','#bf1339','#00b37e','#7c3aed','#0a4494'];
        $bg       = $avatarBg[$i % count($avatarBg)];
      ?>
      <div style="display:flex;align-items:flex-start;gap:.85rem;padding:.95rem 1.1rem;border-bottom:1px solid var(--border)<?= $i === count($recent_tickets)-1 ? ';border-bottom:none' : '' ?>;">
        <!-- Avatar -->
        <div style="flex-shrink:0;width:38px;height:38px;border-radius:10px;background:<?= $bg ?>;display:flex;align-items:center;justify-content:center;font-family:'Montserrat',sans-serif;font-weight:800;font-size:.9rem;color:#fff;">
          <?= htmlspecialchars($initials) ?>
        </div>
        <!-- Info -->
        <div style="flex:1;min-width:0;">
          <div style="display:flex;align-items:center;justify-content:space-between;gap:.5rem;flex-wrap:wrap;">
            <div style="font-family:'Montserrat',sans-serif;font-weight:700;font-size:.82rem;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:160px;">
              <?= htmlspecialchars($t['buyer_name']) ?>
            </div>
            <div style="font-family:'Montserrat',sans-serif;font-weight:800;font-size:.82rem;color:var(--red);white-space:nowrap;">
              KSh <?= number_format($t['amount_paid'], 0) ?>
            </div>
          </div>
          <div style="font-size:.7rem;color:var(--muted);margin:.15rem 0 .35rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
            <?= htmlspecialchars($t['event_title'] ?? '—') ?>
          </div>
          <div style="display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;">
            <span style="font-size:.65rem;background:rgba(5,46,100,.06);border:1px solid var(--border);border-radius:6px;padding:2px 7px;color:var(--muted);font-family:'Montserrat',sans-serif;font-weight:600;">
              #<?= htmlspecialchars($t['ticket_number']) ?>
            </span>
            <span style="font-size:.65rem;color:var(--muted);"><?= (int)$t['quantity'] ?> ticket<?= (int)$t['quantity'] !== 1 ? 's' : '' ?></span>
            <span style="display:inline-flex;align-items:center;gap:4px;font-size:.65rem;color:var(--muted);">
              <span style="width:6px;height:6px;border-radius:50%;background:<?= $dotColor ?>;display:inline-block;"></span>
              <?= ucfirst($t['status']) ?>
            </span>
            <?php if ($t['is_checked_in']): ?>
            <span style="font-size:.6rem;background:rgba(0,179,126,.1);color:var(--success);border-radius:6px;padding:2px 6px;font-family:'Montserrat',sans-serif;font-weight:700;">
              <i class="fa-solid fa-check" style="font-size:.5rem"></i> Checked In
            </span>
            <?php endif; ?>
            <span style="font-size:.63rem;color:var(--muted);margin-left:auto;"><?= date('d M, H:i', strtotime($t['created_at'])) ?></span>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>

    <!-- desktop: table -->
    <div class="buyers-desktop">
      <div class="tbl-scroll">
        <table>
          <thead>
            <tr>
              <th>Buyer</th>
              <th>Event</th>
              <th>Ticket #</th>
              <th style="text-align:center">Qty</th>
              <th>Amount</th>
              <th>M-Pesa Receipt</th>
              <th>Status</th>
              <th>Date</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($recent_tickets as $i => $t):
              $isPaid   = strtolower($t['status']) === 'paid';
              $isFailed = strtolower($t['status']) === 'failed';
              $sc       = $isPaid ? 'b-paid' : ($isFailed ? 'b-failed' : 'b-pending');
              $initials = strtoupper(substr(trim($t['buyer_name']), 0, 1) ?: '?');
              $avatarBg = ['#052e64','#bf1339','#00b37e','#7c3aed','#0a4494'];
              $bg       = $avatarBg[$i % count($avatarBg)];
            ?>
            <tr>
              <td>
                <div style="display:flex;align-items:center;gap:.6rem;">
                  <div style="flex-shrink:0;width:32px;height:32px;border-radius:8px;background:<?= $bg ?>;display:flex;align-items:center;justify-content:center;font-family:'Montserrat',sans-serif;font-weight:800;font-size:.75rem;color:#fff;">
                    <?= htmlspecialchars($initials) ?>
                  </div>
                  <div>
                    <div class="tp"><?= htmlspecialchars($t['buyer_name']) ?></div>
                    <div class="ts"><?= htmlspecialchars($t['buyer_phone'] ?? '') ?></div>
                  </div>
                </div>
              </td>
              <td>
                <div class="tp" style="max-width:130px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:.76rem;">
                  <?= htmlspecialchars($t['event_title'] ?? '—') ?>
                </div>
              </td>
              <td><span class="tm"><?= htmlspecialchars($t['ticket_number']) ?></span></td>
              <td style="text-align:center;">
                <span style="font-family:'Montserrat',sans-serif;font-weight:700;font-size:.85rem;"><?= (int)$t['quantity'] ?></span>
              </td>
              <td><span style="font-family:'Montserrat',sans-serif;font-weight:800;color:var(--red);font-size:.82rem;">KSh <?= number_format($t['amount_paid'], 0) ?></span></td>
              <td><span class="tm"><?= htmlspecialchars($t['mpesa_receipt'] ?? '—') ?></span></td>
              <td>
                <div style="display:flex;flex-direction:column;gap:3px;align-items:flex-start;">
                  <span class="bdg <?= $sc ?>"><?= ucfirst($t['status']) ?></span>
                  <?php if ($t['is_checked_in']): ?>
                  <span class="in-tag"><i class="fa-solid fa-check" style="font-size:.5rem"></i> Checked In</span>
                  <?php endif; ?>
                </div>
              </td>
              <td style="color:var(--muted);font-size:.7rem;white-space:nowrap;"><?= date('d M, H:i', strtotime($t['created_at'])) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <?php endif; ?>
  </div>

  <?php endif; ?>

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
// ── Account type switch ──
(function() {
  var wrap   = document.getElementById('modeSwitch');
  var loader = document.getElementById('modeSwitchLoader');
  var target = document.getElementById('modeSwitchLoaderTarget');
  if (!wrap) return;

  var labels = { creator: 'Creator', fundraiser: 'Fundraiser' };

  function showLoader(type) {
    if (!loader) return;
    if (target) target.textContent = labels[type] || type;
    loader.classList.add('show');
    requestAnimationFrame(function () { loader.classList.add('visible'); });
  }
  function hideLoader() {
    if (!loader) return;
    loader.classList.remove('visible');
    setTimeout(function () { loader.classList.remove('show'); }, 250);
  }

  wrap.querySelectorAll('.mode-switch-opt').forEach(function(btn) {
    btn.addEventListener('click', function() {
      if (btn.classList.contains('active') || wrap.dataset.busy) return;
      wrap.dataset.busy = '1';
      wrap.querySelectorAll('.mode-switch-opt').forEach(function(b) { b.disabled = true; });
      showLoader(btn.dataset.type);
      fetch('switch_account_type.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'switch_account_type=' + encodeURIComponent(btn.dataset.type)
      })
        .then(function(r) { return r.json(); })
        .then(function(data) {
          if (data.success) { window.location.reload(); }
          else {
            hideLoader();
            wrap.querySelectorAll('.mode-switch-opt').forEach(function(b) { b.disabled = false; });
            delete wrap.dataset.busy;
          }
        })
        .catch(function() {
          hideLoader();
          wrap.querySelectorAll('.mode-switch-opt').forEach(function(b) { b.disabled = false; });
          delete wrap.dataset.busy;
        });
    });
  });
})();

// ── Preloader ──
window.addEventListener('load', () => {
  setTimeout(() => {
    var p = document.getElementById('preloader');
    if (p) p.classList.add('gone');
  }, 1600);
});

// ── Location notice (single notice + modal) ──
(function() {
  var ids = <?= !empty($eventsNoLocation) ? json_encode(array_column($eventsNoLocation, 'id')) : '[]' ?>;
  if (!ids.length) return;
  var key = 'locNotice_' + ids.slice().sort().join('_');
  if (localStorage.getItem(key) === '1') {
    var el = document.getElementById('locNoticeSingle');
    if (el) el.remove();
  }
  window._locNoticeKey = key;
})();
function dismissLocAll() {
  if (window._locNoticeKey) localStorage.setItem(window._locNoticeKey, '1');
  var el = document.getElementById('locNoticeSingle');
  if (el) el.remove();
}

// ── Share-to-boost-sales notice (single notice + modal) ──
(function() {
  var ids = <?= !empty($activeEventsForShare) ? json_encode(array_column($activeEventsForShare, 'id')) : '[]' ?>;
  if (!ids.length) return;
  var key = 'shareNotice_' + ids.slice().sort().join('_');
  if (localStorage.getItem(key) === '1') {
    var el = document.getElementById('shareNoticeSingle');
    if (el) el.remove();
  }
  window._shareNoticeKey = key;
})();
function dismissShareAll() {
  if (window._shareNoticeKey) localStorage.setItem(window._shareNoticeKey, '1');
  var el = document.getElementById('shareNoticeSingle');
  if (el) el.remove();
}
var kcShareUrl = '';
function openShareModal(btn) {
  var title  = btn.dataset.title  || '';
  var desc   = btn.dataset.desc   || '';
  var banner = btn.dataset.banner || '';
  var url    = btn.dataset.url    || '';
  kcShareUrl = url;

  document.getElementById('sharePrevTitle').textContent = title;
  document.getElementById('sharePrevDesc').textContent  = desc;
  var img = document.getElementById('sharePrevImg');
  var ico = document.getElementById('sharePrevIco');
  if (banner) { img.src = banner; img.style.display = ''; ico.style.display = 'none'; }
  else { img.style.display = 'none'; ico.style.display = ''; }

  var shareText = desc + '\n' + url;
  document.getElementById('shareWaBtn').href = 'https://wa.me/?text=' + encodeURIComponent(shareText);
  document.getElementById('shareFbBtn').href = 'https://www.facebook.com/sharer/sharer.php?u=' + encodeURIComponent(url);

  var box = document.getElementById('shareLinkBox');
  box.textContent = ''; box.classList.remove('visible');

  document.getElementById('shareOverlay').classList.add('active');
}
function closeShareModal() {
  document.getElementById('shareOverlay').classList.remove('active');
}
function copyShareLink() {
  var box = document.getElementById('shareLinkBox');
  box.textContent = kcShareUrl;
  box.classList.add('visible');
  if (navigator.clipboard && navigator.clipboard.writeText) {
    navigator.clipboard.writeText(kcShareUrl).then(function () {
      if (window.toastr) toastr.success('Link copied to clipboard!', '', { timeOut: 2500 });
    }).catch(function () {});
  } else {
    try {
      var ta = document.createElement('textarea');
      ta.value = kcShareUrl; ta.setAttribute('readonly', '');
      ta.style.cssText = 'position:fixed;top:-9999px;left:-9999px;opacity:0;';
      document.body.appendChild(ta);
      ta.focus(); ta.select(); ta.setSelectionRange(0, ta.value.length);
      document.execCommand('copy');
      document.body.removeChild(ta);
    } catch (err) {}
  }
}
(function() {
  var ov = document.getElementById('shareOverlay');
  if (ov) ov.addEventListener('click', function (e) { if (e.target === this) closeShareModal(); });
})();
function openLocModal() {
  var m = document.getElementById('locModal');
  if (m) { m.style.display = 'flex'; document.body.style.overflow = 'hidden'; }
}
function closeLocModal() {
  var m = document.getElementById('locModal');
  if (m) { m.style.display = 'none'; document.body.style.overflow = ''; }
}

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

// ── Charts ──
Chart.defaults.font.family = "'Glacial Indifference', 'Trebuchet MS', sans-serif";
Chart.defaults.color = '#6b7a99';
const gridColor = 'rgba(5,46,100,0.05)';

// Ticket chart
if (document.getElementById('ticketChart')) {
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
}

// Support chart
if (document.getElementById('supportChart')) {
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
}

// Donation status pie chart (fundraiser dashboard)
if (document.getElementById('donationStatusChart')) {
  new Chart(document.getElementById('donationStatusChart'), {
    type: 'doughnut',
    data: {
      labels: ['Completed', 'Pending', 'Failed'],
      datasets: [{
        data: <?= json_encode(array_values($donationStatusBreakdown)) ?>,
        backgroundColor: ['#00b37e', '#f59e0b', '#bf1339'],
        borderColor: '#fff',
        borderWidth: 3,
        hoverOffset: 6
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      cutout: '68%',
      plugins: { legend: { display: false }, tooltip: { padding: 10, cornerRadius: 8 } }
    }
  });
}

function kcToggleFin(btn) {
  var wrap = btn.closest('.fin-wrap');
  if (!wrap) return;
  wrap.classList.toggle('fin-hidden');
  var icon = btn.querySelector('i');
  icon.classList.toggle('fa-eye');
  icon.classList.toggle('fa-eye-slash');
}
</script>
</body>
</html>