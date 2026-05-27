<?php
// Database connection
include '../includes/connect.php';

$uploadDir = 'uploads/';
$success_message = '';
$error_message = '';

// Handle insert (new training area)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_training_area'])) {
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $file = $_FILES['photo'] ?? null;

    if (!$name || !$description) {
        $error_message = "Name and description are required.";
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
                $fileName = uniqid('training_', true) . "." . $ext;
                $uploadFile = $uploadDir . $fileName;

                if (move_uploaded_file($file['tmp_name'], $uploadFile)) {
                    // Check if name already exists
                    $check = $pdo->prepare("SELECT id FROM training_areas WHERE name = :name");
                    $check->execute(['name' => $name]);

                    if ($check->fetch()) {
                        $error_message = "Training area with this name already exists!";
                        @unlink($uploadFile); // remove uploaded file
                    } else {
                        $stmt = $pdo->prepare("INSERT INTO training_areas (name, description, image, created_at) 
                                               VALUES (:name, :description, :image, NOW())");
                        $stmt->execute([
                            'name' => $name,
                            'description' => $description,
                            'image' => $fileName
                        ]);
                        $success_message = "Training area added successfully!";
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
    $stmt = $pdo->prepare("SELECT image FROM training_areas WHERE id = :id");
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch();

    if ($row) {
        if (file_exists($uploadDir . $row['image'])) {
            @unlink($uploadDir . $row['image']);
        }
        $pdo->prepare("DELETE FROM training_areas WHERE id = :id")->execute(['id' => $id]);
        $success_message = "Training area deleted successfully!";
    }
}

// Handle edit (replace image, name, description)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_training_area'])) {
    $id = (int)$_POST['id'];
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $file = $_FILES['photo'] ?? null;

    if ($name && $description) {
        if ($file && $file['error'] === UPLOAD_ERR_OK) {
            $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime  = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);

            if (in_array($mime, $allowed, true)) {
                $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                $fileName = uniqid('training_', true) . "." . $ext;
                $uploadFile = $uploadDir . $fileName;

                if (move_uploaded_file($file['tmp_name'], $uploadFile)) {
                    // Remove old image
                    $stmt = $pdo->prepare("SELECT image FROM training_areas WHERE id = :id");
                    $stmt->execute(['id' => $id]);
                    $old = $stmt->fetch();
                    if ($old && file_exists($uploadDir . $old['image'])) {
                        @unlink($uploadDir . $old['image']);
                    }

                    // Update db
                    $pdo->prepare("UPDATE training_areas 
                                   SET name=:name, description=:description, image=:image, updated_at=NOW() 
                                   WHERE id=:id")
                        ->execute([
                            'name' => $name,
                            'description' => $description,
                            'image' => $fileName,
                            'id' => $id
                        ]);
                    $success_message = "Training area updated successfully!";
                } else {
                    $error_message = "Failed to upload new image.";
                }
            } else {
                $error_message = "Invalid image file.";
            }
        } else {
            // Update without image
            $pdo->prepare("UPDATE training_areas 
                           SET name=:name, description=:description, updated_at=NOW() 
                           WHERE id=:id")
                ->execute([
                    'name' => $name,
                    'description' => $description,
                    'id' => $id
                ]);
            $success_message = "Training area updated successfully (without image change)!";
        }
    } else {
        $error_message = "Name and description are required.";
    }
}

// Fetch all records
$stmt = $pdo->query("SELECT * FROM training_areas ORDER BY created_at DESC");
$trainingAreas = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Training Areas Management</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        body { background: #f8f9fa; }
        .card-training { 
            box-shadow: 0 4px 10px rgba(0,0,0,0.1); 
            border-radius: 12px; 
            width: 300px; 
        }
        .card-training img { 
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
        <h4 class="mb-3">Add Training Area</h4>
        <form method="post" enctype="multipart/form-data">
            <div class="mb-3">
                <input type="text" name="name" class="form-control" placeholder="Training Area Name" required>
            </div>
            <div class="mb-3">
                <textarea name="description" class="form-control" rows="3" placeholder="Description" required></textarea>
            </div>
            <div class="mb-3">
                <input type="file" name="photo" accept="image/*" class="form-control" required>
            </div>
            <button type="submit" name="add_training_area" class="btn btn-primary">Add Training Area</button>
        </form>
    </div>

    <!-- Display All -->
    <div class="d-flex flex-wrap gap-3">
        <?php foreach ($trainingAreas as $area): ?>
            <div class="card p-2 card-training">
                <img src="<?= $uploadDir . htmlspecialchars($area['image']); ?>" alt="Training Area" class="mb-2">
                <h5><?= htmlspecialchars($area['name']); ?></h5>
                <p><?= htmlspecialchars($area['description']); ?></p>
                <div class="d-flex justify-content-between">
                    <!-- Edit -->
                    <form method="post" enctype="multipart/form-data" style="flex:1; margin-right:5px;">
                        <input type="hidden" name="id" value="<?= $area['id']; ?>">
                        <input type="text" name="name" value="<?= htmlspecialchars($area['name']); ?>" class="form-control mb-1" required>
                        <textarea name="description" class="form-control mb-1" required><?= htmlspecialchars($area['description']); ?></textarea>
                        <input type="file" name="photo" accept="image/*" class="form-control mb-1">
                        <button type="submit" name="edit_training_area" class="btn btn-sm btn-warning w-100">Edit</button>
                    </form>
                    <!-- Delete -->
                    <a href="?delete=<?= $area['id']; ?>" 
                       onclick="return confirm('Are you sure you want to delete this training area?');"
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
