```php
<?php
// Database connection
include 'includes/connect.php';

$success_message = '';
$error_message   = '';

// Add Deposit
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_deposit'])) {
    $transaction_id = strtoupper(uniqid("TXN"));
    $description    = trim($_POST['description']);
    $amount         = (float)$_POST['amount'];

    if ($amount > 0) {
        try {
            // Insert deposit (created_at auto handled by MySQL)
            $stmt = $pdo->prepare("INSERT INTO group_deposits 
                (transaction_id, description, amount) 
                VALUES (:transaction_id, :description, :amount)");
            $stmt->execute([
                'transaction_id' => $transaction_id,
                'description'    => $description,
                'amount'         => $amount
            ]);

            // Update group balance
            $check = $pdo->query("SELECT balance FROM group_balance LIMIT 1")->fetch();
            if ($check) {
                $pdo->prepare("UPDATE group_balance SET balance = balance + :amount")
                    ->execute(['amount' => $amount]);
            } else {
                $pdo->prepare("INSERT INTO group_balance (balance) VALUES (:amount)")
                    ->execute(['amount' => $amount]);
            }

            $success_message = "Deposit of KSh $amount added successfully!";
        } catch (Exception $e) {
            $error_message = "Database error: " . $e->getMessage();
        }
    } else {
        $error_message = "Amount must be greater than 0!";
    }
}

// Delete deposit
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $stmt = $pdo->prepare("SELECT amount FROM group_deposits WHERE id = :id");
    $stmt->execute(['id' => $id]);
    $deposit = $stmt->fetch();

    if ($deposit) {
        $amount = $deposit['amount'];
        $pdo->prepare("DELETE FROM group_deposits WHERE id = :id")->execute(['id' => $id]);
        $pdo->prepare("UPDATE group_balance SET balance = balance - :amount")->execute(['amount' => $amount]);
        $success_message = "Deposit deleted and balance updated!";
    }
}

// Fetch deposits (order by auto timestamp)
$deposits = $pdo->query("SELECT * FROM group_deposits ORDER BY created_at DESC")->fetchAll();

// Fetch balance
$balance = $pdo->query("SELECT balance FROM group_balance LIMIT 1")->fetchColumn() ?: 0;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Group Deposits</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body class="bg-light">
<div class="container py-5">

    <!-- Group Balance -->
    <div class="alert alert-info text-center shadow-sm">
        <h4 class="mb-0">💰 Group Balance: <strong>KSh <?= number_format($balance, 2); ?></strong></h4>
    </div>

    <!-- Add Deposit Form -->
    <div class="card shadow mb-4">
        <div class="card-header bg-primary text-white">
            <h5 class="mb-0">➕ Add Deposit</h5>
        </div>
        <div class="card-body">
            <form method="post">
                <div class="row g-3">
                    <div class="col-md-8">
                        <div class="form-floating">
                            <input type="text" name="description" class="form-control" placeholder="Description (optional)">
                            <label>Description (optional)</label>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-floating">
                            <input type="number" step="0.01" name="amount" class="form-control" placeholder="Amount" required>
                            <label>Amount (KSh)</label>
                        </div>
                    </div>
                </div>
                <button type="submit" name="add_deposit" class="btn btn-success mt-3 w-100">💵 Add Deposit</button>
            </form>
        </div>
    </div>

    <!-- Deposits Table -->
    <div class="card shadow">
        <div class="card-header bg-dark text-white">
            <h5 class="mb-0">📋 Deposit Records</h5>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-striped table-hover table-bordered mb-0">
                    <thead class="table-secondary text-center">
                        <tr>
                            <th>ID</th>
                            <th>Transaction ID</th>
                            <th>Description</th>
                            <th>Amount (KSh)</th>
                            <th>Date</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($deposits) > 0): ?>
                            <?php foreach ($deposits as $d): ?>
                                <tr class="align-middle text-center">
                                    <td><?= $d['id']; ?></td>
                                    <td class="text-primary fw-bold"><?= htmlspecialchars($d['transaction_id']); ?></td>
                                    <td><?= htmlspecialchars($d['description']); ?></td>
                                    <td class="text-success fw-bold"><?= number_format($d['amount'], 2); ?></td>
                                    <td><?= htmlspecialchars($d['created_at']); ?></td>
                                    <td>
                                        <a href="?delete=<?= $d['id']; ?>" 
                                           onclick="return confirm('Are you sure you want to delete this deposit?');"
                                           class="btn btn-sm btn-danger">
                                           🗑 Delete
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="6" class="text-center text-muted">No deposits found.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</div>

<?php if($success_message): ?>
<script>Swal.fire("Success","<?= htmlspecialchars($success_message); ?>","success");</script>
<?php endif; ?>
<?php if($error_message): ?>
<script>Swal.fire("Error","<?= htmlspecialchars($error_message); ?>","error");</script>
<?php endif; ?>

</body>
</html>
```
