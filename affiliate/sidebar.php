<?php
// ==================== AFFILIATE / SIDEBAR.PHP ====================
// Guard: if already loaded (e.g. included twice by router + page), bail out silently.
if (defined('AF_SIDEBAR_LOADED')) return;
define('AF_SIDEBAR_LOADED', true);

if (session_status() === PHP_SESSION_NONE) session_start();

// ── DB connection ─────────────────────────────────────────────────
$connect_paths = [
    __DIR__ . '/includes/connect.php',
    __DIR__ . '/../includes/connect.php',
    __DIR__ . '/../../msanii/includes/connect.php',
    dirname(__DIR__) . '/includes/connect.php',
    dirname(__DIR__) . '/msanii/includes/connect.php',
];
$_af_connected = false;
foreach ($connect_paths as $_af_path) {
    if (file_exists($_af_path)) {
        require_once $_af_path;
        $_af_connected = true;
        break;
    }
}
if (!$_af_connected) die('Database connection failed.');

// ── Auth guard ────────────────────────────────────────────────────
if (empty($_SESSION['affiliate_id'])) {
    if (headers_sent()) {
        echo '<script>window.location.href="../login.php";</script>';
    } else {
        header("Location: ../login.php");
    }
    exit;
}

$affiliate_id = (int)$_SESSION['affiliate_id'];

// ── Fetch affiliate + creator info ────────────────────────────────
$affiliate_name   = $_SESSION['affiliate_name']       ?? 'Affiliate';
$creator_name     = $_SESSION['affiliate_creator']    ?? 'Your Creator';
$affiliate_email  = $_SESSION['affiliate_email']      ?? '';
$creator_id       = (int)($_SESSION['affiliate_creator_id'] ?? 0);
$initials         = 'A';
$commission_type  = 'percentage';
$commission_value = 10;

try {
    $stmt = $pdo->prepare("
        SELECT a.full_name, a.email, a.created_by, a.token,
               c.full_name AS creator_full_name,
               COALESCE(aset.commission_type,'percentage')  AS commission_type,
               COALESCE(aset.commission_value, 10)          AS commission_value
        FROM affiliates a
        LEFT JOIN creators c         ON c.id    = a.created_by
        LEFT JOIN affiliate_settings aset ON aset.creator_id = a.created_by
        WHERE a.id = ?
        LIMIT 1
    ");
    $stmt->execute([$affiliate_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $affiliate_name   = $row['full_name'];
        $affiliate_email  = $row['email'];
        $creator_id       = (int)$row['created_by'];
        $creator_name     = $row['creator_full_name'] ?? 'Your Creator';
        $commission_type  = $row['commission_type'];
        $commission_value = (float)$row['commission_value'];
        $_SESSION['affiliate_creator_id'] = $creator_id;
    }
} catch (Exception $e) { /* silent */ }

// Build initials
$_af_parts = array_filter(explode(' ', trim($affiliate_name)));
if (count($_af_parts) >= 2) {
    $initials = strtoupper(mb_substr($_af_parts[0], 0, 1) . mb_substr(end($_af_parts), 0, 1));
} else {
    $initials = strtoupper(mb_substr($affiliate_name, 0, 2));
}

// ── Pending purchases (unreviewed) ────────────────────────────────
$pending_purchases = 0;
try {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM ticket_purchases tp
        INNER JOIN events e ON e.id = tp.event_id
        WHERE tp.affiliate_id = ? AND tp.payment_status = 'pending'
    ");
    $stmt->execute([$affiliate_id]);
    $pending_purchases = (int)$stmt->fetchColumn();
} catch (Exception $e) { /* silent */ }

// ── Unpaid commissions ────────────────────────────────────────────
$unpaid_commissions = 0;
try {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM affiliate_earnings
        WHERE affiliate_id = ? AND status = 'pending'
    ");
    $stmt->execute([$affiliate_id]);
    $unpaid_commissions = (int)$stmt->fetchColumn();
} catch (Exception $e) { /* silent */ }

// ── Account balance (live) ────────────────────────────────────────
$af_balance = 0;
try {
    $stmt = $pdo->prepare("SELECT COALESCE(balance,0) FROM affiliate_balances WHERE affiliate_id = ? LIMIT 1");
    $stmt->execute([$affiliate_id]);
    $af_balance = (float)$stmt->fetchColumn();
} catch (Exception $e) { /* silent */ }

// ── Active page detection ─────────────────────────────────────────
$current_page = $_GET['page'] ?? 'home';

// ── Helpers (guarded against redeclaration) ───────────────────────
if (!function_exists('af_active')) {
    function af_active(string $page, $match): string {
        if (is_array($match)) return in_array($page, $match) ? 'af-active' : '';
        return $page === $match ? 'af-active' : '';
    }
}
if (!function_exists('af_group_open')) {
    function af_group_open(string $page, array $matches): string {
        return in_array($page, $matches) ? 'open' : '';
    }
}

$commission_label = $commission_type === 'percentage'
    ? number_format($commission_value, 0) . '%'
    : 'KSh ' . number_format($commission_value, 0);
?>

<?php /* ── PAGE LOADER ── */ ?>
<div id="afLoader">
  <div class="afl-inner">
    <div class="afl-ring-wrap">
      <div class="afl-ring"></div>
      <div class="afl-ring afl-ring2"></div>
      <div class="afl-mark">
        <svg viewBox="0 0 40 40" fill="none">
          <path d="M10 20 L18 28 L30 13" stroke="#0e9f6e" stroke-width="4.5"
                stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
      </div>
    </div>
    <div class="afl-word">Key<span>chele</span></div>
    <div class="afl-sub">Affiliate Portal</div>
    <div class="afl-bar"><div class="afl-fill"></div></div>
  </div>
</div>

<?php /* ── MOBILE OVERLAY ── */ ?>
<div id="afOverlay" onclick="afCloseSidebar()"></div>

<?php /* ── HAMBURGER ── */ ?>
<button id="afHamburger" onclick="afOpenSidebar()" aria-label="Open menu">
  <i class="fa-solid fa-bars"></i>
</button>

<?php /* ══════════════════ SIDEBAR ══════════════════ */ ?>
<aside id="afSidebar">

  <?php /* ── BRAND TOP BAR ── */ ?>
  <div class="afs-top">
    <a href="index.php" class="afs-brand">
      <div class="afs-brand-ico">
        <svg viewBox="0 0 40 40" fill="none">
          <path d="M8 8L20 20L8 32"  stroke="#0e9f6e" stroke-width="4.5" stroke-linecap="round" stroke-linejoin="round"/>
          <path d="M20 20H34"        stroke="#065f46" stroke-width="4.5" stroke-linecap="round"/>
        </svg>
      </div>
      <div>
        <span class="afs-brand-name">Key<em>chele</em></span>
        <span class="afs-brand-sub">Affiliate Portal</span>
      </div>
    </a>
    <button class="afs-col-btn" onclick="afToggleCollapse()" title="Collapse sidebar" id="afColBtn">
      <i class="fa-solid fa-angles-left" id="afColIcon"></i>
    </button>
  </div>

  <?php /* ── PROFILE CARD ── */ ?>
  <div class="afs-profile">
    <div class="afs-av">
      <?= htmlspecialchars($initials) ?>
      <span class="afs-av-dot"></span>
    </div>
    <div class="afs-profile-info">
      <div class="afs-pname"><?= htmlspecialchars($affiliate_name) ?></div>
      <div class="afs-prole">
        <i class="fa-solid fa-handshake-simple" style="font-size:.6rem;opacity:.7"></i>
        <?= htmlspecialchars($creator_name) ?>
      </div>
    </div>
    <div class="afs-pbtns">
      <a href="index.php?page=profile" class="afs-pbtn" title="Edit Profile">
        <i class="fa-solid fa-pen-to-square"></i>
      </a>
      <a href="logout.php" class="afs-pbtn afs-pbtn-danger" title="Logout">
        <i class="fa-solid fa-arrow-right-from-bracket"></i>
      </a>
    </div>
  </div>

  <?php /* ── COMMISSION + BALANCE PILLS ── */ ?>
  <div class="afs-pills-wrap">
    <div class="afs-comm-pill">
      <div class="afs-comm-ico"><i class="fa-solid fa-percent"></i></div>
      <div class="afs-comm-body">
        <div class="afs-comm-lbl">Commission Rate</div>
        <div class="afs-comm-val"><?= htmlspecialchars($commission_label) ?> per sale</div>
      </div>
      <div class="afs-comm-badge"><?= $commission_type === 'percentage' ? '%' : 'KES' ?></div>
    </div>

    <div class="afs-bal-pill">
      <div class="afs-bal-ico"><i class="fa-solid fa-vault"></i></div>
      <div class="afs-bal-body">
        <div class="afs-bal-lbl">Available Balance</div>
        <div class="afs-bal-val">KSh <?= number_format($af_balance, 2) ?></div>
      </div>
      <?php if ($af_balance > 0): ?>
        <a href="index.php?page=request_withdrawal" class="afs-bal-cta" title="Withdraw">
          <i class="fa-solid fa-circle-dollar-to-slot"></i>
        </a>
      <?php endif; ?>
    </div>
  </div>

  <?php /* ── NAVIGATION ── */ ?>
  <nav class="afs-nav">

    <?php /* OVERVIEW */ ?>
    <div class="afs-lbl">Overview</div>

    <a href="index.php"
       class="afs-a <?= af_active($current_page, ['home','dashboard']) ?>"
       data-tip="Dashboard">
      <span class="afs-ico"><i class="fa-solid fa-gauge-high"></i></span>
      <span class="afs-txt">Dashboard</span>
    </a>

    <a href="index.php?page=profile"
       class="afs-a <?= af_active($current_page, 'profile') ?>"
       data-tip="My Profile">
      <span class="afs-ico"><i class="fa-solid fa-circle-user"></i></span>
      <span class="afs-txt">My Profile</span>
    </a>

    <?php /* EARNINGS */ ?>
    <div class="afs-lbl">My Earnings</div>

    <a href="index.php?page=earnings"
       class="afs-a <?= af_active($current_page, 'earnings') ?>"
       data-tip="Earnings">
      <span class="afs-ico afs-ico-earn"><i class="fa-solid fa-sack-dollar"></i></span>
      <span class="afs-txt">Earnings</span>
      <?php if ($unpaid_commissions > 0): ?>
        <span class="afs-badge afs-badge-green" style="margin-left:auto">
          <?= $unpaid_commissions ?> pending
        </span>
      <?php endif; ?>
    </a>

    <a href="index.php?page=transactions"
       class="afs-a <?= af_active($current_page, 'transactions') ?>"
       data-tip="Transactions">
      <span class="afs-ico"><i class="fa-solid fa-right-left"></i></span>
      <span class="afs-txt">Transactions</span>
    </a>

    <a href="index.php?page=request_withdrawal"
       class="afs-a <?= af_active($current_page, 'request_withdrawal') ?>"
       data-tip="Withdraw Funds">
      <span class="afs-ico afs-ico-wd"><i class="fa-solid fa-circle-dollar-to-slot"></i></span>
      <span class="afs-txt">Withdraw Funds</span>
    </a>

    <a href="index.php?page=my_withdrawals"
       class="afs-a <?= af_active($current_page, 'my_withdrawals') ?>"
       data-tip="My Withdrawals">
      <span class="afs-ico"><i class="fa-solid fa-clock-rotate-left"></i></span>
      <span class="afs-txt">My Withdrawals</span>
    </a>

    <?php /* TICKETS */ ?>
    <div class="afs-lbl">Creator's Events</div>

    <div class="afs-grp">
      <button class="afs-grp-btn <?= af_group_open($current_page, ['tickets','ticket_purchases','ticket_details']) ?>"
              onclick="afToggleGroup(this)"
              data-tip="Tickets">
        <span class="afs-ico afs-ico-ticket"><i class="fa-solid fa-ticket"></i></span>
        <span class="afs-txt">Tickets</span>
        <?php if ($pending_purchases > 0): ?>
          <span class="afs-badge afs-badge-warn"><?= $pending_purchases ?></span>
        <?php endif; ?>
        <i class="fa-solid fa-chevron-right afs-chev"></i>
      </button>
      <div class="afs-grp-items <?= af_group_open($current_page, ['tickets','ticket_purchases','ticket_details']) ?>">
        <a href="index.php?page=tickets"
           class="afs-a afs-sub <?= af_active($current_page, 'tickets') ?>">
          <span class="afs-ico"><i class="fa-solid fa-list-check"></i></span>
          <span class="afs-txt">Event Tickets</span>
        </a>
        <a href="index.php?page=ticket_purchases"
           class="afs-a afs-sub <?= af_active($current_page, 'ticket_purchases') ?>">
          <span class="afs-ico"><i class="fa-solid fa-cart-shopping"></i></span>
          <span class="afs-txt">Purchases via My Link</span>
          <?php if ($pending_purchases > 0): ?>
            <span class="afs-badge afs-badge-warn" style="margin-left:auto">
              <?= $pending_purchases ?>
            </span>
          <?php endif; ?>
        </a>
        <a href="index.php?page=ticket_details"
           class="afs-a afs-sub <?= af_active($current_page, 'ticket_details') ?>">
          <span class="afs-ico"><i class="fa-solid fa-magnifying-glass-chart"></i></span>
          <span class="afs-txt">Ticket Details</span>
        </a>
      </div>
    </div>

    <?php /* TOOLS */ ?>
    <div class="afs-lbl">Tools</div>

    <a href="index.php?page=my_links"
       class="afs-a <?= af_active($current_page, 'my_links') ?>"
       data-tip="My Referral Links">
      <span class="afs-ico afs-ico-link"><i class="fa-solid fa-link"></i></span>
      <span class="afs-txt">My Referral Links</span>
    </a>

    <a href="index.php?page=performance"
       class="afs-a <?= af_active($current_page, 'performance') ?>"
       data-tip="Performance">
      <span class="afs-ico"><i class="fa-solid fa-chart-line"></i></span>
      <span class="afs-txt">Performance</span>
    </a>

    <div class="afs-divider"></div>

    <a href="logout.php" class="afs-a afs-logout" data-tip="Logout">
      <span class="afs-ico"><i class="fa-solid fa-power-off"></i></span>
      <span class="afs-txt">Logout</span>
    </a>

  </nav>

  <?php /* ── SIDEBAR FOOTER ── */ ?>
  <div class="afs-footer">
    <span class="afs-footer-txt">Keychele Affiliate</span>
    <span class="afs-footer-dot"></span>
    <span class="afs-footer-txt">v2.0</span>
  </div>

</aside>

<?php /* ── MOBILE BOTTOM NAV ── */ ?>
<nav id="afMobNav">
  <a href="index.php"
     class="afmn-item <?= af_active($current_page, ['home','dashboard']) ?>">
    <i class="fa-solid fa-gauge-high"></i><span>Home</span>
  </a>

  <a href="index.php?page=earnings"
     class="afmn-item <?= af_active($current_page, 'earnings') ?>">
    <i class="fa-solid fa-sack-dollar"></i><span>Earnings</span>
    <?php if ($unpaid_commissions > 0): ?><span class="afmn-dot"></span><?php endif; ?>
  </a>

  <div class="afmn-fab-wrap">
    <a href="index.php?page=my_links" class="afmn-fab" title="My Links">
      <i class="fa-solid fa-link"></i>
    </a>
  </div>

  <a href="index.php?page=ticket_purchases"
     class="afmn-item <?= af_active($current_page, 'ticket_purchases') ?>">
    <i class="fa-solid fa-cart-shopping"></i><span>Purchases</span>
    <?php if ($pending_purchases > 0): ?><span class="afmn-dot"></span><?php endif; ?>
  </a>

  <a href="index.php?page=profile"
     class="afmn-item <?= af_active($current_page, 'profile') ?>">
    <i class="fa-solid fa-circle-user"></i><span>Profile</span>
  </a>
</nav>

<?php /* ── FONTS & ICONS ── */ ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Outfit:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>

<style>
/* ════════════════════════════════════════════════════════════════
   CSS VARIABLES — Green Affiliate Theme
════════════════════════════════════════════════════════════════ */
:root {
  --af-w:          256px;
  --af-wc:         72px;
  --af-bg:         #f0fdf4;
  --af-bg2:        #dcfce7;
  --af-bg3:        #ffffff;
  --af-bdr:        #d1fae5;
  --af-bdr2:       #a7f3d0;
  --af-txt:        #0d1f0d;
  --af-mut:        #6b7280;
  --af-green:      #0e9f6e;
  --af-green-d:    #065f46;
  --af-green-l:    #d1fae5;
  --af-green-xl:   #ecfdf5;
  --af-teal:       #0d9488;
  --af-teal-l:     #ccfbf1;
  --af-gold:       #d97706;
  --af-gold-l:     #fef3c7;
  --af-red:        #dc2626;
  --af-red-l:      #fee2e2;
  --af-warn:       #f59e0b;
  --af-warn-l:     #fef3c7;
  --af-shadow:     0 4px 32px rgba(14,159,110,0.12), 0 1px 4px rgba(0,0,0,0.04);
  --af-trn:        0.25s cubic-bezier(0.4,0,0.2,1);
}

/* ════════════════════════════════════════════════════════════════
   PAGE LOADER
════════════════════════════════════════════════════════════════ */
#afLoader {
  position: fixed; inset: 0; z-index: 99999;
  background: #f0fdf4;
  display: flex; align-items: center; justify-content: center;
  transition: opacity .5s ease, visibility .5s ease;
}
#afLoader.hide { opacity: 0; visibility: hidden; pointer-events: none; }

.afl-inner {
  display: flex; flex-direction: column;
  align-items: center; gap: .75rem;
}
.afl-ring-wrap {
  position: relative; width: 72px; height: 72px;
  display: flex; align-items: center; justify-content: center;
}
.afl-ring {
  position: absolute; inset: 0; border-radius: 50%;
  border: 3px solid transparent;
  border-top-color: var(--af-green);
  animation: afSpin .85s linear infinite;
}
.afl-ring2 {
  inset: 10px;
  border-top-color: var(--af-teal);
  border-right-color: rgba(14,159,110,.25);
  animation-duration: 1.3s;
  animation-direction: reverse;
}
@keyframes afSpin { to { transform: rotate(360deg); } }
.afl-mark { position: relative; z-index: 2; width: 28px; height: 28px; }
.afl-mark svg { width: 100%; height: 100%; }

.afl-word {
  font-family: 'Outfit', sans-serif;
  font-weight: 900; font-size: 1.65rem;
  color: var(--af-green-d); letter-spacing: -0.05em;
}
.afl-word span { color: var(--af-green); }

.afl-sub {
  font-family: 'Plus Jakarta Sans', sans-serif;
  font-size: .65rem; font-weight: 700;
  text-transform: uppercase; letter-spacing: .12em;
  color: var(--af-mut);
}
.afl-bar {
  width: 120px; height: 3px;
  background: var(--af-green-l);
  border-radius: 100px; overflow: hidden;
}
.afl-fill {
  height: 100%;
  background: linear-gradient(90deg, var(--af-green), var(--af-teal));
  animation: afBarFill 1.7s ease forwards;
}
@keyframes afBarFill { from { width: 0; } to { width: 100%; } }

/* ════════════════════════════════════════════════════════════════
   OVERLAY + HAMBURGER
════════════════════════════════════════════════════════════════ */
#afOverlay {
  display: none; position: fixed; inset: 0;
  background: rgba(6,95,70,.42);
  backdrop-filter: blur(4px);
  z-index: 1099;
}
#afOverlay.show { display: block; }

#afHamburger {
  display: none;
  position: fixed; top: 13px; left: 13px; z-index: 1200;
  width: 44px; height: 44px; border-radius: 12px;
  background: var(--af-green); border: none;
  color: white; font-size: 1rem;
  align-items: center; justify-content: center;
  cursor: pointer;
  box-shadow: 0 4px 16px rgba(14,159,110,.45);
  transition: background .2s, transform .15s;
}
#afHamburger:hover { background: var(--af-green-d); transform: scale(1.05); }

/* ════════════════════════════════════════════════════════════════
   SIDEBAR SHELL
════════════════════════════════════════════════════════════════ */
#afSidebar {
  position: fixed; top: 0; left: 0;
  width: var(--af-w); height: 100vh;
  background: var(--af-bg3);
  display: flex; flex-direction: column;
  z-index: 1100;
  border-right: 1.5px solid var(--af-bdr);
  box-shadow: var(--af-shadow);
  transition: width var(--af-trn), transform var(--af-trn);
  overflow: hidden;
}

/* Ambient gradient layer */
#afSidebar::before {
  content: ''; position: absolute; inset: 0;
  pointer-events: none; z-index: 0;
  background:
    radial-gradient(ellipse at 110% -5%,  rgba(14,159,110,.07) 0%, transparent 55%),
    radial-gradient(ellipse at -10% 100%, rgba(13,148,136,.05) 0%, transparent 55%);
}
#afSidebar > * { position: relative; z-index: 1; }

/* ── COLLAPSED STATE ── */
#afSidebar.af-col { width: var(--af-wc); }

#afSidebar.af-col .afs-brand-name,
#afSidebar.af-col .afs-brand-sub,
#afSidebar.af-col .afs-profile-info,
#afSidebar.af-col .afs-pbtns,
#afSidebar.af-col .afs-txt,
#afSidebar.af-col .afs-lbl,
#afSidebar.af-col .afs-chev,
#afSidebar.af-col .afs-badge,
#afSidebar.af-col .afs-pills-wrap,
#afSidebar.af-col .afs-grp-items,
#afSidebar.af-col .afs-footer { display: none !important; }

#afSidebar.af-col .afs-profile   { justify-content: center; padding: 12px 6px; }
#afSidebar.af-col .afs-a,
#afSidebar.af-col .afs-grp-btn   { justify-content: center; padding: 10px 0; }
#afSidebar.af-col .afs-ico       { margin: 0; }
#afSidebar.af-col .afs-top       { justify-content: center; padding: 14px 8px; }
#afSidebar.af-col .afs-brand     { display: none; }
#afSidebar.af-col .afs-col-btn   { margin: 0 auto; }
#afSidebar.af-col #afColIcon     { transform: rotate(180deg); }

/* ════════════════════════════════════════════════════════════════
   TOP BAR (Brand + Collapse)
════════════════════════════════════════════════════════════════ */
.afs-top {
  display: flex; align-items: center; justify-content: space-between;
  padding: 15px 14px 13px;
  border-bottom: 1.5px solid var(--af-bdr);
  flex-shrink: 0;
}
.afs-brand {
  display: flex; align-items: center; gap: 10px; text-decoration: none;
}
.afs-brand-ico {
  width: 36px; height: 36px; border-radius: 10px;
  background: var(--af-green-xl);
  border: 1.5px solid var(--af-bdr2);
  display: flex; align-items: center; justify-content: center;
  padding: 6px; flex-shrink: 0;
  box-shadow: 0 2px 10px rgba(14,159,110,.18);
}
.afs-brand-ico svg { width: 100%; height: 100%; }
.afs-brand-name {
  font-family: 'Outfit', sans-serif;
  font-weight: 900; font-size: 1.05rem;
  color: var(--af-green-d); letter-spacing: -0.04em;
  white-space: nowrap; line-height: 1.1;
}
.afs-brand-name em { font-style: normal; color: var(--af-green); }
.afs-brand-sub {
  display: block;
  font-family: 'Plus Jakarta Sans', sans-serif;
  font-size: .55rem; font-weight: 700;
  text-transform: uppercase; letter-spacing: .09em;
  color: var(--af-green); margin-top: 1px;
}
.afs-col-btn {
  width: 28px; height: 28px; border-radius: 7px;
  background: var(--af-green-xl);
  border: 1.5px solid var(--af-bdr);
  color: var(--af-mut);
  display: flex; align-items: center; justify-content: center;
  cursor: pointer; font-size: .68rem;
  transition: all .18s; flex-shrink: 0;
}
.afs-col-btn:hover { background: var(--af-green-l); color: var(--af-green-d); }
#afColIcon { transition: transform var(--af-trn); }

/* ════════════════════════════════════════════════════════════════
   PROFILE CARD
════════════════════════════════════════════════════════════════ */
.afs-profile {
  display: flex; align-items: center; gap: 9px;
  padding: 11px 14px;
  border-bottom: 1.5px solid var(--af-bdr);
  flex-shrink: 0;
  transition: padding var(--af-trn);
}
.afs-av {
  position: relative;
  width: 40px; height: 40px; border-radius: 50%;
  background: linear-gradient(135deg, var(--af-green), var(--af-teal));
  display: flex; align-items: center; justify-content: center;
  font-family: 'Outfit', sans-serif; font-weight: 800; font-size: .82rem;
  color: white; flex-shrink: 0;
  border: 2px solid var(--af-green-l);
  box-shadow: 0 0 0 3px rgba(14,159,110,.14);
}
.afs-av-dot {
  position: absolute; bottom: 1px; right: 1px;
  width: 9px; height: 9px; border-radius: 50%;
  background: #22c55e; border: 2px solid white;
}
.afs-profile-info { flex: 1; min-width: 0; overflow: hidden; }
.afs-pname {
  font-family: 'Outfit', sans-serif; font-weight: 700; font-size: .8rem;
  color: var(--af-txt); white-space: nowrap;
  overflow: hidden; text-overflow: ellipsis;
}
.afs-prole {
  font-family: 'Plus Jakarta Sans', sans-serif; font-size: .62rem;
  color: var(--af-green); margin-top: 2px;
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
  display: flex; align-items: center; gap: 4px;
}
.afs-pbtns { display: flex; gap: 4px; flex-shrink: 0; }
.afs-pbtn {
  width: 27px; height: 27px; border-radius: 7px;
  background: var(--af-green-xl); border: 1.5px solid var(--af-bdr);
  display: flex; align-items: center; justify-content: center;
  color: var(--af-mut); font-size: .68rem;
  text-decoration: none; transition: all .18s;
}
.afs-pbtn:hover           { background: var(--af-green-l); color: var(--af-green-d); }
.afs-pbtn-danger:hover    { background: var(--af-red-l);   color: var(--af-red); }

/* ════════════════════════════════════════════════════════════════
   COMMISSION + BALANCE PILLS
════════════════════════════════════════════════════════════════ */
.afs-pills-wrap {
  display: flex; flex-direction: column; gap: 6px;
  padding: 10px 12px;
  border-bottom: 1.5px solid var(--af-bdr);
  flex-shrink: 0;
}

/* Commission pill */
.afs-comm-pill {
  display: flex; align-items: center; gap: 10px;
  padding: 9px 12px;
  background: linear-gradient(135deg, var(--af-green), var(--af-teal));
  border-radius: 11px;
  box-shadow: 0 3px 14px rgba(14,159,110,.28);
}
.afs-comm-ico { color: rgba(255,255,255,.8); font-size: .85rem; }
.afs-comm-body { flex: 1; min-width: 0; }
.afs-comm-lbl {
  font-family: 'Plus Jakarta Sans', sans-serif;
  font-size: .58rem; font-weight: 600;
  color: rgba(255,255,255,.7);
  text-transform: uppercase; letter-spacing: .07em;
}
.afs-comm-val {
  font-family: 'Outfit', sans-serif; font-weight: 700; font-size: .86rem;
  color: white;
}
.afs-comm-badge {
  background: rgba(255,255,255,.2);
  border-radius: 6px; padding: 2px 8px;
  font-family: 'Outfit', sans-serif;
  font-size: .62rem; font-weight: 800; color: white;
}

/* Balance pill */
.afs-bal-pill {
  display: flex; align-items: center; gap: 10px;
  padding: 9px 12px;
  background: var(--af-green-xl);
  border: 1.5px solid var(--af-bdr2);
  border-radius: 11px;
}
.afs-bal-ico {
  width: 28px; height: 28px; border-radius: 7px;
  background: var(--af-green-l);
  display: flex; align-items: center; justify-content: center;
  color: var(--af-green); font-size: .78rem; flex-shrink: 0;
}
.afs-bal-body { flex: 1; min-width: 0; }
.afs-bal-lbl {
  font-family: 'Plus Jakarta Sans', sans-serif;
  font-size: .58rem; font-weight: 600; color: var(--af-mut);
  text-transform: uppercase; letter-spacing: .07em;
}
.afs-bal-val {
  font-family: 'Outfit', sans-serif; font-weight: 800; font-size: .88rem;
  color: var(--af-green-d);
}
.afs-bal-cta {
  width: 26px; height: 26px; border-radius: 7px;
  background: var(--af-green); color: white;
  display: flex; align-items: center; justify-content: center;
  font-size: .72rem; text-decoration: none; flex-shrink: 0;
  transition: background .18s, transform .18s;
}
.afs-bal-cta:hover { background: var(--af-green-d); transform: scale(1.08); }

/* ════════════════════════════════════════════════════════════════
   NAVIGATION
════════════════════════════════════════════════════════════════ */
.afs-nav {
  flex: 1; overflow-y: auto; overflow-x: hidden;
  padding: 8px 8px 24px;
  scrollbar-width: thin;
  scrollbar-color: var(--af-bdr) transparent;
}
.afs-nav::-webkit-scrollbar { width: 3px; }
.afs-nav::-webkit-scrollbar-thumb { background: var(--af-bdr2); border-radius: 3px; }

/* Section label */
.afs-lbl {
  font-family: 'Plus Jakarta Sans', sans-serif;
  font-size: .58rem; font-weight: 700;
  text-transform: uppercase; letter-spacing: .13em;
  color: var(--af-mut);
  padding: 13px 8px 5px; white-space: nowrap;
}

/* Nav link */
.afs-a {
  display: flex; align-items: center; gap: 9px;
  padding: 8px 9px; border-radius: 10px; margin-bottom: 1px;
  text-decoration: none; color: var(--af-txt);
  font-family: 'Plus Jakarta Sans', sans-serif;
  font-size: .8rem; font-weight: 500;
  transition: all .18s; position: relative;
  white-space: nowrap;
  border: 1.5px solid transparent;
}
.afs-a:hover {
  background: var(--af-green-xl);
  color: var(--af-green-d);
  transform: translateX(2px);
}

/* Active state */
.af-active {
  background: var(--af-green-l) !important;
  color: var(--af-green-d) !important;
  border-color: var(--af-bdr2) !important;
  font-weight: 700;
  box-shadow: 0 2px 10px rgba(14,159,110,.1);
}
.af-active::before {
  content: ''; position: absolute;
  left: -8px; top: 50%; transform: translateY(-50%);
  width: 3px; height: 55%; border-radius: 0 3px 3px 0;
  background: var(--af-green);
}
.af-active .afs-ico { background: var(--af-green) !important; color: white !important; }

/* Sub-link */
.afs-sub { padding: 7px 9px 7px 16px; font-size: .77rem; }
.afs-sub .afs-ico { width: 24px; height: 24px; font-size: .64rem; }

/* Icon box */
.afs-ico {
  width: 30px; height: 30px; border-radius: 8px;
  display: flex; align-items: center; justify-content: center;
  font-size: .78rem; flex-shrink: 0;
  background: var(--af-green-xl); color: var(--af-green);
  transition: all .18s;
}
.afs-a:hover .afs-ico { background: var(--af-green-l); color: var(--af-green-d); }

/* Coloured icon variants */
.afs-ico-earn   { background: rgba(217,119,6,.1)   !important; color: var(--af-gold)  !important; }
.afs-ico-wd     { background: rgba(13,148,136,.12)  !important; color: var(--af-teal)  !important; }
.afs-ico-ticket { background: rgba(14,159,110,.12)  !important; color: var(--af-green) !important; }
.afs-ico-link   { background: rgba(99,102,241,.1)   !important; color: #6366f1        !important; }

/* ── Accordion group ── */
.afs-grp { margin-bottom: 1px; }
.afs-grp-btn {
  display: flex; align-items: center; gap: 9px;
  padding: 8px 9px; border-radius: 10px; width: 100%;
  background: transparent; border: 1.5px solid transparent;
  color: var(--af-txt);
  font-family: 'Plus Jakarta Sans', sans-serif;
  font-size: .8rem; font-weight: 500;
  cursor: pointer; transition: all .18s;
  text-align: left; white-space: nowrap;
}
.afs-grp-btn:hover { background: var(--af-green-xl); color: var(--af-green-d); }
.afs-grp-btn.open  {
  background: var(--af-green-xl);
  color: var(--af-green-d);
  border-color: var(--af-bdr);
}
.afs-chev {
  margin-left: auto; font-size: .58rem;
  color: var(--af-mut); flex-shrink: 0;
  transition: transform var(--af-trn);
}
.afs-grp-btn.open .afs-chev { transform: rotate(90deg); }
.afs-grp-items {
  max-height: 0; overflow: hidden;
  transition: max-height .32s ease;
  padding-left: 4px;
}
.afs-grp-items.open { max-height: 400px; }

/* ── Badges ── */
.afs-badge {
  font-family: 'Plus Jakarta Sans', sans-serif;
  font-size: .55rem; font-weight: 700;
  text-transform: uppercase; letter-spacing: .05em;
  padding: 2px 8px; border-radius: 100px;
  flex-shrink: 0;
}
.afs-badge-green {
  background: var(--af-green-l); color: var(--af-green-d);
  border: 1px solid var(--af-bdr2);
}
.afs-badge-warn {
  background: var(--af-warn-l); color: #92400e;
  border: 1px solid rgba(245,158,11,.3);
}

/* ── Divider + logout ── */
.afs-divider {
  height: 1.5px; background: var(--af-bdr);
  margin: 8px 4px;
}
.afs-logout { color: var(--af-red) !important; }
.afs-logout:hover {
  background: var(--af-red-l) !important;
  color: var(--af-red) !important;
  transform: none !important;
}
.afs-logout .afs-ico {
  background: var(--af-red-l) !important;
  color: var(--af-red) !important;
}

/* ════════════════════════════════════════════════════════════════
   SIDEBAR FOOTER
════════════════════════════════════════════════════════════════ */
.afs-footer {
  display: flex; align-items: center; justify-content: center; gap: 6px;
  padding: 10px 14px;
  border-top: 1.5px solid var(--af-bdr);
  flex-shrink: 0;
}
.afs-footer-txt {
  font-family: 'Plus Jakarta Sans', sans-serif;
  font-size: .58rem; font-weight: 600;
  color: var(--af-mut);
  letter-spacing: .05em;
}
.afs-footer-dot {
  width: 3px; height: 3px; border-radius: 50%;
  background: var(--af-bdr2);
}

/* ════════════════════════════════════════════════════════════════
   DESKTOP TOOLTIPS (collapsed only)
════════════════════════════════════════════════════════════════ */
@media (min-width: 992px) {
  #afSidebar.af-col .afs-a[data-tip]::after,
  #afSidebar.af-col .afs-grp-btn[data-tip]::after {
    content: attr(data-tip);
    position: absolute; left: calc(100% + 14px); top: 50%;
    transform: translateY(-50%);
    background: var(--af-green-d); color: white;
    padding: 5px 12px; border-radius: 8px;
    font-family: 'Plus Jakarta Sans', sans-serif;
    font-size: .73rem; font-weight: 600;
    white-space: nowrap; pointer-events: none;
    opacity: 0; transition: opacity .18s;
    border: 1px solid var(--af-bdr2);
    box-shadow: 0 4px 16px rgba(14,159,110,.25);
    z-index: 9999;
  }
  #afSidebar.af-col .afs-a[data-tip]:hover::after,
  #afSidebar.af-col .afs-grp-btn[data-tip]:hover::after { opacity: 1; }
}

/* ════════════════════════════════════════════════════════════════
   MOBILE BOTTOM NAV
════════════════════════════════════════════════════════════════ */
#afMobNav {
  display: none;
  position: fixed; bottom: 0; left: 0; right: 0; height: 64px;
  background: white;
  border-top: 1.5px solid var(--af-bdr);
  box-shadow: 0 -4px 24px rgba(14,159,110,.1);
  z-index: 1300;
  align-items: center; justify-content: space-around;
  padding: 0 4px;
}
.afmn-item {
  display: flex; flex-direction: column; align-items: center; gap: 3px;
  text-decoration: none; color: var(--af-mut);
  font-family: 'Plus Jakarta Sans', sans-serif;
  font-size: .5rem; font-weight: 700;
  text-transform: uppercase; letter-spacing: .05em;
  transition: color .2s; padding: 4px 8px; position: relative;
}
.afmn-item i { font-size: 1.15rem; }
.afmn-item.af-active,
.afmn-item:hover { color: var(--af-green); }
.afmn-dot {
  position: absolute; top: 2px; right: 5px;
  width: 7px; height: 7px; border-radius: 50%;
  background: var(--af-warn); border: 2px solid white;
}

.afmn-fab-wrap {
  position: relative;
  display: flex; align-items: center; justify-content: center;
}
.afmn-fab {
  width: 52px; height: 52px; border-radius: 50%;
  background: linear-gradient(135deg, var(--af-green), var(--af-teal));
  display: flex; align-items: center; justify-content: center;
  color: white; font-size: 1.15rem;
  margin-top: -20px; text-decoration: none;
  box-shadow: 0 4px 18px rgba(14,159,110,.45);
  transition: transform .22s, box-shadow .22s;
  flex-shrink: 0;
}
.afmn-fab:hover {
  transform: scale(1.08) translateY(-2px);
  box-shadow: 0 6px 22px rgba(14,159,110,.55);
}

/* ════════════════════════════════════════════════════════════════
   RESPONSIVE BREAKPOINTS
════════════════════════════════════════════════════════════════ */
@media (min-width: 992px) {
  #afHamburger { display: none !important; }
  #afMobNav    { display: none !important; }
  #afOverlay   { display: none !important; }

  /* Push main content right of sidebar */
  .af-content,
  .content-wrapper,
  .main-content {
    margin-left: var(--af-w) !important;
    transition: margin-left var(--af-trn);
  }
  #afSidebar.af-col ~ .af-content,
  #afSidebar.af-col ~ .content-wrapper,
  #afSidebar.af-col ~ .main-content {
    margin-left: var(--af-wc) !important;
  }
}

@media (max-width: 991px) {
  #afSidebar {
    transform: translateX(-100%);
    width: var(--af-w) !important;
    transition: transform var(--af-trn);
    box-shadow: none;
  }
  #afSidebar.mob-open {
    transform: translateX(0);
    box-shadow: var(--af-shadow);
  }
  .afs-col-btn  { display: none; }
  #afHamburger  { display: flex !important; }
  #afMobNav     { display: flex !important; }
}
</style>

<script>
(function () {
  'use strict';

  /* ── PAGE LOADER ── */
  window.addEventListener('load', function () {
    setTimeout(function () {
      var l = document.getElementById('afLoader');
      if (l) l.classList.add('hide');
    }, 1700);
  });

  var sb  = document.getElementById('afSidebar');
  var ovl = document.getElementById('afOverlay');

  /* ── MOBILE OPEN / CLOSE ── */
  window.afOpenSidebar = function () {
    if (sb)  sb.classList.add('mob-open');
    if (ovl) ovl.classList.add('show');
    document.body.style.overflow = 'hidden';
  };
  window.afCloseSidebar = function () {
    if (sb)  sb.classList.remove('mob-open');
    if (ovl) ovl.classList.remove('show');
    document.body.style.overflow = '';
  };

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') afCloseSidebar();
  });

  /* ── COLLAPSE (desktop) ── */
  var COL_KEY = 'af_sb_collapsed';

  function applyCollapse(collapsed) {
    if (!sb) return;
    if (collapsed) {
      sb.classList.add('af-col');
    } else {
      sb.classList.remove('af-col');
    }
  }

  // Restore saved state on desktop
  if (window.innerWidth >= 992) {
    applyCollapse(localStorage.getItem(COL_KEY) === '1');
  }

  window.afToggleCollapse = function () {
    if (window.innerWidth < 992) return;
    var isCol = sb && sb.classList.contains('af-col');
    applyCollapse(!isCol);
    localStorage.setItem(COL_KEY, isCol ? '0' : '1');
  };

  /* ── ACCORDION ── */
  window.afToggleGroup = function (btn) {
    var items  = btn.nextElementSibling;
    var isOpen = btn.classList.contains('open');

    // Close all other open groups
    document.querySelectorAll('.afs-grp-btn.open').forEach(function (b) {
      if (b !== btn) {
        b.classList.remove('open');
        var s = b.nextElementSibling;
        if (s) s.classList.remove('open');
      }
    });

    btn.classList.toggle('open', !isOpen);
    if (items) items.classList.toggle('open', !isOpen);
  };

  /* ── RESIZE HANDLER ── */
  window.addEventListener('resize', function () {
    if (window.innerWidth >= 992) {
      afCloseSidebar();
      applyCollapse(localStorage.getItem(COL_KEY) === '1');
    }
  });

})();
</script>