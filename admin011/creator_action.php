<?php
// creator_action.php
include '../msanii/includes/connect.php';

header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Only admins (roles 1 or 2)
if (!isset($_SESSION['user_role']) || !in_array($_SESSION['user_role'], [1, 2])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$action     = $_POST['action']     ?? '';
$creator_id = (int)($_POST['creator_id'] ?? 0);

if ($creator_id < 1 || $action !== 'delete') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

try {
    $pdo->beginTransaction();

    // 1. Get creator details before deletion
    $stmt = $pdo->prepare("SELECT full_name, email FROM creators WHERE id = ?");
    $stmt->execute([$creator_id]);
    $creator = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$creator) {
        throw new Exception("Creator not found");
    }

    $full_name = trim($creator['full_name'] ?? 'Creator');
    $email     = trim($creator['email'] ?? '');

    // 2. Delete all related data
    // my_balance
    $pdo->prepare("DELETE FROM my_balance WHERE creator_id = ?")->execute([$creator_id]);

    // my_page
    $pdo->prepare("DELETE FROM my_page WHERE creator_id = ?")->execute([$creator_id]);

    // support
    $pdo->prepare("DELETE FROM support WHERE creator_id = ?")->execute([$creator_id]);

    // surveys
    $pdo->prepare("DELETE FROM surveys WHERE creator_id = ?")->execute([$creator_id]);

    // ticket_purchases (linked via tickets)
    $pdo->prepare("
        DELETE tp FROM ticket_purchases tp
        INNER JOIN tickets t ON tp.ticket_id = t.id
        WHERE t.creator_id = ?
    ")->execute([$creator_id]);

    // tickets
    $pdo->prepare("DELETE FROM tickets WHERE creator_id = ?")->execute([$creator_id]);

    // withdrawal_requests
    $pdo->prepare("DELETE FROM withdrawal_requests WHERE creator_id = ?")->execute([$creator_id]);

    // Finally delete creator
    $pdo->prepare("DELETE FROM creators WHERE id = ?")->execute([$creator_id]);

    // 3. Add email to blacklist (if valid email exists)
    if ($email && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        // Create blacklist table if missing
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS blacklist (
                id          INT AUTO_INCREMENT PRIMARY KEY,
                email       VARCHAR(255) NOT NULL UNIQUE,
                reason      TEXT,
                created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
                created_by  INT DEFAULT NULL,
                INDEX idx_email (email)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        // Insert or ignore if already blacklisted
        $stmt_black = $pdo->prepare("
            INSERT IGNORE INTO blacklist (email, reason, created_by)
            VALUES (?, ?, ?)
        ");
        $stmt_black->execute([
            $email,
            "Permanently removed from platform - account deletion",
            $_SESSION['user_id'] ?? null
        ]);
    }

    // 4. Send deletion email (if email is valid)
    if ($email && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        require 'vendor/autoload.php'; // PHPMailer

        $mail = new PHPMailer\PHPMailer\PHPMailer(true);

        try {
            // === Your SMTP settings from the example you gave ===
            $mail->isSMTP();
            $mail->Host       = 'mail.keychele.co.ke';
            $mail->SMTPAuth   = true;
            $mail->Username   = 'notifications@keychele.co.ke';
            $mail->Password   = 'King20241064.';
            $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = 587;
            $mail->SMTPOptions = [
                'ssl' => [
                    'verify_peer'       => false,
                    'verify_peer_name'  => false,
                    'allow_self_signed' => true
                ]
            ];

            $mail->setFrom('notifications@keychele.co.ke', 'Keychele Platform');
            $mail->addAddress($email, $full_name);

            $mail->isHTML(true);
            $mail->CharSet = 'UTF-8';
            $mail->Subject = 'Account Removal – Keychele Platform';

            $mail->Body = "
            <div style='font-family:Arial,sans-serif; background:#f5f5f5; padding:30px;'>
                <div style='max-width:600px; margin:auto; background:#fff; padding:30px; border-radius:10px; box-shadow:0 4px 15px rgba(0,0,0,0.1);'>
                    <h2 style='color:#b81236; text-align:center;'>Account Removal Notice</h2>
                    <p style='font-size:16px; line-height:1.6;'>
                        Dear <strong>$full_name</strong>,
                    </p>
                    <p style='font-size:16px; line-height:1.6;'>
                        Your account on the <strong>Keychele platform</strong> has been <strong>permanently removed</strong>.
                    </p>
                    <p style='font-size:16px; line-height:1.6;'>
                        You can no longer use our platform or access any associated services.
                    </p>
                    <p style='font-size:16px; line-height:1.6; color:#c53030; font-weight:bold;'>
                        All your data has been permanently erased from our systems.
                    </p>
                    <p style='font-size:16px; line-height:1.6;'>
                        As per our Terms of Service, any remaining balance cannot be refunded.
                    </p>
                    <p style='font-size:16px; line-height:1.6;'>
                        If you believe this action was taken in error, please contact our support team immediately:
                    </p>
                    <p style='text-align:center; margin:30px 0;'>
                        <a href='mailto:support@keychele.co.ke' style='background:#b81236; color:white; padding:12px 30px; text-decoration:none; border-radius:6px; font-weight:bold;'>
                            Contact Support
                        </a>
                    </p>
                    <p style='font-size:14px; color:#666; text-align:center; margin-top:30px;'>
                        Regards,<br>
                        <strong>Keychele Team</strong>
                    </p>
                </div>
            </div>";

            $mail->AltBody = "Dear $full_name,\n\nYour Keychele account has been permanently removed.\nYou can no longer use the platform.\nAll your data has been erased.\nNo balance refunds per Terms of Service.\nIf this was a mistake, contact support@keychele.co.ke\n\nKeychele Team";

            $mail->send();
        } catch (Exception $e) {
            // Email failed – log but do NOT rollback deletion
            error_log("Deletion email failed for $email: " . $mail->ErrorInfo);
        }
    }

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Creator and all associated data permanently deleted'
    ]);

} catch (Exception $e) {
    $pdo->rollBack();

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error during deletion: ' . $e->getMessage()
    ]);
}