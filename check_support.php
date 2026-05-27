<?php
header('Content-Type: application/json');
include 'msanii/includes/connect.php';

$creator_id = $_GET['creator'] ?? '';
if (!is_numeric($creator_id)) {
    echo json_encode(['paid'=>false,'failed'=>false]);
    exit;
}

// Fetch latest payment for this creator
$stmt = $pdo->prepare("SELECT status, mpesa_receipt FROM support WHERE creator_id = ? ORDER BY id DESC LIMIT 1");
$stmt->execute([$creator_id]);
$latest = $stmt->fetch(PDO::FETCH_ASSOC);

$response = [
    'paid' => false,
    'failed' => false,
    'receipt' => ''
];

if ($latest) {
    if ($latest['status'] === 'paid') {
        $response['paid'] = true;
        $response['receipt'] = $latest['mpesa_receipt'];
    } elseif ($latest['status'] === 'failed') {
        $response['failed'] = true;
    }
}

echo json_encode($response);
