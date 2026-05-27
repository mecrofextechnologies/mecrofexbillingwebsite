<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require 'vendor/autoload.php';
include 'msanii/includes/connect.php';

/* ── CSRF TOKEN ── */
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$errors          = [];
$success_message = "";
$token           = trim($_GET['token'] ?? '');
$creator         = null;
$token_valid     = false;

/* ── VALIDATE TOKEN ── */
if ($token !== '') {
    $stmt = $pdo->prepare("
        SELECT pr.id AS reset_id, pr.creator_id, pr.expires_at,
               c.full_name, c.email
        FROM   password_resets pr
        JOIN   creators c ON c.id = pr.creator_id
        WHERE  pr.token = :tok
        LIMIT  1
    ");
    $stmt->execute(['tok' => $token]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        $errors[] = "This reset link is invalid. Please request a new one.";
    } elseif (strtotime($row['expires_at']) < time()) {
        $errors[] = "This reset link has expired. Please request a new one.";
    } else {
        $token_valid = true;
        $creator     = $row;
    }
}

/* ── FORM SUBMISSION ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $token_valid) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $errors[] = "Invalid session token. Please refresh and try again.";
    } else {
        $password  = $_POST['password']  ?? '';
        $password2 = $_POST['password2'] ?? '';

        if (strlen($password) < 8) {
            $errors[] = "Password must be at least 8 characters.";
        } elseif (!preg_match('/[A-Z]/', $password)) {
            $errors[] = "Password must contain at least one uppercase letter.";
        } elseif (!preg_match('/[0-9]/', $password)) {
            $errors[] = "Password must contain at least one number.";
        } elseif ($password !== $password2) {
            $errors[] = "Passwords do not match.";
        }

        if (empty($errors)) {
            $hashed = password_hash($password, PASSWORD_BCRYPT);
            $upd = $pdo->prepare("UPDATE creators SET password = :p, must_change_password = 0 WHERE id = :id");
            $upd->execute(['p' => $hashed, 'id' => $creator['creator_id']]);

            $del = $pdo->prepare("DELETE FROM password_resets WHERE id = :rid");
            $del->execute(['rid' => $creator['reset_id']]);

            /* Send confirmation email */
            $mail = new PHPMailer(true);
            try {
                $mail->isSMTP();
                $mail->Host = 'mail.keychele.co.ke';
                $mail->SMTPAuth = true;
                $mail->Username = 'notifications@keychele.co.ke';
                $mail->Password = 'King20241064.';
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                $mail->Port = 587;
                $mail->SMTPOptions = ['ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true]];
                $mail->setFrom('notifications@keychele.co.ke', 'Keychele');
                $mail->addAddress($creator['email'], $creator['full_name']);
                $mail->isHTML(true);
                $mail->CharSet = 'UTF-8';
                $mail->Subject = "Your Keychele Password Was Changed";
                $mail->Body = "<div style='font-family:Arial,sans-serif;max-width:560px;margin:0 auto;background:#fff;border-radius:12px;overflow:hidden;border:1px solid #E8E4DF;'><div style='background:linear-gradient(135deg,#C8102E 0%,#FF3356 100%);padding:36px 32px;text-align:center;'><h1 style='color:#fff;font-size:22px;margin:0;'>Password Changed Successfully</h1></div><div style='padding:32px;'><p>Hi <strong>{$creator['full_name']}</strong>,<br>Your Keychele account password was successfully changed.</p><div style='text-align:center;margin-top:20px;'><a href='https://keychele.co.ke/login' style='display:inline-block;background:linear-gradient(135deg,#C8102E 0%,#FF3356 100%);color:#fff;font-size:15px;font-weight:700;text-decoration:none;padding:14px 40px;border-radius:10px;'>Sign In →</a></div></div></div>";
                $mail->send();
            } catch (Exception $e) { error_log("Confirmation email failed: " . $mail->ErrorInfo); }

            $success_message = "Your password has been reset. You can now sign in.";
            $token_valid = false;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title>Keychele — Reset Password</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;500;600;700;800&family=DM+Sans:wght@400;500;700&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  :root {
    --crimson: #C8102E; --crimson-light: #FF3356; --ink: #0D0D0D; --ink-soft: #1A1A2E;
    --slate: #64748B; --mist: #F8F7F5; --white: #FFFFFF; --border: #E8E4DF;
    --font-display: 'Syne', sans-serif; --font-body: 'DM Sans', sans-serif;
    --radius: 20px; --radius-input: 10px;
  }
  html, body { height: 100%; font-family: var(--font-body); background: var(--mist); color: var(--ink); -webkit-font-smoothing: antialiased; }
  .page-wrapper { min-height: 100vh; display: flex; align-items: stretch; }
  .panel-left { display: none; flex: 0 0 42%; position: relative; overflow: hidden; background: var(--ink-soft); }
  @media (min-width: 1024px) { .panel-left { display: flex; flex-direction: column; } }
  .panel-left-img { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; opacity: 0.35; }
  .panel-left-gradient { position: absolute; inset: 0; background: linear-gradient(135deg, rgba(200,16,46,0.9) 0%, rgba(13,13,13,0.95) 100%); mix-blend-mode: multiply; }
  .panel-left-content { position: relative; z-index: 2; display: flex; flex-direction: column; justify-content: space-between; height: 100%; padding: 3rem; }
  .panel-left-logo { display: flex; align-items: center; gap: 12px; }
  .panel-left-logo img { width: 44px; height: 44px; filter: brightness(0) invert(1); }
  .panel-left-logo span { font-family: var(--font-display); font-weight: 800; font-size: 1.25rem; color: white; }
  .panel-left-hero { flex: 1; display: flex; flex-direction: column; justify-content: center; gap: 1.5rem; }
  .panel-left-headline { font-family: var(--font-display); font-weight: 800; font-size: clamp(2rem, 3vw, 2.8rem); line-height: 1.1; color: white; }
  .panel-left-headline em { font-style: normal; color: var(--crimson-light); }
  .panel-right { flex: 1; display: flex; align-items: center; justify-content: center; padding: 1.5rem; background: var(--mist); }
  .form-card { width: 100%; max-width: 440px; background: var(--white); border-radius: var(--radius); box-shadow: 0 32px 80px rgba(13,13,13,0.12); padding: 2.5rem; position: relative; overflow: hidden; }
  @media (max-width: 480px) { .form-card { padding: 2rem 1.5rem; } }
  .form-card::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 3px; background: linear-gradient(90deg, var(--crimson), var(--crimson-light)); }
  .mobile-brand { display: flex; align-items: center; justify-content: center; gap: 10px; margin-bottom: 1.75rem; }
  @media (min-width: 1024px) { .mobile-brand { display: none; } }
  .mobile-brand img { width: 36px; height: 36px; }
  .mobile-brand span { font-family: var(--font-display); font-weight: 800; font-size: 1.15rem; }
  .icon-circle { width: 64px; height: 64px; border-radius: 50%; background: rgba(200,16,46,0.1); border: 1.5px solid rgba(200,16,46,0.15); display: flex; align-items: center; justify-content: center; margin: 0 auto 1.5rem; }
  .icon-circle svg { color: var(--crimson); }
  .form-header { text-align: center; margin-bottom: 2rem; }
  .form-title { font-family: var(--font-display); font-weight: 800; font-size: 1.65rem; margin-bottom: 0.5rem; }
  .form-subtitle { font-size: 0.875rem; color: var(--slate); line-height: 1.6; }
  .field { margin-bottom: 1.25rem; }
  .field label { display: block; font-size: 0.8rem; font-weight: 600; color: #374151; margin-bottom: 0.45rem; }
  .field-input-wrap { position: relative; }
  .field-icon { position: absolute; left: 14px; top: 50%; transform: translateY(-50%); width: 16px; height: 16px; color: #9CA3AF; }
  .field input { width: 100%; height: 50px; padding: 0 14px 0 40px; border: 1.5px solid var(--border); border-radius: var(--radius-input); font-family: var(--font-body); font-size: 0.9rem; background: #FAFAFA; outline: none; transition: all 0.2s; }
  .field input:focus { border-color: var(--crimson); background: var(--white); box-shadow: 0 0 0 3px rgba(200,16,46,0.08); }
  .btn-primary { width: 100%; height: 52px; background: linear-gradient(135deg, var(--crimson) 0%, var(--crimson-light) 100%); color: white; border: none; border-radius: var(--radius-input); font-weight: 700; cursor: pointer; box-shadow: 0 4px 20px rgba(200,16,46,0.4); display: flex; align-items: center; justify-content: center; gap: 8px; }
  .btn-primary:disabled { opacity: 0.7; cursor: not-allowed; }
  .success-state { text-align: center; }
  .success-icon { width: 80px; height: 80px; border-radius: 50%; background: #ECFDF5; border: 2px solid #A7F3D0; display: flex; align-items: center; justify-content: center; margin: 0 auto 1.5rem; }
  .success-icon svg { color: #059669; }
  @keyframes spin { to { transform: rotate(360deg); } }
  .spinner { animation: spin 0.8s linear infinite; }
</style>
</head>
<body>
<div class="page-wrapper">
  <div class="panel-left">
    <img src="https://images.unsplash.com/photo-1633265485768-3069ee5137be?w=900" class="panel-left-img" alt="">
    <div class="panel-left-gradient"></div>
    <div class="panel-left-content">
      <div class="panel-left-logo"><img src="images/logo/KECHELE LOGO.png" alt="Keychele"><span>Keychele</span></div>
      <div class="panel-left-hero">
        <h1 class="panel-left-headline">Secure your<br><em>account.</em></h1>
        <p class="panel-left-desc">Choose a strong password to keep your profile safe.</p>
      </div>
      <div class="panel-left-footer">© <?= date('Y') ?> Keychele</div>
    </div>
  </div>
  <div class="panel-right">
    <div class="form-card">
      <div class="mobile-brand"><img src="images/logo/KECHELE LOGO.png" alt="Keychele"><span>Keychele</span></div>
      <?php if (!empty($success_message)): ?>
      <div class="success-state">
        <div class="success-icon"><svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg></div>
        <h2 class="form-title">Success!</h2>
        <p class="form-subtitle"><?= $success_message ?></p>
        <a href="login" class="btn-primary" style="text-decoration:none; margin-top:1.5rem;">Sign In Now</a>
      </div>
      <?php elseif (!$token_valid): ?>
      <div class="success-state">
        <div class="icon-circle" style="background:rgba(200,16,46,0.1);"><svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg></div>
        <h2 class="form-title">Invalid Link</h2>
        <p class="form-subtitle"><?= $errors[0] ?? 'This link is no longer valid.' ?></p>
        <a href="forgot-password" class="btn-primary" style="text-decoration:none; margin-top:1.5rem;">Request New Link</a>
      </div>
      <?php else: ?>
      <div class="icon-circle"><svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3-3.5 3.5z"/></svg></div>
      <div class="form-header"><h2 class="form-title">Reset Password</h2><p class="form-subtitle">Hi <strong><?= htmlspecialchars($creator['full_name']) ?></strong>, enter your new password.</p></div>
      <form method="POST" id="resetForm">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
        <div class="field"><label>New Password</label><div class="field-input-wrap"><svg class="field-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg><input type="password" name="password" required placeholder="••••••••"></div></div>
        <div class="field"><label>Confirm Password</label><div class="field-input-wrap"><svg class="field-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg><input type="password" name="password2" required placeholder="••••••••"></div></div>
        <button type="submit" class="btn-primary" id="submitBtn">Update Password</button>
      </form>
      <?php endif; ?>
    </div>
  </div>
</div>
<script>
  const Toast = Swal.mixin({ toast: true, position: 'top-end', showConfirmButton: false, timer: 3000, timerProgressBar: true });
  <?php if (!empty($errors)): foreach ($errors as $e): ?>
    Toast.fire({ icon: 'error', title: '<?= addslashes($e) ?>' });
  <?php endforeach; endif; ?>
  document.getElementById('resetForm')?.addEventListener('submit', function () {
    const btn = document.getElementById('submitBtn');
    btn.disabled = true;
    btn.innerHTML = `<svg class="spinner" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"/></svg> Updating…`;
  });
</script>
</body>
</html>
