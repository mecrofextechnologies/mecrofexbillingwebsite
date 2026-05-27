<?php
include '../msanii/includes/connect.php';

// Initialize messages
$success = '';
$error = '';

// ── Blog Addition ───────────────────────────────────────────────
if (isset($_POST['add_blog'])) {
    $title       = trim($_POST['blog_title']);
    $description = trim($_POST['blog_description']);
    $date        = trim($_POST['blog_date']) ?: date('Y-m-d'); // fallback to today

    if (empty($title)) {
        $error = "Title is required.";
    } elseif (empty($_FILES['blog_thumbnail']['name'])) {
        $error = "Please select a thumbnail image.";
    } else {
        $target_dir = "../uploads/blogs/";
        if (!is_dir($target_dir)) mkdir($target_dir, 0777, true);

        $image_name = time() . "_" . basename($_FILES["blog_thumbnail"]["name"]);
        $target_file = $target_dir . $image_name;

        if (move_uploaded_file($_FILES["blog_thumbnail"]["tmp_name"], $target_file)) {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO blogs (title, description, thumbnail, date_created)
                    VALUES (?, ?, ?, ?)
                ");
                $stmt->execute([$title, $description, $image_name, $date]);
                $success = "Blog post added successfully!";
            } catch (Exception $e) {
                $error = "Database error: Could not save blog post.";
                @unlink($target_file);
            }
        } else {
            $error = "Error uploading image. Check folder permissions.";
        }
    }
}

// ── Blog Update ─────────────────────────────────────────────────
if (isset($_POST['update_blog'])) {
    $id          = (int)$_POST['blog_id'];
    $title       = trim($_POST['blog_title']);
    $description = trim($_POST['blog_description']);
    $date        = trim($_POST['blog_date']) ?: date('Y-m-d');

    if (empty($title)) {
        $error = "Title is required.";
    } else {
        $thumbnail_name = $_POST['existing_thumbnail'] ?? '';

        if (!empty($_FILES["blog_thumbnail"]["name"])) {
            $target_dir = "../uploads/blogs/";
            $thumbnail_name = time() . "_" . basename($_FILES["blog_thumbnail"]["name"]);
            $target_file = $target_dir . $thumbnail_name;

            if (move_uploaded_file($_FILES["blog_thumbnail"]["tmp_name"], $target_file)) {
                if (!empty($_POST['existing_thumbnail'])) {
                    @unlink($target_dir . $_POST['existing_thumbnail']);
                }
            } else {
                $error = "Error uploading new thumbnail.";
            }
        }

        if (empty($error)) {
            try {
                $stmt = $pdo->prepare("
                    UPDATE blogs 
                    SET title = ?, description = ?, thumbnail = ?, date_created = ?
                    WHERE id = ?
                ");
                $stmt->execute([$title, $description, $thumbnail_name, $date, $id]);
                $success = "Blog post updated successfully!";
            } catch (Exception $e) {
                $error = "Failed to update blog post.";
            }
        }
    }
}

// ── Blog Delete ─────────────────────────────────────────────────
if (isset($_GET['delete_blog'])) {
    $id = (int)$_GET['delete_blog'];
    try {
        $stmt = $pdo->prepare("SELECT thumbnail FROM blogs WHERE id = ?");
        $stmt->execute([$id]);
        $blog = $stmt->fetch();

        if ($blog) {
            @unlink("../uploads/blogs/" . $blog['thumbnail']);
            $stmt = $pdo->prepare("DELETE FROM blogs WHERE id = ?");
            $stmt->execute([$id]);
            $success = "Blog post deleted successfully!";
        }
    } catch (Exception $e) {
        $error = "Error deleting blog post.";
    }
}

// ── Fetch Blogs ─────────────────────────────────────────────────
$blogs = [];
try {
    $stmt = $pdo->query("SELECT * FROM blogs ORDER BY id DESC");
    $blogs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $error = "Could not load blog posts.";
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Blog Posts</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.css" rel="stylesheet"/>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.js"></script>
    <style>
        body { background: #f4f6f9; padding-top: 40px; }
        .card { border: none; border-radius: 1rem; }
        .btn-gradient {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none; color: white;
        }
        .table th { background: #e9ecef; }
        .table img {
            border-radius: 8px;
            max-height: 90px;
            object-fit: cover;
            width: 140px;
        }
        #toast-container > div {
            opacity: 1 !important;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
    </style>
</head>
<body>
<div class="container">
    <h2 class="text-center mb-5 fw-bold">Manage Blog Posts</h2>

    <!-- Messages -->
    <?php if ($success): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <!-- Add New Blog Form -->
    <div class="card shadow-sm mb-5">
        <div class="card-header bg-white py-3">
            <h5 class="mb-0 fw-bold">Add New Blog Post</h5>
        </div>
        <div class="card-body">
            <form method="POST" enctype="multipart/form-data">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Thumbnail (recommended 1200×800)</label>
                        <input type="file" name="blog_thumbnail" class="form-control" accept="image/*" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Title</label>
                        <input type="text" name="blog_title" class="form-control" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Publication Date</label>
                        <input type="date" name="blog_date" class="form-control" value="<?= date('Y-m-d') ?>">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Description / Content Preview</label>
                        <textarea name="blog_description" class="form-control" rows="6" required></textarea>
                        <small class="text-muted">This is usually a short summary or excerpt shown on the blog list page.</small>
                    </div>
                    <div class="col-12 text-end">
                        <button type="submit" name="add_blog" class="btn btn-gradient px-5">Add Blog Post</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Existing Blogs Table -->
    <h4 class="mb-4 fw-bold">Existing Blog Posts</h4>

    <?php if (empty($blogs)): ?>
        <div class="alert alert-info">No blog posts added yet.</div>
    <?php else: ?>
        <div class="table-responsive mb-5">
            <table class="table table-hover align-middle">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Thumbnail</th>
                        <th>Title</th>
                        <th>Description</th>
                        <th>Date</th>
                        <th style="width:160px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($blogs as $i => $blog): ?>
                    <tr>
                        <td><?= $i + 1 ?></td>
                        <td>
                            <?php if ($blog['thumbnail']): ?>
                                <img src="../uploads/blogs/<?= htmlspecialchars($blog['thumbnail']) ?>" alt="Thumbnail">
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars($blog['title']) ?></td>
                        <td>
                            <?= htmlspecialchars(substr($blog['description'] ?? '', 0, 120)) ?>
                            <?= strlen($blog['description'] ?? '') > 120 ? '...' : '' ?>
                        </td>
                        <td><?= htmlspecialchars($blog['date_created'] ?? '—') ?></td>
                        <td>
                            <button class="btn btn-sm btn-primary me-1" data-bs-toggle="modal" data-bs-target="#editBlogModal<?= $blog['id'] ?>">
                                <i class="fas fa-edit"></i>
                            </button>
                            <a href="?delete_blog=<?= $blog['id'] ?>" class="btn btn-sm btn-danger"
                               onclick="return confirm('Delete this blog post permanently?')">
                                <i class="fas fa-trash"></i>
                            </a>
                        </td>
                    </tr>

                    <!-- Edit Modal -->
                    <div class="modal fade" id="editBlogModal<?= $blog['id'] ?>" tabindex="-1">
                        <div class="modal-dialog modal-lg">
                            <div class="modal-content">
                                <form method="POST" enctype="multipart/form-data">
                                    <div class="modal-header">
                                        <h5 class="modal-title">Edit Blog Post #<?= $blog['id'] ?></h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                    </div>
                                    <div class="modal-body">
                                        <input type="hidden" name="blog_id" value="<?= $blog['id'] ?>">
                                        <input type="hidden" name="existing_thumbnail" value="<?= htmlspecialchars($blog['thumbnail'] ?? '') ?>">
                                        <div class="row g-3">
                                            <div class="col-md-5">
                                                <label>Current Thumbnail</label><br>
                                                <?php if ($blog['thumbnail']): ?>
                                                    <img src="../uploads/blogs/<?= htmlspecialchars($blog['thumbnail']) ?>" class="img-fluid mb-2" style="max-height:180px; object-fit:cover;">
                                                <?php endif; ?>
                                                <input type="file" name="blog_thumbnail" class="form-control mt-2" accept="image/*">
                                                <small>Leave blank to keep current</small>
                                            </div>
                                            <div class="col-md-7">
                                                <label>Title</label>
                                                <input type="text" name="blog_title" class="form-control" value="<?= htmlspecialchars($blog['title']) ?>" required>

                                                <label class="mt-3">Publication Date</label>
                                                <input type="date" name="blog_date" class="form-control" value="<?= htmlspecialchars($blog['date_created'] ?? date('Y-m-d')) ?>">
                                            </div>
                                            <div class="col-12">
                                                <label>Description / Excerpt</label>
                                                <textarea name="blog_description" class="form-control" rows="7" required><?= htmlspecialchars($blog['description']) ?></textarea>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                        <button type="submit" name="update_blog" class="btn btn-gradient">Save Changes</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <div class="text-center mt-5 mb-5">
        <a href="index.php" class="btn btn-outline-secondary px-5">← Back to Dashboard</a>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Toastr settings
toastr.options = {
    closeButton: true,
    progressBar: true,
    positionClass: "toast-top-right",
    timeOut: 4000
};
<?php if ($success): ?>
    toastr.success("<?= addslashes($success) ?>");
<?php endif; ?>
<?php if ($error): ?>
    toastr.error("<?= addslashes($error) ?>");
<?php endif; ?>
</script>
</body>
</html>