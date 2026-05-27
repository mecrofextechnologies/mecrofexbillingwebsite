<?php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require '../vendor/autoload.php';
include 'includes/connect.php';

// Logged in creator ID
$creator_id = $_SESSION['creator_id'] ?? null;

// Redirect if not logged in
if (!$creator_id) {
    die("Please login first.");
}

$success = $error = "";

// Fetch creator username
try {
    $username_stmt = $pdo->prepare("SELECT username FROM creators WHERE id = ?");
    $username_stmt->execute([$creator_id]);
    $creator_username = $username_stmt->fetch(PDO::FETCH_ASSOC)['username'] ?? 'Unknown';
} catch (PDOException $e) {
    $error = "Error fetching username: " . $e->getMessage();
    $creator_username = 'Unknown';
}

// Fetch total team members and list
try {
    // Total count
    $count_stmt = $pdo->prepare("SELECT COUNT(*) as total FROM my_team WHERE creator_id = ?");
    $count_stmt->execute([$creator_id]);
    $total_members = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'];

    // List of members
    $members_stmt = $pdo->prepare("SELECT * FROM my_team WHERE creator_id = ? ORDER BY created_at DESC");
    $members_stmt->execute([$creator_id]);
    $members = $members_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = "Error fetching team: " . $e->getMessage();
}

// Handle email sending
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_email'])) {
    $subject = trim($_POST['subject'] ?? '');
    $message = trim($_POST['message'] ?? '');
    $recipients = $_POST['recipients'] ?? []; // Array of emails or 'all'

    if (empty($subject) || empty($message)) {
        $error = "Subject and message are required.";
    } else {
        $emails = [];
        if (in_array('all', $recipients)) {
            // Send to all
            foreach ($members as $member) {
                $emails[] = $member['email'];
            }
        } else {
            // Send to specific
            $emails = array_filter($recipients, function($email) {
                return filter_var($email, FILTER_VALIDATE_EMAIL);
            });
        }

        if (!empty($emails)) {
            $sent_count = 0;
            foreach ($emails as $email) {
                $mail = new PHPMailer(true);

                try {
                    // Required
                    $mail->isSMTP();
                    $mail->Host       = 'mail.pioneercenter.co.ke';
                    $mail->SMTPAuth   = true;
                    $mail->Username   = 'notifications@pioneercenter.co.ke';
                    $mail->Password   = 'King20241064.';
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
                    $mail->Port       = 465;

                    // FIX: SSL handshake issues
                    $mail->SMTPOptions = [
                        'ssl' => [
                            'verify_peer' => false,
                            'verify_peer_name' => false,
                            'allow_self_signed' => true
                        ]
                    ];

                    $mail->SMTPDebug = 0; // change to 2 for testing

                    $mail->setFrom('notifications@pioneercenter.co.ke', $creator_username . ' from Keychele');
                    
                    $mail->addAddress($email);

                    $mail->CharSet = 'UTF-8';
                    $mail->isHTML(true);

                    $mail->Subject = $subject;

                    $unsub_token = md5($email . $creator_id);
                    $unsub_link = "https://keychele.pioneercenter.co.ke/unsubscribe?email=" . urlencode($email) . "&creator=" . $creator_id . "&token=" . $unsub_token;

                    $mail->Body = "
                    <div style='font-family:Arial; background:#f5f5f5; padding:30px;'>
                        <div style='max-width:600px; margin:auto; background:#fff; padding:25px; border-radius:10px;'>
                            <h2 style='color:#b81236; text-align:center;'>
                                Message from " . htmlspecialchars($creator_username) . " from Keychele
                            </h2>
                            <p>" . nl2br(htmlspecialchars($message)) . "</p>
                            <p style='font-size:12px; color:#777; text-align:center; margin-top:20px;'>
                                To unsubscribe from these emails, <a href='" . $unsub_link . "' style='color:#b81236;'>click here</a>.
                            </p>
                        </div>
                    </div>";

                    $mail->AltBody = $message . "\n\nTo unsubscribe, visit: " . $unsub_link;

                    $mail->send();
                    $sent_count++;
                } catch (Exception $e) {
                    $error .= "Email to $email failed: " . $mail->ErrorInfo . "\n";
                }
            }

            if ($sent_count > 0) {
                $success = "Email sent to $sent_count recipient(s) successfully!";
            } else {
                if (empty($error)) {
                    $error = "Failed to send any emails.";
                }
            }
        } else {
            $error = "No valid recipients selected.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>My Team</title>

  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
  <script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>
  <link rel="stylesheet" href="https://cdn.datatables.net/2.1.8/css/dataTables.dataTables.css" />
  <link rel="stylesheet" href="https://cdn.datatables.net/buttons/3.1.2/css/buttons.dataTables.css" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.css">
  <script src="https://code.jquery.com/jquery-3.7.1.js"></script>
  <script src="https://cdn.datatables.net/2.1.8/js/dataTables.js"></script>
  <script src="https://cdn.datatables.net/buttons/3.1.2/js/dataTables.buttons.js"></script>
  <script src="https://cdn.datatables.net/buttons/3.1.2/js/buttons.dataTables.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/pdfmake.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/vfs_fonts.js"></script>
  <script src="https://cdn.datatables.net/buttons/3.1.2/js/buttons.html5.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.js"></script>
</head>

<body class="bg-gray-50" x-data="{ emailModal: false }">


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



<div class="p-4 md:p-6 min-h-screen pb-20">

  <!-- Header -->
  <div class="bg-gradient-to-r from-purple-600 to-blue-600 p-6 rounded-2xl shadow-lg text-white mb-6 flex justify-between items-center">
    <div>
      <h2 class="text-2xl font-bold">My Team / Tribe</h2>
      <p class="opacity-90">Manage your team members</p>
    </div>
    <i class="fa-solid fa-users text-5xl opacity-80"></i>
  </div>

  <!-- Total Members -->
  <div class="bg-white rounded-2xl shadow-md p-6 mb-6">
    <h3 class="text-lg font-semibold text-gray-800 mb-4">Total Members: <?= $total_members ?></h3>
  </div>

  <!-- Member List -->
  <div class="bg-white rounded-2xl shadow-md p-6 mb-6">
    <div class="flex justify-between items-center mb-4">
      <h3 class="text-lg font-semibold text-gray-800">Team Members</h3>
      <button @click="emailModal = true" class="bg-purple-600 text-white px-4 py-2 rounded-lg font-bold flex items-center gap-2">
        <i class="fa-solid fa-envelope"></i> Send Email
      </button>
    </div>
    <?php if (!empty($members)): ?>
        <table id="teamTable" class="w-full text-left display">
            <thead>
                <tr class="border-b">
                    <th class="p-2">S/N</th>
                    <th class="p-2">Name</th>
                    <th class="p-2">Email</th>
                    <th class="p-2">Joined</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($members as $index => $member): ?>
                    <tr class="border-b">
                        <td class="p-2"><?= $index + 1 ?></td>
                        <td class="p-2"><?= htmlspecialchars($member['name']) ?></td>
                        <td class="p-2"><?= htmlspecialchars($member['email']) ?></td>
                        <td class="p-2"><?= date('d M Y H:i', strtotime($member['created_at'])) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <p class="text-gray-500">No team members yet.</p>
    <?php endif; ?>
  </div>

</div>

<!-- EMAIL MODAL -->
<div x-show="emailModal" x-transition class="fixed inset-0 bg-black bg-opacity-70 flex items-center justify-center z-50 p-4">
    <div class="bg-white rounded-2xl p-8 max-w-md w-full shadow-2xl">
        <button @click="emailModal = false" class="float-right text-gray-500 hover:text-gray-900 text-2xl">×</button>
        <h3 class="text-lg font-semibold text-gray-800 mb-4">Send Email to Team</h3>

        <form method="POST">

          <input type="hidden" name="send_email" value="1">

          <!-- Subject -->
          <label class="block mb-4">
            <span class="font-medium text-gray-700">Subject</span>
            <input name="subject" required type="text" class="mt-1 w-full p-3 border rounded-xl" placeholder="e.g. Upcoming Event Update">
          </label>

          <!-- Message -->
          <label class="block mb-4">
            <span class="font-medium text-gray-700">Message</span>
            <textarea name="message" required rows="6" class="w-full p-3 border rounded-xl" placeholder="Enter your message here..."></textarea>
          </label>

          <!-- Recipients -->
          <div class="mb-4">
            <span class="font-medium text-gray-700 block mb-2">Recipients</span>
            <label class="flex items-center mb-2">
              <input type="checkbox" name="recipients[]" value="all" class="mr-2">
              Send to All Members
            </label>
            <?php if (!empty($members)): ?>
                <?php foreach ($members as $member): ?>
                    <label class="flex items-center mb-2">
                      <input type="checkbox" name="recipients[]" value="<?= htmlspecialchars($member['email']) ?>" class="mr-2">
                      <?= htmlspecialchars($member['name']) ?> (<?= htmlspecialchars($member['email']) ?>)
                    </label>
                <?php endforeach; ?>
            <?php endif; ?>
          </div>

          <!-- Submit -->
          <button type="submit" class="w-full bg-purple-600 text-white p-3 rounded-xl font-bold">
            <i class="fa-solid fa-envelope mr-2"></i>
            Send Email
          </button>

        </form>
    </div>
</div>

<script>
  $(document).ready(function() {
    $('#teamTable').DataTable({
      dom: 'Bfrtip',
      buttons: [
        'excelHtml5',
        'pdfHtml5'
      ]
    });
  });

  <?php if ($success): ?>
    toastr.success('<?= addslashes($success) ?>');
  <?php endif; ?>

  <?php if ($error): ?>
    toastr.error('<?= addslashes($error) ?>');
  <?php endif; ?>
</script>

</body>
</html>