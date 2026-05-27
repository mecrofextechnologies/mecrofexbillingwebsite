<?php
include '../includes/connect.php';

$success_message = '';
$error_message = '';
$editing = false;
$edit_data = [];

// Detect editing
if (isset($_GET['edit_id'])) {
    $edit_id = $_GET['edit_id'];
    $editing = true;
    $stmt = $pdo->prepare("SELECT * FROM faculty WHERE id = :id");
    $stmt->execute(['id' => $edit_id]);
    $edit_data = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Handle insert/update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit'])) {
    $faculty_name = $_POST['faculty_name'] ?? '';
    $entry_grade = $_POST['entry_grade'] ?? '';
    $duration = $_POST['duration'] ?? '';
    $fee = $_POST['fee'] ?? '';
    $description = $_POST['description'] ?? '';
    $link = $_POST['link'] ?? '';
    $edit_id = $_POST['edit_id'] ?? null;

    $profile_image_path = null;

    // Handle image
    if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] === 0) {
        $ext = strtolower(pathinfo($_FILES['profile_image']['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'gif'];

        if (in_array($ext, $allowed)) {
            $new_name = uniqid('', true) . '.' . $ext;
            $upload_dir = '../uploads_folder/';
            $upload_path = $upload_dir . $new_name;

            if (move_uploaded_file($_FILES['profile_image']['tmp_name'], $upload_path)) {
                $profile_image_path = $upload_path;
            } else {
                $error_message = "Image upload failed.";
            }
        } else {
            $error_message = "Invalid image format.";
        }
    }

    if (empty($error_message)) {
        try {
            if ($edit_id) {
                // Update
                $sql = "UPDATE faculty SET 
                        faculty_name = :faculty_name,
                        entry_grade = :entry_grade,
                        duration = :duration,
                        fee = :fee,
                        description = :description,
                        link = :link";

                if ($profile_image_path) {
                    $sql .= ", profile_image = :profile_image";
                }

                $sql .= " WHERE id = :id";

                $params = [
                    'faculty_name' => $faculty_name,
                    'entry_grade' => $entry_grade,
                    'duration' => $duration,
                    'fee' => $fee,
                    'description' => $description,
                    'link' => $link,
                    'id' => $edit_id
                ];

                if ($profile_image_path) {
                    $params['profile_image'] = $profile_image_path;
                }

                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $success_message = "Record updated successfully!";
                $editing = false;
                $edit_data = [];
            } else {
                // Insert
                $check = $pdo->prepare("SELECT COUNT(*) FROM faculty WHERE faculty_name = :faculty_name");
                $check->execute(['faculty_name' => $faculty_name]);

                if ($check->fetchColumn() > 0) {
                    $error_message = "Faculty already exists.";
                } else {
                    $stmt = $pdo->prepare("INSERT INTO faculty 
                        (faculty_name, entry_grade, duration, fee, description, link, profile_image) 
                        VALUES (:faculty_name, :entry_grade, :duration, :fee, :description, :link, :profile_image)");
                    $stmt->execute([
                        'faculty_name' => $faculty_name,
                        'entry_grade' => $entry_grade,
                        'duration' => $duration,
                        'fee' => $fee,
                        'description' => $description,
                        'link' => $link,
                        'profile_image' => $profile_image_path
                    ]);
                    $success_message = "New course added.";
                }
            }
        } catch (PDOException $e) {
            $error_message = "DB Error: " . $e->getMessage();
        }
    }
}

// Handle delete
if (isset($_POST['delete_record'])) {
    $recordId = $_POST['record_id'];
    try {
        $stmt = $pdo->prepare("DELETE FROM faculty WHERE id = :id");
        $stmt->execute(['id' => $recordId]);
        $success_message = "Course deleted.";
    } catch (PDOException $e) {
        $error_message = "Delete failed: " . $e->getMessage();
    }
}

// Fetch all records
try {
    $stmt = $pdo->prepare("SELECT * FROM faculty");
    $stmt->execute();
    $facultyRecords = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error_message = "Error fetching courses: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Course Management</title>
    <link href="https://maxcdn.bootstrapcdn.com/bootstrap/5.1.3/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.css" rel="stylesheet">
</head>
<body>
<div class="container mt-5">
    <div class="card">
        <div class="card-body">
            <h4><?php echo $editing ? 'Edit Course' : 'Add Course'; ?></h4>
            <form method="post" enctype="multipart/form-data" action="index.php?page=courses<?php echo $editing ? '&edit_id=' . $edit_data['id'] : ''; ?>">
                <?php if ($editing): ?>
                    <input type="hidden" name="edit_id" value="<?php echo $edit_data['id']; ?>">
                <?php endif; ?>
                <div class="mb-3">
                    <label class="form-label">Course Name</label>
                    <input type="text" name="faculty_name" class="form-control" required value="<?php echo $editing ? htmlspecialchars($edit_data['faculty_name']) : ''; ?>">
                </div>
                <div class="mb-3">
                    <label class="form-label">Entry Grade</label>
                    <input type="text" name="entry_grade" class="form-control" required value="<?php echo $editing ? htmlspecialchars($edit_data['entry_grade']) : ''; ?>">
                </div>
                <div class="mb-3">
                    <label class="form-label">Duration</label>
                    <input type="text" name="duration" class="form-control" required value="<?php echo $editing ? htmlspecialchars($edit_data['duration']) : ''; ?>">
                </div>
                <div class="mb-3">
                    <label class="form-label">Fee</label>
                    <input type="text" name="fee" class="form-control" value="<?php echo $editing ? htmlspecialchars($edit_data['fee']) : ''; ?>">
                </div>
                <div class="mb-3">
                    <label class="form-label">Description</label>
                    <textarea name="description" class="form-control" required><?php echo $editing ? htmlspecialchars($edit_data['description']) : ''; ?></textarea>
                </div>
                <div class="mb-3">
                    <label class="form-label">Link</label>
                    <input type="url" name="link" class="form-control" value="<?php echo $editing ? htmlspecialchars($edit_data['link']) : ''; ?>">
                </div>
                <div class="mb-3">
                    <label class="form-label">Profile Image</label>
                    <input type="file" name="profile_image" class="form-control" accept="image/*">
                </div>
                <button type="submit" name="submit" class="btn btn-<?php echo $editing ? 'warning' : 'primary'; ?>">
                    <?php echo $editing ? 'Update' : 'Submit'; ?>
                </button>
            </form>

            <hr>
            <h5>Courses List</h5>
            <?php if ($facultyRecords): ?>
                <table class="table table-bordered">
                    <thead>
                    <tr>
                        <th>ID</th>
                        <th>Course</th>
                        <th>Grade</th>
                        <th>Duration</th>
                        <th>Fee</th>
                        <th>Description</th>
                        <th>Link</th>
                        <th>Image</th>
                        <th>Actions</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($facultyRecords as $record): ?>
                        <tr>
                            <td><?php echo $record['id']; ?></td>
                            <td><?php echo $record['faculty_name']; ?></td>
                            <td><?php echo $record['entry_grade']; ?></td>
                            <td><?php echo $record['duration']; ?></td>
                            <td><?php echo $record['fee']; ?></td>
                            <td><?php echo $record['description']; ?></td>
                            <td><?php echo $record['link']; ?></td>
                            <td>
                                <img src="<?php echo $record['profile_image'] ?: '../assets/img/profile.png'; ?>" width="50" height="50" style="object-fit: cover;">
                            </td>
                            <td>
                                <form method="get" style="display:inline;">
                                    <input type="hidden" name="page" value="courses">
                                    <input type="hidden" name="edit_id" value="<?php echo $record['id']; ?>">
                                    <button class="btn btn-sm btn-warning">Edit</button>
                                </form>
                                <form method="post" style="display:inline;" onsubmit="return confirm('Delete this course?');">
                                    <input type="hidden" name="record_id" value="<?php echo $record['id']; ?>">
                                    <button type="submit" name="delete_record" class="btn btn-sm btn-danger">Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p>No courses available.</p>
            <?php endif; ?>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.js"></script>
<script>
    <?php if ($success_message): ?>
    toastr.success("<?php echo $success_message; ?>");
    <?php elseif ($error_message): ?>
    toastr.error("<?php echo $error_message; ?>");
    <?php endif; ?>
</script>
</body>
</html>
