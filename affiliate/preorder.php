<?php
// preorder.php — Public pre-order purchase page
if (session_status() === PHP_SESSION_NONE) session_start();

ini_set('display_errors', 1);
error_reporting(E_ALL);

$connect_path = file_exists('msanii/includes/connect.php')
    ? 'msanii/includes/connect.php' : 'includes/connect.php';
if (!file_exists($connect_path)) die("Database connection file missing.");
require_once $connect_path;

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { header("Location: index.php"); exit; }

// ── Load pre-order ────────────────────────────────
$stmt = $pdo->prepare("SELECT p.*, u.name AS creator_name, u.email AS creator_email
    FROM preorders p
    LEFT JOIN users u ON u.id = p.creator_id
    WHERE p.id = ? AND p.status IN ('active','closed','fulfilled')");
$stmt->execute([$id]);
$po = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$po) { header("Location: index.php"); exit; }

// ── Load tiers ────────────────────────────────────
$stmt2 = $pdo->prepare("SELECT * FROM preorder_tiers WHERE preorder_id=? ORDER BY sort_order");
$stmt2->execute([$id]);
$tiers = $stmt2->fetchAll(PDO::FETCH_ASSOC);

// ── Load stats ────────────────────────────────────
$stmt3 = $pdo->prepare("
    SELECT
        COUNT(*) AS total_orders,
        COALESCE(SUM(quantity),0) AS total_units,
        COALESCE(SUM(CASE WHEN payment_status='completed' THEN total_amount ELSE 0 END),0) AS revenue
    FROM preorder_purchases WHERE preorder_id=? AND payment_status='completed'
");
$stmt3->execute([$id]);
$stats = $stmt3->fetch(PDO::FETCH_ASSOC);

// ── Load updates ──────────────────────────────────
$stmt4 = $pdo->prepare("SELECT * FROM preorder_updates WHERE preorder_id=? AND is_public=1 ORDER BY created_at DESC LIMIT 5");
$stmt4->execute([$id]);
$updates = $stmt4->fetchAll(PDO::FETCH_ASSOC);

// ── Load reviews ──────────────────────────────────
$stmt5 = $pdo->prepare("SELECT * FROM preorder_reviews WHERE preorder_id=? ORDER BY created_at DESC LIMIT 8");
$stmt5->execute([$id]);
$reviews = $stmt5->fetchAll(PDO::FETCH_ASSOC);

// ── Parse JSON fields ─────────────────────────────
$specs  = json_decode($po['specs']  ?? '{}', true) ?: [];
$faqs   = json_decode($po['faqs']   ?? '[]', true) ?: [];
$perks  = json_decode($po['perks']  ?? '[]', true) ?: [];

// ── Deadline status ───────────────────────────────
$is_closed    = $po['status'] === 'closed' || $po['status'] === 'fulfilled';
$deadline_ts  = $po['preorder_deadline'] ? strtotime($po['preorder_deadline']) : null;
$deadline_passed = $deadline_ts && $deadline_ts < time();
$can_order    = !$is_closed && !$deadline_passed;

// ── Progress ──────────────────────────────────────
$goal_units  = (int)($po['goal_units']  ?? 0);
$total_units = (int)($stats['total_units'] ?? 0);
$pct         = $goal_units > 0 ? min(100, round(($total_units / $goal_units) * 100)) : null;

// ── Stock check ───────────────────────────────────
$stock_left = null;
if ($po['total_stock']) {
    $stock_left = max(0, (int)$po['total_stock'] - $total_units);
    if ($stock_left === 0) $can_order = false;
}

// ── HANDLE ORDER SUBMISSION ───────────────────────
$order_success = false;
$order_error   = '';
$order_ref     = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['place_order']) && $can_order) {
    $buyer_name  = trim($_POST['buyer_name']  ?? '');
    $buyer_email = trim($_POST['buyer_email'] ?? '');
    $buyer_phone = trim($_POST['buyer_phone'] ?? '');
    $quantity    = max(1, (int)($_POST['quantity'] ?? 1));
    $tier_id     = (int)($_POST['tier_id'] ?? 0);

    $ship_line1  = trim($_POST['ship_line1']  ?? '');
    $ship_city   = trim($_POST['ship_city']   ?? '');
    $ship_postal = trim($_POST['ship_postal'] ?? '');
    $ship_country= trim($_POST['ship_country']?? 'Kenya');

    if (empty($buyer_name) || empty($buyer_email)) {
        $order_error = 'Please fill in your name and email.';
    } elseif (!filter_var($buyer_email, FILTER_VALIDATE_EMAIL)) {
        $order_error = 'Please enter a valid email address.';
    } else {
        // Determine unit price
        $unit_price = (float)$po['price'];
        $use_tier_id = null;
        if ($tier_id > 0) {
            foreach ($tiers as $t) {
                if ($t['id'] == $tier_id) { $unit_price = (float)$t['price']; $use_tier_id = $tier_id; break; }
            }
        }

        // Enforce qty limits
        if ($po['max_qty'] && $quantity > $po['max_qty']) $quantity = (int)$po['max_qty'];
        if ($quantity < (int)$po['min_qty']) $quantity = (int)$po['min_qty'];

        $total = $unit_price * $quantity;
        $order_num = 'PRE-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));

        $shipping = null;
        if ($po['delivery_type'] !== 'digital') {
            $shipping = json_encode([
                'line1'   => $ship_line1,
                'city'    => $ship_city,
                'postal'  => $ship_postal,
                'country' => $ship_country,
            ]);
        }

        $ins = $pdo->prepare("
            INSERT INTO preorder_purchases
                (preorder_id, tier_id, buyer_name, buyer_email, buyer_phone,
                 shipping_address, order_number, quantity, unit_price, total_amount,
                 currency, payment_status, fulfilment_status)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,'pending','pending')
        ");
        $ins->execute([
            $id, $use_tier_id, $buyer_name, $buyer_email, $buyer_phone,
            $shipping, $order_num, $quantity, $unit_price, $total,
            $po['currency']
        ]);

        $order_success = true;
        $order_ref     = $order_num;
        // TODO: redirect to payment gateway with $order_num and $total
    }
}

$currency = htmlspecialchars($po['currency']);
$price    = number_format((float)$po['price'], 2);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($po['title']) ?> — Pre-order | Keychele</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,400;0,9..144,700;0,9..144,900;1,9..144,400&family=DM+Sans:ital,wght@0,300;0,400;0,500;0,600;1,300&display=swap" rel="stylesheet">
  <style>
    :root {
      --navy:   #032d5d;
      --crimson:#b81236;
      --gold:   #d4a017;
      --cream:  #faf7f2;
      --ink:    #1a0f0a;
    }
    *, *::before, *::after { box-sizing: border-box; }
    body {
      font-family: 'DM Sans', sans-serif;
      background: var(--cream);
      color: var(--ink);
      min-height: 100vh;
    }
    .font-display { font-family: 'Fraunces', serif; }

    /* ── Hero ── */
    .hero {
      background: linear-gradient(165deg, #0a1628 0%, var(--navy) 40%, #1a0830 100%);
      position: relative; overflow: hidden;
    }
    .hero::before {
      content: ''; position: absolute; inset: 0;
      background: radial-gradient(ellipse at 70% 50%, rgba(184,18,54,0.18) 0%, transparent 60%),
                  radial-gradient(ellipse at 20% 80%, rgba(212,160,23,0.1) 0%, transparent 50%);
    }
    .hero-noise {
      position: absolute; inset: 0; opacity: .03;
      background-image: url("data:image/svg+xml,%3Csvg viewBox='0 0 256 256' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='4'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)'/%3E%3C/svg%3E");
    }

    /* Book cover */
    .book-wrapper {
      perspective: 1200px;
      display: inline-block;
    }
    .book-cover {
      width: 200px; height: 280px; border-radius: 4px 14px 14px 4px;
      object-fit: cover;
      box-shadow: -8px 8px 40px rgba(0,0,0,0.6), inset -4px 0 8px rgba(0,0,0,0.3);
      transform: rotateY(-12deg) rotateX(2deg);
      transition: transform .4s ease;
    }
    .book-wrapper:hover .book-cover { transform: rotateY(-4deg) rotateX(1deg); }
    .book-spine {
      position: absolute; left: 0; top: 0; bottom: 0; width: 14px;
      background: linear-gradient(180deg, #1a3a6b, #0a1f3d);
      border-radius: 4px 0 0 4px;
      box-shadow: inset -4px 0 8px rgba(0,0,0,0.4);
    }
    .book-placeholder {
      width: 200px; height: 280px; border-radius: 4px 14px 14px 4px;
      background: linear-gradient(135deg, #1a3a6b, #0a2040);
      display: flex; flex-direction: column; align-items: center; justify-content: center;
      gap: 12px; color: rgba(255,255,255,0.5);
      box-shadow: -8px 8px 40px rgba(0,0,0,0.5);
      transform: rotateY(-10deg);
    }

    /* Stats bar */
    .stat-pill {
      background: rgba(255,255,255,0.08); backdrop-filter: blur(8px);
      border: 1px solid rgba(255,255,255,0.12);
      border-radius: 12px; padding: 12px 20px; text-align: center;
    }

    /* Progress */
    .progress-track {
      height: 8px; background: rgba(255,255,255,0.1);
      border-radius: 99px; overflow: hidden;
    }
    .progress-fill {
      height: 100%; border-radius: 99px;
      background: linear-gradient(90deg, var(--gold), #f59e0b);
      transition: width 1s cubic-bezier(.4,0,.2,1);
    }

    /* Main content */
    .content-section {
      background: white; border-radius: 20px;
      box-shadow: 0 2px 16px rgba(0,0,0,0.05);
    }
    .section-title {
      font-family: 'Fraunces', serif;
      font-size: 1.25rem; font-weight: 700; color: var(--ink);
      padding-bottom: 14px; margin-bottom: 20px;
      border-bottom: 2px solid #f1ebe3;
    }

    /* Tier cards */
    .tier-card {
      border: 2px solid #e8e0d4; border-radius: 16px; padding: 18px;
      cursor: pointer; transition: all .2s; position: relative;
    }
    .tier-card:hover { border-color: var(--navy); }
    .tier-card.selected {
      border-color: var(--navy);
      background: linear-gradient(135deg, #f0f5ff, #e8f0fe);
    }
    .tier-card .popular-tag {
      position: absolute; top: -10px; right: 16px;
      background: var(--crimson); color: white;
      font-size: .65rem; font-weight: 700; letter-spacing: .06em;
      padding: 3px 10px; border-radius: 999px; text-transform: uppercase;
    }

    /* Perk chip */
    .perk-chip {
      display: inline-flex; align-items: center; gap: 6px;
      background: #f0f5e8; color: #2d5016;
      border-radius: 999px; padding: 4px 12px;
      font-size: .75rem; font-weight: 600;
    }

    /* Order form */
    .order-card {
      background: white; border-radius: 20px;
      box-shadow: 0 4px 24px rgba(3,45,93,0.12);
      border: 2px solid #e8f0fe;
      position: sticky; top: 20px;
    }
    .field-label {
      display: block; font-size: .72rem; font-weight: 700; color: #6b7280;
      text-transform: uppercase; letter-spacing: .05em; margin-bottom: 5px;
    }
    .field-input {
      width: 100%; border: 1.5px solid #e5e7eb; border-radius: 10px;
      padding: 10px 14px; font-size: .875rem; background: #f9fafb;
      transition: all .2s; color: #111827; font-family: 'DM Sans', sans-serif;
    }
    .field-input:focus {
      outline: none; border-color: var(--navy); background: white;
      box-shadow: 0 0 0 3px rgba(3,45,93,0.08);
    }

    /* CTA button */
    .cta-btn {
      width: 100%; padding: 16px;
      background: linear-gradient(135deg, var(--crimson), #8b0d27);
      color: white; font-family: 'Fraunces', serif; font-size: 1.0625rem;
      font-weight: 700; border: none; border-radius: 14px; cursor: pointer;
      transition: all .25s; display: flex; align-items: center; justify-content: center; gap: 10px;
      box-shadow: 0 4px 20px rgba(184,18,54,0.35);
      letter-spacing: .01em;
    }
    .cta-btn:hover:not(:disabled) { transform: translateY(-2px); box-shadow: 0 8px 28px rgba(184,18,54,0.45); }
    .cta-btn:disabled { opacity: .55; cursor: not-allowed; }

    /* FAQ accordion */
    .faq-item summary {
      list-style: none; cursor: pointer;
      padding: 16px 0; font-weight: 600; color: var(--ink);
      display: flex; justify-content: space-between; align-items: center;
    }
    .faq-item summary::after {
      content: '+'; font-size: 1.25rem; color: var(--navy);
      transition: transform .2s; flex-shrink: 0;
    }
    .faq-item[open] summary::after { content: '−'; }
    .faq-item { border-bottom: 1px solid #f1ebe3; }
    .faq-item p { padding: 0 0 16px; color: #6b7280; font-size: .9rem; line-height: 1.7; }

    /* Stars */
    .stars { color: #f59e0b; letter-spacing: 2px; }

    /* Update timeline */
    .update-item { display: flex; gap: 14px; padding: 14px 0; border-bottom: 1px solid #f1ebe3; }
    .update-dot { width: 10px; height: 10px; border-radius: 50%; background: var(--navy); flex-shrink: 0; margin-top: 6px; }
    .update-line { width: 2px; background: #e5e7eb; flex-shrink: 0; margin: 6px auto 0; }

    /* Countdown */
    .countdown-box {
      background: linear-gradient(135deg, var(--navy), #0a3d7a);
      color: white; border-radius: 16px; padding: 16px;
      text-align: center;
    }
    .countdown-num {
      font-family: 'Fraunces', serif; font-size: 2rem; font-weight: 900; line-height: 1;
      color: var(--gold);
    }
    .countdown-lbl { font-size: .65rem; font-weight: 700; letter-spacing: .08em; color: rgba(255,255,255,0.6); text-transform: uppercase; }

    /* Toast */
    .order-success {
      background: linear-gradient(135deg, #032d5d, #0a3d7a);
      color: white; border-radius: 20px; padding: 28px;
      text-align: center;
    }

    @media (max-width: 767px) {
      .book-cover, .book-placeholder { width: 140px; height: 196px; }
    }
  </style>
</head>
<body>

<!-- ═══════════════════════════════════════════════
     NAV
═══════════════════════════════════════════════ -->
<nav class="bg-white/90 backdrop-blur-md border-b border-gray-100 sticky top-0 z-50">
  <div class="max-w-screen-xl mx-auto px-4 md:px-6 h-14 flex items-center justify-between">
    <a href="index.php" class="font-display font-bold text-lg text-[var(--navy)]">Keychele</a>
    <div class="flex items-center gap-3">
      <a href="index.php" class="text-gray-400 hover:text-gray-700 text-sm transition-colors">
        <i class="fas fa-home"></i>
      </a>
      <?php if (isset($_SESSION['user_id'])): ?>
        <a href="my_preorders.php" class="text-sm text-[var(--navy)] font-semibold hover:underline">My Orders</a>
      <?php else: ?>
        <a href="login.php" class="text-sm text-[var(--navy)] font-semibold hover:underline">Sign in</a>
      <?php endif; ?>
    </div>
  </div>
</nav>

<!-- ═══════════════════════════════════════════════
     HERO
═══════════════════════════════════════════════ -->
<div class="hero py-12 md:py-16">
  <div class="hero-noise"></div>
  <div class="relative z-10 max-w-screen-xl mx-auto px-4 md:px-6">
    <div class="flex flex-col md:flex-row gap-8 md:gap-14 items-center md:items-start">

      <!-- Book cover -->
      <div class="flex-shrink-0">
        <div class="book-wrapper relative">
          <?php if (!empty($po['cover_image']) && file_exists($po['cover_image'])): ?>
            <div class="book-spine absolute"></div>
            <img src="<?= htmlspecialchars($po['cover_image']) ?>" alt="Cover" class="book-cover">
          <?php else: ?>
            <div class="book-placeholder">
              <i class="fas fa-book-open text-4xl opacity-40"></i>
              <span class="text-sm opacity-40 text-center px-4"><?= htmlspecialchars($po['title']) ?></span>
            </div>
          <?php endif; ?>
        </div>

        <!-- Category tag -->
        <div class="mt-4 text-center">
          <span class="inline-block bg-white/10 text-white/80 text-xs font-semibold px-3 py-1 rounded-full border border-white/20">
            <?= match($po['category']) {
              'book'        => '📖 Book',
              'music'       => '🎵 Album',
              'course'      => '🎓 Course',
              'merchandise' => '👕 Merch',
              'software'    => '💻 Software',
              default       => '📦 Product',
            } ?>
          </span>
        </div>
      </div>

      <!-- Info -->
      <div class="flex-1 text-white text-center md:text-left">
        <?php if ($po['author_artist']): ?>
          <p class="text-white/60 text-sm font-medium mb-2 tracking-wide">
            by <?= htmlspecialchars($po['author_artist']) ?>
            <?= $po['publisher'] ? ' · ' . htmlspecialchars($po['publisher']) : '' ?>
          </p>
        <?php endif; ?>

        <h1 class="font-display text-3xl md:text-5xl font-black leading-tight mb-3">
          <?= htmlspecialchars($po['title']) ?>
        </h1>

        <?php if ($po['subtitle']): ?>
          <p class="text-white/70 text-lg md:text-xl font-light italic mb-5">
            <?= htmlspecialchars($po['subtitle']) ?>
          </p>
        <?php endif; ?>

        <!-- Status -->
        <?php if ($is_closed || $deadline_passed): ?>
          <div class="inline-flex items-center gap-2 bg-red-500/20 border border-red-400/30 text-red-200 rounded-full px-4 py-2 text-sm font-semibold mb-5">
            <i class="fas fa-lock"></i> Pre-orders closed
          </div>
        <?php elseif ($stock_left !== null && $stock_left <= 20): ?>
          <div class="inline-flex items-center gap-2 bg-amber-500/20 border border-amber-400/30 text-amber-200 rounded-full px-4 py-2 text-sm font-semibold mb-5">
            <i class="fas fa-fire"></i> Only <?= $stock_left ?> left!
          </div>
        <?php endif; ?>

        <!-- Stats -->
        <div class="grid grid-cols-3 gap-3 mb-6 max-w-md mx-auto md:mx-0">
          <div class="stat-pill">
            <div class="font-display text-2xl font-bold text-white"><?= number_format($stats['total_orders']) ?></div>
            <div class="text-white/50 text-xs mt-1">Backers</div>
          </div>
          <div class="stat-pill">
            <div class="font-display text-xl font-bold text-[var(--gold)]">
              <?= $currency ?> <?= number_format((float)$po['price']) ?>
            </div>
            <div class="text-white/50 text-xs mt-1">From</div>
          </div>
          <div class="stat-pill">
            <div class="font-display text-2xl font-bold text-white">
              <?= $po['launch_date'] ? date('M Y', strtotime($po['launch_date'])) : '—' ?>
            </div>
            <div class="text-white/50 text-xs mt-1">Ships</div>
          </div>
        </div>

        <!-- Progress -->
        <?php if ($pct !== null): ?>
          <div class="max-w-md mx-auto md:mx-0 mb-4">
            <div class="flex justify-between text-xs text-white/60 mb-2">
              <span><?= $total_units ?> of <?= number_format($goal_units) ?> units pre-ordered</span>
              <span class="font-bold text-[var(--gold)]"><?= $pct ?>%</span>
            </div>
            <div class="progress-track">
              <div class="progress-fill" id="progressBar" style="width:0%"></div>
            </div>
          </div>
        <?php endif; ?>

        <!-- Perks preview -->
        <?php if (!empty($perks)): ?>
          <div class="flex flex-wrap gap-2 justify-center md:justify-start">
            <?php foreach (array_slice($perks,0,4) as $perk): ?>
              <span class="inline-flex items-center gap-1.5 bg-white/10 text-white/80 text-xs font-medium px-3 py-1.5 rounded-full border border-white/15">
                <i class="fas fa-check text-[var(--gold)] text-xs"></i>
                <?= htmlspecialchars($perk) ?>
              </span>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- ═══════════════════════════════════════════════
     MAIN CONTENT
═══════════════════════════════════════════════ -->
<div class="max-w-screen-xl mx-auto px-4 md:px-6 py-10 pb-20">
  <div class="grid lg:grid-cols-3 gap-8">

    <!-- LEFT: Details -->
    <div class="lg:col-span-2 space-y-6">

      <?php if (!empty($order_error)): ?>
        <div class="bg-red-50 border border-red-200 text-red-700 rounded-xl px-5 py-4 flex items-center gap-3 text-sm font-medium">
          <i class="fas fa-exclamation-circle flex-shrink-0"></i><?= htmlspecialchars($order_error) ?>
        </div>
      <?php endif; ?>

      <!-- About -->
      <?php if ($po['description']): ?>
        <div class="content-section p-6 md:p-8">
          <h2 class="section-title">About this pre-order</h2>
          <div class="prose prose-gray max-w-none text-gray-600 leading-relaxed text-sm">
            <?= nl2br(htmlspecialchars($po['description'])) ?>
          </div>
        </div>
      <?php endif; ?>

      <!-- Tiers -->
      <?php if (!empty($tiers)): ?>
        <div class="content-section p-6 md:p-8">
          <h2 class="section-title">Choose Your Edition</h2>
          <div class="space-y-4" id="tierCards">
            <?php foreach ($tiers as $i => $tier): ?>
              <div class="tier-card <?= $i===0?'selected':'' ?>"
                   data-tier-id="<?= $tier['id'] ?>"
                   data-tier-price="<?= $tier['price'] ?>"
                   data-tier-name="<?= htmlspecialchars($tier['name']) ?>"
                   onclick="selectTier(this)">
                <?php if ($i===1): ?><div class="popular-tag">Most Popular</div><?php endif; ?>
                <div class="flex items-start justify-between gap-4">
                  <div class="flex-1">
                    <h3 class="font-display font-bold text-gray-800 text-base mb-1">
                      <?= htmlspecialchars($tier['name']) ?>
                    </h3>
                    <?php if ($tier['description']): ?>
                      <p class="text-gray-500 text-sm mb-3"><?= htmlspecialchars($tier['description']) ?></p>
                    <?php endif; ?>
                    <?php
                    $tierPerks = json_decode($tier['perks'] ?? '[]', true) ?: [];
                    if (!empty($tierPerks)):
                    ?>
                      <div class="flex flex-wrap gap-1.5">
                        <?php foreach ($tierPerks as $tp): ?>
                          <span class="perk-chip"><i class="fas fa-check text-green-600 text-xs"></i><?= htmlspecialchars($tp) ?></span>
                        <?php endforeach; ?>
                      </div>
                    <?php endif; ?>
                  </div>
                  <div class="text-right flex-shrink-0">
                    <div class="font-display text-xl font-bold text-[var(--navy)]">
                      <?= $currency ?> <?= number_format((float)$tier['price']) ?>
                    </div>
                    <?php if ($tier['stock']): ?>
                      <div class="text-xs text-gray-400 mt-1"><?= $tier['stock'] ?> available</div>
                    <?php endif; ?>
                    <div class="mt-2">
                      <div class="w-5 h-5 rounded-full border-2 border-[var(--navy)] ml-auto flex items-center justify-center <?= $i===0?'bg-[var(--navy)]':'' ?>">
                        <?php if ($i===0): ?><i class="fas fa-check text-white text-xs"></i><?php endif; ?>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endif; ?>

      <!-- Specs -->
      <?php if (!empty($specs)): ?>
        <div class="content-section p-6 md:p-8">
          <h2 class="section-title">Product Details</h2>
          <div class="grid grid-cols-2 sm:grid-cols-3 gap-4">
            <?php
            $specIcons = ['pages'=>'📄','format'=>'📋','language'=>'🌍','isbn'=>'🔢','dimensions'=>'📐','weight'=>'⚖️'];
            foreach ($specs as $k => $v): ?>
              <div class="bg-[var(--cream)] rounded-14 p-4 rounded-xl">
                <div class="text-xl mb-1"><?= $specIcons[$k] ?? '•' ?></div>
                <div class="text-xs font-bold text-gray-400 uppercase tracking-wide mb-0.5"><?= ucfirst($k) ?></div>
                <div class="font-semibold text-gray-800 text-sm"><?= htmlspecialchars($v) ?></div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endif; ?>

      <!-- Perks (full list) -->
      <?php if (!empty($perks)): ?>
        <div class="content-section p-6 md:p-8">
          <h2 class="section-title">🎁 What You Get</h2>
          <div class="grid sm:grid-cols-2 gap-3">
            <?php foreach ($perks as $perk): ?>
              <div class="flex items-center gap-3 p-3 bg-green-50 rounded-xl border border-green-100">
                <div class="w-7 h-7 rounded-full bg-green-100 flex items-center justify-center flex-shrink-0">
                  <i class="fas fa-check text-green-600 text-xs"></i>
                </div>
                <span class="text-sm font-medium text-gray-700"><?= htmlspecialchars($perk) ?></span>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endif; ?>

      <!-- Delivery info -->
      <?php if ($po['shipping_note'] || $po['launch_date']): ?>
        <div class="content-section p-6 md:p-8">
          <h2 class="section-title">🚚 Delivery</h2>
          <div class="space-y-3">
            <?php if ($po['launch_date']): ?>
              <div class="flex items-center gap-3 text-sm">
                <i class="fas fa-calendar-check text-[var(--navy)] w-5"></i>
                <span class="text-gray-600">Expected to ship <strong><?= date('d F Y', strtotime($po['launch_date'])) ?></strong></span>
              </div>
            <?php endif; ?>
            <div class="flex items-center gap-3 text-sm">
              <i class="fas fa-<?= $po['delivery_type']==='digital'?'download':($po['delivery_type']==='both'?'boxes':'truck') ?> text-[var(--navy)] w-5"></i>
              <span class="text-gray-600"><?= match($po['delivery_type']) { 'digital'=>'Digital delivery', 'both'=>'Digital + Physical delivery', default=>'Physical shipping' } ?></span>
            </div>
            <?php if ($po['shipping_note']): ?>
              <p class="text-gray-500 text-sm bg-blue-50 rounded-xl p-4 mt-2">
                <?= nl2br(htmlspecialchars($po['shipping_note'])) ?>
              </p>
            <?php endif; ?>
          </div>
        </div>
      <?php endif; ?>

      <!-- Updates -->
      <?php if (!empty($updates)): ?>
        <div class="content-section p-6 md:p-8">
          <h2 class="section-title">📢 Creator Updates</h2>
          <div>
            <?php foreach ($updates as $upd): ?>
              <div class="update-item">
                <div>
                  <div class="update-dot mt-1"></div>
                </div>
                <div class="flex-1">
                  <h4 class="font-bold text-gray-800 text-sm"><?= htmlspecialchars($upd['title']) ?></h4>
                  <p class="text-gray-500 text-xs mt-0.5 mb-2"><?= date('d M Y', strtotime($upd['created_at'])) ?></p>
                  <p class="text-gray-600 text-sm leading-relaxed"><?= nl2br(htmlspecialchars($upd['body'])) ?></p>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endif; ?>

      <!-- FAQs -->
      <?php if (!empty($faqs)): ?>
        <div class="content-section p-6 md:p-8">
          <h2 class="section-title">❓ Frequently Asked Questions</h2>
          <?php foreach ($faqs as $faq): ?>
            <details class="faq-item">
              <summary><?= htmlspecialchars($faq['q']) ?></summary>
              <p><?= nl2br(htmlspecialchars($faq['a'])) ?></p>
            </details>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <!-- Reviews -->
      <?php if (!empty($reviews)): ?>
        <div class="content-section p-6 md:p-8">
          <h2 class="section-title">⭐ Reviews</h2>
          <div class="grid sm:grid-cols-2 gap-4">
            <?php foreach ($reviews as $rev): ?>
              <div class="bg-[var(--cream)] rounded-xl p-4">
                <div class="stars text-sm mb-1"><?= str_repeat('★', (int)$rev['rating']) . str_repeat('☆', 5-(int)$rev['rating']) ?></div>
                <p class="text-gray-700 text-sm leading-relaxed mb-2">"<?= htmlspecialchars($rev['review']) ?>"</p>
                <p class="text-gray-400 text-xs font-semibold"><?= htmlspecialchars($rev['buyer_name']) ?></p>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endif; ?>

    </div><!-- end left -->

    <!-- RIGHT: Order form -->
    <div class="lg:col-span-1">

      <?php if ($order_success): ?>
        <!-- Success state -->
        <div class="order-success">
          <div class="text-5xl mb-4">🎉</div>
          <h3 class="font-display text-2xl font-bold mb-2">You're in!</h3>
          <p class="text-white/80 text-sm mb-4">Your pre-order has been placed. We'll email you with payment instructions.</p>
          <div class="bg-white/10 rounded-xl p-4 mb-4">
            <p class="text-white/60 text-xs uppercase font-bold tracking-wide mb-1">Order Reference</p>
            <p class="font-display text-xl font-bold text-[var(--gold)]"><?= htmlspecialchars($order_ref) ?></p>
          </div>
          <p class="text-white/50 text-xs">Check your email for confirmation.</p>
        </div>

      <?php else: ?>
        <!-- Order form -->
        <div class="order-card p-6">

          <!-- Countdown -->
          <?php if ($deadline_ts && !$is_closed): ?>
            <div class="countdown-box mb-5" id="countdownBox" data-ts="<?= $deadline_ts ?>">
              <p class="text-white/60 text-xs uppercase font-bold tracking-wide mb-3">Pre-order closes in</p>
              <div class="grid grid-cols-4 gap-2">
                <div><div class="countdown-num" id="cdDays">—</div><div class="countdown-lbl">Days</div></div>
                <div><div class="countdown-num" id="cdHours">—</div><div class="countdown-lbl">Hrs</div></div>
                <div><div class="countdown-num" id="cdMins">—</div><div class="countdown-lbl">Min</div></div>
                <div><div class="countdown-num" id="cdSecs">—</div><div class="countdown-lbl">Sec</div></div>
              </div>
            </div>
          <?php endif; ?>

          <h3 class="font-display text-xl font-bold text-[var(--ink)] mb-1">Pre-order Now</h3>
          <p class="text-gray-400 text-xs mb-5">Secure your copy before it sells out.</p>

          <?php if (!$can_order): ?>
            <div class="bg-gray-50 border border-gray-200 rounded-xl p-5 text-center text-gray-500 text-sm font-medium">
              <i class="fas fa-lock text-2xl text-gray-300 block mb-2"></i>
              <?= $is_closed ? 'Pre-orders are closed.' : 'Stock is sold out.' ?>
            </div>
          <?php else: ?>

          <form method="POST" id="orderForm">
            <input type="hidden" name="place_order" value="1">
            <input type="hidden" name="tier_id" id="selectedTierId" value="<?= $tiers[0]['id'] ?? '' ?>">

            <!-- Selected tier display -->
            <?php if (!empty($tiers)): ?>
              <div class="bg-blue-50 rounded-xl p-3 mb-4 flex items-center justify-between">
                <div>
                  <p class="text-xs text-blue-500 font-bold uppercase tracking-wide">Selected Edition</p>
                  <p class="font-semibold text-[var(--navy)] text-sm" id="selectedTierName">
                    <?= htmlspecialchars($tiers[0]['name'] ?? '') ?>
                  </p>
                </div>
                <div class="font-display font-bold text-[var(--navy)]" id="selectedTierPrice">
                  <?= $currency ?> <?= number_format((float)($tiers[0]['price'] ?? $po['price'])) ?>
                </div>
              </div>
            <?php endif; ?>

            <div class="space-y-4">
              <div>
                <label class="field-label">Full Name <span class="text-red-400">*</span></label>
                <input type="text" name="buyer_name" class="field-input" required
                       value="<?= htmlspecialchars($_POST['buyer_name'] ?? '') ?>"
                       placeholder="Your full name">
              </div>
              <div>
                <label class="field-label">Email <span class="text-red-400">*</span></label>
                <input type="email" name="buyer_email" class="field-input" required
                       value="<?= htmlspecialchars($_POST['buyer_email'] ?? '') ?>"
                       placeholder="you@example.com">
              </div>
              <div>
                <label class="field-label">Phone</label>
                <input type="tel" name="buyer_phone" class="field-input"
                       value="<?= htmlspecialchars($_POST['buyer_phone'] ?? '') ?>"
                       placeholder="+254 700 000 000">
              </div>
              <div>
                <label class="field-label">Quantity</label>
                <div class="flex items-center gap-2">
                  <button type="button" onclick="changeQty(-1)"
                    class="w-10 h-10 rounded-xl bg-slate-100 hover:bg-slate-200 font-bold text-lg transition-colors flex-shrink-0">−</button>
                  <input type="number" name="quantity" id="qtyInput" class="field-input text-center font-bold"
                         value="<?= max(1,(int)($po['min_qty']??1)) ?>"
                         min="<?= $po['min_qty']??1 ?>"
                         <?= $po['max_qty'] ? 'max="'.$po['max_qty'].'"' : '' ?>>
                  <button type="button" onclick="changeQty(1)"
                    class="w-10 h-10 rounded-xl bg-slate-100 hover:bg-slate-200 font-bold text-lg transition-colors flex-shrink-0">+</button>
                </div>
              </div>

              <!-- Shipping fields -->
              <?php if ($po['delivery_type'] !== 'digital'): ?>
                <div class="border-t border-gray-100 pt-4">
                  <p class="text-xs font-bold text-gray-400 uppercase tracking-wide mb-3">📦 Shipping Address</p>
                  <div class="space-y-3">
                    <input type="text" name="ship_line1" class="field-input text-sm" placeholder="Street address"
                           value="<?= htmlspecialchars($_POST['ship_line1'] ?? '') ?>">
                    <div class="grid grid-cols-2 gap-2">
                      <input type="text" name="ship_city" class="field-input text-sm" placeholder="City"
                             value="<?= htmlspecialchars($_POST['ship_city'] ?? '') ?>">
                      <input type="text" name="ship_postal" class="field-input text-sm" placeholder="Postal code"
                             value="<?= htmlspecialchars($_POST['ship_postal'] ?? '') ?>">
                    </div>
                    <input type="text" name="ship_country" class="field-input text-sm" placeholder="Country"
                           value="<?= htmlspecialchars($_POST['ship_country'] ?? 'Kenya') ?>">
                  </div>
                </div>
              <?php endif; ?>

              <!-- Total -->
              <div class="bg-[var(--cream)] rounded-xl p-4 flex items-center justify-between">
                <span class="text-sm font-semibold text-gray-600">Total</span>
                <span class="font-display text-xl font-bold text-[var(--navy)]" id="orderTotal">
                  <?= $currency ?> <?= number_format((float)($tiers[0]['price'] ?? $po['price'])) ?>
                </span>
              </div>

              <button type="submit" class="cta-btn" id="orderBtn">
                <i class="fas fa-shopping-bag text-base"></i>
                Place Pre-order
              </button>
            </div>
          </form>

          <?php endif; // can_order ?>

          <!-- Trust signals -->
          <div class="mt-5 flex items-center justify-center gap-4 text-xs text-gray-400">
            <span class="flex items-center gap-1"><i class="fas fa-shield-alt text-green-500"></i> Secure</span>
            <span class="flex items-center gap-1"><i class="fas fa-undo text-blue-500"></i> Refundable</span>
            <span class="flex items-center gap-1"><i class="fas fa-lock text-gray-400"></i> Private</span>
          </div>
        </div><!-- end order-card -->
      <?php endif; ?>

    </div><!-- end right -->
  </div><!-- end grid -->
</div>

<!-- ═══════════════════════════════════════════════
     FOOTER
═══════════════════════════════════════════════ -->
<footer class="border-t border-gray-100 mt-8 py-8 text-center text-xs text-gray-400">
  <p>Powered by <a href="index.php" class="text-[var(--navy)] font-semibold">Keychele</a> ·
     <a href="#" class="hover:text-gray-600">Terms</a> ·
     <a href="#" class="hover:text-gray-600">Privacy</a>
  </p>
</footer>

<script>
// ── Tier selection ────────────────────────────────
const currency = '<?= $currency ?>';

function selectTier(el) {
  document.querySelectorAll('.tier-card').forEach(c => {
    c.classList.remove('selected');
    c.querySelector('.w-5')?.classList.remove('bg-\\[var\\(--navy\\)\\]');
    const check = c.querySelector('.w-5 i');
    if (check) check.parentElement.innerHTML = '';
  });
  el.classList.add('selected');
  const dot = el.querySelector('.w-5');
  if (dot) { dot.classList.add('bg-[var(--navy)]'); dot.innerHTML='<i class="fas fa-check text-white text-xs"></i>'; }

  const tierId    = el.dataset.tierId;
  const tierPrice = parseFloat(el.dataset.tierPrice);
  const tierName  = el.dataset.tierName;

  document.getElementById('selectedTierId').value      = tierId;
  document.getElementById('selectedTierName').textContent = tierName;
  document.getElementById('selectedTierPrice').textContent = currency + ' ' + Math.round(tierPrice).toLocaleString();
  updateTotal();
}

// ── Quantity ──────────────────────────────────────
const minQty = <?= (int)($po['min_qty']??1) ?>;
const maxQty = <?= $po['max_qty'] ? (int)$po['max_qty'] : 999 ?>;

function changeQty(delta) {
  const inp = document.getElementById('qtyInput');
  let v = parseInt(inp.value) + delta;
  v = Math.max(minQty, Math.min(maxQty, v));
  inp.value = v;
  updateTotal();
}
document.getElementById('qtyInput')?.addEventListener('input', updateTotal);

function updateTotal() {
  const qty    = parseInt(document.getElementById('qtyInput')?.value || 1);
  const priceEl = document.getElementById('selectedTierPrice');
  let price = <?= (float)($tiers[0]['price'] ?? $po['price']) ?>;
  if (priceEl) {
    const raw = priceEl.textContent.replace(/[^0-9.]/g,'');
    price = parseFloat(raw) || price;
  } else {
    // no tiers — use tier hidden input
    const activeTier = document.querySelector('.tier-card.selected');
    if (activeTier) price = parseFloat(activeTier.dataset.tierPrice) || <?= (float)$po['price'] ?>;
  }
  const total = price * qty;
  const totalEl = document.getElementById('orderTotal');
  if (totalEl) totalEl.textContent = currency + ' ' + Math.round(total).toLocaleString();
}
updateTotal();

// ── Countdown timer ───────────────────────────────
const cdBox = document.getElementById('countdownBox');
if (cdBox) {
  const target = parseInt(cdBox.dataset.ts) * 1000;
  function tick() {
    const diff = target - Date.now();
    if (diff <= 0) { cdBox.innerHTML='<p class="text-red-300 text-sm font-bold">Pre-orders closed</p>'; return; }
    const d = Math.floor(diff/86400000);
    const h = Math.floor((diff%86400000)/3600000);
    const m = Math.floor((diff%3600000)/60000);
    const s = Math.floor((diff%60000)/1000);
    document.getElementById('cdDays').textContent  = String(d).padStart(2,'0');
    document.getElementById('cdHours').textContent = String(h).padStart(2,'0');
    document.getElementById('cdMins').textContent  = String(m).padStart(2,'0');
    document.getElementById('cdSecs').textContent  = String(s).padStart(2,'0');
  }
  tick(); setInterval(tick, 1000);
}

// ── Progress bar animate ──────────────────────────
window.addEventListener('load', () => {
  const bar = document.getElementById('progressBar');
  if (bar) setTimeout(() => bar.style.width = '<?= $pct ?>%', 200);
});

// ── Order form loading ────────────────────────────
document.getElementById('orderForm')?.addEventListener('submit', () => {
  const btn = document.getElementById('orderBtn');
  if (btn) {
    btn.disabled = true;
    btn.innerHTML = '<span class="inline-block w-4 h-4 border-2 border-white border-t-transparent rounded-full animate-spin"></span><span>Placing order…</span>';
  }
});
</script>
</body>
</html>
