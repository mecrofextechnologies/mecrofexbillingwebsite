<?php
session_start();

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require 'vendor/autoload.php';
include 'msanii/includes/connect.php';

/* ══════════════════════════════════════════════════════════════
   SECURITY HEADERS
══════════════════════════════════════════════════════════════ */
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: geolocation=(), microphone=(), camera=()');

/* ══════════════════════════════════════════════════════════════
   CSRF TOKEN
══════════════════════════════════════════════════════════════ */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$errors          = [];
$success_message = "";

/* ══════════════════════════════════════════════════════════════
   REFERRAL DETECTION
   Link format: /register?refer=USERNAME_CREATORID
══════════════════════════════════════════════════════════════ */
$referrer_info = null;

if (isset($_GET['refer']) && !empty($_GET['refer'])) {
    $_SESSION['ref_code'] = trim($_GET['refer']);
}

if (!empty($_SESSION['ref_code'])) {
    $ref_code = $_SESSION['ref_code'];
    if (preg_match('/^([a-z0-9]+)_(\d+)$/', $ref_code, $m)) {
        $ref_username   = $m[1];
        $ref_creator_id = (int)$m[2];
        try {
            $refStmt = $pdo->prepare(
                "SELECT id, username, full_name FROM creators
                 WHERE id = :id AND username = :u LIMIT 1");
            $refStmt->execute([':id' => $ref_creator_id, ':u' => $ref_username]);
            $row = $refStmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $referrer_info = $row;
            } else {
                unset($_SESSION['ref_code']);
            }
        } catch (PDOException $e) {
            unset($_SESSION['ref_code']);
        }
    } else {
        unset($_SESSION['ref_code']);
    }
}

/* ══════════════════════════════════════════════════════════════
   HELPERS
══════════════════════════════════════════════════════════════ */
function normalizePhone(string $raw): ?string {
    $p = preg_replace('/[\s\-\(\)]+/', '', $raw);
    if (preg_match('/^07\d{8}$/', $p))   return '254' . substr($p, 1);
    if (preg_match('/^01\d{8}$/', $p))   return '254' . substr($p, 1);
    if (preg_match('/^2547\d{8}$/', $p)) return $p;
    if (preg_match('/^2541\d{8}$/', $p)) return $p;
    return null;
}

function maskPhone(string $normalized): string {
    return substr($normalized, 0, 6) . '****' . substr($normalized, -2);
}

function sendSmsTalkSasa(PDO $pdo, string $to, string $message): array {
    try {
        $stmt = $pdo->query("SELECT * FROM admin_sms_settings WHERE provider = 'talksasa' LIMIT 1");
        $cfg  = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (\PDOException $e) {
        return ['ok' => false, 'error' => "DB error fetching SMS config: " . $e->getMessage()];
    }
    if (!$cfg) return ['ok' => false, 'error' => "SMS config not found — no row with provider='talksasa'."];
    $token      = trim($cfg['api_token']   ?? '');
    $senderName = trim($cfg['sender_name'] ?? '');
    if (!$token)      return ['ok' => false, 'error' => "SMS config incomplete — api_token is empty."];
    if (!$senderName) return ['ok' => false, 'error' => "SMS config incomplete — sender_name is empty."];
    $endpoint = 'https://bulksms.talksasa.com/api/v3/sms/send';
    $payload  = json_encode(['recipient' => $to, 'sender_id' => $senderName, 'type' => 'plain', 'message' => $message]);
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $endpoint, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload,
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => false, CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Content-Type: application/json', 'Accept: application/json'],
    ]);
    $raw = curl_exec($ch); $cerr = curl_error($ch); $cerrno = curl_errno($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($cerr) return ['ok' => false, 'error' => "Network error (cURL #{$cerrno}): {$cerr}", 'debug' => ['curl_errno' => $cerrno, 'curl_error' => $cerr]];
    if ($raw === false || $raw === '') return ['ok' => false, 'error' => "Empty response (HTTP {$httpCode}).", 'debug' => ['http_code' => $httpCode]];
    $res = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE) return ['ok' => false, 'error' => "Non-JSON response (HTTP {$httpCode}): " . substr($raw, 0, 200), 'debug' => ['http_code' => $httpCode, 'raw' => substr($raw, 0, 500)]];
    if (isset($res['status']) && strtolower((string)$res['status']) === 'success') return ['ok' => true];
    $gatewayMsg = $res['message'] ?? $res['error'] ?? $res['detail'] ?? json_encode($res);
    return ['ok' => false, 'error' => "SMS failed (HTTP {$httpCode}): {$gatewayMsg}", 'debug' => ['http_code' => $httpCode, 'gateway_msg' => $gatewayMsg, 'full_response' => $res]];
}

function csrfValid(): bool {
    return isset($_POST['csrf_token']) && hash_equals($_SESSION['csrf_token'], $_POST['csrf_token']);
}

function jsonOut(array $data, int $status = 200): void {
    http_response_code($status); header('Content-Type: application/json'); echo json_encode($data); exit;
}

/* ══════════════════════════════════════════════════════════════
   AJAX DISPATCHER
══════════════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    $action = $_POST['ajax_action'];

    if ($action === 'check_username') {
        $val  = strtolower(trim($_POST['value'] ?? ''));
        $stmt = $pdo->prepare("SELECT id FROM creators WHERE username = :u LIMIT 1");
        $stmt->execute(['u' => $val]);
        jsonOut(['exists' => $stmt->rowCount() > 0]);
    }

    if ($action === 'check_email') {
        $val  = strtolower(trim($_POST['value'] ?? ''));
        $stmt = $pdo->prepare("SELECT id FROM creators WHERE email = :e LIMIT 1");
        $stmt->execute(['e' => $val]);
        jsonOut(['exists' => $stmt->rowCount() > 0]);
    }

    if ($action === 'send_otp') {
        if (!csrfValid()) jsonOut(['success' => false, 'message' => 'Invalid session. Refresh and try again.']);
        $raw = trim($_POST['phone'] ?? '');
        $normalized = normalizePhone($raw);
        if (!$normalized) jsonOut(['success' => false, 'message' => 'Invalid phone format. Use 07XXXXXXXX, 01XXXXXXXX, 2547XXXXXXXX or 2541XXXXXXXX.']);
        $existing = $_SESSION['otp_data'] ?? null;
        if ($existing && (time() - ($existing['sent_at'] ?? 0)) < 60) {
            $wait = 60 - (time() - $existing['sent_at']);
            jsonOut(['success' => false, 'message' => "Wait {$wait}s before requesting another OTP."]);
        }
        $otp = str_pad(random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
        $_SESSION['otp_data'] = ['hash' => password_hash($otp, PASSWORD_BCRYPT), 'phone' => $normalized, 'raw_phone' => $raw, 'sent_at' => time(), 'attempts' => 0, 'verified' => false];
        $msg    = "Your Keychele verification code is: {$otp}. Valid for 5 minutes. Do not share.";
        $result = sendSmsTalkSasa($pdo, $normalized, $msg);
        if ($result['ok']) {
            jsonOut(['success' => true, 'masked' => maskPhone($normalized), 'expires_in' => 300]);
        } else {
            unset($_SESSION['otp_data']);
            jsonOut(['success' => false, 'message' => $result['error'], 'debug' => $result['debug'] ?? $result]);
        }
    }

    if ($action === 'verify_otp') {
        if (!csrfValid()) jsonOut(['success' => false, 'message' => 'Invalid session.']);
        $otpData = $_SESSION['otp_data'] ?? null;
        if (!$otpData) jsonOut(['success' => false, 'message' => 'No OTP found. Please request a new one.']);
        if ($otpData['verified']) jsonOut(['success' => true, 'message' => 'Already verified.']);
        if ((time() - $otpData['sent_at']) > 300) { unset($_SESSION['otp_data']); jsonOut(['success' => false, 'message' => 'OTP expired. Please request a new one.', 'expired' => true]); }
        if ($otpData['attempts'] >= 4) { unset($_SESSION['otp_data']); jsonOut(['success' => false, 'message' => 'Too many failed attempts. Request a new OTP.', 'locked' => true]); }
        $_SESSION['otp_data']['attempts']++;
        $input = trim($_POST['otp'] ?? '');
        if (password_verify($input, $otpData['hash'])) {
            $_SESSION['otp_data']['verified'] = true;
            jsonOut(['success' => true, 'message' => 'Phone number verified!']);
        } else {
            $left = 4 - $_SESSION['otp_data']['attempts'];
            jsonOut(['success' => false, 'message' => "Incorrect code. {$left} attempt(s) left."]);
        }
    }

    if ($action === 'save_progress') {
        $step    = max(1, min(5, intval($_POST['step'] ?? 1)));
        $allowed = ['first_name', 'last_name', 'username', 'email', 'phone', 'county'];
        $data    = [];
        foreach ($allowed as $f) {
            if (isset($_POST[$f])) $data[$f] = htmlspecialchars(trim($_POST[$f]), ENT_QUOTES, 'UTF-8');
        }
        $_SESSION['reg_step'] = $step;
        $_SESSION['reg_data'] = array_merge($_SESSION['reg_data'] ?? [], $data);
        jsonOut(['success' => true]);
    }

    if ($action === 'clear_progress') {
        unset($_SESSION['reg_step'], $_SESSION['reg_data']);
        jsonOut(['success' => true]);
    }

    jsonOut(['error' => 'unknown action'], 400);
}

/* ══════════════════════════════════════════════════════════════
   FORM SUBMISSION
══════════════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['ajax_action'])) {

    if (!csrfValid()) {
        $errors[] = "Invalid session token. Please refresh the page.";
    } else {
        $otpData = $_SESSION['otp_data'] ?? null;
        if (!$otpData || empty($otpData['verified'])) {
            $errors[] = "Phone number not verified. Please complete OTP verification.";
        }

        $first_name = htmlspecialchars(trim($_POST['first_name'] ?? ''), ENT_QUOTES, 'UTF-8');
        $last_name  = htmlspecialchars(trim($_POST['last_name']  ?? ''), ENT_QUOTES, 'UTF-8');
        $username   = strtolower(trim($_POST['username'] ?? ''));
        $email      = strtolower(trim($_POST['email']    ?? ''));
        $phone      = $otpData['raw_phone'] ?? trim($_POST['phone'] ?? '');
        $county     = htmlspecialchars(trim($_POST['county'] ?? ''), ENT_QUOTES, 'UTF-8');
        $full_name  = $first_name . ' ' . $last_name;

        $referred_by_id = null;
        if (!empty($_SESSION['ref_code']) && $referrer_info) {
            $referred_by_id = (int)$referrer_info['id'];
        }

        if (!$first_name || !$last_name || !$username || !$email || !$phone || !$county)
            $errors[] = "All fields are required.";

        if (empty($errors) && !preg_match('/^[a-z0-9]{3,15}$/', $username))
            $errors[] = "Username must be 3–15 characters, lowercase letters and numbers only.";

        if (empty($errors) && !filter_var($email, FILTER_VALIDATE_EMAIL))
            $errors[] = "Please enter a valid email address.";

        if (empty($errors)) {
            $stmt = $pdo->prepare("SELECT id FROM creators WHERE username = :u LIMIT 1");
            $stmt->execute(['u' => $username]);
            if ($stmt->rowCount() > 0) $errors[] = "Username is already taken.";
        }

        if (empty($errors)) {
            $stmt = $pdo->prepare("SELECT id FROM creators WHERE email = :e LIMIT 1");
            $stmt->execute(['e' => $email]);
            if ($stmt->rowCount() > 0) $errors[] = "This email is already registered.";
        }

        if (empty($errors)) {
            $stmt = $pdo->prepare("SELECT 1 FROM blacklist WHERE email = :e LIMIT 1");
            $stmt->execute(['e' => $email]);
            if ($stmt->fetch()) {
                $errors[] = "Registration blocked — this account has been permanently banned.";
                $bm = new PHPMailer(true);
                try {
                    $bm->isSMTP(); $bm->Host = 'mail.keychele.co.ke';
                    $bm->SMTPAuth = true; $bm->Username = 'notifications@keychele.co.ke';
                    $bm->Password = 'King20241064.'; $bm->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS; $bm->Port = 587;
                    $bm->SMTPOptions = ['ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true]];
                    $bm->setFrom('notifications@keychele.co.ke', 'Keychele Platform');
                    $bm->addAddress($email); $bm->isHTML(true); $bm->CharSet = 'UTF-8';
                    $bm->Subject = 'Registration Terminated – Keychele Platform';
                    $bm->Body = "<div style='font-family:Arial,sans-serif;max-width:560px;margin:0 auto;padding:32px;background:#fff;border-radius:10px;border:1px solid #eee;'><h2 style='color:#C8102E;'>Registration Blocked</h2><p style='color:#374151;font-size:15px;line-height:1.6;'>Your email <strong>{$email}</strong> has been permanently banned from Keychele.</p><p style='color:#374151;font-size:15px;'>Contact <a href='mailto:support@keychele.co.ke'>support@keychele.co.ke</a> if this is a mistake.</p><hr style='border:none;border-top:1px solid #eee;margin:24px 0;'><p style='color:#9CA3AF;font-size:12px;text-align:center;'>© " . date('Y') . " Keychele.</p></div>";
                    $bm->send();
                } catch (Exception $ex) { error_log("Blacklist email: " . $bm->ErrorInfo); }
            }
        }

        if (empty($errors)) {
            $plain_password  = 'A' . str_pad(mt_rand(0, 9999), 4, '0', STR_PAD_LEFT);
            $hashed_password = password_hash($plain_password, PASSWORD_BCRYPT);

            $stmt = $pdo->prepare("
                INSERT INTO creators
                    (full_name, first_name, last_name, username, email, phone, county,
                     password, must_change_password, referred_by, date_created)
                VALUES
                    (:full_name, :fn, :ln, :u, :e, :ph, :co, :p, 1, :referred_by, NOW())
            ");
            $stmt->execute([
                'full_name'   => $full_name,  'fn' => $first_name, 'ln' => $last_name,
                'u'           => $username,   'e'  => $email,      'ph' => $phone,
                'co'          => $county,     'p'  => $hashed_password,
                'referred_by' => $referred_by_id,
            ]);
            $new_creator_id = (int)$pdo->lastInsertId();

            if ($referred_by_id) {
                try {
                    $pdo->prepare("
                        INSERT INTO referral_logs
                            (referrer_id, referred_creator_id, ref_code, joined_at)
                        VALUES
                            (:ref_id, :new_id, :code, NOW())
                    ")->execute([
                        ':ref_id' => $referred_by_id,
                        ':new_id' => $new_creator_id,
                        ':code'   => $_SESSION['ref_code'] ?? '',
                    ]);
                } catch (PDOException $e) {
                    error_log("Referral log insert failed: " . $e->getMessage());
                }
            }

            unset($_SESSION['reg_step'], $_SESSION['reg_data'], $_SESSION['otp_data'], $_SESSION['ref_code']);
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

            $login_link = "https://keychele.co.ke/login";
            $wm = new PHPMailer(true);
            try {
                $wm->isSMTP(); $wm->Host = 'mail.keychele.co.ke';
                $wm->SMTPAuth = true; $wm->Username = 'notifications@keychele.co.ke';
                $wm->Password = 'King20241064.'; $wm->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS; $wm->Port = 587;
                $wm->SMTPOptions = ['ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true]];
                $wm->setFrom('notifications@keychele.co.ke', 'Keychele');
                $wm->addAddress($email, $full_name);
                $wm->isHTML(true); $wm->CharSet = 'UTF-8';
                $wm->Subject = "Keychele Creator Account – Your Login Details";
                $referral_note = $referred_by_id
                    ? "<p style='color:#374151;font-size:14px;'>You joined via a referral from <strong>" . htmlspecialchars($referrer_info['full_name'] ?: $referrer_info['username']) . "</strong>. Welcome to the Keychele family!</p>"
                    : "";
                $wm->Body = "
<div style='font-family:Arial,sans-serif;max-width:560px;margin:0 auto;background:#ffffff;border-radius:12px;overflow:hidden;border:1px solid #E8E4DF;'>
  <div style='background:linear-gradient(135deg,#C8102E 0%,#FF3356 100%);padding:36px 32px;text-align:center;'>
    <h1 style='color:#ffffff;font-size:26px;margin:0;letter-spacing:-.5px;'>Welcome to Keychele!</h1>
    <p style='color:rgba(255,255,255,0.85);font-size:14px;margin:8px 0 0;'>Your creator account is ready.</p>
  </div>
  <div style='padding:32px;'>
    <p style='color:#374151;font-size:15px;line-height:1.7;margin:0 0 20px;'>Hi <strong>{$full_name}</strong>, welcome aboard! 🎉<br>Your Keychele creator account has been set up. Below are your login credentials:</p>
    {$referral_note}
    <div style='background:#F9FAFB;border:1px solid #E5E7EB;border-radius:10px;padding:24px 28px;margin-bottom:24px;'>
      <table style='width:100%;border-collapse:collapse;font-size:14px;color:#374151;'>
        <tr><td style='padding:8px 0;font-weight:600;width:120px;color:#6B7280;'>Full Name</td><td style='padding:8px 0;font-weight:500;'>{$full_name}</td></tr>
        <tr><td style='padding:8px 0;font-weight:600;color:#6B7280;border-top:1px solid #E5E7EB;'>Username</td><td style='padding:8px 0;font-weight:500;border-top:1px solid #E5E7EB;'>{$username}</td></tr>
        <tr><td style='padding:8px 0;font-weight:600;color:#6B7280;border-top:1px solid #E5E7EB;'>Email</td><td style='padding:8px 0;font-weight:500;border-top:1px solid #E5E7EB;'>{$email}</td></tr>
        <tr><td style='padding:8px 0;font-weight:600;color:#6B7280;border-top:1px solid #E5E7EB;'>Password</td><td style='padding:8px 0;font-weight:700;border-top:1px solid #E5E7EB;color:#C8102E;font-size:18px;letter-spacing:2px;'>{$plain_password}</td></tr>
      </table>
    </div>
    <div style='background:#FEF2F2;border:1px solid #FECACA;border-radius:8px;padding:14px 16px;margin-bottom:24px;'>
      <p style='color:#991B1B;font-size:13px;margin:0;line-height:1.6;'>⚠️ <strong>Important:</strong> You will be required to change your password after your first login. Do not share your credentials with anyone.</p>
    </div>
    <div style='text-align:center;margin-bottom:24px;'>
      <a href='{$login_link}' style='display:inline-block;background:linear-gradient(135deg,#C8102E 0%,#FF3356 100%);color:#ffffff;font-size:15px;font-weight:700;text-decoration:none;padding:14px 40px;border-radius:10px;letter-spacing:0.3px;'>Sign In to Your Account →</a>
    </div>
    <p style='color:#6B7280;font-size:13px;line-height:1.6;'>If you did not request this, contact <a href='mailto:support@keychele.co.ke' style='color:#C8102E;'>support@keychele.co.ke</a>.</p>
  </div>
  <div style='background:#F9FAFB;border-top:1px solid #E5E7EB;padding:20px 32px;text-align:center;'>
    <p style='color:#9CA3AF;font-size:12px;margin:0;'>© " . date('Y') . " Keychele. All rights reserved.</p>
  </div>
</div>";
                $wm->AltBody = "Hi {$full_name}, welcome to Keychele!\nUsername: {$username}\nEmail: {$email}\nPassword: {$plain_password}\nLogin: {$login_link}\nChange password after first login.";
                $wm->send();
                $success_message = "Account created! Login credentials sent to {$email}.";
            } catch (Exception $ex) {
                $errors[] = "Account created but welcome email failed. Contact support.";
                error_log("Welcome email: " . $wm->ErrorInfo);
            }
        }
    }
}

/* ══════════════════════════════════════════════════════════════
   RESTORE SESSION STATE
   Steps: 1=Identity, 2=Contact/Email, 3=Phone Entry, 4=OTP Verify, 5=Location
══════════════════════════════════════════════════════════════ */
$savedStep     = max(1, min(5, intval($_SESSION['reg_step'] ?? 1)));
$savedData     = $_SESSION['reg_data'] ?? [];
$phoneVerified = !empty($_SESSION['otp_data']['verified']);
$otpSent       = isset($_SESSION['otp_data']['sent_at']) && !empty($_SESSION['otp_data']);
$otpExpiresIn  = $otpSent ? max(0, 300 - (time() - ($_SESSION['otp_data']['sent_at'] ?? 0))) : 0;
$verifiedPhone  = $phoneVerified ? htmlspecialchars($_SESSION['otp_data']['raw_phone'] ?? '', ENT_QUOTES, 'UTF-8') : '';

/* If phone was verified in a prior session, jump to step 5 if we'd be on step 3 or 4 */
if ($phoneVerified && $savedStep < 5) {
    $savedStep = 5;
}

$sv = fn(string $k) => htmlspecialchars($savedData[$k] ?? '', ENT_QUOTES, 'UTF-8');

$kenya_counties = ["Baringo","Bomet","Bungoma","Busia","Elgeyo-Marakwet","Embu","Garissa","Homa Bay","Isiolo","Kajiado","Kakamega","Kericho","Kiambu","Kilifi","Kirinyaga","Kisii","Kisumu","Kitui","Kwale","Laikipia","Lamu","Machakos","Makueni","Mandera","Marsabit","Meru","Migori","Mombasa","Murang'a","Nairobi","Nakuru","Nandi","Narok","Nyamira","Nyandarua","Nyeri","Samburu","Siaya","Taita-Taveta","Tana River","Tharaka-Nithi","Trans Nzoia","Turkana","Uasin Gishu","Vihiga","Wajir","West Pokot"];
sort($kenya_counties);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>Keychele — Creator Registration</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;500;600;700;800&family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;1,9..40,300&display=swap" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.css" rel="stylesheet"/>
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

  :root {
    --crimson:      #C8102E;
    --crimson-deep: #9B0B22;
    --crimson-light:#FF3356;
    --ink:          #0D0D0D;
    --ink-soft:     #1A1A2E;
    --slate:        #64748B;
    --mist:         #F8F7F5;
    --white:        #FFFFFF;
    --border:       #E8E4DF;
    --success:      #059669;
    --success-bg:   #ECFDF5;
    --success-brd:  #A7F3D0;
    --purple:       #7c2fb0;
    --purple-l:     #a855f7;
    --font-display: 'Syne', sans-serif;
    --font-body:    'DM Sans', sans-serif;
    --shadow-card:  0 32px 80px rgba(13,13,13,.12), 0 8px 24px rgba(13,13,13,.06);
    --shadow-btn:   0 4px 20px rgba(200,16,46,.4);
    --radius:       20px;
    --radius-sm:    12px;
    --radius-input: 10px;
  }

  html, body { height:100%; font-family:var(--font-body); background:var(--mist); color:var(--ink); -webkit-font-smoothing:antialiased; }
  .page-wrapper { min-height:100vh; display:flex; align-items:stretch; position:relative; overflow:hidden; }

  /* ── LEFT PANEL ── */
  .panel-left { display:none; flex:0 0 42%; position:relative; overflow:hidden; background:var(--ink-soft); }
  @media (min-width:1024px) { .panel-left { display:flex; flex-direction:column; } }
  .panel-left-img { position:absolute; inset:0; width:100%; height:100%; object-fit:cover; opacity:.35; }
  .panel-left-gradient { position:absolute; inset:0; background:linear-gradient(135deg,rgba(200,16,46,.9) 0%,rgba(13,13,13,.95) 100%); mix-blend-mode:multiply; }
  .panel-left-content { position:relative; z-index:2; display:flex; flex-direction:column; justify-content:space-between; height:100%; padding:3rem; }
  .panel-left-logo { display:flex; align-items:center; gap:12px; }
  .panel-left-logo img { width:44px; height:44px; object-fit:contain; filter:brightness(0) invert(1); }
  .panel-left-logo span { font-family:var(--font-display); font-weight:800; font-size:1.25rem; color:white; letter-spacing:-.02em; }
  .panel-left-hero { flex:1; display:flex; flex-direction:column; justify-content:center; gap:1.5rem; }
  .panel-left-eyebrow { display:inline-flex; align-items:center; gap:8px; background:rgba(255,255,255,.1); border:1px solid rgba(255,255,255,.15); border-radius:100px; padding:6px 16px; font-size:.75rem; font-weight:500; color:rgba(255,255,255,.8); letter-spacing:.08em; text-transform:uppercase; width:fit-content; backdrop-filter:blur(10px); }
  .panel-left-eyebrow::before { content:''; width:6px; height:6px; border-radius:50%; background:#4ADE80; animation:pulse-dot 2s infinite; }
  @keyframes pulse-dot { 0%,100%{opacity:1;transform:scale(1)} 50%{opacity:.5;transform:scale(1.4)} }
  .panel-left-headline { font-family:var(--font-display); font-weight:800; font-size:clamp(2rem,3vw,3rem); line-height:1.1; color:white; letter-spacing:-.03em; }
  .panel-left-headline em { font-style:normal; color:var(--crimson-light); }
  .panel-left-desc { font-size:.95rem; line-height:1.7; color:rgba(255,255,255,.6); max-width:320px; }
  .panel-left-stats { display:grid; grid-template-columns:1fr 1fr; gap:1rem; }
  .stat-card { background:rgba(255,255,255,.06); border:1px solid rgba(255,255,255,.1); border-radius:var(--radius-sm); padding:1.25rem; backdrop-filter:blur(10px); }
  .stat-card-num { font-family:var(--font-display); font-weight:800; font-size:1.75rem; color:white; letter-spacing:-.03em; line-height:1; }
  .stat-card-label { font-size:.75rem; color:rgba(255,255,255,.5); margin-top:4px; }
  .panel-left-footer { font-size:.75rem; color:rgba(255,255,255,.3); display:flex; align-items:center; gap:8px; }
  .panel-left-footer::before { content:''; flex:1; height:1px; background:rgba(255,255,255,.1); }

  /* ── RIGHT PANEL ── */
  .panel-right { flex:1; display:flex; align-items:center; justify-content:center; padding:2rem 1.5rem; background:var(--mist); position:relative; }
  .panel-right::before { content:''; position:absolute; top:-200px; right:-200px; width:500px; height:500px; border-radius:50%; background:radial-gradient(circle,rgba(200,16,46,.06) 0%,transparent 70%); pointer-events:none; }

  /* ── FORM CARD ── */
  .form-card { width:100%; max-width:500px; background:var(--white); border-radius:var(--radius); box-shadow:var(--shadow-card); padding:2.5rem; position:relative; overflow:hidden; animation:card-enter .6s cubic-bezier(.16,1,.3,1) both; }
  @keyframes card-enter { from{opacity:0;transform:translateY(24px)} to{opacity:1;transform:translateY(0)} }
  .form-card::before { content:''; position:absolute; top:0; left:0; right:0; height:3px; background:linear-gradient(90deg,var(--crimson),var(--crimson-light),#FF8FA3); }

  .mobile-brand { display:flex; align-items:center; justify-content:center; gap:10px; margin-bottom:1.75rem; }
  @media(min-width:1024px){.mobile-brand{display:none}}
  .mobile-brand img { width:36px; height:36px; object-fit:contain; }
  .mobile-brand span { font-family:var(--font-display); font-weight:800; font-size:1.15rem; color:var(--ink); letter-spacing:-.02em; }

  /* ── REFERRAL BANNER ── */
  .ref-banner {
    display:flex; align-items:center; gap:11px;
    background:linear-gradient(135deg,rgba(124,47,176,0.07) 0%,rgba(124,47,176,0.03) 100%);
    border:1.5px solid rgba(124,47,176,0.25);
    border-radius:12px; padding:11px 14px;
    margin-bottom:1.4rem;
    animation:ref-drop .45s cubic-bezier(.16,1,.3,1) both;
    position:relative; overflow:hidden;
  }
  .ref-banner::before {
    content:''; position:absolute; top:0; left:0; bottom:0; width:3px;
    background:linear-gradient(180deg,var(--purple),var(--purple-l));
    border-radius:3px 0 0 3px;
  }
  @keyframes ref-drop { from{opacity:0;transform:translateY(-10px)} to{opacity:1;transform:translateY(0)} }
  .ref-banner-icon {
    width:36px; height:36px; border-radius:9px; flex-shrink:0;
    background:linear-gradient(135deg,var(--purple),var(--purple-l));
    display:flex; align-items:center; justify-content:center;
    font-size:.9rem; color:white;
    box-shadow:0 4px 12px rgba(124,47,176,0.35);
  }
  .ref-banner-text { flex:1; }
  .ref-banner-title { font-family:var(--font-display); font-weight:700; font-size:.78rem; color:var(--purple); letter-spacing:0.01em; margin-bottom:2px; }
  .ref-banner-sub { font-size:.73rem; color:#6b7a99; line-height:1.45; }
  .ref-banner-sub strong { font-weight:700; color:#5c1a8a; }
  .ref-badge {
    flex-shrink:0; display:inline-flex; align-items:center; gap:5px;
    background:rgba(124,47,176,0.1); border:1px solid rgba(124,47,176,0.22);
    border-radius:100px; padding:4px 10px;
    font-family:var(--font-display); font-size:.62rem; font-weight:700;
    color:var(--purple); white-space:nowrap;
  }
  .ref-badge::before { content:''; width:5px; height:5px; border-radius:50%; background:var(--purple-l); flex-shrink:0; animation:pulse-dot 2s infinite; }

  /* ── FORM HEADER ── */
  .form-header { margin-bottom:1.5rem; }
  .form-title { font-family:var(--font-display); font-weight:800; font-size:1.55rem; color:var(--ink); letter-spacing:-.03em; line-height:1.2; margin-bottom:.35rem; }
  .form-subtitle { font-size:.875rem; color:var(--slate); line-height:1.5; }

  /* ── STEP INDICATOR (5 steps) ── */
  .step-indicator { display:flex; align-items:center; margin-bottom:0; }
  .step-dot {
    display:flex; align-items:center; justify-content:center;
    width:28px; height:28px; border-radius:50%;
    font-family:var(--font-display); font-weight:700; font-size:.7rem;
    transition:all .35s cubic-bezier(.16,1,.3,1);
    position:relative; z-index:1;
    background:var(--border); color:var(--slate);
    border:2px solid transparent; flex-shrink:0;
  }
  .step-dot.active { background:var(--crimson); color:white; border-color:var(--crimson); box-shadow:0 0 0 4px rgba(200,16,46,.12); }
  .step-dot.completed { background:var(--crimson); color:white; border-color:var(--crimson); }
  .step-dot.completed::after { content:''; position:absolute; width:8px; height:5px; border-left:2px solid white; border-bottom:2px solid white; transform:rotate(-45deg) translate(1px,-1px); }
  .step-dot.completed span { display:none; }
  .step-connector { flex:1; height:2px; background:var(--border); transition:background .35s; margin:0 3px; }
  .step-connector.filled { background:var(--crimson); }
  .step-label-row { display:flex; justify-content:space-between; margin-top:7px; margin-bottom:1.4rem; }
  .step-label { font-size:.62rem; color:var(--slate); font-weight:500; text-align:center; flex:1; letter-spacing:.01em; opacity:.6; transition:all .3s; min-width:0; }
  .step-label.active { color:var(--crimson); opacity:1; font-weight:700; }
  .step-label.completed-lbl { color:var(--success); opacity:.8; }

  /* ── FIELDS ── */
  .field { margin-bottom:1rem; }
  .field label { display:block; font-size:.8rem; font-weight:600; color:#374151; margin-bottom:.4rem; letter-spacing:.01em; }
  .field-hint { font-size:.72rem; color:#9CA3AF; margin-top:4px; line-height:1.4; }
  .field-input-wrap { position:relative; }
  .field-icon { position:absolute; left:13px; top:50%; transform:translateY(-50%); width:15px; height:15px; color:#9CA3AF; pointer-events:none; transition:color .2s; }
  .field input, .field select {
    width:100%; height:48px; padding:0 13px 0 38px;
    border:1.5px solid var(--border); border-radius:var(--radius-input);
    font-family:var(--font-body); font-size:.9rem; color:var(--ink);
    background:#FAFAFA; outline:none; transition:all .2s; appearance:none; -webkit-appearance:none;
  }
  .field input::placeholder { color:#C4C4C4; }
  .field input:focus, .field select:focus { border-color:var(--crimson); background:var(--white); box-shadow:0 0 0 3px rgba(200,16,46,.08); }
  .field input.verified-field { border-color:var(--success); background:var(--success-bg); padding-right:38px; }
  .field input.verified-field:focus { border-color:var(--success); box-shadow:0 0 0 3px rgba(5,150,105,.1); }
  .field-verified-icon { position:absolute; right:12px; top:50%; transform:translateY(-50%); color:var(--success); pointer-events:none; display:none; }
  .field-verified-icon.show { display:block; }
  .field-grid { display:grid; grid-template-columns:1fr 1fr; gap:.8rem; }

  .ref-field-input {
    width:100%; height:48px; padding:0 13px 0 38px;
    border:1.5px solid rgba(124,47,176,0.3); border-radius:var(--radius-input);
    font-family:var(--font-body); font-size:.9rem; color:var(--purple);
    background:rgba(124,47,176,0.04); outline:none; cursor:default; font-weight:600;
  }

  .username-meta { display:flex; justify-content:space-between; align-items:center; margin-top:4px; }
  .char-count { font-size:.72rem; color:#9CA3AF; font-weight:500; transition:color .2s; }
  .char-count.valid   { color:var(--success); }
  .char-count.invalid { color:var(--crimson); }

  .feedback { font-size:.74rem; margin-top:5px; min-height:1rem; display:flex; align-items:center; gap:5px; font-weight:500; }
  .feedback::before { content:''; width:5px; height:5px; border-radius:50%; flex-shrink:0; }
  .feedback.checking  { color:#9CA3AF; } .feedback.checking::before  { background:#9CA3AF; }
  .feedback.available { color:var(--success); } .feedback.available::before { background:var(--success); }
  .feedback.taken     { color:var(--crimson); } .feedback.taken::before     { background:var(--crimson); }
  .feedback.invalid   { color:var(--crimson); } .feedback.invalid::before   { background:var(--crimson); }
  .feedback.success   { color:var(--success); } .feedback.success::before   { background:var(--success); }
  .feedback.error     { color:var(--crimson); } .feedback.error::before     { background:var(--crimson); }

  .select-wrap { position:relative; }
  .select-wrap::after { content:''; position:absolute; right:13px; top:50%; transform:translateY(-50%); width:0; height:0; border-left:5px solid transparent; border-right:5px solid transparent; border-top:6px solid #9CA3AF; pointer-events:none; }

  /* ══ STEP 3 — Phone Entry ══ */
  .phone-step-illustration {
    text-align:center; padding:1rem 0 .5rem;
  }
  .phone-step-icon {
    width:72px; height:72px; border-radius:20px;
    background:linear-gradient(135deg,#FEF2F2,#FFE4E6);
    border:2px solid #FECACA;
    display:inline-flex; align-items:center; justify-content:center;
    margin-bottom:.85rem;
    box-shadow:0 8px 24px rgba(200,16,46,.12);
  }

  /* ══ STEP 4 — OTP Verify ══ */
  .otp-step-header {
    text-align:center; padding:.5rem 0 1.25rem;
  }
  .otp-step-icon {
    width:72px; height:72px; border-radius:20px;
    background:linear-gradient(135deg,#EEF2FF,#E0E7FF);
    border:2px solid #C7D2FE;
    display:inline-flex; align-items:center; justify-content:center;
    margin:0 auto .85rem;
    box-shadow:0 8px 24px rgba(99,102,241,.15);
  }
  .otp-step-title { font-family:var(--font-display); font-weight:800; font-size:1.3rem; color:var(--ink); letter-spacing:-.02em; margin-bottom:.3rem; }
  .otp-step-sub { font-size:.85rem; color:var(--slate); line-height:1.5; }
  .otp-step-phone { display:inline-block; font-weight:700; color:var(--ink); background:#F1F5F9; border-radius:6px; padding:2px 10px; font-family:var(--font-display); font-size:.9rem; margin-top:.3rem; }

  /* OTP Digits — large, clear, responsive */
  .otp-digits-wrap {
    display:flex; gap:.55rem; justify-content:center;
    margin:1.5rem 0 1rem;
  }
  .otp-digit {
    width:100%; max-width:56px; aspect-ratio:1/1.1;
    text-align:center;
    font-size:1.5rem; font-weight:800;
    border:2.5px solid var(--border);
    border-radius:12px;
    font-family:var(--font-display);
    background:var(--white); color:var(--ink);
    outline:none;
    transition:all .2s cubic-bezier(.16,1,.3,1);
    caret-color:var(--crimson);
    -moz-appearance:textfield;
  }
  .otp-digit::-webkit-outer-spin-button,
  .otp-digit::-webkit-inner-spin-button { -webkit-appearance:none; margin:0; }
  .otp-digit:focus {
    border-color:var(--crimson);
    background:#FFFBFB;
    box-shadow:0 0 0 4px rgba(200,16,46,.1);
    transform:translateY(-2px) scale(1.04);
  }
  .otp-digit.has-value {
    border-color:#6366F1;
    background:#EEF2FF;
    color:#4338CA;
  }
  .otp-digit.verified-digit {
    border-color:var(--success);
    background:var(--success-bg);
    color:var(--success);
  }
  .otp-digit.error-digit {
    border-color:var(--crimson);
    background:#FEF2F2;
    color:var(--crimson);
    animation:shake .4s ease;
  }
  @keyframes shake { 0%,100%{transform:translateX(0)} 20%,60%{transform:translateX(-4px)} 40%,80%{transform:translateX(4px)} }

  .otp-timer-row { display:flex; justify-content:center; align-items:center; gap:.5rem; margin-bottom:1rem; }
  .otp-timer-badge {
    display:inline-flex; align-items:center; gap:6px;
    background:#F1F5F9; border:1px solid #E2E8F0;
    border-radius:100px; padding:5px 14px;
    font-family:var(--font-display); font-size:.78rem; font-weight:700; color:#475569;
  }
  .otp-timer-badge.urgent { background:#FEF2F2; border-color:#FECACA; color:var(--crimson); }
  .otp-timer-dot { width:6px; height:6px; border-radius:50%; background:var(--success); animation:pulse-dot 1.5s infinite; }
  .otp-timer-dot.urgent { background:var(--crimson); }

  .otp-verify-btn {
    width:100%; height:50px;
    border:none; border-radius:var(--radius-input);
    font-family:var(--font-body); font-size:.92rem; font-weight:700;
    cursor:pointer; transition:all .2s;
    background:linear-gradient(135deg,var(--crimson),var(--crimson-light));
    color:white; box-shadow:var(--shadow-btn);
    display:flex; align-items:center; justify-content:center; gap:8px;
    letter-spacing:.01em;
  }
  .otp-verify-btn:hover:not(:disabled) { transform:translateY(-1px); box-shadow:0 8px 28px rgba(200,16,46,.5); }
  .otp-verify-btn:disabled { opacity:.45; cursor:not-allowed; transform:none; box-shadow:none; }

  .otp-status-bar { min-height:24px; text-align:center; margin-bottom:.5rem; }
  .otp-status-msg { font-size:.78rem; font-weight:600; display:inline-flex; align-items:center; gap:5px; }
  .otp-status-msg.error  { color:var(--crimson); }
  .otp-status-msg.success{ color:var(--success); }

  .otp-resend-row { text-align:center; margin-top:.85rem; }
  .otp-resend-btn {
    font-size:.78rem; font-weight:600; color:var(--crimson);
    background:none; border:none; cursor:pointer;
    text-decoration:underline; text-underline-offset:2px;
    transition:color .2s;
  }
  .otp-resend-btn:disabled { color:#9CA3AF; text-decoration:none; cursor:not-allowed; }
  .otp-resend-btn:hover:not(:disabled) { color:var(--crimson-deep); }

  .otp-change-phone {
    display:inline-flex; align-items:center; gap:5px;
    font-size:.75rem; color:#64748B; cursor:pointer;
    background:none; border:none; text-decoration:underline;
    text-underline-offset:2px; margin-top:.4rem;
    transition:color .2s;
  }
  .otp-change-phone:hover { color:var(--ink); }

  /* ══ Phone Verified Badge ══ */
  .phone-verified-banner {
    display:flex; align-items:center; gap:10px;
    background:var(--success-bg); border:1.5px solid var(--success-brd);
    border-radius:var(--radius-input); padding:12px 16px;
    font-size:.82rem; color:#065F46; font-weight:600;
  }
  .phone-verified-banner svg { flex-shrink:0; }

  /* ── BUTTONS ── */
  .btn-row { display:flex; align-items:center; justify-content:space-between; gap:.85rem; margin-top:1.5rem; }
  .btn { height:50px; padding:0 2rem; border-radius:var(--radius-input); font-family:var(--font-body); font-size:.9rem; font-weight:600; cursor:pointer; border:none; transition:all .2s; display:inline-flex; align-items:center; justify-content:center; gap:8px; letter-spacing:.01em; white-space:nowrap; }
  .btn-primary { background:linear-gradient(135deg,var(--crimson) 0%,var(--crimson-light) 100%); color:white; box-shadow:var(--shadow-btn); flex:1; }
  .btn-primary:hover:not(:disabled) { transform:translateY(-1px); box-shadow:0 8px 28px rgba(200,16,46,.5); }
  .btn-primary:active { transform:translateY(0); }
  .btn-primary:disabled { opacity:.55; cursor:not-allowed; transform:none; box-shadow:none; }
  .btn-ghost { background:transparent; color:var(--slate); border:1.5px solid var(--border); padding:0 1.4rem; flex-shrink:0; }
  .btn-ghost:hover { border-color:#CBD5E1; color:var(--ink); background:#F8F8F8; }

  .signin-link { text-align:center; margin-top:1.35rem; font-size:.82rem; color:var(--slate); }
  .signin-link a { color:var(--crimson); font-weight:600; text-decoration:none; transition:color .2s; }
  .signin-link a:hover { color:var(--crimson-deep); text-decoration:underline; }

  .step { animation:step-fade .3s ease both; }
  @keyframes step-fade { from{opacity:0;transform:translateX(12px)} to{opacity:1;transform:translateX(0)} }
  @keyframes spin { to{transform:rotate(360deg)} }
  .spinner { animation:spin .8s linear infinite; }

  #toast-container > .toast { border-radius:10px!important; box-shadow:0 8px 32px rgba(0,0,0,.12)!important; font-family:var(--font-body)!important; font-size:.875rem!important; padding:14px 18px 14px 52px!important; }
  #toast-container > .toast-success { background-color:#064E3B!important; border-left:4px solid #10B981!important; }
  #toast-container > .toast-error   { background-color:#7F1D1D!important; border-left:4px solid #EF4444!important; }
  #toast-container > .toast-info    { background-color:#1E3A5F!important; border-left:4px solid #60A5FA!important; }

  @media(max-width:520px) {
    .form-card { padding:1.75rem 1.1rem; border-radius:16px; }
    .field-grid { grid-template-columns:1fr; }
    .form-title { font-size:1.35rem; }
    .otp-digits-wrap { gap:.4rem; }
    .otp-digit { font-size:1.25rem; border-radius:10px; }
    .ref-badge { display:none; }
    .step-label { font-size:.57rem; }
  }
  @media(max-width:360px) {
    .otp-digit { font-size:1.1rem; }
    .otp-digits-wrap { gap:.3rem; }
  }

  .hp-field { position:absolute; left:-9999px; top:-9999px; width:1px; height:1px; overflow:hidden; }

  /* Summary pill shown in step 5 */
  .summary-pill {
    display:flex; align-items:center; gap:10px;
    background:#F8FAFC; border:1.5px solid #E2E8F0;
    border-radius:var(--radius-input); padding:10px 14px;
    margin-bottom:.85rem;
  }
  .summary-pill-icon { color:#64748B; flex-shrink:0; }
  .summary-pill-text { font-size:.8rem; color:#374151; }
  .summary-pill-text strong { font-weight:700; color:var(--ink); }
</style>
</head>
<body>

<div class="page-wrapper">

  <!-- ── LEFT PANEL ── -->
  <div class="panel-left">
    <img src="https://images.unsplash.com/photo-1556740749-887f6717d7e4?w=900" class="panel-left-img" alt="">
    <div class="panel-left-gradient"></div>
    <div class="panel-left-content">
      <div class="panel-left-logo">
        <img src="images/logo/KECHELE LOGO.png" alt="Keychele">
        <span>Keychele</span>
      </div>
      <div class="panel-left-hero">
        <div class="panel-left-eyebrow">Now accepting creators</div>
        <h1 class="panel-left-headline">Build your<br><em>creative empire</em><br>with us.</h1>
        <p class="panel-left-desc">Join thousands of Kenyan creators earning and growing their audience on the Keychele platform.</p>
        <div class="panel-left-stats">
          <div class="stat-card"><div class="stat-card-num">10K+</div><div class="stat-card-label">Active Creators</div></div>
          <div class="stat-card"><div class="stat-card-num">47</div><div class="stat-card-label">Counties Covered</div></div>
        </div>
      </div>
      <div class="panel-left-footer">© <?= date('Y') ?> Keychele</div>
    </div>
  </div>

  <!-- ── RIGHT PANEL ── -->
  <div class="panel-right">
    <div class="form-card">

      <div class="mobile-brand">
        <img src="images/logo/KECHELE LOGO.png" alt="Keychele">
        <span>Keychele</span>
      </div>

      <?php if ($referrer_info): ?>
      <div class="ref-banner">
        <div class="ref-banner-icon">
          <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
        </div>
        <div class="ref-banner-text">
          <div class="ref-banner-title">You were referred by a creator</div>
          <div class="ref-banner-sub">
            <strong><?= htmlspecialchars($referrer_info['full_name'] ?: $referrer_info['username']) ?></strong>
            (@<?= htmlspecialchars($referrer_info['username']) ?>) invited you to join Keychele.
          </div>
        </div>
        <div class="ref-badge">
          <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
          Referred
        </div>
      </div>
      <?php endif; ?>

      <div class="form-header" id="formHeader">
        <h2 class="form-title" id="formTitle"><?= $referrer_info ? 'Join Keychele 🎉' : 'Create your account' ?></h2>
        <p class="form-subtitle" id="formSubtitle"><?= $referrer_info ? 'Fill in a few details to complete your sign-up.' : 'Fill in a few details to get started as a creator.' ?></p>
      </div>

      <!-- Step Indicator (5 steps) -->
      <div class="step-indicator">
        <div class="step-dot" id="dot1"><span>1</span></div>
        <div class="step-connector" id="con1"></div>
        <div class="step-dot" id="dot2"><span>2</span></div>
        <div class="step-connector" id="con2"></div>
        <div class="step-dot" id="dot3"><span>3</span></div>
        <div class="step-connector" id="con3"></div>
        <div class="step-dot" id="dot4"><span>4</span></div>
        <div class="step-connector" id="con4"></div>
        <div class="step-dot" id="dot5"><span>5</span></div>
      </div>
      <div class="step-label-row">
        <div class="step-label" id="lbl1">Identity</div>
        <div class="step-label" id="lbl2">Email</div>
        <div class="step-label" id="lbl3">Phone</div>
        <div class="step-label" id="lbl4">Verify</div>
        <div class="step-label" id="lbl5">Location</div>
      </div>

      <form method="POST" id="registerForm" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
        <div class="hp-field" aria-hidden="true">
          <input type="text" name="website" tabindex="-1" autocomplete="off">
        </div>

        <!-- ══ STEP 1 — Identity ══ -->
        <div id="step1" class="step">
          <div class="field-grid">
            <div class="field">
              <label>First Name</label>
              <div class="field-input-wrap">
                <svg class="field-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                <input type="text" name="first_name" id="first_name" placeholder="John" required value="<?= $sv('first_name') ?>">
              </div>
            </div>
            <div class="field">
              <label>Last Name</label>
              <div class="field-input-wrap">
                <svg class="field-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                <input type="text" name="last_name" id="last_name" placeholder="Doe" required value="<?= $sv('last_name') ?>">
              </div>
            </div>
          </div>

          <div class="field">
            <label>Username</label>
            <div class="field-input-wrap">
              <svg class="field-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="4"/><path d="M16 8v5a3 3 0 0 0 6 0v-1a10 10 0 1 0-3.92 7.94"/></svg>
              <input type="text" name="username" id="username" placeholder="johndoe123" required
                     maxlength="15" autocomplete="off" autocapitalize="none" spellcheck="false"
                     value="<?= $sv('username') ?>">
            </div>
            <div class="username-meta">
              <div id="usernameFeedback" class="feedback"></div>
              <span class="char-count" id="charCount">0 / 3–15</span>
            </div>
            <div class="field-hint">Lowercase letters and numbers only, 3–15 characters.</div>
          </div>

          <?php if ($referrer_info): ?>
          <div class="field">
            <label style="color:#7c2fb0;">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="vertical-align:middle;margin-right:4px"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
              Referred By
            </label>
            <div class="field-input-wrap">
              <svg class="field-icon" style="color:#7c2fb0;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>
              <input type="text" class="ref-field-input"
                     value="<?= htmlspecialchars(($referrer_info['full_name'] ?: $referrer_info['username']) . '  (@' . $referrer_info['username'] . ')') ?>"
                     readonly tabindex="-1" style="padding-left:38px">
            </div>
            <div class="field-hint" style="color:#9d6fc7;">Referral will be recorded automatically.</div>
          </div>
          <?php endif; ?>
        </div>

        <!-- ══ STEP 2 — Email ══ -->
        <div id="step2" class="step" style="display:none">
          <div class="field">
            <label>Email Address</label>
            <div class="field-input-wrap">
              <svg class="field-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
              <input type="email" name="email" id="email" placeholder="john@example.com" required
                     autocomplete="email" value="<?= $sv('email') ?>">
            </div>
            <div id="emailFeedback" class="feedback"></div>
            <div class="field-hint">Must include @ and a valid domain (e.g. gmail.com). Your login credentials will be sent here.</div>
          </div>
        </div>

        <!-- ══ STEP 3 — Phone Entry ══ -->
        <div id="step3" class="step" style="display:none">
          <div class="phone-step-illustration">
            <div class="phone-step-icon">
              <svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="#C8102E" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 13.5 19.79 19.79 0 0 1 1.61 4.9 2 2 0 0 1 3.58 2.72h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L7.91 9.4a16 16 0 0 0 6.29 6.29l1.76-1.76a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
            </div>
          </div>
          <div class="field">
            <label>Phone Number</label>
            <div class="field-input-wrap">
              <svg class="field-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 13.5 19.79 19.79 0 0 1 1.61 4.9 2 2 0 0 1 3.58 2.72h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L7.91 9.4a16 16 0 0 0 6.29 6.29l1.76-1.76a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
              <input type="tel" name="phone" id="phone" placeholder="0712 345 678" required
                     value="<?= $phoneVerified ? $verifiedPhone : $sv('phone') ?>"
                     <?= $phoneVerified ? 'class="verified-field" readonly' : '' ?>>
              <svg class="field-verified-icon <?= $phoneVerified ? 'show' : '' ?>" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
            </div>
            <div class="field-hint">Format: 07XXXXXXXX · 01XXXXXXXX · 2547XXXXXXXX · 2541XXXXXXXX</div>
            <div id="phoneFeedback" class="feedback"></div>
          </div>
          <p style="font-size:.8rem;color:#64748B;line-height:1.55;margin-top:.25rem;">
            Click <strong>Continue</strong> and we'll send a 6-digit verification code via SMS to this number.
          </p>
        </div>

        <!-- ══ STEP 4 — OTP Verification ══ -->
        <div id="step4" class="step" style="display:none">
          <div class="otp-step-header">
            <div class="otp-step-icon">
              <svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="#6366F1" stroke-width="2"><rect x="5" y="2" width="14" height="20" rx="2"/><line x1="12" y1="18" x2="12.01" y2="18"/></svg>
            </div>
            <div class="otp-step-title">Enter Verification Code</div>
            <div class="otp-step-sub">
              We sent a 6-digit code to<br>
              <span class="otp-step-phone" id="otpDisplayPhone"><?= $phoneVerified ? $verifiedPhone : '' ?></span>
            </div>
          </div>

          <!-- 6 large digit boxes -->
          <div class="otp-digits-wrap" id="otpDigitsWrap">
            <input class="otp-digit" type="text" inputmode="numeric" pattern="[0-9]" maxlength="1" id="d0" autocomplete="one-time-code" aria-label="Digit 1">
            <input class="otp-digit" type="text" inputmode="numeric" pattern="[0-9]" maxlength="1" id="d1" autocomplete="off" aria-label="Digit 2">
            <input class="otp-digit" type="text" inputmode="numeric" pattern="[0-9]" maxlength="1" id="d2" autocomplete="off" aria-label="Digit 3">
            <input class="otp-digit" type="text" inputmode="numeric" pattern="[0-9]" maxlength="1" id="d3" autocomplete="off" aria-label="Digit 4">
            <input class="otp-digit" type="text" inputmode="numeric" pattern="[0-9]" maxlength="1" id="d4" autocomplete="off" aria-label="Digit 5">
            <input class="otp-digit" type="text" inputmode="numeric" pattern="[0-9]" maxlength="1" id="d5" autocomplete="off" aria-label="Digit 6">
          </div>

          <!-- Timer -->
          <div class="otp-timer-row">
            <div class="otp-timer-badge" id="otpTimerBadge">
              <span class="otp-timer-dot" id="otpTimerDot"></span>
              <span id="otpTimerText">5:00</span>
            </div>
          </div>

          <!-- Status -->
          <div class="otp-status-bar">
            <span class="otp-status-msg" id="otpStatusMsg"></span>
          </div>

          <!-- Verify button (full width) -->
          <button type="button" class="otp-verify-btn" id="btnVerifyOtp" disabled>
            <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
            Verify Code
          </button>

          <div class="otp-resend-row">
            <button type="button" class="otp-resend-btn" id="btnResendOtp" disabled>Resend code</button>
            <br>
            <button type="button" class="otp-change-phone" id="btnChangePhone" onclick="goBackToPhone()">
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="15 18 9 12 15 6"/></svg>
              Use a different number
            </button>
          </div>
        </div>

        <!-- ══ STEP 5 — Location ══ -->
        <div id="step5" class="step" style="display:none">

          <!-- Summary pills -->
          <div class="phone-verified-banner" style="margin-bottom:1.1rem;">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
            Phone&nbsp;<strong id="summaryPhone"><?= $verifiedPhone ?></strong>&nbsp;verified ✓
          </div>

          <div class="field">
            <label>County</label>
            <div class="field-input-wrap select-wrap">
              <svg class="field-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
              <select name="county" required>
                <option value="">Select your county</option>
                <?php foreach ($kenya_counties as $c): ?>
                <option value="<?= htmlspecialchars($c) ?>" <?= $sv('county') === htmlspecialchars($c) ? 'selected' : '' ?>><?= htmlspecialchars($c) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <div style="background:#FEF2F2;border:1px solid #FECACA;border-radius:10px;padding:13px 15px;margin-top:.75rem;">
            <p style="font-size:.8rem;color:#991B1B;line-height:1.6;">
              <strong>By creating an account</strong>, you agree to Keychele's Terms of Service and Privacy Policy. Your login credentials will be sent to the email you provided.
            </p>
          </div>

          <?php if ($referrer_info): ?>
          <div style="display:flex;align-items:center;gap:8px;margin-top:.85rem;background:rgba(124,47,176,0.05);border:1px solid rgba(124,47,176,0.18);border-radius:10px;padding:10px 13px;">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#7c2fb0" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
            <span style="font-size:.77rem;color:#7c2fb0;font-weight:600;">Referral by <strong><?= htmlspecialchars($referrer_info['full_name'] ?: $referrer_info['username']) ?></strong> will be recorded.</span>
          </div>
          <?php endif; ?>
        </div>

        <!-- BUTTONS -->
        <div class="btn-row" id="btnRow">
          <button type="button" id="prevBtn" onclick="prevStep()" class="btn btn-ghost" style="display:none">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="15 18 9 12 15 6"/></svg>
            Back
          </button>
          <button type="button" id="nextBtn" onclick="nextStep()" class="btn btn-primary">
            Continue
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg>
          </button>
          <button type="submit" id="registerBtn" class="btn btn-primary" style="display:none">
            Create Account
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
          </button>
        </div>

        <div class="signin-link">Already have an account? <a href="login">Sign in</a></div>
      </form>
    </div>
  </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.js"></script>
<script>
/* ── CONFIG ─────────────────────────────────────────── */
const SAVED_STEP     = <?= $savedStep ?>;
const PHONE_VERIFIED = <?= $phoneVerified ? 'true' : 'false' ?>;
const OTP_EXPIRES_IN = <?= $otpExpiresIn ?>;
const CSRF = document.querySelector('input[name="csrf_token"]').value;

toastr.options = {
  closeButton: true, progressBar: true,
  positionClass: "toast-top-right",
  timeOut: 6000, extendedTimeOut: 1500
};

/* PHP-side messages */
<?php if (!empty($errors)): foreach ($errors as $e): ?>
toastr.error('<?= addslashes($e) ?>');
<?php endforeach; endif; ?>
<?php if (!empty($success_message)): ?>
toastr.success('<?= addslashes($success_message) ?>');
<?php endif; ?>
<?php if ($referrer_info): ?>
toastr.info('You were referred by <strong><?= addslashes(htmlspecialchars($referrer_info["full_name"] ?: $referrer_info["username"])) ?></strong> — referral will be recorded automatically.', '', { timeOut: 5000 });
<?php endif; ?>

/* ── AJAX ─────────────────────────────────────────── */
async function postAjax(data) {
  data.csrf_token = CSRF;
  const res = await fetch(location.href, {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams(data)
  });
  return res.json();
}

/* ── STEP STATE ──────────────────────────────────────── */
const TOTAL_STEPS   = 5;
let   currentStep   = 1;
let   phoneVerifiedState = PHONE_VERIFIED;

const stepTitles = [
  ['Create your account', 'Fill in your name and a unique username.'],
  ['Your Email', 'Where should we send your login credentials?'],
  ['Phone Number', 'We\'ll verify your number with a one-time code.'],
  ['Verify Phone', 'Enter the 6-digit code we just sent to your phone.'],
  ['Almost done!', 'Select your county to complete registration.'],
];

function updateDots() {
  for (let i = 1; i <= TOTAL_STEPS; i++) {
    const dot = document.getElementById('dot' + i);
    const lbl = document.getElementById('lbl' + i);
    dot.classList.remove('active','completed');
    lbl.classList.remove('active','completed-lbl');
    if (i < currentStep)        { dot.classList.add('completed'); lbl.classList.add('completed-lbl'); }
    else if (i === currentStep) { dot.classList.add('active');    lbl.classList.add('active'); }
  }
  for (let i = 1; i < TOTAL_STEPS; i++) {
    document.getElementById('con' + i).classList.toggle('filled', i < currentStep);
  }
  /* Header */
  const [title, sub] = stepTitles[currentStep - 1];
  document.getElementById('formTitle').textContent    = title;
  document.getElementById('formSubtitle').textContent = sub;
}

function showStep(step) {
  for (let i = 1; i <= TOTAL_STEPS; i++) {
    const el = document.getElementById('step' + i);
    if (el) el.style.display = (i === step) ? 'block' : 'none';
  }
  currentStep = step;
  updateDots();

  const isLast = step === TOTAL_STEPS;
  const isOtp  = step === 4; /* OTP step has its own Verify button */

  document.getElementById('prevBtn').style.display     = step === 1 ? 'none' : 'flex';
  document.getElementById('nextBtn').style.display     = (isLast || isOtp) ? 'none' : 'flex';
  document.getElementById('registerBtn').style.display = isLast ? 'flex' : 'none';
  document.getElementById('btnRow').style.marginTop    = isOtp ? '0' : '';
}

/* ── VALIDATORS ──────────────────────────────────────── */
function isValidEmail(v) { return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v); }
function isValidPhone(v) {
  const p = v.replace(/[\s\-\(\)]+/g,'');
  return /^(07\d{8}|01\d{8}|2547\d{8}|2541\d{8})$/.test(p);
}

function validateStep1() {
  const fn = document.getElementById('first_name').value.trim();
  const ln = document.getElementById('last_name').value.trim();
  const un = document.getElementById('username').value.trim();
  if (!fn || !ln) { toastr.error("Please enter your first and last name."); return false; }
  if (!/^[a-z0-9]{3,15}$/.test(un)) {
    toastr.error("Username must be 3–15 lowercase letters/numbers.");
    document.getElementById('username').focus(); return false;
  }
  const fb = document.getElementById('usernameFeedback');
  if (fb && fb.classList.contains('taken')) { toastr.error("That username is taken. Choose another."); return false; }
  return true;
}

function validateStep2() {
  const email = document.getElementById('email').value.trim();
  if (!isValidEmail(email)) {
    toastr.error("Please enter a valid email address.");
    document.getElementById('email').focus(); return false;
  }
  const efb = document.getElementById('emailFeedback');
  if (efb && efb.classList.contains('taken')) { toastr.error("That email is already registered."); return false; }
  return true;
}

function validateStep3() {
  const phone = document.getElementById('phone').value.trim();
  if (!isValidPhone(phone)) {
    toastr.error("Please enter a valid Kenyan phone number.");
    document.getElementById('phone').focus(); return false;
  }
  return true;
}

/* ── PROGRESS SAVE ────────────────────────────────────── */
function saveProgress(step) {
  postAjax({
    ajax_action: 'save_progress', step,
    first_name: document.getElementById('first_name')?.value || '',
    last_name:  document.getElementById('last_name')?.value  || '',
    username:   document.getElementById('username')?.value   || '',
    email:      document.getElementById('email')?.value      || '',
    phone:      document.getElementById('phone')?.value      || '',
    county:     document.querySelector('select[name=county]')?.value || '',
  }).catch(() => {});
}

/* ── STEP NAVIGATION ──────────────────────────────────── */
async function nextStep() {
  if (currentStep === 1 && !validateStep1()) return;
  if (currentStep === 2 && !validateStep2()) return;

  /* Step 3 → send OTP then advance to step 4 */
  if (currentStep === 3) {
    if (!validateStep3()) return;
    if (phoneVerifiedState) {
      /* Already verified from a previous session, skip OTP */
      saveProgress(5); showStep(5); return;
    }
    const sent = await sendOtpAndAdvance();
    if (!sent) return;
    saveProgress(4); showStep(4); return;
  }

  if (currentStep < TOTAL_STEPS) {
    saveProgress(currentStep + 1);
    showStep(currentStep + 1);
  }
}

function prevStep() {
  if (currentStep === 4) {
    /* Going back from OTP → back to phone entry, clear OTP state */
    clearOtpDigits(); clearOtpTimer(); clearOtpStatus();
    saveProgress(3); showStep(3); return;
  }
  if (currentStep > 1) { saveProgress(currentStep - 1); showStep(currentStep - 1); }
}

function goBackToPhone() {
  clearOtpDigits(); clearOtpTimer(); clearOtpStatus();
  document.getElementById('phone').readOnly = false;
  document.getElementById('phone').classList.remove('verified-field');
  showStep(3);
}

/* ── USERNAME ─────────────────────────────────────────── */
const usernameInput = document.getElementById('username');
const charCountEl   = document.getElementById('charCount');

usernameInput.addEventListener('keypress', e => {
  if (!/^[a-z0-9]$/.test(e.key)) e.preventDefault();
});

let usernameTimer;
usernameInput.addEventListener('input', function () {
  this.value = this.value.toLowerCase().replace(/[^a-z0-9]/g, '');
  const len  = this.value.length;
  charCountEl.textContent = len + ' / 3–15';
  charCountEl.className   = 'char-count ' + (len >= 3 && len <= 15 ? 'valid' : 'invalid');
  clearTimeout(usernameTimer);
  const fb  = document.getElementById('usernameFeedback');
  const val = this.value;
  if (!val) { fb.textContent = ''; fb.className = 'feedback'; return; }
  if (!/^[a-z0-9]{3,15}$/.test(val)) {
    fb.textContent = val.length < 3 ? 'Too short — need at least 3 characters'
                   : val.length > 15 ? 'Too long — max 15 characters'
                   : 'Only lowercase letters and numbers allowed';
    fb.className = 'feedback invalid'; return;
  }
  fb.textContent = 'Checking…'; fb.className = 'feedback checking';
  usernameTimer = setTimeout(async () => {
    const r = await postAjax({ ajax_action: 'check_username', value: val });
    fb.textContent = r.exists ? 'Username is taken' : 'Username is available ✓';
    fb.className   = 'feedback ' + (r.exists ? 'taken' : 'available');
  }, 500);
});

/* ── EMAIL ────────────────────────────────────────────── */
let emailTimer;
document.getElementById('email').addEventListener('input', function () {
  clearTimeout(emailTimer);
  const fb  = document.getElementById('emailFeedback');
  const val = this.value.trim();
  if (!val) { fb.textContent = ''; fb.className = 'feedback'; return; }
  if (!isValidEmail(val)) { fb.textContent = 'Enter a valid email — must include @'; fb.className = 'feedback invalid'; return; }
  fb.textContent = 'Checking…'; fb.className = 'feedback checking';
  emailTimer = setTimeout(async () => {
    const r = await postAjax({ ajax_action: 'check_email', value: val });
    fb.textContent = r.exists ? 'Email already registered' : 'Email is available ✓';
    fb.className   = 'feedback ' + (r.exists ? 'taken' : 'available');
  }, 600);
});

/* ── PHONE input validation live ─────────────────────── */
document.getElementById('phone').addEventListener('input', function () {
  const fb = document.getElementById('phoneFeedback');
  const val = this.value.trim();
  if (!val) { fb.textContent=''; fb.className='feedback'; return; }
  if (isValidPhone(val)) { fb.textContent='Valid phone number ✓'; fb.className='feedback available'; }
  else { fb.textContent='Invalid format'; fb.className='feedback invalid'; }
});

/* ── OTP ENGINE ───────────────────────────────────────── */
let otpTimerInterval  = null;
let resendCountdown   = null;
let otpSecondsLeft    = 0;
let otpSending        = false;

const digits = Array.from({length:6}, (_,i) => document.getElementById('d' + i));

/* Digit input wiring */
digits.forEach((el, i) => {
  el.addEventListener('keydown', e => {
    if (e.key === 'Backspace') {
      if (el.value) { el.value = ''; el.classList.remove('has-value','error-digit'); updateVerifyBtn(); }
      else if (i > 0) { digits[i-1].focus(); digits[i-1].value=''; digits[i-1].classList.remove('has-value','error-digit'); updateVerifyBtn(); }
      e.preventDefault(); return;
    }
    if (e.key === 'ArrowLeft' && i > 0) { digits[i-1].focus(); e.preventDefault(); return; }
    if (e.key === 'ArrowRight' && i < 5) { digits[i+1].focus(); e.preventDefault(); return; }
  });

  el.addEventListener('input', () => {
    const raw = el.value.replace(/\D/g,'');
    el.value  = raw.slice(-1);
    el.classList.remove('error-digit');
    el.classList.toggle('has-value', !!el.value);
    if (el.value && i < 5) digits[i+1].focus();
    updateVerifyBtn();
    clearOtpStatus();
  });

  el.addEventListener('paste', e => {
    const pasted = (e.clipboardData||window.clipboardData).getData('text').replace(/\D/g,'');
    if (pasted.length >= 6) {
      e.preventDefault();
      digits.forEach((d,idx) => {
        d.value = pasted[idx] || '';
        d.classList.remove('error-digit');
        d.classList.toggle('has-value', !!d.value);
      });
      updateVerifyBtn();
      digits[Math.min(5, pasted.length-1)].focus();
    }
  });

  /* Mobile: some keyboards fire 'input' with the full digit even on maxlength=1 */
  el.addEventListener('keyup', () => {
    if (el.value.length > 1) {
      const v = el.value.replace(/\D/g,'');
      el.value = v.slice(0,1);
      el.classList.toggle('has-value', !!el.value);
      if (el.value && i < 5) digits[i+1].focus();
      updateVerifyBtn();
    }
  });
});

function getOtpValue()    { return digits.map(d=>d.value).join(''); }

function clearOtpDigits() {
  digits.forEach(d => { d.value=''; d.classList.remove('has-value','error-digit','verified-digit'); });
  updateVerifyBtn();
}

function updateVerifyBtn() {
  document.getElementById('btnVerifyOtp').disabled = getOtpValue().length < 6;
}

function clearOtpTimer() { clearInterval(otpTimerInterval); clearInterval(resendCountdown); }

function clearOtpStatus() {
  const el = document.getElementById('otpStatusMsg');
  el.textContent=''; el.className='otp-status-msg';
}

function setOtpStatus(msg, type) {
  const el = document.getElementById('otpStatusMsg');
  el.textContent = msg; el.className = 'otp-status-msg ' + type;
}

function startOtpTimer(seconds) {
  clearInterval(otpTimerInterval);
  otpSecondsLeft = seconds;
  const badge   = document.getElementById('otpTimerBadge');
  const text    = document.getElementById('otpTimerText');
  const dot     = document.getElementById('otpTimerDot');
  function tick() {
    const m = Math.floor(otpSecondsLeft/60), s = otpSecondsLeft%60;
    text.textContent = m + ':' + String(s).padStart(2,'0');
    const urgent = otpSecondsLeft <= 60;
    badge.className = 'otp-timer-badge' + (urgent?' urgent':'');
    dot.className   = 'otp-timer-dot'   + (urgent?' urgent':'');
    if (otpSecondsLeft <= 0) {
      clearInterval(otpTimerInterval);
      text.textContent = 'Expired';
      document.getElementById('btnVerifyOtp').disabled = true;
      setOtpStatus('Code has expired — resend to try again.','error');
      digits.forEach(d=>d.classList.add('error-digit'));
    }
    otpSecondsLeft--;
  }
  tick();
  otpTimerInterval = setInterval(tick, 1000);
}

function startResendCountdown() {
  const btn = document.getElementById('btnResendOtp');
  btn.disabled = true;
  let t = 60; btn.textContent = `Resend code (${t}s)`;
  resendCountdown = setInterval(() => {
    t--;
    btn.textContent = t > 0 ? `Resend code (${t}s)` : 'Resend code';
    if (t <= 0) { clearInterval(resendCountdown); btn.disabled = false; }
  }, 1000);
}

/* Send OTP — called when advancing from step 3, returns true/false */
async function sendOtpAndAdvance() {
  if (otpSending) return false;
  otpSending = true;
  const phoneVal = document.getElementById('phone').value.trim();
  const nextBtn  = document.getElementById('nextBtn');
  const origHtml = nextBtn.innerHTML;
  nextBtn.disabled = true;
  nextBtn.innerHTML = '<svg class="spinner" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"/></svg> Sending…';
  try {
    const r = await postAjax({ ajax_action: 'send_otp', phone: phoneVal });
    if (r.success) {
      /* Update OTP step UI */
      document.getElementById('otpDisplayPhone').textContent = r.masked || phoneVal;
      document.getElementById('summaryPhone').textContent    = phoneVal;
      clearOtpDigits();
      clearOtpStatus();
      document.getElementById('btnVerifyOtp').disabled = true;
      startOtpTimer(r.expires_in || 300);
      startResendCountdown();
      toastr.info('Verification code sent to ' + (r.masked || phoneVal));
      otpSending = false;
      nextBtn.disabled = false; nextBtn.innerHTML = origHtml;
      return true;
    } else {
      toastr.error(r.message || 'Failed to send OTP. Please try again.');
      otpSending = false;
      nextBtn.disabled = false; nextBtn.innerHTML = origHtml;
      return false;
    }
  } catch (e) {
    toastr.error('Network error. Please check your connection.');
    otpSending = false;
    nextBtn.disabled = false; nextBtn.innerHTML = origHtml;
    return false;
  }
}

/* Resend OTP (from step 4) */
async function resendOtp() {
  const phoneVal = document.getElementById('phone').value.trim();
  const btn      = document.getElementById('btnResendOtp');
  btn.disabled   = true; btn.textContent = 'Sending…';
  clearOtpStatus();
  try {
    const r = await postAjax({ ajax_action: 'send_otp', phone: phoneVal });
    if (r.success) {
      clearOtpDigits();
      startOtpTimer(r.expires_in || 300);
      startResendCountdown();
      document.getElementById('btnVerifyOtp').disabled = true;
      toastr.info('New code sent to ' + r.masked);
    } else {
      toastr.error(r.message || 'Could not resend OTP.');
      btn.disabled = false; btn.textContent = 'Resend code';
    }
  } catch(e) {
    toastr.error('Network error.');
    btn.disabled = false; btn.textContent = 'Resend code';
  }
}

/* Verify OTP */
async function verifyOtp() {
  const otp = getOtpValue();
  if (otp.length < 6) return;
  const btn = document.getElementById('btnVerifyOtp');
  btn.disabled = true;
  btn.innerHTML = '<svg class="spinner" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"/></svg> Verifying…';
  try {
    const r = await postAjax({ ajax_action: 'verify_otp', otp });
    if (r.success) {
      clearInterval(otpTimerInterval); clearInterval(resendCountdown);
      phoneVerifiedState = true;

      /* Animate digits green */
      digits.forEach(d => { d.classList.remove('has-value','error-digit'); d.classList.add('verified-digit'); });
      setOtpStatus('✓ Phone verified! Moving you forward…', 'success');
      btn.innerHTML = '✓ Verified';

      /* Update phone field */
      const phoneInput = document.getElementById('phone');
      phoneInput.classList.add('verified-field');
      phoneInput.readOnly = true;
      phoneInput.nextElementSibling?.classList.add('show');

      toastr.success('Phone number verified!');

      /* Advance to step 5 after a short moment */
      setTimeout(() => {
        saveProgress(5);
        showStep(5);
      }, 900);

    } else {
      digits.forEach(d => d.classList.add('error-digit'));
      setOtpStatus(r.message || 'Incorrect code. Try again.', 'error');
      btn.innerHTML = '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg> Verify Code';
      if (!r.locked && !r.expired) {
        btn.disabled = getOtpValue().length < 6;
        /* Shake and clear after a moment so user can re-type */
        setTimeout(() => {
          digits.forEach(d => d.classList.remove('error-digit'));
          clearOtpDigits();
          digits[0].focus();
        }, 1200);
      } else {
        clearOtpDigits();
        document.getElementById('btnResendOtp').disabled = false;
        document.getElementById('btnResendOtp').textContent = 'Resend code';
        clearInterval(resendCountdown);
      }
    }
  } catch(e) {
    setOtpStatus('Network error. Try again.', 'error');
    btn.disabled = false;
    btn.innerHTML = '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg> Verify Code';
  }
}

document.getElementById('btnResendOtp').addEventListener('click', resendOtp);
document.getElementById('btnVerifyOtp').addEventListener('click', verifyOtp);

/* Focus first empty digit on step 4 load */
function focusFirstDigit() {
  const first = digits.find(d => !d.value);
  (first || digits[0]).focus();
}

/* ── FORM SUBMIT ─────────────────────────────────────── */
document.getElementById('registerForm').addEventListener('submit', function(e) {
  if (document.querySelector('input[name="website"]').value) { e.preventDefault(); return; }
  if (currentStep < TOTAL_STEPS) { e.preventDefault(); nextStep(); return; }
  const county = document.querySelector('select[name=county]').value;
  if (!county) { e.preventDefault(); toastr.error("Please select your county."); return; }
  const btn = document.getElementById('registerBtn');
  btn.disabled = true;
  btn.innerHTML = '<svg class="spinner" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"/></svg> Creating account…';
});

/* ── INIT ────────────────────────────────────────────── */
(function init() {
  showStep(SAVED_STEP);

  const uv = usernameInput.value;
  if (uv) {
    charCountEl.textContent = uv.length + ' / 3–15';
    charCountEl.className   = 'char-count ' + (uv.length >= 3 && uv.length <= 15 ? 'valid' : 'invalid');
  }

  /* If we restore to step 4, restart timer from session remaining time */
  if (SAVED_STEP === 4 && OTP_EXPIRES_IN > 0) {
    startOtpTimer(OTP_EXPIRES_IN);
    startResendCountdown();
    setTimeout(focusFirstDigit, 100);
  }

  /* Resume timer if returning to page while on OTP step */
  document.addEventListener('visibilitychange', () => {
    if (!document.hidden && currentStep === 4 && otpSecondsLeft > 0 && !phoneVerifiedState) {
      startOtpTimer(otpSecondsLeft);
    }
  });

  /* Focus first digit when step 4 is shown */
  const origShowStep = showStep;
})();
</script>
</body>
</html>