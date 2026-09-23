<?php
/* Daraja B2C QueueTimeOutURL. Safaricom calls this when a payout request
   timed out in its queue. That is not a definitive failure, so the
   withdrawal stays 'processing' with its funds still reserved; it is
   settled by a later result callback or by an admin reconciliation on
   admin011/approve_withdrawal.php. Refunding here could pay out twice. */

include 'msanii/includes/connect.php';
require_once 'msanii/includes/withdrawal_payout.php';

header('Content-Type: application/json');

if (!kc_verify_webhook_token($_GET['key'] ?? null)) {
    kc_payment_log('b2c_callback', 'Timeout rejected: missing/invalid webhook token');
    http_response_code(403);
    exit;
}

$payload    = json_decode(file_get_contents('php://input'), true);
$result     = $payload['Result'] ?? $payload ?? [];
$originator = (string)($result['OriginatorConversationID'] ?? '');
$convId     = (string)($result['ConversationID'] ?? '');

kc_payment_log('b2c_callback', "Queue timeout originator=$originator conv=$convId");

try {
    kc_wd_ensure_schema($pdo);
    $pdo->prepare("
        UPDATE withdrawal_requests
        SET failure_reason = 'M-Pesa queue timeout — awaiting confirmation / admin reconciliation'
        WHERE payout_method = 'b2c' AND status = 'processing'
          AND (reference = ? OR (? <> '' AND conversation_id = ?))
    ")->execute([$originator, $convId, $convId]);
} catch (Throwable $e) {
    kc_payment_log('b2c_callback', 'Timeout processing error: ' . get_class($e));
}

echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
