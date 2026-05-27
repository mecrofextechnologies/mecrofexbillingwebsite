<?php
include '../includes/connect.php'; // Ensure this path is correct

// Initialize variables for form data
$firstname = $middlename = $lastname = $contact = $email = $password = $cpass = $role = '';
$errors = [];

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Retrieve and sanitize form data
    $firstname = trim($_POST['firstname']);
    $middlename = trim($_POST['middlename']);
    $lastname = trim($_POST['lastname']);
    $contact = trim($_POST['contact']);
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $cpass = $_POST['cpass'];
    $role = intval($_POST['role']); // Assuming role is an integer value

    // Password validation
    if (empty($firstname) || empty($lastname) || empty($contact) || empty($email) || empty($password) || empty($cpass)) {
        $errors[] = "All fields are required.";
    }

    if ($password !== $cpass) {
        $errors[] = "Passwords do not match.";
    }

    // Check if email already exists
    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare("SELECT * FROM admin WHERE email = :email LIMIT 1");
            $stmt->execute(['email' => $email]);
            if ($stmt->rowCount() > 0) {
                $errors[] = "Email is already registered.";
            }
        } catch (PDOException $e) {
            $errors[] = "Database error: " . $e->getMessage();
        }
    }

    if (empty($errors)) {
        // Generate a unique admin_id
        $admin_id = uniqid('admin_', true); // Generate a unique ID

        // Hash the password
        $hashed_password = password_hash($password, PASSWORD_BCRYPT);

        // Insert data into the database
        try {
            $stmt = $pdo->prepare("INSERT INTO admin (admin_id, firstname, middlename, lastname, contact, email, password, role) 
                                    VALUES (:admin_id, :firstname, :middlename, :lastname, :contact, :email, :password, :role)");
            $stmt->execute([
                'admin_id' => $admin_id,
                'firstname' => $firstname,
                'middlename' => $middlename,
                'lastname' => $lastname,
                'contact' => $contact,
                'email' => $email,
                'password' => $hashed_password,
                'role' => $role
            ]);
            $success_message = "New Moderator Added Successfully";
        } catch (PDOException $e) {
            $errors[] = "Database error: " . $e->getMessage();
        }
    }
}
?>


<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>New Moderator</title>
    <link rel="stylesheet" href="path/to/your/bootstrap.min.css"> <!-- Add your CSS path -->
    <script src="path/to/your/jquery.min.js"></script> <!-- Add your JS path -->
    <script src="path/to/your/bootstrap.min.js"></script> <!-- Add your JS path -->
</head>
<body>
    <div class="container mt-5">
        <div class="card">
            <div class="card-body">
                <h3 class="card-title">Add New Moderator</h3>
                <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger">
                        <?php foreach ($errors as $error): ?>
                            <p><?php echo htmlspecialchars($error); ?></p>
                        <?php endforeach; ?>
                    </div>
                <?php elseif (isset($success_message)): ?>
                    <div class="alert alert-success">
                        <p><?php echo htmlspecialchars($success_message); ?></p>
                    </div>
                <?php endif; ?>
                <form action="" method="POST" id="manage_staff">
                    <div class="row">
                        <div class="col-md-6 border-right">
                            <b class="text-muted">Personal Information</b>
                            <div class="form-group">
                                <label for="firstname" class="control-label">First Name</label>
                                <input type="text" name="firstname" id="firstname" class="form-control form-control-sm" required>
                            </div>
                            <div class="form-group">
                                <label for="middlename" class="control-label">Middle Name</label>
                                <input type="text" name="middlename" id="middlename" class="form-control form-control-sm" required>
                            </div>
                            <div class="form-group">
                                <label for="lastname" class="control-label">Last Name</label>
                                <input type="text" name="lastname" id="lastname" class="form-control form-control-sm" required>
                            </div>
                            <div class="form-group">
                                <label for="contact" class="control-label">Contact No.</label>
                                <input type="text" name="contact" id="contact" class="form-control form-control-sm" required value="<?php echo htmlspecialchars($contact); ?>">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="email" class="control-label">Email</label>
                                <input type="email" class="form-control form-control-sm" name="email" id="email" required value="<?php echo htmlspecialchars($email); ?>">
                                <small id="msg"></small>
                            </div>
                            <div class="form-group">
                                <label for="password" class="control-label">Password</label>
                                <input type="password" class="form-control form-control-sm" name="password" id="password">
                                <small><i></i></small>
                            </div>
                            <div class="form-group">
                                <label for="cpass" class="label control-label">Confirm Password</label>
                                <input type="password" class="form-control form-control-sm" name="cpass" id="cpass">
                                <small id="pass_match" data-status=''></small>
                            </div>
                            <!-- Add Role Dropdown -->
                            <div class="form-group">
                                <label for="role" class="control-label">Role</label>
                                <select name="role" id="role" class="form-control form-control-sm">
                                    <option value="1" <?php echo ($role == 1) ? 'selected' : ''; ?>>Role 1</option>
                                    <option value="2" <?php echo ($role == 2) ? 'selected' : ''; ?>>Role 2</option>
                                </select>
                            </div>
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
    </div>
</body>
</html>
