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

// ─────────────────────────────────────────────────────────────
// QR CODE HELPER
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
// PDF TICKET
// ─────────────────────────────────────────────────────────────
class TicketPDF extends FPDF {
    private $is_free = false;

    function setTicketMeta($is_free, $type_name = '') {
        $this->is_free = $is_free;
    }

    function Header() {
        $this->SetFillColor(5, 46, 100);
        $this->Rect(0, 0, 210, 52, 'F');
        $this->SetFillColor(254, 196, 17);
        $this->Rect(0, 49, 210, 3, 'F');
        $this->SetFont('Arial', 'B', 30);
        $this->SetTextColor(255, 255, 255);
        $this->SetXY(0, 8);
        $this->Cell(0, 13, 'KEYCHELE', 0, 1, 'C');
        $this->SetFont('Arial', '', 9);
        $this->SetTextColor(254, 196, 17);
        $this->Cell(0, 7, 'O F F I C I A L   E V E N T   T I C K E T', 0, 1, 'C');
        if ($this->is_free) {
            $this->SetFont('Arial', 'B', 8);
            $this->SetFillColor(191, 19, 57);
            $this->SetTextColor(255, 255, 255);
            $this->SetXY(80, 34);
            $this->Cell(50, 9, '  FREE ADMISSION  ', 0, 1, 'C', true);
        }
        $this->Ln(8);
    }

    function Footer() {
        $this->SetFillColor(254, 196, 17);
        $this->Rect(0, 278, 210, 1.5, 'F');
        $this->SetFillColor(5, 46, 100);
        $this->Rect(0, 279, 210, 18, 'F');
        $this->SetY(-13);
        $this->SetFont('Arial', 'I', 8);
        $this->SetTextColor(160, 185, 225);
        $this->Cell(0, 7, 'Present this ticket at the entrance  |  keychele.co.ke  |  Powered by Mecrofex Technologies', 0, 0, 'C');
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
        $this->SetTextColor($highlight ? 191 : 5, $highlight ? 19 : 46, $highlight ? 57 : 100);
        $this->MultiCell(138, 6, $value, 0, 'L');
        $this->Ln(1);
    }

    function AddTicketContent($event, $purchase, $qr_file = null) {
        $is_free = ($purchase['amount_paid'] == 0);

        $this->SetFillColor(240, 244, 252);
        $this->Rect(0, $this->GetY(), 210, 28, 'F');
        $this->Ln(5);
        $this->SetFont('Arial', 'B', 17);
        $this->SetTextColor(5, 46, 100);
        $this->MultiCell(0, 9, iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $event['title']), 0, 'C');
        $this->Ln(3);

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
            foreach ([
                ['FULL NAME',   $purchase['buyer_name']],
                ['TICKET TYPE', $purchase['ticket_type_name'] ?? 'General'],
                ['QUANTITY',    $purchase['quantity'] . ' ticket' . ($purchase['quantity'] > 1 ? 's' : '')],
            ] as [$lbl, $val]) {
                $this->SetX(15);
                $this->SetFont('Arial', '', 8); $this->SetTextColor(140, 155, 180); $this->Cell(52, 6, $lbl, 0, 0);
                $this->SetFont('Arial', 'B', 10); $this->SetTextColor(5, 46, 100); $this->Cell(68, 6, $val, 0, 1);
                $this->Ln(1);
            }
            $this->SetX(15);
            $this->SetFont('Arial', '', 8); $this->SetTextColor(140, 155, 180); $this->Cell(52, 6, 'AMOUNT PAID', 0, 0);
            $this->SetFont('Arial', 'B', 10); $this->SetTextColor(191, 19, 57);
            $this->Cell(68, 6, 'KES ' . number_format($purchase['amount_paid']), 0, 1);
            $this->Ln(1);

            $detail_end_y = $this->GetY();
            $qr_x = 138; $qr_y = $detail_start_y + 6; $qr_w = 56;
            $this->SetFillColor(245, 248, 255); $this->SetDrawColor(5, 46, 100); $this->SetLineWidth(0.5);
            $this->Rect($qr_x - 2, $qr_y - 2, $qr_w + 4, $qr_w + 14, 'DF');
            $this->Image($qr_file, $qr_x, $qr_y, $qr_w, $qr_w);
            $this->SetXY($qr_x - 2, $qr_y + $qr_w + 2);
            $this->SetFont('Arial', 'B', 7); $this->SetTextColor(5, 46, 100);
            $this->Cell($qr_w + 4, 5, 'SCAN TO VERIFY', 0, 1, 'C');
            $this->SetY(max($detail_end_y, $qr_y + $qr_w + 14) + 4);
        } else {
            $this->sectionBar('Buyer Details');
            $this->row('Full Name',   $purchase['buyer_name']);
            $this->row('Ticket Type', $purchase['ticket_type_name'] ?? 'General');
            $this->row('Quantity',    $purchase['quantity'] . ' ticket' . ($purchase['quantity'] > 1 ? 's' : ''));
            $this->row('Amount Paid', 'FREE ADMISSION', true);
            $this->Ln(4);
        }

        $this->sectionBar('Event Details');
        $this->row('Event', $event['title']);
        $this->row('Date',  date('l, F j, Y', strtotime($event['start_date'])));
        $this->row('Time',  date('g:i A',      strtotime($event['start_time'])));
        $this->row('Venue', $event['venue'] ?? 'Location TBA');
        $this->Ln(6);

        $this->thinLine();
        $this->SetFont('Courier', 'B', 16); $this->SetTextColor(5, 46, 100);
        $this->Cell(0, 10, '| | | |   ' . $purchase['ticket_number'] . '   | | | |', 0, 1, 'C');
        $this->Ln(1);
        $this->SetFont('Arial', 'I', 7); $this->SetTextColor(170, 178, 200);
        $this->Cell(0, 5, 'Present this PDF (printed or on your phone) at the event entrance', 0, 1, 'C');
        $this->Ln(6);

        $this->SetFillColor(247, 249, 253); $this->SetX(15);
        $this->SetFont('Arial', 'I', 7); $this->SetTextColor(145, 155, 180);
        $this->MultiCell(180, 4.5,
            'This ticket is non-transferable and non-refundable. Keychele and the event organizer reserve the right to refuse entry. One entry per ticket number.',
            1, 'C', true);
    }
}

// ─────────────────────────────────────────────────────────────
// MPESA CONFIG
// ─────────────────────────────────────────────────────────────
define('MPESA_CONSUMER_KEY',    'ViMJdXYZVIgGsoWGcpEmKp1z69nq6r7atgeOnILgYoiXYUQC');
define('MPESA_CONSUMER_SECRET', '8r6MlY2svifZADA4xmV6Pe8Avg3TxNQFMMfLxqI8jl6Ms7IK1VRrU0PN92vUGvRp');
define('MPESA_SHORTCODE',       '3576741');
define('MPESA_TILL_NUMBER',     '4991672');
define('MPESA_PASSKEY',         'a9e2c0d0181d3391956cfdf16fdef60b3240186dfdd2c5f000e39b3a67801f35');
define('MPESA_CALLBACK_URL',    'https://keychele.co.ke/ticket_callback.php');

function getAccessToken() {
    $url = 'https://api.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials';
    $credentials = base64_encode(MPESA_CONSUMER_KEY . ':' . MPESA_CONSUMER_SECRET);
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_HTTPHEADER=>['Authorization: Basic '.$credentials], CURLOPT_RETURNTRANSFER=>true, CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_TIMEOUT=>30]);
    $res = curl_exec($ch); curl_close($ch);
    $data = json_decode($res, true);
    return $data['access_token'] ?? false;
}

function initiateSTKPush($phone, $amount, $reference, $ticket_type_name) {
    $url = 'https://api.safaricom.co.ke/mpesa/stkpush/v1/processrequest';
    $timestamp = date('YmdHis');
    $password  = base64_encode(MPESA_SHORTCODE . MPESA_PASSKEY . $timestamp);
    $payload = [
        "BusinessShortCode" => MPESA_SHORTCODE, "Password" => $password, "Timestamp" => $timestamp,
        "TransactionType" => "CustomerBuyGoodsOnline", "Amount" => $amount,
        "PartyA" => $phone, "PartyB" => MPESA_TILL_NUMBER, "PhoneNumber" => $phone,
        "CallBackURL" => MPESA_CALLBACK_URL, "AccountReference" => $reference,
        "TransactionDesc" => "Ticket Purchase: $ticket_type_name - $reference"
    ];
    $token = getAccessToken();
    if (!$token) return false;
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$token,'Content-Type: application/json'], CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>json_encode($payload), CURLOPT_RETURNTRANSFER=>true, CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_TIMEOUT=>60]);
    $res = curl_exec($ch); curl_close($ch);
    $result = json_decode($res, true);
    return ($result['ResponseCode'] ?? '1') === '0' ? $result['CheckoutRequestID'] ?? false : false;
}

// ─────────────────────────────────────────────────────────────
// MAIN LOGIC
// ─────────────────────────────────────────────────────────────
$event_id = (int)($_GET['id'] ?? 0);
if ($event_id <= 0) die("<h1 style='text-align:center;margin-top:5rem;color:#bf1339'>Invalid Event ID</h1>");

$stmt = $pdo->prepare("SELECT t.*, c.username, mp.full_name, c.email AS creator_email, t.deadline, c.id AS creator_id_val FROM tickets t LEFT JOIN creators c ON t.creator_id = c.id LEFT JOIN my_page mp ON c.id = mp.creator_id WHERE t.id = ?");
$stmt->execute([$event_id]);
$event = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$event) die("<h1 style='text-align:center;margin-top:5rem;color:#bf1339'>Event Not Found</h1>");
include __DIR__ . '/includes/creator_ticket_settings.php';   // 

include __DIR__ . '/track_ticketpage.php';

$creator_id_val = $event['creator_id'] ?? $event['creator_id_val'] ?? null;
$other_events = [];
if ($creator_id_val) {
    $s2 = $pdo->prepare("SELECT t.id, t.title, t.start_date, t.start_time, t.venue, t.banner, MIN(ett.price) AS min_price FROM tickets t LEFT JOIN event_ticket_types ett ON ett.event_id = t.id AND ett.is_active = 1 WHERE t.creator_id = ? AND t.id != ? GROUP BY t.id ORDER BY t.start_date ASC LIMIT 20");
    $s2->execute([$creator_id_val, $event_id]);
    $other_events = $s2->fetchAll(PDO::FETCH_ASSOC);
}

$deadline_passed = false;
$deadline_message = "Ticket sales have closed. Please contact the event organizer.";
if (!empty($event['deadline']) && strtotime($event['deadline'] . ' 23:59:59') < time()) $deadline_passed = true;

$stmt = $pdo->prepare("SELECT ett.id, ett.type_name, ett.price, ett.quantity_available, ett.quantity_sold, GREATEST(ett.quantity_available - ett.quantity_sold, 0) AS slots_left, (ett.quantity_available > 0 AND ett.quantity_sold >= ett.quantity_available) AS is_sold_out, t.creator_id FROM event_ticket_types ett JOIN tickets t ON ett.event_id = t.id WHERE ett.event_id = ? AND ett.is_active = 1 ORDER BY ett.price ASC");
$stmt->execute([$event_id]);
$ticket_types = $stmt->fetchAll(PDO::FETCH_ASSOC);

$has_any_tickets = count($ticket_types) > 0;
$all_sold_out    = $has_any_tickets && array_reduce($ticket_types, fn($c,$t) => $c && $t['is_sold_out'], true);
$any_available   = !$all_sold_out && !$deadline_passed;

$just_paid = $waiting = $failed = false;
$ticket_number_display = ''; $quantity_display = 1; $buyer_email_display = ''; $error = '';
$payment_fail_reason = $_SESSION['payment_fail_reason'] ?? '';
$banner_url = "msanii/uploads/banners/" . htmlspecialchars($event['banner'] ?: 'default-event.jpg');

if (isset($_SESSION['pending_ticket_checkout'])) {
    $checkout_id = $_SESSION['pending_ticket_checkout'];
    $stmt = $pdo->prepare("SELECT tp.*, ett.type_name FROM ticket_purchases tp LEFT JOIN event_ticket_types ett ON tp.ticket_type_id = ett.id WHERE tp.checkout_request_id = ?");
    $stmt->execute([$checkout_id]);
    $purchase = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($purchase) {
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
    if ($deadline_passed) { $error = $deadline_message; } else {
        $name      = trim($_POST['name'] ?? '');
        $email     = trim($_POST['email'] ?? '');
        $phone_raw = preg_replace('/\D/', '', $_POST['phone'] ?? '');
        $type_id   = (int)($_POST['ticket_type'] ?? 0);
        $quantity  = max(1, (int)($_POST['quantity'] ?? 1));
        $custom_amount_raw = trim($_POST['custom_amount'] ?? '');
        $custom_amount = ($custom_amount_raw !== '') ? (float)$custom_amount_raw : null;

        $phone = $phone_raw;
        if (strlen($phone_raw) === 10 && in_array(substr($phone_raw,0,2),['07','01'])) $phone = '254'.substr($phone_raw,1);
        elseif (strlen($phone_raw)===9 && in_array(substr($phone_raw,0,1),['7','1'])) $phone='254'.$phone_raw;
        elseif (strlen($phone_raw)===12 && substr($phone_raw,0,3)==='254') $phone=$phone_raw;
        elseif (strlen($phone_raw)===13 && substr($phone_raw,0,4)==='+254') $phone=substr($phone_raw,1);

        $selected_type = null;
        foreach ($ticket_types as $t) { if ($t['id'] == $type_id && $t['slots_left'] >= $quantity) { $selected_type=$t; break; } }

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

                if ($is_free) {
                    $stmt=$pdo->prepare("INSERT INTO ticket_purchases (ticket_id,ticket_type_id,buyer_name,buyer_email,buyer_phone,ticket_number,amount_paid,quantity,status,transaction_id,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,'completed',?,NOW(),NOW())");
                    $stmt->execute([$event_id,$type_id,$name,$email,$phone,$ticket_number,0,$quantity,$reference]);
                    $pdo->prepare("UPDATE event_ticket_types SET quantity_sold=quantity_sold+? WHERE id=? AND quantity_available>=quantity_sold+?")->execute([$quantity,$type_id,$quantity]);
                    sendTicketAndNotifications($pdo,$event,$ticket_number,$name,$email,$quantity,0,$selected_type['type_name'],$event['creator_email']);
                    $just_paid=true; $ticket_number_display=$ticket_number; $quantity_display=$quantity; $buyer_email_display=$email;
                } else {
                    $stmt=$pdo->prepare("INSERT INTO ticket_purchases (ticket_id,ticket_type_id,buyer_name,buyer_email,buyer_phone,ticket_number,amount_paid,quantity,checkout_request_id,status,transaction_id,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,'pending',?,NOW(),NOW())");
                    $stmt->execute([$event_id,$type_id,$name,$email,$phone,$ticket_number,$total_amount,$quantity,$reference,$reference]);
                    $checkoutID = initiateSTKPush($phone,$total_amount,$reference,$selected_type['type_name']);
                    if ($checkoutID) {
                        $pdo->prepare("UPDATE ticket_purchases SET checkout_request_id=? WHERE transaction_id=?")->execute([$checkoutID,$reference]);
                        $_SESSION['pending_ticket_checkout']=$checkoutID;
                        $_SESSION['last_quantity']=$quantity;
                        header("Location: /ticket/$event_id"); exit;
                    } else {
                        $pdo->prepare("DELETE FROM ticket_purchases WHERE transaction_id=?")->execute([$reference]);
                        $error="Failed to initiate M-Pesa payment. Please try again.";
                    }
                }
            }
        }
    }
}

// ─────────────────────────────────────────────────────────────
// EMAIL + PDF
// ─────────────────────────────────────────────────────────────
function sendTicketAndNotifications($pdo, $event, $ticket_number, $buyer_name, $buyer_email,
    $quantity, $amount_paid, $ticket_type_name, $creator_email) {

    $is_free = ($amount_paid == 0);
    $qr_file = null;
    if (!$is_free) {
        $qr_file = generateQRCode($ticket_number, $buyer_name, $event['title'], $quantity, $amount_paid);
    }

    $pdf = new TicketPDF();
    $pdf->setTicketMeta($is_free, $ticket_type_name);
    $pdf->AddPage();
    $pdf->AddTicketContent($event, [
        'ticket_number'    => $ticket_number,
        'buyer_name'       => $buyer_name,
        'quantity'         => $quantity,
        'amount_paid'      => $amount_paid,
        'ticket_type_name' => $ticket_type_name,
    ], $qr_file);
    $pdf_file = sys_get_temp_dir() . "/keychele_ticket_{$ticket_number}.pdf";
    $pdf->Output('F', $pdf_file);

    $amount_html = $is_free
        ? '<span style="color:#16a34a;font-weight:800;font-size:18px;">FREE ADMISSION</span>'
        : '<span style="color:#052e64;font-weight:800;font-size:18px;">KES '.number_format($amount_paid).'</span>';
    $free_strip = $is_free
        ? '<tr><td style="background:#bf1339;padding:6px;text-align:center;"><span style="color:#fff;font-size:11px;font-weight:800;letter-spacing:2px;">FREE TICKET</span></td></tr>'
        : '';

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

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP(); $mail->Host='mail.keychele.co.ke'; $mail->SMTPAuth=true;
        $mail->Username='tickets@keychele.co.ke'; $mail->Password='King20241064.';
        $mail->SMTPSecure=PHPMailer::ENCRYPTION_STARTTLS; $mail->Port=587;
        $mail->SMTPOptions=['ssl'=>['verify_peer'=>false,'verify_peer_name'=>false,'allow_self_signed'=>true]];
        $mail->setFrom('tickets@keychele.co.ke','Keychele Tickets');
        $mail->addAddress($buyer_email,$buyer_name);
        $mail->addAttachment($pdf_file);
        $mail->isHTML(true);
        $mail->Subject = "Your Ticket — ".htmlspecialchars($event['title']);
        $mail->Body = "
<!DOCTYPE html><html><head><meta charset='UTF-8'>
<link href='https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600;700;800&display=swap' rel='stylesheet'>
</head>
<body style='margin:0;padding:0;background:#eef2fa;font-family:Montserrat,Arial,sans-serif;'>
<table width='100%' cellpadding='0' cellspacing='0' style='background:#eef2fa;padding:32px 0;'>
<tr><td align='center'>
<table width='580' cellpadding='0' cellspacing='0' style='background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 8px 32px rgba(5,46,100,0.15);'>
  <tr><td style='background:#052e64;padding:0;'>
    <table width='100%' cellpadding='0' cellspacing='0'>
      <tr><td style='padding:30px 40px 18px;text-align:center;'>
        <p style='margin:0;color:#fff;font-size:30px;font-weight:800;letter-spacing:4px;'>KEYCHELE</p>
        <p style='margin:6px 0 0;color:#fec411;font-size:10px;font-weight:700;letter-spacing:3px;'>OFFICIAL EVENT TICKET</p>
      </td></tr>
      <tr><td style='background:#fec411;height:4px;'></td></tr>
    </table>
  </td></tr>
  {$free_strip}
  <tr><td style='background:#bf1339;padding:18px 40px;text-align:center;'>
    <p style='color:rgba(255,255,255,0.7);font-size:10px;letter-spacing:3px;font-weight:700;margin:0 0 6px;'>TICKET NUMBER</p>
    <p style='color:#fff;font-size:22px;font-weight:800;font-family:Courier New,monospace;letter-spacing:4px;margin:0;'>{$ticket_number}</p>
  </td></tr>
  <tr><td style='padding:28px 40px 14px;'>
    <p style='font-size:17px;color:#052e64;font-weight:700;margin:0 0 8px;'>Hello, {$buyer_name}!</p>
    <p style='font-size:14px;color:#4a5578;line-height:1.75;margin:0;'>Your ticket for <strong style='color:#052e64;'>".htmlspecialchars($event['title'])."</strong> is confirmed and attached as a PDF.</p>
  </td></tr>
  <tr><td style='padding:8px 40px 24px;'>
    <table width='100%' cellpadding='0' cellspacing='0' style='border:1.5px solid #dde6f5;border-radius:10px;overflow:hidden;'>
      <tr style='background:#f5f8ff;'><td colspan='2' style='padding:13px 18px;border-bottom:1px solid #dde6f5;'>
        <p style='margin:0;font-size:9px;color:#8a9ab8;letter-spacing:2px;font-weight:700;'>EVENT</p>
        <p style='margin:4px 0 0;font-size:14px;color:#052e64;font-weight:700;'>".htmlspecialchars($event['title'])."</p>
      </td></tr>
      <tr>
        <td style='padding:12px 18px;border-bottom:1px solid #dde6f5;border-right:1px solid #dde6f5;width:50%;'>
          <p style='margin:0;font-size:9px;color:#8a9ab8;letter-spacing:2px;font-weight:700;'>DATE</p>
          <p style='margin:4px 0 0;font-size:13px;color:#052e64;font-weight:700;'>".date('D, d M Y',strtotime($event['start_date']))."</p>
        </td>
        <td style='padding:12px 18px;border-bottom:1px solid #dde6f5;'>
          <p style='margin:0;font-size:9px;color:#8a9ab8;letter-spacing:2px;font-weight:700;'>TIME</p>
          <p style='margin:4px 0 0;font-size:13px;color:#052e64;font-weight:700;'>".date('g:i A',strtotime($event['start_time']))."</p>
        </td>
      </tr>
      <tr>
        <td style='padding:12px 18px;border-right:1px solid #dde6f5;'>
          <p style='margin:0;font-size:9px;color:#8a9ab8;letter-spacing:2px;font-weight:700;'>TYPE</p>
          <p style='margin:4px 0 0;font-size:13px;color:#052e64;font-weight:700;'>{$ticket_type_name}</p>
        </td>
        <td style='padding:12px 18px;'>
          <p style='margin:0;font-size:9px;color:#8a9ab8;letter-spacing:2px;font-weight:700;'>QTY</p>
          <p style='margin:4px 0 0;font-size:13px;color:#052e64;font-weight:700;'>{$quantity}</p>
        </td>
      </tr>
      <tr style='background:#fffdf0;'><td colspan='2' style='padding:13px 18px;border-top:1px solid #dde6f5;'>
        <p style='margin:0;font-size:9px;color:#8a9ab8;letter-spacing:2px;font-weight:700;'>AMOUNT PAID</p>
        <p style='margin:4px 0 0;'>{$amount_html}</p>
      </td></tr>
    </table>
  </td></tr>
  <tr><td style='padding:0 40px 22px;'>
    <div style='background:#f5f8ff;border-left:4px solid #052e64;border-radius:8px;padding:13px 16px;'>
      <p style='margin:0;font-size:9px;color:#8a9ab8;letter-spacing:2px;font-weight:700;'>VENUE</p>
      <p style='margin:4px 0 0;font-size:13px;color:#052e64;font-weight:700;'>".htmlspecialchars($event['venue'] ?? 'TBA')."</p>
    </div>
  </td></tr>
  {$qr_email_section}
  <tr><td style='padding:0 40px 30px;'>
    <div style='border:2px dashed #fec411;background:#fffdf0;border-radius:10px;padding:14px 18px;text-align:center;'>
      <p style='margin:0;font-size:14px;color:#bf1339;font-weight:800;'>📎 Your PDF ticket is attached</p>
      <p style='margin:8px 0 0;font-size:12px;color:#666;'>Download it and present at the entrance (printed or on your phone).</p>
    </div>
  </td></tr>
  <tr><td style='background:#052e64;padding:0;'>
    <table width='100%' cellpadding='0' cellspacing='0'>
      <tr><td style='background:#fec411;height:3px;'></td></tr>
      <tr><td style='padding:18px 40px;text-align:center;'>
        <p style='color:#7a9acc;font-size:12px;margin:0;'>Questions? Contact the event organizer | <a href='https://keychele.co.ke' style='color:#fec411;'>keychele.co.ke</a></p>
        <p style='color:#4a6a99;font-size:11px;margin:6px 0 0;'>© Keychele · Powered by Mecrofex Technologies</p>
      </td></tr>
    </table>
  </td></tr>
</table></td></tr></table></body></html>";
        $mail->send();

        $mail->clearAddresses(); $mail->clearAttachments();
        $mail->addAddress($creator_email);
        $mail->Subject = "New Ticket Sold — ".htmlspecialchars($event['title']);
        $mail->Body = "<h2 style='color:#052e64;'>New ticket sold on Keychele</h2>
<p><b>Buyer:</b> {$buyer_name} &lt;{$buyer_email}&gt;</p>
<p><b>Qty:</b> {$quantity} | <b>Type:</b> {$ticket_type_name} | <b>Ticket #:</b> {$ticket_number}</p>
<p><b>Amount:</b> ".($is_free?'FREE':'KES '.number_format($amount_paid))."</p>
<p><b>Event:</b> ".htmlspecialchars($event['title'])."</p>";
        $mail->send();
    } catch (Exception $e) { error_log("Ticket email failed: ".$mail->ErrorInfo); }

    @unlink($pdf_file);
    if ($qr_file) @unlink($qr_file);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1.0"/>
<title><?= htmlspecialchars($event['title'] ?? 'Event Tickets') ?> — Keychele</title>
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
                         onerror="this.src='https://via.placeholder.com/900x506?text=Event+Banner'"/>
                    <div class="ev-img-overlay"></div>
                    <div class="ev-card-foot">
                        <div class="ev-gold-bar"></div>
                        <div class="ev-title"><?= htmlspecialchars($event['title']) ?></div>
                    </div>
                </div>
            </div>

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
            </div>

            <!-- About -->
            <div class="ic">
                <div class="ic-ttl">About This Event</div>
                <p class="desc"><?= nl2br(htmlspecialchars($event['description'] ?? 'No description available.')) ?></p>
            </div>
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
                            $so  = (bool)$type['is_sold_out'];
                            $ifr = ($type['price'] == 0);
                            $low = !$so && $type['slots_left'] > 0 && $type['slots_left'] <= 20;
                        ?>
                        <div class="ttc <?= $so ? 'sout' : '' ?>"
                             data-id="<?= $type['id'] ?>"
                             data-price="<?= $type['price'] ?>"
                             data-slots="<?= $type['slots_left'] ?>"
                             data-name="<?= htmlspecialchars($type['type_name']) ?>"
                             data-free="<?= $ifr ? '1' : '0' ?>"
                             <?= !$so ? 'onclick="selType(this)"' : '' ?>>
                            <div class="ttc-top">
                                <div>
                                    <div class="tt-n"><?= htmlspecialchars($type['type_name']) ?></div>
                                    <div class="tt-p <?= $ifr ? 'fp' : '' ?>">
                                        <?= $ifr ? 'FREE' : 'KES '.number_format($type['price']) ?>
                                    </div>
                                   <?php if (!$so && $cts_show_remaining): ?>
    <div class="tt-sl <?= $low ? 'low' : '' ?>">
        <?= $low ? '⚠ Only ' : '' ?><?= number_format($type['slots_left']) ?> remaining
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
                            <div class="qty-wr" id="qw_<?= $type['id'] ?>">
                                <button type="button" class="qb" onclick="aq(<?= $type['id'] ?>,-1,event)">−</button>
                                <input type="number" class="qn" id="qn_<?= $type['id'] ?>"
                                       value="1" min="1" max="<?= $type['slots_left'] ?>" readonly/>
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
                        <input type="hidden" name="ticket_type"   id="selTId"       value="">
                        <input type="hidden" name="quantity"      id="totQty"       value="0">
                        <input type="hidden" name="custom_amount" id="custAmtHidden" value="">

                        <div class="fdiv">Your Details</div>

                        <div class="fg">
                            <label class="fl" for="fn">Full Name</label>
                            <input type="text" id="fn" name="name" class="fi" placeholder="Jane Mwangi" required autocomplete="name"/>
                        </div>
                        <div class="fg">
                            <label class="fl" for="fe">Email Address</label>
                            <input type="email" id="fe" name="email" class="fi" placeholder="jane@example.com" required autocomplete="email"/>
                            <p class="fh-txt"><i class="fas fa-info-circle"></i> Ticket PDF will be sent here</p>
                        </div>
                        <div class="fg">
                            <label class="fl" for="fp">M-Pesa Phone</label>
                            <input type="tel" id="fp" name="phone" class="fi" placeholder="0712 345 678" required autocomplete="tel"/>
                            <p class="fh-txt">Kenyan numbers only (07XX or 01XX)</p>
                        </div>

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
    grid-template-columns: 1fr 440px;
    gap: 32px;
    align-items: start;
}

/* ── Event banner card ──────────────────────────────────── */
.ev-card {
    border-radius: 18px; overflow: hidden;
    box-shadow: 0 20px 55px rgba(5,46,100,.45);
    margin-bottom: 20px;
    background: var(--g100);
}
.ev-card-img {
    position: relative;
    width: 100%;
    overflow: hidden;
}
.ev-card-img img {
    width: 100%;
    height: auto;
    display: block;
    object-fit: contain;
    transition: transform .6s ease;
}
.ev-card:hover .ev-card-img img { transform: scale(1.03); }
.ev-img-overlay {
    position: absolute; inset: 0;
    background: linear-gradient(
        to top,
        rgba(5,46,100,.88) 0%,
        rgba(5,46,100,.22) 55%,
        transparent 100%
    );
}
.ev-card-foot {
    position: absolute; bottom: 0; left: 0; right: 0;
    padding: 32px 26px 22px;
}
.ev-gold-bar {
    width: 48px; height: 4px;
    background: var(--gold);
    border-radius: 2px;
    margin-bottom: 10px;
}
.ev-title {
    font-family: var(--fh);
    font-weight: 900;
    font-size: clamp(20px, 3.5vw, 34px);
    color: #fff;
    line-height: 1.2;
    text-shadow: 0 2px 14px rgba(0,0,0,.55);
}

/* ── Pills ──────────────────────────────────────────────── */
.pills { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 18px; }
.pill {
    display: inline-flex; align-items: center; gap: 7px;
    background: rgba(5,46,100,.55);
    border: 1px solid rgba(254,196,17,.28);
    color: rgba(255,255,255,.88);
    border-radius: 30px; padding: 7px 15px;
    font-size: 13px; font-weight: 500;
    backdrop-filter: blur(6px);
}
.pill i { color: var(--gold); font-size: 11px; }

/* ── Share ──────────────────────────────────────────────── */
.share { display: flex; align-items: center; gap: 10px; margin-bottom: 28px; }
.share-lbl {
    font-family: var(--fh); font-size: 10px; font-weight: 700;
    letter-spacing: 2px; text-transform: uppercase;
    color: rgba(255,255,255,.38);
}
.sh {
    width: 36px; height: 36px;
    display: flex; align-items: center; justify-content: center;
    border-radius: 50%; color: rgba(255,255,255,.6);
    border: 1px solid rgba(255,255,255,.14);
    background: rgba(5,46,100,.35);
    text-decoration: none; font-size: 14px; transition: all .2s;
}
.sh:hover {
    background: var(--gold); border-color: var(--gold);
    color: var(--navy); transform: translateY(-2px);
}

/* ── Info Cards ─────────────────────────────────────────── */
.ic {
    background: rgba(255,255,255,.98); border-radius: 16px; padding: 24px 26px;
    margin-bottom: 16px; box-shadow: 0 4px 22px rgba(5,46,100,.13);
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

/* ── Purchase Panel ─────────────────────────────────────── */
.pp {
    border-radius: 18px; overflow: hidden;
    box-shadow: 0 14px 55px rgba(5,46,100,.40);
    position: sticky; top: 20px;
}
.pp-hd {
    background: var(--navy); padding: 26px 28px 22px;
    border-bottom: 3px solid var(--gold);
}
.pp-eye {
    font-family: var(--fh); font-size: 9px; font-weight: 800;
    letter-spacing: 3px; text-transform: uppercase;
    color: var(--gold); margin-bottom: 8px;
}
.pp-hd h2 {
    font-family: var(--fh); font-size: clamp(15px,2vw,18px);
    font-weight: 800; color: #fff; line-height: 1.35;
}
.pp-hd h2 em { font-style: normal; color: var(--gold); }
.pp-hd p { font-size: 13px; color: rgba(255,255,255,.45); margin-top: 6px; }
.pp-body { background: var(--white); padding: 22px 26px 28px; }

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
    width: 100%; padding: 16px; border-radius: 12px; border: none;
    background: var(--navy); color: #fff;
    font-family: var(--fh); font-weight: 800; font-size: 16px;
    cursor: pointer; transition: all .3s;
    display: flex; align-items: center; justify-content: center; gap: 10px; margin-top: 10px;
}
.buybtn:hover:not(:disabled) {
    background: var(--navy-m); transform: translateY(-2px);
    box-shadow: 0 8px 25px rgba(5,46,100,.25);
}
.buybtn:active:not(:disabled) { transform: translateY(0); }
.buybtn:disabled { background: var(--g200); color: var(--g400); cursor: not-allowed; }

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

/* ══════════════════════════════════════════════════════════
   RESPONSIVE
══════════════════════════════════════════════════════════ */

/* Tablet — 3 columns */
@media (max-width: 1200px) {
    .eg { grid-template-columns: repeat(3, 1fr); gap: 18px; }
}

/* Tablet narrow / single-column layout */
@media (max-width: 1080px) {
    .grid2 { grid-template-columns: 1fr; gap: 40px; }
    .pp { position: static; }
    .eg { grid-template-columns: repeat(3, 1fr); gap: 16px; }
}

/* Small tablet — 2 columns */
@media (max-width: 768px) {
    .eg { grid-template-columns: repeat(2, 1fr); gap: 14px; }
}

/* Mobile */
@media (max-width: 600px) {
    .topnav { padding: 0 16px; }
    .mw { padding: 20px 15px 60px; }
    .pp-hd, .pp-body { padding: 20px; }
    .ic { padding: 20px; }

    /* 2 columns, full portrait poster */
    .eg { grid-template-columns: repeat(2, 1fr); gap: 10px; }
    .ecard-img { aspect-ratio: 2 / 3; }

    .ecard-body { padding: 10px 12px 14px; gap: 5px; }
    .ecard-title { font-size: 12px; }
    .ecard-venue { font-size: 10px; }
    .ecard-date-badge { font-size: 9px; padding: 2px 6px; top: 6px; left: 6px; }
    .ecard-price-tag { font-size: 10px; padding: 3px 9px; bottom: 8px; left: 8px; }

    .sec-hd { margin-top: 50px; margin-bottom: 22px; }
    .sec-hd h2 { font-size: 22px; }
}

/* Very small phones */
@media (max-width: 380px) {
    .eg { gap: 8px; }
    .ecard-title { font-size: 11px; }
    .ecard-date-badge, .ecard-price-tag { display: none; }
}
</style>
<script>
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
        const mx = parseInt(card.dataset.slots);
        if (qty > mx) {
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
</script>

<?php endif; ?>
</body>
</html>