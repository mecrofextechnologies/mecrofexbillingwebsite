<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
include 'msanii/includes/connect.php';


use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require 'vendor/autoload.php';
require_once 'vendor/fpdf.php';
require_once 'msanii/includes/fan_auto_register.php';
require_once 'msanii/includes/ticket_email.php';
require_once 'msanii/includes/page_cache.php';
require_once 'msanii/includes/deposit_schema.php';
kc_ensure_deposit_purchases_table($pdo);
kc_ensure_ticket_sms_optin_column($pdo);

// ─────────────────────────────────────────────────────────────
// MPESA — Paybill STK push via the shared Daraja client. Credentials come
// from Admin01 → Settings → Daraja API Credentials / the secure config,
// never from this file (see msanii/includes/daraja.php).
// ─────────────────────────────────────────────────────────────
require_once 'msanii/includes/daraja.php';

// AccountReference shown on the customer's M-Pesa prompt is the creator's
// username; the purchase itself is still matched on CheckoutRequestID.
function initiateSTKPush($phone, $amount, $reference, $ticket_type_name, $creator_username = null) {
    global $pdo;
    $res = kc_daraja_stk_push(
        $pdo, $phone, (int)$amount,
        kc_daraja_account_reference($creator_username, $reference),
        "Ticket Purchase: $ticket_type_name - $reference",
        'ticket_callback.php'
    );
    return $res['ok'] ? $res['checkout_request_id'] : false;
}

// ─────────────────────────────────────────────────────────────
// RATE LIMITING
// Protects against two things: a script hammering this endpoint (each
// attempt burns a real Safaricom API call, and Safaricom accounts can get
// throttled/suspended for abuse), and someone submitting a stranger's phone
// number repeatedly to spam them with unwanted M-Pesa prompts.
// ─────────────────────────────────────────────────────────────
function kc_check_purchase_rate_limit(PDO $pdo, string $ip, ?string $phone): ?string {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS purchase_attempts (
            id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            ip_address VARCHAR(45) NOT NULL,
            phone      TEXT NULL,
            phone_bidx CHAR(64) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_ip_time (ip_address, created_at),
            INDEX idx_phone_time (phone_bidx, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $ipStmt = $pdo->prepare("SELECT COUNT(*) FROM purchase_attempts WHERE ip_address = ? AND created_at > DATE_SUB(NOW(), INTERVAL 10 MINUTE)");
    $ipStmt->execute([$ip]);
    if ((int)$ipStmt->fetchColumn() >= 10) {
        return "Too many attempts. Please wait a few minutes and try again.";
    }

    $phoneBidx = $phone ? kc_phone_index($phone) : null;
    if ($phoneBidx) {
        $phoneStmt = $pdo->prepare("SELECT COUNT(*) FROM purchase_attempts WHERE phone_bidx = ? AND created_at > DATE_SUB(NOW(), INTERVAL 10 MINUTE)");
        $phoneStmt->execute([$phoneBidx]);
        if ((int)$phoneStmt->fetchColumn() >= 3) {
            return "This phone number has had too many payment attempts recently. Please wait a few minutes and try again.";
        }
    }

    // Record this attempt regardless of outcome, then opportunistically prune
    // old rows so the table doesn't grow unbounded (no separate cron needed
    // for a table only ever queried over the last 10 minutes).
    $pdo->prepare("INSERT INTO purchase_attempts (ip_address, phone, phone_bidx) VALUES (?, ?, ?)")
        ->execute([$ip, $phone ? kc_encrypt($phone) : null, $phoneBidx]);
    if (random_int(1, 50) === 1) {
        $pdo->exec("DELETE FROM purchase_attempts WHERE created_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)");
    }

    return null;
}

// ─────────────────────────────────────────────────────────────
// MAP EMBED URL HELPER
// ─────────────────────────────────────────────────────────────
function buildMapEmbedUrl(string $url): string {
    if (str_contains($url, 'output=embed')) return $url;
    if (preg_match('/@(-?\d+\.\d+),(-?\d+\.\d+)/', $url, $m))
        return 'https://maps.google.com/maps?q=' . $m[1] . ',' . $m[2] . '&output=embed&z=15';
    $parsed = parse_url($url);
    parse_str($parsed['query'] ?? '', $qs);
    if (!empty($qs['q']))
        return 'https://maps.google.com/maps?q=' . rawurlencode($qs['q']) . '&output=embed&z=15';
    if (preg_match('#/maps/search/([^/@?+]+)#', $url, $m))
        return 'https://maps.google.com/maps?q=' . rawurlencode(urldecode($m[1])) . '&output=embed&z=15';
    if (preg_match('#/maps/place/([^/@?+]+)#', $url, $m))
        return 'https://maps.google.com/maps?q=' . rawurlencode(urldecode(str_replace('+', ' ', $m[1]))) . '&output=embed&z=15';
    return 'https://maps.google.com/maps?q=' . rawurlencode($url) . '&output=embed&z=15';
}

// ─────────────────────────────────────────────────────────────
// MAIN LOGIC
// ─────────────────────────────────────────────────────────────
if (empty($_SESSION['csrf_token']) || (time() - ($_SESSION['csrf_token_time'] ?? 0)) > 1800) {
    $_SESSION['csrf_token']      = bin2hex(random_bytes(32));
    $_SESSION['csrf_token_time'] = time();
}

$event_id = (int)($_GET['id'] ?? 0);
if ($event_id <= 0) die("<h1 style='text-align:center;margin-top:5rem;color:#bf1339'>Invalid Event ID</h1>");

// Cached (25s TTL) — this is the hottest read on the site's most-visited
// page; a burst of concurrent viewers of the same event would otherwise
// re-run this JOIN once per pageview. Cache holds the raw (encrypted) row;
// decryption still happens per-request below, so nothing decrypted is ever
// written to disk.
$event = kc_cache_get("ticket_event_{$event_id}", 25, function () use ($pdo, $event_id) {
    $stmt = $pdo->prepare("SELECT t.*, c.username, mp.full_name, c.email AS creator_email, t.deadline, c.id AS creator_id_val FROM tickets t LEFT JOIN creators c ON t.creator_id = c.id LEFT JOIN my_page mp ON c.id = mp.creator_id WHERE t.id = ? AND (t.status = 'active' OR t.status IS NULL)");
    $stmt->execute([$event_id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
});
if (!$event) die("<h1 style='text-align:center;margin-top:5rem;color:#bf1339'>Event Not Found</h1>");
$event['creator_email'] = kc_decrypt($event['creator_email']);
$event['full_name'] = kc_decrypt($event['full_name']);
include __DIR__ . '/includes/creator_ticket_settings.php';   //

include __DIR__ . '/track_ticketpage.php';

$creator_id_val = $event['creator_id'] ?? $event['creator_id_val'] ?? null;
$other_events = [];
if ($creator_id_val) {
    // Cached (25s TTL) — same reasoning: this GROUP BY aggregate scales with
    // the creator's total event/ticket-type count, so it's the more
    // expensive of the two "static" queries on this page.
    $other_events = kc_cache_get("ticket_other_events_{$creator_id_val}_{$event_id}", 25, function () use ($pdo, $creator_id_val, $event_id) {
        $s2 = $pdo->prepare("SELECT t.id, t.title, t.start_date, t.start_time, t.venue, t.banner, MIN(ett.price) AS min_price FROM tickets t LEFT JOIN event_ticket_types ett ON ett.event_id = t.id AND ett.is_active = 1 WHERE t.creator_id = ? AND t.id != ? AND (t.status = 'active' OR t.status IS NULL) GROUP BY t.id ORDER BY t.start_date ASC LIMIT 20");
        $s2->execute([$creator_id_val, $event_id]);
        return $s2->fetchAll(PDO::FETCH_ASSOC);
    });
}

// ── Follow-the-organizer state (for the Follow button in the Organized By card) ──
$isFollowingOrganizer = false;
if (!empty($_SESSION['fan_id']) && $creator_id_val) {
    $fc = $pdo->prepare("SELECT 1 FROM followers WHERE fan_id = ? AND creator_id = ?");
    $fc->execute([$_SESSION['fan_id'], $creator_id_val]);
    $isFollowingOrganizer = (bool)$fc->fetchColumn();
}

$deadline_passed = false;
$deadline_message = "Ticket sales have closed. Please contact the event organizer.";
if (!empty($event['deadline']) && strtotime($event['deadline'] . ' 23:59:59') < time()) $deadline_passed = true;

// Cached, but with a much shorter TTL than the two queries above (8s, not
// 25s) — this one includes live quantity_sold/quantity_available and drives
// the slots-left/sold-out display, so it needs to feel closer to real-time.
// A stale "available" here is harmless: the actual purchase always
// re-checks live inventory via the atomic reserve-before-charge UPDATE
// further down, so a sold-out race just surfaces as "just sold out" at
// checkout instead of on page load, same as it always could between two
// requests a few seconds apart.
$ticket_types = kc_cache_get("ticket_types_{$event_id}", 8, function () use ($pdo, $event_id) {
    $stmt = $pdo->prepare("SELECT ett.id, ett.type_name, ett.price, ett.quantity_available, ett.quantity_sold, GREATEST(ett.quantity_available - ett.quantity_sold, 0) AS slots_left, (ett.quantity_available > 0 AND ett.quantity_sold >= ett.quantity_available) AS is_sold_out, t.creator_id, ett.deposit_enabled, ett.deposit_amount FROM event_ticket_types ett JOIN tickets t ON ett.event_id = t.id WHERE ett.event_id = ? AND ett.is_active = 1 ORDER BY ett.price ASC");
    $stmt->execute([$event_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
});

$has_any_tickets = count($ticket_types) > 0;
$all_sold_out    = $has_any_tickets && array_reduce($ticket_types, fn($c,$t) => $c && $t['is_sold_out'], true);
$any_available   = !$all_sold_out && !$deadline_passed;

// ── This fan's own pending-balance deposits for this event, keyed by
// ticket_type_id — lets a returning logged-in buyer pick up where they left
// off (pay the balance) instead of being offered a duplicate deposit.
$fanPendingDeposits = [];
if (!empty($_SESSION['fan_id'])) {
    try {
        $depStmt = $pdo->prepare("
            SELECT ticket_type_id, deposit_ref, balance_due
            FROM deposit_purchases
            WHERE fan_id = ? AND event_id = ? AND status = 'completed'
              AND (balance_status = 'pending' OR balance_status IS NULL)
        ");
        $depStmt->execute([$_SESSION['fan_id'], $event_id]);
        foreach ($depStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $fanPendingDeposits[$row['ticket_type_id']] = $row;
        }
    } catch (Throwable $e) { $fanPendingDeposits = []; }
}

$just_paid = $waiting = $failed = false;
$ticket_number_display = ''; $quantity_display = 1; $buyer_email_display = ''; $error = '';
$payment_fail_reason = $_SESSION['payment_fail_reason'] ?? '';
$banner_url = "msanii/uploads/banners/" . htmlspecialchars($event['banner'] ?: 'default-event.jpg');
$og_title   = $event['title'] ?? 'Event Tickets';
$og_desc    = !empty($event['description'])
    ? mb_strimwidth(trim(preg_replace('/\s+/', ' ', strip_tags($event['description']))), 0, 160, '…')
    : 'Get your tickets now on Keychele — Kenya\'s event ticketing platform.';
$og_image   = 'https://keychele.co.ke/' . $banner_url;
$og_url     = 'https://keychele.co.ke/ticket/' . (int)$event['id'];

if (isset($_SESSION['pending_ticket_checkout'])) {
    $checkout_id = $_SESSION['pending_ticket_checkout'];
    $stmt = $pdo->prepare("SELECT tp.*, ett.type_name FROM ticket_purchases tp LEFT JOIN event_ticket_types ett ON tp.ticket_type_id = ett.id WHERE tp.checkout_request_id = ?");
    $stmt->execute([$checkout_id]);
    $purchase = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($purchase) {
        $purchase = kc_decrypt_row($purchase, ['buyer_name', 'buyer_email', 'buyer_phone']);
        if ($purchase['status'] === 'completed') {
            $just_paid = true;
            $ticket_number_display = $purchase['ticket_number'];
            $quantity_display      = $_SESSION['last_quantity'] ?? $purchase['quantity'];
            $buyer_email_display   = $purchase['buyer_email'] ?? '';
            unset($_SESSION['pending_ticket_checkout'], $_SESSION['last_quantity'], $_SESSION['payment_fail_reason']);
        } elseif ($purchase['status'] === 'failed') { $failed = true; $error = "Payment failed or was not completed."; }
        else { $waiting = true; }
    } else { unset($_SESSION['pending_ticket_checkout']); }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $any_available && !$just_paid && !$waiting) {
    if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $error = "Your session expired. Please refresh the page and try again.";
    } elseif (($rateLimitError = kc_check_purchase_rate_limit($pdo, $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0', preg_replace('/\D/', '', $_POST['phone'] ?? '') ?: null)) !== null) {
        $error = $rateLimitError;
    } elseif ($deadline_passed) { $error = $deadline_message; } else {
        $name      = trim($_POST['name'] ?? '');
        $email     = trim($_POST['email'] ?? '');
        $phone_raw = preg_replace('/\D/', '', $_POST['phone'] ?? '');
        $type_id   = (int)($_POST['ticket_type'] ?? 0);
        $quantity  = max(1, (int)($_POST['quantity'] ?? 1));
        $custom_amount_raw = trim($_POST['custom_amount'] ?? '');
        $custom_amount = ($custom_amount_raw !== '') ? (float)$custom_amount_raw : null;
        $sms_opt_in = isset($_POST['sms_opt_in']) ? 1 : 0;

        $phone = $phone_raw;
        if (strlen($phone_raw) === 10 && in_array(substr($phone_raw,0,2),['07','01'])) $phone = '254'.substr($phone_raw,1);
        elseif (strlen($phone_raw)===9 && in_array(substr($phone_raw,0,1),['7','1'])) $phone='254'.$phone_raw;
        elseif (strlen($phone_raw)===12 && substr($phone_raw,0,3)==='254') $phone=$phone_raw;
        elseif (strlen($phone_raw)===13 && substr($phone_raw,0,4)==='+254') $phone=substr($phone_raw,1);

        $selected_type = null;
        foreach ($ticket_types as $t) { if ($t['id'] == $type_id && ($t['quantity_available'] == 0 || $t['slots_left'] >= $quantity)) { $selected_type=$t; break; } }

        if (!$selected_type) { $error = "Selected ticket type is no longer available or not enough slots."; }
        elseif (empty($name)||empty($email)||!filter_var($email,FILTER_VALIDATE_EMAIL)||!preg_match('/^(254[17][0-9]{8}|0[17][0-9]{8}|[17][0-9]{8})$/',$phone)) {
            $error = "Please complete all fields correctly. Phone must be a valid Kenyan number (e.g. 0712345678).";
        } else {
            $is_free    = ($selected_type['price'] == 0);
            $base_total = $selected_type['price'] * $quantity;

            if (!$is_free && $custom_amount !== null) {
                $custom_total = round($custom_amount * $quantity);
                if ($custom_total < $base_total) {
                    $error = "Custom amount cannot be less than the ticket price (KES " . number_format($selected_type['price']) . " per ticket).";
                } elseif ($custom_total > $base_total * 100) {
                    $error = "Custom amount seems too high. Please check the amount entered.";
                }
            }

            if (!$error) {
                $total_amount  = (!$is_free && $custom_amount !== null) ? (int)round($custom_amount * $quantity) : $base_total;
                $reference     = 'TKT-'.date('ymdHis').strtoupper(substr(uniqid('',true),-4));
                $ticket_number = strtoupper('TKT-'.date('ymd').'-'.substr(md5(uniqid()),0,6));

                // Reserve the slot(s) atomically BEFORE creating any purchase
                // row or contacting Safaricom. The WHERE guard only lets this
                // succeed if enough stock actually remains (InnoDB's row lock
                // serializes concurrent attempts on the same ticket_type), so
                // rowCount() is the real "is there still room" answer — the
                // earlier PHP-side slots_left check above is just a fast,
                // non-authoritative pre-filter for the common case.
                $reserve = $pdo->prepare("UPDATE event_ticket_types SET quantity_sold=quantity_sold+? WHERE id=? AND (quantity_available=0 OR quantity_available>=quantity_sold+?)");
                $reserve->execute([$quantity,$type_id,$quantity]);

                if ($reserve->rowCount() === 0) {
                    $error = "Sorry, this ticket type just sold out.";
                } elseif ($is_free) {
                    $stmt=$pdo->prepare("INSERT INTO ticket_purchases (ticket_id,ticket_type_id,buyer_name,buyer_email,buyer_email_bidx,buyer_phone,sms_opt_in,ticket_number,amount_paid,quantity,status,transaction_id,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,'completed',?,NOW(),NOW())");
                    $stmt->execute([$event_id,$type_id,kc_encrypt($name),kc_encrypt($email),kc_email_index($email),kc_encrypt($phone),$sms_opt_in,$ticket_number,0,$quantity,$reference]);

                    // QR + PDF generation and the buyer/creator emails (plus the
                    // guest-checkout fan-account creation, handled inside
                    // kc_send_ticket_email) are queued rather than run inline, so
                    // this page can respond immediately instead of waiting on
                    // SMTP/QR-API round trips.
                    kc_enqueue_ticket_email($pdo, (int)$pdo->lastInsertId());
                    $just_paid=true; $ticket_number_display=$ticket_number; $quantity_display=$quantity; $buyer_email_display=$email;
                } else {
                    $stmt=$pdo->prepare("INSERT INTO ticket_purchases (ticket_id,ticket_type_id,buyer_name,buyer_email,buyer_email_bidx,buyer_phone,sms_opt_in,ticket_number,amount_paid,quantity,checkout_request_id,status,transaction_id,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,'pending',?,NOW(),NOW())");
                    $stmt->execute([$event_id,$type_id,kc_encrypt($name),kc_encrypt($email),kc_email_index($email),kc_encrypt($phone),$sms_opt_in,$ticket_number,$total_amount,$quantity,$reference,$reference]);
                    $checkoutID = initiateSTKPush($phone,$total_amount,$reference,$selected_type['type_name'],$event['username'] ?? null);
                    if ($checkoutID) {
                        $pdo->prepare("UPDATE ticket_purchases SET checkout_request_id=? WHERE transaction_id=?")->execute([$checkoutID,$reference]);
                        $_SESSION['pending_ticket_checkout']=$checkoutID;
                        $_SESSION['last_quantity']=$quantity;
                        header("Location: /ticket/$event_id"); exit;
                    } else {
                        $pdo->prepare("DELETE FROM ticket_purchases WHERE transaction_id=?")->execute([$reference]);
                        // STK push never went out — release the slot(s) we just reserved.
                        $pdo->prepare("UPDATE event_ticket_types SET quantity_sold=quantity_sold-? WHERE id=?")->execute([$quantity,$type_id]);
                        $error="Failed to initiate M-Pesa payment. Please try again.";
                    }
                }
            }
        }
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1.0"/>
<title><?= htmlspecialchars($event['title'] ?? 'Event Tickets') ?> — Keychele</title>

<meta property="og:type" content="website">
<meta property="og:title" content="<?= htmlspecialchars($og_title) ?>">
<meta property="og:description" content="<?= htmlspecialchars($og_desc) ?>">
<meta property="og:image" content="<?= htmlspecialchars($og_image) ?>">
<meta property="og:image:secure_url" content="<?= htmlspecialchars($og_image) ?>">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta property="og:url" content="<?= htmlspecialchars($og_url) ?>">
<meta property="og:site_name" content="Keychele">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="<?= htmlspecialchars($og_title) ?>">
<meta name="twitter:description" content="<?= htmlspecialchars($og_desc) ?>">
<meta name="twitter:image" content="<?= htmlspecialchars($og_image) ?>">

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Montserrat:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;0,900;1,400&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/css2/ticketstyle.css">
<style>
@font-face {
    font-family: 'Glacial Indifference';
    src: url('https://cdn.jsdelivr.net/gh/freefont/freefont@master/FreeSerif.otf') format('opentype');
    font-weight: normal; font-style: normal;
}
/* Fix: ensure content sits above fixed background overlays */
.mw, .sp, .eg, .load-more-wrap, .sec-hd { position: relative; z-index: 5; }
.ecard, .load-more-btn { position: relative; z-index: 6; }

/* ── Follow-the-organizer prompt ── */
.ofp { margin-top: 14px; padding-top: 14px; border-top: 1px solid rgba(5,46,100,.08); display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
.ofp-txt { flex: 1; min-width: 180px; margin: 0; font-size: 12.5px; color: #667085; line-height: 1.5; }
.ofp-txt i { color: #fec411; margin-right: 4px; }
.ofp-btn {
    flex-shrink: 0; padding: 10px 20px; border-radius: 30px;
    background: #052e64; color: #fff; border: 1.5px solid #052e64;
    font-family: inherit; font-size: 13px; font-weight: 800; letter-spacing: .2px;
    cursor: pointer; display: inline-flex; align-items: center; gap: 7px; transition: all .2s;
}
.ofp-btn:hover { background: #bf1339; border-color: #bf1339; }
.ofp-btn:disabled { opacity: .6; cursor: not-allowed; }
.ofp-btn .lbl-hover { display: none; }
.ofp-btn.following { background: #eef1f9; border-color: #dde3f0; color: #4a5578; }
.ofp-btn.following:hover { background: #fce8ec; border-color: #bf1339; color: #bf1339; }
.ofp-btn.following:hover .lbl-default { display: none; }
.ofp-btn.following:hover .lbl-hover { display: inline; }
.ofp-btn.following:hover i { color: #bf1339; }

/* ── Toast + follow login/register modal ── */
.kc-toast {
    position: fixed; left: 50%; bottom: 28px; transform: translate(-50%, 20px);
    background: #021e44; color: #fff; padding: 14px 24px; border-radius: 50px;
    font-size: 13px; font-weight: 700; box-shadow: 0 12px 40px rgba(0,0,0,.3);
    display: flex; align-items: center; gap: 8px; z-index: 9500;
    opacity: 0; transition: opacity .3s, transform .3s; pointer-events: none;
    max-width: 90vw; text-align: center;
}
.kc-toast.show { opacity: 1; transform: translate(-50%, 0); }
.kc-toast i { color: #fec411; flex-shrink: 0; }

.kc-modal-overlay {
    position: fixed; inset: 0; z-index: 9800;
    background: rgba(2,20,50,.75); backdrop-filter: blur(6px);
    display: none; align-items: center; justify-content: center; padding: 20px;
}
.kc-modal-overlay.show { display: flex; }
.kc-modal-box {
    position: relative; width: 100%; max-width: 480px; height: min(88vh, 760px);
    border-radius: 24px; overflow: hidden; box-shadow: 0 30px 90px rgba(0,0,0,.5);
    background: #021e44;
}
.kc-modal-box iframe { width: 100%; height: 100%; border: none; display: block; background: #021e44; }
.kc-modal-loading { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; color: rgba(255,255,255,.7); font-size: 24px; pointer-events: none; }
.kc-modal-close {
    position: absolute; top: 14px; right: 14px; z-index: 2;
    width: 36px; height: 36px; border-radius: 50%;
    background: rgba(255,255,255,.15); border: none; color: #fff; font-size: 14px;
    cursor: pointer; display: flex; align-items: center; justify-content: center;
    backdrop-filter: blur(6px); transition: background .2s;
}
.kc-modal-close:hover { background: rgba(255,255,255,.3); }
</style>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body>

<div class="bg-scene" style="background-image:url('/<?= $banner_url ?>');"></div>
<div class="bg-veil"></div>

<nav class="topnav">
    <a href="/" class="nb">KEY<span>CHELE</span></a>
    <div class="bc">
        <a href="/">Home</a><span class="s">›</span>
        <a href="/events">Events</a><span class="s">›</span>
        <span><?= htmlspecialchars(mb_strimwidth($event['title'] ?? '', 0, 34, '…')) ?></span>
    </div>
</nav>

<?php if ($just_paid): ?>
<!-- SUCCESS PAGE -->
<div class="sp">
    <div class="sc">
        <div class="si si-ok"><i class="fas fa-check"></i></div>
        <h1 class="stit">You're In! 🎉</h1>
        <p class="ssub">
            Your <?= $quantity_display > 1 ? "<strong>{$quantity_display} tickets have</strong>" : '<strong>ticket has</strong>' ?> been confirmed.
        </p>
        <div class="tkbdg"><?= htmlspecialchars($ticket_number_display) ?></div>
        <div class="email-note">
            <i class="fas fa-envelope" style="color:var(--crimson);"></i>
            Ticket emailed to <strong style="color:var(--navy);"><?= htmlspecialchars($buyer_email_display) ?></strong>
        </div>
        <div style="background:var(--navy-l);border-radius:12px;padding:14px 18px;margin:22px 0;text-align:left;border-left:4px solid var(--gold);">
            <p style="font-size:13px;color:var(--navy);line-height:1.65;">
                <strong style="font-family:var(--fh);">Next steps:</strong>
                Open your email to find the PDF attachment, or download it directly below. Present it at the event entrance — printed or on your phone.
            </p>
        </div>
        <div class="suc-actions">
            <a href="https://mail.google.com/mail/u/0/#search/from%3Atickets%40keychele.co.ke"
               target="_blank" rel="noopener" class="sac-btn sac-gmail">
                <svg width="20" height="20" viewBox="0 0 48 48" xmlns="http://www.w3.org/2000/svg" style="flex-shrink:0;">
                    <rect fill="#EA4335" x="10" y="10" width="28" height="28" rx="2"/>
                    <path fill="#fff" d="M10 14l14 10 14-10v20H10z"/>
                    <path fill="#EA4335" d="M10 14l14 10 14-10"/>
                    <path fill="#fff" stroke="#fff" stroke-width="0.5" d="M24 24L10 14v20h5V20l9 6.5 9-6.5v14h5V14z"/>
                </svg>
                Open Gmail to find your ticket
            </a>
            <a href="/ticket_download.php?ticket=<?= urlencode($ticket_number_display) ?>&email=<?= urlencode($buyer_email_display) ?>"
               class="sac-btn sac-dl" target="_blank" rel="noopener">
                <i class="fas fa-download"></i> Download Ticket PDF
            </a>
            <a href="/" class="sac-btn sac-home">
                <i class="fas fa-arrow-left"></i> Back to Home
            </a>
        </div>
    </div>
</div>

<?php elseif ($waiting): ?>
<!-- MPESA WAITING -->
<div class="sp">
    <div class="sc" style="max-width:460px;">
        <div class="si si-wt" style="width:78px;height:78px;font-size:28px;">
            <i class="fas fa-mobile-alt"></i>
        </div>
        <h1 class="stit" style="font-size:26px;">Check Your Phone</h1>
        <p class="ssub">An M-Pesa prompt has been sent. Enter your <strong style="color:var(--navy);">M-Pesa PIN</strong> to complete the payment.</p>
        <div class="mps">
            <div class="mps-row mdon">
                <div class="mico mico-d"><i class="fas fa-check"></i></div>
                <span class="mlbl mld">Payment request sent to your phone</span>
            </div>
            <div class="mps-row mact" id="st2">
                <div class="mico mico-a" id="si2"><i class="fas fa-sync-alt"></i></div>
                <span class="mlbl mla" id="sl2">Waiting for M-Pesa PIN confirmation…</span>
            </div>
            <div class="mps-row" id="st3">
                <div class="mico mico-w" id="si3"><i class="fas fa-ticket-alt"></i></div>
                <span class="mlbl" id="sl3">Ticket will be issued and emailed to you</span>
            </div>
        </div>
        <div class="cb-wrap">
            <div class="cb" id="cbar" style="width:100%;"></div>
        </div>
        <p class="cb-txt">Timeout in <strong id="countdown">40</strong>s — do not close this page</p>
        <div style="margin-top:18px;background:var(--gold-l);border:1px solid rgba(254,196,17,.4);border-radius:10px;padding:12px 15px;font-size:13px;color:#7a6008;">
            <i class="fas fa-info-circle" style="color:var(--gold-d);margin-right:5px;"></i>
            No prompt? Check your phone's notification tray or wait a moment.
        </div>
    </div>
</div>
<script>
const TOT = 40; let secs = TOT;
const cdEl = document.getElementById('countdown');
const cb   = document.getElementById('cbar');
const tmr = setInterval(() => {
    secs--; cdEl.textContent = secs;
    cb.style.width = (secs / TOT * 100) + '%';
    if (secs <= 10) cb.style.background = 'var(--crimson)';
    if (secs <= 0) {
        clearInterval(tmr); clearInterval(pol);
        document.getElementById('sl2').textContent = 'Timed out — redirecting…';
        setTimeout(() => location.href = '/ticket/<?= $event_id ?>', 2000);
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
    fetch('/check_ticket_payment.php').then(r => r.json()).then(d => {
        if (d.success && d.status === 'completed') {
            clearInterval(pol); clearInterval(tmr);
            setDone('si2','sl2','Payment confirmed!'); setAct('si3','sl3','Issuing your ticket…');
            cb.style.width = '100%'; cb.style.background = 'var(--green)';
            setTimeout(() => {
                if (d.ticket_number && d.buyer_email) {
                    location.href = '/ticket_download.php?ticket=' + encodeURIComponent(d.ticket_number) + '&email=' + encodeURIComponent(d.buyer_email);
                } else if (d.ticket_number) {
                    location.href = '/ticket_download.php?ticket=' + encodeURIComponent(d.ticket_number);
                } else { location.reload(); }
            }, 2400);
        } else if (d.status === 'failed') {
            clearInterval(pol); clearInterval(tmr);
            setDone('si2','sl2','Payment not completed.');
            setTimeout(() => location.href = '/ticket/<?= $event_id ?>', 1500);
        }
    }).catch(() => {});
}
const pol = setInterval(chk, 4000); chk();
</script>

<?php elseif ($failed): ?>
<!-- FAILED -->
<div class="sp">
    <div class="sc">
        <div class="si si-no"><i class="fas fa-times"></i></div>
        <h1 class="stit">Payment Failed</h1>
        <p class="ssub"><?= htmlspecialchars($error) ?></p>
        <?php if ($payment_fail_reason !== ''): ?>
        <div class="ea" style="text-align:left;margin:18px 0;">
            <i class="fas fa-exclamation-circle"></i>
            <p><strong>Reason:</strong> <?= htmlspecialchars($payment_fail_reason) ?></p>
        </div>
        <?php else: ?>
        <p style="font-size:13px;color:var(--g400);margin:16px 0;">
            The prompt may have been cancelled, timed out, or you had insufficient balance.
        </p>
        <?php endif; ?>
        <a href="/ticket/<?= $event_id ?>" class="sbtn sb-cr"><i class="fas fa-redo"></i> Try Again</a>
    </div>
</div>

<?php elseif ($all_sold_out || $deadline_passed): ?>
<!-- CLOSED / SOLD OUT -->
<div class="sp">
    <div class="sc">
        <div class="si si-cl">
            <i class="fas <?= $deadline_passed ? 'fa-calendar-times' : 'fa-ban' ?>"></i>
        </div>
        <h1 class="stit"><?= $deadline_passed ? 'Sales Closed' : 'Sold Out' ?></h1>
        <p class="ssub">
            <?= $deadline_passed ? htmlspecialchars($deadline_message) : 'All tickets for this event have been sold.' ?>
        </p>
        <a href="/" class="sbtn sb-nv"><i class="fas fa-arrow-left"></i> Back to Home</a>
    </div>
</div>

<?php else: ?>
<!-- ════════ MAIN PAGE ════════ -->
<div class="mw">
    <div class="grid2">

        <!-- LEFT COLUMN -->
        <div>
            <!-- Cinematic banner card -->
            <div class="ev-card">
                <div class="ev-card-img">
                    <img src="/<?= $banner_url ?>"
                         alt="<?= htmlspecialchars($event['title']) ?>"
                         id="mainBannerImg"
                         onclick="openBannerLightbox(this.src)"
                         onerror="this.src='https://via.placeholder.com/900x506?text=Event+Banner'"/>
                    <div class="ev-img-overlay"></div>
                    <div class="ev-card-foot">
                        <div class="ev-gold-bar"></div>
                        
                    </div>
                </div>
            </div>
            <div class="ev-title"><?= htmlspecialchars($event['title']) ?></div>
            <!-- Pills -->
            <div class="pills">
                <div class="pill"><i class="far fa-calendar"></i><?= date('D, d M Y', strtotime($event['start_date'] ?? 'now')) ?></div>
                <div class="pill"><i class="far fa-clock"></i><?= date('g:i A', strtotime($event['start_time'] ?? 'now')) ?></div>
                <div class="pill"><i class="fas fa-map-marker-alt"></i><?= htmlspecialchars(mb_strimwidth($event['venue'] ?? 'TBA', 0, 38, '…')) ?></div>
            </div>

            <!-- Share -->
            <div class="share">
                <span class="share-lbl">Share</span>
                <a href="#" class="sh" title="Facebook"><i class="fab fa-facebook-f"></i></a>
                <a href="#" class="sh" title="Twitter/X"><i class="fab fa-x-twitter"></i></a>
                <a href="#" class="sh" title="WhatsApp"><i class="fab fa-whatsapp"></i></a>
                <a href="#" class="sh" title="LinkedIn"><i class="fab fa-linkedin-in"></i></a>
            </div>

            <!-- Organizer -->
            <div class="ic">
                <div class="ic-ttl">Organized By</div>
                <div class="hrow">
                    <div class="hav"><?= strtoupper(substr($event['full_name'] ?? $event['username'] ?? 'E', 0, 1)) ?></div>
                    <div>
                        <div class="hn"><?= htmlspecialchars($event['full_name'] ?? $event['username'] ?? 'Event Organizer') ?></div>
                        <div class="ht">Event Organizer · Keychele</div>
                    </div>
                </div>
                <?php if ($creator_id_val): ?>
                <div class="ofp">
                    <p class="ofp-txt"><i class="fas fa-bell"></i> Follow to get notified if they post another event</p>
                    <button type="button" class="ofp-btn <?= $isFollowingOrganizer ? 'following' : '' ?>"
                            id="followOrganizerBtn" data-creator-id="<?= (int)$creator_id_val ?>"
                            onclick="return handleFollowClick(this)">
                        <i class="fas fa-<?= $isFollowingOrganizer ? 'check' : 'plus' ?>"></i>
                        <span class="lbl-default"><?= $isFollowingOrganizer ? 'Following' : 'Follow' ?></span>
                        <?php if ($isFollowingOrganizer): ?><span class="lbl-hover">Unfollow</span><?php endif; ?>
                    </button>
                </div>
                <?php endif; ?>
            </div>

            <!-- About -->
            <div class="ic">
                <div class="ic-ttl">About This Event</div>
                <p class="desc"><?= nl2br(htmlspecialchars($event['description'] ?? 'No description available.')) ?></p>
            </div>

            <!-- Venue Map -->
            <?php if (!empty($event['location_url'])): ?>
            <div class="ic ic-map">
                <div class="ic-ttl"><i class="fas fa-map-location-dot" style="color:#bf1339;font-size:13px;"></i>Venue &amp; Location</div>
                <div class="map-venue-name">
                    <i class="fas fa-map-marker-alt"></i>
                    <?= htmlspecialchars($event['venue'] ?? '') ?>
                </div>
                <div class="map-wrap">
                    <iframe
                        src="<?= htmlspecialchars(buildMapEmbedUrl($event['location_url'])) ?>"
                        width="100%" height="320"
                        style="border:none;display:block;"
                        loading="lazy"
                        allowfullscreen
                        referrerpolicy="no-referrer-when-downgrade">
                    </iframe>
                </div>
                <a href="<?= htmlspecialchars($event['location_url']) ?>" target="_blank" rel="noopener noreferrer" class="map-open-btn">
                    <i class="fas fa-arrow-up-right-from-square"></i> Open in Google Maps
                </a>
            </div>
            <?php endif; ?>
        </div>

        <!-- RIGHT: PURCHASE PANEL -->
        <div class="pp">
            <div class="pp-hd">
                <p class="pp-eye">Secure Ticket Purchase</p>
                <h2>Get your tickets to<br><em><?= htmlspecialchars(mb_strimwidth($event['title'] ?? '', 0, 45, '…')) ?></em></h2>
                <p>Select a ticket type and fill in your details</p>
            </div>

            <div class="pp-body">
                <?php if ($deadline_passed): ?>
                    <div class="ea"><i class="fas fa-calendar-times"></i><p><?= htmlspecialchars($deadline_message) ?></p></div>

                <?php elseif (empty($ticket_types)): ?>
                    <div style="text-align:center;padding:40px 0;">
                        <i class="fas fa-ticket-alt" style="font-size:46px;color:var(--g200);display:block;margin-bottom:14px;"></i>
                        <p style="color:var(--g400);font-size:15px;">No ticket types available yet.</p>
                    </div>

                <?php else: ?>
                    <div class="nb-box">
                        <i class="fas fa-shield-alt"></i>
                        <p><strong>Secure payments via Mecrofex Technologies</strong>Pay via M-Pesa STK Push. Your ticket PDF is emailed instantly upon confirmation.</p>
                    </div>

                    <div class="ttl">
                        <?php foreach ($ticket_types as $type):
                            $so       = (bool)$type['is_sold_out'];
                            $ifr      = ($type['price'] == 0);
                            $unlimited = ($type['quantity_available'] == 0);
                            $low = !$so && !$unlimited && $type['slots_left'] > 0 && $type['slots_left'] <= 20;
                        ?>
                        <div class="ttc <?= $so ? 'sout' : '' ?>"
                             data-id="<?= $type['id'] ?>"
                             data-price="<?= $type['price'] ?>"
                             data-slots="<?= $type['slots_left'] ?>"
                             data-unlimited="<?= $unlimited ? '1' : '0' ?>"
                             data-name="<?= htmlspecialchars($type['type_name']) ?>"
                             data-free="<?= $ifr ? '1' : '0' ?>"
                             <?= !$so ? 'onclick="selType(this)"' : '' ?>>
                            <div class="ttc-top">
                                <div>
                                    <div class="tt-n"><?= htmlspecialchars($type['type_name']) ?></div>
                                    <div class="tt-p <?= $ifr ? 'fp' : '' ?>">
                                        <?= $ifr ? 'FREE' : 'KES '.number_format($type['price']) ?>
                                    </div>
                                   <?php if (!$so && $cts_show_remaining && !$unlimited): ?>
    <div class="tt-sl <?= $low ? 'low' : '' ?>">
        <?= $low ? '⚠ Only ' . number_format($type['slots_left']) . ' remaining' : number_format($type['slots_left']) . ' remaining' ?>
    </div>
<?php endif; ?>
                                </div>
                                <div>
                                    <?php if ($so): ?><span class="bdg bdg-so">SOLD OUT</span>
                                    <?php elseif ($ifr): ?><span class="bdg bdg-fr">FREE</span>
                                    <?php else: ?><span class="bdg bdg-gd">AVAILABLE</span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <?php if (!$so): ?>
                                <?php if (isset($fanPendingDeposits[$type['id']])): ?>
<div class="dep-option-row" id="dor_<?= $type['id'] ?>">
    <a href="/pay-balance?ref=<?= urlencode($fanPendingDeposits[$type['id']]['deposit_ref']) ?>"
       class="dep-pay-btn bal-pay-btn"
       onclick="return confirmBalance(event, this, '<?= htmlspecialchars($type['type_name']) ?>', <?= (float)$fanPendingDeposits[$type['id']]['balance_due'] ?>)">
        <i class="fas fa-hourglass-half"></i>
        Pay Remaining Balance — KES <?= number_format($fanPendingDeposits[$type['id']]['balance_due']) ?>
        <span class="dep-full-hint">You already paid a deposit for this ticket</span>
    </a>
</div>
                                <?php elseif (!$ifr && !empty($type['deposit_enabled']) && $type['deposit_amount'] > 0): ?>
<div class="dep-option-row" id="dor_<?= $type['id'] ?>">
    <a href="/deposit-parttime-payment?ticket_type_id=<?= $type['id'] ?>&event_id=<?= $event_id ?>"
       class="dep-pay-btn"
       onclick="return confirmDeposit(event, this, '<?= htmlspecialchars($type['type_name']) ?>', <?= (float)$type['deposit_amount'] ?>, <?= (float)$type['price'] ?>)">
        <i class="fas fa-down-to-line"></i>
        Pay Deposit — KES <?= number_format($type['deposit_amount']) ?>
        <span class="dep-full-hint">Full price: KES <?= number_format($type['price']) ?></span>
    </a>
</div>
<?php endif; ?>

                            <div class="qty-wr" id="qw_<?= $type['id'] ?>">
                                <button type="button" class="qb" onclick="aq(<?= $type['id'] ?>,-1,event)">−</button>
                                <input type="number" class="qn" id="qn_<?= $type['id'] ?>"
                                       value="1" min="1" max="<?= $unlimited ? 9999 : $type['slots_left'] ?>" readonly/>
                                <button type="button" class="qb" onclick="aq(<?= $type['id'] ?>,1,event)">+</button>
                            </div>

                            <?php if (!$ifr): ?>
                            <div class="ca-wrap" id="ca_<?= $type['id'] ?>">
                                <div class="ca-lbl">
                                    Pay Custom Amount
                                    <span>Optional — minimum KES <?= number_format($type['price']) ?>/ticket</span>
                                </div>
                                <div class="ca-inp-row">
                                    <div class="ca-pfx">KES</div>
                                    <input type="number"
                                           class="ca-inp"
                                           id="cai_<?= $type['id'] ?>"
                                           min="<?= $type['price'] ?>"
                                           step="1"
                                           placeholder="<?= $type['price'] ?> (or enter more)"
                                           oninput="onCustomAmt(<?= $type['id'] ?>, <?= $type['price'] ?>)"/>
                                </div>
                                <div class="ca-hint" id="cah_<?= $type['id'] ?>">
                                    <i class="fas fa-info-circle"></i>
                                    Leave blank to pay the standard price
                                </div>
                            </div>
                            <?php endif; ?>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="tot-row">
                        <span class="tot-lbl">Order Total</span>
                        <span class="tot-amt dim" id="totAmt">Select a ticket</span>
                    </div>

                    <form method="POST" action="/ticket/<?= $event_id ?>" id="pForm">
                        <input type="hidden" name="csrf_token"    value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                        <input type="hidden" name="ticket_type"   id="selTId"       value="">
                        <input type="hidden" name="quantity"      id="totQty"       value="0">
                        <input type="hidden" name="custom_amount" id="custAmtHidden" value="">

                        <div class="fdiv">Your Details</div>

                        <div class="fg">
                            <label class="fl" for="fn">Full Name</label>
                            <input type="text" id="fn" name="name" class="fi" placeholder="Jane Mwangi" required autocomplete="name"
                                   value="<?= htmlspecialchars($_SESSION['fan_name'] ?? '') ?>"/>
                        </div>
                        <div class="fg">
                            <label class="fl" for="fe">Email Address</label>
                            <input type="email" id="fe" name="email" class="fi" placeholder="jane@example.com" required autocomplete="email"
                                   value="<?= htmlspecialchars($_SESSION['fan_email'] ?? '') ?>"/>
                            <p class="fh-txt"><i class="fas fa-info-circle"></i> Ticket PDF will be sent here</p>
                        </div>
                        <div class="fg">
                            <label class="fl" for="fp">M-Pesa Phone</label>
                            <input type="tel" id="fp" name="phone" class="fi" placeholder="0712 345 678" required autocomplete="tel"/>
                            <p class="fh-txt">Kenyan numbers only (07XX or 01XX)</p>
                        </div>

                        <label class="fg" style="display:flex;align-items:flex-start;gap:9px;cursor:pointer;font-weight:normal;">
                            <input type="checkbox" id="fsms" name="sms_opt_in" style="width:17px;height:17px;margin-top:2px;flex-shrink:0;accent-color:#16a34a;">
                            <span style="font-size:13px;line-height:1.5;color:#475569;">
                                Send me SMS updates about this event (reminders, gate info). Msg &amp; data rates may apply — reply <strong>STOP</strong> anytime to opt out.
                            </span>
                        </label>

                        <button type="submit" class="buybtn" id="buyBtn" disabled>
                            <i class="fas fa-lock" style="font-size:13px;"></i>
                            <span id="btnTxt">Select a ticket to continue</span>
                        </button>

                        <p class="sec-note">
                            <i class="fas fa-shield-alt" style="color:#16a34a;"></i>
                            Secured by <span class="mpill">M-PESA</span>
                        </p>

                        <?php if ($error): ?>
                        <div class="ea"><i class="fas fa-exclamation-circle"></i><p><?= htmlspecialchars($error) ?></p></div>
                        <?php endif; ?>
                    </form>
                <?php endif; ?>
            </div>
        </div>

    </div><!-- end .grid2 -->

    <?php if (!empty($other_events)): ?>
    <!-- MORE FROM ORGANIZER -->
    <div class="sec-hd">
        <div class="sec-hd-left">
            <h2>More from this <span>Organizer</span></h2>
            <div class="sec-bar"></div>
            <p>Other events you might enjoy</p>
        </div>
    </div>

    <div class="eg" id="evGrid">
        <?php foreach (array_slice($other_events, 0, 4) as $oe):
            $ob  = "/msanii/uploads/banners/" . htmlspecialchars($oe['banner'] ?: 'default-event.jpg');
            $op  = $oe['min_price'];
            $ofr = ($op == 0 || $op === null);
            $opr = $ofr ? 'Free' : 'KES ' . number_format($op);
        ?>
        <a href="/ticket/<?= $oe['id'] ?>" class="ecard">
            <div class="ecard-img">
                <img src="<?= $ob ?>"
                     alt="<?= htmlspecialchars($oe['title']) ?>"
                     onerror="this.src='https://via.placeholder.com/400x400?text=Event'"
                     loading="lazy"/>
                <div class="ecard-img-fade"></div>
                <div class="ecard-date-badge">
                    <i class="far fa-calendar"></i>
                    <?= date('d M', strtotime($oe['start_date'])) ?>
                </div>
                <div class="ecard-price-tag <?= $ofr ? 'free-tag' : '' ?>">
                    <?= htmlspecialchars($opr) ?>
                </div>
            </div>
            <div class="ecard-body">
                <div class="ecard-title"><?= htmlspecialchars($oe['title']) ?></div>
                <div class="ecard-venue">
                    <i class="fas fa-map-marker-alt"></i>
                    <?= htmlspecialchars(mb_strimwidth($oe['venue'] ?? 'TBA', 0, 32, '…')) ?>
                </div>
            </div>
        </a>
        <?php endforeach; ?>
    </div>

    <?php if (count($other_events) > 4): ?>
    <div class="load-more-wrap">
        <button class="load-more-btn" id="loadMoreBtn">
            <i class="fas fa-chevron-down"></i> Load More Events
        </button>
    </div>
    <script>
    const remainingEvents = <?= json_encode(array_slice($other_events, 4)) ?>;
    let nextIndex = 0;
    const BATCH = 4;

    document.getElementById('loadMoreBtn').addEventListener('click', function () {
        const grid = document.getElementById('evGrid');
        const btn  = this;
        const batch = remainingEvents.slice(nextIndex, nextIndex + BATCH);

        if (!batch.length) {
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-check"></i> All events shown';
            return;
        }

        batch.forEach(ev => {
            const isFree     = (!ev.min_price || ev.min_price == 0);
            const priceLabel = isFree ? 'Free' : 'KES ' + Number(ev.min_price).toLocaleString();
            const dateLabel  = new Date(ev.start_date).toLocaleDateString('en-KE', { day: '2-digit', month: 'short' });
            const bannerSrc  = '/msanii/uploads/banners/' + (ev.banner || 'default-event.jpg');
            const venue      = (ev.venue || 'TBA').substring(0, 32);

            const card = document.createElement('a');
            card.href  = '/ticket/' + ev.id;
            card.className = 'ecard ecard-entering';
            card.innerHTML = `
                <div class="ecard-img">
                    <img src="${bannerSrc}"
                         alt="${ev.title.replace(/"/g,'&quot;')}"
                         loading="lazy"
                         onerror="this.src='https://via.placeholder.com/400x400?text=Event'"/>
                    <div class="ecard-img-fade"></div>
                    <div class="ecard-date-badge"><i class="far fa-calendar"></i> ${dateLabel}</div>
                    <div class="ecard-price-tag${isFree ? ' free-tag' : ''}">${priceLabel}</div>
                </div>
                <div class="ecard-body">
                    <div class="ecard-title">${ev.title.substring(0, 60)}</div>
                    <div class="ecard-venue"><i class="fas fa-map-marker-alt"></i> ${venue}</div>
                </div>`;

            grid.appendChild(card);
            requestAnimationFrame(() => requestAnimationFrame(() => {
                card.classList.add('ecard-entered');
            }));
        });

        nextIndex += batch.length;
        if (nextIndex >= remainingEvents.length) {
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-check"></i> All events shown';
        }
    });
    </script>
    <?php endif; ?>
    <?php endif; ?>

</div><!-- end .mw -->
<style>
    /* ═══════════════════════════════════════════════════════════
   Keychele — Ticket Page Styles
   Full rewrite with 4-column poster grid & class fixes
═══════════════════════════════════════════════════════════ */

*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
img { display: block; max-width: 100%; }

:root {
    --navy:         #052e64;
    --navy-d:       #031e44;
    --navy-m:       #0a4a99;
    --navy-l:       #e8eef8;
    --navy-xl:      #f2f5fc;
    --gold:         #fec411;
    --gold-d:       #d9a70a;
    --gold-l:       #fffbea;
    --crimson:      #bf1339;
    --crimson-d:    #96102d;
    --crimson-l:    #fce8ec;
    --white:        #ffffff;
    --g50:          #f8f9fd;
    --g100:         #eef1f9;
    --g200:         #dce2f0;
    --g400:         #8a97b8;
    --g600:         #4a5578;
    --g800:         #1a2340;
    --green:        #16a34a;
    --fh: 'Montserrat', system-ui, sans-serif;
    --fb: 'Glacial Indifference', 'Montserrat', system-ui, sans-serif;
    --fm: 'Courier New', monospace;
}

html { scroll-behavior: smooth; }

body {
    font-family: var(--fb);
    background: var(--navy-d);
    color: var(--g600);
    min-height: 100vh;
    overflow-x: hidden;
    -webkit-font-smoothing: antialiased;
}

/* ── Background ─────────────────────────────────────────── */
.bg-scene {
    position: fixed; inset: 0; z-index: 0;
    background-position: center;
    background-size: cover;
    background-repeat: no-repeat;
    filter: blur(24px) brightness(0.15) saturate(0.4);
    transform: scale(1.06);
}
.bg-veil {
    position: fixed; inset: 0; z-index: 1;
    background: linear-gradient(
        150deg,
        rgba(5,46,100,.65) 0%,
        rgba(5,46,100,.20) 40%,
        rgba(3,15,35,.92) 100%
    );
}

/* ── Nav ────────────────────────────────────────────────── */
.topnav {
    position: relative; z-index: 30;
    height: 62px;
    display: flex; align-items: center; justify-content: space-between;
    padding: 0 32px;
    background: rgba(5,46,100,.97);
    border-bottom: 3px solid var(--gold);
    backdrop-filter: blur(12px);
}
.nb {
    font-family: var(--fh);
    font-weight: 900; font-size: 21px;
    letter-spacing: 3px; color: #fff;
    text-decoration: none;
}
.nb span { color: var(--gold); }
.bc {
    display: flex; align-items: center; gap: 6px;
    font-size: 12px; color: rgba(255,255,255,.4);
}
.bc a { color: rgba(255,255,255,.55); text-decoration: none; transition: color .2s; }
.bc a:hover { color: var(--gold); }
.bc .s { color: rgba(255,255,255,.2); }

/* ── Wrapper ────────────────────────────────────────────── */
.mw {
    position: relative; z-index: 5;
    max-width: 1300px; margin: 0 auto;
    padding: 36px 20px 90px;
}

/* ── Two-column layout ──────────────────────────────────── */
.grid2 {
    display: grid;
    grid-template-columns: 1fr 420px;
    gap: 30px;
    align-items: start;
}

/* ── Event banner card ──────────────────────────────────── */
.ev-card {
    border-radius: 18px; overflow: hidden;
    box-shadow: 0 24px 64px rgba(5,46,100,.55);
    margin-bottom: 20px;
    background: var(--navy-d);
}
.ev-card-img {
    position: relative;
    width: 100%;
    aspect-ratio: 4 / 5;
    overflow: hidden;
    background: #050e26;
    display: flex;
    align-items: center;
    justify-content: center;
}
.ev-card-img img {
    width: 100%;
    height: 100%;
    display: block;
    object-fit: contain;
    object-position: center center;
    transition: transform .65s ease;
    cursor: zoom-in;
}
.ev-card:hover .ev-card-img img { transform: scale(1.025); }
.ev-img-overlay {
    position: absolute; inset: 0;
    background: linear-gradient(
        to top,
        rgba(3,14,38,.96)  0%,
        rgba(5,46,100,.28) 42%,
        rgba(0,0,0,.05)    100%
    );
}
.ev-card-foot {
    position: absolute; bottom: 0; left: 0; right: 0;
    padding: 28px 26px 24px;
}
.ev-gold-bar {
    width: 44px; height: 3px;
    background: var(--gold);
    border-radius: 2px;
    margin-bottom: 12px;
}
.ev-title {
    font-family: var(--fh);
    font-weight: 900;
    font-size: clamp(18px, 3vw, 30px);
    color: #fff;
    line-height: 1.25;
    text-shadow: 0 2px 20px rgba(0,0,0,.7);
    letter-spacing: -.2px;
}

/* ── Pills ──────────────────────────────────────────────── */
.pills { display: flex; flex-wrap: wrap; gap: 9px; margin-bottom: 20px; }
.pill {
    display: inline-flex; align-items: center; gap: 7px;
    background: rgba(5,46,100,.62);
    border: 1px solid rgba(254,196,17,.32);
    color: rgba(255,255,255,.9);
    border-radius: 30px; padding: 8px 16px;
    font-size: 13px; font-weight: 600;
    backdrop-filter: blur(8px);
    box-shadow: 0 2px 8px rgba(0,0,0,.18);
}
.pill i { color: var(--gold); font-size: 11px; }

/* ── Share ──────────────────────────────────────────────── */
.share { display: flex; align-items: center; gap: 10px; margin-bottom: 26px; flex-wrap: wrap; }
.share-lbl {
    font-family: var(--fh); font-size: 9px; font-weight: 800;
    letter-spacing: 2.5px; text-transform: uppercase;
    color: rgba(255,255,255,.35);
}
.sh {
    width: 38px; height: 38px;
    display: flex; align-items: center; justify-content: center;
    border-radius: 50%; color: rgba(255,255,255,.65);
    border: 1px solid rgba(255,255,255,.16);
    background: rgba(5,46,100,.45);
    text-decoration: none; font-size: 14px;
    transition: all .2s;
    backdrop-filter: blur(6px);
}
.sh:hover {
    background: var(--gold); border-color: var(--gold);
    color: var(--navy); transform: translateY(-3px);
    box-shadow: 0 6px 16px rgba(254,196,17,.35);
}

/* ── Info Cards ─────────────────────────────────────────── */
.ic {
    background: rgba(255,255,255,.99); border-radius: 16px; padding: 24px 26px;
    margin-bottom: 16px;
    box-shadow: 0 4px 24px rgba(5,46,100,.16), 0 1px 4px rgba(0,0,0,.06);
    border: 1px solid rgba(255,255,255,.7);
}
.ic-ttl {
    font-family: var(--fh); font-weight: 700; font-size: 11px;
    letter-spacing: 2.5px; text-transform: uppercase; color: var(--navy);
    display: flex; align-items: center; gap: 10px; margin-bottom: 16px;
}
.ic-ttl::after {
    content: ''; flex: 1; height: 2px;
    background: linear-gradient(to right, var(--gold), transparent);
}
.hrow { display: flex; align-items: center; gap: 14px; }
.hav {
    width: 48px; height: 48px; border-radius: 50%;
    background: var(--navy); color: var(--gold);
    display: flex; align-items: center; justify-content: center;
    font-family: var(--fh); font-size: 20px; font-weight: 900; flex-shrink: 0;
}
.hn { font-family: var(--fh); font-weight: 700; font-size: 15px; color: var(--navy); }
.ht { font-size: 12px; color: var(--g400); margin-top: 2px; }
.desc { font-size: 15px; color: var(--g600); line-height: 1.8; }

/* ── Venue Map ──────────────────────────────────────────── */
.ic-map { padding: 22px 22px 18px; }
.map-venue-name {
    display: flex; align-items: center; gap: 8px;
    font-size: 13px; font-weight: 600; color: var(--navy);
    margin-bottom: 14px;
}
.map-venue-name i { color: #bf1339; font-size: 13px; flex-shrink: 0; }
.map-wrap {
    border-radius: 12px; overflow: hidden;
    border: 1.5px solid rgba(5,46,100,.1);
    box-shadow: 0 4px 18px rgba(5,46,100,.1);
    margin-bottom: 12px;
}
.map-open-btn {
    display: inline-flex; align-items: center; gap: 7px;
    padding: 9px 18px; border-radius: 30px;
    background: var(--navy); color: #fff;
    font-size: 12px; font-weight: 700;
    font-family: var(--fh); letter-spacing: .5px;
    text-decoration: none;
    transition: background .2s, transform .2s;
}
.map-open-btn:hover { background: #0a3a7a; transform: translateY(-1px); }
.map-open-btn i { font-size: 11px; }

/* ── Purchase Panel ─────────────────────────────────────── */
.pp {
    border-radius: 20px; overflow: hidden;
    box-shadow: 0 20px 64px rgba(5,46,100,.48), 0 2px 8px rgba(0,0,0,.12);
    position: sticky; top: 20px;
    border: 1px solid rgba(254,196,17,.18);
}
.pp-hd {
    background: linear-gradient(135deg, var(--navy-d) 0%, var(--navy) 55%, #0a3a80 100%);
    padding: 28px 28px 24px;
    border-bottom: 3px solid var(--gold);
    position: relative; overflow: hidden;
}
.pp-hd::before {
    content: ''; position: absolute; top: -40px; right: -40px;
    width: 120px; height: 120px; border-radius: 50%;
    background: rgba(254,196,17,.07);
    pointer-events: none;
}
.pp-hd::after {
    content: ''; position: absolute; bottom: -20px; left: -20px;
    width: 80px; height: 80px; border-radius: 50%;
    background: rgba(254,196,17,.05);
    pointer-events: none;
}
.pp-eye {
    font-family: var(--fh); font-size: 9px; font-weight: 800;
    letter-spacing: 3px; text-transform: uppercase;
    color: var(--gold); margin-bottom: 10px;
    display: flex; align-items: center; gap: 8px;
}
.pp-eye::before {
    content: ''; display: inline-block; width: 18px; height: 2px; background: var(--gold); border-radius: 1px;
}
.pp-hd h2 {
    font-family: var(--fh); font-size: clamp(15px,2vw,18px);
    font-weight: 800; color: #fff; line-height: 1.35;
    position: relative;
}
.pp-hd h2 em { font-style: normal; color: var(--gold); }
.pp-hd p { font-size: 13px; color: rgba(255,255,255,.48); margin-top: 7px; }
.pp-body { background: var(--white); padding: 24px 26px 30px; }

/* ── Notice box ─────────────────────────────────────────── */
.nb-box {
    display: flex; gap: 11px; align-items: flex-start;
    background: var(--gold-l); border: 1px solid rgba(254,196,17,.45);
    border-left: 4px solid var(--gold); border-radius: 10px;
    padding: 13px 15px; margin-bottom: 20px;
}
.nb-box i { color: var(--gold-d); font-size: 14px; margin-top: 2px; flex-shrink: 0; }
.nb-box p { font-size: 12.5px; color: #7a6008; line-height: 1.55; }
.nb-box strong { display: block; color: #5c4706; margin-bottom: 2px; font-family: var(--fh); }

/* ── Ticket Types ───────────────────────────────────────── */
.ttl { display: flex; flex-direction: column; gap: 10px; margin-bottom: 20px; }
.ttc {
    border: 2px solid var(--g200); border-radius: 12px; padding: 15px 17px;
    cursor: pointer; background: var(--g50); transition: all .2s;
    position: relative; overflow: hidden;
}
.ttc::before {
    content: ''; position: absolute; left: 0; top: 0; bottom: 0; width: 4px;
    background: var(--navy); transform: scaleY(0);
    transition: transform .2s; border-radius: 4px 0 0 4px;
}
.ttc.sel {
    border-color: var(--navy); background: var(--navy-xl);
    box-shadow: 0 0 0 1px var(--navy), 0 4px 18px rgba(5,46,100,.12);
}
.ttc.sel::before { transform: scaleY(1); }
.ttc.sout { opacity: .5; cursor: not-allowed; }
.ttc:not(.sout):not(.sel):hover {
    border-color: var(--gold);
    box-shadow: 0 2px 14px rgba(254,196,17,.25);
}
.ttc-top { display: flex; align-items: flex-start; justify-content: space-between; gap: 10px; }
.tt-n { font-family: var(--fh); font-weight: 700; font-size: 15px; color: var(--navy); margin-bottom: 4px; }
.tt-p { font-family: var(--fh); font-weight: 900; font-size: 22px; color: var(--crimson); }
.tt-p.fp { color: var(--green); }
.tt-sl { font-size: 12px; color: var(--g400); margin-top: 3px; }
.tt-sl.low { color: var(--crimson); font-weight: 700; }

.bdg {
    font-family: var(--fh); font-size: 10px; font-weight: 800;
    letter-spacing: 1px; padding: 4px 10px; border-radius: 20px; flex-shrink: 0;
}
.bdg-so { background: #fee2e2; color: #dc2626; }
.bdg-fr { background: #dcfce7; color: var(--green); }
.bdg-av { background: var(--navy-l); color: var(--navy); }
.bdg-gd { background: var(--gold-l); color: var(--gold-d); }

/* Quantity picker */
.qty-wr {
    display: none; align-items: center; margin-top: 13px;
    max-width: 165px; border: 1.5px solid var(--g200);
    border-radius: 10px; overflow: hidden;
}
.qty-wr.on { display: flex; }
.qb {
    width: 41px; height: 42px; display: flex; align-items: center; justify-content: center;
    background: var(--g50); border: none; cursor: pointer; font-size: 20px;
    color: var(--navy); transition: background .15s; flex-shrink: 0;
}
.qb:hover { background: var(--navy-l); }
.qb:disabled { opacity: .4; cursor: not-allowed; }
.qn {
    width: 100%; border: none; background: #fff; text-align: center;
    font-family: var(--fh); font-weight: 700; font-size: 16px; color: var(--navy);
}

/* Custom amount */
.ca-wrap { display: none; margin-top: 15px; padding-top: 15px; border-top: 1px dashed var(--g200); }
.ca-wrap.on { display: block; }
.ca-lbl { font-family: var(--fh); font-weight: 700; font-size: 12px; color: var(--navy); margin-bottom: 8px; }
.ca-lbl span { display: block; font-weight: 400; font-size: 10px; color: var(--g400); margin-top: 1px; }
.ca-inp-row {
    display: flex; align-items: center; background: #fff;
    border: 1.5px solid var(--g200); border-radius: 8px; overflow: hidden;
}
.ca-pfx {
    padding: 0 12px; background: var(--g50); border-right: 1.5px solid var(--g200);
    font-family: var(--fh); font-weight: 700; font-size: 13px; color: var(--g400);
}
.ca-inp {
    flex: 1; border: none; padding: 10px 12px;
    font-family: var(--fh); font-weight: 700; font-size: 15px;
    color: var(--navy); outline: none;
}
.ca-hint { margin-top: 6px; font-size: 10px; color: var(--g400); display: flex; align-items: center; gap: 4px; }
.ca-hint.err { color: var(--crimson); }

/* ── Order total ────────────────────────────────────────── */
.tot-row {
    display: flex; align-items: center; justify-content: space-between;
    padding: 18px 0; margin-top: 10px; border-top: 2px solid var(--g100);
}
.tot-lbl {
    font-family: var(--fh); font-weight: 700; font-size: 13px;
    color: var(--g400); text-transform: uppercase; letter-spacing: 1px;
}
.tot-amt { font-family: var(--fh); font-weight: 900; font-size: 24px; color: var(--navy); }
.tot-amt.dim { color: var(--g200); font-size: 16px; font-weight: 500; }
.tot-amt.free { color: var(--green); }

/* ── Form ───────────────────────────────────────────────── */
.fdiv {
    font-family: var(--fh); font-weight: 800; font-size: 10px;
    letter-spacing: 2px; text-transform: uppercase; color: var(--navy);
    margin: 15px 0 18px; display: flex; align-items: center; gap: 10px;
}
.fdiv::after { content: ''; flex: 1; height: 1.5px; background: var(--g100); }
.fg { margin-bottom: 16px; }
.fl {
    display: block; font-family: var(--fh); font-weight: 700;
    font-size: 12px; color: var(--navy); margin-bottom: 6px;
}
.fi {
    width: 100%; padding: 12px 15px;
    border: 1.5px solid var(--g200); border-radius: 10px;
    font-family: var(--fb); font-size: 15px; color: var(--navy); transition: all .2s;
}
.fi:focus {
    border-color: var(--navy); background: var(--navy-xl);
    outline: none; box-shadow: 0 0 0 3px rgba(5,46,100,.08);
}
.fh-txt { font-size: 11px; color: var(--g400); margin-top: 5px; display: flex; align-items: center; gap: 4px; }

/* ── Buy button ─────────────────────────────────────────── */
.buybtn {
    width: 100%; padding: 17px; border-radius: 13px; border: none;
    background: linear-gradient(135deg, var(--navy) 0%, var(--navy-m) 100%);
    color: #fff;
    font-family: var(--fh); font-weight: 800; font-size: 16px;
    cursor: pointer; transition: all .25s;
    display: flex; align-items: center; justify-content: center; gap: 10px; margin-top: 12px;
    box-shadow: 0 4px 16px rgba(5,46,100,.28);
    letter-spacing: .3px;
}
.buybtn:hover:not(:disabled) {
    background: linear-gradient(135deg, #0a3a80 0%, #0e52b8 100%);
    transform: translateY(-2px);
    box-shadow: 0 10px 28px rgba(5,46,100,.38);
}
.buybtn:active:not(:disabled) { transform: translateY(0); box-shadow: 0 4px 14px rgba(5,46,100,.22); }
.buybtn:disabled { background: var(--g200); color: var(--g400); cursor: not-allowed; box-shadow: none; }

.sec-note {
    display: flex; align-items: center; justify-content: center; gap: 8px;
    margin-top: 18px; font-size: 12px; color: var(--g400); font-weight: 500;
}
.mpill {
    background: #00be37; color: #fff;
    padding: 2px 8px; border-radius: 4px; font-weight: 900; font-size: 10px;
}

/* ── Error alert ────────────────────────────────────────── */
.ea {
    background: var(--crimson-l); border: 1px solid rgba(191,19,57,.2);
    border-radius: 10px; padding: 12px 15px; margin-top: 20px;
    display: flex; align-items: flex-start; gap: 10px;
}
.ea i { color: var(--crimson); font-size: 14px; margin-top: 2px; }
.ea p { font-size: 12.5px; color: var(--crimson-d); line-height: 1.5; }

/* ── Section heading ────────────────────────────────────── */
.sec-hd {
    margin-top: 80px; margin-bottom: 32px;
    text-align: center; position: relative; z-index: 5;
}
.sec-hd-left { text-align: center; }
.sec-hd h2 { font-family: var(--fh); font-weight: 900; font-size: 28px; color: #fff; }
.sec-hd h2 span { color: var(--gold); }
.sec-bar { width: 60px; height: 4px; background: var(--gold); margin: 12px auto; border-radius: 2px; }
.sec-hd p { font-size: 15px; color: rgba(255,255,255,.5); }

/* ══════════════════════════════════════════════════════════
   MORE FROM ORGANIZER — 4-column poster grid
══════════════════════════════════════════════════════════ */
.eg {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 22px;
    position: relative; z-index: 5;
}

.ecard {
    background: #fff;
    border-radius: 14px;
    overflow: hidden;
    text-decoration: none;
    display: flex;
    flex-direction: column;
    box-shadow: 0 8px 30px rgba(0,0,0,.22);
    transition: transform .3s ease, box-shadow .3s ease;
}
.ecard:hover {
    transform: translateY(-8px);
    box-shadow: 0 22px 50px rgba(5,46,100,.42);
}

/* Poster image — portrait ratio */
.ecard-img {
    position: relative;
    width: 100%;
    aspect-ratio: 3 / 4;
    overflow: hidden;
    background: var(--g100);
    flex-shrink: 0;
}
.ecard-img img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
    transition: transform .5s ease;
}
.ecard:hover .ecard-img img { transform: scale(1.06); }

.ecard-img-fade {
    position: absolute;
    inset: 0;
    background: linear-gradient(
        to top,
        rgba(5,46,100,.92) 0%,
        rgba(5,46,100,.30) 45%,
        transparent 100%
    );
}

/* Date badge — top left */
.ecard-date-badge {
    position: absolute;
    top: 8px;
    left: 8px;
    display: inline-flex;
    align-items: center;
    width: fit-content;
    height: auto;
    background: rgba(5,46,100,.82);
    color: #fff;
    font-family: var(--fh);
    font-size: 10px;
    font-weight: 700;
    padding: 3px 8px;
    border-radius: 20px;
    gap: 4px;
    border: 1px solid rgba(254,196,17,.40);
    backdrop-filter: blur(4px);
    letter-spacing: .2px;
    line-height: 1;
    white-space: nowrap;
}
.ecard-date-badge i { color: var(--gold); font-size: 9px; }

/* Price tag — top right */
.ecard-price-tag {
    position: absolute;
    bottom: 12px;
    left: 12px;
    top: auto;
    right: auto;
    display: inline-flex;
    align-items: center;
    width: auto;
    height: auto;
    background: var(--crimson);
    color: #fff;
    font-family: var(--fh);
    font-size: 12px;
    font-weight: 800;
    padding: 4px 12px;
    border-radius: 6px;
    letter-spacing: .4px;
    line-height: 1.4;
    white-space: nowrap;
}
.ecard-price-tag.free-tag { background: var(--green); }

/* Body */
.ecard-body {
    padding: 14px 16px 18px;
    display: flex;
    flex-direction: column;
    gap: 7px;
    flex: 1;
}
.ecard-title {
    font-family: var(--fh);
    font-weight: 800;
    font-size: 14px;
    color: var(--navy);
    line-height: 1.35;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}
.ecard-venue {
    font-size: 12px;
    color: var(--g400);
    display: flex;
    align-items: center;
    gap: 5px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.ecard-venue i { color: var(--crimson); font-size: 10px; flex-shrink: 0; }

/* Entering animation (used by JS load-more) */
.ecard-entering {
    opacity: 0;
    transform: translateY(20px);
    transition: opacity .35s ease, transform .35s ease;
}
.ecard-entered {
    opacity: 1;
    transform: translateY(0);
}

/* ── Load more ──────────────────────────────────────────── */
.load-more-wrap {
    text-align: center; margin-top: 36px;
    position: relative; z-index: 5;
}
.load-more-btn {
    background: rgba(5,46,100,.55);
    color: rgba(255,255,255,.85);
    border: 1px solid rgba(255,255,255,.18);
    padding: 13px 38px;
    border-radius: 30px;
    font-family: var(--fh);
    font-size: 14px;
    font-weight: 700;
    cursor: pointer;
    transition: all .2s;
    display: inline-flex; align-items: center; gap: 8px;
    backdrop-filter: blur(6px);
}
.load-more-btn:hover:not(:disabled) {
    background: var(--navy);
    border-color: var(--gold);
    color: #fff;
}
.load-more-btn:disabled { opacity: .45; cursor: not-allowed; }

/* ══════════════════════════════════════════════════════════
   SUCCESS / WAITING / FAILED / CLOSED PAGES
══════════════════════════════════════════════════════════ */
.sp {
    position: relative; z-index: 10;
    min-height: 90vh;
    display: flex; align-items: center; justify-content: center;
    padding: 20px;
}
.sc {
    background: #fff; border-radius: 24px; padding: 45px 35px;
    text-align: center;
    box-shadow: 0 25px 70px rgba(0,0,0,.4);
    width: 100%; max-width: 520px;
}
.si {
    width: 85px; height: 85px; border-radius: 50%;
    margin: 0 auto 24px;
    display: flex; align-items: center; justify-content: center; font-size: 32px;
}
.si-ok { background: #dcfce7; color: var(--green); }
.si-wt { background: var(--navy-l); color: var(--navy); }
.si-no { background: #fce8ec; color: var(--crimson); }
.si-cl { background: var(--g100); color: var(--g400); }

.stit { font-family: var(--fh); font-weight: 900; font-size: 28px; color: var(--navy); margin-bottom: 10px; }
.ssub { font-size: 15px; color: var(--g600); line-height: 1.6; }

.tkbdg {
    display: inline-block; background: var(--navy-xl); color: var(--navy);
    font-family: var(--fm); font-weight: 700; font-size: 20px;
    letter-spacing: 2px; padding: 12px 24px; border-radius: 12px;
    border: 2px dashed var(--navy-m); margin: 24px 0;
}
.email-note {
    font-size: 13px; color: var(--g400);
    display: flex; align-items: center; justify-content: center; gap: 8px;
}

.suc-actions { display: flex; flex-direction: column; gap: 12px; margin-top: 30px; }
.sac-btn {
    display: flex; align-items: center; justify-content: center; gap: 10px;
    padding: 14px; border-radius: 12px; text-decoration: none;
    font-family: var(--fh); font-weight: 700; font-size: 14px; transition: all .2s;
}
.sac-gmail { background: #fff; color: #3c4043; border: 1.5px solid #dadce0; }
.sac-gmail:hover { background: #f8f9fa; border-color: #d2d4d7; }
.sac-dl { background: var(--navy); color: #fff; }
.sac-dl:hover { background: var(--navy-m); transform: translateY(-2px); }
.sac-home { background: var(--g100); color: var(--g600); }
.sac-home:hover { background: var(--g200); }

/* ── M-Pesa waiting steps ───────────────────────────────── */
.mps {
    margin: 24px 0; text-align: left;
    background: var(--g50); border-radius: 16px; padding: 20px;
}
.mps-row { display: flex; align-items: center; gap: 15px; margin-bottom: 18px; opacity: .4; }
.mps-row:last-child { margin-bottom: 0; }
.mps-row.mdon, .mps-row.mact { opacity: 1; }
.mico {
    width: 32px; height: 32px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 12px; flex-shrink: 0;
}
.mico-d { background: var(--green); color: #fff; }
.mico-a { background: var(--navy); color: #fff; animation: spin 2s linear infinite; }
.mico-w { background: var(--g200); color: var(--g400); }
@keyframes spin { 100% { transform: rotate(360deg); } }
.mlbl { font-size: 14px; font-weight: 500; color: var(--g400); }
.mla { color: var(--navy); font-weight: 700; }
.mld { color: var(--green); font-weight: 700; }

.cb-wrap { height: 6px; background: var(--g100); border-radius: 3px; overflow: hidden; margin-bottom: 10px; }
.cb { height: 100%; background: var(--navy); transition: width 1s linear; }
.cb-txt { font-size: 12px; color: var(--g400); }

/* ── Simple action buttons ──────────────────────────────── */
.sbtn {
    display: inline-flex; align-items: center; gap: 10px;
    padding: 14px 28px; border-radius: 12px; text-decoration: none;
    font-family: var(--fh); font-weight: 800; transition: all .2s; margin-top: 24px;
}
.sb-cr { background: var(--crimson); color: #fff; }
.sb-cr:hover { background: var(--crimson-d); transform: translateY(-2px); }
.sb-nv { background: var(--navy); color: #fff; }
.sb-nv:hover { background: var(--navy-m); transform: translateY(-2px); }

/* ── Pagination (kept for reference) ───────────────────── */
.pagination {
    display: flex; justify-content: center; align-items: center;
    gap: 10px; margin-top: 40px;
}
.pg-btn {
    width: 40px; height: 40px; border-radius: 50%;
    border: 1px solid rgba(255,255,255,.2);
    background: rgba(5,46,100,.5); color: #fff;
    display: flex; align-items: center; justify-content: center;
    cursor: pointer; transition: all .2s; text-decoration: none;
}
.pg-btn:hover:not(.disabled) { background: var(--gold); color: var(--navy); border-color: var(--gold); }
.pg-btn.active { background: var(--gold); color: var(--navy); border-color: var(--gold); font-weight: bold; }
.pg-btn.disabled { opacity: .3; cursor: not-allowed; }




/* ── Deposit option ─────────────────────────────────────── */
.dep-option-row {
    margin-top: 12px;
    padding-top: 12px;
    border-top: 1px dashed var(--g200);
}
.dep-pay-btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    background: linear-gradient(135deg, rgba(8,145,178,0.08), rgba(6,182,212,0.05));
    border: 1.5px solid rgba(8,145,178,0.35);
    border-radius: 10px;
    padding: 10px 16px;
    color: #0891b2;
    font-family: var(--fh);
    font-weight: 700;
    font-size: 13px;
    text-decoration: none;
    transition: all .2s;
    width: 100%;
    justify-content: center;
    flex-wrap: wrap;
}
.dep-pay-btn:hover {
    background: rgba(8,145,178,0.14);
    border-color: #0891b2;
    transform: translateY(-1px);
    box-shadow: 0 4px 14px rgba(8,145,178,0.18);
}
.dep-pay-btn i {
    font-size: 12px;
    flex-shrink: 0;
}
.dep-full-hint {
    font-size: 10px;
    font-weight: 500;
    color: var(--g400);
    width: 100%;
    text-align: center;
    margin-top: 2px;
}
.bal-pay-btn {
    background: linear-gradient(135deg, rgba(217,119,6,0.1), rgba(245,158,11,0.06));
    border-color: rgba(217,119,6,0.4);
    color: #b45309;
}
.bal-pay-btn:hover {
    background: rgba(217,119,6,0.16);
    border-color: #d97706;
    box-shadow: 0 4px 14px rgba(217,119,6,0.2);
}


/* ══════════════════════════════════════════════════════════
   RESPONSIVE
══════════════════════════════════════════════════════════ */

/* ══════════════════════════════════════════════════════════
   RESPONSIVE
══════════════════════════════════════════════════════════ */

/* Large tablet — 3 col event grid */
@media (max-width: 1200px) {
    .eg { grid-template-columns: repeat(3, 1fr); gap: 18px; }
}

/* Medium — stack main layout, purchase panel rises to top */
@media (max-width: 1080px) {
    .grid2 {
        grid-template-columns: 1fr;
        gap: 28px;
    }
    /* Show the buy panel first — it's the CTA */
    .pp {
        order: -1;
        position: static;
        border-radius: 18px;
    }
    .eg { grid-template-columns: repeat(3, 1fr); gap: 16px; }
}

/* Consistent poster height cap on large screens */
@media (min-width: 1081px) {
    .ev-card-img { max-height: 680px; }
}

/* Small tablet */
@media (max-width: 768px) {
    .eg { grid-template-columns: repeat(2, 1fr); gap: 14px; }
    .mw { padding: 24px 16px 70px; }
}

/* Mobile */
@media (max-width: 600px) {
    .topnav { padding: 0 14px; }
    .mw { padding: 18px 13px 56px; }
    .pp-hd { padding: 22px 20px 18px; }
    .pp-body { padding: 18px 18px 24px; }
    .ic { padding: 18px 20px; }

    /* Main banner — consistent poster ratio on mobile */
    .ev-card-img { aspect-ratio: 4 / 5; }

    /* 2-col portrait cards */
    .eg { grid-template-columns: repeat(2, 1fr); gap: 10px; }
    .ecard-img { aspect-ratio: 2 / 3; }

    .ecard-body { padding: 10px 12px 14px; gap: 5px; }
    .ecard-title { font-size: 12px; }
    .ecard-venue { font-size: 10px; }
    .ecard-date-badge { font-size: 9px; padding: 2px 6px; top: 6px; left: 6px; }
    .ecard-price-tag { font-size: 10px; padding: 3px 9px; bottom: 8px; left: 8px; }

    .sec-hd { margin-top: 48px; margin-bottom: 20px; }
    .sec-hd h2 { font-size: 22px; }
    .pills { gap: 7px; }
    .pill { font-size: 12px; padding: 7px 13px; }
}

/* Very small phones */
@media (max-width: 380px) {
    .eg { gap: 8px; }
    .ecard-title { font-size: 11px; }
    .ecard-date-badge, .ecard-price-tag { display: none; }
    .ev-card-img { aspect-ratio: 4 / 5; }
}

/* ── Banner lightbox ── */
#bannerLightbox {
    display: none;
    position: fixed;
    inset: 0;
    z-index: 2147483647;
    background: rgba(0,0,0,.93);
    align-items: center;
    justify-content: center;
    padding: 16px;
    cursor: zoom-out;
    animation: lbFadeIn .18s ease;
}
#bannerLightbox.open { display: flex; }
@keyframes lbFadeIn { from { opacity: 0 } to { opacity: 1 } }
#bannerLightbox img {
    max-width: 94vw;
    max-height: 92vh;
    object-fit: contain;
    border-radius: 10px;
    box-shadow: 0 16px 72px rgba(0,0,0,.7);
    cursor: default;
    display: block;
}
#lbCloseBtn {
    position: absolute;
    top: 14px;
    right: 18px;
    width: 44px;
    height: 44px;
    background: rgba(255,255,255,.15);
    border: none;
    border-radius: 50%;
    color: #fff;
    font-size: 22px;
    line-height: 1;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 2px 14px rgba(0,0,0,.4);
    transition: background .15s;
}
#lbCloseBtn:hover { background: rgba(220,38,38,.8); }
</style>

<!-- Banner Lightbox -->
<div id="bannerLightbox" onclick="if(event.target===this)closeBannerLightbox()">
    <button id="lbCloseBtn" onclick="closeBannerLightbox()" title="Close">&#x2715;</button>
    <img id="lbImg" src="" alt="Event Banner">
</div>
<script>
function openBannerLightbox(src) {
    var lb = document.getElementById('bannerLightbox');
    // Move to body root so it escapes any nested stacking context (z-index parents)
    if (lb.parentNode !== document.body) document.body.appendChild(lb);
    document.getElementById('lbImg').src = src;
    lb.classList.add('open');
    document.body.style.overflow = 'hidden';
}
function closeBannerLightbox() {
    document.getElementById('bannerLightbox').classList.remove('open');
    document.body.style.overflow = '';
}
document.addEventListener('keydown', function(e) { if (e.key === 'Escape') closeBannerLightbox(); });

let sId = null, sPrice = 0, sFree = false;

function selType(el) {
    document.querySelectorAll('.ttc').forEach(c => {
        c.classList.remove('sel');
        const q = c.querySelector('.qty-wr'); if (q) q.classList.remove('on');
        const a = c.querySelector('.ca-wrap'); if (a) a.classList.remove('on');
    });
    el.classList.add('sel');
    const qw = el.querySelector('.qty-wr'); if (qw) qw.classList.add('on');
    const ca = el.querySelector('.ca-wrap'); if (ca) ca.classList.add('on');

    sId    = el.dataset.id;
    sPrice = parseFloat(el.dataset.price) || 0;
    sFree  = el.dataset.free === '1';
    document.getElementById('selTId').value = sId;

    const cai = document.getElementById('cai_' + sId);
    if (cai) cai.value = '';
    document.getElementById('custAmtHidden').value = '';
    upd();
}

function aq(id, d, e) {
    if (e) e.stopPropagation();
    const inp = document.getElementById('qn_' + id); if (!inp) return;
    let v = parseInt(inp.value) + d;
    v = Math.max(1, Math.min(v, parseInt(inp.max)));
    inp.value = v;
    upd();
}

function onCustomAmt(id, minPrice) {
    const cai  = document.getElementById('cai_' + id);
    const hint = document.getElementById('cah_' + id);
    const val  = cai.value.trim();
    if (val === '') {
        hint.className = 'ca-hint';
        hint.innerHTML = '<i class="fas fa-info-circle"></i> Leave blank to pay the standard price';
        document.getElementById('custAmtHidden').value = '';
    } else {
        const num = parseFloat(val);
        if (isNaN(num) || num < minPrice) {
            hint.className = 'ca-hint err';
            hint.innerHTML = '<i class="fas fa-exclamation-triangle"></i> Minimum is KES ' + minPrice.toLocaleString() + ' per ticket';
            document.getElementById('custAmtHidden').value = '';
        } else {
            hint.className = 'ca-hint';
            hint.innerHTML = '<i class="fas fa-check-circle" style="color:var(--green);"></i> Custom amount set — thank you for your support!';
            document.getElementById('custAmtHidden').value = num;
        }
    }
    upd();
}

function upd() {
    const ta  = document.getElementById('totAmt');
    const btn = document.getElementById('buyBtn');
    const txt = document.getElementById('btnTxt');

    if (!sId) {
        ta.textContent = 'Select a ticket'; ta.className = 'tot-amt dim';
        btn.disabled = true; txt.textContent = 'Select a ticket to continue';
        document.getElementById('totQty').value = '0';
        return;
    }

    const inp = document.getElementById('qn_' + sId);
    const qty = inp ? parseInt(inp.value) : 1;
    document.getElementById('totQty').value = qty;

    const cai   = document.getElementById('cai_' + sId);
    const caRaw = cai ? cai.value.trim() : '';
    const customPerTicket = caRaw !== '' ? parseFloat(caRaw) : null;
    const customInvalid   = customPerTicket !== null && (isNaN(customPerTicket) || customPerTicket < sPrice);

    if (customInvalid) {
        btn.disabled = true;
        ta.textContent = 'Invalid amount'; ta.className = 'tot-amt dim';
        txt.textContent = 'Fix the custom amount to continue';
        return;
    }

    if (sFree) {
        ta.textContent = 'FREE'; ta.className = 'tot-amt free';
        btn.disabled = false;
        txt.textContent = qty > 1 ? `Reserve ${qty} Free Tickets` : 'Reserve Free Ticket';
    } else {
        const pricePerTicket = (customPerTicket !== null && customPerTicket >= sPrice) ? customPerTicket : sPrice;
        const tot = pricePerTicket * qty;
        ta.textContent = 'KES ' + tot.toLocaleString(); ta.className = 'tot-amt';
        btn.disabled = qty < 1;
        txt.textContent = qty > 1 ? `Pay KES ${tot.toLocaleString()} via M-Pesa` : 'Pay via M-Pesa';
    }
}

document.getElementById('pForm')?.addEventListener('submit', function(e) {
    const qty = parseInt(document.getElementById('totQty').value || 0);
    const tid = document.getElementById('selTId').value;

    if (!tid || qty < 1) {
        e.preventDefault();
        Swal.fire({icon:'warning',title:'No Ticket Selected',text:'Please choose a ticket type and quantity first.',confirmButtonColor:'#bf1339',confirmButtonText:'Got it'});
        return;
    }

    const card = document.querySelector('.ttc.sel');
    if (card) {
        const isUnlimited = card.dataset.unlimited === '1';
        const mx = parseInt(card.dataset.slots);
        if (!isUnlimited && qty > mx) {
            e.preventDefault();
            Swal.fire({icon:'error',title:'Not Enough Slots',text:`Only ${mx} ticket(s) remaining.`,confirmButtonColor:'#bf1339'});
            return;
        }
    }

    const cai = document.getElementById('cai_' + sId);
    if (cai && cai.value.trim() !== '') {
        const cv = parseFloat(cai.value);
        if (isNaN(cv) || cv < sPrice) {
            e.preventDefault();
            Swal.fire({icon:'error',title:'Invalid Custom Amount',text:`The minimum amount is KES ${sPrice.toLocaleString()} per ticket.`,confirmButtonColor:'#bf1339'});
            return;
        }
        document.getElementById('custAmtHidden').value = cv;
    }

    const btn = document.getElementById('buyBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> <span>Processing…</span>';
});


function confirmDeposit(e, link, typeName, depositAmt, fullPrice) {
    e.preventDefault();
    Swal.fire({
        icon: 'info',
        title: 'Pay Deposit for ' + typeName,
        html: `
            <p style="color:var(--g600);font-size:14px;line-height:1.7;margin-bottom:16px;">
                You're about to pay a <strong style="color:#0891b2;">deposit of KES ${depositAmt.toLocaleString()}</strong>
                towards the full ticket price of <strong style="color:var(--navy);">KES ${fullPrice.toLocaleString()}</strong>.
            </p>
            <div style="background:#f0f9ff;border:1px solid rgba(8,145,178,0.3);border-radius:8px;padding:10px 14px;font-size:12px;color:#0c4a6e;text-align:left;">
                <strong>Important:</strong> You'll need to pay the remaining
                <strong>KES ${(fullPrice - depositAmt).toLocaleString()}</strong>
                before the event deadline to secure your entry.
            </div>`,
        showCancelButton: true,
        confirmButtonText: 'Continue to Deposit Payment',
        cancelButtonText: 'Cancel',
        confirmButtonColor: '#0891b2',
        cancelButtonColor: '#6b7a99',
        reverseButtons: true,
    }).then(result => {
        if (result.isConfirmed) {
            if (kcFanLoggedIn) {
                window.location.href = link.href;
            } else {
                // link.href resolves to an absolute URL — account-login.php's
                // redirect sanitizer rejects anything not starting with "/"
                // and falls back to the homepage, so pass the raw relative
                // attribute instead.
                openActionModal('/account-login.php?embed=1&redirect=' + encodeURIComponent(link.getAttribute('href')));
            }
        }
    });
    return false;
}

function confirmBalance(e, link, typeName, balanceDue) {
    e.preventDefault();
    Swal.fire({
        icon: 'info',
        title: 'Pay Remaining Balance for ' + typeName,
        html: `
            <p style="color:var(--g600);font-size:14px;line-height:1.7;margin-bottom:16px;">
                You already paid a deposit for this ticket. Pay the remaining
                <strong style="color:#d97706;">KES ${balanceDue.toLocaleString()}</strong>
                now to receive your ticket PDF by email.
            </p>`,
        showCancelButton: true,
        confirmButtonText: 'Continue to Balance Payment',
        cancelButtonText: 'Cancel',
        confirmButtonColor: '#d97706',
        cancelButtonColor: '#6b7a99',
        reverseButtons: true,
    }).then(result => {
        if (result.isConfirmed) window.location.href = link.href;
    });
    return false;
}

</script>

<div id="kcToast" class="kc-toast"><i class="fas fa-fire"></i> <span id="kcToastMsg"></span></div>

<!-- FOLLOW MODAL: login/create-account gate for visitors who aren't logged in -->
<div id="kcModalOverlay" class="kc-modal-overlay" onclick="if(event.target===this) closeActionModal()">
  <div class="kc-modal-box">
    <div class="kc-modal-loading" id="kcModalLoading"><i class="fas fa-spinner fa-spin"></i></div>
    <button class="kc-modal-close" onclick="closeActionModal()" title="Close"><i class="fas fa-xmark"></i></button>
    <iframe id="kcModalFrame" src="about:blank" title="Keychele" onload="document.getElementById('kcModalLoading').style.display='none'"></iframe>
  </div>
</div>

<script>
let kcFanLoggedIn = <?= !empty($_SESSION['fan_id']) ? 'true' : 'false' ?>;
let kcModalCreatorId = null;
let kcToastTimer = null;

function showToast(msg) {
    document.getElementById('kcToastMsg').textContent = msg;
    const toast = document.getElementById('kcToast');
    requestAnimationFrame(() => toast.classList.add('show'));
    clearTimeout(kcToastTimer);
    kcToastTimer = setTimeout(() => toast.classList.remove('show'), 3200);
}

function openActionModal(url) {
    document.getElementById('kcModalLoading').style.display = 'flex';
    document.getElementById('kcModalFrame').src = url;
    document.getElementById('kcModalOverlay').classList.add('show');
    return false;
}
function closeActionModal() {
    document.getElementById('kcModalOverlay').classList.remove('show');
    document.getElementById('kcModalFrame').src = 'about:blank';
    kcModalCreatorId = null;
}
document.addEventListener('keydown', e => {
    if (e.key === 'Escape' && document.getElementById('kcModalOverlay').classList.contains('show')) closeActionModal();
});

function updateFollowUI(creatorId, following) {
    const btn = document.getElementById('followOrganizerBtn');
    if (!btn || parseInt(btn.dataset.creatorId) !== parseInt(creatorId)) return;
    btn.classList.toggle('following', following);
    btn.querySelector('i').className = 'fas fa-' + (following ? 'check' : 'plus');
    btn.querySelector('.lbl-default').textContent = following ? 'Following' : 'Follow';
    let hoverSpan = btn.querySelector('.lbl-hover');
    if (following && !hoverSpan) {
        hoverSpan = document.createElement('span');
        hoverSpan.className = 'lbl-hover';
        hoverSpan.textContent = 'Unfollow';
        btn.appendChild(hoverSpan);
    } else if (!following && hoverSpan) {
        hoverSpan.remove();
    }
}

function handleFollowClick(btn) {
    const creatorId = btn.dataset.creatorId;
    if (kcFanLoggedIn) {
        btn.disabled = true;
        fetch('/follow.php?creator=' + creatorId + '&ajax=1', { credentials: 'same-origin' })
            .then(r => r.json())
            .then(data => {
                btn.disabled = false;
                if (!data.success) { showToast('Please try again.'); return; }
                updateFollowUI(creatorId, data.following);
                showToast(data.following ? "You're now following this organizer!" : 'You unfollowed this organizer.');
            })
            .catch(() => { btn.disabled = false; showToast('Something went wrong. Please try again.'); });
    } else {
        kcModalCreatorId = creatorId;
        openActionModal('/follow.php?creator=' + creatorId + '&embed=1');
    }
    return false;
}

window.addEventListener('message', function (e) {
    if (e.origin !== window.location.origin) return;
    const data = e.data || {};
    if (data.type === 'kc-close') {
        closeActionModal();
        if (data.message) showToast(data.message);
    } else if (data.type === 'kc-followed') {
        const cid = kcModalCreatorId;
        closeActionModal();
        kcFanLoggedIn = true;
        if (cid) updateFollowUI(cid, data.following);
        showToast(data.following ? "You're now following this organizer!" : 'You unfollowed this organizer.');
    } else if (data.type === 'kc-fan-authenticated') {
        closeActionModal();
        kcFanLoggedIn = true;
        window.location.href = data.redirect;
    }
});
</script>

<?php endif; ?>
</body>
</html>