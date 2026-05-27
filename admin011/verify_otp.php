<?php
ob_start();
session_start();
date_default_timezone_set('Africa/Nairobi');
include '../msanii/includes/connect.php';
require '../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

if (!isset($_SESSION['otp_pending']) || !isset($_SESSION['otp_user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['otp_user_id'];
$email = $_SESSION['otp_email'] ?? '';

// Variables for visible messages
$error_message = '';
$success_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['verify_otp'])) {
    $otp_parts = [$_POST['otp1'] ?? '', $_POST['otp2'] ?? '', $_POST['otp3'] ?? '', $_POST['otp4'] ?? ''];
    $entered_otp = implode('', $otp_parts);

    // Visible debug
    $error_message .= "<strong>Debug:</strong> Entered OTP: '$entered_otp' (length: " . strlen($entered_otp) . ")<br>";

    if (strlen($entered_otp) !== 4 || !ctype_digit($entered_otp)) {
        $error_message .= "Invalid OTP format. Must be 4 digits.<br>";
    } else {
        try {
            $stmt = $pdo->prepare("SELECT otp, expires_at FROM otps WHERE user_id = ? LIMIT 1");
            $stmt->execute([$user_id]);
            $otp_row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$otp_row) {
                $error_message .= "No OTP record found in database for this user.<br>";
                error_log("Verify: No OTP row found for user_id $user_id");
            } else {
                $db_otp = trim($otp_row['otp']);
                $expires = $otp_row['expires_at'];
                $now = date('Y-m-d H:i:s');

                // Visible debug info
                $error_message .= "<strong>DB OTP:</strong> '$db_otp' (length: " . strlen($db_otp) . ")<br>";
                $error_message .= "<strong>Expires at:</strong> $expires<br>";
                $error_message .= "<strong>Current time:</strong> $now<br>";

                error_log("Verify: Entered '$entered_otp' vs DB '$db_otp', expires $expires, now $now");

                if ($now > $expires) {
                    $error_message .= "OTP has expired.<br>";
                } elseif ($entered_otp === $db_otp) {
                    // SUCCESS!
                    $stmt = $pdo->prepare("SELECT admin_id, firstname, email, role FROM admin WHERE admin_id = ?");
                    $stmt->execute([$user_id]);
                    $user = $stmt->fetch(PDO::FETCH_ASSOC);

                    if ($user) {
                        session_regenerate_id(true);
                        $_SESSION['logged_in'] = true;
                        $_SESSION['admin_id'] = $user['admin_id'];
                        $_SESSION['firstname'] = $user['firstname'];
                        $_SESSION['user_email'] = $user['email'];
                        $_SESSION['user_role'] = $user['role'];

                        // Clean up OTP
                        $pdo->prepare("DELETE FROM otps WHERE user_id = ?")->execute([$user_id]);
                        unset($_SESSION['otp_pending'], $_SESSION['otp_user_id'], $_SESSION['otp_email']);

                        $success_message = "Login successful! Redirecting to dashboard...";
                        echo '<script>setTimeout(() => { window.location.href = "index.php"; }, 2000);</script>';
                    } else {
                        $error_message .= "User not found after OTP match (critical error).<br>";
                    }
                } else {
                    $error_message .= "OTP does not match.<br>";
                }
            }
        } catch (Exception $e) {
            $error_message .= "Database error: " . $e->getMessage() . "<br>";
            error_log("Verify error: " . $e->getMessage());
        }
    }
}

// Resend OTP - FULLY FIXED
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['resend_otp'])) {
    try {
        $otp = sprintf("%04d", mt_rand(1000, 9999));
        $expires_at = date('Y-m-d H:i:s', time() + 600);

        $stmt = $pdo->prepare("INSERT INTO otps (user_id, otp, expires_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE otp = VALUES(otp), expires_at = VALUES(expires_at)");
        $stmt->execute([$user_id, $otp, $expires_at]);

        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host       = 'mail.pioneercenter.co.ke';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'notifications@pioneercenter.co.ke';
        $mail->Password   = 'King20241064.';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        $mail->Port       = 465;
        $mail->SMTPOptions = ['ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true]];

        $mail->setFrom('notifications@pioneercenter.co.ke', 'Keychele Admin');
        $mail->addAddress($email);
        $mail->isHTML(true);
        $mail->Subject = "Your New Keychele Admin Login OTP";
        $mail->Body = "<div style='font-family:Arial;background:#f8fff7;padding:30px;text-align:center'>
            <h2>Your New OTP is: <strong style='font-size:48px;color:#2c5364'>$otp</strong></h2>
            <p>Valid for 10 minutes.</p>
        </div>";

        if ($mail->send()) {
            $success_message = "New OTP sent to $email!";
            error_log("Resend: Success - New OTP $otp sent to $email");
        } else {
            $error_message = "Failed to send email. Check server logs.";
            error_log("Resend: Mail failed - " . $mail->ErrorInfo);
        }
    } catch (Exception $e) {
        $error_message = "Resend failed: " . $e->getMessage();
        error_log("Resend error: " . $e->getMessage());
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Verify OTP | Keychele Admin</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="https://upload.wikimedia.org/wikipedia/commons/6/6b/University_icon.svg">
    <style>
        * { box-sizing: border-box; font-family: 'Inter', 'Segoe UI', sans-serif; }
        body {
            margin: 0; height: 100vh;
            background: linear-gradient(135deg, #0f2027, #203a43, #2c5364);
            display: flex; justify-content: center; align-items: center;
        }
        .otp-card {
            background: #ffffff; width: 100%; max-width: 420px; padding: 40px;
            border-radius: 14px; box-shadow: 0 30px 60px rgba(0,0,0,0.25); text-align: center;
        }
        .brand h2 { font-size: 1.6rem; color: #203a43; margin: 0; }
        .brand p { color: #666; }

        .alert {
            padding: 15px; margin: 20px 0; border-radius: 8px; font-size: 0.95rem;
        }
        .alert-error {
            background: #ffe1e1; color: #8a1f1f; border: 1px solid #ffb1b1;
        }
        .alert-success {
            background: #e1ffe1; color: #1f8a1f; border: 1px solid #b1ffb1;
        }

        .otp-boxes { display: flex; justify-content: center; gap: 12px; margin: 30px 0; }
        .otp-input {
            width: 60px; height: 70px; text-align: center; font-size: 32px; font-weight: bold;
            border: 2px solid #ddd; border-radius: 12px; outline: none;
        }
        .otp-input:focus { border-color: #2c5364; box-shadow: 0 0 0 3px rgba(44,83,100,0.15); }

        .btn {
            width: 100%; padding: 14px; background: #2c5364; color: white;
            border: none; border-radius: 8px; font-size: 1.1rem; cursor: pointer;
        }
        .btn:hover { background: #203a43; }

        .resend-link { color: #2c5364; font-weight: bold; text-decoration: none; }
        .timer { font-weight: bold; color: #e74c3c; margin: 15px 0; }
    </style>
</head>
<body>

<div class="otp-card">
    <div class="brand">
        <img src="https://upload.wikimedia.org/wikipedia/commons/6/6b/University_icon.svg" alt="Logo" width="70">
        <h2>Keychele Admin</h2>
        <p>Verify Your Identity</p>
    </div>

    <p>Enter the 4-digit code sent to:<br><strong><?= htmlspecialchars($email) ?></strong></p>

    <div class="timer" id="timer">Time remaining: 10:00</div>

    <?php if (!empty($error_message)): ?>
        <div class="alert alert-error"><?= nl2br($error_message) ?></div>
    <?php endif; ?>

    <?php if (!empty($success_message)): ?>
        <div class="alert alert-success"><?= $success_message ?></div>
    <?php endif; ?>

    <form method="post" id="verifyForm">
        <div class="otp-boxes">
            <input type="text" name="otp1" class="otp-input" maxlength="1" required autocomplete="off">
            <input type="text" name="otp2" class="otp-input" maxlength="1" required autocomplete="off">
            <input type="text" name="otp3" class="otp-input" maxlength="1" required autocomplete="off">
            <input type="text" name="otp4" class="otp-input" maxlength="1" required autocomplete="off">
        </div>
        <button type="submit" name="verify_otp" class="btn">Verify OTP</button>
    </form>

    <p style="margin-top: 25px;">
        Didn't receive it? 
        <form method="post" style="display:inline;">
            <button type="submit" name="resend_otp" class="resend-link">Resend OTP</button>
        </form>
    </p>
</div>

<script src="https://cdn.jsdelivr.net/npm/jquery@3.6.0/dist/jquery.min.js"></script>
<script>
// Auto-focus next input
$('.otp-input').on('input', function() {
    this.value = this.value.replace(/[^0-9]/g, '');
    if (this.value) $(this).next('.otp-input').focus();
}).on('keydown', function(e) {
    if (e.key === 'Backspace' && !this.value) {
        $(this).prev('.otp-input').focus();
    }
});

// Timer
let timeLeft = 600;
const timer = document.getElementById('timer');
setInterval(() => {
    if (timeLeft > 0) {
        timeLeft--;
        const m = Math.floor(timeLeft / 60);
        const s = timeLeft % 60;
        timer.textContent = `Time remaining: ${m}:${s.toString().padStart(2, '0')}`;
    } else {
        timer.textContent = "OTP Expired";
        timer.style.color = '#c0392b';
    }
}, 1000);
</script>

</body>
</html>