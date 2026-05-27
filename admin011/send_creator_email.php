<?php
// send_creator_email.php
// IMPORTANT: Nothing before <?php — no spaces, no blank lines

header('Content-Type: application/json');

include '../msanii/includes/connect.php';

session_start();

if (!isset($_SESSION['user_role']) || !in_array($_SESSION['user_role'], [1, 2])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

require '../vendor/autoload.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$creator_id = $_POST['creator_id'] ?? null;

if (!$creator_id || !is_numeric($creator_id)) {
    echo json_encode(['success' => false, 'message' => 'Invalid creator ID']);
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT full_name, email, username, phone
        FROM creators
        WHERE id = ?
    ");
    $stmt->execute([$creator_id]);
    $creator = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$creator || empty($creator['email'])) {
        echo json_encode(['success' => false, 'message' => 'Creator not found or no email']);
        exit;
    }

    $name = $creator['full_name'] ?: 'Creator';
    $email = $creator['email'];

    $mail = new PHPMailer(true);

    $mail->isSMTP();
    $mail->Host       = 'mail.pioneercenter.co.ke';
    $mail->SMTPAuth   = true;
    $mail->Username   = 'notifications@pioneercenter.co.ke';
    $mail->Password   = 'King20241064.';
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = 587;

    $mail->SMTPOptions = [
        'ssl' => [
            'verify_peer'       => false,
            'verify_peer_name'  => false,
            'allow_self_signed' => true
        ]
    ];

    $mail->setFrom('notifications@pioneercenter.co.ke', 'Keychele');
    $mail->addAddress($email);
    $mail->isHTML(true);
    $mail->Subject = 'Keychele Creator Account – Welcome / Reminder';

    $mail->Body = <<<HTML
    <div style="font-family:Arial,sans-serif;max-width:600px;margin:auto;padding:30px;">
        <h2 style="color:#1e40af;">Hello {$name},</h2>
        
        <p>Welcome to Keychele! This is your creator account.</p>
        
        <p><strong>Username:</strong> {$creator['username']}<br>
        <strong>Phone:</strong> {$creator['phone']}</p>
        
        <p style="margin:30px 0;">
            <a href="https://keychele.co.ke/login.php" style="background:#3b82f6;color:white;padding:14px 32px;border-radius:8px;text-decoration:none;font-weight:bold;display:inline-block;">
                Login to Your Dashboard →
            </a>
        </p>
        
        <p>If you have any questions or need help, feel free to contact support:</p>
        <p><strong>Phone:</strong> 0791 011 130</p>
        
        <p style="margin-top:40px;color:#555;font-size:14px;">
            Thank you for being part of Keychele!<br>
            Let's create something amazing together.
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
        'message' => 'Email sent successfully to ' . $email
    ]);
} catch (Exception $e) {
    error_log("Creator email failed: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Failed to send email. Please try again later.'
    ]);
}