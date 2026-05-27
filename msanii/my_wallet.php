<?php
if (session_status() === PHP_SESSION_NONE) session_start();

// Optional: countdown target date — change as needed
$launch_date = '2025-09-01 00:00:00';
$launch_ts   = strtotime($launch_date);
$now_ts      = time();
$diff        = max(0, $launch_ts - $now_ts);

$days    = floor($diff / 86400);
$hours   = floor(($diff % 86400) / 3600);
$minutes = floor(($diff % 3600) / 60);
$seconds = $diff % 60;

// Feature list — edit freely
$features = [
    ['icon' => 'fa-qrcode',              'title' => 'QR Check-In',          'desc' => 'Instant attendee check-in with QR code scanning at the door.'],
    ['icon' => 'fa-chart-line',          'title' => 'Live Analytics',        'desc' => 'Real-time sales dashboards, revenue tracking, and attendance charts.'],
    ['icon' => 'fa-envelope-open-text',  'title' => 'Smart Reminders',       'desc' => 'Automated email & SMS reminders to attendees before your event.'],
    ['icon' => 'fa-images',              'title' => 'Photo Sharing',         'desc' => 'Send event photos directly to all ticket holders after the event.'],
    ['icon' => 'fa-shield-halved',       'title' => 'Privacy Controls',      'desc' => 'CA-compliant data masking and buyer privacy protection built-in.'],
    ['icon' => 'fa-file-excel',          'title' => 'Export & Reports',      'desc' => 'One-click Excel exports of all ticket data and transaction history.'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Coming Soon | Keychele</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
  <style>
    /* ── Design Tokens (matches full system) ── */
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
      --gold:        #fec411;
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

    /* ── Preloader ── */
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
      animation: spin .75s linear infinite;
    }
    @keyframes spin { to { transform: rotate(360deg); } }
    .preloader-box span { font-size: .9rem; font-weight: 600; color: var(--text-sec); }

    /* ── Topbar ── */
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
    .back-btn {
      display: inline-flex; align-items: center; gap: 8px;
      color: var(--text-sec); text-decoration: none; font-size: .85rem; font-weight: 600;
      transition: var(--transition);
    }
    .back-btn:hover { color: var(--navy); }
    .back-icon {
      width: 34px; height: 34px; border-radius: 10px;
      background: #f1f5f9; display: flex; align-items: center; justify-content: center;
      font-size: .75rem; transition: var(--transition);
    }
    .back-btn:hover .back-icon { background: #e2e8f0; }
    .topbar-brand {
      display: flex; align-items: center; gap: 10px;
      font-size: .95rem; font-weight: 800; color: var(--navy); letter-spacing: -.01em;
    }
    .topbar-brand-dot {
      width: 8px; height: 8px; border-radius: 50%;
      background: var(--crimson); display: inline-block;
    }

    /* ── Page ── */
    .page-wrap { max-width: 1100px; margin: 0 auto; padding: 32px 20px 80px; }
    @media (min-width: 768px) { .page-wrap { padding: 40px 28px 60px; } }

    /* ── Hero (same gradient + dot pattern as other pages) ── */
    .hero {
      position: relative; overflow: hidden;
      background: linear-gradient(130deg, var(--navy) 0%, #0a3d7a 45%, var(--crimson) 100%);
      border-radius: var(--radius-lg); padding: 52px 40px 44px;
      margin-bottom: 32px; box-shadow: var(--shadow-lg);
      text-align: center;
    }
    .hero::before {
      content: '';
      position: absolute; inset: 0;
      background: url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%23ffffff' fill-opacity='0.04'%3E%3Ccircle cx='30' cy='30' r='20'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E") repeat;
      pointer-events: none;
    }
    /* Animated orbiting ring */
    .hero::after {
      content: '';
      position: absolute;
      width: 500px; height: 500px;
      border-radius: 50%;
      border: 1.5px solid rgba(255,255,255,.07);
      top: 50%; left: 50%;
      transform: translate(-50%, -50%);
      animation: orbit 18s linear infinite;
      pointer-events: none;
    }
    @keyframes orbit {
      from { transform: translate(-50%, -50%) rotate(0deg); }
      to   { transform: translate(-50%, -50%) rotate(360deg); }
    }

    .hero-inner { position: relative; z-index: 1; }

    .hero-eyebrow {
      display: inline-flex; align-items: center; gap: 8px;
      background: rgba(255,255,255,.12); border: 1px solid rgba(255,255,255,.2);
      backdrop-filter: blur(8px); border-radius: 999px;
      padding: 5px 18px; font-size: .72rem; font-weight: 700;
      color: #fff; letter-spacing: .1em; text-transform: uppercase;
      margin-bottom: 20px;
      animation: fadeUp .5s ease both;
    }
    .hero-eyebrow-dot {
      width: 7px; height: 7px; border-radius: 50%;
      background: var(--gold);
      box-shadow: 0 0 0 3px rgba(254,196,17,.25);
      animation: pulse 2s ease-in-out infinite;
    }
    @keyframes pulse {
      0%, 100% { box-shadow: 0 0 0 3px rgba(254,196,17,.25); }
      50%       { box-shadow: 0 0 0 6px rgba(254,196,17,.1); }
    }

    .hero-title {
      font-size: clamp(2rem, 6vw, 3.5rem);
      font-weight: 800; color: #fff;
      letter-spacing: -.03em; line-height: 1.1;
      margin-bottom: 16px;
      animation: fadeUp .5s .1s ease both;
    }
    .hero-title span {
      color: var(--gold);
      position: relative;
      display: inline-block;
    }
    /* Underline shimmer on the accent word */
    .hero-title span::after {
      content: '';
      position: absolute; left: 0; bottom: -4px; right: 0; height: 3px;
      background: linear-gradient(90deg, var(--gold), transparent);
      border-radius: 2px;
    }

    .hero-sub {
      font-size: clamp(.9rem, 2vw, 1.1rem);
      color: rgba(255,255,255,.72);
      max-width: 560px; margin: 0 auto 36px;
      line-height: 1.65;
      animation: fadeUp .5s .2s ease both;
    }

    /* ── Countdown ── */
    .countdown {
      display: flex; align-items: flex-start; justify-content: center;
      gap: 12px; flex-wrap: wrap;
      animation: fadeUp .5s .3s ease both;
    }
    .cd-unit {
      background: rgba(255,255,255,.1);
      border: 1px solid rgba(255,255,255,.18);
      backdrop-filter: blur(10px);
      border-radius: var(--radius-md);
      padding: 16px 22px; min-width: 84px;
      text-align: center;
      transition: var(--transition);
    }
    .cd-unit:hover { background: rgba(255,255,255,.16); transform: translateY(-3px); }
    .cd-val {
      font-family: 'JetBrains Mono', monospace;
      font-size: 2.2rem; font-weight: 700; color: #fff;
      line-height: 1; display: block;
    }
    .cd-val.gold { color: var(--gold); }
    .cd-label {
      font-size: .62rem; font-weight: 700; text-transform: uppercase;
      letter-spacing: .1em; color: rgba(255,255,255,.5);
      display: block; margin-top: 6px;
    }
    .cd-sep {
      font-family: 'JetBrains Mono', monospace;
      font-size: 2rem; color: rgba(255,255,255,.3);
      font-weight: 700; align-self: center;
      margin-top: -10px;
    }

    /* ── Progress Bar ── */
    .progress-wrap {
      margin-top: 32px;
      animation: fadeUp .5s .4s ease both;
    }
    .progress-label {
      display: flex; justify-content: space-between;
      font-size: .72rem; color: rgba(255,255,255,.55);
      font-weight: 600; margin-bottom: 8px;
    }
    .progress-track {
      height: 6px; background: rgba(255,255,255,.12);
      border-radius: 999px; overflow: hidden;
    }
    .progress-fill {
      height: 100%; border-radius: 999px;
      background: linear-gradient(90deg, var(--gold), #f59e0b);
      animation: growBar 1.6s 1s cubic-bezier(.4,0,.2,1) both;
    }
    @keyframes growBar {
      from { width: 0 !important; }
    }

    /* ── Notify Form ── */
    .notify-section {
      background: var(--surface);
      border-radius: var(--radius-lg);
      border: 1px solid var(--border);
      box-shadow: var(--shadow-md);
      padding: 32px 32px 28px;
      margin-bottom: 28px;
      animation: fadeUp .5s .15s ease both;
    }
    .notify-header {
      display: flex; align-items: center; gap: 14px; margin-bottom: 20px;
    }
    .notify-icon {
      width: 46px; height: 46px; border-radius: 13px;
      background: var(--navy); color: var(--gold);
      display: flex; align-items: center; justify-content: center;
      font-size: 1.1rem; flex-shrink: 0;
    }
    .notify-header h2 { font-size: 1.15rem; font-weight: 800; color: var(--text-pri); }
    .notify-header p  { font-size: .8rem; color: var(--text-sec); margin-top: 2px; }

    .notify-form { display: flex; gap: 10px; flex-wrap: wrap; }
    .notify-input {
      flex: 1; min-width: 200px;
      padding: 11px 16px;
      border: 1.5px solid var(--border); border-radius: var(--radius-sm);
      font-family: 'Sora', sans-serif; font-size: .85rem; color: var(--text-pri);
      background: #f8fafc; outline: none;
      transition: border-color .2s, box-shadow .2s;
    }
    .notify-input:focus { border-color: var(--navy); box-shadow: 0 0 0 3px rgba(3,45,93,.08); background: #fff; }
    .notify-input::placeholder { color: var(--text-muted); }
    .notify-btn {
      display: inline-flex; align-items: center; gap: 8px;
      padding: 11px 24px; border-radius: var(--radius-sm);
      background: var(--navy); color: #fff;
      font-family: 'Sora', sans-serif; font-size: .85rem; font-weight: 700;
      border: none; cursor: pointer; transition: var(--transition); white-space: nowrap;
    }
    .notify-btn:hover { background: var(--navy-light); box-shadow: 0 4px 16px rgba(3,45,93,.3); transform: translateY(-1px); }
    .notify-btn:active { transform: translateY(0); }
    .notify-success {
      display: none; align-items: center; gap: 10px;
      background: var(--green-bg); border: 1px solid rgba(5,150,105,.2);
      border-radius: var(--radius-sm); padding: 12px 16px;
      margin-top: 12px;
      font-size: .83rem; font-weight: 600; color: #065f46;
    }

    /* ── Features Grid ── */
    .section-label {
      font-size: .7rem; font-weight: 700; text-transform: uppercase;
      letter-spacing: .1em; color: var(--text-muted);
      margin-bottom: 14px;
      display: flex; align-items: center; gap: 10px;
    }
    .section-label::after {
      content: ''; flex: 1; height: 1px; background: var(--border);
    }

    .features-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
      gap: 16px;
      margin-bottom: 28px;
    }
    .feature-card {
      background: var(--surface);
      border: 1px solid var(--border);
      border-radius: var(--radius-md);
      padding: 22px 20px;
      box-shadow: var(--shadow-sm);
      transition: var(--transition);
      position: relative; overflow: hidden;
      animation: fadeUp .45s ease both;
    }
    .feature-card::before {
      content: ''; position: absolute;
      top: 0; left: 0; right: 0; height: 3px;
      border-radius: var(--radius-md) var(--radius-md) 0 0;
      background: linear-gradient(90deg, var(--navy), var(--crimson));
      opacity: 0; transition: opacity .25s;
    }
    .feature-card:hover { transform: translateY(-4px); box-shadow: var(--shadow-md); }
    .feature-card:hover::before { opacity: 1; }
    .feature-card:nth-child(1) { animation-delay: .10s; }
    .feature-card:nth-child(2) { animation-delay: .15s; }
    .feature-card:nth-child(3) { animation-delay: .20s; }
    .feature-card:nth-child(4) { animation-delay: .25s; }
    .feature-card:nth-child(5) { animation-delay: .30s; }
    .feature-card:nth-child(6) { animation-delay: .35s; }

    .feature-icon {
      width: 42px; height: 42px; border-radius: 12px;
      background: linear-gradient(135deg, var(--navy), var(--navy-light));
      display: flex; align-items: center; justify-content: center;
      font-size: .95rem; color: var(--gold);
      margin-bottom: 14px; flex-shrink: 0;
    }
    .feature-title { font-size: .9rem; font-weight: 700; color: var(--text-pri); margin-bottom: 6px; }
    .feature-desc  { font-size: .78rem; color: var(--text-sec); line-height: 1.55; }

    /* ── Info Strip ── */
    .info-strip {
      display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
      gap: 14px; margin-bottom: 28px;
    }
    .info-card {
      background: var(--surface);
      border-radius: var(--radius-md); border: 1px solid var(--border);
      box-shadow: var(--shadow-sm); padding: 20px;
      display: flex; align-items: center; gap: 14px;
      transition: var(--transition);
      animation: fadeUp .5s ease both;
    }
    .info-card:nth-child(1) { animation-delay: .1s; }
    .info-card:nth-child(2) { animation-delay: .2s; }
    .info-card:nth-child(3) { animation-delay: .3s; }
    .info-card:hover { transform: translateY(-3px); box-shadow: var(--shadow-md); }
    .info-card-icon {
      width: 44px; height: 44px; border-radius: 12px; flex-shrink: 0;
      display: flex; align-items: center; justify-content: center; font-size: 1rem;
    }
    .info-card-icon.ic-gold    { background: var(--amber-bg); color: var(--amber); }
    .info-card-icon.ic-indigo  { background: var(--indigo-bg); color: var(--indigo); }
    .info-card-icon.ic-green   { background: var(--green-bg);  color: var(--green); }
    .info-card-title { font-size: .82rem; font-weight: 700; color: var(--text-pri); }
    .info-card-sub   { font-size: .73rem; color: var(--text-muted); margin-top: 2px; }

    /* ── Animations ── */
    @keyframes fadeUp { from { opacity: 0; transform: translateY(14px); } to { opacity: 1; transform: none; } }

    /* ── Bottom Nav ── */
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
    .nav-item:hover  { color: var(--navy); }
  </style>
</head>
<body>

<!-- Preloader -->
<div id="preloader">
  <div class="preloader-box">
    <div class="spin"></div>
    <span>Loading…</span>
  </div>
</div>

<!-- Topbar -->
<div class="topbar">
  <a href="index.php" class="back-btn">
    <span class="back-icon"><i class="fas fa-arrow-left"></i></span>
    <span>Back to Home</span>
  </a>
  <div class="topbar-brand">
    <span class="topbar-brand-dot"></span>
    Keychele
  </div>
</div>

<div class="page-wrap">

  <!-- Hero -->
  <div class="hero">
    <div class="hero-inner">
      <div class="hero-eyebrow">
        <span class="hero-eyebrow-dot"></span>
        Something Big Is Coming
      </div>

      <div class="hero-title">
        We're Working<br>On <span>Something Great</span>
      </div>

      <p class="hero-sub">
        This feature is currently under development. Our team is building something amazing for you — check back soon or drop your email to be the first to know when it launches.
      </p>

      <!-- Countdown -->
      <div class="countdown">
        <div class="cd-unit">
          <span class="cd-val gold" id="cd-days"><?= str_pad($days, 2, '0', STR_PAD_LEFT) ?></span>
          <span class="cd-label">Days</span>
        </div>
        <div class="cd-sep">:</div>
        <div class="cd-unit">
          <span class="cd-val" id="cd-hours"><?= str_pad($hours, 2, '0', STR_PAD_LEFT) ?></span>
          <span class="cd-label">Hours</span>
        </div>
        <div class="cd-sep">:</div>
        <div class="cd-unit">
          <span class="cd-val" id="cd-mins"><?= str_pad($minutes, 2, '0', STR_PAD_LEFT) ?></span>
          <span class="cd-label">Minutes</span>
        </div>
        <div class="cd-sep">:</div>
        <div class="cd-unit">
          <span class="cd-val" id="cd-secs"><?= str_pad($seconds, 2, '0', STR_PAD_LEFT) ?></span>
          <span class="cd-label">Seconds</span>
        </div>
      </div>

      <!-- Progress -->
      <?php
        // Calculate % progress from a fixed start date toward launch
        $start_ts    = strtotime('2025-01-01');
        $total_span  = max(1, $launch_ts - $start_ts);
        $elapsed     = max(0, min($total_span, $now_ts - $start_ts));
        $pct         = round(($elapsed / $total_span) * 100);
      ?>
      <div class="progress-wrap">
        <div class="progress-label">
          <span>Development Progress</span>
          <span><?= $pct ?>% Complete</span>
        </div>
        <div class="progress-track">
          <div class="progress-fill" style="width:<?= $pct ?>%"></div>
        </div>
      </div>

    </div>
  </div>

  <!-- Notify Form -->
  <div class="notify-section">
    <div class="notify-header">
      <div class="notify-icon"><i class="fas fa-bell"></i></div>
      <div>
        <h2>Get Notified at Launch</h2>
        <p>Be the first to know when this feature goes live.</p>
      </div>
    </div>
    <form class="notify-form" id="notifyForm" onsubmit="handleNotify(event)">
      <input type="email" class="notify-input" id="notifyEmail"
             placeholder="Enter your email address…" required autocomplete="email">
      <button type="submit" class="notify-btn">
        <i class="fas fa-paper-plane"></i>
        Notify Me
      </button>
    </form>
    <div class="notify-success" id="notifySuccess">
      <i class="fas fa-circle-check" style="font-size:1.1rem;color:var(--green)"></i>
      <span>You're on the list! We'll email you as soon as it launches.</span>
    </div>
  </div>

  <!-- Info Strip -->
  <div class="info-strip">
    <div class="info-card">
      <div class="info-card-icon ic-gold"><i class="fas fa-rocket"></i></div>
      <div>
        <div class="info-card-title">Launching <?= date('M Y', $launch_ts) ?></div>
        <div class="info-card-sub">Estimated release date</div>
      </div>
    </div>
    <div class="info-card">
      <div class="info-card-icon ic-indigo"><i class="fas fa-code-branch"></i></div>
      <div>
        <div class="info-card-title">Active Development</div>
        <div class="info-card-sub">Engineers working daily</div>
      </div>
    </div>
    <div class="info-card">
      <div class="info-card-icon ic-green"><i class="fas fa-shield-check"></i></div>
      <div>
        <div class="info-card-title">CA Compliant</div>
        <div class="info-card-sub">Privacy-first from day one</div>
      </div>
    </div>
  </div>

  <!-- Features Coming -->
  <div class="section-label"><i class="fas fa-sparkles"></i> Features in this release</div>
  <div class="features-grid">
    <?php foreach ($features as $f): ?>
    <div class="feature-card">
      <div class="feature-icon"><i class="fas <?= $f['icon'] ?>"></i></div>
      <div class="feature-title"><?= htmlspecialchars($f['title']) ?></div>
      <div class="feature-desc"><?= htmlspecialchars($f['desc']) ?></div>
    </div>
    <?php endforeach; ?>
  </div>

</div><!-- /page-wrap -->

<!-- Mobile Bottom Nav -->
<nav class="bottom-nav">
  <a href="index.php?page=dashboard" class="nav-item"><i class="fas fa-home"></i>Home</a>
  <a href="index.php?page=ticket_purchases" class="nav-item"><i class="fas fa-ticket-alt"></i>Tickets</a>
  <a href="transactions.php" class="nav-item"><i class="fas fa-receipt"></i>Trans</a>
  <a href="index.php?page=my_wallet" class="nav-item"><i class="fas fa-wallet"></i>Wallet</a>
</nav>

<script>
// Preloader
window.addEventListener('load', () => {
  const p = document.getElementById('preloader');
  p.style.opacity = '0';
  setTimeout(() => p.style.display = 'none', 400);
});

// Live countdown
(function () {
  const target = <?= $launch_ts * 1000 ?>;

  function pad(n) { return String(n).padStart(2, '0'); }

  function tick() {
    const diff = Math.max(0, Math.floor((target - Date.now()) / 1000));
    const d = Math.floor(diff / 86400);
    const h = Math.floor((diff % 86400) / 3600);
    const m = Math.floor((diff % 3600) / 60);
    const s = diff % 60;

    const dEl = document.getElementById('cd-days');
    const hEl = document.getElementById('cd-hours');
    const mEl = document.getElementById('cd-mins');
    const sEl = document.getElementById('cd-secs');

    if (dEl) dEl.textContent = pad(d);
    if (hEl) hEl.textContent = pad(h);
    if (mEl) mEl.textContent = pad(m);
    if (sEl) {
      // Flash seconds for attention
      sEl.style.opacity = '0.4';
      setTimeout(() => sEl.style.opacity = '1', 250);
      sEl.textContent = pad(s);
    }

    if (diff > 0) setTimeout(tick, 1000);
    else {
      document.querySelectorAll('.cd-val').forEach(el => el.textContent = '00');
    }
  }
  tick();
})();

// Notify form
function handleNotify(e) {
  e.preventDefault();
  const email = document.getElementById('notifyEmail').value.trim();
  if (!email) return;

  const btn  = document.querySelector('.notify-btn');
  const orig = btn.innerHTML;
  btn.disabled = true;
  btn.innerHTML = '<span style="display:inline-block;width:14px;height:14px;border:2px solid #fff;border-top-color:transparent;border-radius:50%;animation:spin .7s linear infinite"></span> Saving…';

  // Simulated save — replace with a real AJAX call to your backend
  setTimeout(() => {
    document.getElementById('notifyForm').style.opacity = '.4';
    document.getElementById('notifyForm').style.pointerEvents = 'none';
    const success = document.getElementById('notifySuccess');
    success.style.display = 'flex';
    btn.disabled = false;
    btn.innerHTML = orig;
  }, 1200);
}
</script>
</body>
</html>
