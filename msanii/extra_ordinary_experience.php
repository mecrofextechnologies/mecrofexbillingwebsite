<?php
include '../includes/connect.php';

// Function to handle file uploads
function uploadFile($file, $folder) {
    // Define the target directory based on the folder
    if ($folder == 'videos') {
        $targetDir = "videos/"; // Path for video files
    } elseif ($folder == 'thumbnails') {
        $targetDir = "uploads/"; // Path for thumbnail images
    } else {
        echo "Invalid folder specified.";
        return false;
    }

    // Check if the directory exists, if not, create it
    if (!is_dir($targetDir)) {
        mkdir($targetDir, 0755, true);
    }

    $targetFile = $targetDir . basename($file["name"]);
    $uploadOk = 1;
    $fileType = strtolower(pathinfo($targetFile, PATHINFO_EXTENSION));

    // Check if file is a valid video or image
    if ($folder == "videos" && $fileType != "mp4") {
        echo "Sorry, only MP4 files are allowed.";
        $uploadOk = 0;
    } elseif ($folder == "thumbnails" && !in_array($fileType, ["jpg", "jpeg", "png"])) {
        echo "Sorry, only JPG, JPEG, and PNG files are allowed.";
        $uploadOk = 0;
    }

    if ($uploadOk == 0) {
        return false;
    } else {
        if (move_uploaded_file($file["tmp_name"], $targetFile)) {
            return basename($file["name"]);
        } else {
            echo "Sorry, there was an error uploading your file.";
            return false;
        }
    }
}


// Handle Add Video Form Submission
if (isset($_POST['add_video'])) {
    $course = $_POST['course'];
    $description = $_POST['description'];
    $president_name = $_POST['president_name'];
    $president_quote = $_POST['president_quote'];
    
    $video_path = uploadFile($_FILES['video_file'], 'videos');
    $thumbnail_path = uploadFile($_FILES['thumbnail'], 'thumbnails');

    if ($video_path && $thumbnail_path) {
        $stmt = $pdo->prepare("INSERT INTO videos (video_path, thumbnail_path, course, description, president_name, president_quote) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$video_path, $thumbnail_path, $course, $description, $president_name, $president_quote]);
        echo "Video added successfully.";
    }
}

// Handle Edit Video Form Submission
if (isset($_POST['update_video'])) {
    $video_id = $_POST['video_id'];
    $course = $_POST['course'];
    $description = $_POST['description'];
    $president_name = $_POST['president_name'];
    $president_quote = $_POST['president_quote'];

    $video_path = ($_FILES['video_file']['error'] !== UPLOAD_ERR_NO_FILE) ? uploadFile($_FILES['video_file'], 'videos') : $_POST['current_video_path'];
    $thumbnail_path = ($_FILES['thumbnail']['error'] !== UPLOAD_ERR_NO_FILE) ? uploadFile($_FILES['thumbnail'], 'thumbnails') : $_POST['current_thumbnail_path'];

    $stmt = $pdo->prepare("UPDATE videos SET video_path = ?, thumbnail_path = ?, course = ?, description = ?, president_name = ?, president_quote = ? WHERE id = ?");
    $stmt->execute([$video_path, $thumbnail_path, $course, $description, $president_name, $president_quote, $video_id]);
    echo "Video updated successfully.";
}

// Handle Delete Video Form Submission
if (isset($_POST['delete_id'])) {
    $video_id = $_POST['delete_id'];

    // Fetch current paths for deletion
    $stmt = $pdo->prepare("SELECT video_path, thumbnail_path FROM videos WHERE id = ?");
    $stmt->execute([$video_id]);
    $video = $stmt->fetch(PDO::FETCH_ASSOC);

    // Delete the video and thumbnail files
    if (file_exists("../uploads/videos/" . $video['video_path'])) {
        unlink("../uploads/videos/" . $video['video_path']);
    }
    if (file_exists("../uploads/thumbnails/" . $video['thumbnail_path'])) {
        unlink("../uploads/thumbnails/" . $video['thumbnail_path']);
    }

    // Delete the video record from the database
    $stmt = $pdo->prepare("DELETE FROM videos WHERE id = ?");
    $stmt->execute([$video_id]);

    echo "Video deleted successfully.";
}

// Fetch videos to display
$stmt = $pdo->query("SELECT * FROM videos");
$videos = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Videos</title>
    <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/4.0.0/css/bootstrap.min.css">
    <script src="https://code.jquery.com/jquery-3.2.1.slim.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/popper.js/1.11.0/umd/popper.min.js"></script>
    <script src="https://maxcdn.bootstrapcdn.com/bootstrap/4.0.0/js/bootstrap.min.js"></script>
    <style>
        .modal-dialog {
            max-width: 90%;
            margin: 1.75rem auto;
        }
        .card-body {
            padding: 0;
        }
        .table img {
            width: 100%;
            height: auto;
        }
    </style>
</head>
<body>
    <div class="container mt-5">
        <h2 class="mb-4">Manage Videos</h2>
        <!-- Add Video Button -->
        <button type="button" class="btn btn-primary mb-4" data-toggle="modal" data-target="#addModa">
            Add New Video
        </button>

        <!-- Add Video Modal -->
        <div class="modal fade" id="addModal" tabindex="-1" role="dialog" aria-labelledby="addModalLabel" aria-hidden="true">
            <div class="modal-dialog" role="document">
                <form method="post" action="" enctype="multipart/form-data">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" id="addModalLabel">Add New Video</h5>
                            <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                                <span aria-hidden="true">&times;</span>
                            </button>
                        </div>
                        <div class="modal-body">
                            <div class="form-group">
                                <label for="video_file">Select Video:</label>
                                <input type="file" class="form-control-file" name="video_file" id="video_file" accept="video/*" required>
                            </div>
                            <div class="form-group">
                                <label for="thumbnail">Thumbnail Image:</label>
                                <input type="file" class="form-control-file" name="thumbnail" id="thumbnail" accept="image/*" required>
                            </div>
                            <div class="form-group">
                                <label for="course">Course Name:</label>
                                <input type="text" class="form-control" name="course" id="course" placeholder="Enter course name" required>
                            </div>
                            <div class="form-group">
                                <label for="description">Description:</label>
                                <textarea class="form-control" name="description" id="description" rows="3" placeholder="Enter description" required></textarea>
                            </div>
                            <div class="form-group">
                                <label for="president_name">President's Name:</label>
                                <input type="text" class="form-control" name="president_name" id="president_name" placeholder="Enter president's name" required>
                            </div>
                            <div class="form-group">
                                <label for="president_quote">Quote of President:</label>
                                <textarea class="form-control" name="president_quote" id="president_quote" rows="2" placeholder="Enter president's quote" required></textarea>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                            <button type="submit" name="add_video" class="btn btn-primary">Add Video</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- Edit Video Modal -->
        <div class="modal fade" id="editModal" tabindex="-1" role="dialog" aria-labelledby="editModalLabel" aria-hidden="true">
            <div class="modal-dialog" role="document">
                <form method="post" action="" enctype="multipart/form-data">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" id="editModalLabel">Edit Video</h5>
                            <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                                <span aria-hidden="true">&times;</span>
                            </button>
                        </div>
                        <div class="modal-body">
                            <input type="hidden" name="video_id" id="edit_video_id">
                            <input type="hidden" name="current_video_path" id="current_video_path">
                            <input type="hidden" name="current_thumbnail_path" id="current_thumbnail_path">
                            <div class="form-group">
                                <label for="edit_course">Course Name:</label>
                                <input type="text" class="form-control" name="course" id="edit_course" required>
                            </div>
                            <div class="form-group">
                                <label for="edit_description">Description:</label>
                                <textarea class="form-control" name="description" id="edit_description" rows="3" required></textarea>
                            </div>
                            <div class="form-group">
                                <label for="edit_president_name">President's Name:</label>
                                <input type="text" class="form-control" name="president_name" id="edit_president_name" required>
                            </div>
                            <div class="form-group">
                                <label for="edit_president_quote">Quote of President:</label>
                                <textarea class="form-control" name="president_quote" id="edit_president_quote" rows="2" required></textarea>
                            </div>
                            <div class="form-group">
                                <label for="edit_video_file">Change Video:</label>
                                <input type="file" class="form-control-file" name="video_file" id="edit_video_file" accept="video/*">
                            </div>
                            <div class="form-group">
                                <label for="edit_thumbnail">Change Thumbnail:</label>
                                <input type="file" class="form-control-file" name="thumbnail" id="edit_thumbnail" accept="image/*">
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                            <button type="submit" name="update_video" class="btn btn-primary">Update Video</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- Video Table -->
        <table class="table table-bordered">
            <thead>
                <tr>
                    <th>Video</th>
                    <th>Thumbnail</th>
                    <th>Course Name</th>
                    <th>Description</th>
                    <th>President's Name</th>
                    <th>President's Quote</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($videos as $video): ?>
                <tr>
                    <td>
                        <video width="150" controls>
                            <source src="videos/<?= htmlspecialchars($video['video_path']) ?>" type="video/mp4">
                            Your browser does not support the video tag.
                        </video>
                    </td>
                    <td>
                        <img src="uploads/<?= htmlspecialchars($video['thumbnail_path']) ?>" alt="Thumbnail" width="100">
                    </td>
                    <td><?= htmlspecialchars($video['course']) ?></td>
                    <td><?= htmlspecialchars($video['description']) ?></td>
                    <td><?= htmlspecialchars($video['president_name']) ?></td>
                    <td><?= htmlspecialchars($video['president_quote']) ?></td>
                    <td>
                        <button class="btn btn-warning btn-sm" onclick="editVideo(<?= $video['id'] ?>, '<?= htmlspecialchars($video['course']) ?>', '<?= htmlspecialchars($video['description']) ?>', '<?= htmlspecialchars($video['president_name']) ?>', '<?= htmlspecialchars($video['president_quote']) ?>', '<?= htmlspecialchars($video['video_path']) ?>', '<?= htmlspecialchars($video['thumbnail_path']) ?>')">Edit</button>
                        <form method="post" action="" style="display:inline-block;">
                            <input type="hidden" name="delete_i" value="<?= $video['id'] ?>">
                            <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('You cannot delete this content, update instead by clicking edit');">Delete</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <script>
        function editVideo(id, course, description, president_name, president_quote, video_path, thumbnail_path) {
            document.getElementById('edit_video_id').value = id;
            document.getElementById('edit_course').value = course;
            document.getElementById('edit_description').value = description;
            document.getElementById('edit_president_name').value = president_name;
            document.getElementById('edit_president_quote').value = president_quote;
            document.getElementById('current_video_path').value = video_path;
            document.getElementById('current_thumbnail_path').value = thumbnail_path;
            $('#editModal').modal('show');
        }
    </script>
</body>
</html>
