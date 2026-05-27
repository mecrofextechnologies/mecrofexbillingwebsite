<?php
include '../includes/connect.php';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $id = bin2hex(random_bytes(7)); // 14-character unique ID
    $description = strip_tags($_POST['description']);
    $thumbnail = '';

    if (isset($_FILES['thumbnail']) && $_FILES['thumbnail']['error'] === UPLOAD_ERR_OK) {
        $uploads_dir = '../uploads_folder/';
        if (!is_dir($uploads_dir)) {
            mkdir($uploads_dir, 0777, true);
        }

        $filename = time() . '_' . basename($_FILES['thumbnail']['name']); // Add timestamp to avoid duplicates
        $target_path = $uploads_dir . $filename;

        if (move_uploaded_file($_FILES['thumbnail']['tmp_name'], $target_path)) {
            $thumbnail = $target_path;
        }
    }

    // Insert into photo_gallery table
    $stmt = $pdo->prepare("INSERT INTO photo_gallery (id, description, thumbnail, date_created) VALUES (?, ?, ?, NOW())");
    $stmt->execute([$id, $description, $thumbnail]);

    // Return JS for toast and redirect to same page
    echo "<script>
        $(document).ready(function() {
            toastr.success('Photo uploaded successfully.', '', {
                timeOut: 2000,
                closeButton: true,
                progressBar: true,
                positionClass: 'toast-top-right'
            });
            setTimeout(function(){
                window.location.href = 'index.php?page=new_photo_gallery';
            }, 750);
        });
    </script>";
    exit;
}

// Fetch existing photos
$photos = $pdo->query("SELECT * FROM photo_gallery ORDER BY date_created DESC")->fetchAll(PDO::FETCH_ASSOC);
?>

<!-- Upload Form -->
<div class="col-lg-12">
    <div class="card">
        <div class="card-body">
            <form action="" id="manage_media" method="POST" enctype="multipart/form-data">
                <input type="hidden" name="id" value="">

                <div class="col-md-6">
                    <div class="form-group">
                        <label class="control-label">Upload Photo</label>
                        <input type="file" name="thumbnail" class="form-control form-control-sm" required>
                    </div>
                </div>

                <div class="col-md-12">
                    <div class="form-group">
                        <label class="control-label">Description</label>
                        <textarea name="description" cols="30" rows="5" class="form-control summernote"></textarea>
                    </div>
                </div>

                <hr>
                <div class="col-lg-12 text-right justify-content-center d-flex">
                    <button class="btn btn-primary mr-2" type="submit">Save</button>
                    <button class="btn btn-secondary" type="reset">Clear</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Display Uploaded Photos -->
    <div class="card mt-4">
        <div class="card-header">
            <h5>Uploaded Photos</h5>
        </div>
        <div class="card-body">
            <div class="row">
                <?php if (count($photos) > 0): ?>
                    <?php foreach ($photos as $photo): ?>
                        <div class="col-md-3 mb-4">
                            <div class="card">
                                <img src="<?= htmlspecialchars($photo['thumbnail']) ?>" class="card-img-top" style="max-height: 200px; object-fit: cover;" alt="Photo">
                                <div class="card-body">
                                    <p class="card-text"><?= nl2br(htmlspecialchars($photo['description'])) ?></p>
                                    <small class="text-muted">Uploaded: <?= date("M d, Y", strtotime($photo['date_created'])) ?></small>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="col-12">
                        <p class="text-muted">No photos uploaded yet.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- JavaScript (AJAX Form Submission) -->
<script>
    $(document).ready(function() {
        $('#manage_media').submit(function(e) {
            e.preventDefault();
            start_load(); // Optional loading indicator

            $.ajax({
                url: '',
                data: new FormData(this),
                cache: false,
                contentType: false,
                processData: false,
                method: 'POST',
                success: function(resp) {
                    $('body').append(resp);
                }
            });
        });
    });
</script>
