```php
<?php
// Database connection
include 'includes/connect.php';

$success_message = '';
$error_message   = '';

// Add payslip
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_payslip'])) {
    $employee_id     = trim($_POST['employee_id']);
    $month           = trim($_POST['month']);
    $year            = (int)$_POST['year'];
    $date            = date('Y-m-d H:i:s');

    $basic_salary    = (float)$_POST['basic_salary'];
    $house_allowance = (float)$_POST['house_allowance'];
    $transport       = (float)$_POST['transport'];
    $overtime        = (float)$_POST['overtime'];

    $tax             = (float)$_POST['tax'];
    $nhif            = (float)$_POST['nhif'];
    $nssf            = (float)$_POST['nssf'];
    $loan            = (float)$_POST['loan'];

    $total_earnings   = $basic_salary + $house_allowance + $transport + $overtime;
    $total_deductions = $tax + $nhif + $nssf + $loan;
    $net_pay          = $total_earnings - $total_deductions;

    if ($employee_id) {
        $stmtEmp = $pdo->prepare("SELECT employee_id, first_name, middle_name, last_name, department, company, location 
                                  FROM employees WHERE employee_id = :empid");
        $stmtEmp->execute(['empid' => $employee_id]);
        $employee = $stmtEmp->fetch();

        if ($employee) {
            $full_name = trim($employee['first_name'].' '.$employee['middle_name'].' '.$employee['last_name']);

            $check = $pdo->prepare("SELECT id FROM payslips WHERE employee_id = :employee_id AND month = :month AND year = :year");
            $check->execute([
                'employee_id' => $employee_id,
                'month'       => $month,
                'year'        => $year
            ]);

            if ($check->rowCount() > 0) {
                $error_message = "Payslip for $full_name in $month $year already exists!";
            } else {
                $stmt = $pdo->prepare("INSERT INTO payslips 
                    (employee_id, employee_name, department, company, location,
                     month, year, month_year, date,
                     basic_salary, house_allowance, transport, overtime,
                     tax, nhif, nssf, loan,
                     total_earnings, total_deductions, net_pay, status, created_at)
                    VALUES 
                    (:employee_id, :employee_name, :department, :company, :location,
                     :month, :year, :month_year, :date,
                     :basic_salary, :house_allowance, :transport, :overtime,
                     :tax, :nhif, :nssf, :loan,
                     :total_earnings, :total_deductions, :net_pay, 'unpaid', NOW())");

                $stmt->execute([
                    'employee_id'     => $employee_id,
                    'employee_name'   => $full_name,
                    'department'      => $employee['department'],
                    'company'         => $employee['company'],
                    'location'        => $employee['location'],
                    'month'           => $month,
                    'year'            => $year,
                    'month_year'      => $month . ' ' . $year,
                    'date'            => $date,
                    'basic_salary'    => $basic_salary,
                    'house_allowance' => $house_allowance,
                    'transport'       => $transport,
                    'overtime'        => $overtime,
                    'tax'             => $tax,
                    'nhif'            => $nhif,
                    'nssf'            => $nssf,
                    'loan'            => $loan,
                    'total_earnings'  => $total_earnings,
                    'total_deductions'=> $total_deductions,
                    'net_pay'         => $net_pay
                ]);

                $success_message = "Payslip added successfully!";
            }
        } else {
            $error_message = "Employee not found!";
        }
    } else {
        $error_message = "Please select an employee.";
    }
}

// Handle Pay action
if (isset($_POST['pay_id'])) {
    $pay_id = $_POST['pay_id'];

    $stmt = $pdo->prepare("SELECT employee_id, net_pay FROM payslips WHERE id = :id AND status='unpaid'");
    $stmt->execute(['id' => $pay_id]);
    $payslip = $stmt->fetch();

    if ($payslip) {
        $employee_id = $payslip['employee_id'];
        $net_pay     = $payslip['net_pay'];

        $check = $pdo->prepare("SELECT * FROM my_balance WHERE employee_id = :employee_id");
        $check->execute(['employee_id' => $employee_id]);

        if ($check->rowCount() > 0) {
            $pdo->prepare("UPDATE my_balance SET amount = amount + :amount WHERE employee_id = :employee_id")
                ->execute(['amount' => $net_pay, 'employee_id' => $employee_id]);
        } else {
            $pdo->prepare("INSERT INTO my_balance (employee_id, amount) VALUES (:employee_id, :amount)")
                ->execute(['employee_id' => $employee_id, 'amount' => $net_pay]);
        }

        $pdo->prepare("UPDATE payslips SET status='paid' WHERE id=:id")->execute(['id'=>$pay_id]);
        $success_message = "Payment processed and balance updated!";
    }
}

// Delete payslip
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $pdo->prepare("DELETE FROM payslips WHERE id = :id")->execute(['id' => $id]);
    $success_message = "Payslip deleted successfully!";
}

// Fetch employees
$employees = $pdo->query("SELECT employee_id, first_name, middle_name, last_name 
                          FROM employees ORDER BY first_name ASC")->fetchAll();

// Fetch payslips
$payslips = $pdo->query("SELECT * FROM payslips ORDER BY created_at DESC")->fetchAll();

$currentYear = date("Y");
$months = ["January","February","March","April","May","June","July","August",
           "September","October","November","December"];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Payslip Management</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body class="bg-light">
<div class="container py-5">

    <!-- Add Payslip Form -->
    <div class="card p-4 shadow mb-4">
        <h4 class="mb-3">Add Employee Payslip</h4>
        <form method="post">
            <div class="row g-2">
                <div class="col-md-6">
                    <label>Select Employee</label>
                    <select name="employee_id" class="form-control" required>
                        <option value="">-- Select Employee --</option>
                        <?php foreach ($employees as $emp): 
                            $display_name = trim($emp['first_name'].' '.$emp['middle_name'].' '.$emp['last_name']); ?>
                            <option value="<?= $emp['employee_id']; ?>">
                                <?= htmlspecialchars($display_name); ?> (<?= $emp['employee_id']; ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label>Month</label>
                    <select name="month" class="form-control" required>
                        <option value="">-- Select Month --</option>
                        <?php foreach ($months as $m): ?>
                            <option value="<?= $m; ?>"><?= $m; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label>Year</label>
                    <input type="text" name="year" class="form-control" value="<?= $currentYear; ?>" readonly>
                </div>
            </div>
            <hr>
            <h6>Earnings</h6>
            <div class="row g-2">
                <div class="col-md-3"><input type="number" name="basic_salary" class="form-control" placeholder="Basic Salary" required></div>
                <div class="col-md-3"><input type="number" name="house_allowance" class="form-control" placeholder="House Allowance"></div>
                <div class="col-md-3"><input type="number" name="transport" class="form-control" placeholder="Transport Allowance"></div>
                <div class="col-md-3"><input type="number" name="overtime" class="form-control" placeholder="Overtime"></div>
            </div>
            <hr>
            <h6>Deductions</h6>
            <div class="row g-2">
                <div class="col-md-3"><input type="number" name="tax" class="form-control" placeholder="Tax (PAYE)"></div>
                <div class="col-md-3"><input type="number" name="nhif" class="form-control" placeholder="NHIF"></div>
                <div class="col-md-3"><input type="number" name="nssf" class="form-control" placeholder="NSSF"></div>
                <div class="col-md-3"><input type="number" name="loan" class="form-control" placeholder="Loan Deduction"></div>
            </div>
            <hr>
            <button type="submit" name="add_payslip" class="btn btn-primary">Add Payslip</button>
        </form>
    </div>

    <!-- Payslips List -->
    <div class="row">
        <?php foreach ($payslips as $p): ?>
            <div class="col-md-6 mb-4">
                <div class="card shadow p-3">
                    <h5>Employee Payslip</h5>
                    <p><b>Month:</b> <?= htmlspecialchars($p['month']); ?> <?= htmlspecialchars($p['year']); ?></p>
                    <p><b>Name:</b> <?= htmlspecialchars($p['employee_name']); ?> (<?= htmlspecialchars($p['employee_id']); ?>)</p>
                    <p><b>Dept:</b> <?= htmlspecialchars($p['department']); ?> | <b>Company:</b> <?= htmlspecialchars($p['company']); ?></p>
                    <p><b>Location:</b> <?= htmlspecialchars($p['location']); ?> | <b>Date:</b> <?= htmlspecialchars($p['date']); ?></p>

                    <table class="table table-sm table-bordered">
                        <thead>
                            <tr><th>Earnings</th><th>Amount</th><th>Deductions</th><th>Amount</th></tr>
                        </thead>
                        <tbody>
                            <tr><td>Basic Salary</td><td><?= $p['basic_salary']; ?></td><td>Tax (PAYE)</td><td><?= $p['tax']; ?></td></tr>
                            <tr><td>House Allowance</td><td><?= $p['house_allowance']; ?></td><td>NHIF</td><td><?= $p['nhif']; ?></td></tr>
                            <tr><td>Transport</td><td><?= $p['transport']; ?></td><td>NSSF</td><td><?= $p['nssf']; ?></td></tr>
                            <tr><td>Overtime</td><td><?= $p['overtime']; ?></td><td>Loan</td><td><?= $p['loan']; ?></td></tr>
                        </tbody>
                    </table>

                    <p><b>Total Earnings:</b> KSh <?= $p['total_earnings']; ?></p>
                    <p><b>Total Deductions:</b> KSh <?= $p['total_deductions']; ?></p>
                    <h5><b>Net Pay:</b> KSh <?= $p['net_pay']; ?></h5>

                    <?php if ($p['status'] === 'paid'): ?>
                        <button class="btn btn-success btn-sm" disabled>Paid</button>
                    <?php else: ?>
                        <form method="post" style="display:inline;">
                            <input type="hidden" name="pay_id" value="<?= $p['id']; ?>">
                            <button type="submit" class="btn btn-primary btn-sm">Pay</button>
                        </form>
                    <?php endif; ?>

                    <a href="?delete=<?= $p['id']; ?>" 
                       onclick="return confirm('Are you sure you want to delete this payslip?');"
                       class="btn btn-danger btn-sm mt-2">Delete</a>
                </div>
            </div>
        <?php endforeach; ?>
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
