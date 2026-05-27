<?php
// customize_send_reminder.php
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

// Get event title
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

// Get creator's name
$creator_name = 'Event Organizer';

$stmt_creator = $pdo->prepare("
    SELECT 
        COALESCE(
            NULLIF(TRIM(full_name), ''),
            NULLIF(TRIM(CONCAT_WS(' ', first_name, last_name)), ''),
            NULLIF(TRIM(username), '')
        ) AS display_name
    FROM creators 
    WHERE id = ?
    LIMIT 1
");
$stmt_creator->execute([$creator_id]);
$row_creator = $stmt_creator->fetch(PDO::FETCH_ASSOC);

if ($row_creator && !empty($row_creator['display_name'])) {
    $creator_name = $row_creator['display_name'];
}

// ────────────────────────────────────────────────
// Handle form submission
// ────────────────────────────────────────────────
$success_message = '';
$error_message   = '';
$sent_count      = 0;
$skipped_count   = 0;
$debug_errors    = []; // collect errors to show

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_reminder'])) {
    $subject      = trim($_POST['subject'] ?? 'Important Update - ' . $event['title']);
    $html_content = trim($_POST['html_content'] ?? '');

    if (empty($html_content) || strlen(strip_tags($html_content)) < 10) {
        $error_message = "Please enter a meaningful message (at least 10 characters after formatting).";
    } else {
        $stmt = $pdo->prepare("
            SELECT DISTINCT buyer_name, buyer_email
            FROM ticket_purchases
            WHERE ticket_id = ?
              AND status = 'completed'
              AND buyer_email IS NOT NULL
              AND buyer_email != ''
        ");
        $stmt->execute([$event_id]);
        $recipients = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($recipients)) {
            $error_message = "No buyers with valid email addresses found.";
        } else {
            foreach ($recipients as $person) {
                $name = htmlspecialchars($person['buyer_name'] ?: 'Valued Guest');
                $email = $person['buyer_email'];

                error_log("Attempting custom reminder to: $email");

                $mail = new PHPMailer(true);
                try {
                    $mail->isSMTP();
                    $mail->Host       = 'mail.keychele.co.ke';
                    $mail->SMTPAuth   = true;
                    $mail->Username   = 'tickets@keychele.co.ke';
                    $mail->Password   = 'King20241064.';
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                    $mail->Port       = 587;
                    $mail->SMTPKeepAlive = false; // important: reset connection per email
                    $mail->Timeout    = 30;

                    $mail->setFrom('tickets@keychele.co.ke', $creator_name);
                    $mail->addAddress($email, $name);

                    $mail->isHTML(true);
                    $mail->Subject = $subject;

                    // Professional template with bullet-proof list styling
                    $mail->Body = '
                    <!DOCTYPE html>
                    <html lang="en">
                    <head>
                        <meta charset="utf-8">
                        <meta name="viewport" content="width=device-width, initial-scale=1">
                        <title>' . htmlspecialchars($subject) . '</title>
                    </head>
                    <body style="margin:0;padding:0;font-family:Arial,Helvetica,sans-serif;background:#f4f4f4;color:#333;">
                        <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="background:#f4f4f4;padding:30px 10px;">
                            <tr>
                                <td align="center">
                                    <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="600" style="max-width:600px;background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 6px 20px rgba(0,0,0,0.08);">
                                        <!-- Header -->
                                        <tr>
                                            <td style="background:linear-gradient(135deg,#032d5d 0%,#b81236 100%);padding:40px 30px;text-align:center;color:white;">
                                                <h1 style="margin:0;font-size:32px;font-weight:700;">' . htmlspecialchars($event['title']) . '</h1>
                                            </td>
                                        </tr>
                                        <!-- Content -->
                                        <tr>
                                            <td style="padding:40px 50px;font-size:16px;line-height:1.7;">
                                                <h2 style="margin:0 0 24px;color:#032d5d;font-size:24px;">Hello ' . $name . ',</h2>
                                                ' . $html_content . '
                                                <p style="margin:40px 0 0;color:#444;font-size:15px;">
                                                    Warm regards,<br>
                                                    <strong style="color:#032d5d;">' . htmlspecialchars($creator_name) . '</strong><br>
                                                    <span style="font-size:14px;color:#666;">Event Organizer</span>
                                                </p>
                                            </td>
                                        </tr>
                                        <!-- Footer -->
                                        <tr>
                                            <td style="background:#f8f9fa;padding:30px 50px;text-align:center;font-size:13px;color:#666;border-top:1px solid #e0e0e0;">
                                                <p style="margin:0 0 8px;">Sent from your Keychele event dashboard.</p>
                                                <p style="margin:0;">Do not reply directly — contact support if needed.</p>
                                            </td>
                                        </tr>
                                    </table>
                                </td>
                            </tr>
                        </table>

                        <!-- Force good bullet/number styling -->
                        <style type="text/css">
                            ul, ol { margin:16px 0 16px 24px !important; padding:0 !important; }
                            li { margin-bottom:10px !important; padding-left:6px !important; }
                            ul { list-style-type:disc !important; }
                            ol { list-style-type:decimal !important; }
                            li::marker { font-size:16px !important; }
                        </style>
                    </body>
                    </html>';

                    $mail->AltBody = strip_tags($html_content);

                    $mail->send();
                    $sent_count++;
                    error_log("Success: sent to $email");
                } catch (Exception $e) {
                    $error_msg = "Failed for $email: " . $mail->ErrorInfo;
                    error_log($error_msg);
                    $debug_errors[] = $error_msg;
                    $skipped_count++;
                }

                // Small delay to prevent rate limiting / timeout
                usleep(500000); // 0.5 second delay
            }

            $success_message = "Custom reminder sent to $sent_count attendee" . ($sent_count === 1 ? '' : 's');
            if ($skipped_count > 0) {
                $success_message .= " ($skipped_count failed)";
            }

            if (!empty($debug_errors)) {
                $error_message = "Some emails failed. Check error log for details.<br>" . implode('<br>', array_slice($debug_errors, 0, 3));
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
  <title>Customize & Send Reminder - <?= htmlspecialchars($event['title'] ?? 'Event') ?></title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <link href="https://cdn.jsdelivr.net/npm/quill@2.0.2/dist/quill.snow.css" rel="stylesheet" />
  <script src="https://cdn.jsdelivr.net/npm/quill@2.0.2/dist/quill.js"></script>
  <style>
    #editor-container .ql-editor { min-height:320px; font-size:16px; line-height:1.7; padding:16px; }
    .ql-toolbar { border-radius:8px 8px 0 0; background:#f8f9fa; }
    .ql-container { border-radius:0 0 8px 8px; }
  </style>
</head>
<body class="bg-gray-50 min-h-screen">

<div class="max-w-5xl mx-auto px-4 py-8 sm:px-6 lg:px-8">
  <div class="mb-8">
    <a href="index.php?page=ticket_purchases"
       class="inline-flex items-center text-indigo-600 hover:text-indigo-800 font-medium text-lg">
      <i class="fas fa-arrow-left mr-2"></i> Back to Event Details
    </a>
  </div>

  <div class="bg-white rounded-2xl shadow-2xl p-6 md:p-10">
    <h1 class="text-3xl md:text-4xl font-bold text-gray-900 mb-3">
      Customize & Send Reminder
    </h1>
    <p class="text-gray-600 text-lg mb-10">
      Event: <strong class="text-indigo-700"><?= htmlspecialchars($event['title']) ?></strong>
    </p>

    <?php if ($success_message): ?>
      <div class="mb-8 p-5 bg-green-50 border border-green-200 text-green-800 rounded-xl shadow-sm">
        <div class="flex items-center gap-3">
          <i class="fas fa-check-circle text-green-600 text-2xl"></i>
          <span class="font-medium"><?= htmlspecialchars($success_message) ?></span>
        </div>
      </div>
    <?php endif; ?>

    <?php if ($error_message): ?>
      <div class="mb-8 p-5 bg-red-50 border border-red-200 text-red-800 rounded-xl shadow-sm">
        <div class="flex items-center gap-3">
          <i class="fas fa-exclamation-circle text-red-600 text-2xl"></i>
          <span class="font-medium"><?= htmlspecialchars($error_message) ?></span>
        </div>
      </div>
    <?php endif; ?>

    <form method="post" class="space-y-10">
      <input type="hidden" name="event_id" value="<?= $event_id ?>">
      <input type="hidden" name="send_reminder" value="1">

      <div>
        <label class="block text-base font-semibold text-gray-800 mb-3">Email Subject</label>
        <input type="text" name="subject" value="Important Update - <?= htmlspecialchars($event['title']) ?>"
               class="w-full border border-gray-300 rounded-xl px-5 py-4 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 text-base shadow-sm"
               required>
      </div>

      <div>
        <label class="block text-base font-semibold text-gray-800 mb-3">Message (supports bullets & numbers)</label>
        <div id="editor-container" class="border border-gray-300 rounded-xl overflow-hidden shadow-sm"></div>
        <input type="hidden" name="html_content" id="html_content">
        <p class="mt-2 text-sm text-gray-500">
          Tip: Use the bullet (•) or numbered list buttons in the toolbar — they work perfectly in emails.
          You can also paste lists from Word/Notes — Quill will convert them automatically.
        </p>
      </div>

      <div class="flex justify-end pt-6">
        <button type="submit" id="sendBtn"
                class="bg-indigo-600 hover:bg-indigo-700 text-white px-10 py-4 rounded-xl font-semibold shadow-lg transition-all disabled:opacity-60 disabled:cursor-not-allowed flex items-center gap-3 min-w-[260px] justify-center text-lg">
          <i class="fas fa-paper-plane"></i>
          <span id="btnText">Send Reminder to All Attendees</span>
        </button>
      </div>
    </form>
  </div>
</div>

<script>
// Quill setup
const quill = new Quill('#editor-container', {
  theme: 'snow',
  modules: {
    toolbar: [
      [{ 'header': [1, 2, 3, false] }],
      ['bold', 'italic', 'underline', 'strike'],
      [{ 'color': [] }, { 'background': [] }],
      [{ 'list': 'ordered'}, { 'list': 'bullet' }],
      ['link', 'clean']
    ]
  },
  placeholder: 'Write or paste your message here... Use bullet/number list buttons for perfect email formatting.'
});

// Default content with proper list example
quill.root.innerHTML = `
  <p>Dear attendees,</p>
  <p>Here is the schedule for <strong><?= htmlspecialchars(addslashes($event['title'])) ?></strong>:</p>
  <ul>
    <li>7:30 AM – Meet Up in Town<br><em>Location: Bata Hilton (CBD)</em></li>
    <li>Arrival & check-in</li>
    <li>Light briefing</li>
    <li>Departure shortly after</li>
  </ul>
  <p><br></p>
  <p>Looking forward to seeing you there!</p>
  <p><br></p>
  <p>Warm regards,</p>
`;

// Sync before submit + loading
document.querySelector('form').addEventListener('submit', function(e) {
  document.getElementById('html_content').value = quill.root.innerHTML;

  const textLength = quill.getText().trim().length;
  if (textLength < 15) {
    e.preventDefault();
    Swal.fire('Message too short', 'Please write at least 15 characters.', 'warning');
    return;
  }

  const btn = document.getElementById('sendBtn');
  const txt = document.getElementById('btnText');
  btn.disabled = true;
  txt.textContent = 'Sending... Please wait';
  btn.innerHTML = `<i class="fas fa-spinner fa-spin"></i> <span id="btnText">Sending...</span>`;
});
</script>

</body>
</html>