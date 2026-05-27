<?php
/**
 * ticket_download.php
 * Default: Shows a branded ticket confirmation page with a download button.
 * With ?download=1: Streams the PDF directly.
 */

ini_set('display_errors', 0);
error_reporting(0);
session_start();

include 'msanii/includes/connect.php';
require_once 'vendor/fpdf.php';

// ─────────────────────────────────────────────────────────────
// Input validation
// ─────────────────────────────────────────────────────────────
$ticket_number = strtoupper(trim($_GET['ticket'] ?? ''));
$buyer_email   = strtolower(trim($_GET['email']  ?? ''));
$do_download   = isset($_GET['download']) && $_GET['download'] === '1';

if (!preg_match('/^TKT-[A-Z0-9\-]+$/i', $ticket_number) || !filter_var($buyer_email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    die('<h2 style="font-family:sans-serif;color:#bf1339;text-align:center;margin-top:4rem;">Invalid request.</h2>');
}

// ─────────────────────────────────────────────────────────────
// Fetch purchase + event
// ─────────────────────────────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT tp.*, t.title, t.start_date, t.start_time, t.venue,
           ett.type_name, tp.mpesa_receipt
    FROM ticket_purchases tp
    JOIN tickets t ON tp.ticket_id = t.id
    LEFT JOIN event_ticket_types ett ON tp.ticket_type_id = ett.id
    WHERE tp.ticket_number = ? AND LOWER(tp.buyer_email) = ? AND tp.status = 'completed'
    LIMIT 1
");
$stmt->execute([$ticket_number, $buyer_email]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    http_response_code(404);
    die('<h2 style="font-family:sans-serif;color:#bf1339;text-align:center;margin-top:4rem;">Ticket not found or email mismatch.</h2>');
}

// ─────────────────────────────────────────────────────────────
// Rate limiting — only on actual PDF stream (?download=1)
// ─────────────────────────────────────────────────────────────
if ($do_download) {
    $ip       = preg_replace('/[^0-9a-fA-F:.]/', '', $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    $rateFile = sys_get_temp_dir() . '/dlrate_' . md5($ip) . '.json';
    $now      = time();
    $window   = 3600;
    $limit    = 10;

    $hits = [];
    if (file_exists($rateFile)) {
        $hits = json_decode(file_get_contents($rateFile), true) ?? [];
    }
    $hits = array_values(array_filter($hits, fn($t) => $t > $now - $window));

    if (count($hits) >= $limit) {
        http_response_code(429);
        die('<h2 style="font-family:sans-serif;color:#bf1339;text-align:center;margin-top:4rem;">Too many requests. Try again in an hour.</h2>');
    }

    $hits[] = $now;
    file_put_contents($rateFile, json_encode($hits), LOCK_EX);
}

// ─────────────────────────────────────────────────────────────
// QR code helper
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
    $tmp = sys_get_temp_dir() . '/qr_dl_' . $ticket_number . '.png';
    file_put_contents($tmp, $img);
    return $tmp;
}

// ─────────────────────────────────────────────────────────────
// PDF class
// ─────────────────────────────────────────────────────────────
class TicketPDF extends FPDF {
    private $is_free = false;

    function setTicketMeta($is_free) { $this->is_free = $is_free; }

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
                ['TICKET TYPE', $purchase['ticket_type_name'] ?? 'General Admission'],
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

            if (!empty($purchase['mpesa_receipt'])) {
                $this->SetX(15);
                $this->SetFont('Arial', '', 8); $this->SetTextColor(140, 155, 180); $this->Cell(52, 6, 'M-PESA RECEIPT', 0, 0);
                $this->SetFont('Arial', 'B', 10); $this->SetTextColor(5, 46, 100);
                $this->Cell(68, 6, $purchase['mpesa_receipt'], 0, 1);
                $this->Ln(1);
            }

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
            $this->row('Ticket Type', $purchase['ticket_type_name'] ?? 'General Admission');
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
// PDF STREAM MODE  (?download=1)
// ─────────────────────────────────────────────────────────────
if ($do_download) {
    $is_free = ($row['amount_paid'] == 0);
    $qr_file = null;
    if (!$is_free) {
        $qr_file = generateQRCode(
            $row['ticket_number'], $row['buyer_name'],
            $row['title'], $row['quantity'], $row['amount_paid']
        );
    }

    $event    = [
        'title'      => $row['title'],
        'start_date' => $row['start_date'],
        'start_time' => $row['start_time'],
        'venue'      => $row['venue'],
    ];
    $purchase = [
        'ticket_number'    => $row['ticket_number'],
        'buyer_name'       => $row['buyer_name'],
        'quantity'         => $row['quantity'],
        'amount_paid'      => $row['amount_paid'],
        'ticket_type_name' => $row['type_name'] ?? 'General Admission',
        'mpesa_receipt'    => $row['mpesa_receipt'] ?? '',
    ];

    $pdf = new TicketPDF();
    $pdf->setTicketMeta($is_free);
    $pdf->AddPage();
    $pdf->AddTicketContent($event, $purchase, $qr_file);
    if ($qr_file) @unlink($qr_file);

    $filename = 'Keychele_Ticket_' . $row['ticket_number'] . '.pdf';
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store, no-cache');
    header('Pragma: no-cache');
    $pdf->Output('D', $filename);
    exit;
}

// ─────────────────────────────────────────────────────────────
// PAGE MODE  (default — no ?download=1)
// ─────────────────────────────────────────────────────────────
$is_free      = ($row['amount_paid'] == 0);
$event_title  = htmlspecialchars($row['title']);
$buyer_name   = htmlspecialchars($row['buyer_name']);
$amount_disp  = $is_free ? 'FREE' : 'KES ' . number_format($row['amount_paid']);
$event_id     = (int)$row['ticket_id'];

$download_url    = '/ticket_download.php?ticket=' . urlencode($ticket_number) . '&email=' . urlencode($buyer_email) . '&download=1';
$purchase_again  = '/ticket/' . $event_id;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1.0"/>
<title>Your Ticket — <?= $event_title ?> | Keychele</title>
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
<style>
*,*::before,*::after{ box-sizing:border-box; margin:0; padding:0; }
:root{
    --navy:#052e64; --navy-d:#031e44; --navy-l:#e8eef8; --navy-xl:#f2f5fc;
    --gold:#fec411; --gold-d:#d9a70a; --gold-l:#fffbea;
    --crimson:#bf1339; --crimson-d:#96102d; --crimson-l:#fce8ec;
    --green:#16a34a; --green-l:#dcfce7;
    --g50:#f8f9fd; --g100:#eef1f9; --g200:#dce2f0; --g400:#8a97b8; --g600:#4a5578;
    --fm:'Courier New',monospace;
}
html{ scroll-behavior:smooth; }

/* ── Full blurred background like ticket.php ── */
body{
    font-family:'Montserrat',system-ui,sans-serif;
    background:var(--navy-d);
    min-height:100vh;
    -webkit-font-smoothing:antialiased;
    overflow-x:hidden;
}
.bg-scene{
    position:fixed; inset:0; z-index:0;
    background:var(--navy-d);
    filter:brightness(0.18);
}
.bg-veil{
    position:fixed; inset:0; z-index:1;
    background:linear-gradient(150deg,
        rgba(5,46,100,.70) 0%,
        rgba(5,46,100,.25) 40%,
        rgba(3,15,35,.95) 100%);
}

/* ── Nav ── */
.topnav{
    position:relative; z-index:30;
    height:62px;
    display:flex; align-items:center; justify-content:space-between;
    padding:0 32px;
    background:rgba(5,46,100,.97);
    border-bottom:3px solid var(--gold);
    backdrop-filter:blur(12px);
}
.nb{
    font-family:'Montserrat',sans-serif;
    font-weight:900; font-size:21px;
    letter-spacing:3px; color:#fff;
    text-decoration:none;
}
.nb span{ color:var(--gold); }

/* ── Page wrapper ── */
.page-wrap{
    position:relative; z-index:5;
    min-height:calc(100vh - 62px);
    display:flex; align-items:flex-start; justify-content:center;
    padding:40px 16px 80px;
}

/* ── Two-column desktop layout ── */
.layout{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:28px;
    width:100%;
    max-width:960px;
    align-items:start;
}

/* ── Card base ── */
.card{
    background:#fff;
    border-radius:20px;
    overflow:hidden;
    box-shadow:0 20px 60px rgba(5,46,100,.50);
}

/* ── LEFT card: ticket confirm ── */
.card-hd{
    background:var(--navy);
    padding:26px 32px 18px;
    text-align:center;
    border-bottom:3px solid var(--gold);
}
.brand{ color:#fff; font-size:24px; font-weight:900; letter-spacing:4px; margin-bottom:3px; }
.brand-sub{ color:var(--gold); font-size:8px; font-weight:800; letter-spacing:3px; text-transform:uppercase; }

.badge-ok{
    display:flex; align-items:center; justify-content:center; gap:10px;
    background:var(--green-l);
    padding:12px 20px;
    border-bottom:1px solid rgba(22,163,74,.2);
}
.badge-ok .icon{
    width:28px; height:28px; border-radius:50%;
    background:var(--green); color:#fff;
    display:flex; align-items:center; justify-content:center;
    font-size:12px; flex-shrink:0;
}
.badge-ok p{ font-size:12px; font-weight:700; color:var(--green); }

.tn-band{
    background:var(--crimson);
    padding:16px 32px; text-align:center;
}
.tn-lbl{ color:rgba(255,255,255,.65); font-size:8px; font-weight:800; letter-spacing:3px; margin-bottom:5px; }
.tn-num{ color:#fff; font-family:var(--fm); font-size:20px; font-weight:800; letter-spacing:4px; }

.card-body{ padding:24px 28px 28px; }

/* ── Email note ── */
.email-note{
    display:flex; align-items:center; justify-content:center; gap:6px;
    font-size:12px; color:var(--g400);
    margin-bottom:20px; flex-wrap:wrap; text-align:center;
}
.email-note i{ color:var(--crimson); flex-shrink:0; }
.email-note strong{ color:var(--navy); word-break:break-all; }

/* ── Instruction box ── */
.info-box{
    background:var(--navy-l); border-left:4px solid var(--gold);
    border-radius:10px; padding:13px 15px;
    font-size:12.5px; color:var(--navy); line-height:1.65;
    margin-bottom:20px;
}
.info-box strong{ display:block; margin-bottom:3px; font-size:13px; }

/* ── Countdown bar ── */
.countdown-wrap{
    background:var(--g100); border-radius:10px;
    padding:12px 16px; margin-bottom:20px;
    text-align:center;
}
.countdown-bar-bg{
    background:var(--g200); border-radius:6px; height:5px;
    overflow:hidden; margin:8px 0 6px;
}
.countdown-bar{
    height:100%; background:var(--navy);
    border-radius:6px; transition:width 1s linear;
    width:100%;
}
.countdown-txt{
    font-size:11px; color:var(--g400);
}
.countdown-txt strong{ color:var(--navy); }

/* ── Download button ── */
.dl-btn{
    display:flex; align-items:center; justify-content:center; gap:10px;
    width:100%; padding:16px;
    background:var(--navy); color:#fff;
    border:none; border-radius:12px;
    font-family:'Montserrat',sans-serif;
    font-size:15px; font-weight:800;
    text-decoration:none;
    transition:background .2s, transform .15s, box-shadow .2s;
    margin-bottom:10px;
}
.dl-btn:hover{
    background:var(--navy-d);
    transform:translateY(-2px);
    box-shadow:0 8px 28px rgba(5,46,100,.40);
}
.dl-btn .dl-icon{
    width:34px; height:34px; border-radius:50%;
    background:var(--gold); color:var(--navy);
    display:flex; align-items:center; justify-content:center;
    font-size:14px; flex-shrink:0;
}

/* ── Gmail button ── */
.gmail-btn{
    display:flex; align-items:center; justify-content:center; gap:9px;
    width:100%; padding:13px;
    background:#fff; color:#3c4043;
    border:2px solid var(--g200); border-radius:12px;
    font-family:'Montserrat',sans-serif;
    font-size:13px; font-weight:700;
    text-decoration:none;
    transition:all .2s; margin-bottom:10px;
}
.gmail-btn:hover{ background:var(--g50); border-color:#bbb; transform:translateY(-1px); }

/* ── Action row: purchase again + exit ── */
.action-row{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:10px;
    margin-top:4px;
}
.btn-purchase{
    display:flex; align-items:center; justify-content:center; gap:8px;
    padding:13px 10px;
    background:var(--crimson); color:#fff;
    border:none; border-radius:12px;
    font-family:'Montserrat',sans-serif;
    font-size:13px; font-weight:800;
    text-decoration:none;
    transition:all .2s; cursor:pointer;
}
.btn-purchase:hover{
    background:var(--crimson-d);
    transform:translateY(-2px);
    box-shadow:0 6px 20px rgba(191,19,57,.35);
}
.btn-exit{
    display:flex; align-items:center; justify-content:center; gap:8px;
    padding:13px 10px;
    background:transparent; color:var(--g400);
    border:2px solid var(--g200); border-radius:12px;
    font-family:'Montserrat',sans-serif;
    font-size:13px; font-weight:700;
    text-decoration:none;
    transition:all .2s;
}
.btn-exit:hover{ background:var(--g100); color:var(--navy); border-color:var(--navy-l); transform:translateY(-1px); }

/* ── RIGHT card: advert ── */
.advert-card{
    background:linear-gradient(145deg, var(--navy) 0%, #0a4a99 100%);
    border:2px solid var(--gold);
    border-radius:20px;
    overflow:hidden;
    box-shadow:0 20px 60px rgba(5,46,100,.50);
    display:flex; flex-direction:column;
}
.advert-top{
    padding:32px 28px 24px;
    flex:1;
}
.advert-icon-wrap{
    width:60px; height:60px; border-radius:16px;
    background:var(--gold); color:var(--navy);
    display:flex; align-items:center; justify-content:center;
    font-size:26px;
    box-shadow:0 6px 18px rgba(254,196,17,.40);
    margin-bottom:20px;
}
.advert-tag{
    font-size:9px; font-weight:800; letter-spacing:2.5px;
    text-transform:uppercase; color:var(--gold); margin-bottom:10px;
}
.advert-title{
    font-size:22px; font-weight:900; color:#fff;
    line-height:1.25; margin-bottom:12px;
}
.advert-title span{ color:var(--gold); }
.advert-sub{
    font-size:14px; color:rgba(255,255,255,.65);
    line-height:1.7; margin-bottom:24px;
}
.advert-features{
    display:flex; flex-direction:column; gap:10px; margin-bottom:28px;
}
.advert-feat{
    display:flex; align-items:center; gap:12px;
}
.feat-icon{
    width:34px; height:34px; border-radius:10px;
    background:rgba(254,196,17,.18);
    display:flex; align-items:center; justify-content:center;
    color:var(--gold); font-size:14px; flex-shrink:0;
}
.feat-txt{ font-size:13px; color:rgba(255,255,255,.80); font-weight:500; }
.advert-cta{
    display:flex; align-items:center; gap:10px;
    width:100%; padding:18px 28px;
    background:var(--gold); color:var(--navy);
    font-family:'Montserrat',sans-serif;
    font-size:15px; font-weight:900;
    text-decoration:none;
    border-top:1px solid rgba(254,196,17,.25);
    transition:background .2s;
    letter-spacing:.3px;
}
.advert-cta:hover{ background:#ffe066; }
.advert-cta .arr{ margin-left:auto; font-size:13px; }

/* ── Responsive ── */
@media(max-width:768px){
    .topnav{ padding:0 16px; }
    .nb{ font-size:18px; }
    .page-wrap{ padding:20px 12px 60px; align-items:flex-start; }

    /* Stack single column on mobile */
    .layout{
        grid-template-columns:1fr;
        gap:20px;
        max-width:480px;
    }

    .card-hd{ padding:20px 20px 14px; }
    .brand{ font-size:20px; }
    .card-body{ padding:18px 18px 22px; }
    .tn-band{ padding:14px 20px; }
    .tn-num{ font-size:16px; letter-spacing:3px; }

    .action-row{ grid-template-columns:1fr 1fr; gap:8px; }
    .btn-purchase, .btn-exit{ font-size:12px; padding:12px 8px; }

    .advert-top{ padding:24px 20px 18px; }
    .advert-title{ font-size:18px; }
    .advert-cta{ padding:16px 20px; font-size:14px; }
    .advert-icon-wrap{ width:50px; height:50px; font-size:20px; }
}

@media(max-width:400px){
    .tn-num{ font-size:13px; letter-spacing:2px; }
    .action-row{ grid-template-columns:1fr; }
}
</style>
</head>
<body>

<div class="bg-scene"></div>
<div class="bg-veil"></div>

<!-- Nav -->
<nav class="topnav">
    <a href="/" class="nb">KEY<span>CHELE</span></a>
</nav>

<div class="page-wrap">
    <div class="layout">

        <!-- ══════════════════════════════════
             LEFT — Ticket confirmation card
        ══════════════════════════════════ -->
        <div class="card">

            <!-- Header -->
            <div class="card-hd">
                <div class="brand">KEYCHELE</div>
                <div class="brand-sub">Official Event Ticket</div>
            </div>

            <!-- Success strip -->
            <div class="badge-ok">
                <div class="icon"><i class="fas fa-check"></i></div>
                <p>Payment confirmed — your ticket is ready!</p>
            </div>

            <!-- Ticket number -->
            <div class="tn-band">
                <div class="tn-lbl">T I C K E T &nbsp; N U M B E R</div>
                <div class="tn-num"><?= htmlspecialchars($ticket_number) ?></div>
            </div>

            <!-- Body -->
            <div class="card-body">

                <!-- Email note -->
                <div class="email-note">
                    <i class="fas fa-envelope"></i>
                    Ticket emailed to
                    <strong><?= htmlspecialchars($buyer_email) ?></strong>
                </div>

                <!-- Instruction -->
                <div class="info-box">
                    <strong>How to use your ticket</strong>
                    Download the PDF below and present it at the event entrance —
                    printed or on your phone. A copy has also been sent to your email.
                </div>

                <!-- Auto-redirect countdown -->
                <div class="countdown-wrap">
                    <div class="countdown-txt">
                        Redirecting to home in <strong id="cdNum">60</strong>s
                    </div>
                    <div class="countdown-bar-bg">
                        <div class="countdown-bar" id="cdBar"></div>
                    </div>
                    <div class="countdown-txt" style="font-size:10px;">
                        Click any button below to cancel auto-redirect
                    </div>
                </div>

                <!-- Download PDF -->
                <a href="<?= htmlspecialchars($download_url) ?>" class="dl-btn" id="anyBtn">
                    <div class="dl-icon"><i class="fas fa-download"></i></div>
                    Download Ticket PDF
                </a>

                <!-- Gmail -->
                <a href="https://mail.google.com/mail/u/0/#search/from%3Atickets%40keychele.co.ke"
                   target="_blank" rel="noopener" class="gmail-btn" id="anyBtn2">
                    <svg width="17" height="17" viewBox="0 0 48 48" xmlns="http://www.w3.org/2000/svg" style="flex-shrink:0;">
                        <rect fill="#EA4335" x="10" y="10" width="28" height="28" rx="2"/>
                        <path fill="#fff" d="M10 14l14 10 14-10v20H10z"/>
                        <path fill="#EA4335" d="M10 14l14 10 14-10"/>
                        <path fill="#fff" stroke="#fff" stroke-width="0.5" d="M24 24L10 14v20h5V20l9 6.5 9-6.5v14h5V14z"/>
                    </svg>
                    Open Gmail for email copy
                </a>

                <!-- Purchase Again + Exit -->
                <div class="action-row">
                    <a href="<?= htmlspecialchars($purchase_again) ?>" class="btn-purchase">
                        <i class="fas fa-ticket-alt"></i>
                        Purchase Again
                    </a>
                    <a href="/" class="btn-exit">
                        <i class="fas fa-sign-out-alt"></i>
                        Exit to Home
                    </a>
                </div>

            </div><!-- /card-body -->
        </div><!-- /left card -->

        <!-- ══════════════════════════════════
             RIGHT — Organizer advert card
        ══════════════════════════════════ -->
        <div class="advert-card">
            <div class="advert-top">
                <div class="advert-icon-wrap">
                    <i class="fas fa-calendar-check"></i>
                </div>
                <div class="advert-tag">For Event Organizers</div>
                <div class="advert-title">
                    Hosting an <span>event?</span><br>
                    Create it on Keychele.
                </div>
                <div class="advert-sub">
                    Kenya's premier event ticketing platform. Sell tickets via M-Pesa,
                    manage attendees, and get paid instantly — all in one place.
                </div>
                <div class="advert-features">
                    <div class="advert-feat">
                        <div class="feat-icon"><i class="fas fa-mobile-alt"></i></div>
                        <span class="feat-txt">M-Pesa STK Push payments built in</span>
                    </div>
                    <div class="advert-feat">
                        <div class="feat-icon"><i class="fas fa-qrcode"></i></div>
                        <span class="feat-txt">QR code tickets for easy check-in</span>
                    </div>
                    <div class="advert-feat">
                        <div class="feat-icon"><i class="fas fa-chart-line"></i></div>
                        <span class="feat-txt">Real-time sales dashboard</span>
                    </div>
                    <div class="advert-feat">
                        <div class="feat-icon"><i class="fas fa-envelope"></i></div>
                        <span class="feat-txt">Automatic ticket emails to buyers</span>
                    </div>
                </div>
            </div>
            <a href="https://keychele.co.ke/register" class="advert-cta">
                <i class="fas fa-rocket"></i>
                Create Your Event — It's Free
                <i class="fas fa-arrow-right arr"></i>
            </a>
        </div><!-- /right advert card -->

    </div><!-- /layout -->
</div><!-- /page-wrap -->

<script>
// ── Auto-redirect countdown (60 seconds) ──
const TOTAL  = 60;
let   secs   = TOTAL;
let   active = true;

const cdNum = document.getElementById('cdNum');
const cdBar = document.getElementById('cdBar');

const tick = setInterval(() => {
    if (!active) { clearInterval(tick); return; }
    secs--;
    cdNum.textContent = secs;
    cdBar.style.width = (secs / TOTAL * 100) + '%';
    if (secs <= 15) cdBar.style.background = 'var(--crimson)';
    if (secs <= 0) {
        clearInterval(tick);
        window.location.href = '/';
    }
}, 1000);

// Cancel auto-redirect on any interaction
function cancelRedirect() {
    active = false;
    clearInterval(tick);
    const wrap = document.querySelector('.countdown-wrap');
    if (wrap) {
        wrap.style.background = 'var(--green-l)';
        wrap.style.borderLeft = '4px solid var(--green)';
        wrap.innerHTML = '<div style="font-size:12px;color:var(--green);font-weight:700;display:flex;align-items:center;gap:6px;"><i class="fas fa-check-circle"></i> Auto-redirect cancelled — take your time!</div>';
    }
}

// Attach to all interactive elements
document.querySelectorAll('a, button').forEach(el => {
    el.addEventListener('click', cancelRedirect, { once: true });
});
// Also cancel on any touch/scroll
document.addEventListener('touchstart', cancelRedirect, { once: true });
document.addEventListener('scroll',     cancelRedirect, { once: true });
</script>

</body>
</html>