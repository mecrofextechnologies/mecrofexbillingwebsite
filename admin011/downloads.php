<?php
// Database connection
include '../includes/connect.php'; // Adjust the path to your actual database connection file

// Define the directory to save uploaded PDFs
$uploadDir = 'pdf_folder/';
$success_message = '';
$error_message = '';

// Ensure the upload directory exists
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

// Handle Job Advert Upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['job_pdf'])) {
    $file = $_FILES['job_pdf'];
    $fileName = basename($file['name']);
    $uploadFile = $uploadDir . $fileName;
    $position = $_POST['position'] ?? ''; // Retrieve position
    $deadline_date = $_POST['deadline_date'] ?? ''; // Retrieve deadline date

    // Validate file type and size here
    $allowedTypes = ['application/pdf'];
    if (in_array($file['type'], $allowedTypes) && $file['size'] < 5000000) { // 5MB limit
        try {
            // Check if the job advert already exists in the database
            $sql = "SELECT COUNT(*) FROM job_adverts WHERE position = :position AND deadline_date = :deadline_date";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(['position' => $position, 'deadline_date' => $deadline_date]);
            $advertExists = $stmt->fetchColumn();

            if ($advertExists) {
                $error_message = "Job advert already exists for the given position and deadline date!";
            } else {
                // Move the uploaded file to the pdf folder
                if (move_uploaded_file($file['tmp_name'], $uploadFile)) {
                    // Insert into database
                    $sql = "INSERT INTO job_adverts (position, deadline_date, pdf) VALUES (:position, :deadline_date, :pdf)";
                    $stmt = $pdo->prepare($sql);

                    if ($stmt->execute(['position' => $position, 'deadline_date' => $deadline_date, 'pdf' => $fileName])) {
                        $success_message = "New job advert uploaded successfully!";
                    } else {
                        $error_message = "Failed to upload job advert. Please try again!";
                    }
                } else {
                    $error_message = "Failed to upload job advert PDF.";
                }
            }
        } catch (PDOException $e) {
            $error_message = "Database error: " . $e->getMessage();
        }
    } else {
        $error_message = "Invalid file type or size for job advert.";
    }
}

// Handle Student Download Upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['student_pdf'])) {
    $file = $_FILES['student_pdf'];
    $fileName = basename($file['name']);
    $uploadFile = $uploadDir . $fileName;
    $document = $_POST['document'] ?? ''; // Retrieve document name

    // Validate file type and size here
    $allowedTypes = ['application/pdf'];
    if (in_array($file['type'], $allowedTypes) && $file['size'] < 5000000) { // 5MB limit
        try {
            // Check if the student document already exists in the database
            $sql = "SELECT COUNT(*) FROM student_downloads WHERE document = :document";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(['document' => $document]);
            $docExists = $stmt->fetchColumn();

            if ($docExists) {
                $error_message = "Document already exists!";
            } else {
                // Move the uploaded file to the pdf folder
                if (move_uploaded_file($file['tmp_name'], $uploadFile)) {
                    // Insert into database
                    $sql = "INSERT INTO student_downloads (document, pdf) VALUES (:document, :pdf)";
                    $stmt = $pdo->prepare($sql);

                    if ($stmt->execute(['document' => $document, 'pdf' => $fileName])) {
                        $success_message = "New student document uploaded successfully!";
                    } else {
                        $error_message = "Failed to upload student document. Please try again!";
                    }
                } else {
                    $error_message = "Failed to upload student document PDF.";
                }
            }
        } catch (PDOException $e) {
            $error_message = "Database error: " . $e->getMessage();
        }
    } else {
        $error_message = "Invalid file type or size for student document.";
    }
}

// Fetch job adverts from the database
try {
    $sql = "SELECT * FROM job_adverts";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $jobAdverts = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error_message = "Failed to fetch job adverts: " . $e->getMessage();
}

// Fetch student downloads from the database
try {
    $sql = "SELECT * FROM student_downloads";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $studentDownloads = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error_message = "Failed to fetch student downloads: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Downloads and Job Adverts</title>
    <link href="https://maxcdn.bootstrapcdn.com/bootstrap/5.1.3/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.css" rel="stylesheet">
</head>
<body>
<div class="container mt-5">
    <div class="col-lg-12">
        <div class="card">
            <div class="card-body">

                <!-- Job Advert Upload Form -->
                <form action="" method="post" enctype="multipart/form-data">
                    <h3>Upload Job Advert</h3>
                    <div class="mb-3">
                        <label for="position" class="form-label">Position</label>
                        <input type="text" class="form-control" id="position" name="position" required>
                    </div>
                    <div class="mb-3">
                        <label for="deadline_date" class="form-label">Set Deadline Date</label>
                        <input type="date" class="form-control" id="deadline_date" name="deadline_date" required>
                    </div>
                    <div class="mb-3">
                        <label for="job_pdf" class="form-label">Upload PDF</label>
                        <input type="file" class="form-control" id="job_pdf" name="job_pdf" required>
                    </div>
                    <button type="submit" class="btn btn-primary">Upload</button>
                </form>

                <!-- Display Job Adverts -->
                <div class="mt-4">
                    <h5>Job Adverts</h5>
                    <?php if ($jobAdverts): ?>
                        <table class="table table-bordered">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Position</th>
                                    <th>Deadline Date</th>
                                    <th>PDF</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($jobAdverts as $advert): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($advert['id']); ?></td>
                                        <td><?php echo htmlspecialchars($advert['position']); ?></td>
                                        <td><?php echo htmlspecialchars($advert['deadline_date']); ?></td>
                                        <td><a href="<?php echo htmlspecialchars($uploadDir . $advert['pdf']); ?>" target="_blank">Download PDF</a></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <p>No job adverts uploaded yet.</p>
                    <?php endif; ?>
                </div>

                <!-- Student Downloads Upload Form -->
                <form action="" method="post" enctype="multipart/form-data">
                    <h3>Upload Student Document</h3>
                    <div class="mb-3">
                        <label for="document" class="form-label">Document Name</label>
                        <input type="text" class="form-control" id="document" name="document" required>
                    </div>
                    <div class="mb-3">
                        <label for="student_pdf" class="form-label">Upload PDF</label>
                        <input type="file" class="form-control" id="student_pdf" name="student_pdf" required>
                    </div>
                    <button type="submit" class="btn btn-primary">Upload</button>
                </form>

                <!-- Display Student Downloads -->
                <div class="mt-4">
                    <h5>Student Downloads</h5>
                    <?php if ($studentDownloads): ?>
                        <table class="table table-bordered">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Document</th>
                                    <th>PDF</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($studentDownloads as $download): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($download['id']); ?></td>
                                        <td><?php echo htmlspecialchars($download['document']); ?></td>
                                        <td><a href="<?php echo htmlspecialchars($uploadDir . $download['pdf']); ?>" target="_blank">Download PDF</a></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <p>No student documents uploaded yet.</p>
                    <?php endif; ?>
                </div>

                <!-- Success and Error Messages -->
                <?php if ($success_message): ?>
                    <div class="alert alert-success"><?php echo $success_message; ?></div>
                <?php endif; ?>
                <?php if ($error_message): ?>
                    <div class="alert alert-danger"><?php echo $error_message; ?></div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
</body>
</html>
