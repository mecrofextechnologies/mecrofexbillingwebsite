<?php
// Database connection
include '../includes/connect.php';

$uploadDir = 'uploads/';
$uploadedPhotos = [];
$success_message = '';
$error_message = '';

function is_valid_image_upload($file) {
    if (!isset($file['tmp_name']) || $file['error'] !== UPLOAD_ERR_OK) return false;
    if ($file['size'] > 5 * 1024 * 1024) return false; // 5MB
    $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    // Prefer finfo over $_FILES['type']
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime  = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    return in_array($mime, $allowed, true);
}

function make_unique_filename($uploadDir, $originalName) {
    $base = pathinfo($originalName, PATHINFO_FILENAME);
    $ext  = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $safe = preg_replace('/[^A-Za-z0-9_\-]/', '_', $base);
    $candidate = $safe . '.' . $ext;
    $i = 1;
    while (file_exists($uploadDir . $candidate)) {
        $candidate = $safe . '_' . $i . '.' . $ext;
        $i++;
    }
    return $candidate;
}

// Add new slide
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_slide'])) {
    $description = trim($_POST['description'] ?? '');
    $title       = trim($_POST['title'] ?? '');
    $file        = $_FILES['photo'] ?? null;

    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    if ($file && is_valid_image_upload($file)) {
        $fileName   = make_unique_filename($uploadDir, basename($file['name']));
        $uploadFile = $uploadDir . $fileName;

        if (move_uploaded_file($file['tmp_name'], $uploadFile)) {
            // NOTE: This assumes your `slides.id` can auto-increment. If not, add `id` accordingly.
            $sql = "INSERT INTO slides (image, description, title, date) 
                    VALUES (:image, :description, :title, NOW())";
            $stmt = $pdo->prepare($sql);
            if ($stmt->execute([
                'image'       => $fileName,
                'description' => $description,
                'title'       => $title
            ])) {
                $success_message = "New slide uploaded successfully!";
            } else {
                $error_message = "Failed to upload slide.";
            }
        } else {
            $error_message = "Failed to move uploaded file.";
        }
    } else {
        $error_message = "Invalid image file or size exceeds 5MB.";
    }
}

// Delete slide
if (isset($_POST['delete_slide'])) {
    $slideId    = $_POST['slide_id'] ?? '';
    $slideImage = $_POST['slide_image'] ?? '';

    $stmt = $pdo->prepare("DELETE FROM slides WHERE id=:id");
    if ($stmt->execute(['id' => $slideId])) {
        if ($slideImage && file_exists($uploadDir . $slideImage)) {
            @unlink($uploadDir . $slideImage);
        }
        $success_message = "Slide deleted successfully!";
    } else {
        $error_message = "Failed to delete slide.";
    }
}

// Update slide
if (isset($_POST['update_slide'])) {
    $slideId       = $_POST['slide_id'] ?? '';
    $description   = trim($_POST['description'] ?? '');
    $title         = trim($_POST['title'] ?? '');
    $current_image = $_POST['current_image'] ?? '';
    $file          = $_FILES['photo'] ?? null;

    if ($file && $file['error'] === UPLOAD_ERR_OK) {
        if (is_valid_image_upload($file)) {
            $fileName   = make_unique_filename($uploadDir, basename($file['name']));
            $uploadFile = $uploadDir . $fileName;

            if (move_uploaded_file($file['tmp_name'], $uploadFile)) {
                $sql = "UPDATE slides 
                        SET image=:image, description=:description, title=:title 
                        WHERE id=:id";
                $stmt = $pdo->prepare($sql);
                $ok = $stmt->execute([
                    'id'          => $slideId,
                    'image'       => $fileName,
                    'description' => $description,
                    'title'       => $title
                ]);
                if ($ok) {
                    // delete old image if different
                    if ($current_image && $current_image !== $fileName && file_exists($uploadDir . $current_image)) {
                        @unlink($uploadDir . $current_image);
                    }
                    $success_message = "Slide updated successfully!";
                } else {
                    $error_message = "Failed to update slide.";
                }
            } else {
                $error_message = "Image upload failed!";
            }
        } else {
            $error_message = "Invalid image file or size exceeds 5MB.";
        }
    } else {
        // Only update title and description
        $sql  = "UPDATE slides SET description=:description, title=:title WHERE id=:id";
        $stmt = $pdo->prepare($sql);
        if ($stmt->execute([
            'id'          => $slideId,
            'description' => $description,
            'title'       => $title
        ])) {
            $success_message = "Slide updated successfully!";
        } else {
            $error_message = "Failed to update slide.";
        }
    }
}

// Fetch slides
$stmt = $pdo->query("SELECT * FROM slides ORDER BY date DESC");
$uploadedPhotos = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Slides Management</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        body { background: #f8f9fa; }
        .card-slide { box-shadow: 0 4px 10px rgba(0,0,0,0.1); border-radius: 12px; overflow: hidden; }
        .card-slide img { height: 200px; object-fit: cover; }
        .img-preview { max-height: 160px; object-fit: cover; }
    </style>
</head>
<body>
<div class="container py-5">

    <!-- Upload Form -->
    <div class="card p-4 shadow-sm mb-5">
        <h4 class="mb-3">Upload New Slide</h4>
        <form method="post" enctype="multipart/form-data">
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label">Title</label>
                    <input type="text" name="title" class="form-control" required>
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label">Image</label>
                    <input type="file" name="photo" accept="image/*" class="form-control" required>
                </div>
            </div>
            <div class="mb-3">
                <label class="form-label">Description</label>
                <textarea name="description" class="form-control" rows="3" required></textarea>
            </div>
            <button type="submit" name="add_slide" class="btn btn-primary">Upload Slide</button>
        </form>
    </div>

    <!-- Display Slides -->
    <h4 class="mb-3">Slides</h4>
    <div class="row">
        <?php if ($uploadedPhotos): ?>
            <?php foreach ($uploadedPhotos as $i => $photo): 
                // Safe modal id per card (fixes "only one edit works")
                $modalId = 'editModal' . $i;
            ?>
                <div class="col-md-4 mb-4">
                    <div class="card card-slide">
                        <img src="<?= $uploadDir . htmlspecialchars($photo['image']); ?>" class="card-img-top" alt="">
                        <div class="card-body">
                            <h5 class="card-title"><?= htmlspecialchars($photo['title']); ?></h5>
                            <p class="card-text"><?= htmlspecialchars($photo['description']); ?></p>
                            <small class="text-muted"><?= htmlspecialchars($photo['date']); ?></small>
                            <div class="mt-3 d-flex justify-content-between">
                                <button type="button" class="btn btn-sm btn-warning" data-bs-toggle="modal" data-bs-target="#<?= $modalId; ?>">Edit</button>
                                <form method="post" onsubmit="return confirm('Delete this slide?');">
                                    <input type="hidden" name="slide_id" value="<?= htmlspecialchars($photo['id']); ?>">
                                    <input type="hidden" name="slide_image" value="<?= htmlspecialchars($photo['image']); ?>">
                                    <button type="submit" name="delete_slide" class="btn btn-sm btn-danger">Delete</button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Edit Modal -->
                <div class="modal fade" id="<?= $modalId; ?>" tabindex="-1" aria-hidden="true">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <form method="post" enctype="multipart/form-data">
                                <div class="modal-header">
                                    <h5 class="modal-title">Edit Slide</h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                </div>
                                <div class="modal-body">
                                    <input type="hidden" name="slide_id" value="<?= htmlspecialchars($photo['id']); ?>">
                                    <input type="hidden" name="current_image" value="<?= htmlspecialchars($photo['image']); ?>">

                                    <div class="mb-3">
                                        <label class="form-label">Title</label>
                                        <input type="text" name="title" value="<?= htmlspecialchars($photo['title']); ?>" class="form-control" required>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Description</label>
                                        <textarea name="description" class="form-control" rows="3" required><?= htmlspecialchars($photo['description']); ?></textarea>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Current Image</label><br>
                                        <img class="img-thumbnail img-preview mb-2" src="<?= $uploadDir . htmlspecialchars($photo['image']); ?>" alt="">
                                        <input type="file" name="photo" accept="image/*" class="form-control mt-2">
                                        <small class="text-muted d-block mt-1">Leave empty to keep current image.</small>
                                    </div>
                                </div>
                                <div class="modal-footer">
                                    <button type="submit" name="update_slide" value="1" class="btn btn-primary">Save Changes</button>
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

            <?php endforeach; ?>
        <?php else: ?>
            <p>No slides uploaded yet.</p>
        <?php endif; ?>
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
