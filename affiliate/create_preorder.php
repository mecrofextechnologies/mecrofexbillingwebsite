<?php
// create_preorder.php — Creator pre-order management dashboard
if (session_status() === PHP_SESSION_NONE) session_start();

ini_set('display_errors', 1);
error_reporting(E_ALL);

$connect_path = file_exists('msanii/includes/connect.php')
    ? 'msanii/includes/connect.php' : 'includes/connect.php';
if (!file_exists($connect_path)) die("Database connection file missing.");
require_once $connect_path;

if (!isset($_SESSION['creator_id']) || !is_numeric($_SESSION['creator_id'])) {
    header("Location: login.php"); exit;
}
$creator_id = (int)$_SESSION['creator_id'];

// ── Determine mode: list | create | edit ──────────
$mode       = $_GET['mode']       ?? 'list';
$preorder_id = (int)($_GET['id'] ?? 0);

// ── Upload helper ─────────────────────────────────
function handleImageUpload(string $field): ?string {
    if (empty($_FILES[$field]['name'])) return null;
    $f   = $_FILES[$field];
    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    $allowed = ['jpg','jpeg','png','webp','gif'];
    if (!in_array($ext, $allowed) || $f['error'] !== UPLOAD_ERR_OK) return null;
    $dir = __DIR__ . '/uploads/preorders/';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $name = 'cover_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    if (move_uploaded_file($f['tmp_name'], $dir . $name)) {
        return 'uploads/preorders/' . $name;
    }
    return null;
}

// ── AJAX: toggle status ───────────────────────────
if (isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    $action = $_POST['ajax_action'];
    $id     = (int)($_POST['id'] ?? 0);
    if ($action === 'toggle_status') {
        $new = $_POST['new_status'] ?? 'active';
        $allowed = ['draft','active','paused','closed','fulfilled'];
        if (!in_array($new, $allowed)) { echo json_encode(['success'=>false]); exit; }
        $s = $pdo->prepare("UPDATE preorders SET status=? WHERE id=? AND creator_id=?");
        $s->execute([$new, $id, $creator_id]);
        echo json_encode(['success'=>true]);
    } elseif ($action === 'delete') {
        $s = $pdo->prepare("DELETE FROM preorders WHERE id=? AND creator_id=?");
        $s->execute([$id, $creator_id]);
        echo json_encode(['success'=>true]);
    }
    exit;
}

// ── SAVE (create or update) ───────────────────────
$save_error = '';
$save_ok    = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_preorder'])) {
    $data = [
        'title'             => trim($_POST['title']             ?? ''),
        'subtitle'          => trim($_POST['subtitle']          ?? ''),
        'description'       => trim($_POST['description']       ?? ''),
        'category'          => $_POST['category']               ?? 'book',
        'author_artist'     => trim($_POST['author_artist']     ?? ''),
        'publisher'         => trim($_POST['publisher']         ?? ''),
        'price'             => (float)($_POST['price']          ?? 0),
        'original_price'    => $_POST['original_price'] !== '' ? (float)$_POST['original_price'] : null,
        'currency'          => $_POST['currency']               ?? 'KES',
        'total_stock'       => $_POST['total_stock'] !== ''  ? (int)$_POST['total_stock']  : null,
        'goal_units'        => $_POST['goal_units']  !== ''  ? (int)$_POST['goal_units']   : null,
        'goal_amount'       => $_POST['goal_amount'] !== ''  ? (float)$_POST['goal_amount']: null,
        'launch_date'       => $_POST['launch_date']            ?? null,
        'preorder_deadline' => $_POST['preorder_deadline']      ?? null,
        'status'            => $_POST['status']                 ?? 'draft',
        'delivery_type'     => $_POST['delivery_type']          ?? 'physical',
        'shipping_note'     => trim($_POST['shipping_note']     ?? ''),
        'min_qty'           => max(1, (int)($_POST['min_qty']   ?? 1)),
        'max_qty'           => $_POST['max_qty'] !== '' ? (int)$_POST['max_qty'] : null,
        'allow_reviews'     => isset($_POST['allow_reviews']) ? 1 : 0,
    ];

    // Build specs JSON
    $specs = [];
    foreach (['pages','format','language','isbn','dimensions','weight'] as $k) {
        if (!empty($_POST['spec_' . $k])) $specs[$k] = trim($_POST['spec_' . $k]);
    }
    $data['specs'] = !empty($specs) ? json_encode($specs) : null;

    // FAQs
    $faqs = [];
    $fqs = $_POST['faq_q'] ?? [];
    $fas = $_POST['faq_a'] ?? [];
    foreach ($fqs as $i => $q) {
        if (trim($q) && trim($fas[$i] ?? '')) {
            $faqs[] = ['q' => trim($q), 'a' => trim($fas[$i])];
        }
    }
    $data['faqs'] = !empty($faqs) ? json_encode($faqs) : null;

    // Perks
    $perks = array_filter(array_map('trim', explode("\n", $_POST['perks'] ?? '')));
    $data['perks'] = !empty($perks) ? json_encode(array_values($perks)) : null;

    if (empty($data['title'])) {
        $save_error = 'Title is required.';
    } elseif ($data['price'] <= 0) {
        $save_error = 'Price must be greater than 0.';
    } else {
        $cover = handleImageUpload('cover_image');

        if ($preorder_id > 0) {
            // Update existing
            $check = $pdo->prepare("SELECT id, cover_image FROM preorders WHERE id=? AND creator_id=?");
            $check->execute([$preorder_id, $creator_id]);
            $existing = $check->fetch(PDO::FETCH_ASSOC);
            if (!$existing) { $save_error = 'Pre-order not found.'; }
            else {
                if (!$cover) $cover = $existing['cover_image'];
                $sql = "UPDATE preorders SET
                    title=:title, subtitle=:subtitle, description=:description,
                    category=:category, author_artist=:author_artist, publisher=:publisher,
                    price=:price, original_price=:original_price, currency=:currency,
                    total_stock=:total_stock, goal_units=:goal_units, goal_amount=:goal_amount,
                    min_qty=:min_qty, max_qty=:max_qty,
                    launch_date=:launch_date, preorder_deadline=:preorder_deadline,
                    status=:status, delivery_type=:delivery_type, shipping_note=:shipping_note,
                    allow_reviews=:allow_reviews, specs=:specs, faqs=:faqs, perks=:perks,
                    cover_image=:cover_image
                    WHERE id=:id AND creator_id=:creator_id";
                $stmt = $pdo->prepare($sql);
                $stmt->execute(array_merge($data, [
                    'cover_image' => $cover,
                    'id'          => $preorder_id,
                    'creator_id'  => $creator_id,
                ]));
                $save_ok = true;
                // Save tiers
                saveTiers($pdo, $preorder_id);
            }
        } else {
            // Insert new
            $sql = "INSERT INTO preorders
                (creator_id, title, subtitle, description, category, author_artist, publisher,
                 price, original_price, currency, total_stock, goal_units, goal_amount,
                 min_qty, max_qty, launch_date, preorder_deadline, status, delivery_type,
                 shipping_note, allow_reviews, specs, faqs, perks, cover_image)
                VALUES
                (:creator_id, :title, :subtitle, :description, :category, :author_artist, :publisher,
                 :price, :original_price, :currency, :total_stock, :goal_units, :goal_amount,
                 :min_qty, :max_qty, :launch_date, :preorder_deadline, :status, :delivery_type,
                 :shipping_note, :allow_reviews, :specs, :faqs, :perks, :cover_image)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(array_merge($data, ['creator_id'=>$creator_id,'cover_image'=>$cover]));
            $preorder_id = (int)$pdo->lastInsertId();
            $save_ok = true;
            saveTiers($pdo, $preorder_id);
            header("Location: create_preorder.php?mode=edit&id={$preorder_id}&saved=1");
            exit;
        }
    }
}

function saveTiers(PDO $pdo, int $pid): void {
    $names  = $_POST['tier_name']  ?? [];
    $prices = $_POST['tier_price'] ?? [];
    $descs  = $_POST['tier_desc']  ?? [];
    $stocks = $_POST['tier_stock'] ?? [];
    $ids    = $_POST['tier_id']    ?? [];

    // Delete removed tiers
    $keepIds = array_filter(array_map('intval', $ids));
    if (!empty($keepIds)) {
        $ph = implode(',', array_fill(0, count($keepIds), '?'));
        $pdo->prepare("DELETE FROM preorder_tiers WHERE preorder_id=? AND id NOT IN ($ph)")
            ->execute(array_merge([$pid], $keepIds));
    } else {
        $pdo->prepare("DELETE FROM preorder_tiers WHERE preorder_id=?")->execute([$pid]);
    }

    foreach ($names as $i => $name) {
        if (empty(trim($name))) continue;
        $tid   = (int)($ids[$i] ?? 0);
        $price = (float)($prices[$i] ?? 0);
        $desc  = trim($descs[$i] ?? '');
        $stock = $stocks[$i] !== '' ? (int)$stocks[$i] : null;
        if ($tid > 0) {
            $pdo->prepare("UPDATE preorder_tiers SET name=?,description=?,price=?,stock=?,sort_order=? WHERE id=? AND preorder_id=?")
                ->execute([$name,$desc,$price,$stock,$i,$tid,$pid]);
        } else {
            $pdo->prepare("INSERT INTO preorder_tiers (preorder_id,name,description,price,stock,sort_order) VALUES (?,?,?,?,?,?)")
                ->execute([$pid,$name,$desc,$price,$stock,$i]);
        }
    }
}

// ── Load preorder for edit ─────────────────────────
$po   = null;
$tiers = [];
if (($mode === 'edit' || $mode === 'view') && $preorder_id > 0) {
    $s = $pdo->prepare("SELECT * FROM preorders WHERE id=? AND creator_id=?");
    $s->execute([$preorder_id, $creator_id]);
    $po = $s->fetch(PDO::FETCH_ASSOC);
    if (!$po) { header("Location: create_preorder.php"); exit; }

    $s2 = $pdo->prepare("SELECT * FROM preorder_tiers WHERE preorder_id=? ORDER BY sort_order");
    $s2->execute([$preorder_id]);
    $tiers = $s2->fetchAll(PDO::FETCH_ASSOC);
}

// ── Load all creator's pre-orders (list mode) ──────
$preorders = [];
if ($mode === 'list') {
    $s = $pdo->prepare("
        SELECT p.*,
               COUNT(pp.id)                              AS total_orders,
               COALESCE(SUM(pp.quantity),0)              AS total_units,
               COALESCE(SUM(CASE WHEN pp.payment_status='completed' THEN pp.total_amount ELSE 0 END),0) AS revenue
        FROM preorders p
        LEFT JOIN preorder_purchases pp ON pp.preorder_id = p.id
        WHERE p.creator_id = ?
        GROUP BY p.id
        ORDER BY p.created_at DESC
    ");
    $s->execute([$creator_id]);
    $preorders = $s->fetchAll(PDO::FETCH_ASSOC);
}

// helpers
$specs = $po ? (json_decode($po['specs'] ?? '{}', true) ?: []) : [];
$faqs  = $po ? (json_decode($po['faqs']  ?? '[]', true) ?: []) : [];
$perks = $po ? (json_decode($po['perks'] ?? '[]', true) ?: []) : [];
$saved = isset($_GET['saved']);

$categories = ['book'=>'📖 Book','music'=>'🎵 Music / Album','course'=>'🎓 Online Course','merchandise'=>'👕 Merchandise','software'=>'💻 Software / App','other'=>'📦 Other'];
$currencies  = ['KES'=>'KES (Kenyan Shilling)','USD'=>'USD (US Dollar)','GBP'=>'GBP (British Pound)','EUR'=>'EUR (Euro)'];
$deliveries  = ['digital'=>'Digital only','physical'=>'Physical / Shipped','both'=>'Both digital & physical'];
$statuses    = ['draft'=>'Draft','active'=>'Active','paused'=>'Paused','closed'=>'Closed','fulfilled'=>'Fulfilled'];
$status_colors = ['draft'=>'slate','active'=>'emerald','paused'=>'amber','closed'=>'red','fulfilled'=>'blue'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= $mode==='list' ? 'My Pre-orders' : ($po ? 'Edit Pre-order' : 'New Pre-order') ?> | Keychele</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,wght@0,400;0,700;1,400&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
  <style>
    :root {
      --navy:   #032d5d;
      --crimson:#b81236;
      --gold:   #e8a020;
      --ink:    #0d1b2a;
      --mist:   #f0f4f8;
    }
    body { font-family:'DM Sans',sans-serif; background:var(--mist); color:var(--ink); }
    h1,h2,.font-display { font-family:'Fraunces',serif; }

    /* Header */
    .page-header {
      background: linear-gradient(135deg, var(--navy) 0%, #0a3d7a 60%, #0f52a0 100%);
      position:relative; overflow:hidden;
    }
    .page-header::after {
      content:''; position:absolute; right:-80px; top:-80px;
      width:280px; height:280px; border-radius:50%;
      background:rgba(255,255,255,0.04);
    }
    .page-header::before {
      content:''; position:absolute; right:120px; bottom:-100px;
      width:350px; height:350px; border-radius:50%;
      background:rgba(184,18,54,0.12);
    }

    /* Cards */
    .form-card {
      background:white; border-radius:20px;
      box-shadow:0 2px 16px rgba(0,0,0,0.06);
      overflow:hidden;
    }
    .card-header {
      padding:18px 24px; border-bottom:1px solid #f1f5f9;
      display:flex; align-items:center; gap:12px;
    }
    .card-icon {
      width:36px; height:36px; border-radius:10px;
      display:flex; align-items:center; justify-content:center; font-size:.9rem; flex-shrink:0;
    }

    /* Fields */
    .field-label {
      display:block; font-size:.72rem; font-weight:700; color:#64748b;
      text-transform:uppercase; letter-spacing:.05em; margin-bottom:6px;
    }
    .field-input, .field-select, .field-textarea {
      width:100%; border:1.5px solid #e2e8f0; border-radius:10px;
      padding:10px 14px; font-size:.875rem; background:#f8fafc;
      transition:all .2s; color:#1e293b; font-family:'DM Sans',sans-serif;
    }
    .field-input:focus, .field-select:focus, .field-textarea:focus {
      outline:none; border-color:var(--navy); background:white;
      box-shadow:0 0 0 3px rgba(3,45,93,.08);
    }
    .field-textarea { resize:vertical; min-height:100px; }

    /* Tabs */
    .tab-btn {
      padding:10px 20px; border-radius:10px; font-size:.8125rem; font-weight:600;
      color:#64748b; border:none; background:transparent; cursor:pointer; transition:all .2s;
      white-space:nowrap;
    }
    .tab-btn.active { background:var(--navy); color:white; }
    .tab-btn:hover:not(.active) { background:#f1f5f9; }
    .tab-pane { display:none; }
    .tab-pane.active { display:block; }

    /* Tier card */
    .tier-card {
      border:1.5px solid #e2e8f0; border-radius:14px; padding:18px;
      position:relative; transition:border-color .2s;
    }
    .tier-card:hover { border-color:#cbd5e1; }
    .tier-remove {
      position:absolute; top:12px; right:12px;
      width:28px; height:28px; border-radius:8px;
      background:#fee2e2; color:#dc2626; border:none; cursor:pointer;
      display:flex; align-items:center; justify-content:center; font-size:.75rem;
    }

    /* Preorder list card */
    .preorder-card {
      background:white; border-radius:18px; padding:20px 24px;
      box-shadow:0 2px 12px rgba(0,0,0,0.05); transition:all .2s;
      border-left:4px solid transparent;
    }
    .preorder-card:hover { box-shadow:0 6px 24px rgba(0,0,0,.1); transform:translateY(-1px); }
    .preorder-card.status-active    { border-left-color:#10b981; }
    .preorder-card.status-draft     { border-left-color:#94a3b8; }
    .preorder-card.status-paused    { border-left-color:#f59e0b; }
    .preorder-card.status-closed    { border-left-color:#ef4444; }
    .preorder-card.status-fulfilled { border-left-color:#3b82f6; }

    /* Progress bar */
    .progress-bar { height:6px; background:#f1f5f9; border-radius:99px; overflow:hidden; }
    .progress-fill { height:100%; border-radius:99px; background:linear-gradient(90deg,var(--navy),#0f52a0); transition:width .6s ease; }

    /* Status badges */
    .status-badge {
      padding:3px 10px; border-radius:999px;
      font-size:.7rem; font-weight:700; letter-spacing:.03em; white-space:nowrap;
    }
    .sb-active    { background:#d1fae5; color:#065f46; }
    .sb-draft     { background:#f1f5f9; color:#475569; }
    .sb-paused    { background:#fef3c7; color:#92400e; }
    .sb-closed    { background:#fee2e2; color:#991b1b; }
    .sb-fulfilled { background:#dbeafe; color:#1e40af; }

    /* Save button */
    .save-btn {
      background:linear-gradient(135deg, var(--navy), #0a3d7a);
      color:white; border:none; border-radius:12px;
      padding:13px 32px; font-size:.9375rem; font-weight:700;
      cursor:pointer; transition:all .2s;
      display:inline-flex; align-items:center; gap:10px;
      box-shadow:0 4px 14px rgba(3,45,93,.3);
    }
    .save-btn:hover { transform:translateY(-1px); box-shadow:0 8px 20px rgba(3,45,93,.35); }

    /* Image preview */
    .img-preview {
      width:100%; height:200px; border-radius:12px; object-fit:cover;
      border:2px solid #e2e8f0;
    }
    .img-placeholder {
      width:100%; height:200px; border-radius:12px;
      border:2px dashed #e2e8f0; display:flex; flex-direction:column;
      align-items:center; justify-content:center; gap:8px; cursor:pointer;
      color:#94a3b8; transition:all .2s; background:#f8fafc;
    }
    .img-placeholder:hover { border-color:var(--navy); color:var(--navy); }

    /* Cover image container */
    #coverPreview { display:none; }

    .char-count { font-size:.7rem; color:#94a3b8; text-align:right; margin-top:3px; }

    /* FAQ row */
    .faq-row { display:grid; grid-template-columns:1fr 1fr auto; gap:10px; align-items:start; }
    @media (max-width:640px) { .faq-row { grid-template-columns:1fr auto; } }

    /* Sticky sidebar */
    @media (min-width:1024px) {
      .sticky-sidebar { position:sticky; top:20px; }
    }

    /* Success toast */
    .toast-success {
      position:fixed; top:20px; right:20px; z-index:9999;
      background:#032d5d; color:white; border-radius:14px;
      padding:14px 20px; display:flex; align-items:center; gap:10px;
      box-shadow:0 8px 28px rgba(3,45,93,.3); font-size:.875rem; font-weight:600;
      animation:slideIn .3s ease;
    }
    @keyframes slideIn { from{transform:translateX(120%);opacity:0} to{transform:none;opacity:1} }

    .preorder-cover {
      width:60px; height:80px; border-radius:8px; object-fit:cover; flex-shrink:0;
      border:1px solid #e2e8f0;
    }
    .preorder-cover-placeholder {
      width:60px; height:80px; border-radius:8px; flex-shrink:0;
      background:linear-gradient(135deg, #e2e8f0, #f1f5f9);
      display:flex; align-items:center; justify-content:center; color:#94a3b8;
      font-size:1.2rem;
    }
  </style>
</head>
<body>

<?php if ($saved): ?>
<div class="toast-success" id="savedToast">
  <i class="fas fa-check-circle text-green-400"></i>
  Pre-order saved successfully!
  <button onclick="document.getElementById('savedToast').remove()" class="ml-2 opacity-60 hover:opacity-100">✕</button>
</div>
<script>setTimeout(()=>document.getElementById('savedToast')?.remove(), 4000);</script>
<?php endif; ?>

<!-- ═══════════════════════════════════════════════
     PAGE HEADER
═══════════════════════════════════════════════ -->
<div class="page-header text-white px-4 md:px-8 py-5">
  <div class="relative z-10 max-w-screen-xl mx-auto">
    <div class="flex items-center gap-2 mb-3 text-sm">
      <a href="index.php?page=dashboard" class="text-white/60 hover:text-white transition-colors">
        <i class="fas fa-home text-xs"></i> Dashboard
      </a>
      <span class="text-white/30">/</span>
      <a href="create_preorder.php" class="text-white/60 hover:text-white transition-colors">Pre-orders</a>
      <?php if ($mode !== 'list'): ?>
        <span class="text-white/30">/</span>
        <span class="text-white/60"><?= $po ? htmlspecialchars($po['title']) : 'New' ?></span>
      <?php endif; ?>
    </div>

    <div class="flex items-center justify-between gap-4 flex-wrap">
      <div class="flex items-center gap-4">
        <div class="w-12 h-12 rounded-2xl flex items-center justify-center text-xl flex-shrink-0"
             style="background:rgba(255,255,255,0.12);">
          <?= $mode==='list' ? '📦' : '✏️' ?>
        </div>
        <div>
          <h1 class="text-xl md:text-2xl font-display font-bold leading-tight">
            <?php if ($mode==='list'): ?>My Pre-order Campaigns
            <?php elseif ($po): ?>Edit: <?= htmlspecialchars($po['title']) ?>
            <?php else: ?>Create New Pre-order
            <?php endif; ?>
          </h1>
          <p class="text-white/60 text-sm mt-0.5">
            <?= $mode==='list'
              ? 'Manage your books, products & campaigns'
              : 'Fill in the details below to set up your pre-order' ?>
          </p>
        </div>
      </div>

      <?php if ($mode === 'list'): ?>
        <a href="create_preorder.php?mode=create"
           class="inline-flex items-center gap-2 bg-white text-[var(--navy)] font-bold px-5 py-2.5 rounded-xl text-sm hover:bg-blue-50 transition-colors shadow-sm">
          <i class="fas fa-plus"></i> New Pre-order
        </a>
      <?php else: ?>
        <a href="create_preorder.php"
           class="text-white/70 hover:text-white text-sm flex items-center gap-1.5 transition-colors">
          <i class="fas fa-arrow-left text-xs"></i> All Pre-orders
        </a>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="max-w-screen-xl mx-auto px-4 md:px-6 py-6 pb-16">

<?php if (!empty($save_error)): ?>
  <div class="bg-red-50 border border-red-200 text-red-700 rounded-xl px-5 py-4 mb-5 flex items-center gap-3 text-sm font-medium">
    <i class="fas fa-exclamation-circle flex-shrink-0"></i><?= htmlspecialchars($save_error) ?>
  </div>
<?php endif; ?>

<!-- ═══════════════════════════════════════════════
     LIST MODE
═══════════════════════════════════════════════ -->
<?php if ($mode === 'list'): ?>

  <?php if (empty($preorders)): ?>
    <div class="bg-white rounded-2xl shadow p-16 text-center">
      <div class="text-6xl mb-4">📦</div>
      <h2 class="font-display text-xl font-bold text-gray-700 mb-2">No pre-orders yet</h2>
      <p class="text-gray-400 text-sm mb-6">Create your first pre-order campaign to start selling your book or product.</p>
      <a href="create_preorder.php?mode=create"
         class="inline-flex items-center gap-2 bg-[var(--navy)] text-white font-bold px-6 py-3 rounded-xl text-sm hover:bg-blue-900 transition-colors">
        <i class="fas fa-plus"></i> Create Pre-order
      </a>
    </div>
  <?php else: ?>
    <div class="space-y-4">
      <?php foreach ($preorders as $p):
        $pct = $p['goal_units'] ? min(100, round(($p['total_units'] / $p['goal_units']) * 100)) : null;
        $sc  = 'sb-' . $p['status'];
      ?>
        <div class="preorder-card status-<?= $p['status'] ?>">
          <div class="flex gap-4 flex-wrap">
            <!-- Cover -->
            <?php if ($p['cover_image'] && file_exists($p['cover_image'])): ?>
              <img src="<?= htmlspecialchars($p['cover_image']) ?>" alt="" class="preorder-cover">
            <?php else: ?>
              <div class="preorder-cover-placeholder">
                <?= $p['category']==='book' ? '📖' : ($p['category']==='music' ? '🎵' : '📦') ?>
              </div>
            <?php endif; ?>

            <!-- Info -->
            <div class="flex-1 min-w-0">
              <div class="flex items-start justify-between gap-3 flex-wrap mb-2">
                <div>
                  <div class="flex items-center gap-2 flex-wrap">
                    <h3 class="font-display font-bold text-gray-800 text-base truncate">
                      <?= htmlspecialchars($p['title']) ?>
                    </h3>
                    <span class="status-badge <?= $sc ?>"><?= ucfirst($p['status']) ?></span>
                  </div>
                  <?php if ($p['subtitle']): ?>
                    <p class="text-xs text-gray-400 mt-0.5"><?= htmlspecialchars($p['subtitle']) ?></p>
                  <?php endif; ?>
                </div>
                <div class="flex items-center gap-2 flex-shrink-0">
                  <a href="preorder.php?id=<?= $p['id'] ?>" target="_blank"
                     class="text-xs text-blue-600 hover:underline flex items-center gap-1">
                    <i class="fas fa-external-link-alt"></i> View
                  </a>
                  <a href="create_preorder.php?mode=edit&id=<?= $p['id'] ?>"
                     class="w-8 h-8 rounded-lg bg-slate-100 hover:bg-slate-200 flex items-center justify-center text-slate-600 text-xs transition-colors">
                    <i class="fas fa-pen"></i>
                  </a>
                  <button class="delete-btn w-8 h-8 rounded-lg bg-red-50 hover:bg-red-100 flex items-center justify-center text-red-500 text-xs transition-colors"
                          data-id="<?= $p['id'] ?>">
                    <i class="fas fa-trash"></i>
                  </button>
                </div>
              </div>

              <!-- Stats row -->
              <div class="grid grid-cols-3 sm:grid-cols-5 gap-3 text-center mb-3">
                <div class="bg-slate-50 rounded-xl px-2 py-2">
                  <div class="font-bold text-gray-800 text-sm"><?= $p['total_orders'] ?></div>
                  <div class="text-gray-400 text-xs">Orders</div>
                </div>
                <div class="bg-slate-50 rounded-xl px-2 py-2">
                  <div class="font-bold text-gray-800 text-sm"><?= $p['total_units'] ?></div>
                  <div class="text-gray-400 text-xs">Units</div>
                </div>
                <div class="bg-emerald-50 rounded-xl px-2 py-2">
                  <div class="font-bold text-emerald-700 text-sm"><?= number_format($p['revenue']) ?></div>
                  <div class="text-gray-400 text-xs"><?= $p['currency'] ?></div>
                </div>
                <div class="bg-slate-50 rounded-xl px-2 py-2 hidden sm:block">
                  <div class="font-bold text-gray-800 text-sm"><?= number_format($p['price']) ?></div>
                  <div class="text-gray-400 text-xs">Price</div>
                </div>
                <div class="bg-slate-50 rounded-xl px-2 py-2 hidden sm:block">
                  <div class="font-bold text-gray-800 text-sm">
                    <?= $p['preorder_deadline'] ? date('d M', strtotime($p['preorder_deadline'])) : '—' ?>
                  </div>
                  <div class="text-gray-400 text-xs">Deadline</div>
                </div>
              </div>

              <!-- Progress bar -->
              <?php if ($pct !== null): ?>
                <div class="flex items-center gap-3 text-xs">
                  <div class="progress-bar flex-1">
                    <div class="progress-fill" style="width:<?= $pct ?>%"></div>
                  </div>
                  <span class="font-bold text-gray-700 flex-shrink-0"><?= $pct ?>%</span>
                  <span class="text-gray-400 flex-shrink-0">of <?= number_format($p['goal_units']) ?> goal</span>
                </div>
              <?php endif; ?>
            </div>
          </div>

          <!-- Quick status toggle -->
          <div class="flex gap-2 mt-3 pt-3 border-t border-slate-100 flex-wrap">
            <?php foreach (['active'=>'✅ Active','paused'=>'⏸ Pause','closed'=>'🔒 Close'] as $st => $label): ?>
              <button class="status-toggle-btn text-xs px-3 py-1.5 rounded-lg font-semibold transition-colors <?= $p['status']===$st ? 'bg-[var(--navy)] text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200' ?>"
                      data-id="<?= $p['id'] ?>" data-status="<?= $st ?>">
                <?= $label ?>
              </button>
            <?php endforeach; ?>
            <a href="preorder_orders.php?preorder_id=<?= $p['id'] ?>"
               class="ml-auto text-xs px-3 py-1.5 rounded-lg bg-blue-50 text-blue-700 font-semibold hover:bg-blue-100 transition-colors flex items-center gap-1">
              <i class="fas fa-list-ul"></i> View Orders
            </a>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

<?php else: ?>
<!-- ═══════════════════════════════════════════════
     CREATE / EDIT MODE
═══════════════════════════════════════════════ -->

<form method="POST" enctype="multipart/form-data" id="preorderForm">
  <input type="hidden" name="save_preorder" value="1">
  <?php if ($po): ?>
    <input type="hidden" name="preorder_id" value="<?= $po['id'] ?>">
  <?php endif; ?>

  <div class="grid lg:grid-cols-3 gap-6">

    <!-- ── MAIN COLUMN ────────────────────────── -->
    <div class="lg:col-span-2 space-y-5">

      <!-- Tabs -->
      <div class="bg-white rounded-2xl shadow-sm p-3 flex gap-2 overflow-x-auto">
        <?php foreach ([
          'basic'    => ['fas fa-info-circle','Basic Info'],
          'pricing'  => ['fas fa-tag','Pricing & Tiers'],
          'details'  => ['fas fa-align-left','Details & Specs'],
          'faqs'     => ['fas fa-question-circle','FAQs'],
          'delivery' => ['fas fa-truck','Delivery'],
        ] as $tab => [$icon, $label]): ?>
          <button type="button" class="tab-btn <?= $tab==='basic'?'active':'' ?>" data-tab="<?= $tab ?>">
            <i class="<?= $icon ?> mr-1.5"></i><?= $label ?>
          </button>
        <?php endforeach; ?>
      </div>

      <!-- TAB: Basic Info -->
      <div class="tab-pane active" id="tab-basic">
        <div class="form-card">
          <div class="card-header">
            <div class="card-icon bg-blue-50 text-blue-600"><i class="fas fa-info-circle"></i></div>
            <h3 class="font-bold text-gray-800">Basic Information</h3>
          </div>
          <div class="p-6 space-y-5">
            <div>
              <label class="field-label">Title <span class="text-red-500">*</span></label>
              <input type="text" name="title" class="field-input" maxlength="255" required
                     value="<?= htmlspecialchars($po['title'] ?? '') ?>"
                     placeholder="e.g. The Nairobi Chronicles">
            </div>
            <div>
              <label class="field-label">Subtitle</label>
              <input type="text" name="subtitle" class="field-input" maxlength="255"
                     value="<?= htmlspecialchars($po['subtitle'] ?? '') ?>"
                     placeholder="A short catchy tagline">
            </div>
            <div class="grid sm:grid-cols-2 gap-4">
              <div>
                <label class="field-label">Category <span class="text-red-500">*</span></label>
                <select name="category" class="field-select">
                  <?php foreach ($categories as $v => $l): ?>
                    <option value="<?= $v ?>" <?= ($po['category']??'book')===$v?'selected':'' ?>><?= $l ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div>
                <label class="field-label">Status</label>
                <select name="status" class="field-select">
                  <?php foreach ($statuses as $v => $l): ?>
                    <option value="<?= $v ?>" <?= ($po['status']??'draft')===$v?'selected':'' ?>><?= $l ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
            <div class="grid sm:grid-cols-2 gap-4">
              <div>
                <label class="field-label">Author / Artist / Creator</label>
                <input type="text" name="author_artist" class="field-input"
                       value="<?= htmlspecialchars($po['author_artist'] ?? '') ?>"
                       placeholder="Name of author or creator">
              </div>
              <div>
                <label class="field-label">Publisher / Brand</label>
                <input type="text" name="publisher" class="field-input"
                       value="<?= htmlspecialchars($po['publisher'] ?? '') ?>"
                       placeholder="Publisher or brand name">
              </div>
            </div>
            <div>
              <label class="field-label">Description</label>
              <textarea name="description" id="descField" class="field-textarea" rows="5"
                        maxlength="3000"
                        placeholder="Tell buyers what makes this product special. What will they get? Why should they pre-order now?"><?= htmlspecialchars($po['description'] ?? '') ?></textarea>
              <div class="char-count"><span id="descCount">0</span> / 3000</div>
            </div>
          </div>
        </div>
      </div>

      <!-- TAB: Pricing & Tiers -->
      <div class="tab-pane" id="tab-pricing">
        <div class="form-card">
          <div class="card-header">
            <div class="card-icon bg-emerald-50 text-emerald-600"><i class="fas fa-tag"></i></div>
            <h3 class="font-bold text-gray-800">Pricing</h3>
          </div>
          <div class="p-6 space-y-4">
            <div class="grid sm:grid-cols-3 gap-4">
              <div>
                <label class="field-label">Price <span class="text-red-500">*</span></label>
                <div class="relative">
                  <span class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-sm font-bold" id="currSymbol">KES</span>
                  <input type="number" name="price" class="field-input pl-14" step="0.01" min="0" required
                         value="<?= $po['price'] ?? '' ?>" placeholder="0.00">
                </div>
              </div>
              <div>
                <label class="field-label">Original Price <span class="text-gray-300">(optional)</span></label>
                <input type="number" name="original_price" class="field-input" step="0.01" min="0"
                       value="<?= $po['original_price'] ?? '' ?>" placeholder="Shows savings">
              </div>
              <div>
                <label class="field-label">Currency</label>
                <select name="currency" class="field-select" id="currencySelect">
                  <?php foreach ($currencies as $v => $l): ?>
                    <option value="<?= $v ?>" <?= ($po['currency']??'KES')===$v?'selected':'' ?>><?= $v ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
            <div class="grid sm:grid-cols-3 gap-4">
              <div>
                <label class="field-label">Min Qty per order</label>
                <input type="number" name="min_qty" class="field-input" min="1"
                       value="<?= $po['min_qty'] ?? 1 ?>">
              </div>
              <div>
                <label class="field-label">Max Qty per order <span class="text-gray-300">(blank=∞)</span></label>
                <input type="number" name="max_qty" class="field-input" min="1"
                       value="<?= $po['max_qty'] ?? '' ?>" placeholder="Unlimited">
              </div>
              <div>
                <label class="field-label">Total Stock <span class="text-gray-300">(blank=∞)</span></label>
                <input type="number" name="total_stock" class="field-input" min="0"
                       value="<?= $po['total_stock'] ?? '' ?>" placeholder="Unlimited">
              </div>
            </div>
            <div class="grid sm:grid-cols-2 gap-4">
              <div>
                <label class="field-label">Goal (units)</label>
                <input type="number" name="goal_units" class="field-input" min="0"
                       value="<?= $po['goal_units'] ?? '' ?>" placeholder="e.g. 500">
              </div>
              <div>
                <label class="field-label">Goal (amount)</label>
                <input type="number" name="goal_amount" class="field-input" step="0.01" min="0"
                       value="<?= $po['goal_amount'] ?? '' ?>" placeholder="e.g. 250000">
              </div>
            </div>
          </div>
        </div>

        <!-- Pricing Tiers -->
        <div class="form-card mt-5">
          <div class="card-header justify-between">
            <div class="flex items-center gap-3">
              <div class="card-icon bg-purple-50 text-purple-600"><i class="fas fa-layer-group"></i></div>
              <div>
                <h3 class="font-bold text-gray-800">Pricing Tiers</h3>
                <p class="text-xs text-gray-400">Optional — offer Standard, Signed, Bundle editions</p>
              </div>
            </div>
            <button type="button" id="addTierBtn"
              class="text-xs px-3 py-2 rounded-lg bg-purple-50 text-purple-700 font-semibold hover:bg-purple-100 transition-colors flex items-center gap-1.5">
              <i class="fas fa-plus text-xs"></i> Add Tier
            </button>
          </div>
          <div class="p-6">
            <div id="tiersContainer" class="space-y-4">
              <?php foreach ($tiers as $i => $tier): ?>
                <div class="tier-card" data-index="<?= $i ?>">
                  <input type="hidden" name="tier_id[]" value="<?= $tier['id'] ?>">
                  <button type="button" class="tier-remove remove-tier"><i class="fas fa-times"></i></button>
                  <div class="grid sm:grid-cols-3 gap-3 mb-3">
                    <div>
                      <label class="field-label text-xs">Tier Name</label>
                      <input type="text" name="tier_name[]" class="field-input text-sm"
                             value="<?= htmlspecialchars($tier['name']) ?>" placeholder="e.g. Standard Edition">
                    </div>
                    <div>
                      <label class="field-label text-xs">Price</label>
                      <input type="number" name="tier_price[]" class="field-input text-sm"
                             step="0.01" value="<?= $tier['price'] ?>" placeholder="0.00">
                    </div>
                    <div>
                      <label class="field-label text-xs">Stock <span class="text-gray-300">(blank=∞)</span></label>
                      <input type="number" name="tier_stock[]" class="field-input text-sm"
                             value="<?= $tier['stock'] ?? '' ?>" placeholder="Unlimited">
                    </div>
                  </div>
                  <div>
                    <label class="field-label text-xs">Description</label>
                    <input type="text" name="tier_desc[]" class="field-input text-sm"
                           value="<?= htmlspecialchars($tier['description'] ?? '') ?>"
                           placeholder="What's included in this tier?">
                  </div>
                </div>
              <?php endforeach; ?>
              <?php if (empty($tiers)): ?>
                <p class="text-center text-gray-400 text-sm py-6" id="noTiersMsg">
                  No tiers added. The base price will be used for all orders.
                </p>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>

      <!-- TAB: Details & Specs -->
      <div class="tab-pane" id="tab-details">
        <div class="form-card">
          <div class="card-header">
            <div class="card-icon bg-amber-50 text-amber-600"><i class="fas fa-align-left"></i></div>
            <h3 class="font-bold text-gray-800">Product Specs</h3>
          </div>
          <div class="p-6">
            <p class="text-xs text-gray-400 mb-4">Fill in what's relevant for your product type.</p>
            <div class="grid sm:grid-cols-2 gap-4">
              <?php
              $specFields = [
                'pages'      => ['📄', 'Pages / Length'],
                'format'     => ['📋', 'Format (Paperback, MP3, etc.)'],
                'language'   => ['🌍', 'Language'],
                'isbn'       => ['🔢', 'ISBN / SKU'],
                'dimensions' => ['📐', 'Dimensions (e.g. 21×14 cm)'],
                'weight'     => ['⚖️', 'Weight (e.g. 350g)'],
              ];
              foreach ($specFields as $key => [$emoji, $label]): ?>
                <div>
                  <label class="field-label"><?= $emoji ?> <?= $label ?></label>
                  <input type="text" name="spec_<?= $key ?>" class="field-input"
                         value="<?= htmlspecialchars($specs[$key] ?? '') ?>">
                </div>
              <?php endforeach; ?>
            </div>

            <div class="mt-5">
              <label class="field-label">🎁 Included Perks / Bonuses</label>
              <p class="text-xs text-gray-400 mb-2">One perk per line — e.g. "Signed copy", "Free bookmark"</p>
              <textarea name="perks" class="field-textarea" rows="5"
                        placeholder="Signed copy&#10;Exclusive bookmark&#10;Author Q&A access"><?= htmlspecialchars(implode("\n", $perks)) ?></textarea>
            </div>
          </div>
        </div>
      </div>

      <!-- TAB: FAQs -->
      <div class="tab-pane" id="tab-faqs">
        <div class="form-card">
          <div class="card-header justify-between">
            <div class="flex items-center gap-3">
              <div class="card-icon bg-teal-50 text-teal-600"><i class="fas fa-question-circle"></i></div>
              <div>
                <h3 class="font-bold text-gray-800">Frequently Asked Questions</h3>
                <p class="text-xs text-gray-400">Help buyers understand your pre-order</p>
              </div>
            </div>
            <button type="button" id="addFaqBtn"
              class="text-xs px-3 py-2 rounded-lg bg-teal-50 text-teal-700 font-semibold hover:bg-teal-100 transition-colors flex items-center gap-1.5">
              <i class="fas fa-plus text-xs"></i> Add FAQ
            </button>
          </div>
          <div class="p-6">
            <div id="faqContainer" class="space-y-3">
              <?php if (empty($faqs)): ?>
                <p class="text-center text-gray-400 text-sm py-6" id="noFaqMsg">No FAQs yet. Click "Add FAQ" to get started.</p>
              <?php endif; ?>
              <?php foreach ($faqs as $faq): ?>
                <div class="faq-row">
                  <input type="text" name="faq_q[]" class="field-input text-sm"
                         value="<?= htmlspecialchars($faq['q']) ?>" placeholder="Question">
                  <input type="text" name="faq_a[]" class="field-input text-sm"
                         value="<?= htmlspecialchars($faq['a']) ?>" placeholder="Answer">
                  <button type="button" class="remove-faq w-9 h-10 rounded-lg bg-red-50 text-red-500 hover:bg-red-100 transition-colors flex items-center justify-center flex-shrink-0">
                    <i class="fas fa-times text-xs"></i>
                  </button>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
      </div>

      <!-- TAB: Delivery -->
      <div class="tab-pane" id="tab-delivery">
        <div class="form-card">
          <div class="card-header">
            <div class="card-icon bg-rose-50 text-rose-600"><i class="fas fa-truck"></i></div>
            <h3 class="font-bold text-gray-800">Delivery & Dates</h3>
          </div>
          <div class="p-6 space-y-5">
            <div>
              <label class="field-label">Delivery Type</label>
              <div class="grid sm:grid-cols-3 gap-3">
                <?php foreach ($deliveries as $v => $l): ?>
                  <label class="cursor-pointer">
                    <input type="radio" name="delivery_type" value="<?= $v ?>"
                           class="sr-only peer"
                           <?= ($po['delivery_type']??'physical')===$v?'checked':'' ?>>
                    <div class="border-2 border-slate-200 peer-checked:border-[var(--navy)] peer-checked:bg-blue-50
                                rounded-xl p-3 text-center text-sm font-semibold text-gray-600
                                peer-checked:text-[var(--navy)] transition-all cursor-pointer">
                      <?= $l ?>
                    </div>
                  </label>
                <?php endforeach; ?>
              </div>
            </div>
            <div class="grid sm:grid-cols-2 gap-4">
              <div>
                <label class="field-label">🚀 Expected Release / Ship Date</label>
                <input type="date" name="launch_date" class="field-input"
                       value="<?= $po['launch_date'] ?? '' ?>">
              </div>
              <div>
                <label class="field-label">⏰ Pre-order Deadline</label>
                <input type="datetime-local" name="preorder_deadline" class="field-input"
                       value="<?= $po['preorder_deadline'] ? date('Y-m-d\TH:i', strtotime($po['preorder_deadline'])) : '' ?>">
              </div>
            </div>
            <div>
              <label class="field-label">Shipping Notes</label>
              <textarea name="shipping_note" class="field-textarea" rows="3"
                        placeholder="e.g. Ships within Kenya only. 3–7 business days after release."><?= htmlspecialchars($po['shipping_note'] ?? '') ?></textarea>
            </div>
            <div class="flex items-center gap-3">
              <label class="relative inline-flex items-center cursor-pointer">
                <input type="checkbox" name="allow_reviews" class="sr-only peer"
                       <?= ($po['allow_reviews']??1) ? 'checked' : '' ?>>
                <div class="w-11 h-6 bg-gray-200 rounded-full peer peer-checked:bg-[var(--navy)] transition-colors"></div>
                <div class="absolute left-0.5 top-0.5 w-5 h-5 bg-white rounded-full shadow transition-transform peer-checked:translate-x-5"></div>
              </label>
              <span class="text-sm font-medium text-gray-700">Allow buyer reviews after delivery</span>
            </div>
          </div>
        </div>
      </div>

    </div><!-- end main col -->

    <!-- ── SIDEBAR ─────────────────────────────── -->
    <div class="lg:col-span-1 space-y-5 sticky-sidebar self-start">

      <!-- Cover Image -->
      <div class="form-card">
        <div class="card-header">
          <div class="card-icon bg-pink-50 text-pink-600"><i class="fas fa-image"></i></div>
          <h3 class="font-bold text-gray-800">Cover Image</h3>
        </div>
        <div class="p-5">
          <?php if (!empty($po['cover_image']) && file_exists($po['cover_image'])): ?>
            <img id="coverPreview" src="<?= htmlspecialchars($po['cover_image']) ?>"
                 class="img-preview mb-3" style="display:block;">
          <?php else: ?>
            <img id="coverPreview" class="img-preview mb-3">
          <?php endif; ?>
          <div class="img-placeholder" id="coverPlaceholder" onclick="document.getElementById('cover_image').click()"
               <?= (!empty($po['cover_image']) && file_exists($po['cover_image'])) ? 'style="display:none"' : '' ?>>
            <i class="fas fa-cloud-upload-alt text-2xl"></i>
            <span class="text-sm font-medium">Click to upload cover</span>
            <span class="text-xs text-gray-400">JPG, PNG, WebP — recommended 400×600px</span>
          </div>
          <input type="file" name="cover_image" id="cover_image" accept="image/*" class="hidden"
                 onchange="previewCover(this)">
          <?php if (!empty($po['cover_image'])): ?>
            <button type="button" onclick="clearCover()"
              class="w-full mt-2 text-xs text-red-500 hover:text-red-700 py-1.5">
              <i class="fas fa-times mr-1"></i>Remove image
            </button>
          <?php endif; ?>
        </div>
      </div>

      <!-- Save actions -->
      <div class="form-card">
        <div class="p-5 space-y-3">
          <button type="submit" class="save-btn w-full justify-center">
            <i class="fas fa-save"></i>
            <?= $po ? 'Save Changes' : 'Create Pre-order' ?>
          </button>
          <?php if ($po): ?>
            <a href="preorder.php?id=<?= $po['id'] ?>" target="_blank"
               class="w-full flex items-center justify-center gap-2 bg-slate-100 hover:bg-slate-200 text-slate-700 font-semibold py-3 rounded-xl text-sm transition-colors">
              <i class="fas fa-eye"></i> Preview public page
            </a>
            <a href="preorder_orders.php?preorder_id=<?= $po['id'] ?>"
               class="w-full flex items-center justify-center gap-2 bg-blue-50 hover:bg-blue-100 text-blue-700 font-semibold py-3 rounded-xl text-sm transition-colors">
              <i class="fas fa-shopping-bag"></i> View orders
            </a>
          <?php endif; ?>
        </div>
      </div>

      <!-- Quick tips -->
      <div class="bg-amber-50 border border-amber-200 rounded-xl p-4">
        <p class="text-xs font-bold text-amber-800 mb-2 flex items-center gap-1.5">
          <i class="fas fa-lightbulb text-amber-500"></i> Tips for success
        </p>
        <ul class="text-xs text-amber-700 space-y-1.5 leading-relaxed">
          <li>📷 A great cover image increases conversions by 40%</li>
          <li>📝 Write a compelling description — explain the why</li>
          <li>🎁 Add perks to reward early backers</li>
          <li>🗓 Set a clear deadline to create urgency</li>
          <li>💬 Use FAQs to reduce buyer hesitation</li>
        </ul>
      </div>

    </div><!-- end sidebar -->
  </div><!-- end grid -->
</form>

<?php endif; // end create/edit mode ?>
</div><!-- end container -->

<script>
// ── Tabs ──────────────────────────────────────────
document.querySelectorAll('.tab-btn').forEach(btn => {
  btn.addEventListener('click', () => {
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    document.querySelectorAll('.tab-pane').forEach(p => p.classList.remove('active'));
    btn.classList.add('active');
    document.getElementById('tab-' + btn.dataset.tab)?.classList.add('active');
  });
});

// ── Char count ────────────────────────────────────
const descField = document.getElementById('descField');
const descCount = document.getElementById('descCount');
if (descField && descCount) {
  const update = () => descCount.textContent = descField.value.length;
  descField.addEventListener('input', update);
  update();
}

// ── Currency symbol ───────────────────────────────
document.getElementById('currencySelect')?.addEventListener('change', function() {
  document.getElementById('currSymbol').textContent = this.value;
});

// ── Cover image preview ───────────────────────────
function previewCover(input) {
  if (input.files?.[0]) {
    const prev = document.getElementById('coverPreview');
    const plac = document.getElementById('coverPlaceholder');
    prev.src = URL.createObjectURL(input.files[0]);
    prev.style.display = 'block';
    if (plac) plac.style.display = 'none';
  }
}
function clearCover() {
  document.getElementById('coverPreview').style.display = 'none';
  document.getElementById('coverPlaceholder').style.display = '';
  document.getElementById('cover_image').value = '';
}

// ── Add Tier ──────────────────────────────────────
let tierIdx = <?= count($tiers) ?>;
document.getElementById('addTierBtn')?.addEventListener('click', () => {
  document.getElementById('noTiersMsg')?.remove();
  const div = document.createElement('div');
  div.className = 'tier-card';
  div.innerHTML = `
    <input type="hidden" name="tier_id[]" value="">
    <button type="button" class="tier-remove remove-tier"><i class="fas fa-times"></i></button>
    <div class="grid sm:grid-cols-3 gap-3 mb-3">
      <div><label class="field-label text-xs">Tier Name</label>
        <input type="text" name="tier_name[]" class="field-input text-sm" placeholder="e.g. Signed Edition"></div>
      <div><label class="field-label text-xs">Price</label>
        <input type="number" name="tier_price[]" class="field-input text-sm" step="0.01" placeholder="0.00"></div>
      <div><label class="field-label text-xs">Stock</label>
        <input type="number" name="tier_stock[]" class="field-input text-sm" placeholder="Unlimited"></div>
    </div>
    <div><label class="field-label text-xs">Description</label>
      <input type="text" name="tier_desc[]" class="field-input text-sm" placeholder="What's included?"></div>
  `;
  document.getElementById('tiersContainer').appendChild(div);
  tierIdx++;
});
document.getElementById('tiersContainer')?.addEventListener('click', e => {
  if (e.target.closest('.remove-tier')) e.target.closest('.tier-card').remove();
});

// ── Add FAQ ───────────────────────────────────────
document.getElementById('addFaqBtn')?.addEventListener('click', () => {
  document.getElementById('noFaqMsg')?.remove();
  const div = document.createElement('div');
  div.className = 'faq-row';
  div.innerHTML = `
    <input type="text" name="faq_q[]" class="field-input text-sm" placeholder="Question">
    <input type="text" name="faq_a[]" class="field-input text-sm" placeholder="Answer">
    <button type="button" class="remove-faq w-9 h-10 rounded-lg bg-red-50 text-red-500 hover:bg-red-100 transition-colors flex items-center justify-center flex-shrink-0">
      <i class="fas fa-times text-xs"></i>
    </button>
  `;
  document.getElementById('faqContainer').appendChild(div);
});
document.getElementById('faqContainer')?.addEventListener('click', e => {
  if (e.target.closest('.remove-faq')) e.target.closest('.faq-row').remove();
});

// ── Status toggle (list page) ─────────────────────
document.querySelectorAll('.status-toggle-btn').forEach(btn => {
  btn.addEventListener('click', async () => {
    const id  = btn.dataset.id;
    const st  = btn.dataset.status;
    const res = await fetch(location.href, {
      method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'},
      body: new URLSearchParams({ajax_action:'toggle_status', id, new_status:st})
    });
    const data = await res.json();
    if (data.success) location.reload();
  });
});

// ── Delete (list page) ────────────────────────────
document.querySelectorAll('.delete-btn').forEach(btn => {
  btn.addEventListener('click', async () => {
    const r = await Swal.fire({
      title:'Delete this pre-order?',
      text:'All orders and data will be permanently deleted.',
      icon:'warning', showCancelButton:true,
      confirmButtonColor:'#dc2626', cancelButtonColor:'#64748b',
      confirmButtonText:'Yes, delete it'
    });
    if (!r.isConfirmed) return;
    const res = await fetch(location.href, {
      method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'},
      body: new URLSearchParams({ajax_action:'delete', id:btn.dataset.id})
    });
    const data = await res.json();
    if (data.success) btn.closest('.preorder-card').remove();
  });
});
</script>
</body>
</html>
