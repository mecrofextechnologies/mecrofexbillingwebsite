<?php
// Database connection
include '../includes/connect.php';

$uploadDir = 'uploads/';
$success_message = '';
$error_message = '';

// Handle insert (new testimonial)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_testimonial'])) {
    $name = trim($_POST['name'] ?? '');
    $profession = trim($_POST['profession'] ?? '');
    $message = trim($_POST['message'] ?? '');
    $file = $_FILES['photo'] ?? null;

    if (!$name || !$profession || !$message) {
        $error_message = "All fields are required.";
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
                $fileName = uniqid('testimonial_', true) . "." . $ext;
                $uploadFile = $uploadDir . $fileName;

                if (move_uploaded_file($file['tmp_name'], $uploadFile)) {
                    $stmt = $pdo->prepare("INSERT INTO client_testimonials (name, profession, message, image, created_at) 
                                           VALUES (:name, :profession, :message, :image, NOW())");
                    $stmt->execute([
                        'name' => $name,
                        'profession' => $profession,
                        'message' => $message,
                        'image' => $fileName
                    ]);
                    $success_message = "Testimonial added successfully!";
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
    $stmt = $pdo->prepare("SELECT image FROM client_testimonials WHERE id = :id");
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch();

    if ($row) {
        if (file_exists($uploadDir . $row['image'])) {
            @unlink($uploadDir . $row['image']);
        }
        $pdo->prepare("DELETE FROM client_testimonials WHERE id = :id")->execute(['id' => $id]);
        $success_message = "Testimonial deleted successfully!";
    }
}

// Handle edit
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_testimonial'])) {
    $id = (int)$_POST['id'];
    $name = trim($_POST['name'] ?? '');
    $profession = trim($_POST['profession'] ?? '');
    $message = trim($_POST['message'] ?? '');
    $file = $_FILES['photo'] ?? null;

    if ($name && $profession && $message) {
        if ($file && $file['error'] === UPLOAD_ERR_OK) {
            $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime  = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);

            if (in_array($mime, $allowed, true)) {
                $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                $fileName = uniqid('testimonial_', true) . "." . $ext;
                $uploadFile = $uploadDir . $fileName;

                if (move_uploaded_file($file['tmp_name'], $uploadFile)) {
                    // Remove old image
                    $stmt = $pdo->prepare("SELECT image FROM client_testimonials WHERE id = :id");
                    $stmt->execute(['id' => $id]);
                    $old = $stmt->fetch();
                    if ($old && file_exists($uploadDir . $old['image'])) {
                        @unlink($uploadDir . $old['image']);
                    }

                    // Update db
                    $pdo->prepare("UPDATE client_testimonials 
                                   SET name=:name, profession=:profession, message=:message, image=:image, updated_at=NOW() 
                                   WHERE id=:id")
                        ->execute([
                            'name' => $name,
                            'profession' => $profession,
                            'message' => $message,
                            'image' => $fileName,
                            'id' => $id
                        ]);
                    $success_message = "Testimonial updated successfully!";
                } else {
                    $error_message = "Failed to upload new image.";
                }
            } else {
                $error_message = "Invalid image file.";
            }
        } else {
            // Update without image
            $pdo->prepare("UPDATE client_testimonials 
                           SET name=:name, profession=:profession, message=:message, updated_at=NOW() 
                           WHERE id=:id")
                ->execute([
                    'name' => $name,
                    'profession' => $profession,
                    'message' => $message,
                    'id' => $id
                ]);
            $success_message = "Testimonial updated successfully (without image change)!";
        }
    } else {
        $error_message = "All fields are required.";
    }
}

// Fetch all testimonials
$stmt = $pdo->query("SELECT * FROM client_testimonials ORDER BY created_at DESC");
$testimonials = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Client Testimonials Management</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        body { background: #f8f9fa; }
        .card-testimonial { 
            box-shadow: 0 4px 10px rgba(0,0,0,0.1); 
            border-radius: 12px; 
            width: 320px; 
        }
        .card-testimonial img { 
            width: 100%; 
            height: 180px; 
            object-fit: cover; 
            border-radius: 8px; 
        }
    </style>
</head>
<body>
<div class="container py-5">

    <!-- Upload Form -->
    <div class="card p-4 shadow-sm mb-4">
        <h4 class="mb-3">Add Testimonial</h4>
        <form method="post" enctype="multipart/form-data">
            <div class="mb-3">
                <input type="text" name="name" class="form-control" placeholder="Client Name" required>
            </div>
            <div class="mb-3">
                <input type="text" name="profession" class="form-control" placeholder="Profession" required>
            </div>
            <div class="mb-3">
                <textarea name="message" class="form-control" rows="3" placeholder="Message" required></textarea>
            </div>
            <div class="mb-3">
                <input type="file" name="photo" accept="image/*" class="form-control" required>
            </div>
            <button type="submit" name="add_testimonial" class="btn btn-primary">Add Testimonial</button>
        </form>
    </div>

    <!-- Display All -->
    <div class="d-flex flex-wrap gap-3">
        <?php foreach ($testimonials as $test): ?>
            <div class="card p-2 card-testimonial">
                <img src="<?= $uploadDir . htmlspecialchars($test['image']); ?>" alt="Client" class="mb-2">
                <h5><?= htmlspecialchars($test['name']); ?></h5>
                <small class="text-muted"><?= htmlspecialchars($test['profession']); ?></small>
                <p><?= htmlspecialchars($test['message']); ?></p>
                <div class="d-flex justify-content-between">
                    <!-- Edit -->
                    <form method="post" enctype="multipart/form-data" style="flex:1; margin-right:5px;">
                        <input type="hidden" name="id" value="<?= $test['id']; ?>">
                        <input type="text" name="name" value="<?= htmlspecialchars($test['name']); ?>" class="form-control mb-1" required>
                        <input type="text" name="profession" value="<?= htmlspecialchars($test['profession']); ?>" class="form-control mb-1" required>
                        <textarea name="message" class="form-control mb-1" required><?= htmlspecialchars($test['message']); ?></textarea>
                        <input type="file" name="photo" accept="image/*" class="form-control mb-1">
                        <button type="submit" name="edit_testimonial" class="btn btn-sm btn-warning w-100">Edit</button>
                    </form>
                    <!-- Delete -->
                    <a href="?delete=<?= $test['id']; ?>" 
                       onclick="return confirm('Are you sure you want to delete this testimonial?');"
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
