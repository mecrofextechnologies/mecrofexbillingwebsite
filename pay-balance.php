<?php
session_start();
include 'msanii/includes/connect.php';
require_once 'msanii/includes/deposit_schema.php';
kc_ensure_deposit_purchases_table($pdo);

if (empty($_SESSION['fan_id'])) {
    header("Location: /account-login.php?redirect=" . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

// ─────────────────────────────────────────────────────────────
// MPESA — Paybill STK push via the shared Daraja client (same config as
// ticket.php / deposit-parttime-payment.php; credentials never live in
// this file — see msanii/includes/daraja.php).
// ─────────────────────────────────────────────────────────────
require_once 'msanii/includes/daraja.php';

// AccountReference on the customer's prompt = the event creator's username.
function balSTKPush($phone, $amount, $reference, $desc, $creator_username = null) {
    global $pdo;
    $res = kc_daraja_stk_push(
        $pdo, $phone, (int)$amount,
        kc_daraja_account_reference($creator_username, $reference),
        $desc,
        'balance_callback.php'
    );
    return $res['ok'] ? $res['checkout_request_id'] : false;
}

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES); }

// ─────────────────────────────────────────────────────────────
// LOAD THE DEPOSIT ROW
// ─────────────────────────────────────────────────────────────
$ref = trim($_GET['ref'] ?? '');
if ($ref === '') { header('Location: /account-dashboard.php'); exit; }

$stmt = $pdo->prepare("
    SELECT dp.*, ett.type_name, t.title, t.start_date, t.start_time, t.venue, t.banner,
           c.username AS creator_username
    FROM deposit_purchases dp
    JOIN event_ticket_types ett ON dp.ticket_type_id = ett.id
    JOIN tickets t              ON dp.event_id = t.id
    LEFT JOIN creators c        ON t.creator_id = c.id
    WHERE dp.deposit_ref = ?
");
$stmt->execute([$ref]);
$dep = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$dep) {
    $notFound = true;
} else {
    $dep = kc_decrypt_row($dep, ['buyer_name', 'buyer_email', 'buyer_phone']);

    // Ownership: fan_id match for rows created after this feature shipped,
    // or an email match for any legacy row that predates fan_id linkage —
    // same trust model account-dashboard.php already uses for ticket_purchases.
    $ownedByFan   = !empty($dep['fan_id']) && (int)$dep['fan_id'] === (int)$_SESSION['fan_id'];
    $ownedByEmail = empty($dep['fan_id']) && !empty($dep['buyer_email_bidx'])
        && hash_equals($dep['buyer_email_bidx'], kc_email_index($_SESSION['fan_email']));
    $notFound = !$ownedByFan && !$ownedByEmail;
}

$banner_url = $notFound ? '' : 'msanii/uploads/banners/' . htmlspecialchars($dep['banner'] ?: 'default-event.jpg');

$deposit_confirmed = !$notFound && $dep['status'] === 'completed';
$already_paid       = !$notFound && $dep['balance_status'] === 'completed';

$just_paid = $waiting = false;
$error = '';

if (!$notFound && isset($_SESSION['pending_balance_checkout'])
    && $_SESSION['pending_balance_checkout'] === $dep['balance_checkout_request_id']) {
    if ($dep['balance_status'] === 'completed') {
        $just_paid = true;
        unset($_SESSION['pending_balance_checkout'], $_SESSION['pending_balance_dep_ref']);
    } elseif ($dep['balance_status'] === 'failed') {
        $error = 'Payment was not completed. Please try again.';
        unset($_SESSION['pending_balance_checkout'], $_SESSION['pending_balance_dep_ref']);
    } else {
        $waiting = true;
    }
}

// ─────────────────────────────────────────────────────────────
// HANDLE FORM POST
// ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $deposit_confirmed && !$already_paid && !$just_paid && !$waiting) {
    $phone_raw = preg_replace('/\D/', '', $_POST['phone'] ?? '');

    $phone = $phone_raw;
    if (strlen($phone_raw) === 10 && in_array(substr($phone_raw, 0, 2), ['07', '01']))
        $phone = '254' . substr($phone_raw, 1);
    elseif (strlen($phone_raw) === 9 && in_array(substr($phone_raw, 0, 1), ['7', '1']))
        $phone = '254' . $phone_raw;
    elseif (strlen($phone_raw) === 12 && substr($phone_raw, 0, 3) === '254')
        $phone = $phone_raw;
    elseif (strlen($phone_raw) === 13 && substr($phone_raw, 0, 4) === '+254')
        $phone = substr($phone_raw, 1);

    if (!preg_match('/^254[17][0-9]{8}$/', $phone)) {
        $error = 'Phone must be a valid Kenyan number (e.g. 0712345678).';
    } else {
        $checkout_id = balSTKPush(
            $phone,
            (int)$dep['balance_due'],
            $dep['deposit_ref'],
            'Balance: ' . mb_strimwidth($dep['type_name'], 0, 20) . ' - ' . $dep['deposit_ref'],
            $dep['creator_username'] ?? null
        );

        if ($checkout_id) {
            $pdo->prepare("UPDATE deposit_purchases SET balance_checkout_request_id = ?, updated_at = NOW() WHERE id = ?")
                ->execute([$checkout_id, $dep['id']]);
            $_SESSION['pending_balance_checkout'] = $checkout_id;
            $_SESSION['pending_balance_dep_ref']  = $dep['deposit_ref'];
            header('Location: /pay-balance?ref=' . urlencode($ref));
            exit;
        } else {
            $error = 'Could not initiate M-Pesa payment. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1.0"/>
<title>Pay Balance — <?= h($dep['title'] ?? 'Keychele') ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>

<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
img { display: block; max-width: 100%; }

:root {
    --navy:      #052e64; --navy-d: #031e44; --navy-m: #0a4a99; --navy-l: #e8eef8; --navy-xl: #f2f5fc;
    --gold:      #fec411; --gold-d: #d9a70a; --gold-l: #fffbea;
    --crimson:   #bf1339; --crimson-d: #96102d; --crimson-l: #fce8ec;
    --teal:      #0891b2; --teal-d: #0e7490; --teal-l: #e0f7fc;
    --amber:     #d97706; --amber-l: #fffbeb;
    --green:     #16a34a;
    --white:     #ffffff;
    --g50:       #f8f9fd; --g100: #eef1f9; --g200: #dce2f0; --g400: #8a97b8; --g600: #4a5578; --g800: #1a2340;
    --fh: 'Montserrat', system-ui, sans-serif;
    --fm: 'Courier New', monospace;
}

html { scroll-behavior: smooth; }
body { font-family: var(--fh); background: var(--navy-d); color: var(--g600); min-height: 100vh; -webkit-font-smoothing: antialiased; }

.bg-scene { position: fixed; inset: 0; z-index: 0; background-image: url('/<?= $banner_url ?>'); background-size: cover; background-position: center; filter: blur(24px) brightness(0.12) saturate(0.35); transform: scale(1.06); }
.bg-veil { position: fixed; inset: 0; z-index: 1; background: linear-gradient(150deg, rgba(5,46,100,.7) 0%, rgba(5,46,100,.2) 40%, rgba(3,15,35,.95) 100%); }

.topnav { position: relative; z-index: 30; height: 62px; display: flex; align-items: center; justify-content: space-between; padding: 0 32px; background: rgba(5,46,100,.97); border-bottom: 3px solid var(--gold); }
.nb { font-weight: 900; font-size: 21px; letter-spacing: 3px; color: #fff; text-decoration: none; }
.nb span { color: var(--gold); }
.bc { display: flex; align-items: center; gap: 6px; font-size: 12px; color: rgba(255,255,255,.4); }
.bc a { color: rgba(255,255,255,.55); text-decoration: none; transition: color .2s; }
.bc a:hover { color: var(--gold); }
.bc .s { color: rgba(255,255,255,.2); }

.mw { position: relative; z-index: 5; max-width: 620px; margin: 0 auto; padding: 40px 20px 90px; }

.pg-hero { background: linear-gradient(120deg, #031e44 0%, #0a3a7a 60%, #0e7490 100%); border-radius: 20px; padding: 2rem 2.25rem; margin-bottom: 2rem; border: 1px solid rgba(255,255,255,0.07); box-shadow: 0 16px 50px rgba(0,0,0,.4); position: relative; overflow: hidden; }
.pg-hero::before { content: ''; position: absolute; inset: 0; pointer-events: none; background-image: linear-gradient(rgba(255,255,255,.025) 1px, transparent 1px), linear-gradient(90deg, rgba(255,255,255,.025) 1px, transparent 1px); background-size: 30px 30px; }
.pg-hero-inner { position: relative; z-index: 1; }
.pg-hero-tag { display: inline-flex; align-items: center; gap: 6px; background: rgba(217,119,6,.18); border: 1px solid rgba(217,119,6,.35); border-radius: 100px; padding: 4px 12px; font-size: 0.64rem; font-weight: 700; letter-spacing: 0.1em; text-transform: uppercase; color: #fcd34d; margin-bottom: 0.65rem; }
.pg-hero-title { font-weight: 900; font-size: clamp(1.4rem, 3vw, 1.9rem); color: #fff; letter-spacing: -0.03em; line-height: 1.2; margin-bottom: 0.35rem; }
.pg-hero-sub { font-size: 0.82rem; color: rgba(255,255,255,.5); }

.card { background: #fff; border-radius: 18px; overflow: hidden; box-shadow: 0 10px 40px rgba(5,46,100,.22); margin-bottom: 20px; }
.card-head { padding: 1.1rem 1.5rem 0.9rem; border-bottom: 1px solid var(--g100); background: #fafbfd; display: flex; align-items: center; gap: 12px; }
.card-head-ico { width: 38px; height: 38px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 0.9rem; flex-shrink: 0; background: var(--amber-l); color: var(--amber); }
.card-head-title { font-weight: 800; font-size: 0.92rem; color: var(--navy); }
.card-head-sub { font-size: 0.73rem; color: var(--g400); margin-top: 2px; }
.card-body { padding: 1.5rem; }

.event-strip { background: var(--g50); border: 1px solid var(--g100); border-radius: 12px; padding: 1rem 1.1rem; display: flex; gap: 12px; align-items: center; margin-bottom: 1.25rem; }
.es-thumb { width: 52px; height: 52px; border-radius: 10px; object-fit: cover; flex-shrink: 0; border: 1px solid var(--g100); }
.es-title { font-weight: 800; font-size: 0.9rem; color: var(--navy); line-height: 1.3; }
.es-meta { font-size: 0.72rem; color: var(--g400); margin-top: 3px; }
.es-meta i { color: var(--crimson); font-size: 0.65rem; margin-right: 3px; }

.type-badge { display: inline-flex; align-items: center; gap: 8px; background: var(--teal-l); border: 1.5px solid rgba(8,145,178,.3); border-radius: 10px; padding: 8px 14px; margin-bottom: 1.25rem; font-weight: 700; font-size: 0.82rem; color: var(--teal-d); }
.type-badge i { font-size: 0.8rem; }

.live-total { background: linear-gradient(135deg, var(--navy-xl), #eef4ff); border: 1.5px solid rgba(217,119,6,.25); border-radius: 12px; padding: 1rem 1.1rem; margin-bottom: 1.25rem; }
.lt-row { display: flex; align-items: center; justify-content: space-between; gap: 1rem; margin-bottom: 0.55rem; }
.lt-row:last-child { margin-bottom: 0; padding-top: 0.55rem; border-top: 1.5px dashed var(--g200); }
.lt-label { font-size: 0.78rem; color: var(--g400); display: flex; align-items: center; gap: 6px; }
.lt-label i { font-size: 0.72rem; width: 14px; color: var(--amber); }
.lt-val { font-weight: 800; font-size: 0.92rem; color: var(--navy); }
.lt-val.amber { color: var(--amber); }
.lt-val.big { font-size: 1.2rem; }

.fg { margin-bottom: 1.1rem; }
.fl { display: block; font-weight: 700; font-size: 0.75rem; color: var(--navy); margin-bottom: 0.45rem; }
.fi { width: 100%; padding: 11px 14px 11px 42px; border: 1.5px solid var(--g200); border-radius: 10px; font-family: var(--fh); font-size: 0.88rem; color: var(--navy); transition: border-color .2s, box-shadow .2s; background: #fafafa; }
.fi:focus { border-color: var(--teal); background: #fff; box-shadow: 0 0 0 3px rgba(8,145,178,.08); outline: none; }
.fi-wrap { position: relative; }
.fi-ico { position: absolute; left: 13px; top: 50%; transform: translateY(-50%); font-size: 0.8rem; color: var(--g400); pointer-events: none; }
.fh-txt { font-size: 0.7rem; color: var(--g400); margin-top: 4px; }

.dep-btn { width: 100%; padding: 15px; border-radius: 12px; border: none; background: linear-gradient(135deg, var(--amber), #f59e0b); color: #fff; font-family: var(--fh); font-weight: 800; font-size: 15px; cursor: pointer; transition: all .3s; display: flex; align-items: center; justify-content: center; gap: 10px; box-shadow: 0 6px 20px rgba(217,119,6,.35); }
.dep-btn:hover:not(:disabled) { background: linear-gradient(135deg, #b45309, var(--amber)); transform: translateY(-2px); box-shadow: 0 10px 30px rgba(217,119,6,.45); }
.dep-btn:disabled { background: var(--g200); color: var(--g400); cursor: not-allowed; box-shadow: none; }

.sec-note { display: flex; align-items: center; justify-content: center; gap: 7px; margin-top: 14px; font-size: 12px; color: var(--g400); }
.mpill { background: #00be37; color: #fff; padding: 2px 8px; border-radius: 4px; font-weight: 900; font-size: 10px; }

.ea { background: var(--crimson-l); border: 1px solid rgba(191,19,57,.2); border-radius: 10px; padding: 12px 15px; margin-top: 18px; display: flex; align-items: flex-start; gap: 10px; }
.ea i { color: var(--crimson); font-size: 14px; margin-top: 2px; flex-shrink: 0; }
.ea p { font-size: 12.5px; color: var(--crimson-d); line-height: 1.5; }

.sp { position: relative; z-index: 10; min-height: 82vh; display: flex; align-items: center; justify-content: center; padding: 20px; }
.sc { background: #fff; border-radius: 24px; padding: 45px 35px; text-align: center; box-shadow: 0 25px 70px rgba(0,0,0,.4); width: 100%; max-width: 520px; }
.si { width: 85px; height: 85px; border-radius: 50%; margin: 0 auto 24px; display: flex; align-items: center; justify-content: center; font-size: 30px; }
.si-ok { background: #dcfce7; color: var(--green); }
.si-wt { background: var(--navy-l); color: var(--navy); }
.si-no { background: var(--crimson-l); color: var(--crimson); }
.stit { font-weight: 900; font-size: 26px; color: var(--navy); margin-bottom: 10px; }
.ssub { font-size: 14px; color: var(--g600); line-height: 1.65; }

.mps { margin: 22px 0; text-align: left; background: var(--g50); border-radius: 14px; padding: 18px; }
.mps-row { display: flex; align-items: center; gap: 14px; margin-bottom: 16px; opacity: .4; }
.mps-row:last-child { margin-bottom: 0; }
.mps-row.mdon, .mps-row.mact { opacity: 1; }
.mico { width: 30px; height: 30px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 11px; flex-shrink: 0; }
.mico-d { background: var(--green); color: #fff; }
.mico-a { background: var(--teal); color: #fff; animation: spin 2s linear infinite; }
.mico-w { background: var(--g200); color: var(--g400); }
@keyframes spin { 100% { transform: rotate(360deg); } }
.mlbl { font-size: 13px; font-weight: 500; color: var(--g400); }
.mla { color: var(--teal); font-weight: 700; }
.mld { color: var(--green); font-weight: 700; }
.cb-wrap { height: 6px; background: var(--g100); border-radius: 3px; overflow: hidden; margin-bottom: 9px; }
.cb { height: 100%; background: var(--teal); transition: width 1s linear; }
.cb-txt { font-size: 12px; color: var(--g400); }

.dep-ref-badge { display: inline-block; background: var(--teal-l); color: var(--teal-d); font-family: var(--fm); font-weight: 700; font-size: 17px; letter-spacing: 2px; padding: 11px 22px; border-radius: 10px; border: 2px dashed rgba(8,145,178,.4); margin: 20px 0; }
.suc-actions { display: flex; flex-direction: column; gap: 11px; margin-top: 24px; }
.sac-btn { display: flex; align-items: center; justify-content: center; gap: 9px; padding: 13px; border-radius: 11px; text-decoration: none; font-weight: 700; font-size: 13px; transition: all .2s; }
.sac-teal { background: var(--teal); color: #fff; }
.sac-teal:hover { background: var(--teal-d); transform: translateY(-2px); }
.sac-home { background: var(--g100); color: var(--g600); }
.sac-home:hover { background: var(--g200); }

@media (max-width: 600px) {
    .topnav { padding: 0 16px; }
    .mw { padding: 22px 14px 70px; }
    .pg-hero { padding: 1.4rem 1.25rem; }
    .card-body { padding: 1.1rem; }
}
</style>
</head>
<body>

<div class="bg-scene"></div>
<div class="bg-veil"></div>

<nav class="topnav">
    <a href="/" class="nb">KEY<span>CHELE</span></a>
    <div class="bc">
        <a href="/">Home</a><span class="s">›</span>
        <a href="/account-dashboard.php">My Account</a><span class="s">›</span>
        <span>Pay Balance</span>
    </div>
</nav>

<?php if ($notFound): ?>
<!-- ══ NOT FOUND / NOT YOURS ══ -->
<div class="sp">
    <div class="sc">
        <div class="si si-no"><i class="fas fa-question"></i></div>
        <h1 class="stit">Deposit Not Found</h1>
        <p class="ssub">We couldn't find a deposit matching this reference on your account.</p>
        <div class="suc-actions">
            <a href="/account-dashboard.php" class="sac-btn sac-teal"><i class="fas fa-user"></i> Go to My Account</a>
        </div>
    </div>
</div>

<?php elseif ($just_paid): ?>
<!-- ══ SUCCESS ══ -->
<div class="sp">
    <div class="sc">
        <div class="si si-ok"><i class="fas fa-check"></i></div>
        <h1 class="stit">Balance Paid! 🎉</h1>
        <p class="ssub">
            Your remaining balance for <strong style="color:var(--navy);"><?= h($dep['title']) ?></strong>
            has been received — you're fully paid up.
        </p>
        <div class="dep-ref-badge"><?= h($dep['deposit_ref']) ?></div>
        <p style="font-size:13px;color:var(--g400);margin-bottom:0;">
            <i class="fas fa-envelope" style="color:var(--teal);margin-right:5px;"></i>
            Your ticket PDF has been emailed to <strong style="color:var(--navy);"><?= h($dep['buyer_email']) ?></strong>
        </p>
        <div class="suc-actions">
            <a href="/account-dashboard.php" class="sac-btn sac-teal"><i class="fas fa-ticket-alt"></i> View My Tickets</a>
            <a href="/" class="sac-btn sac-home"><i class="fas fa-home"></i> Back to Keychele</a>
        </div>
    </div>
</div>

<?php elseif ($already_paid): ?>
<!-- ══ ALREADY FULLY PAID (returning later) ══ -->
<div class="sp">
    <div class="sc">
        <div class="si si-ok"><i class="fas fa-check-double"></i></div>
        <h1 class="stit">Already Fully Paid</h1>
        <p class="ssub">This deposit's balance was already paid — your ticket was emailed to you.</p>
        <div class="suc-actions">
            <a href="/account-dashboard.php" class="sac-btn sac-teal"><i class="fas fa-ticket-alt"></i> View My Tickets</a>
        </div>
    </div>
</div>

<?php elseif (!$deposit_confirmed): ?>
<!-- ══ DEPOSIT NOT YET CONFIRMED ══ -->
<div class="sp">
    <div class="sc">
        <div class="si si-wt"><i class="fas fa-hourglass-half"></i></div>
        <h1 class="stit">Deposit Still Pending</h1>
        <p class="ssub">Your original deposit for <strong style="color:var(--navy);"><?= h($dep['title']) ?></strong> hasn't been confirmed yet. Check back shortly, or contact support if this doesn't resolve.</p>
        <div class="suc-actions">
            <a href="/account-dashboard.php" class="sac-btn sac-teal"><i class="fas fa-user"></i> Go to My Account</a>
        </div>
    </div>
</div>

<?php elseif ($waiting): ?>
<!-- ══ MPESA WAITING ══ -->
<div class="sp">
    <div class="sc" style="max-width:460px;">
        <div class="si si-wt" style="width:76px;height:76px;font-size:26px;">
            <i class="fas fa-mobile-alt"></i>
        </div>
        <h1 class="stit" style="font-size:24px;">Check Your Phone</h1>
        <p class="ssub">An M-Pesa prompt has been sent. Enter your <strong style="color:var(--navy);">M-Pesa PIN</strong> to pay the balance.</p>

        <div class="mps">
            <div class="mps-row mdon">
                <div class="mico mico-d"><i class="fas fa-check"></i></div>
                <span class="mlbl mld">Balance request sent to your phone</span>
            </div>
            <div class="mps-row mact" id="st2">
                <div class="mico mico-a" id="si2"><i class="fas fa-sync-alt"></i></div>
                <span class="mlbl mla" id="sl2">Waiting for M-Pesa PIN confirmation…</span>
            </div>
            <div class="mps-row" id="st3">
                <div class="mico mico-w" id="si3"><i class="fas fa-envelope"></i></div>
                <span class="mlbl" id="sl3">Ticket PDF will be emailed to you</span>
            </div>
        </div>

        <div class="cb-wrap"><div class="cb" id="cbar" style="width:100%;"></div></div>
        <p class="cb-txt">Timeout in <strong id="countdown">40</strong>s — do not close this page</p>
    </div>
</div>
<script>
const TOT = 40; let secs = TOT;
const cdEl = document.getElementById('countdown');
const cb   = document.getElementById('cbar');
const tmr  = setInterval(() => {
    secs--; cdEl.textContent = secs;
    cb.style.width = (secs / TOT * 100) + '%';
    if (secs <= 10) cb.style.background = 'var(--crimson)';
    if (secs <= 0) {
        clearInterval(tmr); clearInterval(pol);
        document.getElementById('sl2').textContent = 'Timed out — redirecting…';
        setTimeout(() => location.href = '/pay-balance?ref=<?= urlencode($ref) ?>', 2000);
    }
}, 1000);
function setDone(siId, slId, text) {
    document.getElementById(siId).parentElement.className = 'mps-row mdon';
    const ico = document.getElementById(siId); ico.className = 'mico mico-d'; ico.innerHTML = '<i class="fas fa-check"></i>';
    const lbl = document.getElementById(slId); lbl.className = 'mlbl mld'; lbl.textContent = text;
}
function setAct(siId, slId, text) {
    document.getElementById(siId).parentElement.className = 'mps-row mact';
    const ico = document.getElementById(siId); ico.className = 'mico mico-a'; ico.innerHTML = '<i class="fas fa-sync-alt"></i>';
    const lbl = document.getElementById(slId); lbl.className = 'mlbl mla'; lbl.textContent = text;
}
function chk() {
    fetch('/check_deposit_payment.php').then(r => r.json()).then(d => {
        if (d.mode !== 'balance') return;
        if (d.success && d.status === 'completed') {
            clearInterval(pol); clearInterval(tmr);
            setDone('si2','sl2','Balance confirmed!');
            setAct('si3','sl3','Sending your ticket…');
            cb.style.width = '100%'; cb.style.background = 'var(--green)';
            setTimeout(() => location.reload(), 2400);
        } else if (d.status === 'failed') {
            clearInterval(pol); clearInterval(tmr);
            setDone('si2','sl2','Payment not completed.');
            setTimeout(() => location.href = '/pay-balance?ref=<?= urlencode($ref) ?>', 1600);
        }
    }).catch(() => {});
}
const pol = setInterval(chk, 4000); chk();
</script>

<?php else: ?>
<!-- ══ PAYMENT FORM ══ -->
<div class="mw">
    <div class="pg-hero">
        <div class="pg-hero-inner">
            <div class="pg-hero-tag"><i class="fas fa-clock"></i> Balance Due</div>
            <div class="pg-hero-title">Pay Your Remaining Balance</div>
            <div class="pg-hero-sub">Complete your payment to receive your ticket PDF</div>
        </div>
    </div>

    <div class="card">
        <div class="card-head">
            <div class="card-head-ico"><i class="fas fa-file-invoice-dollar"></i></div>
            <div>
                <div class="card-head-title">Balance Payment</div>
                <div class="card-head-sub">Ref: <?= h($dep['deposit_ref']) ?></div>
            </div>
        </div>
        <div class="card-body">

            <div class="event-strip">
                <img class="es-thumb" src="/<?= $banner_url ?>" alt="">
                <div>
                    <div class="es-title"><?= h($dep['title']) ?></div>
                    <div class="es-meta">
                        <i class="fas fa-calendar"></i> <?= date('D, d M Y', strtotime($dep['start_date'])) ?>
                        &nbsp;·&nbsp; <?= h($dep['venue'] ?? 'TBA') ?>
                    </div>
                </div>
            </div>

            <div class="type-badge"><i class="fas fa-ticket-alt"></i> <?= h($dep['type_name']) ?> × <?= (int)$dep['quantity'] ?></div>

            <div class="live-total">
                <div class="lt-row">
                    <span class="lt-label"><i class="fas fa-check-circle"></i> Deposit already paid</span>
                    <span class="lt-val">KES <?= number_format((float)$dep['deposit_amount'], 2) ?></span>
                </div>
                <div class="lt-row">
                    <span class="lt-label"><i class="fas fa-hourglass-half"></i> Balance due now</span>
                    <span class="lt-val amber big">KES <?= number_format((float)$dep['balance_due'], 2) ?></span>
                </div>
            </div>

            <form method="POST">
                <div class="fg">
                    <label class="fl">M-Pesa Phone Number</label>
                    <div class="fi-wrap">
                        <i class="fas fa-phone fi-ico"></i>
                        <input type="tel" name="phone" class="fi" placeholder="0712 345 678" required autocomplete="tel"
                               value="<?= h($_POST['phone'] ?? $dep['buyer_phone']) ?>">
                    </div>
                    <div class="fh-txt">Kenyan numbers only (07XX or 01XX) · STK push will be sent here</div>
                </div>

                <button type="submit" class="dep-btn">
                    <i class="fas fa-mobile-alt"></i> Pay KES <?= number_format((float)$dep['balance_due'], 2) ?> Now
                </button>
            </form>

            <?php if ($error): ?>
            <div class="ea"><i class="fas fa-circle-exclamation"></i><p><?= h($error) ?></p></div>
            <?php endif; ?>

            <div class="sec-note"><span class="mpill">M-PESA</span> Secured by Safaricom Daraja</div>
        </div>
    </div>
</div>
<?php endif; ?>

</body>
</html>
