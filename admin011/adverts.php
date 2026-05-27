<?php
// Database connection
include '../includes/connect.php'; // Adjust the path to your actual database connection file

// Define the directory to save uploaded photos
$uploadDir = 'uploads/';
$uploadedPhotos = [];
$success_message = '';
$error_message = '';

// Handle photo upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['photo'])) {
    $file = $_FILES['photo'];
    $fileName = basename($file['name']);
    $uploadFile = $uploadDir . $fileName;
    $description = $_POST['description'] ?? ''; // Retrieve description

    // Ensure the uploads directory exists
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    // Validate file type and size here
    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif'];
    if (in_array($file['type'], $allowedTypes) && $file['size'] < 5000000) { // 5MB limit
        $id = pathinfo($fileName, PATHINFO_FILENAME); // Generate an ID or use another identifier

        // Check if the slide ID already exists in the database
        try {
            $sql = "SELECT COUNT(*) FROM slides WHERE id = :id";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(['id' => $id]);
            $slideExists = $stmt->fetchColumn();

            if ($slideExists) {
                $error_message = "Slide $id Is Already Added Please Choose Another One!";
            } else {
                // Move the uploaded file to the uploads directory
                if (move_uploaded_file($file['tmp_name'], $uploadFile)) {
                    // Insert into database
                    $sql = "INSERT INTO adverts (id, image, description) VALUES (:id, :image, :description)";
                    $stmt = $pdo->prepare($sql);
                    
                    if ($stmt->execute(['id' => $id, 'image' => $fileName, 'description' => $description])) {
                        $success_message = "New advert uploaded successfully!";
                    } else {
                        $error_message = "Failed to upload an advert. Please try again! If the problem persists, contact the system admin.";
                    }
                } else {
                    $error_message = "Failed to upload photo.";
                }
            }
        } catch (PDOException $e) {
            $error_message = "Database error: " . $e->getMessage();
        }
    } else {
        $error_message = "Invalid file type or size.";
    }
}

// Handle slide deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_slide'])) {
    $slideId = $_POST['slide_id'];
    $slideImage = $_POST['slide_image'];

    try {
        // Delete from database
        $sql = "DELETE FROM adverts WHERE id = :id";
        $stmt = $pdo->prepare($sql);

        if ($stmt->execute(['id' => $slideId])) {
            // Remove file from filesystem
            $fileToDelete = $uploadDir . $slideImage;
            if (file_exists($fileToDelete)) {
                unlink($fileToDelete);
            }
            $success_message = "Advert deleted successfully!";
        } else {
            $error_message = "Failed to delete an advert. Please try again!";
        }
    } catch (PDOException $e) {
        $error_message = "Database error: " . $e->getMessage();
    }
}

// Fetch existing adverts from the database
try {
    $sql = "SELECT * FROM adverts";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $uploadedPhotos = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error_message = "Failed to fetch photos: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Adverts</title>
    <!-- Include Bootstrap CSS -->
    <link href="https://maxcdn.bootstrapcdn.com/bootstrap/5.1.3/css/bootstrap.min.css" rel="stylesheet">
    <!-- Include Toastr CSS -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.css" rel="stylesheet">
</head>
<body>
<div class="container mt-5">
    <div class="col-lg-12">
        <div class="card">
            <div class="card-body">
                <!-- Photo Upload Form -->
                <form action="" method="post" enctype="multipart/form-data">
                    <div class="mb-3">
                        <label for="photo" class="form-label">Upload an advert or banner</label>
                        <p><em>(1940 by 500 pixel recommended to maintain quality)</em></p>
                        <input type="file" class="form-control" id="photo" name="photo" required>
                    </div>
                    <div class="mb-3">
                        <label for="description" class="form-label">Description</label>
                        <textarea class="form-control" id="description" name="description" rows="3" required></textarea>
                    </div>
                    <button type="submit" class="btn btn-primary">Upload</button>
                </form>

                <!-- Display Uploaded Slides in a Table -->
                <div class="mt-4">
                    <h5>Adverts</h5>
                    <?php if ($uploadedPhotos): ?>
                        <table class="table table-bordered">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Image</th>
                                    <th>Description</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($uploadedPhotos as $photo): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($photo['id']); ?></td>
                                        <td><img src="<?php echo htmlspecialchars($uploadDir . $photo['image']); ?>" alt="<?php echo htmlspecialchars($photo['image']); ?>" class="img-fluid" width="150"></td>
                                        <td><?php echo htmlspecialchars($photo['description']); ?></td>
                                        <td>
                                            <form action="" method="post">
                                                <input type="hidden" name="slide_id" value="<?php echo htmlspecialchars($photo['id']); ?>">
                                                <input type="hidden" name="slide_image" value="<?php echo htmlspecialchars($photo['image']); ?>">
                                                <button type="button" class="btn btn-danger btn-sm" onclick="confirmDelete('<?php echo htmlspecialchars($photo['id']); ?>', '<?php echo htmlspecialchars($photo['image']); ?>')">Delete</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <p>No adverts uploaded yet.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Confirmation Modal -->
<div class="modal fade" id="confirmDeleteModal" tabindex="-1" aria-labelledby="confirmDeleteModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="confirmDeleteModalLabel">Confirm Deletion</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        Are you sure you want to delete this advert??
      </div>
      <div class="modal-footer">
        <form id="deleteForm" action="" method="post">
          <input type="hidden" name="slide_id" id="slide_id">
          <input type="hidden" name="slide_image" id="slide_image">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" name="delete_slide" class="btn btn-danger">Delete</button>
        </form>
      </div>
    </div>
  </div>
</div>

<!-- Include jQuery -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<!-- Include Toastr JS -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.js"></script>

<script>
    // Function to display Toastr success message
    function showSuccessToast(message) {
        toastr.options = {
            "closeButton": true,
            "debug": false,
            "newestOnTop": false,
            "progressBar": true,
            "positionClass": "toast-top-right",
            "preventDuplicates": true,
            "onclick": null,
            "showDuration": "300",
            "hideDuration": "1000",
            "timeOut": "5000",
            "extendedTimeOut": "1000",
            "showEasing": "swing",
            "hideEasing": "linear",
            "showMethod": "fadeIn",
            "hideMethod": "fadeOut"
        };
        toastr.success(message);
    }

    // Function to display Toastr error message
    function showErrorToast(message) {
        toastr.options = {
            "closeButton": true,
            "debug": false,
            "newestOnTop": false,
            "progressBar": true,
            "positionClass": "toast-top-right",
            "preventDuplicates": true,
            "onclick": null,
            "showDuration": "300",
            "hideDuration": "1000",
            "timeOut": "5000",
            "extendedTimeOut": "1000",
            "showEasing": "swing",
            "hideEasing": "linear",
            "showMethod": "fadeIn",
            "hideMethod": "fadeOut"
        };
        toastr.error(message);
    }

    // Function to confirm slide deletion
    function confirmDelete(slideId, slideImage) {
        $('#slide_id').val(slideId);
        $('#slide_image').val(slideImage);
        $('#confirmDeleteModal').modal('show');
    }

    // Display Toastr messages based on PHP success or error messages
    <?php if ($success_message): ?>
        showSuccessToast('<?php echo $success_message; ?>');
    <?php endif; ?>

    <?php if ($error_message): ?>
        showErrorToast('<?php echo $error_message; ?>');
    <?php endif; ?>
</script>

<!-- Include Bootstrap JS (Ensure you include Popper.js if using Bootstrap's JS components) -->
<script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.6/dist/umd/popper.min.js"></script>
<script src="https://maxcdn.bootstrapcdn.com/bootstrap/5.1.3/js/bootstrap.min.js"></script>
</body>
</html>
