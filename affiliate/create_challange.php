<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require '../vendor/autoload.php';
include 'includes/connect.php';

// Logged in creator ID
$creator_id = $_SESSION['creator_id'] ?? null;
if (!$creator_id) {
    die("Please login first.");
}

$full_name = $email = '';
$success = $error = "";
$active_challenge = null;

// Fetch creator details
try {
    $stmt = $pdo->prepare("SELECT full_name, email FROM creators WHERE id = ?");
    $stmt->execute([$creator_id]);
    $creator = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($creator) {
        $full_name = $creator['full_name'] ?? 'Unknown';
        $email = $creator['email'] ?? 'Not set';
    } else {
        $error = "Creator profile not found.";
    }
} catch (PDOException $e) {
    $error = "Error fetching creator: " . $e->getMessage();
}

// Fetch active challenge (if any)
try {
    $stmt = $pdo->prepare("
        SELECT id, title, duration, start_date, end_date
        FROM challenges 
        WHERE creator_id = ? AND status = 'active'
        LIMIT 1
    ");
    $stmt->execute([$creator_id]);
    $active_challenge = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = "Error checking challenge: " . $e->getMessage();
}

// Handle CREATE new challenge (only if no active one)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create' && !$active_challenge) {
    $title       = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $duration    = intval($_POST['duration'] ?? 0);
    $start_date  = $_POST['start_date'] ?? date('Y-m-d');

    $error = '';
    if (empty($title)) {
        $error = "Challenge title is required.";
    } elseif ($duration < 1 || $duration > 365) {
        $error = "Duration must be between 1 and 365 days.";
    } elseif (strtotime($start_date) < strtotime(date('Y-m-d'))) {
        $error = "Start date cannot be in the past.";
    }

    if (empty($error)) {
        $end_date = date('Y-m-d', strtotime("$start_date + " . ($duration - 1) . " days"));

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("
                INSERT INTO challenges 
                (creator_id, title, description, duration, start_date, end_date, status, created_at)
                VALUES (?, ?, ?, ?, ?, ?, 'active', NOW())
            ");
            $stmt->execute([$creator_id, $title, $description, $duration, $start_date, $end_date]);
            $challenge_id = $pdo->lastInsertId();

            // Create progress rows
            for ($day = 1; $day <= $duration; $day++) {
                $pdo->prepare("
                    INSERT INTO challenge_progress (challenge_id, day, completed, marked_at)
                    VALUES (?, ?, FALSE, NULL)
                ")->execute([$challenge_id, $day]);
            }

            $pdo->commit();

            $success = "Challenge '$title' created successfully! Starts $start_date ($duration days).";

            // Send emails (creator + admin) - same as before
            $mail = new PHPMailer(true);
            // ... (keep your existing email code here) ...

        } catch (Exception $e) {
            $pdo->rollBack();
            $error = "Failed to create challenge: " . $e->getMessage();
        }
    }
}

// Handle CANCEL challenge
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'cancel' && $active_challenge) {
    $reason = trim($_POST['cancel_reason'] ?? '');

    try {
        $pdo->beginTransaction();

        // Delete progress rows
        $pdo->prepare("DELETE FROM challenge_progress WHERE challenge_id = ?")
            ->execute([$active_challenge['id']]);

        // Delete challenge
        $pdo->prepare("DELETE FROM challenges WHERE id = ?")
            ->execute([$active_challenge['id']]);

        // Log cancellation reason
        $pdo->prepare("
            INSERT INTO challenge_cancellations 
            (creator_id, challenge_title, duration, start_date, cancel_reason, cancelled_at)
            VALUES (?, ?, ?, ?, ?, NOW())
        ")->execute([
            $creator_id,
            $active_challenge['title'],
            $active_challenge['duration'],
            $active_challenge['start_date'],
            $reason ?: 'No reason provided'
        ]);

        $pdo->commit();

        $success = "Your challenge '{$active_challenge['title']}' has been cancelled. You can start a new one anytime!";

        // Send cancellation email to creator
        $mail = new PHPMailer(true);
        try {
            // ... SMTP setup same as before ...

            $body = "
            <h2 style='color:#d9534f;'>Challenge Cancelled</h2>
            <p>Hi $full_name,</p>
            <p>Your challenge <strong>{$active_challenge['title']}</strong> has been cancelled.</p>
            <p>Reason: " . ($reason ?: "No reason provided") . "</p>
            <p>It's okay — every journey has turns. You're still strong. Come back stronger!</p>
            <p><a href='https://keychele.co.ke/msanii/index.php?page=create_challenge'>Start a New Challenge</a></p>
            <p>Team Keychele</p>";

            $mail->addAddress($email, $full_name);
            $mail->Subject = "Challenge Cancelled - {$active_challenge['title']}";
            $mail->Body = $body;
            $mail->send();

        } catch (Exception $e) {
            error_log("Cancel email failed: " . $mail->ErrorInfo);
        }

    } catch (Exception $e) {
        $pdo->rollBack();
        $error = "Failed to cancel challenge: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Create / Cancel Challenge</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
</head>
<body class="bg-gray-50">

<div id="preloader" class="fixed inset-0 z-[9999] flex items-center justify-center bg-black/40 backdrop-blur-sm">
  <div class="bg-white rounded-xl shadow-xl px-8 py-6 flex items-center space-x-3">
    <div class="h-5 w-5 border-2 border-purple-500 border-t-transparent rounded-full animate-spin"></div>
    <span class="text-gray-700 font-medium">Loading...</span>
  </div>
</div>

<div class="p-4 md:p-6 min-h-screen pb-20 max-w-4xl mx-auto">

  <!-- Header -->
  <div class="bg-gradient-to-r from-purple-600 to-indigo-600 p-6 rounded-2xl shadow-lg text-white mb-8">
    <h2 class="text-2xl md:text-3xl font-bold">Your Challenge Journey</h2>
    <p class="opacity-90 mt-2">Start fresh or finish strong — we're with you</p>
  </div>

  <?php if ($success): ?>
    <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-5 rounded-xl mb-6">
      <?= htmlspecialchars($success) ?>
    </div>
  <?php endif; ?>

  <?php if ($error): ?>
    <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-5 rounded-xl mb-6">
      <?= htmlspecialchars($error) ?>
    </div>
  <?php endif; ?>

  <?php if ($active_challenge): ?>
    <!-- Show Cancel Option -->
    <div class="bg-white rounded-2xl shadow-md p-8">
      <div class="text-center mb-8">
        <h3 class="text-2xl font-bold text-red-600 mb-3">You have an active challenge</h3>
        <p class="text-xl font-semibold text-gray-800">
          “<?= htmlspecialchars($active_challenge['title']) ?>”
        </p>
        <p class="text-gray-600 mt-2">
          <?= $active_challenge['duration'] ?> days • Started <?= date('M d, Y', strtotime($active_challenge['start_date'])) ?>
        </p>
      </div>

      <p class="text-center text-lg text-gray-700 mb-6">
        Want to cancel and start fresh?
      </p>

      <div class="text-center">
        <button onclick="document.getElementById('cancelModal').classList.remove('hidden')"
                class="px-10 py-4 bg-red-600 hover:bg-red-700 text-white font-bold rounded-xl shadow-lg transition">
          <i class="fa-solid fa-trash-can mr-2"></i> Cancel This Challenge
        </button>
      </div>

      <p class="text-center text-sm text-gray-500 mt-8">
        No worries — cancelling is okay. You can always begin again stronger.
      </p>
    </div>

    <!-- Cancel Confirmation Modal -->
    <div id="cancelModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/60">
      <div class="bg-white rounded-2xl shadow-2xl p-8 max-w-lg w-full mx-4">
        <h3 class="text-2xl font-bold text-red-600 mb-4">Oops! Why are you cancelling?</h3>
        <p class="text-gray-700 mb-6">
          Did you miss today's goal? No worries — you're still strong! 💪<br>
          You can cancel and start again even stronger.
        </p>

        <form method="POST">
          <input type="hidden" name="action" value="cancel">
          <label class="block mb-4">
            <span class="font-medium text-gray-700">Reason for cancelling (optional)</span>
            <textarea name="cancel_reason" rows="4"
                      class="mt-1 w-full p-3 border rounded-xl focus:border-red-500 focus:ring focus:ring-red-200"
                      placeholder="Tell us why... (helps us improve)"></textarea>
          </label>

          <div class="flex gap-4 justify-end">
            <button type="button" onclick="document.getElementById('cancelModal').classList.add('hidden')"
                    class="px-6 py-3 bg-gray-300 hover:bg-gray-400 text-gray-800 rounded-xl">
              Never mind
            </button>
            <button type="submit"
                    class="px-8 py-3 bg-red-600 hover:bg-red-700 text-white font-bold rounded-xl">
              Yes, Cancel Challenge
            </button>
          </div>
        </form>
      </div>
    </div>

  <?php else: ?>
    <!-- Create New Challenge Form -->
    <div class="bg-white rounded-2xl shadow-md p-8">
      <form method="POST" class="space-y-6">
        <input type="hidden" name="action" value="create">

        <h3 class="text-lg font-semibold text-gray-800 mb-4">Your Information</h3>
        <label class="block mb-4">
          <span class="font-medium text-gray-700">Full Name</span>
          <input type="text" value="<?= htmlspecialchars($full_name) ?>" readonly
                 class="mt-1 w-full p-3 border rounded-xl bg-gray-100 cursor-not-allowed">
        </label>
        <label class="block mb-4">
          <span class="font-medium text-gray-700">Email</span>
          <input type="email" value="<?= htmlspecialchars($email) ?>" readonly
                 class="mt-1 w-full p-3 border rounded-xl bg-gray-100 cursor-not-allowed">
        </label>

        <h3 class="text-lg font-semibold text-gray-800 mt-8 mb-4">Challenge Details</h3>
        <label class="block mb-4">
          <span class="font-medium text-gray-700">Title *</span>
          <input name="title" required type="text" class="mt-1 w-full p-3 border rounded-xl focus:border-purple-500 focus:ring focus:ring-purple-200 focus:ring-opacity-50"
                 placeholder="e.g. 7-Day Discipline Challenge">
        </label>
        <label class="block mb-4">
          <span class="font-medium text-gray-700">Description</span>
          <textarea name="description" rows="4" class="mt-1 w-full p-3 border rounded-xl focus:border-purple-500 focus:ring focus:ring-purple-200 focus:ring-opacity-50"
                    placeholder="What are your goals? Any rules?"></textarea>
        </label>
        <label class="block mb-4">
          <span class="font-medium text-gray-700">Duration (Days) *</span>
          <input name="duration" required type="number" min="1" max="365" class="mt-1 w-full p-3 border rounded-xl focus:border-purple-500 focus:ring focus:ring-purple-200 focus:ring-opacity-50"
                 placeholder="e.g. 7">
        </label>
        <label class="block mb-6">
          <span class="font-medium text-gray-700">Start Date *</span>
          <input name="start_date" required type="date" min="<?= date('Y-m-d') ?>" 
                 class="mt-1 w-full p-3 border rounded-xl focus:border-purple-500 focus:ring focus:ring-purple-200 focus:ring-opacity-50"
                 value="<?= date('Y-m-d') ?>">
        </label>

        <button type="submit" class="w-full bg-purple-600 hover:bg-purple-700 text-white p-4 rounded-xl font-bold transition flex items-center justify-center gap-2">
          <i class="fa-solid fa-plus"></i> Create Challenge
        </button>
      </form>
    </div>
  <?php endif; ?>

  <!-- How Challenges Work -->
  <div class="mt-12 pt-8 border-t border-gray-200">
    <h3 class="text-xl font-bold text-gray-800 mb-4">How Challenges Work</h3>
    <div class="bg-blue-50 border-l-4 border-blue-400 p-4 rounded-xl mb-6 text-sm text-blue-800">
      <strong>Important:</strong> Daily email reminders. Mark progress before 10 PM EAT. 
      You can cancel anytime — no judgment, just growth.
    </div>
  </div>

</div>

<script>
window.addEventListener("load", function () {
    document.getElementById("preloader").style.opacity = "0";
    setTimeout(() => { document.getElementById("preloader").style.display = "none"; }, 400);
});
</script>

</body>
</html>