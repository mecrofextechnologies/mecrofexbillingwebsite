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

// ── Load PHPMailer ───────────────────────────────────────────
$mailer_paths = [
    __DIR__ . '/includes/PHPMailer/src/PHPMailer.php',
    __DIR__ . '/../includes/PHPMailer/src/PHPMailer.php',
    dirname(__DIR__) . '/includes/PHPMailer/src/PHPMailer.php',
    __DIR__ . '/vendor/phpmailer/phpmailer/src/PHPMailer.php',
    __DIR__ . '/../vendor/phpmailer/phpmailer/src/PHPMailer.php',
];
$mailer_loaded = false;
foreach ($mailer_paths as $p) {
    if (file_exists($p)) {
        require_once $p;
        require_once str_replace('PHPMailer.php', 'SMTP.php', $p);
        require_once str_replace('PHPMailer.php', 'Exception.php', $p);
        $mailer_loaded = true;
        break;
    }
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailerException;

$creator_id  = (int)$_SESSION['creator_id'];
$errors      = [];
$success_msg = '';

// ── helpers ─────────────────────────────────────────────────
function kc_token($len = 16): string {
    return bin2hex(random_bytes($len / 2));
}
function kc_slug(string $name): string {
    $s = strtolower(trim($name));
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    return trim($s, '-') ?: 'affiliate';
}

// ── fetch creator full name ──────────────────────────────────
$creator_name = 'Your Creator';
try {
    $stmt = $pdo->prepare("SELECT full_name FROM creators WHERE id = ? LIMIT 1");
    $stmt->execute([$creator_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row && !empty($row['full_name'])) $creator_name = $row['full_name'];
} catch (Exception $e) {}

// ── fetch existing affiliate settings ───────────────────────
$aff_settings = [
    'commission_type'    => 'percentage',
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

// ── send affiliate invite email ──────────────────────────────
function send_affiliate_invite(string $to_email, string $to_name, string $creator_name, string $temp_pass, string $login_url, bool $mailer_loaded): bool {
    if (!$mailer_loaded) return false;
    try {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host        = 'mail.keychele.co.ke';
        $mail->SMTPAuth    = true;
        $mail->Username    = 'notifications@keychele.co.ke';
        $mail->Password    = 'King20241064.';
        $mail->SMTPSecure  = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port        = 587;
        $mail->SMTPOptions = [
            'ssl' => [
                'verify_peer'       => false,
                'verify_peer_name'  => false,
                'allow_self_signed' => true,
            ]
        ];
        $mail->setFrom('notifications@keychele.co.ke', 'Keychele');
        $mail->addAddress($to_email, $to_name);
        $mail->isHTML(true);
        $mail->Subject = "You've been added as an affiliate – Keychele";
        $mail->Body = "
        <!DOCTYPE html>
        <html>
        <head>
          <meta charset='UTF-8'>
          <style>
            body { font-family: 'Segoe UI', Arial, sans-serif; background:#f5f7fa; margin:0; padding:0; }
            .wrap { max-width:580px; margin:40px auto; background:#fff; border-radius:14px; overflow:hidden; box-shadow:0 4px 24px rgba(0,0,0,0.08); }
            .head { background:#1a56db; padding:36px 40px; text-align:center; }
            .head h1 { color:#fff; font-size:22px; margin:0; font-weight:700; letter-spacing:-0.5px; }
            .head p { color:rgba(255,255,255,0.8); font-size:13px; margin:6px 0 0; }
            .body { padding:36px 40px; }
            .greeting { font-size:17px; font-weight:700; color:#111; margin-bottom:12px; }
            .text { font-size:14px; color:#444; line-height:1.7; margin-bottom:20px; }
            .cred-box { background:#f0f4ff; border:1px solid #c7d7fd; border-radius:10px; padding:20px 24px; margin-bottom:24px; }
            .cred-box .label { font-size:11px; font-weight:700; text-transform:uppercase; color:#6b7280; letter-spacing:.05em; margin-bottom:4px; }
            .cred-box .value { font-size:14px; font-weight:700; color:#1a56db; font-family:monospace; }
            .btn-wrap { text-align:center; margin-bottom:28px; }
            .btn { display:inline-block; background:#1a56db; color:#fff; text-decoration:none; padding:14px 34px; border-radius:8px; font-size:14px; font-weight:700; }
            .footer { border-top:1px solid #f0f0f0; padding:20px 40px; text-align:center; font-size:12px; color:#9ca3af; }
          </style>
        </head>
        <body>
          <div class='wrap'>
            <div class='head'>
              <h1>Keychele Affiliate Program</h1>
              <p>You have been invited to join as an affiliate partner</p>
            </div>
            <div class='body'>
              <div class='greeting'>Hello " . htmlspecialchars($to_name) . ",</div>
              <p class='text'>
                You have been added as an affiliate partner of <strong>" . htmlspecialchars($creator_name) . "</strong> on Keychele.
                Below are your login credentials. Please log in and change your password as soon as possible.
              </p>
              <div class='cred-box'>
                <div class='label'>Email Address</div>
                <div class='value'>" . htmlspecialchars($to_email) . "</div>
              </div>
              <div class='cred-box'>
                <div class='label'>Temporary Password</div>
                <div class='value'>" . htmlspecialchars($temp_pass) . "</div>
              </div>
              <div class='btn-wrap'>
                <a href='" . htmlspecialchars($login_url) . "' class='btn'>Access Your Affiliate Dashboard</a>
              </div>
              <p class='text' style='font-size:13px; color:#9ca3af;'>
                If you did not expect this invitation, you can safely ignore this email.
              </p>
            </div>
            <div class='footer'>
              &copy; " . date('Y') . " Keychele &middot; keychele.co.ke
            </div>
          </div>
        </body>
        </html>";
        $mail->AltBody = "Hello $to_name,\n\nYou have been added as an affiliate of $creator_name on Keychele.\n\nEmail: $to_email\nPassword: $temp_pass\nLogin: $login_url\n\nKeychele Team";
        $mail->send();
        return true;
    } catch (MailerException $e) {
        return false;
    }
}

// ── handle FORM submissions ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['_action'] ?? '';
    $csrf   = $_POST['_csrf']  ?? '';

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

        if ($commission_type === 'percentage' && $commission_value > 100)
            $errors[] = 'Percentage commission cannot exceed 100%.';

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
                $aff_settings = array_merge($aff_settings, compact('commission_type','commission_value','cookie_days','payout_threshold'));
                $aff_settings['allow_self_register'] = $allow_self;
                $aff_settings['require_approval']    = $req_approval;
                $success_msg = 'Affiliate settings saved successfully.';
            } catch (Exception $e) {
                $errors[] = 'Database error: ' . $e->getMessage();
            }
        }
    }

    // ── 2. ADD AFFILIATE ──
    if (!$errors && $action === 'add_affiliate') {
        $full_name   = trim($_POST['full_name']   ?? '');
        $email       = trim($_POST['email']       ?? '');
        $phone       = trim($_POST['phone']       ?? '');
        $notes       = trim($_POST['notes']       ?? '');
        $send_invite = isset($_POST['send_invite']) ? 1 : 0;

        if (empty($full_name)) $errors[] = 'Full name is required.';
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Valid email is required.';

        if (!$errors) {
            try {
                $stmt = $pdo->prepare("SELECT id FROM affiliates WHERE email = ? AND created_by = ?");
                $stmt->execute([$email, $creator_id]);
                if ($stmt->rowCount() > 0) $errors[] = 'An affiliate with this email already exists.';
            } catch (Exception $e) {}
        }

        if (!$errors) {
            $token     = kc_token(20);
            $slug      = kc_slug($full_name);
            $login_url = rtrim($aff_settings['base_url'], '/') . '/affiliate/login';
            $temp_pass = ucfirst(kc_slug($full_name)) . rand(100,999) . '!';
            $status    = $aff_settings['require_approval'] ? 'pending' : 'active';

            try {
                $stmt = $pdo->prepare("
                    INSERT INTO affiliates
                        (created_by, full_name, email, phone, token, slug, temp_password, notes, status, send_invite, created_at)
                    VALUES (?,?,?,?,?,?,?,?,?,?,NOW())
                ");
                $stmt->execute([
                    $creator_id, $full_name, $email, $phone,
                    $token, $slug, password_hash($temp_pass, PASSWORD_DEFAULT),
                    $notes, $status, $send_invite
                ]);

                if ($send_invite) {
                    $sent = send_affiliate_invite($email, $full_name, $creator_name, $temp_pass, $login_url, $mailer_loaded);
                    if (!$sent) {
                        $errors[] = 'Affiliate created but invite email could not be sent. Check mail server.';
                    }
                }

                if (empty($errors)) {
                    $success_msg = 'Affiliate <strong>' . htmlspecialchars($full_name) . '</strong> added' . ($send_invite ? ' and invite sent' : '') . ' successfully.';
                }
            } catch (Exception $e) {
                $errors[] = 'Could not save affiliate: ' . $e->getMessage();
            }
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
        FROM affiliates WHERE created_by = ? ORDER BY created_at DESC LIMIT 50
    ");
    $stmt->execute([$creator_id]);
    $affiliates = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

$active_tab = 'tab-add';
if (isset($_POST['_action']) && $_POST['_action'] === 'save_settings') $active_tab = 'tab-settings';
if (isset($_POST['_action']) && $_POST['_action'] === 'add_affiliate' && empty($errors)) $active_tab = 'tab-list';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Affiliates — Keychele</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.css">

  <style>
    :root {
      --bg:        #f4f6fb;
      --white:     #ffffff;
      --bdr:       #e4e9f2;
      --bdr2:      #d0d8ea;
      --txt:       #1a202c;
      --txt2:      #4a5568;
      --muted:     #8a94a6;
      --primary:   #1a56db;
      --primary-l: #eff4ff;
      --primary-m: #c7d7fd;
      --green:     #0e9f6e;
      --green-l:   #e8f7f1;
      --amber:     #d97706;
      --amber-l:   #fef3c7;
      --red:       #e02424;
      --red-l:     #fde8e8;
      --radius:    12px;
      --radius-lg: 18px;
      --shadow-sm: 0 1px 3px rgba(0,0,0,0.07), 0 1px 2px rgba(0,0,0,0.04);
      --shadow:    0 4px 16px rgba(0,0,0,0.08), 0 1px 4px rgba(0,0,0,0.04);
      --shadow-lg: 0 8px 32px rgba(26,86,219,0.10), 0 2px 8px rgba(0,0,0,0.05);
    }

    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    body {
      font-family: 'Plus Jakarta Sans', sans-serif;
      background: var(--bg);
      color: var(--txt);
      -webkit-font-smoothing: antialiased;
      min-height: 100vh;
      padding: 32px 28px 60px;
    }

    /* ── Header ── */
    .page-header {
      display: flex; align-items: center; justify-content: space-between;
      gap: 16px; margin-bottom: 32px; flex-wrap: wrap;
    }
    .header-left { display: flex; align-items: center; gap: 16px; }
    .header-ico {
      width: 52px; height: 52px; border-radius: 14px; flex-shrink: 0;
      background: var(--primary-l); border: 1.5px solid var(--primary-m);
      display: flex; align-items: center; justify-content: center;
      font-size: 1.25rem; color: var(--primary);
      box-shadow: 0 2px 8px rgba(26,86,219,0.12);
    }
    .header-info h1 {
      font-size: 1.4rem; font-weight: 800; color: var(--txt); letter-spacing: -.03em;
    }
    .header-info p { font-size: .78rem; color: var(--muted); margin-top: 3px; }

    .stat-pills { display: flex; gap: 10px; flex-wrap: wrap; }
    .stat-pill {
      background: var(--white); border: 1px solid var(--bdr); border-radius: 10px;
      padding: 8px 16px; display: flex; align-items: center; gap: 8px;
      box-shadow: var(--shadow-sm); font-size: .78rem;
    }
    .stat-pill .pill-num { font-weight: 800; color: var(--primary); }
    .stat-pill .pill-lbl { color: var(--muted); font-weight: 500; }

    /* ── Tabs ── */
    .aff-tabs {
      display: flex; gap: 4px; margin-bottom: 24px;
      background: var(--white); border: 1px solid var(--bdr);
      border-radius: 12px; padding: 5px; box-shadow: var(--shadow-sm);
      overflow-x: auto;
    }
    .aff-tab {
      padding: 9px 20px; border-radius: 9px;
      font-size: .75rem; font-weight: 700; text-transform: uppercase; letter-spacing: .06em;
      color: var(--muted); cursor: pointer; transition: all .2s ease;
      white-space: nowrap; display: flex; align-items: center; gap: 7px;
    }
    .aff-tab:hover { color: var(--txt); background: var(--bg); }
    .aff-tab.active { color: var(--primary); background: var(--primary-l); box-shadow: 0 1px 4px rgba(26,86,219,0.12); }
    .aff-tab i { font-size: .8rem; }

    /* ── Panels ── */
    .aff-panel { display: none; animation: fadeUp .25s ease; }
    .aff-panel.active { display: block; }
    @keyframes fadeUp { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: none; } }

    /* ── Cards ── */
    .aff-card {
      background: var(--white); border-radius: var(--radius-lg);
      border: 1px solid var(--bdr); padding: 28px;
      margin-bottom: 20px; box-shadow: var(--shadow);
    }
    .aff-card-title {
      font-size: .9rem; font-weight: 800; color: var(--txt);
      margin-bottom: 22px; display: flex; align-items: center; gap: 10px;
      padding-bottom: 16px; border-bottom: 1px solid var(--bdr);
    }
    .aff-card-title i { color: var(--primary); font-size: .85rem; }

    /* ── Forms ── */
    .aff-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 18px; }
    .aff-field { margin-bottom: 16px; }
    .aff-label {
      display: block; font-size: .7rem; font-weight: 700;
      text-transform: uppercase; letter-spacing: .05em;
      color: var(--txt2); margin-bottom: 7px;
    }
    .aff-input {
      width: 100%; background: var(--bg); border: 1.5px solid var(--bdr2);
      border-radius: 10px; padding: 11px 14px; color: var(--txt);
      font-family: inherit; font-size: .85rem; outline: none;
      transition: all .2s ease;
    }
    .aff-input:focus { border-color: var(--primary); background: #fff; box-shadow: 0 0 0 3px rgba(26,86,219,0.08); }
    .aff-input::placeholder { color: var(--muted); }

    .aff-input-pair {
      display: flex; align-items: stretch;
      background: var(--bg); border: 1.5px solid var(--bdr2);
      border-radius: 10px; overflow: hidden;
    }
    .aff-input-pair:focus-within { border-color: var(--primary); background: #fff; box-shadow: 0 0 0 3px rgba(26,86,219,0.08); }
    .aff-input-pair .aff-input { border: none; background: transparent; box-shadow: none; }
    .aff-input-pair .aff-input:focus { box-shadow: none; }
    .aff-input-unit {
      padding: 0 14px; font-size: .72rem; font-weight: 700;
      color: var(--muted); background: var(--bdr); border-left: 1.5px solid var(--bdr2);
      display: flex; align-items: center; white-space: nowrap;
    }

    /* ── Buttons ── */
    .btn {
      display: inline-flex; align-items: center; gap: 8px; padding: 11px 22px;
      border-radius: 10px; font-size: .8rem; font-weight: 700; cursor: pointer;
      transition: all .2s ease; border: 1.5px solid transparent;
      text-decoration: none; font-family: inherit;
    }
    .btn-primary {
      background: var(--primary); color: #fff; border-color: var(--primary);
      box-shadow: 0 2px 8px rgba(26,86,219,0.25);
    }
    .btn-primary:hover { background: #1648c0; transform: translateY(-1px); box-shadow: 0 4px 14px rgba(26,86,219,0.30); }
    .btn-ghost { background: var(--white); color: var(--txt2); border-color: var(--bdr2); }
    .btn-ghost:hover { background: var(--bg); color: var(--txt); border-color: var(--bdr2); }
    .btn-sm { padding: 6px 12px; font-size: .7rem; border-radius: 7px; }

    /* ── Toggles ── */
    .aff-toggle-row {
      display: flex; align-items: center; gap: 14px; margin-bottom: 18px;
      padding: 14px 16px; border-radius: 10px; background: var(--bg);
      border: 1px solid var(--bdr); transition: background .2s;
    }
    .aff-toggle { position: relative; display: inline-block; width: 40px; height: 22px; flex-shrink: 0; }
    .aff-toggle input { opacity: 0; width: 0; height: 0; }
    .aff-toggle-slider {
      position: absolute; cursor: pointer; inset: 0;
      background-color: #d1d5db; transition: .3s; border-radius: 34px;
    }
    .aff-toggle-slider:before {
      position: absolute; content: ""; height: 16px; width: 16px;
      left: 3px; bottom: 3px; background: #fff; transition: .3s; border-radius: 50%;
      box-shadow: 0 1px 3px rgba(0,0,0,.2);
    }
    input:checked + .aff-toggle-slider { background-color: var(--primary); }
    input:checked + .aff-toggle-slider:before { transform: translateX(18px); }
    .toggle-info .toggle-title { font-size: .82rem; font-weight: 700; color: var(--txt); }
    .toggle-info .toggle-sub  { font-size: .7rem; color: var(--muted); margin-top: 2px; }

    /* ── Commission selector ── */
    .comm-options { display: flex; gap: 10px; margin-bottom: 14px; }
    .comm-option {
      flex: 1; background: var(--bg); border: 1.5px solid var(--bdr2);
      padding: 14px 16px; border-radius: 10px; cursor: pointer;
      display: flex; align-items: center; gap: 10px; transition: all .2s;
    }
    .comm-option:has(input:checked) { background: var(--primary-l); border-color: var(--primary); }
    .comm-option input { accent-color: var(--primary); }
    .comm-option .co-label { font-size: .82rem; font-weight: 700; color: var(--txt); }
    .comm-option .co-sub { font-size: .69rem; color: var(--muted); margin-top: 2px; }

    /* ── Table ── */
    .table-wrap { overflow-x: auto; }
    .data-table { width: 100%; border-collapse: collapse; }
    .data-table th {
      text-align: left; padding: 12px 16px; font-size: .68rem; font-weight: 700;
      text-transform: uppercase; letter-spacing: .05em; color: var(--muted);
      border-bottom: 2px solid var(--bdr); background: var(--bg);
    }
    .data-table th:first-child { border-radius: 8px 0 0 8px; }
    .data-table th:last-child  { border-radius: 0 8px 8px 0; }
    .data-table td {
      padding: 14px 16px; font-size: .8rem; border-bottom: 1px solid var(--bdr);
      color: var(--txt2); vertical-align: middle;
    }
    .data-table tbody tr:hover td { background: #f9fbff; }
    .data-table tbody tr:last-child td { border-bottom: none; }

    .td-name { font-weight: 700; color: var(--txt); display: flex; align-items: center; gap: 10px; }
    .aff-avatar {
      width: 32px; height: 32px; border-radius: 8px; background: var(--primary-l);
      color: var(--primary); display: flex; align-items: center; justify-content: center;
      font-size: .75rem; font-weight: 800; flex-shrink: 0;
    }

    .st-badge {
      padding: 3px 9px; border-radius: 6px;
      font-size: .65rem; font-weight: 700; text-transform: uppercase; letter-spacing: .04em;
    }
    .st-active  { background: var(--green-l); color: var(--green); }
    .st-pending { background: var(--amber-l); color: var(--amber); }

    /* ── Empty state ── */
    .empty-state { text-align: center; padding: 60px 20px; }
    .empty-state .empty-ico { font-size: 2.5rem; color: var(--bdr2); margin-bottom: 16px; display: block; }
    .empty-state .empty-title { font-size: .95rem; font-weight: 700; color: var(--txt); margin-bottom: 6px; }
    .empty-state .empty-sub { font-size: .78rem; color: var(--muted); }

    /* ── Section divider ── */
    .section-divider { margin: 24px 0; border: none; border-top: 1px solid var(--bdr); }

    /* ── Toastr Overrides ── */
    #toast-container > div {
      opacity: 1 !important; border-radius: 10px !important;
      font-family: 'Plus Jakarta Sans', sans-serif !important;
      font-size: .82rem !important; box-shadow: var(--shadow-lg) !important;
      padding: 14px 18px !important;
    }
    .toast-success { background-color: #0e9f6e !important; }
    .toast-error   { background-color: #e02424 !important; }
  </style>
</head>
<body>

  <!-- Header -->
  <header class="page-header">
    <div class="header-left">
      <div class="header-ico"><i class="fa-solid fa-handshake"></i></div>
      <div class="header-info">
        <h1>Affiliate Management</h1>
        <p>Grow your reach by managing affiliate partners</p>
      </div>
    </div>
    <div class="stat-pills">
      <div class="stat-pill">
        <span class="pill-num"><?= count($affiliates) ?></span>
        <span class="pill-lbl">Total Affiliates</span>
      </div>
      <div class="stat-pill">
        <span class="pill-num"><?= count(array_filter($affiliates, fn($a) => $a['status'] === 'active')) ?></span>
        <span class="pill-lbl">Active</span>
      </div>
    </div>
  </header>

  <!-- Tabs -->
  <div class="aff-tabs">
    <div class="aff-tab <?= $active_tab === 'tab-add'      ? 'active' : '' ?>" onclick="kcTab(this,'tab-add')">
      <i class="fa-solid fa-user-plus"></i> Add Affiliate
    </div>
    <div class="aff-tab <?= $active_tab === 'tab-list'     ? 'active' : '' ?>" onclick="kcTab(this,'tab-list')">
      <i class="fa-solid fa-users"></i> My Affiliates
      <?php if (!empty($affiliates)): ?>
        <span style="background:var(--primary);color:#fff;border-radius:20px;padding:1px 7px;font-size:.62rem;"><?= count($affiliates) ?></span>
      <?php endif; ?>
    </div>
    <div class="aff-tab <?= $active_tab === 'tab-settings' ? 'active' : '' ?>" onclick="kcTab(this,'tab-settings')">
      <i class="fa-solid fa-sliders"></i> Settings
    </div>
  </div>

  <!-- ═══════════ TAB: ADD AFFILIATE ═══════════ -->
  <div class="aff-panel <?= $active_tab === 'tab-add' ? 'active' : '' ?>" id="tab-add">
    <div class="aff-card">
      <div class="aff-card-title">
        <i class="fa-solid fa-user-plus"></i> Register New Affiliate
      </div>
      <form method="POST" action="">
        <input type="hidden" name="_action" value="add_affiliate">
        <input type="hidden" name="_csrf"   value="<?= htmlspecialchars($csrf_token) ?>">

        <div class="aff-grid">
          <div class="aff-field">
            <label class="aff-label">Full Name <span style="color:var(--red)">*</span></label>
            <input type="text" name="full_name" class="aff-input" placeholder="e.g. Jane Muthoni" required>
          </div>
          <div class="aff-field">
            <label class="aff-label">Email Address <span style="color:var(--red)">*</span></label>
            <input type="email" name="email" class="aff-input" placeholder="jane@example.com" required>
          </div>
          <div class="aff-field">
            <label class="aff-label">Phone <span style="color:var(--muted);font-weight:500;">Optional</span></label>
            <input type="text" name="phone" class="aff-input" placeholder="+254 700 000000">
          </div>
        </div>

        <div class="aff-field">
          <label class="aff-label">Internal Notes <span style="color:var(--muted);font-weight:500;">Optional</span></label>
          <textarea name="notes" class="aff-input" rows="3" style="resize:vertical;" placeholder="Private notes about this affiliate (not shown to them)…"></textarea>
        </div>

        <hr class="section-divider">

        <div class="aff-toggle-row">
          <label class="aff-toggle">
            <input type="checkbox" name="send_invite" checked>
            <span class="aff-toggle-slider"></span>
          </label>
          <div class="toggle-info">
            <div class="toggle-title"><i class="fa-solid fa-envelope" style="color:var(--primary);margin-right:5px;"></i>Send email invite</div>
            <div class="toggle-sub">Email login credentials to the affiliate using your SMTP server</div>
          </div>
        </div>

        <div style="display:flex; gap:10px; margin-top:4px;">
          <button type="submit" class="btn btn-primary">
            <i class="fa-solid fa-check"></i> Create Affiliate Account
          </button>
          <button type="reset" class="btn btn-ghost">Clear</button>
        </div>
      </form>
    </div>
  </div>

  <!-- ═══════════ TAB: MY AFFILIATES ═══════════ -->
  <div class="aff-panel <?= $active_tab === 'tab-list' ? 'active' : '' ?>" id="tab-list">
    <div class="aff-card">
      <div class="aff-card-title" style="justify-content:space-between;">
        <span><i class="fa-solid fa-users"></i> Your Affiliate Partners</span>
        <button class="btn btn-primary btn-sm" onclick="kcTab(document.querySelectorAll('.aff-tab')[0],'tab-add')">
          <i class="fa-solid fa-plus"></i> Add New
        </button>
      </div>
      <?php if (empty($affiliates)): ?>
        <div class="empty-state">
          <i class="fa-solid fa-handshake-slash empty-ico"></i>
          <div class="empty-title">No affiliates yet</div>
          <div class="empty-sub">Add your first affiliate partner to get started.</div>
        </div>
      <?php else: ?>
        <div class="table-wrap">
          <table class="data-table">
            <thead>
              <tr>
                <th>Name</th>
                <th>Email</th>
                <th>Phone</th>
                <th>Status</th>
                <th>Joined</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($affiliates as $aff):
                $initials = implode('', array_map(fn($w) => strtoupper($w[0]), array_slice(explode(' ', trim($aff['full_name'])), 0, 2)));
              ?>
                <tr>
                  <td>
                    <div class="td-name">
                      <div class="aff-avatar"><?= htmlspecialchars($initials) ?></div>
                      <?= htmlspecialchars($aff['full_name']) ?>
                    </div>
                  </td>
                  <td><?= htmlspecialchars($aff['email']) ?></td>
                  <td><?= htmlspecialchars($aff['phone'] ?: '—') ?></td>
                  <td>
                    <span class="st-badge st-<?= htmlspecialchars($aff['status']) ?>">
                      <?= ucfirst($aff['status']) ?>
                    </span>
                  </td>
                  <td style="color:var(--muted); font-size:.75rem;">
                    <?= date('d M Y', strtotime($aff['created_at'])) ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- ═══════════ TAB: SETTINGS ═══════════ -->
  <div class="aff-panel <?= $active_tab === 'tab-settings' ? 'active' : '' ?>" id="tab-settings">
    <div class="aff-card">
      <div class="aff-card-title"><i class="fa-solid fa-sliders"></i> Program Configuration</div>
      <form method="POST" action="">
        <input type="hidden" name="_action" value="save_settings">
        <input type="hidden" name="_csrf"   value="<?= htmlspecialchars($csrf_token) ?>">

        <div class="aff-field">
          <label class="aff-label">Commission Structure</label>
          <div class="comm-options">
            <label class="comm-option">
              <input type="radio" name="commission_type" value="percentage" <?= $aff_settings['commission_type']==='percentage' ? 'checked' : '' ?>>
              <div>
                <div class="co-label"><i class="fa-solid fa-percent" style="color:var(--primary);"></i> Percentage</div>
                <div class="co-sub">A % of each sale goes to the affiliate</div>
              </div>
            </label>
            <label class="comm-option">
              <input type="radio" name="commission_type" value="fixed" <?= $aff_settings['commission_type']==='fixed' ? 'checked' : '' ?>>
              <div>
                <div class="co-label"><i class="fa-solid fa-coins" style="color:var(--primary);"></i> Fixed Amount</div>
                <div class="co-sub">A fixed KES amount per converted sale</div>
              </div>
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

        <hr class="section-divider">

        <div class="aff-toggle-row">
          <label class="aff-toggle">
            <input type="checkbox" name="allow_self_register" <?= $aff_settings['allow_self_register'] ? 'checked' : '' ?>>
            <span class="aff-toggle-slider"></span>
          </label>
          <div class="toggle-info">
            <div class="toggle-title">Allow self-registration</div>
            <div class="toggle-sub">Let people apply as affiliates via your public affiliate link</div>
          </div>
        </div>

        <div class="aff-toggle-row">
          <label class="aff-toggle">
            <input type="checkbox" name="require_approval" <?= $aff_settings['require_approval'] ? 'checked' : '' ?>>
            <span class="aff-toggle-slider"></span>
          </label>
          <div class="toggle-info">
            <div class="toggle-title">Require manual approval</div>
            <div class="toggle-sub">New affiliates must be manually approved before they can start</div>
          </div>
        </div>

        <button type="submit" class="btn btn-primary">
          <i class="fa-solid fa-floppy-disk"></i> Save Configuration
        </button>
      </form>
    </div>
  </div>

  <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.js"></script>
  <script>
    toastr.options = {
      closeButton: true, progressBar: true,
      positionClass: "toast-top-right", timeOut: "4000"
    };

    function kcTab(el, panelId) {
      document.querySelectorAll('.aff-tab').forEach(t => t.classList.remove('active'));
      document.querySelectorAll('.aff-panel').forEach(p => p.classList.remove('active'));
      el.classList.add('active');
      document.getElementById(panelId).classList.add('active');
    }

    document.querySelectorAll('input[name="commission_type"]').forEach(r => {
      r.addEventListener('change', function() {
        document.getElementById('commUnit').textContent = this.value === 'percentage' ? '%' : 'KES';
      });
    });

    <?php if ($success_msg): ?>
      toastr.success('<?= addslashes(strip_tags($success_msg)) ?>');
    <?php endif; ?>
    <?php foreach ($errors as $err): ?>
      toastr.error('<?= addslashes($err) ?>');
    <?php endforeach; ?>
  </script>
</body>
</html>