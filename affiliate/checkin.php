<?php
ob_start();
session_start();
header('Content-Type: application/json; charset=utf-8');

// ── Temporary debug (REMOVE AFTER TESTING) ──
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$logFile = __DIR__ . '/checkin_errors.log';
function logError($msg) {
    global $logFile;
    file_put_contents($logFile, date('Y-m-d H:i:s') . " - $msg\n", FILE_APPEND);
}

$connect_path = file_exists('msanii/includes/connect.php')
    ? 'msanii/includes/connect.php'
    : (file_exists('includes/connect.php') ? 'includes/connect.php' : null);

if (!$connect_path || !file_exists($connect_path)) {
    logError("Cannot find connect.php in either location");
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server configuration error – contact support']);
    exit;
}

try {
    require_once $connect_path;
} catch (Throwable $e) {
    logError("Failed to include connect.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit;
}

if (!isset($_SESSION['creator_id']) || !is_numeric($_SESSION['creator_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

$creator_id = (int)$_SESSION['creator_id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['action']) || $_POST['action'] !== 'toggle_checkin') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method or action']);
    exit;
}

$purchase_id = (int)($_POST['purchase_id'] ?? 0);
$new_value   = (int)($_POST['new_value'] ?? -1);

if ($purchase_id < 1 || !in_array($new_value, [0, 1])) {
    echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
    exit;
}

try {
    $stmt = $pdo->prepare("
        UPDATE ticket_purchases
        SET is_checked_in = :val
        WHERE id = :pid
          AND ticket_id IN (
              SELECT id FROM tickets WHERE creator_id = :cid
          )
        LIMIT 1
    ");

    $stmt->execute([
        ':val' => $new_value,
        ':pid' => $purchase_id,
        ':cid' => $creator_id
    ]);

    if ($stmt->rowCount() === 1) {
        echo json_encode([
            'success'    => true,
            'checked_in' => $new_value,
            'message'    => $new_value ? 'Checked in' : 'Unchecked'
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Ticket not found, already processed, or you do not have permission'
        ]);
    }
} catch (Throwable $e) {
    logError("Check-in SQL error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error – please try again or contact support'
    ]);
}

ob_end_flush();
exit;