<?php
session_start();
include '../includes/connect.php'; // Include the PDO connection

// Initialize error message variable
$error_message = '';

// Check if the user is logged in (session variable `admin_id` should be set)
if (!isset($_SESSION['admin_id']) || !$_SESSION['logged_in']) {
    // Redirect to login page if not logged in
    header("Location: login.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Get OTP entered by user
    $entered_otp = $_POST['otp'];
    $admin_id = $_SESSION['admin_id']; // Get admin_id from session

    try {
        // Check if the OTP matches
        $stmt = $pdo->prepare("SELECT otp, admin_id FROM admin WHERE admin_id = :admin_id");
        $stmt->bindParam(':admin_id', $admin_id);
        $stmt->execute();
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && $user['otp'] == $entered_otp) {
            // OTP is correct, update otp_status to 1 and otp to 0
            $stmt = $pdo->prepare("UPDATE admin SET otp_status = 1, otp = 0 WHERE admin_id = :admin_id");
            $stmt->bindParam(':admin_id', $admin_id);
            $stmt->execute();

            // Redirect to the appropriate page after successful OTP verification
            header("Location: index.php");
            exit();
        } else {
            $error_message = "Incorrect OTP. Please try again.";
        }
    } catch (PDOException $e) {
        $error_message = "Database query error: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Enter OTP</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            margin: 0;
            background-color: #f4f4f4;
        }
        .otp-container {
            text-align: center;
            background-color: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
            width: 100%;
            max-width: 400px;
        }
        h2 {
            font-size: 1.8em;
            color: #333;
            margin-bottom: 20px;
        }
        .error-message {
            color: red;
            font-size: 1em;
            margin-bottom: 15px;
            background-color: #f8d7da;
            padding: 10px;
            border-radius: 5px;
        }
        input[type="text"] {
            padding: 10px;
            font-size: 1em;
            width: 80%;
            margin: 10px 0;
            border: 1px solid #ccc;
            border-radius: 5px;
        }
        button {
            padding: 10px 20px;
            background-color: lightblue;
            border: none;
            border-radius: 5px;
            font-size: 1.2em;
            cursor: pointer;
        }
        button:hover {
            background-color: #3a8d99;
        }
        button:disabled {
            background-color: #ccc;
            cursor: not-allowed;
        }
    </style>
</head>
<body>

    <div class="otp-container">
        <h2>Enter the OTP sent to your email</h2>
        <p>we have sent one time passord to verify if it is you</p>

        <!-- Display error message -->
        <?php if (!empty($error_message)) : ?>
            <div class="error-message"><?php echo $error_message; ?></div>
        <?php endif; ?>

        <form action="otp.php" method="post">
            <input type="text" id="otp" name="otp" placeholder="Enter OTP" maxlength="6" oninput="validateOTP()" required>
            <button type="submit" id="submit-btn" disabled>Verify OTP</button>
        </form>
    </div>

    <script>
        function validateOTP() {
            const otpField = document.getElementById('otp');
            const submitBtn = document.getElementById('submit-btn');
            const otpValue = otpField.value;

            // Check if OTP is exactly 6 digits
            if (otpValue.length === 6 && /^\d{6}$/.test(otpValue)) {
                submitBtn.disabled = false;  // Enable button if OTP is 6 digits
            } else {
                submitBtn.disabled = true;   // Disable button if OTP is not 6 digits
            }
        }
    </script>

</body>
</html>
