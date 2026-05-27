<?php
// ────────────────────────────────────────────────
// withdrawal2factorauth.php
// Creator sets / updates their 4-digit withdrawal PIN
// ────────────────────────────────────────────────
include 'includes/connect.php';

$creator_id = $_SESSION['creator_id'] ?? null;
if (!$creator_id) {
    die("Please login first.");
}

$success = $error = "";

// Ensure withdrawal_pin column exists (run once – safe to keep)
try {
    $pdo->exec("ALTER TABLE creators ADD COLUMN IF NOT EXISTS withdrawal_pin VARCHAR(255) NULL DEFAULT NULL");
} catch (PDOException $e) { /* column may already exist */ }

// Fetch current creator info
$creator = [];
try {
    $stmt = $pdo->prepare("SELECT full_name, email, withdrawal_pin FROM creators WHERE id = ?");
    $stmt->execute([$creator_id]);
    $creator = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = "Could not load your profile.";
}

$has_pin = !empty($creator['withdrawal_pin']);

// ────────────────────────────────────────────────
// FORM SUBMISSION – set / change PIN
// ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'set_pin') {
        $new_pin     = trim($_POST['new_pin']     ?? '');
        $confirm_pin = trim($_POST['confirm_pin'] ?? '');
        $old_pin     = trim($_POST['old_pin']     ?? '');

        if (!preg_match('/^\d{4,6}$/', $new_pin)) {
            $error = "PIN must be 4 – 6 digits.";
        } elseif ($new_pin !== $confirm_pin) {
            $error = "PINs do not match.";
        } elseif ($has_pin && !password_verify($old_pin, $creator['withdrawal_pin'])) {
            $error = "Current PIN is incorrect.";
        } else {
            $hashed = password_hash($new_pin, PASSWORD_BCRYPT);
            try {
                $stmt = $pdo->prepare("UPDATE creators SET withdrawal_pin = ? WHERE id = ?");
                $stmt->execute([$hashed, $creator_id]);
                // ── REDIRECT after successful PIN set/change ──────────────────
                header('Location: index.php?page=request_withdrawals');
                exit;
            } catch (PDOException $e) {
                $error = "Failed to save PIN: " . $e->getMessage();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Withdrawal 2FA — Keychele</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
  <style>
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
      --teal-bg:     #ccfbf1;
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

    /* ── Preloader ─────────────────────────────────────────────────────────── */
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

    /* ── Topbar ────────────────────────────────────────────────────────────── */
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
    .btn-crimson { background: var(--crimson); color: #fff; }
    .btn-crimson:hover { background: #9f0f2e; box-shadow: 0 4px 14px rgba(184,18,54,.3); transform: translateY(-1px); }
    .btn-outline { background: transparent; color: var(--navy); border: 1.5px solid var(--navy); }
    .btn-outline:hover { background: var(--navy); color: #fff; }

    /* ── Page Wrap ─────────────────────────────────────────────────────────── */
    .page-wrap { max-width: 680px; margin: 0 auto; padding: 24px 20px 96px; }
    @media (min-width: 768px) { .page-wrap { padding: 28px 28px 48px; } }

    /* ── Hero ──────────────────────────────────────────────────────────────── */
    .hero {
      position: relative; overflow: hidden;
      background: linear-gradient(130deg, var(--navy) 0%, #0a3d7a 45%, #7c3aed 100%);
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
    .hero-icon  { font-size: 3.5rem; color: rgba(255,255,255,.2); }

    /* ── Stats Grid ────────────────────────────────────────────────────────── */
    .stats-grid {
      display: grid; grid-template-columns: repeat(2, 1fr);
      gap: 14px; margin-bottom: 24px;
    }
    @media (min-width: 640px) { .stats-grid { grid-template-columns: repeat(3, 1fr); } }
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
    .stat-card.sc-green::before  { background: var(--green); }
    .stat-card.sc-amber::before  { background: var(--amber); }
    .stat-card.sc-purple::before { background: var(--purple); }
    .stat-label { font-size: .68rem; font-weight: 700; text-transform: uppercase; letter-spacing: .07em; color: var(--text-muted); margin-bottom: 8px; }
    .stat-value { font-size: 1.05rem; font-weight: 800; letter-spacing: -.02em; color: var(--text-pri); line-height: 1.2; font-family: 'Sora', sans-serif; }
    .stat-icon-chip {
      margin-top: 12px; width: 34px; height: 34px; border-radius: 9px;
      display: flex; align-items: center; justify-content: center; font-size: .85rem;
    }
    .stat-card.sc-green  .stat-icon-chip { background: var(--green-bg);  color: var(--green); }
    .stat-card.sc-amber  .stat-icon-chip { background: var(--amber-bg);  color: var(--amber); }
    .stat-card.sc-purple .stat-icon-chip { background: var(--purple-bg); color: var(--purple); }

    /* ── Alerts ────────────────────────────────────────────────────────────── */
    .alert {
      display: flex; align-items: flex-start; gap: 12px;
      padding: 14px 18px; border-radius: var(--radius-md);
      font-size: .83rem; margin-bottom: 20px; line-height: 1.5;
    }
    .alert i { font-size: 1rem; margin-top: 1px; flex-shrink: 0; }
    .alert-success { background: var(--green-bg); border: 1px solid #a7f3d0; color: #065f46; }
    .alert-success i { color: var(--green); }
    .alert-error   { background: var(--red-bg);   border: 1px solid #fecaca; color: #7f1d1d; }
    .alert-error i { color: var(--red); }
    .alert-warning { background: var(--amber-bg); border: 1px solid #fcd34d; color: #78350f; }
    .alert-warning i { color: var(--amber); }
    .alert-info    { background: var(--blue-bg);  border: 1px solid #bfdbfe; color: #1e3a8a; }
    .alert-info i  { color: var(--blue); }

    /* ── Panel ─────────────────────────────────────────────────────────────── */
    .panel {
      background: var(--surface); border-radius: var(--radius-lg);
      border: 1px solid var(--border); box-shadow: var(--shadow-md);
      overflow: hidden; margin-bottom: 24px;
    }
    .panel-header {
      padding: 20px 28px; border-bottom: 1px solid var(--border);
      background: #fafbfe; display: flex; align-items: center;
      justify-content: space-between; flex-wrap: wrap; gap: 14px;
    }
    .panel-title { font-size: 1rem; font-weight: 700; color: var(--text-pri); display: flex; align-items: center; gap: 10px; }
    .panel-title i { color: var(--navy); }
    .panel-body { padding: 24px 28px; }

    /* ── Status Banner ─────────────────────────────────────────────────────── */
    .status-banner {
      border-left: 4px solid; border-radius: 0 var(--radius-md) var(--radius-md) 0;
      padding: 16px 20px; margin-bottom: 22px;
      display: flex; align-items: flex-start; gap: 14px;
    }
    .status-banner.pin-set   { background: var(--green-bg);  border-color: var(--green); }
    .status-banner.pin-unset { background: var(--amber-bg);  border-color: var(--amber); }
    .status-banner-icon { font-size: 1.4rem; flex-shrink: 0; margin-top: 1px; }
    .status-banner.pin-set   .status-banner-icon { color: var(--green); }
    .status-banner.pin-unset .status-banner-icon { color: var(--amber); }
    .status-banner-title { font-size: .88rem; font-weight: 700; margin-bottom: 3px; }
    .status-banner.pin-set   .status-banner-title { color: #065f46; }
    .status-banner.pin-unset .status-banner-title { color: #78350f; }
    .status-banner-sub { font-size: .78rem; }
    .status-banner.pin-set   .status-banner-sub { color: #047857; }
    .status-banner.pin-unset .status-banner-sub { color: #92400e; }

    /* ── How it Works ──────────────────────────────────────────────────────── */
    .how-it-works {
      background: var(--blue-bg); border: 1px solid #bfdbfe;
      border-radius: var(--radius-md); padding: 16px 18px; margin-bottom: 22px;
    }
    .how-it-works-title { font-size: .78rem; font-weight: 700; color: #1e40af; margin-bottom: 10px; display: flex; align-items: center; gap: 7px; }
    .how-it-works ol { padding-left: 18px; }
    .how-it-works li { font-size: .78rem; color: #1e3a8a; line-height: 1.6; margin-bottom: 4px; }
    .how-it-works li:last-child { margin-bottom: 0; }

    /* ── Section Label ─────────────────────────────────────────────────────── */
    .section-label {
      font-size: .72rem; font-weight: 700; text-transform: uppercase;
      letter-spacing: .08em; color: var(--text-muted);
      margin: 0 0 14px; display: flex; align-items: center; gap: 8px;
    }
    .section-label::after { content: ''; flex: 1; height: 1px; background: var(--border); }

    /* ── Form Fields ───────────────────────────────────────────────────────── */
    .field { margin-bottom: 16px; }
    .field label {
      display: block; font-size: .7rem; font-weight: 700;
      text-transform: uppercase; letter-spacing: .07em;
      color: var(--text-muted); margin-bottom: 7px;
    }
    .field input {
      width: 100%; padding: 11px 14px;
      border: 1.5px solid var(--border); border-radius: var(--radius-sm);
      font-family: 'Sora', sans-serif; font-size: .85rem;
      color: var(--text-pri); background: var(--surface);
      outline: none; transition: border-color .2s, box-shadow .2s;
    }
    .field input:focus {
      border-color: var(--navy);
      box-shadow: 0 0 0 3px rgba(3,45,93,.08);
    }
    .field input.pin-input {
      letter-spacing: .5rem; font-size: 1.6rem; text-align: center;
      font-family: 'JetBrains Mono', monospace;
    }
    .field input::-webkit-outer-spin-button,
    .field input::-webkit-inner-spin-button { -webkit-appearance: none; }
    .field .field-hint { font-size: .7rem; color: var(--text-muted); margin-top: 5px; }

    /* ── Buttons ───────────────────────────────────────────────────────────── */
    .form-btn {
      display: inline-flex; align-items: center; justify-content: center; gap: 8px;
      padding: 12px 22px; border-radius: var(--radius-sm);
      font-family: 'Sora', sans-serif; font-size: .85rem; font-weight: 700;
      cursor: pointer; border: none; transition: var(--transition);
      width: 100%; letter-spacing: .01em;
    }
    .form-btn-navy   { background: var(--navy);    color: #fff; }
    .form-btn-navy:hover   { background: var(--navy-light); box-shadow: 0 4px 14px rgba(3,45,93,.3); transform: translateY(-1px); }
    .form-btn-purple { background: var(--purple);  color: #fff; }
    .form-btn-purple:hover { background: #6d28d9; box-shadow: 0 4px 14px rgba(124,58,237,.3); transform: translateY(-1px); }

    /* ── Bottom Nav ────────────────────────────────────────────────────────── */
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
    .nav-fab { display: flex; flex-direction: column; align-items: center; gap: 3px; text-decoration: none; font-size: .62rem; font-weight: 600; letter-spacing: .05em; }
    .nav-fab-icon { width: 46px; height: 46px; border-radius: 14px; margin-top: -18px; background: var(--navy); color: #fec411; display: flex; align-items: center; justify-content: center; font-size: 1.1rem; box-shadow: 0 4px 16px rgba(3,45,93,.35); }
    .nav-fab span { color: var(--navy); }

    /* ── Helpers ───────────────────────────────────────────────────────────── */
    .desk-only { display: none; } @media (min-width: 768px) { .desk-only { display: inline; } }
    @keyframes fadeUp { from { opacity: 0; transform: translateY(12px); } to { opacity: 1; transform: none; } }
    .stat-card { animation: fadeUp .4s ease both; }
    .stat-card:nth-child(1) { animation-delay: .05s; }
    .stat-card:nth-child(2) { animation-delay: .10s; }
    .stat-card:nth-child(3) { animation-delay: .15s; }
    .hero { animation: fadeUp .35s ease both; }
    .panel { animation: fadeUp .4s ease both; animation-delay: .1s; }
    .footer-note { text-align: center; font-size: .72rem; color: var(--text-muted); margin-top: 20px; }
    .footer-note i { margin-right: 4px; }
  </style>
</head>
<body>

<!-- Preloader -->
<div id="preloader">
  <div class="preloader-box">
    <div class="spin"></div>
    <span>Loading security settings…</span>
  </div>
</div>

<!-- Topbar -->
<div class="topbar">
  <a href="index.php?page=dashboard" class="topbar-brand">
    <span class="topbar-brand-icon"><i class="fas fa-ticket-alt"></i></span>
    Keychele
  </a>
  <div class="topbar-actions">
    <a href="index.php?page=request_withdrawals" class="action-btn btn-outline">
      <i class="fas fa-arrow-left"></i>
      <span>Back to Withdrawals</span>
    </a>
    <a href="index.php?page=my_wallet" class="action-btn btn-navy">
      <i class="fas fa-wallet"></i>
      <span class="desk-only">Wallet</span>
    </a>
    <a href="index.php?page=dashboard" class="action-btn btn-navy">
      <i class="fas fa-home"></i>
      <span class="desk-only">Dashboard</span>
    </a>
  </div>
</div>

<div class="page-wrap">

  <!-- Hero -->
  <div class="hero">
    <div class="hero-inner">
      <div>
        <div class="hero-eyebrow">
          <i class="fas fa-shield-halved" style="color:#fbbf24"></i>
          Security Settings
        </div>
        <div class="hero-title">Withdrawal 2-Factor Auth</div>
        <div class="hero-sub">Secure your withdrawals with a personal PIN + SMS code</div>
      </div>
      <div class="hero-icon desk-only">
        <i class="fas fa-shield-halved"></i>
      </div>
    </div>
  </div>

  <!-- Stats -->
  <div class="stats-grid">
    <div class="stat-card sc-<?= $has_pin ? 'green' : 'amber' ?>">
      <div class="stat-label">PIN Status</div>
      <div class="stat-value"><?= $has_pin ? 'Active ✓' : 'Not Set ✗' ?></div>
      <div class="stat-icon-chip"><i class="fas fa-<?= $has_pin ? 'lock' : 'lock-open' ?>"></i></div>
    </div>
    <div class="stat-card sc-purple">
      <div class="stat-label">2FA Method</div>
      <div class="stat-value" style="font-size:.85rem">PIN + SMS</div>
      <div class="stat-icon-chip"><i class="fas fa-mobile-screen-button"></i></div>
    </div>
    <div class="stat-card sc-<?= $has_pin ? 'green' : 'amber' ?>">
      <div class="stat-label">Withdrawals</div>
      <div class="stat-value" style="font-size:.85rem"><?= $has_pin ? 'Enabled' : 'Locked' ?></div>
      <div class="stat-icon-chip"><i class="fas fa-<?= $has_pin ? 'circle-check' : 'circle-xmark' ?>"></i></div>
    </div>
  </div>

  <!-- PHP Alerts -->
  <?php if ($error): ?>
  <div class="alert alert-error">
    <i class="fas fa-circle-exclamation"></i>
    <div><?= htmlspecialchars($error) ?></div>
  </div>
  <?php endif; ?>

  <!-- Status Banner -->
  <div class="status-banner <?= $has_pin ? 'pin-set' : 'pin-unset' ?>">
    <i class="fas fa-<?= $has_pin ? 'lock' : 'lock-open' ?> status-banner-icon"></i>
    <div>
      <div class="status-banner-title">
        <?= $has_pin ? 'PIN is SET — withdrawals are protected.' : 'No PIN set — you cannot withdraw until you set one.' ?>
      </div>
      <div class="status-banner-sub">
        <?= $has_pin ? 'You can update your PIN below at any time.' : 'Set a 4–6 digit PIN to enable withdrawals.' ?>
      </div>
    </div>
  </div>

  <!-- How it Works -->
  <div class="how-it-works">
    <div class="how-it-works-title">
      <i class="fas fa-circle-info"></i> How Withdrawal 2FA Works
    </div>
    <ol>
      <li>You set a secret PIN here — known only to you.</li>
      <li>When withdrawing, you enter your PIN to verify your identity.</li>
      <li>The system then sends a one-time <strong>MecroGuard Code</strong> to your registered phone via SMS.</li>
      <li>Both must be correct to proceed — double-layer protection on every withdrawal.</li>
    </ol>
  </div>

  <!-- PIN Form Panel -->
  <div class="panel">
    <div class="panel-header">
      <div class="panel-title">
        <i class="fas fa-key"></i>
        <?= $has_pin ? 'Change Withdrawal PIN' : 'Set Withdrawal PIN' ?>
      </div>
    </div>
    <div class="panel-body">

      <form method="POST" id="pinForm">
        <input type="hidden" name="action" value="set_pin">

        <?php if ($has_pin): ?>
        <div class="section-label">Current PIN</div>
        <div class="field">
          <label>Current PIN <span style="color:var(--crimson)">*</span></label>
          <input name="old_pin" type="password" inputmode="numeric"
                 maxlength="6" required placeholder="••••"
                 class="pin-input" autocomplete="current-password">
          <div class="field-hint"><i class="fas fa-info-circle" style="margin-right:4px"></i>Enter your existing PIN to verify it's you.</div>
        </div>
        <?php endif; ?>

        <div class="section-label"><?= $has_pin ? 'New PIN' : 'Create PIN' ?></div>
        <div class="field">
          <label>New PIN (4–6 digits) <span style="color:var(--crimson)">*</span></label>
          <input name="new_pin" type="password" inputmode="numeric"
                 maxlength="6" minlength="4" required placeholder="••••"
                 class="pin-input" autocomplete="new-password">
          <div class="field-hint"><i class="fas fa-info-circle" style="margin-right:4px"></i>Use 4 to 6 digits. Do not share this with anyone.</div>
        </div>
        <div class="field">
          <label>Confirm New PIN <span style="color:var(--crimson)">*</span></label>
          <input name="confirm_pin" type="password" inputmode="numeric"
                 maxlength="6" minlength="4" required placeholder="••••"
                 class="pin-input" autocomplete="new-password">
        </div>

        <button type="submit" class="form-btn form-btn-navy" style="margin-top:8px">
          <i class="fas fa-floppy-disk"></i>
          <?= $has_pin ? 'Update PIN' : 'Save PIN & Enable Withdrawals' ?>
        </button>
      </form>

    </div>
  </div>

  <p class="footer-note">
    <i class="fas fa-shield" style="color:var(--text-muted)"></i>
    PINs are encrypted with bcrypt and never stored in plain text.
  </p>

</div><!-- /page-wrap -->

<!-- Mobile Bottom Nav -->
<nav class="bottom-nav">
  <a href="index.php?page=dashboard"        class="nav-item"><i class="fas fa-home"></i>Home</a>
  <a href="index.php?page=ticket_purchases" class="nav-item"><i class="fas fa-ticket-alt"></i>Tickets</a>
  <a href="index.php?page=my_music"         class="nav-fab">
    <div class="nav-fab-icon"><i class="fas fa-music"></i></div><span>Music</span>
  </a>
  <a href="index.php?page=my_wallet"        class="nav-item active"><i class="fas fa-wallet"></i>Wallet</a>
  <a href="index.php?page=profile"          class="nav-item"><i class="fas fa-user"></i>Profile</a>
</nav>

<script>
window.addEventListener('load', () => {
  const p = document.getElementById('preloader');
  p.style.opacity = '0';
  setTimeout(() => p.style.display = 'none', 400);
});

// Only allow numeric input in PIN fields
document.querySelectorAll('.pin-input').forEach(el => {
  el.addEventListener('input', () => { el.value = el.value.replace(/\D/g, ''); });
});
</script>
</body>
</html>