<?php
// Database connection
include '../includes/connect.php';

$uploadDir = 'uploads/';
$success_message = '';
$error_message = '';

// Handle insert (new image)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_accreditation'])) {
    $file = $_FILES['photo'] ?? null;

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
            $fileName = uniqid('accreditation_', true) . "." . $ext;
            $uploadFile = $uploadDir . $fileName;
            if (move_uploaded_file($file['tmp_name'], $uploadFile)) {
                $stmt = $pdo->prepare("INSERT INTO accreditation (image) VALUES (:image)");
                $stmt->execute(['image' => $fileName]);
                $success_message = "Accreditation image added successfully!";
            } else {
                $error_message = "Failed to upload image.";
            }
        } else {
            $error_message = "Invalid image file.";
        }
    }
}

// Handle delete
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $stmt = $pdo->prepare("SELECT image FROM accreditation WHERE id = :id");
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch();

    if ($row) {
        if (file_exists($uploadDir . $row['image'])) {
            @unlink($uploadDir . $row['image']);
        }
        $pdo->prepare("DELETE FROM accreditation WHERE id = :id")->execute(['id' => $id]);
        $success_message = "Accreditation image deleted successfully!";
    }
}

// Handle edit (replace image)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_accreditation'])) {
    $id = (int)$_POST['id'];
    $file = $_FILES['photo'] ?? null;

    if ($file && $file['error'] === UPLOAD_ERR_OK) {
        $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime  = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if (in_array($mime, $allowed, true)) {
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $fileName = uniqid('accreditation_', true) . "." . $ext;
            $uploadFile = $uploadDir . $fileName;
            if (move_uploaded_file($file['tmp_name'], $uploadFile)) {
                // Remove old image
                $stmt = $pdo->prepare("SELECT image FROM accreditation WHERE id = :id");
                $stmt->execute(['id' => $id]);
                $old = $stmt->fetch();
                if ($old && file_exists($uploadDir . $old['image'])) {
                    @unlink($uploadDir . $old['image']);
                }

                // Update db
                $pdo->prepare("UPDATE accreditation SET image=:image, date=NOW() WHERE id=:id")
                    ->execute(['image' => $fileName, 'id' => $id]);

                $success_message = "Accreditation image updated successfully!";
            } else {
                $error_message = "Failed to upload new image.";
            }
        } else {
            $error_message = "Invalid image file.";
        }
    }
}

// Fetch all records
$stmt = $pdo->query("SELECT * FROM accreditation ORDER BY date DESC");
$accreditations = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Accreditation Management</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        body { background: #f8f9fa; }
        .card-accreditation { box-shadow: 0 4px 10px rgba(0,0,0,0.1); border-radius: 12px; }
        .grid img { width: 100%; height: 180px; object-fit: cover; border-radius: 8px; }
    </style>
</head>
<body>
<div class="container py-5">

    <!-- Upload Form -->
    <div class="card p-4 shadow-sm mb-4 card-accreditation">
        <h4 class="mb-3">Add Accreditation Image</h4>
        <form method="post" enctype="multipart/form-data">
            <div class="mb-3">
                <input type="file" name="photo" accept="image/*" class="form-control" required>
            </div>
            <button type="submit" name="add_accreditation" class="btn btn-primary">Upload</button>
        </form>
    </div>

    <!-- Display All -->
    <div class="row grid">
        <?php foreach ($accreditations as $acc): ?>
            <div class="col-md-3 mb-4">
                <div class="card p-2">
                    <img src="<?= $uploadDir . htmlspecialchars($acc['image']); ?>" alt="Accreditation" class="mb-2">
                    <div class="d-flex justify-content-between">
                        <!-- Edit -->
                        <form method="post" enctype="multipart/form-data" style="flex:1; margin-right:5px;">
                            <input type="hidden" name="id" value="<?= $acc['id']; ?>">
                            <input type="file" name="photo" accept="image/*" class="form-control mb-1" required>
                            <button type="submit" name="edit_accreditation" class="btn btn-sm btn-warning w-100">Edit</button>
                        </form>
                        <!-- Delete -->
                        <a href="?delete=<?= $acc['id']; ?>" 
                           onclick="return confirm('Are you sure you want to delete this image?');"
                           class="btn btn-sm btn-danger">Delete</a>
                    </div>
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
