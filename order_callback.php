<?php
// ─────────────────────────────────────────────────────────────
// order_callback.php  — M-Pesa STK callback for pre-orders
// Mirrors: ticket_callback.php exactly
// ─────────────────────────────────────────────────────────────
include 'msanii/includes/connect.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require 'vendor/autoload.php';

// ── Logging ──
$logFile = __DIR__ . '/order_callback.log';
$log = fn($msg) => file_put_contents($logFile, date('Y-m-d H:i:s')." - $msg\n", FILE_APPEND|LOCK_EX);

session_start();
$log("Callback received at ".date('Y-m-d H:i:s'));

$rawInput = file_get_contents('php://input');
$log("Input length: ".strlen($rawInput)." bytes");

$callback = json_decode($rawInput, true);
if (!$callback || !isset($callback['Body']['stkCallback'])) {
    $log("Invalid callback — missing Body.stkCallback");
    http_response_code(400); exit;
}

$stk        = $callback['Body']['stkCallback'];
$checkoutID = $stk['CheckoutRequestID'] ?? '';
$resultCode = $stk['ResultCode'] ?? 1;
$resultDesc = $stk['ResultDesc'] ?? 'No description';
$status     = ($resultCode == 0) ? 'completed' : 'failed';
$receipt    = '';
$amount     = 0;

$log("CheckoutID: $checkoutID | Code: $resultCode | Desc: $resultDesc");

if ($resultCode == 0 && isset($stk['CallbackMetadata']['Item'])) {
    foreach ($stk['CallbackMetadata']['Item'] as $item) {
        if ($item['Name'] === 'MpesaReceiptNumber') $receipt = $item['Value'] ?? '';
        if ($item['Name'] === 'Amount')             $amount  = (float)($item['Value'] ?? 0);
    }
    $log("Success — Receipt: $receipt | Amount: $amount");
}

// ── 1. IDEMPOTENCY ──
$chk = $pdo->prepare("SELECT id, payment_status, mpesa_receipt FROM preorder_purchases WHERE payment_ref = ?");
$chk->execute([$checkoutID]);
$existing = $chk->fetch(PDO::FETCH_ASSOC);

if ($existing && !empty($existing['mpesa_receipt'])) {
    $log("Already processed → skip");
    echo json_encode(['ResultCode'=>0,'ResultDesc'=>'Accepted']); exit;
}

// ── 2. UPDATE STATUS ──
try {
    $pdo->prepare("
        UPDATE preorder_purchases
        SET payment_status = :st, mpesa_receipt = :rc, failed_reason = :rs, updated_at = NOW()
        WHERE payment_ref = :cid
    ")->execute([':st'=>$status,':rc'=>$receipt,':rs'=>$resultDesc,':cid'=>$checkoutID]);
    $log("Status → $status");
} catch (Exception $e) {
    $log("Update failed: ".$e->getMessage()); http_response_code(500); exit;
}

if ($status === 'failed') {
    $_SESSION['order_fail_reason'] = $resultDesc;
    $log("Fail reason saved to session");
    echo json_encode(['ResultCode'=>0,'ResultDesc'=>'Accepted']); exit;
}
if ($existing && $existing['payment_status'] === 'completed') {
    $log("Already completed → skip heavy ops");
    echo json_encode(['ResultCode'=>0,'ResultDesc'=>'Accepted']); exit;
}

// ── 3. TRANSACTION — business logic ──
$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare("
        SELECT pp.*,
               p.title, p.launch_date, p.delivery_type, p.shipping_note,
               pt.name AS tier_name,
               p.creator_id,
               COALESCE(mp.full_name, c.username) AS creator_name,
               c.email AS creator_email
        FROM preorder_purchases pp
        JOIN preorders p    ON pp.preorder_id = p.id
        LEFT JOIN preorder_tiers pt ON pp.tier_id = pt.id
        LEFT JOIN creators c        ON c.id = p.creator_id
        LEFT JOIN my_page mp        ON mp.creator_id = p.creator_id
        WHERE pp.payment_ref = ?
    ");
    $stmt->execute([$checkoutID]);
    $purchase = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$purchase) throw new Exception("No purchase for checkout: $checkoutID");

    $log("Purchase found — order: {$purchase['order_number']} | creator_id: ".($purchase['creator_id']??'NULL'));

    // Mark paid
    $pdo->prepare("UPDATE preorder_purchases SET payment_status='completed', paid_at=NOW() WHERE payment_ref=?")
        ->execute([$checkoutID]);

    // Balance distribution (95% creator / 5% platform)
    if ($purchase['total_amount'] > 0 && !empty($purchase['creator_id'])) {
        $total_amt     = (float)$purchase['total_amount'];
        $platform_fee  = round($total_amt * 0.05, 2);
        $creator_share = round($total_amt - $platform_fee, 2);

        $log("Distribution → total: $total_amt | creator: $creator_share | platform: $platform_fee");

        $pdo->prepare("INSERT IGNORE INTO my_balance (creator_id, amount, date) VALUES (?,0.00,NOW())")
            ->execute([$purchase['creator_id']]);
        $pdo->prepare("UPDATE my_balance SET amount = amount + ? WHERE creator_id = ?")
            ->execute([$creator_share, $purchase['creator_id']]);
        $pdo->prepare("INSERT INTO keychele_balance (id, amount, last_updated) VALUES (1,:f,NOW()) ON DUPLICATE KEY UPDATE amount=amount+:f,last_updated=NOW()")
            ->execute([':f' => $platform_fee]);

        $log("Balances updated");
    } else {
        $log("Balance skip — amount: ".($purchase['total_amount']??'NULL')." creator: ".($purchase['creator_id']??'NULL'));
    }

    $pdo->commit();
    $log("Transaction COMMITTED");
    unset($_SESSION['order_fail_reason']);

} catch (Exception $e) {
    $pdo->rollBack();
    $log("ROLLBACK: ".$e->getMessage());
    echo json_encode(['ResultCode'=>0,'ResultDesc'=>'Accepted']); exit;
}

// ── 4. EMAILS ──
if (!empty($purchase['buyer_email'])) {
    try {
        $order_num     = $purchase['order_number'];
        $buyer_name    = htmlspecialchars($purchase['buyer_name'] ?? '');
        $ev_title      = htmlspecialchars($purchase['title'] ?? '');
        $tier_name_str = htmlspecialchars($purchase['tier_name'] ?? 'Standard');
        $qty           = (int)$purchase['quantity'];
        $total_fmt     = number_format((float)$purchase['total_amount'], 2);
        $currency      = htmlspecialchars($purchase['currency'] ?? 'KES');
        $launch_str    = $purchase['launch_date'] ? date('d F Y', strtotime($purchase['launch_date'])) : 'TBA';
        $delivery_type = $purchase['delivery_type'] ?? 'physical';
        $is_digital    = ($delivery_type === 'digital');

        // Parse shipping address
        $addr = json_decode($purchase['shipping_address'] ?? '{}', true) ?: [];
        $addr_html = '';
        if (!$is_digital && !empty($addr)) {
            $addr_html = htmlspecialchars($addr['line1'] ?? '');
            if (!empty($addr['city']))   $addr_html .= ', '.htmlspecialchars($addr['city']);
            if (!empty($addr['county'])) $addr_html .= ', '.htmlspecialchars($addr['county']);
        }

        $receipt_row_html = !empty($receipt)
            ? '<p style="margin:3px 0 0;font-size:12px;color:#16a34a;font-weight:700;">M-Pesa: '.htmlspecialchars($receipt).'</p>'
            : '';

        $delivery_html = $is_digital
            ? '<tr><td colspan="2" style="padding:13px 18px;border-top:1px solid #dde6f5;background:#f0fdf4;"><p style="margin:0;font-size:9px;color:#8a9ab8;letter-spacing:2px;font-weight:700;">DELIVERY</p><p style="margin:4px 0 0;font-size:13px;color:#16a34a;font-weight:700;"><i>Digital — sent to your email on launch date</i></p></td></tr>'
            : ($addr_html ? '<tr><td colspan="2" style="padding:13px 18px;border-top:1px solid #dde6f5;"><p style="margin:0;font-size:9px;color:#8a9ab8;letter-spacing:2px;font-weight:700;">DELIVERY ADDRESS</p><p style="margin:4px 0 0;font-size:13px;color:#052e64;font-weight:700;">'.$addr_html.'</p>'.(!empty($addr['notes']) ? '<p style="margin:3px 0 0;font-size:12px;color:#8a9ab8;">Note: '.htmlspecialchars($addr['notes']).'</p>' : '').'</td></tr>' : '');

        $what_next = $is_digital
            ? 'Your digital product will be delivered to this email on <strong style="color:#052e64;">'.$launch_str.'</strong>.'
            : 'The creator will process and ship your order to your delivery address. You\'ll receive a tracking update when it ships.';

        // ── Buyer email ──
        $buyer_html = "
<!DOCTYPE html><html><head><meta charset='UTF-8'>
<link href='https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600;700;800&display=swap' rel='stylesheet'>
</head>
<body style='margin:0;padding:0;background:#eef2fa;font-family:Montserrat,Arial,sans-serif;'>
<table width='100%' cellpadding='0' cellspacing='0' style='background:#eef2fa;padding:32px 0;'>
<tr><td align='center'>
<table width='600' cellpadding='0' cellspacing='0' style='background:#fff;border-radius:18px;overflow:hidden;box-shadow:0 10px 40px rgba(5,46,100,0.18);'>

  <!-- HEADER -->
  <tr><td style='background:#052e64;padding:0;'>
    <table width='100%' cellpadding='0' cellspacing='0'>
      <tr><td style='padding:30px 40px 18px;text-align:center;'>
        <p style='margin:0;color:#fff;font-size:32px;font-weight:800;letter-spacing:4px;'>KEYCHELE</p>
        <p style='margin:7px 0 0;color:#fec411;font-size:10px;font-weight:800;letter-spacing:3px;'>ORDER CONFIRMATION</p>
      </td></tr>
      <tr><td style='background:#fec411;height:4px;'></td></tr>
    </table>
  </td></tr>

  <!-- ORDER NUMBER BAND -->
  <tr><td style='background:#bf1339;padding:20px 40px;text-align:center;'>
    <p style='color:rgba(255,255,255,.65);font-size:10px;letter-spacing:3px;font-weight:700;margin:0 0 7px;'>ORDER NUMBER</p>
    <p style='color:#fff;font-size:22px;font-weight:800;font-family:Courier New,monospace;letter-spacing:5px;margin:0;'>{$order_num}</p>
  </td></tr>

  <!-- GREETING -->
  <tr><td style='padding:30px 40px 14px;'>
    <p style='font-size:18px;color:#052e64;font-weight:800;margin:0 0 8px;'>Hello, {$buyer_name}! 🎉</p>
    <p style='font-size:14px;color:#4a5578;line-height:1.75;margin:0;'>
      Your order for <strong style='color:#052e64;'>{$ev_title}</strong> has been confirmed and payment received.
    </p>
  </td></tr>

  <!-- DETAIL GRID -->
  <tr><td style='padding:10px 40px 22px;'>
    <table width='100%' cellpadding='0' cellspacing='0' style='border:1.5px solid #dde6f5;border-radius:12px;overflow:hidden;'>
      <tr style='background:#f5f8ff;'><td colspan='2' style='padding:14px 18px;border-bottom:1px solid #dde6f5;'>
        <p style='margin:0;font-size:9px;color:#8a9ab8;letter-spacing:2px;font-weight:700;'>PRODUCT</p>
        <p style='margin:5px 0 0;font-size:15px;color:#052e64;font-weight:800;'>{$ev_title}</p>
      </td></tr>
      <tr>
        <td style='padding:12px 18px;border-bottom:1px solid #dde6f5;border-right:1px solid #dde6f5;width:50%;'>
          <p style='margin:0;font-size:9px;color:#8a9ab8;letter-spacing:2px;font-weight:700;'>TIER / EDITION</p>
          <p style='margin:4px 0 0;font-size:13px;color:#052e64;font-weight:700;'>{$tier_name_str}</p>
        </td>
        <td style='padding:12px 18px;border-bottom:1px solid #dde6f5;'>
          <p style='margin:0;font-size:9px;color:#8a9ab8;letter-spacing:2px;font-weight:700;'>QUANTITY</p>
          <p style='margin:4px 0 0;font-size:13px;color:#052e64;font-weight:700;'>{$qty} unit" . ($qty > 1 ? 's' : '') . "</p>
        </td>
      </tr>
      <tr>
        <td style='padding:12px 18px;border-right:1px solid #dde6f5;border-bottom:1px solid #dde6f5;'>
          <p style='margin:0;font-size:9px;color:#8a9ab8;letter-spacing:2px;font-weight:700;'>EXPECTED LAUNCH</p>
          <p style='margin:4px 0 0;font-size:13px;color:#052e64;font-weight:700;'>{$launch_str}</p>
        </td>
        <td style='padding:12px 18px;border-bottom:1px solid #dde6f5;'>
          <p style='margin:0;font-size:9px;color:#8a9ab8;letter-spacing:2px;font-weight:700;'>DELIVERY</p>
          <p style='margin:4px 0 0;font-size:13px;color:#052e64;font-weight:700;'>". ucfirst($delivery_type) ."</p>
        </td>
      </tr>
      {$delivery_html}
      <tr style='background:#fffdf0;'><td colspan='2' style='padding:14px 18px;border-top:1px solid #dde6f5;'>
        <p style='margin:0;font-size:9px;color:#8a9ab8;letter-spacing:2px;font-weight:700;'>AMOUNT PAID</p>
        <p style='margin:5px 0 0;font-size:19px;color:#052e64;font-weight:800;'>{$currency} {$total_fmt}</p>
        {$receipt_row_html}
      </td></tr>
    </table>
  </td></tr>

  <!-- WHAT'S NEXT -->
  <tr><td style='padding:0 40px 28px;'>
    <div style='background:#f5f8ff;border-left:4px solid #052e64;border-radius:8px;padding:16px 18px;'>
      <p style='margin:0;font-size:13px;color:#8a9ab8;font-weight:700;letter-spacing:1px;text-transform:uppercase;margin-bottom:7px;'>What happens next?</p>
      <p style='margin:0;font-size:13px;color:#052e64;line-height:1.7;'>{$what_next}</p>
    </div>
  </td></tr>

  <!-- FOOTER -->
  <tr><td style='background:#052e64;padding:0;'>
    <table width='100%' cellpadding='0' cellspacing='0'>
      <tr><td style='background:#fec411;height:3px;'></td></tr>
      <tr><td style='padding:20px 40px;text-align:center;'>
        <p style='color:#7a9acc;font-size:12px;margin:0;'>
          Questions? Contact the creator |
          <a href='https://keychele.co.ke' style='color:#fec411;text-decoration:none;'>keychele.co.ke</a>
        </p>
        <p style='color:#4a6a99;font-size:11px;margin:8px 0 0;'>© " . date('Y') . " Keychele · Powered by Mecrofex Technologies</p>
      </td></tr>
    </table>
  </td></tr>

</table></td></tr></table></body></html>";

        // ── Mailer ──
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host       = 'mail.keychele.co.ke';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'tickets@keychele.co.ke';
        $mail->Password   = 'King20241064.';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;
        $mail->SMTPOptions= ['ssl'=>['verify_peer'=>false,'verify_peer_name'=>false,'allow_self_signed'=>true]];
        $mail->setFrom('tickets@keychele.co.ke', 'Keychele Orders');
        $mail->CharSet    = 'UTF-8';
        $mail->isHTML(true);

        // Buyer
        $mail->addAddress($purchase['buyer_email'], $purchase['buyer_name'] ?? '');
        $mail->Subject = "Order Confirmed — {$purchase['title']}";
        $mail->Body    = $buyer_html;
        $mail->AltBody = "Order Confirmed!\n\nProduct: {$purchase['title']}\nOrder: {$order_num}\nTier: {$tier_name_str}\nQty: {$qty}\nPaid: {$currency} {$total_fmt}\nReceipt: {$receipt}\n\nThank you!\n— Keychele";
        $mail->send();
        $log("Buyer email sent to {$purchase['buyer_email']}");

        // Creator notification
        if (!empty($purchase['creator_email'])) {
            $mail->clearAddresses();
            $mail->addAddress($purchase['creator_email']);
            $mail->Subject = "New Order — {$purchase['title']}";
            $mail->Body = "
<!DOCTYPE html><html><head><meta charset='UTF-8'>
<link href='https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600;700;800&display=swap' rel='stylesheet'>
</head>
<body style='margin:0;padding:0;background:#eef2fa;font-family:Montserrat,Arial,sans-serif;'>
<table width='100%' cellpadding='0' cellspacing='0' style='background:#eef2fa;padding:32px 0;'>
<tr><td align='center'>
<table width='560' cellpadding='0' cellspacing='0' style='background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 8px 30px rgba(5,46,100,0.15);'>
  <tr><td style='background:#052e64;padding:0;'>
    <table width='100%'><tr><td style='padding:24px 36px 16px;text-align:center;'>
      <p style='margin:0;color:#fff;font-size:26px;font-weight:800;letter-spacing:3px;'>KEYCHELE</p>
      <p style='margin:5px 0 0;color:#fec411;font-size:9px;font-weight:800;letter-spacing:3px;'>CREATOR NOTIFICATION</p>
    </td></tr>
    <tr><td style='background:#fec411;height:3px;'></td></tr></table>
  </td></tr>
  <tr><td style='padding:28px 36px 20px;'>
    <p style='font-size:22px;color:#052e64;font-weight:800;margin:0 0 6px;'>📦 New order placed!</p>
    <p style='font-size:14px;color:#4a5578;margin:0;line-height:1.65;'>Someone just ordered your product. Here are the details:</p>
  </td></tr>
  <tr><td style='padding:0 36px 24px;'>
    <table width='100%' cellpadding='0' cellspacing='0' style='border:1.5px solid #dde6f5;border-radius:10px;overflow:hidden;'>
      <tr style='background:#f5f8ff;'><td colspan='2' style='padding:13px 18px;border-bottom:1px solid #dde6f5;'>
        <p style='margin:0;font-size:9px;color:#8a9ab8;letter-spacing:2px;font-weight:700;'>PRODUCT</p>
        <p style='margin:4px 0 0;font-size:14px;color:#052e64;font-weight:800;'>{$ev_title}</p>
      </td></tr>
      <tr>
        <td style='padding:12px 18px;border-right:1px solid #dde6f5;border-bottom:1px solid #dde6f5;width:50%;'>
          <p style='margin:0;font-size:9px;color:#8a9ab8;letter-spacing:2px;font-weight:700;'>BUYER</p>
          <p style='margin:4px 0 0;font-size:13px;color:#052e64;font-weight:700;'>{$buyer_name}</p>
          <p style='margin:2px 0 0;font-size:12px;color:#8a9ab8;'>" . htmlspecialchars($purchase['buyer_email']) . "</p>
        </td>
        <td style='padding:12px 18px;border-bottom:1px solid #dde6f5;'>
          <p style='margin:0;font-size:9px;color:#8a9ab8;letter-spacing:2px;font-weight:700;'>ORDER #</p>
          <p style='margin:4px 0 0;font-size:13px;color:#052e64;font-weight:700;font-family:Courier New,monospace;'>{$order_num}</p>
        </td>
      </tr>
      <tr>
        <td style='padding:12px 18px;border-right:1px solid #dde6f5;'>
          <p style='margin:0;font-size:9px;color:#8a9ab8;letter-spacing:2px;font-weight:700;'>TIER / QTY</p>
          <p style='margin:4px 0 0;font-size:13px;color:#052e64;font-weight:700;'>{$tier_name_str} × {$qty}</p>
        </td>
        <td style='padding:12px 18px;'>
          <p style='margin:0;font-size:9px;color:#8a9ab8;letter-spacing:2px;font-weight:700;'>PHONE</p>
          <p style='margin:4px 0 0;font-size:13px;color:#052e64;font-weight:700;'>" . htmlspecialchars($purchase['buyer_phone'] ?? 'N/A') . "</p>
        </td>
      </tr>
      ".(!empty($addr_html) ? "<tr><td colspan='2' style='padding:12px 18px;border-top:1px solid #dde6f5;'><p style='margin:0;font-size:9px;color:#8a9ab8;letter-spacing:2px;font-weight:700;'>DELIVERY ADDRESS</p><p style='margin:4px 0 0;font-size:13px;color:#052e64;font-weight:700;'>{$addr_html}</p></td></tr>" : '')."
      <tr style='background:#f0fdf4;'><td colspan='2' style='padding:13px 18px;border-top:1px solid #dde6f5;'>
        <p style='margin:0;font-size:9px;color:#8a9ab8;letter-spacing:2px;font-weight:700;'>AMOUNT RECEIVED</p>
        <p style='margin:5px 0 0;font-size:18px;color:#052e64;font-weight:800;'>{$currency} {$total_fmt}</p>
        ".(!empty($receipt) ? "<p style='margin:3px 0 0;font-size:12px;color:#16a34a;font-weight:700;'>M-Pesa: {$receipt}</p>" : '')."
      </td></tr>
    </table>
  </td></tr>
  <tr><td style='padding:0 36px 28px;'>
    <div style='background:linear-gradient(135deg,#052e64,#0a4a99);border-radius:10px;padding:16px 20px;text-align:center;'>
      <p style='margin:0;color:#fec411;font-size:14px;font-weight:800;'>Your campaign is gaining momentum! 🚀</p>
      <p style='margin:8px 0 0;font-size:12px;color:rgba(255,255,255,.65);'>Log in to Keychele to manage orders and fulfilment.</p>
    </div>
  </td></tr>
  <tr><td style='background:#052e64;padding:0;'>
    <table width='100%'><tr><td style='background:#fec411;height:3px;'></td></tr>
    <tr><td style='padding:18px 36px;text-align:center;'>
      <p style='color:#7a9acc;font-size:12px;margin:0;'>
        <a href='https://keychele.co.ke' style='color:#fec411;text-decoration:none;'>keychele.co.ke</a>
        · Mecrofex Technologies · " . date('Y') . "
      </p>
    </td></tr></table>
  </td></tr>
</table></td></tr></table></body></html>";
            $mail->send();
            $log("Creator email sent to {$purchase['creator_email']}");
        }

    } catch (Exception $e) {
        $log("Email failed: ".$e->getMessage());
    }
}

$log("Callback done — order: ".($purchase['order_number']??'?'));
echo json_encode(['ResultCode'=>0,'ResultDesc'=>'Accepted']);
