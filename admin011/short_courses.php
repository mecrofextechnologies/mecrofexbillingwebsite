<?php
// Database connection
include '../includes/connect.php';

$uploadDir = 'uploads/';
$success_message = '';
$error_message = '';

// Handle insert (new short course)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_short_course'])) {
    $course_name = trim($_POST['course_name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $file = $_FILES['image'] ?? null;

    if (!$course_name || !$description) {
        $error_message = "Course name and description are required.";
    } else {
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        if ($file && $file['error'] === UPLOAD_ERR_OK) {
            $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime  = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);

            if (in_array($mime, $allowed, true)) {
                $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                $fileName = uniqid('course_', true) . "." . $ext;
                $uploadFile = $uploadDir . $fileName;

                if (move_uploaded_file($file['tmp_name'], $uploadFile)) {
                    // Check duplicate
                    $check = $pdo->prepare("SELECT id FROM short_courses WHERE course_name = :course_name");
                    $check->execute(['course_name' => $course_name]);

                    if ($check->fetch()) {
                        $error_message = "A course with this name already exists!";
                        @unlink($uploadFile);
                    } else {
                        $stmt = $pdo->prepare("INSERT INTO short_courses (course_name, description, image, created_at) 
                                               VALUES (:course_name, :description, :image, NOW())");
                        $stmt->execute([
                            'course_name' => $course_name,
                            'description' => $description,
                            'image' => $fileName
                        ]);
                        $success_message = "Short course added successfully!";
                    }
                } else {
                    $error_message = "Failed to upload image.";
                }
            } else {
                $error_message = "Invalid image file.";
            }
        } else {
            $error_message = "Image upload is required.";
        }
    }
}

// Handle delete
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $stmt = $pdo->prepare("SELECT image FROM short_courses WHERE id = :id");
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch();

    if ($row) {
        if ($row['image'] && file_exists($uploadDir . $row['image'])) {
            @unlink($uploadDir . $row['image']);
        }
        $pdo->prepare("DELETE FROM short_courses WHERE id = :id")->execute(['id' => $id]);
        $success_message = "Short course deleted successfully!";
    }
}

// Handle edit
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_short_course'])) {
    $id = (int)$_POST['id'];
    $course_name = trim($_POST['course_name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $file = $_FILES['image'] ?? null;

    if ($course_name && $description) {
        if ($file && $file['error'] === UPLOAD_ERR_OK) {
            $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime  = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);

            if (in_array($mime, $allowed, true)) {
                $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                $fileName = uniqid('course_', true) . "." . $ext;
                $uploadFile = $uploadDir . $fileName;

                if (move_uploaded_file($file['tmp_name'], $uploadFile)) {
                    // Remove old image
                    $stmt = $pdo->prepare("SELECT image FROM short_courses WHERE id = :id");
                    $stmt->execute(['id' => $id]);
                    $old = $stmt->fetch();
                    if ($old && $old['image'] && file_exists($uploadDir . $old['image'])) {
                        @unlink($uploadDir . $old['image']);
                    }

                    // Update with new image
                    $pdo->prepare("UPDATE short_courses 
                                   SET course_name=:course_name, description=:description, image=:image, updated_at=NOW() 
                                   WHERE id=:id")
                        ->execute([
                            'course_name' => $course_name,
                            'description' => $description,
                            'image' => $fileName,
                            'id' => $id
                        ]);
                    $success_message = "Short course updated successfully!";
                } else {
                    $error_message = "Failed to upload new image.";
                }
            } else {
                $error_message = "Invalid image file.";
            }
        } else {
            // Update without changing image
            $pdo->prepare("UPDATE short_courses 
                           SET course_name=:course_name, description=:description, updated_at=NOW() 
                           WHERE id=:id")
                ->execute([
                    'course_name' => $course_name,
                    'description' => $description,
                    'id' => $id
                ]);
            $success_message = "Short course updated successfully (without image change)!";
        }
    } else {
        $error_message = "Course name and description are required.";
    }
}

// Fetch all records
$stmt = $pdo->query("SELECT * FROM short_courses ORDER BY created_at DESC");
$shortCourses = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Short Courses Management</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        body { background: #f8f9fa; }
        .card-course { 
            box-shadow: 0 4px 10px rgba(0,0,0,0.1); 
            border-radius: 12px; 
            width: 300px; 
        }
        .card-course img {
            width: 100%;
            height: 180px;
            object-fit: cover;
            border-radius: 8px;
        }
    </style>
</head>
<body>
<div class="container py-5">

    <!-- Add Short Course Form -->
    <div class="card p-4 shadow-sm mb-4">
        <h4 class="mb-3">Add Short Course</h4>
        <form method="post" enctype="multipart/form-data">
            <div class="mb-3">
                <input type="text" name="course_name" class="form-control" placeholder="Course Name" required>
            </div>
            <div class="mb-3">
                <textarea name="description" class="form-control" rows="3" placeholder="Description" required></textarea>
            </div>
            <div class="mb-3">
                <input type="file" name="image" accept="image/*" class="form-control" required>
            </div>
            <button type="submit" name="add_short_course" class="btn btn-primary">Add Course</button>
        </form>
    </div>

    <!-- Display All Short Courses -->
    <div class="d-flex flex-wrap gap-3">
        <?php foreach ($shortCourses as $course): ?>
            <div class="card p-3 card-course">
                <?php if (!empty($course['image'])): ?>
                    <img src="<?= $uploadDir . htmlspecialchars($course['image']); ?>" alt="Course Image" class="mb-2">
                <?php endif; ?>
                <h5><?= htmlspecialchars($course['course_name']); ?></h5>
                <p><?= htmlspecialchars($course['description']); ?></p>
                <div class="d-flex justify-content-between">
                    <!-- Edit -->
                    <form method="post" enctype="multipart/form-data" style="flex:1; margin-right:5px;">
                        <input type="hidden" name="id" value="<?= $course['id']; ?>">
                        <input type="text" name="course_name" value="<?= htmlspecialchars($course['course_name']); ?>" class="form-control mb-1" required>
                        <textarea name="description" class="form-control mb-1" required><?= htmlspecialchars($course['description']); ?></textarea>
                        <input type="file" name="image" accept="image/*" class="form-control mb-1">
                        <button type="submit" name="edit_short_course" class="btn btn-sm btn-warning w-100">Edit</button>
                    </form>
                    <!-- Delete -->
                    <a href="?delete=<?= $course['id']; ?>" 
                       onclick="return confirm('Are you sure you want to delete this course?');"
                       class="btn btn-sm btn-danger">Delete</a>
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

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
