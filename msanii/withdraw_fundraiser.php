<?php
// ────────────────────────────────────────────────────────────────────────────
// withdraw_fundraiser.php — Per-fundraiser withdrawal. Same 2FA (PIN +
// MecroGuard SMS) flow as request_withdrawals.php, but the balance comes
// from this specific campaign's wallet_donations row instead of the
// creator's pooled my_balance — so a creator can withdraw one fundraiser's
// donations without touching their ticket-sales wallet or any other
// fundraiser's funds.
// ────────────────────────────────────────────────────────────────────────────

// ── 1. OUTPUT BUFFER – must be FIRST LINE of logic ──────────────────────────
ob_start();

// ── 2. SESSION GUARD ─────────────────────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require '../vendor/autoload.php';
include 'includes/connect.php';

// ── 3. ROBUST AJAX DETECTION ─────────────────────────────────────────────────
$isAjax = (
    (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
     strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
    ||
    (isset($_POST['step1']) || isset($_POST['step2']) || isset($_POST['step3']))
    ||
    (isset($_GET['reset']) || isset($_GET['resend_otp']))
);

// ── SAFE JSON RESPONSE HELPER ─────────────────────────────────────────────────
function jsonResponse(array $data): void {
    ob_clean();
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// ── TABLES ────────────────────────────────────────────────────────────────────
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS creatorwithdrawal_sms_settings (
            id           INT AUTO_INCREMENT PRIMARY KEY,
            provider     VARCHAR(50)  NOT NULL DEFAULT 'talksasa',
            api_id       VARCHAR(100) NOT NULL,
            api_token    VARCHAR(255) NOT NULL,
            sender_name  VARCHAR(50)  NOT NULL DEFAULT 'TALKSASA',
            api_endpoint VARCHAR(255) NOT NULL DEFAULT 'https://bulksms.talksasa.com/api/v3/sms/send',
            created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )
    ");

    // Credentials are configured in Admin01 → Settings → SMS Settings —
    // no longer seeded from source code.

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS withdrawal_otp (
            id          INT AUTO_INCREMENT PRIMARY KEY,
            creator_id  INT         NOT NULL,
            otp_code    VARCHAR(10) NOT NULL,
            expires_at  DATETIME    NOT NULL,
            used        TINYINT(1)  NOT NULL DEFAULT 0,
            created_at  TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_creator (creator_id)
        )
    ");
} catch (PDOException $e) { /* tables already exist – ignore */ }

// Self-healing: withdrawal_requests needs to know which fundraiser (if any)
// a request is against, so the admin approval flow can debit the right
// balance (wallet_donations for a fundraiser vs my_balance for tickets).
try {
    $col = $pdo->query("SHOW COLUMNS FROM withdrawal_requests LIKE 'campaign_id'")->fetchAll();
    if (empty($col)) {
        $pdo->exec("ALTER TABLE withdrawal_requests ADD COLUMN campaign_id INT UNSIGNED NULL DEFAULT NULL AFTER creator_id, ADD INDEX idx_campaign (campaign_id)");
    }
} catch (Throwable $e) {}

// Self-healing: platform_fee is kept separate from `charge` (the pure
// pass-through Safaricom M-Pesa fee) so admin/email/SMS copy never mislabels
// the platform's own cut as an "M-Pesa fee".
try {
    $col = $pdo->query("SHOW COLUMNS FROM withdrawal_requests LIKE 'platform_fee'")->fetchAll();
    if (empty($col)) {
        $pdo->exec("ALTER TABLE withdrawal_requests ADD COLUMN platform_fee DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER charge");
    }
} catch (Throwable $e) {}

// Platform's cut of every fundraiser withdrawal, on top of the Safaricom charge.
const WITHDRAW_PLATFORM_FEE_RATE = 0.035;

// Self-healing: platform_fee kept separate from `charge` (the pass-through
// Safaricom charge) so the two are never conflated in admin reporting/emails.
try {
    $col = $pdo->query("SHOW COLUMNS FROM withdrawal_requests LIKE 'platform_fee'")->fetchAll();
    if (empty($col)) {
        $pdo->exec("ALTER TABLE withdrawal_requests ADD COLUMN platform_fee DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER charge");
    }
} catch (Throwable $e) {}

// Fundraiser withdrawals only (not the general ticket-sales withdrawal flow)
// carry a 3.5% platform fee on top of the Safaricom transfer charge.
const KC_FUNDRAISER_PLATFORM_FEE_RATE = 0.035;

// ── SESSION CHECK ─────────────────────────────────────────────────────────────
$creator_id = $_SESSION['creator_id'] ?? null;
if (!$creator_id) {
    if ($isAjax) {
        jsonResponse([
            'ok'       => false,
            'error'    => 'Session expired. Please log in again.',
            'redirect' => 'login.php',
        ]);
    }
    header('Location: login.php');
    exit;
}

// ── FETCH + VERIFY FUNDRAISER (must belong to this creator) ───────────────────
$campaign_id = (int)($_GET['campaign'] ?? 0);
if ($campaign_id <= 0) {
    if ($isAjax) jsonResponse(['ok' => false, 'error' => 'Missing fundraiser.']);
    die("<h1 style='text-align:center;margin-top:5rem;'>Missing fundraiser</h1>");
}
$campStmt = $pdo->prepare("SELECT id, title FROM fundraiser_campaigns WHERE id = ? AND creator_id = ?");
$campStmt->execute([$campaign_id, $creator_id]);
$campaignRow = $campStmt->fetch(PDO::FETCH_ASSOC);
if (!$campaignRow) {
    if ($isAjax) jsonResponse(['ok' => false, 'error' => 'Fundraiser not found.']);
    die("<h1 style='text-align:center;margin-top:5rem;'>Fundraiser not found</h1>");
}
$campaignTitle = $campaignRow['title'];

// ── CONSTANTS ─────────────────────────────────────────────────────────────────
$full_name       = '';
$email           = '';
$phone           = '';
$current_balance = 0.00;
$mpesa_number    = '';
$has_pin         = false;
$success = $error = '';

$charge_ranges = [
    ['min'=>1,    'max'=>49,    'charge'=>0],
    ['min'=>50,   'max'=>100,   'charge'=>11],
    ['min'=>101,  'max'=>500,   'charge'=>29],
    ['min'=>501,  'max'=>1000,  'charge'=>29],
    ['min'=>1001, 'max'=>1500,  'charge'=>29],
    ['min'=>1501, 'max'=>2500,  'charge'=>29],
    ['min'=>2501, 'max'=>3500,  'charge'=>52],
    ['min'=>3501, 'max'=>5000,  'charge'=>69],
    ['min'=>5001, 'max'=>7500,  'charge'=>87],
    ['min'=>7501, 'max'=>10000, 'charge'=>115],
    ['min'=>10001,'max'=>15000, 'charge'=>167],
    ['min'=>15001,'max'=>20000, 'charge'=>185],
    ['min'=>20001,'max'=>35000, 'charge'=>197],
    ['min'=>35001,'max'=>50000, 'charge'=>278],
    ['min'=>50001,'max'=>250000,'charge'=>309],
];

function getCharge(float $n, array $r): ?float {
    foreach ($r as $range) {
        if ($n >= $range['min'] && $n <= $range['max']) return $range['charge'];
    }
    return null;
}

// ── FETCH CREATOR ─────────────────────────────────────────────────────────────
$creator = null;
try {
    $stmt = $pdo->prepare("SELECT full_name, email, phone, withdrawal_pin FROM creators WHERE id = ?");
    $stmt->execute([$creator_id]);
    $creator = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($creator) {
        $creator = kc_decrypt_row($creator, ['full_name', 'email', 'phone']);
        $full_name = $creator['full_name'] ?? 'Unknown';
        $email     = $creator['email']     ?? '';
        $phone     = $creator['phone']     ?? '';
        $has_pin   = !empty($creator['withdrawal_pin']);
    } else {
        $error = "Creator profile not found.";
        if ($isAjax) jsonResponse(['ok' => false, 'error' => $error]);
    }
} catch (PDOException $e) {
    $error = "Error fetching profile: " . $e->getMessage();
    if ($isAjax) jsonResponse(['ok' => false, 'error' => $error]);
}

// ── FETCH BALANCE (this fundraiser's own wallet, not the pooled my_balance) ──
try {
    $stmt = $pdo->prepare("SELECT amount FROM wallet_donations WHERE campaign_id = ? AND creator_id = ? LIMIT 1");
    $stmt->execute([$campaign_id, $creator_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $current_balance = $row ? (float)$row['amount'] : 0.00;
} catch (PDOException $e) {
    $error = "Error fetching balance: " . $e->getMessage();
    if ($isAjax) jsonResponse(['ok' => false, 'error' => $error]);
}

// ── PHONE NORMALIZER ──────────────────────────────────────────────────────────
// Supports: 07XXXXXXXX, 01XXXXXXXX, +2547XXXXXXXX, +2541XXXXXXXX,
//           2547XXXXXXXX, 2541XXXXXXXX (and any leading zeros stripped)
function normalizePhone(string $phone): string {
    $digits = preg_replace('/\D/', '', $phone);
    if (preg_match('/^254[17]\d{8}$/', $digits)) {
        return $digits;
    }
    if (preg_match('/^0[17]\d{8}$/', $digits)) {
        return '254' . substr($digits, 1);
    }
    if (preg_match('/^[17]\d{8}$/', $digits)) {
        return '254' . $digits;
    }
    return $digits;
}

// ── SMS HELPER ────────────────────────────────────────────────────────────────
function sendMecroGuardSMS(PDO $pdo, string $phone, string $code, string $name): array {
    try {
        $sms = $pdo->query(
            "SELECT * FROM creatorwithdrawal_sms_settings WHERE provider='talksasa' LIMIT 1"
        )->fetch(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        $msg = "DB error loading SMS settings: " . $e->getMessage();
        error_log("MecroGuard: $msg");
        return ['ok' => false, 'reason' => "SMS configuration could not be loaded. Contact support."];
    }

    if (!$sms) {
        error_log("MecroGuard: no row in creatorwithdrawal_sms_settings");
        return ['ok' => false, 'reason' => "SMS gateway is not configured. Please contact the administrator."];
    }

    $rawPhone = $phone;
    $phone    = normalizePhone($phone);

    if (!preg_match('/^254[17]\d{8}$/', $phone)) {
        $msg = "Phone '{$rawPhone}' normalized to '{$phone}' — not a valid Kenyan mobile number.";
        error_log("MecroGuard: $msg");
        return [
            'ok'     => false,
            'reason' => "Your registered phone number ({$rawPhone}) does not appear to be a valid Kenyan mobile number. "
                      . "Please update your profile using format 07XXXXXXXX, 01XXXXXXXX, +2547XXXXXXXX, or +2541XXXXXXXX.",
        ];
    }

    $url    = rtrim($sms['api_endpoint'], '/');
    $token  = trim($sms['api_token']);
    $sender = trim($sms['sender_name']);

    if (empty($token)) {
        error_log("MecroGuard: api_token is empty in DB");
        return ['ok' => false, 'reason' => "SMS API token is missing. Contact the administrator."];
    }

    $message = "Hello {$name}, your MecroGuard withdrawal code is: {$code}. "
             . "Valid for 10 minutes. Do NOT share this code.";

    $payload = json_encode([
        'recipient' => $phone,
        'sender_id' => $sender,
        'type'      => 'plain',
        'message'   => $message,
    ]);

    if (!function_exists('curl_init')) {
        error_log("MecroGuard: cURL extension not available");
        return ['ok' => false, 'reason' => "Server cURL extension is disabled. Contact the hosting provider."];
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Accept: application/json',
            "Authorization: Bearer {$token}",
        ],
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_FOLLOWLOCATION => true,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr) {
        error_log("MecroGuard: cURL failed reaching $url: $curlErr");
        return ['ok' => false, 'reason' => "Could not reach the SMS gateway ($curlErr). Check server internet access or firewall rules."];
    }

    if ($response === false || $response === '') {
        error_log("MecroGuard: empty response from $url (HTTP $httpCode)");
        return ['ok' => false, 'reason' => "SMS gateway returned an empty response (HTTP $httpCode). The service may be down."];
    }

    error_log("MecroGuard SMS → $url | phone: $phone | HTTP $httpCode | resp: $response");

    $result = json_decode($response, true);

    if ($httpCode === 401 || $httpCode === 403) {
        return ['ok' => false, 'reason' => "SMS gateway rejected the API token (HTTP $httpCode). The token may be expired — contact the administrator."];
    }

    if ($httpCode === 422) {
        $apiMsg = $result['message'] ?? ($result['error'] ?? 'Validation error');
        return ['ok' => false, 'reason' => "SMS gateway rejected the request: $apiMsg. Likely cause: invalid sender ID or phone format."];
    }

    if (!in_array($httpCode, [200, 201, 202])) {
        $apiMsg = $result['message'] ?? ($result['error'] ?? "HTTP $httpCode");
        return ['ok' => false, 'reason' => "SMS gateway error: $apiMsg (HTTP $httpCode)."];
    }

    if (isset($result['status'])) {
        $st = strtolower((string)$result['status']);
        if (in_array($st, ['error', 'failed', 'fail'])) {
            $apiMsg = $result['message'] ?? ($result['error'] ?? $st);
            return ['ok' => false, 'reason' => "SMS gateway reported failure: $apiMsg. Check your TalkSasa account balance and sender ID approval."];
        }
    }

    return ['ok' => true, 'reason' => 'SMS sent successfully.'];
}

// ── STEP TRACKING (namespaced + campaign-pinned so this never collides with
// the general request_withdrawals.php flow, or with a different fundraiser) ──
if (!isset($_SESSION['wdf_step']) || (int)($_SESSION['wdf_campaign'] ?? 0) !== $campaign_id) {
    $_SESSION['wdf_step']     = 1;
    $_SESSION['wdf_campaign'] = $campaign_id;
    unset($_SESSION['wdf_data'], $_SESSION['wdf_masked_phone'], $_SESSION['wdf_sms_sent']);
}
$step = (int)$_SESSION['wdf_step'];

// ── RESET ─────────────────────────────────────────────────────────────────────
if (isset($_GET['reset'])) {
    unset($_SESSION['wdf_data'], $_SESSION['wdf_masked_phone'], $_SESSION['wdf_sms_sent']);
    $_SESSION['wdf_step'] = 1;
    if ($isAjax) {
        jsonResponse(['ok' => true, 'step' => 1, 'reload' => true]);
    }
    header("Location: withdraw_fundraiser.php?campaign=$campaign_id");
    exit;
}

// ── RESEND OTP ────────────────────────────────────────────────────────────────
if (isset($_GET['resend_otp']) && $step === 3) {
    $otp_code = str_pad(random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
    $pdo->prepare("UPDATE withdrawal_otp SET used=1 WHERE creator_id=? AND used=0")->execute([$creator_id]);
    $pdo->prepare("INSERT INTO withdrawal_otp (creator_id,otp_code,expires_at) VALUES (?,?,DATE_ADD(NOW(), INTERVAL 10 MINUTE))")
        ->execute([$creator_id, $otp_code]);
    $smsResult = sendMecroGuardSMS($pdo, $phone, $otp_code, $full_name);
    $sent      = $smsResult['ok'];

    if ($isAjax) {
        jsonResponse([
            'ok'      => $sent,
            'step'    => 3,
            'success' => $sent ? 'A new MecroGuard code has been sent to your phone.' : '',
            'error'   => $sent ? '' : 'Failed to resend: ' . $smsResult['reason'],
        ]);
    }
    $success = $sent ? 'A new MecroGuard code has been sent.' : 'Failed to resend. Please try again.';
}

// ── POST HANDLER ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($error)) {

    /* ── STEP 1 : Withdrawal details ─────────────────────────────────────── */
    if ($step === 1 && isset($_POST['step1'])) {
        if (!$has_pin) {
            $error = "You have not set a withdrawal PIN. <a href='index.php?page=withdrawal2factorauth' style='text-decoration:underline;font-weight:600'>Set your PIN first</a>.";
        } elseif (empty($phone)) {
            $error = "No phone number registered. Please update your profile before withdrawing.";
        } else {
            $net_amount   = floatval($_POST['amount']      ?? 0);
            $mpesa_number = trim($_POST['mpesa_number']   ?? '');
            $mpesa_name   = trim($_POST['mpesa_name']     ?? '');
            $charge       = getCharge($net_amount, $charge_ranges);
            $platform_fee = round($net_amount * WITHDRAW_PLATFORM_FEE_RATE, 2);

            if (empty($mpesa_number)) {
                $error = "M-Pesa phone number is required.";
            } elseif (empty($mpesa_name)) {
                $error = "M-Pesa account name is required.";
            } elseif ($net_amount <= 0) {
                $error = "Amount must be greater than 0.";
            } elseif ($charge === null) {
                $error = "Amount is outside supported withdrawal ranges.";
            } else {
                $total_deduct = $net_amount + $charge + $platform_fee;
                if ($total_deduct > $current_balance) {
                    $error = "Insufficient fundraiser balance. You need KES " . number_format($total_deduct, 2)
                           . " but this fundraiser has KES " . number_format($current_balance, 2) . " available.";
                } else {
                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM withdrawal_requests WHERE creator_id=? AND campaign_id=? AND status='pending'");
                    $stmt->execute([$creator_id, $campaign_id]);
                    if ($stmt->fetchColumn() > 0) {
                        $error = "You already have a pending withdrawal for this fundraiser. Please wait for it to be processed.";
                    } else {
                        $_SESSION['wdf_data'] = compact('net_amount', 'mpesa_number', 'mpesa_name', 'charge', 'platform_fee', 'total_deduct');
                        $_SESSION['wdf_step'] = 2;
                        $step = 2;
                    }
                }
            }
        }

        if ($isAjax) {
            jsonResponse([
                'ok'      => empty($error),
                'step'    => $step,
                'error'   => $error,
                'wd_data' => $_SESSION['wdf_data'] ?? null,
            ]);
        }
    }

    /* ── STEP 2 : PIN verification ───────────────────────────────────────── */
    elseif ($step === 2 && isset($_POST['step2'])) {
        $entered_pin = trim($_POST['withdrawal_pin'] ?? '');
        if (empty($entered_pin)) {
            $error = "Please enter your withdrawal PIN.";
        } elseif (!$creator || !password_verify($entered_pin, $creator['withdrawal_pin'])) {
            $error = "Incorrect PIN. Please try again.";
        } else {
            $otp_code = str_pad(random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
            $pdo->prepare("UPDATE withdrawal_otp SET used=1 WHERE creator_id=? AND used=0")->execute([$creator_id]);
            $pdo->prepare("INSERT INTO withdrawal_otp (creator_id,otp_code,expires_at) VALUES (?,?,DATE_ADD(NOW(), INTERVAL 10 MINUTE))")
                ->execute([$creator_id, $otp_code]);

            $smsResult = sendMecroGuardSMS($pdo, $phone, $otp_code, $full_name);
            $sms_sent  = $smsResult['ok'];

            if (!$sms_sent) {
                $error = "Could not send MecroGuard SMS. Reason: " . $smsResult['reason'];
                if ($isAjax) jsonResponse(['ok' => false, 'step' => 2, 'error' => $error]);
            } else {
                $normalizedForMask = normalizePhone($phone);
                $masked = substr($normalizedForMask, 0, -6) . str_repeat('*', 4) . substr($normalizedForMask, -2);
                $_SESSION['wdf_masked_phone'] = $masked;
                $_SESSION['wdf_sms_sent']     = true;
                $_SESSION['wdf_step']         = 3;
                $step = 3;

                if ($isAjax) {
                    jsonResponse([
                        'ok'           => true,
                        'step'         => 3,
                        'masked_phone' => $masked,
                        'wd_data'      => $_SESSION['wdf_data'] ?? null,
                    ]);
                }
            }
        }

        if ($isAjax && !empty($error)) {
            jsonResponse(['ok' => false, 'step' => $step, 'error' => $error]);
        }
    }

    /* ── STEP 3 : OTP verification ───────────────────────────────────────── */
    elseif ($step === 3 && isset($_POST['step3'])) {
        $entered_otp = trim($_POST['otp_code'] ?? '');
        $wd          = $_SESSION['wdf_data']   ?? [];

        if (empty($entered_otp)) {
            $error = "Please enter the MecroGuard code sent to your phone.";
        } elseif (empty($wd)) {
            $error = "Session expired. Please start over.";
            $_SESSION['wdf_step'] = 1;
            $step = 1;
        } else {
            $stmt = $pdo->prepare("
                SELECT id FROM withdrawal_otp
                WHERE creator_id=? AND otp_code=? AND used=0 AND expires_at > NOW()
                ORDER BY id DESC LIMIT 1
            ");
            $stmt->execute([$creator_id, $entered_otp]);
            $otp_row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$otp_row) {
                $error = "Invalid or expired MecroGuard code. Please try again.";
            } else {
                $pdo->prepare("UPDATE withdrawal_otp SET used=1 WHERE id=?")->execute([$otp_row['id']]);
                try {
                    $pdo->beginTransaction();
                    $notes = "Fundraiser: {$campaignTitle} (#{$campaign_id}) | Net: {$wd['net_amount']} | Safaricom Fee: {$wd['charge']} | Platform Fee (3.5%): {$wd['platform_fee']} | M-Pesa Name: " . ($wd['mpesa_name'] ?? '') . " | 2FA: PIN+OTP";
                    $stmt  = $pdo->prepare("
                        INSERT INTO withdrawal_requests
                        (creator_id,campaign_id,mpesa_number,amount,charge,platform_fee,total_amount,status,requested_at,notes)
                        VALUES (?,?,?,?,?,?,?,'pending',NOW(),?)
                    ");
                    $stmt->execute([
                        $creator_id,
                        $campaign_id,
                        kc_encrypt($wd['mpesa_number']),
                        $wd['net_amount'],
                        $wd['charge'],
                        $wd['platform_fee'],
                        $wd['total_deduct'],
                        kc_encrypt($notes),
                    ]);
                    $pdo->commit();

                    $success = "Withdrawal request submitted successfully!<br>"
                             . "→ Fundraiser: <strong>" . htmlspecialchars($campaignTitle) . "</strong><br>"
                             . "→ You will receive: <strong>KES " . number_format($wd['net_amount'], 2) . "</strong><br>"
                             . "→ Platform fee (3.5%): KES " . number_format($wd['platform_fee'], 2) . "<br>"
                             . "→ M-Pesa fee: KES " . number_format($wd['charge'], 2) . "<br>"
                             . "→ Total deducted: <strong>KES " . number_format($wd['total_deduct'], 2) . "</strong><br>"
                             . "→ Processing within 24 hours.";

                    // Send notification emails (non-blocking)
                    try {
                        $mail = new PHPMailer(true);
                        $mail->isSMTP();
                        $mail->Host        = 'mail.keychele.co.ke';
                        $mail->SMTPAuth    = true;
                        $mail->Username    = 'notifications@keychele.co.ke';
                        $mail->Password    = 'King20241064.';
                        $mail->SMTPSecure  = PHPMailer::ENCRYPTION_STARTTLS;
                        $mail->Port        = 587;
                        $mail->SMTPOptions = ['ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true]];
                        $mail->setFrom('notifications@keychele.co.ke', 'Keychele');
                        $mail->CharSet = 'UTF-8';
                        $mail->isHTML(true);

                        $creatorHtml = "<div style='font-family:Arial;max-width:600px;padding:25px;'>
                          <h2 style='color:#032d5d;'>Fundraiser Withdrawal Request Received</h2>
                          <p>Hello <strong>" . htmlspecialchars($full_name) . "</strong>,</p>
                          <ul>
                            <li>Fundraiser: " . htmlspecialchars($campaignTitle) . "</li>
                            <li>Amount to receive: KES " . number_format($wd['net_amount'], 2) . "</li>
                            <li>Platform fee (3.5%): KES " . number_format($wd['platform_fee'], 2) . "</li>
                            <li>M-Pesa fee: KES " . number_format($wd['charge'], 2) . "</li>
                            <li>Total deducted: KES " . number_format($wd['total_deduct'], 2) . "</li>
                            <li>To: " . htmlspecialchars($wd['mpesa_number']) . "</li>
                          </ul>
                          <p style='color:#032d5d;font-weight:bold;'>Processing within 24 hours.</p>
                        </div>";

                        $mail->addAddress($email, $full_name);
                        $mail->Subject = "Fundraiser Withdrawal Request Received";
                        $mail->Body    = $creatorHtml;
                        $mail->send();

                        $mail->clearAddresses();
                        $admin_email = $pdo->query("SELECT email FROM admin LIMIT 1")->fetchColumn();
                        if ($admin_email) {
                            $adminHtml = "<div style='font-family:Arial;max-width:600px;padding:25px;'>
                              <h2 style='color:#032d5d;'>New Fundraiser Withdrawal – 2FA Verified</h2>
                              <p><strong>Creator:</strong> " . htmlspecialchars($full_name) . " ({$email})</p>
                              <p><strong>Fundraiser:</strong> " . htmlspecialchars($campaignTitle) . " (#{$campaign_id})</p>
                              <p><strong>Receive:</strong> KES " . number_format($wd['net_amount'], 2) . "</p>
                              <p><strong>Platform Fee (3.5%):</strong> KES " . number_format($wd['platform_fee'], 2) . "</p>
                              <p><strong>Safaricom Fee:</strong> KES " . number_format($wd['charge'], 2) . "</p>
                              <p><strong>Total deduct:</strong> KES " . number_format($wd['total_deduct'], 2) . "</p>
                              <p><strong>M-Pesa:</strong> " . htmlspecialchars($wd['mpesa_number']) . "</p>
                            </div>";
                            $mail->addAddress($admin_email);
                            $mail->Subject = "New Fundraiser Withdrawal – " . htmlspecialchars($full_name);
                            $mail->Body = $adminHtml;
                            $mail->send();
                        }
                    } catch (Exception $e) {
                        error_log("Email error: " . $mail->ErrorInfo);
                    }

                    unset($_SESSION['wdf_data'], $_SESSION['wdf_masked_phone'], $_SESSION['wdf_sms_sent']);
                    $_SESSION['wdf_step'] = 4;
                    $step = 4;

                    if ($isAjax) {
                        jsonResponse(['ok' => true, 'step' => 4, 'success' => $success]);
                    }

                } catch (PDOException $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    $error = "Database error: " . htmlspecialchars($e->getMessage());
                }
            }
        }

        if ($isAjax && !empty($error)) {
            jsonResponse(['ok' => false, 'step' => $step, 'error' => $error]);
        }
    }
}

$wd_session = $_SESSION['wdf_data'] ?? [];
// When loaded through the dashboard router (index.php?page=withdraw_fundraiser),
// that shell already provides its own <!DOCTYPE>/<html>/<head>/<body> — a
// second nested set breaks the DOM (browsers merge/ignore the duplicates in
// ways that can silently swallow this page's content). Only emit our own
// document wrapper when this file is opened directly.
$wdStandalone = empty($kc_in_router);
?>
<?php if ($wdStandalone): ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Withdraw Fundraiser Funds — Keychele</title>
<?php endif; ?>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <style>
    :root {
      --navy:        #052e64;
      --navy-light:  #7c2fb0;
      --crimson:     #b81236;
      --surface:     #ffffff;
      --bg:          #f0f4fa;
      --border:      #e2e8f0;
      --text-pri:    #0f172a;
      --text-sec:    #475569;
      --text-muted:  #94a3b8;
      --green:       #059669;
      --green-bg:    #d1fae5;
      --blue:        #2563eb;
      --blue-bg:     #dbeafe;
      --indigo:      #4f46e5;
      --indigo-bg:   #e0e7ff;
      --amber:       #d97706;
      --amber-bg:    #fef3c7;
      --red:         #dc2626;
      --red-bg:      #fee2e2;
      --purple:      #7c2fb0;
      --purple-bg:   #ede9fe;
      --teal:        #0d9488;
      --teal-bg:     #ccfbf1;
      --gold:        #f59e0b;
      --radius-sm:   8px;
      --radius-md:   14px;
      --radius-lg:   20px;
      --shadow-sm:   0 1px 4px rgba(3,45,93,.07);
      --shadow-md:   0 4px 20px rgba(3,45,93,.10);
      --shadow-lg:   0 12px 40px rgba(3,45,93,.14);
      --transition:  all .22s cubic-bezier(.4,0,.2,1);
    }
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      font-family: 'Sora', sans-serif;
      background: var(--bg);
      color: var(--text-pri);
      -webkit-font-smoothing: antialiased;
      min-height: 100vh;
    }

    #preloader {
      position: fixed; inset: 0; z-index: 9999;
      display: flex; align-items: center; justify-content: center;
      background: rgba(3,45,93,.55); backdrop-filter: blur(6px);
      transition: opacity .4s ease;
    }
    .preloader-box {
      background: #fff; border-radius: var(--radius-lg);
      padding: 28px 40px; display: flex; align-items: center; gap: 14px;
      box-shadow: var(--shadow-lg);
    }
    .spin {
      width: 22px; height: 22px; border: 3px solid var(--border);
      border-top-color: var(--crimson); border-radius: 50%;
      animation: spin .75s linear infinite; flex-shrink: 0;
    }
    @keyframes spin { to { transform: rotate(360deg); } }
    .preloader-box span { font-size: .9rem; font-weight: 600; color: var(--text-sec); }

    #step-loader {
      display: none;
      position: absolute; inset: 0; z-index: 20;
      background: rgba(255,255,255,.82);
      backdrop-filter: blur(3px);
      border-radius: var(--radius-lg);
      align-items: center; justify-content: center;
      flex-direction: column; gap: 10px;
    }
    #step-loader.active { display: flex; }
    #step-loader .spin { width: 30px; height: 30px; border-width: 4px; }
    #step-loader span   { font-size: .8rem; font-weight: 600; color: var(--text-sec); }

    .topbar {
      background: rgba(255,255,255,.97);
      backdrop-filter: blur(12px);
      border-bottom: 1px solid var(--border);
      padding: 12px 24px;
      display: flex; align-items: center; justify-content: space-between;
      position: sticky; top: 0; z-index: 40;
      box-shadow: var(--shadow-sm);
      gap: 12px; flex-wrap: wrap;
    }
    .topbar-brand {
      display: inline-flex; align-items: center; gap: 10px;
      text-decoration: none; color: var(--navy); font-weight: 800;
      font-size: 1rem; letter-spacing: -.02em;
    }
    .topbar-brand-icon {
      width: 34px; height: 34px; border-radius: 10px;
      background: var(--navy); display: flex; align-items: center; justify-content: center;
      font-size: .75rem; color: #fec411;
    }
    .topbar-actions { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
    .action-btn {
      display: inline-flex; align-items: center; gap: 7px;
      padding: 9px 16px; border-radius: var(--radius-sm);
      font-family: 'Sora', sans-serif; font-size: .78rem; font-weight: 600;
      cursor: pointer; border: none; text-decoration: none;
      transition: var(--transition); white-space: nowrap; letter-spacing: .01em;
    }
    .action-btn i { font-size: .7rem; }
    .btn-navy    { background: var(--navy);    color: #fff; }
    .btn-navy:hover    { background: var(--navy-light); box-shadow: 0 4px 14px rgba(3,45,93,.3); transform: translateY(-1px); }
    .btn-crimson { background: var(--crimson); color: #fff; }
    .btn-crimson:hover { background: #9f0f2e; box-shadow: 0 4px 14px rgba(184,18,54,.3); transform: translateY(-1px); }
    .btn-outline { background: transparent; color: var(--navy); border: 1.5px solid var(--navy); }
    .btn-outline:hover { background: var(--navy); color: #fff; }

    .page-wrap { max-width: 860px; margin: 0 auto; padding: 24px 20px 96px; }
    @media (min-width: 768px) { .page-wrap { padding: 28px 28px 48px; } }

    .hero {
      position: relative; overflow: hidden;
      background: linear-gradient(130deg, var(--navy) 0%, #7c2fb0 100%);
      border-radius: var(--radius-lg); padding: 32px 36px; margin-bottom: 24px;
      box-shadow: var(--shadow-lg);
    }
    .hero::before {
      content: '';
      position: absolute; inset: 0;
      background: url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%23ffffff' fill-opacity='0.04'%3E%3Ccircle cx='30' cy='30' r='20'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E") repeat;
      pointer-events: none;
    }
    .hero-inner {
      position: relative; z-index: 1;
      display: flex; justify-content: space-between; align-items: flex-start;
      flex-wrap: wrap; gap: 16px;
    }
    .hero-eyebrow {
      display: inline-flex; align-items: center; gap: 7px;
      background: rgba(255,255,255,.15); border: 1px solid rgba(255,255,255,.2);
      backdrop-filter: blur(8px); border-radius: 999px;
      padding: 4px 14px; font-size: .72rem; font-weight: 600;
      color: #fff; letter-spacing: .05em; text-transform: uppercase; margin-bottom: 10px;
    }
    .hero-title { font-size: clamp(1.15rem, 3vw, 1.85rem); font-weight: 800; color: #fff; letter-spacing: -.02em; line-height: 1.2; }
    .hero-sub   { font-size: .83rem; color: rgba(255,255,255,.7); margin-top: 6px; }
    .hero-right { text-align: right; }
    .hero-balance-val   { font-family: 'JetBrains Mono', monospace; font-size: 1.7rem; font-weight: 700; color: #fff; line-height: 1; }
    .hero-balance-label { font-size: .75rem; color: rgba(255,255,255,.6); margin-top: 4px; }

    .stats-grid {
      display: grid; grid-template-columns: repeat(2, 1fr);
      gap: 14px; margin-bottom: 24px;
    }
    @media (min-width: 640px) { .stats-grid { grid-template-columns: repeat(4, 1fr); } }
    .stat-card {
      background: var(--surface); border-radius: var(--radius-md);
      padding: 18px 16px; border: 1px solid var(--border);
      box-shadow: var(--shadow-sm); position: relative; overflow: hidden;
      transition: var(--transition);
    }
    .stat-card:hover { transform: translateY(-3px); box-shadow: var(--shadow-md); }
    .stat-card::before {
      content: ''; position: absolute; top: 0; left: 0; right: 0; height: 3px;
      border-radius: var(--radius-md) var(--radius-md) 0 0;
    }
    .stat-card.sc-blue::before   { background: var(--blue); }
    .stat-card.sc-green::before  { background: var(--green); }
    .stat-card.sc-amber::before  { background: var(--amber); }
    .stat-card.sc-purple::before { background: var(--purple); }
    .stat-label { font-size: .68rem; font-weight: 700; text-transform: uppercase; letter-spacing: .07em; color: var(--text-muted); margin-bottom: 8px; }
    .stat-value { font-size: 1.35rem; font-weight: 800; letter-spacing: -.03em; color: var(--text-pri); line-height: 1; font-family: 'JetBrains Mono', monospace; }
    .stat-icon-chip {
      margin-top: 12px; width: 34px; height: 34px; border-radius: 9px;
      display: flex; align-items: center; justify-content: center; font-size: .85rem;
    }
    .stat-card.sc-blue   .stat-icon-chip { background: var(--blue-bg);   color: var(--blue); }
    .stat-card.sc-green  .stat-icon-chip { background: var(--green-bg);  color: var(--green); }
    .stat-card.sc-amber  .stat-icon-chip { background: var(--amber-bg);  color: var(--amber); }
    .stat-card.sc-purple .stat-icon-chip { background: var(--purple-bg); color: var(--purple); }

    .panel {
      background: var(--surface); border-radius: var(--radius-lg);
      border: 1px solid var(--border); box-shadow: var(--shadow-md);
      overflow: hidden; margin-bottom: 24px;
      position: relative;
    }
    .panel-header {
      padding: 20px 28px; border-bottom: 1px solid var(--border);
      background: #fafbfe; display: flex; align-items: center;
      justify-content: space-between; flex-wrap: wrap; gap: 14px;
    }
    .panel-title { font-size: 1rem; font-weight: 700; color: var(--text-pri); display: flex; align-items: center; gap: 10px; }
    .panel-title i { color: var(--navy); }
    .panel-body { padding: 24px 28px; }

    #step-wizard {
      display: flex; align-items: center;
      padding: 18px 28px; border-bottom: 1px solid var(--border); background: #fafbfe;
    }
    .step-item { display: flex; flex-direction: column; align-items: center; gap: 5px; flex: 0 0 auto; }
    .step-num {
      width: 36px; height: 36px; border-radius: 50%;
      display: flex; align-items: center; justify-content: center;
      font-size: .82rem; font-weight: 800; border: 2px solid var(--border);
      background: var(--surface); color: var(--text-muted); transition: var(--transition);
    }
    .step-num.active  { background: var(--navy); color: #fff; border-color: var(--navy); }
    .step-num.done    { background: var(--green); color: #fff; border-color: var(--green); }
    .step-label { font-size: .65rem; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; color: var(--text-muted); white-space: nowrap; }
    .step-label.active { color: var(--navy); }
    .step-label.done   { color: var(--green); }
    .step-line { flex: 1; height: 2px; background: var(--border); margin: 0 8px; margin-bottom: 20px; border-radius: 2px; }
    .step-line.done { background: var(--green); }

    .alert {
      display: flex; align-items: flex-start; gap: 12px;
      padding: 14px 18px; border-radius: var(--radius-md);
      font-size: .83rem; margin-bottom: 20px; line-height: 1.5;
    }
    .alert i { font-size: 1rem; margin-top: 1px; flex-shrink: 0; }
    .alert-success { background: var(--green-bg); border: 1px solid #a7f3d0; color: #065f46; }
    .alert-success i { color: var(--green); }
    .alert-error   { background: var(--red-bg);   border: 1px solid #fecaca; color: #7f1d1d; }
    .alert-error i { color: var(--red); }
    .alert-warning { background: var(--amber-bg); border: 1px solid #fcd34d; color: #78350f; }
    .alert-warning i { color: var(--amber); }
    .alert-info    { background: var(--blue-bg);  border: 1px solid #bfdbfe; color: #1e3a8a; }
    .alert-info i  { color: var(--blue); }

    .pin-warn {
      border-left: 4px solid var(--amber); background: var(--amber-bg);
      border-radius: 0 var(--radius-md) var(--radius-md) 0;
      padding: 16px 20px; margin-bottom: 20px;
    }
    .pin-warn-title { font-size: .85rem; font-weight: 700; color: #78350f; margin-bottom: 5px; }
    .pin-warn-sub   { font-size: .78rem; color: #92400e; margin-bottom: 10px; }

    .summary-strip {
      background: var(--purple-bg); border: 1px solid #ddd6fe;
      border-radius: var(--radius-md); padding: 14px 18px;
      display: flex; flex-wrap: wrap; gap: 12px; align-items: center;
      margin-bottom: 22px;
    }
    .summary-pill {
      display: inline-flex; align-items: center; gap: 6px;
      background: #fff; border: 1px solid #ddd6fe;
      border-radius: 999px; padding: 5px 12px;
      font-size: .75rem; font-weight: 600; color: #5b21b6;
    }
    .summary-pill i { font-size: .7rem; }
    .summary-pill .s-label { font-weight: 400; color: var(--text-sec); margin-right: 2px; }

    .field { margin-bottom: 16px; }
    .field label {
      display: block; font-size: .7rem; font-weight: 700;
      text-transform: uppercase; letter-spacing: .07em;
      color: var(--text-muted); margin-bottom: 7px;
    }
    .field input, .field select {
      width: 100%; padding: 11px 14px;
      border: 1.5px solid var(--border); border-radius: var(--radius-sm);
      font-family: 'Sora', sans-serif; font-size: .85rem;
      color: var(--text-pri); background: var(--surface);
      outline: none; transition: border-color .2s, box-shadow .2s;
    }
    .field input:focus, .field select:focus {
      border-color: var(--navy);
      box-shadow: 0 0 0 3px rgba(3,45,93,.08);
    }
    .field input[readonly] { background: #f8fafc; cursor: not-allowed; color: var(--text-sec); }
    .field .field-hint { font-size: .7rem; color: var(--text-muted); margin-top: 5px; }
    .field input.otp-input { letter-spacing: .5rem; font-size: 1.6rem; text-align: center; font-family: 'JetBrains Mono', monospace; }
    .field input.pin-input { letter-spacing: .4rem; font-size: 1.4rem; text-align: center; font-family: 'JetBrains Mono', monospace; }
    .field input::-webkit-outer-spin-button,
    .field input::-webkit-inner-spin-button { -webkit-appearance: none; }

    .section-label {
      font-size: .72rem; font-weight: 700; text-transform: uppercase;
      letter-spacing: .08em; color: var(--text-muted);
      margin: 20px 0 12px; display: flex; align-items: center; gap: 8px;
    }
    .section-label::after { content: ''; flex: 1; height: 1px; background: var(--border); }
    .field-group-row { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
    @media (max-width: 480px) { .field-group-row { grid-template-columns: 1fr; } }

    .form-btn {
      display: inline-flex; align-items: center; justify-content: center; gap: 8px;
      padding: 12px 22px; border-radius: var(--radius-sm);
      font-family: 'Sora', sans-serif; font-size: .85rem; font-weight: 700;
      cursor: pointer; border: none; transition: var(--transition);
      width: 100%; letter-spacing: .01em;
    }
    .form-btn-navy { background: var(--navy); color: #fff; }
    .form-btn-navy:hover { background: #7c2fb0; box-shadow: 0 4px 14px rgba(124,47,176,.3); transform: translateY(-1px); }
    .form-btn-navy:disabled { opacity: .5; cursor: not-allowed; transform: none; box-shadow: none; }
    .form-btn-green { background: var(--green); color: #fff; }
    .form-btn-green:hover { background: #047857; box-shadow: 0 4px 14px rgba(5,150,105,.3); transform: translateY(-1px); }
    .form-btn-outline { background: transparent; color: var(--text-sec); border: 1.5px solid var(--border); }
    .form-btn-outline:hover { background: #f1f5f9; border-color: #cbd5e1; }
    .btn-row { display: flex; gap: 10px; }
    .btn-row .form-btn { width: auto; flex: 1; }

    .charges-table { width: 100%; border-collapse: collapse; font-size: .8rem; }
    .charges-table thead th {
      padding: 10px 14px; background: #f8fafc;
      font-size: .67rem; font-weight: 700; text-transform: uppercase;
      letter-spacing: .07em; color: var(--text-muted);
      border-bottom: 1px solid var(--border); text-align: left;
    }
    .charges-table tbody td { padding: 10px 14px; border-bottom: 1px solid #f1f5f9; color: var(--text-sec); }
    .charges-table tbody tr:last-child td { border-bottom: none; }
    .charges-table tbody tr:hover td { background: #f8faff; }
    .fee-free { color: var(--green); font-weight: 700; }
    .fee-paid { color: var(--red);   font-weight: 700; }

    .success-state { text-align: center; padding: 52px 24px; }
    .success-icon {
      width: 72px; height: 72px; border-radius: 50%;
      background: var(--green-bg); display: flex; align-items: center;
      justify-content: center; font-size: 1.8rem; color: var(--green);
      margin: 0 auto 18px;
    }
    .success-state h3 { font-size: 1.25rem; font-weight: 800; margin-bottom: 8px; color: var(--text-pri); }
    .success-state p  { font-size: .85rem; color: var(--text-sec); margin-bottom: 24px; }

    .desk-only { display: none; } @media (min-width: 768px) { .desk-only { display: block; } }
    @keyframes fadeUp { from { opacity: 0; transform: translateY(12px); } to { opacity: 1; transform: none; } }
    .stat-card { animation: fadeUp .4s ease both; }
    .stat-card:nth-child(1) { animation-delay: .05s; }
    .stat-card:nth-child(2) { animation-delay: .10s; }
    .stat-card:nth-child(3) { animation-delay: .15s; }
    .stat-card:nth-child(4) { animation-delay: .20s; }
    .hero { animation: fadeUp .35s ease both; }
    #step-content { transition: opacity .25s ease; }
    #step-content.fading { opacity: 0; }
    .resend-link { color: var(--navy); font-weight: 600; text-decoration: underline; cursor: pointer; background: none; border: none; font-family: 'Sora',sans-serif; font-size: inherit; padding: 0; }
    .resend-link:disabled { color: var(--text-muted); cursor: not-allowed; text-decoration: none; }

    .mpesa-name-tip {
      display: flex; align-items: flex-start; gap: 10px;
      background: linear-gradient(135deg, #fef9ec, #fef3c7);
      border: 1px solid #fcd34d; border-left: 4px solid var(--amber);
      border-radius: var(--radius-md); padding: 12px 16px; margin-top: 8px;
      font-size: .78rem; color: #78350f; line-height: 1.5;
    }
    .mpesa-name-tip i { color: var(--amber); font-size: .95rem; flex-shrink: 0; margin-top: 1px; }
    .mpesa-name-tip strong { color: #92400e; }

    .confirm-overlay {
      display: none; position: fixed; inset: 0; z-index: 200;
      background: rgba(3,15,40,.65); backdrop-filter: blur(6px);
      align-items: center; justify-content: center; padding: 20px;
    }
    .confirm-overlay.active { display: flex; }
    .confirm-modal {
      background: #fff; border-radius: var(--radius-lg);
      box-shadow: 0 24px 64px rgba(3,45,93,.25);
      width: 100%; max-width: 480px;
      animation: modalPop .28s cubic-bezier(.34,1.56,.64,1) both;
    }
    @keyframes modalPop { from { opacity:0; transform: scale(.88) translateY(18px); } to { opacity:1; transform: none; } }
    .confirm-modal-header {
      padding: 22px 24px 16px;
      border-bottom: 1px solid var(--border);
      display: flex; align-items: center; gap: 12px;
    }
    .confirm-modal-icon {
      width: 44px; height: 44px; border-radius: 12px; flex-shrink: 0;
      background: var(--amber-bg); display: flex; align-items: center;
      justify-content: center; font-size: 1.15rem; color: var(--amber);
    }
    .confirm-modal-title { font-size: 1rem; font-weight: 800; color: var(--text-pri); line-height: 1.2; }
    .confirm-modal-sub   { font-size: .77rem; color: var(--text-muted); margin-top: 3px; }
    .confirm-modal-body  { padding: 20px 24px; }
    .confirm-table {
      width: 100%; border-collapse: collapse;
      border-radius: var(--radius-md); overflow: hidden;
      border: 1px solid var(--border); margin-bottom: 18px;
    }
    .confirm-table tr td {
      padding: 11px 14px; font-size: .83rem;
      border-bottom: 1px solid #f1f5f9;
    }
    .confirm-table tr:last-child td { border-bottom: none; }
    .confirm-table td:first-child {
      font-size: .7rem; font-weight: 700; text-transform: uppercase;
      letter-spacing: .06em; color: var(--text-muted); background: #f8fafc;
      width: 38%;
    }
    .confirm-table td:last-child { color: var(--text-pri); font-weight: 600; }
    .confirm-highlight {
      background: linear-gradient(135deg, #ede9fe, #e0e7ff);
      border: 1px solid #ddd6fe; border-radius: var(--radius-md);
      padding: 14px 16px; margin-bottom: 18px;
      display: flex; align-items: center; gap: 12px;
    }
    .confirm-highlight-icon {
      width: 40px; height: 40px; border-radius: 10px; flex-shrink: 0;
      background: #7c2fb0; display: flex; align-items: center;
      justify-content: center; font-size: 1rem; color: #fff;
    }
    .confirm-highlight-text { font-size: .82rem; color: #5b21b6; line-height: 1.5; }
    .confirm-highlight-text strong { font-size: 1rem; display: block; margin-bottom: 2px; }
    .confirm-modal-footer {
      padding: 16px 24px 20px;
      border-top: 1px solid var(--border);
      display: flex; gap: 10px;
    }
    .confirm-modal-footer .form-btn { width: auto; flex: 1; }
  </style>
<?php if ($wdStandalone): ?>
</head>
<body>
<?php endif; ?>

<?php if (empty($kc_in_router)): ?>
<div id="preloader">
  <div class="preloader-box">
    <div class="spin"></div>
    <span>Loading withdrawal…</span>
  </div>
</div>

<div class="topbar">
  <a href="index.php?page=0112024" class="topbar-brand">
    <span class="topbar-brand-icon"><i class="fas fa-hand-holding-heart"></i></span>
    Keychele
  </a>
  <div class="topbar-actions">
    <a href="index.php?page=withdrawal2factorauth" class="action-btn btn-outline">
      <i class="fas fa-key"></i>
      <span><?= $has_pin ? 'Change PIN' : 'Set PIN' ?></span>
    </a>
    <a href="index.php?page=0112024" class="action-btn btn-navy">
      <i class="fas fa-home"></i>
      <span class="desk-only" style="display:inline">Dashboard</span>
    </a>
  </div>
</div>
<?php endif; ?>

<div class="page-wrap">

  <!-- Hero -->
  <div class="hero">
    <div class="hero-inner">
      <div>
        <div class="hero-eyebrow">
          <i class="fas fa-shield-halved" style="color:#fbbf24"></i>
          Secure 2FA Withdrawal
        </div>
        <div class="hero-title">Withdraw — <?= htmlspecialchars(mb_strimwidth($campaignTitle, 0, 40, '…')) ?></div>
        <div class="hero-sub">PIN + MecroGuard SMS verification required</div>
      </div>
      <div class="hero-right desk-only">
        <div class="hero-balance-val">KES <?= number_format($current_balance, 2) ?></div>
        <div class="hero-balance-label">Available in This Fundraiser</div>
      </div>
    </div>
  </div>

  <!-- Stats -->
  <div class="stats-grid">
    <div class="stat-card sc-blue">
      <div class="stat-label">Available Balance</div>
      <div class="stat-value"><?= number_format($current_balance, 2) ?></div>
      <div class="stat-icon-chip"><i class="fas fa-wallet"></i></div>
    </div>
    <div class="stat-card sc-green">
      <div class="stat-label">Min Withdrawal</div>
      <div class="stat-value">1.00</div>
      <div class="stat-icon-chip"><i class="fas fa-arrow-down-to-line"></i></div>
    </div>
    <div class="stat-card sc-amber">
      <div class="stat-label">Max Withdrawal</div>
      <div class="stat-value">250,000</div>
      <div class="stat-icon-chip"><i class="fas fa-arrow-up-from-line"></i></div>
    </div>
    <div class="stat-card sc-purple">
      <div class="stat-label">Security Level</div>
      <div class="stat-value" style="font-size:.9rem;font-family:'Sora',sans-serif;font-weight:800"><?= $has_pin ? '2FA ✓' : 'PIN ✗' ?></div>
      <div class="stat-icon-chip"><i class="fas fa-shield-halved"></i></div>
    </div>
  </div>

  <?php if (!$has_pin && $step < 4): ?>
  <div class="pin-warn">
    <div class="pin-warn-title"><i class="fas fa-triangle-exclamation" style="margin-right:6px"></i>Withdrawal PIN Not Set</div>
    <div class="pin-warn-sub">You must set a withdrawal PIN before you can make withdrawals.</div>
    <a href="index.php?page=withdrawal2factorauth" class="action-btn btn-crimson" style="padding:8px 16px;font-size:.75rem">
      <i class="fas fa-key"></i> Set PIN Now
    </a>
  </div>
  <?php endif; ?>

  <!-- Main Panel -->
  <div class="panel" id="main-panel">

    <div id="step-loader">
      <div class="spin"></div>
      <span id="step-loader-msg">Processing…</span>
    </div>

    <div class="panel-header">
      <div class="panel-title">
        <i class="fas fa-hand-holding-dollar"></i>
        Withdraw Fundraiser Earnings
      </div>
    </div>

    <div id="step-wizard" style="<?= $step >= 4 ? 'display:none' : '' ?>">
      <div class="step-item">
        <div class="step-num" id="sn1"><?= $step > 1 ? '<i class="fas fa-check" style="font-size:.65rem"></i>' : '1' ?></div>
        <div class="step-label" id="sl1">Details</div>
      </div>
      <div class="step-line" id="sl-line1"></div>
      <div class="step-item">
        <div class="step-num" id="sn2"><?= $step > 2 ? '<i class="fas fa-check" style="font-size:.65rem"></i>' : '2' ?></div>
        <div class="step-label" id="sl2">PIN</div>
      </div>
      <div class="step-line" id="sl-line2"></div>
      <div class="step-item">
        <div class="step-num" id="sn3">3</div>
        <div class="step-label" id="sl3">MecroGuard</div>
      </div>
    </div>

    <div class="panel-body">
      <div id="panel-mpesa">
        <div id="alert-area"></div>
        <div id="step-content"></div>

        <div id="charges-section" style="display:none">
          <div style="margin-top:32px;padding-top:24px;border-top:1px solid var(--border)">
            <div class="panel-title" style="margin-bottom:14px;font-size:.88rem">
              <i class="fas fa-table" style="color:var(--navy)"></i>
              M-Pesa Withdrawal Charges (Official Safaricom)
            </div>
            <div class="alert alert-warning" style="margin-bottom:14px;padding:10px 14px;font-size:.78rem">
              <i class="fas fa-triangle-exclamation"></i>
              <div>These fees are added automatically on top of the amount you enter.</div>
            </div>
            <div style="overflow-x:auto;border-radius:var(--radius-md);border:1px solid var(--border)">
              <table class="charges-table">
                <thead>
                  <tr><th>From (KES)</th><th>To (KES)</th><th>Fee (KES)</th></tr>
                </thead>
                <tbody>
                  <?php foreach ($charge_ranges as $r): ?>
                  <tr>
                    <td><?= number_format($r['min']) ?></td>
                    <td><?= number_format($r['max']) ?></td>
                    <td class="<?= $r['charge'] == 0 ? 'fee-free' : 'fee-paid' ?>">
                      <?= $r['charge'] == 0 ? 'Free' : number_format($r['charge']) ?>
                    </td>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>

      </div><!-- /panel-mpesa -->
    </div><!-- /panel-body -->
  </div><!-- /panel -->

</div><!-- /page-wrap -->

<!-- Confirmation Modal -->
<div class="confirm-overlay" id="confirm-overlay">
  <div class="confirm-modal">
    <div class="confirm-modal-header">
      <div class="confirm-modal-icon"><i class="fas fa-shield-check"></i></div>
      <div>
        <div class="confirm-modal-title">Confirm Withdrawal Details</div>
        <div class="confirm-modal-sub">Please verify everything is correct before proceeding</div>
      </div>
    </div>
    <div class="confirm-modal-body">

      <div class="confirm-highlight">
        <div class="confirm-highlight-icon"><i class="fas fa-mobile-screen-button"></i></div>
        <div class="confirm-highlight-text">
          <strong id="confirm-name-display">—</strong>
          We will send funds to <span id="confirm-phone-display" style="font-weight:700;color:#5b21b6">—</span>
        </div>
      </div>

      <table class="confirm-table">
        <tr><td>Fundraiser</td><td><?= htmlspecialchars(mb_strimwidth($campaignTitle, 0, 40, '…')) ?></td></tr>
        <tr><td>M-Pesa Name</td><td id="ctbl-name">—</td></tr>
        <tr><td>M-Pesa Number</td><td id="ctbl-phone">—</td></tr>
        <tr><td>Amount to Receive</td><td id="ctbl-amount">—</td></tr>
        <tr><td>Platform Fee (3.5%)</td><td id="ctbl-platform-fee">—</td></tr>
        <tr><td>M-Pesa Fee</td><td id="ctbl-fee">—</td></tr>
        <tr><td>Total Deducted</td><td id="ctbl-total" style="color:var(--crimson)">—</td></tr>
      </table>

      <div class="alert alert-warning" style="margin:0;padding:10px 14px;font-size:.77rem">
        <i class="fas fa-triangle-exclamation"></i>
        <div>Double-check the number above. Funds sent to a wrong number <strong>cannot be reversed</strong>.</div>
      </div>

    </div>
    <div class="confirm-modal-footer">
      <button type="button" class="form-btn form-btn-outline" onclick="closeConfirm()">
        <i class="fas fa-pencil"></i> Edit Details
      </button>
      <button type="button" class="form-btn form-btn-green" id="confirm-proceed-btn" onclick="proceedFromConfirm()">
        <i class="fas fa-check-circle"></i> Yes, Proceed
      </button>
    </div>
  </div>
</div>

<script>
const PHP = {
  step         : <?= (int)$step ?>,
  hasPIN       : <?= $has_pin ? 'true' : 'false' ?>,
  balance      : <?= (float)$current_balance ?>,
  fullName     : <?= json_encode($full_name) ?>,
  email        : <?= json_encode($email) ?>,
  campaignTitle: <?= json_encode($campaignTitle) ?>,
  wdData       : <?= json_encode($wd_session ?: null) ?>,
  maskedPhone  : <?= json_encode($_SESSION['wdf_masked_phone'] ?? '') ?>,
  initError    : <?= json_encode($error) ?>,
  initSuccess  : <?= json_encode($success) ?>,
  pageURL      : 'withdraw_fundraiser.php?campaign=<?= (int)$campaign_id ?>',
  platformFeeRate: <?= WITHDRAW_PLATFORM_FEE_RATE ?>,
};

const chargeRanges = <?= json_encode($charge_ranges) ?>;

function fmtKES(n) {
  return Number(n).toLocaleString('en-KE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
function getCharge(amount) {
  for (const r of chargeRanges) {
    if (amount >= r.min && amount <= r.max) return r.charge;
  }
  return null;
}
function getPlatformFee(amount) {
  return Math.round(amount * PHP.platformFeeRate * 100) / 100;
}
function escHtml(str) {
  return String(str || '')
    .replace(/&/g,'&amp;').replace(/</g,'&lt;')
    .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function showAlert(type, html) {
  const icons = { success:'fa-circle-check', error:'fa-circle-exclamation', warning:'fa-triangle-exclamation', info:'fa-circle-info' };
  document.getElementById('alert-area').innerHTML =
    `<div class="alert alert-${type}"><i class="fas ${icons[type]||icons.info}"></i><div>${html}</div></div>`;
  document.getElementById('alert-area').scrollIntoView({ behavior:'smooth', block:'nearest' });
}
function clearAlert() { document.getElementById('alert-area').innerHTML = ''; }
function setLoader(on, msg = 'Processing…') {
  document.getElementById('step-loader-msg').textContent = msg;
  document.getElementById('step-loader').classList.toggle('active', on);
}

async function ajaxPost(formData) {
  const resp    = await fetch(PHP.pageURL, { method:'POST', body:formData, headers:{'X-Requested-With':'XMLHttpRequest'} });
  const text    = await resp.text();
  const trimmed = text.trimStart().replace(/^\xEF\xBB\xBF/, '');
  try {
    return JSON.parse(trimmed);
  } catch (e) {
    console.error('[Keychele AJAX] Non-JSON response (HTTP ' + resp.status + '):\n', trimmed.slice(0,1000));
    const preview = trimmed.slice(0,300).replace(/<[^>]+>/g,' ').trim();
    throw new Error('Server error – see console.\n\nPreview: ' + preview);
  }
}
async function ajaxGet(params) {
  const sep     = PHP.pageURL.includes('?') ? '&' : '?';
  const resp    = await fetch(PHP.pageURL + sep + params, { headers:{'X-Requested-With':'XMLHttpRequest'} });
  const text    = await resp.text();
  const trimmed = text.trimStart().replace(/^\xEF\xBB\xBF/, '');
  try {
    return JSON.parse(trimmed);
  } catch (e) {
    console.error('[Keychele AJAX GET] Non-JSON response (HTTP ' + resp.status + '):\n', trimmed.slice(0,1000));
    const preview = trimmed.slice(0,300).replace(/<[^>]+>/g,' ').trim();
    throw new Error('Server error – see console.\n\nPreview: ' + preview);
  }
}

function updateWizard(step) {
  const wizard = document.getElementById('step-wizard');
  if (step >= 4) { wizard.style.display = 'none'; return; }
  wizard.style.display = 'flex';
  for (let i = 1; i <= 3; i++) {
    const num   = document.getElementById('sn' + i);
    const label = document.getElementById('sl' + i);
    num.className   = 'step-num'   + (i < step ? ' done' : i === step ? ' active' : '');
    label.className = 'step-label' + (i < step ? ' done' : i === step ? ' active' : '');
    if (i < step) num.innerHTML = '<i class="fas fa-check" style="font-size:.65rem"></i>';
    else num.textContent = i;
  }
  for (let i = 1; i <= 2; i++) {
    document.getElementById('sl-line' + i).className = 'step-line' + (step > i ? ' done' : '');
  }
}

function buildStep1() {
  const disabled = PHP.hasPIN ? '' : 'disabled';
  return `
    <form id="form-step1">
      <input type="hidden" name="step1" value="1">
      <div class="section-label">Account Information</div>
      <div class="field-group-row">
        <div class="field"><label>Full Name</label><input type="text" value="${escHtml(PHP.fullName)}" readonly></div>
        <div class="field"><label>Email Address</label><input type="email" value="${escHtml(PHP.email)}" readonly></div>
      </div>
      <div class="section-label">M-Pesa Destination</div>
      <div class="field">
        <label>M-Pesa Phone Number <span style="color:var(--crimson)">*</span></label>
        <input name="mpesa_number" type="tel" required placeholder="e.g. 0712345678 or 0112345678" autocomplete="tel" id="wd-mpesa-input">
        <div class="field-hint"><i class="fas fa-info-circle" style="margin-right:4px"></i>Accepted: 07XXXXXXXX, 01XXXXXXXX, +2547XXXXXXXX, +2541XXXXXXXX</div>
      </div>
      <div class="field">
        <label>M-Pesa Account Name <span style="color:var(--crimson)">*</span></label>
        <input name="mpesa_name" type="text" required placeholder="e.g. John Doe" id="wd-mpesa-name-input" autocomplete="name">
        <div class="field-hint"><i class="fas fa-user-check" style="margin-right:4px"></i>Enter the name registered on the M-Pesa number above</div>
      </div>
      <div class="mpesa-name-tip" id="mpesa-name-tip" style="display:none">
        <i class="fas fa-bell"></i>
        <div>
          Our team will see <strong id="tip-name-preview">—</strong> as the M-Pesa account name when disbursing your funds.
          Make sure it matches the registered name on the number you entered.
        </div>
      </div>
      <div class="section-label" style="margin-top:20px">Amount</div>
      <div class="field">
        <label>Amount to Receive (KES) <span style="color:var(--crimson)">*</span></label>
        <input name="amount" type="number" min="1" step="1" required placeholder="e.g. 5000" id="wd-amount-input">
        <div class="field-hint" id="fee-hint">
          <i class="fas fa-circle-info" style="margin-right:4px"></i>
          Platform fee (3.5%) + Safaricom fee added automatically. Max (inc. fees): KES ${fmtKES(PHP.balance)}
        </div>
      </div>
      <button type="submit" ${disabled} class="form-btn form-btn-navy" style="margin-top:8px">
        <i class="fas fa-arrow-right"></i> Review &amp; Continue
      </button>
    </form>`;
}

function buildStep2(wd) {
  return `
    <div class="summary-strip">
      <div class="summary-pill"><i class="fas fa-hand-holding-heart"></i><span class="s-label">Fundraiser</span>${escHtml(PHP.campaignTitle)}</div>
      <div class="summary-pill"><i class="fas fa-user"></i><span class="s-label">Name</span>${escHtml(wd.mpesa_name || '—')}</div>
      <div class="summary-pill"><i class="fas fa-mobile-screen-button"></i><span class="s-label">Number</span>${escHtml(wd.mpesa_number)}</div>
      <div class="summary-pill"><i class="fas fa-money-bill-wave"></i><span class="s-label">Receive</span>KES ${fmtKES(wd.net_amount)}</div>
      <div class="summary-pill"><i class="fas fa-percent"></i><span class="s-label">Platform</span>KES ${fmtKES(wd.platform_fee)}</div>
      <div class="summary-pill"><i class="fas fa-tag"></i><span class="s-label">M-Pesa Fee</span>KES ${fmtKES(wd.charge)}</div>
      <div class="summary-pill"><i class="fas fa-minus-circle"></i><span class="s-label">Deducted</span>KES ${fmtKES(wd.total_deduct)}</div>
    </div>
    <form id="form-step2">
      <input type="hidden" name="step2" value="1">
      <div class="field">
        <label>Withdrawal PIN <span style="color:var(--crimson)">*</span></label>
        <input name="withdrawal_pin" type="password" inputmode="numeric"
               maxlength="6" minlength="4" required placeholder="••••"
               class="pin-input" autocomplete="current-password">
      </div>
      <div class="btn-row" style="margin-top:8px">
        <button type="button" onclick="goBack()" class="form-btn form-btn-outline"><i class="fas fa-arrow-left"></i> Back</button>
        <button type="submit" class="form-btn form-btn-navy"><i class="fas fa-shield-halved"></i> Verify PIN</button>
      </div>
    </form>`;
}

function buildStep3(wd, maskedPhone) {
  return `
    <div style="text-align:center;margin-bottom:22px">
      <div class="otp-ring" style="width:64px;height:64px;border-radius:50%;background:var(--purple-bg);display:flex;align-items:center;justify-content:center;font-size:1.5rem;color:var(--purple);margin:0 auto 14px;"><i class="fas fa-message"></i></div>
      <div style="font-size:.95rem;font-weight:700;color:var(--text-pri);margin-bottom:6px">MecroGuard Code</div>
      <div style="font-size:.82rem;color:var(--text-sec)">
        A 6-digit code has been sent to <strong style="color:var(--navy)">${escHtml(maskedPhone)}</strong>
      </div>
    </div>
    <div class="summary-strip">
      <div class="summary-pill"><i class="fas fa-hand-holding-heart"></i><span class="s-label">Fundraiser</span>${escHtml(PHP.campaignTitle)}</div>
      <div class="summary-pill"><i class="fas fa-money-bill-wave"></i><span class="s-label">Receive</span>KES ${fmtKES(wd.net_amount)}</div>
      <div class="summary-pill"><i class="fas fa-percent"></i><span class="s-label">Platform</span>KES ${fmtKES(wd.platform_fee)}</div>
      <div class="summary-pill"><i class="fas fa-tag"></i><span class="s-label">M-Pesa Fee</span>KES ${fmtKES(wd.charge)}</div>
      <div class="summary-pill"><i class="fas fa-minus-circle"></i><span class="s-label">Deducted</span>KES ${fmtKES(wd.total_deduct)}</div>
    </div>
    <form id="form-step3">
      <input type="hidden" name="step3" value="1">
      <div class="field">
        <label>MecroGuard Code <span style="color:var(--crimson)">*</span></label>
        <input name="otp_code" type="text" inputmode="numeric"
               maxlength="6" minlength="6" required
               placeholder="_ _ _ _ _ _" autocomplete="one-time-code"
               class="otp-input" id="otp-field">
        <div class="field-hint" style="text-align:center;margin-top:8px">
          <i class="fas fa-clock" style="margin-right:4px"></i>Code expires in 10 minutes.
          &nbsp;·&nbsp;
          <button type="button" class="resend-link" id="resend-btn" onclick="resendOTP(this)">Resend Code</button>
        </div>
      </div>
      <div class="btn-row" style="margin-top:8px">
        <button type="button" onclick="goBack()" class="form-btn form-btn-outline"><i class="fas fa-xmark"></i> Cancel</button>
        <button type="submit" class="form-btn form-btn-green"><i class="fas fa-paper-plane"></i> Submit Withdrawal</button>
      </div>
    </form>`;
}

function buildStep4() {
  return `
    <div class="success-state">
      <div class="success-icon"><i class="fas fa-circle-check"></i></div>
      <h3>Request Submitted!</h3>
      <p>Your fundraiser withdrawal has been queued for processing within 24 hours.</p>
      <a href="index.php?page=0112024" class="action-btn btn-navy" style="display:inline-flex;margin:0 auto">
        <i class="fas fa-arrow-left"></i> Back to Dashboard
      </a>
    </div>`;
}

let currentStep = PHP.step;
let wdData      = PHP.wdData;
let maskedPhone = PHP.maskedPhone;

function renderStep(step) {
  currentStep = step;
  const content = document.getElementById('step-content');
  content.classList.add('fading');
  setTimeout(() => {
    clearAlert();
    updateWizard(step);
    document.getElementById('charges-section').style.display = step === 1 ? '' : 'none';
    switch (step) {
      case 1: content.innerHTML = buildStep1(); attachStep1(); break;
      case 2: content.innerHTML = buildStep2(wdData); attachStep2(); break;
      case 3: content.innerHTML = buildStep3(wdData, maskedPhone); attachStep3(); break;
      case 4: content.innerHTML = buildStep4(); break;
    }
    content.classList.remove('fading');
    bindDigitOnly();
  }, 250);
}

let _pendingForm = null;

function attachStep1() {
  const form      = document.getElementById('form-step1');
  const amtInp    = document.getElementById('wd-amount-input');
  const nameInp   = document.getElementById('wd-mpesa-name-input');
  const hint      = document.getElementById('fee-hint');
  const nameTip   = document.getElementById('mpesa-name-tip');
  const tipPreview= document.getElementById('tip-name-preview');

  amtInp.addEventListener('input', () => {
    const n = parseFloat(amtInp.value);
    const charge = getCharge(n);
    if (!isNaN(n) && charge !== null) {
      const platformFee = getPlatformFee(n);
      hint.innerHTML = `<i class="fas fa-circle-info" style="margin-right:4px"></i>
        Platform: KES ${fmtKES(platformFee)} &nbsp;|&nbsp; M-Pesa: KES ${fmtKES(charge)} &nbsp;|&nbsp; Total deducted: <strong>KES ${fmtKES(n + charge + platformFee)}</strong>`;
    } else {
      hint.innerHTML = `<i class="fas fa-circle-info" style="margin-right:4px"></i>
        Platform fee (3.5%) + Safaricom fee added automatically. Max (inc. fees): KES ${fmtKES(PHP.balance)}`;
    }
  });

  nameInp.addEventListener('input', () => {
    const v = nameInp.value.trim();
    if (v.length > 0) {
      tipPreview.textContent = v;
      nameTip.style.display  = 'flex';
    } else {
      nameTip.style.display  = 'none';
    }
  });

  form.addEventListener('submit', (e) => {
    e.preventDefault();
    if (!PHP.hasPIN) {
      showAlert('error', 'You have not set a withdrawal PIN. <a href="index.php?page=withdrawal2factorauth" style="text-decoration:underline;font-weight:600">Set your PIN first</a>.');
      return;
    }
    const mpesa      = form.mpesa_number.value.trim();
    const mpesaName  = form.mpesa_name.value.trim();
    const amount     = parseFloat(form.amount.value);
    const charge     = getCharge(amount);
    const platformFee = getPlatformFee(amount);

    if (!mpesa)          { showAlert('error', 'M-Pesa phone number is required.'); return; }
    if (!mpesaName)      { showAlert('error', 'M-Pesa account name is required.'); return; }
    if (!(amount > 0))   { showAlert('error', 'Amount must be greater than 0.'); return; }
    if (charge === null) { showAlert('error', 'Amount is outside supported withdrawal ranges.'); return; }
    if (amount + charge + platformFee > PHP.balance) {
      showAlert('error', `Insufficient fundraiser balance. You need KES ${fmtKES(amount+charge+platformFee)} but this fundraiser has KES ${fmtKES(PHP.balance)} available.`);
      return;
    }

    _pendingForm = form;
    openConfirm({ mpesa, mpesaName, amount, charge, platformFee });
  });
}

function openConfirm({ mpesa, mpesaName, amount, charge, platformFee }) {
  const total = amount + charge + platformFee;
  document.getElementById('confirm-name-display').textContent  = mpesaName;
  document.getElementById('confirm-phone-display').textContent = mpesa;
  document.getElementById('ctbl-name').textContent    = mpesaName;
  document.getElementById('ctbl-phone').textContent   = mpesa;
  document.getElementById('ctbl-amount').textContent  = 'KES ' + fmtKES(amount);
  document.getElementById('ctbl-platform-fee').textContent = 'KES ' + fmtKES(platformFee);
  document.getElementById('ctbl-fee').textContent     = charge === 0 ? 'Free' : 'KES ' + fmtKES(charge);
  document.getElementById('ctbl-total').textContent   = 'KES ' + fmtKES(total);
  document.getElementById('confirm-overlay').classList.add('active');
  document.body.style.overflow = 'hidden';
}
function closeConfirm() {
  document.getElementById('confirm-overlay').classList.remove('active');
  document.body.style.overflow = '';
  _pendingForm = null;
}
async function proceedFromConfirm() {
  if (!_pendingForm) return;
  document.getElementById('confirm-overlay').classList.remove('active');
  document.body.style.overflow = '';
  setLoader(true, 'Validating details…');
  try {
    const res = await ajaxPost(new FormData(_pendingForm));
    if (res.ok) { wdData = res.wd_data; renderStep(2); }
    else showAlert('error', res.error || 'An error occurred. Please try again.');
  } catch (err) {
    showAlert('error', 'Server error — check browser console (F12).<br><small>' + escHtml(err.message.slice(0,200)) + '</small>');
  } finally { setLoader(false); _pendingForm = null; }
}
document.getElementById('confirm-overlay').addEventListener('click', (e) => {
  if (e.target === document.getElementById('confirm-overlay')) closeConfirm();
});
document.addEventListener('keydown', (e) => {
  if (e.key === 'Escape') closeConfirm();
});

function attachStep2() {
  const form = document.getElementById('form-step2');
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    if (!form.withdrawal_pin.value.trim()) { showAlert('error', 'Please enter your withdrawal PIN.'); return; }
    setLoader(true, 'Verifying PIN & sending SMS…');
    try {
      const res = await ajaxPost(new FormData(form));
      if (res.ok) {
        maskedPhone = res.masked_phone || maskedPhone;
        if (res.wd_data) wdData = res.wd_data;
        renderStep(3);
      } else showAlert('error', res.error || 'PIN verification failed. Please try again.');
    } catch (err) {
      showAlert('error', 'Server error — check browser console (F12).<br><small>' + escHtml(err.message.slice(0,200)) + '</small>');
    } finally { setLoader(false); }
  });
}

function attachStep3() {
  const form = document.getElementById('form-step3');
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    if (form.otp_code.value.trim().length !== 6) { showAlert('error', 'Please enter the 6-digit MecroGuard code.'); return; }
    setLoader(true, 'Submitting withdrawal…');
    try {
      const res = await ajaxPost(new FormData(form));
      if (res.ok) {
        renderStep(4);
        if (res.success) setTimeout(() => showAlert('success', res.success), 300);
      } else {
        if (res.step === 1) { wdData = null; renderStep(1); showAlert('error', res.error); }
        else showAlert('error', res.error || 'Invalid or expired code. Please try again.');
      }
    } catch (err) {
      showAlert('error', 'Server error — check browser console (F12).<br><small>' + escHtml(err.message.slice(0,200)) + '</small>');
    } finally { setLoader(false); }
  });
}

async function resendOTP(btn) {
  btn.disabled = true;
  btn.textContent = 'Sending…';
  try {
    const res = await ajaxGet('resend_otp=1');
    showAlert(res.ok ? 'success' : 'error',
              res.ok ? (res.success || 'New code sent to your phone.')
                     : (res.error  || 'Failed to resend. Please try again.'));
  } catch (err) {
    showAlert('error', 'Server error — check browser console (F12).');
  } finally {
    let cd = 30;
    const iv = setInterval(() => {
      btn.textContent = `Resend (${cd}s)`;
      if (--cd < 0) { clearInterval(iv); btn.disabled = false; btn.textContent = 'Resend Code'; }
    }, 1000);
  }
}

async function goBack() {
  try { await ajaxGet('reset=1'); } catch (_) {}
  wdData = null; renderStep(1);
}
async function resetFlow() {
  try { await ajaxGet('reset=1'); } catch (_) {}
  wdData = null; renderStep(1);
}

function bindDigitOnly() {
  document.querySelectorAll('.otp-input, .pin-input').forEach(el => {
    el.addEventListener('input', () => { el.value = el.value.replace(/\D/g, ''); });
  });
}

window.addEventListener('load', () => {
  const p = document.getElementById('preloader');
  if (p) {
    p.style.opacity = '0';
    setTimeout(() => { p.style.display = 'none'; }, 400);
  }
  renderStep(PHP.step);
  if (PHP.initError)   showAlert('error',   PHP.initError);
  if (PHP.initSuccess) showAlert('success', PHP.initSuccess);
});
</script>
<?php if ($wdStandalone): ?>
</body>
</html>
<?php endif; ?>
