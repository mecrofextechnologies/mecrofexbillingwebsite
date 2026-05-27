<!-- Include SweetAlert2 & Bootstrap FIRST -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<?php
include 'includes/connect.php';

// Handle approve request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['approve'])) {
    $withdraw_id  = $_POST['withdraw_id'];
    $employee_id  = $_POST['employee_id'];
    $amount       = (float) $_POST['amount'];
    $receipt_no   = strtoupper(uniqid('RCPT'));

    try {
        // Fetch employee balance
        $stmt = $pdo->prepare("SELECT amount FROM my_balance WHERE employee_id = ? LIMIT 1");
        $stmt->execute([$employee_id]);
        $employee_balance = $stmt->fetchColumn();

        if ($employee_balance === false) {
            throw new Exception("Balance record not found for employee $employee_id.");
        }

        // Check employee balance only
        if ($employee_balance < $amount) {
            echo "<script>
                Swal.fire({
                    icon: 'error',
                    title: 'Insufficient Employee Balance',
                    text: 'Employee balance is not enough to approve this withdrawal.'
                }).then(() => {
                    window.location.href='index.php?page=withdrawal_requests';
                });
            </script>";
        } else {
            $pdo->beginTransaction();

            // Approve withdrawal request
            $stmt = $pdo->prepare("UPDATE my_withdrawal_history 
                                   SET status = 1, receipt_no = ?, date_approved = NOW() 
                                   WHERE id = ? AND employee_id = ?");
            $stmt->execute([$receipt_no, $withdraw_id, $employee_id]);

            // Deduct from employee balance only
            $stmt = $pdo->prepare("UPDATE my_balance 
                                   SET amount = amount - ? 
                                   WHERE employee_id = ?");
            $stmt->execute([$amount, $employee_id]);

            $pdo->commit();

            echo "<script>
                Swal.fire({
                    icon: 'success',
                    title: 'Withdrawal Approved',
                    text: 'Request approved and employee balance updated successfully.',
                    confirmButtonColor: '#28a745'
                }).then(() => {
                    window.location.href='index.php?page=withdrawal_requests';
                });
            </script>";
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        echo "<script>
            Swal.fire({
                icon: 'error',
                title: 'Error',
                text: '" . addslashes($e->getMessage()) . "'
            }).then(() => {
                window.location.href='index.php?page=withdrawal_requests';
            });
        </script>";
    }
}
?>

<div class="container mt-4">
    <h2 class="mb-4 text-primary"><i class="bi bi-wallet2"></i> Withdrawal Requests</h2>

    <div class="card shadow-sm">
        <div class="card-header bg-primary text-white">
            Pending Withdrawal Requests
        </div>
        <div class="card-body table-responsive">
            <table class="table table-bordered table-striped">
                <thead class="thead-dark">
                    <tr>
                        <th>#</th>
                        <th>Employee ID</th>
                        <th>Amount (KSh)</th>
                        <th>Requested On</th>
                        <th>Receipt No.</th>
                        <th>Date Approved</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php
                $stmt = $pdo->query("SELECT * FROM my_withdrawal_history WHERE status = 0 ORDER BY requested_on DESC");
                if ($stmt->rowCount() > 0) {
                    $i = 1;
                    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                        echo "<tr>
                                <td>{$i}</td>
                                <td>{$row['employee_id']}</td>
                                <td>KSh " . number_format($row['amount'], 2) . "</td>
                                <td>" . date('d M Y, h:i A', strtotime($row['requested_on'])) . "</td>
                                <td>" . (!empty($row['receipt_no']) ? $row['receipt_no'] : '—') . "</td>
                                <td>" . (!empty($row['date_approved']) ? date('d M Y, h:i A', strtotime($row['date_approved'])) : '—') . "</td>
                                <td>
                                    <form method='POST' style='display:inline-block;'>
                                        <input type='hidden' name='withdraw_id' value='{$row['id']}'>
                                        <input type='hidden' name='employee_id' value='{$row['employee_id']}'>
                                        <input type='hidden' name='amount' value='{$row['amount']}'>
                                        <button type='submit' name='approve' class='btn btn-success btn-sm'>
                                            <i class='bi bi-check-circle'></i> Approve
                                        </button>
                                    </form>
                                </td>
                              </tr>";
                        $i++;
                    }
                } else {
                    echo "<tr><td colspan='7' class='text-center text-muted'>No pending withdrawals</td></tr>";
                }
                ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
