<?php
// Database connection
include '../includes/connect.php'; // Adjust the path to your actual database connection file

$uploadedVideos = [];
$success_message = '';
$error_message = '';

// Handle video URL submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['video_url'])) {
    $videoUrl = trim($_POST['video_url']);
    $description = trim($_POST['description']); // Get description from POST data
    
    // Extract video ID from YouTube URL
    preg_match('/(?:https?:\/\/)?(?:www\.)?youtu(?:be\.com\/(?:[^\/\n\s]+\/\S+\/|(?:v|e(?:mbed)?)\/|\S*?[?&]v=)|\.be\/)([a-zA-Z0-9_-]{11})/', $videoUrl, $matches);
    
    if (isset($matches[1])) {
        $videoId = $matches[1];

        // Check if the video ID already exists in the database
        try {
            $sql = "SELECT COUNT(*) FROM yvideos WHERE id = :id";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(['id' => $videoId]);
            $videoExists = $stmt->fetchColumn();

            if ($videoExists) {
                $error_message = "Video $videoId is already added. Please choose another one!";
            } else {
                // Insert into database
                $sql = "INSERT INTO yvideos (id, video_url, description) VALUES (:id, :video_url, :description)";
                $stmt = $pdo->prepare($sql);
                
                if ($stmt->execute(['id' => $videoId, 'video_url' => $videoUrl, 'description' => $description])) {
                    $success_message = "New video added successfully!";
                } else {
                    $error_message = "Failed to add video. Please try again! If the problem persists, contact the system admin.";
                }
            }
        } catch (PDOException $e) {
            $error_message = "Database error: " . $e->getMessage();
        }
    } else {
        $error_message = "Invalid YouTube URL.";
    }
}

// Handle video deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_slide'])) {
    $slideId = $_POST['slide_id'];

    try {
        // Delete from database
        $sql = "DELETE FROM yvideos WHERE id = :id";
        $stmt = $pdo->prepare($sql);

        if ($stmt->execute(['id' => $slideId])) {
            $success_message = "Video deleted successfully!";
        } else {
            $error_message = "Failed to delete video. Please try again!";
        }
    } catch (PDOException $e) {
        $error_message = "Database error: " . $e->getMessage();
    }
}

// Fetch existing videos from the database
try {
    $sql = "SELECT * FROM yvideos ORDER BY date DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $uploadedVideos = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error_message = "Failed to fetch videos: " . $e->getMessage();
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Youtube videos</title>
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
                <!-- Video URL Submission Form -->
                <form action="" method="post">
                       <div class="mb-3">
                       <label for="video_url" class="form-label">Add YouTube Video</label>
                       <input type="url" class="form-control" id="video_url" name="video_url" placeholder="Enter YouTube video URL" required>
                 </div>
                    <div class="mb-3">
                      <label for="description" class="form-label">Description</label>
                     <textarea class="form-control" id="description" name="description" placeholder="Describe Video link you just entered to users" rows="3" required></textarea>
                   </div>
                   <button type="submit" class="btn btn-primary">Add Video</button>
                </form>

                <!-- Display Uploaded Videos in a Table -->
                <div class="mt-4">
                <h5>Uploaded Videos</h5>
              <?php if ($uploadedVideos): ?>
               <table class="table table-bordered">
            <thead>
                <tr>
                    <th>Video ID</th>
                    <th>Video</th>
                    <th>Description</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($uploadedVideos as $video): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($video['id']); ?></td>
                        <td>
                            <iframe width="150" height="84" src="https://www.youtube.com/embed/<?php echo htmlspecialchars($video['id']); ?>" frameborder="0" allowfullscreen></iframe>
                        </td>
                        <td><?php echo htmlspecialchars($video['description']); ?></td>
                        <td>
                            <form action="" method="post">
                                <input type="hidden" name="slide_id" value="<?php echo htmlspecialchars($video['id']); ?>">
                                <button type="button" class="btn btn-danger btn-sm" onclick="confirmDelete('<?php echo htmlspecialchars($video['id']); ?>')">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
                </table>
                <?php else: ?>
                   <p>No videos uploaded yet.</p>
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
        Are you sure you want to delete this video?
      </div>
      <div class="modal-footer">
        <form id="deleteForm" action="" method="post">
          <input type="hidden" name="slide_id" id="slide_id">
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

    // Function to confirm video deletion
    function confirmDelete(slideId) {
        $('#slide_id').val(slideId);
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
