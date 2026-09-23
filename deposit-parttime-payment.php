<?php
session_start();
include 'msanii/includes/connect.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require 'vendor/autoload.php';
require_once 'msanii/includes/deposit_schema.php';

// ─────────────────────────────────────────────────────────────
// ENSURE deposit_purchases TABLE EXISTS (+ balance/fan-linkage columns)
// ─────────────────────────────────────────────────────────────
kc_ensure_deposit_purchases_table($pdo);

// ─────────────────────────────────────────────────────────────
// REQUIRE LOGIN — deposit purchases must be tied to a buyer account so the
// balance can be paid later from the same account (see pay-balance.php and
// the Deposits tab on account-dashboard.php). Closes the direct-URL bypass
// around ticket.php's login-gate modal too.
// ─────────────────────────────────────────────────────────────
if (empty($_SESSION['fan_id'])) {
    header("Location: /account-login.php?redirect=" . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

// ─────────────────────────────────────────────────────────────
// MPESA — Paybill STK push via the shared Daraja client (same config as
// ticket.php; credentials never live in this file — see
// msanii/includes/daraja.php).
// ─────────────────────────────────────────────────────────────
require_once 'msanii/includes/daraja.php';

// AccountReference on the customer's prompt = the event creator's username.
function depSTKPush($phone, $amount, $reference, $desc, $creator_username = null) {
    global $pdo;
    $res = kc_daraja_stk_push(
        $pdo, $phone, (int)$amount,
        kc_daraja_account_reference($creator_username, $reference),
        $desc,
        'deposit_callback.php'
    );
    return $res['ok'] ? $res['checkout_request_id'] : false;
}

// ─────────────────────────────────────────────────────────────
// LOAD EVENT + TICKET TYPE
// ─────────────────────────────────────────────────────────────
$event_id      = (int)($_GET['event_id']       ?? 0);
$ticket_type_id = (int)($_GET['ticket_type_id'] ?? 0);

if ($event_id <= 0 || $ticket_type_id <= 0) {
    header('Location: /'); exit;
}

// Fetch event
$stmt = $pdo->prepare("
    SELECT t.*, c.email AS creator_email, c.username AS creator_username, mp.full_name AS organizer_name
    FROM tickets t
    LEFT JOIN creators c  ON t.creator_id = c.id
    LEFT JOIN my_page  mp ON c.id = mp.creator_id
    WHERE t.id = ?");
$stmt->execute([$event_id]);
$event = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$event) { header('Location: /'); exit; }
$event['creator_email'] = kc_decrypt($event['creator_email']);
$event['organizer_name'] = kc_decrypt($event['organizer_name']);

// Fetch ticket type — must have deposit enabled
$stmt = $pdo->prepare("
    SELECT ett.*,
           GREATEST(ett.quantity_available - ett.quantity_sold, 0) AS slots_left
    FROM event_ticket_types ett
    WHERE ett.id = ? AND ett.event_id = ? AND ett.is_active = 1
      AND ett.deposit_enabled = 1 AND ett.deposit_amount > 0 AND ett.price > 0");
$stmt->execute([$ticket_type_id, $event_id]);
$ttype = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$ttype) { header("Location: /ticket/{$event_id}"); exit; }

// Deadline check
$deadline_passed = !empty($event['deadline'])
    && strtotime($event['deadline'] . ' 23:59:59') < time();

$deposit_amount = (float)$ttype['deposit_amount'];
$full_price     = (float)$ttype['price'];
$balance_due    = $full_price - $deposit_amount;
$banner_url     = 'msanii/uploads/banners/' . htmlspecialchars($event['banner'] ?: 'default-event.jpg');

// ─────────────────────────────────────────────────────────────
// SEND DEPOSIT RECEIPT EMAIL
// ─────────────────────────────────────────────────────────────
function sendDepositReceipt($event, $ttype, $dep_ref, $buyer_name, $buyer_email,
                             $quantity, $deposit_amount, $full_price, $balance_due) {
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = 'mail.keychele.co.ke';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'tickets@keychele.co.ke';
        $mail->Password   = 'King20241064.';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;
        $mail->SMTPOptions = ['ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true]];
        $mail->setFrom('tickets@keychele.co.ke', 'Keychele Tickets');
        $mail->addAddress($buyer_email, $buyer_name);
        $mail->isHTML(true);
        $mail->Subject = 'Deposit Confirmed — ' . htmlspecialchars($event['title']);

        $per_ticket_dep = number_format($deposit_amount / max(1, $quantity), 2);
        $per_ticket_bal = number_format($balance_due / max(1, $quantity), 2);

        $mail->Body = "
<!DOCTYPE html><html><head><meta charset='UTF-8'>
<link href='https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600;700;800&display=swap' rel='stylesheet'>
</head>
<body style='margin:0;padding:0;background:#eef2fa;font-family:Montserrat,Arial,sans-serif;'>
<table width='100%' cellpadding='0' cellspacing='0' style='background:#eef2fa;padding:32px 0;'>
<tr><td align='center'>
<table width='580' cellpadding='0' cellspacing='0' style='background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 8px 32px rgba(5,46,100,0.15);'>

  <!-- Header -->
  <tr><td style='background:#052e64;padding:0;'>
    <table width='100%' cellpadding='0' cellspacing='0'>
      <tr><td style='padding:28px 40px 16px;text-align:center;'>
        <p style='margin:0;color:#fff;font-size:28px;font-weight:800;letter-spacing:4px;'>KEYCHELE</p>
        <p style='margin:6px 0 0;color:#fec411;font-size:10px;font-weight:700;letter-spacing:3px;'>DEPOSIT RECEIPT</p>
      </td></tr>
      <tr><td style='background:#fec411;height:4px;'></td></tr>
    </table>
  </td></tr>

  <!-- Deposit badge -->
  <tr><td style='background:#0891b2;padding:16px 40px;text-align:center;'>
    <p style='color:rgba(255,255,255,0.7);font-size:10px;letter-spacing:3px;font-weight:700;margin:0 0 4px;'>DEPOSIT REFERENCE</p>
    <p style='color:#fff;font-size:19px;font-weight:800;font-family:\"Courier New\",monospace;letter-spacing:3px;margin:0;'>" . htmlspecialchars($dep_ref) . "</p>
  </td></tr>

  <!-- Greeting -->
  <tr><td style='padding:26px 40px 14px;'>
    <p style='font-size:16px;color:#052e64;font-weight:700;margin:0 0 8px;'>Hello, " . htmlspecialchars($buyer_name) . "!</p>
    <p style='font-size:14px;color:#4a5578;line-height:1.75;margin:0;'>
      Your deposit for <strong style='color:#052e64;'>" . htmlspecialchars($event['title']) . "</strong>
      has been received. Your spot is reserved — please pay the remaining balance before the event deadline to receive your ticket.
    </p>
  </td></tr>

  <!-- Summary table -->
  <tr><td style='padding:8px 40px 24px;'>
    <table width='100%' cellpadding='0' cellspacing='0' style='border:1.5px solid #dde6f5;border-radius:10px;overflow:hidden;'>
      <tr style='background:#f5f8ff;'><td colspan='2' style='padding:13px 18px;border-bottom:1px solid #dde6f5;'>
        <p style='margin:0;font-size:9px;color:#8a9ab8;letter-spacing:2px;font-weight:700;'>EVENT</p>
        <p style='margin:4px 0 0;font-size:14px;color:#052e64;font-weight:700;'>" . htmlspecialchars($event['title']) . "</p>
      </td></tr>
      <tr>
        <td style='padding:12px 18px;border-bottom:1px solid #dde6f5;border-right:1px solid #dde6f5;width:50%;'>
          <p style='margin:0;font-size:9px;color:#8a9ab8;letter-spacing:2px;font-weight:700;'>DATE</p>
          <p style='margin:4px 0 0;font-size:13px;color:#052e64;font-weight:700;'>" . date('D, d M Y', strtotime($event['start_date'])) . "</p>
        </td>
        <td style='padding:12px 18px;border-bottom:1px solid #dde6f5;'>
          <p style='margin:0;font-size:9px;color:#8a9ab8;letter-spacing:2px;font-weight:700;'>VENUE</p>
          <p style='margin:4px 0 0;font-size:13px;color:#052e64;font-weight:700;'>" . htmlspecialchars($event['venue'] ?? 'TBA') . "</p>
        </td>
      </tr>
      <tr>
        <td style='padding:12px 18px;border-bottom:1px solid #dde6f5;border-right:1px solid #dde6f5;'>
          <p style='margin:0;font-size:9px;color:#8a9ab8;letter-spacing:2px;font-weight:700;'>TICKET TYPE</p>
          <p style='margin:4px 0 0;font-size:13px;color:#052e64;font-weight:700;'>" . htmlspecialchars($ttype['type_name']) . "</p>
        </td>
        <td style='padding:12px 18px;border-bottom:1px solid #dde6f5;'>
          <p style='margin:0;font-size:9px;color:#8a9ab8;letter-spacing:2px;font-weight:700;'>QUANTITY</p>
          <p style='margin:4px 0 0;font-size:13px;color:#052e64;font-weight:700;'>" . (int)$quantity . " ticket" . ($quantity > 1 ? 's' : '') . "</p>
        </td>
      </tr>

      <!-- Payment breakdown -->
      <tr style='background:#f0f9ff;'><td colspan='2' style='padding:13px 18px;border-bottom:1px solid #dde6f5;'>
        <p style='margin:0;font-size:9px;color:#0891b2;letter-spacing:2px;font-weight:700;'>✓ DEPOSIT PAID NOW</p>
        <p style='margin:4px 0 0;font-size:22px;color:#0891b2;font-weight:800;'>KES " . number_format($deposit_amount, 2) . "</p>
        <p style='margin:2px 0 0;font-size:11px;color:#8a9ab8;'>(KES " . $per_ticket_dep . " per ticket)</p>
      </td></tr>
      <tr style='background:#fffdf0;'><td colspan='2' style='padding:13px 18px;border-bottom:1px solid #dde6f5;'>
        <p style='margin:0;font-size:9px;color:#d97706;letter-spacing:2px;font-weight:700;'>⏳ BALANCE DUE BEFORE EVENT</p>
        <p style='margin:4px 0 0;font-size:22px;color:#d97706;font-weight:800;'>KES " . number_format($balance_due, 2) . "</p>
        <p style='margin:2px 0 0;font-size:11px;color:#8a9ab8;'>(KES " . $per_ticket_bal . " per ticket)</p>
      </td></tr>
      <tr style='background:#f8f9fd;'><td colspan='2' style='padding:13px 18px;'>
        <p style='margin:0;font-size:9px;color:#8a9ab8;letter-spacing:2px;font-weight:700;'>FULL TICKET PRICE</p>
        <p style='margin:4px 0 0;font-size:16px;color:#052e64;font-weight:800;'>KES " . number_format($full_price * $quantity, 2) . "</p>
      </td></tr>
    </table>
  </td></tr>

  <!-- Important notice -->
  <tr><td style='padding:0 40px 28px;'>
    <div style='border:2px solid #fec411;background:#fffdf0;border-radius:10px;padding:14px 18px;'>
      <p style='margin:0 0 6px;font-size:13px;color:#92400e;font-weight:800;font-family:Montserrat,Arial,sans-serif;'>⚠️ Important — Action Required</p>
      <p style='margin:0;font-size:12px;color:#78350f;line-height:1.65;'>
        Your ticket PDF will only be issued after the full balance is paid.
        Return to Keychele and complete your payment before the event deadline to guarantee entry.
        Your deposit reference is <strong>" . htmlspecialchars($dep_ref) . "</strong> — keep it safe.
      </p>
    </div>
  </td></tr>

  <!-- Footer -->
  <tr><td style='background:#052e64;padding:0;'>
    <table width='100%' cellpadding='0' cellspacing='0'>
      <tr><td style='background:#fec411;height:3px;'></td></tr>
      <tr><td style='padding:16px 40px;text-align:center;'>
        <p style='color:#7a9acc;font-size:12px;margin:0;'>
          Questions? Contact the organizer &nbsp;|&nbsp;
          <a href='https://keychele.co.ke' style='color:#fec411;'>keychele.co.ke</a>
        </p>
        <p style='color:#4a6a99;font-size:11px;margin:6px 0 0;'>© Keychele · Powered by Mecrofex Technologies</p>
      </td></tr>
    </table>
  </td></tr>

</table></td></tr></table>
</body></html>";
        $mail->send();

        // Notify creator
        $mail->clearAddresses();
        $mail->addAddress($event['creator_email']);
        $mail->Subject = 'New Deposit Received — ' . htmlspecialchars($event['title']);
        $mail->Body    = "<h2 style='color:#052e64;'>New deposit received on Keychele</h2>
<p><b>Buyer:</b> " . htmlspecialchars($buyer_name) . " &lt;" . htmlspecialchars($buyer_email) . "&gt;</p>
<p><b>Ticket Type:</b> " . htmlspecialchars($ttype['type_name']) . " &nbsp;·&nbsp; <b>Qty:</b> {$quantity}</p>
<p><b>Deposit Paid:</b> KES " . number_format($deposit_amount, 2) . "</p>
<p><b>Balance Due:</b> KES " . number_format($balance_due, 2) . "</p>
<p><b>Reference:</b> " . htmlspecialchars($dep_ref) . "</p>
<p><b>Event:</b> " . htmlspecialchars($event['title']) . "</p>";
        $mail->send();
    } catch (Exception $e) {
        error_log('Deposit email failed: ' . $mail->ErrorInfo);
    }
}

// ─────────────────────────────────────────────────────────────
// STATE FLAGS
// ─────────────────────────────────────────────────────────────
$just_paid = $waiting = $failed = false;
$dep_ref_display = $buyer_email_display = $buyer_name_display = '';
$deposit_display = $balance_display = 0;
$error = '';

// Check if returning from a pending STK push
if (isset($_SESSION['pending_deposit_checkout'])) {
    $cid  = $_SESSION['pending_deposit_checkout'];
    $stmt = $pdo->prepare("SELECT * FROM deposit_purchases WHERE checkout_request_id = ?");
    $stmt->execute([$cid]);
    $dep_row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($dep_row) { $dep_row = kc_decrypt_row($dep_row, ['buyer_name', 'buyer_email', 'buyer_phone']); }

    if ($dep_row) {
        if ($dep_row['status'] === 'completed') {
            $just_paid           = true;
            $dep_ref_display     = $dep_row['deposit_ref'];
            $buyer_email_display = $dep_row['buyer_email'];
            $buyer_name_display  = $dep_row['buyer_name'];
            $deposit_display     = (float)$dep_row['deposit_amount'];
            $balance_display     = (float)$dep_row['balance_due'];
            unset($_SESSION['pending_deposit_checkout']);
        } elseif ($dep_row['status'] === 'failed') {
            $failed = true;
            $error  = 'Payment was not completed. Please try again.';
            unset($_SESSION['pending_deposit_checkout']);
        } else {
            $waiting = true;
            // Restore GET params from session so the waiting screen
            // knows which event/type it's for
            $event_id       = $dep_row['event_id'];
            $ticket_type_id = $dep_row['ticket_type_id'];
        }
    } else {
        unset($_SESSION['pending_deposit_checkout']);
    }
}

// ─────────────────────────────────────────────────────────────
// HANDLE FORM POST
// ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$just_paid && !$waiting) {

    if ($deadline_passed) {
        $error = 'Ticket sales have closed for this event.';
    } elseif ($ttype['slots_left'] <= 0) {
        $error = 'Sorry — this ticket type is sold out.';
    } else {
        $name      = trim($_POST['name']  ?? '');
        // Never trust a submitted email over the logged-in account's — the
        // field is read-only in the UI specifically so fan_id stays a
        // trustworthy link to the email a ticket/receipt actually goes to.
        $email     = $_SESSION['fan_email'];
        $phone_raw = preg_replace('/\D/', '', $_POST['phone'] ?? '');
        $quantity  = max(1, (int)($_POST['quantity'] ?? 1));

        // Normalise phone
        $phone = $phone_raw;
        if (strlen($phone_raw) === 10 && in_array(substr($phone_raw, 0, 2), ['07', '01']))
            $phone = '254' . substr($phone_raw, 1);
        elseif (strlen($phone_raw) === 9 && in_array(substr($phone_raw, 0, 1), ['7', '1']))
            $phone = '254' . $phone_raw;
        elseif (strlen($phone_raw) === 12 && substr($phone_raw, 0, 3) === '254')
            $phone = $phone_raw;
        elseif (strlen($phone_raw) === 13 && substr($phone_raw, 0, 4) === '+254')
            $phone = substr($phone_raw, 1);

        if (empty($name) || empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)
            || !preg_match('/^254[17][0-9]{8}$/', $phone)) {
            $error = 'Please fill in all fields correctly. Phone must be a valid Kenyan number (e.g. 0712345678).';
        } elseif ($quantity > $ttype['slots_left']) {
            $error = "Only {$ttype['slots_left']} slot(s) remaining for this ticket type.";
        } else {
            $total_deposit = $deposit_amount * $quantity;
            $total_balance = $balance_due    * $quantity;
            $total_full    = $full_price     * $quantity;

            $dep_ref = 'DEP-' . date('ymdHis') . strtoupper(substr(uniqid('', true), -4));

            // Insert pending row
            $ins = $pdo->prepare("INSERT INTO deposit_purchases
                (event_id, ticket_type_id, buyer_name, buyer_email, buyer_email_bidx, buyer_phone, fan_id,
                 deposit_ref, deposit_amount, full_price, balance_due, quantity, status)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,'pending')");
            $ins->execute([
                $event_id, $ticket_type_id, kc_encrypt($name), kc_encrypt($email), kc_email_index($email), kc_encrypt($phone), $_SESSION['fan_id'],
                $dep_ref, $total_deposit, $total_full, $total_balance, $quantity,
            ]);

            $checkout_id = depSTKPush(
                $phone,
                (int)$total_deposit,
                $dep_ref,
                'Deposit: ' . mb_strimwidth($ttype['type_name'], 0, 20) . ' - ' . $dep_ref,
                $event['creator_username'] ?? null
            );

            if ($checkout_id) {
                $pdo->prepare("UPDATE deposit_purchases SET checkout_request_id = ? WHERE deposit_ref = ?")
                    ->execute([$checkout_id, $dep_ref]);
                $_SESSION['pending_deposit_checkout'] = $checkout_id;
                header("Location: /deposit-parttime-payment?event_id={$event_id}&ticket_type_id={$ticket_type_id}");
                exit;
            } else {
                $pdo->prepare("DELETE FROM deposit_purchases WHERE deposit_ref = ?")->execute([$dep_ref]);
                $error = 'Could not initiate M-Pesa payment. Please try again.';
            }
        }
    }
}

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1.0"/>
<title>Pay Deposit — <?= h($event['title'] ?? '') ?> — Keychele</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
img { display: block; max-width: 100%; }

:root {
    --navy:      #052e64; --navy-d: #031e44; --navy-m: #0a4a99; --navy-l: #e8eef8; --navy-xl: #f2f5fc;
    --gold:      #fec411; --gold-d: #d9a70a; --gold-l: #fffbea;
    --crimson:   #bf1339; --crimson-d: #96102d; --crimson-l: #fce8ec;
    --teal:      #0891b2; --teal-d: #0e7490; --teal-l: #e0f7fc;
    --amber:     #d97706; --amber-l: #fffbeb;
    --green:     #16a34a;
    --white:     #ffffff;
    --g50:       #f8f9fd; --g100: #eef1f9; --g200: #dce2f0; --g400: #8a97b8; --g600: #4a5578; --g800: #1a2340;
    --fh: 'Montserrat', system-ui, sans-serif;
    --fm: 'Courier New', monospace;
}

html { scroll-behavior: smooth; }
body {
    font-family: var(--fh);
    background: var(--navy-d);
    color: var(--g600);
    min-height: 100vh;
    -webkit-font-smoothing: antialiased;
}

/* ── Background ──────────────────────────── */
.bg-scene {
    position: fixed; inset: 0; z-index: 0;
    background-image: url('/<?= $banner_url ?>');
    background-size: cover; background-position: center;
    filter: blur(24px) brightness(0.12) saturate(0.35);
    transform: scale(1.06);
}
.bg-veil {
    position: fixed; inset: 0; z-index: 1;
    background: linear-gradient(150deg, rgba(5,46,100,.7) 0%, rgba(5,46,100,.2) 40%, rgba(3,15,35,.95) 100%);
}

/* ── Nav ─────────────────────────────────── */
.topnav {
    position: relative; z-index: 30;
    height: 62px; display: flex; align-items: center; justify-content: space-between;
    padding: 0 32px;
    background: rgba(5,46,100,.97);
    border-bottom: 3px solid var(--gold);
}
.nb { font-weight: 900; font-size: 21px; letter-spacing: 3px; color: #fff; text-decoration: none; }
.nb span { color: var(--gold); }
.bc { display: flex; align-items: center; gap: 6px; font-size: 12px; color: rgba(255,255,255,.4); }
.bc a { color: rgba(255,255,255,.55); text-decoration: none; transition: color .2s; }
.bc a:hover { color: var(--gold); }
.bc .s { color: rgba(255,255,255,.2); }

/* ── Wrapper ─────────────────────────────── */
.mw {
    position: relative; z-index: 5;
    max-width: 960px; margin: 0 auto;
    padding: 40px 20px 90px;
}

/* ── Page header ─────────────────────────── */
.pg-hero {
    background: linear-gradient(120deg, #031e44 0%, #0a3a7a 60%, #0e7490 100%);
    border-radius: 20px;
    padding: 2rem 2.25rem;
    margin-bottom: 2rem;
    border: 1px solid rgba(255,255,255,0.07);
    box-shadow: 0 16px 50px rgba(0,0,0,.4);
    position: relative; overflow: hidden;
}
.pg-hero::before {
    content: ''; position: absolute; inset: 0; pointer-events: none;
    background-image: linear-gradient(rgba(255,255,255,.025) 1px, transparent 1px),
                      linear-gradient(90deg, rgba(255,255,255,.025) 1px, transparent 1px);
    background-size: 30px 30px;
}
.pg-hero-orb {
    position: absolute; border-radius: 50%; pointer-events: none;
    width: 260px; height: 260px; top: -80px; right: -50px;
    background: radial-gradient(circle, rgba(254,196,17,.15) 0%, transparent 65%);
}
.pg-hero-inner { position: relative; z-index: 1; display: flex; align-items: center; justify-content: space-between; gap: 1rem; }
.pg-hero-tag {
    display: inline-flex; align-items: center; gap: 6px;
    background: rgba(8,145,178,.18); border: 1px solid rgba(8,145,178,.35);
    border-radius: 100px; padding: 4px 12px;
    font-size: 0.64rem; font-weight: 700; letter-spacing: 0.1em; text-transform: uppercase;
    color: #67e8f9; margin-bottom: 0.65rem;
}
.pg-hero-title { font-weight: 900; font-size: clamp(1.4rem, 3vw, 1.9rem); color: #fff; letter-spacing: -0.03em; line-height: 1.2; margin-bottom: 0.35rem; }
.pg-hero-sub { font-size: 0.82rem; color: rgba(255,255,255,.5); }
.pg-hero-event {
    display: inline-flex; align-items: center; gap: 7px;
    background: rgba(255,255,255,.09); border: 1px solid rgba(255,255,255,.16);
    border-radius: 100px; padding: 5px 14px;
    font-size: 0.77rem; color: rgba(255,255,255,.75); font-weight: 600;
    margin-top: 0.65rem; max-width: 100%; overflow: hidden;
}
.pg-hero-event span { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.pg-hero-icon { font-size: 3rem; color: rgba(255,255,255,.1); flex-shrink: 0; }
@media (max-width: 480px) { .pg-hero-icon { display: none; } }

/* ── Two-column grid ─────────────────────── */
.dep-grid { display: grid; grid-template-columns: 1fr 420px; gap: 28px; align-items: start; }
@media (max-width: 920px) { .dep-grid { grid-template-columns: 1fr; } }

/* ── Card ────────────────────────────────── */
.card {
    background: #fff; border-radius: 18px; overflow: hidden;
    box-shadow: 0 10px 40px rgba(5,46,100,.22);
    margin-bottom: 20px;
}
.card-head {
    padding: 1.1rem 1.5rem 0.9rem;
    border-bottom: 1px solid var(--g100);
    background: #fafbfd;
    display: flex; align-items: center; gap: 12px;
}
.card-head-ico {
    width: 38px; height: 38px; border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    font-size: 0.9rem; flex-shrink: 0;
}
.card-head-title { font-weight: 800; font-size: 0.92rem; color: var(--navy); }
.card-head-sub { font-size: 0.73rem; color: var(--g400); margin-top: 2px; }
.card-body { padding: 1.5rem; }

/* ── Deposit summary card ────────────────── */
.dep-summary {
    background: linear-gradient(135deg, #031e44 0%, #052e64 55%, #073878 100%);
    border-radius: 18px; overflow: hidden;
    box-shadow: 0 10px 40px rgba(0,0,0,.3);
    margin-bottom: 20px; border: 1px solid rgba(255,255,255,.07);
}
.dep-summary-head {
    padding: 1.25rem 1.5rem 1rem;
    border-bottom: 1px solid rgba(255,255,255,.08);
    display: flex; align-items: center; gap: 12px;
}
.dep-summary-ico {
    width: 42px; height: 42px; border-radius: 11px;
    background: rgba(8,145,178,.2); border: 1px solid rgba(8,145,178,.35);
    display: flex; align-items: center; justify-content: center;
    font-size: 1rem; color: #67e8f9; flex-shrink: 0;
}
.dep-summary-title { font-weight: 800; font-size: 0.92rem; color: #fff; }
.dep-summary-sub { font-size: 0.72rem; color: rgba(255,255,255,.4); margin-top: 2px; }
.dep-summary-body { padding: 1.5rem; }

.dep-split {
    display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem; margin-bottom: 1rem;
}
.dep-split-box {
    border-radius: 12px; padding: 1rem 1.1rem;
    border: 1px solid rgba(255,255,255,.1);
}
.dep-split-box.pay-now { background: rgba(8,145,178,.18); border-color: rgba(8,145,178,.35); }
.dep-split-box.pay-later { background: rgba(217,119,6,.15); border-color: rgba(217,119,6,.3); }
.dsb-label {
    font-size: 0.62rem; font-weight: 700; text-transform: uppercase;
    letter-spacing: 0.09em; margin-bottom: 0.4rem;
    display: flex; align-items: center; gap: 5px;
}
.dep-split-box.pay-now  .dsb-label { color: #67e8f9; }
.dep-split-box.pay-later .dsb-label { color: #fcd34d; }
.dsb-amount { font-weight: 900; font-size: 1.4rem; letter-spacing: -0.03em; line-height: 1; }
.dep-split-box.pay-now  .dsb-amount { color: #22d3ee; }
.dep-split-box.pay-later .dsb-amount { color: #fcd34d; }
.dsb-sub { font-size: 0.68rem; color: rgba(255,255,255,.4); margin-top: 4px; }

.dep-full-row {
    background: rgba(255,255,255,.06); border: 1px solid rgba(255,255,255,.1);
    border-radius: 10px; padding: 0.9rem 1rem;
    display: flex; align-items: center; justify-content: space-between;
    margin-bottom: 1rem;
}
.dfr-label { font-size: 0.72rem; color: rgba(255,255,255,.45); font-weight: 600; }
.dfr-val { font-weight: 800; font-size: 1.05rem; color: #fff; }

.dep-warning {
    background: rgba(217,119,6,.12); border: 1.5px solid rgba(217,119,6,.3);
    border-radius: 10px; padding: 0.85rem 1rem;
    display: flex; gap: 10px; align-items: flex-start;
}
.dep-warning i { color: #fcd34d; font-size: 0.85rem; margin-top: 1px; flex-shrink: 0; }
.dep-warning-text { font-size: 0.76rem; color: rgba(255,255,255,.65); line-height: 1.6; }
.dep-warning-text strong { color: #fcd34d; font-weight: 700; }

/* ── Event info strip ────────────────────── */
.event-strip {
    background: var(--g50); border: 1px solid var(--g100);
    border-radius: 12px; padding: 1rem 1.1rem;
    display: flex; gap: 12px; align-items: center; margin-bottom: 1.25rem;
}
.es-thumb {
    width: 52px; height: 52px; border-radius: 10px;
    object-fit: cover; flex-shrink: 0; border: 1px solid var(--g100);
}
.es-title { font-weight: 800; font-size: 0.9rem; color: var(--navy); line-height: 1.3; }
.es-meta { font-size: 0.72rem; color: var(--g400); margin-top: 3px; }
.es-meta i { color: var(--crimson); font-size: 0.65rem; margin-right: 3px; }

/* ── Ticket type badge ───────────────────── */
.type-badge {
    display: inline-flex; align-items: center; gap: 8px;
    background: var(--teal-l); border: 1.5px solid rgba(8,145,178,.3);
    border-radius: 10px; padding: 8px 14px; margin-bottom: 1.25rem;
    font-weight: 700; font-size: 0.82rem; color: var(--teal-d);
}
.type-badge i { font-size: 0.8rem; }

/* ── Quantity selector ───────────────────── */
.qty-field { margin-bottom: 1.25rem; }
.qty-field label { display: block; font-weight: 700; font-size: 0.76rem; color: var(--navy); margin-bottom: 0.5rem; letter-spacing: 0.01em; }
.qty-row {
    display: flex; align-items: center;
    border: 1.5px solid var(--g200); border-radius: 10px; overflow: hidden;
    width: fit-content; min-width: 150px;
}
.qty-btn {
    width: 44px; height: 44px; border: none; cursor: pointer;
    background: var(--g50); color: var(--navy); font-size: 20px;
    display: flex; align-items: center; justify-content: center;
    transition: background .15s; flex-shrink: 0;
}
.qty-btn:hover { background: var(--navy-l); }
.qty-btn:disabled { opacity: .35; cursor: not-allowed; }
.qty-num {
    flex: 1; border: none; background: #fff; text-align: center;
    font-family: var(--fh); font-weight: 800; font-size: 16px; color: var(--navy);
    min-width: 60px;
}
.qty-hint { font-size: 0.7rem; color: var(--g400); margin-top: 5px; }

/* ── Form fields ─────────────────────────── */
.fg { margin-bottom: 1.1rem; }
.fl { display: block; font-weight: 700; font-size: 0.75rem; color: var(--navy); margin-bottom: 0.45rem; }
.fi {
    width: 100%; padding: 11px 14px 11px 42px;
    border: 1.5px solid var(--g200); border-radius: 10px;
    font-family: var(--fh); font-size: 0.88rem; color: var(--navy);
    transition: border-color .2s, box-shadow .2s; background: #fafafa;
}
.fi:focus {
    border-color: var(--teal); background: #fff;
    box-shadow: 0 0 0 3px rgba(8,145,178,.08); outline: none;
}
.fi-wrap { position: relative; }
.fi-ico {
    position: absolute; left: 13px; top: 50%; transform: translateY(-50%);
    font-size: 0.8rem; color: var(--g400); pointer-events: none;
}
.fh-txt { font-size: 0.7rem; color: var(--g400); margin-top: 4px; }

.fdiv {
    font-weight: 800; font-size: 0.68rem; letter-spacing: 2px;
    text-transform: uppercase; color: var(--navy);
    margin: 0.25rem 0 1.1rem;
    display: flex; align-items: center; gap: 10px;
}
.fdiv::after { content: ''; flex: 1; height: 1.5px; background: var(--g100); }

/* ── Live total ──────────────────────────── */
.live-total {
    background: linear-gradient(135deg, var(--navy-xl), #eef4ff);
    border: 1.5px solid rgba(8,145,178,.2);
    border-radius: 12px; padding: 1rem 1.1rem;
    margin-bottom: 1.25rem;
}
.lt-row { display: flex; align-items: center; justify-content: space-between; gap: 1rem; margin-bottom: 0.55rem; }
.lt-row:last-child { margin-bottom: 0; padding-top: 0.55rem; border-top: 1.5px dashed var(--g200); }
.lt-label { font-size: 0.78rem; color: var(--g400); display: flex; align-items: center; gap: 6px; }
.lt-label i { font-size: 0.72rem; width: 14px; color: var(--teal); }
.lt-val { font-weight: 800; font-size: 0.92rem; color: var(--navy); }
.lt-val.teal { color: var(--teal); }
.lt-val.amber { color: var(--amber); }
.lt-val.big { font-size: 1.2rem; }

/* ── Submit button ───────────────────────── */
.dep-btn {
    width: 100%; padding: 15px; border-radius: 12px; border: none;
    background: linear-gradient(135deg, var(--teal), #06b6d4);
    color: #fff; font-family: var(--fh); font-weight: 800; font-size: 15px;
    cursor: pointer; transition: all .3s;
    display: flex; align-items: center; justify-content: center; gap: 10px;
    box-shadow: 0 6px 20px rgba(8,145,178,.35);
}
.dep-btn:hover:not(:disabled) {
    background: linear-gradient(135deg, #0e7490, #0891b2);
    transform: translateY(-2px);
    box-shadow: 0 10px 30px rgba(8,145,178,.45);
}
.dep-btn:disabled { background: var(--g200); color: var(--g400); cursor: not-allowed; box-shadow: none; }

.sec-note {
    display: flex; align-items: center; justify-content: center; gap: 7px;
    margin-top: 14px; font-size: 12px; color: var(--g400);
}
.mpill {
    background: #00be37; color: #fff;
    padding: 2px 8px; border-radius: 4px; font-weight: 900; font-size: 10px;
}

/* ── Error ───────────────────────────────── */
.ea {
    background: var(--crimson-l); border: 1px solid rgba(191,19,57,.2);
    border-radius: 10px; padding: 12px 15px; margin-top: 18px;
    display: flex; align-items: flex-start; gap: 10px;
}
.ea i { color: var(--crimson); font-size: 14px; margin-top: 2px; flex-shrink: 0; }
.ea p { font-size: 12.5px; color: var(--crimson-d); line-height: 1.5; }

/* ── How it works ────────────────────────── */
.how-card {
    background: #fff; border-radius: 18px; overflow: hidden;
    box-shadow: 0 10px 40px rgba(5,46,100,.22);
}
.how-head {
    padding: 1.1rem 1.5rem 0.9rem;
    border-bottom: 1px solid var(--g100);
    background: linear-gradient(90deg, rgba(8,145,178,.05), rgba(8,145,178,.01));
    display: flex; align-items: center; gap: 12px;
}
.how-step {
    display: flex; gap: 14px; padding: 1.15rem 1.5rem;
    border-bottom: 1px solid var(--g100);
    transition: background .2s;
}
.how-step:last-child { border-bottom: none; }
.how-step:hover { background: var(--g50); }
.how-step-num {
    width: 34px; height: 34px; border-radius: 50%; flex-shrink: 0;
    display: flex; align-items: center; justify-content: center;
    font-weight: 900; font-size: 0.82rem; color: #fff;
}
.how-step-title { font-weight: 800; font-size: 0.85rem; color: var(--navy); margin-bottom: 3px; }
.how-step-desc { font-size: 0.76rem; color: var(--g400); line-height: 1.55; }

/* ── Sold out / closed ───────────────────── */
.sp {
    position: relative; z-index: 10;
    min-height: 82vh;
    display: flex; align-items: center; justify-content: center; padding: 20px;
}
.sc {
    background: #fff; border-radius: 24px; padding: 45px 35px;
    text-align: center; box-shadow: 0 25px 70px rgba(0,0,0,.4);
    width: 100%; max-width: 520px;
}
.si {
    width: 85px; height: 85px; border-radius: 50%;
    margin: 0 auto 24px;
    display: flex; align-items: center; justify-content: center; font-size: 30px;
}
.si-ok { background: #dcfce7; color: var(--green); }
.si-wt { background: var(--navy-l); color: var(--navy); }
.si-no { background: var(--crimson-l); color: var(--crimson); }
.stit { font-weight: 900; font-size: 26px; color: var(--navy); margin-bottom: 10px; }
.ssub { font-size: 14px; color: var(--g600); line-height: 1.65; }

/* M-Pesa waiting */
.mps { margin: 22px 0; text-align: left; background: var(--g50); border-radius: 14px; padding: 18px; }
.mps-row { display: flex; align-items: center; gap: 14px; margin-bottom: 16px; opacity: .4; }
.mps-row:last-child { margin-bottom: 0; }
.mps-row.mdon, .mps-row.mact { opacity: 1; }
.mico {
    width: 30px; height: 30px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 11px; flex-shrink: 0;
}
.mico-d { background: var(--green); color: #fff; }
.mico-a { background: var(--teal); color: #fff; animation: spin 2s linear infinite; }
.mico-w { background: var(--g200); color: var(--g400); }
@keyframes spin { 100% { transform: rotate(360deg); } }
.mlbl { font-size: 13px; font-weight: 500; color: var(--g400); }
.mla { color: var(--teal); font-weight: 700; }
.mld { color: var(--green); font-weight: 700; }
.cb-wrap { height: 6px; background: var(--g100); border-radius: 3px; overflow: hidden; margin-bottom: 9px; }
.cb { height: 100%; background: var(--teal); transition: width 1s linear; }
.cb-txt { font-size: 12px; color: var(--g400); }

/* Success extras */
.dep-ref-badge {
    display: inline-block; background: var(--teal-l); color: var(--teal-d);
    font-family: var(--fm); font-weight: 700; font-size: 17px;
    letter-spacing: 2px; padding: 11px 22px; border-radius: 10px;
    border: 2px dashed rgba(8,145,178,.4); margin: 20px 0;
}
.suc-actions { display: flex; flex-direction: column; gap: 11px; margin-top: 24px; }
.sac-btn {
    display: flex; align-items: center; justify-content: center; gap: 9px;
    padding: 13px; border-radius: 11px; text-decoration: none;
    font-weight: 700; font-size: 13px; transition: all .2s;
}
.sac-teal { background: var(--teal); color: #fff; }
.sac-teal:hover { background: var(--teal-d); transform: translateY(-2px); }
.sac-home { background: var(--g100); color: var(--g600); }
.sac-home:hover { background: var(--g200); }
.sac-event { background: var(--navy); color: #fff; }
.sac-event:hover { background: var(--navy-m); transform: translateY(-2px); }

/* Redirect bar */
.redirect-bar {
    display: flex; align-items: center; gap: 10px;
    background: var(--g50); border: 1px solid var(--g100);
    border-radius: 10px; padding: 10px 14px; margin-top: 1rem;
}
.redirect-bar-text { font-size: 0.77rem; color: var(--g400); font-weight: 600; }
.redirect-bar-count { font-weight: 800; color: var(--teal); }
.redirect-progress { flex: 1; height: 3px; background: var(--g200); border-radius: 100px; overflow: hidden; }
.redirect-progress-fill { height: 100%; background: var(--teal); border-radius: 100px; width: 100%; transition: width linear; }

/* Balance reminder pill */
.balance-pill {
    display: flex; align-items: center; gap: 10px;
    background: var(--amber-l); border: 1.5px solid rgba(217,119,6,.25);
    border-radius: 12px; padding: 12px 16px; margin-top: 16px; text-align: left;
}
.balance-pill i { color: var(--amber); font-size: 1rem; flex-shrink: 0; }
.balance-pill-title { font-weight: 800; font-size: 0.82rem; color: #92400e; margin-bottom: 2px; }
.balance-pill-sub { font-size: 0.72rem; color: #b45309; line-height: 1.5; }

@media (max-width: 600px) {
    .topnav { padding: 0 16px; }
    .mw { padding: 22px 14px 70px; }
    .dep-split { grid-template-columns: 1fr; }
    .pg-hero { padding: 1.4rem 1.25rem; }
    .card-body { padding: 1.1rem; }
}
</style>
</head>
<body>

<div class="bg-scene"></div>
<div class="bg-veil"></div>

<nav class="topnav">
    <a href="/" class="nb">KEY<span>CHELE</span></a>
    <div class="bc">
        <a href="/">Home</a><span class="s">›</span>
        <a href="/ticket/<?= $event_id ?>">Event</a><span class="s">›</span>
        <span>Deposit Payment</span>
    </div>
</nav>

<?php if ($just_paid): ?>
<!-- ══ SUCCESS ══ -->
<div class="sp">
    <div class="sc">
        <div class="si si-ok"><i class="fas fa-check"></i></div>
        <h1 class="stit">Deposit Confirmed! 🎉</h1>
        <p class="ssub">
            Your deposit for <strong style="color:var(--navy);"><?= h($event['title']) ?></strong>
            has been received and your spot is reserved.
        </p>
        <div class="dep-ref-badge"><?= h($dep_ref_display) ?></div>
        <p style="font-size:13px;color:var(--g400);margin-bottom:0;">
            <i class="fas fa-envelope" style="color:var(--teal);margin-right:5px;"></i>
            Receipt sent to <strong style="color:var(--navy);"><?= h($buyer_email_display) ?></strong>
        </p>

        <div class="balance-pill">
            <i class="fas fa-clock"></i>
            <div>
                <div class="balance-pill-title">Balance Due: KES <?= number_format($balance_display, 2) ?></div>
                <div class="balance-pill-sub">
                    Pay the remaining balance before the event deadline to receive your ticket PDF and guarantee entry.
                </div>
            </div>
        </div>

        <div class="redirect-bar">
            <i class="fas fa-arrow-left" style="color:var(--g400);font-size:0.75rem;"></i>
            <div class="redirect-bar-text">Returning to event in <span class="redirect-bar-count" id="rCount">5</span>s</div>
            <div class="redirect-progress"><div class="redirect-progress-fill" id="rFill"></div></div>
        </div>

        <div class="suc-actions">
            <a href="/ticket/<?= $event_id ?>" class="sac-btn sac-event">
                <i class="fas fa-ticket-alt"></i> Back to Event Page
            </a>
            <a href="/" class="sac-btn sac-home">
                <i class="fas fa-arrow-left"></i> Back to Home
            </a>
        </div>
    </div>
</div>
<script>
(function () {
    var total = 5000, start = Date.now();
    var cEl = document.getElementById('rCount');
    var fEl = document.getElementById('rFill');
    if (fEl) { fEl.style.transition = 'width ' + (total/1000) + 's linear'; setTimeout(function(){ fEl.style.width='0%'; }, 60); }
    var iv = setInterval(function () {
        var left = Math.ceil((total - (Date.now() - start)) / 1000);
        if (cEl) cEl.textContent = Math.max(left, 0);
        if (Date.now() - start >= total) { clearInterval(iv); window.location.href = '/ticket/<?= $event_id ?>'; }
    }, 250);
})();
</script>

<?php elseif ($waiting): ?>
<!-- ══ MPESA WAITING ══ -->
<div class="sp">
    <div class="sc" style="max-width:460px;">
        <div class="si si-wt" style="width:76px;height:76px;font-size:26px;">
            <i class="fas fa-mobile-alt"></i>
        </div>
        <h1 class="stit" style="font-size:24px;">Check Your Phone</h1>
        <p class="ssub">An M-Pesa prompt has been sent. Enter your <strong style="color:var(--navy);">M-Pesa PIN</strong> to pay the deposit.</p>

        <div class="mps">
            <div class="mps-row mdon">
                <div class="mico mico-d"><i class="fas fa-check"></i></div>
                <span class="mlbl mld">Deposit request sent to your phone</span>
            </div>
            <div class="mps-row mact" id="st2">
                <div class="mico mico-a" id="si2"><i class="fas fa-sync-alt"></i></div>
                <span class="mlbl mla" id="sl2">Waiting for M-Pesa PIN confirmation…</span>
            </div>
            <div class="mps-row" id="st3">
                <div class="mico mico-w" id="si3"><i class="fas fa-envelope"></i></div>
                <span class="mlbl" id="sl3">Receipt will be emailed to you</span>
            </div>
        </div>

        <div class="cb-wrap"><div class="cb" id="cbar" style="width:100%;"></div></div>
        <p class="cb-txt">Timeout in <strong id="countdown">40</strong>s — do not close this page</p>

        <div style="margin-top:16px;background:var(--teal-l);border:1px solid rgba(8,145,178,.3);border-radius:10px;padding:11px 14px;font-size:12px;color:#0c4a6e;">
            <i class="fas fa-info-circle" style="color:var(--teal);margin-right:5px;"></i>
            No prompt? Check your phone's notification tray or wait a moment.
        </div>
    </div>
</div>
<script>
const TOT = 40; let secs = TOT;
const cdEl = document.getElementById('countdown');
const cb   = document.getElementById('cbar');
const tmr  = setInterval(() => {
    secs--; cdEl.textContent = secs;
    cb.style.width = (secs / TOT * 100) + '%';
    if (secs <= 10) cb.style.background = 'var(--crimson)';
    if (secs <= 0) {
        clearInterval(tmr); clearInterval(pol);
        document.getElementById('sl2').textContent = 'Timed out — redirecting…';
        setTimeout(() => location.href = '/deposit-parttime-payment?event_id=<?= $event_id ?>&ticket_type_id=<?= $ticket_type_id ?>', 2000);
    }
}, 1000);
function setDone(siId, slId, text) {
    document.getElementById(siId).parentElement.className = 'mps-row mdon';
    const ico = document.getElementById(siId); ico.className = 'mico mico-d'; ico.innerHTML = '<i class="fas fa-check"></i>';
    const lbl = document.getElementById(slId); lbl.className = 'mlbl mld'; lbl.textContent = text;
}
function setAct(siId, slId, text) {
    document.getElementById(siId).parentElement.className = 'mps-row mact';
    const ico = document.getElementById(siId); ico.className = 'mico mico-a'; ico.innerHTML = '<i class="fas fa-sync-alt"></i>';
    const lbl = document.getElementById(slId); lbl.className = 'mlbl mla'; lbl.textContent = text;
}
function chk() {
    fetch('/check_deposit_payment.php').then(r => r.json()).then(d => {
        if (d.success && d.status === 'completed') {
            clearInterval(pol); clearInterval(tmr);
            setDone('si2','sl2','Deposit confirmed!');
            setAct('si3','sl3','Sending your receipt…');
            cb.style.width = '100%'; cb.style.background = 'var(--green)';
            setTimeout(() => location.reload(), 2400);
        } else if (d.status === 'failed') {
            clearInterval(pol); clearInterval(tmr);
            setDone('si2','sl2','Payment not completed.');
            setTimeout(() => location.href = '/deposit-parttime-payment?event_id=<?= $event_id ?>&ticket_type_id=<?= $ticket_type_id ?>', 1600);
        }
    }).catch(() => {});
}
const pol = setInterval(chk, 4000); chk();
</script>

<?php elseif ($failed): ?>
<!-- ══ FAILED ══ -->
<div class="sp">
    <div class="sc">
        <div class="si si-no"><i class="fas fa-times"></i></div>
        <h1 class="stit">Payment Failed</h1>
        <p class="ssub"><?= h($error) ?></p>
        <p style="font-size:13px;color:var(--g400);margin:14px 0;">
            The prompt may have been cancelled, timed out, or you had insufficient M-Pesa balance.
        </p>
        <a href="/deposit-parttime-payment?event_id=<?= $event_id ?>&ticket_type_id=<?= $ticket_type_id ?>"
           style="display:inline-flex;align-items:center;gap:8px;padding:13px 26px;border-radius:11px;background:var(--teal);color:#fff;font-weight:800;text-decoration:none;margin-top:18px;transition:all .2s;">
            <i class="fas fa-redo"></i> Try Again
        </a>
    </div>
</div>

<?php elseif ($deadline_passed): ?>
<!-- ══ CLOSED ══ -->
<div class="sp">
    <div class="sc">
        <div class="si" style="background:var(--g100);color:var(--g400);"><i class="fas fa-calendar-times"></i></div>
        <h1 class="stit">Sales Closed</h1>
        <p class="ssub">Ticket sales for this event have closed. Please contact the organizer.</p>
        <a href="/" style="display:inline-flex;align-items:center;gap:8px;padding:13px 26px;border-radius:11px;background:var(--navy);color:#fff;font-weight:800;text-decoration:none;margin-top:18px;">
            <i class="fas fa-arrow-left"></i> Back to Home
        </a>
    </div>
</div>

<?php else: ?>
<!-- ══ MAIN DEPOSIT FORM ══ -->
<div class="mw">

    <!-- Hero -->
    <div class="pg-hero">
        <div class="pg-hero-orb"></div>
        <div class="pg-hero-inner">
            <div>
                <div class="pg-hero-tag"><i class="fas fa-down-to-line"></i> Deposit / Part Payment</div>
                <h1 class="pg-hero-title">Pay Deposit &amp; Reserve Your Spot</h1>
                <p class="pg-hero-sub">Secure your ticket now · Pay the remaining balance before the event</p>
                <div class="pg-hero-event">
                    <i class="fas fa-calendar-star" style="font-size:0.7rem;opacity:.7"></i>
                    <span><?= h($event['title']) ?></span>
                </div>
            </div>
            <div class="pg-hero-icon"><i class="fas fa-down-to-line"></i></div>
        </div>
    </div>

    <div class="dep-grid">

        <!-- ── LEFT: Summary + How it works ── -->
        <div>

            <!-- Deposit breakdown card -->
            <div class="dep-summary">
                <div class="dep-summary-head">
                    <div class="dep-summary-ico"><i class="fas fa-calculator"></i></div>
                    <div>
                        <div class="dep-summary-title">Payment Breakdown</div>
                        <div class="dep-summary-sub">Per ticket · <?= h($ttype['type_name']) ?></div>
                    </div>
                </div>
                <div class="dep-summary-body">
                    <div class="dep-split">
                        <div class="dep-split-box pay-now">
                            <div class="dsb-label"><i class="fas fa-check-circle"></i> Pay Now</div>
                            <div class="dsb-amount" id="depNowDisplay">KES <?= number_format($deposit_amount) ?></div>
                            <div class="dsb-sub">Deposit per ticket</div>
                        </div>
                        <div class="dep-split-box pay-later">
                            <div class="dsb-label"><i class="fas fa-clock"></i> Pay Later</div>
                            <div class="dsb-amount" id="depLaterDisplay">KES <?= number_format($balance_due) ?></div>
                            <div class="dsb-sub">Balance per ticket</div>
                        </div>
                    </div>
                    <div class="dep-full-row">
                        <span class="dfr-label">Full ticket price</span>
                        <span class="dfr-val">KES <?= number_format($full_price) ?> / ticket</span>
                    </div>
                    <div class="dep-warning">
                        <i class="fas fa-exclamation-triangle"></i>
                        <div class="dep-warning-text">
                            <strong>Ticket PDF issued only after full payment.</strong>
                            You must pay the balance before the event deadline
                            <?php if (!empty($event['deadline'])): ?>
                                (<strong><?= date('D d M Y', strtotime($event['deadline'])) ?></strong>)
                            <?php endif; ?>
                            to receive your ticket and guarantee entry.
                        </div>
                    </div>
                </div>
            </div>

            <!-- How it works -->
            <div class="how-card">
                <div class="how-head">
                    <div class="card-head-ico" style="background:rgba(8,145,178,.1);color:var(--teal);">
                        <i class="fas fa-info-circle"></i>
                    </div>
                    <div>
                        <div class="card-head-title">How Deposit Payment Works</div>
                        <div class="card-head-sub">Three simple steps to secure your spot</div>
                    </div>
                </div>
                <div class="how-step">
                    <div class="how-step-num" style="background:var(--teal);">1</div>
                    <div>
                        <div class="how-step-title">Pay your deposit now via M-Pesa</div>
                        <div class="how-step-desc">
                            Pay KES <?= number_format($deposit_amount) ?> per ticket to reserve your spot.
                            An STK push is sent directly to your phone — enter your PIN to confirm.
                        </div>
                    </div>
                </div>
                <div class="how-step">
                    <div class="how-step-num" style="background:var(--amber);">2</div>
                    <div>
                        <div class="how-step-title">Keep your deposit reference safe</div>
                        <div class="how-step-desc">
                            You'll receive a receipt by email with your deposit reference. Save it —
                            you'll need it when you return to pay the balance.
                        </div>
                    </div>
                </div>
                <div class="how-step">
                    <div class="how-step-num" style="background:var(--green);">3</div>
                    <div>
                        <div class="how-step-title">Pay the balance &amp; get your ticket</div>
                        <div class="how-step-desc">
                            Return before the deadline and pay the remaining KES <?= number_format($balance_due) ?> per ticket.
                            Your PDF ticket with QR code is emailed instantly after full payment.
                        </div>
                    </div>
                </div>
            </div>

        </div>

        <!-- ── RIGHT: Form ── -->
        <div>
            <div class="card">
                <div class="card-head">
                    <div class="card-head-ico" style="background:rgba(8,145,178,.1);color:var(--teal);">
                        <i class="fas fa-down-to-line"></i>
                    </div>
                    <div>
                        <div class="card-head-title">Reserve with Deposit</div>
                        <div class="card-head-sub">Fill in your details to proceed</div>
                    </div>
                </div>
                <div class="card-body">

                    <!-- Event strip -->
                    <div class="event-strip">
                        <img src="/<?= $banner_url ?>" alt="Event" class="es-thumb"
                             onerror="this.src='https://via.placeholder.com/52x52?text=E'">
                        <div>
                            <div class="es-title"><?= h(mb_strimwidth($event['title'], 0, 48, '…')) ?></div>
                            <div class="es-meta">
                                <i class="fas fa-calendar"></i><?= date('d M Y', strtotime($event['start_date'])) ?>
                                &nbsp;·&nbsp;
                                <i class="fas fa-map-marker-alt"></i><?= h(mb_strimwidth($event['venue'] ?? 'TBA', 0, 26, '…')) ?>
                            </div>
                        </div>
                    </div>

                    <!-- Ticket type badge -->
                    <div class="type-badge">
                        <i class="fas fa-ticket-alt"></i>
                        <?= h($ttype['type_name']) ?>
                        <span style="opacity:.5;font-size:.7rem;">·</span>
                        <span style="font-size:.75rem;font-weight:600;color:var(--g400);">
                            <?= $ttype['slots_left'] ?> slot<?= $ttype['slots_left'] != 1 ? 's' : '' ?> left
                        </span>
                    </div>

                    <form method="POST"
                          action="/deposit-parttime-payment?event_id=<?= $event_id ?>&ticket_type_id=<?= $ticket_type_id ?>"
                          id="depForm">

                        <!-- Quantity -->
                        <div class="qty-field">
                            <label>Number of Tickets</label>
                            <div class="qty-row">
                                <button type="button" class="qty-btn" id="qDec" onclick="adjQty(-1)">−</button>
                                <input type="number" class="qty-num" id="qtyInput" name="quantity"
                                       value="1" min="1" max="<?= min($ttype['slots_left'], 10) ?>" readonly>
                                <button type="button" class="qty-btn" id="qInc" onclick="adjQty(1)">+</button>
                            </div>
                            <div class="qty-hint">
                                Max <?= min($ttype['slots_left'], 10) ?> per order
                                <?php if ($ttype['slots_left'] <= 10): ?>
                                    &nbsp;·&nbsp; <span style="color:var(--crimson);font-weight:700;">Only <?= $ttype['slots_left'] ?> remaining!</span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Live total -->
                        <div class="live-total" id="liveTotal">
                            <div class="lt-row">
                                <span class="lt-label"><i class="fas fa-check-circle"></i> Deposit to pay now</span>
                                <span class="lt-val teal" id="ltDep">KES <?= number_format($deposit_amount) ?></span>
                            </div>
                            <div class="lt-row">
                                <span class="lt-label"><i class="fas fa-clock"></i> Balance due later</span>
                                <span class="lt-val amber" id="ltBal">KES <?= number_format($balance_due) ?></span>
                            </div>
                            <div class="lt-row">
                                <span class="lt-label" style="font-weight:700;color:var(--navy);"><i class="fas fa-receipt"></i> Total order value</span>
                                <span class="lt-val big" id="ltFull">KES <?= number_format($full_price) ?></span>
                            </div>
                        </div>

                        <!-- Buyer details -->
                        <div class="fdiv">Your Details</div>

                        <div class="fg">
                            <label class="fl" for="depName">Full Name</label>
                            <div class="fi-wrap">
                                <i class="fi-ico fas fa-user"></i>
                                <input type="text" id="depName" name="name" class="fi"
                                       placeholder="Jane Mwangi" required autocomplete="name"
                                       value="<?= h($_POST['name'] ?? $_SESSION['fan_name'] ?? '') ?>">
                            </div>
                        </div>

                        <div class="fg">
                            <label class="fl" for="depEmail">Email Address</label>
                            <div class="fi-wrap">
                                <i class="fi-ico fas fa-envelope"></i>
                                <input type="email" id="depEmail" name="email" class="fi"
                                       placeholder="jane@example.com" required autocomplete="email" readonly
                                       style="opacity:.7;cursor:not-allowed;background:var(--g100);"
                                       value="<?= h($_SESSION['fan_email']) ?>">
                            </div>
                            <div class="fh-txt"><i class="fas fa-info-circle"></i> Deposit receipt will be sent to your account email</div>
                        </div>

                        <div class="fg">
                            <label class="fl" for="depPhone">M-Pesa Phone Number</label>
                            <div class="fi-wrap">
                                <i class="fi-ico fas fa-mobile-alt"></i>
                                <input type="tel" id="depPhone" name="phone" class="fi"
                                       placeholder="0712 345 678" required autocomplete="tel"
                                       value="<?= h($_POST['phone'] ?? '') ?>">
                            </div>
                            <div class="fh-txt">Kenyan numbers only (07XX or 01XX) · STK push will be sent here</div>
                        </div>

                        <button type="submit" class="dep-btn" id="depSubmitBtn">
                            <i class="fas fa-mobile-alt"></i>
                            <span id="depBtnTxt">Pay Deposit of KES <?= number_format($deposit_amount) ?> via M-Pesa</span>
                        </button>

                        <p class="sec-note">
                            <i class="fas fa-shield-alt" style="color:#16a34a;"></i>
                            Secured by <span class="mpill">M-PESA</span>
                        </p>

                        <?php if ($error): ?>
                        <div class="ea">
                            <i class="fas fa-exclamation-circle"></i>
                            <p><?= h($error) ?></p>
                        </div>
                        <?php endif; ?>

                    </form>
                </div>
            </div>

            <!-- Back link -->
            <div style="text-align:center;">
                <a href="/ticket/<?= $event_id ?>"
                   style="display:inline-flex;align-items:center;gap:7px;padding:10px 20px;border-radius:10px;
                          background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.15);
                          color:rgba(255,255,255,.65);font-size:13px;font-weight:600;text-decoration:none;
                          transition:all .2s;">
                    <i class="fas fa-arrow-left" style="font-size:11px;"></i>
                    Back · Pay full price instead
                </a>
            </div>
        </div>

    </div>
</div>

<script>
const DEP_PER   = <?= $deposit_amount ?>;
const BAL_PER   = <?= $balance_due ?>;
const FULL_PER  = <?= $full_price ?>;
const MAX_QTY   = <?= min($ttype['slots_left'], 10) ?>;

function fmt(n) {
    return 'KES ' + Number(n).toLocaleString('en-KE', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
}

function adjQty(d) {
    const inp = document.getElementById('qtyInput');
    let v = parseInt(inp.value) + d;
    v = Math.max(1, Math.min(v, MAX_QTY));
    inp.value = v;
    document.getElementById('qDec').disabled = (v <= 1);
    document.getElementById('qInc').disabled = (v >= MAX_QTY);
    updateLive(v);
}

function updateLive(qty) {
    const dep  = DEP_PER  * qty;
    const bal  = BAL_PER  * qty;
    const full = FULL_PER * qty;
    document.getElementById('ltDep').textContent  = fmt(dep);
    document.getElementById('ltBal').textContent  = fmt(bal);
    document.getElementById('ltFull').textContent = fmt(full);
    document.getElementById('depBtnTxt').textContent = 'Pay Deposit of ' + fmt(dep) + ' via M-Pesa';
}

// Init
document.getElementById('qDec').disabled = true;
document.getElementById('qInc').disabled = (MAX_QTY <= 1);

document.getElementById('depForm').addEventListener('submit', function (e) {
    const name  = document.getElementById('depName').value.trim();
    const email = document.getElementById('depEmail').value.trim();
    const phone = document.getElementById('depPhone').value.trim();
    const qty   = parseInt(document.getElementById('qtyInput').value);

    if (!name || !email || !phone) {
        e.preventDefault();
        Swal.fire({ icon: 'warning', title: 'Missing Details', text: 'Please fill in all fields before continuing.', confirmButtonColor: '#0891b2' });
        return;
    }

    const btn = document.getElementById('depSubmitBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending M-Pesa prompt…';
});
</script>

<?php endif; ?>
</body>
</html>