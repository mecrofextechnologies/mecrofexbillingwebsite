<?php
// view_ticket_details.php
ob_start();
// ────────────────────────────────────────────────
// DEBUG SETTINGS (disable in production)
// ────────────────────────────────────────────────
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/view_ticket_details_debug.log');

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$connect_path = file_exists('msanii/includes/connect.php')
    ? 'msanii/includes/connect.php'
    : 'includes/connect.php';
if (!file_exists($connect_path)) {
    die("Database connection file missing.");
}
require_once $connect_path;
require_once '../vendor/autoload.php';

if (file_exists('vendor/fpdf.php')) {
    require_once 'vendor/fpdf.php';
}

// ────────────────────────────────────────────────
// Session & auth check
// ────────────────────────────────────────────────
if (!isset($_SESSION['creator_id']) || !is_numeric($_SESSION['creator_id'])) {
    if (isset($_POST['action']) && in_array($_POST['action'], [
        'toggle_checkin', 'send_failure_reminder', 'send_custom_reminder'
    ])) {
        while (ob_get_level() > 0) ob_end_clean();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'Not logged in']);
        exit;
    }
    header("Location: login.php");
    exit;
}

$creator_id = (int)$_SESSION['creator_id'];
$event_id   = (int)($_GET['event_id'] ?? 0);
if ($event_id <= 0) die("Invalid event ID.");

// ────────────────────────────────────────────────
// Get event + verify ownership
// ────────────────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT title, start_date, start_time, venue
    FROM tickets
    WHERE id = ? AND creator_id = ?
");
$stmt->execute([$event_id, $creator_id]);
$event = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$event) die("Event not found or access denied.");

// ── Check-in/Scan availability: allowed from 00:00:00 of event day ──
$event_day_midnight   = strtotime(date('Y-m-d', strtotime($event['start_date'])) . ' 00:00:00');
$checkin_allowed      = (time() >= $event_day_midnight);
$event_date_formatted = date('l, d M Y', strtotime($event['start_date']));
$show_reminder_button = strtotime($event['start_date']) > time();

// ────────────────────────────────────────────────
// AJAX: Toggle check-in
// ────────────────────────────────────────────────
if (isset($_POST['action']) && $_POST['action'] === 'toggle_checkin') {
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');

    // Server-side guard — enforce same rule
    if (!$checkin_allowed) {
        echo json_encode(['success' => false, 'message' => 'Check-in not allowed until event day (' . $event_date_formatted . ')']);
        exit;
    }

    $purchase_id = (int)($_POST['purchase_id'] ?? 0);
    $new_value   = (int)($_POST['new_value'] ?? 0);
    if ($purchase_id <= 0) { echo json_encode(['success' => false, 'message' => 'Invalid purchase ID']); exit; }

    $stmt = $pdo->prepare("
        SELECT tp.is_checked_in, tp.status
        FROM ticket_purchases tp
        JOIN tickets t ON tp.ticket_id = t.id
        WHERE tp.id = ? AND t.creator_id = ? AND t.id = ?
    ");
    $stmt->execute([$purchase_id, $creator_id, $event_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) { echo json_encode(['success' => false, 'message' => 'Ticket not found']); exit; }
    if ($row['status'] !== 'completed') { echo json_encode(['success' => false, 'message' => 'Payment not completed']); exit; }

    if ((int)$row['is_checked_in'] !== $new_value) {
        $stmt = $pdo->prepare("UPDATE ticket_purchases SET is_checked_in = ?, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$new_value, $purchase_id]);
        if ($new_value === 1) sendCheckInPDFAndNotification($pdo, $purchase_id);
    }
    echo json_encode(['success' => true]);
    exit;
}

// ────────────────────────────────────────────────
// AJAX: Send failure reminder
// ────────────────────────────────────────────────
if (isset($_POST['action']) && $_POST['action'] === 'send_failure_reminder') {
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');

    $purchase_id = (int)($_POST['purchase_id'] ?? 0);
    if ($purchase_id <= 0) { echo json_encode(['success' => false, 'message' => 'Invalid purchase ID']); exit; }

    $stmt = $pdo->prepare("
        SELECT tp.buyer_name, tp.buyer_email, tp.ticket_number, tp.status, tp.failed_reason, t.title AS event_title
        FROM ticket_purchases tp
        JOIN tickets t ON tp.ticket_id = t.id
        WHERE tp.id = ? AND t.creator_id = ? AND t.id = ?
    ");
    $stmt->execute([$purchase_id, $creator_id, $event_id]);
    $purchase = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$purchase || $purchase['status'] !== 'failed' || empty($purchase['buyer_email'])) {
        echo json_encode(['success' => false, 'message' => 'Cannot send: invalid or non-failed purchase']); exit;
    }

    $reason = !empty($purchase['failed_reason'])
        ? htmlspecialchars($purchase['failed_reason'])
        : 'Unknown reason (card declined, network issue, insufficient funds, etc.)';

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP(); $mail->Host = 'mail.keychele.co.ke'; $mail->SMTPAuth = true;
        $mail->Username = 'tickets@keychele.co.ke'; $mail->Password = 'King20241064.';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS; $mail->Port = 587;
        $mail->setFrom('tickets@keychele.co.ke', 'Keychele Tickets');
        $mail->addAddress($purchase['buyer_email'], $purchase['buyer_name']);
        $mail->isHTML(true);
        $mail->Subject = 'Payment Issue - Please Retry Your Ticket Purchase';
        $mail->Body = "<h2>Hello {$purchase['buyer_name']},</h2>
            <p>We noticed your payment for ticket <strong>#{$purchase['ticket_number']}</strong>
            for <strong>{$purchase['event_title']}</strong> did not go through.</p>
            <p><strong>Reason:</strong> {$reason}</p>
            <p>Please try again — your spot is still available!</p>
            <p><a href='https://keychele.co.ke/ticket.php?id={$event_id}' style='color:#b81236;font-weight:bold;'>Buy Ticket Again</a></p>
            <p>— Keychele Team</p>";
        $mail->AltBody = strip_tags($mail->Body);
        $mail->send();
        echo json_encode(['success' => true, 'message' => 'Reminder sent']);
    } catch (Exception $e) {
        error_log("Reminder email failed: " . $mail->ErrorInfo);
        echo json_encode(['success' => false, 'message' => 'Failed to send email']);
    }
    exit;
}

// ────────────────────────────────────────────────
// AJAX: Send custom reminder
// ────────────────────────────────────────────────
if (isset($_POST['action']) && $_POST['action'] === 'send_custom_reminder') {
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');

    $message     = trim($_POST['message'] ?? '');
    $subject     = trim($_POST['subject'] ?? 'Important Update from Organizer');
    $sender_name = trim($_POST['sender_name'] ?? 'Event Organizer');

    if (strlen($message) < 10) { echo json_encode(['success' => false, 'message' => 'Message too short (min 10 chars)']); exit; }

    $stmt = $pdo->prepare("
        SELECT DISTINCT tp.buyer_name, tp.buyer_email FROM ticket_purchases tp
        WHERE tp.ticket_id = ? AND tp.status = 'completed' AND tp.buyer_email IS NOT NULL AND tp.buyer_email != ''
    ");
    $stmt->execute([$event_id]);
    $buyers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($buyers)) { echo json_encode(['success' => false, 'message' => 'No completed buyers found']); exit; }

    $success = $failed = 0;
    foreach ($buyers as $buyer) {
        $name = htmlspecialchars($buyer['buyer_name'] ?: 'Valued Guest');
        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP(); $mail->Host = 'mail.keychele.co.ke'; $mail->SMTPAuth = true;
            $mail->Username = 'tickets@keychele.co.ke'; $mail->Password = 'King20241064.';
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS; $mail->Port = 587;
            $mail->setFrom('tickets@keychele.co.ke', $sender_name);
            $mail->addAddress($buyer['buyer_email'], $buyer['buyer_name']);
            $mail->isHTML(true); $mail->Subject = $subject;
            $mail->Body = "<h2>Hello {$name},</h2>
                <div style='white-space:pre-wrap;line-height:1.6;'>" . nl2br(htmlspecialchars($message)) . "</div>
                <br><p>— {$sender_name}<br><small>Event: " . htmlspecialchars($event['title']) . "</small></p>";
            $mail->AltBody = strip_tags($mail->Body);
            $mail->send(); $success++;
        } catch (Exception $e) { error_log("Custom reminder failed for {$buyer['buyer_email']}: " . $mail->ErrorInfo); $failed++; }
    }
    $msg = $success > 0 ? "Sent to $success attendee(s)" : "No messages were sent";
    if ($failed > 0) $msg .= " ($failed failed)";
    echo json_encode(['success' => $success > 0, 'message' => $msg]);
    exit;
}

// ────────────────────────────────────────────────
// PDF generation
// ────────────────────────────────────────────────
function generateTicketPDF($ticket, $purchase_id) {
    $pdf = new FPDF('P', 'mm', 'A5');
    $pdf->AddPage(); $pdf->SetMargins(10, 8, 10);
    $pdf->SetFillColor(12, 74, 132); $pdf->Rect(0, 0, 148, 38, 'F');
    $pdf->SetY(6); $pdf->SetFont('Arial', 'B', 22); $pdf->SetTextColor(255, 255, 255);
    $pdf->Cell(0, 10, 'Keychele', 0, 1, 'C'); $pdf->Ln(4);
    $pdf->SetFont('Arial', 'B', 15); $pdf->SetTextColor(220, 38, 38);
    $pdf->MultiCell(0, 7, $ticket['event_title'], 0, 'C'); $pdf->Ln(6);
    $pdf->SetFont('Arial', '', 10); $pdf->SetTextColor(40, 40, 40);
    $pdf->Cell(0, 6, 'Date: ' . date('d M Y', strtotime($ticket['start_date'])), 0, 1);
    $pdf->Cell(0, 6, 'Time: ' . date('g:i A', strtotime($ticket['start_time'] ?? '00:00:00')), 0, 1);
    $pdf->Cell(0, 6, 'Venue: ' . ($ticket['venue'] ?: 'To Be Announced'), 0, 1); $pdf->Ln(8);
    $pdf->SetFont('Arial', 'B', 13); $pdf->SetTextColor(0, 102, 204);
    $pdf->Cell(0, 8, 'Ticket Type: ' . ($ticket['ticket_type'] ?: 'General Admission'), 0, 1, 'C'); $pdf->Ln(4);
    $pdf->SetFont('Arial', 'B', 16); $pdf->SetTextColor(220, 38, 38);
    $pdf->Cell(0, 10, 'Ticket # ' . $ticket['ticket_number'], 0, 1, 'C'); $pdf->Ln(10);
    $pdf->SetFont('Arial', 'B', 11); $pdf->SetTextColor(40, 40, 40);
    $pdf->Cell(0, 7, 'Buyer Information', 'B', 1, 'L');
    $pdf->SetFont('Arial', '', 10);
    $pdf->Cell(50, 6, 'Name:', 0, 0); $pdf->Cell(0, 6, $ticket['buyer_name'] ?? '—', 0, 1);
    $pdf->Cell(50, 6, 'Email:', 0, 0); $pdf->Cell(0, 6, $ticket['buyer_email'] ?? '—', 0, 1);
    $pdf->Cell(50, 6, 'Phone:', 0, 0); $pdf->Cell(0, 6, $ticket['buyer_phone'] ?? '—', 0, 1);
    $pdf->Ln(8); $pdf->SetFont('Arial', 'B', 11);
    $pdf->Cell(50, 6, 'Check-in Status:', 0, 0);
    $pdf->SetTextColor($ticket['is_checked_in'] ? 34 : 220, $ticket['is_checked_in'] ? 197 : 38, $ticket['is_checked_in'] ? 94 : 38);
    $pdf->Cell(0, 6, $ticket['is_checked_in'] ? 'CHECKED IN' : 'NOT CHECKED IN', 0, 1); $pdf->Ln(12);
    $pdf->SetFont('Arial', 'I', 8); $pdf->SetTextColor(100, 100, 100);
    $pdf->MultiCell(0, 5, "Present this ticket at the entrance.\nThank you for supporting Keychele events!", 0, 'C');
    $pdf->Ln(6); $pdf->SetFont('Arial', '', 9);
    $pdf->Cell(0, 6, '[QR Code / Barcode Placeholder]', 0, 1, 'C');
    return $pdf;
}

function sendCheckInPDFAndNotification($pdo, $purchase_id) {
    global $creator_id, $event_id;
    $stmt = $pdo->prepare("
        SELECT tp.ticket_number, tp.buyer_name, tp.buyer_email, tp.buyer_phone, tp.is_checked_in,
               t.title AS event_title, t.start_date, t.start_time, t.venue,
               ett.type_name AS ticket_type
        FROM ticket_purchases tp
        JOIN tickets t ON tp.ticket_id = t.id
        LEFT JOIN event_ticket_types ett ON tp.ticket_type_id = ett.id
        WHERE tp.id = ? AND t.creator_id = ? AND t.id = ?
    ");
    $stmt->execute([$purchase_id, $creator_id, $event_id]);
    $ticket = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$ticket || !$ticket['buyer_email']) return;

    $pdf = generateTicketPDF($ticket, $purchase_id);
    $pdfContent = $pdf->Output('S');
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP(); $mail->Host = 'mail.keychele.co.ke'; $mail->SMTPAuth = true;
        $mail->Username = 'tickets@keychele.co.ke'; $mail->Password = 'King20241064.';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS; $mail->Port = 587;
        $mail->setFrom('tickets@keychele.co.ke', 'Keychele Tickets');
        $mail->addAddress($ticket['buyer_email'], $ticket['buyer_name']);
        $mail->isHTML(true); $mail->Subject = 'You have been checked in - ' . $ticket['event_title'];
        $mail->Body = "<h2>Check-in Confirmed!</h2>
            <p>Dear {$ticket['buyer_name']},</p>
            <p>You have been successfully checked in for <strong>{$ticket['event_title']}</strong>.</p>
            <p><strong>Ticket #:</strong> {$ticket['ticket_number']}</p>
            <p><strong>Date:</strong> " . date('d M Y', strtotime($ticket['start_date'])) . "</p>
            <p>Attached is your digital ticket. Thank you for attending!</p>
            <p>— Keychele Team</p>";
        $mail->AltBody = strip_tags($mail->Body);
        $mail->addStringAttachment($pdfContent, 'ticket-' . $ticket['ticket_number'] . '.pdf', 'base64', 'application/pdf');
        $mail->send();
    } catch (Exception $e) { error_log("Check-in email failed: " . $mail->ErrorInfo); }
}

// ────────────────────────────────────────────────
// PDF Download
// ────────────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'download_pdf' && isset($_GET['id'])) {
    while (ob_get_level() > 0) ob_end_clean();
    $purchase_id = (int)$_GET['id'];
    $stmt = $pdo->prepare("
        SELECT tp.ticket_number, tp.buyer_name, tp.buyer_email, tp.buyer_phone, tp.is_checked_in,
               t.title AS event_title, t.start_date, t.start_time, t.venue,
               ett.type_name AS ticket_type
        FROM ticket_purchases tp JOIN tickets t ON tp.ticket_id = t.id
        LEFT JOIN event_ticket_types ett ON tp.ticket_type_id = ett.id
        WHERE tp.id = ? AND t.creator_id = ? AND t.id = ?
    ");
    $stmt->execute([$purchase_id, $creator_id, $event_id]);
    $ticket = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$ticket) { http_response_code(403); die("Access denied."); }
    $pdf = generateTicketPDF($ticket, $purchase_id);
    $pdf->Output('D', 'ticket-' . $ticket['ticket_number'] . '.pdf');
    exit;
}

// ────────────────────────────────────────────────
// Fetch purchases
// ────────────────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT tp.id, tp.ticket_number, tp.buyer_name, tp.buyer_email, tp.buyer_phone,
           tp.amount_paid, tp.status, tp.created_at, tp.is_checked_in, tp.failed_reason,
           ett.type_name AS ticket_type_name
    FROM ticket_purchases tp
    JOIN tickets t ON tp.ticket_id = t.id
    LEFT JOIN event_ticket_types ett ON tp.ticket_type_id = ett.id
    WHERE t.id = ? AND t.creator_id = ?
    ORDER BY tp.created_at DESC
");
$stmt->execute([$event_id, $creator_id]);
$purchases = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Stats
$completed_count  = count(array_filter($purchases, fn($p) => $p['status'] === 'completed'));
$checked_in_count = count(array_filter($purchases, fn($p) => (int)$p['is_checked_in'] === 1));
$pending_count    = count(array_filter($purchases, fn($p) => $p['status'] === 'pending'));
$failed_count     = count(array_filter($purchases, fn($p) => $p['status'] === 'failed'));
$event_revenue    = array_sum(array_map(fn($p) => $p['status'] === 'completed' ? (float)$p['amount_paid'] : 0, $purchases));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Tickets: <?= htmlspecialchars($event['title']) ?> | Keychele</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <style>
    /* ── Design Tokens ── */
    :root {
      --navy:        #032d5d;
      --navy-light:  #0a3d7a;
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
      --purple:      #7c3aed;
      --purple-bg:   #ede9fe;
      --teal:        #0d9488;
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

    /* ── Preloader ── */
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

    /* ── Top Nav Bar ── */
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
    .back-btn {
      display: inline-flex; align-items: center; gap: 8px;
      color: var(--text-sec); text-decoration: none; font-size: .85rem; font-weight: 600;
      transition: var(--transition);
    }
    .back-btn:hover { color: var(--navy); }
    .back-icon {
      width: 34px; height: 34px; border-radius: 10px;
      background: #f1f5f9; display: flex; align-items: center; justify-content: center;
      font-size: .75rem; transition: var(--transition);
    }
    .back-btn:hover .back-icon { background: #e2e8f0; }

    .topbar-actions { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }

    /* ── Action Buttons ── */
    .action-btn {
      display: inline-flex; align-items: center; gap: 7px;
      padding: 9px 16px; border-radius: var(--radius-sm);
      font-family: 'Sora', sans-serif; font-size: .78rem; font-weight: 600;
      cursor: pointer; border: none; text-decoration: none;
      transition: var(--transition); white-space: nowrap; letter-spacing: .01em;
    }
    .action-btn i { font-size: .7rem; }
    .btn-navy    { background: var(--navy);   color: #fff; }
    .btn-navy:hover    { background: var(--navy-light); box-shadow: 0 4px 14px rgba(3,45,93,.3); transform: translateY(-1px); }
    .btn-green   { background: var(--green);  color: #fff; }
    .btn-green:hover   { background: #047857; box-shadow: 0 4px 14px rgba(5,150,105,.3); transform: translateY(-1px); }
    .btn-purple  { background: var(--purple); color: #fff; }
    .btn-purple:hover  { background: #6d28d9; box-shadow: 0 4px 14px rgba(124,58,237,.3); transform: translateY(-1px); }
    .btn-teal    { background: var(--teal);   color: #fff; }
    .btn-teal:hover    { background: #0f766e; box-shadow: 0 4px 14px rgba(13,148,136,.3); transform: translateY(-1px); }
    .btn-gold    { background: #fec411; color: var(--navy); }
    .btn-gold:hover    { background: #d9a70a; box-shadow: 0 4px 14px rgba(254,196,17,.4); transform: translateY(-1px); }

    /* Disabled button */
    .btn-disabled {
      background: #e2e8f0; color: var(--text-muted);
      cursor: not-allowed; pointer-events: none;
    }
    .btn-disabled:hover { transform: none; box-shadow: none; }

    /* ── Page Wrap ── */
    .page-wrap { max-width: 1280px; margin: 0 auto; padding: 24px 20px 96px; }
    @media (min-width: 768px) { .page-wrap { padding: 28px 28px 48px; } }

    /* ── Event-Lock Banner ── */
    .lock-banner {
      background: linear-gradient(135deg, #78350f, #92400e);
      border-radius: var(--radius-md);
      padding: 16px 22px;
      display: flex; align-items: center; gap: 14px;
      margin-bottom: 20px;
      border: 1px solid rgba(251,191,36,.3);
      animation: fadeUp .4s ease both;
    }
    .lock-banner-icon {
      width: 42px; height: 42px; border-radius: 12px;
      background: rgba(251,191,36,.15); border: 1.5px solid rgba(251,191,36,.35);
      display: flex; align-items: center; justify-content: center;
      font-size: 1.1rem; color: #fbbf24; flex-shrink: 0;
    }
    .lock-banner h4 { font-size: .9rem; font-weight: 700; color: #fff; margin-bottom: 2px; }
    .lock-banner p  { font-size: .78rem; color: rgba(255,255,255,.7); }

    /* ── Hero Banner ── */
    .hero {
      position: relative; overflow: hidden;
      background: linear-gradient(130deg, var(--navy) 0%, #0a3d7a 45%, var(--crimson) 100%);
      border-radius: var(--radius-lg); padding: 32px 36px; margin-bottom: 24px;
      box-shadow: var(--shadow-lg);
    }
    .hero::before {
      content: '';
      position: absolute; inset: 0;
      background: url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%23ffffff' fill-opacity='0.04'%3E%3Ccircle cx='30' cy='30' r='20'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E") repeat;
      pointer-events: none;
    }
    .hero-inner { position: relative; z-index: 1; display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 16px; }
    .hero-eyebrow {
      display: inline-flex; align-items: center; gap: 7px;
      background: rgba(255,255,255,.15); border: 1px solid rgba(255,255,255,.2);
      backdrop-filter: blur(8px); border-radius: 999px;
      padding: 4px 14px; font-size: .72rem; font-weight: 600;
      color: #fff; letter-spacing: .05em; text-transform: uppercase; margin-bottom: 10px;
    }
    .hero-title { font-size: clamp(1.25rem, 3vw, 2rem); font-weight: 800; color: #fff; letter-spacing: -.02em; line-height: 1.2; }
    .hero-meta  { display: flex; flex-wrap: wrap; gap: 16px; margin-top: 12px; }
    .hero-meta-item { display: flex; align-items: center; gap: 6px; font-size: .82rem; color: rgba(255,255,255,.75); }
    .hero-meta-item i { font-size: .7rem; opacity: .7; }
    .hero-revenue { text-align: right; }
    .hero-revenue-val { font-family: 'JetBrains Mono', monospace; font-size: 2rem; font-weight: 700; color: #fff; line-height: 1; }
    .hero-revenue-label { font-size: .75rem; color: rgba(255,255,255,.6); margin-top: 4px; }

    /* ── Stats Grid ── */
    .stats-grid {
      display: grid; grid-template-columns: repeat(2, 1fr);
      gap: 14px; margin-bottom: 24px;
    }
    @media (min-width: 640px)  { .stats-grid { grid-template-columns: repeat(3, 1fr); } }
    @media (min-width: 1024px) { .stats-grid { grid-template-columns: repeat(5, 1fr); } }

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
    .stat-card.sc-green::before  { background: var(--green); }
    .stat-card.sc-blue::before   { background: var(--blue); }
    .stat-card.sc-indigo::before { background: var(--indigo); }
    .stat-card.sc-amber::before  { background: var(--amber); }
    .stat-card.sc-red::before    { background: var(--red); }
    .stat-label { font-size: .68rem; font-weight: 700; text-transform: uppercase; letter-spacing: .07em; color: var(--text-muted); margin-bottom: 8px; }
    .stat-value { font-size: 1.6rem; font-weight: 800; letter-spacing: -.03em; color: var(--text-pri); line-height: 1; }
    .stat-value.mono { font-family: 'JetBrains Mono', monospace; font-size: 1.15rem; }
    .stat-icon-chip {
      margin-top: 12px; width: 34px; height: 34px; border-radius: 9px;
      display: flex; align-items: center; justify-content: center; font-size: .85rem;
    }
    .stat-card.sc-green  .stat-icon-chip { background: var(--green-bg);  color: var(--green); }
    .stat-card.sc-blue   .stat-icon-chip { background: var(--blue-bg);   color: var(--blue); }
    .stat-card.sc-indigo .stat-icon-chip { background: var(--indigo-bg); color: var(--indigo); }
    .stat-card.sc-amber  .stat-icon-chip { background: var(--amber-bg);  color: var(--amber); }
    .stat-card.sc-red    .stat-icon-chip { background: var(--red-bg);    color: var(--red); }

    /* ── QR Scan Banner ── */
    .scan-banner {
      border-radius: var(--radius-md); padding: 18px 22px;
      display: flex; align-items: center; justify-content: space-between;
      gap: 16px; flex-wrap: wrap; margin-bottom: 24px;
      border: 1.5px solid rgba(254,196,17,.3);
    }
    .scan-banner.active   { background: linear-gradient(130deg, var(--navy) 0%, #0a4a99 60%, var(--navy) 100%); box-shadow: 0 4px 20px rgba(3,45,93,.25); }
    .scan-banner.inactive { background: linear-gradient(130deg, #334155 0%, #475569 100%); border-color: rgba(148,163,184,.2); }
    .scan-banner-left { display: flex; align-items: center; gap: 14px; }
    .scan-banner-icon {
      width: 46px; height: 46px; border-radius: 13px;
      display: flex; align-items: center; justify-content: center;
      font-size: 1.2rem; flex-shrink: 0;
    }
    .scan-banner.active   .scan-banner-icon { background: rgba(254,196,17,.15); border: 1.5px solid rgba(254,196,17,.35); color: #fec411; }
    .scan-banner.inactive .scan-banner-icon { background: rgba(148,163,184,.12); border: 1.5px solid rgba(148,163,184,.2); color: var(--text-muted); }
    .scan-banner h3 { font-size: .92rem; font-weight: 700; color: #fff; margin-bottom: 3px; }
    .scan-banner p  { font-size: .75rem; color: rgba(255,255,255,.55); }
    .btn-scan-lg {
      display: inline-flex; align-items: center; gap: 8px;
      background: #fec411; color: var(--navy);
      padding: 11px 22px; border-radius: 12px;
      font-size: .85rem; font-weight: 800; text-decoration: none;
      transition: var(--transition); white-space: nowrap; flex-shrink: 0;
    }
    .btn-scan-lg:hover { background: #d9a70a; transform: translateY(-2px); box-shadow: 0 6px 20px rgba(254,196,17,.4); }
    .btn-scan-disabled {
      display: inline-flex; align-items: center; gap: 8px;
      background: #475569; color: #94a3b8;
      padding: 11px 22px; border-radius: 12px;
      font-size: .85rem; font-weight: 700; cursor: not-allowed;
      white-space: nowrap; flex-shrink: 0;
    }

    /* ── Main Panel ── */
    .panel { background: var(--surface); border-radius: var(--radius-lg); border: 1px solid var(--border); box-shadow: var(--shadow-md); overflow: hidden; }
    .panel-header {
      padding: 20px 28px; border-bottom: 1px solid var(--border);
      background: #fafbfe; display: flex; align-items: center;
      justify-content: space-between; flex-wrap: wrap; gap: 14px;
    }
    .panel-title { font-size: 1rem; font-weight: 700; color: var(--text-pri); display: flex; align-items: center; gap: 10px; }
    .panel-title i { color: var(--navy); }
    .record-badge { background: var(--blue-bg); color: var(--blue); font-size: .72rem; font-weight: 700; padding: 3px 10px; border-radius: 999px; }

    /* ── Search & Toolbar ── */
    .toolbar { padding: 14px 28px; border-bottom: 1px solid var(--border); background: #fafbfe; display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
    .search-box { position: relative; flex: 1; min-width: 220px; }
    .search-box i { position: absolute; left: 13px; top: 50%; transform: translateY(-50%); color: var(--text-muted); font-size: .82rem; pointer-events: none; }
    .search-input {
      width: 100%; padding: 10px 14px 10px 36px;
      border: 1.5px solid var(--border); border-radius: var(--radius-sm);
      font-family: 'Sora', sans-serif; font-size: .83rem; color: var(--text-pri);
      background: var(--surface); outline: none; transition: border-color .2s, box-shadow .2s;
    }
    .search-input:focus { border-color: var(--navy); box-shadow: 0 0 0 3px rgba(3,45,93,.08); }
    .search-input::placeholder { color: var(--text-muted); }
    .search-meta { font-size: .75rem; color: var(--text-muted); font-weight: 500; white-space: nowrap; }
    #clearSearchBtn { background: none; border: none; cursor: pointer; color: var(--text-muted); font-size: .78rem; padding: 0 4px; display: none; transition: color .2s; }
    #clearSearchBtn:hover { color: var(--crimson); }
    .per-page-select {
      padding: 8px 10px; border: 1.5px solid var(--border); border-radius: var(--radius-sm);
      font-family: 'Sora', sans-serif; font-size: .78rem; font-weight: 600;
      color: var(--text-sec); background: var(--surface); outline: none; cursor: pointer;
    }
    .per-page-select:focus { border-color: var(--navy); }

    /* ── Table ── */
    .table-scroll { overflow-x: auto; -webkit-overflow-scrolling: touch; max-height: 620px; overflow-y: auto; }
    .table-scroll::-webkit-scrollbar { width: 5px; height: 5px; }
    .table-scroll::-webkit-scrollbar-track { background: #f1f5f9; border-radius: 99px; }
    .table-scroll::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 99px; }

    table { width: 100%; border-collapse: collapse; min-width: 1100px; }
    thead th {
      padding: 12px 16px; font-size: .68rem; font-weight: 700;
      text-transform: uppercase; letter-spacing: .07em; color: var(--text-muted);
      background: #f8fafc; border-bottom: 1px solid var(--border); white-space: nowrap;
      position: sticky; top: 0; z-index: 2;
    }
    thead th:first-child { padding-left: 24px; }
    thead th:last-child  { padding-right: 24px; }
    tbody tr { border-bottom: 1px solid #f1f5f9; transition: background .15s; }
    tbody tr:last-child { border-bottom: none; }
    tbody tr:hover { background: #f8faff; }
    tbody td { padding: 14px 16px; font-size: .83rem; color: var(--text-sec); vertical-align: middle; }
    tbody td:first-child { padding-left: 24px; }
    tbody td:last-child  { padding-right: 24px; }

    .sn-cell { font-family: 'JetBrains Mono', monospace; font-size: .72rem; font-weight: 600; color: var(--text-muted); min-width: 36px; }
    .buyer-name-cell { font-weight: 600; color: var(--text-pri); }
    .ticket-num-cell { font-family: 'JetBrains Mono', monospace; font-weight: 700; color: var(--crimson); font-size: .8rem; }
    .amount-cell { font-family: 'JetBrains Mono', monospace; font-weight: 700; color: var(--green); font-size: .82rem; text-align: right; }
    .date-cell { font-size: .72rem; color: var(--text-muted); white-space: nowrap; }

    /* ── Status Badges ── */
    .badge { display: inline-block; padding: 3px 11px; border-radius: 999px; font-size: .7rem; font-weight: 700; letter-spacing: .03em; white-space: nowrap; }
    .badge-completed { background: var(--green-bg);  color: #14532d; }
    .badge-pending   { background: var(--amber-bg);  color: #78350f; }
    .badge-failed    { background: var(--red-bg);    color: #7f1d1d; }
    .badge-type      { background: #f1f5f9;          color: var(--text-sec); }
    .badge-checkin-yes { background: var(--blue-bg); color: #1e3a8a; }
    .badge-checkin-no  { background: #f1f5f9;        color: var(--text-sec); }
    .badge-checkin-disabled { background: #e2e8f0;   color: var(--text-muted); }

    /* ── Check-in Toggle Button ── */
    .checkin-toggle {
      display: inline-flex; align-items: center; gap: 5px;
      padding: 4px 12px; border-radius: 999px;
      font-family: 'Sora', sans-serif; font-size: .7rem; font-weight: 700;
      border: none; cursor: pointer; transition: var(--transition);
    }
    .checkin-toggle.yes { background: var(--blue-bg); color: #1e3a8a; }
    .checkin-toggle.yes:hover { background: #bfdbfe; }
    .checkin-toggle.no  { background: #f1f5f9; color: var(--text-sec); }
    .checkin-toggle.no:hover  { background: #e2e8f0; }
    .checkin-toggle:disabled { opacity: .5; cursor: not-allowed; }

    /* ── Highlight ── */
    .highlight { background: #fde68a; border-radius: 3px; padding: 0 2px; }

    /* ── Row actions ── */
    .row-actions { display: flex; align-items: center; justify-content: center; gap: 6px; }
    .icon-btn {
      width: 32px; height: 32px; border-radius: 8px; border: none; cursor: pointer;
      display: flex; align-items: center; justify-content: center; font-size: .72rem;
      transition: var(--transition); text-decoration: none;
    }
    .icon-btn-blue { background: var(--blue-bg);   color: var(--blue); }
    .icon-btn-blue:hover { background: #bfdbfe; }
    .icon-btn-red  { background: var(--red-bg);    color: var(--red); }
    .icon-btn-red:hover  { background: #fecaca; }

    /* ── No results ── */
    #noResultsDesk { display: none; text-align: center; padding: 48px 24px; }
    #noResultsDesk i { font-size: 2rem; color: var(--text-muted); display: block; margin-bottom: 10px; }
    #noResultsDesk p { color: var(--text-muted); font-size: .875rem; }

    /* ── Empty state ── */
    .empty-state { text-align: center; padding: 64px 24px; }
    .empty-icon { width: 60px; height: 60px; border-radius: 16px; background: var(--blue-bg); display: flex; align-items: center; justify-content: center; font-size: 1.4rem; color: var(--blue); margin: 0 auto 14px; }

    /* ── Pagination ── */
    .pagination-wrap {
      display: flex; align-items: center; justify-content: space-between;
      flex-wrap: wrap; gap: 12px; padding: 16px 28px;
      border-top: 1px solid var(--border); background: #fafbfe;
    }
    .page-info { font-size: .75rem; color: var(--text-muted); font-weight: 500; }
    .page-info strong { color: var(--text-pri); }
    .page-btns { display: flex; align-items: center; gap: 5px; }
    .page-btn {
      display: inline-flex; align-items: center; justify-content: center;
      min-width: 32px; height: 32px; padding: 0 8px;
      border-radius: 8px; border: 1.5px solid var(--border);
      background: var(--surface); font-family: 'Sora', sans-serif;
      font-size: .75rem; font-weight: 600; color: var(--text-sec);
      cursor: pointer; transition: var(--transition);
    }
    .page-btn:hover:not(:disabled) { border-color: var(--navy); color: var(--navy); background: #f0f4fa; }
    .page-btn.active { background: var(--navy); color: #fff; border-color: var(--navy); }
    .page-btn:disabled { opacity: .35; cursor: not-allowed; }

    /* ── Mobile Cards ── */
    .mobile-cards { padding: 14px; display: flex; flex-direction: column; gap: 12px; }
    .m-card {
      background: var(--surface); border: 1px solid var(--border);
      border-radius: var(--radius-md); padding: 16px;
      position: relative; overflow: hidden; transition: var(--transition);
    }
    .m-card::before {
      content: ''; position: absolute; top: 0; left: 0; bottom: 0; width: 4px;
      border-radius: 4px 0 0 4px;
    }
    .m-card.completed::before { background: var(--green); }
    .m-card.pending::before   { background: var(--amber); }
    .m-card.failed::before    { background: var(--red); }
    .m-card:hover { box-shadow: var(--shadow-md); }
    .m-sn { font-family: 'JetBrains Mono', monospace; font-size: .65rem; font-weight: 700; color: var(--text-muted); background: #f1f5f9; border-radius: 5px; padding: 2px 7px; display: inline-block; margin-bottom: 6px; }
    .m-card-top { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 3px; }
    .m-buyer { font-weight: 700; color: var(--text-pri); font-size: .9rem; }
    .m-amount { font-family: 'JetBrains Mono', monospace; font-weight: 700; color: var(--green); font-size: .85rem; }
    .m-date { font-size: .72rem; color: var(--text-muted); margin-bottom: 12px; }
    .m-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-bottom: 12px; }
    .m-field { background: #f8fafc; border-radius: 8px; padding: 8px 10px; }
    .m-field-label { font-size: .62rem; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; color: var(--text-muted); margin-bottom: 3px; }
    .m-field-val { font-size: .8rem; font-weight: 600; color: var(--text-pri); }
    .m-info { background: #f8fafc; border-radius: 10px; padding: 10px 12px; font-size: .78rem; color: var(--text-sec); display: flex; flex-direction: column; gap: 4px; margin-bottom: 10px; }
    .m-info span b { color: var(--text-pri); }
    .m-actions { display: flex; gap: 8px; }
    .m-btn { flex: 1; display: flex; align-items: center; justify-content: center; gap: 6px; padding: 9px; border-radius: 9px; font-family: 'Sora', sans-serif; font-size: .75rem; font-weight: 600; border: none; cursor: pointer; transition: var(--transition); text-decoration: none; }
    .m-btn-blue { background: var(--blue-bg); color: var(--blue); }
    .m-btn-blue:hover { background: #bfdbfe; }
    .m-btn-red  { background: var(--red-bg);  color: var(--red); }
    .m-btn-red:hover  { background: #fecaca; }

    /* ── Bottom Nav ── */
    .bottom-nav {
      position: fixed; bottom: 0; left: 0; right: 0;
      background: rgba(255,255,255,.96); backdrop-filter: blur(12px);
      border-top: 1px solid var(--border);
      display: flex; justify-content: space-around; align-items: center;
      padding: 8px 0 12px; z-index: 50;
    }
    @media (min-width: 768px) { .bottom-nav { display: none; } }
    .nav-item { display: flex; flex-direction: column; align-items: center; gap: 3px; text-decoration: none; color: var(--text-muted); font-size: .62rem; font-weight: 600; text-transform: uppercase; letter-spacing: .05em; transition: color .2s; padding: 4px 12px; }
    .nav-item i { font-size: 1.15rem; }
    .nav-item.active { color: var(--navy); }
    .nav-item:hover  { color: var(--navy); }
    .nav-fab {
      display: flex; flex-direction: column; align-items: center; gap: 3px;
      text-decoration: none; font-size: .62rem; font-weight: 600; letter-spacing: .05em;
    }
    .nav-fab-icon {
      width: 46px; height: 46px; border-radius: 14px; margin-top: -18px;
      display: flex; align-items: center; justify-content: center;
      font-size: 1.1rem; box-shadow: 0 4px 16px rgba(254,196,17,.4);
    }
    .nav-fab.active-scan .nav-fab-icon { background: #fec411; color: var(--navy); }
    .nav-fab.disabled-scan .nav-fab-icon { background: #e2e8f0; color: var(--text-muted); }
    .nav-fab.active-scan span  { color: #d97706; }
    .nav-fab.disabled-scan span { color: var(--text-muted); }

    /* ── Show/hide helpers ── */
    .desk-only { display: none; } @media (min-width: 768px) { .desk-only { display: block; } }
    .mob-only  { display: block; } @media (min-width: 768px) { .mob-only  { display: none;  } }

    /* ── Tooltip ── */
    [data-tip] { position: relative; }
    [data-tip]:hover::after {
      content: attr(data-tip); position: absolute; bottom: 125%; left: 50%;
      transform: translateX(-50%); background: var(--text-pri); color: #fff;
      font-size: .68rem; padding: 4px 8px; border-radius: 6px; white-space: nowrap;
      z-index: 20; pointer-events: none;
    }

    /* ── Animations ── */
    @keyframes fadeUp { from { opacity: 0; transform: translateY(12px); } to { opacity: 1; transform: none; } }
    .stat-card { animation: fadeUp .4s ease both; }
    .stat-card:nth-child(1) { animation-delay: .05s; }
    .stat-card:nth-child(2) { animation-delay: .10s; }
    .stat-card:nth-child(3) { animation-delay: .15s; }
    .stat-card:nth-child(4) { animation-delay: .20s; }
    .stat-card:nth-child(5) { animation-delay: .25s; }
  </style>
</head>
<body>

<!-- Preloader -->
<div id="preloader">
  <div class="preloader-box">
    <div class="spin"></div>
    <span>Loading ticket data…</span>
  </div>
</div>

<!-- ── Top Navigation Bar ── -->
<div class="topbar">
  <a href="index.php?page=ticket_purchases" class="back-btn">
    <span class="back-icon"><i class="fas fa-arrow-left"></i></span>
    <span>Back to Events</span>
  </a>

  <div class="topbar-actions">

    <?php if ($checkin_allowed): ?>
      <a href="scan_checkin.php?event_id=<?= $event_id ?>" class="action-btn btn-gold"
         data-tip="Scan QR codes to check in attendees">
        <i class="fas fa-qrcode"></i>
        <span class="desk-only" style="display:inline">Scan Check-In</span>
        <span class="mob-only"  style="display:inline">Scan</span>
      </a>
    <?php else: ?>
      <button class="action-btn btn-disabled"
              data-tip="Check-in opens at midnight on <?= $event_date_formatted ?>">
        <i class="fas fa-lock"></i>
        <span>Scan Locked</span>
      </button>
    <?php endif; ?>

    <?php if ($show_reminder_button): ?>
      <a href="customize_send_reminder.php?event_id=<?= $event_id ?>" class="action-btn btn-teal">
        <i class="fas fa-envelope-open-text"></i>
        <span class="desk-only" style="display:inline">Send Reminder</span>
        <span class="mob-only"  style="display:inline">Remind</span>
      </a>
    <?php endif; ?>

    <a href="send_pictures.php?event_id=<?= $event_id ?>" class="action-btn btn-purple">
      <i class="fas fa-images"></i>
      <span class="desk-only" style="display:inline">Send Pictures</span>
      <span class="mob-only"  style="display:inline">Photos</span>
    </a>

    <a href="export_tickets_excel.php?event_id=<?= $event_id ?>" class="action-btn btn-green"
       data-tip="Export all ticket data to Excel">
      <i class="fas fa-file-excel"></i>
      <span class="desk-only" style="display:inline">Export Excel</span>
      <span class="mob-only"  style="display:inline">Export</span>
    </a>

  </div>
</div>

<div class="page-wrap">

  <?php if (!$checkin_allowed): ?>
  <!-- ── Event Lock Notice ── -->
  <div class="lock-banner">
    <div class="lock-banner-icon"><i class="fas fa-lock-clock"></i></div>
    <div>
      <h4>Check-in &amp; Scanning Locked Until Event Day</h4>
      <p>Scanning and manual check-in will be enabled automatically from <strong style="color:#fbbf24"><?= $event_date_formatted ?></strong> at midnight (00:00). Come back then to start checking in attendees.</p>
    </div>
  </div>
  <?php endif; ?>

  <!-- ── Hero Banner ── -->
  <div class="hero" style="margin-bottom:24px">
    <div class="hero-inner">
      <div>
        <div class="hero-eyebrow">
          <i class="fas fa-calendar-star" style="color:#fbbf24"></i>
          Event Dashboard
        </div>
        <div class="hero-title"><?= htmlspecialchars($event['title']) ?></div>
        <div class="hero-meta">
          <?php if ($event['start_date']): ?>
          <span class="hero-meta-item"><i class="fas fa-calendar"></i><?= date('d M Y', strtotime($event['start_date'])) ?></span>
          <?php endif; ?>
          <?php if ($event['start_time']): ?>
          <span class="hero-meta-item"><i class="fas fa-clock"></i><?= date('g:i A', strtotime($event['start_time'])) ?></span>
          <?php endif; ?>
          <span class="hero-meta-item"><i class="fas fa-map-marker-alt"></i><?= htmlspecialchars($event['venue'] ?: 'Venue TBA') ?></span>
        </div>
      </div>
      <div class="hero-revenue desk-only">
        <div class="hero-revenue-val">KSh <?= number_format($event_revenue) ?></div>
        <div class="hero-revenue-label">Total Revenue</div>
      </div>
    </div>
  </div>

  <!-- ── Stats Grid ── -->
  <div class="stats-grid">
    <div class="stat-card sc-green">
      <div class="stat-label">Revenue</div>
      <div class="stat-value mono">KSh <?= number_format($event_revenue) ?></div>
      <div class="stat-icon-chip"><i class="fas fa-coins"></i></div>
    </div>
    <div class="stat-card sc-blue">
      <div class="stat-label">Tickets Sold</div>
      <div class="stat-value"><?= $completed_count ?></div>
      <div class="stat-icon-chip"><i class="fas fa-ticket-alt"></i></div>
    </div>
    <div class="stat-card sc-indigo">
      <div class="stat-label">Checked In</div>
      <div class="stat-value" id="checkedInStat"><?= $checked_in_count ?></div>
      <div class="stat-icon-chip"><i class="fas fa-clipboard-check"></i></div>
    </div>
    <div class="stat-card sc-amber">
      <div class="stat-label">Pending</div>
      <div class="stat-value"><?= $pending_count ?></div>
      <div class="stat-icon-chip"><i class="fas fa-hourglass-half"></i></div>
    </div>
    <div class="stat-card sc-red">
      <div class="stat-label">Failed</div>
      <div class="stat-value"><?= $failed_count ?></div>
      <div class="stat-icon-chip"><i class="fas fa-times-circle"></i></div>
    </div>
  </div>

  <!-- ── QR Scan Banner ── -->
  <?php if ($checkin_allowed): ?>
  <div class="scan-banner active">
    <div class="scan-banner-left">
      <div class="scan-banner-icon"><i class="fas fa-qrcode"></i></div>
      <div>
        <h3>QR Code Check-In — Active</h3>
        <p>Use your phone camera to scan attendee tickets at the door and check them in instantly</p>
      </div>
    </div>
    <a href="scan_checkin.php?event_id=<?= $event_id ?>" class="btn-scan-lg">
      <i class="fas fa-camera"></i> Open QR Scanner
    </a>
  </div>
  <?php else: ?>
  <div class="scan-banner inactive">
    <div class="scan-banner-left">
      <div class="scan-banner-icon"><i class="fas fa-lock"></i></div>
      <div>
        <h3>QR Scanning Locked</h3>
        <p>Scanning activates automatically at midnight on <strong style="color:#fbbf24"><?= $event_date_formatted ?></strong></p>
      </div>
    </div>
    <div class="btn-scan-disabled"><i class="fas fa-lock"></i> Locked Until Event Day</div>
  </div>
  <?php endif; ?>

  <!-- ── Tickets Panel ── -->
  <div class="panel">

    <!-- Panel Header -->
    <div class="panel-header">
      <div>
        <div class="panel-title">
          <i class="fas fa-users"></i>
          Ticket Buyers &amp; Check-in
        </div>
      </div>
      <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
        <span class="record-badge" id="recordBadge"><?= count($purchases) ?> record<?= count($purchases) !== 1 ? 's' : '' ?></span>
        <a href="export_tickets_excel.php?event_id=<?= $event_id ?>" class="action-btn btn-green" style="padding:7px 14px;font-size:.75rem">
          <i class="fas fa-file-excel"></i> Export to Excel
        </a>
      </div>
    </div>

    <?php if (empty($purchases)): ?>
      <div class="empty-state">
        <div class="empty-icon"><i class="fas fa-ticket-alt"></i></div>
        <h4 style="font-size:1rem;font-weight:700;margin-bottom:6px">No Tickets Purchased Yet</h4>
        <p style="font-size:.875rem;color:var(--text-muted)">Ticket sales will appear here once buyers complete checkout.</p>
      </div>

    <?php else: ?>

      <!-- Toolbar: Search + Per-page -->
      <div class="toolbar">
        <div class="search-box">
          <i class="fas fa-search"></i>
          <input type="text" id="searchInput" class="search-input"
                 placeholder="Search name, email, phone, ticket #, type…" autocomplete="off">
        </div>
        <button id="clearSearchBtn" title="Clear"><i class="fas fa-times"></i> Clear</button>
        <span class="search-meta" id="searchMeta"></span>
        <div style="display:flex;align-items:center;gap:8px;margin-left:auto">
          <label style="font-size:.72rem;color:var(--text-muted);font-weight:600">Per page:</label>
          <select class="per-page-select" id="perPageSelect">
            <option value="10" selected>10</option>
            <option value="25">25</option>
            <option value="50">50</option>
            <option value="100">100</option>
          </select>
        </div>
      </div>

      <!-- Desktop Table -->
      <div class="desk-only table-scroll" style="display:block">
        <table id="ticketsTable">
          <thead>
            <tr>
              <th class="sn-cell">#</th>
              <th>Purchased</th>
              <th>Buyer</th>
              <th>Email</th>
              <th>Phone</th>
              <th>Ticket No.</th>
              <th>Type</th>
              <th>Status</th>
              <th style="text-align:center">Checked In</th>
              <th style="text-align:right">Amount</th>
              <th style="text-align:center">Actions</th>
            </tr>
          </thead>
          <tbody id="ticketsTableBody">
            <?php foreach ($purchases as $i => $p):
              $st = strtolower($p['status'] ?? 'pending');
              $can = ($st === 'completed');
              $ci  = (int)$p['is_checked_in'];
            ?>
            <tr class="search-row"
                data-index="<?= $i ?>"
                data-name="<?= htmlspecialchars(strtolower($p['buyer_name'] ?? ''), ENT_QUOTES) ?>"
                data-email="<?= htmlspecialchars(strtolower($p['buyer_email'] ?? ''), ENT_QUOTES) ?>"
                data-phone="<?= htmlspecialchars(strtolower($p['buyer_phone'] ?? ''), ENT_QUOTES) ?>"
                data-ticket="<?= htmlspecialchars(strtolower($p['ticket_number'] ?? ''), ENT_QUOTES) ?>"
                data-type="<?= htmlspecialchars(strtolower($p['ticket_type_name'] ?? 'general'), ENT_QUOTES) ?>">
              <td class="sn-cell"><?= $i + 1 ?></td>
              <td class="date-cell"><?= date('M j, Y', strtotime($p['created_at'])) ?><br><?= date('g:i A', strtotime($p['created_at'])) ?></td>
              <td><span class="buyer-name-cell searchable-name"><?= htmlspecialchars($p['buyer_name'] ?? '—') ?></span></td>
              <td style="font-size:.78rem;color:var(--text-muted)"><span class="searchable-email"><?= htmlspecialchars($p['buyer_email'] ?? '—') ?></span></td>
              <td style="font-size:.78rem;color:var(--text-muted)"><span class="searchable-phone"><?= htmlspecialchars($p['buyer_phone'] ?? '—') ?></span></td>
              <td><span class="ticket-num-cell searchable-ticket"><?= htmlspecialchars($p['ticket_number'] ?? '—') ?></span></td>
              <td><span class="badge badge-type searchable-type"><?= htmlspecialchars($p['ticket_type_name'] ?? 'General') ?></span></td>
              <td><span class="badge badge-<?= $st ?>"><?= ucfirst($st) ?></span></td>
              <td style="text-align:center">
                <?php if ($can && $checkin_allowed): ?>
                  <button class="checkin-toggle <?= $ci ? 'yes' : 'no' ?>"
                          data-id="<?= $p['id'] ?>" data-checked="<?= $ci ?>">
                    <?= $ci ? '<i class="fas fa-check"></i> Yes' : '<i class="fas fa-times"></i> No' ?>
                  </button>
                <?php elseif ($can && !$checkin_allowed): ?>
                  <span class="badge badge-checkin-disabled"
                        data-tip="Unlocks <?= $event_date_formatted ?>">Locked</span>
                <?php else: ?>
                  <span class="badge badge-checkin-disabled">—</span>
                <?php endif; ?>
              </td>
              <td class="amount-cell">KSh <?= number_format((float)$p['amount_paid']) ?></td>
              <td>
                <div class="row-actions">
                  <a href="?event_id=<?= $event_id ?>&action=download_pdf&id=<?= $p['id'] ?>"
                     class="icon-btn icon-btn-blue" data-tip="Download PDF">
                    <i class="fas fa-print"></i>
                  </a>
                  <?php if ($st === 'failed'): ?>
                    <button class="icon-btn icon-btn-red send-reminder-btn"
                            data-id="<?= $p['id'] ?>" data-tip="Send retry reminder">
                      <i class="fas fa-envelope"></i>
                    </button>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <div id="noResultsDesk">
          <i class="fas fa-search-minus"></i>
          <p>No tickets match your search. Try a different keyword.</p>
        </div>
      </div>

      <!-- Mobile Cards -->
      <div class="mob-only mobile-cards" id="mobileCards" style="display:flex">
        <?php foreach ($purchases as $i => $p):
          $st  = strtolower($p['status'] ?? 'pending');
          $can = ($st === 'completed');
          $ci  = (int)$p['is_checked_in'];
        ?>
        <div class="m-card <?= $st ?> search-row"
             data-index="<?= $i ?>"
             data-name="<?= htmlspecialchars(strtolower($p['buyer_name'] ?? ''), ENT_QUOTES) ?>"
             data-email="<?= htmlspecialchars(strtolower($p['buyer_email'] ?? ''), ENT_QUOTES) ?>"
             data-phone="<?= htmlspecialchars(strtolower($p['buyer_phone'] ?? ''), ENT_QUOTES) ?>"
             data-ticket="<?= htmlspecialchars(strtolower($p['ticket_number'] ?? ''), ENT_QUOTES) ?>"
             data-type="<?= htmlspecialchars(strtolower($p['ticket_type_name'] ?? 'general'), ENT_QUOTES) ?>">

          <span class="m-sn"># <?= $i + 1 ?></span>
          <div class="m-card-top">
            <div class="m-buyer searchable-name"><?= htmlspecialchars($p['buyer_name'] ?? '—') ?></div>
            <div class="m-amount">KSh <?= number_format((float)$p['amount_paid']) ?></div>
          </div>
          <div class="m-date"><?= date('M j, Y g:i A', strtotime($p['created_at'])) ?></div>

          <div class="m-grid">
            <div class="m-field">
              <div class="m-field-label">Ticket #</div>
              <div class="m-field-val ticket-num-cell searchable-ticket" style="font-size:.75rem"><?= htmlspecialchars($p['ticket_number'] ?? '—') ?></div>
            </div>
            <div class="m-field">
              <div class="m-field-label">Type</div>
              <div class="m-field-val"><span class="badge badge-type searchable-type"><?= htmlspecialchars($p['ticket_type_name'] ?? 'General') ?></span></div>
            </div>
            <div class="m-field">
              <div class="m-field-label">Status</div>
              <div class="m-field-val"><span class="badge badge-<?= $st ?>"><?= ucfirst($st) ?></span></div>
            </div>
            <div class="m-field">
              <div class="m-field-label">Checked In</div>
              <div class="m-field-val">
                <?php if ($can && $checkin_allowed): ?>
                  <button class="checkin-toggle <?= $ci ? 'yes' : 'no' ?>"
                          data-id="<?= $p['id'] ?>" data-checked="<?= $ci ?>" style="width:100%">
                    <?= $ci ? '<i class="fas fa-check"></i> Yes' : '<i class="fas fa-times"></i> No' ?>
                  </button>
                <?php elseif ($can && !$checkin_allowed): ?>
                  <span class="badge badge-checkin-disabled" style="font-size:.65rem">Locked</span>
                <?php else: ?>
                  <span class="badge badge-checkin-disabled">—</span>
                <?php endif; ?>
              </div>
            </div>
          </div>

          <div class="m-info">
            <span><b>Email:</b> <span class="searchable-email"><?= htmlspecialchars($p['buyer_email'] ?? '—') ?></span></span>
            <span><b>Phone:</b> <span class="searchable-phone"><?= htmlspecialchars($p['buyer_phone'] ?? '—') ?></span></span>
          </div>

          <div class="m-actions">
            <a href="?event_id=<?= $event_id ?>&action=download_pdf&id=<?= $p['id'] ?>" class="m-btn m-btn-blue">
              <i class="fas fa-print"></i> Print
            </a>
            <?php if ($st === 'failed'): ?>
              <button class="m-btn m-btn-red send-reminder-btn" data-id="<?= $p['id'] ?>">
                <i class="fas fa-envelope"></i> Send Reminder
              </button>
            <?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>

        <div id="noResultsMob" style="display:none;text-align:center;padding:40px 24px">
          <i class="fas fa-search-minus" style="font-size:2rem;color:var(--text-muted);display:block;margin-bottom:10px"></i>
          <p style="color:var(--text-muted);font-size:.875rem">No tickets match your search.</p>
        </div>
      </div>

      <!-- Pagination -->
      <div class="pagination-wrap" id="paginationWrap">
        <div class="page-info" id="pageInfo"></div>
        <div class="page-btns" id="pageBtns"></div>
      </div>

    <?php endif; ?>
  </div><!-- /panel -->
</div><!-- /page-wrap -->

<!-- Mobile Bottom Nav -->
<nav class="bottom-nav">
  <a href="index.php?page=dashboard" class="nav-item">
    <i class="fas fa-home"></i>Home
  </a>
  <a href="index.php?page=ticket_purchases" class="nav-item active">
    <i class="fas fa-ticket-alt"></i>Tickets
  </a>

  <?php if ($checkin_allowed): ?>
    <a href="scan_checkin.php?event_id=<?= $event_id ?>" class="nav-fab active-scan">
      <div class="nav-fab-icon"><i class="fas fa-qrcode"></i></div>
      <span>Scan QR</span>
    </a>
  <?php else: ?>
    <div class="nav-fab disabled-scan" title="Unlocks <?= $event_date_formatted ?>">
      <div class="nav-fab-icon"><i class="fas fa-lock"></i></div>
      <span>Locked</span>
    </div>
  <?php endif; ?>

  <a href="export_tickets_excel.php?event_id=<?= $event_id ?>" class="nav-item">
    <i class="fas fa-file-excel"></i>Export
  </a>
  <a href="index.php?page=my_wallet" class="nav-item">
    <i class="fas fa-wallet"></i>Wallet
  </a>
</nav>

<script>
// ── Preloader ──
window.addEventListener('load', () => {
  const p = document.getElementById('preloader');
  p.style.opacity = '0';
  setTimeout(() => p.style.display = 'none', 400);
});

// ── Search + Pagination ──
(function () {
  const searchInput  = document.getElementById('searchInput');
  const clearBtn     = document.getElementById('clearSearchBtn');
  const searchMeta   = document.getElementById('searchMeta');
  const perPageSel   = document.getElementById('perPageSelect');
  const pageInfo     = document.getElementById('pageInfo');
  const pageBtns     = document.getElementById('pageBtns');
  const noResultsD   = document.getElementById('noResultsDesk');
  const noResultsM   = document.getElementById('noResultsMob');
  const recordBadge  = document.getElementById('recordBadge');

  const allRows = Array.from(document.querySelectorAll('.search-row'));
  const total   = allRows.length;
  let filtered  = allRows.slice();
  let perPage   = parseInt(perPageSel?.value || 10);
  let curPage   = 1;

  function render() {
    const totalPages = Math.max(1, Math.ceil(filtered.length / perPage));
    curPage = Math.min(curPage, totalPages);
    const start   = (curPage - 1) * perPage;
    const end     = start + perPage;
    const visible = new Set(filtered.slice(start, end));

    allRows.forEach(r => r.style.display = visible.has(r) ? '' : 'none');

    // Re-number SNs
    let n = start + 1;
    filtered.slice(start, end).forEach(row => {
      const snCells = row.querySelectorAll('.sn-cell, .m-sn');
      snCells.forEach(c => {
        if (c.classList.contains('m-sn')) c.textContent = '# ' + n;
        else c.textContent = n;
      });
      n++;
    });

    const empty = filtered.length === 0;
    if (noResultsD) noResultsD.style.display = empty ? 'block' : 'none';
    if (noResultsM) noResultsM.style.display = empty ? 'block' : 'none';

    if (recordBadge) {
      const n2 = filtered.length;
      recordBadge.textContent = n2 + ' record' + (n2 !== 1 ? 's' : '');
    }
    if (searchMeta) {
      const q = searchInput?.value.trim() || '';
      searchMeta.textContent = q ? filtered.length + ' of ' + total + ' result' + (filtered.length !== 1 ? 's' : '') : '';
    }
    if (pageInfo) {
      if (!filtered.length) { pageInfo.innerHTML = 'No results'; }
      else {
        const s = start + 1, e = Math.min(end, filtered.length);
        pageInfo.innerHTML = 'Showing <strong>' + s + '–' + e + '</strong> of <strong>' + filtered.length + '</strong>';
      }
    }
    if (pageBtns) {
      pageBtns.innerHTML = '';
      if (totalPages <= 1) return;
      const mk = (lbl, pg, active, dis) => {
        const b = document.createElement('button');
        b.className = 'page-btn' + (active ? ' active' : '');
        b.innerHTML = lbl; b.disabled = dis;
        if (!dis && !active) b.onclick = () => { curPage = pg; render(); };
        return b;
      };
      pageBtns.appendChild(mk('<i class="fas fa-chevron-left"></i>', curPage - 1, false, curPage === 1));
      let pages = [];
      if (totalPages <= 7) pages = Array.from({length: totalPages}, (_, i) => i + 1);
      else {
        pages = [1];
        if (curPage > 3) pages.push('…');
        for (let p = Math.max(2, curPage - 1); p <= Math.min(totalPages - 1, curPage + 1); p++) pages.push(p);
        if (curPage < totalPages - 2) pages.push('…');
        pages.push(totalPages);
      }
      pages.forEach(p => {
        if (p === '…') {
          const d = document.createElement('span');
          d.textContent = '…'; d.style.cssText = 'padding:0 4px;color:var(--text-muted);font-size:.8rem;line-height:32px';
          pageBtns.appendChild(d);
        } else pageBtns.appendChild(mk(p, p, p === curPage, false));
      });
      pageBtns.appendChild(mk('<i class="fas fa-chevron-right"></i>', curPage + 1, false, curPage === totalPages));
    }
  }

  function doSearch() {
    const q = (searchInput?.value || '').trim().toLowerCase();
    filtered = q
      ? allRows.filter(r => [r.dataset.name, r.dataset.email, r.dataset.phone, r.dataset.ticket, r.dataset.type].some(v => v && v.includes(q)))
      : allRows.slice();
    curPage = 1;
    if (clearBtn) clearBtn.style.display = q ? 'inline-flex' : 'none';

    // Clear previous highlights
    document.querySelectorAll('.highlight').forEach(h => { h.outerHTML = h.innerHTML; });

    // Apply highlights
    if (q) {
      filtered.forEach(row => {
        ['.searchable-name', '.searchable-email', '.searchable-phone', '.searchable-ticket', '.searchable-type'].forEach(sel => {
          row.querySelectorAll(sel).forEach(cell => {
            if (cell.textContent.toLowerCase().includes(q)) {
              const re = new RegExp(`(${q.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')})`, 'gi');
              cell.innerHTML = cell.innerHTML.replace(re, '<span class="highlight">$1</span>');
            }
          });
        });
      });
    }
    render();
  }

  if (searchInput) {
    searchInput.addEventListener('input', doSearch);
    searchInput.addEventListener('keydown', e => { if (e.key === 'Escape') { searchInput.value = ''; doSearch(); } });
  }
  if (clearBtn) clearBtn.addEventListener('click', () => { if (searchInput) searchInput.value = ''; doSearch(); searchInput?.focus(); });
  if (perPageSel) perPageSel.addEventListener('change', () => { perPage = parseInt(perPageSel.value); curPage = 1; render(); });

  render();
})();

// ── Check-in Toggle ──
document.querySelectorAll('.checkin-toggle').forEach(btn => {
  btn.addEventListener('click', async function () {
    if (this.disabled) return;
    const id  = this.dataset.id;
    const was = parseInt(this.dataset.checked);
    const now = was ? 0 : 1;
    this.disabled = true;
    const old = this.innerHTML;
    this.innerHTML = '<span style="display:inline-block;width:12px;height:12px;border:2px solid currentColor;border-top-color:transparent;border-radius:50%;animation:spin .7s linear infinite"></span>';

    try {
      const res  = await fetch(location.href, {
        method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ action: 'toggle_checkin', purchase_id: id, new_value: now })
      });
      const data = await res.json();
      if (data.success) {
        this.dataset.checked = now;
        this.className = 'checkin-toggle ' + (now ? 'yes' : 'no') + (this.style.width ? '' : '');
        this.innerHTML = now ? '<i class="fas fa-check"></i> Yes' : '<i class="fas fa-times"></i> No';
        if (this.style.width) this.style.width = '100%';

        // Update stat card
        const stat = document.getElementById('checkedInStat');
        if (stat) stat.textContent = parseInt(stat.textContent) + (now ? 1 : -1);

        Swal.fire({
          icon: now ? 'success' : 'info', title: now ? '✅ Checked In' : '↩ Unchecked',
          toast: true, position: 'top-end', timer: 1800,
          showConfirmButton: false, background: now ? '#f0fdf4' : '#f8fafc'
        });
      } else { throw new Error(data.message || 'Failed'); }
    } catch (err) {
      this.innerHTML = old;
      Swal.fire({ icon: 'error', title: 'Error', text: err.message, confirmButtonColor: '#b81236' });
    } finally { this.disabled = false; }
  });
});

// ── Failure Reminder ──
document.querySelectorAll('.send-reminder-btn').forEach(btn => {
  btn.addEventListener('click', async function () {
    if (this.disabled) return;
    const id  = this.dataset.id;
    this.disabled = true;
    const old = this.innerHTML;
    this.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending…';

    try {
      const res  = await fetch(location.href, {
        method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ action: 'send_failure_reminder', purchase_id: id })
      });
      const data = await res.json();
      if (data.success) {
        Swal.fire({ icon: 'success', title: 'Sent!', text: 'Reminder email sent to the buyer.', toast: true, position: 'top-end', timer: 3000, showConfirmButton: false });
      } else throw new Error(data.message || 'Could not send reminder');
    } catch (err) {
      Swal.fire({ icon: 'error', title: 'Failed', text: err.message, confirmButtonColor: '#b81236' });
    } finally { this.disabled = false; this.innerHTML = old; }
  });
});
</script>
</body>
</html>
<?php ob_end_flush(); ?>