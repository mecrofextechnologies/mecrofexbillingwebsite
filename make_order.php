<?php
// === DEBUG — remove in production ===
ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();
include 'msanii/includes/connect.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require 'vendor/autoload.php';

// ─────────────────────────────────────────────────────────────
// NOTE: Run this once to add mpesa_receipt to your table:
//   ALTER TABLE preorder_purchases
//     ADD COLUMN mpesa_receipt VARCHAR(100) DEFAULT NULL;
// payment_ref (already in schema) stores the Safaricom CheckoutRequestID.
// ─────────────────────────────────────────────────────────────

// ─────────────────────────────────────────────────────────────
// MPESA CONFIG  (same as ticket.php)
// ─────────────────────────────────────────────────────────────
define('ORD_CONSUMER_KEY',    'ViMJdXYZVIgGsoWGcpEmKp1z69nq6r7atgeOnILgYoiXYUQC');
define('ORD_CONSUMER_SECRET', '8r6MlY2svifZADA4xmV6Pe8Avg3TxNQFMMfLxqI8jl6Ms7IK1VRrU0PN92vUGvRp');
define('ORD_SHORTCODE',       '3576741');
define('ORD_TILL_NUMBER',     '4991672');
define('ORD_PASSKEY',         'a9e2c0d0181d3391956cfdf16fdef60b3240186dfdd2c5f000e39b3a67801f35');
define('ORD_CALLBACK_URL',    'https://keychele.co.ke/order_callback.php');

function ord_getToken() {
    $creds = base64_encode(ORD_CONSUMER_KEY . ':' . ORD_CONSUMER_SECRET);
    $ch = curl_init('https://api.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials');
    curl_setopt_array($ch, [CURLOPT_HTTPHEADER=>['Authorization: Basic '.$creds], CURLOPT_RETURNTRANSFER=>true, CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_TIMEOUT=>30]);
    $res = json_decode(curl_exec($ch), true); curl_close($ch);
    return $res['access_token'] ?? false;
}

function ord_stkPush($phone, $amount, $ref, $product_title) {
    $ts  = date('YmdHis');
    $pwd = base64_encode(ORD_SHORTCODE . ORD_PASSKEY . $ts);
    $tok = ord_getToken();
    if (!$tok) return false;
    $ch = curl_init('https://api.safaricom.co.ke/mpesa/stkpush/v1/processrequest');
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER   => ['Authorization: Bearer '.$tok,'Content-Type: application/json'],
        CURLOPT_POST         => true,
        CURLOPT_POSTFIELDS   => json_encode([
            "BusinessShortCode" => ORD_SHORTCODE,
            "Password"          => $pwd,
            "Timestamp"         => $ts,
            "TransactionType"   => "CustomerBuyGoodsOnline",
            "Amount"            => $amount,
            "PartyA"            => $phone,
            "PartyB"            => ORD_TILL_NUMBER,
            "PhoneNumber"       => $phone,
            "CallBackURL"       => ORD_CALLBACK_URL,
            "AccountReference"  => $ref,
            "TransactionDesc"   => "Order: " . mb_substr($product_title,0,30) . " - $ref"
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT        => 60,
    ]);
    $res = json_decode(curl_exec($ch), true); curl_close($ch);
    return ($res['ResponseCode'] ?? '1') === '0' ? ($res['CheckoutRequestID'] ?? false) : false;
}

// ─────────────────────────────────────────────────────────────
// LOAD PRODUCT
// ─────────────────────────────────────────────────────────────
$preorder_id = (int)($_GET['id'] ?? 0);
if ($preorder_id <= 0) die("<h1 style='text-align:center;margin-top:5rem;color:#bf1339'>Invalid Product ID</h1>");

$stmt = $pdo->prepare("
    SELECT p.*,
           COALESCE(mp.full_name, c.username) AS creator_name,
           mp.profile_pic AS creator_pic,
           c.email        AS creator_email,
           c.id           AS creator_id_val
    FROM preorders p
    LEFT JOIN creators c  ON c.id = p.creator_id
    LEFT JOIN my_page mp  ON mp.creator_id = p.creator_id
    WHERE p.id = ? AND p.status IN ('active','closed','fulfilled')
");
$stmt->execute([$preorder_id]);
$product = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$product) die("<h1 style='text-align:center;margin-top:5rem;color:#bf1339'>Product Not Found</h1>");

// Tiers
$stmt = $pdo->prepare("SELECT * FROM preorder_tiers WHERE preorder_id = ? ORDER BY sort_order ASC, price ASC");
$stmt->execute([$preorder_id]);
$tiers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Stats
$stmt = $pdo->prepare("SELECT COUNT(*) AS orders, COALESCE(SUM(quantity),0) AS units FROM preorder_purchases WHERE preorder_id = ? AND payment_status = 'completed'");
$stmt->execute([$preorder_id]);
$stats = $stmt->fetch(PDO::FETCH_ASSOC);

$is_physical     = in_array($product['delivery_type'], ['physical','both']);
$deadline_passed = !empty($product['preorder_deadline']) && strtotime($product['preorder_deadline']) < time();
$is_closed       = in_array($product['status'], ['closed','fulfilled']) || $deadline_passed;
$cover_src       = !empty($product['cover_image']) ? ltrim($product['cover_image'], './\\') : '';

// ─────────────────────────────────────────────────────────────
// CHECK PENDING / COMPLETED SESSION
// ─────────────────────────────────────────────────────────────
$just_paid = $waiting = $failed = false;
$order_number_display = '';
$quantity_display     = 1;
$error                = '';
$payment_fail_reason  = $_SESSION['order_fail_reason'] ?? '';

if (isset($_SESSION['pending_order_checkout'])) {
    $cid = $_SESSION['pending_order_checkout'];
    $stmt = $pdo->prepare("SELECT * FROM preorder_purchases WHERE payment_ref = ?");
    $stmt->execute([$cid]);
    $pur = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($pur) {
        if ($pur['payment_status'] === 'completed') {
            $just_paid = true;
            $order_number_display = $pur['order_number'];
            $quantity_display     = $_SESSION['ord_qty'] ?? $pur['quantity'];
            unset($_SESSION['pending_order_checkout'], $_SESSION['ord_qty'], $_SESSION['order_fail_reason']);
        } elseif ($pur['payment_status'] === 'failed') {
            $failed = true;
            $error  = "Payment failed or was not completed.";
            unset($_SESSION['pending_order_checkout']);
        } else {
            $waiting = true;
        }
    } else {
        unset($_SESSION['pending_order_checkout']);
    }
}

// ─────────────────────────────────────────────────────────────
// HANDLE FORM SUBMISSION
// ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$is_closed && !$just_paid && !$waiting) {

    $name       = trim($_POST['name']  ?? '');
    $email      = trim($_POST['email'] ?? '');
    $phone_raw  = preg_replace('/\D/', '', $_POST['phone'] ?? '');
    $tier_id    = (int)($_POST['tier_id'] ?? 0);
    $quantity   = max(1, (int)($_POST['quantity'] ?? 1));

    // Delivery address
    $addr_line1 = trim($_POST['addr_line1'] ?? '');
    $addr_city  = trim($_POST['addr_city']  ?? '');
    $addr_county= trim($_POST['addr_county']?? '');
    $addr_notes = trim($_POST['addr_notes'] ?? '');

    // Phone normalisation (same as ticket.php)
    $phone = $phone_raw;
    if (strlen($phone_raw)===10 && in_array(substr($phone_raw,0,2),['07','01'])) $phone='254'.substr($phone_raw,1);
    elseif (strlen($phone_raw)===9 && in_array(substr($phone_raw,0,1),['7','1']))  $phone='254'.$phone_raw;
    elseif (strlen($phone_raw)===12 && substr($phone_raw,0,3)==='254')              $phone=$phone_raw;
    elseif (strlen($phone_raw)===13 && substr($phone_raw,0,4)==='+254')             $phone=substr($phone_raw,1);

    // Resolve tier price
    $selected_tier = null;
    if ($tier_id && $tiers) {
        foreach ($tiers as $t) { if ($t['id'] == $tier_id) { $selected_tier=$t; break; } }
    }
    $unit_price  = $selected_tier ? (float)$selected_tier['price'] : (float)$product['price'];
    $tier_name   = $selected_tier ? $selected_tier['name'] : ($product['title'] ?? 'Standard');
    $total       = $unit_price * $quantity;

    // Validation
    $v_errors = [];
    if (empty($name))                                           $v_errors[] = "Full name is required.";
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) $v_errors[] = "Valid email is required.";
    if (!preg_match('/^(254[17][0-9]{8})$/', $phone))          $v_errors[] = "Valid Kenyan phone number required (e.g. 0712 345 678).";
    if ($is_physical && empty($addr_line1))                     $v_errors[] = "Delivery address is required for physical orders.";
    if ($is_physical && empty($addr_city))                      $v_errors[] = "Town / City is required.";

    if ($v_errors) {
        $error = implode(' ', $v_errors);
    } else {
        $order_number = 'PRE-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));

        $shipping_json = $is_physical ? json_encode([
            'line1'  => $addr_line1,
            'city'   => $addr_city,
            'county' => $addr_county,
            'notes'  => $addr_notes,
        ]) : null;

        // Insert pending purchase
        $stmt = $pdo->prepare("
            INSERT INTO preorder_purchases
              (preorder_id, tier_id, buyer_name, buyer_email, buyer_phone,
               shipping_address, order_number, quantity, unit_price, total_amount,
               currency, payment_method, payment_ref,
               payment_status, created_at, updated_at)
            VALUES
              (?,?,?,?,?, ?,?,?,?,?, ?,?,?, 'pending', NOW(), NOW())
        ");
        $stmt->execute([
            $preorder_id, $tier_id ?: null,
            $name, $email, $phone,
            $shipping_json, $order_number, $quantity, $unit_price, $total,
            $product['currency'] ?? 'KES', 'mpesa', $order_number,
        ]);

        // STK push — store Safaricom CheckoutRequestID into payment_ref
        $checkoutID = ord_stkPush($phone, (int)$total, $order_number, $product['title']);
        if ($checkoutID) {
            $pdo->prepare("UPDATE preorder_purchases SET payment_ref = ? WHERE order_number = ?")
                ->execute([$checkoutID, $order_number]);
            $_SESSION['pending_order_checkout'] = $checkoutID;
            $_SESSION['ord_qty']                = $quantity;
            header("Location: make_order.php?id=$preorder_id"); exit;
        } else {
            $pdo->prepare("DELETE FROM preorder_purchases WHERE order_number = ?")->execute([$order_number]);
            $error = "Failed to initiate M-Pesa payment. Please try again.";
        }
    }
}

// ─────────────────────────────────────────────────────────────
// HELPERS
// ─────────────────────────────────────────────────────────────
$cat_icon = match($product['category'] ?? 'other') {
    'book'        => 'fa-book-open',
    'music'       => 'fa-music',
    'course'      => 'fa-graduation-cap',
    'merchandise' => 'fa-shirt',
    'software'    => 'fa-laptop-code',
    default       => 'fa-box-open',
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1.0"/>
<title><?= htmlspecialchars($product['title']) ?> — Order · Keychele</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Montserrat:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;0,900;1,400&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<style>
/* ═══════════════ RESET ═══════════════ */
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
img{display:block;max-width:100%;}

/* ═══════════════ TOKENS ═══════════════ */
:root{
    --navy:#052e64; --navy-d:#031e44; --navy-m:#0a4a99; --navy-l:#e8eef8; --navy-xl:#f2f5fc;
    --gold:#fec411; --gold-d:#d9a70a; --gold-l:#fffbea;
    --crimson:#bf1339; --crimson-d:#96102d; --crimson-l:#fce8ec;
    --green:#16a34a;
    --g50:#f8f9fd; --g100:#eef1f9; --g200:#dce2f0; --g400:#8a97b8; --g600:#4a5578; --g800:#1a2340;
    --fh:'Montserrat',system-ui,sans-serif;
    --fm:'Courier New',monospace;
}

/* ═══════════════ BASE ═══════════════ */
html{scroll-behavior:smooth;}
body{font-family:var(--fh);background:var(--navy-d);color:var(--g600);min-height:100vh;overflow-x:hidden;-webkit-font-smoothing:antialiased;}

/* ═══════════════ BG ═══════════════ */
.bg-scene{
    position:fixed;inset:0;z-index:0;
    <?php if($cover_src): ?>background:url('<?= htmlspecialchars($cover_src) ?>') center/cover no-repeat;<?php endif; ?>
    background-color:var(--navy-d);
    filter:blur(28px) brightness(0.14) saturate(0.3);
    transform:scale(1.07);
}
.bg-veil{position:fixed;inset:0;z-index:1;background:linear-gradient(150deg,rgba(5,46,100,.65) 0%,rgba(5,46,100,.18) 40%,rgba(3,15,35,.94) 100%);}

/* ═══════════════ TOPNAV ═══════════════ */
.topnav{
    position:relative;z-index:30;height:62px;
    display:flex;align-items:center;justify-content:space-between;
    padding:0 32px;
    background:rgba(5,46,100,.97);border-bottom:3px solid var(--gold);
    backdrop-filter:blur(12px);
}
.nb-logo{font-family:var(--fh);font-weight:900;font-size:21px;letter-spacing:3px;color:#fff;text-decoration:none;}
.nb-logo span{color:var(--gold);}
.bc{display:flex;align-items:center;gap:6px;font-size:12px;color:rgba(255,255,255,.4);}
.bc a{color:rgba(255,255,255,.55);text-decoration:none;transition:color .2s;}
.bc a:hover{color:var(--gold);}
.bc .s{color:rgba(255,255,255,.2);}

/* ═══════════════ MAIN WRAP ═══════════════ */
.mw{position:relative;z-index:5;max-width:1280px;margin:0 auto;padding:36px 20px 100px;}

/* ═══════════════ TWO-COL GRID ═══════════════ */
.grid2{display:grid;grid-template-columns:1fr 460px;gap:32px;align-items:start;}

/* ═══════════════ PRODUCT LEFT ═══════════════ */
.prod-img{
    position:relative;border-radius:18px;overflow:hidden;
    aspect-ratio:4/3;background:var(--navy);
    box-shadow:0 22px 60px rgba(5,46,100,.5);
    margin-bottom:20px;
}
.prod-img img{width:100%;height:100%;object-fit:cover;transition:transform .6s;}
.prod-img:hover img{transform:scale(1.04);}
.prod-img-placeholder{
    width:100%;height:100%;
    display:flex;align-items:center;justify-content:center;
    background:linear-gradient(135deg,var(--navy-d),var(--navy-m));
}
.prod-img-placeholder i{font-size:80px;color:rgba(254,196,17,.35);}
.prod-img-g{position:absolute;inset:0;background:linear-gradient(to top,rgba(5,46,100,.9) 0%,rgba(5,46,100,.2) 55%,transparent 100%);}
.prod-img-bot{position:absolute;bottom:0;left:0;right:0;padding:28px 26px 22px;}
.gold-bar{width:44px;height:4px;background:var(--gold);border-radius:2px;margin-bottom:10px;}
.prod-img-bot h1{font-family:var(--fh);font-weight:900;font-size:clamp(18px,2.8vw,30px);color:#fff;line-height:1.2;text-shadow:0 2px 14px rgba(0,0,0,.55);}
.prod-img-bot p{font-size:13px;color:rgba(255,255,255,.55);margin-top:6px;}

.pills{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:20px;}
.pill{display:inline-flex;align-items:center;gap:7px;background:rgba(5,46,100,.55);border:1px solid rgba(254,196,17,.28);color:rgba(255,255,255,.88);border-radius:30px;padding:7px 14px;font-size:12px;font-weight:500;backdrop-filter:blur(6px);}
.pill i{color:var(--gold);font-size:11px;}

.ic{background:rgba(255,255,255,.98);border-radius:16px;padding:22px 24px;margin-bottom:14px;box-shadow:0 4px 22px rgba(5,46,100,.13);}
.ic-ttl{font-family:var(--fh);font-weight:700;font-size:11px;letter-spacing:2.5px;text-transform:uppercase;color:var(--navy);display:flex;align-items:center;gap:10px;margin-bottom:14px;}
.ic-ttl::after{content:'';flex:1;height:2px;background:linear-gradient(to right,var(--gold),transparent);}
.hrow{display:flex;align-items:center;gap:14px;}
.hav{width:46px;height:46px;border-radius:50%;overflow:hidden;background:var(--navy);color:var(--gold);display:flex;align-items:center;justify-content:center;font-size:19px;font-weight:900;flex-shrink:0;}
.hav img{width:100%;height:100%;object-fit:cover;border-radius:50%;}
.hn{font-family:var(--fh);font-weight:700;font-size:14px;color:var(--navy);}
.ht{font-size:12px;color:var(--g400);margin-top:2px;}
.desc{font-size:14px;color:var(--g600);line-height:1.85;}

/* Progress bar */
.prog-area{margin-bottom:4px;}
.prog-lbl{display:flex;justify-content:space-between;font-size:11px;color:var(--g400);font-weight:700;margin-bottom:7px;}
.prog-pct{color:var(--navy);}
.prog-track{height:8px;background:var(--g200);border-radius:999px;overflow:hidden;}
.prog-fill{height:100%;border-radius:999px;background:linear-gradient(90deg,var(--navy),var(--navy-m));transition:width 1s ease;}

/* Perks */
.perks-grid{display:flex;flex-wrap:wrap;gap:7px;}
.perk-chip{display:inline-flex;align-items:center;gap:5px;background:#f0fdf4;color:#166534;border:1px solid #bbf7d0;font-size:12px;font-weight:600;padding:5px 11px;border-radius:999px;}
.perk-chip i{font-size:10px;}

/* ═══════════════ PURCHASE PANEL (right) ═══════════════ */
.pp{border-radius:18px;overflow:hidden;box-shadow:0 14px 55px rgba(5,46,100,.42);position:sticky;top:20px;}
.pp-hd{background:var(--navy);padding:26px 28px 22px;border-bottom:3px solid var(--gold);}
.pp-eye{font-size:9px;font-weight:800;letter-spacing:3px;text-transform:uppercase;color:var(--gold);margin-bottom:8px;}
.pp-hd h2{font-size:clamp(14px,1.8vw,17px);font-weight:800;color:#fff;line-height:1.35;}
.pp-hd h2 em{font-style:normal;color:var(--gold);}
.pp-hd p{font-size:12px;color:rgba(255,255,255,.45);margin-top:6px;}
.pp-body{background:#fff;padding:22px 26px 28px;}

/* Notice */
.nb-box{display:flex;gap:11px;align-items:flex-start;background:var(--gold-l);border:1px solid rgba(254,196,17,.45);border-left:4px solid var(--gold);border-radius:10px;padding:13px 15px;margin-bottom:18px;}
.nb-box i{color:var(--gold-d);font-size:14px;margin-top:2px;flex-shrink:0;}
.nb-box p{font-size:12px;color:#7a6008;line-height:1.55;}
.nb-box strong{display:block;color:#5c4706;margin-bottom:2px;}

/* Tier cards */
.tier-list{display:flex;flex-direction:column;gap:10px;margin-bottom:20px;}
.tc{border:2px solid var(--g200);border-radius:12px;padding:14px 16px;cursor:pointer;background:var(--g50);transition:all .2s;position:relative;overflow:hidden;}
.tc::before{content:'';position:absolute;left:0;top:0;bottom:0;width:4px;background:var(--navy);transform:scaleY(0);transition:transform .2s;border-radius:4px 0 0 4px;}
.tc.sel{border-color:var(--navy);background:var(--navy-xl);box-shadow:0 0 0 1px var(--navy),0 4px 18px rgba(5,46,100,.12);}
.tc.sel::before{transform:scaleY(1);}
.tc:not(.sel):hover{border-color:var(--gold);box-shadow:0 2px 14px rgba(254,196,17,.25);}
.tc-top{display:flex;align-items:flex-start;justify-content:space-between;gap:8px;}
.tc-name{font-weight:700;font-size:14px;color:var(--navy);margin-bottom:3px;}
.tc-price{font-weight:900;font-size:20px;color:var(--crimson);}
.tc-desc{font-size:12px;color:var(--g400);margin-top:4px;line-height:1.5;}
.bdg{font-size:9px;font-weight:800;letter-spacing:1px;padding:4px 9px;border-radius:20px;flex-shrink:0;}
.bdg-av{background:var(--navy-l);color:var(--navy);}
.bdg-pop{background:#fef3c7;color:#92400e;}

/* Qty */
.qty-row{display:flex;align-items:center;gap:12px;margin-top:12px;}
.qty-lbl{font-size:10px;font-weight:800;letter-spacing:1.5px;text-transform:uppercase;color:var(--navy);}
.qty-wr{display:flex;align-items:center;border:1.5px solid var(--g200);border-radius:10px;overflow:hidden;}
.qb{width:38px;height:40px;display:flex;align-items:center;justify-content:center;background:var(--g50);border:none;cursor:pointer;font-size:18px;color:var(--navy);transition:background .15s;}
.qb:hover{background:var(--navy-l);}
.qb:disabled{opacity:.3;cursor:not-allowed;}
.qn{width:46px;text-align:center;font-weight:800;font-size:17px;color:var(--navy);border:none;background:#fff;outline:none;padding:0;}

/* Total */
.tot-row{display:flex;justify-content:space-between;align-items:center;padding:14px 0;border-top:2px solid var(--g100);border-bottom:2px solid var(--g100);margin-bottom:20px;}
.tot-lbl{font-size:10px;font-weight:700;letter-spacing:2px;text-transform:uppercase;color:var(--g400);}
.tot-amt{font-weight:900;font-size:24px;color:var(--navy);}
.tot-amt.dim{color:var(--g400);font-weight:600;font-size:16px;}

/* Section divider */
.fdiv{font-size:10px;font-weight:800;letter-spacing:2px;text-transform:uppercase;color:var(--g400);display:flex;align-items:center;gap:10px;margin-bottom:16px;}
.fdiv::before,.fdiv::after{content:'';flex:1;height:1px;background:var(--g200);}

/* Form */
.fg{margin-bottom:12px;}
.fl{display:block;font-size:10px;font-weight:800;letter-spacing:1.5px;text-transform:uppercase;color:var(--navy);margin-bottom:5px;}
.fi{width:100%;padding:11px 13px;border:1.5px solid var(--g200);border-radius:10px;font-size:14px;color:var(--navy);background:var(--g50);transition:border-color .2s,box-shadow .2s,background .2s;outline:none;-webkit-appearance:none;}
.fi:focus{border-color:var(--navy);background:#fff;box-shadow:0 0 0 3px rgba(5,46,100,.1);}
.fi::placeholder{color:var(--g400);}
.fi-hint{font-size:11px;color:var(--g400);margin-top:4px;display:flex;align-items:center;gap:4px;}

/* Address section */
.addr-section{display:none;}
.addr-section.show{display:block;}
.addr-card{background:var(--navy-xl);border:1.5px solid rgba(5,46,100,.14);border-radius:12px;padding:16px 18px;margin-bottom:16px;}
.addr-card .addr-ttl{font-size:10px;font-weight:800;letter-spacing:2px;text-transform:uppercase;color:var(--navy);margin-bottom:13px;display:flex;align-items:center;gap:7px;}
.addr-card .addr-ttl i{color:var(--crimson);}
.frow{display:grid;grid-template-columns:1fr 1fr;gap:10px;}

/* Buy button */
.buybtn{width:100%;padding:16px;background:var(--crimson);color:#fff;border:none;border-radius:12px;font-family:var(--fh);font-size:14px;font-weight:800;letter-spacing:.5px;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:10px;transition:background .2s,transform .15s,box-shadow .2s;margin-top:8px;}
.buybtn:hover:not(:disabled){background:var(--crimson-d);transform:translateY(-2px);box-shadow:0 8px 26px rgba(191,19,57,.38);}
.buybtn:disabled{opacity:.4;cursor:not-allowed;transform:none;}
.mpill{background:#4caf50;color:#fff;font-size:9px;font-weight:800;letter-spacing:1px;padding:2px 8px;border-radius:4px;}
.sec-note{text-align:center;font-size:12px;color:var(--g400);margin-top:10px;display:flex;align-items:center;justify-content:center;gap:5px;}

/* Error */
.ea{display:flex;align-items:flex-start;gap:10px;background:var(--crimson-l);border:1px solid rgba(191,19,57,.25);border-left:4px solid var(--crimson);border-radius:10px;padding:13px 15px;margin-top:14px;}
.ea i{color:var(--crimson);flex-shrink:0;margin-top:2px;}
.ea p{font-size:13px;color:var(--crimson-d);line-height:1.55;}

/* ═══════════════ STATE PAGES ═══════════════ */
.sp{position:relative;z-index:5;min-height:calc(100vh - 62px);display:flex;align-items:center;justify-content:center;padding:24px;}
.sc{background:#fff;border-radius:24px;padding:52px 48px;text-align:center;max-width:520px;width:100%;box-shadow:0 28px 80px rgba(5,46,100,.50);}
.si{width:86px;height:86px;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 26px;font-size:34px;}
.si-ok{background:#dcfce7;color:var(--green);}
.si-no{background:var(--crimson-l);color:var(--crimson);}
.si-cl{background:var(--g100);color:var(--g400);}
.si-wt{background:var(--navy-l);color:var(--navy);animation:pr 2.2s infinite;}
@keyframes pr{0%,100%{box-shadow:0 0 0 0 rgba(5,46,100,.32);}50%{box-shadow:0 0 0 18px rgba(5,46,100,0);}}
.stit{font-family:var(--fh);font-size:30px;font-weight:800;color:var(--navy);margin-bottom:12px;line-height:1.1;}
.ssub{font-size:15px;color:var(--g600);line-height:1.7;margin-bottom:8px;}
.ordbdg{display:inline-block;background:var(--navy);color:var(--gold);font-family:var(--fm);font-size:16px;letter-spacing:3px;padding:12px 28px;border-radius:10px;margin:18px 0 6px;}
.sbtn{display:inline-flex;align-items:center;gap:8px;padding:14px 32px;border-radius:10px;font-family:var(--fh);font-size:14px;font-weight:800;text-decoration:none;transition:all .2s;margin-top:20px;}
.sb-nv{background:var(--navy);color:#fff;}
.sb-nv:hover{background:var(--navy-d);box-shadow:0 6px 22px rgba(5,46,100,.35);}
.sb-cr{background:var(--crimson);color:#fff;}
.sb-cr:hover{background:var(--crimson-d);box-shadow:0 6px 22px rgba(191,19,57,.32);}

/* M-Pesa steps */
.mps{display:flex;flex-direction:column;gap:8px;margin:22px 0;text-align:left;}
.mps-row{display:flex;align-items:center;gap:13px;padding:12px 15px;border-radius:10px;background:var(--g50);transition:background .3s;}
.mps-row.mact{background:var(--navy-l);}
.mps-row.mdon{background:#f0fdf4;}
.mico{width:32px;height:32px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:13px;flex-shrink:0;}
.mico-w{background:var(--g200);color:var(--g400);}
.mico-a{background:var(--navy);color:#fff;animation:sp 1s linear infinite;}
.mico-d{background:var(--green);color:#fff;}
@keyframes sp{to{transform:rotate(360deg);}}
.mlbl{font-size:13px;font-weight:500;color:var(--g600);}
.mlbl.mla{color:var(--navy);font-weight:700;}
.mlbl.mld{color:var(--green);}
.cb-wrap{background:var(--g100);border-radius:6px;height:6px;overflow:hidden;margin:18px 0 8px;}
.cb{height:100%;background:var(--navy);border-radius:6px;transition:width 1s linear;}
.cb-txt{font-size:12px;color:var(--g400);text-align:center;}

/* ═══════════════ RESPONSIVE ═══════════════ */
@media(max-width:1060px){.grid2{grid-template-columns:1fr!important;}.pp{position:static;}}
@media(max-width:640px){
    .topnav{padding:0 16px;}.nb-logo{font-size:17px;}
    .mw{padding:18px 12px 60px;}
    .sc{padding:34px 20px;}.stit{font-size:24px;}
    .pp-hd,.pp-body{padding-left:18px;padding-right:18px;}
    .ic{padding:18px 20px;}.bc{display:none;}
    .frow{grid-template-columns:1fr;}
}
</style>
</head>
<body>
<div class="bg-scene"></div>
<div class="bg-veil"></div>

<!-- ══ NAV ══ -->
<nav class="topnav">
    <a href="/" class="nb-logo">KEY<span>CHELE</span></a>
    <div class="bc">
        <a href="/">Home</a><span class="s">›</span>
        <a href="catalog.php">Catalog</a><span class="s">›</span>
        <span><?= htmlspecialchars(mb_strimwidth($product['title'], 0, 38, '…')) ?></span>
    </div>
</nav>

<?php if ($just_paid): ?>
<!-- ════════ SUCCESS ════════ -->
<div class="sp">
<div class="sc">
    <div class="si si-ok"><i class="fas fa-check"></i></div>
    <h1 class="stit">Order Confirmed! 🎉</h1>
    <p class="ssub">
        Your order for <strong><?= htmlspecialchars($product['title']) ?></strong> has been placed.
        A confirmation email with your order details has been sent to you.
    </p>
    <div class="ordbdg"><?= htmlspecialchars($order_number_display) ?></div>
    <p style="font-size:13px;color:var(--g400);margin-top:6px;">
        <i class="fas fa-envelope" style="color:var(--crimson);margin-right:5px;"></i>
        Check your inbox for your order confirmation
    </p>
    <?php if ($is_physical): ?>
    <div style="background:var(--navy-l);border-radius:12px;padding:15px 18px;margin:22px 0;text-align:left;border-left:4px solid var(--gold);">
        <p style="font-size:13px;color:var(--navy);line-height:1.65;">
            <strong style="font-family:var(--fh);">What's next?</strong>
            The creator will process and ship your order once the campaign closes.
            You'll receive a tracking update when your item ships.
        </p>
    </div>
    <?php else: ?>
    <div style="background:var(--navy-l);border-radius:12px;padding:15px 18px;margin:22px 0;text-align:left;border-left:4px solid var(--gold);">
        <p style="font-size:13px;color:var(--navy);line-height:1.65;">
            <strong style="font-family:var(--fh);">What's next?</strong>
            Your digital product will be delivered to your email on the launch date.
        </p>
    </div>
    <?php endif; ?>
    <a href="/" class="sbtn sb-nv"><i class="fas fa-arrow-left"></i> Back to Home</a>
</div>
</div>

<?php elseif ($waiting): ?>
<!-- ════════ MPESA WAITING ════════ -->
<div class="sp">
<div class="sc" style="max-width:460px;">
    <div class="si si-wt" style="width:78px;height:78px;font-size:28px;">
        <i class="fas fa-mobile-alt"></i>
    </div>
    <h1 class="stit" style="font-size:26px;">Check Your Phone</h1>
    <p class="ssub">An M-Pesa prompt has been sent to your phone. Enter your <strong style="color:var(--navy);">M-Pesa PIN</strong> to complete the payment.</p>

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
            <div class="mico mico-w" id="si3"><i class="fas fa-box"></i></div>
            <span class="mlbl" id="sl3">Order will be confirmed and emailed to you</span>
        </div>
    </div>

    <div class="cb-wrap"><div class="cb" id="cbar" style="width:100%;"></div></div>
    <p class="cb-txt">Timeout in <strong id="countdown">40</strong>s — do not close this page</p>

    <div style="margin-top:18px;background:var(--gold-l);border:1px solid rgba(254,196,17,.4);border-radius:10px;padding:12px 15px;font-size:13px;color:#7a6008;">
        <i class="fas fa-info-circle" style="color:var(--gold-d);margin-right:5px;"></i>
        No prompt? Check your notification tray or wait a few seconds.
    </div>
</div>
</div>
<script>
const TOT=40; let secs=TOT;
const cdEl=document.getElementById('countdown'), cb=document.getElementById('cbar');
const tmr=setInterval(()=>{
    secs--; cdEl.textContent=secs;
    cb.style.width=(secs/TOT*100)+'%';
    if(secs<=10) cb.style.background='var(--crimson)';
    if(secs<=0){ clearInterval(tmr);clearInterval(pol);
        document.getElementById('sl2').textContent='Timed out — reloading…';
        setTimeout(()=>location='make_order.php?id=<?= $preorder_id ?>',2000); }
},1000);
function setDone(si,sl,t){ document.getElementById(si).parentElement.className='mps-row mdon'; document.getElementById(si).className='mico mico-d'; document.getElementById(si).innerHTML='<i class="fas fa-check"></i>'; const l=document.getElementById(sl); l.className='mlbl mld'; l.textContent=t; }
function setAct(si,sl,t){ document.getElementById(si).parentElement.className='mps-row mact'; document.getElementById(si).className='mico mico-a'; document.getElementById(si).innerHTML='<i class="fas fa-sync-alt"></i>'; const l=document.getElementById(sl); l.className='mlbl mla'; l.textContent=t; }
function chk(){
    fetch('/check_order_payment.php').then(r=>r.json()).then(d=>{
        if(d.success){ clearInterval(pol);clearInterval(tmr);
            setDone('si2','sl2','Payment confirmed!');
            setAct('si3','sl3','Confirming your order…');
            cb.style.width='100%'; cb.style.background='var(--green)';
            setTimeout(()=>location.reload(),2400); }
    }).catch(()=>{});
}
const pol=setInterval(chk,4000); chk();
</script>

<?php elseif ($failed): ?>
<!-- ════════ FAILED ════════ -->
<div class="sp">
<div class="sc">
    <div class="si si-no"><i class="fas fa-times"></i></div>
    <h1 class="stit">Payment Failed</h1>
    <p class="ssub"><?= htmlspecialchars($error) ?></p>
    <?php if ($payment_fail_reason): ?>
    <div class="ea" style="text-align:left;margin:18px 0;">
        <i class="fas fa-exclamation-circle"></i>
        <p><strong>Reason:</strong> <?= htmlspecialchars($payment_fail_reason) ?></p>
    </div>
    <?php else: ?>
    <p style="font-size:13px;color:var(--g400);margin:16px 0;">The prompt may have been cancelled, timed out, or you had insufficient balance.</p>
    <?php endif; ?>
    <a href="make_order.php?id=<?= $preorder_id ?>" class="sbtn sb-cr"><i class="fas fa-redo"></i> Try Again</a>
</div>
</div>

<?php elseif ($is_closed): ?>
<!-- ════════ CLOSED ════════ -->
<div class="sp">
<div class="sc">
    <div class="si si-cl"><i class="fas fa-box-open"></i></div>
    <h1 class="stit"><?= $product['status'] === 'fulfilled' ? 'Fulfilled' : 'Orders Closed' ?></h1>
    <p class="ssub"><?= $deadline_passed ? 'The pre-order window for this product has closed.' : 'This product is no longer accepting new orders.' ?></p>
    <a href="/" class="sbtn sb-nv"><i class="fas fa-arrow-left"></i> Back to Home</a>
</div>
</div>

<?php else: ?>
<!-- ════════ MAIN PAGE ════════ -->
<div class="mw">
<div class="grid2">

    <!-- ── LEFT: PRODUCT INFO ── -->
    <div>
        <div class="prod-img">
            <?php if ($cover_src): ?>
                <img src="<?= htmlspecialchars($cover_src) ?>"
                     alt="<?= htmlspecialchars($product['title']) ?>"
                     onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
                <div class="prod-img-placeholder" style="display:none;">
                    <i class="fas <?= $cat_icon ?>"></i>
                </div>
            <?php else: ?>
                <div class="prod-img-placeholder"><i class="fas <?= $cat_icon ?>"></i></div>
            <?php endif; ?>
            <div class="prod-img-g"></div>
            <div class="prod-img-bot">
                <div class="gold-bar"></div>
                <h1><?= htmlspecialchars($product['title']) ?></h1>
                <?php if ($product['subtitle']): ?>
                    <p><?= htmlspecialchars($product['subtitle']) ?></p>
                <?php endif; ?>
            </div>
        </div>

        <div class="pills">
            <?php if ($product['author_artist']): ?>
                <div class="pill"><i class="fas fa-user-pen"></i><?= htmlspecialchars($product['author_artist']) ?></div>
            <?php endif; ?>
            <?php if ($product['launch_date']): ?>
                <div class="pill"><i class="fas fa-rocket"></i>Ships <?= date('d M Y', strtotime($product['launch_date'])) ?></div>
            <?php endif; ?>
            <div class="pill">
                <i class="fas <?= $product['delivery_type'] === 'digital' ? 'fa-download' : ($product['delivery_type'] === 'both' ? 'fa-globe' : 'fa-truck') ?>"></i>
                <?= ucfirst($product['delivery_type']) ?> Delivery
            </div>
            <?php if ($stats['orders'] > 0): ?>
                <div class="pill"><i class="fas fa-users"></i><?= number_format($stats['orders']) ?> backers</div>
            <?php endif; ?>
        </div>

        <!-- Creator -->
        <div class="ic">
            <div class="ic-ttl">Created By</div>
            <div class="hrow">
                <div class="hav">
                    <?php
                    $pic = !empty($product['creator_pic']) ? ltrim($product['creator_pic'],'./\\') : '';
                    if ($pic): ?>
                        <img src="<?= htmlspecialchars($pic) ?>" alt=""
                             onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
                        <span style="display:none;"><?= strtoupper(mb_substr($product['creator_name']??'C',0,1)) ?></span>
                    <?php else: ?>
                        <?= strtoupper(mb_substr($product['creator_name']??'C',0,1)) ?>
                    <?php endif; ?>
                </div>
                <div>
                    <div class="hn"><?= htmlspecialchars($product['creator_name'] ?? 'Creator') ?></div>
                    <div class="ht">Creator · Keychele</div>
                </div>
            </div>
        </div>

        <!-- Description -->
        <?php if ($product['description']): ?>
        <div class="ic">
            <div class="ic-ttl">About This Product</div>
            <p class="desc"><?= nl2br(htmlspecialchars($product['description'])) ?></p>
        </div>
        <?php endif; ?>

        <!-- Perks -->
        <?php $perks = json_decode($product['perks'] ?? '[]', true) ?: []; ?>
        <?php if (!empty($perks)): ?>
        <div class="ic">
            <div class="ic-ttl">What You Get</div>
            <div class="perks-grid">
                <?php foreach ($perks as $p): ?>
                    <div class="perk-chip"><i class="fas fa-check"></i><?= htmlspecialchars($p) ?></div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Progress -->
        <?php
        $goal_u = (int)($product['goal_units'] ?? 0);
        $sold_u = (int)($stats['units'] ?? 0);
        if ($goal_u > 0):
            $pct = min(100, round($sold_u / $goal_u * 100));
        ?>
        <div class="ic">
            <div class="ic-ttl">Funding Progress</div>
            <div class="prog-area">
                <div class="prog-lbl">
                    <span><?= number_format($sold_u) ?> of <?= number_format($goal_u) ?> units</span>
                    <span class="prog-pct"><?= $pct ?>% funded</span>
                </div>
                <div class="prog-track"><div class="prog-fill" style="width:<?= $pct ?>%"></div></div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- ── RIGHT: ORDER PANEL ── -->
    <div class="pp">
        <div class="pp-hd">
            <p class="pp-eye">Secure Order</p>
            <h2>Order <em><?= htmlspecialchars(mb_strimwidth($product['title'],0,40,'…')) ?></em></h2>
            <p>Select a tier, fill in your details and pay via M-Pesa</p>
        </div>

        <div class="pp-body">
            <div class="nb-box">
                <i class="fas fa-shield-alt"></i>
                <p><strong>Secure payments via M-Pesa</strong>Your payment is processed instantly. Your order is confirmed as soon as payment clears.</p>
            </div>

            <form method="POST" id="orderForm">
                <!-- TIER SELECTION -->
                <?php if (!empty($tiers)): ?>
                <div class="fdiv">Choose Your Tier</div>
                <div class="tier-list" id="tierList">
                    <?php foreach ($tiers as $i => $tier):
                        $tperks = json_decode($tier['perks'] ?? '[]', true) ?: [];
                    ?>
                    <div class="tc <?= $i === 0 ? 'sel' : '' ?>"
                         data-id="<?= $tier['id'] ?>"
                         data-price="<?= $tier['price'] ?>"
                         data-name="<?= htmlspecialchars($tier['name']) ?>"
                         onclick="selTier(this)">
                        <div class="tc-top">
                            <div>
                                <div class="tc-name"><?= htmlspecialchars($tier['name']) ?></div>
                                <div class="tc-price"><?= htmlspecialchars($product['currency']) ?> <?= number_format((float)$tier['price']) ?></div>
                                <?php if ($tier['description']): ?>
                                    <div class="tc-desc"><?= htmlspecialchars($tier['description']) ?></div>
                                <?php endif; ?>
                            </div>
                            <div>
                                <?php if ($i === 0): ?>
                                    <span class="bdg bdg-av">Standard</span>
                                <?php else: ?>
                                    <span class="bdg bdg-pop">Popular</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php if (!empty($tperks)): ?>
                        <div style="display:flex;flex-wrap:wrap;gap:5px;margin-top:9px;">
                            <?php foreach ($tperks as $tp): ?>
                                <span style="background:#f0fdf4;color:#166534;border:1px solid #bbf7d0;font-size:11px;font-weight:600;padding:2px 8px;border-radius:999px;display:inline-flex;align-items:center;gap:4px;">
                                    <i class="fas fa-check" style="font-size:9px;"></i> <?= htmlspecialchars($tp) ?>
                                </span>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
                <input type="hidden" name="tier_id" id="selTierId" value="<?= $tiers[0]['id'] ?? '' ?>">
                <?php else: ?>
                <input type="hidden" name="tier_id" value="">
                <?php endif; ?>

                <!-- QUANTITY -->
                <div class="qty-row" style="margin-bottom:18px;">
                    <span class="qty-lbl">Quantity</span>
                    <div class="qty-wr">
                        <button type="button" class="qb" id="qMinus" onclick="adjQty(-1)">−</button>
                        <input type="number" class="qn" id="qtyInput" name="quantity" value="1" min="1" max="<?= $product['max_qty'] ?? 10 ?>" readonly/>
                        <button type="button" class="qb" id="qPlus" onclick="adjQty(1)">+</button>
                    </div>
                </div>

                <!-- TOTAL -->
                <div class="tot-row">
                    <span class="tot-lbl">Order Total</span>
                    <span class="tot-amt" id="totAmt">
                        <?= htmlspecialchars($product['currency']) ?>
                        <?php if (!empty($tiers)): ?>
                            <?= number_format((float)$tiers[0]['price']) ?>
                        <?php else: ?>
                            <?= number_format((float)$product['price']) ?>
                        <?php endif; ?>
                    </span>
                </div>

                <!-- BUYER DETAILS -->
                <div class="fdiv">Your Details</div>
                <div class="fg">
                    <label class="fl" for="fn">Full Name</label>
                    <input type="text" id="fn" name="name" class="fi" placeholder="Jane Mwangi" required autocomplete="name"/>
                </div>
                <div class="fg">
                    <label class="fl" for="fe">Email Address</label>
                    <input type="email" id="fe" name="email" class="fi" placeholder="jane@example.com" required autocomplete="email"/>
                    <p class="fi-hint"><i class="fas fa-info-circle"></i> Order confirmation will be sent here</p>
                </div>
                <div class="fg">
                    <label class="fl" for="fp">M-Pesa Phone</label>
                    <input type="tel" id="fp" name="phone" class="fi" placeholder="0712 345 678" required autocomplete="tel"/>
                    <p class="fi-hint">Kenyan numbers only (07XX or 01XX)</p>
                </div>

                <!-- DELIVERY ADDRESS (physical only) -->
                <?php if ($is_physical): ?>
                <div class="addr-section show" id="addrSection">
                    <div class="addr-card">
                        <div class="addr-ttl"><i class="fas fa-map-marker-alt"></i> Delivery Address</div>
                        <div class="fg">
                            <label class="fl" for="aline">Street / Estate / Building</label>
                            <input type="text" id="aline" name="addr_line1" class="fi"
                                   placeholder="e.g. Westlands, Tom Mboya St" required/>
                        </div>
                        <div class="frow">
                            <div class="fg">
                                <label class="fl" for="acity">Town / City</label>
                                <input type="text" id="acity" name="addr_city" class="fi" placeholder="Nairobi" required/>
                            </div>
                            <div class="fg">
                                <label class="fl" for="acounty">County</label>
                                <input type="text" id="acounty" name="addr_county" class="fi" placeholder="Nairobi County"/>
                            </div>
                        </div>
                        <div class="fg" style="margin-bottom:0;">
                            <label class="fl" for="anotes">Delivery Notes <span style="color:var(--g400);text-transform:none;letter-spacing:0;">(optional)</span></label>
                            <input type="text" id="anotes" name="addr_notes" class="fi"
                                   placeholder="e.g. Near Total petrol station, call on arrival"/>
                        </div>
                    </div>
                    <?php if ($product['shipping_note']): ?>
                    <div style="background:var(--navy-xl);border-left:4px solid var(--navy);border-radius:8px;padding:11px 14px;margin-bottom:14px;font-size:12px;color:var(--navy);line-height:1.6;">
                        <i class="fas fa-truck" style="color:var(--crimson);margin-right:6px;"></i>
                        <strong>Shipping note:</strong> <?= htmlspecialchars($product['shipping_note']) ?>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <!-- SUBMIT -->
                <button type="submit" class="buybtn" id="buyBtn">
                    <i class="fas fa-lock" style="font-size:13px;"></i>
                    <span id="btnTxt">Pay via M-Pesa</span>
                </button>
                <p class="sec-note">
                    <i class="fas fa-shield-alt" style="color:#16a34a;"></i>
                    Secured by <span class="mpill">M-PESA</span>
                </p>

                <?php if ($error): ?>
                <div class="ea"><i class="fas fa-exclamation-circle"></i><p><?= htmlspecialchars($error) ?></p></div>
                <?php endif; ?>
            </form>
        </div>
    </div><!-- /pp -->

</div><!-- /grid2 -->
</div><!-- /mw -->

<script>
// ── Tier selection ──
let selPrice = <?= !empty($tiers) ? (float)$tiers[0]['price'] : (float)$product['price'] ?>;
const currency = '<?= htmlspecialchars($product['currency']) ?>';

function selTier(el) {
    document.querySelectorAll('.tc').forEach(c => c.classList.remove('sel'));
    el.classList.add('sel');
    selPrice = parseFloat(el.dataset.price) || 0;
    document.getElementById('selTierId').value = el.dataset.id;
    updTotal();
}

function adjQty(d) {
    const inp = document.getElementById('qtyInput');
    let v = parseInt(inp.value) + d;
    v = Math.max(parseInt(inp.min), Math.min(v, parseInt(inp.max)));
    inp.value = v;
    document.getElementById('qMinus').disabled = (v <= 1);
    updTotal();
}

function updTotal() {
    const qty = parseInt(document.getElementById('qtyInput').value) || 1;
    const tot = selPrice * qty;
    document.getElementById('totAmt').textContent = currency + ' ' + tot.toLocaleString();
    document.getElementById('btnTxt').textContent =
        qty > 1 ? `Pay ${currency} ${tot.toLocaleString()} via M-Pesa` : 'Pay via M-Pesa';
}

// ── Form submit guard ──
document.getElementById('orderForm').addEventListener('submit', function(e) {
    const btn = document.getElementById('buyBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> <span>Processing…</span>';
});

// init
updTotal();
document.getElementById('qMinus').disabled = true;
</script>
<?php endif; ?>
</body>
</html>
