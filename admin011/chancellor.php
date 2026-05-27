<?php
// Database connection
include '../includes/connect.php'; // Adjust the path to your actual database connection file

$success_message = '';
$error_message = '';

// Handle form submission for inserting or updating data into the chancellor table
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit'])) {
    $name = $_POST['name'] ?? ''; // Retrieve name
    $email = $_POST['email'] ?? ''; // Retrieve email
    $academic_qualifications = $_POST['academic_qualifications'] ?? ''; // Retrieve academic qualifications
    $description = $_POST['description'] ?? ''; // Retrieve description

    // Initialize profile image path as null
    $profile_image_path = null;

    // Handle profile image upload (optional)
    if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] == 0) {
        $profile_image_name = $_FILES['profile_image']['name'];
        $profile_image_tmp = $_FILES['profile_image']['tmp_name'];
        $profile_image_ext = pathinfo($profile_image_name, PATHINFO_EXTENSION);
        
        // Allowed file types for the image
        $allowed_image_ext = ['jpg', 'jpeg', 'png', 'gif'];

        if (in_array(strtolower($profile_image_ext), $allowed_image_ext)) {
            // Generate a unique name for the image to avoid collisions
            $profile_image_new_name = uniqid('', true) . '.' . $profile_image_ext;
            $profile_image_upload_dir = '../uploads_folder/'; // Directory where the image will be saved
            $profile_image_upload_path = $profile_image_upload_dir . $profile_image_new_name;

            // Move the uploaded image to the desired location
            if (move_uploaded_file($profile_image_tmp, $profile_image_upload_path)) {
                $profile_image_path = $profile_image_upload_path; // Set the profile image path
            } else {
                $error_message = "Profile image upload failed!";
            }
        } else {
            $error_message = "Invalid image type. Only JPG, JPEG, PNG, and GIF files are allowed.";
        }
    }

    try {
        // Check if the chancellor with this email already exists
        $sql = "SELECT * FROM chancellor WHERE email = :email";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['email' => $email]);
        $existingRecord = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existingRecord) {
            // If record exists, update the existing record
            $sql = "UPDATE chancellor SET 
                        name = :name,
                        academic_qualifications = :academic_qualifications,
                        profile_image = :profile_image,
                        description = :description 
                    WHERE email = :email";
            $stmt = $pdo->prepare($sql);
            if ($stmt->execute([
                'name' => $name,
                'academic_qualifications' => $academic_qualifications,
                'profile_image' => $profile_image_path,
                'description' => $description,
                'email' => $email
            ])) {
                $success_message = "Chancellor record updated successfully!";
            } else {
                $error_message = "Failed to update record. Please try again!";
            }
        } else {
            // If doesn't exist, insert new record
            $sql = "INSERT INTO chancellor (name, email, academic_qualifications, profile_image, description) 
                    VALUES (:name, :email, :academic_qualifications, :profile_image, :description)";
            $stmt = $pdo->prepare($sql);
            if ($stmt->execute([
                'name' => $name,
                'email' => $email,
                'academic_qualifications' => $academic_qualifications,
                'profile_image' => $profile_image_path,
                'description' => $description
            ])) {
                $success_message = "New Chancellor record added successfully!";
            } else {
                $error_message = "Failed to add record. Please try again!";
            }
        }
    } catch (PDOException $e) {
        $error_message = "Database error: " . $e->getMessage();
    }
}

// Handle record deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_record'])) {
    $recordId = $_POST['record_id'];

    try {
        // Delete from database
        $sql = "DELETE FROM chancellor WHERE id = :id";
        $stmt = $pdo->prepare($sql);
        if ($stmt->execute(['id' => $recordId])) {
            $success_message = "Record deleted successfully!";
        } else {
            $error_message = "Failed to delete record. Please try again!";
        }
    } catch (PDOException $e) {
        $error_message = "Database error: " . $e->getMessage();
    }
}

// Fetch existing records from the database
try {
    $sql = "SELECT * FROM chancellor";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $chancellorRecords = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error_message = "Failed to fetch records: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Chancellor</title>
    <link href="https://maxcdn.bootstrapcdn.com/bootstrap/5.1.3/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.css" rel="stylesheet">
</head>
<body>
<div class="container mt-5">
    <div class="col-lg-12">
        <div class="card">
            <div class="card-body">
                <!-- Chancellor Record Form -->
                <form action="" method="post" enctype="multipart/form-data">
                    <div class="mb-3">
                        <label for="name" class="form-label">Chancellor Name</label>
                        <input type="text" class="form-control" id="name" name="name" required>
                    </div>
                    
                    <div class="mb-3">
                        <label for="email" class="form-label">Chancellor Email</label>
                        <input type="email" class="form-control" id="email" name="email" required>
                    </div>
                    <div class="mb-3">
                        <label for="academic_qualifications" class="form-label">Academic Qualifications</label>
                        <textarea class="form-control" id="academic_qualifications" name="academic_qualifications" rows="3" required></textarea>
                    </div>
                    <div class="mb-3">
                        <label for="description" class="form-label">Description</label>
                        <textarea class="form-control" id="description" name="description" rows="3"></textarea>
                    </div>
                    
                    <div class="mb-3">
                        <label for="profile_image" class="form-label">Profile Image (Optional)</label>
                        <input type="file" class="form-control" id="profile_image" name="profile_image" accept="image/*">
                    </div>
                    <button type="submit" name="submit" class="btn btn-primary">Submit</button>
                </form>

                <!-- Display Chancellor Records -->
                <div class="mt-4">
                    <h5>Chancellor Records</h5>
                    <?php if ($chancellorRecords): ?>
                        <table class="table table-bordered">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Name</th>
                                    <th>Email</th>
                                    <th>Academic Qualifications</th>
                                    <th>Description</th>
                                    <th>Profile Image</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($chancellorRecords as $record): ?>
                                    <tr>
                                        <td><?php echo $record['id']; ?></td>
                                        <td><?php echo $record['name']; ?></td>
                                        <td><?php echo $record['email']; ?></td>
                                        <td><?php echo $record['academic_qualifications']; ?></td>
                                        <td><?php echo $record['description']; ?></td>
                                        <td>
                                            <img src="<?php echo $record['profile_image'] ? htmlspecialchars($record['profile_image']) : '../assets/img/profile.png'; ?>" alt="Profile Image" style="width: 50px; height: 50px; object-fit: cover;">
                                        </td>
                                        <td>
                                            <form action="" method="post" onsubmit="return confirm('Are you sure you want to delete this record?');">
                                                <input type="hidden" name="record_id" value="<?php echo $record['id']; ?>">
                                                <button type="submit" name="delete_record" class="btn btn-danger">Delete</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <p>No records found!</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.js"></script>

<script>
// Display success or error messages using Toastr
<?php if ($success_message): ?>
    toastr.success("<?php echo $success_message; ?>");
<?php elseif ($error_message): ?>
    toastr.error("<?php echo $error_message; ?>");
<?php endif; ?>
</script>

</body>
</html>
