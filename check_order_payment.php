<?php
// check_order_payment.php — called by JS polling on the waiting page
session_start();
include 'msanii/includes/connect.php';

header('Content-Type: application/json');

if (empty($_SESSION['pending_order_checkout'])) {
    echo json_encode(['success' => false]); exit;
}

$cid  = $_SESSION['pending_order_checkout'];
$stmt = $pdo->prepare("SELECT payment_status FROM preorder_purchases WHERE payment_ref = ?");
$stmt->execute([$cid]);
$row  = $stmt->fetch(PDO::FETCH_ASSOC);

if ($row && $row['payment_status'] === 'completed') {
    echo json_encode(['success' => true]);
} elseif ($row && $row['payment_status'] === 'failed') {
    echo json_encode(['success' => false, 'failed' => true]);
} else {
    echo json_encode(['success' => false]);
}
