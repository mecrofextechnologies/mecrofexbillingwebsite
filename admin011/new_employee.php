<?php
// Start the session, but only if it's not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

include 'includes/connect.php';

$alert = "";
$editing = false;
$editData = [];

// 🔁 Handle Deletion
if (isset($_GET['delete'])) {
    $id = $_GET['delete'];
    $stmt = $pdo->prepare("SELECT employee_id FROM employees WHERE id = ?");
    $stmt->execute([$id]);
    $emp = $stmt->fetch();

    if ($emp) {
        // Deleting employee and related payslips
        $pdo->prepare("DELETE FROM employees WHERE id = ?")->execute([$id]);
        $alert = "success|Employee deleted (related payslips also removed)";
    } else {
        $alert = "error|Employee not found.";
    }
}

// 📝 Handle Edit View
if (isset($_GET['edit'])) {
    $editing = true;
    $id = $_GET['edit'];
    $stmt = $pdo->prepare("SELECT * FROM employees WHERE id = ?");
    $stmt->execute([$id]);
    $editData = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$editData) {
        $editing = false;
        $alert = "error|Employee not found for editing.";
    }
}

// 💾 Handle Save (Add or Update)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save'])) {
    $first_name = $_POST['first_name'];
    $middle_name = $_POST['middle_name'];
    $last_name = $_POST['last_name'];
    $department = $_POST['department'];
    $company = $_POST['company'];
    $location = $_POST['location'];
    $password = !empty($_POST['password']) ? password_hash($_POST['password'], PASSWORD_DEFAULT) : null;
    $email = isset($_POST['email']) ? $_POST['email'] : null; // Safely get email

    if (!empty($_POST['edit_id'])) {
        // UPDATE
        $edit_id = $_POST['edit_id'];
        $sql = "UPDATE employees SET first_name=?, middle_name=?, last_name=?, department=?, company=?, location=?, email=?";
        $params = [$first_name, $middle_name, $last_name, $department, $company, $location, $email];

        if ($password) {
            $sql .= ", password=?";
            $params[] = $password;
        }

        $sql .= " WHERE id=?";
        $params[] = $edit_id;

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $alert = "success|Employee updated successfully.";
        $editing = false;
        $editData = [];

    } else {
        // INSERT
        $employee_id = generateEmployeeId($pdo);
        $account_number = generateAccountNumber($pdo);

        $stmt = $pdo->prepare("INSERT INTO employees 
            (employee_id, account_number, first_name, middle_name, last_name, department, company, location, password, email)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$employee_id, $account_number, $first_name, $middle_name, $last_name, $department, $company, $location, $password, $email]);

        $alert = "success|Employee added successfully.";
    }
}

// 📥 Fetch All Employees
$employees = $pdo->query("SELECT * FROM employees ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);

// Helper functions for generating IDs
function generateEmployeeId($pdo) {
    $prefix = "JP";
    $dateCode = date("dmy");
    do {
        $random = substr(str_shuffle("0123456789"), 0, 2);
        $empId = $prefix . substr($dateCode, -4) . $random; 
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM employees WHERE employee_id = ?");
        $stmt->execute([$empId]);
        $exists = $stmt->fetchColumn();
    } while ($exists > 0);
    return $empId;
}

function generateAccountNumber($pdo) {
    do {
        $accNo = str_pad(rand(0, 999999), 6, "0", STR_PAD_LEFT);
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM employees WHERE account_number = ?");
        $stmt->execute([$accNo]);
        $exists = $stmt->fetchColumn();
    } while ($exists > 0);
    return $accNo;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Manage Employees</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body>
<div class="container mt-5">
  <!-- Form Card -->
  <div class="card mb-4">
    <div class="card-body">
      <h3 class="mb-4 text-primary"><?= $editing ? '✏️ Edit Employee' : '➕ Add New Employee' ?></h3>
      <form method="POST">
        <input type="hidden" name="edit_id" value="<?= $editing ? $editData['id'] : '' ?>">
        <div class="row g-3">
          <div class="col-md-4">
            <label>First Name</label>
            <input type="text" name="first_name" class="form-control" value="<?= $editing ? $editData['first_name'] : '' ?>" required>
          </div>
          <div class="col-md-4">
            <label>Middle Name</label>
            <input type="text" name="middle_name" class="form-control" value="<?= $editing ? $editData['middle_name'] : '' ?>">
          </div>
          <div class="col-md-4">
            <label>Last Name</label>
            <input type="text" name="last_name" class="form-control" value="<?= $editing ? $editData['last_name'] : '' ?>" required>
          </div>
          <div class="col-md-4">
            <label>Department</label>
            <input type="text" name="department" class="form-control" value="<?= $editing ? $editData['department'] : '' ?>" required>
          </div>
          <div class="col-md-4">
            <label>Company</label>
            <input type="text" name="company" class="form-control" value="<?= $editing ? $editData['company'] : '' ?>" required>
          </div>
          <div class="col-md-4">
            <label>Location</label>
            <input type="text" name="location" class="form-control" value="<?= $editing ? $editData['location'] : '' ?>" required>
          </div>
          <div class="col-md-4">
            <label>Email</label>
            <input type="email" name="email" class="form-control" value="<?= $editing ? $editData['email'] : '' ?>" required>
          </div>
          <div class="col-md-4">
            <label><?= $editing ? 'New Password (leave blank to keep current)' : 'Password' ?></label>
            <input type="password" name="password" class="form-control" <?= $editing ? '' : 'required' ?>>
          </div>
        </div>
        <div class="mt-4 text-end">
          <button type="submit" name="save" class="btn btn-<?= $editing ? 'warning' : 'primary' ?>">
            <?= $editing ? 'Update' : 'Save' ?>
          </button>
          <?php if ($editing): ?>
            <a href="index.php?page=new_employee" class="btn btn-secondary">Cancel</a>
          <?php endif; ?>
        </div>
      </form>
    </div>
  </div>

  <!-- Employee List -->
  <div class="card">
    <div class="card-body">
      <h4 class="mb-3">📋 Employee List</h4>
      <div class="table-responsive">
        <table class="table table-bordered align-middle">
          <thead class="table-light">
            <tr>
              <th>ID</th>
              <th>Employee ID</th>
              <th>Name</th>
              <th>Department</th>
              <th>Company</th>
              <th>Location</th>
              <th>Email</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($employees as $emp): ?>
              <tr>
                <td><?= $emp['id'] ?></td>
                <td><?= $emp['employee_id'] ?></td>
                <td><?= htmlspecialchars($emp['first_name'] . ' ' . $emp['middle_name'] . ' ' . $emp['last_name']) ?></td>
                <td><?= $emp['department'] ?></td>
                <td><?= $emp['company'] ?></td>
                <td><?= $emp['location'] ?></td>
                <td><?= $emp['email'] ?></td>
                <td>
                  <a href="index.php?page=new_employee&edit=<?= $emp['id'] ?>" class="btn btn-sm btn-warning">Edit</a>
                  <a href="index.php?page=new_employee&delete=<?= $emp['id'] ?>" class="btn btn-sm btn-danger" onclick="return confirm('Delete employee and all related payslips?')">Delete</a>
                </td>
              </tr>
            <?php endforeach; ?>
            <?php if (empty($employees)): ?>
              <tr><td colspan="8" class="text-center">No employees found.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<!-- Alert Script -->
<?php if (!empty($alert)): 
  list($type, $msg) = explode("|", $alert); ?>
<script>
Swal.fire({
  icon: '<?= $type ?>',
  title: '<?= ucfirst($type) ?>',
  text: '<?= $msg ?>'
});
</script>
<?php endif; ?>
</body>
</html>
