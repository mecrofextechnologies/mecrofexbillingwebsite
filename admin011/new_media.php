<?php
// Database connection
include '../includes/connect.php';

$uploadDir = 'uploads/';
$success_message = '';
$error_message = '';

// Handle insert (new news/article)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_news_article'])) {
    $title = trim($_POST['title'] ?? '');
    $content = trim($_POST['content'] ?? '');
    $file = $_FILES['photo'] ?? null;

    if (!$title || !$content) {
        $error_message = "Title and content are required.";
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
                $fileName = uniqid('news_', true) . "." . $ext;
                $uploadFile = $uploadDir . $fileName;

                if (move_uploaded_file($file['tmp_name'], $uploadFile)) {
                    $stmt = $pdo->prepare("INSERT INTO news_articles (title, content, image, created_at) 
                                           VALUES (:title, :content, :image, NOW())");
                    $stmt->execute([
                        'title' => $title,
                        'content' => $content,
                        'image' => $fileName
                    ]);
                    $success_message = "News/Article added successfully!";
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
    $stmt = $pdo->prepare("SELECT image FROM news_articles WHERE id = :id");
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch();

    if ($row) {
        if (file_exists($uploadDir . $row['image'])) {
            @unlink($uploadDir . $row['image']);
        }
        $pdo->prepare("DELETE FROM news_articles WHERE id = :id")->execute(['id' => $id]);
        $success_message = "News/Article deleted successfully!";
    }
}

// Handle edit (replace image, title, content)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_news_article'])) {
    $id = (int)$_POST['id'];
    $title = trim($_POST['title'] ?? '');
    $content = trim($_POST['content'] ?? '');
    $file = $_FILES['photo'] ?? null;

    if ($title && $content) {
        if ($file && $file['error'] === UPLOAD_ERR_OK) {
            $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime  = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);

            if (in_array($mime, $allowed, true)) {
                $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                $fileName = uniqid('news_', true) . "." . $ext;
                $uploadFile = $uploadDir . $fileName;

                if (move_uploaded_file($file['tmp_name'], $uploadFile)) {
                    // Remove old image
                    $stmt = $pdo->prepare("SELECT image FROM news_articles WHERE id = :id");
                    $stmt->execute(['id' => $id]);
                    $old = $stmt->fetch();
                    if ($old && file_exists($uploadDir . $old['image'])) {
                        @unlink($uploadDir . $old['image']);
                    }

                    // Update db
                    $pdo->prepare("UPDATE news_articles 
                                   SET title=:title, content=:content, image=:image, updated_at=NOW() 
                                   WHERE id=:id")
                        ->execute([
                            'title' => $title,
                            'content' => $content,
                            'image' => $fileName,
                            'id' => $id
                        ]);
                    $success_message = "News/Article updated successfully!";
                } else {
                    $error_message = "Failed to upload new image.";
                }
            } else {
                $error_message = "Invalid image file.";
            }
        } else {
            // Update without image
            $pdo->prepare("UPDATE news_articles 
                           SET title=:title, content=:content, updated_at=NOW() 
                           WHERE id=:id")
                ->execute([
                    'title' => $title,
                    'content' => $content,
                    'id' => $id
                ]);
            $success_message = "News/Article updated successfully (without image change)!";
        }
    } else {
        $error_message = "Title and content are required.";
    }
}

// Fetch all records
$stmt = $pdo->query("SELECT * FROM news_articles ORDER BY created_at DESC");
$articles = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>News & Articles Management</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        body { background: #f8f9fa; }
        .card-article { 
            box-shadow: 0 4px 10px rgba(0,0,0,0.1); 
            border-radius: 12px; 
            width: 300px; 
        }
        .card-article img { 
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
        <h4 class="mb-3">Add News / Article</h4>
        <form method="post" enctype="multipart/form-data">
            <div class="mb-3">
                <input type="text" name="title" class="form-control" placeholder="Title" required>
            </div>
            <div class="mb-3">
                <textarea name="content" class="form-control" rows="4" placeholder="Content" required></textarea>
            </div>
            <div class="mb-3">
                <input type="file" name="photo" accept="image/*" class="form-control" required>
            </div>
            <button type="submit" name="add_news_article" class="btn btn-primary">Add News/Article</button>
        </form>
    </div>

    <!-- Display All -->
    <div class="d-flex flex-wrap gap-3">
        <?php foreach ($articles as $article): ?>
            <div class="card p-2 card-article">
                <img src="<?= $uploadDir . htmlspecialchars($article['image']); ?>" alt="News Image" class="mb-2">
                <h5><?= htmlspecialchars($article['title']); ?></h5>
                <p><?= htmlspecialchars($article['content']); ?></p>
                <div class="d-flex justify-content-between">
                    <!-- Edit -->
                    <form method="post" enctype="multipart/form-data" style="flex:1; margin-right:5px;">
                        <input type="hidden" name="id" value="<?= $article['id']; ?>">
                        <input type="text" name="title" value="<?= htmlspecialchars($article['title']); ?>" class="form-control mb-1" required>
                        <textarea name="content" class="form-control mb-1" required><?= htmlspecialchars($article['content']); ?></textarea>
                        <input type="file" name="photo" accept="image/*" class="form-control mb-1">
                        <button type="submit" name="edit_news_article" class="btn btn-sm btn-warning w-100">Edit</button>
                    </form>
                    <!-- Delete -->
                    <a href="?delete=<?= $article['id']; ?>" 
                       onclick="return confirm('Are you sure you want to delete this news/article?');"
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
