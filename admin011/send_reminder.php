<?php
header('Content-Type: application/json');

session_start();

if (!isset($_SESSION['user_role']) || !in_array($_SESSION['user_role'], [1, 2])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

require '../vendor/autoload.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

include '../msanii/includes/connect.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$purchase_id = $_POST['purchase_id'] ?? null;

if (!$purchase_id || !is_numeric($purchase_id)) {
    echo json_encode(['success' => false, 'message' => 'Invalid purchase ID']);
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT 
            tp.status,
            tp.buyer_name,
            tp.buyer_email,
            tp.amount_paid,
            tp.failed_reason,
            tp.ticket_id,                     -- added
            t.title AS ticket_title,
            t.id AS ticket_table_id           -- added (the real ticket id)
        FROM ticket_purchases tp
        LEFT JOIN tickets t ON tp.ticket_id = t.id
        WHERE tp.id = ?
    ");
    $stmt->execute([$purchase_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        echo json_encode(['success' => false, 'message' => 'Purchase not found']);
        exit;
    }

    // Normalize status
    $status_clean = trim(strtolower($row['status'] ?? ''));

    if ($status_clean !== 'failed') {
        echo json_encode([
            'success' => false,
            'message' => 'This purchase is not in failed status (found: ' . $status_clean . ')'
        ]);
        exit;
    }

    $buyer_name   = $row['buyer_name'] ?: 'Valued Customer';
    $ticket_title = $row['ticket_title'] ?: 'your selected ticket';
    $amount       = number_format($row['amount_paid'], 2);
    $reason       = $row['failed_reason'] ? htmlspecialchars($row['failed_reason']) : 'an unknown issue';

    // Use ticket_id from tickets table (not purchase id)
    $ticket_id = $row['ticket_table_id'] ?? $row['ticket_id'] ?? 0;
    $link = "https://keychele.co.ke/ticket.php?id=" . urlencode($ticket_id);

    // ────────────────────────────────────────────────
    // Send email
    // ────────────────────────────────────────────────
    $mail = new PHPMailer(true);

    $mail->isSMTP();
    $mail->Host       = 'mail.pioneercenter.co.ke';
    $mail->SMTPAuth   = true;
    $mail->Username   = 'notifications@pioneercenter.co.ke';
    $mail->Password   = 'King20241064.';
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = 587;

    // For development / self-signed certs (remove in production if possible)
    $mail->SMTPOptions = [
        'ssl' => [
            'verify_peer'       => false,
            'verify_peer_name'  => false,
            'allow_self_signed' => true
        ]
    ];

    $mail->setFrom('notifications@pioneercenter.co.ke', 'Keychele Support');
    $mail->addAddress($row['buyer_email']);
    $mail->isHTML(true);
    $mail->Subject = 'Complete Your Ticket Purchase – Keychele';

    $mail->Body = <<<HTML
    <div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;padding:20px 10px;">
        <h2 style="color:#1e40af;">Hello {$buyer_name},</h2>
        
        <p>We noticed that your attempt to purchase <strong>{$ticket_title}</strong> did not complete.</p>
        
        <p><strong>Amount:</strong> KSh {$amount}<br>
        <strong>Reason:</strong> {$reason}</p>
        
        <p style="margin:35px 0;">
            <a href="{$link}" style="background:#3b82f6;color:white;padding:14px 32px;text-decoration:none;border-radius:8px;font-weight:bold;display:inline-block;">
                Complete Your Purchase →
            </a>
        </p>
        
        <p>If you're having trouble or have any questions, feel free to contact our support team:</p>
        <p><strong>Phone:</strong> 0112680521</p>
        
        <p style="margin-top:40px;color:#555;font-size:14px;">
            We're here to help you get your ticket!<br>
            Thank you for choosing Keychele.
        </p>
        
        <hr style="border:none;border-top:1px solid #eee;margin:30px 0;">
        <p style="color:#999;font-size:13px;text-align:center;">
            © 2026 Keychele. All rights reserved.
        </p>
    </div>
    HTML;

    $mail->send();

    echo json_encode([
        'success' => true,
        'message' => 'Reminder email sent successfully'
    ]);
} catch (Exception $e) {
    error_log("Reminder email failed: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Failed to send email. Please try again later.'
    ]);
}