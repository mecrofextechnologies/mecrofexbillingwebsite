<?php
// ==================== SIDEBAR.PHP ====================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$connect_paths = [
    __DIR__ . '/includes/connect.php',
    __DIR__ . '/../includes/connect.php',
    __DIR__ . '/msanii/includes/connect.php',
    dirname(__DIR__) . '/includes/connect.php'
];

$connected = false;
foreach ($connect_paths as $path) {
    if (file_exists($path)) {
        require_once $path;
        $connected = true;
        break;
    }
}
if (!$connected) die('Database connection failed.');

if (empty($_SESSION['creator_id'])) {
    $_SESSION['intended_url'] = $_SERVER['REQUEST_URI'] ?? '/msanii/index.php';
    if (headers_sent()) {
        echo '<script>window.location.href="../login.php";</script>';
        exit;
    } else {
        header("Location: ../login.php");
        exit;
    }
}

$creator_id = $_SESSION['creator_id'];

if (!empty($_SESSION['force_password_change'])) {
    $current_page_chk = $_GET['page'] ?? 'home';
    if ($current_page_chk !== 'change_password') { ?>
        <script>
            window.location.replace("index.php?page=change_password");
            history.pushState(null, null, window.location.href);
            window.onpopstate = function () { window.location.replace("index.php?page=change_password"); };
        </script>
        <noscript><meta http-equiv="refresh" content="0;url=index.php?page=change_password"></noscript>
    <?php exit; }
}

// ── Creator profile data ──────────────────────────────────────
$creator_name = 'User';
$profile_pic  = '';
$initials     = 'U';

try {
    $stmt = $pdo->prepare("
        SELECT
            COALESCE(mp.full_name, c.username, 'User') AS display_name,
            mp.profile_pic,
            c.username
        FROM creators c
        LEFT JOIN my_page mp ON c.id = mp.creator_id
        WHERE c.id = ?
        LIMIT 1
    ");
    $stmt->execute([$creator_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $creator_name = !empty($row['display_name']) ? $row['display_name'] : ($row['username'] ?? 'User');
        $profile_pic  = $row['profile_pic'] ?? '';
    }
} catch (Exception $e) {}

$parts = array_filter(explode(' ', trim($creator_name)));
if (count($parts) >= 2) {
    $initials = strtoupper(mb_substr($parts[0],0,1) . mb_substr(end($parts),0,1));
} else {
    $initials = strtoupper(mb_substr($creator_name, 0, 2));
}

// ── Page exists check ─────────────────────────────────────────
$page_exists = false;
try {
    $stmt = $pdo->prepare("SELECT 1 FROM my_page WHERE creator_id = ? LIMIT 1");
    $stmt->execute([$creator_id]);
    $page_exists = $stmt->rowCount() > 0;
} catch (Exception $e) {}

// ── Unread notifications ──────────────────────────────────────
$notif_count = 0;
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE creator_id = ? AND is_read = 0");
    $stmt->execute([$creator_id]);
    $notif_count = (int)$stmt->fetchColumn();
} catch (Exception $e) {}

// ── Audio music counts ────────────────────────────────────────
$music_total      = 0;
$music_published  = 0;
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM music WHERE creator_id = ?");
    $stmt->execute([$creator_id]);
    $music_total = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM music WHERE creator_id = ? AND status = 'published'");
    $stmt->execute([$creator_id]);
    $music_published = (int)$stmt->fetchColumn();
} catch (Exception $e) {}

// ── Pending ticket purchases ──────────────────────────────────
$pending_tickets = 0;
try {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM ticket_purchases tp
        JOIN tickets t ON t.id = tp.ticket_id
        WHERE t.creator_id = ? AND tp.payment_status = 'pending'
    ");
    $stmt->execute([$creator_id]);
    $pending_tickets = (int)$stmt->fetchColumn();
} catch (Exception $e) {}

// ── Affiliate counts ──────────────────────────────────────────
$affiliate_total  = 0;
$affiliate_active = 0;
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM affiliates WHERE created_by = ?");
    $stmt->execute([$creator_id]);
    $affiliate_total = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM affiliates WHERE created_by = ? AND status = 'active'");
    $stmt->execute([$creator_id]);
    $affiliate_active = (int)$stmt->fetchColumn();
} catch (Exception $e) {}

$current_page = $_GET['page'] ?? 'home';

function kc_active($page, $matches) {
    if (is_array($matches)) return in_array($page, $matches) ? 'kcs-active' : '';
    return $page === $matches ? 'kcs-active' : '';
}
function kc_group_open($page, $matches) {
    return in_array($page, $matches) ? 'open' : '';
}
?>

<?php /* ══════════════════════════════════════════════════════
   GLOBAL PAGE LOADER
══════════════════════════════════════════════════════ */ ?>
<div id="kcLoader">
  <div class="kcl-inner">
    <div class="kcl-logo">
      <div class="kcl-ring"></div>
      <div class="kcl-ring kcl-ring2"></div>
      <div class="kcl-mark">
        <svg viewBox="0 0 40 40" fill="none" xmlns="http://www.w3.org/2000/svg">
          <path d="M8 8L20 20L8 32" stroke="#fec411" stroke-width="4" stroke-linecap="round" stroke-linejoin="round"/>
          <path d="M20 20H34" stroke="#ffffff" stroke-width="4" stroke-linecap="round"/>
        </svg>
      </div>
    </div>
    <div class="kcl-word">Key<span>chele</span></div>
    <div class="kcl-bar"><div class="kcl-fill"></div></div>
  </div>
</div>

<div id="kcOverlay" onclick="kcCloseSidebar()"></div>
<button id="kcHamburger" onclick="kcOpenSidebar()" aria-label="Open menu">
  <i class="fa-solid fa-bars"></i>
</button>

<?php /* ══════════════════════════════════════════════════════
   SIDEBAR
══════════════════════════════════════════════════════ */ ?>
<aside id="kcSidebar">

  <?php /* ── TOP BAR ── */ ?>
  <div class="kcs-top">
    <a href="index.php" class="kcs-brand">
      <div class="kcs-brand-ico">
        <svg viewBox="0 0 40 40" fill="none">
          <path d="M8 8L20 20L8 32" stroke="#fec411" stroke-width="4.5" stroke-linecap="round" stroke-linejoin="round"/>
          <path d="M20 20H34" stroke="#fff" stroke-width="4.5" stroke-linecap="round"/>
        </svg>
      </div>
      <span class="kcs-brand-name">Key<em>chele</em></span>
    </a>
    <button class="kcs-col-btn" id="kcColBtn" onclick="kcToggleCollapse()" title="Collapse sidebar">
      <i class="fa-solid fa-angles-left" id="kcColIcon"></i>
    </button>
  </div>

  <?php /* ── PROFILE CARD ── */ ?>
  <div class="kcs-profile">
    <div class="kcs-av">
      <?php if (!empty($profile_pic)): ?>
        <img src="<?= htmlspecialchars($profile_pic) ?>" alt="Profile">
      <?php else: ?>
        <?= htmlspecialchars($initials) ?>
      <?php endif; ?>
      <span class="kcs-av-dot"></span>
    </div>
    <div class="kcs-profile-info">
      <div class="kcs-pname"><?= htmlspecialchars($creator_name) ?></div>
      <div class="kcs-prole">Creator</div>
    </div>
    <div class="kcs-pbtns">
      <a href="index.php?page=notifications"
         class="kcs-pbtn <?= $notif_count > 0 ? 'kcs-pbtn-notif' : '' ?>"
         title="<?= $notif_count > 0 ? "$notif_count notification".($notif_count>1?'s':'') : 'Notifications' ?>">
        <i class="fa-solid fa-bell"></i>
        <?php if ($notif_count > 0): ?>
          <span class="kcs-nbadge"><?= $notif_count > 9 ? '9+' : $notif_count ?></span>
        <?php endif; ?>
      </a>
      <a href="index.php?page=my_profile" class="kcs-pbtn" title="Edit profile">
        <i class="fa-solid fa-pen-to-square"></i>
      </a>
      <a href="logout.php" class="kcs-pbtn kcs-pbtn-danger" title="Logout">
        <i class="fa-solid fa-arrow-right-from-bracket"></i>
      </a>
    </div>
  </div>

  <?php /* ── NAVIGATION ── */ ?>
  <nav class="kcs-nav">

    <?php /* ═══════ MAIN ═══════ */ ?>
    <div class="kcs-lbl">Main</div>

    <a href="index.php"
       class="kcs-a <?= kc_active($current_page, ['home','dashboard']) ?>"
       data-tip="Dashboard">
      <span class="kcs-ico"><i class="fa-solid fa-gauge-high"></i></span>
      <span class="kcs-txt">Dashboard</span>
    </a>

    <a href="index.php?page=my_profile"
       class="kcs-a <?= kc_active($current_page, 'my_profile') ?>"
       data-tip="My Profile">
      <span class="kcs-ico"><i class="fa-solid fa-circle-user"></i></span>
      <span class="kcs-txt">My Profile</span>
    </a>

    <a href="index.php?page=my_portfolio"
       class="kcs-a <?= kc_active($current_page, 'my_portfolio') ?>"
       data-tip="My Portfolio">
      <span class="kcs-ico"><i class="fa-solid fa-briefcase"></i></span>
      <span class="kcs-txt">My Portfolio</span>
    </a>

    <?php /* ═══════ FINANCE ═══════ */ ?>
    <div class="kcs-lbl">Finance</div>

    <a href="index.php?page=transactions"
       class="kcs-a <?= kc_active($current_page, 'transactions') ?>"
       data-tip="Transactions">
      <span class="kcs-ico"><i class="fa-solid fa-right-left"></i></span>
      <span class="kcs-txt">Transactions</span>
    </a>

    <a href="index.php?page=request_withdrawals"
       class="kcs-a <?= kc_active($current_page, 'request_withdrawals') ?>"
       data-tip="Withdraw Funds">
      <span class="kcs-ico"><i class="fa-solid fa-circle-dollar-to-slot"></i></span>
      <span class="kcs-txt">Withdraw Funds</span>
    </a>

    <a href="index.php?page=my_withdrawals"
       class="kcs-a <?= kc_active($current_page, 'my_withdrawals') ?>"
       data-tip="My Withdrawals">
      <span class="kcs-ico"><i class="fa-solid fa-clock-rotate-left"></i></span>
      <span class="kcs-txt">My Withdrawals</span>
    </a>

    <a href="index.php?page=my_wallet"
       class="kcs-a <?= kc_active($current_page, 'my_wallet') ?>"
       data-tip="My Wallet">
      <span class="kcs-ico"><i class="fa-solid fa-vault"></i></span>
      <span class="kcs-txt">My Wallet</span>
    </a>

    <?php /* ═══════ TOOLS ═══════ */ ?>
    <div class="kcs-lbl">Tools</div>

    <?php if ($page_exists): ?>
      <a href="index.php?page=my_page"
         class="kcs-a <?= kc_active($current_page, 'my_page') ?>"
         data-tip="My Page">
        <span class="kcs-ico"><i class="fa-solid fa-id-card"></i></span>
        <span class="kcs-txt">My Page</span>
      </a>
    <?php else: ?>
      <a href="index.php?page=create_page"
         class="kcs-a <?= kc_active($current_page, 'create_page') ?>"
         data-tip="Create Page">
        <span class="kcs-ico"><i class="fa-solid fa-circle-plus"></i></span>
        <span class="kcs-txt">Create a Page</span>
        <span class="kcs-badge kcs-badge-gold">New</span>
      </a>
    <?php endif; ?>

    <?php /* ── TICKETS ── */ ?>
    <div class="kcs-grp">
      <button class="kcs-grp-btn <?= kc_group_open($current_page, ['create_ticket','my_tickets','ticket_purchases','view_ticket_details','add_ticket','export_tickets_excel']) ?>"
              onclick="kcToggleGroup(this)" data-tip="Tickets">
        <span class="kcs-ico"><i class="fa-solid fa-ticket"></i></span>
        <span class="kcs-txt">Tickets</span>
        <?php if ($pending_tickets > 0): ?>
          <span class="kcs-badge kcs-badge-warn"><?= $pending_tickets ?></span>
        <?php endif; ?>
        <i class="fa-solid fa-chevron-right kcs-chev"></i>
      </button>
      <div class="kcs-grp-items <?= kc_group_open($current_page, ['create_ticket','my_tickets','ticket_purchases','view_ticket_details','add_ticket','export_tickets_excel']) ?>">

        <a href="index.php?page=create_ticket"
           class="kcs-a kcs-sub <?= kc_active($current_page,'create_ticket') ?>">
          <span class="kcs-ico"><i class="fa-solid fa-plus"></i></span>
          <span class="kcs-txt">Create Ticket</span>
        </a>

        <a href="index.php?page=my_tickets"
           class="kcs-a kcs-sub <?= kc_active($current_page,'my_tickets') ?>">
          <span class="kcs-ico"><i class="fa-solid fa-list-check"></i></span>
          <span class="kcs-txt">My Tickets</span>
        </a>

        <a href="index.php?page=ticket_purchases"
           class="kcs-a kcs-sub <?= kc_active($current_page,'ticket_purchases') ?>">
          <span class="kcs-ico"><i class="fa-solid fa-cart-shopping"></i></span>
          <span class="kcs-txt">Purchases</span>
          <?php if ($pending_tickets > 0): ?>
            <span class="kcs-badge kcs-badge-warn" style="margin-left:auto"><?= $pending_tickets ?></span>
          <?php endif; ?>
        </a>

        <a href="index.php?page=view_ticket_details"
           class="kcs-a kcs-sub <?= kc_active($current_page,'view_ticket_details') ?>">
          <span class="kcs-ico"><i class="fa-solid fa-magnifying-glass-chart"></i></span>
          <span class="kcs-txt">Ticket Details</span>
        </a>

        <a href="index.php?page=add_ticket"
           class="kcs-a kcs-sub <?= kc_active($current_page,'add_ticket') ?>">
          <span class="kcs-ico"><i class="fa-solid fa-square-plus"></i></span>
          <span class="kcs-txt">Add Ticket</span>
        </a>

        <a href="export_tickets_excel.php"
           class="kcs-a kcs-sub <?= kc_active($current_page,'export_tickets_excel') ?>">
          <span class="kcs-ico"><i class="fa-solid fa-file-csv"></i></span>
          <span class="kcs-txt">Export to CSV</span>
        </a>

      </div>
    </div>

    <?php /* ── AUDIO MUSIC ── */ ?>
    <div class="kcs-grp">
      <button class="kcs-grp-btn <?= kc_group_open($current_page, ['add_music','my_music','all_music','music_albums','music_analytics','music_earnings','upload_music']) ?>"
              onclick="kcToggleGroup(this)" data-tip="Audio Music">
        <span class="kcs-ico kcs-ico-music"><i class="fa-solid fa-music"></i></span>
        <span class="kcs-txt">Audio Music</span>
        <?php if ($music_published > 0): ?>
          <span class="kcs-badge kcs-badge-music"><?= $music_published ?> Live</span>
        <?php else: ?>
          <span class="kcs-badge kcs-badge-gold">New</span>
        <?php endif; ?>
        <i class="fa-solid fa-chevron-right kcs-chev"></i>
      </button>
      <div class="kcs-grp-items <?= kc_group_open($current_page, ['add_music','my_music','all_music','music_albums','music_analytics','music_earnings','upload_music']) ?>">

        <a href="index.php?page=add_music"
           class="kcs-a kcs-sub <?= kc_active($current_page,'add_music') ?>">
          <span class="kcs-ico"><i class="fa-solid fa-plus"></i></span>
          <span class="kcs-txt">Add New Music</span>
        </a>

        <a href="index.php?page=upload_music"
           class="kcs-a kcs-sub <?= kc_active($current_page,'upload_music') ?>">
          <span class="kcs-ico"><i class="fa-solid fa-cloud-arrow-up"></i></span>
          <span class="kcs-txt">Upload Music</span>
        </a>

        <a href="index.php?page=my_music"
           class="kcs-a kcs-sub <?= kc_active($current_page,'my_music') ?>">
          <span class="kcs-ico"><i class="fa-solid fa-headphones"></i></span>
          <span class="kcs-txt">My Music</span>
          <?php if ($music_total > 0): ?>
            <span class="kcs-badge kcs-badge-cyan" style="margin-left:auto"><?= $music_total ?></span>
          <?php endif; ?>
        </a>

        <a href="index.php?page=all_music"
           class="kcs-a kcs-sub <?= kc_active($current_page,'all_music') ?>">
          <span class="kcs-ico"><i class="fa-solid fa-compact-disc"></i></span>
          <span class="kcs-txt">All Music</span>
        </a>

        <a href="index.php?page=music_albums"
           class="kcs-a kcs-sub <?= kc_active($current_page,'music_albums') ?>">
          <span class="kcs-ico"><i class="fa-solid fa-record-vinyl"></i></span>
          <span class="kcs-txt">Albums</span>
        </a>

        <a href="index.php?page=music_analytics"
           class="kcs-a kcs-sub <?= kc_active($current_page,'music_analytics') ?>">
          <span class="kcs-ico"><i class="fa-solid fa-chart-line"></i></span>
          <span class="kcs-txt">Analytics</span>
        </a>

        <a href="index.php?page=music_earnings"
           class="kcs-a kcs-sub <?= kc_active($current_page,'music_earnings') ?>">
          <span class="kcs-ico"><i class="fa-solid fa-sack-dollar"></i></span>
          <span class="kcs-txt">Earnings</span>
        </a>

      </div>
    </div>

    <?php /* ── AFFILIATES ── */ ?>
    <div class="kcs-grp">
      <button class="kcs-grp-btn <?= kc_group_open($current_page, ['add_affiliate','all_affiliates','affiliate_settings','affiliate_links','affiliate_earnings']) ?>"
              onclick="kcToggleGroup(this)" data-tip="Affiliates">
        <span class="kcs-ico kcs-ico-affiliate"><i class="fa-solid fa-handshake"></i></span>
        <span class="kcs-txt">Affiliates</span>
        <?php if ($affiliate_active > 0): ?>
          <span class="kcs-badge kcs-badge-live"><?= $affiliate_active ?> Active</span>
        <?php elseif ($affiliate_total === 0): ?>
          <span class="kcs-badge kcs-badge-cyan">New</span>
        <?php endif; ?>
        <i class="fa-solid fa-chevron-right kcs-chev"></i>
      </button>
      <div class="kcs-grp-items <?= kc_group_open($current_page, ['add_affiliate','all_affiliates','affiliate_settings','affiliate_links','affiliate_earnings']) ?>">

        <a href="index.php?page=add_affiliate"
           class="kcs-a kcs-sub <?= kc_active($current_page,'add_affiliate') ?>">
          <span class="kcs-ico"><i class="fa-solid fa-user-plus"></i></span>
          <span class="kcs-txt">Add Affiliate</span>
        </a>

        <a href="index.php?page=all_affiliates"
           class="kcs-a kcs-sub <?= kc_active($current_page,'all_affiliates') ?>">
          <span class="kcs-ico"><i class="fa-solid fa-users"></i></span>
          <span class="kcs-txt">All Affiliates</span>
          <?php if ($affiliate_total > 0): ?>
            <span class="kcs-badge kcs-badge-cyan" style="margin-left:auto"><?= $affiliate_total ?></span>
          <?php endif; ?>
        </a>

        <a href="index.php?page=coming_soon"
           class="kcs-a kcs-sub <?= kc_active($current_page,'affiliate_links') ?>">
          <span class="kcs-ico"><i class="fa-solid fa-link"></i></span>
          <span class="kcs-txt">Affiliate Links</span>
        </a>

        <a href="index.php?page=coming_soon"
           class="kcs-a kcs-sub <?= kc_active($current_page,'affiliate_earnings') ?>">
          <span class="kcs-ico"><i class="fa-solid fa-sack-dollar"></i></span>
          <span class="kcs-txt">Earnings</span>
        </a>

        <a href="index.php?page=coming_soon"
           class="kcs-a kcs-sub <?= kc_active($current_page,'affiliate_settings') ?>">
          <span class="kcs-ico"><i class="fa-solid fa-sliders"></i></span>
          <span class="kcs-txt">Settings</span>
        </a>

      </div>
    </div>



<?php /* ── REFERRALS ── */ ?>
<div class="kcs-grp">
  <button class="kcs-grp-btn <?= kc_group_open($current_page, ['my_referrals','refer_creator','referral_earnings']) ?>"
          onclick="kcToggleGroup(this)" data-tip="Referrals">
    <span class="kcs-ico" style="background:rgba(254,196,17,0.14);color:#fec411"><i class="fa-solid fa-user-group"></i></span>
    <span class="kcs-txt">Referrals</span>
    <span class="kcs-badge kcs-badge-gold">Earn</span>
    <i class="fa-solid fa-chevron-right kcs-chev"></i>
  </button>
  <div class="kcs-grp-items <?= kc_group_open($current_page, ['my_referrals','refer_creator','referral_earnings']) ?>">

    <a href="index.php?page=refer_creator"
       class="kcs-a kcs-sub <?= kc_active($current_page,'refer_creator') ?>">
      <span class="kcs-ico"><i class="fa-solid fa-share-nodes"></i></span>
      <span class="kcs-txt">Refer a Creator</span>
    </a>

    <a href="index.php?page=coming_soon"
       class="kcs-a kcs-sub <?= kc_active($current_page,'my_referrals') ?>">
      <span class="kcs-ico"><i class="fa-solid fa-users-line"></i></span>
      <span class="kcs-txt">My Referrals</span>
    </a>

    <a href="index.php?page=coming_soon"
       class="kcs-a kcs-sub <?= kc_active($current_page,'referral_earnings') ?>">
      <span class="kcs-ico"><i class="fa-solid fa-sack-dollar"></i></span>
      <span class="kcs-txt">Referral Earnings</span>
    </a>

  </div>
</div>


    <?php /* ═══════ GAMES & FUN ═══════ */ ?>
    <div class="kcs-lbl">Games &amp; Fun</div>

    <?php /* ── GAMES ── */ ?>
    <div class="kcs-grp">
      <button class="kcs-grp-btn <?= kc_group_open($current_page, ['create_game','my_games']) ?>"
              onclick="kcToggleGroup(this)" data-tip="Games">
        <span class="kcs-ico kcs-ico-game"><i class="fa-solid fa-gamepad"></i></span>
        <span class="kcs-txt">Games</span>
        <span class="kcs-badge kcs-badge-gold">New</span>
        <i class="fa-solid fa-chevron-right kcs-chev"></i>
      </button>
      <div class="kcs-grp-items <?= kc_group_open($current_page, ['create_game','my_games']) ?>">

        <a href="index.php?page=create_game"
           class="kcs-a kcs-sub <?= kc_active($current_page,'create_game') ?>">
          <span class="kcs-ico"><i class="fa-solid fa-plus"></i></span>
          <span class="kcs-txt">Create Game</span>
        </a>

        <a href="index.php?page=my_games"
           class="kcs-a kcs-sub <?= kc_active($current_page,'my_games') ?>">
          <span class="kcs-ico"><i class="fa-solid fa-dice"></i></span>
          <span class="kcs-txt">My Games</span>
        </a>

      </div>
    </div>

    <?php /* ── CHALLENGES ── */ ?>
    <div class="kcs-grp">
      <button class="kcs-grp-btn <?= kc_group_open($current_page, ['create_challange','my_challenges','challenge_progress']) ?>"
              onclick="kcToggleGroup(this)" data-tip="Challenges">
        <span class="kcs-ico"><i class="fa-solid fa-trophy"></i></span>
        <span class="kcs-txt">Challenges</span>
        <i class="fa-solid fa-chevron-right kcs-chev"></i>
      </button>
      <div class="kcs-grp-items <?= kc_group_open($current_page, ['create_challange','my_challenges','challenge_progress']) ?>">

        <a href="index.php?page=create_challange"
           class="kcs-a kcs-sub <?= kc_active($current_page,'create_challange') ?>">
          <span class="kcs-ico"><i class="fa-solid fa-wand-magic-sparkles"></i></span>
          <span class="kcs-txt">Create Challenge</span>
        </a>

        <a href="index.php?page=my_challenges"
           class="kcs-a kcs-sub <?= kc_active($current_page,'my_challenges') ?>">
          <span class="kcs-ico"><i class="fa-solid fa-fire-flame-curved"></i></span>
          <span class="kcs-txt">My Challenges</span>
        </a>

        <a href="index.php?page=challenge_progress"
           class="kcs-a kcs-sub <?= kc_active($current_page,'challenge_progress') ?>">
          <span class="kcs-ico"><i class="fa-solid fa-chart-simple"></i></span>
          <span class="kcs-txt">Progress</span>
        </a>

      </div>
    </div>

    <?php /* ── SURVEYS ── */ ?>
    <div class="kcs-grp">
      <button class="kcs-grp-btn <?= kc_group_open($current_page, ['survey_form','my_surveys']) ?>"
              onclick="kcToggleGroup(this)" data-tip="Surveys">
        <span class="kcs-ico"><i class="fa-solid fa-square-poll-horizontal"></i></span>
        <span class="kcs-txt">Surveys</span>
        <i class="fa-solid fa-chevron-right kcs-chev"></i>
      </button>
      <div class="kcs-grp-items <?= kc_group_open($current_page, ['survey_form','my_surveys']) ?>">

        <a href="index.php?page=survey_form"
           class="kcs-a kcs-sub <?= kc_active($current_page,'survey_form') ?>">
          <span class="kcs-ico"><i class="fa-solid fa-plus"></i></span>
          <span class="kcs-txt">Create Survey</span>
        </a>

        <a href="index.php?page=my_surveys"
           class="kcs-a kcs-sub <?= kc_active($current_page,'my_surveys') ?>">
          <span class="kcs-ico"><i class="fa-solid fa-clipboard-list"></i></span>
          <span class="kcs-txt">My Surveys</span>
        </a>

      </div>
    </div>

    <?php /* ═══════ SUPPORT ═══════ */ ?>
    <div class="kcs-lbl">Support</div>

    <a href="index.php?page=my_tribe"
       class="kcs-a <?= kc_active($current_page,'my_tribe') ?>"
       data-tip="My Tribe">
      <span class="kcs-ico"><i class="fa-solid fa-people-group"></i></span>
      <span class="kcs-txt">My Tribe</span>
    </a>

    <a href="index.php?page=notifications"
       class="kcs-a <?= kc_active($current_page,'notifications') ?>"
       data-tip="Notifications">
      <span class="kcs-ico"><i class="fa-solid fa-bell"></i></span>
      <span class="kcs-txt">Notifications</span>
      <?php if ($notif_count > 0): ?>
        <span class="kcs-badge kcs-badge-red" style="margin-left:auto">
          <?= $notif_count > 99 ? '99+' : $notif_count ?>
        </span>
      <?php endif; ?>
    </a>

    <a href="index.php?page=support_center"
       class="kcs-a <?= kc_active($current_page,'support_center') ?>"
       data-tip="Support Center">
      <span class="kcs-ico"><i class="fa-solid fa-headset"></i></span>
      <span class="kcs-txt">Support Center</span>
    </a>

    <a href="index.php?page=settings"
       class="kcs-a <?= kc_active($current_page,'settings') ?>"
       data-tip="Settings">
      <span class="kcs-ico"><i class="fa-solid fa-gear"></i></span>
      <span class="kcs-txt">Settings</span>
    </a>

    <div class="kcs-divider"></div>

    <a href="logout.php" class="kcs-a kcs-logout" data-tip="Logout">
      <span class="kcs-ico"><i class="fa-solid fa-power-off"></i></span>
      <span class="kcs-txt">Logout</span>
    </a>

  </nav>
</aside>

<?php /* ══════════════════════════════════════════════════════
   MOBILE BOTTOM NAV
══════════════════════════════════════════════════════ */ ?>
<nav id="kcMobNav">

  <a href="index.php"
     class="kcmn-item <?= kc_active($current_page, ['home','dashboard']) ?>">
    <i class="fa-solid fa-gauge-high"></i>
    <span>Home</span>
  </a>

  <a href="index.php?page=my_wallet"
     class="kcmn-item <?= kc_active($current_page,'my_wallet') ?>">
    <i class="fa-solid fa-wallet"></i>
    <span>Wallet</span>
  </a>

  <?php /* FAB — quick create */ ?>
  <div class="kcmn-fab-wrap">
    <button class="kcmn-fab" id="kcMobFab" onclick="kcToggleFabMenu()" aria-label="Create new">
      <i class="fa-solid fa-plus" id="kcFabIcon"></i>
    </button>
    <div class="kcmn-fab-menu" id="kcFabMenu">
      <a href="index.php?page=add_music" class="kcmn-fab-item">
        <span class="kcmn-fab-ico" style="background:rgba(239,68,68,.18);color:#fca5a5"><i class="fa-solid fa-music"></i></span>
        <span>Add Music</span>
      </a>
      <a href="index.php?page=create_ticket" class="kcmn-fab-item">
        <span class="kcmn-fab-ico"><i class="fa-solid fa-ticket"></i></span>
        <span>New Ticket</span>
      </a>
      <a href="index.php?page=add_affiliate" class="kcmn-fab-item">
        <span class="kcmn-fab-ico" style="background:rgba(6,182,212,.18);color:#67e8f9"><i class="fa-solid fa-handshake"></i></span>
        <span>Add Affiliate</span>
      </a>
      <a href="index.php?page=create_game" class="kcmn-fab-item">
        <span class="kcmn-fab-ico" style="background:rgba(16,185,129,.18);color:#6ee7b7"><i class="fa-solid fa-gamepad"></i></span>
        <span>New Game</span>
      </a>
      <a href="index.php?page=create_challange" class="kcmn-fab-item">
        <span class="kcmn-fab-ico"><i class="fa-solid fa-trophy"></i></span>
        <span>Challenge</span>
      </a>
      <a href="index.php?page=survey_form" class="kcmn-fab-item">
        <span class="kcmn-fab-ico"><i class="fa-solid fa-square-poll-horizontal"></i></span>
        <span>Survey</span>
      </a>
    </div>
  </div>

  <a href="index.php?page=all_affiliates"
     class="kcmn-item <?= kc_active($current_page,['all_affiliates','add_affiliate','affiliate_links','affiliate_earnings','affiliate_settings']) ?>">
    <i class="fa-solid fa-handshake"></i>
    <span>Affiliates</span>
  </a>

  <a href="index.php?page=my_profile"
     class="kcmn-item <?= kc_active($current_page,'my_profile') ?>">
    <i class="fa-solid fa-circle-user"></i>
    <?php if ($notif_count > 0): ?><span class="kcmn-notif-dot"></span><?php endif; ?>
    <span>Profile</span>
  </a>

</nav>
<div id="kcFabOverlay" onclick="kcCloseFabMenu()"></div>


<?php /* ══════════════════════════════════════════════════════
   STYLES
══════════════════════════════════════════════════════ */ ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>

<style>
@font-face {
  font-family: 'GlacialI';
  src: url('https://db.onlinewebfonts.com/t/74f7b46c5a6a4374c10b2cb3ef8b4fc1.woff2') format('woff2');
  font-weight: 400;
}

/* ══════════════════════════════════════════
   CSS VARIABLES
══════════════════════════════════════════ */
:root {
  --sb-w:         260px;
  --sb-wc:        72px;
  --sb-bg:        #052e64;
  --sb-bg2:       #041f47;
  --sb-bg3:       #031530;
  --sb-bdr:       rgba(255,255,255,0.08);
  --sb-txt:       rgba(255,255,255,0.72);
  --sb-mut:       rgba(255,255,255,0.32);
  --gold:         #fec411;
  --gold-dim:     rgba(254,196,17,0.14);
  --red:          #bf1339;
  --red-l:        #e8143f;
  --red-dim:      rgba(191,19,57,0.18);
  --green:        #00b37e;
  --green-dim:    rgba(0,179,126,0.18);
  --purple:       #7c3aed;
  --purple-dim:   rgba(124,58,237,0.18);
  --game:         #10b981;
  --game-dim:     rgba(16,185,129,0.18);
  --cyan:         #06b6d4;
  --cyan-dim:     rgba(6,182,212,0.18);
  --music:        #ef4444;
  --music-dim:    rgba(239,68,68,0.18);
  --trn:          0.28s cubic-bezier(0.4,0,0.2,1);
}

/* ══════════════════════════════════════════
   PAGE LOADER
══════════════════════════════════════════ */
#kcLoader {
  position: fixed; inset: 0; z-index: 99999;
  background: var(--sb-bg3);
  display: flex; align-items: center; justify-content: center;
  transition: opacity 0.5s, visibility 0.5s;
}
#kcLoader.hide { opacity: 0; visibility: hidden; pointer-events: none; }
.kcl-inner { display: flex; flex-direction: column; align-items: center; gap: 1rem; }
.kcl-logo {
  position: relative; width: 68px; height: 68px;
  display: flex; align-items: center; justify-content: center;
}
.kcl-ring {
  position: absolute; inset: 0; border-radius: 50%;
  border: 3px solid transparent; border-top-color: var(--gold);
  animation: kcSpin .9s linear infinite;
}
.kcl-ring2 {
  inset: 9px; border-top-color: rgba(191,19,57,.6); border-right-color: rgba(254,196,17,.3);
  animation-duration: 1.4s; animation-direction: reverse;
}
@keyframes kcSpin { to { transform: rotate(360deg); } }
.kcl-mark { position: relative; z-index: 1; width: 30px; height: 30px; }
.kcl-mark svg { width: 100%; height: 100%; }
.kcl-word {
  font-family: 'Montserrat', sans-serif; font-weight: 900; font-size: 1.5rem;
  color: white; letter-spacing: -0.05em;
}
.kcl-word span { color: var(--gold); }
.kcl-bar { width: 110px; height: 3px; background: rgba(255,255,255,.1); border-radius: 100px; overflow: hidden; }
.kcl-fill {
  height: 100%; background: linear-gradient(90deg, var(--red), var(--gold));
  border-radius: 100px; animation: kcBarFill 1.7s ease forwards;
}
@keyframes kcBarFill { from { width: 0 } to { width: 100% } }

/* ══════════════════════════════════════════
   OVERLAY & HAMBURGER
══════════════════════════════════════════ */
#kcOverlay {
  display: none; position: fixed; inset: 0;
  background: rgba(2,10,28,.72); backdrop-filter: blur(5px); z-index: 1099;
}
#kcOverlay.show { display: block; }
#kcHamburger {
  display: none; position: fixed; top: 12px; left: 12px; z-index: 1200;
  width: 42px; height: 42px; border-radius: 11px;
  background: var(--sb-bg); border: 1px solid var(--sb-bdr);
  color: white; font-size: 1rem; align-items: center; justify-content: center;
  cursor: pointer; box-shadow: 0 4px 18px rgba(0,0,0,.4); transition: background .2s;
}
#kcHamburger:hover { background: var(--sb-bg2); }

/* ══════════════════════════════════════════
   SIDEBAR SHELL
══════════════════════════════════════════ */
#kcSidebar {
  position: fixed; top: 0; left: 0;
  width: var(--sb-w); height: 100vh;
  background: var(--sb-bg);
  display: flex; flex-direction: column;
  z-index: 1100; overflow: hidden;
  border-right: 1px solid var(--sb-bdr);
  box-shadow: 6px 0 48px rgba(0,0,0,.32);
  transition: width var(--trn), transform var(--trn);
}
#kcSidebar::before {
  content: ''; position: absolute; inset: 0; pointer-events: none;
  background:
    radial-gradient(ellipse at 120% -10%, rgba(191,19,57,.25) 0%, transparent 52%),
    radial-gradient(ellipse at -15% 100%, rgba(254,196,17,.09) 0%, transparent 50%),
    radial-gradient(ellipse at 50% 55%,   rgba(124,58,237,.05) 0%, transparent 65%);
}
#kcSidebar > * { position: relative; z-index: 1; }

/* ── COLLAPSED ── */
#kcSidebar.kc-col { width: var(--sb-wc); }
#kcSidebar.kc-col .kcs-brand-name,
#kcSidebar.kc-col .kcs-profile-info,
#kcSidebar.kc-col .kcs-pbtns,
#kcSidebar.kc-col .kcs-txt,
#kcSidebar.kc-col .kcs-lbl,
#kcSidebar.kc-col .kcs-chev,
#kcSidebar.kc-col .kcs-badge,
#kcSidebar.kc-col .kcs-grp-items { display: none !important; }
#kcSidebar.kc-col .kcs-profile   { justify-content: center; padding: 14px 8px; }
#kcSidebar.kc-col .kcs-a,
#kcSidebar.kc-col .kcs-grp-btn   { justify-content: center; padding: 10px 0; }
#kcSidebar.kc-col .kcs-ico       { margin: 0; }
#kcSidebar.kc-col #kcColIcon     { transform: rotate(180deg); }

/* ══════════════════════════════════════════
   TOP BAR
══════════════════════════════════════════ */
.kcs-top {
  display: flex; align-items: center; justify-content: space-between;
  padding: 16px 14px 13px; border-bottom: 1px solid var(--sb-bdr); flex-shrink: 0;
}
.kcs-brand { display: flex; align-items: center; gap: 10px; text-decoration: none; }
.kcs-brand-ico {
  width: 34px; height: 34px;
  background: rgba(254,196,17,.12); border: 1px solid rgba(254,196,17,.22);
  border-radius: 9px; display: flex; align-items: center; justify-content: center;
  padding: 6px; flex-shrink: 0;
}
.kcs-brand-ico svg { width: 100%; height: 100%; }
.kcs-brand-name {
  font-family: 'Montserrat', sans-serif; font-weight: 900; font-size: 1.08rem;
  color: white; letter-spacing: -0.04em; white-space: nowrap;
}
.kcs-brand-name em { font-style: normal; color: var(--gold); }
.kcs-col-btn {
  width: 28px; height: 28px; border-radius: 7px;
  background: rgba(255,255,255,.06); border: 1px solid var(--sb-bdr);
  color: var(--sb-mut); display: flex; align-items: center; justify-content: center;
  cursor: pointer; font-size: .68rem; transition: background .2s, color .2s; flex-shrink: 0;
}
.kcs-col-btn:hover { background: rgba(255,255,255,.14); color: white; }
#kcColIcon { transition: transform var(--trn); }

/* ══════════════════════════════════════════
   PROFILE CARD
══════════════════════════════════════════ */
.kcs-profile {
  display: flex; align-items: center; gap: 9px;
  padding: 11px 14px; border-bottom: 1px solid var(--sb-bdr);
  background: rgba(0,0,0,.15); flex-shrink: 0; transition: padding var(--trn);
}
.kcs-av {
  position: relative; width: 40px; height: 40px; border-radius: 50%;
  background: linear-gradient(135deg, var(--red), var(--red-l));
  display: flex; align-items: center; justify-content: center;
  font-family: 'Montserrat', sans-serif; font-weight: 800; font-size: .85rem;
  color: white; flex-shrink: 0; border: 2px solid rgba(255,255,255,.14); overflow: hidden;
}
.kcs-av img { width: 100%; height: 100%; object-fit: cover; border-radius: 50%; }
.kcs-av-dot {
  position: absolute; bottom: 1px; right: 1px;
  width: 9px; height: 9px; border-radius: 50%;
  background: var(--green); border: 2px solid var(--sb-bg);
}
.kcs-profile-info { flex: 1; min-width: 0; overflow: hidden; }
.kcs-pname {
  font-family: 'Montserrat', sans-serif; font-weight: 700; font-size: .8rem;
  color: white; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.kcs-prole {
  font-family: 'GlacialI', sans-serif; font-size: .65rem; color: var(--gold);
  text-transform: uppercase; letter-spacing: .06em; margin-top: 1px;
}
.kcs-pbtns { display: flex; gap: 4px; flex-shrink: 0; }
.kcs-pbtn {
  position: relative; width: 26px; height: 26px; border-radius: 6px;
  background: rgba(255,255,255,.06); border: 1px solid rgba(255,255,255,.08);
  display: flex; align-items: center; justify-content: center;
  color: var(--sb-mut); font-size: .68rem; text-decoration: none; transition: all .18s;
}
.kcs-pbtn:hover              { background: rgba(255,255,255,.14); color: white; }
.kcs-pbtn-notif              { color: var(--gold) !important; background: var(--gold-dim) !important; border-color: rgba(254,196,17,.25) !important; }
.kcs-pbtn-danger:hover       { background: var(--red-dim); color: #ff8fa3; }
.kcs-nbadge {
  position: absolute; top: -5px; right: -5px;
  min-width: 15px; height: 15px; padding: 0 3px;
  background: var(--red-l); color: white; border-radius: 999px;
  font-family: 'Montserrat', sans-serif; font-size: .5rem; font-weight: 800;
  display: flex; align-items: center; justify-content: center;
  border: 2px solid var(--sb-bg); line-height: 1;
}

/* ══════════════════════════════════════════
   NAV SCROLL
══════════════════════════════════════════ */
.kcs-nav {
  flex: 1; overflow-y: auto; overflow-x: hidden;
  padding: 8px 8px 90px;
  scrollbar-width: thin; scrollbar-color: rgba(255,255,255,.1) transparent;
}
.kcs-nav::-webkit-scrollbar { width: 3px; }
.kcs-nav::-webkit-scrollbar-thumb { background: rgba(255,255,255,.1); border-radius: 3px; }

.kcs-lbl {
  font-family: 'Montserrat', sans-serif; font-size: .57rem; font-weight: 700;
  text-transform: uppercase; letter-spacing: .13em;
  color: var(--sb-mut); padding: 14px 8px 5px; white-space: nowrap;
}

/* ── NAV LINK ── */
.kcs-a {
  display: flex; align-items: center; gap: 9px;
  padding: 8px 9px; border-radius: 9px;
  text-decoration: none; color: var(--sb-txt);
  font-family: 'GlacialI', 'Glacial Indifference', sans-serif; font-size: .83rem;
  transition: background .16s, color .16s, transform .16s;
  position: relative; white-space: nowrap; margin-bottom: 1px;
  border: 1px solid transparent;
}
.kcs-a:hover { background: rgba(255,255,255,.07); color: white; transform: translateX(3px); }
.kcs-active {
  background: linear-gradient(135deg, rgba(191,19,57,.36), rgba(191,19,57,.14)) !important;
  color: white !important; border-color: rgba(191,19,57,.26) !important;
  box-shadow: 0 2px 16px rgba(191,19,57,.14);
}
.kcs-active::before {
  content: ''; position: absolute; left: -8px; top: 50%; transform: translateY(-50%);
  width: 3px; height: 55%; border-radius: 0 3px 3px 0; background: var(--gold);
}
.kcs-active .kcs-ico { color: var(--gold) !important; background: var(--gold-dim) !important; }
.kcs-sub { padding: 7px 9px 7px 13px; font-size: .78rem; }
.kcs-sub .kcs-ico { width: 24px; height: 24px; font-size: .65rem; }

/* ── ICON BOX ── */
.kcs-ico {
  width: 30px; height: 30px; border-radius: 8px;
  display: flex; align-items: center; justify-content: center;
  font-size: .8rem; flex-shrink: 0;
  background: rgba(255,255,255,.05); color: rgba(255,255,255,.55);
  transition: background .16s, color .16s;
}
.kcs-a:hover .kcs-ico      { background: var(--gold-dim); color: rgba(255,255,255,.9); }
.kcs-ico-music             { background: var(--music-dim) !important; color: #fca5a5 !important; }
.kcs-ico-game              { background: var(--game-dim) !important; color: #6ee7b7 !important; }
.kcs-ico-affiliate         { background: var(--cyan-dim) !important; color: #67e8f9 !important; }

/* ── ACCORDION ── */
.kcs-grp { margin-bottom: 1px; }
.kcs-grp-btn {
  display: flex; align-items: center; gap: 9px;
  padding: 8px 9px; border-radius: 9px;
  width: 100%; background: transparent; border: none;
  color: var(--sb-txt);
  font-family: 'GlacialI', 'Glacial Indifference', sans-serif; font-size: .83rem;
  cursor: pointer; transition: background .16s, color .16s; text-align: left; white-space: nowrap;
}
.kcs-grp-btn:hover { background: rgba(255,255,255,.07); color: white; }
.kcs-grp-btn.open  { background: rgba(255,255,255,.05); color: white; }
.kcs-grp-btn .kcs-ico { background: rgba(255,255,255,.05); color: rgba(255,255,255,.55); }
.kcs-grp-btn:hover .kcs-ico { background: var(--gold-dim); color: rgba(255,255,255,.9); }
.kcs-chev {
  margin-left: auto; font-size: .58rem; color: var(--sb-mut);
  flex-shrink: 0; transition: transform var(--trn);
}
.kcs-grp-btn.open .kcs-chev { transform: rotate(90deg); }
.kcs-grp-items { max-height: 0; overflow: hidden; transition: max-height .35s ease; padding-left: 4px; }
.kcs-grp-items.open { max-height: 700px; }

/* ── BADGES ── */
.kcs-badge {
  font-family: 'Montserrat', sans-serif; font-size: .52rem; font-weight: 800;
  text-transform: uppercase; letter-spacing: .06em;
  padding: 2px 7px; border-radius: 100px;
  margin-left: auto; flex-shrink: 0; white-space: nowrap;
}
.kcs-badge-gold  { background: var(--gold-dim); color: var(--gold); border: 1px solid rgba(254,196,17,.28); }
.kcs-badge-live  {
  background: var(--green-dim); color: #34d399; border: 1px solid rgba(0,179,126,.3);
  animation: kcPulse 2.5s ease-in-out infinite;
}
.kcs-badge-music {
  background: var(--music-dim); color: #fca5a5; border: 1px solid rgba(239,68,68,.3);
  animation: kcPulse 2.5s ease-in-out infinite;
}
.kcs-badge-warn  { background: rgba(245,158,11,.18); color: #fbbf24; border: 1px solid rgba(245,158,11,.3); }
.kcs-badge-red   { background: var(--red-dim); color: #fca5a5; border: 1px solid rgba(191,19,57,.3); }
.kcs-badge-cyan  { background: var(--cyan-dim); color: #67e8f9; border: 1px solid rgba(6,182,212,.3); }
@keyframes kcPulse { 0%,100% { opacity:1 } 50% { opacity:.6 } }

/* ── DIVIDER & LOGOUT ── */
.kcs-divider { height: 1px; background: var(--sb-bdr); margin: 8px 4px; }
.kcs-logout { color: rgba(255,130,145,.75); }
.kcs-logout:hover { background: rgba(191,19,57,.15) !important; color: #ffaab5 !important; transform: none !important; }
.kcs-logout .kcs-ico { background: rgba(191,19,57,.14); color: rgba(255,130,145,.75); }

/* ══════════════════════════════════════════
   DESKTOP TOOLTIPS (collapsed)
══════════════════════════════════════════ */
@media (min-width: 992px) {
  #kcSidebar.kc-col .kcs-a[data-tip],
  #kcSidebar.kc-col .kcs-grp-btn[data-tip] { position: relative; }
  #kcSidebar.kc-col .kcs-a[data-tip]::after,
  #kcSidebar.kc-col .kcs-grp-btn[data-tip]::after {
    content: attr(data-tip);
    position: absolute; left: calc(100% + 14px); top: 50%; transform: translateY(-50%);
    background: #082a5e; color: white; padding: 5px 12px; border-radius: 8px;
    font-family: 'GlacialI', sans-serif; font-size: .78rem;
    white-space: nowrap; pointer-events: none; opacity: 0; transition: opacity .18s;
    border: 1px solid rgba(255,255,255,.1); box-shadow: 0 4px 20px rgba(0,0,0,.35); z-index: 9999;
  }
  #kcSidebar.kc-col .kcs-a[data-tip]:hover::after,
  #kcSidebar.kc-col .kcs-grp-btn[data-tip]:hover::after { opacity: 1; }
}

/* ══════════════════════════════════════════
   MOBILE BOTTOM NAV
══════════════════════════════════════════ */
#kcMobNav {
  display: none; position: fixed; bottom: 0; left: 0; right: 0;
  height: 64px; background: var(--sb-bg2); border-top: 1px solid var(--sb-bdr);
  z-index: 1300; align-items: center; justify-content: space-around;
  padding: 0 4px; box-shadow: 0 -4px 28px rgba(0,0,0,.4);
}
.kcmn-item {
  display: flex; flex-direction: column; align-items: center; gap: 3px;
  text-decoration: none; color: var(--sb-mut);
  font-family: 'Montserrat', sans-serif; font-size: .48rem; font-weight: 700;
  text-transform: uppercase; letter-spacing: .05em;
  transition: color .2s; padding: 4px 6px; position: relative;
}
.kcmn-item i { font-size: 1.1rem; }
.kcmn-item.kcs-active,
.kcmn-item:hover { color: var(--gold); }
.kcmn-notif-dot {
  position: absolute; top: 2px; right: 4px; width: 7px; height: 7px; border-radius: 50%;
  background: var(--red-l); border: 1.5px solid var(--sb-bg2);
}

/* FAB wrapper */
.kcmn-fab-wrap { position: relative; display: flex; align-items: center; justify-content: center; }
.kcmn-fab {
  width: 52px; height: 52px; border-radius: 50%;
  background: linear-gradient(135deg, var(--red), var(--red-l));
  display: flex; align-items: center; justify-content: center;
  color: white; font-size: 1.1rem; border: none; margin-top: -18px;
  cursor: pointer; box-shadow: 0 4px 22px rgba(191,19,57,.55);
  transition: transform .25s, box-shadow .25s, background .25s;
  flex-shrink: 0; z-index: 10;
}
.kcmn-fab.open {
  background: linear-gradient(135deg, #334155, #1e293b);
  box-shadow: 0 4px 22px rgba(0,0,0,.4); transform: rotate(45deg);
}

/* FAB menu */
.kcmn-fab-menu {
  position: absolute; bottom: 62px; left: 50%;
  transform: translateX(-50%) translateY(10px);
  background: #0a2346; border: 1px solid rgba(255,255,255,.1);
  border-radius: 16px; padding: 8px;
  display: flex; flex-direction: column; gap: 2px;
  min-width: 175px;
  box-shadow: 0 8px 36px rgba(0,0,0,.55);
  opacity: 0; pointer-events: none;
  transition: opacity .22s, transform .22s; z-index: 1400;
}
.kcmn-fab-menu.show { opacity: 1; pointer-events: all; transform: translateX(-50%) translateY(0); }
.kcmn-fab-item {
  display: flex; align-items: center; gap: 10px;
  padding: 9px 12px; border-radius: 10px; text-decoration: none;
  color: rgba(255,255,255,.82);
  font-family: 'GlacialI', sans-serif; font-size: .82rem;
  transition: background .15s, color .15s;
}
.kcmn-fab-item:hover { background: rgba(255,255,255,.08); color: white; }
.kcmn-fab-ico {
  width: 28px; height: 28px; border-radius: 8px;
  background: rgba(255,255,255,.07); display: flex; align-items: center; justify-content: center;
  font-size: .75rem; color: var(--gold); flex-shrink: 0;
}

/* FAB overlay */
#kcFabOverlay { display: none; position: fixed; inset: 0; z-index: 1250; }
#kcFabOverlay.show { display: block; }

/* ══════════════════════════════════════════
   RESPONSIVE
══════════════════════════════════════════ */
@media (min-width: 992px) {
  #kcHamburger { display: none !important; }
  #kcMobNav    { display: none !important; }
  #kcOverlay   { display: none !important; }
  .content-wrapper,
  .main-content,
  [class*="main-content"],
  .wrapper > .content-wrapper {
    margin-left: var(--sb-w) !important;
    transition: margin-left var(--trn);
  }
  #kcSidebar.kc-col ~ .content-wrapper,
  #kcSidebar.kc-col ~ .main-content {
    margin-left: var(--sb-wc) !important;
  }
}
@media (max-width: 991px) {
  #kcSidebar { transform: translateX(-100%); width: var(--sb-w) !important; transition: transform var(--trn); }
  #kcSidebar.mob-open { transform: translateX(0); }
  .kcs-col-btn { display: none; }
  #kcHamburger { display: flex !important; }
  #kcMobNav    { display: flex !important; }
}
</style>


<?php /* ══════════════════════════════════════════════════════
   JAVASCRIPT
══════════════════════════════════════════════════════ */ ?>
<script>
(function () {

  /* LOADER */
  window.addEventListener('load', function () {
    setTimeout(function () {
      var l = document.getElementById('kcLoader');
      if (l) l.classList.add('hide');
    }, 1800);
  });

  var sidebar    = document.getElementById('kcSidebar');
  var overlay    = document.getElementById('kcOverlay');
  var hamBtn     = document.getElementById('kcHamburger');
  var colBtn     = document.getElementById('kcColBtn');
  var fabBtn     = document.getElementById('kcMobFab');
  var fabMenu    = document.getElementById('kcFabMenu');
  var fabOverlay = document.getElementById('kcFabOverlay');

  /* ── MOBILE SIDEBAR ── */
  window.kcOpenSidebar = function () {
    if (!sidebar) return;
    sidebar.classList.add('mob-open');
    if (overlay) overlay.classList.add('show');
    document.body.style.overflow = 'hidden';
  };
  window.kcCloseSidebar = function () {
    if (!sidebar) return;
    sidebar.classList.remove('mob-open');
    if (overlay) overlay.classList.remove('show');
    document.body.style.overflow = '';
  };
  if (hamBtn)  hamBtn.addEventListener('click', function(e){ e.stopPropagation(); kcOpenSidebar(); });
  if (overlay) overlay.addEventListener('click', kcCloseSidebar);
  document.addEventListener('keydown', function(e){ if (e.key==='Escape'){ kcCloseSidebar(); kcCloseFabMenu(); } });

  /* ── DESKTOP COLLAPSE ── */
  var COL_KEY = 'kc_sb_collapsed';
  function applyCollapse(col) {
    if (!sidebar) return;
    col ? sidebar.classList.add('kc-col') : sidebar.classList.remove('kc-col');
  }
  if (window.innerWidth >= 992) applyCollapse(localStorage.getItem(COL_KEY) === '1');
  window.kcToggleCollapse = function () {
    if (window.innerWidth < 992) return;
    var isCol = sidebar.classList.contains('kc-col');
    applyCollapse(!isCol);
    localStorage.setItem(COL_KEY, isCol ? '0' : '1');
  };
  if (colBtn) colBtn.addEventListener('click', function(e){ e.stopPropagation(); kcToggleCollapse(); });

  /* ── ACCORDION ── */
  window.kcToggleGroup = function (btn) {
    var items  = btn.nextElementSibling;
    var isOpen = btn.classList.contains('open');
    document.querySelectorAll('.kcs-grp-btn.open').forEach(function(b) {
      if (b !== btn) { b.classList.remove('open'); var s=b.nextElementSibling; if(s) s.classList.remove('open'); }
    });
    btn.classList.toggle('open', !isOpen);
    if (items) items.classList.toggle('open', !isOpen);
  };

  /* ── MOBILE FAB MENU ── */
  window.kcToggleFabMenu = function () {
    if (!fabMenu) return;
    fabMenu.classList.contains('show') ? kcCloseFabMenu() : kcOpenFabMenu();
  };
  function kcOpenFabMenu() {
    if (fabMenu)    fabMenu.classList.add('show');
    if (fabBtn)     fabBtn.classList.add('open');
    if (fabOverlay) fabOverlay.classList.add('show');
  }
  window.kcCloseFabMenu = function () {
    if (fabMenu)    fabMenu.classList.remove('show');
    if (fabBtn)     fabBtn.classList.remove('open');
    if (fabOverlay) fabOverlay.classList.remove('show');
  };

  /* ── RESIZE ── */
  window.addEventListener('resize', function () {
    if (window.innerWidth >= 992) {
      kcCloseSidebar();
      applyCollapse(localStorage.getItem(COL_KEY) === '1');
    }
  });

})();
</script>