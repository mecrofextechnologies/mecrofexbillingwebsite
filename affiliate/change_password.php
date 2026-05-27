<?php


// Redirect if not logged in
if (empty($_SESSION['creator_id'])) {
    header("Location: ../login.php");
    exit;
}

include 'includes/connect.php'; // Adjust path if needed

$creator_id = $_SESSION['creator_id'];
$errors = [];
$success = false;
$must_change = false;

// Check if this is a forced change
try {
    $stmt = $pdo->prepare("SELECT must_change_password FROM creators WHERE id = ?");
    $stmt->execute([$creator_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($row && $row['must_change_password'] == 1) {
        $must_change = true;
    }
} catch (Exception $e) {
    // Silent - proceed anyway
}

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $current_password = $_POST['current_password'] ?? '';
    $new_password     = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    // Basic validation
    if ($must_change && empty($current_password)) {
        $errors[] = "Please enter your current (temporary) password.";
    } elseif (!$must_change && empty($current_password)) {
        $errors[] = "Current password is required.";
    }

    if (empty($new_password) || empty($confirm_password)) {
        $errors[] = "New password and confirmation are required.";
    } elseif ($new_password !== $confirm_password) {
        $errors[] = "New passwords do not match.";
    } elseif (strlen($new_password) < 8) {
        $errors[] = "New password must be at least 8 characters long.";
    }

    // Verify current password
    if (empty($errors)) {
        $stmt = $pdo->prepare("SELECT password FROM creators WHERE id = ?");
        $stmt->execute([$creator_id]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($current_password, $user['password'])) {
            $errors[] = "Current password is incorrect.";
        }
    }

    // Update password
    if (empty($errors)) {
        $new_hash = password_hash($new_password, PASSWORD_BCRYPT);

        $stmt = $pdo->prepare("
            UPDATE creators 
            SET 
                password = :newpass,
                must_change_password = 0,
                updated_at = NOW()
            WHERE id = :id
        ");

        $stmt->execute([
            'newpass' => $new_hash,
            'id'      => $creator_id
        ]);

        // Clear force flag from session
        unset($_SESSION['force_password_change']);

        $success = true;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <title>Change Password - Keychele</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
    <style>
        :root {
            --primary: #b81236;
            --accent: #fdbd10;
            --light: #fff;
            --dark: #222;
            --gray: #555;
        }
        body {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            min-height: 100vh;
            font-family: 'Segoe UI', system-ui, sans-serif;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .card {
            border: none;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 15px 35px rgba(0,0,0,0.12);
            max-width: 480px;
            width: 100%;
        }
        .card-header {
            background: var(--primary);
            color: white;
            text-align: center;
            padding: 2rem 1.5rem;
            border-bottom: none;
        }
        .card-header h3 {
            margin: 0;
            font-weight: 600;
            letter-spacing: 0.5px;
        }
        .card-body {
            padding: 2.5rem;
        }
        .form-label {
            font-weight: 500;
            color: var(--dark);
        }
        .form-control {
            border-radius: 8px;
            padding: 0.75rem 1rem;
            border: 1px solid #ced4da;
            transition: all 0.2s;
        }
        .form-control:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 0.25rem rgba(184,18,54,0.15);
        }
        .btn-primary {
            background: var(--primary);
            border: none;
            border-radius: 50px;
            padding: 0.75rem 2rem;
            font-weight: 600;
            transition: all 0.3s;
        }
        .btn-primary:hover {
            background: #9e0f2e;
            transform: translateY(-2px);
        }
        .alert-must-change {
            background: #fff3cd;
            border: 1px solid #ffeeba;
            color: #856404;
            border-radius: 10px;
            padding: 1.25rem;
            margin-bottom: 2rem;
        }
        .alert-success {
            border-radius: 10px;
        }
        .input-group-text {
            background: #f1f3f5;
            border-radius: 8px 0 0 8px;
        }
    </style>
</head>
<body>

<div class="card">
    <div class="card-header">
        <h3>Change Your Password</h3>
    </div>

    <div class="card-body">
        <?php if ($must_change): ?>
        <div class="alert-must-change text-center">
            <i class="fas fa-shield-alt fa-2x mb-3" style="color:var(--primary);"></i>
            <h5 class="mb-2">Important Security Step</h5>
            <p class="mb-1">For your account's protection, you <strong>must change your temporary password</strong> before continuing.</p>
            <p class="mb-0 small">This is a one-time requirement.</p>
        </div>
        <?php endif; ?>

        <?php if ($success): ?>
        <div class="alert alert-success text-center">
            <i class="fas fa-check-circle fa-2x mb-3"></i>
            <h5>Password Updated Successfully!</h5>
            <p class="mb-3">Redirecting to dashboard in <span id="countdown">3</span> seconds...</p>
            <a href="index.php" class="btn btn-outline-primary btn-sm">Go to Dashboard Now</a>
        </div>

        <script>
            let seconds = 3;
            const countdown = document.getElementById('countdown');
            const timer = setInterval(() => {
                seconds--;
                countdown.textContent = seconds;
                if (seconds <= 0) {
                    clearInterval(timer);
                    window.location.href = 'index.php';
                }
            }, 1000);
        </script>
        <?php else: ?>

        <?php if (!empty($errors)): ?>
        <div class="alert alert-danger">
            <ul class="mb-0">
                <?php foreach ($errors as $err): ?>
                    <li><?= htmlspecialchars($err) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <form method="POST" class="mt-4">
            <div class="mb-4">
                <label class="form-label">Current Password</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="fas fa-lock"></i></span>
                    <input type="password" name="current_password" class="form-control" required autocomplete="current-password">
                </div>
            </div>

            <div class="mb-4">
                <label class="form-label">New Password</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="fas fa-key"></i></span>
                    <input type="password" name="new_password" class="form-control" required minlength="8" autocomplete="new-password">
                </div>
                <small class="text-muted d-block mt-1">Minimum 8 characters</small>
            </div>

            <div class="mb-4">
                <label class="form-label">Confirm New Password</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="fas fa-key"></i></span>
                    <input type="password" name="confirm_password" class="form-control" required autocomplete="new-password">
                </div>
            </div>

            <button type="submit" class="btn btn-primary w-100 mt-3">
                Update Password
            </button>
        </form>

        <?php endif; ?>
    </div>
</div>

</body>
</html>