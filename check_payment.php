<?php
// check_payment.php
session_start();
include 'msanii/includes/connect.php';

if (isset($_SESSION['pending_payment'])) {
    $checkout_id = $_SESSION['pending_payment']['checkout_id'];
    $stmt = $pdo->prepare("SELECT amount FROM support WHERE mpesa_receipt = ? AND status = 'completed'");
    $stmt->execute([$checkout_id]);
    
    if ($stmt->rowCount() > 0) {
        $row = $stmt->fetch();
        unset($_SESSION['pending_payment']);
        echo json_encode([
            'success' => true,
            'amount' => $row['amount'],
            'creator_name' => $_SESSION['pending_payment']['creator_name'] ?? 'Creator'
        ]);
    } else {
        echo json_encode(['success' => false]);
    }
} else {
    echo json_encode(['success' => false]);
}
?>