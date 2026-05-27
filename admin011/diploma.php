<?php
// Database connection
include '../includes/connect.php'; // Adjust the path to your actual database connection file

$success_message = '';
$error_message = '';

// Handle form submission for inserting data into the diploma table
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit'])) {
    $program_name = trim($_POST['program_name'] ?? ''); // Retrieve and trim program_name
    $program_overview = trim($_POST['program_overview'] ?? ''); // Retrieve and trim program_overview
    $who_can_join = trim($_POST['who_can_join'] ?? ''); // Retrieve and trim who_can_join

    try {
        // Check if the program_name already exists
        $checkSql = "SELECT COUNT(*) FROM diploma WHERE program_name = :program_name";
        $checkStmt = $pdo->prepare($checkSql);
        $checkStmt->execute(['program_name' => $program_name]);
        $programExists = $checkStmt->fetchColumn();

        if ($programExists) {
            $error_message = "Program name already exists!";
        } else {
            // Insert data into the diploma table
            $sql = "INSERT INTO diploma (program_name, program_overview, who_can_join) 
                    VALUES (:program_name, :program_overview, :who_can_join)";
            $stmt = $pdo->prepare($sql);
            if ($stmt->execute([
                'program_name' => $program_name,
                'program_overview' => $program_overview,
                'who_can_join' => $who_can_join
            ])) {
                $success_message = "New record added successfully!";
            } else {
                $error_message = "Failed to add record. Please try again!";
            }
        }
    } catch (PDOException $e) {
        $error_message = "Database error: " . $e->getMessage();
    }
}

// Handle record deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_record'])) {
    $recordId = $_POST['record_id'];

    try {
        // Delete from database
        $sql = "DELETE FROM diploma WHERE id = :id";
        $stmt = $pdo->prepare($sql);
        if ($stmt->execute(['id' => $recordId])) {
            $success_message = "Record deleted successfully!";
        } else {
            $error_message = "Failed to delete record. Please try again!";
        }
    } catch (PDOException $e) {
        $error_message = "Database error: " . $e->getMessage();
    }
}

// Fetch existing records from the database
try {
    $sql = "SELECT * FROM diploma";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $diplomaRecords = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error_message = "Failed to fetch records: " . $e->getMessage();
}
?>


<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Diploma Program Management</title>
    <link href="https://maxcdn.bootstrapcdn.com/bootstrap/5.1.3/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body>
<div class="container mt-5">
    <div class="col-lg-12">
        <div class="card">
            <div class="card-body">
                <!-- Diploma Program Form -->
                <form action="" method="post">
                    <div class="mb-3">
                        <label for="program_name" class="form-label">Program Name</label>
                        <input type="text" class="form-control" id="program_name" name="program_name" required>
                    </div>
                    <div class="mb-3">
                        <label for="who_can_join" class="form-label">Who Can Join</label>
                        <input type="text" class="form-control" id="who_can_join" name="who_can_join" required>
                    </div>
                    <div class="mb-3">
                        <label for="program_overview" class="form-label">Program Overview</label>
                        <textarea class="form-control" id="program_overview" name="program_overview" rows="3" required></textarea>
                    </div>
                    <button type="submit" name="submit" class="btn btn-primary">Submit</button>
                </form>

                <!-- Display Diploma Program Records -->
                <div class="mt-4">
                    <h5>Diploma Programs</h5>
                    <?php if ($diplomaRecords): ?>
                        <table class="table table-bordered">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Program Name</th>
                                    <th>Program Overview</th>
                                    <th>Who Can Join</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($diplomaRecords as $record): ?>
                                    <tr>
                                        <td><?php echo $record['id']; ?></td>
                                        <td><?php echo $record['program_name']; ?></td>
                                        <td><?php echo $record['program_overview']; ?></td>
                                        <td><?php echo $record['who_can_join']; ?></td>
                                        <td>
                                            <form action="" method="post" onsubmit="return confirm('Are you sure you want to delete this record?');">
                                                <input type="hidden" name="record_id" value="<?php echo $record['id']; ?>">
                                                <button type="submit" name="delete_record" class="btn btn-danger">Delete</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <p>No records found!</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Display success or error messages using SweetAlert
<?php if ($success_message): ?>
    Swal.fire({
        icon: 'success',
        title: 'Success',
        text: '<?php echo $success_message; ?>',
        timer: 3000,
        showConfirmButton: false
    });
<?php elseif ($error_message): ?>
    Swal.fire({
        icon: 'error',
        title: 'Error',
        text: '<?php echo $error_message; ?>',
        timer: 3000,
        showConfirmButton: false
    });
<?php endif; ?>
</script>

</body>
</html>
