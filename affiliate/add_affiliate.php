<?php
// ==================== ADD_AFFILIATE.PHP ====================
if (session_status() === PHP_SESSION_NONE) session_start();

$connect_paths = [
    __DIR__ . '/includes/connect.php',
    __DIR__ . '/../includes/connect.php',
    dirname(__DIR__) . '/includes/connect.php'
];
$connected = false;
foreach ($connect_paths as $path) {
    if (file_exists($path)) { require_once $path; $connected = true; break; }
}
if (!$connected) die('Database connection failed.');

if (empty($_SESSION['creator_id'])) {
    header("Location: ../login.php"); exit;
}

$creator_id   = (int)$_SESSION['creator_id'];
$errors       = [];
$success_msg  = '';
$generated_link = '';

// ── helpers ─────────────────────────────────────────────────
function kc_token($len = 16): string {
    return bin2hex(random_bytes($len / 2));
}
function kc_slug(string $name): string {
    $s = strtolower(trim($name));
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    return trim($s, '-') ?: 'affiliate';
}

// ── fetch existing affiliate settings for this creator ──────
$aff_settings = [
    'commission_type'    => 'percentage',   // percentage | fixed
    'commission_value'   => 10,
    'cookie_days'        => 30,
    'allow_self_register'=> 1,
    'require_approval'   => 0,
    'payout_threshold'   => 500,
    'base_url'           => 'https://keychele.co.ke',
];
try {
    $stmt = $pdo->prepare("SELECT * FROM affiliate_settings WHERE creator_id = ? LIMIT 1");
    $stmt->execute([$creator_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) $aff_settings = array_merge($aff_settings, $row);
} catch (Exception $e) {}

// ── handle FORM submissions ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['_action'] ?? '';
    $csrf   = $_POST['_csrf']  ?? '';

    // CSRF
    if (empty($_SESSION['aff_csrf']) || $csrf !== $_SESSION['aff_csrf']) {
        $errors[] = 'Invalid request token. Please refresh and try again.';
    }

    // ── 1. SAVE SETTINGS ──
    if (!$errors && $action === 'save_settings') {
        $commission_type  = in_array($_POST['commission_type'] ?? '', ['percentage','fixed']) ? $_POST['commission_type'] : 'percentage';
        $commission_value = max(0, (float)($_POST['commission_value'] ?? 10));
        $cookie_days      = max(1, (int)($_POST['cookie_days'] ?? 30));
        $allow_self       = isset($_POST['allow_self_register']) ? 1 : 0;
        $req_approval     = isset($_POST['require_approval'])    ? 1 : 0;
        $payout_threshold = max(0, (float)($_POST['payout_threshold'] ?? 500));

        if ($commission_type === 'percentage' && $commission_value > 100) {
            $errors[] = 'Percentage commission cannot exceed 100%.';
        }

        if (!$errors) {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO affiliate_settings
                        (creator_id, commission_type, commission_value, cookie_days, allow_self_register, require_approval, payout_threshold)
                    VALUES (?,?,?,?,?,?,?)
                    ON DUPLICATE KEY UPDATE
                        commission_type    = VALUES(commission_type),
                        commission_value   = VALUES(commission_value),
                        cookie_days        = VALUES(cookie_days),
                        allow_self_register= VALUES(allow_self_register),
                        require_approval   = VALUES(require_approval),
                        payout_threshold   = VALUES(payout_threshold)
                ");
                $stmt->execute([$creator_id, $commission_type, $commission_value, $cookie_days, $allow_self, $req_approval, $payout_threshold]);
                $aff_settings = array_merge($aff_settings, compact('commission_type','commission_value','cookie_days','allow_self_register','require_approval','payout_threshold'));
                $success_msg = 'Affiliate settings saved successfully.';
            } catch (Exception $e) {
                $errors[] = 'Database error: ' . $e->getMessage();
            }
        }
    }

    // ── 2. ADD AFFILIATE (admin-created) ──
    if (!$errors && $action === 'add_affiliate') {
        $full_name   = trim($_POST['full_name']   ?? '');
        $email       = trim($_POST['email']       ?? '');
        $phone       = trim($_POST['phone']       ?? '');
        $notes       = trim($_POST['notes']       ?? '');
        $send_invite = isset($_POST['send_invite']) ? 1 : 0;

        if (empty($full_name)) $errors[] = 'Full name is required.';
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Valid email is required.';

        if (!$errors) {
            // Check if email already exists for this creator
            try {
                $stmt = $pdo->prepare("SELECT id FROM affiliates WHERE email = ? AND created_by = ?");
                $stmt->execute([$email, $creator_id]);
                if ($stmt->rowCount() > 0) $errors[] = 'An affiliate with this email already exists.';
            } catch (Exception $e) {}
        }

        if (!$errors) {
            $token       = kc_token(20);
            $slug        = kc_slug($full_name);
            $login_url   = rtrim($aff_settings['base_url'], '/') . '/login/affiliate?ref=' . $token;
            $temp_pass   = ucfirst(kc_slug($full_name)) . rand(100,999) . '!';

            try {
                $stmt = $pdo->prepare("
                    INSERT INTO affiliates
                        (created_by, full_name, email, phone, token, slug, temp_password, notes, status, send_invite, created_at)
                    VALUES (?,?,?,?,?,?,?,?,?,?,NOW())
                ");
                $stmt->execute([
                    $creator_id, $full_name, $email, $phone,
                    $token, $slug, password_hash($temp_pass, PASSWORD_DEFAULT),
                    $notes, ($aff_settings['require_approval'] ? 'pending' : 'active'),
                    $send_invite
                ]);
                $affiliate_id = $pdo->lastInsertId();
                $generated_link = $login_url;
                $success_msg = 'Affiliate <strong>' . htmlspecialchars($full_name) . '</strong> added successfully.';
            } catch (Exception $e) {
                $errors[] = 'Could not save affiliate: ' . $e->getMessage();
            }
        }
    }

    // ── 3. GENERATE LINK for existing affiliate ──
    if (!$errors && $action === 'gen_link') {
        $aff_id = (int)($_POST['affiliate_id'] ?? 0);
        try {
            $stmt = $pdo->prepare("SELECT token, full_name FROM affiliates WHERE id = ? AND created_by = ?");
            $stmt->execute([$aff_id, $creator_id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $generated_link = rtrim($aff_settings['base_url'], '/') . '/login/affiliate?ref=' . $row['token'];
                $success_msg = 'Login link generated for <strong>' . htmlspecialchars($row['full_name']) . '</strong>.';
            } else {
                $errors[] = 'Affiliate not found.';
            }
        } catch (Exception $e) {
            $errors[] = 'Error generating link.';
        }
    }
}

// Regenerate CSRF
$_SESSION['aff_csrf'] = kc_token(32);
$csrf_token = $_SESSION['aff_csrf'];

// ── fetch affiliates list ────────────────────────────────────
$affiliates = [];
try {
    $stmt = $pdo->prepare("
        SELECT id, full_name, email, phone, token, status, send_invite, created_at
        FROM affiliates
        WHERE created_by = ?
        ORDER BY created_at DESC
        LIMIT 50
    ");
    $stmt->execute([$creator_id]);
    $affiliates = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

$base_url = rtrim($aff_settings['base_url'], '/');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Affiliates — Keychele</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
  <!-- Toastr CSS -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.css">
  
  <style>
    /* ── Design Tokens (Dark Theme) ── */
    :root {
      --bg:       #020c1e;
      --bg2:      #041226;
      --bg3:      #061a38;
      --card:     #07214a;
      --bdr:      rgba(255,255,255,0.07);
      --txt:      rgba(255,255,255,0.85);
      --mut:      rgba(255,255,255,0.40);
      --gold:     #fec411;
      --gold-d:   rgba(254,196,17,0.12);
      --cyan:     #06b6d4;
      --cyan-d:   rgba(6,182,212,0.13);
      --red:      #bf1339;
      --red-l:    #e8143f;
      --red-d:    rgba(191,19,57,0.18);
      --green:    #00b37e;
      --green-d:  rgba(0,179,126,0.15);
      --radius:   14px;
      --trn:      0.22s ease;
      --shadow:   0 8px 40px rgba(0,0,0,0.45);
    }

    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    body {
      font-family: 'Montserrat', sans-serif;
      background: var(--bg);
      color: var(--txt);
      -webkit-font-smoothing: antialiased;
      min-height: 100vh;
      padding: 32px 28px 60px;
    }

    /* ── Header ── */
    .page-header {
      display: flex; align-items: center; gap: 14px; margin-bottom: 32px; flex-wrap: wrap;
    }
    .header-ico {
      width: 50px; height: 50px; border-radius: 14px; flex-shrink: 0;
      background: var(--cyan-d); border: 1px solid rgba(6,182,212,.22);
      display: flex; align-items: center; justify-content: center; font-size: 1.3rem; color: var(--cyan);
    }
    .header-info h1 { font-size: 1.5rem; font-weight: 800; letter-spacing: -.03em; color: #fff; }
    .header-info p { font-size: .78rem; color: var(--mut); margin-top: 2px; }

    /* ── Tabs ── */
    .aff-tabs { display: flex; gap: 6px; margin-bottom: 28px; border-bottom: 1px solid var(--bdr); overflow-x: auto; }
    .aff-tab {
      padding: 12px 20px; border-radius: 10px 10px 0 0; font-size: .75rem; font-weight: 700;
      text-transform: uppercase; letter-spacing: .07em; color: var(--mut); cursor: pointer;
      border: 1px solid transparent; border-bottom: none; transition: var(--trn); white-space: nowrap;
    }
    .aff-tab:hover { color: #fff; }
    .aff-tab.active { color: var(--cyan); background: var(--cyan-d); border-color: rgba(6,182,212,.22); }

    /* ── Panels ── */
    .aff-panel { display: none; animation: fadeUp .3s ease; }
    .aff-panel.active { display: block; }
    @keyframes fadeUp { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: none; } }

    /* ── Cards ── */
    .aff-card {
      background: var(--card); border-radius: var(--radius); border: 1px solid var(--bdr);
      padding: 24px; margin-bottom: 24px; box-shadow: var(--shadow);
    }
    .aff-card-title { font-size: .95rem; font-weight: 700; color: #fff; margin-bottom: 20px; display: flex; align-items: center; gap: 10px; }

    /* ── Forms ── */
    .aff-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 20px; }
    .aff-field { margin-bottom: 18px; }
    .aff-label { display: block; font-size: .72rem; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; color: var(--mut); margin-bottom: 8px; }
    .aff-input {
      width: 100%; background: var(--bg3); border: 1px solid var(--bdr); border-radius: 10px;
      padding: 12px 16px; color: #fff; font-family: inherit; font-size: .85rem; outline: none; transition: var(--trn);
    }
    .aff-input:focus { border-color: var(--cyan); box-shadow: 0 0 0 3px var(--cyan-d); }
    
    .aff-input-pair { display: flex; align-items: center; background: var(--bg3); border: 1px solid var(--bdr); border-radius: 10px; overflow: hidden; }
    .aff-input-pair .aff-input { border: none; }
    .aff-input-unit { padding: 0 16px; font-size: .75rem; font-weight: 700; color: var(--mut); background: var(--bg2); height: 100%; display: flex; align-items: center; }

    /* ── Buttons ── */
    .btn {
      display: inline-flex; align-items: center; gap: 8px; padding: 12px 24px; border-radius: 10px;
      font-size: .8rem; font-weight: 700; cursor: pointer; transition: var(--trn); border: 1px solid transparent; text-decoration: none;
    }
    .btn-cyan { background: var(--cyan); color: #fff; }
    .btn-cyan:hover { background: #0891b2; transform: translateY(-2px); }
    .btn-ghost { background: var(--bg3); color: var(--txt); border-color: var(--bdr); }
    .btn-ghost:hover { background: var(--bg2); color: #fff; }
    .btn-sm { padding: 6px 12px; font-size: .7rem; border-radius: 6px; }
    .btn-gold { background: var(--gold-d); color: var(--gold); border-color: rgba(254,196,17,0.2); }
    .btn-gold:hover { background: var(--gold); color: #000; }

    /* ── Table ── */
    .table-wrap { overflow-x: auto; }
    table { width: 100%; border-collapse: collapse; }
    th { text-align: left; padding: 14px 20px; font-size: .7rem; font-weight: 700; text-transform: uppercase; color: var(--mut); border-bottom: 1px solid var(--bdr); }
    td { padding: 16px 20px; font-size: .8rem; border-bottom: 1px solid var(--bdr); color: var(--txt); }
    tr:hover td { background: rgba(255,255,255,0.02); }

    .st-badge { padding: 4px 10px; border-radius: 6px; font-size: .65rem; font-weight: 700; text-transform: uppercase; }
    .st-active { background: var(--green-d); color: var(--green); }
    .st-pending { background: var(--gold-d); color: var(--gold); }

    /* ── Toggles ── */
    .aff-toggle-row { display: flex; align-items: center; gap: 14px; margin-bottom: 16px; }
    .aff-toggle { position: relative; display: inline-block; width: 42px; height: 22px; flex-shrink: 0; }
    .aff-toggle input { opacity: 0; width: 0; height: 0; }
    .aff-toggle-slider {
      position: absolute; cursor: pointer; inset: 0; background-color: var(--bg3);
      transition: .3s; border-radius: 34px; border: 1px solid var(--bdr);
    }
    .aff-toggle-slider:before {
      position: absolute; content: ""; height: 14px; width: 14px; left: 3px; bottom: 3px;
      background-color: var(--mut); transition: .3s; border-radius: 50%;
    }
    input:checked + .aff-toggle-slider { background-color: var(--cyan-d); border-color: var(--cyan); }
    input:checked + .aff-toggle-slider:before { transform: translateX(20px); background-color: var(--cyan); }

    /* ── Toastr Customization ── */
    #toast-container > div { opacity: 1; box-shadow: var(--shadow); border-radius: 10px; font-family: 'Montserrat', sans-serif; }
    .toast-success { background-color: var(--green) !important; }
    .toast-error { background-color: var(--red) !important; }
  </style>
</head>
<body>

  <header class="page-header">
    <div class="header-ico"><i class="fa-solid fa-handshake"></i></div>
    <div class="header-info">
      <h1>Affiliate Management</h1>
      <p>Configure your program and manage your affiliate partners</p>
    </div>
  </header>

  <div class="aff-tabs">
    <div class="aff-tab active" onclick="kcTab(this, 'tab-add')">Add Affiliate</div>
    <div class="aff-tab" onclick="kcTab(this, 'tab-list')">My Affiliates</div>
    <div class="aff-tab" onclick="kcTab(this, 'tab-settings')">Settings</div>
  </div>

  <!-- TAB: ADD AFFILIATE -->
  <div class="aff-panel active" id="tab-add">
    <div class="aff-card">
      <div class="aff-card-title"><i class="fa-solid fa-user-plus"></i> Register New Affiliate</div>
      <form method="POST" action="">
        <input type="hidden" name="_action" value="add_affiliate">
        <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf_token) ?>">
        
        <div class="aff-grid">
          <div class="aff-field">
            <label class="aff-label">Full Name</label>
            <input type="text" name="full_name" class="aff-input" placeholder="e.g. John Doe" required>
          </div>
          <div class="aff-field">
            <label class="aff-label">Email Address</label>
            <input type="email" name="email" class="aff-input" placeholder="john@example.com" required>
          </div>
          <div class="aff-field">
            <label class="aff-label">Phone Number (Optional)</label>
            <input type="text" name="phone" class="aff-input" placeholder="+254...">
          </div>
        </div>

        <div class="aff-field">
          <label class="aff-label">Internal Notes</label>
          <textarea name="notes" class="aff-input" rows="3" placeholder="Private notes about this affiliate..."></textarea>
        </div>

        <div class="aff-toggle-row">
          <label class="aff-toggle">
            <input type="checkbox" name="send_invite" checked>
            <span class="aff-toggle-slider"></span>
          </label>
          <div>
            <div style="font-size:.8rem; font-weight:700; color:#fff;">Send email invite</div>
            <div style="font-size:.7rem; color:var(--mut);">Automatically send login instructions to the affiliate</div>
          </div>
        </div>

        <button type="submit" class="btn btn-cyan">
          <i class="fa-solid fa-check"></i> Create Affiliate Account
        </button>
      </form>
    </div>

    <?php if ($generated_link): ?>
    <div class="aff-card" style="border-color: var(--gold); background: var(--gold-d);">
      <div class="aff-card-title" style="color: var(--gold);"><i class="fa-solid fa-link"></i> Affiliate Login Link Generated</div>
      <div style="background: var(--bg); padding: 16px; border-radius: 10px; border: 1px solid var(--bdr); display: flex; align-items: center; justify-content: space-between; gap: 12px;">
        <code id="genLink" style="color: var(--cyan); font-size: .8rem; word-break: break-all;"><?= htmlspecialchars($generated_link) ?></code>
        <button class="btn btn-ghost btn-sm" onclick="kcQuickCopy('<?= htmlspecialchars($generated_link) ?>', this)">
          <i class="fa-solid fa-copy"></i> Copy
        </button>
      </div>
      <p style="font-size: .7rem; color: var(--mut); margin-top: 12px;">Share this link with the affiliate so they can access their dashboard.</p>
    </div>
    <?php endif; ?>
  </div>

  <!-- TAB: MY AFFILIATES -->
  <div class="aff-panel" id="tab-list">
    <div class="aff-card">
      <div class="aff-card-title"><i class="fa-solid fa-users"></i> Your Affiliate Partners</div>
      <?php if (empty($affiliates)): ?>
        <div style="text-align:center; padding: 40px; color: var(--mut);">
          <i class="fa-solid fa-handshake-slash" style="font-size: 2.5rem; opacity: .2; margin-bottom: 16px; display: block;"></i>
          <p>No affiliates found yet.</p>
        </div>
      <?php else: ?>
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Name</th>
                <th>Email</th>
                <th>Status</th>
                <th>Joined</th>
                <th style="text-align:right">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($affiliates as $aff): ?>
                <tr>
                  <td><div style="font-weight:700; color:#fff;"><?= htmlspecialchars($aff['full_name']) ?></div></td>
                  <td><?= htmlspecialchars($aff['email']) ?></td>
                  <td>
                    <span class="st-badge st-<?= htmlspecialchars($aff['status']) ?>">
                      <?= ucfirst($aff['status']) ?>
                    </span>
                  </td>
                  <td style="font-size:.75rem; color:var(--mut);"><?= date('d M Y', strtotime($aff['created_at'])) ?></td>
                  <td style="text-align:right">
                    <div style="display:flex; gap:8px; justify-content:flex-end;">
                      <form method="POST" style="display:inline;">
                        <input type="hidden" name="_action" value="gen_link">
                        <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf_token) ?>">
                        <input type="hidden" name="affiliate_id" value="<?= (int)$aff['id'] ?>">
                        <button type="submit" class="btn btn-gold btn-sm" title="Regenerate Link">
                          <i class="fa-solid fa-link"></i>
                        </button>
                      </form>
                      <button class="btn btn-ghost btn-sm" onclick="kcQuickCopy('<?= $base_url.'/login/affiliate?ref='.$aff['token'] ?>', this)" title="Copy Link">
                        <i class="fa-solid fa-copy"></i>
                      </button>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- TAB: SETTINGS -->
  <div class="aff-panel" id="tab-settings">
    <div class="aff-card">
      <div class="aff-card-title"><i class="fa-solid fa-sliders"></i> Program Configuration</div>
      <form method="POST" action="">
        <input type="hidden" name="_action" value="save_settings">
        <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf_token) ?>">

        <div class="aff-field">
          <label class="aff-label">Commission Structure</label>
          <div style="display:flex; gap:12px; margin-bottom:12px;">
            <label style="flex:1; background:var(--bg3); border:1px solid var(--bdr); padding:16px; border-radius:10px; cursor:pointer; display:flex; align-items:center; gap:10px;">
              <input type="radio" name="commission_type" value="percentage" <?= $aff_settings['commission_type']==='percentage' ? 'checked' : '' ?>>
              <div style="font-size:.8rem; font-weight:700;">Percentage (%)</div>
            </label>
            <label style="flex:1; background:var(--bg3); border:1px solid var(--bdr); padding:16px; border-radius:10px; cursor:pointer; display:flex; align-items:center; gap:10px;">
              <input type="radio" name="commission_type" value="fixed" <?= $aff_settings['commission_type']==='fixed' ? 'checked' : '' ?>>
              <div style="font-size:.8rem; font-weight:700;">Fixed Amount (KES)</div>
            </label>
          </div>
        </div>

        <div class="aff-grid">
          <div class="aff-field">
            <label class="aff-label">Commission Value</label>
            <div class="aff-input-pair">
              <input type="number" name="commission_value" class="aff-input" step="0.01" value="<?= htmlspecialchars((string)$aff_settings['commission_value']) ?>">
              <span class="aff-input-unit" id="commUnit"><?= $aff_settings['commission_type']==='percentage' ? '%' : 'KES' ?></span>
            </div>
          </div>
          <div class="aff-field">
            <label class="aff-label">Cookie Duration</label>
            <div class="aff-input-pair">
              <input type="number" name="cookie_days" class="aff-input" value="<?= (int)$aff_settings['cookie_days'] ?>">
              <span class="aff-input-unit">Days</span>
            </div>
          </div>
          <div class="aff-field">
            <label class="aff-label">Payout Threshold</label>
            <div class="aff-input-pair">
              <input type="number" name="payout_threshold" class="aff-input" value="<?= (int)$aff_settings['payout_threshold'] ?>">
              <span class="aff-input-unit">KES</span>
            </div>
          </div>
        </div>

        <div class="aff-toggle-row">
          <label class="aff-toggle">
            <input type="checkbox" name="allow_self_register" <?= $aff_settings['allow_self_register'] ? 'checked' : '' ?>>
            <span class="aff-toggle-slider"></span>
          </label>
          <div>
            <div style="font-size:.8rem; font-weight:700; color:#fff;">Allow self-registration</div>
            <div style="font-size:.7rem; color:var(--mut);">Let people sign up as affiliates via your public link</div>
          </div>
        </div>

        <div class="aff-toggle-row">
          <label class="aff-toggle">
            <input type="checkbox" name="require_approval" <?= $aff_settings['require_approval'] ? 'checked' : '' ?>>
            <span class="aff-toggle-slider"></span>
          </label>
          <div>
            <div style="font-size:.8rem; font-weight:700; color:#fff;">Require manual approval</div>
            <div style="font-size:.7rem; color:var(--mut);">New affiliates must be approved before they can start</div>
          </div>
        </div>

        <button type="submit" class="btn btn-cyan">
          <i class="fa-solid fa-floppy-disk"></i> Save Configuration
        </button>
      </form>
    </div>
  </div>

  <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.js"></script>
  <script>
    toastr.options = { "closeButton": true, "progressBar": true, "positionClass": "toast-top-right", "timeOut": "3000" };

    function kcTab(el, panelId) {
      $('.aff-tab').removeClass('active');
      $('.aff-panel').removeClass('active');
      $(el).addClass('active');
      $('#' + panelId).addClass('active');
    }

    function kcQuickCopy(text, btn) {
      navigator.clipboard.writeText(text).then(() => {
        const orig = $(btn).html();
        $(btn).html('<i class="fa-solid fa-check"></i> Copied');
        toastr.success('Link copied to clipboard');
        setTimeout(() => $(btn).html(orig), 2000);
      });
    }

    $('input[name="commission_type"]').on('change', function() {
      $('#commUnit').text(this.value === 'percentage' ? '%' : 'KES');
    });

    <?php if ($success_msg): ?>
      toastr.success('<?= addslashes($success_msg) ?>');
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
      <?php foreach ($errors as $err): ?>
        toastr.error('<?= addslashes($err) ?>');
      <?php endforeach; ?>
    <?php endif; ?>

    // Auto-switch tabs based on actions
    <?php if ($generated_link || (isset($_POST['_action']) && $_POST['_action'] === 'gen_link')): ?>
      kcTab($('.aff-tab')[1], 'tab-list');
    <?php elseif (isset($_POST['_action']) && $_POST['_action'] === 'save_settings'): ?>
      kcTab($('.aff-tab')[2], 'tab-settings');
    <?php endif; ?>
  </script>
</body>
</html>
