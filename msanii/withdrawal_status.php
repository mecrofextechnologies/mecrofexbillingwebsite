<?php
/* Polled by request_withdrawals.php while an automatic payout is in flight.
   Returns only the logged-in creator's own withdrawal, with no provider
   internals. */
include 'includes/connect.php';
require_once 'includes/withdrawal_payout.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$creator_id = (int)($_SESSION['creator_id'] ?? 0);
if (!$creator_id) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Session expired. Please log in again.']);
    exit;
}

$ref = preg_replace('/[^A-Z0-9]/', '', strtoupper((string)($_GET['ref'] ?? '')));

// Recovery: a payout reserved but never dispatched (the original request
// died between reserving and calling M-Pesa). A 'pending' row has provably
// not been sent, and kc_wd_dispatch only sends for the one caller that
// claims pending → processing, so this cannot double-pay.
if ($ref !== '') {
    $stuck = $pdo->prepare("SELECT id FROM withdrawal_requests WHERE creator_id = ? AND reference = ? AND payout_method = 'b2c' AND status = 'pending' AND requested_at < NOW() - INTERVAL 20 SECOND");
    $stuck->execute([$creator_id, $ref]);
    if ($stuckId = $stuck->fetchColumn()) kc_wd_dispatch($pdo, (int)$stuckId);
}

$status = $ref !== '' ? kc_wd_public_status($pdo, $creator_id, $ref) : null;

echo json_encode($status ? ['ok' => true] + $status : ['ok' => false, 'error' => 'Withdrawal not found.']);
