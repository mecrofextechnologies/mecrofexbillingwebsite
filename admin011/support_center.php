<?php
// support.php - Modern styled support form with SweetAlert2

ini_set('display_errors', 1);
error_reporting(E_ALL);

include '../includes/connect.php';

$flash = [
    'success' => '',
    'error' => ''
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $message = trim($_POST['message'] ?? '');

    if ($name && $email && $message) {
        try {
            $stmt = $pdo->prepare("INSERT INTO support_queries (name, email, message, created_at) VALUES (?, ?, ?, NOW())");
            $stmt->execute([$name, $email, $message]);
            $flash['success'] = 'Your support request has been submitted successfully.';
        } catch (Exception $e) {
            $flash['error'] = 'Database error: ' . $e->getMessage();
        }
    } else {
        $flash['error'] = 'Please fill in all required fields.';
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Support Center</title>
    <meta name="viewport" content="width=device-width, initial-scale=1"> <!-- mobile responsive -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/@sweetalert2/theme-bootstrap-4/bootstrap-4.min.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #3083B5, #6EC1E4);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .card {
            border-radius: 15px;
            box-shadow: 0 8px 20px rgba(0,0,0,0.1);
            max-width: 500px;
            width: 100%;
        }
        .form-control {
            border-radius: 10px;
        }
        .btn-primary {
            border-radius: 10px;
            padding: 12px;
            font-weight: 500;
        }
        h3 {
            font-weight: 600;
        }
    </style>
</head>
<body>

<div class="card p-4 bg-white">
    <h3 class="mb-4 text-center text-primary">💬 Contact Support</h3>
    <form method="POST" action="index.php?page=support_center">
        <div class="mb-3">
            <label for="name" class="form-label">Your Name <span class="text-danger">*</span></label>
            <input type="text" name="name" class="form-control" required placeholder="Enter your name">
        </div>
        <div class="mb-3">
            <label for="email" class="form-label">Your Email <span class="text-danger">*</span></label>
            <input type="email" name="email" class="form-control" required placeholder="you@example.com">
        </div>
        <div class="mb-3">
            <label for="message" class="form-label">Your Message <span class="text-danger">*</span></label>
            <textarea name="message" class="form-control" rows="5" required placeholder="Write your issue here..."></textarea>
        </div>
        <button type="submit" class="btn btn-primary w-100">📨 Submit Request</button>
    </form>
</div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
<?php if (!empty($flash['success'])): ?>
    Swal.fire({
        icon: 'success',
        title: 'Success',
        text: "<?= addslashes($flash['success']) ?>",
        confirmButtonColor: '#3083B5'
    });
<?php elseif (!empty($flash['error'])): ?>
    Swal.fire({
        icon: 'error',
        title: 'Oops...',
        text: "<?= addslashes($flash['error']) ?>",
        confirmButtonColor: '#d33'
    });
<?php endif; ?>
</script>

</body>
</html>
