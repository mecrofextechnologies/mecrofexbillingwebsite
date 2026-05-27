<?php 

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require '../vendor/autoload.php';
include 'includes/connect.php';

if (!isset($_SESSION['creator_id'])) {
    header("Location: ../login");
    exit;
}

$creator_id = $_SESSION['creator_id'];
$success_msg = '';
$error_msg = '';

// Fetch current creator info + profile pic from my_page
$stmt = $pdo->prepare("
    SELECT c.full_name, c.username, c.email,
           mp.profile_pic, mp.full_name AS page_name
    FROM creators c
    LEFT JOIN my_page mp ON c.id = mp.creator_id
    WHERE c.id = ?
");
$stmt->execute([$creator_id]);
$creator = $stmt->fetch(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name = trim($_POST['full_name'] ?? '');
    $username  = trim($_POST['username'] ?? '');
    $password  = trim($_POST['password'] ?? '');

    if ($full_name === '' || $username === '') {
        $error_msg = "Full Name and Username are required.";
    } else {
        try {
            $stmt = $pdo->prepare("SELECT id FROM creators WHERE username = ? AND id != ?");
            $stmt->execute([$username, $creator_id]);
            if ($stmt->fetch()) {
                $error_msg = "Username already taken. Please choose another.";
            } else {
                if ($password !== '') {
                    $hashed = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare("UPDATE creators SET full_name = ?, username = ?, password = ? WHERE id = ?");
                    $stmt->execute([$full_name, $username, $hashed, $creator_id]);
                } else {
                    $stmt = $pdo->prepare("UPDATE creators SET full_name = ?, username = ? WHERE id = ?");
                    $stmt->execute([$full_name, $username, $creator_id]);
                }

                $success_msg = "Profile updated successfully!";

                // Email notification
                $mail = new PHPMailer(true);
                try {
                    $mail->isSMTP();
                    $mail->Host       = 'mail.pioneercenter.co.ke';
                    $mail->SMTPAuth   = true;
                    $mail->Username   = 'notifications@pioneercenter.co.ke';
                    $mail->Password   = 'King20241064.';
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
                    $mail->Port       = 465;
                    $mail->SMTPOptions = ['ssl' => ['verify_peer'=>false,'verify_peer_name'=>false,'allow_self_signed'=>true]];
                    $mail->SMTPDebug  = 0;
                    $mail->setFrom('notifications@pioneercenter.co.ke', 'Keychele');
                    $mail->addAddress($creator['email'], $full_name);
                    $mail->CharSet = 'UTF-8';
                    $mail->isHTML(true);
                    $mail->Subject = "Profile Update Notification";
                    $mail->Body = "
                    <div style='font-family:Arial;background:#f5f5f5;padding:30px;'>
                      <div style='max-width:600px;margin:auto;background:#fff;padding:25px;border-radius:10px;'>
                        <h2 style='color:#bf1339;text-align:center;'>Profile Updated</h2>
                        <p>Hello <strong>".htmlspecialchars($full_name)."</strong>,</p>
                        <p>Your profile has been successfully updated.</p>
                        <p><strong>Full Name:</strong> ".htmlspecialchars($full_name)."<br>
                           <strong>Username:</strong> ".htmlspecialchars($username)."</p>
                        <p>If you didn't make this change, please contact support immediately.</p>
                        <p style='margin-top:20px;font-size:13px;color:#777;'>Best regards,<br>Keychele Team</p>
                      </div>
                    </div>";
                    $mail->AltBody = "Hello $full_name,\n\nYour profile has been updated.\n\nFull Name: $full_name\nUsername: $username\n\nKeychele Team";
                    $mail->send();
                } catch (Exception $e) { /* silent */ }

                // Refresh
                $stmt = $pdo->prepare("SELECT c.full_name, c.username, c.email, mp.profile_pic FROM creators c LEFT JOIN my_page mp ON c.id = mp.creator_id WHERE c.id = ?");
                $stmt->execute([$creator_id]);
                $creator = $stmt->fetch(PDO::FETCH_ASSOC);
            }
        } catch (Exception $e) {
            $error_msg = "Error updating profile: " . $e->getMessage();
        }
    }
}

// Build initials
$display_name = $creator['full_name'] ?? $creator['username'] ?? 'Creator';
$parts = array_filter(explode(' ', trim($display_name)));
if (count($parts) >= 2) {
    $initials = strtoupper(mb_substr($parts[0],0,1) . mb_substr(end($parts),0,1));
} else {
    $initials = strtoupper(mb_substr($display_name, 0, 2));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>My Profile — Keychele</title>

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.css"/>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.js"></script>

  <style>
  @font-face {
    font-family: 'GlacialI';
    src: url('https://db.onlinewebfonts.com/t/74f7b46c5a6a4374c10b2cb3ef8b4fc1.woff2') format('woff2');
    font-weight: 400;
  }

  :root {
    --red:     #bf1339;
    --red-l:   #e8143f;
    --gold:    #fec411;
    --gold-d:  #d4a60a;
    --navy:    #052e64;
    --navy-l:  #0a4494;
    --bg:      #eef1f8;
    --card:    #ffffff;
    --border:  rgba(5,46,100,0.1);
    --text:    #0f1923;
    --muted:   #6b7a99;
    --success: #00b37e;
    --r:       14px;
    --r-lg:    20px;
    --sh:      0 4px 24px rgba(5,46,100,0.10);
    --sh-lg:   0 12px 48px rgba(5,46,100,0.15);
    --trn:     0.22s ease;
  }

  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

  body {
    font-family: 'GlacialI', 'Trebuchet MS', sans-serif;
    background: var(--bg);
    color: var(--text);
    -webkit-font-smoothing: antialiased;
    padding-bottom: 80px;
    min-height: 100vh;
  }

  h1,h2,h3,h4,h5 { font-family: 'Montserrat', sans-serif; }

  /* ── PAGE WRAP ── */
  .page {
    padding: 1.5rem;
    max-width: 680px;
    margin: 0 auto;
  }
  @media (max-width: 600px) { .page { padding: 1rem 0.9rem; } }

  /* ── HERO ── */
  .page-hero {
    position: relative; overflow: hidden;
    background: linear-gradient(120deg, var(--navy) 0%, #0a3a7a 55%, #0f4faa 100%);
    border-radius: var(--r-lg);
    padding: 2rem 2.25rem;
    margin-bottom: 1.5rem;
    box-shadow: var(--sh-lg), 0 0 0 1px rgba(255,255,255,0.05) inset;
    animation: heroIn 0.55s cubic-bezier(0.16,1,0.3,1) both;
  }

  @keyframes heroIn {
    from { opacity:0; transform:translateY(20px); }
    to   { opacity:1; transform:translateY(0); }
  }

  .page-hero::before {
    content: ''; position: absolute; inset: 0; pointer-events: none;
    background-image: linear-gradient(rgba(255,255,255,0.03) 1px, transparent 1px),
                      linear-gradient(90deg, rgba(255,255,255,0.03) 1px, transparent 1px);
    background-size: 34px 34px;
  }

  .hero-orb1 {
    position: absolute; border-radius: 50%; pointer-events: none;
    width: 260px; height: 260px; top: -90px; right: -50px;
    background: radial-gradient(circle, rgba(254,196,17,0.16) 0%, transparent 65%);
  }
  .hero-orb2 {
    position: absolute; border-radius: 50%; pointer-events: none;
    width: 160px; height: 160px; bottom: -60px; left: 20%;
    background: radial-gradient(circle, rgba(191,19,57,0.18) 0%, transparent 65%);
  }

  .hero-inner {
    position: relative; z-index: 1;
    display: flex; align-items: center;
    justify-content: space-between; gap: 1.5rem;
  }

  .hero-tag {
    display: inline-flex; align-items: center; gap: 6px;
    background: rgba(254,196,17,0.14);
    border: 1px solid rgba(254,196,17,0.28);
    border-radius: 100px; padding: 4px 12px;
    font-family: 'Montserrat', sans-serif;
    font-size: 0.65rem; font-weight: 700;
    letter-spacing: 0.09em; text-transform: uppercase;
    color: var(--gold); margin-bottom: 0.65rem;
  }

  .hero-title {
    font-family: 'Montserrat', sans-serif;
    font-weight: 900;
    font-size: clamp(1.4rem, 3vw, 2rem);
    color: white; letter-spacing: -0.04em; line-height: 1.15;
    margin-bottom: 0.35rem;
  }

  .hero-sub { font-size: 0.83rem; color: rgba(255,255,255,0.5); }

  /* Profile avatar in hero */
  .hero-avatar {
    width: 72px; height: 72px; border-radius: 50%;
    background: linear-gradient(135deg, var(--red), var(--red-l));
    display: flex; align-items: center; justify-content: center;
    font-family: 'Montserrat', sans-serif;
    font-weight: 800; font-size: 1.5rem; color: white;
    border: 3px solid rgba(255,255,255,0.2);
    box-shadow: 0 0 0 5px rgba(191,19,57,0.2);
    flex-shrink: 0; overflow: hidden;
  }
  .hero-avatar img { width: 100%; height: 100%; object-fit: cover; }

  @media (max-width: 400px) { .hero-avatar { width: 56px; height: 56px; font-size: 1.1rem; } }

  /* ── SECTION LABEL ── */
  .sec-label {
    font-family: 'Montserrat', sans-serif;
    font-size: 0.68rem; font-weight: 700;
    text-transform: uppercase; letter-spacing: 0.1em;
    color: var(--muted);
    display: flex; align-items: center; gap: 8px;
    margin-bottom: 1rem;
  }
  .sec-label::before {
    content: ''; width: 3px; height: 14px; border-radius: 3px;
    background: linear-gradient(180deg, var(--red), var(--gold));
    flex-shrink: 0;
  }

  /* ── CARD ── */
  .card {
    background: var(--card);
    border-radius: var(--r-lg);
    border: 1px solid var(--border);
    box-shadow: var(--sh);
    overflow: hidden;
    margin-bottom: 1.25rem;
    animation: cardIn 0.5s cubic-bezier(0.16,1,0.3,1) 0.05s both;
  }
  @keyframes cardIn {
    from { opacity:0; transform:translateY(14px); }
    to   { opacity:1; transform:translateY(0); }
  }

  .card-head {
    padding: 1.15rem 1.5rem 1rem;
    border-bottom: 1px solid var(--border);
    background: #fafbfd;
    display: flex; align-items: center; gap: 10px;
  }

  .card-head-ico {
    width: 36px; height: 36px; border-radius: 9px;
    display: flex; align-items: center; justify-content: center;
    font-size: 0.88rem; flex-shrink: 0;
  }

  .card-head-title {
    font-family: 'Montserrat', sans-serif;
    font-weight: 700; font-size: 0.9rem; color: var(--text);
  }
  .card-head-sub { font-size: 0.72rem; color: var(--muted); margin-top: 2px; }

  .card-body { padding: 1.5rem; }

  /* ── FIELDS ── */
  .field { margin-bottom: 1.15rem; }

  .field-label {
    display: block;
    font-family: 'Montserrat', sans-serif;
    font-size: 0.73rem; font-weight: 700;
    color: #374151; margin-bottom: 0.45rem;
    letter-spacing: 0.01em;
  }

  .field-hint {
    font-size: 0.68rem; color: var(--muted);
    margin-top: 5px; font-family: 'GlacialI', sans-serif;
  }

  .fi { position: relative; }
  .fi-icon {
    position: absolute; left: 13px; top: 50%;
    transform: translateY(-50%);
    font-size: 0.82rem; color: #9ca3af;
    pointer-events: none; z-index: 1;
  }

  input[type=text], input[type=email],
  input[type=password], input[type=number] {
    width: 100%;
    font-family: 'GlacialI', 'Trebuchet MS', sans-serif;
    font-size: 0.88rem; color: var(--text);
    background: #fafafa;
    border: 1.5px solid rgba(5,46,100,0.14);
    border-radius: 10px;
    padding: 11px 14px 11px 40px;
    outline: none;
    transition: border-color var(--trn), box-shadow var(--trn), background var(--trn);
    appearance: none;
  }

  input:focus {
    border-color: var(--navy);
    background: #fff;
    box-shadow: 0 0 0 3px rgba(5,46,100,0.08);
  }

  input[readonly], input:disabled {
    background: #f1f3f8 !important;
    color: var(--muted) !important;
    cursor: not-allowed;
    opacity: 0.8;
  }

  /* Password toggle */
  .pw-wrap { position: relative; }
  .pw-toggle {
    position: absolute; right: 13px; top: 50%;
    transform: translateY(-50%);
    background: none; border: none;
    color: var(--muted); font-size: 0.82rem;
    cursor: pointer; padding: 4px;
    transition: color 0.18s;
  }
  .pw-toggle:hover { color: var(--navy); }

  /* ── INFO BADGE ── */
  .info-badge {
    display: flex; align-items: flex-start; gap: 10px;
    background: rgba(5,46,100,0.05);
    border: 1px solid rgba(5,46,100,0.12);
    border-radius: 10px; padding: 12px 14px;
    margin-bottom: 1.25rem;
  }
  .info-badge i {
    color: var(--navy); font-size: 0.85rem;
    margin-top: 2px; flex-shrink: 0;
  }
  .info-badge-text {
    font-size: 0.78rem; color: var(--muted);
    line-height: 1.55;
  }
  .info-badge-text strong {
    font-family: 'Montserrat', sans-serif;
    color: var(--navy); font-size: 0.75rem;
  }

  /* ── DIVIDER ── */
  .divider {
    height: 1px; background: var(--border);
    margin: 1.25rem 0;
  }

  /* ── SUBMIT BTN ── */
  .submit-btn {
    width: 100%; height: 50px;
    background: linear-gradient(135deg, var(--red) 0%, var(--red-l) 100%);
    border: none; border-radius: var(--r);
    color: white;
    font-family: 'Montserrat', sans-serif;
    font-weight: 800; font-size: 0.92rem;
    letter-spacing: 0.02em; cursor: pointer;
    display: flex; align-items: center; justify-content: center; gap: 9px;
    box-shadow: 0 4px 20px rgba(191,19,57,0.35);
    transition: transform 0.2s, box-shadow 0.2s;
    margin-top: 0.25rem;
  }
  .submit-btn:hover { transform: translateY(-2px); box-shadow: 0 8px 28px rgba(191,19,57,0.48); }
  .submit-btn:active { transform: translateY(0); }
  .submit-btn:disabled { opacity: 0.65; cursor: not-allowed; transform: none; }

  /* ── SECURITY CARD ── */
  .security-row {
    display: flex; align-items: center; gap: 12px;
    padding: 12px 0;
    border-bottom: 1px solid var(--border);
  }
  .security-row:last-child { border-bottom: none; padding-bottom: 0; }

  .security-ico {
    width: 36px; height: 36px; border-radius: 9px;
    display: flex; align-items: center; justify-content: center;
    font-size: 0.82rem; flex-shrink: 0;
  }

  .security-info { flex: 1; min-width: 0; }
  .security-title {
    font-family: 'Montserrat', sans-serif;
    font-weight: 700; font-size: 0.8rem; color: var(--text);
  }
  .security-sub { font-size: 0.7rem; color: var(--muted); margin-top: 2px; }

  .security-status {
    font-family: 'Montserrat', sans-serif;
    font-size: 0.62rem; font-weight: 700;
    padding: 3px 9px; border-radius: 100px;
    letter-spacing: 0.04em; text-transform: uppercase;
  }
  .s-ok     { background: rgba(0,179,126,0.1); color: #065f46; }
  .s-warn   { background: rgba(245,158,11,0.1); color: #92400e; }
  .s-locked { background: rgba(5,46,100,0.1); color: var(--navy); }

  /* ── TOASTR OVERRIDES ── */
  #toast-container > .toast {
    border-radius: 12px !important;
    box-shadow: 0 8px 32px rgba(0,0,0,0.14) !important;
    font-family: 'GlacialI', sans-serif !important;
    font-size: 0.875rem !important;
    padding: 14px 18px 14px 56px !important;
  }
  #toast-container > .toast-success { background-color: #064E3B !important; border-left: 4px solid #10B981 !important; }
  #toast-container > .toast-error   { background-color: #7F1D1D !important; border-left: 4px solid #EF4444 !important; }
  #toast-container > .toast-info    { background-color: var(--navy) !important; border-left: 4px solid var(--gold) !important; }
  #toast-container > .toast-warning { background-color: #78350F !important; border-left: 4px solid #f59e0b !important; }

  @keyframes spin { to { transform: rotate(360deg); } }
  .spinner { animation: spin 0.8s linear infinite; display: inline-block; }
  </style>
</head>
<body>

<div class="page">

  <!-- ══ HERO ══ -->
  <div class="page-hero">
    <div class="hero-orb1"></div>
    <div class="hero-orb2"></div>
    <div class="hero-inner">
      <div>
        <div class="hero-tag">
          <i class="fa-solid fa-user-pen"></i> Account
        </div>
        <h1 class="hero-title">My Profile</h1>
        <p class="hero-sub">Manage your personal information &amp; security</p>
      </div>

      <!-- Avatar: show profile_pic if exists, else initials -->
      <div class="hero-avatar">
        <?php if (!empty($creator['profile_pic'])): ?>
          <img src="<?= htmlspecialchars($creator['profile_pic']) ?>" alt="Profile">
        <?php else: ?>
          <?= htmlspecialchars($initials) ?>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <form method="POST" id="profileForm">

    <!-- ══ PERSONAL INFO ══ -->
    <div class="sec-label">Personal Information</div>

    <div class="card">
      <div class="card-head">
        <div class="card-head-ico" style="background:rgba(5,46,100,0.08); color:var(--navy)">
          <i class="fa-solid fa-id-card"></i>
        </div>
        <div>
          <div class="card-head-title">Basic Details</div>
          <div class="card-head-sub">Your name and public username</div>
        </div>
      </div>
      <div class="card-body">

        <div class="field">
          <label class="field-label">Full Name <span style="color:var(--red)">*</span></label>
          <div class="fi">
            <i class="fi-icon fa-solid fa-user"></i>
            <input type="text" name="full_name" required
                   value="<?= htmlspecialchars($creator['full_name'] ?? '') ?>"
                   placeholder="Your full name">
          </div>
        </div>

        <div class="field">
          <label class="field-label">Username <span style="color:var(--red)">*</span></label>
          <div class="fi">
            <i class="fi-icon fa-solid fa-at"></i>
            <input type="text" name="username" required
                   value="<?= htmlspecialchars($creator['username'] ?? '') ?>"
                   placeholder="Your username">
          </div>
          <div class="field-hint">Your public handle on Keychele. Must be unique.</div>
        </div>

        <div class="field">
          <label class="field-label">
            Email Address
            <span style="font-family:'GlacialI',sans-serif; font-weight:400; color:var(--muted); font-size:0.68rem; margin-left:6px; text-transform:none; letter-spacing:0">(Cannot be changed)</span>
          </label>
          <div class="fi">
            <i class="fi-icon fa-solid fa-envelope"></i>
            <input type="email"
                   value="<?= htmlspecialchars($creator['email'] ?? '') ?>"
                   readonly>
          </div>
          <div class="field-hint">To change your email, contact Keychele support.</div>
        </div>

      </div>
    </div>

    <!-- ══ SECURITY ══ -->
    <div class="sec-label" style="margin-top:1.5rem">Security</div>

    <div class="card">
      <div class="card-head">
        <div class="card-head-ico" style="background:rgba(191,19,57,0.08); color:var(--red)">
          <i class="fa-solid fa-shield-halved"></i>
        </div>
        <div>
          <div class="card-head-title">Password & Security</div>
          <div class="card-head-sub">Keep your account safe</div>
        </div>
      </div>
      <div class="card-body">

        <div class="info-badge">
          <i class="fa-solid fa-circle-info"></i>
          <div class="info-badge-text">
            <strong>Tip:</strong> Leave the password field blank if you don't want to change it.
            A notification email will be sent to <strong><?= htmlspecialchars($creator['email'] ?? '') ?></strong> whenever your profile is updated.
          </div>
        </div>

        <div class="field">
          <label class="field-label">New Password</label>
          <div class="fi pw-wrap">
            <i class="fi-icon fa-solid fa-lock"></i>
            <input type="password" name="password" id="pwInput"
                   placeholder="Leave blank to keep current password"
                   autocomplete="new-password">
            <button type="button" class="pw-toggle" onclick="togglePw()" id="pwToggleBtn">
              <i class="fa-solid fa-eye" id="pwIcon"></i>
            </button>
          </div>
          <div class="field-hint">Minimum 8 characters recommended. Use a mix of letters, numbers, and symbols.</div>
        </div>

        <!-- Strength meter -->
        <div id="pwStrengthWrap" style="margin-top:-0.5rem; margin-bottom:1rem; display:none;">
          <div style="display:flex; gap:4px; margin-bottom:4px;">
            <div class="pw-seg" id="seg1"></div>
            <div class="pw-seg" id="seg2"></div>
            <div class="pw-seg" id="seg3"></div>
            <div class="pw-seg" id="seg4"></div>
          </div>
          <span id="pwStrengthLabel" style="font-family:'Montserrat',sans-serif; font-size:0.65rem; font-weight:700; letter-spacing:0.05em;"></span>
        </div>

        <style>
          .pw-seg {
            flex: 1; height: 3px; border-radius: 3px;
            background: rgba(5,46,100,0.1);
            transition: background 0.25s;
          }
        </style>

        <!-- Security status rows -->
        <div class="divider"></div>

        <div class="security-row">
          <div class="security-ico" style="background:rgba(0,179,126,0.1); color:#00b37e">
            <i class="fa-solid fa-envelope-circle-check"></i>
          </div>
          <div class="security-info">
            <div class="security-title">Email Verified</div>
            <div class="security-sub">Your account email is confirmed</div>
          </div>
          <span class="security-status s-ok">Verified</span>
        </div>

        <div class="security-row">
          <div class="security-ico" style="background:rgba(5,46,100,0.08); color:var(--navy)">
            <i class="fa-solid fa-lock"></i>
          </div>
          <div class="security-info">
            <div class="security-title">Password Protection</div>
            <div class="security-sub">Your account is password protected</div>
          </div>
          <span class="security-status s-locked">Active</span>
        </div>

        <div class="security-row">
          <div class="security-ico" style="background:rgba(245,158,11,0.1); color:#f59e0b">
            <i class="fa-solid fa-bell"></i>
          </div>
          <div class="security-info">
            <div class="security-title">Change Notifications</div>
            <div class="security-sub">Email alerts sent on profile updates</div>
          </div>
          <span class="security-status s-ok">On</span>
        </div>

      </div>
    </div>

    <!-- Submit -->
    <button type="submit" class="submit-btn" id="submitBtn">
      <i class="fa-solid fa-floppy-disk"></i>
      Save Changes
    </button>

  </form>

</div><!-- /page -->

<script>
/* ── Toastr config ── */
toastr.options = {
  closeButton:     true,
  progressBar:     true,
  positionClass:   'toast-top-right',
  timeOut:         5500,
  extendedTimeOut: 1500,
  showMethod:      'slideDown',
  hideMethod:      'fadeOut'
};

<?php if ($success_msg): ?>
toastr.success(
  '<strong><?= addslashes($success_msg) ?></strong><br><small>A confirmation email has been sent to your inbox.</small>',
  '<i class="fa-solid fa-circle-check" style="margin-right:6px"></i> Profile Updated',
  { timeOut: 6000 }
);
<?php endif; ?>

<?php if ($error_msg): ?>
toastr.error(
  '<strong><?= addslashes($error_msg) ?></strong><br><small>Please review and try again.</small>',
  '<i class="fa-solid fa-circle-exclamation" style="margin-right:6px"></i> Error',
  { timeOut: 7000 }
);
<?php endif; ?>

/* ── Password toggle ── */
function togglePw() {
  const input = document.getElementById('pwInput');
  const icon  = document.getElementById('pwIcon');
  const isHidden = input.type === 'password';
  input.type = isHidden ? 'text' : 'password';
  icon.className = isHidden ? 'fa-solid fa-eye-slash' : 'fa-solid fa-eye';
}

/* ── Password strength meter ── */
const pwInput = document.getElementById('pwInput');
const wrap    = document.getElementById('pwStrengthWrap');
const label   = document.getElementById('pwStrengthLabel');
const segs    = [document.getElementById('seg1'), document.getElementById('seg2'),
                 document.getElementById('seg3'), document.getElementById('seg4')];

const colors  = ['#ef4444','#f59e0b','#3b82f6','#10b981'];
const labels  = ['Weak','Fair','Good','Strong'];

pwInput.addEventListener('input', function () {
  const val = this.value;

  if (!val) {
    wrap.style.display = 'none';
    return;
  }

  wrap.style.display = 'block';

  let score = 0;
  if (val.length >= 8)          score++;
  if (/[A-Z]/.test(val))        score++;
  if (/[0-9]/.test(val))        score++;
  if (/[^A-Za-z0-9]/.test(val)) score++;
  score = Math.max(1, score);

  segs.forEach((seg, i) => {
    seg.style.background = i < score ? colors[score - 1] : 'rgba(5,46,100,0.1)';
  });

  label.textContent = labels[score - 1];
  label.style.color = colors[score - 1];
});

/* ── Form submit loading state ── */
document.getElementById('profileForm').addEventListener('submit', function () {
  const btn = document.getElementById('submitBtn');
  btn.disabled = true;
  btn.innerHTML = `
    <svg class="spinner" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
      <path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"/>
    </svg>
    Saving Changes...
  `;
});
</script>

</body>
</html>