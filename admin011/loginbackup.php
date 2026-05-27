<?php
session_start();
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require '../vendor/autoload.php'; // Ensure this path is correct
include '../includes/connect.php'; // Ensure this path is correct

// Initialize error message variable
$error_message = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Get email and password from form
    $email = $_POST['email'];
    $password = $_POST['password'];

    try {
        // Prepare SQL query to prevent SQL injection
        $stmt = $pdo->prepare("SELECT admin_id, password, role, firstname FROM admin WHERE email = :email");
        $stmt->bindParam(':email', $email);
        $stmt->execute();

        // Check if email exists
        if ($stmt->rowCount() == 1) {
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            $admin_id = $user['admin_id']; // Fetch admin_id from the database
            $hashed_password = $user['password'];
            $role = $user['role'];
            $firstname = $user['firstname'];

            // Verify the hashed password
            if (password_verify($password, $hashed_password)) {
                // Password is correct, generate OTP
                $otp = rand(100000, 999999); // Generate a 6-digit OTP
                
                // Save OTP in the database
                $stmt = $pdo->prepare("UPDATE admin SET otp = :otp WHERE email = :email");
                $stmt->bindParam(':otp', $otp);
                $stmt->bindParam(':email', $email);
                $stmt->execute();

                // Send OTP via email using PHPMailer
                $mail = new PHPMailer(true);
                try {
                    $mail->isSMTP();
                    $mail->Host       = 'mail.pioneercenter.co.ke'; // SMTP server
                    $mail->SMTPAuth   = true;
                    $mail->Username   = 'notifications@pioneercenter.co.ke'; // SMTP username
                    $mail->Password   = 'King20241064.'; // SMTP password
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS; // Encryption method
                    $mail->Port       = 465; // SMTP port

                    // Set the sender's email and name
                    $mail->setFrom('notifications@pioneercenter.co.ke', 'PIONEER CENTER LOGIN');

                    // Add recipient's email
                    $mail->addAddress($email); // User's email

                    // Set email format to HTML
                    $mail->isHTML(true);
                    $mail->Subject = 'Here is your one-time password!';

                    // OTP message
                    $mail->Body    = 'Dear ' . htmlspecialchars($firstname) . ',<br><br>Here is your one-time password for logging in to the PIONEER CENTER admin panel: <strong>' . $otp . '</strong><br><br>Please use this OTP to proceed with your login. It will expire in 10 minutes.<br><br>Best regards,<br>PIONEER CENTER WEB';

                    // Alt body for non-HTML email clients
                    $mail->AltBody = 'Dear ' . htmlspecialchars($firstname) . ',\n\nHere is your one-time password for logging in to the PIONEER CENTER admin panel: ' . $otp . '\n\nPlease use this OTP to proceed with your login. It will expire in 10 minutes.\n\nBest regards,\nPIONEER CENTER WEB';

                    // Send the email
                    $mail->send();
                    $success_message = "A one-time password has been sent to " . htmlspecialchars($email) . ". Please check your inbox to proceed.";

                    // Set session variables
                    $_SESSION['admin_id'] = $admin_id; // Store admin_id in session
                    $_SESSION['logged_in'] = true;
                    $_SESSION['user_email'] = $email; // Store email or other user info in session
                    $_SESSION['user_role'] = $role; // Store user's role in session

                    // Redirect to OTP page
                    header("Location: index.php");
                    exit();
                } catch (Exception $e) {
                    // If email fails to send, add error message
                    $error_message = "Failed to send the OTP email. Mailer Error: {$mail->ErrorInfo}";
                }
            } else {
                $error_message = "Incorrect password, try again!";
            }
        } else {
            $error_message = "Email you entered does not exist!";
        }
    } catch (PDOException $e) {
        // Handle query errors
        $error_message = "Database query error: " . $e->getMessage();
    }
}
?>



<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pioneer Center - Login</title>
    <!-- Favicons -->
  <link href="../school photos/newlogo.jpg" rel="icon">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.css">
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 0;
            display: flex;
            flex-direction: row;
            height: 100vh;
        }
        .left-side {
            background: url('../school photos/newlogo.jpg') no-repeat center center;
            background-size: cover;
            width: 50%;
            display: flex;
            justify-content: center;
            align-items: center;
            color: white;
            text-align: center;
        }
        .left-side h1 {
            font-size: 3em;
            font-weight: bold;
        }
        .right-side {
            width: 50%;
            display: flex;
            justify-content: center;
            align-items: center;
            background-color: #f4f4f4;
            flex-direction: column;
            padding: 20px;
        }
        .login-box {
            background: white;
            padding: 40px;
            border-radius: 10px;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
            width: 300px;
            text-align: center;
            position: relative;
        }
        .login-box img {
            width: 100px;
            margin-bottom: 20px;
        }
        .login-box h2 {
            margin-bottom: 20px;
            font-size: 1.5em;
            color: #6f2dbd;
        }
        .login-box input[type="text"], 
        .login-box input[type="password"] {
            width: 100%;
            padding: 10px;
            margin: 10px 0;
            border: 1px solid #ccc;
            border-radius: 5px;
        }
        .login-box button {
            width: 108%;
            padding: 10px;
            background-color: #275D7A;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 1em;
            position: relative;
        }
        .login-box button:hover {
            background-color: #4A8BA3; 
        }
        .login-box .spinner {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 3px solid rgba(255, 255, 255, 0.3);
            border-radius: 50%;
            border-top-color: white;
            animation: spin 0.4s linear infinite;
            margin-right: 10px;
        }
        .login-box .validating-text {
            display: inline-block;
            vertical-align: middle;
            color: white;
            position: relative;
            top: -8px; 
        }
        .login-box .error-message {
            border: 1px solid red;
            background-color: #f8d7da;
            color: #721c24;
            padding: 10px;
            margin-bottom: 15px;
            border-radius: 5px;
            font-size: 0.9em;
            text-align: center;
        }
        .footer {
            margin-top: 20px;
            font-size: 0.8em;
            color: #ccc;
        }
        @media (max-width: 768px) {
            body {
                flex-direction: column;
            }
            .left-side {
                display: none;
            }
            .right-side {
                width: 100%;
            }
            .login-box {
                width: 80%;
                padding: 20px;
            }
        }
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
    </style>
</head>
<body>
    <div class="left-side">
        <h1>EXCELLENCE IN TRAINING</h1>
    </div>
    <div class="right-side">
        <div class="login-box">
            <img src="../school photos/newlogo.jpg" alt="Logo">
            <h2>Hi Admin, welcome back</h2>
            <p>Please fill in your details to log in</p>

            <!-- Display error message -->
            <?php if (!empty($error_message)) : ?>
                <div class="error-message" id="error-message"><?php echo $error_message; ?></div>
            <?php endif; ?>

            <form id="login-form" action="login.php" method="post" onsubmit="event.preventDefault(); showSpinner();">
                <input type="text" name="email" placeholder="Your Email Address" required>
                <input type="password" name="password" placeholder="Enter your Password" required>
                <button id="login-button" type="submit">Sign In</button>
            </form>

            <a href="#">Forgot password?</a>
        </div>
        <div class="footer">
            &copy; 2025 - PIONEER CENTER
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.js"></script>
    <script>
        function showSpinner() {
            const button = document.getElementById('login-button');
            const spinner = document.createElement('div');
            const validatingText = document.createElement('span');
            spinner.className = 'spinner';
            validatingText.className = 'validating-text';
            validatingText.innerText = 'Validating...';
            button.disabled = true;
            button.innerHTML = '';
            button.appendChild(spinner);
            button.appendChild(validatingText);

            setTimeout(() => {
                document.getElementById('login-form').submit();
            }, 3000);
        }

        window.onload = function() {
            const errorMessage = document.getElementById('error-message');
            if (errorMessage) {
                setTimeout(() => {
                    errorMessage.style.display = 'none';
                }, 3000);
            }

            <?php if (isset($_SESSION['success_message'])): ?>
            toastr.options = {
                "closeButton": true,
                "debug": false,
                "newestOnTop": false,
                "progressBar": false,
                "positionClass": "toast-top-right",
                "preventDuplicates": false,
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
            toastr.success('<?php echo $_SESSION['success_message']; ?>');
            <?php unset($_SESSION['success_message']); endif; ?>
        };
    </script>
</body>
</html>
