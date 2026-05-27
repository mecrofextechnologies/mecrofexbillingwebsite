<?php
include 'includes/connect.php';

// Redirect if not logged in
if (!isset($_SESSION['employee_id'])) {
    echo "<script>window.location.href = 'login.php';</script>";
    exit();
}

$employee_id = $_SESSION['employee_id'];

// SweetAlert placeholders
$success_message = '';
$error_message   = '';

// Handle withdrawal request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['withdraw_amount'])) {
    $amount = floatval($_POST['withdraw_amount']);

    if ($amount <= 0) {
        $error_message = "Invalid withdrawal amount.";
    } else {
        // Check available balance
        $stmt = $pdo->prepare("SELECT SUM(amount) as balance FROM my_balance WHERE employee_id = ?");
        $stmt->execute([$employee_id]);
        $balance = $stmt->fetchColumn() ?? 0;

        if ($amount > $balance) {
            $error_message = "Insufficient balance for this withdrawal.";
        } else {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO my_withdrawal_history (employee_id, amount, status, requested_on) 
                    VALUES (?, ?, 0, NOW())
                ");
                $stmt->execute([$employee_id, $amount]);
                $success_message = "Withdrawal request submitted successfully!";
            } catch (PDOException $e) {
                $error_message = "Database error: " . $e->getMessage();
            }
        }
    }
}

// Fetch stats
$stmt = $pdo->prepare("SELECT SUM(amount) FROM my_balance WHERE employee_id = ?");
$stmt->execute([$employee_id]);
$available_balance = $stmt->fetchColumn() ?? 0;

$stmt = $pdo->prepare("SELECT SUM(amount) FROM my_withdrawal_history WHERE employee_id = ? AND status = 1");
$stmt->execute([$employee_id]);
$total_withdrawals = $stmt->fetchColumn() ?? 0;

$stmt = $pdo->prepare("SELECT COUNT(*) FROM my_withdrawal_history WHERE employee_id = ? AND status = 0");
$stmt->execute([$employee_id]);
$pending_requests = $stmt->fetchColumn() ?? 0;

// Check if `approved_on` column exists in DB
$approved_on_exists = false;
try {
    $check = $pdo->query("SHOW COLUMNS FROM my_withdrawal_history LIKE 'approved_on'");
    if ($check && $check->rowCount() > 0) {
        $approved_on_exists = true;
    }
} catch (PDOException $e) {
    $approved_on_exists = false;
}

// Fetch withdrawal history (conditionally include approved_on)
$query = "
    SELECT id, amount, status, requested_on, receipt_no 
    " . ($approved_on_exists ? ", approved_on " : "") . "
    FROM my_withdrawal_history 
    WHERE employee_id = ? 
    ORDER BY requested_on DESC
";
$stmt = $pdo->prepare($query);
$stmt->execute([$employee_id]);
$withdrawals = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Withdrawal Request</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/css/adminlte.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body>
<div class="container mt-4">

    <!-- Stats -->
    <div class="row">
        <div class="col-lg-4 col-6">
            <div class="small-box bg-info text-white shadow-sm">
                <div class="inner">
                    <h3>KSh <?= number_format($available_balance, 2) ?></h3>
                    <p>Available Balance</p>
                </div>
                <div class="icon"><i class="fas fa-wallet"></i></div>
            </div>
        </div>
        <div class="col-lg-4 col-6">
            <div class="small-box bg-success text-white shadow-sm">
                <div class="inner">
                    <h3>KSh <?= number_format($total_withdrawals, 2) ?></h3>
                    <p>Total Withdrawals</p>
                </div>
                <div class="icon"><i class="fas fa-money-bill-wave"></i></div>
            </div>
        </div>
        <div class="col-lg-4 col-6">
            <div class="small-box bg-warning text-white shadow-sm">
                <div class="inner">
                    <h3><?= $pending_requests ?></h3>
                    <p>Pending Requests</p>
                </div>
                <div class="icon"><i class="fas fa-clock"></i></div>
            </div>
        </div>
    </div>

    <!-- Withdrawal Request Form -->
    <div class="card shadow-sm">
        <div class="card-header bg-primary text-white">
            <h3 class="card-title"><i class="fas fa-paper-plane"></i> Request Withdrawal</h3>
        </div>
        <div class="card-body">
            <form method="POST">
                <div class="form-group">
                    <label for="withdraw_amount">Amount (KSh)</label>
                    <input type="number" step="0.01" name="withdraw_amount" class="form-control" placeholder="Enter amount" required>
                </div>
                <button type="submit" class="btn btn-success"><i class="fas fa-check-circle"></i> Submit Request</button>
            </form>
        </div>
    </div>

    <!-- Withdrawal History -->
    <div class="card mt-4 shadow-sm">
        <div class="card-header bg-secondary text-white">
            <h3 class="card-title"><i class="fas fa-history"></i> Withdrawal History</h3>
        </div>
        <div class="card-body table-responsive">
            <table class="table table-bordered table-hover">
                <thead class="thead-dark">
                    <tr>
                        <th>#</th>
                        <th>Amount (KSh)</th>
                        <th>Status</th>
                        <th>Requested On</th>
                        <th>Receipt No.</th>
                        <th>Date Approved</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($withdrawals): ?>
                        <?php foreach ($withdrawals as $i => $w): ?>
                            <tr>
                                <td><?= $i + 1 ?></td>
                                <td>KSh <?= number_format($w['amount'], 2) ?></td>
                                <td>
                                    <?php if ($w['status'] == 1): ?>
                                        <span class="badge badge-success">Approved</span>
                                    <?php elseif ($w['status'] == 0): ?>
                                        <span class="badge badge-warning">Pending</span>
                                    <?php else: ?>
                                        <span class="badge badge-danger">Rejected</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= date("d M Y, h:i A", strtotime($w['requested_on'])) ?></td>
                                <td><?= (!empty($w['receipt_no'])) ? $w['receipt_no'] : '—' ?></td>
                                <td>
                                    <?php 
                                        if ($approved_on_exists && $w['status'] == 1 && !empty($w['approved_on'])) {
                                            echo date("d M Y, h:i A", strtotime($w['approved_on']));
                                        } else {
                                            echo '—';
                                        }
                                    ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="6" class="text-center">No withdrawals found.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<?php if ($success_message): ?>
<script>
Swal.fire({
    icon: 'success',
    title: 'Success',
    text: '<?= $success_message ?>',
    confirmButtonColor: '#3085d6'
});
</script>
<?php endif; ?>

<?php if ($error_message): ?>
<script>
Swal.fire({
    icon: 'error',
    title: 'Error',
    text: '<?= $error_message ?>',
    confirmButtonColor: '#d33'
});
</script>
<?php endif; ?>

</body>
</html>
