<?php
ob_start();


use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require '../vendor/autoload.php';
include '../msanii/includes/connect.php'; // Adjust path if needed

// Initialize variables
$firstname = $middlename = $lastname = $contact = $email = $role = '';
$errors = [];
$success_message = '';

// Get current user's role from session
$user_role = isset($_SESSION['user_role']) ? intval($_SESSION['user_role']) : 1;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $firstname   = trim($_POST['firstname'] ?? '');
    $middlename  = trim($_POST['middlename'] ?? '');
    $lastname    = trim($_POST['lastname'] ?? '');
    $contact     = trim($_POST['contact'] ?? '');
    $email       = trim($_POST['email'] ?? '');
    $role        = intval($_POST['role'] ?? 1);

    // Basic validation
    if (empty($firstname) || empty($lastname) || empty($contact) || empty($email)) {
        $errors[] = "All required fields must be filled.";
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Invalid email format.";
    }

    // Check email uniqueness
    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM admin WHERE email = ?");
            $stmt->execute([$email]);
            if ($stmt->fetchColumn() > 0) {
                $errors[] = "This email is already registered.";
            }
        } catch (PDOException $e) {
            $errors[] = "Database error: " . $e->getMessage();
        }
    }

    if (empty($errors)) {
        // Generate random 8-char password
        $password = substr(str_shuffle('abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'), 0, 8);
        $hashed_password = password_hash($password, PASSWORD_BCRYPT);

        // Generate 6-digit verification code
        $verification_code = rand(100000, 999999);

        try {
            // Insert new admin
            $stmt = $pdo->prepare("
                INSERT INTO admin (firstname, middlename, lastname, contact, email, password, role)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$firstname, $middlename, $lastname, $contact, $email, $hashed_password, $role]);

            // Send email with PHPMailer
            $mail = new PHPMailer(true);
            $mail->isSMTP();
            $mail->Host       = 'mail.keychele.co.ke';
            $mail->SMTPAuth   = true;
            $mail->Username   = 'no-reply@keychele.co.ke';
            $mail->Password   = 'King20241064.';
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            $mail->Port       = 465;

            $mail->setFrom('no-reply@keychele.co.ke', 'Keychele Admin Account');
            $mail->addAddress($email, $firstname);
            $mail->isHTML(true);
            $mail->Subject = 'Welcome - Your Admin Account Details';

            $login_link = 'https://keychele.co.ke/admin011/login.php';

            $mail->Body = "
            <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #e0e0e0; border-radius: 8px;'>
                <h2 style='color: #1e3a8a;'>Welcome to Keychele Admin Panel</h2>
                <p>Dear <strong>" . htmlspecialchars($firstname) . "</strong>,</p>
                <p>You have been added as an administrator.</p>
                <p><strong>Your login credentials:</strong></p>
                <ul>
                    <li><strong>Email:</strong> " . htmlspecialchars($email) . "</li>
                    <li><strong>Password:</strong> <code>" . htmlspecialchars($password) . "</code> (change after first login)</li>
                </ul>
                <p><strong>Verification Code:</strong> <strong style='font-size:1.4em;'>" . $verification_code . "</strong></p>
                <p style='margin-top: 25px;'>
                    <a href='$login_link' style='background:#1e3a8a;color:white;padding:12px 24px;border-radius:6px;text-decoration:none;font-weight:bold;'>Login Now</a>
                </p>
                <p style='color:#666;font-size:0.9em;margin-top:30px;'>
                    For security, please change your password immediately after logging in.
                </p>
            </div>";

            $mail->AltBody = strip_tags(str_replace(['<br>','</p>','<p>'], "\n", $mail->Body));

            $mail->send();

            $success_message = "New moderator added successfully. Login details & verification code sent to <strong>" . htmlspecialchars($email) . "</strong>.";
            // Reset form
            $firstname = $middlename = $lastname = $contact = $email = $role = '';

        } catch (Exception $e) {
            $errors[] = "Failed to send welcome email: " . $mail->ErrorInfo;
        } catch (PDOException $e) {
            $errors[] = "Database insertion failed: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add New Moderator | Admin Panel</title>

    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

    <!-- Toastr CSS -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.css" rel="stylesheet">

    <style>
        body {
            background: linear-gradient(135deg, #f0f4f8, #d9e2ec);
            min-height: 100vh;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .card {
            border: none;
            border-radius: 16px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.12);
            overflow: hidden;
        }
        .card-header {
            background: linear-gradient(90deg, #1e3a8a, #3b82f6);
            color: white;
            padding: 1.5rem;
            border-bottom: none;
        }
        .form-label {
            font-weight: 500;
            color: #374151;
        }
        .form-control, .form-select {
            border-radius: 8px;
            border: 1px solid #d1d5db;
            padding: 0.65rem 1rem;
            transition: all 0.2s;
        }
        .form-control:focus, .form-select:focus {
            border-color: #3b82f6;
            box-shadow: 0 0 0 0.25rem rgba(59,130,246,0.25);
        }
        .input-group-text {
            background: #f3f4f6;
            border-radius: 8px 0 0 8px;
        }
        .btn-primary {
            background: #1e3a8a;
            border: none;
            padding: 0.75rem 1.8rem;
            border-radius: 8px;
            font-weight: 600;
            transition: all 0.3s;
        }
        .btn-primary:hover {
            background: #1e40af;
            transform: translateY(-2px);
        }
        .btn-secondary {
            border-radius: 8px;
            padding: 0.75rem 1.8rem;
        }

        /* ────────────────────────────────────────────────
           Modern, clean, professional Toastr redesign
           ──────────────────────────────────────────────── */
        @keyframes slideInRight {
            from { transform: translateX(120%); opacity: 0; }
            to   { transform: translateX(0);    opacity: 1; }
        }
        @keyframes fadeOut {
            from { opacity: 1; }
            to   { opacity: 0; }
        }
        .toast {
            animation: slideInRight 0.4s ease-out forwards !important;
            border-radius: 8px !important;
            box-shadow: 0 6px 20px rgba(0,0,0,0.15) !important;
            border: none !important;
            border-left: 5px solid #ccc !important;
            background: #ffffff !important;
            color: #1f2937 !important;
            font-family: 'Segoe UI', sans-serif !important;
            padding: 14px 18px !important;
            min-width: 320px !important;
            max-width: 420px !important;
            overflow: hidden !important;
            margin-bottom: 12px !important;
        }
        .toast-title {
            font-weight: 600 !important;
            font-size: 1.05rem !important;
            margin-bottom: 4px !important;
        }
        .toast-message {
            font-size: 0.95rem !important;
            line-height: 1.45 !important;
            margin: 0 !important;
        }
        .toast-progress {
            position: absolute !important;
            bottom: 0 !important;
            left: 0 !important;
            height: 3px !important;
            background: #e5e7eb !important;
            width: 100% !important;
        }
        .toast-progress-bar {
            height: 100% !important;
            width: 100% !important;
            transform-origin: left !important;
            animation: shrink 5s linear forwards !important;
        }
        @keyframes shrink {
            from { width: 100%; }
            to   { width: 0%; }
        }

        /* Type-specific styles ─ minimal colored border + title color */
        .toast-success   { border-left-color: #10b981 !important; }
        .toast-success .toast-title   { color: #065f46 !important; }
        .toast-success .toast-progress-bar { background: #10b981 !important; }

        .toast-error     { border-left-color: #ef4444 !important; }
        .toast-error .toast-title     { color: #991b1b !important; }
        .toast-error .toast-progress-bar   { background: #ef4444 !important; }

        .toast-info      { border-left-color: #3b82f6 !important; }
        .toast-info .toast-title      { color: #1e40af !important; }
        .toast-info .toast-progress-bar    { background: #3b82f6 !important; }

        .toast-warning   { border-left-color: #f59e0b !important; }
        .toast-warning .toast-title   { color: #92400e !important; }
        .toast-warning .toast-progress-bar { background: #f59e0b !important; }

        /* Hide close button icon if too distracting (optional) */
        .toast-close-button {
            opacity: 0.5 !important;
            font-size: 1.1rem !important;
            color: #6b7280 !important;
        }
        .toast:hover .toast-close-button {
            opacity: 0.9 !important;
        }
    </style>
</head>
<body>

<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-lg-8 col-xl-7">

            <div class="card">
                <div class="card-header text-center">
                    <h4 class="mb-0 fw-semibold">Add New Moderator</h4>
                </div>

                <div class="card-body p-4 p-md-5">
                    <form method="POST" id="addModeratorForm">
                        <div class="row g-4">
                            <!-- Left Column -->
                            <div class="col-md-6">
                                <h6 class="text-muted mb-3 fw-semibold">Personal Information</h6>

                                <div class="mb-3">
                                    <label for="firstname" class="form-label">First Name <span class="text-danger">*</span></label>
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="far fa-user"></i></span>
                                        <input type="text" name="firstname" id="firstname" class="form-control" value="<?= htmlspecialchars($firstname) ?>" required>
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <label for="middlename" class="form-label">Middle Name</label>
                                    <input type="text" name="middlename" id="middlename" class="form-control" value="<?= htmlspecialchars($middlename) ?>">
                                </div>

                                <div class="mb-3">
                                    <label for="lastname" class="form-label">Last Name <span class="text-danger">*</span></label>
                                    <input type="text" name="lastname" id="lastname" class="form-control" value="<?= htmlspecialchars($lastname) ?>" required>
                                </div>

                                <div class="mb-3">
                                    <label for="contact" class="form-label">Contact Number <span class="text-danger">*</span></label>
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="fas fa-phone"></i></span>
                                        <input type="text" name="contact" id="contact" class="form-control" value="<?= htmlspecialchars($contact) ?>" required>
                                    </div>
                                </div>
                            </div>

                            <!-- Right Column -->
                            <div class="col-md-6">
                                <h6 class="text-muted mb-3 fw-semibold invisible"> </h6>

                                <div class="mb-3">
                                    <label for="email" class="form-label">Email Address <span class="text-danger">*</span></label>
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="far fa-envelope"></i></span>
                                        <input type="email" name="email" id="email" class="form-control" value="<?= htmlspecialchars($email) ?>" required>
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <label for="role" class="form-label">Role <span class="text-danger">*</span></label>
                                    <select name="role" id="role" class="form-select" required>
                                        <?php if (in_array($user_role, [2,3,4])): ?>
                                            <option value="2" <?= $role == 2 ? 'selected' : '' ?>>Admin</option>
                                            <option value="3" <?= $role == 3 ? 'selected' : '' ?>>Content Manager</option>
                                            <option value="4" <?= $role == 4 ? 'selected' : '' ?>>Academic Master</option>
                                        <?php else: ?>
                                            <option value="1" <?= $role == 1 ? 'selected' : '' ?>>System Administrator</option>
                                            <option value="2" <?= $role == 2 ? 'selected' : '' ?>>Admin</option>
                                            <option value="3" <?= $role == 3 ? 'selected' : '' ?>>Content Manager</option>
                                            <option value="4" <?= $role == 4 ? 'selected' : '' ?>>Academic Master</option>
                                        <?php endif; ?>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <hr class="my-4">

                        <div class="d-flex justify-content-end gap-3">
                            <button type="reset" class="btn btn-secondary px-4">Clear</button>
                            <button type="submit" class="btn btn-primary px-5">Save Moderator</button>
                        </div>
                    </form>
                </div>
            </div>

        </div>
    </div>
</div>

<!-- Scripts -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.js"></script>

<script>
// ────────────────────────────────────────────────
// Professional Toastr configuration
// ────────────────────────────────────────────────
toastr.options = {
    "closeButton": true,
    "debug": false,
    "newestOnTop": true,
    "progressBar": true,
    "positionClass": "toast-top-right",
    "preventDuplicates": true,
    "onclick": null,
    "showDuration": "350",
    "hideDuration": "800",
    "timeOut": "5200",
    "extendedTimeOut": "1600",
    "showEasing": "swing",
    "hideEasing": "linear",
    "showMethod": "slideDown",
    "hideMethod": "fadeOut"
};

<?php if (!empty($errors)): ?>
    <?php foreach ($errors as $err): ?>
        toastr.error("<?= addslashes($err) ?>", "Error");
    <?php endforeach; ?>
<?php endif; ?>

<?php if (!empty($success_message)): ?>
    toastr.success("<?= addslashes($success_message) ?>", "Success");
<?php endif; ?>
</script>

</body>
</html>