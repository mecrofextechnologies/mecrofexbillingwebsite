<?php
// Database connection
include '../includes/connect.php';

$uploadDir = 'uploads/';
$success_message = '';
$error_message = '';

// Check if About Us record exists
$stmt = $pdo->query("SELECT * FROM about_us LIMIT 1");
$about = $stmt->fetch(PDO::FETCH_ASSOC);

// Handle insert/update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_about'])) {
    $title       = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $mission     = trim($_POST['mission'] ?? '');
    $vision      = trim($_POST['vision'] ?? '');
    $core_values = trim($_POST['core_values'] ?? '');
    $file        = $_FILES['photo'] ?? null;
    $currentImage = $_POST['current_image'] ?? '';

    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    // Handle image upload
    $fileName = $currentImage;
    if ($file && $file['error'] === UPLOAD_ERR_OK) {
        $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime  = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if (in_array($mime, $allowed, true)) {
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $fileName = uniqid('about_', true) . "." . $ext;
            $uploadFile = $uploadDir . $fileName;
            if (move_uploaded_file($file['tmp_name'], $uploadFile)) {
                if ($currentImage && file_exists($uploadDir . $currentImage)) {
                    @unlink($uploadDir . $currentImage);
                }
            } else {
                $error_message = "Failed to upload image.";
            }
        } else {
            $error_message = "Invalid image file.";
        }
    }

    if (!$error_message) {
        if ($about) {
            // Update existing record
            $sql = "UPDATE about_us SET title=:title, description=:description, mission=:mission, vision=:vision, core_values=:core_values, image=:image, date=NOW() WHERE id=:id";
            $stmt = $pdo->prepare($sql);
            $ok = $stmt->execute([
                'title'       => $title,
                'description' => $description,
                'mission'     => $mission,
                'vision'      => $vision,
                'core_values' => $core_values,
                'image'       => $fileName,
                'id'          => $about['id']
            ]);
            $success_message = $ok ? "About Us updated successfully!" : "Failed to update content.";
        } else {
            // Insert new record
            $sql = "INSERT INTO about_us (title, description, mission, vision, core_values, image, date) 
                    VALUES (:title, :description, :mission, :vision, :core_values, :image, NOW())";
            $stmt = $pdo->prepare($sql);
            $ok = $stmt->execute([
                'title'       => $title,
                'description' => $description,
                'mission'     => $mission,
                'vision'      => $vision,
                'core_values' => $core_values,
                'image'       => $fileName
            ]);
            $success_message = $ok ? "About Us added successfully!" : "Failed to add content.";
        }
        // Reload data
        $stmt = $pdo->query("SELECT * FROM about_us LIMIT 1");
        $about = $stmt->fetch(PDO::FETCH_ASSOC);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>About Us Management</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        body { background: #f8f9fa; }
        .card-about { box-shadow: 0 4px 10px rgba(0,0,0,0.1); border-radius: 12px; }
        .card-about img { max-height: 250px; object-fit: cover; }
    </style>
</head>
<body>
<div class="container py-5">
    <div class="card p-4 shadow-sm">
        <h4 class="mb-3"><?= $about ? "Edit About Us Content" : "Add About Us Content"; ?></h4>
        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="current_image" value="<?= htmlspecialchars($about['image'] ?? ''); ?>">

            <div class="mb-3">
                <label class="form-label">Title</label>
                <input type="text" name="title" value="<?= htmlspecialchars($about['title'] ?? ''); ?>" class="form-control" required>
            </div>

            <div class="mb-3">
                <label class="form-label">Description</label>
                <textarea name="description" class="form-control" rows="3" required><?= htmlspecialchars($about['description'] ?? ''); ?></textarea>
            </div>

            <div class="mb-3">
                <label class="form-label">Mission</label>
                <textarea name="mission" class="form-control" rows="2"><?= htmlspecialchars($about['mission'] ?? ''); ?></textarea>
            </div>

            <div class="mb-3">
                <label class="form-label">Vision</label>
                <textarea name="vision" class="form-control" rows="2"><?= htmlspecialchars($about['vision'] ?? ''); ?></textarea>
            </div>

            <div class="mb-3">
                <label class="form-label">Core Values</label>
                <textarea name="core_values" class="form-control" rows="2"><?= htmlspecialchars($about['core_values'] ?? ''); ?></textarea>
            </div>

            <div class="mb-3">
                <label class="form-label">Image</label><br>
                <?php if (!empty($about['image'])): ?>
                    <img src="<?= $uploadDir . htmlspecialchars($about['image']); ?>" class="img-thumbnail mb-2" style="max-width:200px;">
                <?php endif; ?>
                <input type="file" name="photo" accept="image/*" class="form-control">
                <small class="text-muted">Leave empty to keep current image.</small>
            </div>

            <button type="submit" name="save_about" class="btn btn-primary"><?= $about ? "Update" : "Save"; ?></button>
        </form>
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
