<?php
if (session_status() === PHP_SESSION_NONE) session_start();

$connect_path = file_exists('msanii/includes/connect.php')
    ? 'msanii/includes/connect.php'
    : 'includes/connect.php';

if (!include_once $connect_path) die("Database connection failed.");

// ── Auth guard ──────────────────────────────────────────────────────────────
if (empty($_SESSION['creator_id'])) {
    header('Location: login.php'); exit;
}
$creator_id = (int)$_SESSION['creator_id'];

// ── Ensure tables exist ─────────────────────────────────────────────────────
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

$pdo->exec("CREATE TABLE IF NOT EXISTS sms_topup_log (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    creator_id  INT UNSIGNED NOT NULL,
    amount      DECIMAL(10,2) NOT NULL,
    sms_credits INT UNSIGNED NOT NULL,
    date        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    note        VARCHAR(255) NULL,
    INDEX idx_creator (creator_id),
    INDEX idx_date (date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// ── Fetch creator info ───────────────────────────────────────────────────────
$stmt = $pdo->prepare("SELECT c.username, mp.full_name
                       FROM creators c
                       LEFT JOIN my_page mp ON c.id = mp.creator_id
                       WHERE c.id = ?");
$stmt->execute([$creator_id]);
$creator      = $stmt->fetch(PDO::FETCH_ASSOC);
$display_name = $creator['full_name'] ?? $creator['username'] ?? 'Creator';
$initials     = strtoupper(substr($display_name, 0, 1));

// ── Fetch balances ───────────────────────────────────────────────────────────
$stmt = $pdo->prepare("SELECT COALESCE(amount, 0) FROM my_balance WHERE creator_id = ?");
$stmt->execute([$creator_id]);
$ksh_balance = (float)$stmt->fetchColumn();

// Ensure SMS row exists
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
    $sms_row          = ['balance' => $welcome_bonus, 'total_bought' => 0, 'total_used' => 0, 'last_topped_up' => null, 'bonus_given' => 1];
} else {
    $sms_balance = (int)$sms_row['balance'];
    if (!$sms_row['bonus_given'] && $sms_row['total_bought'] == 0) {
        $pdo->prepare("UPDATE creator_sms SET balance = balance + ?, bonus_given = 1 WHERE creator_id = ?")
            ->execute([$welcome_bonus, $creator_id]);
        $sms_balance     += $welcome_bonus;
        $bonus_just_given = true;
    }
}

// ── Pricing constant ─────────────────────────────────────────────────────────
define('SMS_RATE', 0.50); // KES per SMS

// ── Process purchase ─────────────────────────────────────────────────────────
$msg        = null;
$msg_type   = null;
$show_success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['purchase'])) {
    $custom_sms = filter_input(INPUT_POST, 'custom_sms', FILTER_VALIDATE_INT);
    $pkg_sms    = filter_input(INPUT_POST, 'pkg_sms',    FILTER_VALIDATE_INT);
    $qty        = ($custom_sms > 0) ? $custom_sms : ($pkg_sms > 0 ? $pkg_sms : 0);

    if ($qty < 2) {
        $msg = "Minimum purchase is 2 SMS credits.";
        $msg_type = 'error';
    } else {
        $cost = round($qty * SMS_RATE, 2);

        // Re-fetch balance fresh inside transaction
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("SELECT COALESCE(amount, 0) FROM my_balance WHERE creator_id = ? FOR UPDATE");
            $stmt->execute([$creator_id]);
            $live_balance = (float)$stmt->fetchColumn();

            if ($live_balance < $cost) {
                $pdo->rollBack();
                $msg = "Insufficient balance. You need KSh " . number_format($cost, 2)
                     . " but your balance is KSh " . number_format($live_balance, 2) . ".";
                $msg_type = 'error';
            } else {
                // Deduct from my_balance
                $pdo->prepare("UPDATE my_balance SET amount = amount - ? WHERE creator_id = ?")
                    ->execute([$cost, $creator_id]);

                // Credit SMS
                $pdo->prepare("INSERT INTO creator_sms (creator_id, balance, bonus_given, total_bought)
                               VALUES (?, ?, 1, ?)
                               ON DUPLICATE KEY UPDATE
                                 balance        = balance + VALUES(balance),
                                 total_bought   = total_bought + VALUES(total_bought),
                                 last_topped_up = NOW()")
                    ->execute([$creator_id, $qty, $qty]);

                // Log the transaction
                $pdo->prepare("INSERT INTO sms_topup_log (creator_id, amount, sms_credits, note)
                               VALUES (?, ?, ?, ?)")
                    ->execute([$creator_id, $cost, $qty, "Topup: {$qty} SMS @ KSh " . SMS_RATE . " each"]);

                $pdo->commit();

                // Refresh
                $sms_balance = $sms_balance + $qty;
                $ksh_balance = $live_balance - $cost;
                $show_success = true;
                $msg      = "Success! {$qty} SMS credits added to your account. KSh " . number_format($cost, 2) . " deducted.";
                $msg_type = 'success';
            }
        } catch (Exception $e) {
            $pdo->rollBack();
            $msg = "Transaction failed. Please try again.";
            $msg_type = 'error';
        }
    }
}

// ── Topup history ────────────────────────────────────────────────────────────
$stmt = $pdo->prepare("SELECT * FROM sms_topup_log WHERE creator_id = ? ORDER BY date DESC LIMIT 10");
$stmt->execute([$creator_id]);
$history = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Totals ───────────────────────────────────────────────────────────────────
$total_bought = (int)($sms_row['total_bought'] ?? 0);
$total_used   = (int)($sms_row['total_used']   ?? 0);
$last_topup   = $sms_row['last_topped_up'] ?? null;

date_default_timezone_set('Africa/Nairobi');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>SMS Top-Up — Keychele</title>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>

<style>
@font-face {
  font-family: 'Glacial Indifference';
  src: url('https://db.onlinewebfonts.com/t/74f7b46c5a6a4374c10b2cb3ef8b4fc1.woff2') format('woff2');
  font-weight: 400; font-style: normal;
}
@font-face {
  font-family: 'Glacial Indifference';
  src: url('https://db.onlinewebfonts.com/t/2b2f592d11d16f79ccbc60b42a25e9e1.woff2') format('woff2');
  font-weight: 700; font-style: normal;
}

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
  min-height: 100vh;
}

h1, h2, h3, h4, h5 { font-family: 'Montserrat', sans-serif; }

/* ── PAGE WRAPPER ── */
.sms-page { max-width: 720px; margin: 0 auto; padding: 1.5rem; }
@media (max-width: 600px) { .sms-page { padding: 1rem 0.9rem; } }

/* ── BACK LINK ── */
.back-link {
  display: inline-flex; align-items: center; gap: 7px;
  font-family: 'Montserrat', sans-serif; font-size: 0.72rem; font-weight: 700;
  color: var(--muted); text-decoration: none; letter-spacing: 0.04em;
  margin-bottom: 1.25rem; transition: color 0.18s, gap 0.18s;
}
.back-link:hover { color: var(--navy); gap: 10px; }
.back-link i { font-size: 0.65rem; }

/* ── HERO CARD ── */
.sms-hero {
  position: relative; overflow: hidden;
  border-radius: var(--r-lg);
  background: linear-gradient(135deg, #4c1d95 0%, var(--sms) 45%, #7c3aed 100%);
  padding: 2rem 2.25rem;
  box-shadow: 0 16px 56px rgba(124,58,237,0.32), 0 0 0 1px rgba(255,255,255,0.07) inset;
  margin-bottom: 1.5rem;
  animation: rise 0.55s cubic-bezier(0.16,1,0.3,1) both;
}
@keyframes rise { from{opacity:0;transform:translateY(20px)} to{opacity:1;transform:translateY(0)} }

.hero-grid {
  position: absolute; inset: 0; pointer-events: none;
  background-image: linear-gradient(rgba(255,255,255,0.04) 1px,transparent 1px),
                    linear-gradient(90deg,rgba(255,255,255,0.04) 1px,transparent 1px);
  background-size: 32px 32px;
}
.hero-orb {
  position: absolute; border-radius: 50%; pointer-events: none;
  width: 300px; height: 300px;
  top: -100px; right: -60px;
  background: radial-gradient(circle, rgba(167,139,250,0.35) 0%, transparent 65%);
}
.hero-orb2 {
  position: absolute; border-radius: 50%; pointer-events: none;
  width: 180px; height: 180px;
  bottom: -70px; left: 10%;
  background: radial-gradient(circle, rgba(254,196,17,0.18) 0%, transparent 65%);
}

.hero-inner { position: relative; z-index: 2; display: flex; align-items: center; justify-content: space-between; gap: 1.5rem; flex-wrap: wrap; }

.hero-left {}
.hero-tag {
  display: inline-flex; align-items: center; gap: 6px;
  background: rgba(255,255,255,0.12); border: 1px solid rgba(255,255,255,0.2);
  border-radius: 100px; padding: 4px 12px; margin-bottom: 0.75rem;
  font-family: 'Montserrat', sans-serif; font-size: 0.62rem; font-weight: 700;
  letter-spacing: 0.09em; text-transform: uppercase; color: rgba(255,255,255,0.85);
}
.hero-title {
  font-family: 'Montserrat', sans-serif; font-weight: 900; font-size: clamp(1.4rem,4vw,2rem);
  letter-spacing: -0.04em; color: white; line-height: 1.1; margin-bottom: 0.5rem;
}
.hero-title span { color: var(--gold); }
.hero-sub { font-size: 0.78rem; color: rgba(255,255,255,0.6); margin-bottom: 1.1rem; }
.hero-rate {
  display: inline-flex; align-items: center; gap: 8px;
  background: rgba(255,255,255,0.1); border: 1px solid rgba(255,255,255,0.18);
  border-radius: 10px; padding: 8px 14px;
}
.rate-num {
  font-family: 'Montserrat', sans-serif; font-weight: 800; font-size: 1.2rem; color: var(--gold);
}
.rate-lbl { font-size: 0.72rem; color: rgba(255,255,255,0.6); }

.hero-stats { display: flex; flex-direction: column; gap: 0.6rem; flex-shrink: 0; }
.hs {
  background: rgba(255,255,255,0.1); border: 1px solid rgba(255,255,255,0.15);
  border-radius: 12px; padding: 0.85rem 1.1rem; text-align: center; min-width: 110px;
  backdrop-filter: blur(8px);
}
.hs-val { font-family: 'Montserrat', sans-serif; font-weight: 800; font-size: 1.3rem; color: white; letter-spacing: -0.03em; }
.hs-lbl { font-size: 0.62rem; color: rgba(255,255,255,0.5); text-transform: uppercase; letter-spacing: 0.07em; margin-top: 2px; }
.hs-val.gold { color: var(--gold); }
.hs-val.grn  { color: #6ee7b7; }

/* ── ALERT MESSAGES ── */
.msg-box {
  display: flex; align-items: flex-start; gap: 12px;
  border-radius: var(--r); padding: 1.1rem 1.3rem;
  margin-bottom: 1.25rem; animation: rise 0.4s ease both;
}
.msg-box.success { background: rgba(0,179,126,0.08); border: 1.5px solid rgba(0,179,126,0.3); }
.msg-box.error   { background: rgba(191,19,57,0.06); border: 1.5px solid rgba(191,19,57,0.25); }
.msg-ico { width: 34px; height: 34px; border-radius: 9px; display: flex; align-items: center; justify-content: center; font-size: 0.9rem; flex-shrink: 0; }
.msg-box.success .msg-ico { background: rgba(0,179,126,0.15); color: var(--success); }
.msg-box.error   .msg-ico { background: rgba(191,19,57,0.1); color: var(--red); }
.msg-text { font-size: 0.82rem; line-height: 1.55; }
.msg-box.success .msg-text { color: #065f46; }
.msg-box.error   .msg-text { color: var(--red-d); }

/* ── BALANCE BAR ── */
.bal-bar {
  display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 0.6rem;
  background: var(--card); border: 1px solid var(--border); border-radius: var(--r);
  padding: 1rem 1.35rem; box-shadow: var(--sh-sm); margin-bottom: 1.5rem;
}
.bal-item { display: flex; align-items: center; gap: 10px; }
.bal-ico { width: 38px; height: 38px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 0.95rem; }
.bal-ico.ksh { background: rgba(0,179,126,0.1); color: var(--success); }
.bal-ico.sms { background: rgba(124,58,237,0.1); color: var(--sms); }
.bal-lbl { font-size: 0.65rem; color: var(--muted); text-transform: uppercase; letter-spacing: 0.05em; }
.bal-val { font-family: 'Montserrat', sans-serif; font-weight: 800; font-size: 1.05rem; color: var(--text); letter-spacing: -0.02em; }
.bal-divider { width: 1px; height: 38px; background: var(--border); }

/* ── PACKAGES ── */
.sec-hd { display: flex; align-items: center; gap: 8px; margin-bottom: 1rem; }
.sec-title {
  font-family: 'Montserrat', sans-serif; font-weight: 700; font-size: 0.72rem;
  text-transform: uppercase; letter-spacing: 0.1em; color: var(--muted);
  display: flex; align-items: center; gap: 8px;
}
.sec-title::before { content: ''; width: 3px; height: 14px; border-radius: 3px; background: linear-gradient(180deg, var(--sms), var(--sms-l)); }

.pkg-grid { display: grid; grid-template-columns: repeat(2,1fr); gap: 0.8rem; margin-bottom: 1.5rem; }
@media (min-width:540px) { .pkg-grid { grid-template-columns: repeat(3,1fr); } }

.pkg {
  position: relative; background: var(--card); border: 2px solid var(--border);
  border-radius: var(--r); padding: 1.2rem 1rem; text-align: center;
  cursor: pointer; transition: all 0.2s; overflow: hidden;
  animation: rise 0.5s cubic-bezier(0.16,1,0.3,1) both;
}
.pkg:nth-child(1){animation-delay:.05s}.pkg:nth-child(2){animation-delay:.10s}
.pkg:nth-child(3){animation-delay:.15s}.pkg:nth-child(4){animation-delay:.20s}
.pkg:nth-child(5){animation-delay:.25s}.pkg:nth-child(6){animation-delay:.30s}

.pkg:hover { border-color: var(--sms); box-shadow: 0 4px 20px rgba(124,58,237,0.18); transform: translateY(-2px); }
.pkg.selected { border-color: var(--sms); background: rgba(124,58,237,0.04); box-shadow: 0 4px 20px rgba(124,58,237,0.2); }
.pkg.selected::after {
  content: '\f00c';
  font-family: 'Font Awesome 6 Free'; font-weight: 900;
  position: absolute; top: 8px; right: 10px;
  font-size: 0.65rem; color: var(--sms);
}
.pkg-popular {
  position: absolute; top: 0; left: 50%; transform: translateX(-50%);
  background: linear-gradient(90deg, var(--sms), var(--sms-l));
  color: white; font-family: 'Montserrat', sans-serif;
  font-size: 0.52rem; font-weight: 800; letter-spacing: 0.08em;
  text-transform: uppercase; padding: 3px 10px; border-radius: 0 0 8px 8px;
}
.pkg-sms { font-family: 'Montserrat', sans-serif; font-weight: 900; font-size: 1.5rem; color: var(--text); letter-spacing: -0.04em; margin-top: 0.75rem; }
.pkg-sms span { font-size: 0.72rem; font-weight: 600; color: var(--muted); letter-spacing: 0; }
.pkg-cost { font-family: 'Montserrat', sans-serif; font-size: 0.78rem; font-weight: 700; color: var(--sms); margin: 4px 0 6px; }
.pkg-save {
  display: inline-block; background: rgba(0,179,126,0.1); color: var(--success);
  font-family: 'Montserrat', sans-serif; font-size: 0.57rem; font-weight: 700;
  border-radius: 100px; padding: 2px 8px;
}

/* ── CUSTOM INPUT ── */
.custom-box {
  background: var(--card); border: 1px solid var(--border); border-radius: var(--r);
  padding: 1.35rem; box-shadow: var(--sh-sm); margin-bottom: 1.5rem;
  animation: rise 0.55s ease .35s both;
}
.custom-title { font-family: 'Montserrat', sans-serif; font-weight: 700; font-size: 0.82rem; color: var(--text); margin-bottom: 0.65rem; }
.custom-row { display: flex; gap: 0.75rem; align-items: flex-end; }
.field { flex: 1; }
.field label { display: block; font-size: 0.68rem; color: var(--muted); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 5px; }
.field input[type=number] {
  width: 100%; border: 1.5px solid var(--border); border-radius: 10px;
  padding: 0.75rem 1rem; font-family: 'Montserrat', sans-serif; font-weight: 700;
  font-size: 1rem; color: var(--text); background: #f8f9fc;
  outline: none; transition: border-color 0.18s, box-shadow 0.18s;
  -moz-appearance: textfield;
}
.field input::-webkit-inner-spin-button { -webkit-appearance: none; }
.field input:focus { border-color: var(--sms); box-shadow: 0 0 0 3px rgba(124,58,237,0.1); background: white; }
.cost-preview {
  font-family: 'Montserrat', sans-serif; font-size: 0.73rem; color: var(--muted);
  margin-top: 6px; min-height: 18px; transition: color 0.18s;
}
.cost-preview.active { color: var(--sms); font-weight: 700; }

/* ── BUY BUTTON ── */
.buy-form { margin-bottom: 1.5rem; }
.buy-btn {
  width: 100%; display: flex; align-items: center; justify-content: center; gap: 10px;
  background: linear-gradient(135deg, var(--sms), #6d28d9);
  color: white; border: none; border-radius: var(--r); cursor: pointer;
  font-family: 'Montserrat', sans-serif; font-weight: 800; font-size: 1rem;
  letter-spacing: -0.01em; padding: 1.1rem;
  box-shadow: 0 6px 24px rgba(124,58,237,0.4);
  transition: transform 0.2s, box-shadow 0.2s, opacity 0.2s;
  animation: rise 0.55s ease .4s both;
}
.buy-btn:hover { transform: translateY(-2px); box-shadow: 0 10px 32px rgba(124,58,237,0.5); }
.buy-btn:active { transform: translateY(0); }
.buy-btn:disabled { opacity: 0.5; cursor: not-allowed; transform: none; }
.buy-sub {
  text-align: center; font-size: 0.68rem; color: var(--muted); margin-top: 0.6rem;
  display: flex; align-items: center; justify-content: center; gap: 6px;
}

/* ── HISTORY TABLE ── */
.hist-card {
  background: var(--card); border: 1px solid var(--border); border-radius: var(--r);
  box-shadow: var(--sh-sm); overflow: hidden;
  animation: rise 0.55s ease .45s both;
}
.hist-hd {
  display: flex; align-items: center; justify-content: space-between;
  padding: 1rem 1.35rem; border-bottom: 1px solid var(--border); background: #fafbfd;
}
.hist-title { font-family: 'Montserrat', sans-serif; font-weight: 700; font-size: 0.9rem; color: var(--text); }
.tbl-wrap { overflow-x: auto; }
table { width: 100%; border-collapse: collapse; }
thead tr { background: #fafbfd; }
th {
  padding: 0.7rem 1rem; text-align: left;
  font-family: 'Montserrat', sans-serif; font-size: 0.65rem; font-weight: 700;
  text-transform: uppercase; letter-spacing: 0.07em; color: var(--muted);
  border-bottom: 1px solid var(--border); white-space: nowrap;
}
td { padding: 0.85rem 1rem; border-bottom: 1px solid rgba(5,46,100,0.05); font-size: 0.82rem; vertical-align: middle; }
tbody tr:last-child td { border-bottom: none; }
tbody tr:hover { background: rgba(124,58,237,0.025); }
.tm { font-family: 'Montserrat', sans-serif; font-weight: 700; font-size: 0.75rem; color: var(--navy); }
.ta { font-family: 'Montserrat', sans-serif; font-weight: 700; }
.empty-state { padding: 3rem 1rem; text-align: center; color: var(--muted); }
.empty-state i { font-size: 2rem; opacity: 0.25; margin-bottom: 0.75rem; display: block; }
.empty-state p { font-size: 0.82rem; }

/* ── BOTTOM NAV ── */
.mob-nav {
  position: fixed; bottom: 0; left: 0; right: 0; height: 62px;
  background: var(--card); border-top: 1px solid var(--border);
  box-shadow: 0 -4px 20px rgba(5,46,100,0.08);
  z-index: 100; display: flex; align-items: center; justify-content: space-around;
}
@media (min-width: 768px) { .mob-nav { display: none; } }
.mn-item { display: flex; flex-direction: column; align-items: center; gap: 2px; text-decoration: none; color: var(--muted); font-family: 'Montserrat', sans-serif; font-size: 0.53rem; font-weight: 700; letter-spacing: 0.04em; text-transform: uppercase; transition: color 0.2s; }
.mn-item.active, .mn-item:hover { color: var(--sms); }
.mn-item i { font-size: 1.05rem; }
.mn-fab { width: 50px; height: 50px; border-radius: 50%; background: linear-gradient(135deg, var(--red), #f04060); display: flex; align-items: center; justify-content: center; color: white; font-size: 1.15rem; margin-top: -14px; box-shadow: 0 4px 18px rgba(191,19,57,0.45); text-decoration: none; transition: transform 0.2s; }
.mn-fab:hover { transform: scale(1.08) translateY(-2px); }

/* ── SUCCESS CONFETTI OVERLAY ── */
.success-overlay { display: none; }
.success-overlay.show {
  display: flex; position: fixed; inset: 0; z-index: 200;
  background: rgba(15,25,35,0.55); backdrop-filter: blur(4px);
  align-items: center; justify-content: center; padding: 1rem;
}
.success-modal {
  background: white; border-radius: var(--r-lg); padding: 2.5rem 2rem;
  text-align: center; max-width: 360px; width: 100%;
  box-shadow: var(--sh-lg); animation: pop-in 0.45s cubic-bezier(0.16,1,0.3,1) both;
}
@keyframes pop-in { from{opacity:0;transform:scale(0.85)} to{opacity:1;transform:scale(1)} }
.success-icon {
  width: 72px; height: 72px; border-radius: 50%;
  background: linear-gradient(135deg, var(--sms), var(--sms-l));
  display: flex; align-items: center; justify-content: center;
  font-size: 1.8rem; color: white; margin: 0 auto 1.25rem;
  box-shadow: 0 8px 28px rgba(124,58,237,0.4);
}
.success-title { font-family: 'Montserrat', sans-serif; font-weight: 900; font-size: 1.4rem; color: var(--text); margin-bottom: 0.5rem; }
.success-body { font-size: 0.82rem; color: var(--muted); margin-bottom: 1.5rem; line-height: 1.6; }
.success-close {
  width: 100%; background: linear-gradient(135deg, var(--sms), #6d28d9);
  color: white; border: none; border-radius: 10px; cursor: pointer;
  font-family: 'Montserrat', sans-serif; font-weight: 700; font-size: 0.85rem;
  padding: 0.9rem; box-shadow: 0 4px 16px rgba(124,58,237,0.35); transition: transform 0.2s;
}
.success-close:hover { transform: translateY(-1px); }
</style>
</head>
<body>

<?php if ($show_success): ?>
<div class="success-overlay show" id="successOverlay">
  <div class="success-modal">
    <div class="success-icon"><i class="fa-solid fa-check"></i></div>
    <div class="success-title">Credits Added!</div>
    <div class="success-body">
      Your SMS balance has been updated.<br>
      New balance: <strong><?= $sms_balance ?> SMS credits</strong>
    </div>
    <button class="success-close" onclick="document.getElementById('successOverlay').classList.remove('show')">
      <i class="fa-solid fa-check"></i>&nbsp; Done
    </button>
  </div>
</div>
<?php endif; ?>

<div class="sms-page">

  <a href="https://keychele.co.ke/msanii/index.php?page=dashboard.php" class="back-link">
    <i class="fa-solid fa-arrow-left"></i> Back to Dashboard
  </a>

  <!-- ── HERO ── -->
  <div class="sms-hero">
    <div class="hero-grid"></div>
    <div class="hero-orb"></div>
    <div class="hero-orb2"></div>
    <div class="hero-inner">
      <div class="hero-left">
        <div class="hero-tag"><i class="fa-solid fa-message"></i>&nbsp; SMS Credits</div>
        <h1 class="hero-title">Top Up <span>SMS</span><br>Credits</h1>
        <div class="hero-sub">Power OTP authentication &amp; buyer notifications.</div>
        <div class="hero-rate">
          <div>
            <div class="rate-num">KSh 0.50</div>
            <div class="rate-lbl">per SMS credit</div>
          </div>
        </div>
      </div>
      <div class="hero-stats">
        <div class="hs">
          <div class="hs-val gold"><?= $sms_balance ?></div>
          <div class="hs-lbl">SMS Balance</div>
        </div>
        <div class="hs">
          <div class="hs-val grn"><?= $total_bought ?></div>
          <div class="hs-lbl">Total Bought</div>
        </div>
      </div>
    </div>
  </div>

  <!-- ── ALERT MESSAGE ── -->
  <?php if ($msg && !$show_success): ?>
  <div class="msg-box <?= $msg_type ?>">
    <div class="msg-ico">
      <i class="fa-solid fa-<?= $msg_type === 'success' ? 'check' : 'circle-exclamation' ?>"></i>
    </div>
    <div class="msg-text"><?= htmlspecialchars($msg) ?></div>
  </div>
  <?php endif; ?>

  <!-- ── BALANCE BAR ── -->
  <div class="bal-bar">
    <div class="bal-item">
      <div class="bal-ico ksh"><i class="fa-solid fa-vault"></i></div>
      <div>
        <div class="bal-lbl">KSh Balance</div>
        <div class="bal-val">KSh <?= number_format($ksh_balance, 2) ?></div>
      </div>
    </div>
    <div class="bal-divider"></div>
    <div class="bal-item">
      <div class="bal-ico sms"><i class="fa-solid fa-message"></i></div>
      <div>
        <div class="bal-lbl">SMS Credits</div>
        <div class="bal-val"><?= $sms_balance ?> credits</div>
      </div>
    </div>
    <?php if ($last_topup): ?>
    <div class="bal-item" style="margin-left:auto">
      <div>
        <div class="bal-lbl">Last Top-Up</div>
        <div style="font-family:'Montserrat',sans-serif;font-size:0.75rem;font-weight:700;color:var(--muted)"><?= date('d M Y', strtotime($last_topup)) ?></div>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <!-- ── PACKAGES ── -->
  <div class="sec-hd"><div class="sec-title">Choose a Package</div></div>

  <form method="POST" class="buy-form" id="buyForm">
    <input type="hidden" name="purchase" value="1">
    <input type="hidden" name="pkg_sms"  id="pkgSmsInput" value="">
    <input type="hidden" name="custom_sms" id="customSmsInput" value="">

    <div class="pkg-grid">
      <?php
        $packages = [
          ['sms' => 10,  'cost' => 5.00,  'save' => null],
          ['sms' => 20,  'cost' => 10.00, 'save' => null],
          ['sms' => 50,  'cost' => 25.00, 'save' => null, 'popular' => true],
          ['sms' => 100, 'cost' => 50.00, 'save' => 'Save 0%'],
          ['sms' => 250, 'cost' => 125.00,'save' => null],
          ['sms' => 500, 'cost' => 250.00,'save' => null],
        ];
        foreach ($packages as $p):
      ?>
      <div class="pkg" data-sms="<?= $p['sms'] ?>" data-cost="<?= $p['cost'] ?>" onclick="selectPkg(this)">
        <?php if (!empty($p['popular'])): ?>
          <div class="pkg-popular">Most Popular</div>
        <?php endif; ?>
        <div class="pkg-sms"><?= $p['sms'] ?> <span>SMS</span></div>
        <div class="pkg-cost">KSh <?= number_format($p['cost'], 2) ?></div>
        <?php if (!empty($p['save'])): ?>
          <div class="pkg-save"><?= $p['save'] ?></div>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>

    <!-- Custom Input -->
    <div class="custom-box">
      <div class="custom-title"><i class="fa-solid fa-sliders" style="color:var(--sms);margin-right:6px"></i>Custom Amount</div>
      <div class="custom-row">
        <div class="field">
          <label>Number of SMS Credits</label>
          <input type="number" id="customSms" min="2" placeholder="e.g. 75" oninput="onCustomInput(this)">
          <div class="cost-preview" id="costPreview">Minimum 2 credits</div>
        </div>
      </div>
    </div>

    <button type="submit" class="buy-btn" id="buyBtn" disabled>
      <i class="fa-solid fa-message"></i>
      <span id="buyBtnText">Select a package or enter custom amount</span>
    </button>
    <div class="buy-sub">
      <i class="fa-solid fa-shield-halved"></i>
      Deducted instantly from your KSh balance &nbsp;·&nbsp; Rate: KSh 0.50 / SMS
    </div>
  </form>

  <!-- ── HISTORY ── -->
  <div class="hist-card">
    <div class="hist-hd">
      <div class="hist-title">Top-Up History</div>
      <span style="font-family:'Montserrat',sans-serif;font-size:0.68rem;font-weight:700;color:var(--muted)">Last 10 transactions</span>
    </div>
    <div class="tbl-wrap">
      <?php if (empty($history)): ?>
        <div class="empty-state">
          <i class="fa-solid fa-clock-rotate-left"></i>
          <p>No top-up history yet</p>
        </div>
      <?php else: ?>
      <table>
        <thead>
          <tr>
            <th>#</th>
            <th>SMS Credits</th>
            <th>Amount Deducted</th>
            <th>Note</th>
            <th>Date</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($history as $i => $h): ?>
          <tr>
            <td><span class="tm"><?= $h['id'] ?></span></td>
            <td>
              <span style="display:inline-flex;align-items:center;gap:6px">
                <span style="width:26px;height:26px;border-radius:7px;background:rgba(124,58,237,0.1);display:inline-flex;align-items:center;justify-content:center;color:var(--sms);font-size:.7rem">
                  <i class="fa-solid fa-message"></i>
                </span>
                <span class="ta" style="color:var(--sms)"><?= number_format($h['sms_credits']) ?> SMS</span>
              </span>
            </td>
            <td class="ta" style="color:var(--red)">KSh <?= number_format($h['amount'], 2) ?></td>
            <td style="color:var(--muted);font-size:.76rem"><?= htmlspecialchars($h['note'] ?? '—') ?></td>
            <td style="color:var(--muted);font-size:.72rem"><?= date('d M Y, H:i', strtotime($h['date'])) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>
  </div>

</div><!-- /sms-page -->

<!-- MOBILE NAV -->
<nav class="mob-nav">
  <a href="https://keychele.co.ke/msanii/index.php?page=dashboard.php" class="mn-item"><i class="fa-solid fa-house"></i>Home</a>
  <a href="#" class="mn-item"><i class="fa-solid fa-wallet"></i>Wallet</a>
  <a href="#" class="mn-fab"><i class="fa-solid fa-plus"></i></a>
  <a href="#" class="mn-item active"><i class="fa-solid fa-message"></i>SMS</a>
  <a href="https://keychele.co.ke/msanii/index.php?page=profile.php" class="mn-item"><i class="fa-solid fa-user"></i>Profile</a>
</nav>

<script>
const SMS_RATE = 0.50;
let selectedSms = 0;
let useCustom   = false;

function selectPkg(el) {
  document.querySelectorAll('.pkg').forEach(p => p.classList.remove('selected'));
  el.classList.add('selected');
  selectedSms = parseInt(el.dataset.sms);
  useCustom   = false;
  document.getElementById('customSms').value = '';
  document.getElementById('pkgSmsInput').value   = selectedSms;
  document.getElementById('customSmsInput').value = '';
  updateCostPreview(selectedSms, el.dataset.cost);
  updateBtn(selectedSms);
}

function onCustomInput(inp) {
  const val = parseInt(inp.value) || 0;
  document.querySelectorAll('.pkg').forEach(p => p.classList.remove('selected'));
  document.getElementById('pkgSmsInput').value    = '';
  document.getElementById('customSmsInput').value = val > 0 ? val : '';
  selectedSms = val;
  useCustom   = true;
  if (val >= 2) {
    const cost = (val * SMS_RATE).toFixed(2);
    updateCostPreview(val, cost);
    updateBtn(val);
  } else {
    const preview = document.getElementById('costPreview');
    preview.textContent = 'Minimum 2 credits';
    preview.classList.remove('active');
    updateBtn(0);
  }
}

function updateCostPreview(qty, cost) {
  const preview = document.getElementById('costPreview');
  preview.textContent = `${qty} SMS = KSh ${parseFloat(cost).toFixed(2)} deducted from your KSh balance`;
  preview.classList.add('active');
}

function updateBtn(qty) {
  const btn  = document.getElementById('buyBtn');
  const text = document.getElementById('buyBtnText');
  if (qty >= 2) {
    const cost = (qty * SMS_RATE).toFixed(2);
    btn.disabled  = false;
    text.textContent = `Buy ${qty} SMS Credits — KSh ${cost}`;
  } else {
    btn.disabled  = true;
    text.textContent = 'Select a package or enter custom amount';
  }
}

// Confirm before submit
document.getElementById('buyForm').addEventListener('submit', function(e) {
  if (selectedSms < 2) { e.preventDefault(); return; }
  const cost = (selectedSms * SMS_RATE).toFixed(2);
  if (!confirm(`Confirm purchase: ${selectedSms} SMS credits for KSh ${cost}?\n\nThis will be deducted from your account balance.`)) {
    e.preventDefault();
  }
});
</script>
</body>
</html>
