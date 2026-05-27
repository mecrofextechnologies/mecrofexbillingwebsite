<?php
include '../msanii/includes/connect.php';

// Start session only if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Access control
if (!isset($_SESSION['user_role']) || !in_array($_SESSION['user_role'], [1, 2])) {
    header("Location: ../login.php");
    exit();
}

$user_role = $_SESSION['user_role'];
$success_message = '';
$error_message = '';

// --- HANDLE POST ACTIONS (Edit, Delete, Change Password) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'edit_admin') {
            $admin_id   = intval($_POST['admin_id']);
            $firstname  = trim($_POST['firstname']);
            $middlename = trim($_POST['middlename']);
            $lastname   = trim($_POST['lastname']);
            $contact    = trim($_POST['contact']);
            $email      = trim($_POST['email']);
            $role       = intval($_POST['role']);

            // Basic validation
            if (empty($firstname) || empty($lastname) || empty($email)) {
                throw new Exception("Required fields are missing.");
            }

            $stmt = $pdo->prepare("UPDATE admin SET firstname=?, middlename=?, lastname=?, contact=?, email=?, role=? WHERE admin_id=?");
            $stmt->execute([$firstname, $middlename, $lastname, $contact, $email, $role, $admin_id]);
            $success_message = "Admin details updated successfully.";

        } elseif ($action === 'delete_admin') {
            $admin_id = intval($_POST['admin_id']);
            
            // Prevent self-deletion if applicable
            // if (isset($_SESSION['admin_id']) && $admin_id == $_SESSION['admin_id']) {
            //     throw new Exception("You cannot delete your own account.");
            // }

            $stmt = $pdo->prepare("DELETE FROM admin WHERE admin_id = ?");
            $stmt->execute([$admin_id]);
            $success_message = "Admin deleted successfully.";

        } elseif ($action === 'change_password') {
            $admin_id = intval($_POST['admin_id']);
            $new_password = $_POST['new_password'];
            $confirm_password = $_POST['confirm_password'];

            if (empty($new_password) || strlen($new_password) < 6) {
                throw new Exception("Password must be at least 6 characters long.");
            }
            if ($new_password !== $confirm_password) {
                throw new Exception("Passwords do not match.");
            }

            $hashed_password = password_hash($new_password, PASSWORD_BCRYPT);
            $stmt = $pdo->prepare("UPDATE admin SET password = ? WHERE admin_id = ?");
            $stmt->execute([$hashed_password, $admin_id]);
            $success_message = "Password changed successfully.";
        }
    } catch (Exception $e) {
        $error_message = $e->getMessage();
    }
}

// --- FETCH DATA ---
$sql = "SELECT admin_id, firstname, middlename, lastname, contact, email, role FROM admin";
if ($user_role == 2) {
    $sql .= " WHERE role = 2";
}
$sql .= " ORDER BY lastname, firstname";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $admins = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Analytics
    $total_admins = count($admins);
    $super_count = 0;
    $moderator_count = 0;
    foreach ($admins as $admin) {
        if ($admin['role'] == 1) $super_count++;
        if ($admin['role'] == 2) $moderator_count++;
    }

    // Pagination
    $per_page = 20;
    $current_page = max(1, (int)($_GET['page'] ?? 1));
    $offset = ($current_page - 1) * $per_page;
    $total_pages = ceil($total_admins / $per_page);
    $admins_paginated = array_slice($admins, $offset, $per_page);

} catch (PDOException $e) {
    $error_message = "Database error: " . htmlspecialchars($e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Moderators & Admins • Management</title>

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

  <style>
    body { background-color: #f8f9fc; font-family: system-ui, -apple-system, sans-serif; }
    .card { border: none; box-shadow: 0 4px 20px rgba(0,0,0,0.06); }
    .card-header { background: linear-gradient(135deg, #1e40af, #3b82f6); color: white; }
    .kpi-card { transition: transform 0.25s ease; }
    .kpi-card:hover { transform: translateY(-5px); }
    .role-super { background:#198754; color: white; font-weight: 500; }
    .role-moderator { background:#0dcaf0; color: white; font-weight: 500; }
    .table th { background:#f1f5f9; font-size: 0.84rem; text-transform: uppercase; letter-spacing: 0.4px; }
    .pagination .page-link { color: #1e40af; }
    .pagination .page-item.active .page-link { background-color: #1e40af; border-color: #1e40af; }
  </style>
</head>
<body>

<div class="container py-4">

  <div class="d-flex justify-content-between align-items-center mb-4">
    <h3 class="fw-bold mb-0">
      <i class="fas fa-users-shield me-2"></i> Moderators & Admins
    </h3>
    <div>
      <a href="add_admin.php" class="btn btn-primary">
        <i class="fas fa-plus me-2"></i> Add New Admin
      </a>
    </div>
  </div>

  <?php if ($success_message): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
      <?= $success_message ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
  <?php endif; ?>

  <?php if ($error_message): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
      <?= $error_message ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
  <?php endif; ?>

  <!-- KPI Cards -->
  <div class="row g-4 mb-5">
    <div class="col-xl-4 col-md-6">
      <div class="card kpi-card h-100 border-0">
        <div class="card-body text-center">
          <h6 class="text-muted mb-1">Total Admins</h6>
          <h3 class="fw-bold text-primary"><?= number_format($total_admins) ?></h3>
        </div>
      </div>
    </div>
    <div class="col-xl-4 col-md-6">
      <div class="card kpi-card h-100 border-0">
        <div class="card-body text-center">
          <h6 class="text-muted mb-1">Super Admins</h6>
          <h3 class="fw-bold text-success"><?= number_format($super_count) ?></h3>
        </div>
      </div>
    </div>
    <div class="col-xl-4 col-md-6">
      <div class="card kpi-card h-100 border-0">
        <div class="card-body text-center">
          <h6 class="text-muted mb-1">Moderators</h6>
          <h3 class="fw-bold text-info"><?= number_format($moderator_count) ?></h3>
        </div>
      </div>
    </div>
  </div>

  <!-- Admins Table -->
  <div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
      <h5 class="mb-0">All Admins & Moderators (<?= number_format($total_admins) ?> total)</h5>
      <div style="max-width:320px;">
        <input type="text" id="searchInput" class="form-control form-control-sm" placeholder="Search name, email, phone...">
      </div>
    </div>

    <div class="table-responsive">
      <table class="table table-hover mb-0" id="adminsTable">
        <thead>
          <tr>
            <th>#</th>
            <th>Full Name</th>
            <th>Contact</th>
            <th>Email</th>
            <th>Role</th>
            <th class="text-end">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!empty($admins_paginated)): ?>
            <?php foreach ($admins_paginated as $index => $admin): ?>
              <tr>
                <td class="text-center"><?= $offset + $index + 1 ?></td>
                <td>
                  <strong>
                    <?= htmlspecialchars(ucwords($admin['lastname'])) ?>,
                    <?= htmlspecialchars(ucwords($admin['firstname'])) ?>
                    <?= htmlspecialchars(ucwords($admin['middlename'] ?? '')) ?>
                  </strong>
                </td>
                <td><?= htmlspecialchars('0' . $admin['contact'] ?? '-') ?></td>
                <td><?= htmlspecialchars($admin['email'] ?? '-') ?></td>
                <td>
                  <?php if ($admin['role'] == 1): ?>
                    <span class="badge role-super">Super Admin</span>
                  <?php else: ?>
                    <span class="badge role-moderator">Moderator</span>
                  <?php endif; ?>
                </td>
                <td class="text-end">
                  <div class="btn-group">
                    <button class="btn btn-sm btn-outline-primary edit-btn"
                            data-bs-toggle="modal" data-bs-target="#editModal"
                            data-id="<?= $admin['admin_id'] ?>"
                            data-firstname="<?= htmlspecialchars($admin['firstname']) ?>"
                            data-middlename="<?= htmlspecialchars($admin['middlename']) ?>"
                            data-lastname="<?= htmlspecialchars($admin['lastname']) ?>"
                            data-contact="<?= htmlspecialchars($admin['contact']) ?>"
                            data-email="<?= htmlspecialchars($admin['email']) ?>"
                            data-role="<?= $admin['role'] ?>">
                      <i class="fas fa-edit"></i> Edit
                    </button>
                    <button class="btn btn-sm btn-outline-warning password-btn"
                            data-bs-toggle="modal" data-bs-target="#passwordModal"
                            data-id="<?= $admin['admin_id'] ?>"
                            data-name="<?= htmlspecialchars($admin['firstname'] . ' ' . $admin['lastname']) ?>">
                      <i class="fas fa-key"></i> Pass
                    </button>
                    <button class="btn btn-sm btn-outline-danger delete-btn"
                            data-bs-toggle="modal" data-bs-target="#deleteModal"
                            data-id="<?= $admin['admin_id'] ?>"
                            data-name="<?= htmlspecialchars($admin['firstname'] . ' ' . $admin['lastname']) ?>">
                      <i class="fas fa-trash-alt"></i>
                    </button>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr><td colspan="6" class="text-center py-5 text-muted">No moderators or admins found.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <?php if ($total_pages > 1): ?>
    <div class="card-footer d-flex justify-content-between align-items-center">
      <div class="text-muted">Page <?= $current_page ?> of <?= $total_pages ?></div>
      <nav aria-label="Pagination">
        <ul class="pagination mb-0">
          <li class="page-item <?= $current_page <= 1 ? 'disabled' : '' ?>"><a class="page-link" href="?page=<?= $current_page - 1 ?>">Previous</a></li>
          <?php for ($i = max(1, $current_page - 2); $i <= min($total_pages, $current_page + 2); $i++): ?>
            <li class="page-item <?= $i == $current_page ? 'active' : '' ?>"><a class="page-link" href="?page=<?= $i ?>"><?= $i ?></a></li>
          <?php endfor; ?>
          <li class="page-item <?= $current_page >= $total_pages ? 'disabled' : '' ?>"><a class="page-link" href="?page=<?= $current_page + 1 ?>">Next</a></li>
        </ul>
      </nav>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- Edit Modal -->
<div class="modal fade" id="editModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <form method="POST" class="modal-content">
      <input type="hidden" name="action" value="edit_admin">
      <input type="hidden" name="admin_id" id="editAdminId">
      <div class="modal-header">
        <h5 class="modal-title">Edit Admin Details</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="row g-3">
          <div class="col-md-4">
            <label class="form-label">First Name</label>
            <input type="text" name="firstname" id="editFirstname" class="form-control" required>
          </div>
          <div class="col-md-4">
            <label class="form-label">Middle Name</label>
            <input type="text" name="middlename" id="editMiddlename" class="form-control">
          </div>
          <div class="col-md-4">
            <label class="form-label">Last Name</label>
            <input type="text" name="lastname" id="editLastname" class="form-control" required>
          </div>
          <div class="col-md-6">
            <label class="form-label">Contact</label>
            <input type="text" name="contact" id="editContact" class="form-control" required>
          </div>
          <div class="col-md-6">
            <label class="form-label">Email</label>
            <input type="email" name="email" id="editEmail" class="form-control" required>
          </div>
          <div class="col-md-12">
            <label class="form-label">Role</label>
            <select name="role" id="editRole" class="form-select">
              <option value="1">Super Admin</option>
              <option value="2">Moderator</option>
            </select>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-primary">Save Changes</button>
      </div>
    </form>
  </div>
</div>

<!-- Change Password Modal -->
<div class="modal fade" id="passwordModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form method="POST" class="modal-content">
      <input type="hidden" name="action" value="change_password">
      <input type="hidden" name="admin_id" id="passAdminId">
      <div class="modal-header">
        <h5 class="modal-title">Change Password for <span id="passAdminName"></span></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3">
          <label class="form-label">New Password</label>
          <input type="password" name="new_password" class="form-control" required minlength="6">
        </div>
        <div class="mb-3">
          <label class="form-label">Confirm Password</label>
          <input type="password" name="confirm_password" class="form-control" required minlength="6">
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-warning">Update Password</button>
      </div>
    </form>
  </div>
</div>

<!-- Delete Modal -->
<div class="modal fade" id="deleteModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form method="POST" class="modal-content">
      <input type="hidden" name="action" value="delete_admin">
      <input type="hidden" name="admin_id" id="deleteAdminId">
      <div class="modal-header">
        <h5 class="modal-title text-danger">Confirm Deletion</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        Are you sure you want to delete <strong id="deleteAdminName"></strong>? This action cannot be undone.
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-danger">Delete Permanently</button>
      </div>
    </form>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Search functionality
document.getElementById('searchInput')?.addEventListener('input', function(e) {
  const term = e.target.value.toLowerCase();
  document.querySelectorAll('#adminsTable tbody tr').forEach(row => {
    row.style.display = row.textContent.toLowerCase().includes(term) ? '' : 'none';
  });
});

// Populate Edit Modal
document.querySelectorAll('.edit-btn').forEach(btn => {
  btn.addEventListener('click', function() {
    document.getElementById('editAdminId').value = this.dataset.id;
    document.getElementById('editFirstname').value = this.dataset.firstname;
    document.getElementById('editMiddlename').value = this.dataset.middlename;
    document.getElementById('editLastname').value = this.dataset.lastname;
    document.getElementById('editContact').value = this.dataset.contact;
    document.getElementById('editEmail').value = this.dataset.email;
    document.getElementById('editRole').value = this.dataset.role;
  });
});

// Populate Password Modal
document.querySelectorAll('.password-btn').forEach(btn => {
  btn.addEventListener('click', function() {
    document.getElementById('passAdminId').value = this.dataset.id;
    document.getElementById('passAdminName').textContent = this.dataset.name;
  });
});

// Populate Delete Modal
document.querySelectorAll('.delete-btn').forEach(btn => {
  btn.addEventListener('click', function() {
    document.getElementById('deleteAdminId').value = this.dataset.id;
    document.getElementById('deleteAdminName').textContent = this.dataset.name;
  });
});
</script>
</body>
</html>
