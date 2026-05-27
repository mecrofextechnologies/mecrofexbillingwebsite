<?php
// send_pictures.php
session_start();

$connect_path = file_exists('msanii/includes/connect.php')
    ? 'msanii/includes/connect.php'
    : 'includes/connect.php';

if (!file_exists($connect_path)) {
    die("Database connection file not found.");
}

require_once $connect_path;
require_once '../vendor/autoload.php'; // PHPMailer

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// ────────────────────────────────────────────────
// Authentication & Event check
// ────────────────────────────────────────────────
if (!isset($_SESSION['creator_id']) || !is_numeric($_SESSION['creator_id'])) {
    header("Location: login.php");
    exit;
}

$creator_id = (int)$_SESSION['creator_id'];
$event_id   = (int)($_GET['event_id'] ?? $_POST['event_id'] ?? 0);

if ($event_id <= 0) {
    die("Invalid event ID.");
}

// Verify ownership
$stmt = $pdo->prepare("
    SELECT title 
    FROM tickets 
    WHERE id = ? AND creator_id = ?
");
$stmt->execute([$event_id, $creator_id]);
$event = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$event) {
    die("Event not found or access denied.");
}

// ────────────────────────────────────────────────
// Handle form submission
// ────────────────────────────────────────────────
$success_message = '';
$error_message   = '';
$sent_count      = 0;
$skipped_count   = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_pictures'])) {

    $message = trim($_POST['message'] ?? '');

    // ── File validation ───────────────────────────────────────
    if (empty($_FILES['pictures']['name']) || !is_array($_FILES['pictures']['name'])) {
        $error_message = "No pictures were uploaded.";
    } else {
        $files      = $_FILES['pictures'];
        $file_count = count($files['name']);

        if ($file_count > 50) {
            $error_message = "Maximum 50 pictures allowed. You selected $file_count.";
        } else {
            // Collect valid images
            $attachments = [];
            for ($i = 0; $i < $file_count; $i++) {
                if ($files['error'][$i] !== UPLOAD_ERR_OK) {
                    continue;
                }

                $tmp_name = $files['tmp_name'][$i];
                $name     = $files['name'][$i];
                $size     = $files['size'][$i];
                $type     = mime_content_type($tmp_name);

                // Basic validation
                if ($size > 10 * 1024 * 1024) { // 10 MB max per file
                    continue;
                }
                if (!str_starts_with($type, 'image/')) {
                    continue;
                }

                $attachments[] = [
                    'path' => $tmp_name,
                    'name' => $name,
                    'type' => $type
                ];
            }

            if (empty($attachments)) {
                $error_message = "No valid image files were uploaded.";
            } else {
                // ── Get checked-in attendees with email ────────────────
                $stmt = $pdo->prepare("
                    SELECT buyer_name, buyer_email
                    FROM ticket_purchases
                    WHERE ticket_id = ?
                      AND status = 'completed'
                      AND is_checked_in = 1
                      AND buyer_email IS NOT NULL
                      AND buyer_email != ''
                ");
                $stmt->execute([$event_id]);
                $recipients = $stmt->fetchAll(PDO::FETCH_ASSOC);

                if (empty($recipients)) {
                    $error_message = "No checked-in attendees with valid email addresses found.";
                } else {
                    foreach ($recipients as $person) {
                        $name = htmlspecialchars($person['buyer_name'] ?: 'Attendee');

                        $mail = new PHPMailer(true);
                        try {
                            $mail->isSMTP();
                            $mail->Host       = 'mail.keychele.co.ke';
                            $mail->SMTPAuth   = true;
                            $mail->Username   = 'tickets@keychele.co.ke';
                            $mail->Password   = 'King20241064.';
                            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                            $mail->Port       = 587;

                            $mail->setFrom('tickets@keychele.co.ke', 'Keychele Events');
                            $mail->addAddress($person['buyer_email'], $name);

                            $mail->isHTML(true);
                            $mail->Subject = 'Event Highlights - ' . $event['title'];

                            $body = "
                                <h2>Hello {$name},</h2>
                                <p>Thank you for attending <strong>" . htmlspecialchars($event['title']) . "</strong>!</p>
                            ";

                            if ($message !== '') {
                                $body .= "<p>" . nl2br(htmlspecialchars($message)) . "</p>";
                            }

                            $body .= "
                                <p>Here are some beautiful moments captured during the event:</p>
                                <p style='color:#666; font-size:0.95em;'>
                                    Best regards,<br>
                                    Keychele Team
                                </p>
                            ";

                            $mail->Body    = $body;
                            $mail->AltBody = strip_tags($body);

                            // Attach images
                            foreach ($attachments as $att) {
                                $mail->addAttachment($att['path'], $att['name'], PHPMailer::ENCODING_BASE64, $att['type']);
                            }

                            $mail->send();
                            $sent_count++;
                        } catch (Exception $e) {
                            error_log("Failed to send pictures to {$person['buyer_email']}: " . $mail->ErrorInfo);
                            $skipped_count++;
                        }
                    }

                    $success_message = "Pictures sent to $sent_count attendee" . ($sent_count === 1 ? '' : 's');
                    if ($skipped_count > 0) {
                        $success_message .= " ($skipped_count could not be delivered)";
                    }
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Send Pictures - <?= htmlspecialchars($event['title'] ?? 'Event') ?></title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <style>
    .preview-container {
      display: flex;
      flex-wrap: wrap;
      gap: 12px;
      margin-top: 12px;
    }
    .preview-item {
      position: relative;
      width: 120px;
      height: 120px;
      border-radius: 8px;
      overflow: hidden;
      border: 1px solid #e5e7eb;
      background: #f3f4f6;
    }
    .preview-item img {
      width: 100%;
      height: 100%;
      object-fit: cover;
    }
  </style>
</head>
<body class="bg-gray-50 min-h-screen">

<div class="max-w-4xl mx-auto px-4 py-8 sm:px-6 lg:px-8">

  <div class="mb-6">
    <a href="index.php?page=ticket_purchases"
       class="inline-flex items-center text-indigo-600 hover:text-indigo-800 font-medium">
      <i class="fas fa-arrow-left mr-2"></i> Back to ticket details
    </a>
  </div>

  <div class="bg-white rounded-2xl shadow-xl p-6 md:p-8">

    <h1 class="text-2xl md:text-3xl font-bold text-gray-800 mb-2">
      Send Pictures to Checked-in Attendees
    </h1>

    <p class="text-gray-600 mb-8">
      Event: <strong><?= htmlspecialchars($event['title']) ?></strong>
    </p>

    <?php if ($success_message): ?>
      <div class="mb-6 p-4 bg-green-50 border border-green-200 text-green-800 rounded-lg">
        <?= htmlspecialchars($success_message) ?>
      </div>
    <?php endif; ?>

    <?php if ($error_message): ?>
      <div class="mb-6 p-4 bg-red-50 border border-red-200 text-red-800 rounded-lg">
        <?= htmlspecialchars($error_message) ?>
      </div>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data" class="space-y-8" id="sendPicturesForm">

      <input type="hidden" name="event_id" value="<?= $event_id ?>">
      <input type="hidden" name="send_pictures" value="1">

      <div>
        <label class="block text-sm font-medium text-gray-700 mb-2">
          Select Pictures <span class="text-red-600 font-semibold">(max 50)</span>
        </label>
        <input type="file" name="pictures[]" multiple accept="image/*" id="pictureInput"
               class="block w-full text-sm text-gray-500
                      file:mr-4 file:py-2.5 file:px-5
                      file:rounded-lg file:border-0
                      file:text-sm file:font-semibold
                      file:bg-indigo-50 file:text-indigo-700
                      hover:file:bg-indigo-100
                      focus:outline-none focus:ring-2 focus:ring-indigo-500">
        <p class="mt-2 text-sm text-gray-500">
          JPG, JPEG, PNG, GIF, WEBP • Recommended: 2–8 MB per photo
        </p>
        <p class="mt-1 text-sm text-gray-600" id="fileCount">0 files selected</p>
        <div id="previewContainer" class="preview-container mt-4"></div>
      </div>

      <div>
        <label class="block text-sm font-medium text-gray-700 mb-2">
          Optional Message
        </label>
        <textarea name="message" rows="5"
                  class="w-full border border-gray-300 rounded-lg px-4 py-3 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 resize-y"
                  placeholder="Example: Thank you for being part of the event! Here are some beautiful moments captured during the day..."></textarea>
      </div>

      <div class="flex justify-end pt-4">
        <button type="submit" id="sendBtn"
                class="bg-indigo-600 hover:bg-indigo-700 text-white px-8 py-3 rounded-lg font-medium shadow-md transition-colors disabled:opacity-60 disabled:cursor-not-allowed flex items-center gap-2 min-w-[180px] justify-center">
          <i class="fas fa-paper-plane"></i>
          <span id="btnText">Send Pictures</span>
        </button>
      </div>

    </form>
  </div>
</div>

<script>
// ─── Preview + file count ───────────────────────────────────────
const input     = document.getElementById('pictureInput');
const countEl   = document.getElementById('fileCount');
const preview   = document.getElementById('previewContainer');
const sendBtn   = document.getElementById('sendBtn');
const btnText   = document.getElementById('btnText');

input.addEventListener('change', () => {
  const files = input.files;
  countEl.textContent = `${files.length} file${files.length !== 1 ? 's' : ''} selected`;

  preview.innerHTML = '';

  if (files.length > 50) {
    Swal.fire({
      icon: 'warning',
      title: 'Too many files',
      text: 'Maximum allowed is 50 pictures. Only the first 50 will be previewed.',
      confirmButtonColor: '#4f46e5'
    });
  }

  Array.from(files).slice(0, 50).forEach(file => {
    if (!file.type.startsWith('image/')) return;
    const reader = new FileReader();
    reader.onload = e => {
      const div = document.createElement('div');
      div.className = 'preview-item';
      div.innerHTML = `<img src="${e.target.result}" alt="preview">`;
      preview.appendChild(div);
    };
    reader.readAsDataURL(file);
  });
});

// ─── Form submit with loading state ──────────────────────────────
document.getElementById('sendPicturesForm').addEventListener('submit', function(e) {
  const files = input.files;

  if (files.length === 0) {
    e.preventDefault();
    Swal.fire('No pictures', 'Please select at least one image.', 'warning');
    return;
  }

  if (files.length > 50) {
    e.preventDefault();
    Swal.fire('Limit exceeded', 'Maximum 50 pictures allowed.', 'warning');
    return;
  }

  // Show sending state
  sendBtn.disabled = true;
  btnText.textContent = 'Sending pictures...';
  sendBtn.innerHTML = `
    <i class="fas fa-spinner fa-spin"></i>
    <span id="btnText">Sending pictures...</span>
  `;
});
</script>

</body>
</html>