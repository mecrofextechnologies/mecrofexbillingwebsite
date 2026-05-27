<?php
declare(strict_types=1);
session_start();

/* ─────────────────────────────────────────────
   SECURITY HEADERS
───────────────────────────────────────────── */
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
header("X-XSS-Protection: 1; mode=block");
header("Referrer-Policy: strict-origin-when-cross-origin");
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdn.jsdelivr.net; font-src 'self' https://fonts.gstatic.com; img-src 'self' https://images.unsplash.com data:; connect-src 'self'; frame-ancestors 'none';");

include 'msanii/includes/connect.php';

/* ─────────────────────────────────────────────
   CONSTANTS
───────────────────────────────────────────── */
defined('MAX_ATTEMPTS')    || define('MAX_ATTEMPTS',    5);
defined('LOCKOUT_SECONDS') || define('LOCKOUT_SECONDS', 300);
defined('REDIRECT_DELAY')  || define('REDIRECT_DELAY',  2);

/* ─────────────────────────────────────────────
   CSRF TOKEN  (rotate every 30 min)
───────────────────────────────────────────── */
if (empty($_SESSION['csrf_token']) || (time() - ($_SESSION['csrf_token_time'] ?? 0)) > 1800) {
    $_SESSION['csrf_token']      = bin2hex(random_bytes(32));
    $_SESSION['csrf_token_time'] = time();
}

/* ─────────────────────────────────────────────
   RATE-LIMIT STATE
───────────────────────────────────────────── */
if (!isset($_SESSION['login_attempts']))   $_SESSION['login_attempts']   = 0;
if (!isset($_SESSION['first_attempt_at'])) $_SESSION['first_attempt_at'] = 0;

/* ─────────────────────────────────────────────
   HELPERS — IP & USER AGENT
───────────────────────────────────────────── */
function getClientIp(): string {
    foreach (['HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $key) {
        if (!empty($_SERVER[$key])) {
            $ip = trim(explode(',', $_SERVER[$key])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
        }
    }
    return '0.0.0.0';
}

function getUserAgent(): string {
    return substr($_SERVER['HTTP_USER_AGENT'] ?? 'unknown', 0, 500);
}

/* ─────────────────────────────────────────────
   HELPER — Write to creator_logs
───────────────────────────────────────────── */
function logCreatorEvent(
    PDO     $pdo,
    string  $event_type,
    string  $email,
    ?int    $creator_id     = null,
    ?string $failure_reason = null,
    int     $attempt_number = 0
): void {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO creator_logs
                (creator_id, email, event_type, failure_reason, attempt_number, ip_address, user_agent)
            VALUES
                (:creator_id, :email, :event_type, :failure_reason, :attempt_number, :ip_address, :user_agent)
        ");
        $stmt->execute([
            ':creator_id'     => $creator_id,
            ':email'          => $email,
            ':event_type'     => $event_type,
            ':failure_reason' => $failure_reason,
            ':attempt_number' => $attempt_number,
            ':ip_address'     => getClientIp(),
            ':user_agent'     => getUserAgent(),
        ]);
    } catch (PDOException $e) {
        error_log('creator_logs insert error: ' . $e->getMessage());
    }
}

/* ─────────────────────────────────────────────
   LOCKOUT HELPERS
───────────────────────────────────────────── */
function isLockedOut(): bool {
    if ($_SESSION['login_attempts'] < MAX_ATTEMPTS) return false;
    $elapsed = time() - ($_SESSION['first_attempt_at'] ?? 0);
    if ($elapsed >= LOCKOUT_SECONDS) {
        $_SESSION['login_attempts']   = 0;
        $_SESSION['first_attempt_at'] = 0;
        return false;
    }
    return true;
}
function lockoutSecondsLeft(): int {
    return max(0, LOCKOUT_SECONDS - (time() - ($_SESSION['first_attempt_at'] ?? 0)));
}

/* ─────────────────────────────────────────────
   ALREADY LOGGED IN?
───────────────────────────────────────────── */
if (!empty($_SESSION['creator_id'])) {
    header('Location: msanii/index.php'); exit;
}
if (!empty($_SESSION['affiliate_id'])) {
    header('Location: affiliate/index.php'); exit;
}

/* ─────────────────────────────────────────────
   PROCESS POST
───────────────────────────────────────────── */
$alert      = null;
$login_type = 'creator';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $login_type = in_array($_POST['login_type'] ?? '', ['creator', 'affiliate'])
        ? $_POST['login_type'] : 'creator';

    $email = strtolower(trim(filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL) ?? ''));

    /* ── CSRF check ── */
    if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {

        if ($login_type === 'creator') {
            logCreatorEvent($pdo, 'csrf_mismatch', $email, null, 'CSRF token mismatch', $_SESSION['login_attempts']);
        }

        $alert = ['type' => 'error', 'title' => 'Invalid Request', 'message' => 'Session token mismatch. Please refresh and try again.'];
    }

    /* ── Lockout check ── */
    elseif (isLockedOut()) {
        $mins = ceil(lockoutSecondsLeft() / 60);

        if ($login_type === 'creator') {
            logCreatorEvent($pdo, 'locked_out', $email, null, 'Account locked — too many failed attempts', $_SESSION['login_attempts']);
        }

        $alert = ['type' => 'warning', 'title' => 'Account Locked', 'message' => "Too many failed attempts. Please wait {$mins} minute(s)."];
    }

    else {
        $password = trim($_POST['password'] ?? '');

        /* ── Missing fields ── */
        if ($email === '' || $password === '') {

            if ($login_type === 'creator') {
                logCreatorEvent($pdo, 'missing_fields', $email, null, 'Email or password field was empty', $_SESSION['login_attempts']);
            }

            $alert = ['type' => 'error', 'title' => 'Missing Fields', 'message' => 'Email and password are required.'];
        }

        /* ── Invalid email format ── */
        elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {

            if ($login_type === 'creator') {
                logCreatorEvent($pdo, 'invalid_email', $email, null, 'Email failed FILTER_VALIDATE_EMAIL', $_SESSION['login_attempts']);
            }

            $alert = ['type' => 'error', 'title' => 'Invalid Email', 'message' => 'Please enter a valid email address.'];
        }

        /* ══════════════════════════════════════
           CREATOR LOGIN
        ══════════════════════════════════════ */
        elseif ($login_type === 'creator') {
            try {
                $stmt = $pdo->prepare("SELECT id, full_name, email, password, must_change_password FROM creators WHERE email = :email LIMIT 1");
                $stmt->execute([':email' => $email]);
                $creator = $stmt->fetch(PDO::FETCH_ASSOC);
            } catch (PDOException $e) {
                error_log('Login DB error: ' . $e->getMessage());
                $creator = null;
            }

            $userExists    = (bool)$creator;
            $validPassword = $creator && password_verify($password, $creator['password']);

            /* ── Wrong password or email not found ── */
            if (!$validPassword) {
                if ($_SESSION['login_attempts'] === 0) $_SESSION['first_attempt_at'] = time();
                $_SESSION['login_attempts']++;
                $remaining = MAX_ATTEMPTS - $_SESSION['login_attempts'];

                $event_type     = $userExists ? 'failed_password' : 'failed_not_found';
                $failure_reason = $userExists
                    ? 'Password did not match'
                    : 'No creator account found for this email';

                logCreatorEvent(
                    $pdo,
                    $event_type,
                    $email,
                    $userExists ? (int)$creator['id'] : null,
                    $failure_reason,
                    $_SESSION['login_attempts']
                );

                if ($remaining <= 0) {
                    $alert = ['type' => 'warning', 'title' => 'Account Locked',
                        'message' => 'Maximum attempts reached. Try again in 5 minutes.<br><br><a href="forgot-password" style="color:#C8102E;font-weight:bold;text-decoration:none;">Forgot your password? Reset here</a>'];
                } else {
                    $alert = ['type' => 'error', 'title' => 'Login Failed',
                        'message' => "Invalid credentials. {$remaining} attempt(s) remaining.<br><br><a href='forgot-password' style='color:#C8102E;font-weight:bold;text-decoration:none;'>Forgot your password? Reset here</a>"];
                }

            /* ── Success ── */
            } else {
                $_SESSION['login_attempts']   = 0;
                $_SESSION['first_attempt_at'] = 0;

                // Rehash if needed
                if (password_needs_rehash($creator['password'], PASSWORD_BCRYPT, ['cost' => 12])) {
                    $newHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
                    $pdo->prepare("UPDATE creators SET password = ? WHERE id = ?")->execute([$newHash, $creator['id']]);
                }

                $pdo->prepare("UPDATE creators SET last_login = NOW() WHERE id = ?")->execute([$creator['id']]);
                session_regenerate_id(true);

                $_SESSION['creator_id']    = (int)$creator['id'];
                $_SESSION['creator_name']  = htmlspecialchars($creator['full_name'], ENT_QUOTES, 'UTF-8');
                $_SESSION['creator_email'] = $email;

                $redirectUrl = ($creator['must_change_password'] == 1)
                    ? 'msanii/index.php?page=change_password'
                    : 'msanii/index.php';

                if ($creator['must_change_password'] == 1) {
                    $_SESSION['force_password_change'] = true;
                    logCreatorEvent(
                        $pdo,
                        'force_password_change',
                        $email,
                        (int)$creator['id'],
                        'Login successful — redirected to change password',
                        0
                    );
                } else {
                    logCreatorEvent(
                        $pdo,
                        'success',
                        $email,
                        (int)$creator['id'],
                        null,
                        0
                    );
                }

                $alert = [
                    'type'    => 'success',
                    'title'   => 'Login Successful',
                    'message' => 'Welcome back, ' . htmlspecialchars($creator['full_name']) . '!',
                    'redirect'=> $redirectUrl,
                    'delay'   => REDIRECT_DELAY,
                ];
            }
        }

        /* ══════════════════════════════════════
           AFFILIATE LOGIN
        ══════════════════════════════════════ */
        else {
            try {
                $stmt = $pdo->prepare("
                    SELECT a.id, a.full_name, a.email, a.temp_password, a.status, a.created_by,
                           c.full_name AS creator_name
                    FROM affiliates a
                    LEFT JOIN creators c ON c.id = a.created_by
                    WHERE a.email = :email
                    LIMIT 1
                ");
                $stmt->execute([':email' => $email]);
                $affiliate = $stmt->fetch(PDO::FETCH_ASSOC);
            } catch (PDOException $e) {
                error_log('Affiliate login DB error: ' . $e->getMessage());
                $affiliate = null;
            }

            $validPassword = $affiliate && password_verify($password, $affiliate['temp_password']);

            if (!$validPassword) {
                if ($_SESSION['login_attempts'] === 0) $_SESSION['first_attempt_at'] = time();
                $_SESSION['login_attempts']++;
                $remaining = MAX_ATTEMPTS - $_SESSION['login_attempts'];

                if ($remaining <= 0) {
                    $alert = ['type' => 'warning', 'title' => 'Account Locked',
                        'message' => 'Maximum attempts reached. Try again in 5 minutes.'];
                } else {
                    $alert = ['type' => 'error', 'title' => 'Login Failed',
                        'message' => "Invalid affiliate credentials. {$remaining} attempt(s) remaining."];
                }
            } elseif ($affiliate['status'] === 'pending') {
                $alert = ['type' => 'warning', 'title' => 'Account Pending',
                    'message' => 'Your affiliate account is awaiting approval. Please check back later.'];
            } else {
                $_SESSION['login_attempts']   = 0;
                $_SESSION['first_attempt_at'] = 0;

                session_regenerate_id(true);
                $_SESSION['affiliate_id']      = (int)$affiliate['id'];
                $_SESSION['affiliate_name']    = htmlspecialchars($affiliate['full_name'], ENT_QUOTES, 'UTF-8');
                $_SESSION['affiliate_email']   = $email;
                $_SESSION['affiliate_creator'] = htmlspecialchars($affiliate['creator_name'] ?? '', ENT_QUOTES, 'UTF-8');

                $alert = [
                    'type'    => 'success',
                    'title'   => 'Welcome!',
                    'message' => 'Logged in as affiliate. Redirecting to your dashboard…',
                    'redirect'=> 'affiliate/index.php',
                    'delay'   => REDIRECT_DELAY,
                ];
            }
        }
    }

    // Rotate CSRF unless we're about to redirect
    if (empty($alert['redirect'])) {
        $_SESSION['csrf_token']      = bin2hex(random_bytes(32));
        $_SESSION['csrf_token_time'] = time();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title>Keychele — Login</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;500;600;700;800&family=DM+Sans:ital,wght@0,400;0,500;0,700;1,400&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

  :root {
    --crimson:      #C8102E;
    --crimson-l:    #FF3356;
    --ink:          #0D0D0D;
    --ink-soft:     #1A1A2E;
    --slate:        #64748B;
    --mist:         #F8F7F5;
    --white:        #FFFFFF;
    --border:       #E8E4DF;
    --border-focus: #C8102E;
    --green:        #0e9f6e;
    --green-l:      #dcfce7;
    --amber:        #d97706;
    --amber-l:      #fef3c7;
    --font-display: 'Syne', sans-serif;
    --font-body:    'DM Sans', sans-serif;
    --radius:       22px;
    --radius-sm:    11px;
    --trn:          0.22s ease;
  }

  html, body {
    height: 100%; font-family: var(--font-body);
    background: var(--mist); color: var(--ink);
    -webkit-font-smoothing: antialiased;
  }

  /* ── Layout ── */
  .page-wrapper { min-height: 100vh; display: flex; align-items: stretch; }

  /* ── Left panel ── */
  .panel-left {
    display: none; flex: 0 0 44%; position: relative;
    overflow: hidden; background: var(--ink-soft);
  }
  @media (min-width: 1024px) { .panel-left { display: flex; flex-direction: column; } }

  .panel-left-img {
    position: absolute; inset: 0; width: 100%; height: 100%;
    object-fit: cover; opacity: 0.28;
  }
  .panel-left-gradient {
    position: absolute; inset: 0;
    background: linear-gradient(140deg, rgba(200,16,46,0.92) 0%, rgba(13,13,13,0.97) 100%);
    mix-blend-mode: multiply;
  }
  .panel-left-content {
    position: relative; z-index: 2; display: flex;
    flex-direction: column; justify-content: space-between;
    height: 100%; padding: 3rem;
  }
  .panel-logo { display: flex; align-items: center; gap: 12px; }
  .panel-logo img { width: 42px; height: 42px; filter: brightness(0) invert(1); }
  .panel-logo span { font-family: var(--font-display); font-weight: 800; font-size: 1.2rem; color: #fff; }

  .panel-hero { flex: 1; display: flex; flex-direction: column; justify-content: center; gap: 1.5rem; }
  .panel-headline {
    font-family: var(--font-display); font-weight: 800;
    font-size: clamp(2.1rem, 3vw, 3rem); line-height: 1.08; color: #fff;
  }
  .panel-headline em { font-style: normal; color: var(--crimson-l); }
  .panel-desc { font-size: .9rem; color: rgba(255,255,255,0.6); line-height: 1.7; max-width: 320px; }

  .panel-badges { display: flex; flex-direction: column; gap: 12px; margin-top: 8px; }
  .panel-badge {
    display: flex; align-items: center; gap: 12px;
    background: rgba(255,255,255,0.07); border: 1px solid rgba(255,255,255,0.1);
    border-radius: 12px; padding: 12px 16px;
  }
  .panel-badge-ico {
    width: 36px; height: 36px; border-radius: 9px;
    background: rgba(200,16,46,0.25); display: flex; align-items: center; justify-content: center;
    flex-shrink: 0; font-size: 1rem;
  }
  .panel-badge-text .pbt { font-size: .8rem; font-weight: 700; color: #fff; }
  .panel-badge-text .pbs { font-size: .72rem; color: rgba(255,255,255,0.5); margin-top: 1px; }
  .panel-footer { font-size: .75rem; color: rgba(255,255,255,0.35); }

  /* ── Right panel ── */
  .panel-right {
    flex: 1; display: flex; align-items: center; justify-content: center;
    padding: 2rem 1.5rem; background: var(--mist);
  }

  /* ── Card ── */
  .form-card {
    width: 100%; max-width: 460px;
    background: var(--white); border-radius: var(--radius);
    box-shadow: 0 32px 80px rgba(13,13,13,0.11), 0 2px 8px rgba(13,13,13,0.05);
    padding: 2.75rem 2.5rem;
    position: relative; overflow: hidden;
    animation: fadeSlide .35s ease;
  }
  @media (max-width: 480px) { .form-card { padding: 2rem 1.5rem; border-radius: 16px; } }
  .form-card::before {
    content: ''; position: absolute; top: 0; left: 0; right: 0; height: 3.5px;
    background: linear-gradient(90deg, var(--crimson), var(--crimson-l));
    transition: background 0.3s ease;
  }
  body.aff-mode .form-card::before {
    background: linear-gradient(90deg, #0b8a5f, var(--green));
  }

  /* Mobile brand */
  .mobile-brand { display: flex; align-items: center; justify-content: center; gap: 10px; margin-bottom: 1.75rem; }
  @media (min-width: 1024px) { .mobile-brand { display: none; } }
  .mobile-brand img { width: 34px; height: 34px; }
  .mobile-brand span { font-family: var(--font-display); font-weight: 800; font-size: 1.1rem; }

  /* ── Type switcher ── */
  .type-switcher {
    display: grid; grid-template-columns: 1fr 1fr;
    background: var(--mist); border: 1.5px solid var(--border);
    border-radius: 12px; padding: 4px; gap: 4px; margin-bottom: 2rem;
  }
  .type-btn {
    padding: 11px 10px; border-radius: 9px; border: none; background: transparent;
    font-family: var(--font-body); font-size: .8rem; font-weight: 700;
    color: var(--slate); cursor: pointer; transition: var(--trn);
    display: flex; align-items: center; justify-content: center; gap: 7px;
  }
  .type-btn svg { width: 15px; height: 15px; flex-shrink: 0; }
  .type-btn.active {
    background: var(--white); color: var(--ink);
    box-shadow: 0 2px 8px rgba(13,13,13,0.10);
  }
  .type-btn.active.creator  { color: var(--crimson); }
  .type-btn.active.affiliate { color: var(--green);  }

  /* ── Dynamic header ── */
  .form-header { text-align: center; margin-bottom: 1.75rem; }
  .icon-circle {
    width: 60px; height: 60px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    margin: 0 auto 1.25rem; transition: var(--trn);
  }
  .icon-circle.creator {
    background: rgba(200,16,46,0.09);
    border: 1.5px solid rgba(200,16,46,0.16);
    color: var(--crimson);
  }
  .icon-circle.affiliate {
    background: rgba(14,159,110,0.10);
    border: 1.5px solid rgba(14,159,110,0.2);
    color: var(--green);
  }
  .form-title    { font-family: var(--font-display); font-weight: 800; font-size: 1.6rem; margin-bottom: 0.4rem; }
  .form-subtitle { font-size: .82rem; color: var(--slate); line-height: 1.6; }

  /* ── Fields ── */
  .field { margin-bottom: 1.2rem; }
  .field label { display: block; font-size: .78rem; font-weight: 600; color: #374151; margin-bottom: .45rem; }
  .field-wrap { position: relative; }
  .field-icon {
    position: absolute; left: 13px; top: 50%; transform: translateY(-50%);
    width: 15px; height: 15px; color: #9CA3AF; pointer-events: none; transition: var(--trn);
  }
  .field input {
    width: 100%; height: 50px; padding: 0 14px 0 40px;
    border: 1.5px solid var(--border); border-radius: var(--radius-sm);
    font-family: var(--font-body); font-size: .88rem;
    background: #FAFAFA; outline: none; color: var(--ink); transition: var(--trn);
  }
  .field input:focus {
    border-color: var(--border-focus); background: var(--white);
    box-shadow: 0 0 0 3px rgba(200,16,46,0.07);
  }
  .field-wrap:focus-within .field-icon { color: var(--crimson); }

  /* affiliate focus overrides */
  body.aff-mode .field input:focus {
    border-color: var(--green);
    box-shadow: 0 0 0 3px rgba(14,159,110,0.08);
  }
  body.aff-mode .field-wrap:focus-within .field-icon { color: var(--green); }

  /* password toggle */
  .pw-toggle {
    position: absolute; right: 13px; top: 50%; transform: translateY(-50%);
    background: none; border: none; cursor: pointer; color: #9CA3AF;
    padding: 2px; display: flex; align-items: center; transition: var(--trn);
  }
  .pw-toggle:hover { color: var(--slate); }
  .field input[type="password"] { padding-right: 42px; }

  /* ── Forgot link ── */
  .extras {
    display: flex; align-items: center; justify-content: flex-end;
    margin-top: -0.5rem; margin-bottom: 1.5rem;
  }
  .forgot-link { font-size: .78rem; color: var(--slate); text-decoration: none; transition: var(--trn); }
  .forgot-link:hover { color: var(--crimson); text-decoration: underline; }

  /* ── Submit button ── */
  .btn-submit {
    width: 100%; height: 52px; border: none; border-radius: var(--radius-sm);
    font-family: var(--font-body); font-size: .9rem; font-weight: 700;
    color: #fff; cursor: pointer; transition: var(--trn);
    display: flex; align-items: center; justify-content: center; gap: 9px;
  }
  .btn-submit.creator-btn {
    background: linear-gradient(135deg, var(--crimson) 0%, var(--crimson-l) 100%);
    box-shadow: 0 4px 18px rgba(200,16,46,0.38);
  }
  .btn-submit.creator-btn:hover  { box-shadow: 0 6px 24px rgba(200,16,46,0.46); transform: translateY(-1px); }
  .btn-submit.affiliate-btn {
    background: linear-gradient(135deg, #0b8a5f 0%, var(--green) 100%);
    box-shadow: 0 4px 18px rgba(14,159,110,0.35);
  }
  .btn-submit.affiliate-btn:hover { box-shadow: 0 6px 24px rgba(14,159,110,0.44); transform: translateY(-1px); }
  .btn-submit:disabled { opacity: 0.65; cursor: not-allowed; transform: none !important; }

  /* ── Info note ── */
  .info-note {
    margin-top: 1.5rem; padding: 12px 14px; border-radius: 10px;
    font-size: .74rem; line-height: 1.55; display: flex; align-items: flex-start; gap: 8px;
  }
  .info-note.creator-note   { background: rgba(200,16,46,0.05); color: #7f1d1d; border: 1px solid rgba(200,16,46,0.12); }
  .info-note.affiliate-note { background: rgba(14,159,110,0.06); color: #064e3b; border: 1px solid rgba(14,159,110,0.15); }
  .info-note svg { width: 14px; height: 14px; flex-shrink: 0; margin-top: 1px; }

  /* ── Animations ── */
  @keyframes spin      { to { transform: rotate(360deg); } }
  .spinner             { animation: spin 0.8s linear infinite; }
  @keyframes fadeSlide { from { opacity: 0; transform: translateY(6px); } to { opacity: 1; transform: none; } }
</style>
</head>
<body class="<?= ($login_type ?? 'creator') === 'affiliate' ? 'aff-mode' : '' ?>">

<div class="page-wrapper">

  <!-- ══════════════════════════════════════════
       LEFT PANEL
  ═══════════════════════════════════════════ -->
  <div class="panel-left">
    <img src="https://images.unsplash.com/photo-1556740749-887f6717d7e4?w=900" class="panel-left-img" alt="">
    <div class="panel-left-gradient"></div>
    <div class="panel-left-content">

      <div class="panel-logo">
        <img src="images/logo/KECHELE LOGO.png" alt="Keychele">
        <span>Keychele</span>
      </div>

      <div class="panel-hero">
        <h1 class="panel-headline">Welcome<br>back, <em>Champion.</em></h1>
        <p class="panel-desc">Sign in to manage your profile, track your growth, and connect with your audience.</p>
        <div class="panel-badges">
          <div class="panel-badge">
            <div class="panel-badge-ico">🎯</div>
            <div class="panel-badge-text">
              <div class="pbt">Creator Dashboard</div>
              <div class="pbs">Manage challenges, products &amp; analytics</div>
            </div>
          </div>
          <div class="panel-badge">
            <div class="panel-badge-ico">🤝</div>
            <div class="panel-badge-text">
              <div class="pbt">Affiliate Portal</div>
              <div class="pbs">Track commissions, referrals &amp; earnings</div>
            </div>
          </div>
        </div>
      </div>

      <div class="panel-footer">© <?= date('Y') ?> Keychele. All rights reserved.</div>
    </div>
  </div>

  <!-- ══════════════════════════════════════════
       RIGHT PANEL
  ═══════════════════════════════════════════ -->
  <div class="panel-right">
    <div class="form-card">

      <!-- Mobile brand -->
      <div class="mobile-brand">
        <img src="images/logo/KECHELE LOGO.png" alt="Keychele">
        <span>Keychele</span>
      </div>

      <!-- Type switcher -->
      <div class="type-switcher" id="typeSwitcher">
        <button type="button"
          class="type-btn creator <?= ($login_type ?? 'creator') === 'creator' ? 'active' : '' ?>"
          onclick="setLoginType('creator')">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
            <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
            <circle cx="12" cy="7" r="4"/>
          </svg>
          Creator Login
        </button>
        <button type="button"
          class="type-btn affiliate <?= ($login_type ?? 'creator') === 'affiliate' ? 'active' : '' ?>"
          onclick="setLoginType('affiliate')">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
            <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
            <circle cx="9" cy="7" r="4"/>
            <path d="M23 21v-2a4 4 0 0 0-3-3.87"/>
            <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
          </svg>
          Affiliate Login
        </button>
      </div>

      <!-- Dynamic header -->
      <div class="form-header">
        <div class="icon-circle creator" id="headerIcon">
          <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
            <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
            <circle cx="12" cy="7" r="4"/>
          </svg>
        </div>
        <h2 class="form-title" id="headerTitle">Creator Login</h2>
        <p class="form-subtitle" id="headerSubtitle">Enter your credentials to access your creator dashboard.</p>
      </div>

      <!-- Form -->
      <form method="POST" id="loginForm" novalidate>
        <input type="hidden" name="csrf_token"   value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
        <input type="hidden" name="login_type"   id="loginTypeInput" value="<?= htmlspecialchars($login_type ?? 'creator') ?>">

        <!-- Email -->
        <div class="field">
          <label for="email">Email Address</label>
          <div class="field-wrap">
            <svg class="field-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/>
              <polyline points="22,6 12,13 2,6"/>
            </svg>
            <input type="email" id="email" name="email"
              placeholder="you@example.com" required autocomplete="email"
              value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
          </div>
        </div>

        <!-- Password -->
        <div class="field">
          <label for="password">Password</label>
          <div class="field-wrap">
            <svg class="field-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
              <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
            </svg>
            <input type="password" id="password" name="password"
              placeholder="••••••••" required autocomplete="current-password">
            <button type="button" class="pw-toggle" onclick="togglePw()" title="Show/hide password" aria-label="Toggle password visibility">
              <svg id="pwEye" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                <circle cx="12" cy="12" r="3"/>
              </svg>
            </button>
          </div>
        </div>

        <!-- Forgot link -->
        <div class="extras">
          <a href="forgot-password" class="forgot-link" id="forgotLink">Forgot password?</a>
        </div>

        <!-- Submit -->
        <button type="submit" class="btn-submit creator-btn" id="submitBtn">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
            <path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/>
            <polyline points="10 17 15 12 10 7"/>
            <line x1="15" y1="12" x2="3" y2="12"/>
          </svg>
          Sign In as Creator
        </button>

        <!-- Contextual hint -->
        <div class="info-note creator-note" id="infoNote">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <circle cx="12" cy="12" r="10"/>
            <line x1="12" y1="8" x2="12" y2="12"/>
            <line x1="12" y1="16" x2="12.01" y2="16"/>
          </svg>
          <span>Creators manage challenges, products, and their affiliate programs.</span>
        </div>
      </form>

    </div><!-- /.form-card -->
  </div><!-- /.panel-right -->

</div><!-- /.page-wrapper -->

<script>
/* ── Type-switcher config ───────────────────────────────── */
const typeConfig = {
  creator: {
    btnText:    'Sign In as Creator',
    btnClass:   'creator-btn',
    iconClass:  'creator',
    iconSvg:    '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
    title:      'Creator Login',
    subtitle:   'Enter your credentials to access your creator dashboard.',
    noteClass:  'creator-note',
    noteText:   'Creators manage challenges, products, and their affiliate programs.',
    forgotHref: 'forgot-password',
    forgotVisible: true,
  },
  affiliate: {
    btnText:    'Sign In as Affiliate',
    btnClass:   'affiliate-btn',
    iconClass:  'affiliate',
    iconSvg:    '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
    title:      'Affiliate Login',
    subtitle:   'Sign in with your affiliate credentials to view commissions and referrals.',
    noteClass:  'affiliate-note',
    noteText:   'Use the email and temporary password sent to you when your account was created.',
    forgotHref: '#',
    forgotVisible: false,
  }
};

function setLoginType(type) {
  const cfg = typeConfig[type];

  // Hidden input
  document.getElementById('loginTypeInput').value = type;

  // Body class drives CSS focus/border overrides
  document.body.className = type === 'affiliate' ? 'aff-mode' : '';

  // Switcher buttons
  document.querySelectorAll('.type-btn').forEach(btn => btn.classList.remove('active'));
  document.querySelector('.type-btn.' + type).classList.add('active');

  // Icon
  const ic = document.getElementById('headerIcon');
  ic.className = 'icon-circle ' + cfg.iconClass;
  ic.querySelector('svg').innerHTML = cfg.iconSvg;

  // Title / subtitle
  document.getElementById('headerTitle').textContent    = cfg.title;
  document.getElementById('headerSubtitle').textContent = cfg.subtitle;

  // Submit button
  const btn = document.getElementById('submitBtn');
  btn.className = 'btn-submit ' + cfg.btnClass;
  btn.innerHTML =
    `<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
       <path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/>
       <polyline points="10 17 15 12 10 7"/>
       <line x1="15" y1="12" x2="3" y2="12"/>
     </svg> ${cfg.btnText}`;

  // Note
  const note = document.getElementById('infoNote');
  note.className = 'info-note ' + cfg.noteClass;
  note.querySelector('span').textContent = cfg.noteText;

  // Forgot link
  const fl = document.getElementById('forgotLink');
  fl.href = cfg.forgotHref;
  fl.style.visibility = cfg.forgotVisible ? 'visible' : 'hidden';
}

/* ── Password toggle ────────────────────────────────────── */
function togglePw() {
  const inp   = document.getElementById('password');
  const isText = inp.type === 'text';
  inp.type = isText ? 'password' : 'text';
  document.getElementById('pwEye').innerHTML = isText
    ? '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>'
    : '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/>';
}

/* ── Submit spinner ─────────────────────────────────────── */
document.getElementById('loginForm').addEventListener('submit', function () {
  const btn = document.getElementById('submitBtn');
  btn.disabled = true;
  btn.innerHTML =
    `<svg class="spinner" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
       <path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"/>
     </svg> Signing in…`;
});

/* ── SweetAlert2 ─────────────────────────────────────────── */
<?php if ($alert): ?>
  Swal.fire({
    icon:              '<?= $alert['type'] ?>',
    title:             '<?= addslashes($alert['title']) ?>',
    html:              '<?= addslashes($alert['message']) ?>',
    showConfirmButton: <?= $alert['type'] === 'success' ? 'false' : 'true' ?>,
    timer:             <?= $alert['type'] === 'success' ? ($alert['delay'] * 1000) : 'null' ?>,
    timerProgressBar:  <?= $alert['type'] === 'success' ? 'true' : 'false' ?>,
    heightAuto: false,
  }).then(() => {
    <?php if (!empty($alert['redirect'])): ?>
      window.location.href = '<?= addslashes($alert['redirect']) ?>';
    <?php endif; ?>
  });
<?php endif; ?>
</script>
</body>
</html>