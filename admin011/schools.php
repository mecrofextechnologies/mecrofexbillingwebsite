<?php
include '../includes/connect.php';

$success_message = '';
$error_message = '';
$editing = false;
$edit_data = [];
$action_flag = ''; // for JS-based redirect

// Check if editing
if (isset($_GET['edit_id'])) {
    $edit_id = $_GET['edit_id'];
    $editing = true;
    $stmt = $pdo->prepare("SELECT * FROM schools WHERE id = :id");
    $stmt->execute(['id' => $edit_id]);
    $edit_data = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit'])) {
    $school_name = trim($_POST['school_name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $edit_id = $_POST['edit_id'] ?? null;

    if (empty($school_name) || empty($description)) {
        $error_message = "Both school name and description are required.";
    } else {
        try {
            $query = "SELECT COUNT(*) FROM schools WHERE school_name = :school_name";
            $params = ['school_name' => $school_name];

            if ($edit_id) {
                $query .= " AND id != :id";
                $params['id'] = $edit_id;
            }

            $stmt = $pdo->prepare($query);
            $stmt->execute($params);

            if ($stmt->fetchColumn() > 0) {
                $error_message = "A school with that name already exists.";
            } else {
                if ($edit_id) {
                    $stmt = $pdo->prepare("UPDATE schools SET school_name = :school_name, description = :description WHERE id = :id");
                    $stmt->execute([
                        'school_name' => $school_name,
                        'description' => $description,
                        'id' => $edit_id
                    ]);
                    $success_message = "School updated successfully.";
                    $action_flag = 'updated';
                } else {
                    $stmt = $pdo->prepare("INSERT INTO schools (school_name, description) VALUES (:school_name, :description)");
                    $stmt->execute([
                        'school_name' => $school_name,
                        'description' => $description
                    ]);
                    $success_message = "New school added.";
                    $action_flag = 'success';
                }
            }
        } catch (PDOException $e) {
            $error_message = "Database error: " . $e->getMessage();
        }
    }
}

// Handle deletion
if (isset($_POST['delete_record'])) {
    $recordId = $_POST['record_id'];
    try {
        $stmt = $pdo->prepare("DELETE FROM schools WHERE id = :id");
        $stmt->execute(['id' => $recordId]);
        $success_message = "School deleted successfully.";
        $action_flag = 'deleted';
    } catch (PDOException $e) {
        $error_message = "Delete failed: " . $e->getMessage();
    }
}

// Fetch all schools
try {
    $stmt = $pdo->prepare("SELECT * FROM schools ORDER BY id DESC");
    $stmt->execute();
    $schools = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error_message = "Error fetching schools: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>School Management</title>
    <link href="https://maxcdn.bootstrapcdn.com/bootstrap/5.1.3/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.css" rel="stylesheet">
</head>
<body>
<div class="container mt-5">
    <div class="card">
        <div class="card-body">
            <h4><?php echo $editing ? 'Edit School' : 'Add School'; ?></h4>
            <form method="post" action="<?php echo htmlspecialchars($_SERVER['REQUEST_URI']); ?>">
                <?php if ($editing): ?>
                    <input type="hidden" name="edit_id" value="<?php echo $edit_data['id']; ?>">
                <?php endif; ?>
                <div class="mb-3">
                    <label class="form-label">School Name</label>
                    <input type="text" name="school_name" class="form-control" required
                           value="<?php echo $editing ? htmlspecialchars($edit_data['school_name']) : ''; ?>">
                </div>
                <div class="mb-3">
                    <label class="form-label">Description</label>
                    <textarea name="description" class="form-control" required><?php echo $editing ? htmlspecialchars($edit_data['description']) : ''; ?></textarea>
                </div>
                <button type="submit" name="submit" class="btn btn-<?php echo $editing ? 'warning' : 'primary'; ?>">
                    <?php echo $editing ? 'Update' : 'Submit'; ?>
                </button>
            </form>

            <hr>
            <h5>Schools List</h5>
            <?php if ($schools): ?>
                <table class="table table-bordered">
                    <thead>
                    <tr>
                        <th>ID</th>
                        <th>School Name</th>
                        <th>Description</th>
                        <th>Actions</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($schools as $school): ?>
                        <tr>
                            <td><?php echo $school['id']; ?></td>
                            <td><?php echo htmlspecialchars($school['school_name']); ?></td>
                            <td><?php echo htmlspecialchars($school['description']); ?></td>
                            <td>
                                <form method="get" style="display:inline;">
                                    <input type="hidden" name="page" value="schools">
                                    <input type="hidden" name="edit_id" value="<?php echo $school['id']; ?>">
                                    <button class="btn btn-sm btn-warning">Edit</button>
                                </form>
                                <form method="post" style="display:inline;" onsubmit="return confirm('Delete this school?');">
                                    <input type="hidden" name="record_id" value="<?php echo $school['id']; ?>">
                                    <button type="submit" name="delete_record" class="btn btn-sm btn-danger">Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p>No schools found.</p>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Scripts -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.js"></script>
<script>
    <?php if (!empty($error_message)): ?>
    toastr.error("<?php echo $error_message; ?>");
    <?php elseif (!empty($success_message)): ?>
    toastr.success("<?php echo $success_message; ?>");

    // Optional JS redirect to remove POST data from URL
    setTimeout(function () {
        window.location.href = "index.php?page=schools";
    }, 2000);
    <?php endif; ?>
</script>
</body>
</html>
