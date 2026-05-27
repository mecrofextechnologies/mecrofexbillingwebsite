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
$email_sent_to   = "";

/* ── TALKSASA SMS FUNCTION ── */
function sendTalkSasaSMS($phone, $message) {
    $url = 'https://bulksms.talksasa.com/api/v3/sms/send';
    $apiToken = '2059|f4JXd7XM8Lwmi5QEDHik9onDsOkNMS0XuwXGhQq9ab191461';
    
    $data = [
        'recipient' => $phone,
        'sender_id' => 'TALKSASA',
        'type'      => 'plain',
        'message'   => $message
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $apiToken,
        'Content-Type: application/json',
        'Accept: application/json'
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ['response' => $response, 'http_code' => $httpCode];
}

/* ── FORM SUBMISSION ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    /* CSRF check */
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $errors[] = "Invalid session token. Please refresh and try again.";
    } else {
        $email = trim($_POST['email'] ?? '');

        /* Basic validation */
        if ($email === '') {
            $errors[] = "Please enter your email address.";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = "Please enter a valid email address.";
        } else {
            /* Check the email exists in the creators table */
            $stmt = $pdo->prepare("SELECT id, full_name, phone FROM creators WHERE email = :e LIMIT 1");
            $stmt->execute(['e' => $email]);
            $creator = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$creator) {
                $success_message = "If that email is registered, a password reset link has been sent.";
                $email_sent_to   = $email;
            } else {
                /* Generate a secure token valid for 60 minutes */
                $token     = bin2hex(random_bytes(32));
                $expires   = date('Y-m-d H:i:s', strtotime('+60 minutes'));
                $full_name = $creator['full_name'];
                $user_id   = $creator['id'];
                $phone     = $creator['phone'] ?? null;

                /* Delete any previous unused tokens for this user */
                $del = $pdo->prepare("DELETE FROM password_resets WHERE creator_id = :uid");
                $del->execute(['uid' => $user_id]);

                /* Insert new token */
                $ins = $pdo->prepare("
                    INSERT INTO password_resets (creator_id, token, expires_at)
                    VALUES (:uid, :tok, :exp)
                ");
                $ins->execute(['uid' => $user_id, 'tok' => $token, 'exp' => $expires]);

                $reset_link = "https://keychele.co.ke/reset-password?token=" . urlencode($token);

                /* Send the email */
                $mail = new PHPMailer(true);
                try {
                    $mail->isSMTP();
                    $mail->Host        = 'mail.keychele.co.ke';
                    $mail->SMTPAuth    = true;
                    $mail->Username    = 'notifications@keychele.co.ke';
                    $mail->Password    = 'King20241064.';
                    $mail->SMTPSecure  = PHPMailer::ENCRYPTION_STARTTLS;
                    $mail->Port        = 587;
                    $mail->SMTPOptions = ['ssl' => [
                        'verify_peer'      => false,
                        'verify_peer_name' => false,
                        'allow_self_signed'=> true,
                    ]];

                    $mail->setFrom('notifications@keychele.co.ke', 'Keychele');
                    $mail->addAddress($email, $full_name);
                    $mail->isHTML(true);
                    $mail->CharSet = 'UTF-8';
                    $mail->Subject = "Reset Your Keychele Password";

                    $mail->Body = "
                        <div style='font-family:Arial,sans-serif;max-width:560px;margin:0 auto;background:#ffffff;border-radius:12px;overflow:hidden;border:1px solid #E8E4DF;'>
                          <div style='background:linear-gradient(135deg,#C8102E 0%,#FF3356 100%);padding:36px 32px;text-align:center;'>
                            <h1 style='color:#ffffff;font-size:24px;margin:0;'>Password Reset Request</h1>
                            <p style='color:rgba(255,255,255,0.85);font-size:14px;margin:8px 0 0;'>We received a request to reset your password.</p>
                          </div>
                          <div style='padding:32px;'>
                            <p style='color:#374151;font-size:15px;line-height:1.7;margin:0 0 20px;'>
                              Hi <strong>{$full_name}</strong>,<br>
                              Click the button below to reset your password. This link is valid for <strong>60 minutes</strong>.
                            </p>
                            <div style='text-align:center;margin:28px 0;'>
                              <a href='{$reset_link}'
                                 style='display:inline-block;background:linear-gradient(135deg,#C8102E 0%,#FF3356 100%);color:#ffffff;font-size:15px;font-weight:700;text-decoration:none;padding:16px 44px;border-radius:10px;'>
                                Reset My Password →
                              </a>
                            </div>
                            <div style='background:#FEF2F2;border:1px solid #FECACA;border-radius:8px;padding:14px 16px;margin-bottom:20px;'>
                              <p style='color:#991B1B;font-size:13px;margin:0;line-height:1.6;'>
                                ⚠️ If you did not request a password reset, you can safely ignore this email.
                              </p>
                            </div>
                            <p style='color:#6B7280;font-size:12px;line-height:1.8;'>
                              If the button doesn't work, copy and paste this link:<br>
                              <a href='{$reset_link}' style='color:#C8102E;word-break:break-all;'>{$reset_link}</a>
                            </p>
                          </div>
                          <div style='background:#F9FAFB;border-top:1px solid #E5E7EB;padding:20px 32px;text-align:center;'>
                            <p style='color:#9CA3AF;font-size:12px;margin:0;'>© " . date('Y') . " Keychele. All rights reserved.</p>
                          </div>
                        </div>
                    ";

                    $mail->AltBody = "Hi {$full_name},\n\nReset your Keychele password using the link below (valid for 60 minutes):\n{$reset_link}";
                    $mail->send();

                } catch (Exception $e) {
                    error_log("Password reset email failed: " . $mail->ErrorInfo);
                    $errors[] = "Could not send the reset email. Please try again.";
                }

                /* Send the SMS via TalkSasa */
                if (!empty($phone)) {
                    $smsMessage = "Hi {$full_name}, reset your Keychele password using this link: {$reset_link} (Valid for 60 mins).";
                    sendTalkSasaSMS($phone, $smsMessage);
                }

                if (empty($errors)) {
                    $success_message = "If that email is registered, a password reset link has been sent.";
                    $email_sent_to   = $email;
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title>Keychele — Forgot Password</title>
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
  .panel-left-desc { font-size: 0.95rem; line-height: 1.7; color: rgba(255,255,255,0.6); max-width: 300px; }
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
  .back-link { text-align: center; margin-top: 1.5rem; font-size: 0.82rem; }
  .back-link a { color: var(--crimson); font-weight: 600; text-decoration: none; display: inline-flex; align-items: center; gap: 5px; }
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
    <img src="https://images.unsplash.com/photo-1556740749-887f6717d7e4?w=900" class="panel-left-img" alt="">
    <div class="panel-left-gradient"></div>
    <div class="panel-left-content">
      <div class="panel-left-logo"><img src="images/logo/KECHELE LOGO.png" alt="Keychele"><span>Keychele</span></div>
      <div class="panel-left-hero">
        <h1 class="panel-left-headline">Forgot your<br><em>password?</em></h1>
        <p class="panel-left-desc">We'll send a secure reset link to your inbox and phone in seconds.</p>
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
        <h2 class="form-title">Check your inbox!</h2>
        <p class="form-subtitle">We've sent a reset link to: <br><strong><?= htmlspecialchars($email_sent_to) ?></strong></p>
        <a href="login" class="btn-primary" style="text-decoration:none; margin-top:1.5rem;">Back to Sign In</a>
      </div>
      <?php else: ?>
      <div class="icon-circle"><svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg></div>
      <div class="form-header"><h2 class="form-title">Forgot password?</h2><p class="form-subtitle">Enter your email and we'll send you a reset link.</p></div>
      <form method="POST" id="forgotForm">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
        <div class="field">
          <label>Email Address</label>
          <div class="field-input-wrap">
            <svg class="field-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
            <input type="email" name="email" placeholder="john@example.com" required>
          </div>
        </div>
        <button type="submit" class="btn-primary" id="submitBtn">Send Reset Link</button>
      </form>
      <div class="back-link"><a href="login">Back to Sign In</a></div>
      <?php endif; ?>
    </div>
  </div>
</div>
<script>
  const Toast = Swal.mixin({ toast: true, position: 'top-end', showConfirmButton: false, timer: 3000, timerProgressBar: true });
  <?php if (!empty($errors)): foreach ($errors as $e): ?>
    Toast.fire({ icon: 'error', title: '<?= addslashes($e) ?>' });
  <?php endforeach; endif; ?>
  document.getElementById('forgotForm')?.addEventListener('submit', function () {
    const btn = document.getElementById('submitBtn');
    btn.disabled = true;
    btn.innerHTML = `<svg class="spinner" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"/></svg> Sending…`;
  });
</script>
</body>
</html>
