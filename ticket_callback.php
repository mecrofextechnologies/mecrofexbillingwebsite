<?php
include 'msanii/includes/connect.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require 'vendor/autoload.php';
require_once 'vendor/fpdf.php';

// ─────────────────────────────────────────────────────────────
// ENSURE referral_earnings TABLE EXISTS
// ─────────────────────────────────────────────────────────────
$pdo->exec("
    CREATE TABLE IF NOT EXISTS referral_earnings (
        id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        referrer_id        INT UNSIGNED NOT NULL COMMENT 'Creator who referred',
        referred_id        INT UNSIGNED NOT NULL COMMENT 'Creator who was referred',
        ticket_purchase_id INT UNSIGNED NULL COMMENT 'ticket_purchases.id that triggered this earning',
        amount             DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        status             ENUM('pending','paid') NOT NULL DEFAULT 'pending',
        created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_referrer (referrer_id),
        INDEX idx_referred (referred_id),
        INDEX idx_status   (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// ─────────────────────────────────────────────────────────────
// QR CODE HELPER  (paid tickets only)
// ─────────────────────────────────────────────────────────────
function generateQRCode($ticket_number, $buyer_name, $event_title, $quantity, $amount_paid) {
    $data = json_encode([
        'ticket'   => $ticket_number,
        'buyer'    => $buyer_name,
        'event'    => $event_title,
        'qty'      => (int)$quantity,
        'paid'     => 'KES ' . number_format($amount_paid, 2),
        'verified' => false,
    ]);
    $url = 'https://api.qrserver.com/v1/create-qr-code/?size=280x280&ecc=H&data=' . urlencode($data);
    $ctx = stream_context_create(['http' => ['timeout' => 10]]);
    $img = @file_get_contents($url, false, $ctx);
    if (!$img) return false;
    $tmp = sys_get_temp_dir() . '/qr_' . $ticket_number . '.png';
    file_put_contents($tmp, $img);
    return $tmp;
}

// ─────────────────────────────────────────────────────────────
// PDF TICKET CLASS
// Brand: Navy #052e64 · Gold #fec411 · Crimson #bf1339
// ─────────────────────────────────────────────────────────────
class TicketPDF extends FPDF {
    private $is_free   = false;
    private $type_name = '';

    function setMeta($is_free, $type_name = '') {
        $this->is_free   = $is_free;
        $this->type_name = $type_name;
    }

    function Header() {
        $this->SetFillColor(5, 46, 100);
        $this->Rect(0, 0, 210, 54, 'F');
        $this->SetFillColor(254, 196, 17);
        $this->Rect(0, 51, 210, 3, 'F');

        $logoPath = __DIR__ . '/images/logo/KECHELE LOGO.png';
        if (file_exists($logoPath)) $this->Image($logoPath, 8, 6, 30);
        if (file_exists($logoPath)) $this->Image($logoPath, 172, 6, 30);

        $this->SetFont('Arial', 'B', 28);
        $this->SetTextColor(255, 255, 255);
        $this->SetXY(0, 9);
        $this->Cell(0, 13, 'KEYCHELE', 0, 1, 'C');

        $this->SetFont('Arial', '', 9);
        $this->SetTextColor(254, 196, 17);
        $this->Cell(0, 7, 'O F F I C I A L   E V E N T   T I C K E T', 0, 1, 'C');

        if ($this->is_free) {
            $this->SetFont('Arial', 'B', 8);
            $this->SetFillColor(191, 19, 57);
            $this->SetTextColor(255, 255, 255);
            $this->SetXY(80, 35);
            $this->Cell(50, 9, '  FREE ADMISSION  ', 0, 1, 'C', true);
        }

        $this->Ln(8);
    }

    function Footer() {
        $this->SetFillColor(254, 196, 17);
        $this->Rect(0, 278, 210, 1.5, 'F');
        $this->SetFillColor(5, 46, 100);
        $this->Rect(0, 279.5, 210, 18, 'F');
        $this->SetY(-13);
        $this->SetFont('Arial', 'I', 8);
        $this->SetTextColor(160, 185, 225);
        $this->Cell(0, 7,
            'Present this ticket at the entrance  |  keychele.co.ke  |  Powered by Mecrofex Technologies',
            0, 0, 'C');
    }

    function sectionBar($label) {
        $this->Ln(3);
        $this->SetX(15);
        $this->SetFillColor(5, 46, 100);
        $this->SetTextColor(255, 255, 255);
        $this->SetFont('Arial', 'B', 8);
        $this->Cell(180, 7, '  ' . strtoupper($label), 0, 1, 'L', true);
        $this->Ln(4);
    }

    function thinLine() {
        $this->Ln(3);
        $this->SetDrawColor(225, 230, 245);
        $this->SetLineWidth(0.3);
        $this->Line(15, $this->GetY(), 195, $this->GetY());
        $this->Ln(4);
    }

    function row($label, $value, $highlight = false) {
        $this->SetX(15);
        $this->SetFont('Arial', '', 8);
        $this->SetTextColor(140, 155, 180);
        $this->Cell(52, 6, strtoupper($label), 0, 0);
        $this->SetFont('Arial', 'B', 10);
        if ($highlight) {
            $this->SetTextColor(191, 19, 57);
        } else {
            $this->SetTextColor(5, 46, 100);
        }
        $this->MultiCell(138, 6, $value, 0, 'L');
        $this->Ln(1);
    }

    function AddTicketContent($event, $purchase, $qr_file = null) {
        $is_free = ($purchase['amount_paid'] == 0);

        // ── Title band ──
        $this->SetFillColor(240, 244, 252);
        $this->Rect(0, $this->GetY(), 210, 28, 'F');
        $this->Ln(5);
        $this->SetFont('Arial', 'B', 17);
        $this->SetTextColor(5, 46, 100);
        $this->MultiCell(0, 9, iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $event['title']), 0, 'C');
        $this->Ln(3);

        // ── Ticket number badge ──
        $this->SetFillColor(191, 19, 57);
        $this->SetXY(40, $this->GetY());
        $this->SetFont('Arial', 'B', 14);
        $this->SetTextColor(255, 255, 255);
        $this->Cell(130, 13, $purchase['ticket_number'], 0, 1, 'C', true);
        $this->Ln(1);
        $this->SetFont('Arial', '', 7);
        $this->SetTextColor(155, 165, 185);
        $this->Cell(0, 5, 'T I C K E T   N U M B E R', 0, 1, 'C');
        $this->Ln(6);

        $has_qr = (!$is_free && $qr_file && file_exists($qr_file));

        if ($has_qr) {
            $detail_start_y = $this->GetY();

            $this->sectionBar('Buyer Details');

            $this->SetX(15);
            $this->SetFont('Arial', '', 8); $this->SetTextColor(140, 155, 180);
            $this->Cell(52, 6, 'FULL NAME', 0, 0);
            $this->SetFont('Arial', 'B', 10); $this->SetTextColor(5, 46, 100);
            $this->Cell(68, 6, $purchase['buyer_name'], 0, 1);
            $this->Ln(1);

            $this->SetX(15);
            $this->SetFont('Arial', '', 8); $this->SetTextColor(140, 155, 180);
            $this->Cell(52, 6, 'TICKET TYPE', 0, 0);
            $this->SetFont('Arial', 'B', 10); $this->SetTextColor(5, 46, 100);
            $this->Cell(68, 6, $purchase['ticket_type_name'] ?? 'General Admission', 0, 1);
            $this->Ln(1);

            $this->SetX(15);
            $this->SetFont('Arial', '', 8); $this->SetTextColor(140, 155, 180);
            $this->Cell(52, 6, 'QUANTITY', 0, 0);
            $this->SetFont('Arial', 'B', 10); $this->SetTextColor(5, 46, 100);
            $qty_label = $purchase['quantity'] . ' ticket' . ($purchase['quantity'] > 1 ? 's' : '');
            $this->Cell(68, 6, $qty_label, 0, 1);
            $this->Ln(1);

            $this->SetX(15);
            $this->SetFont('Arial', '', 8); $this->SetTextColor(140, 155, 180);
            $this->Cell(52, 6, 'AMOUNT PAID', 0, 0);
            $this->SetFont('Arial', 'B', 10); $this->SetTextColor(191, 19, 57);
            $this->Cell(68, 6, 'KES ' . number_format($purchase['amount_paid']), 0, 1);
            $this->Ln(1);

            if (!empty($purchase['mpesa_receipt'])) {
                $this->SetX(15);
                $this->SetFont('Arial', '', 8); $this->SetTextColor(140, 155, 180);
                $this->Cell(52, 6, 'M-PESA RECEIPT', 0, 0);
                $this->SetFont('Arial', 'B', 10); $this->SetTextColor(5, 46, 100);
                $this->Cell(68, 6, $purchase['mpesa_receipt'], 0, 1);
                $this->Ln(1);
            }

            $detail_end_y = $this->GetY();

            $qr_x = 138; $qr_y = $detail_start_y + 6; $qr_w = 56;
            $this->SetFillColor(245, 248, 255);
            $this->SetDrawColor(5, 46, 100);
            $this->SetLineWidth(0.5);
            $this->Rect($qr_x - 2, $qr_y - 2, $qr_w + 4, $qr_w + 14, 'DF');
            $this->Image($qr_file, $qr_x, $qr_y, $qr_w, $qr_w);
            $this->SetXY($qr_x - 2, $qr_y + $qr_w + 2);
            $this->SetFont('Arial', 'B', 7); $this->SetTextColor(5, 46, 100);
            $this->Cell($qr_w + 4, 5, 'SCAN TO VERIFY', 0, 1, 'C');
            $this->SetY(max($detail_end_y, $qr_y + $qr_w + 14) + 4);

        } else {
            $this->sectionBar('Buyer Details');
            $this->row('Full Name',   $purchase['buyer_name']);
            $this->row('Ticket Type', $purchase['ticket_type_name'] ?? 'General Admission');
            $this->row('Quantity',    $purchase['quantity'] . ' ticket' . ($purchase['quantity'] > 1 ? 's' : ''));
            $this->row('Amount Paid', 'FREE ADMISSION', true);
            $this->Ln(4);
        }

        // ── Event details ──
        $this->sectionBar('Event Details');
        $this->row('Event', $event['title']);
        $this->row('Date',  date('l, F j, Y', strtotime($event['start_date'])));
        $this->row('Time',  date('g:i A',      strtotime($event['start_time'])));
        $this->row('Venue', $event['venue'] ?? 'Location TBA');
        $this->Ln(6);

        // ── Decorative barcode ──
        $this->thinLine();
        $this->SetFont('Courier', 'B', 16);
        $this->SetTextColor(5, 46, 100);
        $this->Cell(0, 10, '| | | |   ' . $purchase['ticket_number'] . '   | | | |', 0, 1, 'C');
        $this->Ln(1);
        $this->SetFont('Arial', 'I', 7);
        $this->SetTextColor(170, 178, 200);
        $this->Cell(0, 5, 'Present this PDF (printed or on your phone) at the event entrance', 0, 1, 'C');
        $this->Ln(6);

        // ── Terms ──
        $this->SetFillColor(247, 249, 253);
        $this->SetX(15);
        $this->SetFont('Arial', 'I', 7);
        $this->SetTextColor(145, 155, 180);
        $this->MultiCell(180, 4.5,
            'This ticket is non-transferable and non-refundable. Keychele and the event organizer reserve the right to refuse entry. One entry per ticket number.',
            1, 'C', true);
    }
}

// ─────────────────────────────────────────────────────────────
// LOGGING
// ─────────────────────────────────────────────────────────────
$logFile = __DIR__ . '/ticket_callback.log';
$log = function($msg) use ($logFile) {
    file_put_contents($logFile, date('Y-m-d H:i:s') . " - $msg\n", FILE_APPEND | LOCK_EX);
};

session_start();

$log("Callback received from Safaricom at " . date('Y-m-d H:i:s'));

$rawInput = file_get_contents('php://input');
$log("Raw input length: " . strlen($rawInput) . " bytes");

$callback = json_decode($rawInput, true);

if (!$callback || !isset($callback['Body']['stkCallback'])) {
    $log("Invalid callback format - missing Body.stkCallback");
    http_response_code(400);
    exit;
}

$stk        = $callback['Body']['stkCallback'];
$checkoutID = $stk['CheckoutRequestID'] ?? '';
$resultCode = $stk['ResultCode'] ?? 1;
$resultDesc = $stk['ResultDesc'] ?? 'No description provided by Safaricom';

$log("Processing CheckoutRequestID: $checkoutID | ResultCode: $resultCode | Desc: $resultDesc");

$status  = ($resultCode == 0) ? 'completed' : 'failed';
$receipt = '';
$amount  = 0;

if ($resultCode == 0 && isset($stk['CallbackMetadata']['Item'])) {
    foreach ($stk['CallbackMetadata']['Item'] as $item) {
        if ($item['Name'] === 'MpesaReceiptNumber') $receipt = $item['Value'] ?? '';
        if ($item['Name'] === 'Amount')             $amount  = (float)($item['Value'] ?? 0);
    }
    $log("Success - Receipt: $receipt | Amount: $amount KES");
}

// ─────────────────────────────────────────────────────────────
// 1. IDEMPOTENCY CHECK
// ─────────────────────────────────────────────────────────────
$checkStmt = $pdo->prepare("SELECT id, status, mpesa_receipt FROM ticket_purchases WHERE checkout_request_id = ?");
$checkStmt->execute([$checkoutID]);
$existing = $checkStmt->fetch(PDO::FETCH_ASSOC);

if ($existing && $existing['mpesa_receipt'] && $existing['mpesa_receipt'] !== '') {
    $log("Already fully processed (receipt exists) → skipping");
    echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
    exit;
}

// ─────────────────────────────────────────────────────────────
// 2. UPDATE STATUS + RECEIPT + FAILURE REASON
// ─────────────────────────────────────────────────────────────
try {
    $pdo->prepare("
        UPDATE ticket_purchases
        SET status = :status, mpesa_receipt = :receipt, failed_reason = :reason, updated_at = NOW()
        WHERE checkout_request_id = :checkoutID
    ")->execute([':status' => $status, ':receipt' => $receipt, ':reason' => $resultDesc, ':checkoutID' => $checkoutID]);
    $log("Status updated to: $status | Reason: $resultDesc");
} catch (Exception $e) {
    $log("Status update failed: " . $e->getMessage());
    http_response_code(500);
    exit;
}

if ($status === 'failed') {
    $_SESSION['payment_fail_reason'] = $resultDesc;
    $log("Set session failure reason: $resultDesc");
}

if ($existing && $existing['status'] === 'completed') {
    $log("Was already completed → skipping heavy operations");
    echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
    exit;
}

if ($status !== 'completed') {
    $log("Payment not successful → exiting");
    echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
    exit;
}

// ─────────────────────────────────────────────────────────────
// 3. TRANSACTION – core business logic
// ─────────────────────────────────────────────────────────────
$pdo->beginTransaction();

try {
    $purchaseStmt = $pdo->prepare("
        SELECT tp.*, t.title, t.start_date, t.start_time, t.venue, t.creator_id,
               ett.id AS ticket_type_id, ett.type_name,
               c.username, c.referred_by, mp.full_name, c.email AS creator_email
        FROM ticket_purchases tp
        JOIN tickets t ON tp.ticket_id = t.id
        LEFT JOIN event_ticket_types ett ON tp.ticket_type_id = ett.id
        LEFT JOIN creators c ON t.creator_id = c.id
        LEFT JOIN my_page mp ON c.id = mp.creator_id
        WHERE tp.checkout_request_id = ?
    ");
    $purchaseStmt->execute([$checkoutID]);
    $purchase = $purchaseStmt->fetch(PDO::FETCH_ASSOC);

    if (!$purchase) throw new Exception("No matching purchase for checkout: $checkoutID");

    $log("Purchase found - amount_paid: {$purchase['amount_paid']}, creator_id: " . ($purchase['creator_id'] ?? 'NULL') . ", referred_by: " . ($purchase['referred_by'] ?? 'NULL'));

    // Ensure ticket number
    $ticket_number = $purchase['ticket_number'] ?? '';
    if (empty($ticket_number)) {
        $ticket_number = strtoupper('TKT-' . date('ymd') . '-' . substr(md5(uniqid()), 0, 6));
        $pdo->prepare("UPDATE ticket_purchases SET ticket_number = ? WHERE checkout_request_id = ?")
            ->execute([$ticket_number, $checkoutID]);
        $log("Generated ticket number: $ticket_number");
    }

    // Decrement slots
    $qty = (int)($purchase['quantity'] ?? 1);
    $stmt = $pdo->prepare("
        UPDATE event_ticket_types
        SET quantity_available = quantity_available - :qty
        WHERE id = :type_id AND quantity_available >= :qty
    ");
    $stmt->execute([':qty' => $qty, ':type_id' => $purchase['ticket_type_id']]);
    $log("Slots decremented: qty=$qty, type_id={$purchase['ticket_type_id']}, affected={$stmt->rowCount()}");

    // ─────────────────────────────────────────────────────
    // BALANCE DISTRIBUTION
    // • Creator was referred → 96.5% creator | 1% referrer | 2.5% platform
    // • Creator not referred → 96.5% creator | 3.5% platform
    // ─────────────────────────────────────────────────────
    if ($purchase['amount_paid'] > 0 && !empty($purchase['creator_id'])) {

        $creatorId  = (int)$purchase['creator_id'];
        $amountPaid = (float)$purchase['amount_paid'];
        $referredBy = isset($purchase['referred_by']) ? (int)$purchase['referred_by'] : 0;
        $purchaseId = $existing['id'] ?? null;

        // Ensure creator has a balance row
        $pdo->prepare("INSERT IGNORE INTO my_balance (creator_id, amount, date) VALUES (?, 0.00, NOW())")
            ->execute([$creatorId]);

        if ($referredBy > 0) {
            // ── Referred creator split ──
            $platformFee   = round($amountPaid * 0.025, 2); // 2.5%
            $referralFee   = round($amountPaid * 0.010, 2); // 1%
            $creatorAmount = round($amountPaid - $platformFee - $referralFee, 2); // 96.5%

            $log("Distribution (referred) → total: $amountPaid | creator: $creatorAmount (96.5%) | referral: $referralFee (1%) to creator_id $referredBy | platform: $platformFee (2.5%)");

            // Creator balance
            $pdo->prepare("UPDATE my_balance SET amount = amount + ? WHERE creator_id = ?")
                ->execute([$creatorAmount, $creatorId]);

            // Referrer balance
            $pdo->prepare("INSERT IGNORE INTO my_balance (creator_id, amount, date) VALUES (?, 0.00, NOW())")
                ->execute([$referredBy]);
            $pdo->prepare("UPDATE my_balance SET amount = amount + ? WHERE creator_id = ?")
                ->execute([$referralFee, $referredBy]);

            // Log referral earning
            $pdo->prepare("
                INSERT INTO referral_earnings
                    (referrer_id, referred_id, ticket_purchase_id, amount, status, created_at)
                VALUES (?, ?, ?, ?, 'pending', NOW())
            ")->execute([$referredBy, $creatorId, $purchaseId, $referralFee]);

            // Platform balance (2.5%)
            $pdo->prepare("
                INSERT INTO keychele_balance (id, amount, last_updated) VALUES (1, :fee, NOW())
                ON DUPLICATE KEY UPDATE amount = amount + :fee, last_updated = NOW()
            ")->execute([':fee' => $platformFee]);

            $log("Referred split done — referrer_id: $referredBy credited KES $referralFee");

        } else {
            // ── Normal (not referred) split ──
            $platformFee   = round($amountPaid * 0.035, 2); // 3.5%
            $creatorAmount = round($amountPaid - $platformFee, 2); // 96.5%

            $log("Distribution (normal) → total: $amountPaid | creator: $creatorAmount (96.5%) | platform: $platformFee (3.5%)");

            $pdo->prepare("UPDATE my_balance SET amount = amount + ? WHERE creator_id = ?")
                ->execute([$creatorAmount, $creatorId]);

            $pdo->prepare("
                INSERT INTO keychele_balance (id, amount, last_updated) VALUES (1, :fee, NOW())
                ON DUPLICATE KEY UPDATE amount = amount + :fee, last_updated = NOW()
            ")->execute([':fee' => $platformFee]);

            $log("Normal split done");
        }

        $log("Balances updated successfully");

    } else {
        $log("Balance update SKIPPED - amount_paid=" . ($purchase['amount_paid'] ?? 'NULL') . " | creator_id=" . ($purchase['creator_id'] ?? 'NULL'));
    }

    $pdo->commit();
    $log("Transaction COMMITTED");
    unset($_SESSION['payment_fail_reason']);

} catch (Exception $e) {
    $pdo->rollBack();
    $log("CRITICAL: Rolled back - " . $e->getMessage() . "\nTrace: " . $e->getTraceAsString());
    echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
    exit;
}

// ─────────────────────────────────────────────────────────────
// 4. EMAILS + PDF  (only on success)
// ─────────────────────────────────────────────────────────────
if ($status === 'completed' && !empty($purchase['buyer_email'])) {
    try {
        $is_free = ($purchase['amount_paid'] == 0);

        // ── Generate QR code for PAID tickets only ──
        $qr_file = null;
        if (!$is_free) {
            $qr_file = generateQRCode(
                $ticket_number,
                $purchase['buyer_name'],
                $purchase['title'],
                $purchase['quantity'],
                $purchase['amount_paid']
            );
            $log($qr_file ? "QR code generated: $qr_file" : "QR code generation failed (continuing without QR)");
        }

        // ── Generate PDF ──
        $pdf = new TicketPDF();
        $pdf->setMeta($is_free, $purchase['type_name'] ?? '');
        $pdf->AddPage();
        $pdf->AddTicketContent($purchase, [
            'ticket_number'    => $ticket_number,
            'buyer_name'       => $purchase['buyer_name'],
            'quantity'         => $purchase['quantity'],
            'amount_paid'      => $purchase['amount_paid'],
            'ticket_type_name' => $purchase['type_name'] ?? 'General Admission',
            'mpesa_receipt'    => $receipt,
        ], $qr_file);
        $pdf_file = sys_get_temp_dir() . "/keychele_ticket_{$ticket_number}.pdf";
        $pdf->Output('F', $pdf_file);

        // ── Shared email helper vars ──
        $ev_title   = htmlspecialchars($purchase['title'] ?? '');
        $ev_date    = date('l, d F Y', strtotime($purchase['start_date'] ?? 'now'));
        $ev_time    = date('g:i A',    strtotime($purchase['start_time'] ?? 'now'));
        $ev_venue   = htmlspecialchars($purchase['venue'] ?? 'TBD');
        $buyer_name = htmlspecialchars($purchase['buyer_name'] ?? '');
        $organizer  = htmlspecialchars($purchase['full_name'] ?? $purchase['username'] ?? 'Organizer');
        $amount_fmt = number_format($purchase['amount_paid'], 2);

        $amount_display = $is_free
            ? '<span style="color:#16a34a;font-weight:800;font-size:19px;">FREE ADMISSION</span>'
            : '<span style="color:#052e64;font-weight:800;font-size:19px;">KES ' . $amount_fmt . '</span>';
        $free_ribbon = $is_free
            ? '<tr><td style="background:#bf1339;padding:7px;text-align:center;"><span style="color:#fff;font-size:11px;font-weight:800;letter-spacing:2px;">FREE TICKET</span></td></tr>'
            : '';
        $receipt_row = !empty($receipt)
            ? '<tr><td style="padding:12px 18px;border-right:1px solid #dde6f5;"><p style="margin:0;font-size:9px;color:#8a9ab8;letter-spacing:2px;font-weight:700;">M-PESA RECEIPT</p><p style="margin:4px 0 0;font-size:13px;color:#16a34a;font-weight:700;">' . htmlspecialchars($receipt) . '</p></td><td style="padding:12px 18px;"><p style="margin:0;font-size:9px;color:#8a9ab8;letter-spacing:2px;font-weight:700;">QTY</p><p style="margin:4px 0 0;font-size:13px;color:#052e64;font-weight:700;">' . (int)$purchase['quantity'] . '</p></td></tr>'
            : '<tr><td colspan="2" style="padding:12px 18px;"><p style="margin:0;font-size:9px;color:#8a9ab8;letter-spacing:2px;font-weight:700;">QUANTITY</p><p style="margin:4px 0 0;font-size:13px;color:#052e64;font-weight:700;">' . (int)$purchase['quantity'] . ' ticket' . ($purchase['quantity'] > 1 ? 's' : '') . '</p></td></tr>';

        // ── QR section in email (paid only) ──
        $qr_email_section = '';
        if (!$is_free && $qr_file && file_exists($qr_file)) {
            $qr_b64 = base64_encode(file_get_contents($qr_file));
            $qr_email_section = "
  <tr><td style='padding:0 40px 26px;'>
    <table width='100%' cellpadding='0' cellspacing='0' style='border:2px solid #dde6f5;border-radius:14px;overflow:hidden;'>
      <tr style='background:#f5f8ff;'><td style='padding:14px 20px;border-bottom:1px solid #dde6f5;text-align:center;'>
        <p style='margin:0;font-family:Montserrat,Arial,sans-serif;font-size:10px;font-weight:800;letter-spacing:2.5px;text-transform:uppercase;color:#052e64;'>Admin Check-In QR Code</p>
        <p style='margin:4px 0 0;font-size:12px;color:#8a9ab8;'>For venue staff to scan at entry</p>
      </td></tr>
      <tr><td style='padding:20px;text-align:center;background:#fff;'>
        <img src='data:image/png;base64,{$qr_b64}' width='160' height='160' alt='QR Code' style='border:8px solid #f0f4ff;border-radius:10px;display:inline-block;'/>
      </td></tr>
      <tr style='background:#052e64;'><td style='padding:10px 20px;text-align:center;'>
        <p style='margin:0;font-size:11px;color:rgba(255,255,255,0.65);font-family:Montserrat,Arial,sans-serif;'>
          <i>This QR code contains your ticket details for staff verification</i>
        </p>
      </td></tr>
    </table>
  </td></tr>";
        }

        // ── Buyer email HTML ──
        $buyer_email_html = "
<!DOCTYPE html><html><head><meta charset='UTF-8'>
<link href='https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600;700;800&display=swap' rel='stylesheet'>
</head>
<body style='margin:0;padding:0;background:#eef2fa;font-family:Montserrat,Arial,sans-serif;'>
<table width='100%' cellpadding='0' cellspacing='0' style='background:#eef2fa;padding:32px 0;'>
<tr><td align='center'>
<table width='600' cellpadding='0' cellspacing='0' style='background:#fff;border-radius:18px;overflow:hidden;box-shadow:0 10px 40px rgba(5,46,100,0.18);'>
  <tr><td style='background:#052e64;padding:0;'>
    <table width='100%' cellpadding='0' cellspacing='0'>
      <tr><td style='padding:30px 40px 18px;text-align:center;'>
        <p style='margin:0;color:#fff;font-size:32px;font-weight:800;letter-spacing:4px;'>KEYCHELE</p>
        <p style='margin:7px 0 0;color:#fec411;font-size:10px;font-weight:800;letter-spacing:3px;'>OFFICIAL EVENT TICKET</p>
      </td></tr>
      <tr><td style='background:#fec411;height:4px;'></td></tr>
    </table>
  </td></tr>
  {$free_ribbon}
  <tr><td style='background:#bf1339;padding:20px 40px;text-align:center;'>
    <p style='color:rgba(255,255,255,.65);font-size:10px;letter-spacing:3px;font-weight:700;margin:0 0 7px;'>TICKET NUMBER</p>
    <p style='color:#fff;font-size:24px;font-weight:800;font-family:Courier New,monospace;letter-spacing:5px;margin:0;'>{$ticket_number}</p>
  </td></tr>
  <tr><td style='padding:30px 40px 14px;'>
    <p style='font-size:18px;color:#052e64;font-weight:800;margin:0 0 8px;'>Hello, {$buyer_name}! 🎉</p>
    <p style='font-size:14px;color:#4a5578;line-height:1.75;margin:0;'>
      Your ticket for <strong style='color:#052e64;'>{$ev_title}</strong> is confirmed.<br>
      Your PDF ticket is attached — please download and present it at the entrance.
    </p>
  </td></tr>
  <tr><td style='padding:10px 40px 22px;'>
    <table width='100%' cellpadding='0' cellspacing='0' style='border:1.5px solid #dde6f5;border-radius:12px;overflow:hidden;'>
      <tr style='background:#f5f8ff;'><td colspan='2' style='padding:14px 18px;border-bottom:1px solid #dde6f5;'>
        <p style='margin:0;font-size:9px;color:#8a9ab8;letter-spacing:2px;font-weight:700;'>EVENT</p>
        <p style='margin:5px 0 0;font-size:15px;color:#052e64;font-weight:800;'>{$ev_title}</p>
        <p style='margin:3px 0 0;font-size:12px;color:#8a9ab8;'>by {$organizer}</p>
      </td></tr>
      <tr>
        <td style='padding:12px 18px;border-bottom:1px solid #dde6f5;border-right:1px solid #dde6f5;width:50%;'>
          <p style='margin:0;font-size:9px;color:#8a9ab8;letter-spacing:2px;font-weight:700;'>DATE</p>
          <p style='margin:4px 0 0;font-size:13px;color:#052e64;font-weight:700;'>{$ev_date}</p>
        </td>
        <td style='padding:12px 18px;border-bottom:1px solid #dde6f5;'>
          <p style='margin:0;font-size:9px;color:#8a9ab8;letter-spacing:2px;font-weight:700;'>TIME</p>
          <p style='margin:4px 0 0;font-size:13px;color:#052e64;font-weight:700;'>{$ev_time}</p>
        </td>
      </tr>
      {$receipt_row}
      <tr style='border-top:1px solid #dde6f5;'><td colspan='2' style='padding:12px 18px;border-bottom:1px solid #dde6f5;'>
        <p style='margin:0;font-size:9px;color:#8a9ab8;letter-spacing:2px;font-weight:700;'>TICKET TYPE</p>
        <p style='margin:4px 0 0;font-size:13px;color:#052e64;font-weight:700;'>" . htmlspecialchars($purchase['type_name'] ?? 'General Admission') . "</p>
      </td></tr>
      <tr style='background:#fffdf0;'><td colspan='2' style='padding:14px 18px;'>
        <p style='margin:0;font-size:9px;color:#8a9ab8;letter-spacing:2px;font-weight:700;'>AMOUNT PAID</p>
        <p style='margin:5px 0 0;'>{$amount_display}</p>
      </td></tr>
    </table>
  </td></tr>
  <tr><td style='padding:0 40px 22px;'>
    <div style='background:#f5f8ff;border-left:4px solid #052e64;border-radius:8px;padding:14px 18px;'>
      <p style='margin:0;font-size:9px;color:#8a9ab8;letter-spacing:2px;font-weight:700;'>VENUE</p>
      <p style='margin:5px 0 0;font-size:14px;color:#052e64;font-weight:700;'>{$ev_venue}</p>
    </div>
  </td></tr>
  {$qr_email_section}
  <tr><td style='padding:0 40px 32px;'>
    <div style='border:2px dashed #fec411;background:#fffdf0;border-radius:12px;padding:16px 20px;text-align:center;'>
      <p style='margin:0;font-size:15px;color:#bf1339;font-weight:800;'>📎 Your PDF ticket is attached to this email</p>
      <p style='margin:9px 0 0;font-size:13px;color:#666;line-height:1.6;'>
        Download it and present at the entrance — printed or on your phone.<br>
        <strong style='color:#052e64;'>We can't wait to see you there!</strong>
      </p>
    </div>
  </td></tr>
  <tr><td style='background:#052e64;padding:0;'>
    <table width='100%' cellpadding='0' cellspacing='0'>
      <tr><td style='background:#fec411;height:3px;'></td></tr>
      <tr><td style='padding:20px 40px;text-align:center;'>
        <p style='color:#7a9acc;font-size:12px;margin:0;'>
          Questions? Contact the event organizer |
          <a href='https://keychele.co.ke' style='color:#fec411;text-decoration:none;'>keychele.co.ke</a>
        </p>
        <p style='color:#4a6a99;font-size:11px;margin:8px 0 0;'>
          &copy; " . date('Y') . " Keychele &middot; Powered by Mecrofex Technologies &middot; Kenya's Premier Event Platform
        </p>
      </td></tr>
    </table>
  </td></tr>
</table>
</td></tr>
</table>
</body></html>";

        // ── Mailer setup ──
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host        = 'mail.keychele.co.ke';
        $mail->SMTPAuth    = true;
        $mail->Username    = 'tickets@keychele.co.ke';
        $mail->Password    = 'King20241064.';
        $mail->SMTPSecure  = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port        = 587;
        $mail->SMTPOptions = ['ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true]];
        $mail->setFrom('tickets@keychele.co.ke', 'Keychele Tickets');
        $mail->CharSet     = 'UTF-8';
        $mail->isHTML(true);

        // ── 1. Buyer email + PDF attachment ──
        $mail->addAddress($purchase['buyer_email'], $purchase['buyer_name'] ?? '');
        $mail->addAttachment($pdf_file);
        $mail->Subject = "Your Ticket Confirmed — " . ($purchase['title'] ?? 'Event');
        $mail->Body    = $buyer_email_html;
        $mail->AltBody = "Ticket Confirmed!\n\nEvent: {$purchase['title']}\nTicket: {$ticket_number}\nDate: {$ev_date} at {$ev_time}\nVenue: {$ev_venue}\nPaid: KES {$amount_fmt}\nReceipt: {$receipt}\n\nSee you there!\n— Keychele";
        $mail->send();
        $log("Buyer email + PDF sent to {$purchase['buyer_email']}");

        // ── 2. Creator notification ──
        if (!empty($purchase['creator_email'])) {
            $mail->clearAddresses();
            $mail->clearAttachments();
            $mail->addAddress($purchase['creator_email']);
            $mail->Subject = "New Ticket Sold — " . ($purchase['title'] ?? 'Event');
            $mail->Body    = "
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
    <p style='font-size:22px;color:#052e64;font-weight:800;margin:0 0 6px;'>🎟 New ticket sold!</p>
    <p style='font-size:14px;color:#4a5578;margin:0;line-height:1.65;'>Someone just purchased a ticket for your event. Here are the details:</p>
  </td></tr>
  <tr><td style='padding:0 36px 24px;'>
    <table width='100%' cellpadding='0' cellspacing='0' style='border:1.5px solid #dde6f5;border-radius:10px;overflow:hidden;'>
      <tr style='background:#f5f8ff;'><td colspan='2' style='padding:13px 18px;border-bottom:1px solid #dde6f5;'>
        <p style='margin:0;font-size:9px;color:#8a9ab8;letter-spacing:2px;font-weight:700;'>EVENT</p>
        <p style='margin:4px 0 0;font-size:14px;color:#052e64;font-weight:800;'>{$ev_title}</p>
      </td></tr>
      <tr>
        <td style='padding:12px 18px;border-right:1px solid #dde6f5;border-bottom:1px solid #dde6f5;width:50%;'>
          <p style='margin:0;font-size:9px;color:#8a9ab8;letter-spacing:2px;font-weight:700;'>BUYER</p>
          <p style='margin:4px 0 0;font-size:13px;color:#052e64;font-weight:700;'>{$buyer_name}</p>
          <p style='margin:2px 0 0;font-size:12px;color:#8a9ab8;'>" . htmlspecialchars($purchase['buyer_email']) . "</p>
        </td>
        <td style='padding:12px 18px;border-bottom:1px solid #dde6f5;'>
          <p style='margin:0;font-size:9px;color:#8a9ab8;letter-spacing:2px;font-weight:700;'>TICKET TYPE</p>
          <p style='margin:4px 0 0;font-size:13px;color:#052e64;font-weight:700;'>" . htmlspecialchars($purchase['type_name'] ?? 'General') . "</p>
        </td>
      </tr>
      <tr>
        <td style='padding:12px 18px;border-right:1px solid #dde6f5;'>
          <p style='margin:0;font-size:9px;color:#8a9ab8;letter-spacing:2px;font-weight:700;'>TICKET #</p>
          <p style='margin:4px 0 0;font-size:13px;color:#052e64;font-weight:700;font-family:Courier New,monospace;'>{$ticket_number}</p>
        </td>
        <td style='padding:12px 18px;'>
          <p style='margin:0;font-size:9px;color:#8a9ab8;letter-spacing:2px;font-weight:700;'>QTY</p>
          <p style='margin:4px 0 0;font-size:13px;color:#052e64;font-weight:700;'>" . (int)$purchase['quantity'] . " ticket" . ($purchase['quantity'] > 1 ? 's' : '') . "</p>
        </td>
      </tr>
      <tr style='background:#f0fdf4;'><td colspan='2' style='padding:13px 18px;border-top:1px solid #dde6f5;'>
        <p style='margin:0;font-size:9px;color:#8a9ab8;letter-spacing:2px;font-weight:700;'>AMOUNT RECEIVED</p>
        <p style='margin:5px 0 0;font-size:18px;color:#052e64;font-weight:800;'>
          " . ($is_free ? '<span style="color:#16a34a;">FREE TICKET</span>' : 'KES ' . $amount_fmt) . "
        </p>
        " . (!empty($receipt) ? "<p style='margin:3px 0 0;font-size:12px;color:#16a34a;font-weight:700;'>Receipt: {$receipt}</p>" : '') . "
      </td></tr>
    </table>
  </td></tr>
  <tr><td style='padding:0 36px 28px;'>
    <div style='background:linear-gradient(135deg,#052e64,#0a4a99);border-radius:10px;padding:16px 20px;text-align:center;'>
      <p style='margin:0;color:#fec411;font-size:14px;font-weight:800;'>Great job! Your event is gaining momentum 🚀</p>
      <p style='margin:8px 0 0;font-size:12px;color:rgba(255,255,255,.65);'>Keep sharing to sell more tickets.</p>
    </div>
  </td></tr>
  <tr><td style='background:#052e64;padding:0;'>
    <table width='100%'><tr><td style='background:#fec411;height:3px;'></td></tr>
    <tr><td style='padding:18px 36px;text-align:center;'>
      <p style='color:#7a9acc;font-size:12px;margin:0;'>
        <a href='https://keychele.co.ke' style='color:#fec411;text-decoration:none;'>keychele.co.ke</a>
        &middot; Mecrofex Technologies &middot; " . date('Y') . "
      </p>
    </td></tr></table>
  </td></tr>
</table>
</td></tr>
</table>
</body></html>";
            $mail->send();
            $log("Creator notification sent to {$purchase['creator_email']}");
        }

    } catch (Exception $e) {
        $log("Email/PDF failed: " . $e->getMessage());
        // Never fail the callback over email issues
    }

    // ── Cleanup temp files ──
    @unlink($pdf_file ?? '');
    if ($qr_file) @unlink($qr_file);
}

$log("Callback processing completed successfully");
echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);