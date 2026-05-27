<?php
include 'includes/connect.php';
?>

<!-- Include SweetAlert2 & Bootstrap -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<div class="container mt-4">
    <h2 class="mb-4 text-success"><i class="bi bi-cash-coin"></i> Group Withdrawals</h2>

    <?php
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['withdraw'])) {
        $reason = trim($_POST['reason']);
        $amount = (float) $_POST['amount'];
        $transaction_cost = (float) $_POST['transaction_cost'];
        $transaction_id = uniqid('wd_');
        $date = date('Y-m-d H:i:s');

        try {
            // Check current balance
            $stmt = $pdo->query("SELECT balance FROM group_balance WHERE id = 1 LIMIT 1");
            $current_balance = $stmt->fetchColumn();

            if ($current_balance === false) {
                throw new Exception("Balance row not found.");
            }

            $total_deduction = $amount + $transaction_cost;

            if ($current_balance < $total_deduction) {
                echo "<script>
                    Swal.fire({
                        icon: 'error',
                        title: 'Insufficient Balance',
                        text: 'Not enough balance to complete withdrawal.'
                    });
                </script>";
            } else {
                // Insert withdrawal
                $stmt = $pdo->prepare("INSERT INTO group_withdrawals 
                    (transaction_id, reason, amount, transaction_cost, date) 
                    VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$transaction_id, $reason, $amount, $transaction_cost, $date]);

                // Update balance
                $stmt = $pdo->prepare("UPDATE group_balance SET balance = balance - ? WHERE id = 1");
                $stmt->execute([$total_deduction]);

                echo "<script>
                    Swal.fire({
                        icon: 'success',
                        title: 'Withdrawal Successful',
                        text: 'Withdrawal of KSh $amount has been processed.',
                        confirmButtonColor: '#28a745'
                    }).then(() => {
                        window.location.href='index.php?page=withdraw';
                    });
                </script>";
            }
        } catch (Exception $e) {
            echo "<script>
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: '" . addslashes($e->getMessage()) . "'
                });
            </script>";
        }
    }
    ?>

    <!-- Withdrawal Form -->
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-success text-white">
            <i class="bi bi-wallet2"></i> Make a Withdrawal
        </div>
        <div class="card-body">
            <form method="POST">
                <div class="form-group">
                    <label>Reason</label>
                    <input type="text" name="reason" class="form-control" required>
                </div>
                <div class="form-group">
                    <label>Amount (KSh)</label>
                    <input type="number" name="amount" step="0.01" min="1" class="form-control" required>
                </div>
                <div class="form-group">
                    <label>Transaction Cost (KSh)</label>
                    <input type="number" name="transaction_cost" step="0.01" min="0" class="form-control" value="0">
                </div>
                <button type="submit" name="withdraw" class="btn btn-success btn-block">
                    <i class="bi bi-cash-stack"></i> Withdraw
                </button>
            </form>
        </div>
    </div>

    <!-- Withdrawal History -->
    <div class="card shadow-sm">
        <div class="card-header bg-success text-white">
            <i class="bi bi-clock-history"></i> Withdrawal History
        </div>
        <div class="card-body table-responsive">
            <table class="table table-bordered table-striped">
                <thead class="thead-dark">
                    <tr>
                        <th>ID</th>
                        <th>Transaction ID</th>
                        <th>Reason</th>
                        <th>Amount</th>
                        <th>Transaction Cost</th>
                        <th>Date</th>
                    </tr>
                </thead>
                <tbody>
                <?php
                $stmt = $pdo->query("SELECT * FROM group_withdrawals ORDER BY date DESC");
                if ($stmt->rowCount() > 0) {
                    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                        echo "<tr>
                                <td>{$row['id']}</td>
                                <td>{$row['transaction_id']}</td>
                                <td>{$row['reason']}</td>
                                <td>KSh " . number_format($row['amount'], 2) . "</td>
                                <td>KSh " . number_format($row['transaction_cost'], 2) . "</td>
                                <td>{$row['date']}</td>
                              </tr>";
                    }
                } else {
                    echo "<tr><td colspan='6' class='text-center text-muted'>No withdrawals yet</td></tr>";
                }
                ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
