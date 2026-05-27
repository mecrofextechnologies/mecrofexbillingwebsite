<?php
session_start();
require 'vendor/autoload.php';
include 'msanii/includes/connect.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// ====================== LIVE MPESA CONFIG ======================
define('MPESA_CONSUMER_KEY',    'ViMJdXYZVIgGsoWGcpEmKp1z69nq6r7atgeOnILgYoiXYUQC');
define('MPESA_CONSUMER_SECRET', '8r6MlY2svifZADA4xmV6Pe8Avg3TxNQFMMfLxqI8jl6Ms7IK1VRrU0PN92vUGvRp');
define('MPESA_SHORTCODE',       '3576741');
define('MPESA_TILL_NUMBER',     '4991672');
define('MPESA_PASSKEY',         'a9e2c0d0181d3391956cfdf16fdef60b3240186dfdd2c5f000e39b3a67801f35');
define('MPESA_CALLBACK_URL',    'https://keychele.co.ke/callback.php');

function getAccessToken() {
    $url = 'https://api.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials';
    $credentials = base64_encode(MPESA_CONSUMER_KEY . ':' . MPESA_CONSUMER_SECRET);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER     => ['Authorization: Basic ' . $credentials],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT        => 30
    ]);
    $response = curl_exec($ch);
    $error    = curl_error($ch);
    curl_close($ch);
    if ($error) error_log("MPESA Token cURL error: " . $error);
    $result = json_decode($response, true);
    return $result['access_token'] ?? false;
}

function initiateSTKPush($phone, $amount, $reference) {
    $url       = 'https://api.safaricom.co.ke/mpesa/stkpush/v1/processrequest';
    $timestamp = date('YmdHis');
    $password  = base64_encode(MPESA_SHORTCODE . MPESA_PASSKEY . $timestamp);

    $payload = [
        "BusinessShortCode" => MPESA_SHORTCODE,
        "Password"          => $password,
        "Timestamp"         => $timestamp,
        "TransactionType"   => "CustomerBuyGoodsOnline",
        "Amount"            => $amount,
        "PartyA"            => $phone,
        "PartyB"            => MPESA_TILL_NUMBER,
        "PhoneNumber"       => $phone,
        "CallBackURL"       => MPESA_CALLBACK_URL,
        "AccountReference"  => $reference,
        "TransactionDesc"   => "Support @ Keychele"
    ];

    $token = getAccessToken();
    if (!$token) { error_log("MPESA: Failed to get access token"); return false; }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token, 'Content-Type: application/json'],
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT        => 60
    ]);
    $response = curl_exec($ch);
    $error    = curl_error($ch);
    curl_close($ch);

    if ($error) { error_log("MPESA STK cURL error: " . $error); return false; }

    $result = json_decode($response, true);
    if (!isset($result['ResponseCode']) || $result['ResponseCode'] !== '0') {
        error_log("MPESA STK Push failed: " . json_encode($result));
        return false;
    }
    error_log("MPESA STK Push SUCCESS - CheckoutRequestID: " . ($result['CheckoutRequestID'] ?? 'N/A'));
    return $result['CheckoutRequestID'] ?? false;
}

// ── FETCH CREATOR (fixed: always pull full_name from creators table) ──
$creator_id = $_GET['creator'] ?? '';
if (!is_numeric($creator_id)) { http_response_code(400); die("Invalid creator"); }

$stmt = $pdo->prepare("
    SELECT COALESCE(NULLIF(TRIM(c.full_name), ''), mp.full_name, CONCAT('@', c.username)) AS full_name,
           mp.profile_pic,
           c.username,
           c.email AS creator_email
    FROM my_page mp
    JOIN creators c ON mp.creator_id = c.id
    WHERE mp.creator_id = ?
    LIMIT 1
");
$stmt->execute([$creator_id]);
$creator = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$creator) { http_response_code(404); die("Creator not found"); }

// Safe variables — never null
$displayName  = $creator['full_name']      ?? 'Creator';
$profilePic   = $creator['profile_pic']    ?? 'default.png';
$username     = $creator['username']       ?? '';
$creatorEmail = $creator['creator_email']  ?? '';
$initial      = strtoupper(substr($displayName, 0, 1));

// ── STATE FLAGS ──
$just_paid       = false;
$payment_failed  = false;
$waiting_payment = false;
$show_form       = true;
$receipt         = '';
$paid_amount     = 0;

if (isset($_SESSION['paid_creator']) && $_SESSION['paid_creator'] == $creator_id && !empty($_SESSION['receipt'])) {
    $just_paid   = true;
    $receipt     = $_SESSION['receipt'];
    $paid_amount = $_SESSION['paid_amount'] ?? 0;
    unset($_SESSION['paid_creator'], $_SESSION['receipt'], $_SESSION['pending_checkout'], $_SESSION['paid_amount']);
    $show_form   = false;

} elseif (isset($_SESSION['pending_checkout'])) {
    $checkout_id = $_SESSION['pending_checkout'];

    $chkStmt = $pdo->prepare("
        SELECT status, mpesa_receipt, amount, created_at, supporter_name, phone
        FROM support WHERE checkout_request_id = ?
    ");
    $chkStmt->execute([$checkout_id]);
    $row = $chkStmt->fetch(PDO::FETCH_ASSOC);

    if ($row) {
        $age = time() - strtotime($row['created_at']);

        if (!empty($row['mpesa_receipt'])) {
            $just_paid   = true;
            $receipt     = $row['mpesa_receipt'];
            $paid_amount = (int)$row['amount'];

            $pdo->prepare("
                INSERT INTO my_balance (creator_id, amount, date)
                VALUES (?, ?, NOW())
                ON DUPLICATE KEY UPDATE amount = amount + VALUES(amount)
            ")->execute([$creator_id, $paid_amount]);

            if (!isset($_SESSION['support_email_sent_' . $checkout_id])) {
                sendSupportEmails($pdo, $creator, $row, $paid_amount, $receipt);
                $_SESSION['support_email_sent_' . $checkout_id] = true;
            }

            unset($_SESSION['pending_checkout']);
            $show_form = false;

        } elseif ($row['status'] === 'failed') {
            $payment_failed = true;
            unset($_SESSION['pending_checkout']);
            $show_form = false;

        } elseif ($row['status'] === 'pending' || $row['status'] === null) {
            if ($age >= 300) {
                $pdo->prepare("UPDATE support SET status = 'failed' WHERE checkout_request_id = ?")
                    ->execute([$checkout_id]);
                $payment_failed = true;
                unset($_SESSION['pending_checkout']);
                $show_form = false;
            } else {
                $waiting_payment = true;
                $show_form = false;
            }
        }
    } else {
        unset($_SESSION['pending_checkout']);
    }
}

// ── PROCESS NEW PAYMENT ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $show_form) {
    $name      = trim($_POST['name'] ?? 'Anonymous');
    $phone_raw = preg_replace('/\D/', '', $_POST['phone'] ?? '');
    $amount    = (int)($_POST['amount'] ?? 0);

    if (substr($phone_raw, 0, 1) === '0')  $phone = '254' . substr($phone_raw, 1);
    elseif (strlen($phone_raw) === 9)       $phone = '254' . $phone_raw;
    else                                    $phone = $phone_raw;

    if ($amount < 1 || !preg_match('/^2547[0-9]{8}$/', $phone)) {
        echo "<script>document.addEventListener('DOMContentLoaded',()=>Swal.fire({icon:'error',title:'Invalid Input',text:'Minimum KES 1. Use a valid Safaricom number (07xx xxx xxx).',confirmButtonColor:'#b81236'}));</script>";
    } else {
        $reference  = 'SUP_' . time() . rand(100, 999);
        $checkoutID = initiateSTKPush($phone, $amount, $reference);

        if ($checkoutID) {
            $pdo->prepare("
                INSERT INTO support
                (creator_id, supporter_name, phone, amount, transaction_id, checkout_request_id, status, created_at)
                VALUES (?, ?, ?, ?, ?, ?, 'pending', NOW())
            ")->execute([$creator_id, $name, $phone, $amount, $reference, $checkoutID]);

            $_SESSION['pending_checkout'] = $checkoutID;
            header("Location: support.php?creator=$creator_id");
            exit;
        } else {
            echo "<script>document.addEventListener('DOMContentLoaded',()=>Swal.fire({icon:'error',title:'Failed',text:'Could not send M-Pesa prompt. Check network or try again.',confirmButtonColor:'#b81236'}));</script>";
        }
    }
}

function sendSupportEmails($pdo, $creator, $supportRow, $amount, $receipt) {
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = 'mail.keychele.co.ke';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'notifications@keychele.co.ke';
        $mail->Password   = 'King20241064.';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        $mail->Port       = 465;
        $mail->SMTPOptions = ['ssl' => ['verify_peer'=>false,'verify_peer_name'=>false,'allow_self_signed'=>true]];
        $mail->CharSet    = 'UTF-8';
        $mail->isHTML(true);

        $supporterName = htmlspecialchars($supportRow['supporter_name'] ?: 'Supporter');
        $creatorName   = htmlspecialchars($creator['full_name'] ?? 'Creator');
        $supportDate   = date('l, d F Y \a\t h:i A', strtotime($supportRow['created_at'] ?? 'now'));
        $yr            = date('Y');

        $baseStyle = "font-family:'Segoe UI',Arial,sans-serif;margin:0;padding:0;background:#f4f6fc;";
        $wrapStyle = "max-width:560px;margin:0 auto;background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 16px 48px rgba(3,45,93,.15);";
        $hdStyle   = "background:linear-gradient(135deg,#032d5d 0%,#b81236 100%);padding:48px 32px;text-align:center;";
        $ftStyle   = "background:#021e3f;color:rgba(255,255,255,.55);text-align:center;padding:20px 32px;font-size:12px;";

        // Email to supporter
        $mail->setFrom('notifications@keychele.co.ke', 'Keychele');
        $mail->addAddress($supportRow['phone'] . '@m-pesa.com', $supporterName);
        $mail->Subject = "Thank You for Supporting $creatorName! 🎉";
        $mail->Body = "<div style='$baseStyle'><div style='$wrapStyle'>
            <div style='$hdStyle'>
                <div style='color:#f5c842;font-size:11px;font-weight:800;letter-spacing:3px;text-transform:uppercase;margin-bottom:12px;'>Keychele Platform</div>
                <div style='font-size:48px;'>🙏</div>
                <h1 style='color:#fff;font-size:28px;margin:12px 0 4px;'>Thank You!</h1>
                <p style='color:rgba(255,255,255,.7);font-size:14px;margin:0;'>Your support makes a real difference</p>
            </div>
            <div style='padding:40px 32px;text-align:center;'>
                <h2 style='color:#032d5d;font-size:20px;margin:0 0 12px;'>You supported <strong style=\"color:#b81236;\">$creatorName</strong></h2>
                <p style='color:#4a5578;font-size:14px;line-height:1.7;margin-bottom:24px;'>Your generous contribution helps creators keep building amazing content for their community.</p>
                <div style='background:#f4f6fc;border:1.5px dashed #dde3f0;border-radius:12px;padding:24px;margin-bottom:24px;'>
                    <div style='font-size:36px;font-weight:900;color:#032d5d;'>KES " . number_format($amount) . "</div>
                    <div style='font-size:12px;color:#8a97b8;margin-top:4px;text-transform:uppercase;letter-spacing:1px;'>Amount Sent</div>
                    <hr style='border:none;border-top:1px solid #dde3f0;margin:16px 0;'>
                    <div style='font-size:13px;color:#4a5578;'><strong>M-Pesa Receipt:</strong> <span style='color:#b81236;font-weight:800;font-family:monospace;'>$receipt</span></div>
                    <div style='font-size:12px;color:#8a97b8;margin-top:6px;'>$supportDate</div>
                </div>
            </div>
            <div style='$ftStyle'>© $yr Keychele · Kenya's Creator Economy</div>
        </div></div>";
        $mail->AltBody = "Thank you for supporting $creatorName!\nAmount: KES " . number_format($amount) . "\nReceipt: $receipt\nDate: $supportDate";
        $mail->send();

        // Email to creator
        $mail->clearAddresses();
        $mail->addAddress($creator['creator_email'] ?? '', $creatorName);
        $mail->Subject = "💰 New Support Received — KES " . number_format($amount);
        $mail->Body = "<div style='$baseStyle'><div style='$wrapStyle'>
            <div style='$hdStyle'>
                <div style='color:#f5c842;font-size:11px;font-weight:800;letter-spacing:3px;text-transform:uppercase;margin-bottom:12px;'>Keychele Platform</div>
                <div style='font-size:48px;'>💰</div>
                <h1 style='color:#fff;font-size:28px;margin:12px 0 4px;'>New Support!</h1>
                <p style='color:rgba(255,255,255,.7);font-size:14px;margin:0;'>Someone just sent you a tip</p>
            </div>
            <div style='padding:40px 32px;text-align:center;'>
                <h2 style='color:#032d5d;font-size:20px;margin:0 0 12px;'>You received <strong style=\"color:#b81236;\">KES " . number_format($amount) . "</strong></h2>
                <p style='color:#4a5578;font-size:14px;line-height:1.7;margin-bottom:24px;'>Keep creating — your community appreciates you!</p>
                <div style='background:#f4f6fc;border:1.5px dashed #dde3f0;border-radius:12px;padding:24px;margin-bottom:24px;'>
                    <div style='font-size:36px;font-weight:900;color:#16a34a;'>KES " . number_format($amount) . "</div>
                    <div style='font-size:12px;color:#8a97b8;margin-top:4px;text-transform:uppercase;letter-spacing:1px;'>Amount Received</div>
                    <hr style='border:none;border-top:1px solid #dde3f0;margin:16px 0;'>
                    <div style='font-size:13px;color:#4a5578;'><strong>From:</strong> " . htmlspecialchars($supportRow['supporter_name'] ?: 'Anonymous') . "</div>
                    <div style='font-size:13px;color:#4a5578;margin-top:6px;'><strong>Receipt:</strong> <span style='color:#b81236;font-weight:800;font-family:monospace;'>$receipt</span></div>
                    <div style='font-size:12px;color:#8a97b8;margin-top:6px;'>$supportDate</div>
                </div>
            </div>
            <div style='$ftStyle'>© $yr Keychele · Kenya's Creator Economy</div>
        </div></div>";
        $mail->AltBody = "New support received!\nAmount: KES " . number_format($amount) . "\nFrom: " . ($supportRow['supporter_name'] ?: 'Anonymous') . "\nReceipt: $receipt\nDate: $supportDate";
        $mail->send();

    } catch (Exception $e) {
        error_log("Support email failed: " . $mail->ErrorInfo);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Support <?= htmlspecialchars($displayName) ?> | Keychele</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700;900&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600;9..40,700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<style>
:root{
  --navy:    #032d5d;
  --navy-d:  #021e3f;
  --navy-m:  #0a4a99;
  --navy-l:  #e8eef8;
  --accent:  #b81236;
  --accent-d:#8f0e2a;
  --accent-l:#fce4eb;
  --gold:    #f5c842;
  --gold-l:  #fffbea;
  --white:   #ffffff;
  --green:   #16a34a;
  --green-l: #dcfce7;
  --g100:    #eef1f9;
  --g200:    #dde3f0;
  --g400:    #8a97b8;
  --g600:    #4a5578;
  --ff:      'Playfair Display', Georgia, serif;
  --fb:      'DM Sans', system-ui, sans-serif;
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
html,body{min-height:100%;font-family:var(--fb);-webkit-font-smoothing:antialiased;}

body{
  min-height:100vh;
  display:flex;align-items:center;justify-content:center;
  padding:24px 16px 48px;
  background:var(--navy-d);
  position:relative;overflow-x:hidden;
}
body::before{
  content:'';position:fixed;inset:0;
  background:
    radial-gradient(ellipse 70% 55% at 15% 35%, rgba(184,18,54,.22) 0%, transparent 65%),
    radial-gradient(ellipse 60% 65% at 85% 65%, rgba(10,74,153,.28) 0%, transparent 65%),
    radial-gradient(ellipse 45% 45% at 50% 50%, rgba(245,200,66,.05) 0%, transparent 70%);
  z-index:0;
}
body::after{
  content:'';position:fixed;inset:0;
  background-image:radial-gradient(rgba(255,255,255,.04) 1px, transparent 1px);
  background-size:30px 30px;z-index:0;pointer-events:none;
}

/* ── WRAP ── */
.support-wrap{position:relative;z-index:1;width:100%;max-width:440px;}

/* ── CARD ── */
.card{
  background:var(--white);border-radius:24px;overflow:hidden;
  box-shadow:0 24px 80px rgba(2,30,63,.5),0 4px 16px rgba(0,0,0,.15);
}

/* ── CARD HEADER ── */
.card-head{
  position:relative;
  padding:44px 32px 68px;
  background:linear-gradient(135deg,var(--navy-d) 0%,var(--navy-m) 55%,var(--accent) 100%);
  text-align:center;overflow:hidden;
}
.card-head::before{
  content:'';position:absolute;top:0;left:0;right:0;height:4px;
  background:linear-gradient(90deg,var(--gold),var(--accent),var(--gold));
}
.card-head::after{
  content:'';position:absolute;inset:0;
  background-image:radial-gradient(rgba(255,255,255,.055) 1px, transparent 1px);
  background-size:24px 24px;pointer-events:none;
}

.back-btn{
  position:absolute;top:20px;left:20px;z-index:2;
  width:38px;height:38px;border-radius:50%;
  background:rgba(255,255,255,.12);border:1.5px solid rgba(255,255,255,.2);
  display:flex;align-items:center;justify-content:center;
  color:rgba(255,255,255,.85);font-size:14px;
  text-decoration:none;transition:all .2s;backdrop-filter:blur(8px);
}
.back-btn:hover{background:rgba(255,255,255,.22);color:#fff;}

.mpesa-badge{
  position:absolute;top:20px;right:20px;z-index:2;
  background:rgba(22,163,74,.18);border:1px solid rgba(22,163,74,.35);
  border-radius:50px;padding:5px 12px;
  font-size:10px;font-weight:800;letter-spacing:1.5px;text-transform:uppercase;
  color:#4ade80;backdrop-filter:blur(6px);
  display:flex;align-items:center;gap:5px;
}

.avatar-outer{position:relative;display:inline-block;margin-bottom:-54px;z-index:5;}
.avatar-ring{
  width:108px;height:108px;border-radius:50%;padding:3px;
  background:linear-gradient(135deg,var(--gold),var(--accent));
  box-shadow:0 8px 32px rgba(0,0,0,.35);display:inline-block;
}
.avatar-ring img,.avatar-ring .av-fb{
  width:100%;height:100%;border-radius:50%;object-fit:cover;
  border:4px solid var(--white);background:var(--navy-l);
}
.avatar-ring .av-fb{
  display:flex;align-items:center;justify-content:center;
  font-family:var(--ff);font-size:36px;font-weight:900;color:var(--navy);
}

/* ── CARD BODY ── */
.card-body{padding:66px 28px 32px;text-align:center;}

.support-eyebrow{
  display:inline-flex;align-items:center;gap:6px;
  background:var(--accent-l);border:1px solid rgba(184,18,54,.2);
  color:var(--accent);font-size:10px;font-weight:800;
  letter-spacing:2px;text-transform:uppercase;
  padding:5px 14px;border-radius:50px;margin-bottom:14px;
}
.support-title{
  font-family:var(--ff);
  font-size:clamp(20px,5vw,28px);
  font-weight:900;color:var(--navy);
  line-height:1.2;margin-bottom:6px;
}
.support-title .name{color:var(--accent);}
.support-sub{font-size:13px;color:var(--g600);margin-bottom:24px;line-height:1.6;}

/* Preset amounts */
.amount-presets{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-bottom:16px;}
.preset-btn{
  padding:12px 8px;border-radius:10px;
  font-family:var(--fb);font-size:14px;font-weight:800;
  border:1.5px solid var(--g200);background:var(--g100);color:var(--g600);
  cursor:pointer;transition:all .2s;
}
.preset-btn:hover,.preset-btn.active{
  background:var(--accent);color:var(--white);
  border-color:var(--accent);
  box-shadow:0 4px 14px rgba(184,18,54,.3);
}

/* Form inputs */
.form-group{margin-bottom:14px;text-align:left;}
.form-label{
  display:block;font-size:11px;font-weight:700;
  color:var(--navy);letter-spacing:.5px;text-transform:uppercase;
  margin-bottom:6px;
}
.input-wrap{position:relative;}
.form-input{
  width:100%;height:50px;
  padding:0 16px 0 44px;
  border:1.5px solid var(--g200);border-radius:12px;
  font-family:var(--fb);font-size:14px;color:var(--navy);
  background:#fafbff;outline:none;
  transition:border-color .2s,box-shadow .2s;
}
.form-input:focus{border-color:var(--accent);background:var(--white);box-shadow:0 0 0 3px rgba(184,18,54,.1);}
.form-input::placeholder{color:var(--g400);}
.input-icon{
  position:absolute;left:14px;top:50%;transform:translateY(-50%);
  color:var(--g400);font-size:13px;pointer-events:none;transition:color .2s;
}
.form-input:focus + .input-icon{color:var(--accent);}

/* Big amount input */
.amount-input{
  width:100%;height:72px;
  border:2px solid var(--g200);border-radius:14px;
  font-family:var(--ff);font-size:36px;font-weight:900;
  text-align:center;color:var(--navy);background:#fafbff;
  outline:none;transition:all .2s;
  padding:0 16px;
}
.amount-input:focus{border-color:var(--accent);background:var(--white);box-shadow:0 0 0 4px rgba(184,18,54,.1);}

/* Pay button */
.pay-btn{
  width:100%;height:58px;border:none;border-radius:14px;
  background:linear-gradient(135deg,var(--green),#138a3e);
  color:var(--white);
  font-family:var(--fb);font-size:16px;font-weight:800;
  display:flex;align-items:center;justify-content:center;gap:10px;
  cursor:pointer;
  box-shadow:0 6px 24px rgba(22,163,74,.4);
  transition:all .2s;margin-top:16px;
  letter-spacing:.3px;
}
.pay-btn:hover{background:linear-gradient(135deg,#138a3e,#0e6830);transform:translateY(-2px);box-shadow:0 10px 32px rgba(22,163,74,.5);}
.pay-btn:active{transform:translateY(0);}
.pay-btn:disabled{opacity:.65;cursor:not-allowed;transform:none;}

.form-note{
  text-align:center;font-size:11px;color:var(--g400);
  margin-top:14px;display:flex;align-items:center;justify-content:center;gap:5px;
}

/* ── SUCCESS STATE ── */
.state-box{padding:16px 0 24px;text-align:center;}
.state-icon-wrap{
  width:100px;height:100px;border-radius:50%;
  display:flex;align-items:center;justify-content:center;
  margin:0 auto 20px;font-size:42px;
}
.state-icon-wrap.success{background:var(--green-l);color:var(--green);}
.state-icon-wrap.failed {background:var(--accent-l);color:var(--accent);}
.state-icon-wrap.waiting{background:var(--navy-l);color:var(--navy);}

.state-title{font-family:var(--ff);font-size:24px;font-weight:900;margin-bottom:10px;}
.state-title.success{color:var(--green);}
.state-title.failed {color:var(--accent);}
.state-title.waiting{color:var(--navy);}

.receipt-box{
  background:var(--g100);border:1.5px dashed var(--g200);
  border-radius:12px;padding:18px 20px;margin:16px 0;
  text-align:left;
}
.receipt-row{
  display:flex;justify-content:space-between;align-items:center;
  font-size:13px;color:var(--g600);padding:5px 0;
  border-bottom:1px solid var(--g200);
}
.receipt-row:last-child{border-bottom:none;}
.receipt-row strong{color:var(--navy);}
.receipt-code{color:var(--accent);font-weight:800;font-family:monospace;font-size:14px;}

/* Spinner */
.spinner{
  width:60px;height:60px;border-radius:50%;
  border:5px solid var(--navy-l);
  border-top-color:var(--navy);
  animation:spin 0.9s linear infinite;
  margin:0 auto 20px;
}
@keyframes spin{to{transform:rotate(360deg);}}

/* Page footer */
.page-footer{text-align:center;margin-top:22px;font-size:12px;color:rgba(255,255,255,.32);}
.page-footer a{color:var(--gold);font-weight:700;text-decoration:none;}

@media(max-width:480px){
  .card-body{padding:58px 20px 28px;}
  .card-head{padding:36px 20px 62px;}
  .amount-input{font-size:28px;height:60px;}
}
</style>
</head>
<body>

<div class="support-wrap">
  <div class="card">

    <!-- Header -->
    <div class="card-head">
      <a href="/<?= htmlspecialchars($username) ?>" class="back-btn" title="Back to profile">
        <i class="fas fa-arrow-left"></i>
      </a>
      <div class="mpesa-badge">
        <i class="fas fa-mobile-alt"></i> M-Pesa
      </div>

      <div class="avatar-outer">
        <div class="avatar-ring">
          <img src="msanii/uploads/<?= htmlspecialchars($profilePic) ?>"
               alt="<?= htmlspecialchars($displayName) ?>"
               onerror="this.style.display='none';this.nextElementSibling.style.display='flex'"/>
          <div class="av-fb" style="display:none;"><?= $initial ?></div>
        </div>
      </div>
    </div>

    <!-- Body -->
    <div class="card-body">

      <?php if ($just_paid): ?>
      <!-- ── SUCCESS ── -->
      <div class="state-box">
        <div class="state-icon-wrap success"><i class="fas fa-check-circle"></i></div>
        <div class="state-title success">Payment Successful!</div>
        <p style="font-size:14px;color:var(--g600);margin-bottom:16px;">
          Your support for <strong style="color:var(--navy);"><?= htmlspecialchars($displayName) ?></strong> was received. Thank you! 🎉
        </p>
        <div class="receipt-box">
          <div class="receipt-row">
            <span>Amount Sent</span>
            <strong>KES <?= number_format($paid_amount) ?></strong>
          </div>
          <div class="receipt-row">
            <span>M-Pesa Receipt</span>
            <span class="receipt-code"><?= htmlspecialchars($receipt) ?></span>
          </div>
          <div class="receipt-row">
            <span>Creator</span>
            <strong><?= htmlspecialchars($displayName) ?></strong>
          </div>
        </div>
        <p style="font-size:13px;color:var(--g400);">
          Redirecting in <span id="countdown" style="color:var(--navy);font-weight:800;">7</span>s…
        </p>
      </div>
      <script>
        let sec = 7;
        const t = setInterval(() => {
          document.getElementById('countdown').textContent = --sec;
          if (sec <= 0) { clearInterval(t); location.href = '/<?= htmlspecialchars($username) ?>'; }
        }, 1000);
      </script>

      <?php elseif ($payment_failed): ?>
      <!-- ── FAILED ── -->
      <div class="state-box">
        <div class="state-icon-wrap failed"><i class="fas fa-times-circle"></i></div>
        <div class="state-title failed">Payment Failed</div>
        <p style="font-size:14px;color:var(--g600);margin-bottom:20px;">
          The transaction was cancelled or timed out. Please try again.
        </p>
        <a href="support.php?creator=<?= $creator_id ?>"
           style="display:inline-flex;align-items:center;gap:8px;padding:12px 28px;background:var(--accent);color:#fff;border-radius:50px;font-weight:700;font-size:14px;text-decoration:none;transition:all .2s;"
           onmouseover="this.style.background='var(--accent-d)'"
           onmouseout="this.style.background='var(--accent)'">
          <i class="fas fa-redo"></i> Try Again
        </a>
      </div>
      <script>setTimeout(() => location.href = '/<?= htmlspecialchars($username) ?>', 10000);</script>

      <?php elseif ($waiting_payment): ?>
      <!-- ── WAITING ── -->
      <div class="state-box">
        <div class="spinner"></div>
        <div class="state-title waiting">Waiting for Payment…</div>
        <p style="font-size:14px;color:var(--g600);margin-bottom:8px;">
          Check your phone and enter your <strong>M-Pesa PIN</strong> to complete payment.
        </p>
        <p style="font-size:12px;color:var(--g400);">Auto-checking every 8 seconds…</p>
      </div>
      <script>setInterval(() => location.reload(), 8000);</script>
      <script>
        document.addEventListener('DOMContentLoaded', () => {
          Swal.fire({
            icon:'success',
            title:'STK Push Sent! 📲',
            html:'<p style="font-size:14px;color:#4a5578;">Check your phone and enter your <strong>M-Pesa PIN</strong> to authorize the payment.</p>',
            timer:7000,timerProgressBar:true,
            allowOutsideClick:false,
            confirmButtonColor:'#032d5d'
          });
        });
      </script>

      <?php else: ?>
      <!-- ── PAYMENT FORM ── -->
      <div class="support-eyebrow"><i class="fas fa-heart"></i> Send a tip</div>
      <h1 class="support-title">
        Support <span class="name"><?= htmlspecialchars($displayName) ?></span>
      </h1>
      <p class="support-sub">Send a tip via M-Pesa. Every shilling counts!</p>

      <form method="POST" id="supportForm">

        <!-- Quick presets -->
        <div class="amount-presets">
          <button type="button" class="preset-btn" onclick="setAmt(50,this)">KES 50</button>
          <button type="button" class="preset-btn active" onclick="setAmt(100,this)">KES 100</button>
          <button type="button" class="preset-btn" onclick="setAmt(250,this)">KES 250</button>
          <button type="button" class="preset-btn" onclick="setAmt(500,this)">KES 500</button>
          <button type="button" class="preset-btn" onclick="setAmt(1000,this)">KES 1,000</button>
          <button type="button" class="preset-btn" onclick="setAmt(0,this)">Custom</button>
        </div>

        <!-- Amount -->
        <div class="form-group">
          <label class="form-label">Amount (KES)</label>
          <input type="number" name="amount" id="amountInput"
                 value="100" min="1" required
                 class="amount-input"
                 placeholder="Enter amount">
        </div>

        <!-- Name -->
        <div class="form-group">
          <label class="form-label">Your Name (optional)</label>
          <div class="input-wrap">
            <input type="text" name="name"
                   class="form-input"
                   placeholder="e.g. Jane Doe"
                   value="<?= htmlspecialchars($_POST['name'] ?? '') ?>">
            <i class="fas fa-user input-icon"></i>
          </div>
        </div>

        <!-- Phone -->
        <div class="form-group">
          <label class="form-label">Safaricom Number <span style="color:var(--accent);">*</span></label>
          <div class="input-wrap">
            <input type="text" name="phone" required
                   class="form-input"
                   placeholder="0712 345 678"
                   value="<?= htmlspecialchars($_POST['phone'] ?? '07') ?>">
            <i class="fas fa-mobile-alt input-icon"></i>
          </div>
        </div>

        <button type="submit" class="pay-btn" id="payBtn">
          <i class="fas fa-mobile-alt" style="font-size:18px;"></i>
          <span id="btnText">Send M-Pesa Tip</span>
        </button>

        <p class="form-note">
          <i class="fas fa-lock"></i>
          Secured by Safaricom M-Pesa. No card required.
        </p>
      </form>
      <?php endif; ?>

    </div><!-- /card-body -->
  </div><!-- /card -->

  <div class="page-footer">
    <p>© Keychele <?= date('Y') ?> · <a href="index.php">Back to Keychele</a></p>
  </div>
</div>

<script>
function setAmt(v, el) {
  const input = document.getElementById('amountInput');
  if (v > 0) input.value = v;
  else { input.value = ''; input.focus(); }
  document.querySelectorAll('.preset-btn').forEach(b => b.classList.remove('active'));
  el.classList.add('active');
}

// Remove active from presets if user types manually
document.getElementById('amountInput')?.addEventListener('input', function() {
  document.querySelectorAll('.preset-btn').forEach(b => b.classList.remove('active'));
});

document.getElementById('supportForm')?.addEventListener('submit', function() {
  const btn  = document.getElementById('payBtn');
  const text = document.getElementById('btnText');
  btn.disabled = true;
  text.textContent = 'Sending Push, please wait…';
});
</script>

</body>
</html>