<?php
/* Daraja B2C ResultURL — final outcome of an automatic creator withdrawal
   (see msanii/includes/withdrawal_payout.php for the state machine).

   Public URL: authenticated by the shared webhook secret appended to the
   ResultURL when the payout was sent. Idempotent: Safaricom retries, and
   every state change below is status-guarded, so a duplicate callback is
   acknowledged without touching balances or re-sending the SMS. */

include 'msanii/includes/connect.php';
require_once 'msanii/includes/withdrawal_payout.php';

header('Content-Type: application/json');
$ack = fn() => print(json_encode(['ResultCode' => 0, 'ResultDesc' => 'Accepted']));

if (!kc_verify_webhook_token($_GET['key'] ?? null)) {
    kc_payment_log('b2c_callback', 'Rejected: missing/invalid webhook token from ' . ($_SERVER['REMOTE_ADDR'] ?? '?'));
    http_response_code(403);
    exit;
}

$payload = json_decode(file_get_contents('php://input'), true);
$result  = $payload['Result'] ?? null;
if (!is_array($result)) {
    kc_payment_log('b2c_callback', 'Invalid payload: missing Result');
    http_response_code(400);
    exit;
}

$resultCode = (string)($result['ResultCode'] ?? '');
$resultDesc = substr((string)($result['ResultDesc'] ?? ''), 0, 200);
$originator = (string)($result['OriginatorConversationID'] ?? '');
$convId     = (string)($result['ConversationID'] ?? '');

// ResultParameter is a list, or a single object when there is only one.
$params = $result['ResultParameters']['ResultParameter'] ?? [];
if (isset($params['Key'])) $params = [$params];
$kv = [];
foreach ((array)$params as $p) {
    if (isset($p['Key'])) $kv[$p['Key']] = $p['Value'] ?? null;
}
$receipt = (string)($kv['TransactionReceipt'] ?? $result['TransactionID'] ?? '');
$paidAmt = isset($kv['TransactionAmount']) ? (float)$kv['TransactionAmount'] : null;

// Stored for audit — deliberately excludes ReceiverPartyPublicName (PII).
$raw = substr(json_encode([
    'code' => $resultCode, 'desc' => $resultDesc, 'conv' => $convId,
    'receipt' => $receipt, 'amount' => $paidAmt,
    'completed' => $kv['TransactionCompletedDateTime'] ?? null,
]), 0, 1000);

kc_payment_log('b2c_callback', "Result originator=$originator conv=$convId code=$resultCode receipt=$receipt");

try {
    kc_wd_ensure_schema($pdo);

    $find = $pdo->prepare("
        SELECT id, amount FROM withdrawal_requests
        WHERE payout_method = 'b2c' AND (reference = ? OR (? <> '' AND conversation_id = ?))
        LIMIT 1
    ");
    $find->execute([$originator, $convId, $convId]);
    $wd = $find->fetch(PDO::FETCH_ASSOC);

    if (!$wd) {
        kc_payment_log('b2c_callback', "No withdrawal matches originator=$originator conv=$convId");
        $ack();
        exit;
    }
    $id = (int)$wd['id'];
    if ($convId !== '') {
        $pdo->prepare("UPDATE withdrawal_requests SET conversation_id = COALESCE(conversation_id, ?) WHERE id = ?")->execute([$convId, $id]);
    }

    if ($resultCode === '0') {
        if ($paidAmt !== null && abs($paidAmt - (float)$wd['amount']) > 0.01) {
            kc_payment_log('b2c_callback', "WARNING #$id amount mismatch: requested {$wd['amount']} paid $paidAmt");
        }
        if (kc_wd_mark_success($pdo, $id, $receipt, $raw)) {
            $ack();  // Respond to Safaricom before the (slower) SMS call.
            if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
            kc_wd_send_success_sms($pdo, $id);
            exit;
        }
    } else {
        kc_wd_mark_failed($pdo, $id, 'M-Pesa: ' . ($resultDesc ?: "result code $resultCode"), $resultCode, $raw);
    }
} catch (Throwable $e) {
    kc_payment_log('b2c_callback', 'Processing error: ' . get_class($e) . ' ' . substr($e->getMessage(), 0, 200));
    http_response_code(500);  // Let Safaricom retry; every step is idempotent.
    exit;
}

$ack();
