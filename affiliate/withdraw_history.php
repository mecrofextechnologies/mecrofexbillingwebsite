<?php
session_start();
include 'includes/connect.php';

// Ensure employee is logged in
if (!isset($_SESSION['employee_id'])) {
    echo "<div class='alert alert-danger'>You must be logged in to view this page.</div>";
    exit();
}

$employee_id = $_SESSION['employee_id'];

// Fetch approved withdrawals for this employee
$stmt = $pdo->prepare("SELECT * FROM my_withdrawal_history 
                       WHERE employee_id = ? AND status = 1 
                       ORDER BY date_approved DESC");
$stmt->execute([$employee_id]);
$withdrawals = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Approved Withdrawals</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/css/adminlte.min.css">
</head>
<body>
<div class="container mt-4">

    <!-- Header -->
    <div class="card shadow-sm">
        <div class="card-header bg-success text-white">
            <h3 class="card-title"><i class="fas fa-check-circle"></i> Approved Withdrawals</h3>
        </div>
        <div class="card-body table-responsive">
            <table class="table table-bordered table-hover">
                <thead class="thead-dark">
                    <tr>
                        <th>#</th>
                        <th>Amount (KSh)</th>
                        <th>Requested On</th>
                        <th>Receipt No.</th>
                        <th>Date Approved</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($withdrawals && count($withdrawals) > 0): ?>
                    <?php $i = 1; foreach ($withdrawals as $row): ?>
                        <tr>
                            <td><?= $i++; ?></td>
                            <td>KSh <?= number_format($row['amount'], 2); ?></td>
                            <td><?= date('d M Y, h:i A', strtotime($row['requested_on'])); ?></td>
                            <td><?= !empty($row['receipt_no']) ? htmlspecialchars($row['receipt_no']) : '—'; ?></td>
                            <td><?= !empty($row['date_approved']) ? date('d M Y, h:i A', strtotime($row['date_approved'])) : '—'; ?></td>
                            <td>
                                <a href="download_receipt.php?id=<?= $row['id']; ?>" class="btn btn-sm btn-primary">
                                    <i class="fas fa-file-pdf"></i> Download Receipt
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="6" class="text-center text-muted">No approved withdrawals found</td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>
</body>
</html>
