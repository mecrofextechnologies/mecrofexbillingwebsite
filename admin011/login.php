<?php
ob_start();
session_start();
date_default_timezone_set('Africa/Nairobi');

// ────────────────────────────────────────────────
//  CONFIG / INCLUDES
// ────────────────────────────────────────────────
include '../msanii/includes/connect.php';
require '../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
// !! IMPORTANT: alias PHPMailer's own Exception so it doesn't conflict with \Exception
use PHPMailer\PHPMailer\Exception as MailException;

// ── Debug mode: set to FALSE in production ──────
define('DEBUG_MODE', true);

ini_set('display_errors', DEBUG_MODE ? 1 : 0);
ini_set('display_startup_errors', DEBUG_MODE ? 1 : 0);
error_reporting(E_ALL);

$error_message   = '';
$success_message = '';
$debug_info      = []; // Collects step-by-step debug info

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if (empty($email) || empty($password)) {
        $error_message = "Email and password are required.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error_message = "Please enter a valid email address.";
    } else {
        try {
            // ── STEP 1: DB connection check ──────────────────
            if (!isset($pdo) || !($pdo instanceof PDO)) {
                throw new \RuntimeException("Database connection failed — \$pdo is not available. Check connect.php.");
            }
            if (DEBUG_MODE) $debug_info[] = "✅ Step 1: DB connection OK";

            // ── STEP 2: Authenticate user ────────────────────
            $stmt = $pdo->prepare("
                SELECT admin_id, firstname, email, password, role 
                FROM admin 
                WHERE email = ? 
                LIMIT 1
            ");
            $stmt->execute([$email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (DEBUG_MODE) $debug_info[] = $user
                ? "✅ Step 2: User found — admin_id={$user['admin_id']}, role={$user['role']}"
                : "⚠️ Step 2: No user found for email: " . htmlspecialchars($email);

            if (!$user || !password_verify($password, $user['password'])) {
                $error_message = "Invalid email or password.";
                if (DEBUG_MODE && $user) $debug_info[] = "❌ Step 2b: Password mismatch for {$user['admin_id']}";
            } else {
                // ── STEP 3: Generate OTP ─────────────────────
                $otp        = sprintf("%04d", mt_rand(1000, 9999));
                $expires_at = date('Y-m-d H:i:s', time() + 600);

                if (DEBUG_MODE) $debug_info[] = "✅ Step 3: OTP generated = $otp, expires $expires_at";

                // ── STEP 4: Store OTP ────────────────────────
                // Uses REPLACE INTO to avoid needing a unique constraint on (user_id)
                // If you have a unique key on user_id use the ON DUPLICATE KEY version instead
                $stmt = $pdo->prepare("
                    INSERT INTO otps (user_id, otp, expires_at) 
                    VALUES (?, ?, ?) 
                    ON DUPLICATE KEY UPDATE 
                        otp        = VALUES(otp), 
                        expires_at = VALUES(expires_at)
                ");
                $result = $stmt->execute([$user['admin_id'], $otp, $expires_at]);

                if (!$result) {
                    $errInfo = $stmt->errorInfo();
                    throw new \RuntimeException("OTP INSERT failed: " . implode(' | ', $errInfo));
                }

                if (DEBUG_MODE) {
                    $rows = $stmt->rowCount();
                    $debug_info[] = "✅ Step 4: OTP stored — rowCount=$rows";
                }

                // ── STEP 5: Send email ───────────────────────
                $mail = new PHPMailer(true);

                if (DEBUG_MODE) {
                    $mail->SMTPDebug  = SMTP::DEBUG_CONNECTION;
                    $mail->Debugoutput = function ($str, $level) use (&$debug_info) {
                        $debug_info[] = "📧 SMTP [$level]: " . trim($str);
                        error_log("PHPMailer [$level]: " . trim($str));
                    };
                }

                $mail->isSMTP();
                $mail->Host       = 'mail.keychele.co.ke';
                $mail->SMTPAuth   = true;
                $mail->Username   = 'notifications@keychele.co.ke';
                $mail->Password   = 'King20241064.';
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                $mail->Port       = 587;
                $mail->Timeout    = 15;
                $mail->SMTPOptions = [
                    'ssl' => [
                        'verify_peer'       => false,
                        'verify_peer_name'  => false,
                        'allow_self_signed' => true,
                    ]
                ];

                $mail->setFrom('notifications@keychele.co.ke', 'Keychele');
                $mail->addAddress($email);
                $mail->isHTML(true);
                $mail->Subject = 'Your Keychele Admin Login OTP';
                $mail->Body    = <<<HTML
                <div style="font-family:Inter,Arial;background:#f8fff7;padding:30px">
                    <div style="max-width:600px;margin:auto;background:white;border-radius:16px;overflow:hidden;box-shadow:0 10px 30px rgba(0,0,0,.1)">
                        <div style="background:linear-gradient(135deg,#0f2027,#203a43,#2c5364);padding:40px 30px;text-align:center;color:white">
                            <h1 style="font-size:42px;font-weight:900;margin:0;letter-spacing:-1px">Keychele</h1>
                            <p style="font-size:16px;margin:8px 0 0;opacity:.8;letter-spacing:3px;text-transform:uppercase">Admin Portal</p>
                        </div>
                        <div style="padding:40px 30px;text-align:center">
                            <h2 style="color:#0f2027;font-size:24px;margin-bottom:8px">Verification Code</h2>
                            <p style="font-size:16px;color:#4a5568;line-height:1.7">Use the code below to complete your secure login.</p>
                            <div style="background:#f7f9fc;border:2px solid #e2e8f0;border-radius:16px;padding:32px;margin:32px 0">
                                <span style="font-size:52px;font-weight:900;letter-spacing:16px;color:#2c5364;font-family:monospace">$otp</span>
                                <p style="margin:16px 0 0;font-size:13px;color:#718096">Expires in <strong>10 minutes</strong></p>
                            </div>
                            <p style="color:#a0aec0;font-size:13px">If you didn't request this, you can safely ignore this email.</p>
                        </div>
                        <div style="background:#f7fafc;padding:20px;text-align:center;color:#a0aec0;font-size:12px;border-top:1px solid #e2e8f0">
                            © 2026 <strong style="color:#2c5364">Keychele</strong> · All Rights Reserved
                        </div>
                    </div>
                </div>
                HTML;

                try {
                    $sent = $mail->send();
                    if (DEBUG_MODE) $debug_info[] = "✅ Step 5: Email sent successfully to $email";
                } catch (MailException $me) {
                    $errDetail = $mail->ErrorInfo;
                    if (DEBUG_MODE) $debug_info[] = "❌ Step 5: PHPMailer send failed — $errDetail";
                    throw new \RuntimeException("Email send failed: $errDetail");
                }

                // ── STEP 6: Set session & redirect ───────────
                session_regenerate_id(true);
                $_SESSION['otp_user_id']   = $user['admin_id'];
                $_SESSION['otp_email']     = $email;
                $_SESSION['otp_pending']   = true;
                $_SESSION['otp_firstname'] = $user['firstname'];
                $_SESSION['otp_role']      = $user['role'];

                if (DEBUG_MODE) $debug_info[] = "✅ Step 6: Session set, redirecting to verify_otp.php";

                $success_message = "OTP sent! Redirecting to verification...";
                echo '<script>setTimeout(() => { window.location.href = "verify_otp.php"; }, 2000);</script>';
            }

        } catch (\RuntimeException $e) {
            // Runtime errors we explicitly throw — show full detail in debug
            $error_message = DEBUG_MODE
                ? "Runtime Error: " . $e->getMessage()
                : "System error. Please try again.";
            error_log("Login RuntimeException: " . $e->getMessage() . "\n" . $e->getTraceAsString());
            if (DEBUG_MODE) $debug_info[] = "❌ RuntimeException: " . $e->getMessage();

        } catch (\PDOException $e) {
            $error_message = DEBUG_MODE
                ? "Database Error: " . $e->getMessage()
                : "System error. Please try again.";
            error_log("Login PDOException: " . $e->getMessage() . "\n" . $e->getTraceAsString());
            if (DEBUG_MODE) $debug_info[] = "❌ PDOException: " . $e->getMessage();

        } catch (\Exception $e) {
            $error_message = DEBUG_MODE
                ? "Exception: " . $e->getMessage()
                : "System error. Please try again.";
            error_log("Login Exception: " . $e->getMessage() . "\n" . $e->getTraceAsString());
            if (DEBUG_MODE) $debug_info[] = "❌ Exception: " . $e->getMessage();

        } catch (\Throwable $t) {
            $error_message = DEBUG_MODE
                ? "Fatal Error: " . $t->getMessage() . " in " . $t->getFile() . " line " . $t->getLine()
                : "Unexpected server error.";
            error_log("Login Throwable: " . $t->getMessage() . "\n" . $t->getTraceAsString());
            if (DEBUG_MODE) $debug_info[] = "❌ Throwable: " . $t->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Keychele | Admin Login</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
    <style>
        :root {
            --ink:      #0b1620;
            --deep:     #0f2027;
            --mid:      #203a43;
            --teal:     #2c5364;
            --accent:   #4ecdc4;
            --muted:    #8899a6;
            --surface:  #ffffff;
            --border:   #dde4ea;
            --error-bg: #fff1f2;
            --error-fg: #be123c;
            --ok-bg:    #f0fdf4;
            --ok-fg:    #166534;
        }

        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            min-height: 100vh;
            font-family: 'DM Sans', sans-serif;
            background: var(--deep);
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            position: relative;
        }

        /* ── Ambient background ── */
        body::before {
            content: '';
            position: fixed;
            inset: 0;
            background:
                radial-gradient(ellipse 80% 60% at 20% 110%, rgba(44,83,100,.45) 0%, transparent 60%),
                radial-gradient(ellipse 60% 50% at 80% -10%,  rgba(78,205,196,.12) 0%, transparent 55%),
                radial-gradient(ellipse 100% 80% at 50% 50%,  #0f2027 0%, #0b1620 100%);
            pointer-events: none;
        }

        /* ── Grid texture overlay ── */
        body::after {
            content: '';
            position: fixed;
            inset: 0;
            background-image: linear-gradient(rgba(255,255,255,.02) 1px, transparent 1px),
                              linear-gradient(90deg, rgba(255,255,255,.02) 1px, transparent 1px);
            background-size: 48px 48px;
            pointer-events: none;
        }

        /* ── Card ── */
        .card {
            position: relative;
            z-index: 10;
            width: 100%;
            max-width: 440px;
            margin: 20px;
            background: var(--surface);
            border-radius: 20px;
            overflow: hidden;
            box-shadow:
                0 0 0 1px rgba(255,255,255,.06),
                0 24px 64px rgba(0,0,0,.45),
                0 8px 24px rgba(0,0,0,.3);
            animation: rise .7s cubic-bezier(.22,1,.36,1) both;
        }

        @keyframes rise {
            from { opacity: 0; transform: translateY(28px) scale(.98); }
            to   { opacity: 1; transform: translateY(0)    scale(1);   }
        }

        /* ── Card header ── */
        .card-header {
            background: linear-gradient(145deg, var(--deep) 0%, var(--teal) 100%);
            padding: 36px 36px 32px;
            position: relative;
            overflow: hidden;
        }

        .card-header::before {
            content: '';
            position: absolute;
            width: 220px; height: 220px;
            border-radius: 50%;
            border: 40px solid rgba(78,205,196,.08);
            top: -80px; right: -60px;
        }
        .card-header::after {
            content: '';
            position: absolute;
            width: 140px; height: 140px;
            border-radius: 50%;
            border: 28px solid rgba(255,255,255,.04);
            bottom: -60px; left: -30px;
        }

        .logo-ring {
            width: 56px; height: 56px;
            border-radius: 14px;
            background: rgba(255,255,255,.1);
            border: 1px solid rgba(255,255,255,.15);
            display: flex; align-items: center; justify-content: center;
            margin-bottom: 18px;
            backdrop-filter: blur(6px);
        }
        .logo-ring svg { width: 28px; height: 28px; fill: white; opacity: .9; }

        .card-header h1 {
            font-family: 'Syne', sans-serif;
            font-size: 1.75rem;
            font-weight: 800;
            color: white;
            letter-spacing: -0.5px;
            line-height: 1.1;
        }
        .card-header p {
            font-size: .8rem;
            color: rgba(255,255,255,.5);
            letter-spacing: 2.5px;
            text-transform: uppercase;
            margin-top: 6px;
        }

        /* ── Card body ── */
        .card-body { padding: 32px 36px 36px; }

        /* ── Alerts ── */
        .alert {
            display: flex; align-items: flex-start; gap: 10px;
            padding: 12px 16px;
            border-radius: 10px;
            margin-bottom: 22px;
            font-size: .875rem;
            line-height: 1.5;
            animation: alertIn .3s ease both;
        }
        @keyframes alertIn { from { opacity:0; transform:translateY(-6px); } to { opacity:1; transform:none; } }

        .alert-error   { background: var(--error-bg); color: var(--error-fg); border: 1px solid #fecdd3; }
        .alert-success { background: var(--ok-bg);    color: var(--ok-fg);    border: 1px solid #bbf7d0; }
        .alert-icon { flex-shrink: 0; margin-top: 1px; font-size: 1rem; }

        /* ── Form ── */
        .form-group { margin-bottom: 18px; }

        .form-group label {
            display: block;
            font-size: .78rem;
            font-weight: 500;
            color: var(--mid);
            letter-spacing: .5px;
            text-transform: uppercase;
            margin-bottom: 7px;
        }

        .input-wrap {
            position: relative;
        }
        .input-wrap .icon {
            position: absolute;
            left: 14px; top: 50%;
            transform: translateY(-50%);
            color: var(--muted);
            pointer-events: none;
            line-height: 1;
        }

        .form-group input {
            width: 100%;
            padding: 12px 14px 12px 42px;
            border: 1.5px solid var(--border);
            border-radius: 10px;
            font-family: 'DM Sans', sans-serif;
            font-size: .95rem;
            color: var(--ink);
            background: #fafbfc;
            outline: none;
            transition: border-color .2s, box-shadow .2s, background .2s;
        }
        .form-group input::placeholder { color: #b0bec5; }
        .form-group input:focus {
            border-color: var(--teal);
            background: white;
            box-shadow: 0 0 0 3px rgba(44,83,100,.1);
        }

        /* ── Submit button ── */
        .btn {
            width: 100%;
            padding: 14px;
            border: none;
            border-radius: 10px;
            background: linear-gradient(135deg, var(--mid) 0%, var(--teal) 100%);
            color: white;
            font-family: 'Syne', sans-serif;
            font-size: .95rem;
            font-weight: 700;
            letter-spacing: .3px;
            cursor: pointer;
            position: relative;
            overflow: hidden;
            transition: opacity .2s, transform .15s;
            margin-top: 8px;
        }
        .btn:hover:not(:disabled) {
            opacity: .92;
            transform: translateY(-1px);
        }
        .btn:active:not(:disabled) { transform: translateY(0); }
        .btn:disabled { opacity: .65; cursor: not-allowed; }

        .btn::after {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(135deg, transparent 40%, rgba(255,255,255,.08));
        }

        .btn-inner { display: flex; align-items: center; justify-content: center; gap: 10px; }

        .spinner {
            display: none;
            width: 18px; height: 18px;
            border: 2px solid rgba(255,255,255,.3);
            border-top-color: white;
            border-radius: 50%;
            animation: spin .7s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }

        /* ── Footer links ── */
        .card-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 18px 36px;
            border-top: 1px solid var(--border);
            background: #f9fafb;
        }
        .card-footer a {
            font-size: .82rem;
            color: var(--teal);
            text-decoration: none;
            font-weight: 500;
        }
        .card-footer a:hover { text-decoration: underline; }
        .card-footer span { font-size: .78rem; color: var(--muted); }

        /* ── Debug panel ── */
        .debug-panel {
            margin: 0 36px 28px;
            background: #0d1b24;
            border: 1px solid #1e3040;
            border-radius: 10px;
            overflow: hidden;
        }
        .debug-panel summary {
            padding: 10px 16px;
            font-size: .78rem;
            font-family: 'Syne', monospace;
            color: var(--accent);
            cursor: pointer;
            user-select: none;
            letter-spacing: .5px;
        }
        .debug-panel summary:hover { background: rgba(78,205,196,.05); }
        .debug-steps {
            padding: 12px 16px;
            border-top: 1px solid #1e3040;
        }
        .debug-steps p {
            font-family: 'Courier New', monospace;
            font-size: .75rem;
            color: #8bafc2;
            line-height: 1.9;
            white-space: pre-wrap;
            word-break: break-all;
        }
        .debug-steps p.ok   { color: #4ade80; }
        .debug-steps p.warn { color: #facc15; }
        .debug-steps p.err  { color: #f87171; }
        .debug-steps p.smtp { color: #94a3b8; font-size: .7rem; }

        @media (max-width: 480px) {
            .card-body, .card-footer, .debug-panel { padding-left: 24px; padding-right: 24px; }
            .card-header { padding: 28px 24px 24px; }
        }
    </style>
</head>
<body>

<div class="card">

    <!-- Header -->
    <div class="card-header">
        <div class="logo-ring">
            <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                <path d="M12 1L3 5v6c0 5.25 3.75 10.15 9 11.35C17.25 21.15 21 16.25 21 11V5l-9-4zm0 4l5 2.18V11c0 3.46-2.37 6.7-5 7.93-2.63-1.23-5-4.47-5-7.93V7.18L12 5z"/>
            </svg>
        </div>
        <h1>Keychele Admin</h1>
        <p>Secure Access Portal</p>
    </div>

    <!-- Body -->
    <div class="card-body">

        <?php if ($error_message): ?>
        <div class="alert alert-error">
            <span class="alert-icon">⚠️</span>
            <div><?= htmlspecialchars($error_message) ?></div>
        </div>
        <?php endif; ?>

        <?php if ($success_message): ?>
        <div class="alert alert-success">
            <span class="alert-icon">✅</span>
            <div><?= htmlspecialchars($success_message) ?></div>
        </div>
        <?php endif; ?>

        <form method="post" id="loginForm">
            <div class="form-group">
                <label for="email">Email Address</label>
                <div class="input-wrap">
                    <span class="icon">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/>
                            <polyline points="22,6 12,13 2,6"/>
                        </svg>
                    </span>
                    <input type="email" id="email" name="email"
                           placeholder="admin@example.com"
                           value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                           required autofocus>
                </div>
            </div>

            <div class="form-group">
                <label for="password">Password</label>
                <div class="input-wrap">
                    <span class="icon">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
                            <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                        </svg>
                    </span>
                    <input type="password" id="password" name="password"
                           placeholder="••••••••" required>
                </div>
            </div>

            <button class="btn" type="submit" id="loginBtn">
                <span class="btn-inner">
                    <span id="btnText">Sign In</span>
                    <span class="spinner" id="spinner"></span>
                </span>
            </button>
        </form>

    </div><!-- /card-body -->

    <!-- Debug panel (only shown when DEBUG_MODE = true AND steps exist) -->
    <?php if (DEBUG_MODE && !empty($debug_info)): ?>
    <div class="debug-panel">
        <details open>
            <summary>🛠 Debug Trace (<?= count($debug_info) ?> steps)</summary>
            <div class="debug-steps">
                <?php foreach ($debug_info as $step): ?>
                    <?php
                    $cls = 'smtp';
                    if (str_starts_with($step, '✅')) $cls = 'ok';
                    elseif (str_starts_with($step, '⚠️')) $cls = 'warn';
                    elseif (str_starts_with($step, '❌')) $cls = 'err';
                    ?>
                    <p class="<?= $cls ?>"><?= htmlspecialchars($step) ?></p>
                <?php endforeach; ?>
            </div>
        </details>
    </div>
    <?php endif; ?>

    <!-- Footer -->
    <div class="card-footer">
        <a href="#">Forgot password?</a>
        <span>© 2026 Keychele</span>
    </div>

</div><!-- /card -->

<script>
document.getElementById('loginForm').addEventListener('submit', function () {
    const btn     = document.getElementById('loginBtn');
    const txt     = document.getElementById('btnText');
    const spinner = document.getElementById('spinner');
    btn.disabled  = true;
    txt.textContent = 'Signing in…';
    spinner.style.display = 'block';
});
</script>

</body>
</html>