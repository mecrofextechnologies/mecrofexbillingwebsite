<?php
// Start session to communicate with support.php
session_start();

$input = file_get_contents('php://input');
$callback = json_decode($input, true);

// Log raw callback
file_put_contents('mpesa_log.txt', date('Y-m-d H:i:s') . " CALLBACK: " . $input . PHP_EOL, FILE_APPEND);

if (!isset($callback['Body']['stkCallback']['CheckoutRequestID'])) {
    http_response_code(400);
    exit(json_encode(['ResultCode' => 1]));
}

$stk = $callback['Body']['stkCallback'];
$checkoutRequestID = $stk['CheckoutRequestID'];
$resultCode        = $stk['ResultCode'];
$resultDesc        = $stk['ResultDesc'];

require_once 'msanii/includes/connect.php';

// ============================================
// 1. FAILED / CANCELLED PAYMENT
// ============================================
if ($resultCode != 0) {
    // User cancelled, insufficient funds, timeout, etc.
    file_put_contents('mpesa_log.txt', date('Y-m-d H:i:s') . " FAILED: $checkoutRequestID | $resultDesc" . PHP_EOL, FILE_APPEND);

    // Optional: Mark as failed so support.php can show "Transaction Failed"
    try {
        $pdo->prepare("UPDATE support SET status = 'failed' WHERE checkout_request_id = ? AND status = 'pending'")
            ->execute([$checkoutRequestID]);
    } catch (Exception $e) {
        // Ignore if row not found
    }

    // Clear any pending session for this checkout
    if (isset($_SESSION['pending_checkout']) && $_SESSION['pending_checkout'] === $checkoutRequestID) {
        unset($_SESSION['pending_checkout']);
        $_SESSION['payment_failed'] = true; // Tell support.php to show failed message
    }

    header('Content-Type: application/json');
    echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
    exit;
}

// ============================================
// 2. SUCCESSFUL PAYMENT
// ============================================
$amount = $receipt = $phone = $transDate = null;
foreach ($stk['CallbackMetadata']['Item'] ?? [] as $item) {
    switch ($item['Name']) {
        case 'Amount':             $amount    = $item['Value']; break;
        case 'MpesaReceiptNumber': $receipt   = $item['Value']; break;
        case 'PhoneNumber':        $phone     = substr($item['Value'], -9); break;
        case 'TransactionDate':    $transDate = $item['Value']; break;
    }
}

// Format date: 20251125165825 → 2025-11-25 16:58:25
$transaction_date = '';
if ($transDate && strlen($transDate) == 14) {
    $transaction_date = substr($transDate,0,4).'-'.substr($transDate,4,2).'-'.substr($transDate,6,2).' '.
                        substr($transDate,8,2).':'.substr($transDate,10,2).':'.substr($transDate,12,2);
}

try {
    $sql = "UPDATE support SET
                amount           = :amount,
                mpesa_receipt    = :receipt,
                phone            = COALESCE(:phone, phone),
                transaction_date = :transaction_date,
                transaction_id   = :receipt,
                status           = 'completed'
            WHERE checkout_request_id = :checkout
              AND (mpesa_receipt IS NULL OR mpesa_receipt = '')";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':amount'           => $amount,
        ':receipt'          => $receipt,
        ':phone'            => $phone,
        ':transaction_date' => $transaction_date,
        ':checkout'         => $checkoutRequestID
    ]);

    if ($stmt->rowCount() > 0) {
        file_put_contents('mpesa_log.txt', date('Y-m-d H:i:s') . " SUCCESS → UPDATED: $checkoutRequestID | Receipt: $receipt | KES $amount" . PHP_EOL, FILE_APPEND);

        // === TELL support.php THIS USER JUST PAID SUCCESSFULLY ===
        if (isset($_SESSION['pending_checkout']) && $_SESSION['pending_checkout'] === $checkoutRequestID) {
            // Find creator_id from this transaction
            $stmt2 = $pdo->prepare("SELECT creator_id FROM support WHERE checkout_request_id = ?");
            $stmt2->execute([$checkoutRequestID]);
            $row = $stmt2->fetch();

            $_SESSION['paid_creator'] = $row['creator_id'] ?? null;
            $_SESSION['receipt']      = $receipt;

            // Clean up
            unset($_SESSION['pending_checkout'], $_SESSION['payment_failed']);
        }
    } else {
        file_put_contents('mpesa_log.txt', date('Y-m-d H:i:s') . " NO UPDATE (already processed): $checkoutRequestID" . PHP_EOL, FILE_APPEND);
    }

} catch (Exception $e) {
    file_put_contents('mpesa_log.txt', date('Y-m-d H:i:s') . " DB ERROR: " . $e->getMessage() . PHP_EOL, FILE_APPEND);
}

// Always acknowledge Safaricom
header('Content-Type: application/json');
echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);