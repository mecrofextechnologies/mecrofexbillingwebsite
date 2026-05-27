<?php
// check_ticket_payment.php
session_start();
include 'msanii/includes/connect.php';
header('Content-Type: application/json');

if (!isset($_SESSION['pending_ticket_checkout'])) {
    echo json_encode(['success' => false, 'status' => 'no_session']);
    exit;
}

$checkout_id = $_SESSION['pending_ticket_checkout'];

$stmt = $pdo->prepare("
    SELECT tp.status, tp.ticket_number, tp.buyer_email, tp.quantity
    FROM ticket_purchases tp
    WHERE tp.checkout_request_id = ?
");
$stmt->execute([$checkout_id]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    // Purchase not found at all — clean up orphaned session key
    unset($_SESSION['pending_ticket_checkout']);
    echo json_encode(['success' => false, 'status' => 'not_found']);
    exit;
}

if ($row['status'] === 'completed') {
    // ── DO NOT unset session here ──
    // The ticket page needs the session intact so it can render
    // the success view on the subsequent location.reload() call.
    // Session is unset inside ticket.php after $just_paid is set.
    echo json_encode([
        'success'       => true,
        'status'        => 'completed',
        'ticket_number' => $row['ticket_number'],
        'buyer_email'   => $row['buyer_email'],
        'quantity'      => (int)$row['quantity'],
    ]);

} elseif ($row['status'] === 'failed') {
    // Safe to clean up — page will reload into the failed state
    unset($_SESSION['pending_ticket_checkout']);
    unset($_SESSION['last_quantity']);
    echo json_encode(['success' => false, 'status' => 'failed']);

} else {
    // Still pending — keep polling
    echo json_encode(['success' => false, 'status' => 'pending']);
}