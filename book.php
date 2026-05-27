<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'vendor/autoload.php';
include 'msanii/includes/connect.php';
session_start();

// Get creator_id
$creator_id = $_GET['creator'] ?? '';
if (empty($creator_id) || !is_numeric($creator_id)) {
    die("<h1 class='text-center text-4xl text-red-600 mt-20'>Invalid Request</h1>");
}

// Fetch creator + email
$stmt = $pdo->prepare("
    SELECT mp.full_name, mp.profile_pic, c.username, c.email AS creator_email
    FROM my_page mp
    JOIN creators c ON mp.creator_id = c.id
    WHERE mp.creator_id = ?
");
$stmt->execute([$creator_id]);
$creator = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$creator || empty($creator['creator_email'])) {
    die("<h1 class='text-center text-4xl text-red-600 mt-20'>Creator Not Found or No Email</h1>");
}

// === DYNAMIC SERVICES ===
$services = [];
$port = $pdo->prepare("SELECT DISTINCT category FROM portfolio WHERE creator_id = ?");
$port->execute([$creator_id]);
foreach ($port->fetchAll() as $p) {
    $cat = ucwords(trim($p['category']));
    if ($cat && !in_array($cat, $services)) $services[] = $cat;
}

$tickets = $pdo->prepare("SELECT title FROM tickets WHERE creator_id = ?");
$tickets->execute([$creator_id]);
foreach ($tickets->fetchAll() as $t) {
    if ($t['title'] && !in_array($t['title'], $services)) {
        $services[] = $t['title'];
    }
}

if (empty($services)) {
    $services = ['Videography', 'Photography', 'Graphic Design', 'Web Development', 'Consultation'];
}
$services = array_unique($services);
sort($services);

// Handle booking
$success = false;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name    = trim($_POST['name'] ?? '');
    $email   = trim(strtolower($_POST['email'] ?? ''));
    $phone   = trim($_POST['phone'] ?? '');
    $service = $_POST['service'] ?? '';
    $message = trim($_POST['message'] ?? '');

    if (empty($name) || empty($email) || empty($phone) || empty($service)) {
        $error = "All required fields must be filled.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Invalid email address.";
    } elseif (!preg_match('/^[\d\+\-\s\(\)]+$/', $phone)) {
        $error = "Invalid phone number.";
    } else {
        try {
            $check = $pdo->prepare("SELECT id FROM bookings WHERE creator_id = ? AND email = ? AND phone = ?");
            $check->execute([$creator_id, $email, $phone]);
            if ($check->rowCount() > 0) {
                $error = "You've already sent a booking request!";
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO bookings (creator_id, name, email, phone, service, message, created_at) 
                    VALUES (?, ?, ?, ?, ?, ?, NOW())
                ");
                $stmt->execute([$creator_id, $name, $email, $phone, $service, $message]);

                // === SEND EMAIL WITH YOUR PHPMailer SETUP ===
                $mail = new PHPMailer(true);
                try {
                    $mail->isSMTP();
                    $mail->Host       = 'mail.pioneercenter.co.ke';
                    $mail->SMTPAuth   = true;
                    $mail->Username   = 'notifications@pioneercenter.co.ke';
                    $mail->Password   = 'King20241064.';
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
                    $mail->Port       = 465;

                    $mail->SMTPOptions = [
                        'ssl' => [
                            'verify_peer' => false,
                            'verify_peer_name' => false,
                            'allow_self_signed' => true
                        ]
                    ];

                    $mail->setFrom('notifications@pioneercenter.co.ke', 'Keychele Bookings');
                    $mail->addAddress($creator['creator_email'], $creator['full_name']);
                    $mail->addReplyTo($email, $name);

                    $mail->isHTML(true);
                    $mail->CharSet = 'UTF-8';
                    $mail->Subject = "New Booking Request from $name";

                    $profile_link = "https://keychele.pioneercenter.co.ke/keychele/{$creator['username']}";

                    $mail->Body = "
                    <div style='font-family: Arial, sans-serif; background:#f9f9f9; padding:30px;'>
                        <div style='max-width:600px; margin:auto; background:#fff; padding:30px; border-radius:12px; box-shadow:0 10px 30px rgba(0,0,0,0.1);'>
                            <h2 style='color:#b81236; text-align:center; font-size:28px;'>New Booking Request!</h2>
                            <hr style='border:1px solid #eee; margin:20px 0;'>
                            <p style='font-size:16px; color:#333;'><strong>Client Name:</strong> $name</p>
                            <p style='font-size:16px; color:#333;'><strong>Email:</strong> $email</p>
                            <p style='font-size:16px; color:#333;'><strong>Phone:</strong> $phone</p>
                            <p style='font-size:16px; color:#333;'><strong>Service:</strong> $service</p>
                            <p style='font-size:16px; color:#333;'><strong>Message:</strong><br>" . nl2br(htmlspecialchars($message)) . "</p>
                            <hr style='border:1px solid #eee; margin:20px 0;'>
                            <p style='text-align:center;'>
                                <a href='$profile_link' style='background:#032d5d; color:white; padding:14px 28px; text-decoration:none; border-radius:8px; font-weight:bold;'>
                                    View Creator Profile
                                </a>
                            </p>
                            <p style='font-size:13px; color:#777; text-align:center; margin-top:20px;'>
                                This booking was made via <strong>Keychele.co.ke</strong>
                            </p>
                        </div>
                    </div>";

                    $mail->send();
                    $success = true;

                } catch (Exception $e) {
                    $error = "Booking saved, but email failed to send.";
                }
            }
        } catch (Exception $e) {
            $error = "Booking failed. Please try again.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Book <?= htmlspecialchars($creator['full_name']) ?></title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

  <script>
    tailwind.config = {
      theme: { extend: { colors: { primary: '#032d5d', accent: '#b81236' } } }
    }
  </script>
  <style>
    .gradient-bg { background: linear-gradient(135deg, #032d5d 0%, #b81236 100%); }
    .card-shadow { box-shadow: 0 20px 40px rgba(3,45,93,0.15); }
  </style>
</head>
<body class="bg-gray-50 min-h-screen flex items-center justify-center p-4">

<div class="max-w-lg w-full">

  <!-- SUCCESS -->
  <?php if ($success): ?>
  <script>
    Swal.fire({
      icon: 'success',
      title: 'Booking Sent!',
      html: 'Thank you <strong><?= addslashes($name) ?></strong>!<br>Your request has been sent to <strong><?= addslashes($creator['full_name']) ?></strong>.',
      timer: 4500,
      timerProgressBar: true,
      showConfirmButton: false
    }).then(() => {
      window.location.href = '/keychele/<?= htmlspecialchars($creator['username']) ?>';
    });
  </script>
  <?php endif; ?>

  <!-- ERROR -->
  <?php if ($error): ?>
  <script>
    Swal.fire({ icon: 'error', title: 'Error', text: '<?= addslashes($error) ?>' });
  </script>
  <?php endif; ?>

  <div class="bg-white rounded-3xl overflow-hidden card-shadow">
    <div class="gradient-bg text-white p-10 text-center relative">
      <div class="absolute top-4 left-4">
        <a href="/keychele/<?= htmlspecialchars($creator['username']) ?>" class="text-white opacity-80 hover:opacity-100">
          <i class="fas fa-arrow-left text-2xl"></i>
        </a>
      </div>
      <img src="msanii/uploads/<?= htmlspecialchars($creator['profile_pic'] ?? 'default.png') ?>" 
           class="w-32 h-32 rounded-full mx-auto border-6 border-white shadow-2xl object-cover -mb-20 z-10">
    </div>

    <div class="pt-24 pb-10 px-8 text-center">
      <h1 class="text-4xl font-black text-primary mb-2">
        Book <span class="text-accent"><?= htmlspecialchars($creator['full_name']) ?></span>
      </h1>
      <p class="text-gray-600 text-lg">Fill in your details to get started</p>
    </div>

    <form method="POST" class="px-8 pb-12 space-y-6" id="bookingForm">
      <input type="text" name="name" required placeholder="Your Full Name *" value="<?= htmlspecialchars($_POST['name']??'') ?>"
             class="w-full px-5 py-4 rounded-xl border-2 border-gray-200 focus:border-accent outline-none text-lg">

      <input type="email" name="email" required placeholder="Your Email *" value="<?= htmlspecialchars($_POST['email']??'') ?>"
             class="w-full px-5 py-4 rounded-xl border-2 border-gray-200 focus:border-accent outline-none text-lg">

      <input type="text" name="phone" required placeholder="Phone Number (e.g. +254...)" value="<?= htmlspecialchars($_POST['phone']??'') ?>"
             class="w-full px-5 py-4 rounded-xl border-2 border-gray-200 focus:border-accent outline-none text-lg">

      <select name="service" required class="w-full px-5 py-4 rounded-xl border-2 border-gray-200,200 focus:border-accent outline-none text-lg">
        <option value="">-- Select Service --</option>
        <?php foreach ($services as $srv): ?>
          <option value="<?= htmlspecialchars($srv) ?>" <?= ($_POST['service']??'') === $srv ? 'selected' : '' ?>>
            <?= htmlspecialchars($srv) ?>
          </option>
        <?php endforeach; ?>
      </select>

      <textarea name="message" rows="5" placeholder="Project details, dates, budget..." 
                class="w-full px-5 py-4 rounded-xl border-2 border-gray-200 focus:border-accent outline-none resize-none text-lg"><?= htmlspecialchars($_POST['message']??'') ?></textarea>

      <button type="submit" id="submitBtn" class="w-full bg-accent hover:bg-red-700 text-white font-bold text-xl py-5 rounded-xl shadow-lg transform hover:scale-105 transition flex items-center justify-center gap-3">
        <span id="btnText">Send Booking Request</span>
        <i class="fas fa-paper-plane ml-2"></i>
      </button>
    </form>
  </div>

  <p class="text-center mt-10 text-gray-500 text-sm">© Keychele 2025. All rights reserved.</p>
</div>

<!-- LOADING STATE SCRIPT -->
<script>
  document.getElementById('bookingForm').addEventListener('submit', function() {
    const btn = document.getElementById('submitBtn');
    const btnText = document.getElementById('btnText');
    
    btn.disabled = true;
    btn.classList.add('opacity-80', 'cursor-not-allowed');
    btnText.innerHTML = `
      <svg class="animate-spin -ml-1 mr-3 h-5 w-5 text-white inline" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
      </svg>
      Please wait... sending request
    `;
  });
</script>
</body>
</html>