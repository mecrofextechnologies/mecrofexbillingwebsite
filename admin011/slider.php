<?php
include '../msanii/includes/connect.php';

// Initialize messages
$success = '';
$error = '';

// ── Slide Addition ───────────────────────────────────────────────
if (isset($_POST['add_slide'])) {
    $title     = trim($_POST['slide_title']);
    $desc      = trim($_POST['slide_desc']);
    $btn1_text = trim($_POST['btn1_text']);
    $btn1_link = trim($_POST['btn1_link']);
    $btn2_text = trim($_POST['btn2_text']);
    $btn2_link = trim($_POST['btn2_link']);

    $word_count = str_word_count($desc);

    if ($word_count > 5) {
        $error = "Description must be 5 words or fewer.";
    } elseif (empty($_FILES['slide_image']['name'])) {
        $error = "Please select an image.";
    } else {
        $target_dir = "../uploads/slides/";
        if (!is_dir($target_dir)) mkdir($target_dir, 0777, true);

        $image_name = time() . "_" . basename($_FILES["slide_image"]["name"]);
        $target_file = $target_dir . $image_name;

        if (move_uploaded_file($_FILES["slide_image"]["tmp_name"], $target_file)) {
            try {
                $stmt = $pdo->prepare("INSERT INTO sliders (image, title, description, btn1_text, btn1_link, btn2_text, btn2_link) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$image_name, $title, $desc, $btn1_text, $btn1_link, $btn2_text, $btn2_link]);
                $success = "Slide added successfully!";
            } catch (Exception $e) {
                $error = "Database error: Could not save slide.";
                @unlink($target_file);
            }
        } else {
            $error = "Error uploading image. Check folder permissions.";
        }
    }
}

// ── Slide Update ─────────────────────────────────────────────────
if (isset($_POST['update_slide'])) {
    $id = (int)$_POST['slide_id'];
    $title = trim($_POST['slide_title']);
    $desc  = trim($_POST['slide_desc']);
    $btn1_text = trim($_POST['btn1_text']);
    $btn1_link = trim($_POST['btn1_link']);
    $btn2_text = trim($_POST['btn2_text']);
    $btn2_link = trim($_POST['btn2_link']);

    $word_count = str_word_count($desc);

    if ($word_count > 5) {
        $error = "Description must be 5 words or fewer.";
    } else {
        $image_name = $_POST['existing_image'] ?? '';

        if (!empty($_FILES["slide_image"]["name"])) {
            $target_dir  = "../uploads/slides/";
            $image_name  = time() . "_" . basename($_FILES["slide_image"]["name"]);
            $target_file = $target_dir . $image_name;

            if (move_uploaded_file($_FILES["slide_image"]["tmp_name"], $target_file)) {
                if (!empty($_POST['existing_image'])) {
                    @unlink($target_dir . $_POST['existing_image']);
                }
            } else {
                $error = "Error uploading new image.";
            }
        }

        if (empty($error)) {
            try {
                $stmt = $pdo->prepare("UPDATE sliders SET image=?, title=?, description=?, btn1_text=?, btn1_link=?, btn2_text=?, btn2_link=? WHERE id=?");
                $stmt->execute([$image_name, $title, $desc, $btn1_text, $btn1_link, $btn2_text, $btn2_link, $id]);
                $success = "Slide updated successfully!";
            } catch (Exception $e) {
                $error = "Failed to update slide.";
            }
        }
    }
}

// ── Slide Delete ─────────────────────────────────────────────────
if (isset($_GET['delete_slide'])) {
    $id = (int)$_GET['delete_slide'];
    try {
        $stmt = $pdo->prepare("SELECT image FROM sliders WHERE id=?");
        $stmt->execute([$id]);
        $slide = $stmt->fetch();

        if ($slide) {
            @unlink("../uploads/slides/" . $slide['image']);
            $stmt = $pdo->prepare("DELETE FROM sliders WHERE id=?");
            $stmt->execute([$id]);
            $success = "Slide deleted successfully!";
        }
    } catch (Exception $e) {
        $error = "Error deleting slide.";
    }
}

// ── Fetch Slides ─────────────────────────────────────────────────
$slides = [];
try {
    $stmt = $pdo->query("SELECT * FROM sliders ORDER BY id DESC");
    $slides = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $error = "Could not load slides.";
}

// ── Featured Cards Logic ─────────────────────────────────────────
$cards = [];
try {
    $stmt = $pdo->query("SELECT * FROM featured_cards ORDER BY id ASC LIMIT 3");
    $cards = $stmt->fetchAll(PDO::FETCH_ASSOC);
    // Pad to 3 slots if fewer exist
    while (count($cards) < 3) {
        $cards[] = ['id' => null, 'title' => '', 'description' => '', 'icon' => 'fas fa-star', 'link' => '#'];
    }
} catch (Exception $e) {
    $cards = array_fill(0, 3, ['id' => null, 'title' => '', 'description' => '', 'icon' => 'fas fa-star', 'link' => '#']);
}

// ── Featured Card Update ─────────────────────────────────────────
if (isset($_POST['update_card'])) {
    $card_id   = !empty($_POST['card_id']) ? (int)$_POST['card_id'] : null;
    $title     = trim($_POST['card_title'] ?? '');
    $desc      = trim($_POST['card_desc'] ?? '');
    $icon      = trim($_POST['card_icon'] ?? '') ?: 'fas fa-star';
    $link      = trim($_POST['card_link'] ?? '') ?: '#';

    if (empty($desc)) {
        $error = "Description is required for featured cards.";
    } else {
        try {
            if ($card_id) {
                $stmt = $pdo->prepare("UPDATE featured_cards SET title=?, description=?, icon=?, link=? WHERE id=?");
                $stmt->execute([$title, $desc, $icon, $link, $card_id]);
                $success = "Featured card updated successfully!";
            } else {
                // Only create if we haven't reached limit (already padded to 3)
                $stmt_count = $pdo->query("SELECT COUNT(*) FROM featured_cards");
                if ($stmt_count->fetchColumn() < 3) {
                    $stmt = $pdo->prepare("INSERT INTO featured_cards (title, description, icon, link) VALUES (?, ?, ?, ?)");
                    $stmt->execute([$title, $desc, $icon, $link]);
                    $success = "Featured card created successfully!";
                } else {
                    $error = "Maximum of 3 featured cards reached.";
                }
            }
        } catch (Exception $e) {
            $error = "Error saving card.";
        }
    }
}

// ── Featured Card Delete ─────────────────────────────────────────
if (isset($_GET['delete_card'])) {
    $id = (int)$_GET['delete_card'];
    try {
        $stmt = $pdo->prepare("DELETE FROM featured_cards WHERE id=?");
        $stmt->execute([$id]);
        $success = "Featured card deleted successfully!";
    } catch (Exception $e) {
        $error = "Error deleting featured card.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Slider & Featured Cards</title>

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
            border-radius: 6px;
            max-height: 80px;
            object-fit: cover;
        }

        /* Toastr custom style */
        #toast-container > div {
            opacity: 1 !important;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        .toast {
            background-color: white !important;
            border: 2px solid #28a745 !important;
            color: #155724 !important;
        }
        .toast-title { color: #155724; font-weight: 600; }
        .toast-message { color: #155724; }
        .toast-close-button { color: #155724 !important; opacity: 0.7; }

        @keyframes bounceInCustom {
            0%   { transform: scale(0.3);   opacity: 0; }
            50%  { transform: scale(1.15);  opacity: 1; }
            70%  { transform: scale(0.95); }
            85%  { transform: scale(1.05); }
            100% { transform: scale(1);    }
        }
        .toast.toast-bounce-in {
            animation: bounceInCustom 0.8s ease-out forwards;
        }
    </style>
</head>
<body>

<div class="container">

    <h2 class="text-center mb-5 fw-bold">Manage Homepage Slider & Featured Cards</h2>

    <!-- Add New Slide Form -->
    <div class="card shadow-sm mb-5">
        <div class="card-header bg-white py-3">
            <h5 class="mb-0 fw-bold">Add New Slider Slide</h5>
        </div>
        <div class="card-body">
            <form method="POST" enctype="multipart/form-data">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Slide Image (1920×800 recommended)</label>
                        <input type="file" name="slide_image" class="form-control" accept="image/*" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Title</label>
                        <input type="text" name="slide_title" class="form-control" required>
                    </div>
                    <div class="col-md-12">
                        <label class="form-label">Description (max 5 words)</label>
                        <input type="text" name="slide_desc" id="slide_desc" class="form-control" placeholder="e.g. Join creators today" required>
                        <small class="text-muted">Words: <span id="wordCount">0</span>/5</small>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Button 1 Text</label>
                        <input type="text" name="btn1_text" class="form-control" value="Get Started" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Button 1 Link</label>
                        <input type="url" name="btn1_link" class="form-control" placeholder="https://" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Button 2 Text (optional)</label>
                        <input type="text" name="btn2_text" class="form-control" value="Learn More">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Button 2 Link (optional)</label>
                        <input type="url" name="btn2_link" class="form-control" placeholder="https://">
                    </div>
                    <div class="col-12 text-end">
                        <button type="submit" name="add_slide" class="btn btn-gradient px-5">Add Slide</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Existing Slides Table -->
    <h4 class="mb-4 fw-bold">Existing Slides</h4>

    <?php if (empty($slides)): ?>
        <div class="alert alert-info">No slides added yet.</div>
    <?php else: ?>
        <div class="table-responsive mb-5">
            <table class="table table-hover align-middle">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Image</th>
                        <th>Title</th>
                        <th>Description</th>
                        <th>Btn 1</th>
                        <th>Btn 2</th>
                        <th style="width:160px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($slides as $i => $slide): ?>
                    <tr>
                        <td><?= $i + 1 ?></td>
                        <td><img src="../uploads/slides/<?= htmlspecialchars($slide['image']) ?>" alt="Slide"></td>
                        <td><?= htmlspecialchars($slide['title']) ?></td>
                        <td><?= htmlspecialchars($slide['description']) ?></td>
                        <td>
                            <?= htmlspecialchars($slide['btn1_text']) ?><br>
                            <small class="text-muted"><?= htmlspecialchars($slide['btn1_link']) ?></small>
                        </td>
                        <td>
                            <?php if (!empty($slide['btn2_text'])): ?>
                                <?= htmlspecialchars($slide['btn2_text']) ?><br>
                                <small class="text-muted"><?= htmlspecialchars($slide['btn2_link'] ?: '—') ?></small>
                            <?php else: ?> — <?php endif; ?>
                        </td>
                        <td>
                            <button class="btn btn-sm btn-primary me-1" data-bs-toggle="modal" data-bs-target="#editSlideModal<?= $slide['id'] ?>">
                                <i class="fas fa-edit"></i>
                            </button>
                            <a href="?delete_slide=<?= $slide['id'] ?>" class="btn btn-sm btn-danger"
                               onclick="return confirm('Delete this slide permanently?')">
                                <i class="fas fa-trash"></i>
                            </a>
                        </td>
                    </tr>

                    <!-- Slide Edit Modal -->
                    <div class="modal fade" id="editSlideModal<?= $slide['id'] ?>" tabindex="-1">
                        <div class="modal-dialog modal-lg">
                            <div class="modal-content">
                                <form method="POST" enctype="multipart/form-data">
                                    <div class="modal-header">
                                        <h5 class="modal-title">Edit Slide #<?= $slide['id'] ?></h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                    </div>
                                    <div class="modal-body">
                                        <input type="hidden" name="slide_id" value="<?= $slide['id'] ?>">
                                        <input type="hidden" name="existing_image" value="<?= htmlspecialchars($slide['image']) ?>">
                                        <div class="row g-3">
                                            <div class="col-md-6">
                                                <label>Current Image</label><br>
                                                <img src="../uploads/slides/<?= htmlspecialchars($slide['image']) ?>" class="img-fluid mb-2" style="max-height:150px;">
                                                <input type="file" name="slide_image" class="form-control" accept="image/*">
                                                <small>Leave blank to keep current</small>
                                            </div>
                                            <div class="col-md-6">
                                                <label>Title</label>
                                                <input type="text" name="slide_title" class="form-control" value="<?= htmlspecialchars($slide['title']) ?>" required>
                                            </div>
                                            <div class="col-md-12">
                                                <label>Description (max 5 words)</label>
                                                <input type="text" name="slide_desc" class="form-control" value="<?= htmlspecialchars($slide['description']) ?>" required>
                                            </div>
                                            <div class="col-md-6">
                                                <label>Button 1 Text</label>
                                                <input type="text" name="btn1_text" class="form-control" value="<?= htmlspecialchars($slide['btn1_text']) ?>" required>
                                            </div>
                                            <div class="col-md-6">
                                                <label>Button 1 Link</label>
                                                <input type="url" name="btn1_link" class="form-control" value="<?= htmlspecialchars($slide['btn1_link']) ?>" required>
                                            </div>
                                            <div class="col-md-6">
                                                <label>Button 2 Text</label>
                                                <input type="text" name="btn2_text" class="form-control" value="<?= htmlspecialchars($slide['btn2_text'] ?? '') ?>">
                                            </div>
                                            <div class="col-md-6">
                                                <label>Button 2 Link</label>
                                                <input type="url" name="btn2_link" class="form-control" value="<?= htmlspecialchars($slide['btn2_link'] ?? '') ?>">
                                            </div>
                                        </div>
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                        <button type="submit" name="update_slide" class="btn btn-gradient">Save Changes</button>
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

    <!-- Featured Cards Table -->
    <h4 class="mb-4 fw-bold">Featured Cards (Maximum 3)</h4>

    <div class="table-responsive mb-5">
        <table class="table table-hover align-middle">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Title</th>
                    <th>Description</th>
                    <th>Icon</th>
                    <th>Link</th>
                    <th style="width:160px;">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($cards as $i => $card): ?>
                <tr>
                    <td><?= $i + 1 ?></td>
                    <td><?= htmlspecialchars($card['title'] ?: '<em>(no title)</em>') ?></td>
                    <td><?= htmlspecialchars($card['description'] ?: '<em>(empty)</em>') ?></td>
                    <td><i class="<?= htmlspecialchars($card['icon']) ?> fa-2x"></i></td>
                    <td><?= htmlspecialchars($card['link'] === '#' ? '(no link)' : $card['link']) ?></td>
                    <td>
                        <?php if ($card['id']): ?>
                            <button class="btn btn-sm btn-primary me-1" data-bs-toggle="modal" data-bs-target="#editCardModal<?= $card['id'] ?>">
                                <i class="fas fa-edit"></i>
                            </button>
                            <a href="?delete_card=<?= $card['id'] ?>" class="btn btn-sm btn-danger"
                               onclick="return confirm('Delete this featured card?')">
                                <i class="fas fa-trash"></i>
                            </a>
                        <?php else: ?>
                            <button class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#createCardModal">
                                <i class="fas fa-plus"></i> Create
                            </button>
                        <?php endif; ?>
                    </td>
                </tr>

                <?php if ($card['id']): ?>
                <!-- Edit Modal for existing card -->
                <div class="modal fade" id="editCardModal<?= $card['id'] ?>" tabindex="-1">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <form method="POST">
                                <div class="modal-header">
                                    <h5 class="modal-title">Edit Featured Card #<?= $card['id'] ?></h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                </div>
                                <div class="modal-body">
                                    <input type="hidden" name="card_id" value="<?= $card['id'] ?>">
                                    <div class="mb-3">
                                        <label class="form-label">Title (optional)</label>
                                        <input type="text" name="card_title" class="form-control" value="<?= htmlspecialchars($card['title']) ?>">
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Description <span class="text-danger">*</span></label>
                                        <textarea name="card_desc" class="form-control" rows="3" required><?= htmlspecialchars($card['description']) ?></textarea>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Icon Class (Font Awesome)</label>
                                        <input type="text" name="card_icon" class="form-control" value="<?= htmlspecialchars($card['icon']) ?>" placeholder="e.g. fas fa-users">
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Link URL (optional)</label>
                                        <input type="url" name="card_link" class="form-control" value="<?= htmlspecialchars($card['link']) ?>">
                                    </div>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                    <button type="submit" name="update_card" class="btn btn-gradient">Save Changes</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Create New Card Modal (shown when clicking "Create" on empty slot) -->
    <div class="modal fade" id="createCardModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <div class="modal-header">
                        <h5 class="modal-title">Create New Featured Card</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="card_id" value="">
                        <div class="mb-3">
                            <label class="form-label">Title (optional)</label>
                            <input type="text" name="card_title" class="form-control">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Description <span class="text-danger">*</span></label>
                            <textarea name="card_desc" class="form-control" rows="3" required></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Icon Class (Font Awesome)</label>
                            <input type="text" name="card_icon" class="form-control" value="fas fa-star" placeholder="e.g. fas fa-users">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Link URL (optional)</label>
                            <input type="url" name="card_link" class="form-control" value="#">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="update_card" class="btn btn-gradient">Create Card</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="text-center mt-5 mb-5">
        <a href="index.php" class="btn btn-outline-secondary px-5">← Back to Dashboard</a>
    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>
// Word counter for slide description
document.getElementById('slide_desc')?.addEventListener('input', function() {
    const words = this.value.trim().split(/\s+/).filter(w => w.length > 0).length;
    document.getElementById('wordCount').textContent = words;
    this.style.borderColor = words > 5 ? '#dc3545' : '#ced4da';
});

// Toastr settings
toastr.options = {
    closeButton: true,
    progressBar: true,
    positionClass: "toast-top-right",
    timeOut: 4500,
    extendedTimeOut: 1500,
    showEasing: "swing",
    hideEasing: "linear",
    showMethod: "fadeIn",
    hideMethod: "fadeOut"
};

toastr.options.onShown = function() {
    $('.toast').last().addClass('toast-bounce-in');
};

<?php if ($success): ?>
    toastr.success("<?= addslashes($success) ?>");
<?php endif; ?>

<?php if ($error): ?>
    toastr.error("<?= addslashes($error) ?>");
<?php endif; ?>

<?php if ($success || $error): ?>
    window.scrollTo({ top: 0, behavior: 'smooth' });
<?php endif; ?>
</script>

</body>
</html>