<?php
// support.php - Modern styled support form with SweetAlert2

ini_set('display_errors', 1);
error_reporting(E_ALL);

include 'includes/connect.php';

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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Support Center</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/@sweetalert2/theme-bootstrap-4/bootstrap-4.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        primary: '#b81236',
                        secondary: '#032d5d',
                    }
                }
            }
        }
    </script>
    <style>
        body {
            background: linear-gradient(135deg, #f5f5f5, #e0e0e0);
        }
        .card-hover {
            transition: all 0.3s ease;
        }
        .card-hover:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 15px rgba(0, 0, 0, 0.1);
        }
    </style>
</head>


<!-- Loading Overlay -->
<div id="preloader" class="fixed inset-0 z-[9999] flex items-center justify-center bg-black/40 backdrop-blur-sm">
  <div class="bg-white rounded-xl shadow-xl px-8 py-6 flex items-center space-x-3">
    <div class="h-5 w-5 border-2 border-green-500 border-t-transparent rounded-full animate-spin"></div>
    <span class="text-gray-700 font-medium">Loading...Please wait!</span>
  </div>
</div>
<script>
window.addEventListener("load", function () {
    const loader = document.getElementById("preloader");
    loader.style.opacity = "0";
    loader.style.transition = "opacity 0.3s ease";
    setTimeout(() => { loader.style.display = "none"; }, 300);
});
</script>



<body class="bg-gray-100 min-h-screen flex items-center justify-center p-4 md:p-8">

    <div class="bg-white rounded-2xl shadow-xl p-8 max-w-md w-full card-hover">
        <h3 class="text-2xl font-bold text-secondary mb-6 text-center flex items-center justify-center gap-2">
            <i class="fas fa-headset text-primary"></i> Contact Support
        </h3>
        <form method="POST" action="index.php?page=support_center">
            <div class="mb-4">
                <label for="name" class="block text-sm font-medium text-gray-700 mb-1">Your Name <span class="text-primary">*</span></label>
                <input type="text" name="name" id="name" class="w-full px-4 py-3 border border-gray-300 rounded-xl focus:outline-none focus:border-primary" required placeholder="Enter your name">
            </div>
            <div class="mb-4">
                <label for="email" class="block text-sm font-medium text-gray-700 mb-1">Your Email <span class="text-primary">*</span></label>
                <input type="email" name="email" id="email" class="w-full px-4 py-3 border border-gray-300 rounded-xl focus:outline-none focus:border-primary" required placeholder="you@example.com">
            </div>
            <div class="mb-6">
                <label for="message" class="block text-sm font-medium text-gray-700 mb-1">Your Message <span class="text-primary">*</span></label>
                <textarea name="message" id="message" rows="5" class="w-full px-4 py-3 border border-gray-300 rounded-xl focus:outline-none focus:border-primary" required placeholder="Describe your issue here..."></textarea>
            </div>
            <button type="submit" class="w-full bg-primary text-white py-3 rounded-xl font-bold hover:bg-red-700 transition duration-300 flex items-center justify-center gap-2">
                <i class="fas fa-paper-plane"></i> Submit Request
            </button>
        </form>
    </div>

<script>
<?php if (!empty($flash['success'])): ?>
    Swal.fire({
        icon: 'success',
        title: 'Success!',
        text: "<?= addslashes($flash['success']) ?>",
        confirmButtonColor: '#b81236'
    });
<?php elseif (!empty($flash['error'])): ?>
    Swal.fire({
        icon: 'error',
        title: 'Error',
        text: "<?= addslashes($flash['error']) ?>",
        confirmButtonColor: '#b81236'
    });
<?php endif; ?>
</script>

</body>
</html>